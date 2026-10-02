<?php
/**
 * Reusable blocks: a block (type + props) saved once in a library and placed on many pages by reference.
 *
 *   - Storage: a private post type `rk_reusable` (title = name, meta `_rk_reusable_block` = {type, props} JSON).
 *   - In a layout a reusable is the block { type: "reusable", props: { refId } }. Editing the library entry changes
 *     every page that uses it; "Detach" in the editor copies the content back into the page as an ordinary block.
 *   - A reusable cannot contain another reusable (no nesting, so no cycles).
 *   - Public pages resolve the reference at render time (PHP renderer) or receive a `reusables` map next to the layout
 *     (REST /public/page/{slug}, used by the Node SSR).
 *
 * REST (all under /builder/reusables): GET list; POST create; POST {id} update; POST {id}/delete (refused while a
 * page still uses it). Reading needs edit_pages; changing needs edit_pages + edit_others_pages because it changes
 * pages the author may not own.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const RK_BUILDER_REUSABLE_TYPE = 'rk_reusable';
const RK_BUILDER_MAX_REUSABLES = 200;

function rk_builder_register_reusable_type() {
	register_post_type( RK_BUILDER_REUSABLE_TYPE, array(
		'labels'              => array( 'name' => 'Reusable blocks', 'singular_name' => 'Reusable block' ),
		'public'              => false,
		'show_ui'             => false,
		'show_in_rest'        => false,
		'show_in_menu'        => false,
		'exclude_from_search' => true,
		'rewrite'             => false,
		'query_var'           => false,
		'supports'            => array( 'title' ),
	) );
}

/* ------------------------------------------------------------------ *
 * Read
 * ------------------------------------------------------------------ */

/** Decode and re-validate the stored block; null when missing or corrupt. */
function rk_builder_reusable_stored_block( $id ) {
	$raw = get_post_meta( $id, '_rk_reusable_block', true );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || array() !== rk_builder_reusable_block_issues( $data, null ) ) { return null; }
	return rk_builder_reusable_canonical_block( $data );
}

/** @return array{id:int,name:string,slug:string,block:array}|null */
function rk_builder_reusable_get( $id ) {
	$id   = (int) $id;
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || ! is_object( $post ) || RK_BUILDER_REUSABLE_TYPE !== $post->post_type || 'publish' !== $post->post_status ) { return null; }
	$block = rk_builder_reusable_stored_block( $id );
	if ( null === $block ) { return null; }
	return array( 'id' => (int) $post->ID, 'name' => rk_builder_plain( $post->post_title ), 'slug' => (string) $post->post_name, 'block' => $block );
}

/** Block type held by a library entry ('' when it is missing). */
function rk_builder_reusable_type( $id ) {
	$r = rk_builder_reusable_get( (int) $id );
	return ( null !== $r && isset( $r['block']['type'] ) ) ? (string) $r['block']['type'] : '';
}

function rk_builder_reusable_list() {
	$posts = get_posts( array( 'post_type' => RK_BUILDER_REUSABLE_TYPE, 'post_status' => 'publish', 'posts_per_page' => RK_BUILDER_MAX_REUSABLES, 'orderby' => 'title', 'order' => 'ASC' ) );
	$out   = array();
	foreach ( $posts as $p ) {
		$r = rk_builder_reusable_get( (int) $p->ID );
		if ( null !== $r ) { $out[] = $r; }
	}
	return $out;
}

/** IDs of reusables referenced by a layout. */
function rk_builder_layout_reusable_ids( $layout ) {
	$ids = array();
	if ( is_array( $layout ) && isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ) {
		foreach ( $layout['blocks'] as $b ) {
			if ( is_array( $b ) && isset( $b['type'], $b['props']['refId'] ) && 'reusable' === $b['type'] && is_numeric( $b['props']['refId'] ) ) { $ids[ (int) $b['props']['refId'] ] = true; }
		}
	}
	return array_keys( $ids );
}

