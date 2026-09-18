<?php
/**
 * A run: one search/replace from scan through apply and undo. Backed by a row in
 * besr_runs so every AJAX tick and WP-CLI see the same state.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A run: one search and replace, from scan through apply and undo.
 */
class BESR_Run {

    const STATUS_SCANNING  = 'scanning';
    const STATUS_SCANNED   = 'scanned';
    const STATUS_APPLYING  = 'applying';
    const STATUS_APPLIED   = 'applied';
    const STATUS_UNDOING   = 'undoing';
    const STATUS_UNDONE    = 'undone';
    const STATUS_ERROR     = 'error';
    const STATUS_CANCELLED = 'cancelled';

    const RUNNING  = array( self::STATUS_SCANNING, self::STATUS_APPLYING, self::STATUS_UNDOING );
    const LOCK_TTL = 120;
    const MAX_LOG  = 400;

    /**
     * @var array
     */
    private array $row;

    /**
     * @var array
     */
    private array $config;

    /**
     * @var array
     */
    private array $state;

    /**
     * @var array
     */
    private array $log;

    /**
     * @var array
     */
    private array $tables;

    /**
     * @var array
     */
    private array $summary;

    /**
     * @var bool
     */
    private bool $tables_dirty = false;

    /**
     * @var string|null
     */
    private ?string $lock_token = null;

    private function __construct( array $row ) {
        $this->row     = $row;
        $this->config  = self::decode( $row['config'] ?? '' );
        $this->state   = self::decode( $row['state'] ?? '' );
        $this->log     = self::decode( $row['log'] ?? '' );
        $this->tables  = self::decode( $row['tables_json'] ?? '' );
        $this->summary = self::decode( $row['summary'] ?? '' );
    }

    private static function decode( $json ): array {
        if ( is_array( $json ) ) {
            return $json;
        }
        $d = json_decode( (string) $json, true );
        return is_array( $d ) ? $d : array();
    }

    // ---- factory ----

