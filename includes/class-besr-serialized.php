<?php
/**
 * Byte-exact walker over PHP serialized data.
 *
 * We never call unserialize() on stored data: no objects are instantiated, no
 * __wakeup runs, protected/private properties are handled, floats and references
 * are copied verbatim, and string lengths are recomputed after replacement.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * A byte-exact walker over PHP serialized data that never calls unserialize().
 */
class BESR_Serialized {

    /**
     * @var string
     */
    private string $s;

    /**
     * @var int
     */
    private int $pos = 0;

    /**
     * @var int
     */
    private int $len;
    /**
     * @var callable
     */
    private $cb;

    private function __construct( string $s, callable $cb ) {
        $this->s   = $s;
        $this->len = strlen( $s );
        $this->cb  = $cb;
    }

    public static function looks_serialized( string $s ): bool {
        if ( strlen( $s ) < 4 ) {
            return 'N;' === $s;
        }
        if ( ':' !== $s[1] && ';' !== $s[1] ) {
            return false;
        }
        $last = substr( $s, -1 );
        if ( ';' !== $last && '}' !== $last ) {
            return false;
        }
        return (bool) preg_match( '/^(?:[sSabidOCE]:|N;|[rR]:)/', $s );
    }

    /**
     * Walk serialized data, handing every string to a callback.
     *
     * @param string   $serialized The serialized text.
     * @param callable $on_string  function( string $value, array $ctx ): string, where $ctx
     *                             carries role (value|array_key|prop_name|class_name), class, depth and path.
     * @return string The rewritten serialized text.
     * @throws BESR_Parse_Exception When the data cannot be parsed.
     */
    public static function walk( string $serialized, callable $on_string ): string {
        $w   = new self( $serialized, $on_string );
        $out = $w->value( 0, '', null, 'value' );
        if ( $w->pos !== $w->len ) {
            $w->fail( 'trailing data' );
        }
        return $out;
    }

    // ------------------------------------------------------------------

    /**
     * Abort the walk; the caller leaves the value untouched.
     *
     * @throws BESR_Parse_Exception Always.
     */
    private function fail( string $why ): void {
        $e           = new BESR_Parse_Exception( sprintf( 'Malformed serialized data at byte %d: %s', $this->pos, $why ) );
        $e->position = $this->pos;
        throw $e;
    }

    private function expect( string $c ): void {
        if ( $this->pos >= $this->len || $this->s[ $this->pos ] !== $c ) {
            $this->fail( "expected '{$c}'" );
        }
        ++$this->pos;
    }

    private function read_until( string $c ): string {
        $end = strpos( $this->s, $c, $this->pos );
        if ( false === $end ) {
            $this->fail( "unterminated token, expected '{$c}'" );
        }
        $tok       = substr( $this->s, $this->pos, $end - $this->pos );
        $this->pos = $end + 1;
        return $tok;
    }

    private function read_int(): int {
        $tok = $this->read_until( ':' );
        if ( ! preg_match( '/^-?\d+$/', $tok ) ) {
            $this->fail( 'expected integer' );
        }
        return (int) $tok;
    }

    /**
     * S:LEN:"...": returns the raw bytes and advances past the closing quote.
     */
    private function read_string_body(): string {
        $n = $this->read_int();
        $this->expect( '"' );
        if ( $this->pos + $n > $this->len ) {
            $this->fail( 'string length exceeds data' );
        }
        $str        = substr( $this->s, $this->pos, $n );
        $this->pos += $n;
        $this->expect( '"' );
        return $str;
    }

    private function emit_string( string $str ): string {
        return 's:' . strlen( $str ) . ':"' . $str . '"';
    }