/** {id => reusable record} for the reusables a layout references (public REST payload). */
function rk_builder_reusables_for_layout( $layout ) {
	$map = array();
	foreach ( rk_builder_layout_reusable_ids( $layout ) as $id ) {
		$r = rk_builder_reusable_get( $id );
		if ( null !== $r ) { $map[ (string) $id ] = $r; }
	}
	return $map;
}

/** Page IDs whose draft or published layout references this reusable. */
function rk_builder_reusable_pages_using( $id ) {
	$needle = '"refId":' . (int) $id . '}';
	return get_posts( array(
		'post_type'      => 'page',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => 500,
		'fields'         => 'ids',
		'meta_query'     => array(
			'relation' => 'OR',
			array( 'key' => '_rk_layout_draft', 'value' => $needle, 'compare' => 'LIKE' ),
			array( 'key' => '_rk_layout_published', 'value' => $needle, 'compare' => 'LIKE' ),
		),
	) );
}

/* ------------------------------------------------------------------ *
 * Validation
 * ------------------------------------------------------------------ */

/**
 * Issues for a {type, props} block destined for the library. Same strict rules as a layout block, and never "reusable".
 *
 * @param mixed      $block
 * @param array|null $hosts image hosts (null = do not check hosts)
 */
function rk_builder_reusable_block_issues( $block, $hosts = array() ) {
	$issues = array();
	if ( ! rk_builder_is_object( $block ) ) {
		rk_builder_add_issue( $issues, '', 'Expected object' );
		return $issues;
	}
	foreach ( $block as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'type', 'props' ), true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	if ( ! isset( $block['type'] ) || ! is_string( $block['type'] ) || 'reusable' === $block['type'] || ! isset( rk_builder_block_specs()[ $block['type'] ] ) ) {
		rk_builder_add_issue( $issues, 'type', 'A reusable block must be one of the content block types (not another reusable)' );
	}
	if ( ! isset( $block['props'] ) ) { rk_builder_add_issue( $issues, 'props', 'Required' ); }
	if ( $issues ) { return $issues; }
	$doc    = array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => array( array( 'id' => 'reusable-inner', 'type' => $block['type'], 'props' => $block['props'] ) ) );
	$layout = rk_builder_validate_layout( $doc, $hosts );
	foreach ( $layout as $i ) {
		$issues[] = array( 'path' => preg_replace( '/^blocks\.0\.?/', '', $i['path'] ), 'message' => $i['message'] );
	}
	return $issues;
}

function rk_builder_reusable_canonical_block( array $block ) {
	$doc = rk_builder_canonicalize_layout( array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => array( array( 'id' => 'reusable-inner', 'type' => $block['type'], 'props' => $block['props'] ) ) ) );
	return array( 'type' => $block['type'], 'props' => $doc['blocks'][0]['props'] );
}

function rk_builder_reusable_name( $v, array &$issues ) {
	if ( ! is_string( $v ) || '' === trim( $v ) || rk_builder_strlen( trim( $v ) ) > 80 ) {
		rk_builder_add_issue( $issues, 'name', 'Name must be 1-80 characters' );
		return '';
	}
	return sanitize_text_field( $v );
}

/* ------------------------------------------------------------------ *
 * Rendering
 * ------------------------------------------------------------------ */

/** Markup mirrors client/src/blocks/reusable/View.tsx: the referenced block's own markup, nothing around it. */
function rk_builder_render_reusable( array $p, array $context = array() ) {
	$r = rk_builder_reusable_get( (int) $p['refId'] );
	if ( null === $r ) { return ''; }
	return rk_builder_render_block( array( 'id' => 'reusable-' . $r['id'], 'type' => $r['block']['type'], 'props' => $r['block']['props'] ), $context );
}

/* ------------------------------------------------------------------ *
 * REST
 * ------------------------------------------------------------------ */

function rk_builder_perm_reusable_write( $req ) { return rk_builder_authorize_caps( array( 'edit_pages', 'edit_others_pages' ) ); }

function rk_builder_reusable_item( array $r ) {
	return array( 'id' => $r['id'], 'name' => $r['name'], 'block' => $r['block'] );
}

