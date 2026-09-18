<?php
/**
 * Special cases: our own rows, site address options written last, protected
 * columns, transients, cache invalidation after writes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Special cases: rows and columns that are protected by default, the site address
 * options that must be written last, and cache invalidation after a write.
 */
class BESR_Rules {

    /**
     * Columns excluded unless the user opts in. column => reason code
     */
    public static function column_exclusions( array $desc, array $config ): array {
        global $wpdb;
        $out  = array();
        $name = $desc['name'];
        $cols = array_column( $desc['columns'], 'name' );

        if ( $name === $wpdb->posts && in_array( 'guid', $cols, true ) && empty( $config['include_guid'] ) ) {
            $out['guid'] = 'guid';
        }
        if ( empty( $config['include_identity'] ) ) {
            $identity = array(
                $wpdb->users    => array( 'user_login', 'user_email', 'user_pass', 'user_activation_key' ),
                $wpdb->comments => array( 'comment_author_email' ),
            );
            if ( is_multisite() ) {
                $identity[ $wpdb->signups ] = array( 'user_login', 'user_email', 'activation_key' );
            }
            foreach ( $identity[ $name ] ?? array() as $col ) {
                if ( in_array( $col, $cols, true ) ) {
                    $out[ $col ] = 'identity';
                }
            }
        } elseif ( $name === $wpdb->users && in_array( 'user_pass', $cols, true ) ) {
            $out['user_pass'] = 'identity';
        }
        /**
         * Filter column exclusions for a table. column => reason.
         */
        return (array) apply_filters( 'besr_column_exclusions', $out, $desc, $config );
    }

    public static function exclusion_label( string $reason ): string {
        switch ( $reason ) {
            case 'guid':
                return __( 'GUID (opt-in)', 'best-search-replace' );
            case 'identity':
                return __( 'protected sign-in column (opt-in)', 'best-search-replace' );
        }
        return $reason;
    }

    /**
     * Rows that are never touched. Returns a reason code or null.
     */
    public static function skip_row( array $desc, array $row, array $config ): ?string {
        global $wpdb;
        if ( $desc['name'] === $wpdb->options && isset( $row['option_name'] ) ) {
            $opt = (string) $row['option_name'];
            if ( 0 === strpos( $opt, 'besr_' ) ) {
                return 'own_option';
            }
            if ( empty( $config['include_transients'] ) && ( 0 === strpos( $opt, '_transient_' ) || 0 === strpos( $opt, '_site_transient_' ) ) ) {
                return 'transient';
            }
        }
        if ( is_multisite() && $desc['name'] === $wpdb->sitemeta && isset( $row['meta_key'] ) ) {
            $k = (string) $row['meta_key'];
            if ( 0 === strpos( $k, 'besr_' ) ) {
                return 'own_option';
            }
            if ( empty( $config['include_transients'] ) && 0 === strpos( $k, '_site_transient_' ) ) {
                return 'transient';
            }
        }
        return null;
    }

    /**
     * Siteurl/home are written last so the admin keeps working during the run.
     */
    public static function defer_row( array $desc, array $row ): bool {
        global $wpdb;
        return $desc['name'] === $wpdb->options && isset( $row['option_name'] ) && in_array( $row['option_name'], array( 'siteurl', 'home' ), true );
    }

    /**
     * Warnings shown before the scan starts.
     *
     * @return array [ ['code','level','message'] ]
     */
    public static function preflight( array $config ): array {
        $w       = array();
        $search  = (string) $config['search'];
        $replace = (string) $config['replace'];
        $t       = BESR_Term::parse( $search );

        if ( '' === $replace ) {
            $w[] = array( 'code' => 'empty_replace', 'level' => 'warning', 'message' => __( '"Replace with" is empty, so every match will be removed. Check the review screen carefully.', 'best-search-replace' ) );
        }
        if ( '' !== $replace && false !== strpos( $replace, $search ) ) {
            $w[] = array( 'code' => 'replace_contains_search', 'level' => 'warning', 'message' => __( '"Replace with" contains "Search for". Running this twice would double it up; run it once and check History.', 'best-search-replace' ) );
        }
        if ( strlen( $search ) < 4 ) {
            $w[] = array( 'code' => 'short_search', 'level' => 'warning', 'message' => __( 'Short text like this appears in many places. Expect a lot of matches; use the review filters.', 'best-search-replace' ) );
        }
        if ( $t['is_hostlike'] ) {
            $rt = BESR_Term::parse( $replace );
            if ( $rt['is_hostlike'] && ( '/' === substr( $search, -1 ) ) !== ( '/' === substr( $replace, -1 ) ) ) {
                $w[] = array( 'code' => 'slash_mismatch', 'level' => 'warning', 'message' => __( 'One address ends with "/" and the other does not. Usually both should match.', 'best-search-replace' ) );
            }
            $home = (string) get_option( 'home' );
            $hh   = wp_parse_url( $home, PHP_URL_HOST );
            if ( $hh && false !== stripos( $hh, $t['bare_host'] ) ) {
                $w[] = array( 'code' => 'siteurl_change', 'level' => 'warning', 'message' => __( "You are changing this site's own address. The site address settings are written last; make sure the new address points at this server, and expect to sign in again there.", 'best-search-replace' ) );
                if ( defined( 'WP_HOME' ) || defined( 'WP_SITEURL' ) ) {
                    $w[] = array( 'code' => 'siteurl_constant', 'level' => 'info', 'message' => __( 'WP_HOME or WP_SITEURL is defined in wp-config.php; the stored setting will change but has no effect until you update the constant.', 'best-search-replace' ) );
                }
            }
        }
        if ( is_multisite() ) {
            $w[] = array( 'code' => 'multisite', 'level' => 'info', 'message' => __( 'Only this site\'s tables are searched. The network site list (wp_blogs) is not changed by this tool.', 'best-search-replace' ) );
        }
        /**
         * Filter preflight warnings.
         */
        return (array) apply_filters( 'besr_preflight_warnings', $w, $config );
    }

    /**
     * Cache invalidation after apply or undo. Returns log lines.
     */
    public static function after_write( BESR_Run $run, bool $options_touched ): array {
        $lines = array();
        $ok    = wp_cache_flush();
        if ( ! $ok ) {
            if ( function_exists( 'wp_cache_flush_runtime' ) ) {
                wp_cache_flush_runtime();
            }
            $lines[] = 'Persistent object cache could not be flushed automatically. Flush it from your host panel.';
        } else {
            $lines[] = 'Object cache flushed.';
        }
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        if ( $options_touched ) {
            delete_option( 'rewrite_rules' );
            $lines[] = 'Rewrite rules reset; WordPress rebuilds them on the next request.';
        }
        return $lines;
    }
}
