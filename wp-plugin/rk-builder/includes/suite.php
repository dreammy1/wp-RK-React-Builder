<?php
/**
 * RK Suite integration: MCP tools for the builder.
 *
 * RK Suite's RK API module hosts a native MCP server (POST /wp-json/rk/v1/mcp). When it applies the
 * `rk_api_mcp_catalog` / `rk_api_mcp_handlers` filters, the tools below appear next to its own `wp_*` tools.
 * Without RK Suite these filters never run, so this file is inert in the standalone plugin.
 *
 * Every tool goes through the same permission callbacks, validation, revisions and locking as the editor's
 * REST routes. The RK API accepts two kinds of caller:
 *   - an Application Password (a real user): that user's capabilities apply;
 *   - the RK API key (no user): tools run as the site's first administrator, which is the same power the
 *     key already gives over pages in RK API.
 *
 * Publishing is deliberately two-step: `wp_builder_publish` / `wp_builder_unpublish` refuse unless the call
 * passes confirm=true, so an assistant cannot make a page live by accident.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'rk_api_mcp_catalog', 'rk_builder_mcp_catalog' );
add_filter( 'rk_api_mcp_handlers', 'rk_builder_mcp_handlers' );

function rk_builder_mcp_schema( array $props, array $required = array() ) {
	return array( 'type' => 'object', 'properties' => (object) $props, 'required' => $required, 'additionalProperties' => false );
}

function rk_builder_mcp_catalog( $catalog ) {
	$catalog = is_array( $catalog ) ? $catalog : array();
	$id      = array( 'type' => 'integer', 'description' => 'WordPress page ID' );
	$confirm = array( 'type' => 'boolean', 'description' => 'Must be true. Confirms the user asked for this.' );
	$rev     = array( 'type' => 'integer', 'description' => 'Revision the edit is based on. Omit to use the current one (last write wins).' );
	$tools   = array(
		'wp_builder_block_types'  => array( 'Describe the RK Builder document format: allowed block types and the exact props each accepts (strict: unknown props are rejected). Read this before writing a layout.', rk_builder_mcp_schema( array() ) ),
		'wp_builder_list_pages'   => array( 'List pages with their RK Builder draft revision and published revision.', rk_builder_mcp_schema( array( 'search' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string', 'description' => 'any|publish|draft|private|pending|future' ), 'per_page' => array( 'type' => 'integer' ), 'page' => array( 'type' => 'integer' ) ) ) ),
		'wp_builder_get_layout'   => array( 'Get a page\'s current DRAFT layout (blocks), the site theme and revision numbers.', rk_builder_mcp_schema( array( 'id' => $id ), array( 'id' ) ) ),
		'wp_builder_save_layout'  => array( 'Save a page\'s DRAFT layout {version:1, blocks:[{id,type,props}]}. Validated strictly; never publishes. Returns the new revision.', rk_builder_mcp_schema( array( 'id' => $id, 'layout' => array( 'type' => 'object', 'description' => '{version:1, blocks:[{id,type,props}]}. See wp_builder_block_types.' ), 'theme' => array( 'type' => 'object', 'description' => 'Optional site theme (administrators only).' ), 'expectedRevision' => $rev ), array( 'id', 'layout' ) ) ),
		'wp_builder_preview_link' => array( 'Create a 15-minute preview URL that shows the DRAFT on the real site.', rk_builder_mcp_schema( array( 'id' => $id ), array( 'id' ) ) ),
		'wp_builder_publish'      => array( 'Publish the current draft (makes it live). Only call after the user explicitly asked to publish.', rk_builder_mcp_schema( array( 'id' => $id, 'confirm' => $confirm, 'expectedRevision' => $rev ), array( 'id', 'confirm' ) ) ),
		'wp_builder_unpublish'    => array( 'Take a published page offline (back to draft). Only call after the user explicitly asked.', rk_builder_mcp_schema( array( 'id' => $id, 'confirm' => $confirm ), array( 'id', 'confirm' ) ) ),
		'wp_builder_get_theme'    => array( 'Read the site-wide RK Builder theme (colors, font, logo, header, footer, social links).', rk_builder_mcp_schema( array() ) ),
		'wp_builder_save_theme'   => array( 'Replace the site-wide theme. Administrators only. Affects every page, including live ones.', rk_builder_mcp_schema( array( 'theme' => array( 'type' => 'object' ), 'confirm' => $confirm ), array( 'theme', 'confirm' ) ) ),
		'wp_builder_list_media'   => array( 'List media-library images (id, url, alt) for image blocks and hero backgrounds (bgMediaId + bgUrl).', rk_builder_mcp_schema( array( 'search' => array( 'type' => 'string' ), 'per_page' => array( 'type' => 'integer' ) ) ) ),
	);
	foreach ( $tools as $name => $def ) {
		$catalog[ $name ] = array( 'name' => $name, 'description' => $def[0], 'inputSchema' => $def[1] );
	}
	return $catalog;
}

function rk_builder_mcp_handlers( $handlers ) {
	$handlers = is_array( $handlers ) ? $handlers : array();
	foreach ( array( 'block_types', 'list_pages', 'get_layout', 'save_layout', 'preview_link', 'publish', 'unpublish', 'get_theme', 'save_theme', 'list_media' ) as $tool ) {
		$handlers[ 'wp_builder_' . $tool ] = 'rk_builder_mcp_tool_' . $tool;
	}
	return $handlers;
}

/** Make sure a user context exists (API-key callers have none). */
function rk_builder_mcp_actor() {
	if ( is_user_logged_in() ) { return true; }
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
	if ( ! $admins ) { return rk_builder_error( 'rk_unauthorized', 'No administrator account exists to run this tool as.', 401 ); }
	wp_set_current_user( (int) $admins[0] );
	return true;
}

