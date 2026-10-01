<?php
/** Cache invalidation hooks (spec §16): firing, integrations, guards, settings. */

// Fake cache plugins: record calls, optionally throw. Defined globally (functions cannot be undefined),
// they only record, so they are harmless to every other test.
function wp_cache_post_change( $id ) { rk_fake_purge( 'wpsc', $id ); }
function w3tc_flush_post( $id ) { rk_fake_purge( 'w3tc', $id ); }
function rocket_clean_post( $id ) { rk_fake_purge( 'rocket', $id ); }
function clean_post_cache( $id ) { rk_fake_purge( 'core', $id ); }
function rk_fake_purge( $who, $id ) {
	$GLOBALS['RK']['purged'][] = $who . ':' . $id;
	if ( ! empty( $GLOBALS['RK']['purge_throw'] ) && $GLOBALS['RK']['purge_throw'] === $who ) { throw new RuntimeException( 'cache plugin exploded' ); }
}
function rk_purged() { return isset( $GLOBALS['RK']['purged'] ) ? $GLOBALS['RK']['purged'] : array(); }

function rk_cache_events() {
	$GLOBALS['RK']['events'] = array();
	// NB: the stub's do_action() threads each listener's return value into the next one, so these
	// observers run first (priority 5) and return their first argument, like a well-behaved filter.
	add_action( 'rk_builder_layout_changed', function ( $id, $reason ) { $GLOBALS['RK']['events'][] = 'layout:' . $id . ':' . $reason; return $id; }, 5, 2 );
	add_action( 'rk_builder_theme_changed', function ( $x = null ) { $GLOBALS['RK']['events'][] = 'theme'; return $x; }, 5 );
	add_action( 'rk_builder_public_cache_purge', function ( $id ) { $GLOBALS['RK']['events'][] = 'purge:' . $id; return $id; }, 5 );
}
function rk_events() { return isset( $GLOBALS['RK']['events'] ) ? $GLOBALS['RK']['events'] : array(); }

rk_test( 'cache: publish, unpublish and restore fire layout_changed and purge the page; a draft save of a published page does not', function () {
	rk_test_hooks();
	rk_cache_events();
	$id = rk_test_page( 'draft', 'about' );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	t_eq( rk_events(), array(), 'draft save: no public change' );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ) );
	t_eq( rk_events(), array( 'layout:' . $id . ':publish', 'purge:' . $id ) );
	t_eq( rk_purged(), array( 'core:' . $id, 'wpsc:' . $id, 'w3tc:' . $id, 'rocket:' . $id ) );
	$GLOBALS['RK']['events'] = $GLOBALS['RK']['purged'] = array();
	t_ok( rk_save( $id, rk_spacer_layout( 56 ), 2 ) );
	t_eq( rk_events(), array(), 'editing the draft of an already published page does not purge' );
	t_eq( rk_purged(), array() );
	t_ok( rk_post( '/rk/v1/builder/revisions/' . $id . '/1/restore', array( 'expectedRevision' => 3 ) ) );
	t_eq( rk_events(), array( 'layout:' . $id . ':restore', 'purge:' . $id ) );
	$GLOBALS['RK']['events'] = array();
	t_ok( rk_post( '/rk/v1/builder/unpublish/' . $id ) );
	t_eq( rk_events(), array( 'layout:' . $id . ':unpublish', 'purge:' . $id ) );
	$GLOBALS['RK']['events'] = array();
	t_ok( rk_post( '/rk/v1/builder/unpublish/' . $id ) );
	t_eq( rk_events(), array(), 'idempotent unpublish is not a change' );
	t_eq( count( $GLOBALS['RK']['http'] ), 2, 'the Node revalidation webhook still fires once per publish/unpublish' );
} );

