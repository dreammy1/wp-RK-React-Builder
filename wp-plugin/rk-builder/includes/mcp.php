<?php
/**
 * RK Builder as an MCP server (Model Context Protocol, streamable HTTP, stateless JSON-RPC 2.0).
 *
 * An AI client (Claude, Cursor, ...) POSTs to /wp-json/rk/v1/builder-mcp and can then do what an
 * administrator does in the dashboard: pages and layouts, SEO and schema, content types and entries,
 * templates, media, reusable blocks, site and global settings, redirects, code and reviews.
 *
 * It adds no new powers. Every tool is one existing RK Builder REST route, run with rest_do_request()
 * as the user who signed in, so the same permission checks and the same strict validation apply as in
 * the dashboard. The only things this file decides are:
 *   - whether the server answers at all (off until an administrator turns it on),
 *   - how much it may do: "read" (look only), "write" (create and change; trashing is recoverable),
 *     "full" (also permanent deletes, tracking code, anything that cannot be undone),
 *   - the audit log of the last calls.
 *
 * Two ways to sign in, both end up as a WordPress user whose own rights apply:
 *   - a connection key made on the AI & MCP screen (sent as `X-RK-API-Key: <key>` or `Authorization: Bearer <key>`;
 *     only a SHA-256 hash of it is stored, the key itself is shown once), or
 *   - a WordPress Application Password (HTTP Basic).
 *
 * Add a tool from another plugin with the `rk_builder_mcp_tools` filter (see rk_builder_ai_tools()).
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const RK_BUILDER_MCP_PROTO     = '2025-06-18';
const RK_BUILDER_MCP_LOG_LIMIT = 50;
const RK_BUILDER_MCP_MAX_TEXT  = 120000;

/* ------------------------------------------------------------------ *
 * Settings
 * ------------------------------------------------------------------ */

function rk_builder_ai_levels() { return array( 'read' => 0, 'write' => 1, 'full' => 2 ); }

function rk_builder_ai_defaults() { return array( 'enabled' => false, 'level' => 'write' ); }

function rk_builder_ai_settings() {
	$s = get_option( 'rk_builder_mcp', array() );
	$s = is_array( $s ) ? $s : array();
	$d = rk_builder_ai_defaults();
	return array(
		'enabled' => ! empty( $s['enabled'] ),
		'level'   => isset( $s['level'] ) && isset( rk_builder_ai_levels()[ $s['level'] ] ) ? $s['level'] : $d['level'],
	);
}

/** The level in force for this request: the global level, lowered to the key's own level when a key signed in. */
function rk_builder_ai_level() {
	$levels = rk_builder_ai_levels();
	$level  = rk_builder_ai_settings()['level'];
	$key    = isset( $GLOBALS['rk_builder_ai_key'] ) ? $GLOBALS['rk_builder_ai_key'] : null;
	if ( is_array( $key ) && isset( $levels[ $key['level'] ] ) && $levels[ $key['level'] ] < $levels[ $level ] ) { $level = $key['level']; }
	return $level;
}

/* ------------------------------------------------------------------ *
 * Connection keys
 * ------------------------------------------------------------------ */

const RK_BUILDER_AI_MAX_KEYS = 10;

function rk_builder_ai_keys() {
	$k = get_option( 'rk_builder_mcp_keys', array() );
	return is_array( $k ) ? array_values( $k ) : array();
}

function rk_builder_ai_key_hash( $token ) { return hash( 'sha256', (string) $token ); }

/** @return array{token:string,key:array}|WP_Error */
function rk_builder_ai_key_create( $name, $level, $user_id ) {
	$keys = rk_builder_ai_keys();
	if ( count( $keys ) >= RK_BUILDER_AI_MAX_KEYS ) { return new WP_Error( 'rk_mcp_too_many_keys', 'Up to ' . RK_BUILDER_AI_MAX_KEYS . ' keys. Revoke one you no longer use.', array( 'status' => 409 ) ); }
	$token = 'rkb_' . bin2hex( random_bytes( 20 ) );
	$key   = array(
		'id'      => substr( bin2hex( random_bytes( 6 ) ), 0, 10 ),
		'name'    => $name,
		'hash'    => rk_builder_ai_key_hash( $token ),
		'prefix'  => substr( $token, 0, 8 ),
		'user'    => (int) $user_id,
		'level'   => $level,
		'created' => gmdate( 'c' ),
		'used'    => '',
	);
	$keys[] = $key;
	update_option( 'rk_builder_mcp_keys', $keys, false );
	return array( 'token' => $token, 'key' => $key );
}

function rk_builder_ai_key_find( $token ) {
	$h = rk_builder_ai_key_hash( $token );
	foreach ( rk_builder_ai_keys() as $k ) {
		if ( isset( $k['hash'] ) && hash_equals( (string) $k['hash'], $h ) ) { return $k; }
	}
	return null;
}

