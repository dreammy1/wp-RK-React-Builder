<?php
/**
 * Content types (service, portfolio + their category taxonomies), featured-image REST field,
 * and the public, unauthenticated read endpoints: /public/page/{slug} and /content/{type}.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------------ *
 * CPTs / taxonomies
 * ------------------------------------------------------------------ */

function rk_builder_register_content_types() {
	if ( rk_builder_setting( 'enable_service_cpt', true ) ) {
		register_post_type( 'service', array(
			'labels'       => array( 'name' => 'Services', 'singular_name' => 'Service' ),
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'service',
			'menu_icon'    => 'dashicons-hammer',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
		) );
		register_taxonomy( 'service_cat', 'service', array(
			'labels'       => array( 'name' => 'Service Categories', 'singular_name' => 'Service Category' ),
			'public'       => true,
			'show_in_rest' => true,
			'hierarchical' => true,
		) );
	}
	if ( rk_builder_setting( 'enable_portfolio_cpt', true ) ) {
		register_post_type( 'portfolio', array(
			'labels'       => array( 'name' => 'Portfolio', 'singular_name' => 'Project' ),
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'portfolio',
			'menu_icon'    => 'dashicons-portfolio',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
		) );
		register_taxonomy( 'portfolio_cat', 'portfolio', array(
			'labels'       => array( 'name' => 'Project Categories', 'singular_name' => 'Project Category' ),
			'public'       => true,
			'show_in_rest' => true,
			'hierarchical' => true,
		) );
	}
}

/** type => taxonomy, for the content types enabled in the settings (both by default). */
function rk_builder_content_types() {
	$types = array();
	if ( rk_builder_setting( 'enable_service_cpt', true ) ) { $types['service'] = 'service_cat'; }
	if ( rk_builder_setting( 'enable_portfolio_cpt', true ) ) { $types['portfolio'] = 'portfolio_cat'; }
	return $types;
}

/** Featured image info `{url,width,height,alt,srcset?}` or null. */
function rk_builder_featured_image( $post_id ) {
	$thumb = get_post_thumbnail_id( $post_id );
	if ( ! $thumb ) { return null; }
	$src = wp_get_attachment_image_src( $thumb, 'large' );
	if ( ! $src || empty( $src[0] ) ) { return null; }
	$image = array(
		'url'    => (string) $src[0],
		'width'  => (int) $src[1],
		'height' => (int) $src[2],
		'alt'    => rk_builder_plain( get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ),
	);
	$srcset = wp_get_attachment_image_srcset( $thumb, 'large' );
	if ( is_string( $srcset ) && '' !== $srcset ) { $image['srcset'] = $srcset; }
	return $image;
}

/** Inline featured image on the core CPT REST responses (saves a request per grid item). */
function rk_builder_register_rest_fields() {
	foreach ( array_keys( rk_builder_content_types() ) as $type ) {
		register_rest_field( $type, 'featured_image_url', array(
			'get_callback' => function ( $obj ) {
				$id = get_post_thumbnail_id( $obj['id'] );
				return $id ? (string) wp_get_attachment_image_url( $id, 'large' ) : '';
			},
			'schema'       => array( 'type' => 'string', 'readonly' => true ),
		) );
		register_rest_field( $type, 'featured_image', array(
			'get_callback' => function ( $obj ) { return rk_builder_featured_image( $obj['id'] ); },
			'schema'       => array( 'type' => array( 'object', 'null' ), 'readonly' => true ),
		) );
	}
}

/* ------------------------------------------------------------------ *
 * GET /content/{type}
 * ------------------------------------------------------------------ */

function rk_builder_validate_content_limit( $v ) { return rk_builder_validate_positive_int( $v ) && (int) $v <= 24; }
function rk_builder_validate_content_category( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-z0-9-]{0,60}\z/', $v ); }
function rk_builder_validate_content_orderby( $v ) { return is_string( $v ) && in_array( $v, array( 'date', 'title', 'menu_order' ), true ); }
function rk_builder_validate_content_order( $v ) { return is_string( $v ) && in_array( strtolower( $v ), array( 'asc', 'desc' ), true ); }

