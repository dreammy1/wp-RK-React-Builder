<?php
/** Publish / unpublish / public page / preview tokens / revalidation webhook. */

function rk_public( $slug, $query = array() ) { return rk_get( '/rk/v1/public/page/' . $slug, $query ); }

function rk_published_page( $slug = 'about', $h = 40 ) {
	$id = rk_test_page( 'draft', $slug );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( $h ), 0 ) );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ) );
	rk_test_login( 'anon' );
	return $id;
}

rk_test( 'publish returns the documented shape and flips post_status', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	$d = t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ) );
	t_eq( $d['ok'], true );
	t_eq( $d['pageId'], $id );
	t_eq( $d['revision'], 2 );
	t_eq( $d['status'], 'publish' );
	t_eq( $d['publishedRevision'], 2 );
	t_assert( isset( $d['publishedAt'], $d['updatedAt'], $d['link'] ) );
	t_eq( get_post( $id )->post_status, 'publish' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) )['revisions'][0]['kind'], 'publish' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['publishedRevision'], 2 );
} );

rk_test( 'publish: stale revision -> 409; subscriber/anonymous refused; wp_update_post failure -> 500 and nothing recorded', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_err( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 0 ) ), 'rk_revision_conflict', 409 );
	$GLOBALS['RK']['fail_update_post'] = true;
	t_err( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ), 'rk_server_error', 500 );
	t_eq( rk_builder_get_revision( $id ), 1 );
	t_assert( ! isset( $GLOBALS['RK']['meta'][ $id ]['_rk_layout_published'] ) );
	t_eq( count( $GLOBALS['RK']['http'] ), 0, 'no revalidation for a failed publish' );
} );

rk_test( 'public page serves the published snapshot and later draft saves do NOT change it', function () {
	$id = rk_published_page( 'about', 40 );
	$d  = t_ok( rk_public( 'about' ) );
	t_eq( $d['layout']['blocks'][0]['props']['h'], 40 );
	t_eq( $d['revision'], 2 );
	t_assert( ! isset( $d['preview'] ) );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 200 ), 2 ) );
	rk_test_login( 'anon' );
	t_eq( t_ok( rk_public( 'about' ) )['layout']['blocks'][0]['props']['h'], 40, 'draft edit leaked to the public page' );
	rk_test_login( 'editor' );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 3 ) ) );
	rk_test_login( 'anon' );
	t_eq( t_ok( rk_public( 'about' ) )['layout']['blocks'][0]['props']['h'], 200, 'republish updates the snapshot' );
} );

rk_test( 'public page response shape and cache headers', function () {
	rk_published_page( 'about', 40 );
	$res = rk_public( 'about' );
	$d   = t_ok( $res );
	t_eq( array_keys( $d ), array( 'page', 'layout', 'theme', 'revision' ) );
	foreach ( array( 'id', 'title', 'slug', 'description', 'modified', 'image' ) as $k ) { t_assert( array_key_exists( $k, $d['page'] ), $k ); }
	t_eq( $d['page']['image'], null );
	t_eq( $d['page']['slug'], 'about' );
	t_eq( $d['theme']['version'], 1 );
	t_eq( $res->get_headers()['Cache-Control'], 'public, max-age=0, s-maxage=60' );
} );

rk_test( 'draft / pending / private / password-protected / missing / trashed pages are all an identical 404', function () {
	rk_test_login( 'anon' );
	$msgs = array();
	foreach ( array( 'draft', 'pending', 'private', 'future', 'trash' ) as $st ) {
		$id = rk_test_page( $st, 'p-' . $st );
		$GLOBALS['RK']['meta'][ $id ]['_rk_layout_published'] = json_encode( rk_spacer_layout( 40 ) ); // even with a snapshot
		$e = rk_public( 'p-' . $st );
		t_err( $e, 'rk_not_found', 404, $st );
		$msgs[] = $e->get_error_message();
	}
	$id = rk_test_page( 'publish', 'secret', 2, array( 'post_password' => 'pw' ) );
	$GLOBALS['RK']['meta'][ $id ]['_rk_layout_published'] = json_encode( rk_spacer_layout( 40 ) );
	$e = rk_public( 'secret' ); t_err( $e, 'rk_not_found', 404 ); $msgs[] = $e->get_error_message();
	$e = rk_public( 'does-not-exist' ); t_err( $e, 'rk_not_found', 404 ); $msgs[] = $e->get_error_message();
	t_eq( count( array_unique( $msgs ) ), 1, 'error bodies must not reveal whether the page exists' );
	// publish status without a builder snapshot (e.g. published through wp-admin) is not served
	rk_test_page( 'publish', 'plain' );
	t_err( rk_public( 'plain' ), 'rk_not_found', 404 );
	t_err( rk_public( 'bad.slug' ), 'rest_no_route', 404, 'route regex rejects odd slugs' );
} );