function rk_builder_ai_key_touch( array $key ) {
	$keys = rk_builder_ai_keys();
	foreach ( $keys as $i => $k ) {
		if ( $k['id'] !== $key['id'] ) { continue; }
		if ( '' !== $k['used'] && strtotime( $k['used'] ) > time() - 60 ) { return; } // once a minute is enough
		$keys[ $i ]['used'] = gmdate( 'c' );
		update_option( 'rk_builder_mcp_keys', $keys, false );
		return;
	}
}

function rk_builder_ai_key_revoke( $id ) {
	$keys = rk_builder_ai_keys();
	$out  = array_values( array_filter( $keys, function ( $k ) use ( $id ) { return $k['id'] !== $id; } ) );
	if ( count( $out ) === count( $keys ) ) { return false; }
	update_option( 'rk_builder_mcp_keys', $out, false );
	return true;
}

/** The key sent with the request, from X-RK-API-Key or Authorization: Bearer (null when none). */
function rk_builder_ai_request_token( $req ) {
	$t = trim( (string) $req->get_header( 'x-rk-api-key' ) );
	if ( '' !== $t ) { return $t; }
	$a = (string) $req->get_header( 'authorization' );
	if ( '' === $a && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { $a = (string) $_SERVER['HTTP_AUTHORIZATION']; } // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared by hash, never output
	if ( '' === $a && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) { $a = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; } // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( 1 === preg_match( '/^Bearer\s+(\S+)\z/i', trim( $a ), $m ) ) { return $m[1]; }
	return null;
}

function rk_builder_ai_endpoint() { return rest_url( RK_BUILDER_NS . '/builder-mcp' ); }

/* ------------------------------------------------------------------ *
 * Tool catalogue
 * ------------------------------------------------------------------ */

/**
 * name => array( title, group, risk (read|write|full), description, method, path, props, required ).
 * `path` may hold {placeholders}; the argument of the same name fills it. All other arguments are the
 * query string (GET) or the JSON body (POST). method 'LOCAL' runs a function named in `callback`.
 */
function rk_builder_ai_tools() {
	$int  = array( 'type' => 'integer' );
	$str  = array( 'type' => 'string' );
	$bool = array( 'type' => 'boolean' );
	$obj  = array( 'type' => 'object' );
	$id   = array( 'id' => array( 'type' => 'integer', 'description' => 'The page, template or entry ID' ) );
	$T    = array();
	$add  = function ( $name, $title, $group, $risk, $desc, $method, $path, $props = array(), $required = array(), $callback = '' ) use ( &$T ) {
		$T[ $name ] = array( 'title' => $title, 'group' => $group, 'risk' => $risk, 'description' => $desc, 'method' => $method, 'path' => $path, 'props' => $props, 'required' => $required, 'callback' => $callback );
	};

	// Orientation
	$add( 'rkb_overview', 'Site overview', 'Overview', 'read', 'Site name, page counts, front page, whether search engines may index it, and recent pages. Start here.', 'GET', '/builder/overview' );
	$add( 'rkb_block_catalog', 'Block catalogue', 'Overview', 'read', 'Every block type a page layout may contain, with each prop, its type and its limits. Read this before writing a layout.', 'LOCAL', '', array(), array(), 'rk_builder_ai_block_catalog' );

	// Pages
	$add( 'rkb_list_pages', 'List pages', 'Pages', 'read', 'Pages with status, slug and revision. Filter by search text or status (publish, draft, private).', 'GET', '/builder/pages', array( 'search' => $str, 'status' => $str, 'per_page' => $int, 'page' => $int ) );
	$add( 'rkb_get_layout', 'Get page layout', 'Pages', 'read', 'The draft layout ({version, blocks[]}), the theme and the current revision of a page. Keep the revision for saving.', 'GET', '/builder/layout/{id}', $id, array( 'id' ) );
	$add( 'rkb_create_page', 'Create page', 'Pages', 'write', 'Create a draft page. Optional starter: "blank" or "header-footer".', 'POST', '/builder/pages/new', array( 'title' => $str, 'slug' => $str, 'starter' => $str ), array( 'title' ) );
	$add( 'rkb_update_page', 'Rename page / change address', 'Pages', 'write', 'Change a page title or its address (slug).', 'POST', '/builder/pages/{id}/update', $id + array( 'title' => $str, 'slug' => $str ), array( 'id' ) );
	$add( 'rkb_save_layout', 'Save page layout (draft)', 'Pages', 'write', 'Save a draft layout. Send status "draft", the layout object and expectedRevision (the revision from rkb_get_layout; 0 for a new page). Nothing goes live until rkb_publish.', 'POST', '/builder/layout/{id}', $id + array( 'status' => array( 'type' => 'string', 'enum' => array( 'draft' ) ), 'layout' => $obj, 'theme' => $obj, 'expectedRevision' => $int ), array( 'id', 'status', 'layout', 'expectedRevision' ) );
	$add( 'rkb_publish', 'Publish page', 'Pages', 'write', 'Make the saved draft live. expectedRevision is the draft revision you saved.', 'POST', '/builder/publish/{id}', $id + array( 'expectedRevision' => $int ), array( 'id', 'expectedRevision' ) );
	$add( 'rkb_unpublish', 'Unpublish page', 'Pages', 'write', 'Take a page offline (it becomes a draft).', 'POST', '/builder/unpublish/{id}', $id, array( 'id' ) );
	$add( 'rkb_duplicate_page', 'Duplicate page', 'Pages', 'write', 'Copy a page as a new draft.', 'POST', '/builder/pages/{id}/duplicate', $id, array( 'id' ) );
	$add( 'rkb_set_front_page', 'Make front page', 'Pages', 'write', 'Use a published page as the home page.', 'POST', '/builder/pages/{id}/front', $id, array( 'id' ) );
	$add( 'rkb_trash_page', 'Move page to trash', 'Pages', 'write', 'Move a page to the WordPress trash (it can be restored there).', 'POST', '/builder/pages/{id}/trash', $id, array( 'id' ) );
	$add( 'rkb_preview_link', 'Draft preview link', 'Pages', 'write', 'A short-lived private link that shows the draft.', 'POST', '/builder/preview-token/{id}', $id, array( 'id' ) );
	$add( 'rkb_list_revisions', 'List revisions', 'Pages', 'read', 'Saved revisions of a page.', 'GET', '/builder/revisions/{id}', $id, array( 'id' ) );
	$add( 'rkb_restore_revision', 'Restore revision', 'Pages', 'write', 'Bring back an earlier revision as a new draft revision.', 'POST', '/builder/revisions/{id}/{revisionId}/restore', $id + array( 'revisionId' => $int ), array( 'id', 'revisionId' ) );

	// Search and sharing
	$add( 'rkb_get_page_seo', 'Get page SEO', 'SEO', 'read', 'Search title, description, social image, noindex and the page\'s JSON-LD schema choices.', 'GET', '/builder/pages/{id}/seo', $id, array( 'id' ) );
	$add( 'rkb_set_page_seo', 'Set page SEO', 'SEO', 'write', 'Set title, description, image, noindex and schema. schema holds pageType, breadcrumb, business, article{on,type}, service{on,name,description}, product{on,name,price,currency,availability,brand}, faq{on,items "Q | A" per line}, review{on,rating,count}.', 'POST', '/builder/pages/{id}/seo', $id + array( 'title' => $str, 'description' => $str, 'image' => $str, 'noindex' => $bool, 'schema' => $obj ), array( 'id' ) );

	// Content
	$add( 'rkb_list_types', 'List content types', 'Content', 'read', 'Content types with their fields, categories and entry counts.', 'GET', '/builder/types' );
	$add( 'rkb_set_types', 'Save content types', 'Content', 'write', 'Replace the content type definitions (send the whole list from rkb_list_types with your changes).', 'POST', '/builder/types', array( 'types' => array( 'type' => 'array' ) ), array( 'types' ) );
	$add( 'rkb_list_entries', 'List entries', 'Content', 'read', 'Entries of one content type (type is its slug).', 'GET', '/builder/entries/{type}', array( 'type' => $str, 'search' => $str, 'page' => $int ), array( 'type' ) );
	$add( 'rkb_get_entry', 'Get entry', 'Content', 'read', 'One entry with every field value.', 'GET', '/builder/entry/{id}', $id, array( 'id' ) );
	$add( 'rkb_create_entry', 'Create entry', 'Content', 'write', 'Create an entry of a content type. status is publish, draft, pending or private; fields holds the custom field values by field name (see rkb_list_types); terms holds category slugs by group; image is a media ID.', 'POST', '/builder/entries/{type}', array( 'type' => $str, 'title' => $str, 'slug' => $str, 'status' => $str, 'excerpt' => $str, 'content' => $str, 'image' => $int, 'menuOrder' => $int, 'terms' => $obj, 'fields' => $obj, 'seo' => $obj ), array( 'type', 'title' ) );
	$add( 'rkb_update_entry', 'Update entry', 'Content', 'write', 'Change an entry. Send only what changes.', 'POST', '/builder/entry/{id}', $id + array( 'title' => $str, 'slug' => $str, 'status' => $str, 'excerpt' => $str, 'content' => $str, 'image' => $int, 'menuOrder' => $int, 'terms' => $obj, 'fields' => $obj, 'seo' => $obj ), array( 'id' ) );
	$add( 'rkb_duplicate_entry', 'Duplicate entry', 'Content', 'write', 'Copy an entry as a draft.', 'POST', '/builder/entry/{id}/duplicate', $id, array( 'id' ) );
	$add( 'rkb_trash_entry', 'Move entry to trash', 'Content', 'write', 'Move an entry to the WordPress trash (recoverable).', 'POST', '/builder/entry/{id}/trash', $id, array( 'id' ) );

	// Templates
	$add( 'rkb_list_templates', 'List templates', 'Templates', 'read', 'Single, archive, card, 404, header and footer templates.', 'GET', '/builder/templates' );
	$add( 'rkb_create_template', 'Create template', 'Templates', 'write', 'Create a template (kind: single, archive, loop, notfound, header, footer; postType for single/archive/loop).', 'POST', '/builder/templates', array( 'title' => $str, 'kind' => $str, 'postType' => $str, 'taxonomy' => $str ), array( 'title', 'kind' ) );
	$add( 'rkb_update_template', 'Update template', 'Templates', 'write', 'Rename a template or switch it on or off (active).', 'POST', '/builder/templates/{id}/update', $id + array( 'title' => $str, 'active' => $bool ), array( 'id' ) );
	$add( 'rkb_delete_template', 'Delete template', 'Templates', 'full', 'Delete a template for good.', 'POST', '/builder/templates/{id}/delete', $id, array( 'id' ) );

	// Media
	$add( 'rkb_list_media', 'List images', 'Media', 'read', 'Images in the media library. Set detail for caption and description, missing_alt to see only images without alt text.', 'GET', '/builder/media', array( 'search' => $str, 'per_page' => $int, 'page' => $int, 'detail' => $bool, 'missing_alt' => $bool ) );
	$add( 'rkb_update_media', 'Edit image text', 'Media', 'write', 'Set alt text, title, caption and description of an image.', 'POST', '/builder/media/{id}', $id + array( 'alt' => $str, 'title' => $str, 'caption' => $str, 'description' => $str ), array( 'id' ) );
	$add( 'rkb_delete_media', 'Delete image', 'Media', 'full', 'Delete an image file for good.', 'POST', '/builder/media/{id}/delete', $id, array( 'id' ) );

	// Reusable blocks
	$add( 'rkb_list_reusables', 'List reusable blocks', 'Reusable blocks', 'read', 'Saved blocks shared by pages, with how many pages use each.', 'GET', '/builder/reusables', array( 'uses' => $bool ) );
	$add( 'rkb_create_reusable', 'Create reusable block', 'Reusable blocks', 'write', 'Save a block ({type, props}) to the library.', 'POST', '/builder/reusables', array( 'name' => $str, 'block' => $obj ), array( 'name', 'block' ) );
	$add( 'rkb_update_reusable', 'Update reusable block', 'Reusable blocks', 'write', 'Rename a reusable block or change its content (changes every page that uses it).', 'POST', '/builder/reusables/{id}', $id + array( 'name' => $str, 'block' => $obj ), array( 'id' ) );
	$add( 'rkb_delete_reusable', 'Delete reusable block', 'Reusable blocks', 'full', 'Delete an unused reusable block for good (refused while a page uses it).', 'POST', '/builder/reusables/{id}/delete', $id, array( 'id' ) );

	// Site
	$add( 'rkb_get_site', 'Get site settings', 'Site', 'read', 'Site name, tagline, search visibility, front page and business details (organization).', 'GET', '/builder/site' );
	$add( 'rkb_set_site', 'Save site settings', 'Site', 'write', 'Change site name, tagline, searchVisible, frontPageId or organization details.', 'POST', '/builder/site', array( 'name' => $str, 'tagline' => $str, 'searchVisible' => $bool, 'frontPageId' => $int, 'organization' => $obj ) );
	$add( 'rkb_get_global', 'Get global settings', 'Site', 'read', 'Content width, gutters, toolbar style, theme styles and speed clean-ups.', 'GET', '/builder/global' );
	$add( 'rkb_set_global', 'Save global settings', 'Site', 'write', 'Change layout_width, gutter, gutter_mobile, admin_bar (default|builder|hidden), theme_styles, no_emojis, no_embeds, no_block_css, no_head_clutter.', 'POST', '/builder/global', array( 'layout_width' => $int, 'gutter' => $int, 'gutter_mobile' => $int, 'admin_bar' => $str, 'theme_styles' => $bool, 'no_emojis' => $bool, 'no_embeds' => $bool, 'no_block_css' => $bool, 'no_head_clutter' => $bool ) );
	$add( 'rkb_get_theme', 'Get design theme', 'Site', 'read', 'Colours, type and spacing used on every page.', 'GET', '/theme-config' );
	$add( 'rkb_set_theme', 'Save design theme', 'Site', 'write', 'Replace the design theme (send the whole object from rkb_get_theme with your changes).', 'POST', '/theme-config', array(), array() );
	$add( 'rkb_get_redirects', 'Get redirects', 'Site', 'read', 'Old-address to new-address rules.', 'GET', '/builder/redirects' );
	$add( 'rkb_set_redirects', 'Save redirects', 'Site', 'write', 'Replace the redirect list: items [{from, to, code 301|302}].', 'POST', '/builder/redirects', array( 'items' => array( 'type' => 'array' ) ), array( 'items' ) );
	$add( 'rkb_get_code', 'Get code and tracking', 'Site', 'read', 'Head, body and footer snippets, and the analytics ID.', 'GET', '/builder/code' );
	$add( 'rkb_set_code', 'Save code and tracking', 'Site', 'full', 'Change the code printed on every page (scripts run on the live site, so this needs full access).', 'POST', '/builder/code', array(), array() );
	$add( 'rkb_get_reviews', 'Get reviews', 'Site', 'read', 'Google reviews settings and the reviews shown on the site.', 'GET', '/builder/reviews-admin' );
	$add( 'rkb_set_review_items', 'Save review list', 'Site', 'write', 'Replace the review list: items [{id, author, rating, text, hidden, ...}].', 'POST', '/builder/reviews-admin/items', array( 'items' => array( 'type' => 'array' ) ), array( 'items' ) );

	// Copy: make the wording about the owner's business
	$arr = array( 'type' => 'array' );
	$add( 'rkb_ai_brief', 'Business brief', 'Copy', 'read', 'Who the business is: site name, business details and the owner\'s own notes (about, customers, tone, services). Read this before rewriting any wording.', 'LOCAL', '', array(), array(), 'rk_builder_ai_brief_tool' );
	$add( 'rkb_copy_extract', 'Read the site wording', 'Copy', 'read', 'Every editable text of the pages, templates (header, footer) and reusable blocks, with each text\'s limit. scope: all|pages|templates|reusables; id + kind for one document; includeSeo adds each page\'s search title and description; offset/limit page through (next tells where to continue).', 'LOCAL', '', array( 'scope' => $str, 'kind' => $str, 'id' => $int, 'includeSeo' => $bool, 'offset' => $int, 'limit' => $int ), array(), 'rk_builder_copy_extract' );
	$add( 'rkb_copy_apply', 'Write rewritten wording (drafts)', 'Copy', 'write', 'Save rewritten texts as DRAFTS (the live site does not change). edits: [{kind: page|template|reusable, id, blockId, prop, text}]; seo: [{id, title, description}]. Use dryRun first. Texts are checked against their limits; list-style texts must keep their lines and every link, address and colour. Skipped edits come back with the reason.', 'LOCAL', '', array( 'edits' => $arr, 'seo' => $arr, 'dryRun' => $bool ), array(), 'rk_builder_copy_apply' );

	$T = apply_filters( 'rk_builder_mcp_tools', $T );
	return is_array( $T ) ? $T : array();
}

function rk_builder_ai_tool_allowed( array $tool, $level ) {
	$rank = array_merge( rk_builder_ai_levels(), array() );
	$need = isset( $rank[ $tool['risk'] ] ) ? $rank[ $tool['risk'] ] : 2;
	return $need <= $rank[ $level ];
}

/** The JSON-Schema of a tool's arguments. */
function rk_builder_ai_input_schema( array $tool ) {
	$props = array();
	foreach ( $tool['props'] as $k => $p ) { $props[ $k ] = is_array( $p ) ? $p : array( 'type' => 'string' ); }
	$schema = array( 'type' => 'object', 'properties' => $props ? $props : new stdClass(), 'additionalProperties' => 'POST' === $tool['method'] );
	if ( $tool['required'] ) { $schema['required'] = array_values( $tool['required'] ); }
	return $schema;
}

/** What tools/list sends for one tool. */
function rk_builder_ai_describe( $name, array $tool ) {
	return array(
		'name'        => $name,
		'title'       => $tool['title'],
		'description' => $tool['description'],
		'inputSchema' => rk_builder_ai_input_schema( $tool ),
		'annotations' => array(
			'readOnlyHint'    => 'read' === $tool['risk'],
			'destructiveHint' => 'full' === $tool['risk'],
			'idempotentHint'  => 'read' === $tool['risk'],
			'openWorldHint'   => false,
		),
	);
}

/** Block types and the fields each accepts, from the same specs the validator uses. */
function rk_builder_ai_block_catalog() {
	$out = array();
	foreach ( rk_builder_block_specs() as $type => $fields ) {
		$f = array();
		foreach ( $fields as $name => $spec ) {
			$row = array( 'type' => $spec['t'], 'required' => empty( $spec['opt'] ) );
			foreach ( array( 'min', 'max' ) as $k ) { if ( isset( $spec[ $k ] ) ) { $row[ $k ] = $spec[ $k ]; } }
			if ( isset( $spec['values'] ) ) { $row['values'] = $spec['values']; }
			$f[ $name ] = $row;
		}
		$out[ $type ] = $f;
	}
	return array(
		'layout' => 'A layout is {"version":1,"blocks":[{"id":"<unique string>","type":"<block type>","props":{...}}]}. Props not listed for a type are rejected; required props must be present; link fields take a path like /contact or an https address; image fields take a media-library URL.',
		'blocks' => $out,
	);
}

/* ------------------------------------------------------------------ *
 * Running a tool
 * ------------------------------------------------------------------ */

function rk_builder_ai_error_text( $code, $message ) {
	return array( 'code' => (string) $code, 'message' => (string) $message );
}

/** @return array{data:mixed,error:bool} */
function rk_builder_ai_run( $name, array $args ) {
	$tools = rk_builder_ai_tools();
	if ( ! isset( $tools[ $name ] ) || ! rk_builder_ai_tool_allowed( $tools[ $name ], rk_builder_ai_level() ) ) {
		return array( 'data' => rk_builder_ai_error_text( 'rk_mcp_unknown_tool', 'Unknown tool, or the access level is too low for it: ' . $name ), 'error' => true );
	}
	$tool = $tools[ $name ];
	if ( 'LOCAL' === $tool['method'] ) {
		return array( 'data' => is_callable( $tool['callback'] ) ? call_user_func( $tool['callback'], $args ) : null, 'error' => false );
	}
	$path = $tool['path'];
	if ( preg_match_all( '/\{([A-Za-z]+)\}/', $path, $m ) ) {
		foreach ( $m[1] as $key ) {
			if ( ! isset( $args[ $key ] ) || ! is_scalar( $args[ $key ] ) || '' === (string) $args[ $key ] ) {
				return array( 'data' => rk_builder_ai_error_text( 'rk_mcp_bad_arguments', 'Missing argument: ' . $key ), 'error' => true );
			}
			$path = str_replace( '{' . $key . '}', rawurlencode( (string) $args[ $key ] ), $path );
			unset( $args[ $key ] );
		}
	}
	$req = new WP_REST_Request( $tool['method'], '/' . RK_BUILDER_NS . $path );
	if ( 'GET' === $tool['method'] ) {
		foreach ( $args as $k => $v ) { $req->set_param( $k, is_bool( $v ) ? ( $v ? '1' : '' ) : $v ); }
	} else {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $args ? $args : new stdClass() ) );
	}
	$resp = rest_do_request( $req );
	$data = $resp->get_data();
	return array( 'data' => $data, 'error' => (bool) $resp->is_error() );
}

