<?php
/**
 * Plugin Name: RK Builder (Headless) — Layouts, Theme Config & CPTs
 * Description: The WordPress backend for a headless React page builder. Registers Portfolio + Service CPTs and REST endpoints that store/serve per-page layout JSON and a global theme config. Pairs with a Next.js frontend + the RK Builder editor. STUB / starting point.
 * Version: 0.1.0
 * Author: Rakib Hasan
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: rk-builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RK_BUILDER_VERSION', '0.1.0' );
define( 'RK_BUILDER_NS', 'rk/v1' );

/* ------------------------------------------------------------------ *
 * 1) Content types  (in production these come from RK Core; stubbed
 *    here so the endpoints have data to serve out of the box)
 * ------------------------------------------------------------------ */
add_action( 'init', function () {

	register_post_type( 'service', array(
		'labels'       => array( 'name' => 'Services', 'singular_name' => 'Service' ),
		'public'       => true,
		'show_in_rest' => true,            // exposes /wp-json/wp/v2/service
		'rest_base'    => 'service',
		'menu_icon'    => 'dashicons-hammer',
		'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
	) );

	register_post_type( 'portfolio', array(
		'labels'       => array( 'name' => 'Portfolio', 'singular_name' => 'Project' ),
		'public'       => true,
		'show_in_rest' => true,            // exposes /wp-json/wp/v2/portfolio
		'rest_base'    => 'portfolio',
		'menu_icon'    => 'dashicons-portfolio',
		'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
	) );

	register_taxonomy( 'portfolio_cat', 'portfolio', array(
		'labels'       => array( 'name' => 'Project Categories' ),
		'public'       => true,
		'show_in_rest' => true,
		'hierarchical' => true,
	) );
} );

/* Expose the featured-image URL inline on the CPT REST responses so the
 * React grids don't need a second request per item. */
add_action( 'rest_api_init', function () {
	foreach ( array( 'service', 'portfolio' ) as $type ) {
		register_rest_field( $type, 'featured_image_url', array(
			'get_callback' => function ( $obj ) {
				$id = get_post_thumbnail_id( $obj['id'] );
				return $id ? wp_get_attachment_image_url( $id, 'large' ) : '';
			},
			'schema' => array( 'type' => 'string' ),
		) );
	}
} );

/* ------------------------------------------------------------------ *
 * 2) REST endpoints: page layout + global theme config
 * ------------------------------------------------------------------ */
add_action( 'rest_api_init', function () {

	// ---- Page layout ----
	register_rest_route( RK_BUILDER_NS, '/builder/layout/(?P<id>\d+)', array(
		array(
			'methods'             => WP_REST_Server::READABLE,          // GET (public: SSR needs it)
			'callback'            => 'rk_builder_get_layout',
			'permission_callback' => '__return_true',
			'args'                => array( 'id' => array( 'validate_callback' => 'is_numeric' ) ),
		),
		array(
			'methods'             => WP_REST_Server::CREATABLE,         // POST (auth)
			'callback'            => 'rk_builder_save_layout',
			'permission_callback' => 'rk_builder_can_edit',
			'args'                => array( 'id' => array( 'validate_callback' => 'is_numeric' ) ),
		),
	) );

	// ---- Global theme config ----
	register_rest_route( RK_BUILDER_NS, '/theme-config', array(
		array(
			'methods'             => WP_REST_Server::READABLE,          // GET (public)
			'callback'            => 'rk_builder_get_theme',
			'permission_callback' => '__return_true',
		),
		array(
			'methods'             => WP_REST_Server::CREATABLE,         // POST (admin)
			'callback'            => 'rk_builder_save_theme',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		),
	) );
} );

/** Write auth for layout saves: must be able to edit the target page. */
function rk_builder_can_edit( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_pages' );
}

/* ---------------- layout callbacks ---------------- */

function rk_builder_get_layout( WP_REST_Request $req ) {
	$id  = (int) $req['id'];
	if ( ! get_post( $id ) ) {
		return new WP_Error( 'not_found', 'Page not found.', array( 'status' => 404 ) );
	}
	$raw = get_post_meta( $id, '_rk_layout', true );
	$layout = $raw ? json_decode( $raw, true ) : array( 'version' => 1, 'blocks' => array() );
	return rest_ensure_response( array(
		'id'     => $id,
		'title'  => get_the_title( $id ),
		'slug'   => get_post_field( 'post_name', $id ),
		'layout' => is_array( $layout ) ? $layout : array( 'version' => 1, 'blocks' => array() ),
	) );
}

