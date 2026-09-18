<?php
/**
 * The undo journal: the previous value of every cell we change, so a run can be
 * reverted exactly, exported as SQL, and pruned after the retention period.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The undo journal: the previous value of every cell the plugin changes.
 */
class BESR_Journal {

    const COMPRESS_OVER = 4096;

    public static function max_bytes(): int {
        /**
         * Filter the maximum undo-journal size per run in bytes (default 2 GB).
         */
        return (int) apply_filters( 'besr_journal_max_bytes', 2 * 1024 * 1024 * 1024 );
    }

    /**
     * Store the pre-image of a cell. Returns true, or a reason string when it could not be stored.
     *
     * @return true|string
     * @throws RuntimeException When the journal cannot be written or the run would exceed its size cap.
     */
    public static function record( BESR_Run $run, array $match, string $before, string $after, bool $deferred, int $max_packet ) {
        global $wpdb;
        $stored     = $before;
        $compressed = 0;
        if ( strlen( $before ) > self::COMPRESS_OVER && function_exists( 'gzcompress' ) ) {
            $z = gzcompress( $before, 6 );
            if ( false !== $z && strlen( $z ) < strlen( $before ) ) {
                $stored     = $z;
                $compressed = 1;
            }
        }
        if ( strlen( $stored ) + 2048 > $max_packet ) {
            return sprintf( 'cell too large for this server (max_allowed_packet %s)', size_format( $max_packet ) );
        }
        $bytes = (int) $run->row( 'journal_bytes' ) + strlen( $stored );
        if ( $bytes > self::max_bytes() ) {
            throw new RuntimeException( sprintf( 'Undo journal would exceed %s. Raise the besr_journal_max_bytes filter or continue without the undo journal.', size_format( self::max_bytes() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is logged or returned as JSON, never echoed as HTML; the UI escapes it on display.
        }
        $ok = $wpdb->insert( BESR_Schema::journal(), array(
            'run_id'        => $run->id(),
            'match_id'      => (int) $match['id'],
            'table_name'    => $match['table_name'],
            'pk_json'       => $match['pk_json'],
            'column_name'   => $match['column_name'],
            'cell_key'      => md5( $match['table_name'] . '|' . $match['pk_json'] . '|' . $match['column_name'] ),
            'before_value'  => $stored,
            'before_hash'   => md5( $before ),
            'after_hash'    => md5( $after ),
            'before_length' => strlen( $before ),
            'compressed'    => $compressed,
            'deferred'      => $deferred ? 1 : 0,
            'status'        => 'written',
            'created_at'    => current_time( 'mysql', true ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ) );
        if ( false === $ok ) {
            throw new RuntimeException( 'Could not write the undo journal: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is logged or returned as JSON, never echoed as HTML; the UI escapes it on display.
        }
        $run->set_row( 'journal_bytes', $bytes );
        return true;
    }

    /**
     * Remove journal rows for matches whose UPDATE failed.
     */
    public static function discard_for_matches( array $match_ids ): void {
        global $wpdb;
        if ( ! $match_ids ) {
            return;
        }
        $table = BESR_Schema::journal();
        $in    = implode( ',', array_map( 'intval', $match_ids ) );
        $wpdb->query( "DELETE FROM `{$table}` WHERE match_id IN ({$in}) AND status = 'written'" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    public static function inflate( array $row ): string {
        if ( ! empty( $row['compressed'] ) ) {
            $v = @gzuncompress( (string) $row['before_value'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A corrupt journal blob is detected by the hash check that follows.
            return false === $v ? '' : $v;
        }
        return (string) $row['before_value'];
    }

    public static function count( int $run_id, ?string $status = null ): int {
        global $wpdb;
        $table = BESR_Schema::journal();
        if ( null === $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE run_id = %d AND status = %s", $run_id, $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    // ------------------------------------------------------------------
    // Undo
    // ------------------------------------------------------------------

    /**
     * Phase: restore journalled cells, newest first.
     */
    public static function phase_undo_rows( BESR_Run $run, BESR_Engine $engine ): bool {
        global $wpdb;
        $table  = BESR_Schema::journal();
        $totals = (array) $run->get( 'totals', array() );
        if ( ! isset( $totals['journal'] ) || 0 === (int) $run->cursor( 'undo_started', 0 ) ) {
            $totals['journal'] = self::count( $run->id(), 'written' );
            $run->set( 'totals', $totals );
            $run->set_cursor( 'undo_started', 1 );
            $run->set( 'conflict_list', array() );
            $tables = $run->tables();
            foreach ( $tables as $i => $t ) {
                $tables[ $i ]['restored'] = 0;
            }
            $run->set_tables( $tables );
            $run->save();
        }
        while ( $engine->has_time() ) {
            $start = microtime( true );
            $limit = $engine->chunk( 'undo', 100 );
            $rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d AND status = 'written' AND deferred = 0 ORDER BY id DESC LIMIT %d", $run->id(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            if ( ! $rows ) {
                return true;
            }
            self::restore_rows( $run, $rows );
            $run->save();
            $engine->adapt_chunk( 'undo', microtime( true ) - $start, 20, 500 );
        }
        return false;
    }

    /**
     * Phase: restore the site address settings, which were written last.
     *
     * @param BESR_Run    $run    The run being processed.
     * @param BESR_Engine $engine Unused here, but every phase callback receives it.
     * @return bool True when the phase is finished.
     */
    public static function phase_undo_deferred( BESR_Run $run, BESR_Engine $engine ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the phase-callback signature; BESR_Engine passes it to every phase.
        global $wpdb;
        $table = BESR_Schema::journal();
        $rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d AND status = 'written' AND deferred = 1 ORDER BY id DESC", $run->id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        if ( $rows ) {
            self::restore_rows( $run, $rows );
            wp_cache_delete( 'home', 'options' );
            wp_cache_delete( 'siteurl', 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            $run->set( 'new_home', '' );
            $run->log( 'Site address settings restored.' );
        }
        $run->save();
        return true;
    }

    /**
     * Phase: flush caches and record what the undo did.
     *
     * @param BESR_Run    $run    The run being processed.
     * @param BESR_Engine $engine Unused here, but every phase callback receives it.
     * @return bool True when the phase is finished.
     */
    public static function phase_undo_finalize( BESR_Run $run, BESR_Engine $engine ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the phase-callback signature; BESR_Engine passes it to every phase.
        foreach ( BESR_Rules::after_write( $run, true ) as $line ) {
            $run->log( $line );
        }
        $summary         = $run->summary();
        $summary['undo'] = array(
            'reverted'  => $run->count_get( 'reverted' ),
            'conflicts' => $run->count_get( 'conflicts' ),
            'errors'    => $run->count_get( 'undo_errors' ),
        );
        $run->set_summary( $summary );
        do_action( 'besr_after_undo', $run );
        $run->save();
        return true;
    }

    private static function restore_rows( BESR_Run $run, array $rows ): void {
        global $wpdb;
        $jt     = BESR_Schema::journal();
        $force  = (bool) $run->config( 'undo_force' );
        $tables = $run->tables();
        $by     = array();
        foreach ( $tables as $i => $t ) {
            $by[ $t['name'] ] = $i;
        }
        $conflicts = (array) $run->get( 'conflict_list', array() );

        foreach ( $rows as $j ) {
            $idx = $by[ $j['table_name'] ] ?? null;
            $pk  = json_decode( (string) $j['pk_json'], true );
            if ( null === $idx || ! is_array( $pk ) || ! BESR_Schema::exists( $j['table_name'] ) ) {
                $wpdb->update( $jt, array( 'status' => 'conflict' ), array( 'id' => $j['id'] ) );
                $run->count_add( 'conflicts' );
                $conflicts[] = array( 'table' => $j['table_name'], 'pk' => $pk, 'column' => $j['column_name'], 'reason' => 'table missing' );
                continue;
            }
            $desc  = $tables[ $idx ];
            $table = BESR_Tables::name( $desc['name'] );
            $col   = BESR_Tables::name( $j['column_name'] );
            $where = BESR_Tables::pk_where( $desc, $pk );
            $cur   = $wpdb->get_var( "SELECT `{$col}` FROM `{$table}` WHERE {$where} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            if ( null === $cur ) {
                $wpdb->update( $jt, array( 'status' => 'conflict' ), array( 'id' => $j['id'] ) );
                $run->count_add( 'conflicts' );
                $conflicts[] = array( 'table' => $table, 'pk' => $pk, 'column' => $col, 'reason' => 'row deleted' );
                continue;
            }
            $hash = md5( (string) $cur );
            if ( $hash === $j['before_hash'] ) {
                $wpdb->update( $jt, array( 'status' => 'reverted' ), array( 'id' => $j['id'] ) );
                $run->count_add( 'reverted' );
                continue;
            }
            if ( $hash !== $j['after_hash'] && ! $force ) {
                $wpdb->update( $jt, array( 'status' => 'conflict' ), array( 'id' => $j['id'] ) );
                $run->count_add( 'conflicts' );
                $conflicts[] = array( 'table' => $table, 'pk' => $pk, 'column' => $col, 'reason' => 'edited after this run' );
                continue;
            }
            $before = self::inflate( $j );
            if ( ! empty( $j['compressed'] ) && md5( $before ) !== $j['before_hash'] ) {
                $wpdb->update( $jt, array( 'status' => 'error' ), array( 'id' => $j['id'] ) );
                $run->count_add( 'undo_errors' );
                $conflicts[] = array( 'table' => $table, 'pk' => $pk, 'column' => $col, 'reason' => 'journal entry corrupt' );
                continue;
            }
            $ok = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `{$col}` = %s WHERE {$where}" . ( isset( $pk['__row'] ) ? ' LIMIT 1' : '' ), $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            if ( false === $ok ) {
                $wpdb->update( $jt, array( 'status' => 'error' ), array( 'id' => $j['id'] ) );
                $run->count_add( 'undo_errors' );
                $conflicts[] = array( 'table' => $table, 'pk' => $pk, 'column' => $col, 'reason' => $wpdb->last_error );
                continue;
            }
            $wpdb->update( $jt, array( 'status' => 'reverted' ), array( 'id' => $j['id'] ) );
            $run->count_add( 'reverted' );
            $tables[ $idx ]['restored'] = (int) ( $tables[ $idx ]['restored'] ?? 0 ) + 1;
            if ( isset( $pk['__row'] ) ) {
                // Whole-row identity: the row now contains the pre-image, so update the stored key for later entries.
                continue;
            }
        }
        $run->set_tables( $tables );
        $run->set( 'conflict_list', array_slice( $conflicts, 0, 200 ) );
    }

    /**
     * Before undo: how many journaled cells no longer contain what we wrote (edited since).
     * Checks up to $limit entries.
     */
    public static function conflicts_preview( BESR_Run $run, int $limit = 500 ): array {
        global $wpdb;
        $jt    = BESR_Schema::journal();
        $rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, table_name, pk_json, column_name, before_hash, after_hash FROM `{$jt}` WHERE run_id = %d AND status = 'written' ORDER BY id DESC LIMIT %d", $run->id(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        $total = self::count( $run->id(), 'written' );
        $by    = array();
        foreach ( $run->tables() as $t ) {
            $by[ $t['name'] ] = $t;
        }
        $changed  = 0;
        $examples = array();
        foreach ( $rows as $j ) {
            $desc = $by[ $j['table_name'] ] ?? null;
            $pk   = json_decode( (string) $j['pk_json'], true );
            if ( ! $desc || ! is_array( $pk ) ) {
                continue;
            }
            $col = BESR_Tables::name( $j['column_name'] );
            $cur = $wpdb->get_var( 'SELECT `' . $col . '` FROM `' . BESR_Tables::name( $desc['name'] ) . '` WHERE ' . BESR_Tables::pk_where( $desc, $pk ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            $h   = null === $cur ? '' : md5( (string) $cur );
            if ( $h !== $j['after_hash'] && $h !== $j['before_hash'] ) {
                ++$changed;
                if ( count( $examples ) < 5 ) {
                    $examples[] = array( 'table' => $j['table_name'], 'pk' => $pk, 'column' => $j['column_name'] );
                }
            }
        }
        $overlap = self::overlap_count( $run->id() );
        return array(
		'total' => $total,
		'checked' => count( $rows ),
		'changed' => $changed,
		'examples' => $examples,
		'overlap_runs' => $overlap,
		'tables' => count( array_filter( $run->tables(), function ( $t ) {
            return (int) ( $t['updated'] ?? 0 ) > 0;
		} ) ),
		);
    }

    /**
     * Later runs that touched the same cells.
     */
    public static function overlap_count( int $run_id ): int {
        global $wpdb;
        $jt = BESR_Schema::journal();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT j2.run_id) FROM `{$jt}` j1 JOIN `{$jt}` j2 ON j1.cell_key = j2.cell_key WHERE j1.run_id = %d AND j2.run_id > j1.run_id AND j2.status = 'written'", // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            $run_id
        ) );
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /**
     * @return Generator<string> lines of SQL
     */
    public static function export_sql( BESR_Run $run ): Generator {
        global $wpdb;
        $jt = BESR_Schema::journal();
        yield sprintf( "-- Best Search Replace undo script for run %s (%s)\n", $run->uuid(), $run->row( 'applied_at' ) ?: 'not applied' );
        yield sprintf( "-- \"%s\" -> \"%s\"\n", str_replace( "\n", ' ', $run->search() ), str_replace( "\n", ' ', $run->replace() ) );
        yield "-- Statements are unconditional: they do not check whether a cell changed after the run.\n";
        yield "-- Site address rows (siteurl/home) are at the end; run them only when the old address resolves.\n\n";
        $by = array();
        foreach ( $run->tables() as $t ) {
            $by[ $t['name'] ] = $t;
        }
        foreach ( array( 0, 1 ) as $deferred ) {
            $last = PHP_INT_MAX;
            while ( true ) {
                $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$jt}` WHERE run_id = %d AND deferred = %d AND id < %d ORDER BY id DESC LIMIT 200", $run->id(), $deferred, $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                if ( ! $rows ) {
                    break;
                }
                foreach ( $rows as $j ) {
                    $last = (int) $j['id'];
                    $desc = $by[ $j['table_name'] ] ?? null;
                    $pk   = json_decode( (string) $j['pk_json'], true );
                    if ( ! $desc || ! is_array( $pk ) ) {
                        continue;
                    }
                    $sql = $wpdb->prepare( 'UPDATE `' . BESR_Tables::name( $desc['name'] ) . '` SET `' . BESR_Tables::name( $j['column_name'] ) . '` = %s WHERE ' . BESR_Tables::pk_where( $desc, $pk ) . ( isset( $pk['__row'] ) ? ' LIMIT 1' : '' ) . ';', self::inflate( $j ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    yield $sql . "\n";
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Retention
    // ------------------------------------------------------------------

    /**
     * Drop a run's journal entries.
     */
    public static function delete_run( int $run_id ): void {
        global $wpdb;
        $wpdb->delete( BESR_Schema::journal(), array( 'run_id' => $run_id ) );
    }

    /**
     * Apply retention: drop journals of old applied runs, delete stale unfinished runs,
     * keep at most keep_runs applied runs with a journal.
     */
    public static function prune(): array {
        global $wpdb;
        if ( ! BESR_Schema::exists( BESR_Schema::runs() ) ) {
            return array();
        }
        $rt     = BESR_Schema::runs();
        $days   = (int) BESR_Settings::get( 'retention_days' );
        $keep   = (int) BESR_Settings::get( 'keep_runs' );
        $report = array( 'journals' => 0, 'runs' => 0 );
        $active = BESR_Run::active();

        if ( $days > 0 ) {
            $cut  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
            $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id FROM `{$rt}` WHERE status IN ('applied','undone') AND applied_at IS NOT NULL AND applied_at < %s", $cut ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            foreach ( $rows as $r ) {
                $run = BESR_Run::load( (int) $r['id'] );
                if ( $run && ! $run->get( 'journal_pruned' ) ) {
                    self::delete_run( $run->id() );
                    BESR_Matches::delete_run( $run->id() );
                    $run->set( 'journal_pruned', true );
                    $run->save();
                    ++$report['journals'];
                }
            }
        }
        // Old scans, cancelled and failed runs: remove entirely after 7 days.
        $cut  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id FROM `{$rt}` WHERE status IN ('scanned','cancelled','error') AND updated_at < %s", $cut ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        foreach ( $rows as $r ) {
            if ( $active && $active->id() === (int) $r['id'] ) {
                continue;
            }
            $run = BESR_Run::load( (int) $r['id'] );
            if ( $run ) {
                $run->delete();
                ++$report['runs'];
            }
        }
        // Keep at most N applied runs with a live journal.
        $rows = (array) $wpdb->get_results( "SELECT id FROM `{$rt}` WHERE status = 'applied' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        $n    = 0;
        foreach ( $rows as $r ) {
            $run = BESR_Run::load( (int) $r['id'] );
            if ( ! $run || $run->get( 'journal_pruned' ) ) {
                continue;
            }
            ++$n;
            if ( $n > $keep ) {
                self::delete_run( $run->id() );
                BESR_Matches::delete_run( $run->id() );
                $run->set( 'journal_pruned', true );
                $run->save();
                ++$report['journals'];
            }
        }
        // Stale locks on runs that are no longer running.
        $wpdb->query( "UPDATE `{$rt}` SET lock_token = NULL, lock_expires = NULL WHERE lock_expires IS NOT NULL AND lock_expires < UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        return $report;
    }

    public static function total_bytes(): int {
        global $wpdb;
        $rt = BESR_Schema::runs();
        return (int) $wpdb->get_var( "SELECT COALESCE(SUM(journal_bytes),0) FROM `{$rt}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }
}
