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
 * een bezoeker het ontdekt. De formulieren mailen daarbij niet - je krijgt alleen
 * de overzichtsmail, op het adres uit `db_cf7_healthcheck/recipient`.
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
 *   db_cf7_healthcheck/smtp_test          — op true: één formulier mailt écht, als SMTP-test
 */
class CF7_Healthcheck {

    /** Naam van de geplande taak. Gelijk aan de thema-versie, zodat een site die
     *  van thema naar plugin overstapt zijn bestaande planning houdt. */
    public const CRON_HOOK = 'db_cf7_daily_healthcheck';

    /** Lock tegen overlappende runs. */
    private const RUN_LOCK = 'db_cf7_running';

    /** Het formulier dat deze run wél mag mailen (alleen bij de SMTP-test). */
    private const MAIL_FORM = 'db_cf7_mail_form_id';

    /** Geheim waaraan de inzendingen van deze run te herkennen zijn. */
    private const TOKEN = 'db_cf7_token';

    /** De header waarin dat geheim meereist. */
    private const HEADER = 'X-DB-Healthcheck';

    /** Meldingen van de lopende run. */
    private array $log = [];

    /** Dezelfde run, maar per formulier - de overzichtsmail bouwt hier zijn lijst mee. */
    private array $results = [];

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

    /**
     * Zelfde als alert(), maar bewaart de uitslag ook per formulier. Daarmee maakt
     * de overzichtsmail een nette lijst in plaats van losse regels logtekst.
     *
     * @param string $status ok | failed | skipped | note
     */
    private function record( string $message, string $status, string $title = '', int $id = 0, string $detail = '' ): void {
        $this->alert( $message );
        $this->results[] = [
            'status' => $status,
            'title'  => $title,
            'id'     => $id,
            'detail' => $detail,
        ];
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

        $this->log     = [];
        $this->results = [];

        // Contact Form 7 kan gedeactiveerd zijn - dan niet doorgaan (voorkomt fatal error)
        if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
            $this->record(
                'CF7 healthcheck FAILED: Contact Form 7 is niet actief.',
                'failed',
                'Contact Form 7',
                0,
                'De plugin is niet actief, er is niets getest.'
            );
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
                $this->record(
                    "CF7 healthcheck FAILED voor '{$form['title']}' (ID {$form_id}): formulier kon niet geladen worden.",
                    'failed',
                    $form['title'],
                    $form_id,
                    'Het formulier kon niet geladen worden.'
                );
                continue;
            }

            if ( $result['skippable'] ) {
                $this->record(
                    "CF7 healthcheck: form '{$form['title']}' (ID {$form_id}) overgeslagen - {$result['reason']}. Handmatige check nodig.",
                    'skipped',
                    $form['title'],
                    $form_id,
                    ucfirst( $result['reason'] ) . '. Handmatige check nodig.'
                );
                continue;
            }

