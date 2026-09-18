<?php
/**
 * AJAX endpoints that drive scans, review, apply and undo from the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The admin-ajax endpoints that drive a run from the browser.
 */
class BESR_Ajax {

    const ACTIONS = array(
        'analyze',
	'tables',
	'scan_start',
	'tick',
	'cancel',
	'retry',
	'status',
        'review_summary',
	'review_matches',
	'match_detail',
	'set_exclusions',
	'set_selection',
        'apply_start',
	'undo_preview',
	'undo_start',
	'runs',
	'delete_run',
	'delete_journal',
	'save_settings',
    );

    public function __construct() {
        foreach ( self::ACTIONS as $action ) {
            add_action( 'wp_ajax_besr_' . $action, array( $this, 'handle_' . $action ) );
        }
    }

    // ---- helpers ----

    /**
     * Reject the request unless the nonce and the capability both check out.
     */
    private function verify(): void {
        check_ajax_referer( BESR_Engine::NONCE );
        if ( ! current_user_can( BESR_Settings::capability() ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to use this tool.', 'best-search-replace' ), 'code' => 'forbidden' ), 403 );
        }
        BESR_Schema::ensure();
    }

    /**
     * Raw (unslashed) string; search/replace values must keep every character.
     */
    private function raw( string $key, int $max = 2000 ): string {
        if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::verify() before any handler reads input.
            return '';
        }
        $v = (string) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- Nonce and capability verified in self::verify(); search and replace text must keep every byte; NUL-stripped and length-capped below.
        $v = str_replace( "\0", '', $v );
        return strlen( $v ) > $max ? substr( $v, 0, $max ) : $v;
    }

    private function text( string $key, string $default = '' ): string {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::verify() before any handler reads input.
    }

    private function int( string $key, int $default = 0 ): int {
        return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::verify() before any handler reads input.
    }

