<?php
/** Layout load/save: round trips, validation errors, conflicts, payload size, theme permissions. */

rk_test( 'never-saved page loads empty layout, revision 0, default theme', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$d = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	t_eq( $d['layout'], array( 'version' => 1, 'blocks' => array() ) );
	t_eq( $d['revision'], 0 );
	t_eq( $d['publishedRevision'], null );
	t_eq( $d['theme']['primary'], '#C7F36B' );
	t_eq( $d['theme']['font'], 'Space Grotesk' );
	t_eq( $d['page']['id'], $id );
	foreach ( array( 'id', 'title', 'slug', 'status', 'link' ) as $k ) { t_assert( isset( $d['page'][ $k ] ), $k ); }
	t_assert( isset( $d['updatedAt'] ) );
} );

rk_test( 'round trip: save then load returns an equivalent document (every prop of layout-full)', function () {
	$id  = rk_test_page();
	$doc = fx( 'valid/layout-full.json' )['document'];
	rk_test_login( 'editor' );
	$s = t_ok( rk_save( $id, $doc, 0 ) );
	t_eq( $s['ok'], true );
	t_eq( $s['pageId'], $id );
	t_eq( $s['revision'], 1 );
	t_eq( $s['status'], 'draft' );
	t_assert( 1 === preg_match( '/Z$/', $s['updatedAt'] ) );
	$loaded = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	t_eq( $loaded['revision'], 1 );
	// Compare after a real JSON encode/decode, exactly what the wire sees.
	t_deep( json_decode( json_encode( $loaded['layout'] ), true ), $doc );
	t_eq( count( $loaded['layout']['blocks'] ), 9 );
	// And the stored meta is the JSON we expect (slashing handled: '</' and '\n' survive).
	$raw = json_decode( $GLOBALS['RK']['meta'][ $id ]['_rk_layout_draft'], true );
	t_deep( $raw, $doc );
	t_eq( $raw['blocks'][2]['props']['text'], $doc['blocks'][2]['props']['text'] );
} );

rk_test( 'round trip survives backslashes, quotes and unicode in text (wp_slash correctness)', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$text = "C:\\temp \"quoted\" 'single' \u{1F600} \\n literal\nnewline </script>";
	$doc  = array( 'version' => 1, 'blocks' => array( array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => $text ) ) ) );
	t_ok( rk_save( $id, $doc, 0 ) );
	t_eq( t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['layout']['blocks'][0]['props']['text'], $text );
} );

rk_test( 'floats like 40.0 are stored as ints', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_post( '/rk/v1/builder/layout/' . $id, '{"layout":{"version":1,"blocks":[{"id":"s","type":"spacer","props":{"h":40.0}}]},"expectedRevision":0,"status":"draft"}' ) );
	t_eq( t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['layout']['blocks'][0]['props']['h'], 40 );
} );

rk_test( 'unknown block type is rejected with 400 and an issues array; nothing is stored', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$bad = array( 'version' => 1, 'blocks' => array( array( 'id' => 'b', 'type' => 'columns', 'props' => array() ) ) );
	$d   = t_err( rk_save( $id, $bad, 0 ), 'rk_invalid_layout', 400 );
	t_assert( is_array( $d['issues'] ) && count( $d['issues'] ) >= 1 );
	t_eq( $d['issues'][0]['path'], 'layout.blocks.0.type' );
	t_assert( ! isset( $GLOBALS['RK']['meta'][ $id ]['_rk_layout_draft'] ), 'must not persist invalid data' );
	t_eq( rk_builder_get_revision( $id ), 0 );
} );

rk_test( 'every invalid layout fixture is rejected by the endpoint, never silently cleaned', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	foreach ( fx_files( 'invalid' ) as $file ) {
		$fx = json_decode( file_get_contents( $file ), true );
		if ( 'layout' !== $fx['kind'] ) { continue; }
		$res = rk_save( $id, $fx['document'], 0 );
		t_err( $res, 'rk_invalid_layout', 400, basename( $file ) );
	}
	t_eq( rk_builder_get_revision( $id ), 0 );
} );