/**
 * Run a REST handler with its own permission callback, exactly as the REST server would.
 *
 * @param callable $handler
 * @param callable $perm
 * @param string   $method GET|POST
 * @param array    $params URL/query params (id, search, ...)
 * @param array|null $body JSON body
 */
function rk_builder_mcp_run( $handler, $perm, $method, array $params = array(), $body = null ) {
	$actor = rk_builder_mcp_actor();
	if ( true !== $actor ) { return $actor; }
	$req = new WP_REST_Request( $method, '/' . RK_BUILDER_NS . '/builder' );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$gate = call_user_func( $perm, $req );
	if ( true !== $gate ) { return $gate; }
	return call_user_func( $handler, $req );
}

function rk_builder_mcp_id( array $a ) {
	return isset( $a['id'] ) && is_numeric( $a['id'] ) ? (int) $a['id'] : 0;
}

function rk_builder_mcp_need_confirm( array $a ) {
	if ( isset( $a['confirm'] ) && true === $a['confirm'] ) { return true; }
	return rk_builder_error( 'rk_confirmation_required', 'This changes what visitors see. Ask the user, then call again with confirm=true.', 400 );
}

function rk_builder_mcp_revision( array $a, $id ) {
	return isset( $a['expectedRevision'] ) && is_numeric( $a['expectedRevision'] ) ? (int) $a['expectedRevision'] : rk_builder_get_revision( $id );
}

function rk_builder_mcp_tool_block_types( array $a ) {
	$out = array();
	foreach ( rk_builder_block_specs() as $type => $fields ) {
		$props = array();
		foreach ( $fields as $name => $spec ) {
			switch ( $spec['t'] ) {
				case 'text':  $d = 'string ' . $spec['min'] . '-' . $spec['max'] . ' chars'; break;
				case 'link':  $d = 'link: "", /relative, #anchor, https://, mailto: or tel:'; break;
				case 'image': $d = 'image URL: /relative or https:// on an allowed host'; break;
				case 'int':   $d = 'integer ' . $spec['min'] . '-' . $spec['max']; break;
				case 'bool':  $d = 'boolean'; break;
				case 'slug':  $d = 'slug a-z0-9-, up to 60 chars (may be empty)'; break;
				case 'enum':  $d = 'one of ' . wp_json_encode( $spec['values'] ); break;
				default:      $d = $spec['t'];
			}
			$props[ $name ] = $d . ( ! empty( $spec['opt'] ) ? ' (optional)' : '' );
		}
		$out[ $type ] = $props;
	}
	return array(
		'format'   => array( 'version' => 1, 'blocks' => '[{ "id": "unique-slug", "type": "<block type>", "props": { ... } }]', 'maxBlocks' => RK_BUILDER_MAX_BLOCKS, 'idPattern' => '^[a-z0-9][a-z0-9_-]{0,63}$ (unique per page)' ),
		'blocks'   => $out,
		'notes'    => array(
			'Unknown props or block types are rejected; every required prop must be present (use "" for empty text).',
			'Image block: url + alt (or decorative=true). Hero background is decorative: bgUrl and optional bgMediaId.',
			'The contact block renders id="contact", so a button linking to "#contact" scrolls to it.',
			'Saving writes a draft only. Publishing is a separate, confirmed step.',
		),
	);
}

