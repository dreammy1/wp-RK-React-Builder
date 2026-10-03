<?php
/**
 * Global site settings (option `rk_builder_global`), edited in the dashboard under "Global settings".
 *
 *   layout        content width and side gutters of every RK Builder page (CSS custom properties)
 *   admin bar     what the WordPress toolbar shows on the public site: default | builder | hidden
 *   theme styles  whether the active WordPress theme's stylesheets load on RK Builder's standalone documents
 *   clean-ups     emoji script, embeds, block-library CSS, generator / RSD / shortlink tags
 *
 *   GET  /builder/global   → { global }
 *   POST /builder/global   → { global }  (any subset of the keys)       administrators only
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_global_defaults() {
	return array(
		'layout_width'    => 1144,   // px, 640..1920: width of the content column
		'gutter'          => 28,     // px, 0..120: least space at each side on a computer
		'gutter_mobile'   => 16,     // px, 0..60: least space at each side on a phone
		'admin_bar'       => 'default', // default | builder | hidden (public site only)
		'theme_styles'    => true,   // load the WordPress theme's CSS on standalone RK documents
		'no_emojis'       => false,
		'no_embeds'       => false,
		'no_block_css'    => false,
		'no_head_clutter' => false,
	);
}

function rk_builder_global_sanitize( $in, $base = null ) {
	$d   = rk_builder_global_defaults();
	$out = is_array( $base ) ? array_merge( $d, array_intersect_key( $base, $d ) ) : $d;
	$in  = is_array( $in ) ? $in : array();
	$int = function ( $k, $min, $max ) use ( $in, &$out ) {
		if ( array_key_exists( $k, $in ) && is_numeric( $in[ $k ] ) && (int) $in[ $k ] == $in[ $k ] ) { $out[ $k ] = max( $min, min( $max, (int) $in[ $k ] ) ); }
	};
	$int( 'layout_width', 640, 1920 );
	$int( 'gutter', 0, 120 );
	$int( 'gutter_mobile', 0, 60 );
	if ( array_key_exists( 'admin_bar', $in ) && is_string( $in['admin_bar'] ) && in_array( $in['admin_bar'], array( 'default', 'builder', 'hidden' ), true ) ) { $out['admin_bar'] = $in['admin_bar']; }
	foreach ( array( 'theme_styles', 'no_emojis', 'no_embeds', 'no_block_css', 'no_head_clutter' ) as $k ) {
		if ( array_key_exists( $k, $in ) ) { $out[ $k ] = ! empty( $in[ $k ] ); }
	}
	return $out;
}

function rk_builder_global() {
	$s = get_option( 'rk_builder_global', array() );
	return rk_builder_global_sanitize( is_array( $s ) ? $s : array() );
}

/** Custom properties for the content column and its side gutters (used by site.css, with the same defaults as fallbacks). */
function rk_builder_global_css( $g = null ) {
	$g = null === $g ? rk_builder_global() : $g;
	return '.rk-root{--site-container:' . (int) $g['layout_width'] . 'px;--site-gutter:' . (int) $g['gutter'] . 'px;--site-gutter-m:' . (int) $g['gutter_mobile'] . 'px}';
}

/* ------------------------------------------------------------------ *
 * REST
 * ------------------------------------------------------------------ */

function rk_builder_handle_get_global( $req ) {
	return rk_builder_no_store( array( 'global' => rk_builder_global() ) );
}

