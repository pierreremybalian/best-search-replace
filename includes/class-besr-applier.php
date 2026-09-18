<?php
/**
 * Apply phases: replay the match index. Every cell is re-read by primary key and
 * checked against the hash recorded at scan time before anything is written.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The apply phases: replay the match index, verifying every cell before writing.
 */
class BESR_Applier {

    /**
     * Phase: work out how much there is to do and clear the counters.
     *
     * @param BESR_Run    $run    The run being processed.
     * @param BESR_Engine $engine Unused here, but every phase callback receives it.
     * @return bool True when the phase is finished.
     */
    public static function phase_prepare( BESR_Run $run, BESR_Engine $engine ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the phase-callback signature; BESR_Engine passes it to every phase.
        global $wpdb;
        $packet = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
        $run->set( 'max_allowed_packet', $packet > 0 ? $packet : 4194304 );

        $status            = BESR_Matches::count_status( $run->id() );
        $totals            = (array) $run->get( 'totals', array() );
        $totals['matches'] = (int) ( $status['pending'] ?? 0 );
        $run->set( 'totals', $totals );
        $run->count_set( 'matches_done', 0 );
        $run->count_set( 'applied_cells', 0 );
        $run->count_set( 'applied_occurrences', 0 );
        $run->count_set( 'rows_updated', 0 );
        $run->count_set( 'stale', 0 );
        $run->count_set( 'noop', 0 );
        $run->count_set( 'apply_errors', 0 );
        $run->count_set( 'apply_skipped', 0 );
        $run->set( 'deferred_ids', array() );
        $run->set( 'error_list', array() );
        $run->set( 'tables_updated', array() );
        $run->set_cursor( 'apply_after', 0 );

        $tables = $run->tables();
        foreach ( $tables as $i => $t ) {
            $tables[ $i ]['updated'] = 0;
            $tables[ $i ]['errors']  = 0;
        }
        $run->set_tables( $tables );

        BESR_Journal::prune();

        $run->log( sprintf( '%s cells to process. Undo journal cap: %s.', number_format_i18n( $totals['matches'] ), size_format( BESR_Journal::max_bytes() ) ) );
        $run->save();
        return true;
    }

    public static function phase_rows( BESR_Run $run, BESR_Engine $engine ): bool {
        global $wpdb;
        $after = (int) $run->cursor( 'apply_after', 0 );

        while ( $engine->has_time() ) {
            $start = microtime( true );
            $limit = $engine->chunk( 'apply', 100 );
            $rows  = BESR_Matches::pending_after( $run->id(), $after, $limit );
            if ( ! $rows ) {
                return true;
            }
            self::process_chunk( $run, $rows, false );
            $after = (int) end( $rows )['id'];
            $run->set_cursor( 'apply_after', $after );
            $run->save();
            $engine->adapt_chunk( 'apply', microtime( true ) - $start, 20, 500 );
        }
        return false;
    }

