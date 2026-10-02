<?php
/**
 * REST plumbing: route registration, permission callbacks and shared helpers.
 *
 * Authentication is NOT implemented here: core's cookie + X-WP-Nonce and Application Passwords
 * establish the current user. We only enforce capabilities and always answer with WP_Errors that
 * carry a stable `rk_*` code and `data.status`.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_MAX_PAYLOAD' ) ) { define( 'RK_BUILDER_MAX_PAYLOAD', 256 * 1024 ); }

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */

function rk_builder_unauthorized() {
	return rk_builder_error( 'rk_unauthorized', 'You must be logged in.', 401 );
}

function rk_builder_forbidden( $message = 'You are not allowed to do that.' ) {
	return rk_builder_error( 'rk_forbidden', $message, 403 );
}

function rk_builder_not_found( $message = 'Not found.' ) {
	return rk_builder_error( 'rk_not_found', $message, 404 );
}

/** 400 with a list of {path,message}. $code: rk_invalid_layout | rk_invalid_theme. */
function rk_builder_invalid( $code, array $issues, $message = 'The submitted data is not valid.' ) {
	return rk_builder_error( $code, $message, 400, array( 'issues' => array_values( $issues ) ) );
}

function rk_builder_prefix_issues( array $issues, $prefix ) {
	foreach ( $issues as $i => $issue ) {
		$issues[ $i ]['path'] = '' === $issue['path'] ? $prefix : $prefix . '.' . $issue['path'];
	}
	return $issues;
}

