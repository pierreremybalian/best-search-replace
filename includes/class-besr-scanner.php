<?php
/**
 * Scan phases: describe the chosen tables, walk them in keyset chunks recording every
 * matching cell into the match index, then summarize. Nothing outside besr_* is written.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The scan phases: describe the chosen tables, walk them, then summarize.
 */
class BESR_Scanner {

    /**
     * Phase: describe every table, compile the ruleset, compute totals.
     */
    public static function phase_prepare( BESR_Run $run, BESR_Engine $engine ): bool {
        $names  = (array) $run->config( 'tables', array() );
        $total  = count( $names );
        $index  = (int) $run->cursor( 'prepare_index', 0 );
        $tables = $run->tables();
        $config = $run->config_all();

        while ( $index < $total && $engine->has_time() ) {
            $desc = BESR_Tables::describe( $names[ $index ], $config );
            if ( isset( $tables[ $index ] ) ) {
                $tables[ $index ] = $desc;
            } else {
                $tables[] = $desc;
            }
            ++$index;
            $run->set_tables( $tables );
            $run->set_cursor( 'prepare_index', $index );
            $run->save();
        }
        if ( $index < $total ) {
            return false;
        }

        $rows    = 0;
        $skipped = array();
        foreach ( $tables as $t ) {
            if ( 'skipped' === $t['status'] ) {
                $skipped[] = $t['name'] . ' (' . self::skip_label( $t['skip_reason'] ) . ')';
            } else {
                $rows += (int) $t['approx_rows'];
            }
        }
        $totals         = (array) $run->get( 'totals', array() );
        $totals['rows'] = max( 1, $rows );
        $run->set( 'totals', $totals );
        $run->log( sprintf( '%d tables described, ~%s rows to scan.', count( $tables ), number_format_i18n( $rows ) ) );
        if ( $skipped ) {
            $run->log( 'Not searchable: ' . implode( ', ', $skipped ) );
        }
        $run->save();
        return true;
    }

    public static function skip_label( string $reason ): string {
        switch ( $reason ) {
            case 'no_primary_key':
                return __( 'no primary key', 'best-search-replace' );
            case 'no_text_columns':
                return __( 'no text columns', 'best-search-replace' );
            case 'binary_only':
                return __( 'binary columns only', 'best-search-replace' );
        }
        return $reason;
    }

