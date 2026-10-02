<?php
/** Site export/import: bundle validation, media remapping, permissions, dry run. */

function rk_bundle( array $over = array() ) {
	return array_merge( array(
		'format'  => 'rk-builder-site',
		'version' => 1,
		'source'  => array( 'url' => 'https://old.example.com/' ),
		'theme'   => null,
		'media'   => array( array( 'id' => 77, 'url' => 'https://old.example.com/wp-content/uploads/a.jpg', 'alt' => 'A', 'title' => 'A' ) ),
		'pages'   => array(
			array( 'slug' => 'home', 'title' => 'Home', 'layout' => array( 'version' => 1, 'blocks' => array(
				array( 'id' => 'h', 'type' => 'hero', 'props' => array( 'heading' => 'Hi', 'sub' => '', 'cta' => '', 'ctaHref' => '', 'bgMediaId' => 77, 'bgUrl' => 'https://old.example.com/wp-content/uploads/a.jpg' ) ),
				array( 'id' => 'i', 'type' => 'image', 'props' => array( 'mediaId' => 77, 'url' => 'https://old.example.com/wp-content/uploads/a.jpg', 'alt' => 'A', 'decorative' => false, 'srcset' => 'x 1w' ) ),
			) ) ),
			array( 'slug' => 'bad', 'title' => 'Bad', 'layout' => array( 'version' => 1, 'blocks' => array( array( 'id' => 'x', 'type' => 'nope', 'props' => array() ) ) ) ),
		),
		'content' => array(),
	), $over );
}

rk_test( 'bundle shape: wrong format, version and non-arrays are fatal', function () {
	t_assert( count( rk_builder_bundle_check_shape( rk_bundle( array( 'format' => 'other' ) ) ) ) >= 1 );
	t_assert( count( rk_builder_bundle_check_shape( rk_bundle( array( 'version' => 2 ) ) ) ) >= 1 );
	t_assert( count( rk_builder_bundle_check_shape( rk_bundle( array( 'pages' => 'x' ) ) ) ) >= 1 );
	t_assert( count( rk_builder_bundle_check_shape( 'x' ) ) >= 1 );
	t_eq( rk_builder_bundle_check_shape( rk_bundle() ), array() );
} );

rk_test( 'bundle shape: item limits are enforced', function () {
	$many = array_fill( 0, RK_BUILDER_MAX_TRANSFER_MEDIA + 1, array( 'url' => 'https://a.example/x.jpg' ) );
	t_assert( count( rk_builder_bundle_check_shape( rk_bundle( array( 'media' => $many ) ) ) ) >= 1 );
} );

rk_test( 'media refs are collected from images, hero backgrounds and the theme logo', function () {
	$b    = rk_bundle();
	$refs = rk_builder_bundle_media_refs( $b['pages'][0]['layout'], array( 'logoMediaId' => 5, 'logoUrl' => 'https://old.example.com/l.png' ) );
	t_eq( count( $refs ), 3 );
	t_eq( $refs[0]['id'], 77 );
	t_eq( $refs[2]['id'], 5 );
} );

rk_test( 'remap rewrites ids and urls to the local copies and drops stale srcset', function () {
	$b    = rk_bundle();
	$maps = array( 'id' => array( 77 => array( 'id' => 900, 'url' => 'https://new.example.com/u/a.jpg', 'width' => 800, 'height' => 500 ) ), 'url' => array() );
	$out  = rk_builder_bundle_remap_layout( $b['pages'][0]['layout'], $maps );
	t_eq( $out['blocks'][0]['props']['bgMediaId'], 900 );
	t_eq( $out['blocks'][0]['props']['bgUrl'], 'https://new.example.com/u/a.jpg' );
	t_eq( $out['blocks'][1]['props']['mediaId'], 900 );
	t_eq( $out['blocks'][1]['props']['width'], 800 );
	t_assert( ! isset( $out['blocks'][1]['props']['srcset'] ), 'srcset must be dropped' );
} );

