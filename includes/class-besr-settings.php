<?php
/**
 * Plugin settings: one option, sane defaults, explicit sanitizers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin settings: one option, sane defaults and explicit sanitizers.
 */
class BESR_Settings {

    const OPTION = 'besr_settings';

    public static function defaults(): array {
        return array(
            'retention_days'     => 30,
            'keep_runs'          => 20,
            'default_variants'   => array( 'exact', 'scheme', 'www', 'json', 'urlencoded', 'scheme_relative' ),
            'default_excluded'   => array( 'inside_email', 'longer_domain', 'inside_word', 'in_hash_like_string', 'in_serialized_key' ),
            'include_logs'       => false,
            'budget'             => 0,     // seconds per request; 0 = automatic.
            'batch_rows'         => 0,     // 0 = adaptive
            'uninstall_remove'   => false,
        );
    }

    public static function all(): array {
        $stored = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
    }

    public static function get( string $key ) {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    public static function save( array $input ): array {
        $clean = self::sanitize( $input );
        update_option( self::OPTION, $clean, false );
        return $clean;
    }

    public static function sanitize( array $input ): array {
        $d                         = self::defaults();
        $clean                     = array();
        $clean['retention_days']   = isset( $input['retention_days'] ) ? max( 0, min( 3650, (int) $input['retention_days'] ) ) : $d['retention_days'];
        $clean['keep_runs']        = isset( $input['keep_runs'] ) ? max( 1, min( 500, (int) $input['keep_runs'] ) ) : $d['keep_runs'];
        $clean['default_variants'] = array_values( array_intersect( (array) ( $input['default_variants'] ?? $d['default_variants'] ), BESR_Ruleset::VARIANT_KEYS ) );
        $clean['default_excluded'] = array_values( array_intersect( (array) ( $input['default_excluded'] ?? $d['default_excluded'] ), BESR_Context::TOGGLEABLE ) );
        $clean['include_logs']     = ! empty( $input['include_logs'] );
        $clean['budget']           = isset( $input['budget'] ) ? max( 0, min( 300, (float) $input['budget'] ) ) : 0;
        $clean['batch_rows']       = isset( $input['batch_rows'] ) ? max( 0, min( 50000, (int) $input['batch_rows'] ) ) : 0;
        $clean['uninstall_remove'] = ! empty( $input['uninstall_remove'] );
        return $clean;
    }

    public static function capability(): string {
        /**
         * Filter the capability required to use Best Search Replace.
         *
         * @param string $cap Default 'manage_options'.
         */
        return (string) apply_filters( 'besr_capability', 'manage_options' );
    }
}
