<?php
/** Authentication / permission boundaries (cookie+nonce and app passwords are core's job; we enforce caps). */

$rk_builder_routes = function ( $id ) {
	return array(
		array( 'GET', '/rk/v1/builder/pages' ),
		array( 'GET', '/rk/v1/builder/layout/' . $id ),
		array( 'POST', '/rk/v1/builder/layout/' . $id, array( 'layout' => rk_spacer_layout( 40 ), 'expectedRevision' => 0, 'status' => 'draft' ) ),
		array( 'GET', '/rk/v1/builder/revisions/' . $id ),
		array( 'GET', '/rk/v1/builder/revisions/' . $id . '/1' ),
		array( 'POST', '/rk/v1/builder/revisions/' . $id . '/1/restore', array( 'expectedRevision' => 0 ) ),
		array( 'POST', '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 0 ) ),
		array( 'POST', '/rk/v1/builder/unpublish/' . $id ),
		array( 'POST', '/rk/v1/builder/preview-token/' . $id ),
		array( 'GET', '/rk/v1/builder/media' ),
		array( 'POST', '/rk/v1/theme-config', fx( 'valid/theme-minimal.json' )['document'] ),
	);
};

rk_test( 'anonymous gets rk_unauthorized 401 on every authenticated route', function () use ( $rk_builder_routes ) {
	$id = rk_test_page();
	foreach ( $rk_builder_routes( $id ) as $r ) {
		$res = rk_test_request( $r[0], $r[1], isset( $r[2] ) ? array( 'body' => $r[2] ) : array() );
		t_err( $res, 'rk_unauthorized', 401, $r[0] . ' ' . $r[1] );
	}
} );

rk_test( 'subscriber gets rk_forbidden 403 on every authenticated route (not core rest_forbidden)', function () use ( $rk_builder_routes ) {
	$id = rk_test_page();
	rk_test_login( 'subscriber' );
	foreach ( $rk_builder_routes( $id ) as $r ) {
		$res = rk_test_request( $r[0], $r[1], isset( $r[2] ) ? array( 'body' => $r[2] ) : array() );
		t_err( $res, 'rk_forbidden', 403, $r[0] . ' ' . $r[1] );
	}
} );

rk_test( 'author (no edit_pages) cannot use the page builder but can list media', function () {
	$id = rk_test_page( 'draft', 'a', 4 );
	rk_test_login( 'author' );
	t_err( rk_get( '/rk/v1/builder/layout/' . $id ), 'rk_forbidden', 403 );
	t_err( rk_get( '/rk/v1/builder/pages' ), 'rk_forbidden', 403 );
	t_ok( rk_get( '/rk/v1/builder/media' ) );
} );

rk_test( 'editor can load and save; admin can too', function () {
	$id = rk_test_page();
	foreach ( array( 'editor', 'admin' ) as $who ) {
		rk_test_login( $who );
		$cur = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
		t_ok( rk_save( $id, rk_spacer_layout( 40 ), $cur['revision'] ), $who );
	}
} );

rk_test( 'unknown id and non-page posts give 404 rk_not_found', function () {
	rk_test_login( 'editor' );
	t_err( rk_get( '/rk/v1/builder/layout/99999' ), 'rk_not_found', 404 );
	$GLOBALS['RK']['posts'][500] = (object) array( 'ID' => 500, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 2 );
	t_err( rk_get( '/rk/v1/builder/layout/500' ), 'rk_not_found', 404 );
	$trash = rk_test_page( 'trash', 'gone' );
	t_err( rk_get( '/rk/v1/builder/layout/' . $trash ), 'rk_not_found', 404 );
} );

rk_test( 'GET layout reports capabilities for the current user', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['capabilities'], array( 'manageTheme' => false, 'publish' => true ) );
	rk_test_login( 'admin' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['capabilities'], array( 'manageTheme' => true, 'publish' => true ) );
} );

rk_test( 'authenticated responses are no-store', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_eq( rk_get( '/rk/v1/builder/layout/' . $id )->get_headers()['Cache-Control'], 'no-store' );
	t_eq( rk_get( '/rk/v1/builder/pages' )->get_headers()['Cache-Control'], 'no-store' );
} );

rk_test( 'page list: shape, drafts/private included, status filter, per_page cap, search', function () {
	$a = rk_test_page( 'draft', 'one' );
	$b = rk_test_page( 'publish', 'two' );
	$c = rk_test_page( 'private', 'three' );
	rk_test_page( 'trash', 'gone' );
	rk_test_login( 'editor' );
	$d = t_ok( rk_get( '/rk/v1/builder/pages' ) );
	t_eq( $d['total'], 3 );
	t_eq( count( $d['pages'] ), 3 );
	$p = $d['pages'][0];
	foreach ( array( 'id', 'title', 'slug', 'status', 'modified', 'revision', 'publishedRevision' ) as $k ) { t_assert( array_key_exists( $k, $p ), 'missing ' . $k ); }
	t_eq( $p['revision'], 0 );
	t_eq( $p['publishedRevision'], null );
	t_assert( 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $p['modified'] ), 'ISO8601 UTC' );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/pages', array( 'status' => 'private' ) ) )['pages'] ), 1 );
	t_err( rk_get( '/rk/v1/builder/pages', array( 'per_page' => '101' ) ), 'rest_invalid_param', 400 );
	t_err( rk_get( '/rk/v1/builder/pages', array( 'status' => 'trash' ) ), 'rest_invalid_param', 400 );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/pages', array( 'per_page' => '2' ) ) )['pages'] ), 2 );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/pages', array( 'search' => 'tw' ) ) )['pages'] ), 1 );
} );

rk_test( 'cross-origin spoofing: handlers never read an Origin header or trust a user id param', function () {
	$id = rk_test_page();
	// Passing a "user" param does nothing: identity comes only from core auth.
	$res = rk_test_request( 'GET', '/rk/v1/builder/layout/' . $id, array( 'query' => array( 'user' => '1', 'current_user' => '1' ) ) );
	t_err( $res, 'rk_unauthorized', 401 );
} );
