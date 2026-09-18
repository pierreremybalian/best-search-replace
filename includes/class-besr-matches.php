<?php
/**
 * The match index: one row per cell that contains the search text, written during
 * the scan and replayed during apply. Also powers the review screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The match index: one row per cell that contains the search text, written during
 * the scan and replayed during apply.
 */
class BESR_Matches {

    const BATCH_ROWS  = 200;
    const BATCH_BYTES = 1048576;

    /**
     * @var array
     */
    private array $buffer = array();

    /**
     * @var int
     */
    private int $buffer_bytes = 0;

    /**
     * @var int
     */
    private int $run_id;

    public function __construct( int $run_id ) {
        $this->run_id = $run_id;
    }

    public function add( array $row ): void {
        $this->buffer[]      = $row;
        $this->buffer_bytes += strlen( $row['snippet_before'] ) + strlen( $row['snippet_after'] ) + strlen( $row['pk_json'] ) + 200;
        if ( count( $this->buffer ) >= self::BATCH_ROWS || $this->buffer_bytes >= self::BATCH_BYTES ) {
            $this->flush();
        }
    }

    public function flush(): void {
        global $wpdb;
        if ( ! $this->buffer ) {
            return;
        }
        $table  = BESR_Schema::matches();
        $values = array();
        foreach ( $this->buffer as $r ) {
            $values[] = $wpdb->prepare(
                '(%d,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%d,%s,%s,%s)',
                $this->run_id,
                $r['table_name'],
                $r['pk_json'],
                $r['column_name'],
                $r['path'],
                $r['encoding'],
                $r['occurrences'],
                $r['flagsets'],
                $r['variants'],
                $r['snippet_before'],
                $r['snippet_after'],
                $r['cell_hash'],
                $r['cell_length'],
                $r['selection'] ?? 'auto',
                $r['status'] ?? 'pending',
                $r['note'] ?? ''
            );
        }
        $sql = "INSERT INTO `{$table}` (run_id, table_name, pk_json, column_name, path, encoding, occurrences, flagsets, variants, snippet_before, snippet_after, cell_hash, cell_length, selection, status, note) VALUES " . implode( ',', $values );
        if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            throw new RuntimeException( 'Could not write the match index: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is logged or returned as JSON, never echoed as HTML; the UI escapes it on display.
        }
        $this->buffer       = array();
        $this->buffer_bytes = 0;
    }

    // ---- reading ----

