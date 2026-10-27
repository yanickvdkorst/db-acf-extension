<?php

namespace DB_ACF_UI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dagelijkse healthcheck op de Contact Form 7-formulieren van deze site.
 *
 * Elke nacht om 06:00 (WP-tijdzone) wordt elk formulier ingediend via het
 * REST-endpoint van CF7, zodat je het merkt als een formulier stuk is vóórdat
 * een bezoeker het ontdekt. Eén willekeurig formulier mag daarbij écht mailen;
 * dat test meteen de SMTP-instellingen zonder dat de inbox volloopt. De uitslag
 * gaat als overzichtsmail naar het adres uit `db_cf7_healthcheck/recipient`.
 *
 * Deze code stond eerder in functions-root.php van het thema. In de plugin
 * draait hij op elke site die de plugin heeft, en is hij in één release bij te
 * werken. Een site waar het thema de oude functies nog heeft, wordt
 * overgeslagen (zie de function_exists-check in db-acf-ui.php) — anders zouden
 * er twee healthchecks naast elkaar lopen.
 *
 * Aanpassen per site kan met filters:
 *   db_cf7_healthcheck/enabled            — helemaal uitzetten
 *   db_cf7_healthcheck/recipient          — ontvanger van de overzichtsmail
 *   db_cf7_healthcheck/test_email         — adres waaraan een testinzending te herkennen is
 *   db_cf7_healthcheck/notify_on_success  — op false: alleen mailen bij problemen
 *   db_cf7_healthcheck/staging_hosts      — domeinen die nooit mailen
 */
class CF7_Healthcheck {

    /** Naam van de geplande taak. Gelijk aan de thema-versie, zodat een site die
     *  van thema naar plugin overstapt zijn bestaande planning houdt. */
    public const CRON_HOOK = 'db_cf7_daily_healthcheck';

    /** Lock tegen overlappende runs. */
    private const RUN_LOCK = 'db_cf7_running';

    /** Het formulier dat deze run wél mag mailen. */
    private const MAIL_FORM = 'db_cf7_mail_form_id';

    /** Geheim waaraan de inzendingen van deze run te herkennen zijn. */
    private const TOKEN = 'db_cf7_token';

    /** De header waarin dat geheim meereist. */
    private const HEADER = 'X-DB-Healthcheck';

    /** Meldingen van de lopende run. */
    private array $log = [];

    /** De meldingen van de laatste run, voor wie hem zelf aanroept. */
    public function get_log(): array {
        return $this->log;
    }

    public function register(): void {
        if ( ! apply_filters( 'db_cf7_healthcheck/enabled', true ) ) {
            return;
        }

        add_action( self::CRON_HOOK, [ $this, 'run' ] );
        add_action( 'init', [ $this, 'maybe_schedule' ] );

        // Trigger 1: /?db_cf7_test=1 op de frontend. Let op: een page-cache/CDN kan
        // deze querystring negeren en de gewone (gecachete) pagina serveren.
        add_action( 'init', [ $this, 'maybe_run_manually' ] );

        // Trigger 2: /wp-admin/admin-post.php?action=db_cf7_test — wp-admin wordt
        // nooit gecachet, dus dit werkt ook als trigger 1 wordt opgeslokt.
        add_action( 'admin_post_db_cf7_test', [ $this, 'run_manual_test' ] );

        add_filter( 'wpcf7_skip_mail', [ $this, 'maybe_skip_mail' ], 10, 2 );
        add_action( 'wpcf7_before_send_mail', [ $this, 'redirect_mail' ] );
        add_filter( 'wpcf7_flamingo_submit_if', [ $this, 'skip_flamingo' ] );
        add_filter( 'wpcf7_skip_spam_check', [ $this, 'skip_spam_check' ], 10, 2 );
    }

