<?php
/**
 * Short-lived preview tokens: base64url(payload).base64url(HMAC-SHA256(payload_b64)).
 * payload = {"p": pageId, "e": expiryUnix}. Secret: RK_BUILDER_PREVIEW_SECRET or wp_salt('auth').
 * Tokens are bearer credentials: they are never logged by this plugin.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_PREVIEW_TTL' ) ) { define( 'RK_BUILDER_PREVIEW_TTL', 900 ); }

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

function rk_builder_preview_ttl() {
	return max( 30, (int) apply_filters( 'rk_builder_preview_ttl', RK_BUILDER_PREVIEW_TTL ) );
}

/** @return array{token:string,expiresAt:string} */
function rk_builder_create_preview_token( $page_id ) {
	$expires = rk_builder_now() + rk_builder_preview_ttl();
	$payload = rk_builder_b64url_encode( wp_json_encode( array( 'p' => (int) $page_id, 'e' => $expires ) ) );
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
	return $data['p'] === (int) $page_id && $data['e'] >= rk_builder_now();
}
