<?php
/** Custom sign-in page: the block, the page picker and the live markup. (The real redirects are covered by the all-in-one browser test.) */

if ( ! function_exists( 'wp_lostpassword_url' ) ) { function wp_lostpassword_url() { return 'https://cms.example.com/wp-login.php?action=lostpassword'; } }
if ( ! function_exists( 'wp_logout_url' ) ) { function wp_logout_url( $to = '' ) { return 'https://cms.example.com/wp-login.php?action=logout'; } }
if ( ! function_exists( 'wp_validate_redirect' ) ) {
	function wp_validate_redirect( $url, $fallback = '' ) { return 0 === strpos( (string) $url, 'https://cms.example.com/' ) ? $url : $fallback; }
}

function rk_login_layout( array $props ) {
	return array( 'version' => 1, 'blocks' => array( array( 'id' => 'l', 'type' => 'login', 'props' => array_merge( array( 'heading' => 'Sign in', 'intro' => '', 'button' => 'Go' ), $props ) ) ) );
}

rk_test( 'login: the block validates; options are optional', function () {
	t_eq( rk_builder_validate_layout( rk_login_layout( array() ), RK_TEST_HOSTS ), array() );
	t_eq( rk_builder_validate_layout( rk_login_layout( array( 'remember' => false, 'forgot' => false ) ), RK_TEST_HOSTS ), array() );
	foreach ( array( array( 'button' => '' ), array( 'heading' => '' ), array( 'remember' => 'yes' ), array( 'action' => 'https://evil.example' ) ) as $bad ) {
		t_assert( count( rk_builder_validate_layout( rk_login_layout( $bad ), RK_TEST_HOSTS ) ) >= 1, json_encode( $bad ) );
	}
} );

rk_test( 'login: static markup has no action; options turn rows off', function () {
	$html = rk_builder_render_login( array( 'heading' => 'Hi <b>', 'intro' => '', 'button' => 'Go' ) );
	t_assert( false === strpos( $html, 'action=' ) && false === strpos( $html, 'rk_login_form' ), 'nothing server-specific in the static markup' );
	t_assert( false !== strpos( $html, 'Hi &lt;b&gt;' ) && false !== strpos( $html, 'rememberme' ) && false !== strpos( $html, 'Forgot your password?' ) );
	$min = rk_builder_render_login( array( 'heading' => 'x', 'intro' => '', 'button' => 'Go', 'remember' => false, 'forgot' => false ) );
	t_assert( false === strpos( $min, 'rememberme' ) && false === strpos( $min, 'Forgot' ) );
} );

rk_test( 'login: served to a visitor, the form posts to wp-login.php and a failed attempt shows one fixed message', function () {
	rk_test_login( 'anon' );
	unset( $_GET['rk_login_error'], $_GET['rk_login_msg'], $_REQUEST['redirect_to'] );
	$p    = array( 'heading' => 'Sign in', 'intro' => '', 'button' => 'Go' );
	$live = rk_builder_render_login( $p, array( 'rk_live' => true ) );
	t_assert( 1 === preg_match( '#<form [^>]*action="[^"]*wp-login\.php"#', $live ), 'posts to WordPress' );
	t_assert( false !== strpos( $live, 'name="rk_login_form"' ) && false === strpos( $live, 'name="redirect_to"' ) );
	t_assert( false !== strpos( $live, 'href="https://cms.example.com/wp-login.php?action=lostpassword"' ) );
	$_GET['rk_login_error'] = '1';
	$err = rk_builder_render_login( $p, array( 'rk_live' => true ) );
	t_assert( false !== strpos( $err, 'is-error' ) && false === strpos( $err, 'hidden=""' ) );
	unset( $_GET['rk_login_error'] );
	$_GET['rk_login_msg'] = '<script>';
	t_assert( false !== strpos( rk_builder_render_login( $p, array( 'rk_live' => true ) ), 'hidden=""' ), 'an unknown message code shows nothing' );
	$_GET['rk_login_msg'] = 'loggedout';
	t_assert( false !== strpos( rk_builder_render_login( $p, array( 'rk_live' => true ) ), 'You have been signed out.' ) );
	unset( $_GET['rk_login_msg'] );
	$_REQUEST['redirect_to'] = 'https://evil.example/x';
	t_assert( false === strpos( rk_builder_render_login( $p, array( 'rk_live' => true ) ), 'evil.example' ), 'a foreign redirect is dropped' );
	$_REQUEST['redirect_to'] = 'https://cms.example.com/wp-admin/';
	t_assert( false !== strpos( rk_builder_render_login( $p, array( 'rk_live' => true ) ), 'name="redirect_to" value="https://cms.example.com/wp-admin/"' ) );
	unset( $_REQUEST['redirect_to'] );
} );

