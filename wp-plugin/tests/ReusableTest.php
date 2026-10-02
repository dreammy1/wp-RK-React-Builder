<?php
/** Reusable blocks: library CRUD, strict validation, permissions, linked rendering, usage guard. */

function rk_cta_block( $heading = 'Call us' ) {
	return array( 'type' => 'cta', 'props' => array( 'heading' => $heading, 'cta' => 'Go', 'ctaHref' => '/contact' ) );
}
function rk_make_reusable( $name = 'Footer CTA', $block = null ) {
	rk_test_login( 'editor' );
	$d = t_ok( rk_post( '/rk/v1/builder/reusables', array( 'name' => $name, 'block' => $block ?: rk_cta_block() ) ) );
	return (int) $d['item']['id'];
}
function rk_reusable_layout( $id ) {
	return array( 'version' => 1, 'blocks' => array( array( 'id' => 'r1', 'type' => 'reusable', 'props' => array( 'refId' => $id ) ) ) );
}

rk_test( 'reusable: create, list and read back the canonical block', function () {
	$id = rk_make_reusable();
	$l  = t_ok( rk_get( '/rk/v1/builder/reusables' ) );
	t_eq( count( $l['items'] ), 1 );
	t_eq( $l['items'][0]['id'], $id );
	t_eq( $l['items'][0]['name'], 'Footer CTA' );
	t_eq( $l['items'][0]['block'], rk_cta_block() );
} );

rk_test( 'reusable: strict validation (unknown prop, nested reusable, bad type, missing name)', function () {
	rk_test_login( 'editor' );
	$bad = rk_cta_block();
	$bad['props']['evil'] = 1;
	t_err( rk_post( '/rk/v1/builder/reusables', array( 'name' => 'x', 'block' => $bad ) ), 'rk_invalid_reusable', 400 );
	t_err( rk_post( '/rk/v1/builder/reusables', array( 'name' => 'x', 'block' => array( 'type' => 'reusable', 'props' => array( 'refId' => 1 ) ) ) ), 'rk_invalid_reusable', 400 );
	t_err( rk_post( '/rk/v1/builder/reusables', array( 'name' => 'x', 'block' => array( 'type' => 'nope', 'props' => array() ) ) ), 'rk_invalid_reusable', 400 );
	t_err( rk_post( '/rk/v1/builder/reusables', array( 'block' => rk_cta_block() ) ), 'rk_invalid_reusable', 400 );
	t_err( rk_post( '/rk/v1/builder/reusables', array( 'name' => str_repeat( 'a', 81 ), 'block' => rk_cta_block() ) ), 'rk_invalid_reusable', 400 );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/reusables' ) )['items'] ), 0, 'nothing is stored on failure' );
} );

rk_test( 'reusable: permissions (anon 401, subscriber 403, author may read-not-write, editor may write)', function () {
	t_err( rk_get( '/rk/v1/builder/reusables' ), 'rk_unauthorized', 401 );
	rk_test_login( 'subscriber' );
	t_err( rk_get( '/rk/v1/builder/reusables' ), 'rk_forbidden', 403 );
	rk_test_login( 'author' );
	t_err( rk_post( '/rk/v1/builder/reusables', array( 'name' => 'x', 'block' => rk_cta_block() ) ), 'rk_forbidden', 403 );
	$id = rk_make_reusable();
	t_assert( $id > 0 );
} );

rk_test( 'reusable: update changes the block for every page that references it (linked)', function () {
	$id = rk_make_reusable();
	$page = rk_test_page( 'publish', 'home' );
	rk_test_login( 'editor' );
	t_ok( rk_save( $page, rk_reusable_layout( $id ), 0 ) );
	$u = t_ok( rk_post( '/rk/v1/builder/reusables/' . $id, array( 'block' => rk_cta_block( 'New heading' ) ) ) );
	t_eq( $u['item']['block']['props']['heading'], 'New heading' );
	$html = rk_builder_render_layout( rk_reusable_layout( $id ) );
	t_assert( false !== strpos( $html, 'New heading' ), 'renders the updated content' );
	t_assert( false !== strpos( $html, 'rk-block-cta' ), 'renders the referenced block type' );
	t_eq( rk_builder_render_layout( rk_reusable_layout( 99999 ) ), '', 'a missing reference renders nothing' );
} );

rk_test( 'reusable: rename only; unknown id is 404', function () {
	$id = rk_make_reusable();
	$u  = t_ok( rk_post( '/rk/v1/builder/reusables/' . $id, array( 'name' => 'Renamed' ) ) );
	t_eq( $u['item']['name'], 'Renamed' );
	t_eq( $u['item']['block']['props']['heading'], 'Call us' );
	t_err( rk_post( '/rk/v1/builder/reusables/9999', array( 'name' => 'x' ) ), 'rk_not_found', 404 );
} );

rk_test( 'reusable: delete is refused while a page uses it, allowed once detached', function () {
	$id   = rk_make_reusable();
	$page = rk_test_page( 'draft', 'about' );
	rk_test_login( 'editor' );
	$s = t_ok( rk_save( $page, rk_reusable_layout( $id ), 0 ) );
	$d = t_err( rk_post( '/rk/v1/builder/reusables/' . $id . '/delete', array() ), 'rk_reusable_in_use', 409 );
	t_eq( $d['pages'], array( $page ) );
	t_ok( rk_save( $page, rk_spacer_layout( 40 ), $s['revision'] ) );
	t_ok( rk_post( '/rk/v1/builder/reusables/' . $id . '/delete', array() ) );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/reusables' ) )['items'] ), 0 );
} );

rk_test( 'reusable: layouts accept a reusable block with a positive integer refId only', function () {
	t_eq( rk_builder_validate_layout( rk_reusable_layout( 5 ) ), array() );
	foreach ( array( 0, -1, '5', 1.5, null ) as $bad ) {
		t_assert( count( rk_builder_validate_layout( rk_reusable_layout( $bad ) ) ) >= 1, 'should reject ' . json_encode( $bad ) );
	}
	$extra = rk_reusable_layout( 5 );
	$extra['blocks'][0]['props']['name'] = 'x';
	t_assert( count( rk_builder_validate_layout( $extra ) ) >= 1, 'no extra props' );
} );

rk_test( 'reusable: public page payload carries the resolved reusables', function () {
	$id   = rk_make_reusable();
	$page = rk_test_page( 'publish', 'home' );
	rk_test_login( 'editor' );
	$s = t_ok( rk_save( $page, rk_reusable_layout( $id ), 0 ) );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $page, array( 'expectedRevision' => $s['revision'] ) ) );
	rk_test_login( 'anon' );
	$d = t_ok( rk_get( '/rk/v1/public/page/home' ) );
	t_eq( $d['reusables'][ (string) $id ]['block']['type'], 'cta' );
} );