            $testable[] = [ 'form' => $form, 'result' => $result ];
        }

        // Geen enkel formulier mailt. Ze worden wel volledig ingediend en
        // gevalideerd, maar de mail wordt overgeslagen - je krijgt dus alleen de
        // overzichtsmail. Die gaat zelf ook via wp_mail en is daarmee meteen de
        // controle of de site kan mailen.
        //
        // Wil je toch een échte formuliermail als SMTP-test, dan zet je de filter
        // hieronder op true; er wordt dan willekeurig één formulier gekozen dat wél
        // mag mailen. Elk formulier wordt ingediend via wp_remote_post - een aparte
        // HTTP-request en dus een ander PHP-proces - dus delen we dat formulier-ID
        // via een transient, zodat de skip-mail-filter daar weet welk formulier mag.
        delete_transient( self::MAIL_FORM );
        if ( ! empty( $testable ) && apply_filters( 'db_cf7_healthcheck/smtp_test', false ) ) {
            $chosen = $testable[ array_rand( $testable ) ];
            set_transient( self::MAIL_FORM, (int) $chosen['form']['id'], 5 * MINUTE_IN_SECONDS );
            $this->record(
                "CF7 healthcheck: echte mail wordt alleen verstuurd voor '{$chosen['form']['title']}' (ID {$chosen['form']['id']}) om SMTP te testen; overige formulieren worden getest zonder mail.",
                'note',
                '',
                0,
                "Als SMTP-test is één echte mail verstuurd, via '{$chosen['form']['title']}'."
            );
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
            $this->record(
                "CF7 healthcheck FAILED voor '{$form['title']}' (ID {$form_id}): request mislukt - " . $response->get_error_message(),
                'failed',
                $form['title'],
                $form_id,
                'Het formulier was niet bereikbaar: ' . $response->get_error_message()
            );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );
        $parsed    = json_decode( $raw_body, true );

        if ( ! empty( $parsed['status'] ) && $parsed['status'] === 'mail_sent' ) {
            $this->record( "CF7 healthcheck OK voor '{$form['title']}' (ID {$form_id}): mail_sent", 'ok', $form['title'], $form_id );
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

        $status = (string) ( $parsed['status'] ?? 'onbekend' );

        $this->record(
            "CF7 healthcheck FAILED voor '{$form['title']}' (ID {$form_id}): status = {$status}{$errors}",
            'failed',
            $form['title'],
            $form_id,
            "Status: {$status}{$errors}"
        );
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
     * Onderdrukt tijdens de healthcheck de CF7-mail. Draait in het REST-proces dat
     * de inzending afhandelt. Het formulier wordt nog steeds volledig ingediend en
     * gevalideerd - CF7 geeft bij een overgeslagen mail alsnog status 'mail_sent'
     * terug, dus de OK/FAILED-controle blijft kloppen. Alleen als de SMTP-test aan
     * staat mag één gekozen formulier wél mailen (transient db_cf7_mail_form_id).
     */
    public function maybe_skip_mail( $skip_mail, $contact_form ) {
        $submission = \WPCF7_Submission::get_instance();

        // Alleen ingrijpen bij healthcheck-inzendingen; echte bezoekers nooit.
        if ( ! $submission || ! $this->is_healthcheck_request( $submission ) ) {
            return $skip_mail;
        }

        $mail_form_id = (int) get_transient( self::MAIL_FORM );

        // Geen gekozen formulier bekend? Dan geen mail versturen - dat is het
        // normale geval, want de SMTP-test staat standaard uit.
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
     *
     * De mail gaat als HTML de deur uit, met de platte tekst als alternatief
     * mee (AltBody) voor wie geen HTML krijgt voorgeschoteld.
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

        // Het onderwerp houdt dezelfde opbouw aan: er staan mailregels op die op
        // "[CF7 healthcheck]" filteren.
        $subject = $failed
            ? sprintf( '⚠️ [CF7 healthcheck] %d %s op %s', $failed, 1 === $failed ? 'probleem' : 'problemen', $site )
            : "✅ [CF7 healthcheck] Alles OK op {$site}";

        $tekst = $this->summary_text( $log, $failed );
        $html  = $this->summary_html( (string) $site, $failed );

        // wp_mail kent geen tekstversie naast de HTML; die hangen we er zelf in.
        $alt = static function ( $phpmailer ) use ( $tekst ) {
            $phpmailer->AltBody = $tekst;
        };
        add_action( 'phpmailer_init', $alt );

        wp_mail( $this->recipient(), $subject, $html, [ 'Content-Type: text/html; charset=UTF-8' ] );

        remove_action( 'phpmailer_init', $alt );
    }

    /** De uitslag als platte tekst, zoals de mail er vóór 1.9.3 uitzag. */
    private function summary_text( array $log, int $failed ): string {
        $tekst  = $failed
            ? "Let op: {$failed} formulier(en) hebben een probleem.\n\n"
            : "Alle formulieren zijn succesvol getest.\n\n";
        $tekst .= 'Uitgevoerd op: ' . current_time( 'd-m-Y H:i' ) . "\n\n";
        $tekst .= empty( $log ) ? '(geen formulieren gevonden)' : implode( "\n", $log );

        return $tekst;
    }

    /**
     * De uitslag als opgemaakte mail: een gekleurde kop, daaronder de formulieren
     * gegroepeerd op uitslag. Alles met inline stijlen en tabellen, want mailclients
     * gooien een <style>-blok of moderne opmaak er net zo makkelijk uit.
     */
    private function summary_html( string $site, int $failed ): string {
        $kapot   = $this->results_with_status( 'failed' );
        $ok      = $this->results_with_status( 'ok' );
        $skipped = $this->results_with_status( 'skipped' );

        $accent = $failed ? '#b32d2e' : '#157347';
        $kop    = $failed
            ? sprintf( '%d %s gevonden', $failed, 1 === $failed ? 'probleem' : 'problemen' )
            : 'Alle formulieren werken';

        $getest  = count( $kapot ) + count( $ok );
        $telling = [ sprintf( '%d %s getest', $getest, 1 === $getest ? 'formulier' : 'formulieren' ) ];
        if ( $failed ) {
            $telling[] = sprintf( '%d met een probleem', $failed );
        }
        if ( $skipped ) {
            $telling[] = sprintf( '%d overgeslagen', count( $skipped ) );
        }

        $rijen  = $this->html_group( 'Problemen', $kapot, '#b32d2e' );
        $rijen .= $this->html_group( 'In orde', $ok, '#157347' );
        $rijen .= $this->html_group( 'Overgeslagen', $skipped, '#b26a00' );

        if ( '' === $rijen ) {
            $rijen = '<tr><td style="padding:14px 28px 0;font-size:14px;color:#646970;">Er zijn geen formulieren gevonden om te testen.</td></tr>';
        }

        $noten = '';
        foreach ( $this->results_with_status( 'note' ) as $noot ) {
            $noten .= '<tr><td style="padding:16px 28px 0;font-size:13px;color:#787c82;line-height:1.5;">'
                . esc_html( $noot['detail'] ) . '</td></tr>';
        }

        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";

        return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html( $kop ) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f0f0f1;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:' . $font . ';">'

            . '<tr><td style="background:' . esc_attr( $accent ) . ';padding:22px 28px;">'
            . '<div style="font-size:19px;font-weight:700;color:#ffffff;line-height:1.3;">' . esc_html( $kop ) . '</div>'
            . '<div style="margin-top:5px;font-size:13px;color:#ffffff;opacity:0.85;">'
            . esc_html( $site ) . ' &middot; ' . esc_html( current_time( 'd-m-Y H:i' ) ) . '</div>'
            . '</td></tr>'

            . '<tr><td style="padding:20px 28px 0;font-size:14px;color:#50575e;">'
            . esc_html( implode( ' · ', $telling ) ) . '</td></tr>'

            . $rijen
            . $noten

            . '<tr><td style="padding:24px 28px 26px;">'
            . '<a href="' . esc_url( admin_url( 'admin.php?page=wpcf7' ) ) . '"'
            . ' style="display:inline-block;background:#1d2327;color:#ffffff;text-decoration:none;font-size:14px;padding:11px 18px;border-radius:6px;">'
            . 'Formulieren bekijken</a></td></tr>'

            . '<tr><td style="background:#f6f7f7;padding:15px 28px;font-size:12px;color:#787c82;line-height:1.5;">'
            . 'Automatische controle van alle Contact Form 7-formulieren, elke nacht om 06:00. '
            . 'De formulieren zelf versturen daarbij geen mail.</td></tr>'

            . '</table></td></tr></table></body></html>';
    }

    /** Eén blok in de mail: een kopje met daaronder de formulieren. */
    private function html_group( string $kop, array $items, string $kleur ): string {
        if ( empty( $items ) ) {
            return '';
        }

        $html = '<tr><td style="padding:20px 28px 2px;font-size:11px;font-weight:700;letter-spacing:0.08em;'
            . 'text-transform:uppercase;color:#787c82;">' . esc_html( $kop ) . '</td></tr>';

        foreach ( $items as $item ) {
            $naam = '' !== $item['title'] ? $item['title'] : 'Onbekend formulier';
            $naam = $item['id']
                ? '<a href="' . esc_url( admin_url( 'admin.php?page=wpcf7&post=' . (int) $item['id'] . '&action=edit' ) ) . '"'
                    . ' style="color:#1d2327;text-decoration:none;border-bottom:1px solid #dcdcde;">' . esc_html( $naam ) . '</a>'
                : esc_html( $naam );

            $detail = '' !== $item['detail']
                ? '<div style="margin:4px 0 0 18px;font-size:13px;color:#646970;line-height:1.5;">'
                    . esc_html( $item['detail'] ) . '</div>'
                : '';

            $html .= '<tr><td style="padding:0 28px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #f0f0f1;">'
                . '<tr><td style="padding:11px 0;font-size:15px;color:#1d2327;line-height:1.4;">'
                . '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'
                . esc_attr( $kleur ) . ';margin-right:10px;"></span>'
                . $naam . $detail
                . '</td></tr></table></td></tr>';
        }

        return $html;
    }

    /** De uitslagen van deze run met een bepaalde status. */
    private function results_with_status( string $status ): array {
        return array_values( array_filter( $this->results, static function ( $item ) use ( $status ) {
            return $item['status'] === $status;
        } ) );
    }
}