function rk_builder_ai_log( $name, $ok ) {
	$u   = wp_get_current_user();
	$log = get_option( 'rk_builder_ai_log', array() );
	$log = is_array( $log ) ? $log : array();
	$key = isset( $GLOBALS['rk_builder_ai_key'] ) ? $GLOBALS['rk_builder_ai_key'] : null;
	array_unshift( $log, array( 't' => gmdate( 'c' ), 'user' => $u && isset( $u->user_login ) ? (string) $u->user_login : '', 'tool' => (string) $name, 'ok' => (bool) $ok, 'via' => is_array( $key ) ? $key['name'] : '' ) );
	update_option( 'rk_builder_ai_log', array_slice( $log, 0, RK_BUILDER_MCP_LOG_LIMIT ), false );
}

/* ------------------------------------------------------------------ *
 * The protocol
 * ------------------------------------------------------------------ */

function rk_builder_ai_rpc_result( $id, $result ) { return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ); }
function rk_builder_ai_rpc_error( $id, $code, $message ) { return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) ); }

function rk_builder_ai_instructions() {
	return "RK Builder manages a WordPress site made of pages (each a layout of blocks), content types and entries, templates, media, reusable blocks and site settings.\n"
		. "Start with rkb_overview. To build or change a page: rkb_block_catalog (what blocks exist), rkb_get_layout (current draft and revision), then rkb_save_layout with status \"draft\" and expectedRevision, then rkb_publish with the revision you saved. A save that returns a conflict means someone else changed the page: read it again and redo your edit.\n"
		. "To make a site's wording about the owner's business (for example after installing a kit): rkb_ai_brief, rkb_copy_extract, then rkb_copy_apply (dryRun first). It saves drafts only; the owner reviews and publishes. The prompt \"rewrite-site-copy\" has the full steps.\n"
		. "Read before you write, change one thing at a time, and tell the user what you changed. Deleting is only available at the full access level.";
}

