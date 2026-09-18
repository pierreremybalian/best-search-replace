<?php
/**
 * The three plugin tables: runs, match index, undo journal.
 *
 * Created lazily (page load, AJAX start, CLI entry) instead of on activation so
 * every multisite subsite gets its own tables the first time the tool is used there.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The three plugin tables: runs, match index and undo journal.
 */
class BESR_Schema {

    const VERSION = '1';
    const OPTION  = 'besr_db_version';

    public static function runs(): string {
        global $wpdb;
        return $wpdb->prefix . 'besr_runs';
    }

    public static function matches(): string {
        global $wpdb;
        return $wpdb->prefix . 'besr_matches';
    }

    public static function journal(): string {
        global $wpdb;
        return $wpdb->prefix . 'besr_journal';
    }

    /**
     * All three, for exclusion from scans.
     */
    public static function own_tables(): array {
        return array( self::runs(), self::matches(), self::journal() );
    }

    public static function ensure(): void {
        if ( get_option( self::OPTION ) === self::VERSION && self::exists( self::runs() ) ) {
            return;
        }
        self::install();
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $runs    = self::runs();
        $matches = self::matches();
        $journal = self::journal();

        dbDelta( "CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  site_id bigint(20) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'scanning',
  stage varchar(10) NOT NULL DEFAULT 'scan',
  phase varchar(40) NOT NULL DEFAULT '',
  search_for text NOT NULL,
  replace_with text NOT NULL,
  config longtext NULL,
  excluded_contexts varchar(255) NOT NULL DEFAULT '',
  tables_json longtext NULL,
  state longtext NULL,
  summary longtext NULL,
  log longtext NULL,
  error text NULL,
  lock_token char(36) NULL,
  lock_expires datetime NULL,
  journal_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  journal_disabled_from bigint(20) unsigned NOT NULL DEFAULT 0,
  origin varchar(10) NOT NULL DEFAULT 'admin',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  scanned_at datetime NULL,
  applied_at datetime NULL,
  undone_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY status (status)
) {$collate};" );

        dbDelta( "CREATE TABLE {$matches} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  table_name varchar(64) NOT NULL,
  pk_json text NOT NULL,
  column_name varchar(64) NOT NULL,
  path varchar(191) NOT NULL DEFAULT '',
  encoding varchar(12) NOT NULL DEFAULT 'plain',
  occurrences smallint(5) unsigned NOT NULL DEFAULT 1,
  flagsets varchar(500) NOT NULL DEFAULT '',
  variants varchar(191) NOT NULL DEFAULT '',
  snippet_before varchar(500) NOT NULL DEFAULT '',
  snippet_after varchar(500) NOT NULL DEFAULT '',
  cell_hash char(32) NOT NULL,
  cell_length int(10) unsigned NOT NULL DEFAULT 0,
  selection varchar(8) NOT NULL DEFAULT 'auto',
  status varchar(12) NOT NULL DEFAULT 'pending',
  note varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY run_table (run_id, table_name, id),
  KEY run_apply (run_id, status, id)
) {$collate};" );

        dbDelta( "CREATE TABLE {$journal} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  match_id bigint(20) unsigned NOT NULL DEFAULT 0,
  table_name varchar(64) NOT NULL,
  pk_json text NOT NULL,
  column_name varchar(64) NOT NULL,
  cell_key char(32) NOT NULL,
  before_value longblob NOT NULL,
  before_hash char(32) NOT NULL,
  after_hash char(32) NOT NULL,
  before_length int(10) unsigned NOT NULL DEFAULT 0,
  compressed tinyint(1) NOT NULL DEFAULT 0,
  deferred tinyint(1) NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'written',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id, status, id),
  KEY cell_key (cell_key)
) {$collate};" );

        update_option( self::OPTION, self::VERSION, false );
    }

    public static function drop(): void {
        global $wpdb;
        foreach ( self::own_tables() as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        }
        delete_option( self::OPTION );
    }

    public static function exists( string $table ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
}