    /**
     * Phase: walk the tables.
     *
     * @throws RuntimeException When a table cannot be read.
     */
    public static function phase_scan( BESR_Run $run, BESR_Engine $engine ): bool {
        global $wpdb;

        $tables   = $run->tables();
        $index    = (int) $run->cursor( 'scan_index', 0 );
        $rules    = BESR_Ruleset::from_run( $run );
        $replacer = new BESR_Replacer( $rules );
        $matches  = new BESR_Matches( $run->id() );
        $config   = $run->config_all();
        $needles  = $rules->needle_strings();

        // Remove leftovers of a chunk that failed after a partial flush.
        $watermark = (int) $run->cursor( 'match_watermark', 0 );
        $mt        = BESR_Schema::matches();
        $wpdb->query( $wpdb->prepare( "DELETE FROM `{$mt}` WHERE run_id = %d AND id > %d", $run->id(), $watermark ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        $total = count( $tables );

        while ( $index < $total && $engine->has_time() ) {
            $t     = $tables[ $index ];
            $start = microtime( true );

            if ( 'skipped' === $t['status'] ) {
                $index = self::finish_table( $run, $index, $t );
                continue;
            }
            if ( 'pending' === $t['status'] ) {
                $t['status'] = 'scanning';
            }

            $table = BESR_Tables::name( $t['name'] );
            $text  = array_column( $t['columns'], 'name' );
            $extra = array();
            foreach ( array( 'option_name', 'meta_key' ) as $ctx_col ) {
                if ( ! in_array( $ctx_col, $text, true ) && self::table_has_column( $t, $ctx_col ) ) {
                    $extra[] = $ctx_col;
                }
            }
            $default_chunk = (int) max( 20, min( 2000, floor( 2097152 / max( 1, (int) $t['avg_row_length'] ) ) ) );
            $limit         = $engine->chunk( 'scan', $default_chunk );
            $like          = BESR_Tables::like_clause( $t['columns'], $needles, $rules->case_insensitive );
            $pkless        = 'none' === $t['pk_kind'];
            $select        = $pkless ? '*' : BESR_Tables::cols( array_unique( array_merge( $t['pk_columns'], $text, $extra ) ) );

            if ( 'int' === $t['pk_kind'] ) {
                $pk       = BESR_Tables::name( $t['pk_columns'][0] );
                $last     = $run->cursor( 'scan_last_pk', null );
                $where_pk = null === $last ? '1=1' : $wpdb->prepare( "`{$pk}` > %d", (int) $last ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                $sql      = "SELECT {$select} FROM `{$table}` WHERE {$where_pk} AND {$like} ORDER BY `{$pk}` ASC LIMIT " . (int) $limit;
            } elseif ( ! $pkless ) {
                $last     = $run->cursor( 'scan_last_pk', null );
                $where_pk = is_array( $last ) ? BESR_Tables::keyset_after( $t, $last ) : '1=1';
                $order    = BESR_Tables::cols( $t['pk_columns'] );
                $sql      = "SELECT {$select} FROM `{$table}` WHERE {$where_pk} AND {$like} ORDER BY {$order} LIMIT " . (int) $limit;
            } else {
                $offset = (int) $run->cursor( 'scan_offset', 0 );
                $sql    = "SELECT {$select} FROM `{$table}` WHERE {$like} LIMIT " . (int) $limit . ' OFFSET ' . $offset;
            }

            $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            if ( null === $rows && $wpdb->last_error ) {
                if ( ! $pkless && 'int' !== $t['pk_kind'] && $t['keyset'] ) {
                    // Server rejected the row-constructor comparison: fall back to LIMIT/OFFSET.
                    $t['keyset'] = false;
                    $run->update_table( $index, $t );
                    $run->log( sprintf( '%s: keyset pagination not supported here, using offsets.', $table ) );
                    continue;
                }
                throw new RuntimeException( sprintf( 'Query failed on %s: %s', $table, $wpdb->last_error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is logged or returned as JSON, never echoed as HTML; the UI escapes it on display.
            }
            $rows = (array) $rows;

            $cells = 0;
            $occ   = 0;
            $inc   = 0;
            foreach ( $rows as $row ) {
                $skip = BESR_Rules::skip_row( $t, $row, $config );
                if ( $skip ) {
                    $run->count_add( 'skipped_' . $skip );
                    continue;
                }
                $deferred = BESR_Rules::defer_row( $t, $row );
                $pk_json  = $pkless ? wp_json_encode( array( '__row' => $row ) ) : wp_json_encode( array_intersect_key( $row, array_flip( $t['pk_columns'] ) ) );
                if ( $pkless && strlen( $pk_json ) > 65536 ) {
                    $run->count_add( 'skipped_row_too_large' );
                    continue;
                }
                foreach ( $t['columns'] as $col ) {
                    $value = $row[ $col['name'] ] ?? null;
                    if ( ! is_string( $value ) || '' === $value ) {
                        continue;
                    }
                    $r = $replacer->process_cell( $value, array( 'table' => $table, 'column' => $col['name'], 'max_chars' => $col['max_chars'], 'max_bytes' => $col['max_bytes'] ) );
                    if ( 0 === $r->occurrences && null === $r->error ) {
                        continue;
                    }
                    $matches->add( array(
                        'table_name'     => $table,
                        'pk_json'        => $pk_json,
                        'column_name'    => $col['name'],
                        'path'           => $r->path,
                        'encoding'       => $r->encoding,
                        'occurrences'    => $r->occurrences,
                        'flagsets'       => $r->flagsets_json(),
                        'variants'       => implode( ',', array_keys( $r->variants ) ),
                        'snippet_before' => $r->snippet_before,
                        'snippet_after'  => $r->snippet_after,
                        'cell_hash'      => md5( $value ),
                        'cell_length'    => strlen( $value ),
                        'selection'      => 'auto',
                        'status'         => null === $r->error ? 'pending' : 'error',
                        'note'           => null !== $r->error ? $r->error : ( $deferred ? 'deferred' : '' ),
                    ) );
                    ++$cells;
                    $occ += $r->occurrences;
                    $inc += $r->included;
                    if ( null !== $r->error ) {
                        $run->count_add( 'scan_errors' );
                    }
                    if ( $deferred ) {
                        $run->count_add( 'deferred' );
                    }
                }
            }
            $matches->flush();

            $n                  = count( $rows );
            $t['scanned_rows'] += $n;
            $t['cells']        += $cells;
            $t['occurrences']  += $occ;
            $t['included']     += $inc;
            $run->count_add( 'rows_scanned', $n );
            $run->count_add( 'cells', $cells );
            $run->count_add( 'occurrences', $occ );
            $run->count_add( 'included', $inc );

            $table_done = $n < $limit;
            if ( $n > 0 ) {
                $last_row = end( $rows );
                if ( 'int' === $t['pk_kind'] ) {
                    $run->set_cursor( 'scan_last_pk', (int) $last_row[ $t['pk_columns'][0] ] );
                } elseif ( ! $pkless ) {
                    $run->set_cursor( 'scan_last_pk', array_intersect_key( $last_row, array_flip( $t['pk_columns'] ) ) );
                } else {
                    $run->set_cursor( 'scan_offset', (int) $run->cursor( 'scan_offset', 0 ) + $n );
                }
            }
            $engine->adapt_chunk( 'scan', microtime( true ) - $start, 20, 5000 );
            self::progress_rows( $run, $tables, $index, $t );

            if ( $table_done ) {
                $t['status'] = 'done';
                $run->update_table( $index, $t );
                $index = self::finish_table( $run, $index, $t );
            } else {
                $run->update_table( $index, $t );
                $run->set_cursor( 'match_watermark', self::max_match_id( $run->id() ) );
                $run->save();
            }
        }

        return $index >= $total;
    }

    private static function table_has_column( array $t, string $col ): bool {
        foreach ( $t['columns'] as $c ) {
            if ( $c['name'] === $col ) {
                return true;
            }
        }
        return in_array( $col, $t['pk_columns'], true ) || isset( $t['excluded_columns'][ $col ] );
    }

    private static function max_match_id( int $run_id ): int {
        global $wpdb;
        $mt = BESR_Schema::matches();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id),0) FROM `{$mt}` WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    private static function finish_table( BESR_Run $run, int $index, array $t ): int {
        if ( 'skipped' !== $t['status'] ) {
            $run->log( sprintf( '%s: %s rows scanned, %s matches in %s cells.', $t['name'], number_format_i18n( (int) $t['scanned_rows'] ), number_format_i18n( (int) $t['occurrences'] ), number_format_i18n( (int) $t['cells'] ) ) );
        }
        ++$index;
        $run->set_cursor( 'scan_index', $index );
        $run->set_cursor( 'scan_last_pk', null );
        $run->set_cursor( 'scan_offset', 0 );
        $run->count_add( 'tables_done' );
        self::progress_rows( $run, $run->tables(), $index, null );
        $run->set_cursor( 'match_watermark', self::max_match_id( $run->id() ) );
        $run->save();
        return $index;
    }

    /**
     * Rows_progress = rows of finished tables + estimated position inside the current one.
     */
    private static function progress_rows( BESR_Run $run, array $tables, int $index, ?array $current ): void {
        $done = 0;
        foreach ( $tables as $i => $t ) {
            if ( $i < $index && 'skipped' !== $t['status'] ) {
                $done += (int) $t['approx_rows'];
            }
        }
        if ( $current && 'int' === $current['pk_kind'] && null !== $current['pk_min'] && null !== $current['pk_max'] && $current['pk_max'] > $current['pk_min'] ) {
            $last  = $run->cursor( 'scan_last_pk', null );
            $frac  = null === $last ? 0 : ( (int) $last - $current['pk_min'] ) / ( $current['pk_max'] - $current['pk_min'] );
            $done += (int) ( $current['approx_rows'] * min( 1.0, max( 0.0, $frac ) ) );
        } elseif ( $current ) {
            $done += min( (int) $current['approx_rows'], (int) $current['scanned_rows'] );
        }
        $run->count_set( 'rows_progress', $done );
    }

    /**
     * Phase: exact totals, LIKE hits for what we did not search, journal estimate.
     */
    public static function phase_summarize( BESR_Run $run, BESR_Engine $engine ): bool {
        global $wpdb;
        $tables  = $run->tables();
        $rules   = BESR_Ruleset::from_run( $run );
        $needles = $rules->needle_strings();
        $index   = (int) $run->cursor( 'sum_index', 0 );

        if ( 0 === $index ) {
            $per = BESR_Matches::per_table( $run->id() );
            foreach ( $tables as $i => $t ) {
                $p                           = $per[ $t['name'] ] ?? array();
                $tables[ $i ]['cells']       = array_sum( array_column( $p, 'cells' ) );
                $tables[ $i ]['occurrences'] = array_sum( array_column( $p, 'occurrences' ) );
                $tables[ $i ]['errors']      = (int) ( $p['error']['cells'] ?? 0 );
            }
            $run->set_tables( $tables );
        }

        // LIKE hits for skipped tables and excluded columns, so "not searched" never means "nothing there".
        $total = count( $tables );
        while ( $index < $total && $engine->has_time() ) {
            $t = $tables[ $index ];
            if ( 'skipped' === $t['status'] && null === $t['like_hits'] && $needles ) {
                $cols = array();
                foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM `' . BESR_Tables::name( $t['name'] ) . '`', ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    if ( preg_match( BESR_Tables::TEXT_TYPES, strtolower( (string) $c['Type'] ) ) ) {
                        $cols[] = $c['Field'];
                    }
                }
                $t['like_hits'] = $cols ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . BESR_Tables::name( $t['name'] ) . '` WHERE ' . BESR_Tables::like_clause( $cols, $needles, $rules->case_insensitive ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            }
            if ( ! empty( $t['excluded_columns'] ) && $needles && ! isset( $t['excluded_hits'] ) ) {
                $t['excluded_hits'] = array();
                foreach ( array_keys( $t['excluded_columns'] ) as $col ) {
                    $t['excluded_hits'][ $col ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . BESR_Tables::name( $t['name'] ) . '` WHERE ' . BESR_Tables::like_clause( array( $col ), $needles, $rules->case_insensitive ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                }
            }
            $tables[ $index ] = $t;
            ++$index;
            $run->update_table( $index - 1, $t );
            $run->set_cursor( 'sum_index', $index );
            $run->save();
        }
        if ( $index < $total ) {
            return false;
        }

        self::refresh_summary( $run );
        $run->save();
        return true;
    }

    /**
     * Recompute the review summary (also called after exclusion/selection changes).
     */
    public static function refresh_summary( BESR_Run $run ): void {
        $excluded = $run->excluded_contexts();
        $eff      = BESR_Matches::effective_totals( $run->id(), $excluded );
        $status   = BESR_Matches::count_status( $run->id() );
        $tables   = $run->tables();
        $summary  = $run->summary();

        $skipped_tables = array();
        $excluded_cols  = array();
        foreach ( $tables as $t ) {
            if ( 'skipped' === $t['status'] ) {
                $skipped_tables[] = array( 'name' => $t['name'], 'reason' => $t['skip_reason'], 'label' => self::skip_label( $t['skip_reason'] ), 'like_hits' => $t['like_hits'] );
            }
            foreach ( (array) $t['excluded_columns'] as $col => $reason ) {
                $excluded_cols[] = array( 'table' => $t['name'], 'column' => $col, 'reason' => $reason, 'label' => BESR_Rules::exclusion_label( $reason ), 'like_hits' => $t['excluded_hits'][ $col ] ?? null );
            }
        }

        $flags = array();
        foreach ( $eff['by_flag'] as $flag => $count ) {
            $flags[ $flag ] = array(
                'flag'      => $flag,
                'count'     => (int) $count,
                'excluded'  => in_array( $flag, $excluded, true ) || in_array( $flag, BESR_Context::HARD, true ),
                'hard'      => in_array( $flag, BESR_Context::HARD, true ),
                'toggle'    => in_array( $flag, BESR_Context::TOGGLEABLE, true ),
                'label'     => BESR_Context::label( $flag ),
                'explain'   => BESR_Context::explain( $flag, $run->search(), $run->replace() ),
            );
        }

        $summary['counts']           = array(
            'occurrences'     => $eff['occurrences'],
            'included'        => $eff['included'],
            'cells'           => $eff['cells'],
            'cells_included'  => $eff['cells_included'],
            'cells_skipped'   => $eff['cells_skipped'],
            'errors'          => (int) ( $status['error'] ?? 0 ),
            'tables_scanned'  => count( array_filter( $tables, function ( $t ) {
                return 'skipped' !== $t['status'];
            } ) ),
            'tables_matched'  => count( array_filter( $tables, function ( $t ) {
                return (int) $t['cells'] > 0;
            } ) ),
            'tables_total'    => count( $tables ),
            'deferred'        => $run->count_get( 'deferred' ),
            'skipped_transient' => $run->count_get( 'skipped_transient' ),
            'journal_estimate' => BESR_Matches::journal_estimate( $run->id() ),
        );
        $summary['flags']            = $flags;
        $summary['skipped_tables']   = $skipped_tables;
        $summary['excluded_columns'] = $excluded_cols;
        $run->set_summary( $summary );
        $run->count_set( 'included', $eff['included'] );
        $run->count_set( 'occurrences', $eff['occurrences'] );
        $run->count_set( 'cells', $eff['cells'] );
    }
}
