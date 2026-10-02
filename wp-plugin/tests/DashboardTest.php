<?php
/** Dashboard API: overview, page management, per-page SEO, site settings, visualizer admin. */

function rk_dash_page( $title = 'About', $extra = array() ) {
	rk_test_login( 'admin' );
	$r = t_ok( rk_post( '/rk/v1/builder/pages/new', array_merge( array( 'title' => $title ), $extra ) ) );
	return $r['page'];
}

rk_test( 'dashboard: every route needs the right role', function () {
	t_err( rk_get( '/rk/v1/builder/overview' ), 'rk_unauthorized', 401 );
	t_err( rk_post( '/rk/v1/builder/pages/new', array( 'title' => 'x' ) ), 'rk_unauthorized', 401 );
	t_err( rk_get( '/rk/v1/builder/site' ), 'rk_unauthorized', 401 );
	rk_test_login( 'editor' );
	t_ok( rk_get( '/rk/v1/builder/overview' ) );
	t_err( rk_get( '/rk/v1/builder/site' ), 'rk_forbidden', 403 );
	t_err( rk_get( '/rk/v1/builder/visualizer-admin' ), 'rk_forbidden', 403 );
	t_err( rk_post( '/rk/v1/builder/visualizer-admin/leads/delete', array( 'all' => true ) ), 'rk_forbidden', 403 );
} );

rk_test( 'dashboard: create a page (title required, slug made unique), starts as a draft', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/pages/new', array() ), 'rk_invalid_page', 400 );
	t_err( rk_post( '/rk/v1/builder/pages/new', array( 'title' => 'x', 'nope' => 1 ) ), 'rk_invalid_page', 400 );
	$p = rk_dash_page( 'Our Story' );
	t_eq( $p['title'], 'Our Story' );
	t_eq( $p['slug'], 'our-story' );
	t_eq( $p['status'], 'draft' );
	t_eq( $p['revision'], 1 );
	t_eq( rk_builder_get_draft_layout( $p['id'] )['blocks'], array(), 'blank layout when the site has no header/footer blocks' );
} );

rk_test( 'dashboard: a new page starts with the site header and footer when they exist', function () {
	rk_test_login( 'admin' );
	$b = rk_bundle( array(
		'media'     => array(),
		'reusables' => array(
			array( 'id' => 1, 'slug' => 'site-header', 'name' => 'Header', 'block' => array( 'type' => 'cta', 'props' => array( 'heading' => 'H', 'cta' => 'Go', 'ctaHref' => '/' ) ) ),
			array( 'id' => 2, 'slug' => 'site-footer', 'name' => 'Footer', 'block' => array( 'type' => 'cta', 'props' => array( 'heading' => 'F', 'cta' => 'Go', 'ctaHref' => '/' ) ) ),
		),
		'pages'     => array(),
	) );
	t_ok( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => $b, 'options' => array( 'dryRun' => false ) ) ) );
	$p = rk_dash_page( 'Landing' );
	$blocks = rk_builder_get_draft_layout( $p['id'] )['blocks'];
	t_eq( array_map( function ( $x ) { return $x['id']; }, $blocks ), array( 'site-header', 'site-footer' ) );
	$blank = t_ok( rk_post( '/rk/v1/builder/pages/new', array( 'title' => 'Blank', 'starter' => false ) ) )['page'];
	t_eq( rk_builder_get_draft_layout( $blank['id'] )['blocks'], array() );
} );

