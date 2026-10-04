<?php
/**
 * Custom sign-in page.
 *
 * One published page that contains the "Sign-in form" block can take over WordPress's login screen. The form posts to
 * wp-login.php, so WordPress itself still checks the password, sets the cookie and runs every security / 2FA plugin;
 * this file only sends visitors to the branded page and brings them back to it with a message when sign-in fails.
 *
 * Safety:
 *   - Nothing is redirected unless the chosen page is published, served by RK Builder and still has the block.
 *   - wp-login.php?rk_login=0 always shows the normal WordPress login (escape hatch if the page is ever broken).
 *   - Only the plain "login" action is redirected; password reset, logout and password-protected pages keep working.
 *   - A sign-in attempt that did not come from our form is left to WordPress untouched.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const RK_BUILDER_LOGIN_OPTION = 'rk_builder_login_page';
const RK_BUILDER_LOGIN_ENABLED_OPTION = 'rk_builder_login_enabled';
const RK_BUILDER_LOGIN_IMAGE_OPTION = 'rk_builder_login_image';

/** The page the admin chose (0 = WordPress's own login). */
function rk_builder_login_page_id() {
	return max( 0, (int) get_option( RK_BUILDER_LOGIN_OPTION, 0 ) );
}

/** Does this layout hold a sign-in block (top level, or inside a section)? */
function rk_builder_layout_has_login( $layout ) {
	if ( ! is_array( $layout ) ) { return false; }
	return false !== strpos( (string) wp_json_encode( $layout ), '"type":"login"' );
}

/** Published pages that carry the block, for the picker: array of array( id, title ). */
function rk_builder_login_candidates() {
	$out = array();
	foreach ( rk_builder_dash_all_pages() as $p ) {
		if ( 'publish' !== $p->post_status || '' !== (string) $p->post_password ) { continue; }
		$raw = (string) get_post_meta( (int) $p->ID, '_rk_layout_published', true );
		if ( false === strpos( $raw, '"type":"login"' ) ) { continue; }
		$out[] = array( 'id' => (int) $p->ID, 'title' => rk_builder_plain( get_the_title( $p ) ) );
	}
	return $out;
}

/** The on/off switch. A site that chose a page before the switch existed keeps it on. */
function rk_builder_login_enabled() {
	$v = get_option( RK_BUILDER_LOGIN_ENABLED_OPTION, null );
	return null === $v ? true : '0' !== (string) $v && false !== $v && 0 !== $v;
}

/** The picture chosen under Site & SEO for the full-screen sign-in layout ('' = none). A picture set on the block itself wins. */
function rk_builder_login_image() {
	$u = (string) get_option( RK_BUILDER_LOGIN_IMAGE_OPTION, '' );
	return '' !== $u && rk_builder_is_safe_image_url( $u, null ) ? $u : '';
}

/** The chosen page's address when it is live and still has the block (whether or not the switch is on), else ''. */
function rk_builder_login_page_link() {
	$id = rk_builder_login_page_id();
	if ( $id < 1 ) { return ''; }
	$rk = rk_builder_public_page( $id );
	if ( null === $rk || ! rk_builder_layout_has_login( $rk['layout'] ) ) { return ''; }
	$url = (string) get_permalink( $id );
	return '' === $url ? '' : $url;
}

/** The custom page's address, or '' when WordPress's own login should be used (switched off, or the page is not live). */
function rk_builder_login_page_url() {
	return rk_builder_login_enabled() ? rk_builder_login_page_link() : '';
}

/** A full-screen sign-in page brings no site header or footer of its own. */
function rk_builder_login_fullscreen_layout( $layout ) {
	if ( ! is_array( $layout ) || empty( $layout['blocks'] ) || ! is_array( $layout['blocks'] ) ) { return false; }
	foreach ( $layout['blocks'] as $b ) {
		if ( is_array( $b ) && isset( $b['type'], $b['props']['layout'] ) && 'login' === $b['type'] && 'split' === $b['props']['layout'] ) { return true; }
	}
	return false;
}

