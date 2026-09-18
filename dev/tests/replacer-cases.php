<?php
/**
 * Replacer unit cases. Run: wp eval-file /tests/replacer-cases.php
 */

class BESR_Test_Fx {
    protected $url;
    private $secret;
    public $arr;
    public function __construct( $u ) {
        $this->url    = $u;
        $this->secret = 's@example.com';
        $this->arr    = array( 'k' => $u, 'f' => 0.1, 'n' => serialize( array( 'deep' => $u . '/nested' ) ) );
    }
}

$fail = 0;
$t = function ( $name, $cfg, $in, $expOut = null, $exp = array() ) use ( &$fail ) {
    $rs  = BESR_Ruleset::compile( array_merge( array( 'search' => 'https://example.com', 'replace' => 'https://newsite.test', 'variants' => array( 'exact', 'scheme', 'www', 'json', 'urlencoded', 'scheme_relative' ), 'excluded_contexts' => array( 'inside_email', 'longer_domain', 'inside_word', 'in_hash_like_string', 'in_serialized_key' ) ), $cfg ) );
    $rp  = new BESR_Replacer( $rs );
    $r   = $rp->process_cell( $in, $exp['meta'] ?? array() );
    $out = $r->changed ? $r->value : $in;
    $ok  = true;
    $why = array();
    if ( null !== $expOut && $out !== $expOut ) {
        $ok = false; $why[] = 'out=' . var_export( $out, true );
    }
    foreach ( array( 'occurrences', 'included', 'error', 'encoding' ) as $k ) {
        if ( array_key_exists( $k, $exp ) && $r->$k !== $exp[ $k ] ) {
            $ok = false; $why[] = "$k=" . var_export( $r->$k, true );
        }
    }
    if ( isset( $exp['flags'] ) ) {
        foreach ( $exp['flags'] as $f => $c ) {
            if ( ( $r->flags[ $f ] ?? 0 ) !== $c ) {
                $ok = false; $why[] = "flag $f=" . ( $r->flags[ $f ] ?? 0 );
            }
        }
    }
    if ( isset( $exp['assert'] ) && ! $exp['assert']( $out, $r ) ) {
        $ok = false; $why[] = 'assert';
    }
    WP_CLI::log( ( $ok ? 'PASS' : 'FAIL' ) . " $name" . ( $ok ? '' : '  ' . implode( ' ', $why ) ) );
    if ( ! $ok ) { $fail++; }
};