rk_test( 'dangerous links are rejected through the endpoint', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	foreach ( array( 'javascript:alert(1)', 'data:text/html,x', 'vbscript:x', ' javascript:x', 'JaVaScRiPt:x' ) as $bad ) {
		$doc = array( 'version' => 1, 'blocks' => array( array( 'id' => 'h', 'type' => 'hero', 'props' => array( 'heading' => 'x', 'sub' => '', 'cta' => 'go', 'ctaHref' => $bad ) ) ) );
		t_err( rk_save( $id, $doc, 0 ), 'rk_invalid_layout', 400, $bad );
	}
} );

rk_test( 'images: foreign absolute host rejected, site host accepted, filter/constant extends the allow-list', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$mk = function ( $u ) { return array( 'version' => 1, 'blocks' => array( array( 'id' => 'i', 'type' => 'image', 'props' => array( 'url' => $u, 'alt' => 'a', 'decorative' => false ) ) ) ); };
	t_err( rk_save( $id, $mk( 'https://evil.example/a.jpg' ), 0 ), 'rk_invalid_layout', 400 );
	t_ok( rk_save( $id, $mk( 'https://cms.example.com/wp-content/uploads/a.jpg' ), 0 ) );
	add_filter( 'rk_builder_allowed_image_hosts', function ( $h ) { $h[] = 'cdn.example.org'; return $h; } );
	t_ok( rk_save( $id, $mk( 'https://cdn.example.org/a.jpg' ), 1 ) );
	t_err( rk_save( $id, $mk( 'https://cdn.example.org.evil.example/a.jpg' ), 2 ), 'rk_invalid_layout', 400 );
} );

rk_test( 'body strictness: status must be "draft", unknown keys, missing/invalid expectedRevision', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$ok = array( 'layout' => rk_spacer_layout( 40 ), 'expectedRevision' => 0, 'status' => 'draft' );
	$d  = t_err( rk_post( '/rk/v1/builder/layout/' . $id, array_merge( $ok, array( 'status' => 'publish' ) ) ), 'rk_invalid_layout', 400 );
	t_eq( $d['issues'][0]['path'], 'status' );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, array( 'layout' => $ok['layout'], 'expectedRevision' => 0 ) ), 'rk_invalid_layout', 400, 'status is required' );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, array_merge( $ok, array( 'post_status' => 'publish' ) ) ), 'rk_invalid_layout', 400, 'unknown key' );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, array( 'layout' => $ok['layout'], 'status' => 'draft' ) ), 'rk_invalid_layout', 400, 'expectedRevision required' );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, array_merge( $ok, array( 'expectedRevision' => '0' ) ) ), 'rk_invalid_layout', 400, 'string revision' );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, array_merge( $ok, array( 'expectedRevision' => -1 ) ) ), 'rk_invalid_layout', 400 );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, '{not json' ), 'rk_invalid_layout', 400 );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, '[1,2]' ), 'rk_invalid_layout', 400 );
	t_eq( rk_builder_get_revision( $id ), 0 );
} );

rk_test( 'stale expectedRevision gives 409 rk_revision_conflict with currentRevision', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_ok( rk_save( $id, rk_spacer_layout( 48 ), 1 ) );
	$d = t_err( rk_save( $id, rk_spacer_layout( 56 ), 1 ), 'rk_revision_conflict', 409 );
	t_eq( $d['currentRevision'], 2 );
	$d = t_err( rk_save( $id, rk_spacer_layout( 56 ), 5 ), 'rk_revision_conflict', 409, 'ahead is also a conflict' );
	t_eq( $d['currentRevision'], 2 );
	t_eq( t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['layout']['blocks'][0]['props']['h'], 48, 'conflicting save changed nothing' );
} );

