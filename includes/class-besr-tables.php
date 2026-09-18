<?php
/**
 * Table discovery and description: which tables belong to this site, what their
 * primary key looks like, which columns can hold text, how big they are.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Table discovery and description, plus the SQL fragments used to walk a table
 * by primary key.
 */
class BESR_Tables {

    /**
     * Types we scan.
     */
    const TEXT_TYPES = '/^(?:(?:var)?char|(?:tiny|medium|long)?text|json)\b/i';
    /**
     * Types we report but never scan.
     */
    const BINARY_TYPES = '/blob|binary|^enum|^set|bit/i';

    public static function name( string $table ): string {
        return preg_replace( '/[^A-Za-z0-9_$]/', '', str_replace( '`', '', $table ) );
    }

    /**
     * Tables that belong to the current site (single site: all tables with the prefix).
     *
     * @return string[] table names
     */
    public static function discover(): array {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $names  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
        $own    = array_map( 'strtolower', BESR_Schema::own_tables() );
        $out    = array();

        $exclude = array();
        if ( is_multisite() ) {
            $exclude = array_merge( array_values( $wpdb->tables( 'global', true ) ), array_values( $wpdb->tables( 'ms_global', true ) ) );
            $exclude = array_map( 'strtolower', $exclude );
        }
        $is_main = is_multisite() && $prefix === $wpdb->base_prefix;

        foreach ( $names as $name ) {
            $lower = strtolower( $name );
            if ( in_array( $lower, $own, true ) || in_array( $lower, $exclude, true ) ) {
                continue;
            }
            if ( $is_main && preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $name ) ) {
                continue; // Another site's table.
            }
            $out[] = $name;
        }
        sort( $out, SORT_STRING | SORT_FLAG_CASE );