rk_test( 'cache: theme changes (theme endpoint and admin layout save) fire theme_changed and purge every RK page', function () {
	rk_test_hooks();
	rk_cache_events();
	$a = rk_pub_page( rk_test_layout( array() ), 'a' );
	$b = rk_pub_page( rk_test_layout( array() ), 'b' );
	rk_test_page( 'draft', 'c' );
	$plain = rk_test_page( 'publish', 'plain' ); // published page without an RK snapshot
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/theme-config', fx( 'valid/theme-full.json' )['document'] ) );
	t_eq( rk_events()[0], 'theme' );
	t_eq( array_slice( rk_events(), 1 ), array( 'purge:' . $a, 'purge:' . $b, 'purge:0' ) );
	t_assert( ! in_array( 'core:' . $plain, rk_purged(), true ) );
	t_eq( count( $GLOBALS['RK']['http'] ), 1, 'one theme revalidation' );
	$GLOBALS['RK']['events'] = array();
	$id = rk_test_page();
	$theme = fx( 'valid/theme-minimal.json' )['document'];
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0, array( 'theme' => $theme ) ) );
	t_eq( rk_events()[0], 'theme', 'theme changed through a layout save' );
	$GLOBALS['RK']['events'] = array();
	t_ok( rk_save( $id, rk_spacer_layout( 48 ), 1, array( 'theme' => $theme ) ) );
	t_eq( rk_events(), array(), 'an unchanged theme is not a change' );
} );

rk_test( 'cache: service/portfolio/media changes purge all RK pages once per request (deferred) and ping the frontend', function () {
	rk_test_hooks();
	rk_cache_events();
	$a = rk_pub_page( rk_test_layout( array() ), 'a' );
	$sid = rk_make_cpt( 'service', 'Wiring', array() );
	$service = get_post( $sid );
	do_action( 'save_post_service', $sid, $service, true );
	do_action( 'save_post_service', $sid, $service, true );
	do_action( 'save_post_portfolio', $sid, $service, true );
	t_eq( rk_purged(), array(), 'nothing until shutdown' );
	do_action( 'shutdown' );
	t_eq( rk_purged(), array( 'core:' . $a, 'wpsc:' . $a, 'w3tc:' . $a, 'rocket:' . $a ), 'purged exactly once' );
	t_eq( json_decode( $GLOBALS['RK']['http'][0]['args']['body'], true ), array( 'type' => 'content', 'pageId' => 0, 'slug' => '' ) );
	t_eq( count( $GLOBALS['RK']['http'] ), 1 );
	// each trigger on its own
	$triggers = array(
		'deleted service'    => function () use ( $service ) { do_action( 'deleted_post', $service->ID, $service ); },
		'edit_attachment'    => function () { do_action( 'edit_attachment', 55 ); },
		'thumbnail changed'  => function () use ( $sid ) { do_action( 'updated_post_meta', 1, $sid, '_thumbnail_id', '5' ); },
		'alt text changed'   => function () { $GLOBALS['RK']['posts'][60] = (object) array( 'ID' => 60, 'post_type' => 'attachment' ); do_action( 'updated_post_meta', 1, 60, '_wp_attachment_image_alt', 'x' ); },
		'service category'   => function () use ( $sid ) { do_action( 'set_object_terms', $sid, array( 'a' ), array( 1 ), 'service_cat' ); },
	);
	foreach ( $triggers as $name => $fire ) {
		$GLOBALS['RK']['purged'] = array();
		$fire();
		do_action( 'shutdown' );
		t_eq( count( rk_purged() ), 4, $name );
	}
	// irrelevant changes do nothing
	$GLOBALS['RK']['purged'] = array();
	$post = (object) array( 'ID' => 70, 'post_type' => 'post' );
	do_action( 'deleted_post', 70, $post );
	do_action( 'save_post_service', 70, $post, true ); // (hook name is the contract; a revision is skipped below)
	do_action( 'updated_post_meta', 1, $sid, '_some_other_meta', 'x' );
	do_action( 'set_object_terms', $sid, array( 'a' ), array( 1 ), 'category' );
	rk_builder_pending_content_purge( false );
	do_action( 'shutdown' );
	t_eq( rk_purged(), array() );
} );

