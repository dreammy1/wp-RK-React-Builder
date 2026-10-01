<?php
/** Revision history: list, get, restore, monotonic numbers, retention. */

rk_test( 'every save appends a revision; list is newest first with the documented shape', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_ok( rk_save( $id, rk_spacer_layout( 48 ), 1 ) );
	$d = t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) );
	t_eq( $d['retained'], 2 );
	t_eq( array_column( $d['revisions'], 'id' ), array( 2, 1 ) );
	$r = $d['revisions'][0];
	t_eq( array_keys( $r ), array( 'id', 'kind', 'savedAt', 'author', 'blocks' ) );
	t_eq( $r['kind'], 'draft' );
	t_eq( $r['author'], 'Ed Editor' );
	t_eq( $r['blocks'], 1 );
	t_assert( ! isset( $r['layout'] ), 'list must not carry layouts' );
} );

rk_test( 'GET a single revision returns summary + layout', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_ok( rk_save( $id, rk_spacer_layout( 48 ), 1 ) );
	$d = t_ok( rk_get( '/rk/v1/builder/revisions/' . $id . '/1' ) );
	t_eq( $d['revision']['id'], 1 );
	t_eq( $d['layout']['blocks'][0]['props']['h'], 40 );
	t_err( rk_get( '/rk/v1/builder/revisions/' . $id . '/99' ), 'rk_not_found', 404 );
} );

rk_test( 'restore creates a NEW revision (kind restore), keeps history, equals the old layout', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_ok( rk_save( $id, rk_spacer_layout( 48 ), 1 ) );
	$s = t_ok( rk_post( '/rk/v1/builder/revisions/' . $id . '/1/restore', array( 'expectedRevision' => 2 ) ) );
	t_eq( $s['ok'], true );
	t_eq( $s['revision'], 3 );
	t_eq( $s['pageId'], $id );
	$cur = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	t_eq( $cur['revision'], 3 );
	t_eq( $cur['layout']['blocks'][0]['props']['h'], 40 );
	$list = t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) );
	t_eq( array_column( $list['revisions'], 'id' ), array( 3, 2, 1 ), 'nothing deleted' );
	t_eq( $list['revisions'][0]['kind'], 'restore' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/revisions/' . $id . '/2' ) )['layout']['blocks'][0]['props']['h'], 48 );
} );

rk_test( 'restore: stale expectedRevision -> 409, unknown revision -> 404, missing body -> 400', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	$d = t_err( rk_post( '/rk/v1/builder/revisions/' . $id . '/1/restore', array( 'expectedRevision' => 0 ) ), 'rk_revision_conflict', 409 );
	t_eq( $d['currentRevision'], 1 );
	t_err( rk_post( '/rk/v1/builder/revisions/' . $id . '/77/restore', array( 'expectedRevision' => 1 ) ), 'rk_not_found', 404 );
	t_err( rk_post( '/rk/v1/builder/revisions/' . $id . '/1/restore', array() ), 'rk_invalid_layout', 400 );
	t_eq( rk_builder_get_revision( $id ), 1 );
} );

rk_test( 'retention keeps only the last 20 (filterable), numbers keep increasing', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	for ( $i = 0; $i < 25; $i++ ) { t_ok( rk_save( $id, rk_spacer_layout( 8 + $i ), $i ) ); }
	$list = t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) );
	t_eq( $list['retained'], 20 );
	t_eq( $list['revisions'][0]['id'], 25 );
	t_eq( $list['revisions'][19]['id'], 6 );
	t_err( rk_get( '/rk/v1/builder/revisions/' . $id . '/5' ), 'rk_not_found', 404, 'pruned' );
	t_eq( rk_builder_get_revision( $id ), 25 );
	add_filter( 'rk_builder_max_revisions', function () { return 3; } );
	t_ok( rk_save( $id, rk_spacer_layout( 100 ), 25 ) );
	$list = t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) );
	t_eq( array_column( $list['revisions'], 'id' ), array( 26, 25, 24 ) );
} );

rk_test( 'restoring a revision that is the 20th-oldest still works and numbering stays monotonic', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	for ( $i = 0; $i < 21; $i++ ) { t_ok( rk_save( $id, rk_spacer_layout( 8 + $i ), $i ) ); }
	t_eq( t_ok( rk_post( '/rk/v1/builder/revisions/' . $id . '/2/restore', array( 'expectedRevision' => 21 ) ) )['revision'], 22 );
	t_eq( t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['layout']['blocks'][0]['props']['h'], 9 );
} );

rk_test( 'revision endpoints are permission checked per page', function () {
	$id = rk_test_page( 'draft', 'x', 2 );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	rk_test_login( 'subscriber' );
	t_err( rk_get( '/rk/v1/builder/revisions/' . $id . '/1' ), 'rk_forbidden', 403 );
	rk_test_login( 'anon' );
	t_err( rk_post( '/rk/v1/builder/revisions/' . $id . '/1/restore', array( 'expectedRevision' => 1 ) ), 'rk_unauthorized', 401 );
} );

rk_test( 'corrupt stored meta never fatals: draft falls back to empty, bad revision records are skipped', function () {
	$id = rk_test_page();
	rk_test_login( 'editor' );
	$GLOBALS['RK']['meta'][ $id ]['_rk_layout_draft'] = '{broken json';
	$GLOBALS['RK']['meta'][ $id ]['_rk_revisions']    = '[{"id":"x"},42,{"id":1,"kind":"draft","savedAt":"2026-01-01T00:00:00Z","author_id":2,"layout":{"version":1,"blocks":[]}}]';
	$GLOBALS['RK']['meta'][ $id ]['_rk_revision']     = '1';
	$d = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	t_eq( $d['layout'], array( 'version' => 1, 'blocks' => array() ) );
	t_eq( t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) )['retained'], 1 );
	// publishing a corrupt draft is refused with 400, not published as garbage
	t_err( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ), 'rk_invalid_layout', 400 );
	// and saving over it recovers the page
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 1 ) );
} );
