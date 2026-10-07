<?php
/**
 * UI Library: curated, modern, responsive pre-built components and templates for RK Builder.
 * Includes Theme, Page, Header, Footer, and Section templates across 5 website style archetypes.
 *
 * Provides WP-Admin submenu entry points and REST API routes for:
 *   GET  /builder/ui-library/summary   → { categories, counts }
 *   POST /builder/ui-library/apply-theme   { theme }          → applies theme tokens
 *   POST /builder/ui-library/create-page   { title, layout }  → creates draft page from template
 *   POST /builder/ui-library/create-template { title, kind, layout, activate? } → creates header/footer template
 *   POST /builder/ui-library/create-reusable { name, block }  → saves section as reusable block
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Register the UI Library submenu item under the RK Builder admin menu.
 */
function rk_builder_register_ui_library_menu() {
	if ( ! function_exists( 'add_submenu_page' ) ) {
		return;
	}
	$title = function_exists( 'esc_html__' ) ? esc_html__( 'UI Library', 'rk-builder' ) : 'UI Library';
	$hook  = add_submenu_page(
		'rk-builder',
		$title,
		$title,
		'edit_pages',
		'rk-builder-ui-library',
		'rk_builder_render_ui_library_page'
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'rk_builder_render_ui_library_standalone' );
	}
}

/**
 * Fallback page rendering if standalone load hook did not intercept.
 */
function rk_builder_render_ui_library_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		$err = function_exists( 'esc_html__' ) ? esc_html__( 'You do not have permission to access this page.', 'rk-builder' ) : 'You do not have permission to access this page.';
		wp_die( $err, '', array( 'response' => 403 ) );
	}
	$msg = function_exists( 'esc_html__' ) ? esc_html__( 'Opening RK Builder UI Library…', 'rk-builder' ) : 'Opening RK Builder UI Library…';
	echo '<div class="wrap"><p>' . esc_html( $msg ) . '</p></div>';
}

/**
 * Standalone document output when accessing the UI Library submenu directly.
 */
function rk_builder_render_ui_library_standalone() {
	rk_builder_render_standalone();
}

/**
 * Register UI Library REST API routes.
 */
function rk_builder_register_ui_library_routes( $ns ) {
	$POST = WP_REST_Server::CREATABLE;
	$GET  = WP_REST_Server::READABLE;

	register_rest_route( $ns, '/builder/ui-library/summary', array(
		'methods'             => $GET,
		'callback'            => 'rk_builder_handle_ui_library_summary',
		'permission_callback' => 'rk_builder_perm_list_pages',
	) );

	register_rest_route( $ns, '/builder/ui-library/apply-theme', array(
		'methods'             => $POST,
		'callback'            => 'rk_builder_handle_ui_library_apply_theme',
		'permission_callback' => 'rk_builder_perm_theme_write',
	) );

	register_rest_route( $ns, '/builder/ui-library/create-page', array(
		'methods'             => $POST,
		'callback'            => 'rk_builder_handle_ui_library_create_page',
		'permission_callback' => 'rk_builder_perm_list_pages',
	) );

	register_rest_route( $ns, '/builder/ui-library/create-template', array(
		'methods'             => $POST,
		'callback'            => 'rk_builder_handle_ui_library_create_template',
		'permission_callback' => 'rk_builder_perm_theme_write',
	) );

	register_rest_route( $ns, '/builder/ui-library/create-reusable', array(
		'methods'             => $POST,
		'callback'            => 'rk_builder_handle_ui_library_create_reusable',
		'permission_callback' => 'rk_builder_perm_reusable_write',
	) );
}

/**
 * Summary metadata of the UI Library.
 */
function rk_builder_handle_ui_library_summary( $req ) {
	return rk_builder_no_store( array(
		'categories' => array(
			array( 'id' => 'theme', 'label' => 'Theme Presets', 'count' => 5 ),
			array( 'id' => 'page', 'label' => 'Page Templates', 'count' => 5 ),
			array( 'id' => 'header', 'label' => 'Header Templates', 'count' => 5 ),
			array( 'id' => 'footer', 'label' => 'Footer Templates', 'count' => 5 ),
			array( 'id' => 'section', 'label' => 'Section Templates', 'count' => 5 ),
		),
		'archetypes' => array(
			array( 'id' => 'saas', 'label' => 'Modern SaaS / Tech' ),
			array( 'id' => 'minimal', 'label' => 'Minimalist Editorial' ),
			array( 'id' => 'agency', 'label' => 'Bold Dark Agency' ),
			array( 'id' => 'artisan', 'label' => 'Warm Artisan Lifestyle' ),
			array( 'id' => 'corporate', 'label' => 'Enterprise Corporate' ),
		),
		'totalVariants' => 25,
	) );
}

