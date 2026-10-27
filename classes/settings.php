<?php

namespace DB_ACF_UI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Instellingenpagina: welke onderdelen van de plugin draaien op deze site?
 *
 * De plugin staat op elke klantsite, maar niet elke site wil alles. Hier zet je
 * per onderdeel aan of uit, onder Instellingen → DB ACF Extensie.
 *
 * Alles staat standaard aan: een site die alleen de update binnenhaalt merkt
 * dus niets. Pas als je hier iets uitvinkt verandert er gedrag.
 *
 * In code kan het ook, bijvoorbeeld vanuit een mu-plugin:
 *
 *     add_filter( 'db_acf_ui/module_enabled', function ( $enabled, $module ) {
 *         return 'cf7_healthcheck' === $module ? false : $enabled;
 *     }, 10, 2 );
 */
class Settings {

    public const OPTION = 'db_acf_ui_modules';

    private const PAGE_SLUG = 'db-acf-ui-modules';

    /** admin-post actie achter de knop "Nu testen". */
    private const RUN_ACTION = 'db_acf_ui_run_cf7_test';

    /** Waar de uitslag van die run tijdelijk staat, tot de pagina hem toont. */
    private const RESULT_KEY = 'db_acf_ui_cf7_result_';

    /**
     * De onderdelen, met de tekst die op de instellingenpagina komt.
     */
    public static function modules(): array {
        return [
            'layout_ui'       => [
                'label' => __( 'Aangepaste layout-interface', 'db-acf-ui' ),
                'uitleg' => __( 'Flexible content-layouts openen in een modal, met potlood-knop, zichtbaarheidsknop en sluiten met Esc. Bij "Layout toevoegen" krijg je grote kaarten met een voorbeeldafbeelding per blok, uit <code>lib/admin/images/acf-flexible-content-extended</code> in het thema. Uit = de standaardweergave van ACF.', 'db-acf-ui' ),
            ],
            'bold_toolbar'    => [
                'label' => __( 'Vetgedrukt op tekstvelden', 'db-acf-ui' ),
                'uitleg' => __( 'Zet een B-knop op tekstvelden waar dat per veld is aangezet. Uit = die velden werken weer als een gewoon tekstveld; wat er al vet staat blijft staan.', 'db-acf-ui' ),
            ],
            'cf7_healthcheck' => [
                'label' => __( 'Dagelijkse formulier-check', 'db-acf-ui' ),
                'uitleg' => __( 'Dient elke nacht om 06:00 alle Contact Form 7-formulieren in en mailt de uitslag, zodat je kapotte formulieren merkt vóór een bezoeker. De formulieren zelf versturen geen mail; je krijgt alleen het overzicht.', 'db-acf-ui' ),
            ],
        ];
    }

    /** Standaard: alles aan, zodat een update niets verandert. */
    public static function defaults(): array {
        return array_fill_keys( array_keys( self::modules() ), true );
    }

