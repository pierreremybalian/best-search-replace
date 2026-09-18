<?php
/**
 * Compiles the search term and its selected variants into one alternation regex,
 * a rendering per branch, and the LIKE needles that pre-filter rows.
 *
 * Frozen per run: everything the scanner recorded stays reproducible at apply time.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compiles the search term and its variants into one alternation pattern, a rendering
 * per branch, and the LIKE needles that pre-filter rows.
 */
final class BESR_Ruleset {

    const VARIANT_KEYS = array( 'exact', 'scheme', 'www', 'json', 'urlencoded', 'scheme_relative', 'bare' );

    /**
     * @var string
     */
    public string $search;

    /**
     * @var string
     */
    public string $replace;

    /**
     * @var bool
     */
    public bool $case_insensitive;

    /**
     * @var array
     */
    public array $term;

    /**
     * @var array
     */
    public array $variants;

    /**
     * Flag => true.
     *
     * @var array
     */
    public array $excluded;

    /**
     * @var string
     */
    public string $regex = '';
    /**
     * Group name => ['key','encoding','literal','rendering','schemeless','first_is_word','last_is_word'].
     *
     * @var array
     */
    public array $branches = array();

    /**
     * @var array
     */
    public array $needles = array();

    /**
     * For the UI: [['key','encoding','search','replace','on']].
     *
     * @var array
     */
    public array $variant_list = array();


    /**
     * @var array
     */
    private static array $cache = array();

    public static function compile( array $config ): BESR_Ruleset {
        $rs                   = new self();
        $rs->search           = (string) ( $config['search'] ?? '' );
        $rs->replace          = (string) ( $config['replace'] ?? '' );
        $rs->case_insensitive = ! empty( $config['case_insensitive'] );
        $rs->variants         = array_values( array_unique( array_merge( array( 'exact' ), array_intersect( (array) ( $config['variants'] ?? array() ), self::VARIANT_KEYS ) ) ) );
        $rs->excluded         = array_fill_keys( (array) ( $config['excluded_contexts'] ?? array() ), true );
        $rs->term             = BESR_Term::parse( $rs->search );
        $rs->build();
        return $rs;
    }

    public static function from_run( BESR_Run $run ): BESR_Ruleset {
        $key = $run->id() . ':' . $run->excluded_contexts_csv();
        if ( ! isset( self::$cache[ $key ] ) ) {
            $config                      = $run->config_all();
            $config['excluded_contexts'] = $run->excluded_contexts();
            self::$cache[ $key ]         = self::compile( $config );
        }
        return self::$cache[ $key ];
    }

    public function is_excluded( string $flag ): bool {
        return isset( $this->excluded[ $flag ] );
    }

    // ------------------------------------------------------------------

    /**
     * Spellings of the term (before encoding), keyed by variant key.
     *
     * @return array key => ['search' => spelling, 'replace' => rendering, 'schemeless' => bool]
     */
    public function spellings(): array {
        $t   = $this->term;
        $out = array( 'exact' => array( 'search' => $this->search, 'replace' => $this->replace, 'schemeless' => ! $t['has_scheme'] ) );

        if ( ! $t['is_hostlike'] ) {
            return $out;
        }

        $hostpath = $t['host'] . $t['port'] . $t['path'];
        $other    = ( 'https' === $t['scheme'] ) ? 'http' : 'https';
        $toggle   = $t['has_www'] ? substr( $hostpath, 4 ) : 'www.' . $hostpath;
        $on       = array_flip( $this->variants );

        if ( $t['has_scheme'] && isset( $on['scheme'] ) ) {
            $out['scheme'] = array( 'search' => $other . '://' . $hostpath, 'replace' => $this->replace, 'schemeless' => false );
        }
        if ( isset( $on['www'] ) ) {
            $prefix     = $t['has_scheme'] ? $t['scheme'] . '://' : '';
            $out['www'] = array( 'search' => $prefix . $toggle, 'replace' => $this->replace, 'schemeless' => ! $t['has_scheme'] );
            if ( $t['has_scheme'] && isset( $on['scheme'] ) ) {
                $out['scheme_www'] = array( 'search' => $other . '://' . $toggle, 'replace' => $this->replace, 'schemeless' => false );
            }
        }
        if ( $t['has_scheme'] && isset( $on['scheme_relative'] ) ) {
            $out['scheme_relative'] = array( 'search' => '//' . $hostpath, 'replace' => '//' . BESR_Term::schemeless( $this->replace ), 'schemeless' => false, 'relative' => true );
            if ( isset( $on['www'] ) ) {
                $out['scheme_relative_www'] = array( 'search' => '//' . $toggle, 'replace' => '//' . BESR_Term::schemeless( $this->replace ), 'schemeless' => false, 'relative' => true );
            }
        }
        if ( $t['has_scheme'] && isset( $on['bare'] ) ) {
            $out['bare'] = array( 'search' => $hostpath, 'replace' => BESR_Term::schemeless( $this->replace ), 'schemeless' => true );
            if ( isset( $on['www'] ) ) {
                $out['bare_www'] = array( 'search' => $toggle, 'replace' => BESR_Term::schemeless( $this->replace ), 'schemeless' => true );
            }
        }
        return $out;
    }

