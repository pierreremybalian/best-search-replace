<?php
/**
 * End-to-end engine test against the fixtures. Run: wp eval-file /tests/engine-smoke.php
 * Uses a 0.5s budget so scan/apply/undo take many ticks.
 */

global $wpdb;
$fail = 0;
$check = function ( $name, $cond, $detail = '' ) use ( &$fail ) {
    WP_CLI::log( ( $cond ? 'PASS' : 'FAIL' ) . " $name" . ( $cond || '' === $detail ? '' : "  ($detail)" ) );
    if ( ! $cond ) { $fail++; }
};
$drive = function ( BESR_Run $run ) {
    $ticks = 0;
    while ( $run->is_running() && $ticks < 5000 ) {
        $engine = new BESR_Engine( $run, 0.005 );
        $out    = $engine->tick();
        $ticks++;
        if ( ! empty( $out['busy'] ) ) { usleep( 100000 ); }
    }
    return $ticks;
};
$checksum = function () use ( $wpdb ) {
    $p = $wpdb->prefix;
    $tables = array( "{$p}posts", "{$p}postmeta", "{$p}options", "{$p}comments", "{$p}users", "{$p}usermeta", "{$p}gf_entry", "{$p}composite_pk", "{$p}actionscheduler_logs", "{$p}besr_fixture_nopk", "{$p}foo_blob", "{$p}terms" );
    $out = array();
    foreach ( $tables as $t ) {
        if ( "{$p}options" === $t ) {
            // Volatile rows (cron, transients, rewrite_rules, our own options) are excluded on purpose.
            $wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
            $out[ $t ] = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(CONCAT(option_id, ':', MD5(option_value)) ORDER BY option_id SEPARATOR ',')) FROM `$t` WHERE option_name NOT IN ('rewrite_rules','cron') AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT LIKE 'besr%'" );
            continue;
        }
        $row = $wpdb->get_row( "CHECKSUM TABLE `$t`", ARRAY_A );
        $out[ $t ] = $row['Checksum'] ?? null;
    }
    return $out;
};

BESR_Schema::ensure();
// Clean slate: remove earlier test runs.
foreach ( BESR_Run::list( array( 'limit' => 500 ) ) as $old ) { $old->delete(); }
$p = $wpdb->prefix;

// Make sure fixtures are in the "before" state (a previous run may have been applied without undo).
$leftover = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE '%newsite.test%'" );
if ( $leftover > 0 ) {
    WP_CLI::warning( "Fixtures contain newsite.test already ($leftover posts); reseed with ./bootstrap.sh --reset for a clean run." );
}

$before = $checksum();