function rk_builder_save_layout( WP_REST_Request $req ) {
	$id     = (int) $req['id'];
	$layout = $req->get_param( 'layout' );
	if ( ! is_array( $layout ) || ! isset( $layout['blocks'] ) || ! is_array( $layout['blocks'] ) ) {
		return new WP_Error( 'bad_layout', 'Expected { blocks: [...] }.', array( 'status' => 400 ) );
	}
	$clean = rk_builder_sanitize_layout( $layout );
	update_post_meta( $id, '_rk_layout', wp_slash( wp_json_encode( $clean ) ) );
	return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'blocks' => count( $clean['blocks'] ) ) );
}

/** Whitelist block types + recursively sanitize props. Never trust the client tree. */
function rk_builder_sanitize_layout( $layout ) {
	$allowed = array( 'hero', 'heading', 'text', 'image', 'cta', 'services', 'portfolio', 'spacer', 'columns' );
	$out = array( 'version' => 1, 'blocks' => array() );
	foreach ( (array) $layout['blocks'] as $b ) {
		$type = isset( $b['type'] ) ? sanitize_key( $b['type'] ) : '';
		if ( ! in_array( $type, $allowed, true ) ) { continue; }
		$props = array();
		foreach ( (array) ( $b['props'] ?? array() ) as $k => $v ) {
			$k = sanitize_key( $k );
			if ( is_string( $v ) ) {
				$props[ $k ] = ( 'url' === $k || 'href' === substr( $k, -4 ) || 'ctaHref' === $k ) ? esc_url_raw( $v ) : sanitize_text_field( $v );
			} elseif ( is_numeric( $v ) ) {
				$props[ $k ] = 0 + $v;
			} elseif ( is_bool( $v ) ) {
				$props[ $k ] = (bool) $v;
			}
			// (nested children for the "columns" block would be sanitized recursively here)
		}
		$out['blocks'][] = array(
			'type'  => $type,
			'id'    => isset( $b['id'] ) ? sanitize_key( $b['id'] ) : substr( md5( uniqid( '', true ) ), 0, 7 ),
			'props' => $props,
		);
	}
	return $out;
}

/* ---------------- theme-config callbacks ---------------- */

function rk_builder_get_theme() {
	$defaults = array(
		'primary' => '#2f6df6', 'bg' => '#ffffff', 'ink' => '#141a22',
		'font' => 'Inter', 'logo' => '', 'social' => array(),
		'header' => array( 'sticky' => true ), 'footer' => array( 'columns' => 3 ),
	);
	$cfg = get_option( 'rk_theme_config', array() );
	return rest_ensure_response( wp_parse_args( is_array( $cfg ) ? $cfg : array(), $defaults ) );
}

function rk_builder_save_theme( WP_REST_Request $req ) {
	$in  = (array) $req->get_json_params();
	$cfg = array(
		'primary' => sanitize_hex_color( $in['primary'] ?? '' ) ?: '#2f6df6',
		'bg'      => sanitize_hex_color( $in['bg'] ?? '' ) ?: '#ffffff',
		'ink'     => sanitize_hex_color( $in['ink'] ?? '' ) ?: '#141a22',
		'font'    => sanitize_text_field( $in['font'] ?? 'Inter' ),
		'logo'    => esc_url_raw( $in['logo'] ?? '' ),
		'social'  => array_map( 'esc_url_raw', (array) ( $in['social'] ?? array() ) ),
		'header'  => array( 'sticky' => ! empty( $in['header']['sticky'] ) ),
		'footer'  => array( 'columns' => max( 1, min( 4, (int) ( $in['footer']['columns'] ?? 3 ) ) ) ),
	);
	update_option( 'rk_theme_config', $cfg );
	return rest_ensure_response( array( 'ok' => true, 'config' => $cfg ) );
}

/* ------------------------------------------------------------------ *
 * 3) CORS for the decoupled frontend (lock this down to your domain)
 * ------------------------------------------------------------------ */
add_action( 'rest_api_init', function () {
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	add_filter( 'rest_pre_serve_request', function ( $served ) {
		$allowed = apply_filters( 'rk_builder_frontend_origin', 'https://your-frontend.example' );
		$origin  = get_http_origin();
		if ( $origin && $origin === $allowed ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Access-Control-Allow-Credentials: true' );
		}
		return $served;
	} );
}, 15 );

/* Flush rewrite rules on activation so the CPT routes register cleanly. */
register_activation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );
