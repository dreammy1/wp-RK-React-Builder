<?php
/**
 * CORS for a separately hosted editor/frontend. Exact-match allow-list only: origins are never
 * reflected, there is no wildcard, and `null` origins are rejected.
 *
 * Sources (merged): constant RK_BUILDER_ALLOWED_ORIGINS (array or comma string), option
 * `rk_builder_allowed_origins`, the "Allowed origins" setting (Settings > RK Builder, only needed
 * for direct cross-origin browser use), filter `rk_builder_allowed_origins`.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Normalise to a list of "scheme://host[:port]" origins; drops anything else (incl. wildcards). */
function rk_builder_normalize_origins( $value ) {
	if ( is_string( $value ) ) { $value = preg_split( '/[\s,]+/', $value ); }
	$out = array();
	foreach ( (array) $value as $o ) {
		if ( ! is_string( $o ) ) { continue; }
		$o = rtrim( trim( $o ), '/' );
		if ( 1 === preg_match( '~^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?\z~', $o ) ) { $out[] = strtolower( $o ); }
	}
	return array_values( array_unique( $out ) );
}

function rk_builder_allowed_origins() {
	$list = array();
	if ( defined( 'RK_BUILDER_ALLOWED_ORIGINS' ) ) { $list = array_merge( $list, rk_builder_normalize_origins( RK_BUILDER_ALLOWED_ORIGINS ) ); }
	$list = array_merge( $list, rk_builder_normalize_origins( get_option( 'rk_builder_allowed_origins', array() ) ) );
	$list = array_merge( $list, rk_builder_normalize_origins( rk_builder_setting( 'allowed_origins', '' ) ) );
	$list = apply_filters( 'rk_builder_allowed_origins', $list );
	return rk_builder_normalize_origins( $list );
}

/** Pure: returns the exact origin to echo back, or null. */
function rk_builder_match_origin( $origin, array $allowed ) {
	if ( ! is_string( $origin ) || '' === $origin || 'null' === $origin ) { return null; }
	foreach ( $allowed as $a ) {
		if ( $a === $origin ) { return $a; }
	}
	return null;
}

/** Pure: headers to send for a request Origin (empty array when not allowed). */
function rk_builder_cors_headers( $origin, array $allowed ) {
	$match = rk_builder_match_origin( $origin, $allowed );
	if ( null === $match ) { return array(); }
	return array(
		'Access-Control-Allow-Origin'      => $match,
		'Vary'                             => 'Origin',
		'Access-Control-Allow-Methods'     => 'GET, POST, OPTIONS',
		'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-WP-Nonce',
		'Access-Control-Allow-Credentials' => 'true',
		'Access-Control-Expose-Headers'    => 'X-WP-Total, X-WP-TotalPages',
	);
}

/** Replace core's permissive (origin-reflecting) REST CORS handling with the allow-list. */
function rk_builder_install_cors() {
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	add_filter( 'rest_pre_serve_request', 'rk_builder_send_cors_headers', 15 );
}

function rk_builder_send_cors_headers( $served ) {
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	if ( '' !== $origin && ! headers_sent() ) {
		header( 'Vary: Origin', false );
		foreach ( rk_builder_cors_headers( $origin, rk_builder_allowed_origins() ) as $name => $value ) {
			if ( 'Vary' === $name ) { continue; }
			header( $name . ': ' . $value );
		}
	}
	return $served;
}
