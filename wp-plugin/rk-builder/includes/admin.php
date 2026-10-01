<?php
/**
 * Same-origin admin screen: wp-admin > RK Builder opens the editor as a bare, full-page document
 * (no wp-admin chrome, so the app's CSS cannot collide with core styles) served from the WordPress
 * origin. WordPress cookie auth + a REST nonce authenticate every API call. The nonce is only ever
 * printed for logged-in users who may edit pages.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_register_admin_menu() {
	$hook = add_menu_page( 'RK Builder', 'RK Builder', 'edit_pages', 'rk-builder', 'rk_builder_render_admin_page', 'dashicons-layout', 58 );
	if ( $hook ) {
		// Runs before wp-admin prints its header, so we can answer with the standalone document.
		add_action( 'load-' . $hook, 'rk_builder_render_standalone' );
	}
}

/** URL of the built editor bundle (module script): constant, then option, then filter. */
function rk_builder_app_url() {
	$url = defined( 'RK_BUILDER_APP_URL' ) ? (string) RK_BUILDER_APP_URL : (string) get_option( 'rk_builder_app_url', '' );
	$url = (string) apply_filters( 'rk_builder_app_url', $url );
	return esc_url_raw( $url, array( 'http', 'https' ) );
}

/** URL of the editor stylesheet: RK_BUILDER_APP_CSS_URL, else the bundle URL with a .css extension. */
function rk_builder_app_css_url() {
	$app = rk_builder_app_url();
	$css = defined( 'RK_BUILDER_APP_CSS_URL' ) ? (string) RK_BUILDER_APP_CSS_URL : preg_replace( '/\.js(\?.*)?$/', '.css$1', $app );
	return esc_url_raw( (string) apply_filters( 'rk_builder_app_css_url', $css ), array( 'http', 'https' ) );
}

/** Where "Preview link" sends editors: the public frontend (RK_BUILDER_FRONTEND_URL), else this site. */
function rk_builder_frontend_url() {
	$url = defined( 'RK_BUILDER_FRONTEND_URL' ) ? (string) RK_BUILDER_FRONTEND_URL : home_url( '/' );
	return esc_url_raw( (string) apply_filters( 'rk_builder_frontend_url', $url ), array( 'http', 'https' ) );
}

/** The boot object the app reads from window.RK_BUILDER_BOOT, or null if the user may not use it. */
function rk_builder_boot_data() {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) ) { return null; }
	return array(
		'mode'          => 'nonce',
		'apiBase'       => rest_url( 'rk/v1/' ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'publicSiteUrl' => rk_builder_frontend_url(),
		'adminUrl'      => admin_url( 'admin.php?page=rk-builder' ),
	);
}

/** The complete HTML document. Returns '' when the current user may not see it. */
function rk_builder_standalone_document() {
	$boot = rk_builder_boot_data();
	if ( null === $boot ) { return ''; }
	$json = wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	$app  = rk_builder_app_url();
	$css  = rk_builder_app_css_url();

	$html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
	$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
	$html .= '<meta name="robots" content="noindex, nofollow"><title>RK Builder</title>';
	$html .= '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	$html .= '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">';
	if ( '' !== $css ) {
		$html .= '<link rel="stylesheet" href="' . esc_url( $css ) . '">';
	}
	$html .= '</head><body><div id="root">';
	if ( '' === $app ) {
		$html .= '<p style="padding:24px;font-family:sans-serif">' . esc_html__( 'RK Builder: set the RK_BUILDER_APP_URL constant (or the rk_builder_app_url option) to the URL of the built editor app.', 'rk-builder' ) . '</p>';
	}
	$html .= '</div><script>window.RK_BUILDER_BOOT = ' . $json . ';</script>';
	if ( '' !== $app ) {
		$html .= '<script type="module" crossorigin src="' . esc_url( $app ) . '"></script>';
	}
	return $html . '</body></html>';
}

function rk_builder_render_standalone() {
	if ( ! current_user_can( 'edit_pages' ) ) { return; } // fall through to core's permission screen
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo rk_builder_standalone_document(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts / JSON_HEX_* encoded JSON.
	exit;
}

/** Fallback if the load- hook did not fire (e.g. embedded by another plugin). */
function rk_builder_render_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'rk-builder' ), '', array( 'response' => 403 ) );
	}
	echo '<div class="wrap"><p>' . esc_html__( 'Opening RK Builder…', 'rk-builder' ) . '</p></div>';
}