rk_test( 'login: when already signed in the form is replaced by a panel', function () {
	rk_test_login( 'admin' );
	$html = rk_builder_render_login( array( 'heading' => 'Sign in', 'intro' => '', 'button' => 'Go' ), array( 'rk_live' => true ) );
	t_assert( false === strpos( $html, '<form' ) && false !== strpos( $html, 'site-login-done' ) && false !== strpos( $html, 'Sign out' ) );
} );

rk_test( 'login: create a page, pick it, and switch back to the WordPress login', function () {
	rk_test_login( 'admin' );
	delete_option( RK_BUILDER_LOGIN_OPTION );
	$r = t_ok( rk_post( '/rk/v1/builder/login/create', array() ) );
	$id = $r['site']['loginPageId'];
	t_assert( $id > 0 && 'publish' === get_post( $id )->post_status, 'a published page' );
	t_eq( array_column( $r['loginPages'], 'id' ), array( $id ), 'it is offered in the picker' );
	t_assert( '' !== $r['site']['loginUrl'], 'and has an address' );
	t_eq( rk_builder_login_page_url(), $r['site']['loginUrl'] );
	// A page without the block cannot be chosen.
	$plain = rk_dash_page( 'Plain' );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $plain['id'], array( 'expectedRevision' => rk_builder_get_revision( $plain['id'] ) ) ) );
	t_err( rk_post( '/rk/v1/builder/site', array( 'loginPageId' => $plain['id'] ) ), 'rk_invalid_site', 400 );
	t_err( rk_post( '/rk/v1/builder/site', array( 'loginPageId' => 987654 ) ), 'rk_invalid_site', 400 );
	t_eq( rk_builder_login_page_id(), $id, 'a rejected choice changes nothing' );
	// Unpublishing the page (or any broken state) silently falls back, so nobody is locked out.
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
	t_eq( rk_builder_login_page_url(), '', 'a page that is no longer live is ignored' );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	$off = t_ok( rk_post( '/rk/v1/builder/site', array( 'loginPageId' => 0 ) ) );
	t_eq( $off['site']['loginPageId'], 0 );
	t_eq( rk_builder_login_page_url(), '' );
} );

rk_test( 'login: the page is kept out of caches and the lost-password screen is styled with the theme colours', function () {
	$css = rk_builder_login_screen_css();
	t_assert( false !== strpos( $css, 'body.login{background:' ) && false !== strpos( $css, '.button-primary' ) );
	t_eq( rk_builder_login_contrast( '#ffffff' ), '#111111' );
	t_eq( rk_builder_login_contrast( '#101010' ), '#ffffff' );
} );

rk_test( 'login: the on/off switch decides whether visitors are sent to the page; the page choice is kept', function () {
	rk_test_login( 'admin' );
	delete_option( RK_BUILDER_LOGIN_ENABLED_OPTION );
	$r  = t_ok( rk_post( '/rk/v1/builder/login/create', array() ) );
	$id = $r['site']['loginPageId'];
	t_eq( $r['site']['loginEnabled'], true, 'creating the page switches it on' );
	t_assert( '' !== $r['site']['loginUrl'] );
	$off = t_ok( rk_post( '/rk/v1/builder/site', array( 'loginEnabled' => false ) ) );
	t_eq( $off['site']['loginEnabled'], false );
	t_eq( $off['site']['loginUrl'], '', 'no redirect while it is off' );
	t_eq( rk_builder_login_page_url(), '' );
	t_eq( $off['site']['loginPageId'], $id, 'the page stays chosen' );
	t_assert( '' !== $off['site']['loginLink'], 'and can still be viewed' );
	$on = t_ok( rk_post( '/rk/v1/builder/site', array( 'loginEnabled' => true ) ) );
	t_assert( '' !== $on['site']['loginUrl'] );
	t_err( rk_post( '/rk/v1/builder/site', array( 'loginEnabled' => 'yes' ) ), 'rk_invalid_site', 400 );
	// A site that picked a page before the switch existed stays on.
	delete_option( RK_BUILDER_LOGIN_ENABLED_OPTION );
	t_eq( rk_builder_login_enabled(), true );
	t_ok( rk_post( '/rk/v1/builder/site', array( 'loginPageId' => 0 ) ) );
} );

