<?php
/**
 * Short-lived preview tokens: base64url(payload).base64url(HMAC-SHA256(payload_b64)).
 * payload = {"p": pageId, "e": expiryUnix, "u": "rk_builder_preview"}. Secret: RK_BUILDER_PREVIEW_SECRET
 * or wp_salt('auth'). Tokens issued before the purpose claim existed (no "u") still verify until they
 * expire; a token carrying any other purpose never does.
 * Tokens are bearer credentials: they are never logged by this plugin.
 *
 * Public preview route: /?rk_preview=1&page=<id>&token=<token> renders the DRAFT layout in a
 * standalone, noindex, no-store document. Anything invalid is a real 404 (never the published page).
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_PREVIEW_PURPOSE' ) ) { define( 'RK_BUILDER_PREVIEW_PURPOSE', 'rk_builder_preview' ); }

function rk_builder_b64url_encode( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

function rk_builder_b64url_decode( $s ) {
	if ( ! is_string( $s ) || 1 !== preg_match( '/^[A-Za-z0-9_-]*\z/', $s ) ) { return false; }
	return base64_decode( strtr( $s, '-_', '+/' ), true );
}

function rk_builder_preview_secret() {
	if ( defined( 'RK_BUILDER_PREVIEW_SECRET' ) && is_string( RK_BUILDER_PREVIEW_SECRET ) && '' !== RK_BUILDER_PREVIEW_SECRET ) {
		return RK_BUILDER_PREVIEW_SECRET;
	}
	return wp_salt( 'auth' );
}

/** Token lifetime in seconds: setting `preview_ttl`, overridden by the RK_BUILDER_PREVIEW_TTL constant, then the filter. */
function rk_builder_preview_ttl() {
	$ttl = defined( 'RK_BUILDER_PREVIEW_TTL' ) ? (int) RK_BUILDER_PREVIEW_TTL : (int) rk_builder_setting( 'preview_ttl', 900 );
	return max( 30, (int) apply_filters( 'rk_builder_preview_ttl', $ttl ) );
}

/** @return array{token:string,expiresAt:string} */
function rk_builder_create_preview_token( $page_id ) {
	$expires = rk_builder_now() + rk_builder_preview_ttl();
	$payload = rk_builder_b64url_encode( wp_json_encode( array( 'p' => (int) $page_id, 'e' => $expires, 'u' => RK_BUILDER_PREVIEW_PURPOSE ) ) );
	$sig     = rk_builder_b64url_encode( hash_hmac( 'sha256', $payload, rk_builder_preview_secret(), true ) );
	return array( 'token' => $payload . '.' . $sig, 'expiresAt' => rk_builder_iso( $expires ) );
}

/** True only for an authentic, unexpired token issued for exactly this page id. */
function rk_builder_verify_preview_token( $token, $page_id ) {
	if ( ! is_string( $token ) || strlen( $token ) > 512 || 1 !== preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token ) ) { return false; }
	list( $payload, $sig ) = explode( '.', $token, 2 );
	$expected = rk_builder_b64url_encode( hash_hmac( 'sha256', $payload, rk_builder_preview_secret(), true ) );
	if ( ! hash_equals( $expected, $sig ) ) { return false; }
	$json = rk_builder_b64url_decode( $payload );
	$data = false === $json ? null : json_decode( $json, true );
	if ( ! is_array( $data ) || ! isset( $data['p'], $data['e'] ) || ! is_int( $data['p'] ) || ! is_int( $data['e'] ) ) { return false; }
	if ( array_key_exists( 'u', $data ) && RK_BUILDER_PREVIEW_PURPOSE !== $data['u'] ) { return false; }
	return $data['p'] === (int) $page_id && $data['e'] >= rk_builder_now();
}

/* ------------------------------------------------------------------ *
 * Public preview route:  /?rk_preview=1&page=<id>&token=<token>
 * ------------------------------------------------------------------ */

/** The preview URL on this WordPress site (the token is a short-lived bearer credential). */
function rk_builder_preview_url( $page_id, $token ) {
	return home_url( '/' ) . '?' . http_build_query( array( 'rk_preview' => 1, 'page' => (int) $page_id, 'token' => (string) $token ), '', '&', PHP_QUERY_RFC3986 );
}