/** The page post, or null unless it is a real (non-trashed) `page`. */
function rk_builder_get_page( $id ) {
	$id   = (int) $id;
	$page = $id > 0 ? get_post( $id ) : null;
	if ( ! $page || ! is_object( $page ) || 'page' !== $page->post_type ) { return null; }
	if ( in_array( $page->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) { return null; }
	return $page;
}

/** 413 when the raw body exceeds the cap (null otherwise). */
function rk_builder_check_payload( $req ) {
	if ( strlen( (string) $req->get_body() ) > RK_BUILDER_MAX_PAYLOAD ) {
		return rk_builder_error( 'rk_payload_too_large', 'Request body exceeds ' . ( RK_BUILDER_MAX_PAYLOAD / 1024 ) . ' KB.', 413 );
	}
	return null;
}

/** Decoded JSON object body, or a 400 WP_Error. */
function rk_builder_json_body( $req, $code = 'rk_invalid_layout' ) {
	$body = $req->get_json_params();
	if ( ! is_array( $body ) || ( array() !== $body && ! rk_builder_is_object( $body ) ) ) {
		return rk_builder_invalid( $code, array( array( 'path' => '', 'message' => 'Expected a JSON object body.' ) ), 'Invalid request body.' );
	}
	return $body;
}

/** Validate `{expectedRevision}`-style integer. Returns array( int|null, issues ). */
function rk_builder_read_expected_revision( array $body, array &$issues ) {
	if ( ! array_key_exists( 'expectedRevision', $body ) ) {
		rk_builder_add_issue( $issues, 'expectedRevision', 'Required' );
		return null;
	}
	$err = rk_builder_check_int( $body['expectedRevision'], 0, 2147483647 );
	if ( null !== $err ) {
		rk_builder_add_issue( $issues, 'expectedRevision', $err );
		return null;
	}
	return (int) $body['expectedRevision'];
}

function rk_builder_conflict( $page_id ) {
	return rk_builder_error( 'rk_revision_conflict', 'The page was changed elsewhere. Reload to get the latest revision.', 409, array( 'currentRevision' => rk_builder_get_revision( $page_id ) ) );
}

/** Authenticated builder responses must never be cached. */
function rk_builder_no_store( $response ) {
	if ( is_wp_error( $response ) ) { return $response; }
	$response = rest_ensure_response( $response );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/* ------------------------------------------------------------------ *
 * Permission callbacks (always return true or a WP_Error)
 * ------------------------------------------------------------------ */

function rk_builder_authorize_caps( array $caps ) {
	if ( ! is_user_logged_in() ) { return rk_builder_unauthorized(); }
	foreach ( $caps as $cap ) {
		if ( ! current_user_can( $cap ) ) { return rk_builder_forbidden(); }
	}
	return true;
}

/** Logged in, may edit pages in general, and may edit this particular page (+ extra caps on it). */
function rk_builder_authorize_page( $req, array $page_caps = array( 'edit_post' ) ) {
	$gate = rk_builder_authorize_caps( array( 'edit_pages' ) );
	if ( true !== $gate ) { return $gate; }
	$page = rk_builder_get_page( isset( $req['id'] ) ? $req['id'] : 0 );
	if ( ! $page ) { return rk_builder_not_found( 'Page not found.' ); }
	foreach ( $page_caps as $cap ) {
		if ( ! current_user_can( $cap, $page->ID ) ) { return rk_builder_forbidden(); }
	}
	return true;
}

function rk_builder_perm_list_pages( $req ) { return rk_builder_authorize_caps( array( 'edit_pages' ) ); }
function rk_builder_perm_edit_page( $req ) { return rk_builder_authorize_page( $req, array( 'edit_post' ) ); }
function rk_builder_perm_publish_page( $req ) { return rk_builder_authorize_page( $req, array( 'edit_post', 'publish_post' ) ); }
function rk_builder_perm_media( $req ) { return rk_builder_authorize_caps( array( 'upload_files' ) ); }
function rk_builder_perm_theme_write( $req ) { return rk_builder_authorize_caps( array( 'manage_options' ) ); }
/** Whole-site export/import touches every page, the theme and media: administrators only. */
function rk_builder_perm_site_transfer( $req ) { return rk_builder_authorize_caps( array( 'manage_options', 'edit_pages' ) ); }

/* ------------------------------------------------------------------ *
 * Route registration
 * ------------------------------------------------------------------ */

function rk_builder_register_routes() {
	$ns   = RK_BUILDER_NS;
	$id   = array( 'id' => array( 'type' => 'integer', 'required' => true ) );
	$GET  = WP_REST_Server::READABLE;
	$POST = WP_REST_Server::CREATABLE;

	register_rest_route( $ns, '/builder/pages', array(
		'methods' => $GET, 'callback' => 'rk_builder_handle_list_pages', 'permission_callback' => 'rk_builder_perm_list_pages',
		'args'    => array(
			'search'   => array( 'type' => 'string', 'validate_callback' => 'rk_builder_validate_short_string' ),
			'status'   => array( 'type' => 'string', 'validate_callback' => 'rk_builder_validate_page_status' ),
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'validate_callback' => 'rk_builder_validate_per_page_100' ),
			'page'     => array( 'type' => 'integer', 'default' => 1, 'validate_callback' => 'rk_builder_validate_positive_int' ),
		),
	) );
	register_rest_route( $ns, '/builder/layout/(?P<id>\d+)', array(
		array( 'methods' => $GET, 'callback' => 'rk_builder_handle_get_layout', 'permission_callback' => 'rk_builder_perm_edit_page', 'args' => $id ),
		array( 'methods' => $POST, 'callback' => 'rk_builder_handle_save_layout', 'permission_callback' => 'rk_builder_perm_edit_page', 'args' => $id ),
	) );
	register_rest_route( $ns, '/builder/revisions/(?P<id>\d+)', array(
		'methods' => $GET, 'callback' => 'rk_builder_handle_list_revisions', 'permission_callback' => 'rk_builder_perm_edit_page', 'args' => $id,
	) );
	register_rest_route( $ns, '/builder/revisions/(?P<id>\d+)/(?P<revisionId>\d+)', array(
		'methods' => $GET, 'callback' => 'rk_builder_handle_get_revision', 'permission_callback' => 'rk_builder_perm_edit_page',
	) );
	register_rest_route( $ns, '/builder/revisions/(?P<id>\d+)/(?P<revisionId>\d+)/restore', array(
		'methods' => $POST, 'callback' => 'rk_builder_handle_restore_revision', 'permission_callback' => 'rk_builder_perm_edit_page',
	) );
	register_rest_route( $ns, '/builder/publish/(?P<id>\d+)', array(
		'methods' => $POST, 'callback' => 'rk_builder_handle_publish', 'permission_callback' => 'rk_builder_perm_publish_page', 'args' => $id,
	) );
	register_rest_route( $ns, '/builder/unpublish/(?P<id>\d+)', array(
		'methods' => $POST, 'callback' => 'rk_builder_handle_unpublish', 'permission_callback' => 'rk_builder_perm_publish_page', 'args' => $id,
	) );
	register_rest_route( $ns, '/builder/preview-token/(?P<id>\d+)', array(
		'methods' => $POST, 'callback' => 'rk_builder_handle_preview_token', 'permission_callback' => 'rk_builder_perm_edit_page', 'args' => $id,
	) );
	register_rest_route( $ns, '/builder/media', array(
		array(
			'methods' => $GET, 'callback' => 'rk_builder_handle_media', 'permission_callback' => 'rk_builder_perm_media',
			'args'    => array(
				'search'   => array( 'type' => 'string', 'validate_callback' => 'rk_builder_validate_short_string' ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'validate_callback' => 'rk_builder_validate_per_page_50' ),
			),
		),
		array(
			'methods' => $POST, 'callback' => 'rk_builder_handle_upload_media', 'permission_callback' => 'rk_builder_perm_media',
			'args'    => array(
				'alt'   => array( 'type' => 'string', 'validate_callback' => 'rk_builder_validate_alt_text' ),
				'title' => array( 'type' => 'string', 'validate_callback' => 'rk_builder_validate_short_string' ),
			),
		),
	) );
	register_rest_route( $ns, '/builder/reusables', array(
		array( 'methods' => $GET, 'callback' => 'rk_builder_handle_list_reusables', 'permission_callback' => 'rk_builder_perm_list_pages' ),
		array( 'methods' => $POST, 'callback' => 'rk_builder_handle_create_reusable', 'permission_callback' => 'rk_builder_perm_reusable_write' ),
	) );
	register_rest_route( $ns, '/builder/reusables/(?P<id>\d+)', array(
		'methods' => $POST, 'callback' => 'rk_builder_handle_update_reusable', 'permission_callback' => 'rk_builder_perm_reusable_write', 'args' => $id,
	) );
	register_rest_route( $ns, '/builder/reusables/(?P<id>\d+)/delete', array(
		'methods' => $POST, 'callback' => 'rk_builder_handle_delete_reusable', 'permission_callback' => 'rk_builder_perm_reusable_write', 'args' => $id,
	) );
	register_rest_route( $ns, '/builder/site-export', array(
		'methods' => $GET, 'callback' => 'rk_builder_handle_site_export', 'permission_callback' => 'rk_builder_perm_site_transfer',
	) );
	register_rest_route( $ns, '/builder/site-import', array(
		'methods' => $POST, 'callback' => 'rk_builder_handle_site_import', 'permission_callback' => 'rk_builder_perm_site_transfer',
	) );
	rk_builder_register_theme_routes( $ns );
	register_rest_route( $ns, '/theme-config', array(
		array( 'methods' => $GET, 'callback' => 'rk_builder_handle_get_theme', 'permission_callback' => '__return_true' ),
		array( 'methods' => $POST, 'callback' => 'rk_builder_handle_save_theme', 'permission_callback' => 'rk_builder_perm_theme_write' ),
	) );
	register_rest_route( $ns, '/public/page/(?P<slug>[A-Za-z0-9_-]+)', array(
		'methods' => $GET, 'callback' => 'rk_builder_handle_public_page', 'permission_callback' => '__return_true',
		'args'    => array( 'preview' => array( 'type' => 'string', 'validate_callback' => 'rk_builder_validate_short_string' ) ),
	) );
	register_rest_route( $ns, '/content/(?P<type>[A-Za-z0-9_-]+)', array(
		'methods' => $GET, 'callback' => 'rk_builder_handle_content', 'permission_callback' => '__return_true',
		'args'    => array(
			'limit'    => array( 'type' => 'integer', 'validate_callback' => 'rk_builder_validate_content_limit' ),
			'category' => array( 'type' => 'string', 'validate_callback' => 'rk_builder_validate_content_category' ),
			'orderby'  => array( 'type' => 'string', 'default' => 'date', 'validate_callback' => 'rk_builder_validate_content_orderby' ),
			'order'    => array( 'type' => 'string', 'default' => 'desc', 'validate_callback' => 'rk_builder_validate_content_order' ),
		),
	) );
}

/* Param validators: return true/false (core turns false into 400 rest_invalid_param). */
function rk_builder_validate_short_string( $v ) { return is_string( $v ) && strlen( $v ) <= 512; }
function rk_builder_validate_alt_text( $v ) { return is_string( $v ) && strlen( $v ) <= 1200; }
function rk_builder_validate_positive_int( $v ) { return is_numeric( $v ) && (string) (int) $v === (string) $v && (int) $v >= 1 && (int) $v <= 1000000; }
function rk_builder_validate_per_page_100( $v ) { return rk_builder_validate_positive_int( $v ) && (int) $v <= 100; }
function rk_builder_validate_per_page_50( $v ) { return rk_builder_validate_positive_int( $v ) && (int) $v <= 50; }
function rk_builder_validate_page_status( $v ) {
	return is_string( $v ) && in_array( $v, array( 'any', 'publish', 'draft', 'private', 'pending', 'future' ), true );
}