/**
 * Shared by GET /content/{type} and the PHP grid renderer: PUBLISHED, non-password-protected posts only.
 *
 * @param string $type One of rk_builder_content_types().
 * @param array  $opts limit (1..24), category (slug or ''), orderby (date|title|menu_order), order (asc|desc).
 * @return array{items:array[],total:int}|null null for an unknown/disabled type.
 */
function rk_builder_query_content( $type, array $opts = array() ) {
	$types = rk_builder_content_types();
	if ( ! is_string( $type ) || ! isset( $types[ $type ] ) ) { return null; }
	$limit    = isset( $opts['limit'] ) ? max( 1, min( 24, (int) $opts['limit'] ) ) : (int) rk_builder_setting( 'default_grid_limit', 6 );
	$category = isset( $opts['category'] ) && is_string( $opts['category'] ) && rk_builder_validate_content_category( $opts['category'] ) ? $opts['category'] : '';
	$orderby  = isset( $opts['orderby'] ) && rk_builder_validate_content_orderby( $opts['orderby'] ) ? $opts['orderby'] : 'date';
	$order    = isset( $opts['order'] ) && rk_builder_validate_content_order( $opts['order'] ) ? strtoupper( $opts['order'] ) : ( 'date' === $orderby ? 'DESC' : 'ASC' );

	$args = array(
		'post_type'           => $type,
		'post_status'         => 'publish',
		'has_password'        => false,
		'posts_per_page'      => $limit,
		'orderby'             => $orderby,
		'order'               => $order,
		'ignore_sticky_posts' => true,
	);
	if ( 'menu_order' === $orderby ) { $args['orderby'] = array( 'menu_order' => $order, 'title' => 'ASC' ); }
	if ( '' !== $category ) {
		$args['tax_query'] = array( array( 'taxonomy' => $types[ $type ], 'field' => 'slug', 'terms' => array( $category ) ) );
	}
	$query = new WP_Query( $args );
	$items = array();
	foreach ( $query->posts as $post ) {
		$slugs   = wp_get_object_terms( $post->ID, $types[ $type ], array( 'fields' => 'slugs' ) );
		$items[] = array(
			'id'         => (int) $post->ID,
			'title'      => rk_builder_plain( get_the_title( $post ) ),
			'excerpt'    => rk_builder_excerpt( $post ),
			'link'       => (string) get_permalink( $post->ID ),
			'categories' => is_wp_error( $slugs ) ? array() : array_values( array_map( 'strval', (array) $slugs ) ),
			'image'      => rk_builder_featured_image( $post->ID ),
		);
	}
	return array( 'items' => $items, 'total' => (int) $query->found_posts );
}

function rk_builder_handle_content( $req ) {
	$types = rk_builder_content_types();
	$type  = (string) $req['type'];
	if ( ! isset( $types[ $type ] ) ) { return rk_builder_not_found( 'Unknown content type.' ); }

	// Re-validate (route-level validation does not run when the handler is called directly).
	$checks = array(
		'limit'    => 'rk_builder_validate_content_limit',
		'category' => 'rk_builder_validate_content_category',
		'orderby'  => 'rk_builder_validate_content_orderby',
		'order'    => 'rk_builder_validate_content_order',
	);
	foreach ( $checks as $name => $fn ) {
		$v = $req->get_param( $name );
		if ( null !== $v && ! $fn( $v ) ) {
			return new WP_Error( 'rest_invalid_param', 'Invalid parameter: ' . $name, array( 'status' => 400, 'params' => array( $name => 'Invalid value.' ) ) );
		}
	}
	$result = rk_builder_query_content( $type, array(
		'limit'    => null !== $req->get_param( 'limit' ) ? (int) $req->get_param( 'limit' ) : (int) rk_builder_setting( 'default_grid_limit', 6 ),
		'category' => (string) $req->get_param( 'category' ),
		'orderby'  => null !== $req->get_param( 'orderby' ) ? (string) $req->get_param( 'orderby' ) : 'date',
		'order'    => null !== $req->get_param( 'order' ) ? (string) $req->get_param( 'order' ) : null,
	) );
	$response = rest_ensure_response( $result );
	$response->header( 'Cache-Control', 'public, max-age=0, s-maxage=60' );
	return $response;
}

