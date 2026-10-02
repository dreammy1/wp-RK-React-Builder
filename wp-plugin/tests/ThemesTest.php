<?php
/** Theme engine: capture the site as a package, library, install in one call, export and import files. */

function rk_theme_site() {
	rk_test_login( 'admin' );
	$b = rk_bundle( array(
		'media' => array(),
		'pages' => array(
			array( 'slug' => 'home', 'title' => 'Home', 'wasPublished' => true, 'layout' => array( 'version' => 1, 'blocks' => array( array( 'id' => 'h', 'type' => 'hero', 'props' => array( 'heading' => 'Hi', 'sub' => '', 'cta' => '', 'ctaHref' => '' ) ) ) ), 'seo' => array( 'title' => 'Welcome', 'description' => 'Hello there' ) ),
			array( 'slug' => 'about', 'title' => 'About', 'wasPublished' => true, 'layout' => array( 'version' => 1, 'blocks' => array( array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => 'About us' ) ) ) ) ),
		),
	) );
	t_ok( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => $b, 'options' => array( 'dryRun' => false ) ) ) );
}

rk_test( 'themes: every route needs an administrator', function () {
	foreach ( array( array( 'GET', '/rk/v1/builder/themes' ), array( 'POST', '/rk/v1/builder/themes' ), array( 'POST', '/rk/v1/builder/themes/install' ), array( 'GET', '/rk/v1/builder/themes/export' ), array( 'POST', '/rk/v1/builder/themes/delete' ), array( 'POST', '/rk/v1/builder/themes/import' ) ) as $r ) {
		t_err( 'GET' === $r[0] ? rk_get( $r[1] ) : rk_post( $r[1], array() ), 'rk_unauthorized', 401 );
	}
	rk_test_login( 'editor' );
	t_err( rk_get( '/rk/v1/builder/themes' ), 'rk_forbidden', 403 );
} );

rk_test( 'themes: saving needs a name and at least one builder page', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/themes', array() ), 'rk_invalid_theme', 400 );
	t_err( rk_post( '/rk/v1/builder/themes', array( 'name' => 'X', 'surprise' => 1 ) ), 'rk_invalid_theme', 400 );
	t_err( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Empty' ) ), 'rk_empty', 409 );
} );

rk_test( 'themes: capture the site, list it, export the package file, delete it', function () {
	rk_theme_site();
	$r = t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Peoria <b>Floors</b>', 'description' => 'A flooring site', 'version' => '2.1.0', 'author' => 'Rakib' ) ) );
	t_eq( $r['theme']['slug'], 'peoria-floors' );
	t_eq( $r['theme']['name'], 'Peoria Floors' );
	t_eq( $r['theme']['version'], '2.1.0' );
	t_eq( $r['theme']['pages'], 2 );
	$list = t_ok( rk_get( '/rk/v1/builder/themes' ) );
	t_eq( count( $list['items'] ), 1 );
	$file = t_ok( rk_get( '/rk/v1/builder/themes/export', array( 'slug' => 'peoria-floors' ) ) );
	t_eq( $file['format'], 'rk-builder-site' );
	t_eq( $file['themeMeta']['name'], 'Peoria Floors' );
	t_eq( count( $file['pages'] ), 2 );
	t_eq( $file['pages'][0]['seo']['title'], 'Welcome', 'a page keeps its SEO fields in the package' );
	t_eq( $file['pages'][1]['seo'], array(), 'a page without SEO exports an empty object' );
	t_err( rk_get( '/rk/v1/builder/themes/export', array( 'slug' => 'nope' ) ), 'rk_not_found', 404 );
	t_eq( t_ok( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'peoria-floors' ) ) )['deleted'], 'peoria-floors' );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'] ), 0 );
	t_err( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'peoria-floors' ) ), 'rk_not_found', 404 );
} );

rk_test( 'themes: saving again with the same name replaces the package', function () {
	rk_theme_site();
	t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Same' ) ) );
	$r = t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Same', 'version' => '1.1.0' ) ) );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'] ), 1 );
	t_eq( $r['theme']['version'], '1.1.0' );
} );

rk_test( 'themes: import a package file into the library, then install it in one call', function () {
	rk_theme_site();
	t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Source' ) ) );
	$file = t_ok( rk_get( '/rk/v1/builder/themes/export', array( 'slug' => 'source' ) ) );
	t_ok( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'source' ) ) );
	// wipe the pages so the install has to create them
	foreach ( array( 'home', 'about' ) as $s ) { $id = rk_builder_find_page_by_slug( $s ); if ( $id ) { wp_delete_post( $id, true ); } }

	t_err( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => array( 'format' => 'x' ) ) ), 'rk_invalid_theme', 400 );
	$added = t_ok( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => $file, 'name' => 'Imported One' ) ) );
	t_eq( $added['theme']['slug'], 'imported-one' );
	t_eq( $added['check']['pages']['create'], 2 );
	t_eq( rk_builder_find_page_by_slug( 'home' ), 0, 'importing to the library does not touch the site' );

	$dry = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'imported-one', 'options' => array( 'dryRun' => true ) ) ) );
	t_eq( $dry['dryRun'], true );
	t_eq( rk_builder_find_page_by_slug( 'home' ), 0 );

	$r = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'imported-one', 'options' => array( 'publish' => true, 'frontPage' => true ) ) ) );
	t_eq( $r['pages']['create'], 2 );
	t_eq( $r['published'], 2 );
	t_eq( $r['frontPage'], true );
	$home = rk_builder_find_page_by_slug( 'home' );
	t_eq( get_post( $home )->post_status, 'publish' );
	t_eq( (int) get_option( 'page_on_front' ), $home );
	t_eq( rk_builder_seo_read( $home )['title'], 'Welcome', 'SEO fields travel with the package' );
} );

rk_test( 'themes: installing without publish leaves drafts and does not move the front page', function () {
	rk_theme_site();
	t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Drafts' ) ) );
	foreach ( array( 'home', 'about' ) as $s ) { $id = rk_builder_find_page_by_slug( $s ); if ( $id ) { wp_delete_post( $id, true ); } }
	update_option( 'page_on_front', 0 );
	$r = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'drafts', 'options' => array( 'frontPage' => true ) ) ) );
	t_eq( $r['published'], 0 );
	t_eq( $r['frontPage'], false );
	t_eq( (int) get_option( 'page_on_front' ), 0 );
	t_err( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'drafts', 'options' => array( 'bogus' => true ) ) ), 'rk_invalid_theme', 400 );
	t_err( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'missing' ) ), 'rk_not_found', 404 );
} );

rk_test( 'themes: the library is capped', function () {
	rk_theme_site();
	for ( $i = 1; $i <= RK_BUILDER_MAX_THEMES; $i++ ) { t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'T' . $i ) ) ); }
	t_err( rk_post( '/rk/v1/builder/themes', array( 'name' => 'One too many' ) ), 'rk_limit', 409 );
} );