function rk_builder_ai_dispatch( $msg ) {
	if ( ! is_array( $msg ) ) { return rk_builder_ai_rpc_error( null, -32600, 'Invalid request' ); }
	$id     = isset( $msg['id'] ) ? $msg['id'] : null;
	$method = isset( $msg['method'] ) ? (string) $msg['method'] : '';
	$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
	if ( 0 === strpos( $method, 'notifications/' ) ) { return null; }
	switch ( $method ) {
		case 'initialize':
			$proto = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) && '' !== $params['protocolVersion'] ? $params['protocolVersion'] : RK_BUILDER_MCP_PROTO;
			return rk_builder_ai_rpc_result( $id, array(
				'protocolVersion' => $proto,
				'capabilities'    => array( 'tools' => array( 'listChanged' => false ), 'prompts' => array( 'listChanged' => false ) ),
				'serverInfo'      => array( 'name' => 'rk-builder', 'version' => defined( 'RK_BUILDER_VERSION' ) ? RK_BUILDER_VERSION : '1' ),
				'instructions'    => rk_builder_ai_instructions(),
			) );
		case 'ping':
			return rk_builder_ai_rpc_result( $id, new stdClass() );
		case 'tools/list':
			$level = rk_builder_ai_level();
			$list  = array();
			foreach ( rk_builder_ai_tools() as $name => $tool ) {
				if ( rk_builder_ai_tool_allowed( $tool, $level ) ) { $list[] = rk_builder_ai_describe( $name, $tool ); }
			}
			return rk_builder_ai_rpc_result( $id, array( 'tools' => $list ) );
		case 'prompts/list':
				return rk_builder_ai_rpc_result( $id, array( 'prompts' => rk_builder_ai_prompts() ) );
			case 'prompts/get':
				$pname = isset( $params['name'] ) ? (string) $params['name'] : '';
				if ( 'rewrite-site-copy' !== $pname ) { return rk_builder_ai_rpc_error( $id, -32602, 'Unknown prompt: ' . $pname ); }
				$pargs = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				return rk_builder_ai_rpc_result( $id, array( 'description' => 'Rewrite this site for your business', 'messages' => array( array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => rk_builder_ai_rewrite_prompt( $pargs ) ) ) ) ) );
			case 'tools/call':
			$name = isset( $params['name'] ) ? (string) $params['name'] : '';
			$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
			$r    = rk_builder_ai_run( $name, $args );
			rk_builder_ai_log( $name, ! $r['error'] );
			$text = (string) wp_json_encode( $r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( strlen( $text ) > RK_BUILDER_MCP_MAX_TEXT ) { $text = substr( $text, 0, RK_BUILDER_MCP_MAX_TEXT ) . '… [truncated: ask for a smaller page of results]'; }
			return rk_builder_ai_rpc_result( $id, array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ), 'isError' => $r['error'] ) );
	}
	return rk_builder_ai_rpc_error( $id, -32601, 'Method not found: ' . $method );
}