function rk_builder_excerpt( $post ) {
	$text = trim( (string) $post->post_excerpt );
	if ( '' === $text ) { $text = wp_trim_words( strip_shortcodes( (string) $post->post_content ), 30, '…' ); }
	return rk_builder_plain( $text );
}

/* ------------------------------------------------------------------ *
 * GET /public/page/{slug}
 * ------------------------------------------------------------------ */

/** Meta description: <=160 chars, from the excerpt else the first text/hero block. */
function rk_builder_describe( $excerpt, array $layout ) {
	$text = trim( (string) $excerpt );
	if ( '' === $text ) {
		foreach ( $layout['blocks'] as $block ) {
			if ( 'text' === $block['type'] ) {
				$text = $block['props']['text'];
			} elseif ( 'hero' === $block['type'] ) {
				$text = '' !== trim( $block['props']['sub'] ) ? $block['props']['sub'] : $block['props']['heading'];
			}
			if ( '' !== trim( $text ) ) { break; }
		}
	}
	$text = trim( (string) preg_replace( '/\s+/u', ' ', rk_builder_plain( $text ) ) );
	$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	if ( $len > 160 ) {
		$text = function_exists( 'mb_substr' ) ? rtrim( mb_substr( $text, 0, 159, 'UTF-8' ) ) . '…' : rtrim( substr( $text, 0, 159 ) ) . '…';
	}
	return $text;
}

function rk_builder_handle_public_page( $req ) {
	$slug = (string) $req['slug'];
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,200}\z/', $slug ) ) { return rk_builder_not_found( 'Page not found.' ); }
	$candidates = get_posts( array(
		'name'             => strtolower( $slug ),
		'post_type'        => 'page',
		'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'   => 10,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => true,
	) );
	$token = $req->get_param( 'preview' );

	$page    = null;
	$preview = false;
	if ( null !== $token ) {
		// A preview was asked for: it must be valid, otherwise 404. Never fall back to the published snapshot.
		$token = is_string( $token ) ? $token : '';
		foreach ( $candidates as $c ) {
			if ( '' !== $token && rk_builder_verify_preview_token( $token, (int) $c->ID ) ) { $page = $c; $preview = true; break; }
		}
		if ( ! $page ) { return rk_builder_error( 'rk_preview_invalid', 'The preview link is invalid or has expired.', 404 ); }
	} else {
		foreach ( $candidates as $c ) {
			if ( 'publish' === $c->post_status && '' === (string) $c->post_password ) { $page = $c; break; }
		}
	}
	// Unpublished, private, password protected and missing pages are indistinguishable.
	if ( ! $page ) { return rk_builder_not_found( 'Page not found.' ); }

	if ( $preview ) {
		$layout   = rk_builder_get_draft_layout( $page->ID );
		$revision = rk_builder_get_revision( $page->ID );
	} else {
		$layout = rk_builder_get_published_layout( $page->ID );
		if ( null === $layout ) { return rk_builder_not_found( 'Page not found.' ); }
		$published = rk_builder_get_published_revision( $page->ID );
		$revision  = null === $published ? 0 : $published;
	}
	$image = rk_builder_featured_image( $page->ID );
	$data  = array(
		'page'     => array(
			'id'          => (int) $page->ID,
			'title'       => rk_builder_plain( get_the_title( $page ) ),
			'slug'        => (string) $page->post_name,
			'description' => rk_builder_describe( $page->post_excerpt, $layout ),
			'modified'    => $preview ? rk_builder_page_modified( $page ) : ( rk_builder_get_published_at( $page->ID ) ?: rk_builder_mysql_gmt_to_iso( $page->post_modified_gmt ) ),
			'image'       => $image ? $image['url'] : null,
		),
		'layout'   => $layout,
		'theme'    => rk_builder_theme_for_output( rk_builder_get_theme() ),
		'revision' => $revision,
	);
	if ( $preview ) { $data['preview'] = true; }
	$response = rest_ensure_response( $data );
	$response->header( 'Cache-Control', $preview ? 'no-store' : 'public, max-age=0, s-maxage=60' );
	return $response;
}
