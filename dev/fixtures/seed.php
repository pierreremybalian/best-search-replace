<?php
/**
 * Edge-case fixtures for Best Search Replace. Run with: wp eval-file /fixtures/seed.php
 * Idempotent: guarded by the besr_fixtures_seeded option. "Old" domain is example.com.
 */

global $wpdb;

if ( '1' === (string) get_option( 'besr_fixtures_seeded' ) ) {
    WP_CLI::log( 'Fixtures already seeded.' );
    return;
}

class BESR_Fixture_Obj {
    protected $url;
    private $secret;
    public $arr;
    public function __construct() {
        $this->url    = 'https://example.com/p';
        $this->secret = 'x@example.com';
        $this->arr    = array(
            'https://example.com/as-key' => 'value',
            'f'                          => 0.1,
            'nested'                     => serialize( array( 'deep' => 'https://example.com/nested' ) ),
            'plain'                      => 'https://example.com/plain',
        );
    }
}

$U = 'https://example.com';
$posts = array(
    'Case http'            => "<p>Visit <a href=\"http://example.com/a\">http://example.com/a</a></p>",
    'Case https'           => "<p>Visit <a href=\"$U/a\">$U/a</a></p>",
    'Case www'             => "<p><img src=\"https://www.example.com/img.png\"></p>",
    'Case scheme-relative' => "<p><a href=\"//example.com/a\">relative</a></p>",
    'Case JSON block'      => '<!-- wp:image {"url":"https:\/\/example.com\/img.jpg","id":5} --><figure class="wp-block-image"><img src="' . $U . '/img.jpg" alt=""/></figure><!-- /wp:image -->',
    'Case URL-encoded'     => "<p><a href=\"https://tracker.test/r?next=https%3A%2F%2Fexample.com%2Fpage\">go</a></p>",
    'Case uppercase'       => "<p>HTTPS://EXAMPLE.COM/x</p>",
    'Case email'           => "<p>Write to admin@example.com today.</p>",
    'Case email longer'    => "<p>Write to sales@example.com.au today.</p>",
    'Case subdomain'       => "<p><a href=\"https://shop.example.com/x\">shop</a></p>",
    'Case longer tld'      => "<p><a href=\"https://example.com.au/\">au</a></p>",
    'Case prefix word'     => "<p>See myexample.com for more.</p>",
    'Case suffix word'     => "<p>See example.company for more.</p>",
    'Case plain domain'    => "<p>Just example.com in text and $U/full as URL.</p>",
);
$ids = array();
foreach ( $posts as $title => $content ) {
    $ids[ $title ] = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => $content, 'post_name' => sanitize_title( $title ) ) );
}
// ~1.5 MB post.
$para = "<p>Big paragraph with <a href=\"$U/one\">one</a>, <a href=\"$U/two\">two</a> and <a href=\"http://example.com/three\">three</a> links. " . str_repeat( 'Lorem ipsum dolor sit amet. ', 20 ) . "</p>\n";
$big  = '';
while ( strlen( $big ) < 1500000 ) {
    $big .= $para;
}
$ids['Case huge'] = wp_insert_post( array( 'post_title' => 'Case huge', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => $big, 'post_name' => 'case-huge' ) );

// Elementor-style meta (~300 KB, escaped slashes).
$el = array();
for ( $i = 0; $i < 1500; $i++ ) {
    $el[] = array( 'id' => 'el' . $i, 'elType' => 'widget', 'settings' => array( 'image' => array( 'url' => $U . '/uploads/' . $i . '.jpg' ), 'link' => array( 'url' => $U . '/page-' . $i . '/' ), 'text' => str_repeat( 'x', 80 ) ) );
}
update_post_meta( $ids['Case https'], '_elementor_data', wp_slash( json_encode( $el ) ) );
update_post_meta( $ids['Case https'], '_elementor_css', 'stub' );
update_post_meta( $ids['Case http'], 'bsrfx_meta_url', $U . '/meta' );

// Options.
update_option( 'bsrfx_serialized', new BESR_Fixture_Obj(), false );
update_option( 'bsrfx_json', wp_json_encode( array( 'home' => $U, 'img' => $U . '/img.jpg' ) ), false );
set_transient( 'bsrfx_transient', $U . '/transient', 0 );
update_option( 'theme_mods_bsrfx_theme', array( 'custom_logo_url' => $U . '/logo.png', 'nav_menu_locations' => array() ), false );
update_option( 'widget_text_bsrfx', array( 2 => array( 'title' => 'Links', 'text' => "<a href=\"$U/widget\">w</a>" ), '_multiwidget' => 1 ), false );

