<?php
/**
 * Friendly content groups for the table picker, so nobody has to know that
 * "wp_postmeta" is where their custom fields live.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Friendly content groups for the table picker.
 */
class BESR_Groups {

    public static function definitions(): array {
        $defs = array(
            'content'     => array(
                'label'   => __( 'Posts, pages & custom content', 'best-search-replace' ),
                'hint'    => __( 'Post content, titles, excerpts, block data and custom fields (postmeta).', 'best-search-replace' ),
                'tables'  => array( 'posts', 'postmeta' ),
                'default' => true,
            ),
            'settings'    => array(
                'label'   => __( 'Site settings', 'best-search-replace' ),
                'hint'    => __( 'Site address, theme and plugin settings, widgets (wp_options).', 'best-search-replace' ),
                'tables'  => array( 'options' ),
                'default' => true,
            ),
            'taxonomy'    => array(
                'label'   => __( 'Categories, tags & menus', 'best-search-replace' ),
                'hint'    => __( 'Terms, taxonomies, term meta and navigation menus.', 'best-search-replace' ),
                'tables'  => array( 'terms', 'term_taxonomy', 'termmeta', 'term_relationships' ),
                'default' => true,
            ),
            'comments'    => array(
                'label'   => __( 'Comments', 'best-search-replace' ),
                'hint'    => __( 'Comments and comment meta.', 'best-search-replace' ),
                'tables'  => array( 'comments', 'commentmeta' ),
                'default' => true,
            ),
            'users'       => array(
                'label'   => __( 'Users', 'best-search-replace' ),
                'hint'    => __( 'Profiles and user settings. E-mail and login columns are protected unless you opt in.', 'best-search-replace' ),
                'tables'  => array( 'users', 'usermeta' ),
                'default' => true,
                'global'  => true,
            ),
            'woocommerce' => array(
                'label'    => __( 'WooCommerce', 'best-search-replace' ),
                'hint'     => __( 'Orders, products lookup tables and store settings.', 'best-search-replace' ),
                'prefixes' => array( 'wc_', 'woocommerce_' ),
                'default'  => true,
            ),
            'plugins'     => array(
                'label'   => __( 'Plugin data', 'best-search-replace' ),
                'hint'    => __( 'Tables created by plugins, grouped by plugin.', 'best-search-replace' ),
                'dynamic' => true,
                'default' => true,
            ),
            'logs'        => array(
                'label'    => __( 'Logs & caches (usually safe to skip)', 'best-search-replace' ),
                'hint'     => __( 'Activity logs, statistics, sessions and caches. Plugins regenerate these; replacing inside them is rarely needed.', 'best-search-replace' ),
                'tables'   => array(
                    'actionscheduler_logs',
		'wc_admin_notes',
		'wc_admin_note_actions',
		'woocommerce_sessions',
		'wc_rate_limits',
		'wc_download_log',
                    'wfHits',
		'wfLogins',
		'wfStatus',
		'wfFileMods',
		'wfNotifications',
		'wfTrafficRates',
		'wfLiveTrafficHuman',
		'wfSNIPCache',
		'wfReverseCache',
                    'redirection_404',
		'redirection_logs',
		'wpforms_logs',
		'wpmailsmtp_debug_events',
		'wpmailsmtp_tasks_meta',
                    'itsec_logs',
		'aiowps_events',
		'aiowps_debug_log',
		'cerber_log',
		'cerber_traffic',
		'e_events',
		'gf_form_view',
		'wpo_404_detector',
                    'mailpoet_statistics_clicks',
		'mailpoet_statistics_opens',
		'yoast_indexable_hierarchy',
		'wpml_mails',
                ),
                'patterns' => array(
                    '/(^|_)(logs?|log_entries|debug|caches?|sessions?|hits|stats|statistics|tracking|audit|history|queue|jobs|transients|rate_?limits?|events?_log)$/i',
                    '/^simple_history/',
			'/^statistics_/',
			'/^litespeed_url/',
			'/^wsal_/',
			'/^aryo_activity_log/',
                ),
                'default'  => false,
            ),
            'other'       => array(
                'label'   => __( 'Other tables', 'best-search-replace' ),
                'hint'    => __( 'We could not tell what these belong to. Included by default so nothing is missed.', 'best-search-replace' ),
                'default' => true,
            ),
            'global'      => array(
                'label'          => __( 'Network-wide tables (shared by every site)', 'best-search-replace' ),
                'hint'           => __( 'Users, user meta and the network tables. Changes here affect every site in the network.', 'best-search-replace' ),
                'default'        => false,
                'multisite_only' => true,
            ),
        );
        /**
         * Filter the content group definitions.
         *
         * @param array $defs
         */
        return (array) apply_filters( 'besr_table_groups', $defs );
    }