function rk_builder_handle_list_reusables( $req ) {
	return rk_builder_no_store( array( 'items' => array_map( 'rk_builder_reusable_item', rk_builder_reusable_list() ) ) );
}

function rk_builder_reusable_body( $req, $need_all ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_reusable' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'name', 'block' ), true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	$name = null;
	if ( array_key_exists( 'name', $body ) ) { $name = rk_builder_reusable_name( $body['name'], $issues ); }
	elseif ( $need_all ) { rk_builder_add_issue( $issues, 'name', 'Required' ); }
	$block = null;
	if ( array_key_exists( 'block', $body ) ) {
		$bi = rk_builder_reusable_block_issues( $body['block'], rk_builder_allowed_image_hosts() );
		$issues = array_merge( $issues, rk_builder_prefix_issues( $bi, 'block' ) );
		if ( ! $bi ) { $block = rk_builder_reusable_canonical_block( $body['block'] ); }
	} elseif ( $need_all ) { rk_builder_add_issue( $issues, 'block', 'Required' ); }
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_reusable', $issues ); }
	return array( 'name' => $name, 'block' => $block );
}

function rk_builder_handle_create_reusable( $req ) {
	$in = rk_builder_reusable_body( $req, true );
	if ( is_wp_error( $in ) ) { return $in; }
	if ( count( rk_builder_reusable_list() ) >= RK_BUILDER_MAX_REUSABLES ) {
		return rk_builder_error( 'rk_payload_too_large', 'The library is full (' . RK_BUILDER_MAX_REUSABLES . ' reusable blocks).', 413 );
	}
	$id = wp_insert_post( array( 'post_type' => RK_BUILDER_REUSABLE_TYPE, 'post_status' => 'publish', 'post_title' => $in['name'] ), true );
	if ( is_wp_error( $id ) || ! $id ) { return rk_builder_error( 'rk_server_error', 'Could not save the reusable block.', 500 ); }
	rk_builder_write_json_meta( (int) $id, '_rk_reusable_block', $in['block'] );
	$r = rk_builder_reusable_get( (int) $id );
	$response = rk_builder_no_store( array( 'item' => rk_builder_reusable_item( $r ) ) );
	if ( $response instanceof WP_REST_Response ) { $response->set_status( 201 ); }
	return $response;
}

function rk_builder_handle_update_reusable( $req ) {
	$id = (int) $req['id'];
	$r  = rk_builder_reusable_get( $id );
	if ( null === $r ) { return rk_builder_not_found( 'Reusable block not found.' ); }
	$in = rk_builder_reusable_body( $req, false );
	if ( is_wp_error( $in ) ) { return $in; }
	if ( null !== $in['name'] && $in['name'] !== $r['name'] ) {
		wp_update_post( array( 'ID' => $id, 'post_title' => $in['name'] ) );
	}
	if ( null !== $in['block'] ) {
		rk_builder_write_json_meta( $id, '_rk_reusable_block', $in['block'] );
		// Published pages that use it now look different: purge them like a publish would.
		foreach ( rk_builder_reusable_pages_using( $id ) as $pid ) {
			$post = get_post( $pid );
			if ( $post && 'publish' === $post->post_status ) {
				rk_builder_revalidate( 'publish', (int) $pid, (string) $post->post_name );
				rk_builder_layout_changed( (int) $pid, 'reusable' );
			}
		}
	}
	return rk_builder_no_store( array( 'item' => rk_builder_reusable_item( rk_builder_reusable_get( $id ) ) ) );
}

function rk_builder_handle_delete_reusable( $req ) {
	$id = (int) $req['id'];
	if ( null === rk_builder_reusable_get( $id ) ) { return rk_builder_not_found( 'Reusable block not found.' ); }
	$using = rk_builder_reusable_pages_using( $id );
	if ( $using ) {
		return rk_builder_error( 'rk_reusable_in_use', 'This block is still used on ' . count( $using ) . ' page(s). Detach or remove it there first.', 409, array( 'pages' => array_slice( array_map( 'intval', $using ), 0, 20 ) ) );
	}
	wp_delete_post( $id, true );
	return rk_builder_no_store( array( 'ok' => true ) );
}