rk_test( 'a held lock yields a 409 (never a silent overwrite); lock is released after each save', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$GLOBALS['RK']['options'][ 'rk_builder_lock_' . $id ] = (string) time();
	t_err( rk_save( $id, rk_spacer_layout( 40 ), 0 ), 'rk_revision_conflict', 409 );
	$GLOBALS['RK']['options'][ 'rk_builder_lock_' . $id ] = (string) ( time() - 1000 ); // stale
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ), 'stale lock is taken over' );
	t_assert( ! isset( $GLOBALS['RK']['options'][ 'rk_builder_lock_' . $id ] ), 'released' );
	t_ok( rk_save( $id, rk_spacer_layout( 48 ), 1 ) );
	t_err( rk_save( $id, rk_spacer_layout( 48 ), 1 ), 'rk_revision_conflict', 409, 'lock released even after conflict' );
	t_assert( ! isset( $GLOBALS['RK']['options'][ 'rk_builder_lock_' . $id ] ) );
} );

rk_test( 'payload over 256KB gives 413 rk_payload_too_large (before anything is stored)', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$big = str_repeat( 'x', 256 * 1024 + 1 );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, '{"layout":{"version":1,"blocks":[]},"expectedRevision":0,"status":"draft","pad":"' . $big . '"}' ), 'rk_payload_too_large', 413 );
	t_err( rk_post( '/rk/v1/builder/publish/' . $id, '{"expectedRevision":0,"pad":"' . $big . '"}' ), 'rk_payload_too_large', 413 );
	t_eq( rk_builder_get_revision( $id ), 0 );
	// Exactly at the limit is not "too large" (it fails validation instead).
	$head = '{"layout":{"version":1,"blocks":[]},"expectedRevision":0,"status":"draft","p":"';
	$body = $head . str_repeat( 'x', 256 * 1024 - strlen( $head ) - 2 ) . '"}';
	t_eq( strlen( $body ), 256 * 1024 );
	t_err( rk_post( '/rk/v1/builder/layout/' . $id, $body ), 'rk_invalid_layout', 400 );
} );

rk_test( 'a max-size valid layout (100 text blocks) is accepted and stays under the cap', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$blocks = array();
	for ( $i = 0; $i < 100; $i++ ) { $blocks[] = array( 'id' => 't' . $i, 'type' => 'text', 'props' => array( 'text' => str_repeat( 'a', 2000 ) ) ); }
	t_ok( rk_save( $id, array( 'version' => 1, 'blocks' => $blocks ), 0 ) );
} );

rk_test( 'saving a draft of a published page never touches post_status or the published snapshot', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ) );
	$s = t_ok( rk_save( $id, rk_spacer_layout( 80 ), 2 ) );
	t_eq( $s['status'], 'publish' );
	t_eq( json_decode( $GLOBALS['RK']['meta'][ $id ]['_rk_layout_published'], true )['blocks'][0]['props']['h'], 40 );
} );

rk_test( 'theme via layout save: editor may echo the stored theme but not change it; admin may', function () {
	$id = rk_test_page();
	$new = fx( 'valid/theme-full.json' )['document'];
	rk_test_login( 'editor' );
	$cur = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	$theme = json_decode( json_encode( $cur['theme'] ), true );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0, array( 'theme' => $theme ) ), 'identical theme is fine' );
	t_err( rk_save( $id, rk_spacer_layout( 48 ), 1, array( 'theme' => $new ) ), 'rk_forbidden', 403 );
	t_eq( get_option( 'rk_theme_config', null ), null, 'editor could not write the theme' );
	t_eq( rk_builder_get_revision( $id ), 1, 'forbidden save changed nothing' );
	rk_test_login( 'admin' );
	t_ok( rk_save( $id, rk_spacer_layout( 48 ), 1, array( 'theme' => $new ) ) );
	t_eq( rk_builder_get_theme()['font'], 'IBM Plex Mono' );
	t_eq( count( $GLOBALS['RK']['http'] ), 1, 'theme change triggers revalidation' );
	// invalid theme in a layout save is rejected, never clipped
	$bad = $new; $bad['font'] = 'Comic Sans';
	t_err( rk_save( $id, rk_spacer_layout( 56 ), 2, array( 'theme' => $bad ) ), 'rk_invalid_theme', 400 );
} );
