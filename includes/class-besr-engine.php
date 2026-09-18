<?php
/**
 * The engine: a resumable, time-budgeted state machine. Every tick() does a bounded
 * amount of work, persists the run, and returns. The browser or WP-CLI keeps calling
 * tick() until the stage (scan, apply or undo) is finished.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The engine: a resumable, time-budgeted state machine driven by repeated tick() calls.
 */
class BESR_Engine {

    const NONCE = 'besr_nonce';

    /**
     * Stage => phase => [callable, weight]
     */
    const PHASES = array(
        'scan'  => array(
            'prepare'        => array( array( 'BESR_Scanner', 'phase_prepare' ), 3 ),
            'scan_tables'    => array( array( 'BESR_Scanner', 'phase_scan' ), 92 ),
            'summarize'      => array( array( 'BESR_Scanner', 'phase_summarize' ), 5 ),
        ),
        'apply' => array(
            'apply_prepare'  => array( array( 'BESR_Applier', 'phase_prepare' ), 2 ),
            'apply_rows'     => array( array( 'BESR_Applier', 'phase_rows' ), 90 ),
            'apply_deferred' => array( array( 'BESR_Applier', 'phase_deferred' ), 3 ),
            'apply_finalize' => array( array( 'BESR_Applier', 'phase_finalize' ), 5 ),
        ),
        'undo'  => array(
            'undo_rows'      => array( array( 'BESR_Journal', 'phase_undo_rows' ), 92 ),
            'undo_deferred'  => array( array( 'BESR_Journal', 'phase_undo_deferred' ), 3 ),
            'undo_finalize'  => array( array( 'BESR_Journal', 'phase_undo_finalize' ), 5 ),
        ),
    );

    /**
     * @var BESR_Run
     */
    private BESR_Run $run;

    /**
     * @var float
     */
    private float $budget;

    /**
     * @var float
     */
    private float $deadline;

    /**
     * @var bool
     */
    private bool $first_check = true;

    /**
     * @var bool
     */
    private bool $in_tick = false;

    /**
     * @var bool
     */
    private static bool $shutdown_registered = false;

    public function __construct( BESR_Run $run, ?float $budget = null ) {
        $this->run    = $run;
        $this->budget = $budget ?? self::default_budget();
        $start        = microtime( true );
        if ( ! self::is_cli() && ! empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
            $start = (float) $_SERVER['REQUEST_TIME_FLOAT'];
        }
        $this->deadline = $start + $this->budget;
    }

    public static function is_cli(): bool {
        return defined( 'WP_CLI' ) && WP_CLI;
    }

    /**
     * Seconds of work per request: 40% of max_execution_time, between 2 and 15 (60 in CLI).
     */
    public static function default_budget(): float {
        $setting = (float) BESR_Settings::get( 'budget' );
        if ( $setting > 0 ) {
            return $setting;
        }
        if ( self::is_cli() ) {
            return 60.0;
        }
        $max = (int) ini_get( 'max_execution_time' );
        if ( $max <= 0 ) {
            return 15.0;
        }
        return max( 2.0, min( 15.0, $max * 0.4 ) );
    }

    /**
     * True while there is budget left; always true on the first check so every tick makes progress.
     */
    public function has_time(): bool {
        if ( $this->first_check ) {
            $this->first_check = false;
            return true;
        }
        if ( microtime( true ) >= $this->deadline ) {
            return false;
        }
        return $this->memory_ok();
    }

    public function budget(): float {
        return $this->budget;
    }

    private function memory_ok(): bool {
        $limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
        if ( $limit <= 0 ) {
            return true;
        }
        return memory_get_usage( true ) < $limit * 0.75;
    }

    /**
     * Current chunk size for a key, seeded with a default.
     */
    public function chunk( string $key, int $default ): int {
        $fixed = (int) BESR_Settings::get( 'batch_rows' );
        if ( $fixed > 0 && 'scan' === $key ) {
            return $fixed;
        }
        $chunk = (array) $this->run->get( 'chunk', array() );
        return max( 1, (int) ( $chunk[ $key ] ?? $default ) );
    }

    /**
     * Grow or shrink a chunk size based on how long the last chunk took.
     */
    public function adapt_chunk( string $key, float $elapsed, int $min, int $max ): void {
        $chunk = (array) $this->run->get( 'chunk', array() );
        $cur   = (int) ( $chunk[ $key ] ?? $min );
        if ( $elapsed < $this->budget * 0.2 ) {
            $cur = (int) min( $max, max( $cur * 2, $min ) );
        } elseif ( $elapsed > $this->budget * 0.8 ) {
            $cur = (int) max( $min, floor( $cur / 2 ) );
        }
        $chunk[ $key ] = max( $min, min( $max, $cur ) );
        $this->run->set( 'chunk', $chunk );
    }