    /**
     * Store a new run, ready for its first scan tick.
     *
     * @throws RuntimeException When the run record cannot be created.
     */
    public static function create( array $config ): BESR_Run {
        global $wpdb;
        $now  = current_time( 'mysql', true );
        $uuid = wp_generate_uuid4();
        $wpdb->insert( BESR_Schema::runs(), array(
            'uuid'              => $uuid,
            'site_id'           => get_current_blog_id(),
            'status'            => self::STATUS_SCANNING,
            'stage'             => 'scan',
            'phase'             => 'prepare',
            'search_for'        => (string) $config['search'],
            'replace_with'      => (string) $config['replace'],
            'config'            => wp_json_encode( $config ),
            'excluded_contexts' => implode( ',', (array) ( $config['excluded_contexts'] ?? array() ) ),
            'state'             => wp_json_encode( array( 'cursor' => array(), 'chunk' => array(), 'counts' => array() ) ),
            'log'               => '[]',
            'origin'            => (string) ( $config['origin'] ?? 'admin' ),
            'user_id'           => get_current_user_id(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ) );
        $run = self::load( (int) $wpdb->insert_id );
        if ( ! $run ) {
            throw new RuntimeException( 'Could not create the run record: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is logged or returned as JSON, never echoed as HTML; the UI escapes it on display.
        }
        return $run;
    }

    /**
     * @param int|string $id numeric id or uuid (prefix match on 6+ chars allowed).
     */
    public static function load( $id ): ?BESR_Run {
        global $wpdb;
        $table = BESR_Schema::runs();
        $id    = trim( (string) $id );
        if ( '' === $id ) {
            return null;
        }
        if ( ctype_digit( $id ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        } elseif ( preg_match( '/^[0-9a-f-]{6,36}$/i', $id ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE uuid LIKE %s ORDER BY id DESC LIMIT 1", $wpdb->esc_like( $id ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        } else {
            return null;
        }
        return $row ? new self( $row ) : null;
    }

    public static function active(): ?BESR_Run {
        global $wpdb;
        $table = BESR_Schema::runs();
        $in    = "'" . implode( "','", self::RUNNING ) . "'";
        $row   = $wpdb->get_row( "SELECT * FROM `{$table}` WHERE status IN ({$in}) ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        return $row ? new self( $row ) : null;
    }

    /**
     * Most recent run that needs attention (running or error).
     */
    public static function unfinished(): ?BESR_Run {
        global $wpdb;
        $table = BESR_Schema::runs();
        $in    = "'" . implode( "','", array_merge( self::RUNNING, array( self::STATUS_ERROR ) ) ) . "'";
        $row   = $wpdb->get_row( "SELECT * FROM `{$table}` WHERE status IN ({$in}) ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        return $row ? new self( $row ) : null;
    }

    /**
     * @return BESR_Run[]
     */
    public static function list( array $args = array() ): array {
        global $wpdb;
        $table  = BESR_Schema::runs();
        $limit  = (int) ( $args['limit'] ?? 50 );
        $offset = (int) ( $args['offset'] ?? 0 );
        $where  = '1=1';
        if ( ! empty( $args['status'] ) ) {
            $st     = array_map( 'sanitize_key', (array) $args['status'] );
            $where .= " AND status IN ('" . implode( "','", $st ) . "')";
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        return array_map( function ( $r ) {
            return new self( $r );
        }, $rows );
    }

    public static function count(): int {
        global $wpdb;
        $table = BESR_Schema::runs();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    // ---- accessors ----

    /**
     * The run id.
     */
    public function id(): int {
        return (int) $this->row['id'];
    }

    public function uuid(): string {
        return (string) $this->row['uuid'];
    }

    public function short(): string {
        return substr( $this->uuid(), 0, 8 );
    }

    public function status(): string {
        return (string) $this->row['status'];
    }

    public function stage(): string {
        return (string) $this->row['stage'];
    }

    public function phase(): string {
        return (string) $this->row['phase'];
    }

    public function set_phase( string $phase ): void {
        $this->row['phase'] = $phase;
    }

    public function search(): string {
        return (string) $this->row['search_for'];
    }

    public function replace(): string {
        return (string) $this->row['replace_with'];
    }

    public function row( string $key ) {
        return $this->row[ $key ] ?? null;
    }

    public function set_row( string $key, $value ): void {
        $this->row[ $key ] = $value;
    }

    public function is_running(): bool {
        return in_array( $this->status(), self::RUNNING, true );
    }

    public function set_status( string $status, ?string $stage = null ): void {
        $this->row['status'] = $status;
        if ( null !== $stage ) {
            $this->row['stage'] = $stage;
        }
        $now = current_time( 'mysql', true );
        if ( self::STATUS_SCANNED === $status ) {
            $this->row['scanned_at'] = $now;
        } elseif ( self::STATUS_APPLIED === $status ) {
            $this->row['applied_at'] = $now;
        } elseif ( self::STATUS_UNDONE === $status ) {
            $this->row['undone_at'] = $now;
        }
    }

    // ---- config (frozen at scan, except the exclusions) ----

    /**
     * A value from the configuration frozen when the scan started.
     */
    public function config( string $key, $default = null ) {
        return $this->config[ $key ] ?? $default;
    }

    public function config_all(): array {
        return $this->config;
    }

    public function set_config( string $key, $value ): void {
        $this->config[ $key ] = $value;
    }

    public function excluded_contexts(): array {
        $csv = (string) $this->row['excluded_contexts'];
        return '' === $csv ? array() : array_values( array_filter( explode( ',', $csv ) ) );
    }

    public function excluded_contexts_csv(): string {
        return (string) $this->row['excluded_contexts'];
    }

    public function set_excluded_contexts( array $flags ): void {
        $flags = array_values( array_intersect( $flags, BESR_Context::TOGGLEABLE ) );
        sort( $flags );
        $this->row['excluded_contexts'] = implode( ',', $flags );
    }

    // ---- state ----

    /**
     * A value from the run state.
     */
    public function get( string $key, $default = null ) {
        return $this->state[ $key ] ?? $default;
    }

    public function set( string $key, $value ): void {
        $this->state[ $key ] = $value;
    }

    public function cursor( string $key, $default = null ) {
        return $this->state['cursor'][ $key ] ?? $default;
    }

    public function set_cursor( string $key, $value ): void {
        $this->state['cursor'][ $key ] = $value;
    }

    public function reset_cursor(): void {
        $this->state['cursor'] = array();
    }

    public function count_get( string $key ): int {
        return (int) ( $this->state['counts'][ $key ] ?? 0 );
    }

    public function count_add( string $key, int $n = 1 ): void {
        $this->state['counts'][ $key ] = $this->count_get( $key ) + $n;
    }

    public function count_set( string $key, int $n ): void {
        $this->state['counts'][ $key ] = $n;
    }

    public function counts(): array {
        return (array) ( $this->state['counts'] ?? array() );
    }

    public function tables(): array {
        return $this->tables;
    }

    public function set_tables( array $tables ): void {
        $this->tables       = $tables;
        $this->tables_dirty = true;
    }

    public function table( string $name ): ?array {
        foreach ( $this->tables as $t ) {
            if ( $t['name'] === $name ) {
                return $t;
            }
        }
        return null;
    }

    public function update_table( int $index, array $desc ): void {
        $this->tables[ $index ] = $desc;
        $this->tables_dirty     = true;
    }

    public function summary(): array {
        return $this->summary;
    }

    public function set_summary( array $summary ): void {
        $this->summary = $summary;
    }

    public function summary_set( string $key, $value ): void {
        $this->summary[ $key ] = $value;
    }

    // ---- log / errors ----

    /**
     * Append a line to the run log, keeping only the most recent entries.
     */
    public function log( string $message ): void {
        $this->log[] = array( 't' => time(), 'm' => $message );
        if ( count( $this->log ) > self::MAX_LOG ) {
            $this->log = array_slice( $this->log, -self::MAX_LOG );
        }
    }

    public function log_lines(): array {
        return $this->log;
    }

    public function fail( string $message ): void {
        $this->row['status'] = self::STATUS_ERROR;
        $this->row['error']  = $message;
        $this->log( 'ERROR: ' . $message );
        $this->save();
    }

    public function error(): string {
        return (string) ( $this->row['error'] ?? '' );
    }

    public function clear_error(): void {
        $this->row['error'] = '';
    }

    // ---- persistence ----

    /**
     * Write the run back to the database.
     */
    public function save(): void {
        global $wpdb;
        $now  = current_time( 'mysql', true );
        $data = array(
            'status'                => $this->row['status'],
            'stage'                 => $this->row['stage'],
            'phase'                 => $this->row['phase'],
            'config'                => wp_json_encode( $this->config ),
            'excluded_contexts'     => $this->row['excluded_contexts'],
            'state'                 => wp_json_encode( $this->state ),
            'summary'               => wp_json_encode( $this->summary ),
            'log'                   => wp_json_encode( $this->log ),
            'error'                 => (string) ( $this->row['error'] ?? '' ),
            'journal_bytes'         => (int) ( $this->row['journal_bytes'] ?? 0 ),
            'journal_disabled_from' => (int) ( $this->row['journal_disabled_from'] ?? 0 ),
            'updated_at'            => $now,
            'scanned_at'            => $this->row['scanned_at'] ?? null,
            'applied_at'            => $this->row['applied_at'] ?? null,
            'undone_at'             => $this->row['undone_at'] ?? null,
        );
        if ( $this->tables_dirty ) {
            $data['tables_json'] = wp_json_encode( $this->tables );
        }
        $wpdb->update( BESR_Schema::runs(), $data, array( 'id' => $this->id() ) );
        $this->row['updated_at'] = $now;
        $this->tables_dirty      = false;
    }

    public function delete(): void {
        global $wpdb;
        BESR_Matches::delete_run( $this->id() );
        BESR_Journal::delete_run( $this->id() );
        $wpdb->delete( BESR_Schema::runs(), array( 'id' => $this->id() ) );
    }

    // ---- lock ----

    /**
     * Take the run lock so two ticks never work on the same run at once.
     */
    public function acquire_lock(): bool {
        global $wpdb;
        if ( null === $this->lock_token ) {
            $this->lock_token = wp_generate_uuid4();
        }
        $affected = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET lock_token = %s, lock_expires = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)
             WHERE id = %d AND (lock_token IS NULL OR lock_token = %s OR lock_expires IS NULL OR lock_expires < UTC_TIMESTAMP())',
            BESR_Schema::runs(),
            $this->lock_token,
            self::LOCK_TTL,
            $this->id(),
            $this->lock_token
        ) );
        return 1 === (int) $affected;
    }

    public function release_lock(): void {
        global $wpdb;
        if ( null === $this->lock_token ) {
            return;
        }
        $table = BESR_Schema::runs();
        $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET lock_token = NULL, lock_expires = NULL WHERE id = %d AND lock_token = %s", $this->id(), $this->lock_token ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    // ---- payloads ----

    /**
     * The run description sent to the browser and to WP-CLI.
     */
    public function summary_payload(): array {
        $user = get_userdata( (int) $this->row['user_id'] );
        return array(
            'id'                => $this->id(),
            'uuid'              => $this->uuid(),
            'short'             => $this->short(),
            'status'            => $this->status(),
            'stage'             => $this->stage(),
            'phase'             => $this->phase(),
            'search'            => $this->search(),
            'replace'           => $this->replace(),
            'case_insensitive'  => (bool) $this->config( 'case_insensitive' ),
            'variants'          => (array) $this->config( 'variants', array() ),
            'excluded_contexts' => $this->excluded_contexts(),
            'tables'            => array_column( $this->tables, 'name' ),
            'tables_requested'  => (array) $this->config( 'tables', array() ),
            'origin'            => (string) $this->row['origin'],
            'user'              => $user ? $user->display_name : ( 'cli' === $this->row['origin'] ? 'WP-CLI' : '' ),
            'user_id'           => (int) $this->row['user_id'],
            'error'             => $this->error(),
            'partial'           => (bool) $this->get( 'partial', false ),
            'keep_siteurl'      => (bool) $this->config( 'keep_siteurl' ),
            'created_at'        => $this->row['created_at'],
            'updated_at'        => $this->row['updated_at'],
            'scanned_at'        => $this->row['scanned_at'],
            'applied_at'        => $this->row['applied_at'],
            'undone_at'         => $this->row['undone_at'],
            'journal_bytes'     => (int) $this->row['journal_bytes'],
            'journal_expired'   => (bool) $this->get( 'journal_pruned', false ),
            'undo_until'        => $this->undo_until(),
        );
    }

    public function undo_until(): string {
        if ( self::STATUS_APPLIED !== $this->status() || empty( $this->row['applied_at'] ) ) {
            return '';
        }
        $days = (int) BESR_Settings::get( 'retention_days' );
        if ( $days <= 0 ) {
            return '';
        }
        return gmdate( 'Y-m-d H:i:s', strtotime( $this->row['applied_at'] . ' UTC' ) + $days * DAY_IN_SECONDS );
    }
}