/** Send a response header (and let tests observe it). */
function rk_builder_emit_header( $line ) {
	do_action( 'rk_builder_emit_header', $line );
	if ( ! headers_sent() ) { header( $line, true ); }
}

/**
 * Decide what a preview request gets. Pure: no output, no headers.
 *
 * @param array $get Typically $_GET.
 * @return array{status:int,html:string}|null null when this is not a preview request at all.
 */
function rk_builder_preview_response( array $get ) {
	if ( ! isset( $get['rk_preview'] ) ) { return null; }
	$notfound = array( 'status' => 404, 'html' => '' );
	if ( ! rk_builder_setting( 'enabled', true ) ) { return $notfound; }
	$id    = isset( $get['page'] ) && is_string( $get['page'] ) && 1 === preg_match( '/^[0-9]{1,10}\z/', $get['page'] ) ? (int) $get['page'] : 0;
	$token = isset( $get['token'] ) && is_string( $get['token'] ) ? $get['token'] : '';
	$page  = $id > 0 ? rk_builder_get_page( $id ) : null;
	// Wrong token, expired, tampered, missing, for another page, or no such page: all the same 404.
	if ( ! $page || '' === $token || ! rk_builder_verify_preview_token( $token, $id ) ) {
		rk_builder_log( 'info', 'preview_rejected', array( 'page_id' => $id ) );
		return $notfound;
	}
	$theme = rk_builder_get_theme();
	$html  = rk_builder_render_layout( rk_builder_get_draft_layout( $id ), array( 'page_id' => $id, 'preview' => true ) );
	return array( 'status' => 200, 'html' => rk_builder_preview_document( $page, $html, $theme ) );
}

/** The DRAFT preview as a standalone, noindex document (no wp_head(): nothing else may add tags to it). */
function rk_builder_preview_document( $page, $layout_html, array $theme ) {
	$title = rk_builder_plain( get_the_title( $page ) );
	$head  = '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">'
		. '<title>' . rk_builder_h( 'Preview: ' . $title ) . '</title>'
		. '<link rel="stylesheet" href="' . rk_builder_h( rk_builder_site_css_url() . '?ver=' . RK_BUILDER_VERSION ) . '">'
		. '<style>' . rk_builder_theme_css( $theme ) . '</style>';
	if ( apply_filters( 'rk_builder_load_google_fonts', false ) ) {
		$head .= '<link rel="stylesheet" href="' . rk_builder_h( RK_BUILDER_GOOGLE_FONTS_URL ) . '">';
	}
	return '<!doctype html><html lang="en"><head>' . $head . '</head><body><div class="site-root rk-root">'
		. '<div class="preview-banner" role="status">Draft preview — not published</div>'
		. rk_builder_site_header_html( $theme )
		. '<main id="main">' . $layout_html . '</main>'
		. rk_builder_site_footer_html( $theme ) . '</div></body></html>';
}

function rk_builder_preview_headers() {
	if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
	rk_builder_emit_header( 'Cache-Control: no-store, private' );
	rk_builder_emit_header( 'X-Robots-Tag: noindex, nofollow' );
	rk_builder_emit_header( 'Referrer-Policy: no-referrer' );
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
}

/** template_redirect: serve the preview, or turn the request into a real 404. Never falls back to published content. */
function rk_builder_maybe_serve_preview() {
	$get = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET ) : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$res = rk_builder_preview_response( is_array( $get ) ? $get : array() );
	if ( null === $res ) { return; }
	rk_builder_preview_headers();
	if ( 200 !== $res['status'] ) {
		global $wp_query;
		if ( is_object( $wp_query ) ) {
			if ( method_exists( $wp_query, 'set_404' ) ) { $wp_query->set_404(); }
			$wp_query->posts = array();
			$wp_query->post_count = 0;
			$wp_query->found_posts = 0;
		}
		status_header( 404 );
		return; // WordPress renders the theme's normal 404 template
	}
	status_header( 200 );
	rk_builder_emit_header( 'Content-Type: text/html; charset=UTF-8' );
	echo $res['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer
	if ( apply_filters( 'rk_builder_exit_after_preview', true ) ) { exit; }
}

add_action( 'template_redirect', 'rk_builder_maybe_serve_preview', 0 );