/**
 * Apply theme tokens directly to active site theme.
 */
function rk_builder_handle_ui_library_apply_theme( $req ) {
	$body = rk_builder_json_body( $req, 'rk_invalid_theme' );
	if ( is_wp_error( $body ) ) { return $body; }

	$raw_theme = isset( $body['theme'] ) && is_array( $body['theme'] ) ? $body['theme'] : null;
	if ( ! $raw_theme ) {
		return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'theme', 'message' => 'Required' ) ) );
	}

	$issues = rk_builder_validate_theme( $raw_theme, rk_builder_allowed_image_hosts() );
	if ( ! empty( $issues ) ) {
		return rk_builder_invalid( 'rk_invalid_theme', $issues );
	}

	$canonical = rk_builder_canonicalize_theme( $raw_theme );
	rk_builder_store_theme( $canonical );

	rk_builder_purge_all_public_cache();
	return rk_builder_no_store( array( 'ok' => true, 'theme' => rk_builder_get_theme() ) );
}

/**
 * Create a new draft page populated with a layout template.
 */
function rk_builder_handle_ui_library_create_page( $req ) {
	$body = rk_builder_json_body( $req, 'rk_invalid_layout' );
	if ( is_wp_error( $body ) ) { return $body; }

	$title = isset( $body['title'] ) && is_string( $body['title'] ) ? trim( sanitize_text_field( $body['title'] ) ) : '';
	if ( '' === $title || rk_builder_strlen( $title ) > 120 ) {
		return rk_builder_invalid( 'rk_invalid_page', array( array( 'path' => 'title', 'message' => 'Title must be between 1 and 120 characters.' ) ) );
	}

	$raw_layout = isset( $body['layout'] ) && is_array( $body['layout'] ) ? $body['layout'] : null;
	if ( ! $raw_layout ) {
		return rk_builder_invalid( 'rk_invalid_layout', array( array( 'path' => 'layout', 'message' => 'Required' ) ) );
	}

	$issues = rk_builder_validate_layout( $raw_layout, rk_builder_allowed_image_hosts() );
	if ( ! empty( $issues ) ) {
		return rk_builder_invalid( 'rk_invalid_layout', $issues );
	}

	$canonical_layout = rk_builder_canonicalize_layout( $raw_layout );
	$post_data = array(
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_title'  => $title,
	);

	if ( ! empty( $body['slug'] ) && is_string( $body['slug'] ) ) {
		$post_data['post_name'] = sanitize_title( $body['slug'] );
	}

	$id = wp_insert_post( $post_data, true );
	if ( is_wp_error( $id ) || ! $id ) {
		return rk_builder_error( 'rk_server_error', 'Could not create the page.', 500 );
	}

	$id = (int) $id;
	rk_builder_commit_revision( $id, 'draft', $canonical_layout );

	$res = rk_builder_no_store( array(
		'ok'       => true,
		'id'       => $id,
		'title'    => $title,
		'editLink' => rk_builder_edit_link( $id ),
	) );
	if ( $res instanceof WP_REST_Response ) { $res->set_status( 201 ); }
	return $res;
}

/**
 * Create a header or footer template (rk_template).
 */