// ---------------------------------------------------------------- scan
$run = BESR_Engine::start_scan( array(
    'search'         => 'https://example.com',
    'replace'        => 'https://newsite.test',
    'groups'         => array( 'all' ),
    'variants'       => array( 'exact', 'scheme', 'www', 'json', 'urlencoded', 'scheme_relative' ),
    'include_global' => false,
) );
$check( 'start_scan returns run', $run instanceof BESR_Run, is_wp_error( $run ) ? $run->get_error_message() : '' );
if ( is_wp_error( $run ) ) { WP_CLI::halt( 1 ); }
$ticks = $drive( $run );
$run   = BESR_Run::load( $run->id() );
$check( 'scan took several ticks', $ticks > 3, "ticks=$ticks" );
$check( 'status scanned', BESR_Run::STATUS_SCANNED === $run->status(), $run->status() . ' ' . $run->error() );
$sum = $run->summary();
$check( 'cells > 0', ( $sum['counts']['cells'] ?? 0 ) > 0, wp_json_encode( $sum['counts'] ?? null ) );
$tab = array();
foreach ( $run->tables() as $t ) { $tab[ $t['name'] ] = $t; }
$check( 'nopk table skipped with like_hits', 'skipped' === ( $tab["{$p}besr_fixture_nopk"]['status'] ?? '' ) && ( $tab["{$p}besr_fixture_nopk"]['like_hits'] ?? 0 ) > 0, wp_json_encode( array( $tab["{$p}besr_fixture_nopk"]['status'] ?? null, $tab["{$p}besr_fixture_nopk"]['like_hits'] ?? null ) ) );
$check( 'blob table skipped binary_only', 'binary_only' === ( $tab["{$p}foo_blob"]['skip_reason'] ?? '' ), $tab["{$p}foo_blob"]['skip_reason'] ?? 'missing' );
$check( 'guid excluded on posts', isset( $tab["{$p}posts"]['excluded_columns']['guid'] ) );
$check( 'composite pk scanned', ( $tab["{$p}composite_pk"]['cells'] ?? 0 ) === 30, (string) ( $tab["{$p}composite_pk"]['cells'] ?? 'n/a' ) );
$check( 'gf_entry scanned 100 cells', ( $tab["{$p}gf_entry"]['cells'] ?? 0 ) === 100, (string) ( $tab["{$p}gf_entry"]['cells'] ?? 'n/a' ) );
$check( 'transient row skipped', $run->count_get( 'skipped_transient' ) > 0, (string) $run->count_get( 'skipped_transient' ) );
$mt = BESR_Schema::matches();
$transient_id = (int) $wpdb->get_var( "SELECT option_id FROM {$p}options WHERE option_name = '_transient_bsrfx_transient'" );
$check( 'transient not in matches', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$mt` WHERE run_id=%d AND table_name=%s AND pk_json=%s", $run->id(), "{$p}options", wp_json_encode( array( 'option_id' => (string) $transient_id ) ) ) ) );
$ser_id = (int) $wpdb->get_var( "SELECT option_id FROM {$p}options WHERE option_name = 'bsrfx_serialized'" );
$ser_match = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$mt` WHERE run_id=%d AND table_name=%s AND pk_json=%s", $run->id(), "{$p}options", wp_json_encode( array( 'option_id' => (string) $ser_id ) ) ), ARRAY_A );
$check( 'serialized option matched (nested)', $ser_match && in_array( $ser_match['encoding'], array( 'serialized', 'nested' ), true ), wp_json_encode( $ser_match ? array( $ser_match['encoding'], $ser_match['occurrences'], $ser_match['flagsets'] ) : null ) );
$check( 'serialized option: key flagged', $ser_match && false !== strpos( $ser_match['flagsets'], 'in_serialized_key' ), $ser_match['flagsets'] ?? '' );
$siteurl_ids = $wpdb->get_col( "SELECT option_id FROM {$p}options WHERE option_name IN ('siteurl','home')" );
$n_site = 0;
foreach ( $siteurl_ids as $sid ) {
    $n_site += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$mt` WHERE run_id=%d AND pk_json=%s AND table_name=%s", $run->id(), wp_json_encode( array( 'option_id' => (string) $sid ) ), "{$p}options" ) );
}
$check( 'siteurl/home not matched', 0 === $n_site );
$json_post = (int) $wpdb->get_var( "SELECT ID FROM {$p}posts WHERE post_title = 'Case JSON block'" );
$jm = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$mt` WHERE run_id=%d AND table_name=%s AND pk_json=%s AND column_name='post_content'", $run->id(), "{$p}posts", wp_json_encode( array( 'ID' => (string) $json_post ) ) ), ARRAY_A );
$check( 'JSON block post matched with 2 occurrences (json + plain)', $jm && 2 === (int) $jm['occurrences'], wp_json_encode( $jm ? array( $jm['occurrences'], $jm['variants'] ) : null ) );
$check( 'user_email column never matched', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$mt` WHERE run_id=%d AND table_name=%s AND column_name='user_email'", $run->id(), "{$p}users" ) ) );
$check( 'user_url matched', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$mt` WHERE run_id=%d AND table_name=%s AND column_name='user_url'", $run->id(), "{$p}users" ) ) );
$email_post = (int) $wpdb->get_var( "SELECT ID FROM {$p}posts WHERE post_title = 'Case email'" );
$check( 'email-only post not matched (bare variant off)', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$mt` WHERE run_id=%d AND table_name=%s AND pk_json=%s", $run->id(), "{$p}posts", wp_json_encode( array( 'ID' => (string) $email_post ) ) ) ) );
$huge = (int) $wpdb->get_var( "SELECT ID FROM {$p}posts WHERE post_title = 'Case huge'" );
$hm   = $wpdb->get_row( $wpdb->prepare( "SELECT occurrences, cell_length FROM `$mt` WHERE run_id=%d AND table_name=%s AND pk_json=%s AND column_name='post_content'", $run->id(), "{$p}posts", wp_json_encode( array( 'ID' => (string) $huge ) ) ), ARRAY_A );
$check( 'huge post matched', $hm && (int) $hm['cell_length'] > 1400000 && (int) $hm['occurrences'] > 100, wp_json_encode( $hm ) );
$check( 'review query works', ( BESR_Matches::query( $run->id(), array( 'table' => "{$p}posts", 'per_page' => 5 ) )['total'] ?? 0 ) > 0 );
$rev = BESR_Matches::query( $run->id(), array( 'table' => "{$p}posts", 'per_page' => 3 ) );
$check( 'review rows enriched with title', ! empty( $rev['rows'][0]['where']['label'] ) && false !== strpos( $rev['rows'][0]['before'], '<mark>' ), wp_json_encode( $rev['rows'][0]['where'] ?? null ) );
$logs_cells = (int) ( $tab["{$p}actionscheduler_logs"]['cells'] ?? 0 );
$check( 'logs table scanned (groups=all)', 20 === $logs_cells, (string) $logs_cells );

// ---------------------------------------------------------------- apply
$res = BESR_Engine::begin_apply( $run );
$check( 'begin_apply ok', true === $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
$ticks = $drive( $run );
$run   = BESR_Run::load( $run->id() );
$check( 'apply took several ticks', $ticks > 3, "ticks=$ticks" );
$check( 'status applied', BESR_Run::STATUS_APPLIED === $run->status(), $run->status() . ' ' . $run->error() );
$c = $run->counts();
$check( 'applied cells > 0', ( $c['applied_cells'] ?? 0 ) > 0, wp_json_encode( $c ) );
$check( 'no apply errors', 0 === (int) ( $c['apply_errors'] ?? 0 ), wp_json_encode( $run->get( 'error_list' ) ) );
$check( 'gf_entry updated', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}gf_entry WHERE source_url LIKE '%example.com%'" ) && 50 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}gf_entry WHERE source_url LIKE '%newsite.test%'" ) );
$check( 'gf_entry serialized payload still valid', false !== @unserialize( (string) $wpdb->get_var( "SELECT payload FROM {$p}gf_entry WHERE id = 2" ) ) && false !== strpos( (string) $wpdb->get_var( "SELECT payload FROM {$p}gf_entry WHERE id = 2" ), 'newsite.test' ) );
$check( 'composite_pk updated', 30 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}composite_pk WHERE val LIKE '%newsite.test%'" ) );
$residue = "SELECT GROUP_CONCAT(post_title) FROM {$p}posts WHERE (post_content LIKE BINARY '%https://example.com%' OR post_content LIKE BINARY '%http://example.com%') AND post_content NOT LIKE '%example.com.au%'";
$check( 'no https://example.com left in posts (except longer domain)', null === $wpdb->get_var( $residue ), (string) $wpdb->get_var( $residue ) );
$check( 'admin@example.com intact', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE '%admin@example.com%'" ) );
$check( 'shop.example.com intact (subdomain not matched by URL term)', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE '%https://shop.example.com/x%'" ) );
$check( 'example.com.au intact (longer domain)', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE '%https://example.com.au/%'" ) );
$check( 'JSON block rewritten', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE '%https:\\\\/\\\\/newsite.test\\\\/img.jpg%'" ), (string) $wpdb->get_var( "SELECT post_content FROM {$p}posts WHERE post_title='Case JSON block'" ) );
$check( 'URL-encoded rewritten', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE '%https%3A%2F%2Fnewsite.test%2Fpage%'" ) );
$check( 'uppercase left alone (case-sensitive)', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE BINARY '%HTTPS://EXAMPLE.COM/x%'" ) );
$raw = (string) $wpdb->get_var( "SELECT option_value FROM {$p}options WHERE option_name = 'bsrfx_serialized'" );
$obj = @unserialize( $raw, array( 'allowed_classes' => false ) );
$check( 'serialized option still unserializes', false !== $obj );
$check( 'protected url rewritten', false !== strpos( $raw, "\0*\0url" ) && false !== strpos( $raw, 'https://newsite.test/p' ), str_replace( "\0", '\\0', substr( $raw, 0, 120 ) ) );
$check( 'private email prop intact', false !== strpos( $raw, 'x@example.com' ) );
$check( 'nested serialized rewritten', false !== strpos( $raw, 'newsite.test/nested' ) );
$check( 'array key kept (in_serialized_key excluded)', false !== strpos( $raw, 'https://example.com/as-key' ) );
$check( 'float intact', false !== strpos( $raw, 'd:0.1;' ) );
$check( 'transient untouched', false !== strpos( (string) $wpdb->get_var( "SELECT option_value FROM {$p}options WHERE option_name = '_transient_bsrfx_transient'" ), 'example.com/transient' ) );
$check( 'user_email untouched', 'editor@example.com' === $wpdb->get_var( "SELECT user_email FROM {$p}users WHERE user_login='editor'" ) );
$check( 'comment_author_email untouched', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}comments WHERE comment_author_email='bob@example.com'" ) );
$check( 'comment content rewritten', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}comments WHERE comment_content LIKE '%newsite.test/comment%'" ) );
$check( 'elementor meta rewritten', false !== strpos( (string) $wpdb->get_var( "SELECT meta_value FROM {$p}postmeta WHERE meta_key='_elementor_data'" ), 'https:\/\/newsite.test\/uploads\/1.jpg' ) );
$check( 'guid untouched', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE guid LIKE '%newsite.test%'" ) === 0 );
$jt = BESR_Schema::journal();
$jcount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$jt` WHERE run_id=%d", $run->id() ) );
$check( 'journal rows == applied cells', $jcount === (int) $c['applied_cells'], "$jcount vs " . $c['applied_cells'] );
$check( 'journal_bytes recorded', (int) $run->row( 'journal_bytes' ) > 0 );
$sql = '';
foreach ( BESR_Journal::export_sql( $run ) as $line ) { $sql .= $line; }
$check( 'export sql has one UPDATE per journal row', substr_count( $sql, "\nUPDATE " ) + ( 0 === strpos( $sql, 'UPDATE' ) ? 1 : 0 ) === $jcount, (string) substr_count( $sql, 'UPDATE ' ) );

// ---------------------------------------------------------------- undo
$wpdb->query( $wpdb->prepare( "UPDATE {$p}gf_entry SET source_url = %s WHERE id = 2", 'https://edited.test/after' ) );
$pre = BESR_Journal::conflicts_preview( $run, 5000 );
$check( 'conflicts_preview sees 1 changed cell', 1 === (int) $pre['changed'], wp_json_encode( $pre ) );
$res = BESR_Engine::begin_undo( $run );
$check( 'begin_undo ok', true === $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
$ticks = $drive( $run );
$run   = BESR_Run::load( $run->id() );
$check( 'undo took several ticks', $ticks > 2, "ticks=$ticks" );
$check( 'status undone', BESR_Run::STATUS_UNDONE === $run->status(), $run->status() . ' ' . $run->error() );
$check( 'exactly one conflict', 1 === $run->count_get( 'conflicts' ), (string) $run->count_get( 'conflicts' ) . ' ' . wp_json_encode( $run->get( 'conflict_list' ) ) );
$check( 'undo errors 0', 0 === $run->count_get( 'undo_errors' ) );
$after = $checksum();
foreach ( $before as $t => $sum_before ) {
    if ( "{$p}gf_entry" === $t ) {
        $check( "checksum $t differs (hand-edited)", $sum_before !== $after[ $t ] );
    } else {
        $check( "checksum $t restored", $sum_before === $after[ $t ], "$sum_before vs {$after[$t]}" );
    }
}
$check( 'hand-edited cell kept', 'https://edited.test/after' === $wpdb->get_var( "SELECT source_url FROM {$p}gf_entry WHERE id = 2" ) );
// restore it manually so the fixtures are pristine again
$wpdb->query( $wpdb->prepare( "UPDATE {$p}gf_entry SET source_url = %s WHERE id = 2", 'https://example.com/form/2' ) );

// ---------------------------------------------------------------- bare-domain scan
$run2 = BESR_Engine::start_scan( array(
    'search'   => 'example.com',
    'replace'  => 'newsite.test',
    'groups'   => array( 'content', 'settings', 'users', 'comments' ),
    'variants' => array( 'exact', 'www', 'json', 'urlencoded' ),
) );
$check( 'bare scan starts', $run2 instanceof BESR_Run, is_wp_error( $run2 ) ? $run2->get_error_message() : '' );
if ( $run2 instanceof BESR_Run ) {
    $drive( $run2 );
    $run2 = BESR_Run::load( $run2->id() );
    $s2   = $run2->summary();
    $check( 'bare scan scanned', BESR_Run::STATUS_SCANNED === $run2->status(), $run2->error() );
    $check( 'inside_email flagged', ( $s2['flags']['inside_email']['count'] ?? 0 ) > 0, wp_json_encode( array_keys( $s2['flags'] ?? array() ) ) );
    $check( 'longer_domain flagged', ( $s2['flags']['longer_domain']['count'] ?? 0 ) > 0 );
    $check( 'inside_word flagged (myexample.com)', ( $s2['flags']['inside_word']['count'] ?? 0 ) > 0 );
    $check( 'excluded flags reduce included', $s2['counts']['included'] < $s2['counts']['occurrences'], wp_json_encode( $s2['counts'] ) );
    $errs = BESR_Matches::query( $run2->id(), array( 'view' => 'errors', 'per_page' => 5 ) );
    WP_CLI::log( '  scan error rows: ' . $errs['total'] . ' ' . wp_json_encode( array_map( function ( $r ) { return array( $r['table_name'], $r['column_name'], $r['note'] ); }, $errs['rows'] ) ) );
    $ex = BESR_Matches::examples( $run2->id(), 'inside_email', 2 );
    $check( 'examples for inside_email', count( $ex ) > 0 && false !== strpos( $ex[0]['before'], '<mark>' ) );
    // toggle: include emails -> included grows
    $inc_before = $s2['counts']['included'];
    $run2->set_excluded_contexts( array( 'longer_domain', 'inside_word', 'in_hash_like_string', 'in_serialized_key' ) );
    BESR_Scanner::refresh_summary( $run2 );
    $run2->save();
    $s3 = BESR_Run::load( $run2->id() )->summary();
    $check( 'toggling inside_email increases included without rescan', $s3['counts']['included'] > $inc_before, "$inc_before -> {$s3['counts']['included']}" );
    // per-row skip
    $first = null;
    foreach ( BESR_Matches::query( $run2->id(), array( 'per_page' => 50, 'excluded' => $run2->excluded_contexts() ) )['rows'] as $cand ) {
        if ( $cand['included'] > 0 && 'pending' === $cand['status'] ) { $first = $cand; break; }
    }
    if ( $first ) {
        BESR_Matches::set_selection( $run2->id(), array( 'ids' => array( $first['id'] ) ), 'skip' );
        BESR_Scanner::refresh_summary( $run2 );
        $s4 = $run2->summary();
        $check( 'row skip reduces included', $s4['counts']['included'] < $s3['counts']['included'], "{$s3['counts']['included']} -> {$s4['counts']['included']} (skipped id {$first['id']} included {$first['included']})" );
    } else {
        $check( 'row skip: found a candidate row', false );
    }
    $run2->delete();
}

// prune keeps history row
$report = BESR_Journal::prune( true );
$check( 'prune runs', is_array( $report ) );
$check( 'run still listed after undo', null !== BESR_Run::load( $run->id() ) );

WP_CLI::log( $fail ? "$fail FAILED" : 'ALL PASSED' );
WP_CLI::halt( $fail ? 1 : 0 );
