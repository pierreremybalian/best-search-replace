<?php
/**
 * Uninstall: only removes data when the "remove all plugin data on uninstall"
 * setting is on, so accidental deletes never destroy undo journals.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-besr-schema.php';
require_once __DIR__ . '/includes/class-besr-settings.php';
require_once __DIR__ . '/includes/class-besr-ruleset.php';
require_once __DIR__ . '/includes/class-besr-context.php';
require_once __DIR__ . '/includes/class-besr-term.php';

wp_clear_scheduled_hook( 'besr_daily_prune' );

if ( ! BESR_Settings::get( 'uninstall_remove' ) ) {
    return;
}

global $wpdb;
BESR_Schema::drop();
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'besr\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
wp_cache_flush();