function rk_builder_mcp_tool_list_pages( array $a ) {
	$params = array();
	foreach ( array( 'search', 'status', 'per_page', 'page' ) as $k ) { if ( isset( $a[ $k ] ) ) { $params[ $k ] = $a[ $k ]; } }
	return rk_builder_mcp_run( 'rk_builder_handle_list_pages', 'rk_builder_perm_list_pages', 'GET', $params );
}

function rk_builder_mcp_tool_get_layout( array $a ) {
	return rk_builder_mcp_run( 'rk_builder_handle_get_layout', 'rk_builder_perm_edit_page', 'GET', array( 'id' => rk_builder_mcp_id( $a ) ) );
}

function rk_builder_mcp_tool_save_layout( array $a ) {
	$id = rk_builder_mcp_id( $a );
	if ( ! isset( $a['layout'] ) ) { return rk_builder_invalid( 'rk_invalid_layout', array( array( 'path' => 'layout', 'message' => 'Required' ) ) ); }
	$body = array( 'layout' => $a['layout'], 'expectedRevision' => rk_builder_mcp_revision( $a, $id ), 'status' => 'draft' );
	if ( isset( $a['theme'] ) ) { $body['theme'] = $a['theme']; }
	return rk_builder_mcp_run( 'rk_builder_handle_save_layout', 'rk_builder_perm_edit_page', 'POST', array( 'id' => $id ), $body );
}

function rk_builder_mcp_tool_preview_link( array $a ) {
	return rk_builder_mcp_run( 'rk_builder_handle_preview_token', 'rk_builder_perm_edit_page', 'POST', array( 'id' => rk_builder_mcp_id( $a ) ) );
}

function rk_builder_mcp_tool_publish( array $a ) {
	$ok = rk_builder_mcp_need_confirm( $a );
	if ( true !== $ok ) { return $ok; }
	$id = rk_builder_mcp_id( $a );
	return rk_builder_mcp_run( 'rk_builder_handle_publish', 'rk_builder_perm_publish_page', 'POST', array( 'id' => $id ), array( 'expectedRevision' => rk_builder_mcp_revision( $a, $id ) ) );
}

function rk_builder_mcp_tool_unpublish( array $a ) {
	$ok = rk_builder_mcp_need_confirm( $a );
	if ( true !== $ok ) { return $ok; }
	return rk_builder_mcp_run( 'rk_builder_handle_unpublish', 'rk_builder_perm_publish_page', 'POST', array( 'id' => rk_builder_mcp_id( $a ) ), array() );
}

function rk_builder_mcp_tool_get_theme( array $a ) {
	return rk_builder_handle_get_theme( new WP_REST_Request( 'GET', '/' . RK_BUILDER_NS . '/theme-config' ) );
}

function rk_builder_mcp_tool_save_theme( array $a ) {
	$ok = rk_builder_mcp_need_confirm( $a );
	if ( true !== $ok ) { return $ok; }
	if ( ! isset( $a['theme'] ) ) { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'theme', 'message' => 'Required' ) ) ); }
	return rk_builder_mcp_run( 'rk_builder_handle_save_theme', 'rk_builder_perm_theme_write', 'POST', array(), $a['theme'] );
}

function rk_builder_mcp_tool_list_media( array $a ) {
	$params = array();
	foreach ( array( 'search', 'per_page' ) as $k ) { if ( isset( $a[ $k ] ) ) { $params[ $k ] = $a[ $k ]; } }
	return rk_builder_mcp_run( 'rk_builder_handle_media', 'rk_builder_perm_media', 'GET', $params );
}

/**
 * Inside RK Suite the builder's menu is folded under the RK menu, which changes the screen's hook name, so
 * core's `load-<hook>` action (includes/admin.php) may not fire. Answer from admin_init instead; the function
 * checks capabilities itself and falls through to core's permission screen when the user may not edit pages.
 */
if ( defined( 'RK_BUILDER_IN_SUITE' ) ) {
	add_action( 'admin_init', 'rk_builder_suite_open_builder', 20 );
}
function rk_builder_suite_open_builder() {
	global $pagenow;
	if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'rk-builder' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing check.
		rk_builder_render_standalone();
	}
}