rk_test( 'unpublish: page 404s publicly, snapshot kept, "unpublish" revision recorded, webhook sent', function () {
	$id = rk_published_page( 'about', 40 );
	$GLOBALS['RK']['http'] = array();
	rk_test_login( 'editor' );
	$d = t_ok( rk_post( '/rk/v1/builder/unpublish/' . $id ) );
	t_eq( $d['ok'], true );
	t_eq( $d['status'], 'draft' );
	t_eq( $d['revision'], 3 );
	t_eq( get_post( $id )->post_status, 'draft' );
	t_assert( isset( $GLOBALS['RK']['meta'][ $id ]['_rk_layout_published'] ), 'unpublish clears nothing else' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) )['revisions'][0]['kind'], 'unpublish' );
	rk_test_login( 'anon' );
	t_err( rk_public( 'about' ), 'rk_not_found', 404 );
	t_eq( count( $GLOBALS['RK']['http'] ), 1 );
	t_eq( json_decode( $GLOBALS['RK']['http'][0]['args']['body'], true )['type'], 'unpublish' );
	// idempotent on an already unpublished page: no new revision, no webhook
	rk_test_login( 'editor' );
	t_eq( t_ok( rk_post( '/rk/v1/builder/unpublish/' . $id ) )['revision'], 3 );
	t_eq( count( $GLOBALS['RK']['http'] ), 1 );
} );

rk_test( 'revalidation webhook: non-blocking, 3s, JSON {type,pageId,slug}, secret only in header', function () {
	$id = rk_test_page( 'draft', 'about' );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ) );
	$call = $GLOBALS['RK']['http'][0];
	t_eq( $call['url'], RK_BUILDER_REVALIDATE_URL );
	t_eq( $call['args']['blocking'], false );
	t_eq( $call['args']['timeout'], 3 );
	t_eq( $call['args']['headers']['X-RK-Revalidate-Secret'], RK_BUILDER_REVALIDATE_SECRET );
	t_eq( json_decode( $call['args']['body'], true ), array( 'type' => 'publish', 'pageId' => $id, 'slug' => 'about' ) );
	t_assert( false === strpos( $call['args']['body'], RK_BUILDER_REVALIDATE_SECRET ) && false === strpos( $call['url'], RK_BUILDER_REVALIDATE_SECRET ), 'secret must not be in body/url' );
	// filterable
	add_filter( 'rk_builder_revalidate_args', function ( $a ) { $a['timeout'] = 1; return $a; } );
	t_ok( rk_post( '/rk/v1/builder/unpublish/' . $id ) );
	t_eq( $GLOBALS['RK']['http'][1]['args']['timeout'], 1 );
} );

rk_test( 'theme POST triggers a "theme" revalidation', function () {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/theme-config', fx( 'valid/theme-full.json' )['document'] ) );
	t_eq( json_decode( $GLOBALS['RK']['http'][0]['args']['body'], true ), array( 'type' => 'theme', 'pageId' => 0, 'slug' => '' ) );
} );

rk_test( 'preview token endpoint: shape, ttl, requires edit access to that page', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$d = t_ok( rk_post( '/rk/v1/builder/preview-token/' . $id ) );
	$GLOBALS['RK_SECRETS'][] = $d['token'];
	t_assert( 1 === preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $d['token'] ), 'base64url(payload).base64url(sig)' );
	t_eq( strtotime( $d['expiresAt'] ), rk_builder_now() + 900, 'default 15 minutes' );
	t_err( rk_post( '/rk/v1/builder/preview-token/99999' ), 'rk_not_found', 404 );
	add_filter( 'rk_builder_preview_ttl', function () { return 60; } );
	t_eq( strtotime( t_ok( rk_post( '/rk/v1/builder/preview-token/' . $id ) )['expiresAt'] ), rk_builder_now() + 60 );
} );

rk_test( 'preview: valid token serves the DRAFT of an unpublished page with preview:true and no-store', function () {
	$id = rk_test_page( 'draft', 'wip' );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 96 ), 0 ) );
	$token = t_ok( rk_post( '/rk/v1/builder/preview-token/' . $id ) )['token'];
	$GLOBALS['RK_SECRETS'][] = $token;
	rk_test_login( 'anon' );
	t_err( rk_public( 'wip' ), 'rk_not_found', 404 );
	$res = rk_public( 'wip', array( 'preview' => $token ) );
	$d   = t_ok( $res );
	t_eq( $d['preview'], true );
	t_eq( $d['layout']['blocks'][0]['props']['h'], 96 );
	t_eq( $d['revision'], 1 );
	t_eq( $res->get_headers()['Cache-Control'], 'no-store' );
} );

rk_test( 'preview: on a published page the token shows the newer draft, plain requests still see the snapshot', function () {
	$id = rk_published_page( 'about', 40 );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 200 ), 2 ) );
	$token = t_ok( rk_post( '/rk/v1/builder/preview-token/' . $id ) )['token'];
	$GLOBALS['RK_SECRETS'][] = $token;
	rk_test_login( 'anon' );
	t_eq( t_ok( rk_public( 'about', array( 'preview' => $token ) ) )['layout']['blocks'][0]['props']['h'], 200 );
	t_eq( t_ok( rk_public( 'about' ) )['layout']['blocks'][0]['props']['h'], 40 );
} );

