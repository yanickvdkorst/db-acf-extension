<?php

namespace DB_ACF_UI;

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
                'uitleg' => __( 'Dient elke nacht om 06:00 alle Contact Form 7-formulieren in en mailt de uitslag, zodat je kapotte formulieren merkt vóór een bezoeker. Eén formulier mailt echt, als test van de SMTP-instellingen.', 'db-acf-ui' ),
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
        add_filter( 'plugin_action_links_' . plugin_basename( DB_ACF_UI_FILE ), [ $this, 'action_link' ] );
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
        </div>
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