rk_test( 'dashboard: rename and change the address', function () {
	$p = rk_dash_page( 'Old name' );
	$r = t_ok( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/update', array( 'title' => 'New <b>name</b>', 'slug' => 'New Address!' ) ) )['page'];
	t_eq( $r['title'], 'New name' );
	t_eq( $r['slug'], 'new-address' );
	t_err( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/update', array() ), 'rk_invalid_page', 400 );
	t_err( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/update', array( 'title' => '   ' ) ), 'rk_invalid_page', 400 );
	t_err( rk_post( '/rk/v1/builder/pages/999999/update', array( 'title' => 'x' ) ), 'rk_not_found', 404 );
} );

rk_test( 'dashboard: duplicate copies the layout and SEO into a new draft', function () {
	$p = rk_dash_page( 'Source page' );
	t_ok( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/seo', array( 'title' => 'SEO title', 'description' => 'Desc', 'noindex' => true ) ) );
	$d = t_ok( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/duplicate', array() ) )['page'];
	t_eq( $d['title'], 'Copy of Source page' );
	t_eq( $d['status'], 'draft' );
	t_assert( $d['id'] !== $p['id'] );
	$seo = rk_builder_seo_read( $d['id'] );
	t_eq( $seo['title'], 'SEO title' );
	t_eq( isset( $seo['noindex'] ), false, 'a copy is indexable until you decide' );
} );

rk_test( 'dashboard: per-page SEO is read, validated and saved', function () {
	$p = rk_dash_page( 'Seo page' );
	$g = t_ok( rk_get( '/rk/v1/builder/pages/' . $p['id'] . '/seo' ) )['seo'];
	t_eq( $g['title'], '' );
	t_eq( $g['pageTitle'], 'Seo page' );
	$s = t_ok( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/seo', array( 'title' => 'T <i>x</i>', 'description' => 'Some   text', 'image' => 'javascript:alert(1)', 'noindex' => true ) ) )['seo'];
	t_eq( $s['title'], 'T x' );
	t_eq( $s['description'], 'Some text' );
	t_eq( $s['image'], '', 'unsafe image addresses are dropped' );
	t_eq( $s['noindex'], true );
	t_err( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/seo', array( 'bogus' => 1 ) ), 'rk_invalid_seo', 400 );
	$list = t_ok( rk_get( '/rk/v1/builder/pages' ) )['pages'];
	$row = array_values( array_filter( $list, function ( $x ) use ( $p ) { return $x['id'] === $p['id']; } ) )[0];
	t_eq( $row['noindex'], true );
	t_eq( $row['hasDescription'], true );
} );

rk_test( 'dashboard: front page needs a published page; the front page cannot be trashed', function () {
	$p = rk_dash_page( 'Home page' );
	t_err( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/front', array() ), 'rk_conflict', 409 );
	$c = rk_builder_get_revision( $p['id'] );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $p['id'], array( 'expectedRevision' => $c ) ) );
	$f = t_ok( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/front', array() ) )['page'];
	t_eq( $f['isFront'], true );
	t_eq( (int) get_option( 'page_on_front' ), $p['id'] );
	t_err( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/trash', array() ), 'rk_conflict', 409 );
} );

rk_test( 'dashboard: trash moves a page out of the list', function () {
	$p = rk_dash_page( 'Throwaway' );
	t_eq( t_ok( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/trash', array() ) )['trashed'], $p['id'] );
	t_eq( get_post( $p['id'] )->post_status, 'trash' );
	t_err( rk_post( '/rk/v1/builder/pages/' . $p['id'] . '/trash', array() ), 'rk_not_found', 404 );
} );

rk_test( 'dashboard: site settings are saved and validated', function () {
	rk_test_login( 'admin' );
	$p = rk_dash_page( 'Front' );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $p['id'], array( 'expectedRevision' => rk_builder_get_revision( $p['id'] ) ) ) );
	$r = t_ok( rk_post( '/rk/v1/builder/site', array(
		'name' => 'Acme <b>Floors</b>', 'tagline' => 'We floor you', 'searchVisible' => false, 'frontPageId' => $p['id'],
		'organization' => array( 'name' => 'Acme', 'telephone' => '+1555', 'email' => 'a@b.test', 'logo' => 'javascript:x' ),
	) ) );
	t_eq( $r['site']['name'], 'Acme Floors' );
	t_eq( $r['site']['tagline'], 'We floor you' );
	t_eq( $r['site']['searchVisible'], false );
	t_eq( $r['site']['frontPageId'], $p['id'] );
	t_eq( $r['site']['organization']['telephone'], '+1555' );
	t_eq( $r['site']['organization']['logo'], '' );
	t_eq( (string) get_option( 'blog_public' ), '0' );
	t_err( rk_post( '/rk/v1/builder/site', array( 'name' => '  ' ) ), 'rk_invalid_site', 400 );
	t_err( rk_post( '/rk/v1/builder/site', array( 'frontPageId' => 987654 ) ), 'rk_invalid_site', 400 );
	t_err( rk_post( '/rk/v1/builder/site', array( 'wat' => 1 ) ), 'rk_invalid_site', 400 );
	$off = t_ok( rk_post( '/rk/v1/builder/site', array( 'frontPageId' => 0 ) ) );
	t_eq( $off['site']['frontPageId'], 0 );
} );

rk_test( 'dashboard: overview counts pages and lists what needs attention', function () {
	rk_test_login( 'admin' );
	$a = rk_dash_page( 'Live one' );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $a['id'], array( 'expectedRevision' => rk_builder_get_revision( $a['id'] ) ) ) );
	rk_dash_page( 'Draft one' );
	$o = t_ok( rk_get( '/rk/v1/builder/overview' ) );
	t_eq( $o['pages']['publish'], 1 );
	t_eq( $o['pages']['draft'], 1 );
	t_eq( count( $o['recent'] ), 2 );
	t_eq( $o['attention']['missingDescription'][0]['id'], $a['id'], 'a live page with no description is flagged' );
	t_eq( $o['visualizer']['enabled'], false );
	t_eq( $o['themes'], 0 );
	t_assert( isset( $o['site']['plugin'] ) && '' !== $o['site']['plugin'] );
} );

rk_test( 'dashboard: visualizer settings never expose secrets; blank keeps them; leads can be deleted', function () {
	rk_test_login( 'admin' );
	$r = t_ok( rk_post( '/rk/v1/builder/visualizer-admin', array( 'enabled' => true, 'provider' => 'gemini', 'gemini_key' => 'SECRET-KEY-123', 'gemini_model' => 'gemini-2.5-flash-image' ) ) );
	t_eq( $r['settings']['gemini_key_set'], true );
	t_eq( isset( $r['settings']['gemini_key'] ), false );
	t_eq( strpos( json_encode( $r ), 'SECRET-KEY-123' ), false, 'the key is never sent back' );
	t_eq( $r['ready'], true );
	$again = t_ok( rk_post( '/rk/v1/builder/visualizer-admin', array( 'enabled' => true, 'provider' => 'gemini' ) ) );
	t_eq( $again['settings']['gemini_key_set'], true, 'a blank key keeps the stored one' );
	$clear = t_ok( rk_post( '/rk/v1/builder/visualizer-admin', array( 'enabled' => true, 'provider' => 'gemini', 'clear_gemini_key' => true ) ) );
	t_eq( $clear['settings']['gemini_key_set'], false );

	rk_builder_viz_store_lead( array( 'name' => 'A', 'email' => 'a@x.test', 'phone' => '1' ) );
	rk_builder_viz_store_lead( array( 'name' => 'B', 'email' => 'b@x.test', 'phone' => '2' ) );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/visualizer-admin' ) )['leads'] ), 2 );
	t_eq( count( t_ok( rk_post( '/rk/v1/builder/visualizer-admin/leads/delete', array( 'email' => 'a@x.test' ) ) )['leads'] ), 1 );
	t_err( rk_post( '/rk/v1/builder/visualizer-admin/leads/delete', array() ), 'rk_invalid_settings', 400 );
	t_eq( count( t_ok( rk_post( '/rk/v1/builder/visualizer-admin/leads/delete', array( 'all' => true ) ) )['leads'] ), 0 );
} );