rk_test( 'preview: expired, tampered, malformed and wrong-page tokens do not unlock drafts', function () {
	$id    = rk_test_page( 'draft', 'wip' );
	$other = rk_test_page( 'draft', 'other' );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 96 ), 0 ) );
	$token = t_ok( rk_post( '/rk/v1/builder/preview-token/' . $id ) )['token'];
	$GLOBALS['RK_SECRETS'][] = $token;
	rk_test_login( 'anon' );
	t_ok( rk_public( 'wip', array( 'preview' => $token ) ) );

	// wrong page
	t_err( rk_public( 'other', array( 'preview' => $token ) ), 'rk_preview_invalid', 404 );

	// tampered signature / payload (re-sign with a different secret, flip bits, change page id)
	list( $p, $s ) = explode( '.', $token );
	$flip = ( 'A' === $s[0] ? 'B' : 'A' ) . substr( $s, 1 );
	t_assert( ! rk_builder_verify_preview_token( $p . '.' . $flip, $id ), 'bad signature' );
	$forged_payload = rk_builder_b64url_encode( json_encode( array( 'p' => $other, 'e' => time() + 9999 ) ) );
	t_assert( ! rk_builder_verify_preview_token( $forged_payload . '.' . $s, $other ), 'payload swapped, old signature' );
	$wrong_key = rk_builder_b64url_encode( hash_hmac( 'sha256', $p, 'some-other-secret', true ) );
	t_assert( ! rk_builder_verify_preview_token( $p . '.' . $wrong_key, $id ), 'signed with another key' );
	foreach ( array( '', 'x', '.', 'a.b.c', $p, $p . '.', '.' . $s, $p . '.' . $s . '=' ) as $junk ) {
		t_assert( ! rk_builder_verify_preview_token( $junk, $id ), 'junk token' );
		t_err( rk_public( 'wip', array( 'preview' => $junk ) ), 'rk_preview_invalid', 404 );
	}
	t_assert( ! rk_builder_verify_preview_token( str_repeat( 'a', 600 ), $id ), 'oversized token' );
	t_err( rk_get( '/rk/v1/public/page/wip', array( 'preview' => array( 'x' ) ) ), 'rest_invalid_param', 400 );

	// expired
	add_filter( 'rk_builder_now', function ( $t ) { return $t + 901; } );
	t_assert( ! rk_builder_verify_preview_token( $token, $id ), 'expired' );
	t_err( rk_public( 'wip', array( 'preview' => $token ) ), 'rk_preview_invalid', 404 );
} );

rk_test( 'preview tokens use RK_BUILDER_PREVIEW_SECRET (constant) and constant-time compare', function () {
	$t = rk_builder_create_preview_token( 7 )['token'];
	$GLOBALS['RK_SECRETS'][] = $t;
	list( $p, $s ) = explode( '.', $t );
	t_eq( $s, rk_builder_b64url_encode( hash_hmac( 'sha256', $p, RK_BUILDER_PREVIEW_SECRET, true ) ) );
	t_eq( json_decode( rk_builder_b64url_decode( $p ), true )['p'], 7 );
	$src = file_get_contents( dirname( __DIR__ ) . '/rk-builder/includes/preview.php' );
	t_assert( false !== strpos( $src, 'hash_equals(' ), 'uses hash_equals' );
} );

rk_test( 'description: excerpt, else first text/hero block, <=160 chars', function () {
	$long = str_repeat( 'word ', 80 );
	$layout = array( 'version' => 1, 'blocks' => array(
		array( 'id' => 'a', 'type' => 'spacer', 'props' => array( 'h' => 8 ) ),
		array( 'id' => 'b', 'type' => 'text', 'props' => array( 'text' => $long ) ),
	) );
	t_eq( rk_builder_describe( 'An excerpt', $layout ), 'An excerpt' );
	$d = rk_builder_describe( '', $layout );
	t_assert( mb_strlen( $d ) <= 160 && '…' === mb_substr( $d, -1 ), 'truncated with ellipsis' );
	$hero = array( 'version' => 1, 'blocks' => array( array( 'id' => 'h', 'type' => 'hero', 'props' => array( 'heading' => 'Head', 'sub' => 'Sub <b>line</b>', 'cta' => '', 'ctaHref' => '' ) ) ) );
	t_eq( rk_builder_describe( '', $hero ), 'Sub line' );
	t_eq( rk_builder_describe( '', rk_builder_empty_layout() ), '' );
} );

rk_test( 'public page includes the featured image URL when set', function () {
	$id = rk_published_page( 'about', 40 );
	$GLOBALS['RK']['attachments'][900] = array( 'url' => 'https://cms.example.com/wp-content/uploads/f.jpg', 'w' => 800, 'h' => 600, 'title' => 'f' );
	$GLOBALS['RK']['meta'][ $id ]['_thumbnail_id'] = '900';
	t_eq( t_ok( rk_public( 'about' ) )['page']['image'], 'https://cms.example.com/wp-content/uploads/f.jpg' );
} );