    public static function known_prefixes(): array {
        $map = array(
            'gf_' => 'Gravity Forms',
		'rg_' => 'Gravity Forms (legacy)',
		'e_' => 'Elementor',
		'wpforms_' => 'WPForms',
		'yoast_' => 'Yoast SEO',
            'aioseo_' => 'All in One SEO',
		'rank_math_' => 'Rank Math',
		'actionscheduler_' => 'Action Scheduler',
		'wf' => 'Wordfence',
		'wfls_' => 'Wordfence Login Security',
            'redirection_' => 'Redirection',
		'icl_' => 'WPML',
		'wpml_' => 'WPML',
		'trp_' => 'TranslatePress',
		'pmxi_' => 'WP All Import',
		'pmxe_' => 'WP All Export',
            'frm_' => 'Formidable Forms',
		'nf3_' => 'Ninja Forms',
		'fluentform_' => 'Fluent Forms',
		'fc_' => 'FluentCRM',
		'edd_' => 'Easy Digital Downloads',
            'learndash_' => 'LearnDash',
		'pmpro_' => 'Paid Memberships Pro',
		'mepr_' => 'MemberPress',
		'wpmailsmtp_' => 'WP Mail SMTP',
		'itsec_' => 'Solid Security',
            'aiowps_' => 'All In One Security',
		'cerber_' => 'WP Cerber',
		'statistics_' => 'WP Statistics',
		'bp_' => 'BuddyPress',
		'um_' => 'Ultimate Member',
            'litespeed_' => 'LiteSpeed Cache',
		'wpo_' => 'WP-Optimize',
		'ewwwio_' => 'EWWW Image Optimizer',
		'smush_' => 'Smush',
		'as3cf_' => 'WP Offload Media',
            'tec_' => 'The Events Calendar',
		'gla_' => 'Google Listings & Ads',
		'hustle_' => 'Hustle',
		'mailpoet_' => 'MailPoet',
		'wpvivid_' => 'WPvivid',
            'duplicator_' => 'Duplicator',
		'yith_' => 'YITH',
		'wpgmza_' => 'WP Go Maps',
		'nextend2_' => 'Smart Slider 3',
		'revslider' => 'Slider Revolution',
            'layerslider' => 'LayerSlider',
		'wpstg' => 'WP Staging',
		'snippets' => 'Code Snippets',
		'wpdatatables' => 'wpDataTables',
		'amelia_' => 'Amelia',
		'bookly_' => 'Bookly',
            'wpdiscuz_' => 'wpDiscuz',
		'simple_history' => 'Simple History',
		'bsr_' => 'Better Search Replace',
		'wsal_' => 'WP Activity Log',
		'acf_' => 'Advanced Custom Fields',
            'cf7_' => 'Contact Form 7 add-ons',
		'db7_' => 'Contact Form 7 Database',
		'flamingo_' => 'Flamingo',
		'wc_' => 'WooCommerce',
		'woocommerce_' => 'WooCommerce',
            'jet_' => 'JetEngine',
		'wpr_' => 'Royal Addons',
		'ppress_' => 'ProfilePress',
		'wpcode_' => 'WPCode',
		'wpsc_' => 'WP Super Cache',
		'w3tc_' => 'W3 Total Cache',
            'wp_rocket' => 'WP Rocket',
		'wprocket' => 'WP Rocket',
		'seopress_' => 'SEOPress',
		'burst_' => 'Burst Statistics',
		'cmplz_' => 'Complianz',
		'rsssl_' => 'Really Simple SSL',
            'ninja_table' => 'Ninja Tables',
		'tablepress' => 'TablePress',
		'wpdm_' => 'Download Manager',
		'dlm_' => 'Download Monitor',
		'give_' => 'GiveWP',
		'charitable_' => 'Charitable',
            'affiliate_wp_' => 'AffiliateWP',
		'polylang' => 'Polylang',
		'wpsp_' => 'WP Show Posts',
		'social_' => 'Social plugins',
		'toolset_' => 'Toolset',
		'cptui' => 'Custom Post Type UI',
        );
        /**
         * Filter the table-prefix → plugin name map.
         *
         * @param array $map
         */
        return (array) apply_filters( 'besr_known_prefixes', $map );
    }