// Users and comments.
if ( ! username_exists( 'editor' ) ) {
    wp_insert_user( array( 'user_login' => 'editor', 'user_email' => 'editor@example.com', 'user_pass' => 'password', 'role' => 'editor', 'user_url' => $U . '/editor' ) );
}
if ( ! username_exists( 'someone' ) ) {
    wp_insert_user( array( 'user_login' => 'someone', 'user_email' => 'someone@sub.example.com', 'user_pass' => 'password', 'role' => 'subscriber' ) );
}
wp_insert_comment( array( 'comment_post_ID' => $ids['Case https'], 'comment_author' => 'Bob', 'comment_author_email' => 'bob@example.com', 'comment_author_url' => $U . '/bob', 'comment_content' => "Nice, see $U/comment", 'comment_approved' => 1 ) );

// Extra tables.
$p = $wpdb->prefix;
$wpdb->query( "DROP TABLE IF EXISTS `{$p}besr_fixture_nopk`" );
$wpdb->query( "CREATE TABLE `{$p}besr_fixture_nopk` (label TEXT, body LONGTEXT) ENGINE=InnoDB" );
for ( $i = 1; $i <= 48; $i++ ) {
    $wpdb->insert( "{$p}besr_fixture_nopk", array( 'label' => "row $i", 'body' => 0 === $i % 2 ? "$U/nopk/$i" : "nothing here $i" ) );
}
$wpdb->insert( "{$p}besr_fixture_nopk", array( 'label' => 'dup', 'body' => "$U/duplicate" ) );
$wpdb->insert( "{$p}besr_fixture_nopk", array( 'label' => 'dup', 'body' => "$U/duplicate" ) );

$wpdb->query( "DROP TABLE IF EXISTS `{$p}gf_entry`" );
$wpdb->query( "CREATE TABLE `{$p}gf_entry` (id INT AUTO_INCREMENT PRIMARY KEY, source_url TEXT, payload LONGTEXT) ENGINE=InnoDB" );
for ( $i = 1; $i <= 100; $i++ ) {
    $wpdb->insert( "{$p}gf_entry", array( 'source_url' => 0 === $i % 2 ? "$U/form/$i" : "https://other.test/form/$i", 'payload' => serialize( array( 'ref' => 0 === $i % 2 ? "$U/ref" : 'none', 'n' => $i ) ) ) );
}

$wpdb->query( "DROP TABLE IF EXISTS `{$p}actionscheduler_logs`" );
$wpdb->query( "CREATE TABLE `{$p}actionscheduler_logs` (log_id INT PRIMARY KEY, message TEXT) ENGINE=InnoDB" );
for ( $i = 1; $i <= 20; $i++ ) {
    $wpdb->insert( "{$p}actionscheduler_logs", array( 'log_id' => $i, 'message' => "fetched $U/cron/$i" ) );
}

$wpdb->query( "DROP TABLE IF EXISTS `{$p}foo_blob`" );
$wpdb->query( "CREATE TABLE `{$p}foo_blob` (id INT PRIMARY KEY, payload BLOB) ENGINE=InnoDB" );
$wpdb->insert( "{$p}foo_blob", array( 'id' => 1, 'payload' => "$U/blob" ) );

$wpdb->query( "DROP TABLE IF EXISTS `{$p}composite_pk`" );
$wpdb->query( "CREATE TABLE `{$p}composite_pk` (a INT NOT NULL, b VARCHAR(20) NOT NULL, val TEXT, PRIMARY KEY (a,b)) ENGINE=InnoDB" );
for ( $i = 1; $i <= 30; $i++ ) {
    $wpdb->insert( "{$p}composite_pk", array( 'a' => (int) ceil( $i / 3 ), 'b' => 'k' . $i, 'val' => "$U/composite/$i" ) );
}

update_option( 'besr_fixtures_seeded', '1', false );

$n_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%example.com%'" );
WP_CLI::log( "Seeded. Expected counts:" );
WP_CLI::log( "  posts with example.com in content: $n_posts" );
WP_CLI::log( "  gf_entry rows with URL: 50 (source_url) + 50 (payload)" );
WP_CLI::log( "  composite_pk rows: 30, actionscheduler_logs: 20, nopk rows with URL: 26" );