/** Messages the page can show (codes travel in the address, text never does). */
function rk_builder_login_messages() {
	return array(
		'loggedout'  => 'You have been signed out.',
		'checkemail' => 'Check your email for the link to reset your password.',
		'reset'      => 'Your password was changed. Sign in with the new one.',
	);
}

/** A redirect_to value from the request when it is safe (same site), else ''. */
function rk_builder_login_requested_redirect() {
	$raw = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? trim( (string) wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a destination, validated below
	if ( '' === $raw ) { return ''; }
	$ok = wp_validate_redirect( $raw, '' );
	return is_string( $ok ) ? $ok : '';
}

/* ------------------------------------------------------------------ *
 * Sending people to the branded page and back
 * ------------------------------------------------------------------ */

/** wp-login.php (plain "login" action, GET): go to the branded page. */
function rk_builder_login_maybe_redirect() {
	if ( isset( $_GET['rk_login'] ) && '0' === (string) $_GET['rk_login'] ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- escape hatch only
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) { return; }
	if ( ! empty( $_REQUEST['interim-login'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the in-admin pop-up keeps WordPress's form
	$url = rk_builder_login_page_url();
	if ( '' === $url ) { return; }
	$args = array();
	$to   = rk_builder_login_requested_redirect();
	if ( '' !== $to ) { $args['redirect_to'] = $to; }
	if ( isset( $_GET['loggedout'] ) ) { $args['rk_login_msg'] = 'loggedout'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	elseif ( isset( $_GET['checkemail'] ) ) { $args['rk_login_msg'] = 'checkemail'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	elseif ( isset( $_GET['password-reset'] ) ) { $args['rk_login_msg'] = 'reset'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( add_query_arg( $args, $url ) );
	exit;
}

/** A sign-in from our form failed: back to the branded page with a short message. */
function rk_builder_login_on_failed( $username = '', $error = null ) {
	if ( empty( $_POST['rk_login_form'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress checks the credentials itself
	$url = rk_builder_login_page_url();
	if ( '' === $url ) { return; }
	$args = array( 'rk_login_error' => '1' );
	$to   = rk_builder_login_requested_redirect();
	if ( '' !== $to ) { $args['redirect_to'] = $to; }
	wp_safe_redirect( add_query_arg( $args, $url ) );
	exit;
}

/** After a sign-in from our form with no destination, people who can edit pages land on the builder. */
function rk_builder_login_redirect_filter( $redirect_to, $requested, $user ) {
	if ( empty( $_POST['rk_login_form'] ) || '' !== (string) $requested ) { return $redirect_to; } // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $user instanceof WP_User && user_can( $user, 'edit_pages' ) ) { return admin_url( 'admin.php?page=rk-builder' ); }
	return $redirect_to;
}

/* ------------------------------------------------------------------ *
 * The block, as served to a visitor
 * ------------------------------------------------------------------ */

/** The logo (or site name) and the business details, taken from Themes and Site & SEO. */
function rk_builder_login_fill_brand( $html ) {
	$org  = rk_builder_seo_organization();
	$name = isset( $org['name'] ) && '' !== trim( (string) $org['name'] ) ? (string) $org['name'] : (string) get_bloginfo( 'name' );
	if ( false !== strpos( $html, 'data-rk-login-brand' ) ) {
		$logo  = rk_builder_src( rk_builder_theme_logo_url( rk_builder_get_theme() ) );
		$inner = '' !== $logo
			? '<img class="site-login-logo" src="' . $logo . '" alt="' . rk_builder_h( $name ) . '" height="44"/>'
			: '<span class="site-login-wordmark">' . rk_builder_h( $name ) . '</span>';
		$html  = str_replace( '<div class="site-login-brand" data-rk-login-brand=""></div>', '<div class="site-login-brand"><a href="' . rk_builder_h( home_url( '/' ) ) . '">' . $inner . '</a></div>', $html );
	}
	if ( false !== strpos( $html, 'data-rk-login-details' ) ) {
		$rows = '';
		$line = function ( $label, $value, $href = '' ) {
			if ( '' === trim( (string) $value ) ) { return ''; }
			$v = '' !== $href ? '<a href="' . rk_builder_h( $href ) . '">' . rk_builder_h( $value ) . '</a>' : rk_builder_h( $value );
			return '<li><span>' . $label . '</span>' . $v . '</li>';
		};
		$get   = function ( $k ) use ( $org ) { return isset( $org[ $k ] ) ? trim( (string) $org[ $k ] ) : ''; };
		$place = trim( implode( ', ', array_filter( array( $get( 'street' ), $get( 'city' ), trim( $get( 'region' ) . ' ' . $get( 'postal' ) ) ) ) ) );
		$rows .= $line( 'Visit', $place );
		$rows .= $line( 'Call', $get( 'telephone' ), rk_builder_phone_href( $get( 'telephone' ) ) );
		$rows .= $line( 'Email', $get( 'email' ), rk_builder_email_href( $get( 'email' ) ) );
		$rows .= $line( 'Hours', str_replace( "\n", ' · ', trim( str_replace( "\r", '', $get( 'hours' ) ) ) ) );
		$box   = '' === $rows ? '' : '<p class="site-login-biz">' . rk_builder_h( $name ) . '</p><ul>' . $rows . '</ul>';
		$html  = str_replace( '<div class="site-login-details" data-rk-login-details=""></div>', '' === $box ? '' : '<div class="site-login-details">' . $box . '</div>', $html );
	}
	return $html;
}

/** Fill in the static block markup: real action + hidden fields, message, lost-password link, or the signed-in panel. */
function rk_builder_login_make_live( $html ) {
	$html = rk_builder_login_fill_brand( (string) $html );
	$side = rk_builder_login_image();
	if ( '' !== $side ) {
		$html = str_replace( '<div class="site-login-media"></div>', '<div class="site-login-media"><img class="site-login-img" src="' . rk_builder_src( $side ) . '" alt="" decoding="async"/></div>', $html );
	}
	if ( is_user_logged_in() ) {
		$user = wp_get_current_user();
		$who  = isset( $user->display_name ) && '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;
		$ini  = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $who, 0, 1 ) ) : strtoupper( substr( $who, 0, 1 ) );
		$dash = current_user_can( 'edit_pages' ) ? admin_url( 'admin.php?page=rk-builder' ) : home_url( '/' );
		$done = '<div class="site-login-done"><div class="site-login-who"><span class="site-login-avatar" aria-hidden="true">' . rk_builder_h( $ini ) . '</span><span class="site-login-name"><small>Signed in as</small><strong>' . rk_builder_h( $who ) . '</strong></span></div>'
			. '<a class="site-login-btn" href="' . rk_builder_h( $dash ) . '">' . ( current_user_can( 'edit_pages' ) ? 'Open the dashboard' : 'Go to the site' ) . '</a>'
			. '<a class="site-login-btn is-ghost" href="' . rk_builder_h( wp_logout_url( home_url( '/' ) ) ) . '">Sign out</a></div>';
		$html = preg_replace( '#(<h2>.*?</h2>)<p>.*?</p>#s', '$1', (string) $html, 1 );
		$html = preg_replace( '#<div class="site-login-msg"[^>]*></div>#', '', (string) $html );
		$html = preg_replace( '#<form class="site-login-form".*?</form>#s', $done, (string) $html );
		return preg_replace( '#<p class="site-login-links">.*?</p>#s', '', (string) $html );
	}
	$action = rk_builder_h( site_url( 'wp-login.php', 'login_post' ) );
	$hidden = '<input type="hidden" name="rk_login_form" value="1"/>';
	$to     = rk_builder_login_requested_redirect();
	if ( '' !== $to ) { $hidden .= '<input type="hidden" name="redirect_to" value="' . rk_builder_h( $to ) . '"/>'; }
	$html = str_replace( '<form class="site-login-form" data-rk-login="" method="post">', '<form class="site-login-form" data-rk-login="" method="post" action="' . $action . '">' . $hidden, (string) $html );
	$html = str_replace( 'href="#rk-lostpassword"', 'href="' . rk_builder_h( wp_lostpassword_url() ) . '"', $html );
	$msg  = '';
	if ( isset( $_GET['rk_login_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows a fixed message only
		$msg = '<div class="site-login-msg is-error" role="alert">That did not work. Check your email or username and your password, then try again.</div>';
	} elseif ( isset( $_GET['rk_login_msg'] ) && is_string( $_GET['rk_login_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$all = rk_builder_login_messages();
		$key = (string) wp_unslash( $_GET['rk_login_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $all[ $key ] ) ) { $msg = '<div class="site-login-msg" role="status">' . rk_builder_h( $all[ $key ] ) . '</div>'; }
	}
	if ( '' !== $msg ) { $html = str_replace( '<div class="site-login-msg" role="alert" hidden=""></div>', $msg, $html ); }
	return $html;
}

/** The page is personal (who is signed in, the last error): keep it out of page caches and search results. */
function rk_builder_login_page_headers() {
	$rk = rk_builder_current_request_page();
	if ( null === $rk || ! rk_builder_layout_has_login( $rk['layout'] ) ) { return; }
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
	nocache_headers();
	if ( ! headers_sent() ) { header( 'X-Robots-Tag: noindex, nofollow' ); }
}

function rk_builder_login_body_class( $classes ) {
	$rk = rk_builder_current_request_page();
	if ( null !== $rk && rk_builder_layout_has_login( $rk['layout'] ) ) { $classes[] = rk_builder_login_fullscreen_layout( $rk['layout'] ) ? 'rk-login-full' : 'rk-login-page'; }
	return $classes;
}

/* ------------------------------------------------------------------ *
 * WordPress's own screens (lost password, reset, 2FA...) in the site's colours
 * ------------------------------------------------------------------ */

function rk_builder_login_contrast( $hex ) {
	$h = ltrim( (string) $hex, '#' );
	if ( 6 !== strlen( $h ) ) { return '#ffffff'; }
	$l = ( 0.299 * hexdec( substr( $h, 0, 2 ) ) + 0.587 * hexdec( substr( $h, 2, 2 ) ) + 0.114 * hexdec( substr( $h, 4, 2 ) ) ) / 255;
	return $l > 0.6 ? '#111111' : '#ffffff';
}

function rk_builder_login_screen_css() {
	$theme = rk_builder_get_theme();
	$hex   = function ( $v, $fallback ) { return is_string( $v ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}\z/', $v ) ? $v : $fallback; };
	$dark   = $hex( isset( $theme['dark'] ) ? $theme['dark'] : '', $hex( isset( $theme['ink'] ) ? $theme['ink'] : '', '#1B2430' ) );
	$accent = $hex( isset( $theme['accent'] ) ? $theme['accent'] : '', $hex( isset( $theme['primary'] ) ? $theme['primary'] : '', '#C7F36B' ) );
	$on     = rk_builder_login_contrast( $accent );
	$logo   = rk_builder_src( rk_builder_theme_logo_url( $theme ) );
	$css    = 'body.login{background:' . $dark . ';color:#fff}'
		. '.login form{border:0;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.35)}'
		. '.login #nav a,.login #backtoblog a,.login .privacy-policy-page-link a{color:#fff;opacity:.85}'
		. '.login #nav a:hover,.login #backtoblog a:hover{color:' . $accent . ';opacity:1}'
		. '.login input:focus{border-color:' . $accent . ';box-shadow:0 0 0 1px ' . $accent . '}'
		. '.wp-core-ui .button-primary{background:' . $accent . ';border-color:' . $accent . ';color:' . $on . ';text-shadow:none;box-shadow:none}'
		. '.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:' . $accent . ';border-color:' . $accent . ';color:' . $on . ';filter:brightness(.92)}';
	if ( '' !== $logo ) {
		return $css . '.login h1 a{background:url(' . $logo . ') center/contain no-repeat;width:100%;height:64px}';
	}
	return $css . '.login h1 a{background:none;width:auto;height:auto;text-indent:0;color:#fff;font-size:26px;font-weight:700;line-height:1.2}';
}

function rk_builder_login_screen_styles() {
	if ( '' === rk_builder_login_page_url() ) { return; }
	echo '<style id="rk-login-screen">' . rk_builder_login_screen_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- colours are validated hex, the logo URL is escaped
}

function rk_builder_login_header_url( $url ) { return '' === rk_builder_login_page_url() ? $url : home_url( '/' ); }
function rk_builder_login_header_text( $text ) { return '' === rk_builder_login_page_url() ? $text : (string) get_bloginfo( 'name' ); }

/* ------------------------------------------------------------------ *
 * Admin: create the page, hooks
 * ------------------------------------------------------------------ */

function rk_builder_login_starter_layout() {
	return array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => array(
		array( 'id' => 'login-form', 'type' => 'login', 'props' => array( 'heading' => 'Welcome back', 'intro' => 'Sign in to continue.', 'button' => 'Sign in', 'remember' => true, 'forgot' => true, 'layout' => 'split', 'side' => 'left', 'showBrand' => true, 'showDetails' => true ) ),
	) );
}

/** POST /builder/login/create: a published "Sign in" page holding the form, switched on as the login page. */
function rk_builder_handle_create_login_page( $req ) {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Sign in', 'post_name' => rk_builder_dash_unique_slug( 'sign-in' ) ), true );
	if ( is_wp_error( $id ) || ! $id ) { return rk_builder_error( 'rk_server_error', 'Could not create the page.', 500 ); }
	$layout = rk_builder_login_starter_layout();
	$commit = rk_builder_with_lock( (int) $id, function () use ( $id, $layout ) {
		$updated = wp_update_post( array( 'ID' => (int) $id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $updated ) || ! $updated ) { return rk_builder_error( 'rk_server_error', 'Could not publish the page.', 500 ); }
		return rk_builder_commit_revision( (int) $id, 'publish', rk_builder_canonicalize_layout( $layout ), array( 'published' => true ) );
	} );
	if ( is_wp_error( $commit ) ) { return $commit; }
	$page = get_post( (int) $id );
	rk_builder_revalidate( 'publish', (int) $id, $page ? (string) $page->post_name : '' );
	rk_builder_layout_changed( (int) $id, 'publish' );
	update_option( RK_BUILDER_LOGIN_OPTION, (int) $id );
	update_option( RK_BUILDER_LOGIN_ENABLED_OPTION, '1' );
	return rk_builder_handle_get_site( $req );
}

function rk_builder_register_login_hooks() {
	add_action( 'login_form_login', 'rk_builder_login_maybe_redirect' );
	add_action( 'wp_login_failed', 'rk_builder_login_on_failed', 10, 2 );
	add_filter( 'login_redirect', 'rk_builder_login_redirect_filter', 10, 3 );
	add_action( 'template_redirect', 'rk_builder_login_page_headers', 1 );
	add_filter( 'body_class', 'rk_builder_login_body_class' );
	add_action( 'login_enqueue_scripts', 'rk_builder_login_screen_styles' );
	add_filter( 'login_headerurl', 'rk_builder_login_header_url' );
	add_filter( 'login_headertext', 'rk_builder_login_header_text' );
}
rk_builder_register_login_hooks();
