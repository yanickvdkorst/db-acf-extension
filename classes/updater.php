<?php

namespace DB_ACF_UI;

/**
 * Auto-update vanaf GitHub Releases.
 *
 * Belangrijkste regel: vergelijk de release-versie met de versie die op SCHIJF
 * staat, niet met de constante uit het geheugen. Tijdens een update draait
 * WordPress meteen na het installeren opnieuw een update-check
 * (`upgrader_process_complete`), en op dat moment is de constante nog de óude
 * versie omdat het oude bestand aan het begin van het verzoek geladen is. Wie
 * daarmee vergelijkt, schrijft direct na een geslaagde update een nieuwe
 * "update beschikbaar" in de transient — en die blijft daar een uur staan,
 * want WordPress checkt op de pluginpagina niet vaker (zie wp_update_plugins).
 * Dat is de reden dat je twee keer moest updaten.
 *
 * WordPress zet de schijfversie zelf al klaar in `$transient->checked`.
 */
class Updater {

    /** Hoe lang het antwoord van GitHub bewaard blijft. */
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** Kortere bewaartijd na een mislukte aanroep, zodat een storing snel herstelt. */
    private const FAIL_TTL = 15 * MINUTE_IN_SECONDS;

    private const CACHE_KEY = 'db_acf_ui_latest_release';

    /** Onthoudt voor welke versie de update-cache voor het laatst is gewist. */
    private const PURGE_OPTION = 'db_acf_ui_purged_for_version';

    public static function register(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_update' ] );
        add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 20, 3 );

        // Zelfherstel: bij de eerste adminpagina ná een versiewissel de
        // update-gegevens weggooien en opnieuw laten ophalen. Vangt ook een
        // verouderde melding op die door een oudere versie is achtergelaten.
        add_action( 'admin_init', [ __CLASS__, 'maybe_purge' ] );

        // Na élke plugin-update onze eigen cache legen.
        add_action( 'upgrader_process_complete', [ __CLASS__, 'flush_cache' ], 10, 0 );
    }

    // ─── Update-melding ────────────────────────────────────────────────────

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( DB_ACF_UI_FILE );

        // De versie van schijf. Zie de uitleg bovenaan deze class.
        $installed = $transient->checked[ $plugin_file ] ?? DB_ACF_UI_VERSION;

        $release = self::get_latest_release();
        if ( null === $release ) {
            return $transient; // GitHub onbereikbaar — laat de vorige stand staan.
        }

        $has_update = version_compare( $release['version'], $installed, '>' )
            && '' !== $release['package'];

        $info = (object) [
            'id'           => 'github.com/' . self::repo(),
            'slug'         => DB_ACF_UI_SLUG,
            'plugin'       => $plugin_file,
            'new_version'  => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'tested'       => get_bloginfo( 'version' ),
            'requires_php' => DB_ACF_UI_MIN_PHP_VERSION,
        ];

        if ( $has_update ) {
            unset( $transient->no_update[ $plugin_file ] );
            $transient->response[ $plugin_file ] = $info;
        } else {
            // Actief opruimen: haalt ook een verouderde melding weg die een
            // eerdere versie heeft achtergelaten.
            unset( $transient->response[ $plugin_file ] );
            $transient->no_update[ $plugin_file ] = $info;
        }

        return $transient;
    }

    // ─── Detailvenster ─────────────────────────────────────────────────────

    public static function plugin_info( $res, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $res;
        }
        if ( empty( $args->slug ) || DB_ACF_UI_SLUG !== $args->slug ) {
            return $res;
        }

        $release = self::get_latest_release();
        if ( null === $release ) {
            return $res; // Liever het standaardgedrag dan een half gevuld venster.
        }

        return (object) [
            'name'          => 'DB ACF Extension',
            'slug'          => DB_ACF_UI_SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://digitalebazen.nl">Digitale Bazen</a>',
            'homepage'      => $release['url'],
            'requires_php'  => DB_ACF_UI_MIN_PHP_VERSION,
            'last_updated'  => $release['released'],
            'download_link' => $release['package'],
            'sections'      => [
                'description' => 'Aangepaste ACF interface voor Digitale Bazen.',
                'changelog'   => '' !== $release['notes']
                    ? wpautop( wp_kses_post( $release['notes'] ) )
                    : 'Geen changelog opgegeven.',
            ],
        ];
    }

    // ─── Cache ─────────────────────────────────────────────────────────────

    public static function maybe_purge(): void {
        if ( get_option( self::PURGE_OPTION ) === DB_ACF_UI_VERSION ) {
            return;
        }

        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );
        update_option( self::PURGE_OPTION, DB_ACF_UI_VERSION, false );
    }

    public static function flush_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    // ─── GitHub ────────────────────────────────────────────────────────────

    /**
     * De nieuwste release, uit de cache of vers opgehaald.
     *
     * Zonder cache belde de plugin GitHub bij élke update-check én bij elk
     * detailvenster. Ongeauthenticeerd staat GitHub 60 verzoeken per uur per
     * IP toe; daarboven komt er niets meer terug en verdween de update-melding
     * zomaar.
     *
     * @return array{version:string,url:string,package:string,notes:string,released:string}|null
     */
    private static function get_latest_release(): ?array {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $cached : null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::repo() . '/releases/latest',
            [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
                    'Accept'     => 'application/vnd.github+json',
                ],
            ]
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            set_transient( self::CACHE_KEY, 'failed', self::FAIL_TTL );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! is_object( $data ) || empty( $data->tag_name ) ) {
            set_transient( self::CACHE_KEY, 'failed', self::FAIL_TTL );
            return null;
        }

        $release = [
            'version'  => ltrim( (string) $data->tag_name, 'vV' ),
            'url'      => (string) ( $data->html_url ?? '' ),
            'package'  => self::find_package( $data ),
            'notes'    => (string) ( $data->body ?? '' ),
            'released' => (string) ( $data->published_at ?? '' ),
        ];

        set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

        return $release;
    }

    /**
     * De zip die bij de release hangt. Bewust géén teruggevallen op de door
     * GitHub gegenereerde bron-zip: die pakt uit naar een map met de tagnaam
     * erin, waardoor WordPress de plugin naast de bestaande installeert in
     * plaats van eroverheen. Zonder bruikbare zip bieden we liever geen update
     * aan dan een kapotte.
     */
    private static function find_package( object $data ): string {
        $wanted = DB_ACF_UI_SLUG . '.zip';

        foreach ( (array) ( $data->assets ?? [] ) as $asset ) {
            if ( isset( $asset->name, $asset->browser_download_url ) && $wanted === $asset->name ) {
                return (string) $asset->browser_download_url;
            }
        }

        return '';
    }

    private static function repo(): string {
        return (string) apply_filters( 'db_acf_ui/github_repo', DB_ACF_UI_GITHUB_REPO );
    }
}