rk_test( 'login: the full-screen layout has a picture side and a form side; logo and business details are filled in live', function () {
	rk_test_login( 'anon' );
	$p = array( 'heading' => 'Welcome', 'intro' => '', 'button' => 'Go', 'layout' => 'split', 'side' => 'right', 'imageUrl' => 'https://cms.example.com/uploads/side.jpg', 'imageAlt' => 'Showroom', 'showBrand' => true, 'showDetails' => true );
	$static = rk_builder_render_login( $p );
	t_assert( false !== strpos( $static, 'site-login is-split side-right' ) && false !== strpos( $static, 'class="site-login-media"' ) && false !== strpos( $static, 'side.jpg' ) );
	t_assert( false !== strpos( $static, 'data-rk-login-brand=""' ) && false !== strpos( $static, 'data-rk-login-details=""' ), 'placeholders only until served' );
	update_option( 'rk_builder_seo_org', array( 'name' => 'Acme <Floors>', 'telephone' => '+1 555 0100', 'email' => 'hi@acme.test', 'street' => '1 Main St', 'city' => 'Peoria', 'region' => 'IL', 'postal' => '61602', 'hours' => "Mon-Fri 8-5\nSat 9-1" ) );
	$live = rk_builder_render_login( $p, array( 'rk_live' => true ) );
	t_assert( false === strpos( $live, 'data-rk-login-brand' ) && false === strpos( $live, 'data-rk-login-details' ) );
	t_assert( false !== strpos( $live, 'Acme &lt;Floors&gt;' ), 'name, escaped' );
	t_assert( false !== strpos( $live, 'href="tel:+15550100"' ) && false !== strpos( $live, 'href="mailto:hi@acme.test"' ) );
	t_assert( false !== strpos( $live, '1 Main St, Peoria, IL 61602' ) && false !== strpos( $live, 'Mon-Fri 8-5 · Sat 9-1' ) );
	delete_option( 'rk_builder_seo_org' );
	$empty = rk_builder_render_login( $p, array( 'rk_live' => true ) );
	t_assert( false === strpos( $empty, 'site-login-details' ), 'no details, no empty box' );
	$card = rk_builder_render_login( array( 'heading' => 'x', 'intro' => '', 'button' => 'Go' ) );
	t_assert( false === strpos( $card, 'is-split' ) && false === strpos( $card, 'site-login-media' ), 'the card layout is unchanged' );
	t_eq( rk_builder_login_fullscreen_layout( rk_login_layout( array( 'layout' => 'split' ) ) ), true );
	t_eq( rk_builder_login_fullscreen_layout( rk_login_layout( array() ) ), false );
} );

rk_test( 'login: a picture chosen under Site & SEO fills the picture side; the block\'s own picture wins; signed-in panel is a proper card', function () {
	rk_test_login( 'admin' );
	update_option( RK_BUILDER_LOGIN_IMAGE_OPTION, '' );
	t_err( rk_post( '/rk/v1/builder/site', array( 'loginImage' => 'javascript:alert(1)' ) ), 'rk_invalid_site', 400 );
	$r = t_ok( rk_post( '/rk/v1/builder/site', array( 'loginImage' => 'https://cms.example.com/uploads/side.jpg' ) ) );
	t_eq( $r['site']['loginImage'], 'https://cms.example.com/uploads/side.jpg' );
	rk_test_login( 'anon' );
	$bare = array( 'heading' => 'Hi', 'intro' => 'Intro', 'button' => 'Go', 'layout' => 'split' );
	$live = rk_builder_render_login( $bare, array( 'rk_live' => true ) );
	t_assert( false !== strpos( $live, '<div class="site-login-media"><img class="site-login-img" src="https://cms.example.com/uploads/side.jpg"' ), 'site-wide picture used' );
	$own = rk_builder_render_login( array_merge( $bare, array( 'imageUrl' => 'https://cms.example.com/uploads/own.jpg' ) ), array( 'rk_live' => true ) );
	t_assert( false !== strpos( $own, 'own.jpg' ) && false === strpos( $own, 'side.jpg' ), 'the block\'s own picture wins' );
	rk_test_login( 'admin' );
	$in = rk_builder_render_login( $bare, array( 'rk_live' => true ) );
	t_assert( false !== strpos( $in, 'site-login-avatar' ) && false !== strpos( $in, 'Signed in as' ) && false !== strpos( $in, 'is-ghost' ) && false === strpos( $in, 'Intro' ), 'a card with who you are, no sign-in intro' );
	rk_test_login( 'anon' );
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/site', array( 'loginImage' => '' ) ) );
	t_eq( rk_builder_login_image(), '' );
} );