    public static function phases( string $stage ): array {
        return array_keys( self::PHASES[ $stage ] ?? array() );
    }

    public static function phase_label( string $phase ): string {
        $labels = array(
            'prepare'        => __( 'Preparing tables', 'best-search-replace' ),
            'scan_tables'    => __( 'Scanning', 'best-search-replace' ),
            'summarize'      => __( 'Summarizing', 'best-search-replace' ),
            'apply_prepare'  => __( 'Preparing to replace', 'best-search-replace' ),
            'apply_rows'     => __( 'Replacing', 'best-search-replace' ),
            'apply_deferred' => __( 'Updating site address (last step)', 'best-search-replace' ),
            'apply_finalize' => __( 'Flushing caches', 'best-search-replace' ),
            'undo_rows'      => __( 'Restoring', 'best-search-replace' ),
            'undo_deferred'  => __( 'Restoring site address (last step)', 'best-search-replace' ),
            'undo_finalize'  => __( 'Flushing caches', 'best-search-replace' ),
        );
        return $labels[ $phase ] ?? $phase;
    }

    // ------------------------------------------------------------------
    // Starting stages
    // ------------------------------------------------------------------

    /**
     * Validate input and create a run ready for its first scan tick.
     *
     * @return BESR_Run|WP_Error
     */
    public static function start_scan( array $input ) {
        BESR_Schema::ensure();

        $active = BESR_Run::active();
        if ( $active ) {
            return new WP_Error( 'besr_busy', __( 'Another run is in progress. Wait for it to finish or stop it first.', 'best-search-replace' ), array( 'run' => $active->summary_payload() ) );
        }

        $search  = (string) ( $input['search'] ?? '' );
        $replace = (string) ( $input['replace'] ?? '' );
        if ( '' === $search ) {
            return new WP_Error( 'besr_empty_search', __( 'Enter the text to search for.', 'best-search-replace' ) );
        }
        if ( $search === $replace ) {
            return new WP_Error( 'besr_same', __( '"Search for" and "Replace with" are the same, nothing would change.', 'best-search-replace' ) );
        }
        if ( strlen( $search ) > 2000 || strlen( $replace ) > 2000 ) {
            return new WP_Error( 'besr_too_long', __( 'Search and replace text must be under 2000 characters.', 'best-search-replace' ) );
        }

        $include_global = ! empty( $input['include_global'] ) && is_multisite() && is_super_admin();
        $tables         = BESR_Groups::resolve( (array) ( $input['groups'] ?? array() ), (array) ( $input['tables'] ?? array() ), $include_global );
        if ( ! $tables ) {
            return new WP_Error( 'besr_no_tables', __( 'Choose at least one table or content group.', 'best-search-replace' ) );
        }

        $variants = array_values( array_intersect( (array) ( $input['variants'] ?? BESR_Settings::get( 'default_variants' ) ), BESR_Ruleset::VARIANT_KEYS ) );
        $excluded = array_values( array_intersect( (array) ( $input['excluded_contexts'] ?? BESR_Settings::get( 'default_excluded' ) ), BESR_Context::TOGGLEABLE ) );
        sort( $excluded );

        $config = array(
            'search'             => $search,
            'replace'            => $replace,
            'case_insensitive'   => ! empty( $input['case_insensitive'] ),
            'variants'           => $variants,
            'excluded_contexts'  => $excluded,
            'groups'             => array_values( (array) ( $input['groups'] ?? array() ) ),
            'tables'             => $tables,
            'include_global'     => $include_global,
            'include_guid'       => ! empty( $input['include_guid'] ),
            'include_transients' => ! empty( $input['include_transients'] ),
            'include_identity'   => ! empty( $input['include_identity'] ),
            'include_pkless'     => ! empty( $input['include_pkless'] ),
            'origin'             => self::is_cli() ? 'cli' : 'admin',
        );

        // Make sure the regex compiles before we persist anything.
        $rules = BESR_Ruleset::compile( $config );
        if ( false === @preg_match( $rules->regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing whether the compiled pattern is valid; a warning here is the expected failure signal.
            return new WP_Error( 'besr_regex', __( 'The search text could not be compiled into a pattern.', 'best-search-replace' ) );
        }

        $run = BESR_Run::create( $config );
        $run->set( 'totals', array( 'tables' => count( $tables ) ) );
        $run->log( sprintf( 'Scan started: "%s" → "%s" (%d variant(s), %d table(s)).', $search, $replace, count( $rules->branches ), count( $tables ) ) );
        foreach ( BESR_Rules::preflight( $config ) as $w ) {
            $run->log( strtoupper( $w['level'] ) . ': ' . $w['message'] );
        }
        $run->summary_set( 'warnings', BESR_Rules::preflight( $config ) );
        $run->save();
        return $run;
    }

    /**
     * @return true|WP_Error
     */
    public static function begin_apply( BESR_Run $run, array $opts = array() ) {
        if ( BESR_Run::STATUS_SCANNED !== $run->status() ) {
            return new WP_Error( 'besr_not_scanned', __( 'Only a finished scan can be applied.', 'best-search-replace' ) );
        }
        if ( BESR_Run::active() ) {
            return new WP_Error( 'besr_busy', __( 'Another run is in progress.', 'best-search-replace' ) );
        }
        $run->set_config( 'stale', ( 'replace' === ( $opts['stale'] ?? '' ) ) ? 'replace' : 'skip' );
        $run->set_config( 'keep_siteurl', ! empty( $opts['keep_siteurl'] ) );
        $run->set_config( 'no_journal', ! empty( $opts['no_journal'] ) );
        $run->set_status( BESR_Run::STATUS_APPLYING, 'apply' );
        $run->set_phase( 'apply_prepare' );
        $run->reset_cursor();
        $run->set( 'partial', false );
        $run->clear_error();
        $run->log( 'Apply started' . ( ! empty( $opts['no_journal'] ) ? ' WITHOUT undo journal' : '' ) . '.' );
        $run->save();
        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function begin_undo( BESR_Run $run, array $opts = array() ) {
        if ( BESR_Run::STATUS_APPLIED !== $run->status() ) {
            return new WP_Error( 'besr_not_applied', __( 'Only an applied run can be undone.', 'best-search-replace' ) );
        }
        if ( $run->get( 'journal_pruned' ) ) {
            return new WP_Error( 'besr_no_journal', __( 'The undo data for this run has been removed.', 'best-search-replace' ) );
        }
        if ( BESR_Run::active() ) {
            return new WP_Error( 'besr_busy', __( 'Another run is in progress.', 'best-search-replace' ) );
        }
        $run->set_config( 'undo_force', ! empty( $opts['force'] ) );
        $run->set_status( BESR_Run::STATUS_UNDOING, 'undo' );
        $run->set_phase( 'undo_rows' );
        $run->reset_cursor();
        $run->clear_error();
        $run->count_set( 'reverted', 0 );
        $run->count_set( 'conflicts', 0 );
        $run->count_set( 'undo_errors', 0 );
        $run->log( 'Undo started' . ( ! empty( $opts['force'] ) ? ' (restoring even where content changed afterwards)' : '' ) . '.' );
        $run->save();
        return true;
    }

    /**
     * Stop a running scan or apply. @return true|WP_Error
     */
    public static function cancel( BESR_Run $run ) {
        switch ( $run->status() ) {
            case BESR_Run::STATUS_SCANNING:
                $run->set_status( BESR_Run::STATUS_CANCELLED );
                $run->log( 'Scan stopped by the user.' );
                break;
            case BESR_Run::STATUS_ERROR:
                if ( 'scan' === $run->stage() ) {
                    $run->set_status( BESR_Run::STATUS_CANCELLED );
                    break;
                }
                // Fall through: a failed apply is treated like a stopped apply.
            case BESR_Run::STATUS_APPLYING:
                $run->set( 'partial', true );
                $run->log( 'Replace stopped by the user; tables already done stay changed. Use Undo to revert them.' );
                $run->set_phase( 'apply_finalize' );
                $run->set_status( BESR_Run::STATUS_APPLYING, 'apply' );
                $run->clear_error();
                $run->save();
                $engine = new self( $run );
                $engine->tick();
                return true;
            default:
                return new WP_Error( 'besr_cannot_cancel', __( 'This run cannot be stopped in its current state.', 'best-search-replace' ) );
        }
        $run->set_phase( '' );
        $run->release_lock();
        $run->save();
        return true;
    }

    /**
     * Resume after an error. @return true|WP_Error
     */
    public static function retry( BESR_Run $run ) {
        if ( BESR_Run::STATUS_ERROR !== $run->status() ) {
            return new WP_Error( 'besr_not_error', __( 'Only a failed run can be retried.', 'best-search-replace' ) );
        }
        $stage  = $run->stage();
        $status = array( 'scan' => BESR_Run::STATUS_SCANNING, 'apply' => BESR_Run::STATUS_APPLYING, 'undo' => BESR_Run::STATUS_UNDOING )[ $stage ] ?? BESR_Run::STATUS_SCANNING;
        $run->set_status( $status, $stage );
        if ( '' === $run->phase() ) {
            $run->set_phase( self::phases( $stage )[0] );
        }
        $run->clear_error();
        $run->log( 'Retrying.' );
        $run->save();
        return true;
    }

    // ------------------------------------------------------------------
    // Ticking
    // ------------------------------------------------------------------

    /**
     * Do a bounded amount of work, persist the run, and return its progress.
     */
    public function tick(): array {
        $run = $this->run;

        if ( ! $run->is_running() ) {
            return $this->response();
        }
        if ( ! $run->acquire_lock() ) {
            $out         = $this->response();
            $out['busy'] = true;
            return $out;
        }

        @set_time_limit( (int) max( 60, $this->budget * 4 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit() is disabled on some hosts and its failure is not actionable.
        ignore_user_abort( true );
        $this->register_shutdown();
        $this->in_tick = true;

        try {
            while ( $this->has_time() && $run->is_running() ) {
                $phase = $run->phase();
                if ( '' === $phase ) {
                    $this->complete_stage();
                    break;
                }
                if ( $this->run_phase( $phase ) ) {
                    $this->advance();
                    if ( '' === $run->phase() ) {
                        $this->complete_stage();
                        break;
                    }
                }
            }
        } catch ( Throwable $e ) {
            $run->fail( $e->getMessage() );
        } finally {
            $this->in_tick = false;
            $run->release_lock();
        }

        return $this->response();
    }

    private function run_phase( string $phase ): bool {
        $stage = $this->run->stage();
        if ( ! isset( self::PHASES[ $stage ][ $phase ] ) ) {
            throw new RuntimeException( 'Unknown phase ' . $phase ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is logged or returned as JSON, never echoed as HTML; the UI escapes it on display.
        }
        return (bool) call_user_func( self::PHASES[ $stage ][ $phase ][0], $this->run, $this );
    }

    private function advance(): void {
        $run    = $this->run;
        $phases = self::phases( $run->stage() );
        $index  = array_search( $run->phase(), $phases, true );
        $done   = (array) $run->get( 'done_phases', array() );
        $done[] = $run->phase();
        $run->set( 'done_phases', array_values( array_unique( $done ) ) );
        if ( false !== $index && $index + 1 < count( $phases ) ) {
            $run->set_phase( $phases[ $index + 1 ] );
            $run->reset_cursor();
        } else {
            $run->set_phase( '' );
        }
        $run->save();
    }

    private function complete_stage(): void {
        $run = $this->run;
        switch ( $run->stage() ) {
            case 'scan':
                $run->set_status( BESR_Run::STATUS_SCANNED );
                $run->log( sprintf( 'Scan finished: %s matches in %s cells.', number_format_i18n( $run->count_get( 'occurrences' ) ), number_format_i18n( $run->count_get( 'cells' ) ) ) );
                break;
            case 'apply':
                $run->set_status( BESR_Run::STATUS_APPLIED );
                $run->log( sprintf( 'Done. Replaced %s matches in %s rows.', number_format_i18n( $run->count_get( 'applied_occurrences' ) ), number_format_i18n( $run->count_get( 'rows_updated' ) ) ) );
                break;
            case 'undo':
                $run->set_status( BESR_Run::STATUS_UNDONE );
                $run->log( sprintf( 'Undone. %s cells restored, %s left as they were.', number_format_i18n( $run->count_get( 'reverted' ) ), number_format_i18n( $run->count_get( 'conflicts' ) ) ) );
                break;
        }
        $run->set_phase( '' );
        $run->set( 'done_phases', array() );
        $run->save();
    }

    private function register_shutdown(): void {
        if ( self::$shutdown_registered ) {
            return;
        }
        self::$shutdown_registered = true;
        $id                        = $this->run->id();
        register_shutdown_function( function () use ( $id ) {
            if ( ! $this->in_tick ) {
                return;
            }
            $error = error_get_last();
            $fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
            if ( ! $error || ! in_array( (int) $error['type'], $fatal, true ) ) {
                return;
            }
            $fresh = BESR_Run::load( $id );
            if ( $fresh && $fresh->is_running() ) {
                $fresh->fail( sprintf( 'PHP fatal error: %s in %s:%d', $error['message'], basename( (string) $error['file'] ), (int) $error['line'] ) );
                $fresh->release_lock();
            }
        } );
    }

    // ------------------------------------------------------------------
    // Progress reporting
    // ------------------------------------------------------------------

    /**
     * Overall progress of the current stage, as a percentage.
     */
    public function progress(): int {
        $run    = $this->run;
        $status = $run->status();
        if ( in_array( $status, array( BESR_Run::STATUS_SCANNED, BESR_Run::STATUS_APPLIED, BESR_Run::STATUS_UNDONE ), true ) ) {
            return 100;
        }
        if ( ! $run->is_running() && BESR_Run::STATUS_ERROR !== $status ) {
            return 0;
        }
        $phases = self::PHASES[ $run->stage() ] ?? array();
        $done   = (array) $run->get( 'done_phases', array() );
        $totals = (array) $run->get( 'totals', array() );
        $total  = 0;
        $earned = 0.0;
        foreach ( $phases as $name => $def ) {
            $total += $def[1];
            if ( in_array( $name, $done, true ) ) {
                $earned += $def[1];
            } elseif ( $name === $run->phase() ) {
                $frac = 0.0;
                switch ( $name ) {
                    case 'prepare':
                        $frac = ! empty( $totals['tables'] ) ? $run->cursor( 'prepare_index', 0 ) / $totals['tables'] : 0;
                        break;
                    case 'scan_tables':
                        $frac = ! empty( $totals['rows'] ) ? $run->count_get( 'rows_progress' ) / $totals['rows'] : 0;
                        break;
                    case 'apply_rows':
                        $frac = ! empty( $totals['matches'] ) ? $run->count_get( 'matches_done' ) / $totals['matches'] : 0;
                        break;
                    case 'undo_rows':
                        $frac = ! empty( $totals['journal'] ) ? ( $run->count_get( 'reverted' ) + $run->count_get( 'conflicts' ) + $run->count_get( 'undo_errors' ) ) / $totals['journal'] : 0;
                        break;
                }
                $earned += $def[1] * min( 1.0, max( 0.0, (float) $frac ) );
            }
        }
        return (int) floor( 100 * $earned / max( 1, $total ) );
    }

    public function message(): string {
        $run    = $this->run;
        $status = $run->status();
        switch ( $status ) {
            case BESR_Run::STATUS_SCANNED:
                /* translators: 1: number of matches, 2: number of cells. */
                return sprintf( __( 'Scan finished: %1$s matches in %2$s cells.', 'best-search-replace' ), number_format_i18n( $run->count_get( 'occurrences' ) ), number_format_i18n( $run->count_get( 'cells' ) ) );
            case BESR_Run::STATUS_APPLIED:
                /* translators: 1: number of matches, 2: number of rows, 3: number of tables. */
                return sprintf( __( 'Done. Replaced %1$s matches in %2$s rows across %3$s tables.', 'best-search-replace' ), number_format_i18n( $run->count_get( 'applied_occurrences' ) ), number_format_i18n( $run->count_get( 'rows_updated' ) ), number_format_i18n( $run->count_get( 'tables_updated' ) ) );
            case BESR_Run::STATUS_UNDONE:
                /* translators: 1: number of cells restored, 2: number of cells left unchanged. */
                return sprintf( __( 'Undone. %1$s cells restored, %2$s left unchanged.', 'best-search-replace' ), number_format_i18n( $run->count_get( 'reverted' ) ), number_format_i18n( $run->count_get( 'conflicts' ) ) );
            case BESR_Run::STATUS_CANCELLED:
                return __( 'Stopped.', 'best-search-replace' );
            case BESR_Run::STATUS_ERROR:
                return $run->error();
        }
        $phase  = $run->phase();
        $label  = self::phase_label( $phase );
        $detail = '';
        switch ( $phase ) {
            case 'scan_tables':
                $tables = $run->tables();
                $i      = (int) $run->cursor( 'scan_index', 0 );
                if ( isset( $tables[ $i ] ) ) {
                    $t      = $tables[ $i ];
                    $detail = sprintf( '%s — %s / %s %s', $t['name'], number_format_i18n( (int) $t['scanned_rows'] ), number_format_i18n( (int) $t['approx_rows'] ), __( 'rows', 'best-search-replace' ) );
                }
                break;
            case 'apply_rows':
                $totals = (array) $run->get( 'totals', array() );
                $detail = sprintf( '%s / %s %s', number_format_i18n( $run->count_get( 'matches_done' ) ), number_format_i18n( (int) ( $totals['matches'] ?? 0 ) ), __( 'cells', 'best-search-replace' ) );
                break;
            case 'undo_rows':
                $totals = (array) $run->get( 'totals', array() );
                $detail = sprintf( '%s / %s %s', number_format_i18n( $run->count_get( 'reverted' ) + $run->count_get( 'conflicts' ) ), number_format_i18n( (int) ( $totals['journal'] ?? 0 ) ), __( 'cells', 'best-search-replace' ) );
                break;
        }
        return $detail ? $label . ' (' . $detail . ')' : $label . '…';
    }

    public function response( int $log_from = 0 ): array {
        $run    = $this->run;
        $status = $run->status();
        $log    = array_values( array_slice( $run->log_lines(), $log_from ) );
        $tables = array();
        $groups = array();
        foreach ( $run->tables() as $t ) {
            $tables[ $t['name'] ] = array(
                'group'            => $t['group'],
                'status'           => $t['status'],
                'skip_reason'      => $t['skip_reason'],
                'rows'             => (int) $t['approx_rows'],
                'scanned'          => (int) $t['scanned_rows'],
                'cells'            => (int) $t['cells'],
                'occurrences'      => (int) $t['occurrences'],
                'like_hits'        => $t['like_hits'],
                'excluded_columns' => $t['excluded_columns'],
                'blob_columns'     => $t['blob_columns'],
                'updated'          => (int) ( $t['updated'] ?? 0 ),
                'restored'         => (int) ( $t['restored'] ?? 0 ),
                'errors'           => (int) ( $t['errors'] ?? 0 ),
            );
            $g                    = $t['group'];
            if ( ! isset( $groups[ $g ] ) ) {
                $groups[ $g ] = array( 'tables_total' => 0, 'tables_done' => 0, 'cells' => 0, 'occurrences' => 0 );
            }
            ++$groups[ $g ]['tables_total'];
            if ( in_array( $t['status'], array( 'done', 'skipped' ), true ) ) {
                ++$groups[ $g ]['tables_done'];
            }
            $groups[ $g ]['cells']       += (int) $t['cells'];
            $groups[ $g ]['occurrences'] += (int) $t['occurrences'];
        }
        $out = array(
            'run'       => $run->summary_payload(),
            'status'    => $status,
            'stage'     => $run->stage(),
            'phase'     => $run->phase(),
            'progress'  => $this->progress(),
            'message'   => $this->message(),
            'busy'      => false,
            'done'      => ! $run->is_running(),
            'log'       => array_map( function ( $l ) {
                return array( 't' => date_i18n( 'H:i:s', (int) $l['t'] ), 'm' => (string) $l['m'] );
            }, $log ),
            'log_count' => count( $run->log_lines() ),
            'counts'    => $run->counts(),
            'totals'    => (array) $run->get( 'totals', array() ),
            'tables'    => $tables,
            'groups'    => $groups,
            'warnings'  => (array) ( $run->summary()['warnings'] ?? array() ),
            'summary'   => $run->summary(),
            'errors'    => array_slice( (array) $run->get( 'error_list', array() ), 0, 50 ),
            'conflicts' => array_slice( (array) $run->get( 'conflict_list', array() ), 0, 50 ),
            'links'     => array(
                'review' => admin_url( 'tools.php?page=best-search-replace&run=' . $run->id() ),
            ),
        );
        if ( class_exists( 'BESR_Admin' ) && in_array( $status, array( BESR_Run::STATUS_SCANNED, BESR_Run::STATUS_APPLIED, BESR_Run::STATUS_UNDONE ), true ) ) {
            $out['links']['export_csv'] = BESR_Admin::download_url( 'besr_export_matches', $run );
            if ( BESR_Run::STATUS_APPLIED === $status && ! $run->get( 'journal_pruned' ) ) {
                $out['links']['export_undo'] = BESR_Admin::download_url( 'besr_export_undo', $run );
            }
        }
        if ( BESR_Run::STATUS_APPLIED === $status && $run->get( 'new_home' ) ) {
            $out['links']['admin_after'] = trailingslashit( (string) $run->get( 'new_home' ) ) . 'wp-admin/tools.php?page=best-search-replace&run=' . $run->id();
            $out['links']['home_after']  = (string) $run->get( 'new_home' );
        }
        return $out;
    }
}