rk_test( 'cache: page trash/delete/status change of an RK page fires layout_changed; other pages do not', function () {
	rk_test_hooks();
	rk_cache_events();
	$a = rk_pub_page( rk_test_layout( array() ), 'a' );
	$plain = rk_test_page( 'publish', 'plain' );
	do_action( 'wp_trash_post', $a );
	do_action( 'before_delete_post', $a, get_post( $a ) );
	do_action( 'transition_post_status', 'draft', 'publish', get_post( $a ) );
	do_action( 'transition_post_status', 'publish', 'publish', get_post( $a ) );
	do_action( 'wp_trash_post', $plain );
	do_action( 'transition_post_status', 'draft', 'publish', get_post( $plain ) );
	t_eq( array_values( array_filter( rk_events(), function ( $e ) { return 0 === strpos( $e, 'layout:' ); } ) ), array( 'layout:' . $a . ':delete', 'layout:' . $a . ':delete', 'layout:' . $a . ':status' ) );
	$GLOBALS['RK']['events'] = array();
	$GLOBALS['RK']['meta'][ $a ]['_thumbnail_id'] = '5';
	do_action( 'updated_post_meta', 1, $a, '_thumbnail_id', '6' );
	t_eq( rk_events()[0], 'layout:' . $a . ':content' );
} );

rk_test( 'cache: setting cache_purge=false skips third-party purges but still runs core + the host hook', function () {
	rk_test_hooks();
	rk_cache_events();
	update_option( 'rk_builder_settings', array( 'cache_purge' => false ) );
	$ls = 0;
	add_action( 'litespeed_purge_post', function () use ( &$ls ) { $ls++; } );
	rk_builder_purge_page_cache( 42 );
	t_eq( rk_purged(), array( 'core:42' ) );
	t_eq( $ls, 0 );
	t_eq( rk_events(), array( 'purge:42' ) );
	update_option( 'rk_builder_settings', array( 'cache_purge' => true ) );
	$GLOBALS['RK']['purged'] = array();
	rk_builder_purge_page_cache( 42 );
	t_eq( rk_purged(), array( 'core:42', 'wpsc:42', 'w3tc:42', 'rocket:42' ) );
	t_eq( $ls, 1, 'LiteSpeed purge action fired' );
} );

rk_test( 'cache: a throwing cache integration (or listener) never breaks a publish, other integrations still run, and nothing sensitive is logged', function () {
	rk_test_hooks();
	rk_test_log_start();
	add_action( 'rk_builder_layout_changed', function () { throw new RuntimeException( 'listener exploded' ); } );
	$GLOBALS['RK']['purge_throw'] = 'wpsc';
	add_action( 'rk_builder_public_cache_purge', function () { throw new RuntimeException( 'host hook exploded' ); } );
	$id = rk_test_page( 'draft', 'about' );
	rk_test_login( 'editor' );
	t_ok( rk_save( $id, rk_spacer_layout( 40 ), 0 ) );
	$d = t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => 1 ) ) );
	t_eq( $d['status'], 'publish' );
	t_eq( get_post( $id )->post_status, 'publish' );
	// the throwing listener was added after the core one, so core still purged; now exercise the integration throw directly
	rk_builder_purge_page_cache( $id );
	t_assert( in_array( 'w3tc:' . $id, rk_purged(), true ) && in_array( 'rocket:' . $id, rk_purged(), true ), 'integrations after the failing one still ran' );
	rk_builder_theme_changed();
	rk_builder_content_changed();
	rk_builder_flush_content_purge();
	$log = rk_test_log_read();
	t_assert( false !== strpos( $log, 'cache_purge_failed' ) && false !== strpos( $log, 'wp-super-cache' ), $log );
	t_assert( false !== strpos( $log, 'hook_failed' ) );
	$GLOBALS['RK']['purge_throw'] = false;
} );

rk_test( 'cache: manual purge-all returns the number of RK pages and signals hosts with page 0', function () {
	rk_test_hooks();
	rk_cache_events();
	$a = rk_pub_page( rk_test_layout( array() ), 'a' );
	$b = rk_pub_page( rk_test_layout( array() ), 'b' );
	rk_test_page( 'draft', 'c' );
	t_eq( rk_builder_purge_all_public_cache(), 2 );
	t_eq( rk_events(), array( 'purge:' . $a, 'purge:' . $b, 'purge:0' ) );
	t_eq( rk_builder_published_page_ids(), array( $a, $b ) );
} );
