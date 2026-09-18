<?php
/**
 * Replaces inside one cell: plain text, serialized PHP (any depth), JSON-in-text.
 * Every occurrence is classified so it can be excluded by category, and the result
 * is validated before anything is ever written.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Replaces inside a single cell, descending into serialized data and classifying
 * every occurrence so it can be included or skipped.
 */
class BESR_Replacer {

    const MAX_DEPTH = 8;
    const CONTEXT   = 60;
    const SNIPPET   = 500;

    /**
     * @var BESR_Ruleset
     */
    private BESR_Ruleset $rules;

    /**
     * @var bool
     */
    private bool $detail = false;

    /**
     * @var BESR_Cell_Result|null
     */
    private ?BESR_Cell_Result $r = null;

    /**
     * @var bool
     */
    private bool $snippet_from_excluded = false;
    /**
     * Per-cell override: 'include' replaces every occurrence, 'skip' none.
     *
     * @var string
     */
    private string $selection = 'auto';

    public function __construct( BESR_Ruleset $rules ) {
        $this->rules = $rules;
    }

    /**
     * @param string $value     Raw cell value.
     * @param array  $meta      table, column, max_chars, max_bytes.
     * @param bool   $detail    Collect every occurrence (review drawer).
     * @param string $selection auto|include|skip.
     */
    public function process_cell( string $value, array $meta = array(), bool $detail = false, string $selection = 'auto' ): BESR_Cell_Result {
        $r                           = new BESR_Cell_Result();
        $this->r                     = $r;
        $this->detail                = $detail;
        $this->selection             = $selection;
        $this->snippet_from_excluded = false;

        if ( '' === $value || ! $this->rules->might_match( $value ) ) {
            return $r;
        }

        $meta = array_merge( array( 'table' => '', 'column' => '', 'max_chars' => null, 'max_bytes' => null, 'role' => 'value', 'path' => '' ), $meta );

        if ( BESR_Serialized::looks_serialized( $value ) ) {
            $new = $this->process_serialized( $value, $meta, 0 );
            if ( null === $new ) {
                return $r; // error already recorded.
            }
        } else {
            $new = $this->process_text( $value, $meta );
        }

        if ( null !== $r->error ) {
            return $r;
        }

        if ( $new !== $value ) {
            if ( null !== $meta['max_bytes'] && strlen( $new ) > (int) $meta['max_bytes'] ) {
                $r->error = 'would_truncate';
                return $r;
            }
            if ( null !== $meta['max_chars'] && $this->char_length( $new ) > (int) $meta['max_chars'] ) {
                $r->error = 'would_truncate';
                return $r;
            }
            $r->changed = true;
            $r->value   = $new;
        }
        return $r;
    }

    // ------------------------------------------------------------------

    /**
     * Replace inside a plain string, classifying every occurrence as it goes.
     */
    private function process_text( string $text, array $meta ): string {
        $r      = $this->r;
        $rules  = $this->rules;
        $result = preg_replace_callback(
            $rules->regex,
            function ( array $m ) use ( $text, $meta ) {
                return $this->on_hit( $m, $text, $meta );
            },
            $text,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );
        if ( null === $result ) {
            $r->error = 'regex_error: ' . ( function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : (string) preg_last_error() );
            return $text;
        }
        return $result;
    }

    private function on_hit( array $m, string $subject, array $meta ): string {
        $r      = $this->r;
        $rules  = $this->rules;
        $hit    = $m[0][0];
        $offset = (int) $m[0][1];
        $name   = $rules->matched_branch( $m );
        $branch = $rules->branches[ $name ];
        $flags  = BESR_Context::classify( $subject, $offset, strlen( $hit ), $branch, $rules, $meta );

        ++$r->occurrences;
        $r->variants[ $branch['key'] ] = ( $r->variants[ $branch['key'] ] ?? 0 ) + 1;
        foreach ( $flags as $f ) {
            $r->flags[ $f ] = ( $r->flags[ $f ] ?? 0 ) + 1;
        }
        sort( $flags );
        $set                 = implode( '|', $flags );
        $r->flagsets[ $set ] = ( $r->flagsets[ $set ] ?? 0 ) + 1;

        $hard     = (bool) array_intersect( $flags, BESR_Context::HARD );
        $excluded = $hard;
        if ( ! $hard ) {
            if ( 'skip' === $this->selection ) {
                $excluded = true;
            } elseif ( 'include' !== $this->selection ) {
                foreach ( $flags as $f ) {
                    if ( $rules->is_excluded( $f ) ) {
                        $excluded = true;
                        break;
                    }
                }
            }
        }
        if ( ! $excluded ) {
            ++$r->included;
        }

        $rendering = $branch['rendering'];
        if ( $this->detail ) {
            list( $b, $a )        = $this->snippet( $subject, $offset, strlen( $hit ), $rendering );
            $r->occurrence_list[] = array(
                'offset'   => $offset,
                'length'   => strlen( $hit ),
                'variant'  => $branch['key'],
                'encoding' => $branch['encoding'],
                'flags'    => $flags,
                'included' => ! $excluded,
                'path'     => $meta['path'],
                'before'   => $b,
                'after'    => $a,
            );
        }
        if ( '' === $r->snippet_before || ( $this->snippet_from_excluded && ! $excluded ) ) {
            list( $r->snippet_before, $r->snippet_after ) = $this->snippet( $subject, $offset, strlen( $hit ), $rendering );
            $r->path                                      = (string) $meta['path'];
            $this->snippet_from_excluded                  = $excluded;
        }

        return $excluded ? $hit : $rendering;
    }