function rk_builder_ai_permission( $req ) {
	unset( $GLOBALS['rk_builder_ai_key'] );
	if ( ! rk_builder_ai_settings()['enabled'] ) {
		return new WP_Error( 'rk_mcp_disabled', 'The MCP server is switched off. An administrator can turn it on under RK Builder > AI & MCP.', array( 'status' => 403 ) );
	}
	$token = rk_builder_ai_request_token( $req );
	if ( null !== $token ) {
		$key  = rk_builder_ai_key_find( $token );
		$user = $key ? get_userdata( (int) $key['user'] ) : false;
		if ( ! $key || ! $user ) {
			return new WP_Error( 'rk_unauthorized', 'That connection key is not valid, or it was revoked.', array( 'status' => 401 ) );
		}
		wp_set_current_user( (int) $key['user'] );
		$GLOBALS['rk_builder_ai_key'] = $key;
		rk_builder_ai_key_touch( $key );
	}
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rk_unauthorized', 'Sign in with a connection key (X-RK-API-Key or Bearer) or an Application Password (HTTP Basic).', array( 'status' => 401, 'headers' => array( 'WWW-Authenticate' => 'Basic realm="RK Builder"' ) ) );
	}
	return current_user_can( 'edit_pages' ) ? true : new WP_Error( 'rk_forbidden', 'This account may not edit pages.', array( 'status' => 403 ) );
}