function rk_builder_handle_ui_library_create_template( $req ) {
	$body = rk_builder_json_body( $req, 'rk_invalid_template' );
	if ( is_wp_error( $body ) ) { return $body; }

	$kind = isset( $body['kind'] ) && is_string( $body['kind'] ) ? trim( $body['kind'] ) : '';
	if ( ! in_array( $kind, array( 'header', 'footer' ), true ) ) {
		return rk_builder_invalid( 'rk_invalid_template', array( array( 'path' => 'kind', 'message' => 'Kind must be header or footer.' ) ) );
	}

	$title = isset( $body['title'] ) && is_string( $body['title'] ) ? trim( sanitize_text_field( $body['title'] ) ) : '';
	if ( '' === $title || rk_builder_strlen( $title ) > 80 ) {
		return rk_builder_invalid( 'rk_invalid_template', array( array( 'path' => 'title', 'message' => 'Title must be between 1 and 80 characters.' ) ) );
	}

	$raw_layout = isset( $body['layout'] ) && is_array( $body['layout'] ) ? $body['layout'] : null;
	if ( ! $raw_layout ) {
		return rk_builder_invalid( 'rk_invalid_layout', array( array( 'path' => 'layout', 'message' => 'Required' ) ) );
	}

	$issues = rk_builder_validate_layout( $raw_layout, rk_builder_allowed_image_hosts() );
	if ( ! empty( $issues ) ) {
		return rk_builder_invalid( 'rk_invalid_layout', $issues );
	}

	if ( count( rk_builder_tpl_all() ) >= RK_BUILDER_MAX_TEMPLATES ) {
		return rk_builder_error( 'rk_payload_too_large', 'Too many templates.', 413 );
	}

	$activate = ! empty( $body['activate'] );
	$post_data = array(
		'post_type'   => RK_BUILDER_TEMPLATE_TYPE,
		'post_status' => $activate ? 'publish' : 'draft',
		'post_title'  => $title,
	);

	$id = wp_insert_post( $post_data, true );
	if ( is_wp_error( $id ) || ! $id ) {
		return rk_builder_error( 'rk_server_error', 'Could not create the template.', 500 );
	}

	$id = (int) $id;
	$canonical_layout = rk_builder_canonicalize_layout( $raw_layout );

	update_post_meta( $id, '_rk_tpl_kind', $kind );
	update_post_meta( $id, '_rk_tpl_type', '' );
	update_post_meta( $id, '_rk_tpl_tax', '' );
	update_post_meta( $id, '_rk_tpl_active', '0' );
	update_post_meta( $id, '_rk_tpl_slug', rk_builder_tpl_new_slug( $title ) );

	rk_builder_commit_revision( $id, 'draft', $canonical_layout );

	if ( $activate ) {
		rk_builder_commit_revision( $id, 'publish', $canonical_layout );
		rk_builder_tpl_set_active( $id, true );
	}

	rk_builder_purge_all_public_cache();

	$res = rk_builder_no_store( array(
		'ok'       => true,
		'id'       => $id,
		'item'     => rk_builder_tpl_item( get_post( $id ) ),
		'editLink' => rk_builder_edit_link( $id ),
	) );
	if ( $res instanceof WP_REST_Response ) { $res->set_status( 201 ); }
	return $res;
}

/**
 * Save a section template as a reusable block (rk_reusable).
 */
function rk_builder_handle_ui_library_create_reusable( $req ) {
	$body = rk_builder_json_body( $req, 'rk_invalid_reusable' );
	if ( is_wp_error( $body ) ) { return $body; }

	$name = isset( $body['name'] ) && is_string( $body['name'] ) ? trim( sanitize_text_field( $body['name'] ) ) : '';
	if ( '' === $name || rk_builder_strlen( $name ) > 80 ) {
		return rk_builder_invalid( 'rk_invalid_reusable', array( array( 'path' => 'name', 'message' => 'Name must be 1-80 characters' ) ) );
	}

	$block = isset( $body['block'] ) && is_array( $body['block'] ) ? $body['block'] : null;
	if ( ! $block ) {
		return rk_builder_invalid( 'rk_invalid_reusable', array( array( 'path' => 'block', 'message' => 'Required' ) ) );
	}

	// Reusable blocks expect { type, props } without block id.
	if ( isset( $block['type'], $block['props'] ) && is_array( $block['props'] ) ) {
		$block = array(
			'type'  => (string) $block['type'],
			'props' => (array) $block['props'],
		);
	}

	$bi = rk_builder_reusable_block_issues( $block, rk_builder_allowed_image_hosts() );
	if ( ! empty( $bi ) ) {
		return rk_builder_invalid( 'rk_invalid_reusable', rk_builder_prefix_issues( $bi, 'block' ) );
	}

	if ( count( rk_builder_reusable_list() ) >= RK_BUILDER_MAX_REUSABLES ) {
		return rk_builder_error( 'rk_payload_too_large', 'The library is full (' . RK_BUILDER_MAX_REUSABLES . ' reusable blocks).', 413 );
	}

	$canonical_block = rk_builder_reusable_canonical_block( $block );
	$id = wp_insert_post( array(
		'post_type'   => RK_BUILDER_REUSABLE_TYPE,
		'post_status' => 'publish',
		'post_title'  => $name,
	), true );

	if ( is_wp_error( $id ) || ! $id ) {
		return rk_builder_error( 'rk_server_error', 'Could not save the reusable block.', 500 );
	}

	$id = (int) $id;
	rk_builder_write_json_meta( $id, '_rk_reusable_block', $canonical_block );
	$item = rk_builder_reusable_get( $id );

	$res = rk_builder_no_store( array(
		'ok'   => true,
		'id'   => $id,
		'item' => rk_builder_reusable_item( $item ),
	) );
	if ( $res instanceof WP_REST_Response ) { $res->set_status( 201 ); }
	return $res;
}