    private function build(): void {
        $on        = array_flip( $this->variants );
        $encodings = array( 'plain' );
        if ( isset( $on['json'] ) && false !== strpos( $this->search, '/' ) ) {
            $encodings[] = 'json';
        }
        if ( isset( $on['urlencoded'] ) && rawurlencode( $this->search ) !== $this->search ) {
            $encodings[] = 'urlencoded';
        }

        $branches = array();
        foreach ( $this->spellings() as $key => $sp ) {
            foreach ( $encodings as $enc ) {
                $literal   = self::encode( $sp['search'], $enc );
                $rendering = self::encode( $sp['replace'], $enc );
                if ( '' === $literal ) {
                    continue;
                }
                if ( 'json' === $enc && false === strpos( $sp['search'], '/' ) ) {
                    continue; // identical to plain.
                }
                if ( 'urlencoded' === $enc && $literal === $sp['search'] ) {
                    continue;
                }
                $last_enc   = 'urlencoded' === $enc && preg_match( '/%[0-9A-Fa-f]{2}$/', $literal );
                $first_enc  = 'urlencoded' === $enc && 0 === strpos( $literal, '%' );
                $branches[] = array(
                    'key'           => $key,
                    'encoding'      => $enc,
                    'literal'       => $literal,
                    'rendering'     => $rendering,
                    'schemeless'    => ! empty( $sp['schemeless'] ),
                    'relative'      => ! empty( $sp['relative'] ),
                    'first_is_word' => ! $first_enc && BESR_Term::is_word_byte( substr( $literal, 0, 1 ) ),
                    'last_is_word'  => ! $last_enc && BESR_Term::is_word_byte( substr( $literal, -1 ) ),
                );
            }
        }

        // Longest literal first so the most specific spelling wins at a shared offset.
        usort( $branches, function ( $a, $b ) {
            return strlen( $b['literal'] ) <=> strlen( $a['literal'] );
        } );

        $parts = array();
        $seen  = array();
        foreach ( $branches as $i => $b ) {
            $norm = $this->case_insensitive ? strtolower( $b['literal'] ) : $b['literal'];
            if ( isset( $seen[ $norm ] ) ) {
                continue;
            }
            $seen[ $norm ] = true;
            $name          = 'g' . $i;
            $pattern       = self::literal_pattern( $b['literal'], $b['encoding'] );
            if ( $b['relative'] ) {
                $pattern = '(?<![:\w])' . $pattern;
            }
            $parts[]                 = '(?<' . $name . '>' . $pattern . ')';
            $this->branches[ $name ] = $b;
        }
        $this->regex = '#' . implode( '|', $parts ) . '#' . ( $this->case_insensitive ? 'i' : '' );

        $this->needles      = $this->compute_needles();
        $this->variant_list = $this->describe_variants();
    }

