<?php
/**
 * Same-origin admin screen: wp-admin > RK Builder opens the editor as a bare, full-page document
 * served from the WordPress origin. WordPress cookie auth + a REST nonce authenticate every API
 * call. The nonce is only ever printed for logged-in users who may edit pages (capability
 * `edit_pages`) and only while the builder is enabled in Settings > RK Builder.
 *
 * DEVIATION FROM THE SPEC (section 5.2, "enqueue builder assets inside wp-admin"): the editor is
 * emitted as a standalone document from the `load-<hook>` action instead of being enqueued into
 * the wp-admin chrome. The React app ships global CSS resets (html/body/*, box-sizing, fonts) that
 * would collide with core's admin styles in both directions, so it gets its own clean document.
 * Everything else the spec asks for is kept: dedicated root element, REST root, nonce, current
 * user and configuration passed through window.RK_BUILDER_BOOT, no secrets exposed.
 *
 * Deep link: admin.php?page=rk-builder&page_id=42 (the app reads `page_id`; the boot object also
 * carries the validated `initialPageId`). The Pages list gets an "Open in RK Builder" row action
 * and the classic editor's Publish box gets a link; the block editor is covered by the list action.
 *
 * App asset URLs (rk_builder_app_url / rk_builder_app_css_url) live in includes/assets.php.
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

/** Builder switched on in Settings > RK Builder (default: on). */
function rk_builder_is_enabled() {
	return (bool) rk_builder_setting( 'enabled', true );
}

/** Where "Preview link" sends editors: the public frontend (RK_BUILDER_FRONTEND_URL), else this site. */
function rk_builder_frontend_url() {
	$url = defined( 'RK_BUILDER_FRONTEND_URL' ) ? (string) RK_BUILDER_FRONTEND_URL : home_url( '/' );
	return esc_url_raw( (string) apply_filters( 'rk_builder_frontend_url', $url ), array( 'http', 'https' ) );
}

/** admin.php?page=rk-builder[&page_id=N]. */
function rk_builder_edit_link( $page_id = 0 ) {
	$url = admin_url( 'admin.php?page=rk-builder' );
	return $page_id > 0 ? $url . '&page_id=' . (int) $page_id : $url;
}

/** The page id from ?page_id=, only when it is a page the current user may edit; else 0. */
function rk_builder_requested_page_id() {
	if ( ! isset( $_GET['page_id'] ) || ! is_scalar( $_GET['page_id'] ) ) { return 0; } // phpcs:ignore WordPress.Security.NonceVerification -- read-only deep link.
	$raw = (string) wp_unslash( $_GET['page_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( 1 !== preg_match( '/^[1-9][0-9]{0,9}\z/', $raw ) ) { return 0; }
	$id   = (int) $raw;
	$post = get_post( $id );
	if ( ! $post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) { return 0; }
	return $id;
}

/** The boot object the app reads from window.RK_BUILDER_BOOT, or null if the user may not use it. */
function rk_builder_boot_data() {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) || ! rk_builder_is_enabled() ) { return null; }
	$uid  = (int) get_current_user_id();
	$user = get_userdata( $uid );
	$page = rk_builder_requested_page_id();
	return array(
		'mode'          => 'nonce',
		'apiBase'       => rest_url( 'rk/v1/' ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'currentUser'   => array(
			'id'           => $uid,
			'name'         => ( $user && isset( $user->display_name ) ) ? (string) $user->display_name : '',
			'capabilities' => array(
				'manageTheme' => (bool) current_user_can( 'manage_options' ),
				'publish'     => (bool) current_user_can( 'publish_pages' ),
			),
		),
		'publicSiteUrl' => rk_builder_frontend_url(),
		'adminUrl'      => admin_url( 'admin.php?page=rk-builder' ),
		'initialPageId' => $page > 0 ? $page : null,
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
		$html .= '<div role="alert" style="max-width:640px;margin:48px auto;padding:16px 20px;font-family:sans-serif;line-height:1.5;border-left:4px solid #d63638;background:#fcf0f1;color:#1d2327">';
		$html .= '<strong>' . esc_html__( 'RK Builder cannot start', 'rk-builder' ) . '</strong><br>' . esc_html( rk_builder_missing_assets_message() );
		$html .= '<br><a href="' . esc_url( admin_url( 'index.php' ) ) . '">' . esc_html__( 'Back to the dashboard', 'rk-builder' ) . '</a></div>';
	}
	$html .= '</div><script>window.RK_BUILDER_BOOT = ' . $json . ';</script>';
	if ( '' !== $app ) {
		$html .= '<script type="module" crossorigin src="' . esc_url( $app ) . '"></script>';
	}
	return $html . '</body></html>';
}

function rk_builder_render_standalone() {
	if ( ! current_user_can( 'edit_pages' ) ) { return; } // fall through to core's permission screen
	if ( ! rk_builder_is_enabled() ) {
		$msg = esc_html__( 'RK Builder is disabled in the plugin settings.', 'rk-builder' );
		if ( current_user_can( 'manage_options' ) ) {
			$msg .= ' <a href="' . esc_url( admin_url( 'options-general.php?page=rk-builder-settings' ) ) . '">' . esc_html__( 'Open Settings > RK Builder to enable it.', 'rk-builder' ) . '</a>';
		}
		wp_die( $msg, esc_html__( 'RK Builder is disabled', 'rk-builder' ), array( 'response' => 403 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}
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

/** Whether the "Open in RK Builder" entry points may be shown for this post. */
function rk_builder_can_open_page( $post ) {
	return is_object( $post ) && isset( $post->ID, $post->post_type ) && 'page' === $post->post_type
		&& rk_builder_is_enabled() && current_user_can( 'edit_pages' ) && current_user_can( 'edit_post', (int) $post->ID );
}

/** Pages list row action (filter `page_row_actions`). */
function rk_builder_page_row_actions( $actions, $post = null ) {
	if ( ! is_array( $actions ) || ! rk_builder_can_open_page( $post ) ) { return $actions; }
	$actions['rk_builder'] = '<a href="' . esc_url( rk_builder_edit_link( (int) $post->ID ) ) . '">' . esc_html__( 'Open in RK Builder', 'rk-builder' ) . '</a>';
	return $actions;
}

/** Link in the classic editor's Publish box (action `post_submitbox_misc_actions`). */
function rk_builder_submitbox_link( $post = null ) {
	if ( ! rk_builder_can_open_page( $post ) ) { return; }
	echo '<div class="misc-pub-section rk-builder-open"><a href="' . esc_url( rk_builder_edit_link( (int) $post->ID ) ) . '">' . esc_html__( 'Open in RK Builder', 'rk-builder' ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
}