    /**
     * Plant de dagelijkse run op de eerstvolgende 06:00 in de WP-tijdzone.
     *
     * Een dagelijkse WP-cron telt steeds 86400 seconden op. Na het ingaan van de
     * zomer- of wintertijd klopt de lokale tijd dus een uur niet meer en draait
     * de check om 05:00 of 07:00. Staat hij scheef, dan zetten we hem hier terug;
     * dat gebeurt vanzelf bij de eerste pageload na de klokwissel.
     */
    public function maybe_schedule(): void {
        $volgende = wp_next_scheduled( self::CRON_HOOK );

        if ( $volgende ) {
            if ( '06:00' === wp_date( 'H:i', $volgende ) ) {
                return;
            }

            wp_unschedule_event( $volgende, self::CRON_HOOK );
        }

        wp_schedule_event( $this->next_six_am(), 'daily', self::CRON_HOOK );
    }

    /** Eerstvolgende 06:00 in de tijdzone van de site. */
    private function next_six_am(): int {
        $tz     = wp_timezone();
        $now    = new \DateTime( 'now', $tz );
        $target = new \DateTime( 'today 06:00', $tz );
        if ( $target <= $now ) {
            $target->modify( '+1 day' );
        }

        return $target->getTimestamp();
    }

    /** Opruimen bij deactiveren van de plugin. */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }

        delete_transient( self::RUN_LOCK );
        delete_transient( self::MAIL_FORM );
        delete_transient( self::TOKEN );
    }

    // ─── Adressen en instellingen ──────────────────────────────────────────

    private function recipient(): string {
        return (string) apply_filters( 'db_cf7_healthcheck/recipient', 'wordpress@digitalebazen.nl' );
    }

    private function test_email(): string {
        return (string) apply_filters( 'db_cf7_healthcheck/test_email', 'monitoring+cf7test@digitalebazen.nl' );
    }

    // ─── Handmatig draaien ─────────────────────────────────────────────────

    public function maybe_run_manually(): void {
        if ( empty( $_GET['db_cf7_test'] ) ) {
            return;
        }

        $this->run_manual_test();
    }

    /**
     * Draait de healthcheck handmatig en print de uitslag als platte tekst.
     */
    public function run_manual_test(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'CF7 healthcheck: geen rechten. Log eerst in als beheerder.' );
        }

        // Een achtergebleven run-lock (bv. van een afgebroken cron-run) wissen: een
        // handmatige run is bewust en mag niet stil geblokkeerd worden.
        delete_transient( self::RUN_LOCK );

        $this->run();

        header( 'Content-Type: text/plain; charset=utf-8' );
        if ( empty( $this->log ) ) {
            echo "CF7 healthcheck: geen formulieren gevonden.\n";
        } else {
            $failed = $this->count_failures( $this->log );
            echo ( $failed ? "CF7 healthcheck: {$failed} probleem/problemen gevonden.\n\n" : "CF7 healthcheck: alles OK.\n\n" );
            echo implode( "\n", $this->log ) . "\n";
        }
        exit;
    }

    // ─── De run zelf ───────────────────────────────────────────────────────

    /**
     * Logt (en verzamelt) een healthcheck-melding.
     */
    private function alert( string $message ): void {
        error_log( '[CF7 healthcheck] ' . $message );
        $this->log[] = $message;
    }

    private function count_failures( array $log ): int {
        return count( array_filter( $log, static function ( $line ) {
            return strpos( $line, 'FAILED' ) !== false;
        } ) );
    }

    /**
     * Haalt alle gepubliceerde CF7-formulieren op.
     */
    private function get_forms(): array {
        $posts = get_posts( [
            'post_type'      => 'wpcf7_contact_form',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );

        $forms = [];
        foreach ( $posts as $post ) {
            $forms[] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
            ];
        }

        return $forms;
    }

    public function run( $send_summary_mail = true ): void {
        // Voorkom overlappende runs. De run doet per formulier een loopback-request
        // (timeout 15s) en loopt met meerdere formulieren makkelijk over de 60s
        // WP-Cron-lock heen. Zonder deze lock kan WP-Cron een tweede run starten die
        // een ander formulier kiest - dan krijg je twee testformulier-mails.
        if ( get_transient( self::RUN_LOCK ) ) {
            error_log( '[CF7 healthcheck] Run overgeslagen: er draait al een healthcheck.' );
            return;
        }
        set_transient( self::RUN_LOCK, 1, 10 * MINUTE_IN_SECONDS );

        // Geheim voor deze run. Elke inzending krijgt het als header mee, zodat
        // het REST-proces (een andere PHP-request) weet dat wij het zijn — ook
        // bij een formulier zonder e-mailveld.
        $token = wp_generate_password( 32, false );
        set_transient( self::TOKEN, $token, 10 * MINUTE_IN_SECONDS );

        $this->log = [];

        // Contact Form 7 kan gedeactiveerd zijn - dan niet doorgaan (voorkomt fatal error)
        if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
            $this->alert( 'CF7 healthcheck FAILED: Contact Form 7 is niet actief.' );
            if ( $send_summary_mail ) {
                $this->send_summary_mail( $this->log );
            }
            delete_transient( self::TOKEN );
            delete_transient( self::RUN_LOCK );
            return;
        }

        // Eerst bepalen welke formulieren daadwerkelijk getest kunnen worden
        // (quiz/captcha/file-upload zijn niet blind te automatiseren).
        $testable = [];
        foreach ( $this->get_forms() as $form ) {
            $form_id = $form['id'];
            $result  = $this->build_payload( $form_id );

            if ( ! $result ) {
                $this->alert( "CF7 healthcheck: kon form '{$form['title']}' (ID {$form_id}) niet laden." );
                continue;
            }

            if ( $result['skippable'] ) {
                $this->alert( "CF7 healthcheck: form '{$form['title']}' (ID {$form_id}) overgeslagen - {$result['reason']}. Handmatige check nodig." );
                continue;
            }

            $testable[] = [ 'form' => $form, 'result' => $result ];
        }

        // Kies willekeurig één formulier dat écht een mail mag versturen. Zo testen
        // we alle formulieren én SMTP, maar zonder een overvolle mailbox: de rest
        // wordt wel ingediend en gevalideerd, maar de mail wordt overgeslagen.
        //
        // Elk formulier wordt ingediend via wp_remote_post - dat is een aparte
        // HTTP-request en dus een ander PHP-proces. Het gekozen formulier-ID delen
        // we daarom via een transient (database), zodat de skip-mail-filter in dat
        // andere proces weet welk formulier wél mag mailen.
        delete_transient( self::MAIL_FORM );
        if ( ! empty( $testable ) ) {
            $chosen = $testable[ array_rand( $testable ) ];
            set_transient( self::MAIL_FORM, (int) $chosen['form']['id'], 5 * MINUTE_IN_SECONDS );
            $this->alert( "CF7 healthcheck: echte mail wordt alleen verstuurd voor '{$chosen['form']['title']}' (ID {$chosen['form']['id']}) om SMTP te testen; overige formulieren worden getest zonder mail." );
        }

        foreach ( $testable as $item ) {
            $this->submit( $item['form'], $item['result'], $token );
        }

        delete_transient( self::MAIL_FORM );
        delete_transient( self::TOKEN );

        if ( $send_summary_mail ) {
            $this->send_summary_mail( $this->log );
        }

        delete_transient( self::RUN_LOCK );
    }

    /**
     * Dient één formulier in op het REST-endpoint van CF7 en beoordeelt het antwoord.
     */
    private function submit( array $form, array $result, string $token = '' ): void {
        $form_id = $form['id'];
        $url     = rest_url( "contact-form-7/v1/contact-forms/{$form_id}/feedback" );

        // CF7's REST-endpoint accepteert uitsluitend multipart/form-data,
        // dus bouwen we de body handmatig op met een boundary.
        $boundary = wp_generate_password( 24, false );
        $body     = '';
        foreach ( $result['payload'] as $key => $value ) {
            foreach ( (array) $value as $val ) {
                $body .= "--{$boundary}\r\n";
                $body .= 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n";
                $body .= $val . "\r\n";
            }
        }
        // File-velden als echte upload-parts meesturen (CF7 valideert op extensie)
        foreach ( $result['files'] as $field => $file ) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $file['filename'] . '"' . "\r\n";
            $body .= "Content-Type: application/octet-stream\r\n\r\n";
            $body .= $file['content'] . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                self::HEADER   => $token,
            ],
            'body'    => $body,
            'timeout' => 15, // hangend endpoint mag de hele run niet blokkeren
        ] );

        if ( is_wp_error( $response ) ) {
            $this->alert( "CF7 healthcheck: request failed voor '{$form['title']}' (ID {$form_id}) - " . $response->get_error_message() );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );
        $parsed    = json_decode( $raw_body, true );

        if ( ! empty( $parsed['status'] ) && $parsed['status'] === 'mail_sent' ) {
            $this->alert( "CF7 healthcheck OK voor '{$form['title']}' (ID {$form_id}): mail_sent" );
            return;
        }

        $errors = '';
        if ( ! empty( $parsed['invalid_fields'] ) ) {
            $errors = ' - velden: ' . wp_json_encode( wp_list_pluck( $parsed['invalid_fields'], 'message' ) );
        }
        // Geen bruikbare status: laat HTTP-code en een stukje ruwe body zien voor diagnose
        if ( empty( $parsed['status'] ) ) {
            $errors .= " - HTTP {$http_code}, body: " . mb_substr( trim( $raw_body ), 0, 300 );
        } elseif ( ! empty( $parsed['message'] ) ) {
            $errors .= ' - melding: ' . $parsed['message'];
        }

        $this->alert( "CF7 healthcheck FAILED voor '{$form['title']}' (ID {$form_id}): status = " . ( $parsed['status'] ?? 'onbekend' ) . $errors );
    }

    /**
     * Bepaalt een toegestane bestandsextensie voor een CF7 file-veld.
     * CF7 valideert file-uploads alleen op extensie, niet op inhoud.
     * Geeft false terug als er geen bruikbare extensie te bepalen is.
     */
    private function pick_file_extension( $tag ) {
        $accept = explode( ',', wpcf7_acceptable_filetypes( $tag->get_option( 'filetypes' ), 'attr' ) );

        foreach ( $accept as $a ) {
            $a = trim( $a );
            if ( $a === '' ) {
                continue;
            }
            // Directe extensie zoals ".pdf"
            if ( preg_match( '/^\.[a-z0-9]+$/i', $a ) ) {
                return ltrim( strtolower( $a ), '.' );
            }
            // MIME zoals "image/*" -> naar concrete extensie(s)
            $exts = wpcf7_convert_mime_to_ext( $a );
            if ( ! empty( $exts ) ) {
                return strtolower( trim( reset( $exts ), ' .' ) );
            }
        }

        return false;
    }

    /**
     * Genereert automatisch een testpayload voor een CF7-form op basis van de veldtypes.
     */
    public function build_payload( $form_id ) {
        $contact_form = \WPCF7_ContactForm::get_instance( $form_id );
        if ( ! $contact_form ) {
            return false;
        }

        $tags    = $contact_form->scan_form_tags();
        $payload = [
            '_wpcf7'                => $form_id,
            '_wpcf7_version'        => WPCF7_VERSION,
            '_wpcf7_unit_tag'       => "wpcf7-f{$form_id}",
            '_wpcf7_container_post' => 0,
        ];

        $has_unsupported_field = false;
        $unsupported_reason    = '';
        $files                 = [];

        foreach ( $tags as $tag ) {
            $name = $tag->name;
            if ( empty( $name ) ) {
                continue; // submit-knop of andere non-input tag
            }

            $basetype = $tag->basetype; // text, email, tel, textarea, select, checkbox, etc.

            switch ( $basetype ) {
                case 'text':
                case 'text*':
                    $payload[ $name ] = ( strpos( $name, 'naam' ) !== false || strpos( $name, 'name' ) !== false )
                        ? 'Healthcheck Bot'
                        : 'Test';
                    break;

                case 'email':
                    $payload[ $name ] = $this->test_email();
                    break;

                case 'tel':
                    $payload[ $name ] = '0612345678';
                    break;

                case 'url':
                    $payload[ $name ] = 'https://digitalebazen.nl';
                    break;

                case 'number':
                case 'range':
                    $payload[ $name ] = '1';
                    break;

                case 'date':
                    $payload[ $name ] = date( 'Y-m-d' );
                    break;

                case 'textarea':
                    $payload[ $name ] = 'Automated healthcheck bericht - ' . current_time( 'mysql' );
                    break;

                case 'select':
                case 'checkbox':
                case 'radio':
                    // Bij first_as_label is de eerste optie slechts een placeholder-label
                    // ("Selecteer"), geen geldige waarde - pak dan de eerste echte optie.
                    $values = $tag->values;
                    $index  = ( $tag->has_option( 'first_as_label' ) && count( $values ) > 1 ) ? 1 : 0;
                    if ( ! empty( $values[ $index ] ) ) {
                        $payload[ $name ] = $values[ $index ];
                    }
                    break;

                case 'acceptance':
                    $payload[ $name ] = '1'; // "ik ga akkoord"-checkboxen altijd aanvinken
                    break;

                case 'quiz':
                    // Reken-quiz (bv. "5+3=?") kunnen we niet automatisch oplossen
                    $has_unsupported_field = true;
                    $unsupported_reason    = 'bevat een quiz-veld';
                    break;

                case 'file':
                    // Stuur een klein testbestand mee met een toegestane extensie
                    $ext = $this->pick_file_extension( $tag );
                    if ( $ext ) {
                        $files[ $name ] = [
                            'filename' => "healthcheck-test.{$ext}",
                            'content'  => 'Automated CF7 healthcheck testbestand.',
                        ];
                    } else {
                        // Geen bruikbare extensie te bepalen - dan toch overslaan
                        $has_unsupported_field = true;
                        $unsupported_reason    = 'bevat een file-upload veld met onbekend type';
                    }
                    break;

                default:
                    // Onbekend/nieuw veldtype - vul een generieke waarde in als vangnet
                    $payload[ $name ] = 'Test';
                    break;
            }

            // reCAPTCHA laten we altijd falen op verificatie - los benoemen ipv silent negeren
            if ( $basetype === 'recaptcha' ) {
                $has_unsupported_field = true;
                $unsupported_reason    = 'bevat reCAPTCHA';
            }
        }

        return [
            'payload'   => $payload,
            'files'     => $files,
            'skippable' => $has_unsupported_field,
            'reason'    => $unsupported_reason,
        ];
    }

    // ─── Hooks tijdens een testinzending ───────────────────────────────────

    /**
     * Is dit een inzending van de healthcheck zelf?
     *
     * In de eerste plaats aan de header te zien: die reist mee ongeacht welke
     * velden een formulier heeft. Vroeger keken we alleen naar het testadres in
     * de veldwaarden, maar een formulier zonder e-mailveld kreeg dat nooit —
     * zo'n inzending werd als gewone bezoeker behandeld en kon dus bij de klant
     * en in Flamingo belanden.
     *
     * Het testadres blijft als terugval, voor als een proxy of security-plugin
     * de header wegpoetst. Alleen tijdens een lopende run: buiten die paar
     * minuten komt een bezoeker die dit adres toevallig invult gewoon door.
     */
    public function is_healthcheck_request( $submission = null ): bool {
        $token = (string) get_transient( self::TOKEN );

        if ( '' === $token ) {
            return false; // er loopt geen healthcheck
        }

        $key    = 'HTTP_' . strtoupper( str_replace( '-', '_', self::HEADER ) );
        $header = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : '';

        if ( '' !== $header && hash_equals( $token, $header ) ) {
            return true;
        }

        return $submission && $this->data_has_test_email( $submission->get_posted_data() );
    }

    /** Staat het testadres in een van de velden? */
    public function data_has_test_email( $data ): bool {
        foreach ( (array) $data as $value ) {
            if ( in_array( $this->test_email(), (array) $value, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Onderdrukt tijdens de healthcheck de CF7-mail voor alle formulieren behalve
     * het willekeurig gekozen formulier (zie transient db_cf7_mail_form_id). Draait
     * in het REST-proces dat de inzending afhandelt. Het formulier wordt nog steeds
     * volledig ingediend en gevalideerd - CF7 geeft bij een overgeslagen mail alsnog
     * status 'mail_sent' terug, dus de OK/FAILED-controle blijft kloppen.
     */
    public function maybe_skip_mail( $skip_mail, $contact_form ) {
        $submission = \WPCF7_Submission::get_instance();

        // Alleen ingrijpen bij healthcheck-inzendingen; echte bezoekers nooit.
        if ( ! $submission || ! $this->is_healthcheck_request( $submission ) ) {
            return $skip_mail;
        }

        $mail_form_id = (int) get_transient( self::MAIL_FORM );

        // Geen gekozen formulier bekend? Dan geen mail versturen (voorkomt volle inbox).
        if ( ! $mail_form_id ) {
            return true;
        }

        // Het gekozen formulier mag zijn mail versturen (SMTP-test); de rest niet.
        return (int) $contact_form->id() !== $mail_form_id;
    }

    /** Stuurt de mail van een testinzending naar ons eigen adres. */
    public function redirect_mail( $contact_form ): void {
        $submission = \WPCF7_Submission::get_instance();
        if ( ! $submission ) {
            return;
        }

        if ( $this->is_healthcheck_request( $submission ) ) {
            $mail              = $contact_form->prop( 'mail' );
            $mail['recipient'] = $this->recipient();
            $contact_form->set_properties( [ 'mail' => $mail ] );
        }
    }

    /** Sla healthcheck-inzendingen niet op in Flamingo (voorkomt een vervuilde inbox). */
    public function skip_flamingo( $cases ) {
        $submission = \WPCF7_Submission::get_instance();
        if ( $submission && $this->is_healthcheck_request( $submission ) ) {
            return []; // geen enkele status -> Flamingo slaat deze inzending niet op
        }
        return $cases;
    }

    /** Laat Akismet de healthcheck-inzending niet als spam markeren (voorkomt valse FAILED). */
    public function skip_spam_check( $skip, $submission ) {
        if ( $submission && $this->is_healthcheck_request( $submission ) ) {
            return true;
        }
        return $skip;
    }

    // ─── Overzichtsmail ────────────────────────────────────────────────────

    /**
     * Stuurt een overzichtsmail met de uitslag van de healthcheck.
     */
    private function send_summary_mail( array $log ): void {
        $site = wp_parse_url( home_url(), PHP_URL_HOST );

        // Geen mails versturen vanaf test-/staging-omgevingen
        $staging = (array) apply_filters( 'db_cf7_healthcheck/staging_hosts', [ '.ontwikkelbazen.nl' ] );
        foreach ( $staging as $suffix ) {
            if ( $site && $suffix !== '' && substr( $site, -strlen( $suffix ) ) === $suffix ) {
                error_log( "[CF7 healthcheck] Overzichtsmail overgeslagen op testomgeving {$site}" );
                return;
            }
        }

        $failed = $this->count_failures( $log );

        // Op sites met veel formulieren wil je misschien alleen horen als er iets
        // stuk is; dan zet je deze filter op false.
        if ( ! $failed && ! apply_filters( 'db_cf7_healthcheck/notify_on_success', true ) ) {
            return;
        }

        $subject = $failed
            ? "⚠️ [CF7 healthcheck] {$failed} PROBLEEM/PROBLEMEN op {$site}"
            : "✅ [CF7 healthcheck] Alles OK op {$site}";

        $intro = $failed
            ? "Let op: {$failed} formulier(en) hebben een probleem.\n\n"
            : "Alle formulieren zijn succesvol getest.\n\n";

        $bericht  = $intro;
        $bericht .= "Uitgevoerd op: " . current_time( 'd-m-Y H:i' ) . "\n\n";
        $bericht .= empty( $log ) ? "(geen formulieren gevonden)" : implode( "\n", $log );

        wp_mail( $this->recipient(), $subject, $bericht );
    }
}