    private function flag( string $key ): bool {
        return isset( $_POST[ $key ] ) && in_array( (string) $_POST[ $key ], array( '1', 'true', 'on' ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::verify() before any handler reads input.
    }

    /**
     * @return string[]
     */
    private function list( string $key, $sanitizer = 'sanitize_key' ): array {
        if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by self::verify() before any handler reads input.
            return array();
        }
        $v = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- Nonce and capability verified in self::verify(); search and replace text must keep every byte; NUL-stripped and length-capped below.
        if ( is_string( $v ) ) {
            $v = '' === $v ? array() : explode( ',', $v );
        }
        return array_values( array_filter( array_map( $sanitizer, (array) $v ) ) );
    }

    private static function table_name( $v ): string {
        return BESR_Tables::name( (string) $v );
    }

    private function load_run(): BESR_Run {
        $run = BESR_Run::load( $this->text( 'run_id' ) );
        if ( ! $run ) {
            wp_send_json_error( array( 'message' => __( 'That run no longer exists.', 'best-search-replace' ), 'code' => 'not_found' ), 404 );
        }
        return $run;
    }

    private function respond( BESR_Run $run, int $log_from = 0 ): void {
        wp_send_json_success( ( new BESR_Engine( $run ) )->response( $log_from ) );
    }

    private function error( $wp_error ): void {
        $data  = array( 'message' => $wp_error->get_error_message(), 'code' => $wp_error->get_error_code() );
        $extra = $wp_error->get_error_data();
        if ( is_array( $extra ) ) {
            $data = array_merge( $data, $extra );
        }
        wp_send_json_error( $data );
    }

    // ---- wizard ----

    /**
     * Describe what the entered text looks like and which variants apply.
     */
    public function handle_analyze(): void {
        $this->verify();
        $search  = $this->raw( 'search' );
        $replace = $this->raw( 'replace' );
        $on      = $this->list( 'variants' );
        if ( ! $on ) {
            $on = (array) BESR_Settings::get( 'default_variants' );
        }
        if ( '' === $search ) {
            wp_send_json_success( array( 'ok' => false, 'type' => 'text', 'label' => '', 'variants' => array(), 'warnings' => array() ) );
        }
        $term     = BESR_Term::parse( $search );
        $config   = array(
            'search'           => $search,
            'replace'          => $replace,
            'case_insensitive' => $this->flag( 'case_insensitive' ),
            'variants'         => $on,
        );
        $warnings = BESR_Rules::preflight( $config );
        if ( $search === $replace ) {
            array_unshift( $warnings, array( 'code' => 'same', 'level' => 'error', 'message' => __( '"Search for" and "Replace with" are the same. Nothing would change.', 'best-search-replace' ) ) );
        }
        wp_send_json_success( array(
            'ok'       => $search !== $replace,
            'type'     => $term['type'],
            'label'    => BESR_Term::type_label( $term['type'] ),
            'variants' => BESR_Ruleset::available_variants( $search, $replace, $on ),
            'warnings' => $warnings,
        ) );
    }

    public function handle_tables(): void {
        $this->verify();
        wp_send_json_success( BESR_Groups::build( is_multisite() && is_super_admin() ) );
    }

    public function handle_scan_start(): void {
        $this->verify();
        $run = BESR_Engine::start_scan( array(
            'search'             => $this->raw( 'search' ),
            'replace'            => $this->raw( 'replace' ),
            'case_insensitive'   => $this->flag( 'case_insensitive' ),
            'variants'           => $this->list( 'variants' ),
            'excluded_contexts'  => $this->list( 'excluded_contexts' ),
            'groups'             => $this->list( 'groups', 'sanitize_text_field' ),
            'tables'             => $this->list( 'tables', array( __CLASS__, 'table_name' ) ),
            'include_guid'       => $this->flag( 'include_guid' ),
            'include_transients' => $this->flag( 'include_transients' ),
            'include_identity'   => $this->flag( 'include_identity' ),
            'include_pkless'     => $this->flag( 'include_pkless' ),
            'include_global'     => $this->flag( 'include_global' ),
        ) );
        if ( is_wp_error( $run ) ) {
            $this->error( $run );
        }
        $this->respond( $run );
    }

    // ---- ticking ----

    /**
     * Do one slice of work on the running job and return its progress.
     */
    public function handle_tick(): void {
        $this->verify();
        $run    = $this->load_run();
        $engine = new BESR_Engine( $run );
        $engine->tick();
        wp_send_json_success( $engine->response( $this->int( 'log_from' ) ) );
    }

    public function handle_cancel(): void {
        $this->verify();
        $run = $this->load_run();
        $res = BESR_Engine::cancel( $run );
        if ( is_wp_error( $res ) ) {
            $this->error( $res );
        }
        $fresh = BESR_Run::load( $run->id() ) ?: $run;
        $this->respond( $fresh, $this->int( 'log_from' ) );
    }

    public function handle_retry(): void {
        $this->verify();
        $run = $this->load_run();
        $res = BESR_Engine::retry( $run );
        if ( is_wp_error( $res ) ) {
            $this->error( $res );
        }
        $this->respond( $run, $this->int( 'log_from' ) );
    }

    public function handle_status(): void {
        $this->verify();
        $run = '' !== $this->text( 'run_id' ) ? BESR_Run::load( $this->text( 'run_id' ) ) : BESR_Run::unfinished();
        if ( ! $run ) {
            wp_send_json_success( null );
        }
        $this->respond( $run );
    }

    // ---- review ----

    /**
     * Totals, warnings and per-group counts for the review screen.
     */
    public function handle_review_summary(): void {
        $this->verify();
        $run = $this->load_run();
        if ( BESR_Run::STATUS_SCANNED === $run->status() ) {
            BESR_Scanner::refresh_summary( $run );
            $run->save();
        }
        wp_send_json_success( $this->summary_payload( $run ) );
    }

    private function summary_payload( BESR_Run $run ): array {
        $summary = $run->summary();
        $flags   = array();
        foreach ( (array) ( $summary['flags'] ?? array() ) as $flag => $info ) {
            $info['examples'] = BESR_Matches::examples( $run->id(), $flag, 2 );
            $flags[ $flag ]   = $info;
        }
        $defs   = BESR_Groups::definitions();
        $groups = array();
        foreach ( $run->tables() as $t ) {
            $c   = BESR_Groups::classify( $t['name'] );
            $key = $c['group'];
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array( 'key' => $key, 'label' => $defs[ $key ]['label'] ?? $key, 'cells' => 0, 'occurrences' => 0, 'tables' => array() );
            }
            $groups[ $key ]['tables'][]     = array(
                'name'        => $t['name'],
                'cells'       => (int) $t['cells'],
                'occurrences' => (int) $t['occurrences'],
                'status'      => $t['status'],
                'skip_reason' => $t['skip_reason'],
                'skip_label'  => $t['skip_reason'] ? BESR_Scanner::skip_label( $t['skip_reason'] ) : '',
                'like_hits'   => $t['like_hits'],
                'errors'      => (int) ( $t['errors'] ?? 0 ),
                'plugin'      => $c['subgroup'] ? $c['label'] : '',
            );
            $groups[ $key ]['cells']       += (int) $t['cells'];
            $groups[ $key ]['occurrences'] += (int) $t['occurrences'];
        }
        $ordered = array();
        foreach ( array_keys( $defs ) as $key ) {
            if ( isset( $groups[ $key ] ) ) {
                $ordered[] = $groups[ $key ];
            }
        }
        foreach ( $groups as $key => $g ) {
            if ( ! isset( $defs[ $key ] ) ) {
                $ordered[] = $g;
            }
        }
        $scanned_at = $run->row( 'scanned_at' );
        $age        = $scanned_at ? time() - strtotime( $scanned_at . ' UTC' ) : 0;
        return array(
            'run'              => $run->summary_payload(),
            'counts'           => (array) ( $summary['counts'] ?? array() ),
            'flags'            => $flags,
            'skipped_tables'   => (array) ( $summary['skipped_tables'] ?? array() ),
            'excluded_columns' => (array) ( $summary['excluded_columns'] ?? array() ),
            'warnings'         => (array) ( $summary['warnings'] ?? array() ),
            'groups'           => $ordered,
            'stale'            => $age > DAY_IN_SECONDS,
            'age'              => $scanned_at ? human_time_diff( strtotime( $scanned_at . ' UTC' ) ) : '',
            'excluded'         => $run->excluded_contexts(),
            'links'            => array(
                'export_csv'  => BESR_Admin::download_url( 'besr_export_matches', $run ),
                'export_undo' => BESR_Admin::download_url( 'besr_export_undo', $run ),
            ),
        );
    }