function rk_builder_handle_set_global( $req ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_global' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	$known  = array_keys( rk_builder_global_defaults() );
	foreach ( $body as $k => $v ) {
		if ( ! in_array( (string) $k, $known, true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	$ranges = array( 'layout_width' => array( 640, 1920 ), 'gutter' => array( 0, 120 ), 'gutter_mobile' => array( 0, 60 ) );
	foreach ( $ranges as $k => $r ) {
		if ( array_key_exists( $k, $body ) && ( ! is_numeric( $body[ $k ] ) || (int) $body[ $k ] != $body[ $k ] || $body[ $k ] < $r[0] || $body[ $k ] > $r[1] ) ) { rk_builder_add_issue( $issues, $k, 'Use a whole number from ' . $r[0] . ' to ' . $r[1] ); }
	}
	if ( array_key_exists( 'admin_bar', $body ) && ( ! is_string( $body['admin_bar'] ) || ! in_array( $body['admin_bar'], array( 'default', 'builder', 'hidden' ), true ) ) ) { rk_builder_add_issue( $issues, 'admin_bar', 'Choose default, builder or hidden' ); }
	foreach ( array( 'theme_styles', 'no_emojis', 'no_embeds', 'no_block_css', 'no_head_clutter' ) as $k ) {
		if ( array_key_exists( $k, $body ) && ! is_bool( $body[ $k ] ) ) { rk_builder_add_issue( $issues, $k, 'Expected true or false' ); }
	}
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_global', $issues ); }
	$next = rk_builder_global_sanitize( $body, rk_builder_global() );
	update_option( 'rk_builder_global', $next, false );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return rk_builder_no_store( array( 'global' => $next ) );
}

function rk_builder_register_global_routes( $ns ) {
	register_rest_route( $ns, '/builder/global', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_global', 'permission_callback' => 'rk_builder_perm_theme_write' ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_global', 'permission_callback' => 'rk_builder_perm_theme_write' ),
	) );
}

/* ------------------------------------------------------------------ *
 * Admin bar on the public site
 * ------------------------------------------------------------------ */

/** Which toolbar items survive the "builder" style: the site name, the account menu and anything of ours. */
function rk_builder_adminbar_keeps( $id, $parent ) {
	$id     = (string) $id;
	$parent = (string) $parent;
	if ( in_array( $id, array( 'site-name', 'my-account', 'top-secondary', 'user-actions', 'user-info', 'logout', 'edit-profile' ), true ) ) { return true; }
	if ( in_array( $parent, array( 'site-name', 'my-account', 'user-actions', 'top-secondary' ), true ) ) { return true; }
	return 0 === strpos( $id, 'rk-' ) || 0 === strpos( $parent, 'rk-' );
}

function rk_builder_adminbar_show( $show ) {
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || 'hidden' !== rk_builder_global()['admin_bar'] ) { return $show; }
	return false;
}

/** The builder URL for the page being viewed, or '' (builder pages only, for people who may edit them). */
function rk_builder_adminbar_edit_target() {
	$base = admin_url( 'admin.php?page=rk-builder' );
	$out  = array();
	$dyn  = function_exists( 'rk_builder_dyn_request' ) ? rk_builder_dyn_request() : null;
	if ( is_array( $dyn ) && isset( $dyn['tpl']['id'] ) && current_user_can( 'manage_options' ) ) {
		$names = array( 'single' => 'Edit single template', 'archive' => 'Edit listing template', 'notfound' => 'Edit 404 template' );
		$out[] = array( 'rk-edit', isset( $names[ $dyn['kind'] ] ) ? $names[ $dyn['kind'] ] : 'Edit template', $base . '&page_id=' . (int) $dyn['tpl']['id'] );
	}
	if ( function_exists( 'is_singular' ) && is_singular( 'page' ) ) {
		$id = (int) get_queried_object_id();
		if ( $id > 0 && current_user_can( 'edit_post', $id ) && ( '' !== (string) get_post_meta( $id, '_rk_layout_draft', true ) || '' !== (string) get_post_meta( $id, '_rk_layout_published', true ) ) ) {
			array_unshift( $out, array( 'rk-edit', 'Edit with RK Builder', $base . '&page_id=' . $id ) );
		}
	}
	if ( function_exists( 'is_singular' ) && is_singular() && ! is_singular( 'page' ) ) {
		$p = get_queried_object();
		if ( $p && isset( $p->post_type ) && current_user_can( 'edit_post', (int) $p->ID ) ) { $out[] = array( 'rk-entry', 'Edit entry in RK Builder', $base . '&view=content&type=' . rawurlencode( (string) $p->post_type ) ); }
	}
	foreach ( array( 'header' => 'Edit header', 'footer' => 'Edit footer' ) as $kind => $label ) {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'rk_builder_tpl_find' ) ) { break; }
		$t = rk_builder_tpl_find( $kind, '' );
		if ( $t ) { $out[] = array( 'rk-' . $kind, $label, $base . '&page_id=' . (int) $t['id'] ); }
	}
	return $out;
}

