<?php
/**
 * Revalidation webhook for the headless frontend (e.g. Next.js on-demand revalidation).
 * Enabled only when RK_BUILDER_REVALIDATE_URL and RK_BUILDER_REVALIDATE_SECRET are defined.
 * The secret goes in the X-RK-Revalidate-Secret header and is never logged.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @param string $type    'publish' | 'unpublish' | 'theme'
 * @param int    $page_id 0 for theme changes
 * @param string $slug
 * @return bool Whether a request was dispatched.
 */
function rk_builder_revalidate( $type, $page_id = 0, $slug = '' ) {
	if ( ! defined( 'RK_BUILDER_REVALIDATE_URL' ) || ! defined( 'RK_BUILDER_REVALIDATE_SECRET' ) ) { return false; }
	$url    = (string) RK_BUILDER_REVALIDATE_URL;
	$secret = (string) RK_BUILDER_REVALIDATE_SECRET;
	$url    = apply_filters( 'rk_builder_revalidate_url', $url, $type, $page_id );
	if ( '' === $url || '' === $secret || ! preg_match( '~^https?://~i', $url ) ) { return false; }
	$args = array(
		'blocking' => false,
		'timeout'  => 3,
		'headers'  => array(
			'Content-Type'           => 'application/json',
			'X-RK-Revalidate-Secret' => $secret,
		),
		'body'     => wp_json_encode( array( 'type' => (string) $type, 'pageId' => (int) $page_id, 'slug' => (string) $slug ) ),
	);
	$args = apply_filters( 'rk_builder_revalidate_args', $args, $type, $page_id );
	wp_remote_post( $url, $args ); // fire-and-forget; failures must never break a save
	return true;
}
