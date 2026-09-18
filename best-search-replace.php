<?php
/**
 * Plugin Name:       Best Search Replace
 * Plugin URI:        https://github.com/pierreremybalian/best-search-replace
 * Description:       Search and replace across your database with a full preview of every match, collision warnings (e-mail addresses, sub-domains, GUIDs), friendly table groups, serialized-data safety and one-click undo.
 * Version:           1.0.0
 * Author:            Pierre R. Balian
 * Author URI:        https://github.com/pierreremybalian
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       best-search-replace
 * Requires at least: 6.2
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Best Search Replace requires PHP 7.4 or newer.', 'best-search-replace' ) . '</p></div>';
    } );
    return;
}

define( 'BESR_VERSION', '1.0.0' );
define( 'BESR_PLUGIN_FILE', __FILE__ );
define( 'BESR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BESR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$besr_classes = array(
    'BESR_Schema'          => 'class-besr-schema.php',
    'BESR_Settings'        => 'class-besr-settings.php',
    'BESR_Term'            => 'class-besr-term.php',
    'BESR_Ruleset'         => 'class-besr-ruleset.php',
    'BESR_Context'         => 'class-besr-context.php',
    'BESR_Serialized'      => 'class-besr-serialized.php',
    'BESR_Replacer'        => 'class-besr-replacer.php',
    'BESR_Tables'          => 'class-besr-tables.php',
    'BESR_Groups'          => 'class-besr-groups.php',
    'BESR_Rules'           => 'class-besr-rules.php',
    'BESR_Run'             => 'class-besr-run.php',
    'BESR_Matches'         => 'class-besr-matches.php',
    'BESR_Journal'         => 'class-besr-journal.php',
    'BESR_Scanner'         => 'class-besr-scanner.php',
    'BESR_Applier'         => 'class-besr-applier.php',
    'BESR_Engine'          => 'class-besr-engine.php',
    'BESR_Admin'           => 'class-besr-admin.php',
    'BESR_Ajax'            => 'class-besr-ajax.php',
);
foreach ( $besr_classes as $besr_class => $besr_file ) {
    if ( ! class_exists( $besr_class ) && file_exists( BESR_PLUGIN_DIR . 'includes/' . $besr_file ) ) {
        require_once BESR_PLUGIN_DIR . 'includes/' . $besr_file;
    }
}
unset( $besr_classes, $besr_class, $besr_file );

add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        new BESR_Admin();
        new BESR_Ajax();
    }
    add_action( 'besr_daily_prune', array( 'BESR_Journal', 'prune' ) );
    if ( ! wp_next_scheduled( 'besr_daily_prune' ) && ! wp_installing() ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'besr_daily_prune' );
    }
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once BESR_PLUGIN_DIR . 'includes/class-besr-cli.php';
    WP_CLI::add_command( 'besr', 'BESR_CLI' );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
    $url = admin_url( 'tools.php?page=best-search-replace' );
    array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Search & Replace', 'best-search-replace' ) . '</a>' );
    return $links;
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'besr_daily_prune' );
} );