    /**
     * @return string|null null when an error was recorded
     */
    private function process_serialized( string $value, array $meta, int $depth ): ?string {
        $r = $this->r;
        if ( $depth > 0 ) {
            $r->encoding = 'nested';
        } elseif ( 'plain' === $r->encoding ) {
            $r->encoding = 'serialized';
        }
        try {
            $out = BESR_Serialized::walk( $value, function ( string $str, array $ctx ) use ( $meta, $depth ) {
                $m         = $meta;
                $m['role'] = $ctx['role'];
                $m['path'] = $ctx['path'];
                if ( 'value' === $ctx['role'] && $depth < self::MAX_DEPTH && BESR_Serialized::looks_serialized( $str ) && $this->rules->might_match( $str ) ) {
                    $inner = $this->process_serialized( $str, $m, $depth + 1 );
                    return null === $inner ? $str : $inner;
                }
                if ( ! $this->rules->might_match( $str ) ) {
                    return $str;
                }
                return $this->process_text( $str, $m );
            } );
        } catch ( BESR_Parse_Exception $e ) {
            // Not actually serialized (is_serialized false positive) -> plain text. Otherwise never touch it.
            if ( false === @unserialize( $value, array( 'allowed_classes' => false ) ) && 'b:0;' !== $value ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Validation probe: a failure here is the answer we want, and the value is never written.
                $r->encoding = 'plain';
                return $this->process_text( $value, $meta );
            }
            $r->error = 'malformed_serialized: ' . $e->getMessage();
            return null;
        }

        if ( $out !== $value ) {
            if ( false === @unserialize( $out, array( 'allowed_classes' => false ) ) && 'b:0;' !== $out ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Validation probe: a failure here is the answer we want, and the value is never written.
                $r->error = 'invalid_output';
                return null;
            }
        }
        return $out;
    }

    /**
     * @return string[] [before, after] with the hit wrapped in \x01 ... \x02
     */
    private function snippet( string $subject, int $offset, int $length, string $rendering ): array {
        $start  = max( 0, $offset - self::CONTEXT );
        $pre    = substr( $subject, $start, $offset - $start );
        $post   = substr( $subject, $offset + $length, self::CONTEXT );
        $hit    = substr( $subject, $offset, $length );
        $pre    = ( $start > 0 ? '…' : '' ) . self::clean( $pre );
        $post   = self::clean( (string) $post ) . ( $offset + $length + self::CONTEXT < strlen( $subject ) ? '…' : '' );
        $before = $pre . "\x01" . self::clean( $hit ) . "\x02" . $post;
        $after  = $pre . "\x01" . self::clean( $rendering ) . "\x02" . $post;
        return array( self::cap( $before ), self::cap( $after ) );
    }

    private static function clean( string $s ): string {
        $s = preg_replace( '/\s+/', ' ', $s );
        return self::utf8( (string) $s );
    }

    private static function cap( string $s ): string {
        if ( strlen( $s ) <= self::SNIPPET ) {
            return $s;
        }
        // Keep the marked region: trim the outer edges first.
        $a = strpos( $s, "\x01" );
        $b = strpos( $s, "\x02" );
        if ( false !== $a && false !== $b && $b - $a < self::SNIPPET - 20 ) {
            $room  = self::SNIPPET - ( $b - $a + 1 );
            $left  = (int) floor( $room / 2 );
            $start = max( 0, $a - $left );
            return self::utf8( substr( $s, $start, self::SNIPPET ) );
        }
        return self::utf8( substr( $s, 0, self::SNIPPET ) );
    }

    /**
     * Make a byte string safe for JSON output (invalid sequences are dropped).
     */
    public static function utf8( string $s ): string {
        if ( function_exists( 'mb_convert_encoding' ) ) {
            $fixed = @mb_convert_encoding( $s, 'UTF-8', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid byte sequences are expected here; the fallback below handles them.
            return is_string( $fixed ) ? $fixed : '';
        }
        if ( function_exists( 'iconv' ) ) {
            $fixed = @iconv( 'UTF-8', 'UTF-8//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid byte sequences are expected here; the fallback below handles them.
            return is_string( $fixed ) ? $fixed : '';
        }
        return (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '', $s );
    }

    private function char_length( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
    }
}

/**
 * What the replacer found and produced for one cell.
 */
final class BESR_Cell_Result {
    /**
     * @var bool
     */
    public bool $changed = false;

    /**
     * @var string|null
     */
    public ?string $value = null;

    /**
     * @var int
     */
    public int $occurrences = 0;

    /**
     * @var int
     */
    public int $included = 0;
    /**
     * Flagset string ("" or "a|b") => count
     *
     * @var array
     */
    public array $flagsets = array();
    /**
     * Flag => count
     *
     * @var array
     */
    public array $flags = array();
    /**
     * Variant key => count
     *
     * @var array
     */
    public array $variants = array();

    /**
     * @var string
     */
    public string $encoding = 'plain';

    /**
     * @var string
     */
    public string $path = '';

    /**
     * @var string
     */
    public string $snippet_before = '';

    /**
     * @var string
     */
    public string $snippet_after = '';

    /**
     * @var string|null
     */
    public ?string $error = null;

    /**
     * @var array
     */
    public array $occurrence_list = array();

    public function flagsets_json(): string {
        return $this->flagsets ? (string) wp_json_encode( $this->flagsets ) : '';
    }
}