    /**
     * Preg_quote a literal; for URL-encoded literals make the hex digits case-insensitive.
     */
    private static function literal_pattern( string $literal, string $encoding ): string {
        if ( 'urlencoded' !== $encoding ) {
            return preg_quote( $literal, '#' );
        }
        $out = '';
        foreach ( preg_split( '/(%[0-9A-Fa-f]{2})/', $literal, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $piece ) {
            $out .= preg_match( '/^%[0-9A-Fa-f]{2}$/', $piece ) ? '(?i:' . preg_quote( $piece, '#' ) . ')' : preg_quote( $piece, '#' );
        }
        return $out;
    }

    public static function encode( string $s, string $encoding ): string {
        switch ( $encoding ) {
            case 'json':
                return str_replace( '/', '\/', $s );
            case 'urlencoded':
                return rawurlencode( $s );
        }
        return $s;
    }

    /**
     * LIKE needles: the shortest substring every branch literal contains, per encoding.
     * For host-like terms that is the bare host (+port +path); every spelling contains it.
     *
     * @return array [ ['s' => needle, 'ci' => bool] ]
     */
    private function compute_needles(): array {
        $t    = $this->term;
        $base = $t['is_hostlike'] ? $t['bare_host'] . $t['port'] . $t['path'] : $this->search;
        $encs = array();
        foreach ( $this->branches as $b ) {
            $encs[ $b['encoding'] ] = true;
        }
        $out  = array();
        $seen = array();
        foreach ( array_keys( $encs ) as $enc ) {
            $n  = self::encode( $base, $enc );
            $ci = $this->case_insensitive || 'urlencoded' === $enc;
            $k  = ( $ci ? strtolower( $n ) : $n );
            if ( '' === $n || isset( $seen[ $k ] ) ) {
                continue;
            }
            $seen[ $k ] = true;
            $out[]      = array( 's' => $n, 'ci' => $ci );
        }
        return $out;
    }

    /**
     * Plain needle strings, for SQL LIKE clauses.
     */
    public function needle_strings(): array {
        return array_map( function ( $n ) {
            return $n['s'];
        }, $this->needles );
    }

    /**
     * Cheap test used before any regex work: could this value contain a match at all?
     */
    public function might_match( string $value ): bool {
        foreach ( $this->needles as $n ) {
            if ( $n['ci'] ? false !== stripos( $value, $n['s'] ) : false !== strpos( $value, $n['s'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Which branch matched, from a preg match array with PREG_OFFSET_CAPTURE.
     */
    public function matched_branch( array $m ): string {
        foreach ( $this->branches as $name => $b ) {
            if ( isset( $m[ $name ] ) && is_array( $m[ $name ] ) && $m[ $name ][1] >= 0 && '' !== $m[ $name ][0] ) {
                return $name;
            }
        }
        return array_key_first( $this->branches ) ?: '';
    }

    /**
     * UI-facing list of what will be matched and what it becomes.
     */
    private function describe_variants(): array {
        $list = array();
        foreach ( $this->branches as $b ) {
            $list[] = array(
                'key'      => $b['key'],
                'encoding' => $b['encoding'],
                'search'   => $b['literal'],
                'replace'  => $b['rendering'],
            );
        }
        return $list;
    }

    /**
     * The variants that apply to a term, for the analysis step in the UI.
     *
     * @return array [ ['key','label','search','replace','available','on','note'] ]
     */
    public static function available_variants( string $search, string $replace, array $on ): array {
        $t      = BESR_Term::parse( $search );
        $other  = ( 'https' === $t['scheme'] ) ? 'http' : 'https';
        $hp     = $t['host'] . $t['port'] . $t['path'];
        $toggle = $t['has_www'] ? substr( $hp, 4 ) : 'www.' . $hp;
        $rows   = array();
        $rows[] = array( 'key' => 'exact', 'label' => __( 'Exactly as typed', 'best-search-replace' ), 'search' => $search, 'replace' => $replace, 'available' => true, 'on' => true, 'note' => '' );
        if ( $t['is_hostlike'] && $t['has_scheme'] ) {
            /* translators: %s: the other URL scheme, for example "http://". */
            $rows[] = array( 'key' => 'scheme', 'label' => sprintf( __( '%s as well', 'best-search-replace' ), $other . '://' ), 'search' => $other . '://' . $hp, 'replace' => $replace, 'available' => true, 'on' => in_array( 'scheme', $on, true ), 'note' => __( 'http and https', 'best-search-replace' ) );
        }
        if ( $t['is_hostlike'] ) {
            $prefix = $t['has_scheme'] ? $t['scheme'] . '://' : '';
            $rows[] = array( 'key' => 'www', 'label' => $t['has_www'] ? __( 'Without www.', 'best-search-replace' ) : __( 'With www.', 'best-search-replace' ), 'search' => $prefix . $toggle, 'replace' => $replace, 'available' => true, 'on' => in_array( 'www', $on, true ), 'note' => __( 'with and without www', 'best-search-replace' ) );
        }
        if ( false !== strpos( $search, '/' ) ) {
            $rows[] = array( 'key' => 'json', 'label' => __( 'JSON-escaped', 'best-search-replace' ), 'search' => self::encode( $search, 'json' ), 'replace' => self::encode( $replace, 'json' ), 'available' => true, 'on' => in_array( 'json', $on, true ), 'note' => __( 'block editor, Elementor, JSON settings', 'best-search-replace' ) );
        }
        if ( rawurlencode( $search ) !== $search ) {
            $rows[] = array( 'key' => 'urlencoded', 'label' => __( 'URL-encoded', 'best-search-replace' ), 'search' => rawurlencode( $search ), 'replace' => rawurlencode( $replace ), 'available' => true, 'on' => in_array( 'urlencoded', $on, true ), 'note' => __( 'redirect parameters, embed caches', 'best-search-replace' ) );
        }
        if ( $t['is_hostlike'] && $t['has_scheme'] ) {
            $rows[] = array( 'key' => 'scheme_relative', 'label' => __( 'Scheme-relative', 'best-search-replace' ), 'search' => '//' . $hp, 'replace' => '//' . BESR_Term::schemeless( $replace ), 'available' => true, 'on' => in_array( 'scheme_relative', $on, true ), 'note' => __( 'links starting with //', 'best-search-replace' ) );
            $rows[] = array( 'key' => 'bare', 'label' => __( 'Bare domain', 'best-search-replace' ), 'search' => $hp, 'replace' => BESR_Term::schemeless( $replace ), 'available' => true, 'on' => in_array( 'bare', $on, true ), 'note' => __( 'also matches e-mail addresses and sub-domains; you can skip those on the review screen', 'best-search-replace' ) );
        }
        return $rows;
    }
}