$B = array( 'search' => 'example.com', 'replace' => 'newsite.test' );
$t( 'email excluded', $B, 'Mail admin@example.com or visit example.com', 'Mail admin@example.com or visit newsite.test', array( 'occurrences' => 2, 'included' => 1, 'flags' => array( 'inside_email' => 1 ) ) );
$t( 'email included when un-excluded', $B + array( 'excluded_contexts' => array() ), 'admin@example.com', 'admin@newsite.test', array( 'included' => 1 ) );
$t( 'longer domain', $B, 'shop.example.com example.com.au', null, array( 'occurrences' => 2, 'included' => 0, 'flags' => array( 'longer_domain' => 2 ) ) );
$t( 'scheme collapses', array(), 'see https://example.com/x and http://example.com/y', 'see https://newsite.test/x and https://newsite.test/y', array( 'occurrences' => 2, 'included' => 2 ) );
$t( 'www', array(), 'https://www.example.com/', 'https://newsite.test/', array( 'included' => 1 ) );
$t( 'json escaped', array(), '{"u":"https:\/\/example.com\/x"}', '{"u":"https:\/\/newsite.test\/x"}', array( 'included' => 1 ) );
$t( 'urlencoded hex case', array(), 'r=https%3a%2f%2fexample.com%2Fx', 'r=https%3A%2F%2Fnewsite.test%2Fx', array( 'included' => 1 ) );
$t( 'scheme-relative not inside absolute', array(), 'https://example.com //example.com', 'https://newsite.test //newsite.test', array( 'occurrences' => 2 ) );
$t( 'bare off by default: email untouched with URL term', array(), 'admin@example.com https://example.com', 'admin@example.com https://newsite.test', array( 'occurrences' => 1 ) );
$ser = serialize( new BESR_Test_Fx( 'https://example.com' ) );
$t( 'serialized protected/private props + nested', array(), $ser, null, array( 'encoding' => 'nested', 'assert' => function ( $out ) {
    return false !== strpos( $out, "\0*\0url" ) && false !== strpos( $out, 'https://newsite.test' ) && false !== strpos( $out, 'newsite.test/nested' ) && false !== strpos( $out, 'd:0.1;' ) && false !== @unserialize( $out, array( 'allowed_classes' => false ) );
} ) );
$t( 'class name flagged, not replaced', array( 'search' => 'example_com', 'replace' => 'x' ), 'O:11:"example_com":1:{s:1:"a";s:11:"example_com";}', 'O:11:"example_com":1:{s:1:"a";s:1:"x";}', array( 'occurrences' => 2, 'included' => 1, 'flags' => array( 'inside_serialized_class_name' => 1 ) ) );
$t( 'array key excluded by default', $B, 'a:1:{s:11:"example.com";s:11:"example.com";}', 'a:1:{s:11:"example.com";s:12:"newsite.test";}', array( 'occurrences' => 2, 'included' => 1, 'flags' => array( 'in_serialized_key' => 1 ) ) );
$t( 'float/reference byte exact', $B, 'a:2:{i:0;d:0.1;i:1;R:2;}', 'a:2:{i:0;d:0.1;i:1;R:2;}', array( 'occurrences' => 0 ) );
$t( 'garbage that looks serialized is treated as text', $B, 'a:2:{i:0;s:5:"example.com";}', 'a:2:{i:0;s:5:"newsite.test";}', array( 'error' => null, 'encoding' => 'plain' ) );
$t( 'truly malformed (trailing data) never written', $B, 'a:1:{i:0;s:11:"example.com";};', null, array( 'error' => 'malformed_serialized: Malformed serialized data at byte 29: trailing data' ) );
$t( 'is_serialized false positive as text', $B, 'a: example.com is fine', 'a: newsite.test is fine', array( 'included' => 1 ) );
$t( 'inside_word plain', array( 'search' => 'Acme', 'replace' => 'Globex' ), 'Acme and Acmeco', 'Globex and Acmeco', array( 'flags' => array( 'inside_word' => 1 ) ) );
$t( 'path term', array( 'search' => '/uploads/2019', 'replace' => '/uploads/2020' ), 'https://x.com/uploads/2019/a.jpg', 'https://x.com/uploads/2020/a.jpg', array( 'included' => 1, 'flags' => array( 'inside_word' => 0 ) ) );
$t( 'would_truncate', array( 'search' => 'a', 'replace' => str_repeat( 'b', 30 ) ), 'a', null, array( 'error' => 'would_truncate', 'meta' => array( 'max_chars' => 20 ) ) );
$t( 'case insensitive', array( 'case_insensitive' => true ), 'HTTPS://EXAMPLE.COM/x', 'https://newsite.test/x', array( 'flags' => array( 'case_differs' => 1 ) ) );
$t( 'hash-like', array( 'search' => 'abc123', 'replace' => 'x' ), 'k=' . str_repeat( 'Zabc123', 8 ), null, array( 'included' => 0, 'flags' => array( 'in_hash_like_string' => 8 ) ) );
$t( 'invalid utf8 survives', $B, "\xC3\x28 example.com", "\xC3\x28 newsite.test", array( 'included' => 1 ) );
$t( 'fully excluded cell is unchanged', $B, 'admin@example.com', 'admin@example.com', array( 'included' => 0, 'occurrences' => 1 ) );

$rs = BESR_Ruleset::compile( array( 'search' => 'https://example.com', 'replace' => 'x', 'variants' => array( 'exact', 'scheme', 'www', 'json', 'urlencoded', 'scheme_relative' ) ) );
$ok = array( 'example.com' ) === $rs->needle_strings();
WP_CLI::log( ( $ok ? 'PASS' : 'FAIL' ) . ' needles collapse: ' . wp_json_encode( $rs->needle_strings() ) );
if ( ! $ok ) { $fail++; }

// selection param
$rp = new BESR_Replacer( BESR_Ruleset::compile( $B + array( 'excluded_contexts' => array( 'inside_email' ) ) ) );
$r  = $rp->process_cell( 'admin@example.com', array(), false, 'include' );
$ok = $r->changed && 'admin@newsite.test' === $r->value && 1 === $r->included;
WP_CLI::log( ( $ok ? 'PASS' : 'FAIL' ) . ' selection=include replaces excluded occurrence' );
if ( ! $ok ) { $fail++; }
$r  = $rp->process_cell( 'visit example.com', array(), false, 'skip' );
$ok = ! $r->changed && 0 === $r->included && 1 === $r->occurrences;
WP_CLI::log( ( $ok ? 'PASS' : 'FAIL' ) . ' selection=skip replaces nothing' );
if ( ! $ok ) { $fail++; }

WP_CLI::log( $fail ? "$fail FAILED" : 'ALL PASSED' );
WP_CLI::halt( $fail ? 1 : 0 );