    /**
     * @return array ['group' => key, 'subgroup' => key|'', 'label' => string]
     */
    public static function classify( string $table ): array {
        global $wpdb;
        $un   = $table;
        $pref = $wpdb->prefix;
        if ( 0 === strpos( $table, $pref ) ) {
            $un = substr( $table, strlen( $pref ) );
        } elseif ( 0 === strpos( $table, $wpdb->base_prefix ) ) {
            $un = substr( $table, strlen( $wpdb->base_prefix ) );
        }
        $defs = self::definitions();

        foreach ( $defs as $key => $def ) {
            if ( ! empty( $def['tables'] ) && in_array( $un, $def['tables'], true ) ) {
                return array( 'group' => $key, 'subgroup' => '', 'label' => $def['label'] );
            }
        }
        if ( ! empty( $defs['logs']['patterns'] ) ) {
            foreach ( $defs['logs']['patterns'] as $re ) {
                if ( preg_match( $re, $un ) ) {
                    return array( 'group' => 'logs', 'subgroup' => '', 'label' => $defs['logs']['label'] );
                }
            }
        }
        foreach ( $defs as $key => $def ) {
            foreach ( (array) ( $def['prefixes'] ?? array() ) as $p ) {
                if ( 0 === strpos( $un, $p ) ) {
                    return array( 'group' => $key, 'subgroup' => '', 'label' => $def['label'] );
                }
            }
        }
        $best       = '';
        $best_label = '';
        foreach ( self::known_prefixes() as $p => $label ) {
            $hit = ( 'wf' === $p ) ? (bool) preg_match( '/^wf[A-Z]/', $un ) : 0 === strpos( $un, $p );
            if ( $hit && strlen( $p ) > strlen( $best ) ) {
                $best       = $p;
                $best_label = $label;
            }
        }
        if ( '' !== $best ) {
            return array( 'group' => 'plugins', 'subgroup' => 'plugins:' . $best, 'label' => $best_label );
        }
        if ( preg_match( '/^([a-z0-9]+)_/i', $un, $m ) && ! in_array( strtolower( $m[1] ), array( 'wp', 'term', 'post', 'user', 'comment' ), true ) ) {
            /* translators: %s: table name prefix */
            return array( 'group' => 'plugins', 'subgroup' => 'plugins:' . $m[1] . '_', 'label' => sprintf( __( 'Unknown plugin (prefix %s)', 'best-search-replace' ), $m[1] . '_' ) );
        }
        return array( 'group' => 'other', 'subgroup' => '', 'label' => $defs['other']['label'] );
    }