        /**
         * Filter the tables offered for search/replace on this site.
         *
         * @param string[] $out
         */
        return (array) apply_filters( 'besr_tables', $out );
    }

    /**
     * Network-wide tables (users, usermeta, blogs, sitemeta...). Multisite only.
     */
    public static function discover_global(): array {
        global $wpdb;
        if ( ! is_multisite() ) {
            return array();
        }
        $names = array_merge( array_values( $wpdb->tables( 'global', true ) ), array_values( $wpdb->tables( 'ms_global', true ) ) );
        $out   = array();
        foreach ( array_unique( $names ) as $t ) {
            if ( BESR_Schema::exists( $t ) ) {
                $out[] = $t;
            }
        }
        sort( $out, SORT_STRING | SORT_FLAG_CASE );
        return $out;
    }

    /**
     * Cheap facts for the picker (SHOW TABLE STATUS for all tables at once).
     */
    public static function status_all(): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A );
        $out  = array();
        foreach ( $rows as $r ) {
            $out[ $r['Name'] ] = array(
                'rows'           => (int) $r['Rows'],
                'size_bytes'     => (int) $r['Data_length'] + (int) $r['Index_length'],
                'engine'         => (string) $r['Engine'],
                'avg_row_length' => (int) $r['Avg_row_length'],
                'collation'      => (string) ( $r['Collation'] ?? '' ),
            );
        }
        return $out;
    }

    /**
     * Full descriptor used by the scanner and applier.
     */
    public static function describe( string $table, array $config = array() ): array {
        global $wpdb;
        $table  = self::name( $table );
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
        $desc   = array(
            'name'             => $table,
            'group'            => BESR_Groups::classify( $table )['group'],
            'engine'           => (string) ( $status['Engine'] ?? '' ),
            'approx_rows'      => (int) ( $status['Rows'] ?? 0 ),
            'avg_row_length'   => (int) ( $status['Avg_row_length'] ?? 0 ),
            'size_bytes'       => (int) ( $status['Data_length'] ?? 0 ) + (int) ( $status['Index_length'] ?? 0 ),
            'pk_kind'          => 'none',
            'pk_columns'       => array(),
            'pk_types'         => array(),
            'columns'          => array(),
            'excluded_columns' => array(),
            'blob_columns'     => array(),
            'pk_min'           => null,
            'pk_max'           => null,
            'status'           => 'pending',
            'skip_reason'      => '',
            'scanned_rows'     => 0,
            'cells'            => 0,
            'occurrences'      => 0,
            'included'         => 0,
            'like_hits'        => null,
            'keyset'           => true,
        );

        $cols  = (array) $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        $types = array();
        foreach ( $cols as $c ) {
            $type                 = strtolower( (string) $c['Type'] );
            $types[ $c['Field'] ] = $type;
            if ( preg_match( self::BINARY_TYPES, $type ) ) {
                $desc['blob_columns'][] = $c['Field'];
                continue;
            }
            if ( ! preg_match( self::TEXT_TYPES, $type ) ) {
                continue;
            }
            $desc['columns'][] = array(
                'name'      => $c['Field'],
                'type'      => $type,
                'max_chars' => self::max_chars( $type ),
                'max_bytes' => self::max_bytes( $type ),
                'collation' => (string) ( $c['Collation'] ?? '' ),
            );
        }

        $pk                 = self::primary_key( $table, $types );
        $desc['pk_kind']    = $pk['kind'];
        $desc['pk_columns'] = $pk['columns'];
        foreach ( $pk['columns'] as $col ) {
            $desc['pk_types'][ $col ] = preg_match( '/int\b|^int\(/', $types[ $col ] ?? '' ) ? 'int' : 'string';
        }
        if ( 'int' === $pk['kind'] ) {
            $col            = self::name( $pk['columns'][0] );
            $mm             = $wpdb->get_row( "SELECT MIN(`{$col}`) AS mn, MAX(`{$col}`) AS mx FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            $desc['pk_min'] = isset( $mm['mn'] ) ? (int) $mm['mn'] : null;
            $desc['pk_max'] = isset( $mm['mx'] ) ? (int) $mm['mx'] : null;
        }

        // Column-level rules (guid, identity columns...) are applied by BESR_Rules.
        $excl = BESR_Rules::column_exclusions( $desc, $config );
        if ( $excl ) {
            $desc['excluded_columns'] = $excl;
            $desc['columns']          = array_values( array_filter( $desc['columns'], function ( $c ) use ( $excl ) {
                return ! isset( $excl[ $c['name'] ] );
            } ) );
        }

        if ( 'none' === $pk['kind'] && empty( $config['include_pkless'] ) ) {
            $desc['status']      = 'skipped';
            $desc['skip_reason'] = 'no_primary_key';
        } elseif ( empty( $desc['columns'] ) ) {
            $desc['status']      = 'skipped';
            $desc['skip_reason'] = empty( $desc['blob_columns'] ) ? 'no_text_columns' : 'binary_only';
        }

        /**
         * Filter a table descriptor before scanning (e.g. to drop columns).
         *
         * @param array $desc
         */
        return (array) apply_filters( 'besr_table_descriptor', $desc );
    }

    /**
     * @return array ['kind' => int|composite|unique|none, 'columns' => string[]]
     */
    public static function primary_key( string $table, array $types ): array {
        global $wpdb;
        $keys = (array) $wpdb->get_results( "SHOW KEYS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        $by   = array();
        foreach ( $keys as $k ) {
            $by[ $k['Key_name'] ][ (int) $k['Seq_in_index'] ] = $k;
        }
        $candidates = array();
        if ( isset( $by['PRIMARY'] ) ) {
            $candidates[] = $by['PRIMARY'];
        }
        foreach ( $by as $name => $parts ) {
            if ( 'PRIMARY' !== $name && '0' === (string) $parts[ array_key_first( $parts ) ]['Non_unique'] ) {
                $candidates[] = $parts;
            }
        }
        foreach ( $candidates as $i => $parts ) {
            ksort( $parts );
            $cols     = array_column( $parts, 'Column_name' );
            $nullable = false;
            foreach ( $parts as $p ) {
                if ( 'YES' === ( $p['Null'] ?? '' ) ) {
                    $nullable = true;
                }
            }
            if ( $nullable ) {
                continue;
            }
            if ( 1 === count( $cols ) && preg_match( '/^(?:tiny|small|medium|big)?int/', $types[ $cols[0] ] ?? '' ) ) {
                return array( 'kind' => 'int', 'columns' => $cols );
            }
            return array( 'kind' => 0 === $i && isset( $by['PRIMARY'] ) ? 'composite' : 'unique', 'columns' => $cols );
        }
        return array( 'kind' => 'none', 'columns' => array() );
    }

    public static function max_chars( string $type ): ?int {
        if ( preg_match( '/^(?:var)?char\((\d+)\)/', $type, $m ) ) {
            return (int) $m[1];
        }
        return null;
    }

    public static function max_bytes( string $type ): ?int {
        if ( 0 === strpos( $type, 'tinytext' ) ) {
            return 255;
        }
        if ( 0 === strpos( $type, 'mediumtext' ) ) {
            return 16777215;
        }
        if ( 0 === strpos( $type, 'longtext' ) || 0 === strpos( $type, 'json' ) ) {
            return 4294967295;
        }
        if ( 0 === strpos( $type, 'text' ) ) {
            return 65535;
        }
        return null;
    }

    // ---- SQL helpers ----

    /**
     * `col1`, `col2`
     */
    public static function cols( array $names ): string {
        return '`' . implode( '`, `', array_map( array( __CLASS__, 'name' ), $names ) ) . '`';
    }

    /**
     * (`a` LIKE %s OR `b` LIKE %s ...) prepared.
     */
    public static function like_clause( array $columns, array $needles, bool $case_insensitive ): string {
        global $wpdb;
        $parts = array();
        foreach ( $columns as $col ) {
            $c   = '`' . self::name( is_array( $col ) ? $col['name'] : $col ) . '`';
            $bin = is_array( $col ) && preg_match( '/_bin$/', (string) ( $col['collation'] ?? '' ) );
            foreach ( $needles as $n ) {
                if ( $bin && $case_insensitive ) {
                    $parts[] = $wpdb->prepare( "LOWER({$c}) LIKE %s", '%' . $wpdb->esc_like( strtolower( $n ) ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                } else {
                    $parts[] = $wpdb->prepare( "{$c} LIKE %s", '%' . $wpdb->esc_like( $n ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
                }
            }
        }
        return $parts ? '(' . implode( ' OR ', $parts ) . ')' : '1=1';
    }

    /**
     * WHERE clause identifying one row by its PK values (or whole row for pk-less).
     */
    public static function pk_where( array $desc, array $pk ): string {
        global $wpdb;
        $parts = array();
        if ( isset( $pk['__row'] ) ) {
            foreach ( (array) $pk['__row'] as $col => $val ) {
                $parts[] = null === $val
                    ? '`' . self::name( $col ) . '` IS NULL'
                    : $wpdb->prepare( '`' . self::name( $col ) . '` <=> %s', $val ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
            }
            return implode( ' AND ', $parts );
        }
        foreach ( $desc['pk_columns'] as $col ) {
            $fmt     = ( 'int' === ( $desc['pk_types'][ $col ] ?? 'string' ) ) ? '%d' : '%s';
            $parts[] = $wpdb->prepare( '`' . self::name( $col ) . "` = {$fmt}", $pk[ $col ] ?? '' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
        }
        return $parts ? implode( ' AND ', $parts ) : '0=1';
    }

    /**
     * Keyset predicate for composite keys: (a,b) > (%s,%d).
     */
    public static function keyset_after( array $desc, array $last ): string {
        global $wpdb;
        $cols = array();
        $vals = array();
        $fmts = array();
        foreach ( $desc['pk_columns'] as $col ) {
            $cols[] = '`' . self::name( $col ) . '`';
            $fmts[] = ( 'int' === ( $desc['pk_types'][ $col ] ?? 'string' ) ) ? '%d' : '%s';
            $vals[] = $last[ $col ] ?? '';
        }
        return $wpdb->prepare( '(' . implode( ', ', $cols ) . ') > (' . implode( ', ', $fmts ) . ')', $vals ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Identifiers are plugin table names or come from SHOW TABLES/SHOW COLUMNS via BESR_Tables::name(); all values use placeholders.
    }
}