    public function handle_review_matches(): void {
        $this->verify();
        $run  = $this->load_run();
        $args = array(
            'table'    => self::table_name( $this->text( 'table' ) ),
            'page'     => $this->int( 'page', 1 ),
            'per_page' => $this->int( 'per_page', 50 ),
            'q'        => $this->raw( 'q', 200 ),
            'flag'     => $this->text( 'flag' ),
            'view'     => $this->text( 'view', 'all' ),
            'status'   => $this->text( 'status' ),
            'excluded' => $run->excluded_contexts(),
        );
        wp_send_json_success( BESR_Matches::query( $run->id(), $args ) );
    }

    public function handle_match_detail(): void {
        global $wpdb;
        $this->verify();
        $match = BESR_Matches::get( $this->int( 'match_id' ) );
        if ( ! $match ) {
            wp_send_json_error( array( 'message' => __( 'Unknown match.', 'best-search-replace' ) ), 404 );
        }
        $run = BESR_Run::load( (int) $match['run_id'] );
        if ( ! $run ) {
            wp_send_json_error( array( 'message' => __( 'That run no longer exists.', 'best-search-replace' ) ), 404 );
        }
        $desc = $run->table( $match['table_name'] );
        $pk   = json_decode( (string) $match['pk_json'], true );
        if ( ! $desc || ! is_array( $pk ) ) {
            wp_send_json_error( array( 'message' => __( 'The table for this match is no longer part of the run.', 'best-search-replace' ) ) );
        }
        $col   = BESR_Tables::name( $match['column_name'] );
        $value = $wpdb->get_var( 'SELECT `' . $col . '` FROM `' . BESR_Tables::name( $desc['name'] ) . '` WHERE ' . BESR_Tables::pk_where( $desc, $pk ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        if ( null === $value ) {
            wp_send_json_success( array( 'row_missing' => true, 'occurrences' => array(), 'cell_changed' => true ) );
        }
        $meta = array( 'table' => $desc['name'], 'column' => $col );
        foreach ( $desc['columns'] as $c ) {
            if ( $c['name'] === $col ) {
                $meta['max_chars'] = $c['max_chars'];
                $meta['max_bytes'] = $c['max_bytes'];
            }
        }
        $replacer = new BESR_Replacer( BESR_Ruleset::from_run( $run ) );
        $r        = $replacer->process_cell( (string) $value, $meta, true, (string) $match['selection'] );
        $list     = array();
        foreach ( $r->occurrence_list as $o ) {
            $o['before'] = BESR_Matches::mark_snippet( $o['before'] );
            $o['after']  = BESR_Matches::mark_snippet( $o['after'] );
            $o['labels'] = array_map( array( 'BESR_Context', 'label' ), $o['flags'] );
            $list[]      = $o;
        }
        wp_send_json_success( array(
            'occurrences'  => $list,
            'cell_changed' => md5( (string) $value ) !== $match['cell_hash'],
            'error'        => $r->error,
            'encoding'     => $r->encoding,
            'cell_length'  => strlen( (string) $value ),
        ) );
    }

    public function handle_set_exclusions(): void {
        $this->verify();
        $run = $this->load_run();
        if ( BESR_Run::STATUS_SCANNED !== $run->status() ) {
            wp_send_json_error( array( 'message' => __( 'Skips can only be changed before replacing.', 'best-search-replace' ) ) );
        }
        $run->set_excluded_contexts( $this->list( 'excluded' ) );
        BESR_Scanner::refresh_summary( $run );
        $run->log( 'Skipped categories: ' . ( $run->excluded_contexts_csv() ?: 'none' ) );
        $run->save();
        wp_send_json_success( $this->summary_payload( $run ) );
    }

    public function handle_set_selection(): void {
        $this->verify();
        $run = $this->load_run();
        if ( BESR_Run::STATUS_SCANNED !== $run->status() ) {
            wp_send_json_error( array( 'message' => __( 'Selection can only be changed before replacing.', 'best-search-replace' ) ) );
        }
        $selection = $this->text( 'selection', 'auto' );
        $scope     = array();
        $ids       = array_map( 'intval', $this->list( 'ids', 'intval' ) );
        if ( $ids ) {
            $scope['ids'] = $ids;
        } elseif ( '' !== $this->text( 'table' ) ) {
            $scope['table'] = self::table_name( $this->text( 'table' ) );
        } elseif ( $this->flag( 'all' ) ) {
            $scope['all'] = true;
        }
        $n = BESR_Matches::set_selection( $run->id(), $scope, $selection );
        BESR_Scanner::refresh_summary( $run );
        $run->save();
        wp_send_json_success( array( 'updated' => $n, 'counts' => $run->summary()['counts'] ?? array(), 'flags' => $run->summary()['flags'] ?? array() ) );
    }

    // ---- apply / undo ----

    /**
     * Begin replacing the matches recorded by the scan.
     */
    public function handle_apply_start(): void {
        $this->verify();
        $run = $this->load_run();
        $res = BESR_Engine::begin_apply( $run, array(
            'stale'        => $this->text( 'stale', 'skip' ),
            'keep_siteurl' => $this->flag( 'keep_siteurl' ),
            'no_journal'   => $this->flag( 'no_journal' ),
        ) );
        if ( is_wp_error( $res ) ) {
            $this->error( $res );
        }
        $engine = new BESR_Engine( $run );
        $engine->tick();
        wp_send_json_success( $engine->response() );
    }

    public function handle_undo_preview(): void {
        $this->verify();
        $run = $this->load_run();
        if ( BESR_Run::STATUS_APPLIED !== $run->status() ) {
            wp_send_json_error( array( 'message' => __( 'Only an applied run can be undone.', 'best-search-replace' ) ) );
        }
        wp_send_json_success( BESR_Journal::conflicts_preview( $run ) );
    }

    public function handle_undo_start(): void {
        $this->verify();
        $run = $this->load_run();
        $res = BESR_Engine::begin_undo( $run, array( 'force' => $this->flag( 'force' ) ) );
        if ( is_wp_error( $res ) ) {
            $this->error( $res );
        }
        $engine = new BESR_Engine( $run );
        $engine->tick();
        wp_send_json_success( $engine->response() );
    }

    // ---- history / settings ----

    /**
     * A page of past runs for the History tab.
     */
    public function handle_runs(): void {
        $this->verify();
        $page = max( 1, $this->int( 'page', 1 ) );
        $runs = BESR_Run::list( array( 'limit' => 25, 'offset' => ( $page - 1 ) * 25 ) );
        $out  = array();
        foreach ( $runs as $run ) {
            $p           = $run->summary_payload();
            $p['counts'] = $run->counts();
            $out[]       = $p;
        }
        wp_send_json_success( array( 'runs' => $out, 'total' => BESR_Run::count(), 'page' => $page ) );
    }

    public function handle_delete_run(): void {
        $this->verify();
        $run = $this->load_run();
        if ( $run->is_running() ) {
            wp_send_json_error( array( 'message' => __( 'Stop the run before deleting it.', 'best-search-replace' ) ) );
        }
        $run->delete();
        wp_send_json_success( array( 'deleted' => $run->id() ) );
    }

    public function handle_delete_journal(): void {
        $this->verify();
        $run = $this->load_run();
        if ( $run->is_running() ) {
            wp_send_json_error( array( 'message' => __( 'Stop the run first.', 'best-search-replace' ) ) );
        }
        BESR_Journal::delete_run( $run->id() );
        BESR_Matches::delete_run( $run->id() );
        $run->set( 'journal_pruned', true );
        $run->set_row( 'journal_bytes', 0 );
        $run->log( 'Undo data deleted by the user.' );
        $run->save();
        wp_send_json_success( $run->summary_payload() );
    }

    public function handle_save_settings(): void {
        $this->verify();
        $input = array(
            'retention_days'   => $this->int( 'retention_days', 30 ),
            'keep_runs'        => $this->int( 'keep_runs', 20 ),
            'default_variants' => $this->list( 'default_variants' ),
            'default_excluded' => $this->list( 'default_excluded' ),
            'include_logs'     => $this->flag( 'include_logs' ),
            'budget'           => (float) $this->text( 'budget', '0' ),
            'batch_rows'       => $this->int( 'batch_rows', 0 ),
            'uninstall_remove' => $this->flag( 'uninstall_remove' ),
        );
        wp_send_json_success( BESR_Settings::save( $input ) );
    }
}