    /**
     * Phase: write the site address settings, held back until everything else is done.
     *
     * @param BESR_Run    $run    The run being processed.
     * @param BESR_Engine $engine Unused here, but every phase callback receives it.
     * @return bool True when the phase is finished.
     */
    public static function phase_deferred( BESR_Run $run, BESR_Engine $engine ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the phase-callback signature; BESR_Engine passes it to every phase.
        $ids = (array) $run->get( 'deferred_ids', array() );
        if ( $ids ) {
            $rows = BESR_Matches::by_ids( $ids );
            if ( $run->config( 'keep_siteurl' ) ) {
                BESR_Matches::set_status( array_column( $rows, 'id' ), 'skipped', 'site address kept unchanged' );
                $run->count_add( 'apply_skipped', count( $rows ) );
                $run->log( 'Site address settings left unchanged as requested.' );
            } else {
                self::process_chunk( $run, $rows, true );
                $home = get_option( 'home' );
                wp_cache_delete( 'home', 'options' );
                wp_cache_delete( 'siteurl', 'options' );
                wp_cache_delete( 'alloptions', 'options' );
                $new_home = (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", 'home' ) );
                if ( $new_home && $new_home !== $home ) {
                    $run->set( 'new_home', $new_home );
                    $run->log( 'Site address changed to ' . $new_home );
                }
            }
            $run->set( 'deferred_ids', array() );
        }
        $run->save();
        return true;
    }

    /**
     * Phase: flush caches and record what the run changed.
     *
     * @param BESR_Run    $run    The run being processed.
     * @param BESR_Engine $engine Unused here, but every phase callback receives it.
     * @return bool True when the phase is finished.
     */
    public static function phase_finalize( BESR_Run $run, BESR_Engine $engine ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the phase-callback signature; BESR_Engine passes it to every phase.
        $updated = (array) $run->get( 'tables_updated', array() );
        $run->count_set( 'tables_updated', count( $updated ) );
        foreach ( BESR_Rules::after_write( $run, isset( $updated[ $GLOBALS['wpdb']->options ] ) ) as $line ) {
            $run->log( $line );
        }
        foreach ( $run->tables() as $t ) {
            if ( $t['name'] === $GLOBALS['wpdb']->postmeta && (int) ( $t['updated'] ?? 0 ) > 0 && defined( 'ELEMENTOR_VERSION' ) ) {
                $run->log( 'Elementor detected: regenerate CSS under Elementor → Tools → Regenerate Files & Data.' );
                break;
            }
        }
        $summary          = $run->summary();
        $summary['apply'] = $run->counts();
        $run->set_summary( $summary );
        do_action( 'besr_after_apply', $run );
        $run->save();
        return true;
    }

    // ------------------------------------------------------------------

    /**
     * Re-read, verify, journal and update a chunk of match rows.
     *
     * @throws Throwable When a chunk fails; the surrounding transaction is rolled back first.
     */
    private static function process_chunk( BESR_Run $run, array $rows, bool $deferred_pass ): void {
        global $wpdb;
        $rules      = BESR_Ruleset::from_run( $run );
        $replacer   = new BESR_Replacer( $rules );
        $excluded   = $run->excluded_contexts();
        $stale_mode = (string) $run->config( 'stale', 'skip' );
        $journal_on = ! $run->config( 'no_journal' );
        $packet     = (int) $run->get( 'max_allowed_packet', 4194304 );

        // Group consecutive rows of the same table + pk.
        $groups = array();
        foreach ( $rows as $m ) {
            $groups[ $m['table_name'] . '|' . $m['pk_json'] ][] = $m;
        }

        $tables  = $run->tables();
        $by_name = array();
        foreach ( $tables as $i => $t ) {
            $by_name[ $t['name'] ] = $i;
        }
        $tables_updated = (array) $run->get( 'tables_updated', array() );
        $error_list     = (array) $run->get( 'error_list', array() );

        $innodb = true;
        foreach ( $groups as $g ) {
            $idx = $by_name[ $g[0]['table_name'] ] ?? null;
            if ( null === $idx || 'innodb' !== strtolower( (string) $tables[ $idx ]['engine'] ) ) {
                $innodb = false;
            }
        }
        if ( $innodb ) {
            $wpdb->query( 'START TRANSACTION' );
        }

        try {
            foreach ( $groups as $group ) {
                $first = $group[0];
                $idx   = $by_name[ $first['table_name'] ] ?? null;
                $ids   = array_column( $group, 'id' );
                $run->count_add( 'matches_done', count( $group ) );
                if ( null === $idx ) {
                    BESR_Matches::set_status( $ids, 'skipped', 'table not part of this run' );
                    $run->count_add( 'apply_skipped', count( $group ) );
                    continue;
                }
                $desc  = $tables[ $idx ];
                $table = BESR_Tables::name( $desc['name'] );
                $pk    = json_decode( (string) $first['pk_json'], true );
                if ( ! is_array( $pk ) ) {
                    BESR_Matches::set_status( $ids, 'error', 'unreadable primary key' );
                    $run->count_add( 'apply_errors', count( $group ) );
                    continue;
                }

                // Deferred rows (siteurl/home) wait for the last phase.
                if ( ! $deferred_pass ) {
                    $is_deferred = false;
                    foreach ( $group as $m ) {
                        if ( 'deferred' === $m['note'] ) {
                            $is_deferred = true;
                        }
                    }
                    if ( $is_deferred ) {
                        $run->set( 'deferred_ids', array_merge( (array) $run->get( 'deferred_ids', array() ), $ids ) );
                        continue;
                    }
                }

                $cols   = array_values( array_unique( array_merge( $desc['pk_columns'], array_column( $group, 'column_name' ) ) ) );
                $select = isset( $pk['__row'] ) ? '*' : BESR_Tables::cols( $cols );
                $where  = BESR_Tables::pk_where( $desc, $pk );
                $row    = $wpdb->get_row( "SELECT {$select} FROM `{$table}` WHERE {$where} LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                if ( ! $row ) {
                    BESR_Matches::set_status( $ids, 'skipped', 'row deleted since the scan' );
                    $run->count_add( 'apply_skipped', count( $group ) );
                    continue;
                }

                $updates  = array();
                $applied  = array();
                $occ      = 0;
                $col_meta = array();
                foreach ( $desc['columns'] as $c ) {
                    $col_meta[ $c['name'] ] = $c;
                }

                foreach ( $group as $m ) {
                    $col     = $m['column_name'];
                    $current = $row[ $col ] ?? null;
                    if ( ! is_string( $current ) ) {
                        BESR_Matches::set_status( array( $m['id'] ), 'skipped', 'column missing' );
                        $run->count_add( 'apply_skipped' );
                        continue;
                    }
                    if ( md5( $current ) !== $m['cell_hash'] ) {
                        if ( 'replace' !== $stale_mode ) {
                            BESR_Matches::set_status( array( $m['id'] ), 'stale', 'changed since the scan' );
                            $run->count_add( 'stale' );
                            continue;
                        }
                    }
                    $meta = array( 'table' => $table, 'column' => $col, 'max_chars' => $col_meta[ $col ]['max_chars'] ?? null, 'max_bytes' => $col_meta[ $col ]['max_bytes'] ?? null );
                    $r    = $replacer->process_cell( $current, $meta, false, (string) $m['selection'] );
                    if ( null !== $r->error ) {
                        BESR_Matches::set_status( array( $m['id'] ), 'error', $r->error );
                        $run->count_add( 'apply_errors' );
                        $tables[ $idx ]['errors'] = (int) ( $tables[ $idx ]['errors'] ?? 0 ) + 1;
                        $error_list[]             = array( 'table' => $table, 'pk' => $pk, 'column' => $col, 'message' => $r->error );
                        continue;
                    }
                    if ( ! $r->changed || 0 === $r->included ) {
                        BESR_Matches::set_status( array( $m['id'] ), 'noop', 0 === $r->included ? 'all occurrences skipped' : '' );
                        $run->count_add( 'noop' );
                        continue;
                    }
                    if ( $journal_on ) {
                        $res = BESR_Journal::record( $run, $m, $current, $r->value, $deferred_pass, $packet );
                        if ( true !== $res ) {
                            BESR_Matches::set_status( array( $m['id'] ), 'skipped', $res );
                            $run->count_add( 'apply_skipped' );
                            continue;
                        }
                    }
                    $updates[ $col ] = $r->value;
                    $applied[]       = (int) $m['id'];
                    $occ            += $r->included;
                }

                if ( ! $updates ) {
                    continue;
                }
                $set = array();
                foreach ( $updates as $col => $val ) {
                    $set[] = $wpdb->prepare( '`' . BESR_Tables::name( $col ) . '` = %s', $val ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                }
                $sql  = "UPDATE `{$table}` SET " . implode( ', ', $set ) . " WHERE {$where}" . ( isset( $pk['__row'] ) ? ' LIMIT 1' : '' );
                $done = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                if ( false === $done ) {
                    $msg = $wpdb->last_error ?: 'update failed';
                    BESR_Matches::set_status( $applied, 'error', $msg );
                    $run->count_add( 'apply_errors', count( $applied ) );
                    $tables[ $idx ]['errors'] = (int) ( $tables[ $idx ]['errors'] ?? 0 ) + count( $applied );
                    $error_list[]             = array( 'table' => $table, 'pk' => $pk, 'column' => implode( ',', array_keys( $updates ) ), 'message' => $msg );
                    if ( $journal_on ) {
                        BESR_Journal::discard_for_matches( $applied );
                    }
                    continue;
                }
                BESR_Matches::set_status( $applied, 'applied' );
                $run->count_add( 'applied_cells', count( $applied ) );
                $run->count_add( 'applied_occurrences', $occ );
                $run->count_add( 'rows_updated' );
                $tables[ $idx ]['updated']       = (int) ( $tables[ $idx ]['updated'] ?? 0 ) + 1;
                $tables_updated[ $desc['name'] ] = true;
            }

            $run->set_tables( $tables );
            $run->set( 'tables_updated', $tables_updated );
            $run->set( 'error_list', array_slice( $error_list, 0, 200 ) );
            if ( $innodb ) {
                $run->save();
                $wpdb->query( 'COMMIT' );
            }
        } catch ( Throwable $e ) {
            if ( $innodb ) {
                $wpdb->query( 'ROLLBACK' );
            }
            throw $e;
        }
    }
}