    private function value( int $depth, string $path, ?string $class, string $role ): string {
        if ( $this->pos >= $this->len ) {
            $this->fail( 'unexpected end' );
        }
        $type = $this->s[ $this->pos ];
        ++$this->pos;

        switch ( $type ) {
            case 'N':
                $this->expect( ';' );
                return 'N;';

            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                $this->expect( ':' );
                $tok = $this->read_until( ';' );
                if ( '' === $tok || ! preg_match( '/^[-+0-9.eEINFAN]+$/', $tok ) ) {
                    $this->fail( 'bad scalar' );
                }
                return $type . ':' . $tok . ';';

            case 's':
                $this->expect( ':' );
                $str = $this->read_string_body();
                $this->expect( ';' );
                $new = call_user_func( $this->cb, $str, array( 'role' => $role, 'class' => $class, 'depth' => $depth, 'path' => $path ) );
                return $this->emit_string( is_string( $new ) ? $new : $str ) . ';';

            case 'S':
                // Escaped string (rare, produced by old serializers). Copied verbatim.
                $this->expect( ':' );
                $n = $this->read_int();
                $this->expect( '"' );
                $start = $this->pos;
                $count = 0;
                while ( $count < $n ) {
                    if ( $this->pos >= $this->len ) {
                        $this->fail( 'unterminated escaped string' );
                    }
                    if ( '\\' === $this->s[ $this->pos ] ) {
                        $this->pos += 3;
                    } else {
                        ++$this->pos;
                    }
                    ++$count;
                }
                $body = substr( $this->s, $start, $this->pos - $start );
                $this->expect( '"' );
                $this->expect( ';' );
                return 'S:' . $n . ':"' . $body . '";';

            case 'E':
                $this->expect( ':' );
                $str = $this->read_string_body();
                $this->expect( ';' );
                return 'E:' . strlen( $str ) . ':"' . $str . '";';

            case 'a':
                $this->expect( ':' );
                $n = $this->read_int();
                $this->expect( '{' );
                $out = 'a:' . $n . ':{';
                for ( $i = 0; $i < $n; $i++ ) {
                    $out .= $this->pair( $depth + 1, $path, $class, 'array_key' );
                }
                $this->expect( '}' );
                return $out . '}';

            case 'O':
                $this->expect( ':' );
                $cls     = $this->read_string_body();
                $new_cls = call_user_func( $this->cb, $cls, array( 'role' => 'class_name', 'class' => $cls, 'depth' => $depth, 'path' => $path ) );
                unset( $new_cls ); // class names are never rewritten; the callback only records flags.
                $this->expect( ':' );
                $n = $this->read_int();
                $this->expect( '{' );
                $out = 'O:' . strlen( $cls ) . ':"' . $cls . '":' . $n . ':{';
                for ( $i = 0; $i < $n; $i++ ) {
                    $out .= $this->pair( $depth + 1, $path, $cls, 'prop_name' );
                }
                $this->expect( '}' );
                return $out . '}';

            case 'C':
                $this->expect( ':' );
                $cls = $this->read_string_body();
                $this->expect( ':' );
                $n = $this->read_int();
                $this->expect( '{' );
                if ( $this->pos + $n > $this->len ) {
                    $this->fail( 'custom body exceeds data' );
                }
                $body       = substr( $this->s, $this->pos, $n );
                $this->pos += $n;
                $this->expect( '}' );
                return 'C:' . strlen( $cls ) . ':"' . $cls . '":' . $n . ':{' . $body . '}';
        }

        --$this->pos;
        $this->fail( "unknown type '{$type}'" );
        return '';
    }

    /**
     * Key + value inside an array or object body.
     */
    private function pair( int $depth, string $path, ?string $class, string $key_role ): string {
        if ( $this->pos >= $this->len ) {
            $this->fail( 'unexpected end in pair' );
        }
        $ktype = $this->s[ $this->pos ];
        if ( 'i' === $ktype ) {
            ++$this->pos;
            $this->expect( ':' );
            $tok = $this->read_until( ';' );
            if ( ! preg_match( '/^-?\d+$/', $tok ) ) {
                $this->fail( 'bad integer key' );
            }
            $key_out  = 'i:' . $tok . ';';
            $sub_path = $path . '[' . $tok . ']';
        } elseif ( 's' === $ktype ) {
            ++$this->pos;
            $this->expect( ':' );
            $key = $this->read_string_body();
            $this->expect( ';' );
            $ctx = array( 'role' => $key_role, 'class' => $class, 'depth' => $depth, 'path' => $path );
            $new = call_user_func( $this->cb, $key, $ctx );
            if ( 'prop_name' === $key_role || ! is_string( $new ) ) {
                $new = $key;
            }
            $key_out = $this->emit_string( $new ) . ';';
            $label   = $key;
            if ( 'prop_name' === $key_role && "\0" === substr( $key, 0, 1 ) ) {
                $label = (string) substr( $key, strrpos( $key, "\0" ) + 1 );
            }
            $sub_path = ( 'prop_name' === $key_role ) ? $path . '->' . $label : $path . '[' . $label . ']';
        } else {
            $this->fail( 'bad key type' );
            return '';
        }
        if ( strlen( $sub_path ) > 191 ) {
            $sub_path = substr( $sub_path, 0, 188 ) . '...';
        }
        return $key_out . $this->value( $depth, $sub_path, $class, 'value' );
    }
}

/**
 * Thrown when serialized data cannot be parsed; the value is then never rewritten.
 */
class BESR_Parse_Exception extends RuntimeException {
    /**
     * @var int
     */
    public int $position = 0;
}