function rk_builder_ai_handle( $req ) {
	$body = $req->get_json_params();
	if ( ! is_array( $body ) ) { return new WP_REST_Response( rk_builder_ai_rpc_error( null, -32700, 'Parse error' ), 200 ); }
	if ( isset( $body[0] ) ) {
		$out = array();
		foreach ( $body as $msg ) { $r = rk_builder_ai_dispatch( $msg ); if ( null !== $r ) { $out[] = $r; } }
		return new WP_REST_Response( $out ? $out : null, $out ? 200 : 202 );
	}
	$r = rk_builder_ai_dispatch( $body );
	return null === $r ? new WP_REST_Response( null, 202 ) : new WP_REST_Response( $r, 200 );
}

function rk_builder_ai_handle_get( $req ) {
	if ( false !== stripos( (string) $req->get_header( 'accept' ), 'text/event-stream' ) ) { return new WP_REST_Response( null, 405, array( 'Allow' => 'POST' ) ); }
	return new WP_REST_Response( array( 'mcp' => true, 'server' => 'rk-builder', 'transport' => 'streamable-http', 'note' => 'POST JSON-RPC 2.0 here: initialize, tools/list, tools/call.' ), 200 );
}

/* ------------------------------------------------------------------ *
 * Settings screen API (administrators)
 * ------------------------------------------------------------------ */