    /**
     * A page of still-pending matches, ordered by id.
     */
    public static function pending_after( int $run_id, int $after_id, int $limit ): array {
        global $wpdb;
        $table = BESR_Schema::matches();
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE run_id = %d AND status = 'pending' AND id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            $run_id,
            $after_id,
            $limit
        ), ARRAY_A );
    }

    public static function by_ids( array $ids ): array {
        global $wpdb;
        if ( ! $ids ) {
            return array();
        }
        $table = BESR_Schema::matches();
        $in    = implode( ',', array_map( 'intval', $ids ) );
        return (array) $wpdb->get_results( "SELECT * FROM `{$table}` WHERE id IN ({$in}) ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $table = BESR_Schema::matches();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        return $row ?: null;
    }

    /**
     * GROUP BY flagsets, selection: the basis for instant category toggles.
     *
     * @return array [ ['flagsets' => json, 'selection' => s, 'cells' => n, 'occurrences' => n] ]
     */
    public static function flagset_totals( int $run_id, string $status = 'pending' ): array {
        global $wpdb;
        $table = BESR_Schema::matches();
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT flagsets, selection, COUNT(*) AS cells, SUM(occurrences) AS occurrences FROM `{$table}` WHERE run_id = %d AND status = %s GROUP BY flagsets, selection", // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            $run_id,
            $status
        ), ARRAY_A );
    }

    /**
     * How many occurrences in a flagset map would be replaced, given the exclusions and a selection.
     */
    public static function included_count( array $flagsets, array $excluded, string $selection ): int {
        if ( 'skip' === $selection ) {
            return 0;
        }
        $n = 0;
        foreach ( $flagsets as $set => $count ) {
            $flags = '' === $set ? array() : explode( '|', $set );
            if ( array_intersect( $flags, BESR_Context::HARD ) ) {
                continue;
            }
            if ( 'include' !== $selection && array_intersect( $flags, $excluded ) ) {
                continue;
            }
            $n += (int) $count;
        }
        return $n;
    }

    public static function decode_flagsets( string $json ): array {
        $d = json_decode( $json, true );
        return is_array( $d ) ? $d : array();
    }

    /**
     * Effective totals: occurrences, cells with something to replace, cells fully skipped, per-flag counts.
     */
    public static function effective_totals( int $run_id, array $excluded ): array {
        $rows = self::flagset_totals( $run_id );
        $out  = array( 'occurrences' => 0, 'included' => 0, 'cells' => 0, 'cells_included' => 0, 'cells_skipped' => 0, 'by_flag' => array(), 'by_flag_excluded' => array() );
        foreach ( $rows as $r ) {
            // Every cell in this GROUP BY row has the same flagsets, so per-cell counts multiply by cells.
            $sets                = self::decode_flagsets( (string) $r['flagsets'] );
            $cells               = (int) $r['cells'];
            $occ                 = (int) $r['occurrences'];
            $inc                 = self::included_count( $sets, $excluded, (string) $r['selection'] ) * $cells;
            $out['occurrences'] += $occ;
            $out['included']    += $inc;
            $out['cells']       += $cells;
            if ( $inc > 0 ) {
                $out['cells_included'] += $cells;
            } else {
                $out['cells_skipped'] += $cells;
            }
            // flags: a flagset row aggregates $cells cells; occurrences per set are split proportionally.
            foreach ( $sets as $set => $count ) {
                if ( '' === $set ) {
                    continue;
                }
                foreach ( explode( '|', $set ) as $flag ) {
                    $out['by_flag'][ $flag ] = ( $out['by_flag'][ $flag ] ?? 0 ) + (int) $count * $cells;
                    if ( in_array( $flag, BESR_Context::HARD, true ) || ( 'include' !== $r['selection'] && in_array( $flag, $excluded, true ) ) || 'skip' === $r['selection'] ) {
                        $out['by_flag_excluded'][ $flag ] = ( $out['by_flag_excluded'][ $flag ] ?? 0 ) + (int) $count * $cells;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Per-table totals for pending matches (exact numbers after scan).
     */
    public static function per_table( int $run_id ): array {
        global $wpdb;
        $table = BESR_Schema::matches();
        $rows  = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT table_name, status, COUNT(*) AS cells, SUM(occurrences) AS occurrences FROM `{$table}` WHERE run_id = %d GROUP BY table_name, status", // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            $run_id
        ), ARRAY_A );
        $out   = array();
        foreach ( $rows as $r ) {
            $out[ $r['table_name'] ][ $r['status'] ] = array( 'cells' => (int) $r['cells'], 'occurrences' => (int) $r['occurrences'] );
        }
        return $out;
    }

    public static function count_status( int $run_id ): array {
        global $wpdb;
        $table = BESR_Schema::matches();
        $rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM `{$table}` WHERE run_id = %d GROUP BY status", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        $out   = array();
        foreach ( $rows as $r ) {
            $out[ $r['status'] ] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Rough undo-journal size: bytes of every pending cell that has something to replace.
     */
    public static function journal_estimate( int $run_id ): int {
        global $wpdb;
        $table = BESR_Schema::matches();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cell_length),0) FROM `{$table}` WHERE run_id = %d AND status = 'pending' AND selection <> 'skip'", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    /**
     * Review page query.
     *
     * @param int   $run_id Run the matches belong to.
     * @param array $args   table, flag, view (all|flagged|skipped|errors|stale), q, page, per_page, excluded, status.
     * @return array Total, page, pages, per_page and the presented rows.
     */
    public static function query( int $run_id, array $args ): array {
        global $wpdb;
        $table    = BESR_Schema::matches();
        $per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $clauses  = array( $wpdb->prepare( 'run_id = %d', $run_id ) );

        if ( ! empty( $args['table'] ) ) {
            $clauses[] = $wpdb->prepare( 'table_name = %s', $args['table'] );
        }
        if ( ! empty( $args['flag'] ) ) {
            // A flag is stored either as the whole key ("flag") or as one part of a compound key ("a|flag").
            $clauses[] = '(' . $wpdb->prepare( 'flagsets LIKE %s', '%' . $wpdb->esc_like( '"' . $args['flag'] ) . '%' )
                . ' OR ' . $wpdb->prepare( 'flagsets LIKE %s', '%' . $wpdb->esc_like( '|' . $args['flag'] ) . '%' ) . ')';
        }
        if ( ! empty( $args['status'] ) ) {
            $clauses[] = $wpdb->prepare( 'status = %s', $args['status'] );
        }

        $view = (string) ( $args['view'] ?? 'all' );
        if ( 'flagged' === $view ) {
            // Any key that is not the empty one, wherever it sits in the JSON object.
            $clauses[] = 'flagsets REGEXP \'"[^"]+":\'';
        } elseif ( 'skipped' === $view ) {
            $clauses[] = "selection = 'skip'";
        } elseif ( 'errors' === $view ) {
            $clauses[] = "status = 'error'";
        } elseif ( 'stale' === $view ) {
            $clauses[] = "status IN ('stale','skipped')";
        }

        if ( ! empty( $args['q'] ) ) {
            $like      = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
            $clauses[] = $wpdb->prepare( '(snippet_before LIKE %s OR pk_json LIKE %s OR path LIKE %s OR column_name LIKE %s)', $like, $like, $like, $like );
        }

        $where = implode( ' AND ', $clauses );
        // $per_page and $offset are clamped integers above, so they are interpolated rather than
        // running the already-prepared $where through a second prepare().
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifier is a plugin table name; every value in $where uses a placeholder.
        $rows  = (array) $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id ASC LIMIT {$per_page} OFFSET {$offset}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifier is a plugin table name; every value in $where uses a placeholder and the limits are clamped integers.
        return array(
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int) ceil( $total / $per_page ),
            'per_page' => $per_page,
            'rows'     => self::present( $rows, (array) ( $args['excluded'] ?? array() ) ),
        );
    }

    /**
     * Two example rows per flag for the warnings panel.
     */
    public static function examples( int $run_id, string $flag, int $limit = 2 ): array {
        global $wpdb;
        $table = BESR_Schema::matches();
        $rows  = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE run_id = %d AND (flagsets LIKE %s OR flagsets LIKE %s) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            $run_id,
            '%' . $wpdb->esc_like( '"' . $flag ) . '%',
            '%' . $wpdb->esc_like( '|' . $flag ) . '%',
            $limit
        ), ARRAY_A );
        return self::present( $rows, array() );
    }

    /**
     * Add human context (post title, option name ...) and the effective decision.
     */
    public static function present( array $rows, array $excluded ): array {
        $rows = self::enrich( $rows );
        foreach ( $rows as &$r ) {
            $sets             = self::decode_flagsets( (string) $r['flagsets'] );
            $r['flagsets']    = $sets;
            $r['pk']          = json_decode( (string) $r['pk_json'], true );
            $r['flags']       = array_values( array_unique( array_filter( explode( '|', implode( '|', array_keys( $sets ) ) ) ) ) );
            $r['included']    = in_array( $r['status'], array( 'pending', 'applied' ), true ) ? self::included_count( $sets, $excluded, (string) $r['selection'] ) : 0;
            $r['occurrences'] = (int) $r['occurrences'];
            $r['id']          = (int) $r['id'];
            $r['cell_length'] = (int) $r['cell_length'];
            $r['before']      = self::mark_snippet( (string) $r['snippet_before'] );
            $r['after']       = self::mark_snippet( (string) $r['snippet_after'] );
            unset( $r['snippet_before'], $r['snippet_after'], $r['pk_json'], $r['cell_hash'] );
        }
        unset( $r );
        return $rows;
    }

    /**
     * Escape the snippet and turn the \x01 \x02 markers into <mark>.
     */
    public static function mark_snippet( string $s ): string {
        $s = esc_html( $s );
        return str_replace( array( "\x01", "\x02" ), array( '<mark>', '</mark>' ), $s );
    }

    /**
     * Human "where": post titles, option names, meta keys, user logins.
     */
    public static function enrich( array $rows ): array {
        global $wpdb;
        $by_table = array();
        foreach ( $rows as $i => $r ) {
            $by_table[ $r['table_name'] ][ $i ] = json_decode( (string) $r['pk_json'], true );
        }
        foreach ( $rows as &$r ) {
            $pk         = json_decode( (string) $r['pk_json'], true );
            $r['where'] = array( 'type' => '', 'label' => isset( $pk['__row'] ) ? __( 'row (no primary key)', 'best-search-replace' ) : '#' . implode( ', ', array_map( 'strval', (array) $pk ) ), 'url' => '' );
        }
        unset( $r );

        foreach ( $by_table as $table => $pks ) {
            $ids = array();
            foreach ( $pks as $pk ) {
                if ( is_array( $pk ) && 1 === count( $pk ) && ! isset( $pk['__row'] ) ) {
                    $ids[] = (int) reset( $pk );
                }
            }
            $ids = array_values( array_unique( array_filter( $ids ) ) );
            if ( ! $ids ) {
                continue;
            }
            $in   = implode( ',', $ids );
            $info = array();
            if ( $table === $wpdb->posts ) {
                foreach ( (array) $wpdb->get_results( "SELECT ID, post_title, post_type FROM `{$wpdb->posts}` WHERE ID IN ({$in})", ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    $pto              = get_post_type_object( $p['post_type'] );
                    $info[ $p['ID'] ] = array( 'type' => $pto ? $pto->labels->singular_name : $p['post_type'], 'label' => '' !== $p['post_title'] ? $p['post_title'] : __( '(no title)', 'best-search-replace' ), 'url' => get_edit_post_link( (int) $p['ID'], 'raw' ) ?: '' );
                }
            } elseif ( $table === $wpdb->postmeta ) {
                $metas = (array) $wpdb->get_results( "SELECT m.meta_id, m.meta_key, m.post_id, p.post_title, p.post_type FROM `{$wpdb->postmeta}` m LEFT JOIN `{$wpdb->posts}` p ON p.ID = m.post_id WHERE m.meta_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                foreach ( $metas as $m ) {
                    $pto                   = $m['post_type'] ? get_post_type_object( $m['post_type'] ) : null;
                    $info[ $m['meta_id'] ] = array(
                        'type'  => $m['meta_key'],
                        /* translators: %s: the name of the post or term the value belongs to. */
                        'label' => $m['post_title'] ? sprintf( __( 'on "%s"', 'best-search-replace' ), $m['post_title'] ) : sprintf( __( 'on post #%d', 'best-search-replace' ), (int) $m['post_id'] ),
                        'url'   => $m['post_title'] ? ( get_edit_post_link( (int) $m['post_id'], 'raw' ) ?: '' ) : '',
                        'sub'   => $pto ? $pto->labels->singular_name : '',
                    );
                }
            } elseif ( $table === $wpdb->options ) {
                foreach ( (array) $wpdb->get_results( "SELECT option_id, option_name FROM `{$wpdb->options}` WHERE option_id IN ({$in})", ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    $info[ $o['option_id'] ] = array( 'type' => __( 'Setting', 'best-search-replace' ), 'label' => $o['option_name'], 'url' => '' );
                }
            } elseif ( $table === $wpdb->usermeta ) {
                foreach ( (array) $wpdb->get_results( "SELECT m.umeta_id, m.meta_key, u.user_login, u.ID FROM `{$wpdb->usermeta}` m LEFT JOIN `{$wpdb->users}` u ON u.ID = m.user_id WHERE m.umeta_id IN ({$in})", ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    /* translators: %s: user login */
                    $info[ $m['umeta_id'] ] = array( 'type' => $m['meta_key'], 'label' => sprintf( __( 'for %s', 'best-search-replace' ), $m['user_login'] ), 'url' => $m['ID'] ? get_edit_user_link( (int) $m['ID'] ) : '' );
                }
            } elseif ( $table === $wpdb->users ) {
                foreach ( (array) $wpdb->get_results( "SELECT ID, user_login FROM `{$wpdb->users}` WHERE ID IN ({$in})", ARRAY_A ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    $info[ $u['ID'] ] = array( 'type' => __( 'User', 'best-search-replace' ), 'label' => $u['user_login'], 'url' => get_edit_user_link( (int) $u['ID'] ) );
                }
            } elseif ( $table === $wpdb->comments ) {
                foreach ( (array) $wpdb->get_results( "SELECT c.comment_ID, p.post_title FROM `{$wpdb->comments}` c LEFT JOIN `{$wpdb->posts}` p ON p.ID = c.comment_post_ID WHERE c.comment_ID IN ({$in})", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    /* translators: 1: comment id, 2: post title */
                    $info[ $c['comment_ID'] ] = array( 'type' => __( 'Comment', 'best-search-replace' ), 'label' => sprintf( __( '#%1$d on "%2$s"', 'best-search-replace' ), (int) $c['comment_ID'], (string) $c['post_title'] ), 'url' => admin_url( 'comment.php?action=editcomment&c=' . (int) $c['comment_ID'] ) );
                }
            } elseif ( $table === $wpdb->commentmeta ) {
                foreach ( (array) $wpdb->get_results( "SELECT meta_id, meta_key, comment_id FROM `{$wpdb->commentmeta}` WHERE meta_id IN ({$in})", ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    /* translators: %d: comment ID. */
                    $info[ $m['meta_id'] ] = array( 'type' => $m['meta_key'], 'label' => sprintf( __( 'on comment #%d', 'best-search-replace' ), (int) $m['comment_id'] ), 'url' => '' );
                }
            } elseif ( $table === $wpdb->terms ) {
                foreach ( (array) $wpdb->get_results( "SELECT term_id, name FROM `{$wpdb->terms}` WHERE term_id IN ({$in})", ARRAY_A ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    $info[ $t['term_id'] ] = array( 'type' => __( 'Term', 'best-search-replace' ), 'label' => $t['name'], 'url' => '' );
                }
            } elseif ( $table === $wpdb->term_taxonomy ) {
                foreach ( (array) $wpdb->get_results( "SELECT tt.term_taxonomy_id, tt.taxonomy, t.name FROM `{$wpdb->term_taxonomy}` tt LEFT JOIN `{$wpdb->terms}` t ON t.term_id = tt.term_id WHERE tt.term_taxonomy_id IN ({$in})", ARRAY_A ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    $info[ $t['term_taxonomy_id'] ] = array( 'type' => $t['taxonomy'], 'label' => (string) $t['name'], 'url' => '' );
                }
            } elseif ( $table === $wpdb->termmeta ) {
                foreach ( (array) $wpdb->get_results( "SELECT m.meta_id, m.meta_key, t.name FROM `{$wpdb->termmeta}` m LEFT JOIN `{$wpdb->terms}` t ON t.term_id = m.term_id WHERE m.meta_id IN ({$in})", ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                    /* translators: %s: the name of the post or term the value belongs to. */
                    $info[ $m['meta_id'] ] = array( 'type' => $m['meta_key'], 'label' => sprintf( __( 'on "%s"', 'best-search-replace' ), (string) $m['name'] ), 'url' => '' );
                }
            }
            if ( ! $info ) {
                continue;
            }
            foreach ( $pks as $i => $pk ) {
                $id = is_array( $pk ) ? (int) reset( $pk ) : 0;
                if ( isset( $info[ $id ] ) ) {
                    $rows[ $i ]['where'] = $info[ $id ];
                }
            }
        }
        return $rows;
    }

    // ---- writing ----

    /**
     * Mark a set of matches as always replaced, always skipped, or decided by their flags.
     *
     * @param int    $run_id    Run the matches belong to.
     * @param array  $scope     ['ids' => int[]], ['table' => name] or ['all' => true].
     * @param string $selection auto, skip or include.
     * @return int Number of match rows updated.
     */
    public static function set_selection( int $run_id, array $scope, string $selection ): int {
        global $wpdb;
        if ( ! in_array( $selection, array( 'auto', 'skip', 'include' ), true ) ) {
            return 0;
        }
        $table = BESR_Schema::matches();
        $where = $wpdb->prepare( "run_id = %d AND status = 'pending'", $run_id );
        if ( ! empty( $scope['ids'] ) ) {
            $where .= ' AND id IN (' . implode( ',', array_map( 'intval', (array) $scope['ids'] ) ) . ')';
        } elseif ( ! empty( $scope['table'] ) ) {
            $where .= $wpdb->prepare( ' AND table_name = %s', $scope['table'] );
        } elseif ( empty( $scope['all'] ) ) {
            return 0;
        }
        return (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET selection = %s WHERE {$where}", $selection ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    public static function set_status( array $ids, string $status, string $note = '' ): void {
        global $wpdb;
        if ( ! $ids ) {
            return;
        }
        $table = BESR_Schema::matches();
        $in    = implode( ',', array_map( 'intval', $ids ) );
        $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = %s, note = %s WHERE id IN ({$in})", $status, substr( $note, 0, 191 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }

    public static function delete_run( int $run_id ): void {
        global $wpdb;
        $wpdb->delete( BESR_Schema::matches(), array( 'run_id' => $run_id ) );
    }

    /**
     * Group of the table for the review page.
     */
    public static function groups_for( array $tables ): array {
        $out = array();
        foreach ( $tables as $t ) {
            $out[ $t ] = BESR_Groups::classify( $t );
        }
        return $out;
    }
}
