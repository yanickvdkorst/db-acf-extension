<?php

namespace DB_ACF_UI;

/**
 * Vet-knop op gewone ACF tekstvelden.
 *
 * ACF heeft geen toolbar op een `text`-veld, dus om een deel van bijvoorbeeld een
 * titel vet te maken moet je met de hand <strong>…</strong> typen. Deze class zet
 * er een B-knop bij (plus Cmd/Ctrl + B) die de selectie in- en uitpakt.
 *
 * Bewust OPT-IN per veld: niet elk tekstveld wordt door het theme als HTML
 * uitgevoerd. In een alt-tekst, een title-attribuut of een meta-tag zou de lezer
 * de tags letterlijk zien staan. Aanzetten doe je in de veldinstellingen onder
 * "Vetgedrukt toestaan".
 *
 * Alles in één keer aanzetten kan met de filter, bijvoorbeeld voor elk veld
 * waarvan de naam op `titel` eindigt:
 *
 *     add_filter( 'db_acf_ui/allow_bold', function ( $enabled, $field ) {
 *         return $enabled || str_ends_with( $field['name'] ?? '', 'titel' );
 *     }, 10, 2 );
 *
 * Regelovergangen zijn een tweede, losse schakelaar ("Regelovergang toestaan",
 * filter `db_acf_ui/allow_br`): in een titel wil je die soms wel, in een
 * knoptekst of een naam juist niet. Staat hij uit, dan doet Enter niets. Een
 * <br> die al in de waarde staat blijft hoe dan ook staan — die weggooien zou
 * bestaande pagina's veranderen.
 */
class Bold_Toolbar {

    /** Naam van de veldinstelling; komt zo ook in de field group JSON te staan. */
    public const SETTING = 'db_allow_bold';

    /** Idem, voor regelovergangen. Los aan te zetten, want lang niet elke titel
     *  mag over meerdere regels lopen. */
    public const SETTING_BR = 'db_allow_br';

    /** Class op de veld-wrapper waar de JS op zoekt. */
    public const WRAPPER_CLASS = 'db-acf-has-bold';

    /** Idem; staat alleen op velden waar Enter een <br> mag maken. */
    public const WRAPPER_CLASS_BR = 'db-acf-allows-br';

    /**
     * Veldtypes met één enkele tekstinput. `textarea` bewust niet: daar levert
     * losse HTML sneller rommel op, en `wysiwyg` heeft al een eigen toolbar.
     */
    private const SUPPORTED_TYPES = [ 'text' ];

    public function register(): void {
        foreach ( self::SUPPORTED_TYPES as $type ) {
            add_action( "acf/render_field_settings/type={$type}", [ $this, 'render_setting' ] );
        }

        add_filter( 'acf/prepare_field', [ $this, 'mark_field' ] );
    }

    /**
     * De schakelaar in de veldinstellingen (tabblad Algemeen).
     *
     * Bewust zonder instructie-tekst. Let bij het aanzetten wel op: doe dit
     * alleen bij velden die het theme als HTML uitvoert. Bij een alt-tekst, een
     * title-attribuut of een meta-tag zou de bezoeker de tags letterlijk zien.
     */
    public function render_setting( $field ): void {
        acf_render_field_setting( $field, [
            'label'         => __( 'Vetgedrukt toestaan', 'db-acf-ui' ),
            'name'          => self::SETTING,
            'type'          => 'true_false',
            'ui'            => 1,
            'default_value' => 0,
        ] );

        // Alleen zinvol bij een veld met de vet-knop: zonder die knop is er geen
        // editor waarin Enter iets kan doen. Vandaar de conditie.
        acf_render_field_setting( $field, [
            'label'         => __( 'Regelovergang toestaan', 'db-acf-ui' ),
            'instructions'  => __( 'Met Enter maak je een nieuwe regel (&lt;br&gt;) in dit veld.', 'db-acf-ui' ),
            'name'          => self::SETTING_BR,
            'type'          => 'true_false',
            'ui'            => 1,
            'default_value' => 0,
            'conditions'    => [
                'field'    => self::SETTING,
                'operator' => '==',
                'value'    => 1,
            ],
        ] );
    }

    /**
     * Zet de wrapper-class op velden waar de knop moet verschijnen.
     */
    public function mark_field( $field ) {
        if ( ! is_array( $field ) || empty( $field['type'] ) ) {
            return $field;
        }

        if ( ! in_array( $field['type'], self::SUPPORTED_TYPES, true ) ) {
            return $field;
        }

        // Op het field group-scherm rendert ACF zijn eigen instellingen óók als
        // tekstvelden (Label, Naam, Standaardwaarde). Die willen we overslaan,
        // anders krijgt de veldeditor zelf overal een B-knop.
        if ( $this->is_field_group_editor() ) {
            return $field;
        }

        if ( ! $this->is_enabled_for( $field ) ) {
            return $field;
        }

        $classes = [ $field['wrapper']['class'] ?? '', self::WRAPPER_CLASS ];

        if ( $this->is_br_enabled_for( $field ) ) {
            $classes[] = self::WRAPPER_CLASS_BR;
        }

        $field['wrapper']['class'] = trim( implode( ' ', array_filter( $classes ) ) );

        return $field;
    }

    /**
     * Staat de knop aan voor dit veld? De veldinstelling is leidend; de filter
     * maakt het mogelijk om 'm site-breed op naam aan te zetten.
     */
    private function is_enabled_for( array $field ): bool {
        $enabled = ! empty( $field[ self::SETTING ] );

        return (bool) apply_filters( 'db_acf_ui/allow_bold', $enabled, $field );
    }

    /**
     * Mag Enter in dit veld een regelovergang maken?
     */
    private function is_br_enabled_for( array $field ): bool {
        $enabled = ! empty( $field[ self::SETTING_BR ] );

        return (bool) apply_filters( 'db_acf_ui/allow_br', $enabled, $field );
    }

    /**
     * Draaien we op het bewerkscherm van een ACF field group?
     */
    private function is_field_group_editor(): bool {
        static $is_editor = null;

        if ( null !== $is_editor ) {
            return $is_editor;
        }

        $is_editor = false;

        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            $is_editor = $screen && 'acf-field-group' === $screen->post_type;
        }

        return $is_editor;
    }
}