function rk_builder_ai_key_item( array $k ) {
	$u = get_userdata( (int) $k['user'] );
	return array( 'id' => $k['id'], 'name' => $k['name'], 'prefix' => $k['prefix'], 'level' => $k['level'], 'created' => $k['created'], 'used' => $k['used'], 'user' => $u && isset( $u->user_login ) ? (string) $u->user_login : '' );
}

function rk_builder_ai_payload() {
	$s     = rk_builder_ai_settings();
	$tools = array();
	foreach ( rk_builder_ai_tools() as $name => $t ) {
		$tools[] = array( 'name' => $name, 'title' => $t['title'], 'group' => $t['group'], 'risk' => $t['risk'], 'description' => $t['description'], 'enabled' => rk_builder_ai_tool_allowed( $t, $s['level'] ) );
	}
	$log = get_option( 'rk_builder_ai_log', array() );
	$u   = wp_get_current_user();
	return array(
		'settings'      => $s,
		'endpoint'      => rk_builder_ai_endpoint(),
		'tools'         => $tools,
		'log'           => is_array( $log ) ? array_values( $log ) : array(),
		'keys'          => array_map( 'rk_builder_ai_key_item', rk_builder_ai_keys() ),
		'passwordsOk'   => function_exists( 'wp_is_application_passwords_available' ) ? (bool) wp_is_application_passwords_available() : false,
		'profileUrl'    => admin_url( 'profile.php#application-passwords-section' ),
		'username'      => $u && isset( $u->user_login ) ? (string) $u->user_login : '',
		'brief'         => rk_builder_ai_brief(),
		'prompt'        => rk_builder_ai_rewrite_prompt(),
	);
}