    /**
     * The tree the picker and the CLI render.
     */
    public static function build( bool $include_global = false ): array {
        global $wpdb;
        $defs   = self::definitions();
        $status = BESR_Tables::status_all();
        $tables = BESR_Tables::discover();
        $groups = array();
        foreach ( $defs as $key => $def ) {
            if ( ! empty( $def['multisite_only'] ) && ! is_multisite() ) {
                continue;
            }
            $groups[ $key ] = array(
                'key'      => $key,
                'label'    => $def['label'],
                'hint'     => $def['hint'] ?? '',
                'default'  => ! empty( $def['default'] ),
                'tables'   => array(),
                'children' => array(),
                'rows'     => 0,
            );
        }

        $add = function ( string $table, string $group, string $sub, string $sublabel ) use ( &$groups, $status ) {
            $st  = $status[ $table ] ?? array( 'rows' => 0, 'size_bytes' => 0, 'engine' => '' );
            $row = array(
                'name'       => $table,
                'rows'       => (int) $st['rows'],
                'size_bytes' => (int) $st['size_bytes'],
                'engine'     => $st['engine'],
            );
            if ( $sub ) {
                if ( ! isset( $groups[ $group ]['children'][ $sub ] ) ) {
                    $groups[ $group ]['children'][ $sub ] = array( 'key' => $sub, 'label' => $sublabel, 'tables' => array(), 'rows' => 0 );
                }
                $groups[ $group ]['children'][ $sub ]['tables'][] = $row;
                $groups[ $group ]['children'][ $sub ]['rows']    += $row['rows'];
            } else {
                $groups[ $group ]['tables'][] = $row;
            }
            $groups[ $group ]['rows'] += $row['rows'];
        };

        foreach ( $tables as $t ) {
            $c = self::classify( $t );
            if ( ! isset( $groups[ $c['group'] ] ) ) {
                $c['group'] = 'other';
            }
            $add( $t, $c['group'], $c['subgroup'], $c['label'] );
        }
        if ( $include_global && isset( $groups['global'] ) ) {
            foreach ( BESR_Tables::discover_global() as $t ) {
                $add( $t, 'global', '', '' );
            }
        }
        foreach ( $groups as &$g ) {
            $g['children'] = array_values( $g['children'] );
            usort( $g['children'], function ( $a, $b ) {
                return strcasecmp( $a['label'], $b['label'] );
            } );
        }
        unset( $g );

        return array(
            'scope'  => array(
                'prefix'      => $wpdb->prefix,
                'multisite'   => is_multisite(),
                'site_id'     => get_current_blog_id(),
                'show_global' => $include_global,
            ),
            'groups' => array_values( array_filter( $groups, function ( $g ) {
                return $g['tables'] || $g['children'] || 'other' !== $g['key'];
            } ) ),
        );
    }

    /**
     * Resolve group keys / table names into a concrete list of tables.
     *
     * @param string[] $groups  group keys ('recommended', 'all', 'core' also accepted).
     * @param string[] $tables  explicit table names.
     */
    public static function resolve( array $groups, array $tables, bool $include_global = false ): array {
        $tree  = self::build( $include_global );
        $all   = array();
        $pick  = array();
        $flags = array_flip( $groups );
        foreach ( $tree['groups'] as $g ) {
            $names = array_column( $g['tables'], 'name' );
            foreach ( $g['children'] as $c ) {
                $names = array_merge( $names, array_column( $c['tables'], 'name' ) );
                if ( isset( $flags[ $c['key'] ] ) ) {
                    $pick = array_merge( $pick, array_column( $c['tables'], 'name' ) );
                }
            }
            $all     = array_merge( $all, $names );
            $is_core = in_array( $g['key'], array( 'content', 'settings', 'taxonomy', 'comments', 'users' ), true );
            if ( isset( $flags['all'] ) || isset( $flags[ $g['key'] ] )
                || ( isset( $flags['recommended'] ) && $g['default'] )
                || ( isset( $flags['core'] ) && $is_core ) ) {
                $pick = array_merge( $pick, $names );
            }
        }
        foreach ( $tables as $t ) {
            if ( in_array( $t, $all, true ) ) {
                $pick[] = $t;
            }
        }
        $pick = array_values( array_unique( $pick ) );
        sort( $pick, SORT_STRING | SORT_FLAG_CASE );
        return $pick;
    }
}