    /**
     * Draait dit onderdeel op deze site?
     */
    public static function enabled( string $module ): bool {
        $opgeslagen = get_option( self::OPTION, [] );
        $enabled    = is_array( $opgeslagen ) && array_key_exists( $module, $opgeslagen )
            ? (bool) $opgeslagen[ $module ]
            : true; // onbekend of nooit opgeslagen: aan

        return (bool) apply_filters( 'db_acf_ui/module_enabled', $enabled, $module );
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_page' ] );
        add_action( 'admin_init', [ $this, 'register_setting' ] );
        add_action( 'admin_post_' . self::RUN_ACTION, [ $this, 'run_cf7_test' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( DB_ACF_UI_FILE ), [ $this, 'action_link' ] );
    }

    /**
     * De knop "Nu testen" op de instellingenpagina: draait de formulier-check
     * meteen en zet de uitslag klaar voor het scherm.
     *
     * Anders dan de nachtelijke run gaat er geen overzichtsmail uit — je ziet
     * het resultaat immers voor je neus.
     */
    public function run_cf7_test(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Geen toegang.', 'db-acf-ui' ) );
        }

        check_admin_referer( self::RUN_ACTION );

        // Dezelfde twee voorwaarden als voor de knop zelf: de pagina toont hem
        // niet in deze gevallen, maar het verzoek moet er ook niet langs kunnen.
        if ( ! self::enabled( 'cf7_healthcheck' ) ) {
            wp_die( esc_html__( 'De formulier-check staat uit op deze site.', 'db-acf-ui' ) );
        }

        if ( function_exists( 'db_run_cf7_test' ) ) {
            wp_die( esc_html__( 'Het thema draait zijn eigen versie van deze controle.', 'db-acf-ui' ) );
        }

        $healthcheck = new CF7_Healthcheck();

        // Een achtergebleven lock van een afgebroken run mag een bewuste klik
        // niet stil blokkeren.
        delete_transient( 'db_cf7_running' );

        $healthcheck->run( false );

        set_transient( self::RESULT_KEY . get_current_user_id(), $healthcheck->get_log(), 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( add_query_arg( 'db-acf-tested', '1', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) );
        exit;
    }

    public function add_page(): void {
        add_options_page(
            __( 'DB ACF Extensie', 'db-acf-ui' ),
            __( 'DB ACF Extensie', 'db-acf-ui' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render' ]
        );
    }

    /** Snelkoppeling vanaf het plugin-overzicht. */
    public function action_link( $links ) {
        $url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
        array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Instellingen', 'db-acf-ui' ) ) );

        return $links;
    }

    public function register_setting(): void {
        register_setting( self::PAGE_SLUG, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize' ],
            'default'           => self::defaults(),
        ] );
    }

    /**
     * Alleen de bekende onderdelen opslaan, als echte booleans. Een vinkje dat
     * niet is meegestuurd staat uit — zo werkt een HTML-checkbox nu eenmaal.
     */
    public function sanitize( $input ): array {
        $was = get_option( self::OPTION, [] );
        $uit = [];

        $schoon = [];
        foreach ( array_keys( self::modules() ) as $module ) {
            $aan = ! empty( $input[ $module ] );
            $schoon[ $module ] = $aan;

            $stond_aan = ! is_array( $was ) || ! array_key_exists( $module, $was ) || $was[ $module ];
            if ( $stond_aan && ! $aan ) {
                $uit[] = $module;
            }
        }

        // De formulier-check heeft een geplande taak; die ruimen we meteen op als
        // het onderdeel uitgaat, anders blijft hij tot in de eeuwigheid staan.
        if ( in_array( 'cf7_healthcheck', $uit, true ) ) {
            CF7_Healthcheck::unschedule();
        }

        return $schoon;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Geen toegang.', 'db-acf-ui' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'DB ACF Extensie', 'db-acf-ui' ); ?></h1>
            <p><?php esc_html_e( 'Kies wat de plugin op deze site doet. Alles staat standaard aan.', 'db-acf-ui' ); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields( self::PAGE_SLUG ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                    <?php foreach ( self::modules() as $module => $info ) : ?>
                        <?php
                        $aan       = self::enabled( $module );
                        $door_code = $aan !== $this->stored( $module );
                        ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $info['label'] ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $module ); ?>]"
                                           value="1" <?php checked( $this->stored( $module ) ); ?>>
                                    <?php esc_html_e( 'Aan', 'db-acf-ui' ); ?>
                                </label>
                                <p class="description"><?php echo wp_kses( $info['uitleg'], [ 'code' => [] ] ); ?></p>
                                <?php if ( $door_code ) : ?>
                                    <p class="description" style="color:#b32d2e">
                                        <?php
                                        echo esc_html(
                                            $aan
                                                ? __( 'Staat via code aan (filter db_acf_ui/module_enabled), ongeacht dit vinkje.', 'db-acf-ui' )
                                                : __( 'Staat via code uit (filter db_acf_ui/module_enabled), ongeacht dit vinkje.', 'db-acf-ui' )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php $this->render_cf7_test_box(); ?>
        </div>
        <?php
    }

    /**
     * Het blokje onderaan: knop om de formulier-check nu te draaien, en de
     * uitslag van de vorige klik.
     */
    private function render_cf7_test_box(): void {
        if ( ! self::enabled( 'cf7_healthcheck' ) ) {
            return;
        }

        $sleutel = self::RESULT_KEY . get_current_user_id();
        $log     = get_transient( $sleutel );
        if ( false !== $log ) {
            delete_transient( $sleutel );
        }
        ?>
        <hr>
        <h2><?php esc_html_e( 'Formulieren nu testen', 'db-acf-ui' ); ?></h2>
        <p>
            <?php esc_html_e( 'Draait de controle meteen, zonder op vannacht te wachten. Alle formulieren worden ingediend; er gaat geen overzichtsmail uit. Bij veel formulieren duurt dit even.', 'db-acf-ui' ); ?>
        </p>

        <?php if ( function_exists( 'db_run_cf7_test' ) ) : ?>
            <p class="description" style="color:#b32d2e">
                <?php esc_html_e( 'Het thema bevat nog een eigen versie van deze controle; die heeft voorrang. Haal het blok uit functions-root.php om deze knop te gebruiken.', 'db-acf-ui' ); ?>
            </p>
            <?php return; ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::RUN_ACTION ); ?>">
            <?php wp_nonce_field( self::RUN_ACTION ); ?>
            <?php submit_button( __( 'Nu testen', 'db-acf-ui' ), 'secondary', 'submit', false ); ?>
        </form>

        <?php if ( is_array( $log ) ) : ?>
            <?php
            $mislukt = count( array_filter( $log, static function ( $regel ) {
                return false !== strpos( $regel, 'FAILED' );
            } ) );
            ?>
            <div class="notice <?php echo $mislukt ? 'notice-error' : 'notice-success'; ?>" style="margin-top:1em">
                <p>
                    <strong>
                        <?php
                        if ( empty( $log ) ) {
                            esc_html_e( 'Geen formulieren gevonden.', 'db-acf-ui' );
                        } elseif ( $mislukt ) {
                            printf(
                                esc_html( _n( '%d probleem gevonden.', '%d problemen gevonden.', $mislukt, 'db-acf-ui' ) ),
                                (int) $mislukt
                            );
                        } else {
                            esc_html_e( 'Alles OK.', 'db-acf-ui' );
                        }
                        ?>
                    </strong>
                </p>
                <?php if ( ! empty( $log ) ) : ?>
                    <pre style="white-space:pre-wrap;margin:0 0 1em"><?php echo esc_html( implode( "\n", $log ) ); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /** De opgeslagen stand, dus zonder de filter eroverheen. */
    private function stored( string $module ): bool {
        $opgeslagen = get_option( self::OPTION, [] );

        return ! is_array( $opgeslagen ) || ! array_key_exists( $module, $opgeslagen )
            ? true
            : (bool) $opgeslagen[ $module ];
    }
}
