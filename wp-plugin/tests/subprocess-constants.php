<?php
/**
 * Helper for tests that need a PHP constant defined (constants cannot be undefined, so they run in a
 * fresh process): php subprocess-constants.php <mode>. Prints one JSON object.
 *   headless   RK_BUILDER_FRONTEND_URL defined -> preview-token response has no "url"
 *   ttl        RK_BUILDER_PREVIEW_TTL=77 beats the preview_ttl setting
 *   seo:<C>    constant <C> (e.g. WPSEO_VERSION) defined -> which SEO plugin is detected, and is anything printed in wp_head?
 */
error_reporting( E_ALL );
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );
$mode = isset( $argv[1] ) ? $argv[1] : '';
require __DIR__ . '/wp-stubs.php';
rk_test_reset();
define( 'RK_BUILDER_PREVIEW_SECRET', 'subprocess-secret' );
if ( 'headless' === $mode ) { define( 'RK_BUILDER_FRONTEND_URL', 'https://app.example.com' ); }
if ( 'ttl' === $mode ) { define( 'RK_BUILDER_PREVIEW_TTL', 77 ); }
if ( 0 === strpos( $mode, 'seo:' ) ) { define( substr( $mode, 4 ), '1.0' ); }
require dirname( __DIR__ ) . '/rk-builder/rk-builder.php';
do_action( 'init' );
do_action( 'rest_api_init' );
rk_test_reset();
$out = array();
if ( 'headless' === $mode || 'ttl' === $mode ) {
	$id = rk_test_page( 'draft', 'a', 2 );
	rk_test_login( 'editor' );
	if ( 'ttl' === $mode ) { update_option( 'rk_builder_settings', array( 'preview_ttl' => 500 ) ); }
	$res = rk_test_request( 'POST', '/rk/v1/builder/preview-token/' . $id );
	$d   = $res->get_data();
	$out = array( 'keys' => array_keys( $d ), 'ttl' => strtotime( $d['expiresAt'] ) - rk_builder_now() );
}
if ( 0 === strpos( $mode, 'seo:' ) ) {
	$id = rk_test_page( 'publish', 'p', 2 );
	$GLOBALS['RK']['meta'][ $id ]['_rk_layout_published'] = json_encode( array( 'version' => 1, 'blocks' => array() ) );
	add_action( 'wp', 'rk_builder_seo_setup' ); // rk_test_reset() cleared the hooks registered at load
	rk_test_set_query( array( 'singular' => true, 'id' => $id ) );
	do_action( 'wp' );
	ob_start();
	do_action( 'wp_head' );
	$out = array( 'plugin' => rk_builder_active_seo_plugin(), 'head' => ob_get_clean(), 'title' => apply_filters( 'document_title_parts', array( 'title' => 'T' ) ) );
}
echo json_encode( $out );