function rk_builder_adminbar_menu( $bar ) {
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! is_object( $bar ) || ! rk_builder_setting( 'enabled', true ) ) { return; }
	$mode = rk_builder_global()['admin_bar'];
	if ( 'builder' === $mode && method_exists( $bar, 'get_nodes' ) ) {
		foreach ( (array) $bar->get_nodes() as $node ) {
			if ( ! rk_builder_adminbar_keeps( $node->id, isset( $node->parent ) ? $node->parent : '' ) ) { $bar->remove_node( $node->id ); }
		}
	}
	if ( ! current_user_can( 'edit_pages' ) ) { return; }
	$items = rk_builder_adminbar_edit_target();
	$items[] = array( 'rk-dashboard', 'RK Builder dashboard', admin_url( 'admin.php?page=rk-builder' ) );
	foreach ( $items as $it ) { $bar->add_node( array( 'id' => $it[0], 'title' => esc_html( $it[1] ), 'href' => esc_url( $it[2] ) ) ); }
}

/* ------------------------------------------------------------------ *
 * Theme styles and clean-ups (public site)
 * ------------------------------------------------------------------ */

/** Is this request drawn by one of RK Builder's standalone documents? */
function rk_builder_is_standalone_request() {
	if ( function_exists( 'is_admin' ) && is_admin() ) { return false; }
	if ( function_exists( 'rk_builder_dyn_request' ) && rk_builder_dyn_request() ) { return true; }
	return 'standalone' === rk_builder_rendering_mode() && null !== rk_builder_current_request_page();
}

function rk_builder_block_css_handles() { return array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles', 'wc-blocks-style' ); }

function rk_builder_global_trim_styles() {
	if ( function_exists( 'is_admin' ) && is_admin() ) { return; }
	$g = rk_builder_global();
	$drop = array();
	if ( $g['no_block_css'] ) { $drop = array_merge( $drop, rk_builder_block_css_handles() ); }
	if ( ! $g['theme_styles'] && rk_builder_is_standalone_request() ) {
		$drop = array_merge( $drop, rk_builder_block_css_handles() );
		$styles = function_exists( 'wp_styles' ) ? wp_styles() : null;
		$roots  = array( function_exists( 'get_stylesheet_directory_uri' ) ? get_stylesheet_directory_uri() : '', function_exists( 'get_template_directory_uri' ) ? get_template_directory_uri() : '' );
		if ( $styles && ! empty( $styles->registered ) ) {
			foreach ( $styles->registered as $handle => $style ) {
				$src = is_object( $style ) && isset( $style->src ) && is_string( $style->src ) ? $style->src : '';
				foreach ( $roots as $r ) { if ( '' !== $r && '' !== $src && 0 === strpos( $src, $r ) ) { $drop[] = $handle; } }
			}
		}
	}
	foreach ( array_unique( $drop ) as $h ) {
		if ( 0 === strpos( (string) $h, 'rk-builder' ) ) { continue; }
		wp_dequeue_style( $h );
		wp_deregister_style( $h );
	}
}

function rk_builder_global_cleanups() {
	$g = rk_builder_global();
	if ( $g['no_emojis'] ) {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}
	if ( $g['no_embeds'] ) {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_action( 'wp_enqueue_scripts', function () { wp_dequeue_script( 'wp-embed' ); wp_deregister_script( 'wp-embed' ); }, 100 );
	}
	if ( $g['no_head_clutter'] ) {
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
	}
}

function rk_builder_register_global_hooks() {
	add_filter( 'show_admin_bar', 'rk_builder_adminbar_show', 99 );
	add_action( 'admin_bar_menu', 'rk_builder_adminbar_menu', 9999 );
	add_action( 'wp_enqueue_scripts', 'rk_builder_global_trim_styles', 100 );
	add_action( 'init', 'rk_builder_global_cleanups', 20 );
}
rk_builder_register_global_hooks();