function rk_builder_handle_set_ai_brief( $req ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_brief' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	foreach ( $body as $k => $v ) {
		if ( ! isset( rk_builder_ai_brief_fields()[ (string) $k ] ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
		elseif ( ! is_string( $v ) || rk_builder_strlen( $v ) > rk_builder_ai_brief_fields()[ $k ] ) { rk_builder_add_issue( $issues, (string) $k, 'Up to ' . rk_builder_ai_brief_fields()[ $k ] . ' characters' ); }
	}
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_brief', $issues ); }
	$brief = rk_builder_ai_brief_save( $body );
	return rk_builder_no_store( array( 'brief' => $brief, 'prompt' => rk_builder_ai_rewrite_prompt() ) );
}

function rk_builder_handle_get_mcp( $req ) { return rk_builder_no_store( rk_builder_ai_payload() ); }

function rk_builder_handle_set_mcp( $req ) {
	$body = rk_builder_json_body( $req, 'rk_invalid_mcp' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'enabled', 'level', 'clearLog' ), true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	if ( array_key_exists( 'enabled', $body ) && ! is_bool( $body['enabled'] ) ) { rk_builder_add_issue( $issues, 'enabled', 'Expected true or false' ); }
	if ( array_key_exists( 'level', $body ) && ( ! is_string( $body['level'] ) || ! isset( rk_builder_ai_levels()[ $body['level'] ] ) ) ) { rk_builder_add_issue( $issues, 'level', 'Expected read, write or full' ); }
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_mcp', $issues ); }
	$s = rk_builder_ai_settings();
	if ( array_key_exists( 'enabled', $body ) ) { $s['enabled'] = $body['enabled']; }
	if ( array_key_exists( 'level', $body ) ) { $s['level'] = $body['level']; }
	update_option( 'rk_builder_mcp', $s, false );
	if ( ! empty( $body['clearLog'] ) ) { update_option( 'rk_builder_ai_log', array(), false ); }
	return rk_builder_handle_get_mcp( $req );
}

function rk_builder_handle_create_mcp_key( $req ) {
	$body = rk_builder_json_body( $req, 'rk_invalid_mcp' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'name', 'level' ), true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	$name = isset( $body['name'] ) && is_string( $body['name'] ) ? rk_builder_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $body['name'] ) ) ), 0, 60 ) : '';
	if ( '' === $name ) { rk_builder_add_issue( $issues, 'name', 'Give the key a name, for example the assistant that will use it' ); }
	$level = isset( $body['level'] ) ? $body['level'] : 'write';
	if ( ! is_string( $level ) || ! isset( rk_builder_ai_levels()[ $level ] ) ) { rk_builder_add_issue( $issues, 'level', 'Expected read, write or full' ); }
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_mcp', $issues ); }
	$made = rk_builder_ai_key_create( $name, $level, get_current_user_id() );
	if ( is_wp_error( $made ) ) { return $made; }
	$out = rk_builder_ai_payload();
	$out['created'] = array( 'token' => $made['token'], 'id' => $made['key']['id'] ); // the only time the key is shown
	return rk_builder_no_store( $out );
}

function rk_builder_handle_revoke_mcp_key( $req ) {
	if ( ! rk_builder_ai_key_revoke( (string) $req['id'] ) ) { return rk_builder_not_found( 'Key not found.' ); }
	return rk_builder_handle_get_mcp( $req );
}

function rk_builder_register_mcp_routes( $ns ) {
	register_rest_route( $ns, '/builder/mcp/brief', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_ai_brief', 'permission_callback' => 'rk_builder_perm_theme_write' ) );
	register_rest_route( $ns, '/builder/mcp/keys', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_create_mcp_key', 'permission_callback' => 'rk_builder_perm_theme_write' ) );
	register_rest_route( $ns, '/builder/mcp/keys/(?P<id>[a-f0-9]{10})/revoke', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_revoke_mcp_key', 'permission_callback' => 'rk_builder_perm_theme_write' ) );
	register_rest_route( $ns, '/builder-mcp', array(
		array( 'methods' => 'POST', 'callback' => 'rk_builder_ai_handle', 'permission_callback' => 'rk_builder_ai_permission' ),
		array( 'methods' => 'GET', 'callback' => 'rk_builder_ai_handle_get', 'permission_callback' => 'rk_builder_ai_permission' ),
	) );
	register_rest_route( $ns, '/builder/mcp', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_mcp', 'permission_callback' => 'rk_builder_perm_theme_write' ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_mcp', 'permission_callback' => 'rk_builder_perm_theme_write' ),
	) );
}