rk_test( 'remap by url when the id is unknown; an unmapped attachment id is REMOVED (it belongs to another site)', function () {
	$b    = rk_bundle();
	$maps = array( 'id' => array(), 'url' => array( 'https://old.example.com/wp-content/uploads/a.jpg' => array( 'id' => 31, 'url' => 'https://new.example.com/b.jpg' ) ) );
	$out  = rk_builder_bundle_remap_layout( $b['pages'][0]['layout'], $maps );
	t_eq( $out['blocks'][1]['props']['mediaId'], 31 );
	$none = rk_builder_bundle_remap_layout( $b['pages'][0]['layout'], array( 'id' => array(), 'url' => array() ) );
	t_assert( ! isset( $none['blocks'][0]['props']['bgMediaId'] ) && ! isset( $none['blocks'][1]['props']['mediaId'] ), 'foreign attachment ids must not survive' );
	t_eq( $none['blocks'][1]['props']['url'], 'https://old.example.com/wp-content/uploads/a.jpg' );
	$theme = rk_builder_bundle_remap_theme( array( 'primary' => '#000000', 'logoMediaId' => 5, 'logoUrl' => 'https://old.example.com/l.png' ), array( 'id' => array(), 'url' => array() ) );
	t_assert( ! isset( $theme['logoMediaId'] ) );
} );

rk_test( 'bundle hosts include the source site and media hosts', function () {
	$hosts = rk_builder_bundle_hosts( rk_bundle() );
	t_assert( in_array( 'old.example.com', $hosts, true ) );
} );

rk_test( 'media entries: invalid urls are reported, duplicates collapsed', function () {
	$bad = array();
	$out = rk_builder_bundle_media_entries( array( 'media' => array(
		array( 'id' => 1, 'url' => 'https://a.example/x.jpg' ),
		array( 'id' => 2, 'url' => 'https://a.example/x.jpg' ),
		array( 'url' => 'javascript:alert(1)' ),
		array( 'url' => 'ftp://a.example/x.jpg' ),
		'junk',
	) ), $bad );
	t_eq( count( $out ), 1 );
	t_eq( count( $bad ), 3 );
} );

rk_test( 'site export/import need an administrator', function () {
	t_err( rk_get( '/rk/v1/builder/site-export' ), 'rk_unauthorized', 401 );
	t_err( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => rk_bundle() ) ), 'rk_unauthorized', 401 );
	rk_test_login( 'editor' );
	t_err( rk_get( '/rk/v1/builder/site-export' ), 'rk_forbidden', 403 );
	t_err( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => rk_bundle() ) ), 'rk_forbidden', 403 );
} );

rk_test( 'import rejects malformed requests with rk_invalid_bundle', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/site-import', array() ), 'rk_invalid_bundle', 400 );
	t_err( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => rk_bundle( array( 'format' => 'x' ) ) ) ), 'rk_invalid_bundle', 400 );
	t_err( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => rk_bundle(), 'surprise' => 1 ) ), 'rk_invalid_bundle', 400 );
	t_err( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => rk_bundle(), 'options' => array( 'dryRun' => 'yes' ) ) ), 'rk_invalid_bundle', 400 );
} );

rk_test( 'import defaults to a dry run: nothing is written, bad pages are reported', function () {
	rk_test_login( 'admin' );
	$before = count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 50 ) ) );
	$r = t_ok( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => rk_bundle() ) ) );
	t_eq( $r['dryRun'], true );
	t_eq( $r['pages']['create'], 1 );
	t_eq( count( $r['pages']['skipped'] ), 1 );
	t_eq( $r['pages']['skipped'][0]['slug'], 'bad' );
	t_eq( $r['media']['total'], 1 );
	t_eq( count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 50 ) ) ), $before, 'a dry run must not create pages' );
} );
