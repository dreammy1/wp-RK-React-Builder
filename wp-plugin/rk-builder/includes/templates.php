<?php
/**
 * Theme builder: templates built in the normal block editor that decide how content types look on the public site.
 *
 *   - A template is a private post of type `rk_template` carrying a layout (draft and published, same storage and
 *     publish flow as a page) plus meta: `_rk_tpl_kind` (single | archive | loop), `_rk_tpl_type` (the post type it is
 *     for), `_rk_tpl_tax` (an archive for one taxonomy only), `_rk_tpl_per_page`, `_rk_tpl_active`.
 *   - single   : replaces the page of one entry (a service, a listing, ...).
 *   - archive  : replaces the list page of a type or of one of its taxonomies (the "listing" template).
 *   - loop     : the card drawn for each entry inside a Loop grid block.
 *   - Only a PUBLISHED and ACTIVE template is ever used; one template per target is active at a time.
 *   - Dynamic blocks (render/dynamic.php) read the entry being shown from the render context.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const RK_BUILDER_TEMPLATE_TYPE = 'rk_template';
const RK_BUILDER_MAX_TEMPLATES = 100;

function rk_builder_register_template_type() {
	register_post_type( RK_BUILDER_TEMPLATE_TYPE, array(
		'labels'              => array( 'name' => 'Templates', 'singular_name' => 'Template' ),
		'public'              => false,
		'show_ui'             => false,
		'show_in_rest'        => false,
		'show_in_menu'        => false,
		'exclude_from_search' => true,
		'rewrite'             => false,
		'query_var'           => false,
		'capability_type'     => 'page',
		'map_meta_cap'        => true,
		'supports'            => array( 'title' ),
	) );
}

function rk_builder_tpl_kinds() { return array( 'single', 'archive', 'loop' ); }

/* ------------------------------------------------------------------ *
 * Read
 * ------------------------------------------------------------------ */

/** The template post, or null unless it is a real, non-trashed template. */
function rk_builder_tpl_post( $id ) {
	$id   = (int) $id;
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || ! is_object( $post ) || RK_BUILDER_TEMPLATE_TYPE !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) { return null; }
	return $post;
}

function rk_builder_tpl_meta( $id ) {
	return array(
		'kind'     => (string) get_post_meta( $id, '_rk_tpl_kind', true ),
		'postType' => (string) get_post_meta( $id, '_rk_tpl_type', true ),
		'taxonomy' => (string) get_post_meta( $id, '_rk_tpl_tax', true ),
		'active'   => '1' === (string) get_post_meta( $id, '_rk_tpl_active', true ),
	);
}

/** One row for the dashboard list and the editor's template pickers. */
function rk_builder_tpl_item( $post ) {
	$m = rk_builder_tpl_meta( $post->ID );
	return array(
		'id' => (int) $post->ID, 'title' => rk_builder_plain( $post->post_title ), 'kind' => $m['kind'], 'postType' => $m['postType'], 'taxonomy' => $m['taxonomy'],
		'active' => $m['active'], 'status' => (string) $post->post_status, 'modified' => rk_builder_page_modified( $post ),
		'live' => 'publish' === $post->post_status && '' !== (string) get_post_meta( $post->ID, '_rk_layout_published', true ),
	);
}

function rk_builder_tpl_all() {
	$posts = get_posts( array( 'post_type' => RK_BUILDER_TEMPLATE_TYPE, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => RK_BUILDER_MAX_TEMPLATES, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true ) );
	return is_array( $posts ) ? $posts : array();
}

/**
 * The live template for a target: published, active, with a valid published layout.
 *
 * @return array{id:int,layout:array}|null
 */
function rk_builder_tpl_find( $kind, $type, $taxonomy = '' ) {
	if ( ! rk_builder_setting( 'enabled', true ) ) { return null; }
	foreach ( rk_builder_tpl_all() as $post ) {
		if ( 'publish' !== $post->post_status ) { continue; }
		$m = rk_builder_tpl_meta( $post->ID );
		if ( ! $m['active'] || $m['kind'] !== $kind || $m['postType'] !== $type || $m['taxonomy'] !== $taxonomy ) { continue; }
		$layout = rk_builder_get_published_layout( $post->ID );
		if ( null !== $layout ) { return array( 'id' => (int) $post->ID, 'layout' => $layout ); }
	}
	return null;
}

/** A loop template's published layout by ID (the Loop grid block's `templateId`), or null. */
function rk_builder_tpl_loop_layout( $id ) {
	$post = rk_builder_tpl_post( $id );
	if ( ! $post || 'publish' !== $post->post_status || 'loop' !== get_post_meta( $post->ID, '_rk_tpl_kind', true ) ) { return null; }
	return rk_builder_get_published_layout( $post->ID );
}

/* ------------------------------------------------------------------ *
 * Starter layouts
 * ------------------------------------------------------------------ */

function rk_builder_dyn_block_defaults( $type ) {
	$all = array(
		'dynfield'    => array( 'source' => 'title', 'tag' => 'p', 'style' => 'plain', 'align' => 'left', 'label' => '', 'prefix' => '', 'suffix' => '', 'link' => false, 'fallback' => '' ),
		'dynimage'    => array( 'source' => 'featured', 'ratio' => 'landscape', 'link' => false, 'fallback' => 'hide' ),
		'dyngallery'  => array( 'source' => '', 'cols' => 3, 'ratio' => 'square', 'gap' => 'md', 'limit' => 0 ),
		'dynrepeater' => array( 'source' => '', 'layout' => 'list', 'cols' => 2, 'heading' => '' ),
		'dyninfo'     => array( 'heading' => '', 'sources' => '', 'labels' => true, 'layout' => 'rows' ),
		'loopgrid'    => array(
			'eyebrow' => '', 'heading' => '', 'intro' => '', 'postType' => 'current', 'taxonomy' => '', 'term' => '', 'limit' => 9, 'orderBy' => 'date', 'order' => 'desc',
			'cols' => 3, 'mobileCols' => 1, 'gap' => 'md', 'templateId' => 0, 'equalHeight' => true, 'filters' => false, 'search' => false, 'pagination' => true,
			'related' => false, 'emptyText' => 'Nothing here yet.', 'tone' => 'light',
		),
	);
	return isset( $all[ $type ] ) ? $all[ $type ] : array();
}

/** One layout block; $n numbers the id. */
function rk_builder_tpl_block( $type, array $props, &$n ) {
	$n++;
	return array( 'id' => $type . '-' . $n, 'type' => $type, 'props' => array_merge( rk_builder_dyn_block_defaults( $type ), $props ) );
}

/** The site's header / footer from the reusable library (first navbar / sitefooter found), as reusable blocks. */
function rk_builder_tpl_chrome( $kind, &$n ) {
	foreach ( rk_builder_reusable_list() as $r ) {
		if ( $kind === $r['block']['type'] ) { $n++; return array( 'id' => 'reusable-' . $n, 'type' => 'reusable', 'props' => array( 'refId' => (int) $r['id'] ) ); }
	}
	return null;
}

/** A layout to start from, built from the type's own fields. */
function rk_builder_tpl_starter( $kind, array $def, $taxonomy = '' ) {
	$n      = 0;
	$blocks = array();
	$fields = $def['fields'];
	if ( 'loop' === $kind ) {
		$blocks[] = rk_builder_tpl_block( 'dynimage', array( 'source' => 'featured', 'ratio' => 'landscape', 'link' => true, 'fallback' => 'placeholder' ), $n );
		$tax = rk_builder_dyn_taxonomies( $def['slug'] );
		if ( $tax ) { $blocks[] = rk_builder_tpl_block( 'dynfield', array( 'source' => 'terms:' . $tax[0]['slug'], 'style' => 'eyebrow' ), $n ); }
		$blocks[] = rk_builder_tpl_block( 'dynfield', array( 'source' => 'title', 'tag' => 'h3', 'link' => true ), $n );
		$blocks[] = rk_builder_tpl_block( 'dynfield', array( 'source' => 'excerpt', 'tag' => 'p' ), $n );
		return array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => $blocks );
	}
	$h = rk_builder_tpl_chrome( 'navbar', $n );
	if ( $h ) { $blocks[] = $h; }
	if ( 'archive' === $kind ) {
		$title = '' !== $taxonomy ? $def['plural'] . ' by category' : $def['plural'];
		$blocks[] = array( 'id' => 'heading-' . ( ++$n ), 'type' => 'heading', 'props' => array( 'text' => $title, 'level' => 2 ) );
		$blocks[] = rk_builder_tpl_block( 'loopgrid', array( 'postType' => 'current', 'filters' => (bool) rk_builder_dyn_taxonomies( $def['slug'] ), 'search' => true, 'pagination' => true, 'limit' => 12 ), $n );
	} else {
		$blocks[] = rk_builder_tpl_block( 'dynimage', array( 'source' => 'featured', 'ratio' => 'wide' ), $n );
		$blocks[] = rk_builder_tpl_block( 'dynfield', array( 'source' => 'title', 'tag' => 'h1' ), $n );
		$info = array();
		foreach ( $fields as $f ) {
			if ( ! in_array( $f['type'], array( 'gallery', 'repeater', 'image', 'textarea' ), true ) && count( $info ) < 8 ) { $info[] = 'field:' . $f['key']; }
		}
		if ( $info ) { $blocks[] = rk_builder_tpl_block( 'dyninfo', array( 'sources' => implode( ',', $info ) ), $n ); }
		$blocks[] = rk_builder_tpl_block( 'dynfield', array( 'source' => 'content', 'tag' => 'div' ), $n );
		foreach ( $fields as $f ) {
			if ( 'gallery' === $f['type'] ) { $blocks[] = rk_builder_tpl_block( 'dyngallery', array( 'source' => 'field:' . $f['key'] ), $n ); break; }
		}
		$rep = 0;
		foreach ( $fields as $f ) {
			if ( 'repeater' === $f['type'] && $rep < 2 ) { $blocks[] = rk_builder_tpl_block( 'dynrepeater', array( 'source' => 'field:' . $f['key'], 'heading' => $f['label'] ), $n ); $rep++; }
		}
		$blocks[] = rk_builder_tpl_block( 'loopgrid', array( 'heading' => 'More ' . $def['plural'], 'limit' => 3, 'related' => true, 'pagination' => false ), $n );
	}
	$f = rk_builder_tpl_chrome( 'sitefooter', $n );
	if ( $f ) { $blocks[] = $f; }
	return array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => $blocks );
}

/* ------------------------------------------------------------------ *
 * REST
 * ------------------------------------------------------------------ */

function rk_builder_handle_list_templates( $req ) {
	$items = array();
	foreach ( rk_builder_tpl_all() as $post ) { $items[] = rk_builder_tpl_item( $post ); }
	return rk_builder_no_store( array( 'items' => $items ) );
}

/** target fields shared by create and update; returns array( values, issues ). */
function rk_builder_tpl_target( array $body, array $current = array() ) {
	$issues = array();
	$kind   = array_key_exists( 'kind', $body ) ? $body['kind'] : ( isset( $current['kind'] ) ? $current['kind'] : null );
	$type   = array_key_exists( 'postType', $body ) ? $body['postType'] : ( isset( $current['postType'] ) ? $current['postType'] : null );
	$tax    = array_key_exists( 'taxonomy', $body ) ? $body['taxonomy'] : ( isset( $current['taxonomy'] ) ? $current['taxonomy'] : '' );
	if ( ! is_string( $kind ) || ! in_array( $kind, rk_builder_tpl_kinds(), true ) ) { rk_builder_add_issue( $issues, 'kind', 'Choose single, archive or loop' ); }
	$def = is_string( $type ) ? rk_builder_dyn_type( $type ) : null;
	if ( ! $def ) { rk_builder_add_issue( $issues, 'postType', 'Choose a content type' ); }
	$tax = is_string( $tax ) ? $tax : '';
	if ( '' !== $tax ) {
		$ok = false;
		if ( $def ) { foreach ( rk_builder_dyn_taxonomies( $def['slug'] ) as $x ) { if ( $x['slug'] === $tax ) { $ok = true; } } }
		if ( ! $ok || 'archive' !== $kind ) { rk_builder_add_issue( $issues, 'taxonomy', 'Only archive templates can target one of the type\'s taxonomies' ); }
	}
	return array( array( 'kind' => $kind, 'postType' => $type, 'taxonomy' => $tax, 'def' => $def ), $issues );
}

function rk_builder_handle_create_template( $req ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_template' );
	if ( is_wp_error( $body ) ) { return $body; }
	list( $t, $issues ) = rk_builder_tpl_target( $body );
	$title = isset( $body['title'] ) && is_string( $body['title'] ) ? trim( sanitize_text_field( $body['title'] ) ) : '';
	if ( '' === $title || rk_builder_strlen( $title ) > 80 ) { rk_builder_add_issue( $issues, 'title', 'Give the template a name (up to 80 characters)' ); }
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_template', $issues ); }
	if ( count( rk_builder_tpl_all() ) >= RK_BUILDER_MAX_TEMPLATES ) { return rk_builder_error( 'rk_payload_too_large', 'Too many templates.', 413 ); }
	$id = wp_insert_post( array( 'post_type' => RK_BUILDER_TEMPLATE_TYPE, 'post_status' => 'draft', 'post_title' => $title ), true );
	if ( is_wp_error( $id ) || ! $id ) { return rk_builder_error( 'rk_server_error', 'Could not create the template.', 500 ); }
	$id = (int) $id;
	update_post_meta( $id, '_rk_tpl_kind', $t['kind'] );
	update_post_meta( $id, '_rk_tpl_type', $t['postType'] );
	update_post_meta( $id, '_rk_tpl_tax', $t['taxonomy'] );
	update_post_meta( $id, '_rk_tpl_active', '0' );
	update_post_meta( $id, '_rk_tpl_slug', rk_builder_tpl_new_slug( $title ) );
	rk_builder_commit_revision( $id, 'draft', rk_builder_tpl_starter( $t['kind'], $t['def'], $t['taxonomy'] ) );
	$r = rk_builder_no_store( array( 'item' => rk_builder_tpl_item( get_post( $id ) ) ) );
	if ( $r instanceof WP_REST_Response ) { $r->set_status( 201 ); }
	return $r;
}

/** Switch a template on or off; switching a single / archive template on switches the previous one for the same target off. */
function rk_builder_tpl_set_active( $id, $on ) {
	$m = rk_builder_tpl_meta( $id );
	if ( $on && 'loop' !== $m['kind'] ) {
		foreach ( rk_builder_tpl_all() as $other ) {
			if ( (int) $other->ID === (int) $id ) { continue; }
			$o = rk_builder_tpl_meta( $other->ID );
			if ( $o['active'] && $o['kind'] === $m['kind'] && $o['postType'] === $m['postType'] && $o['taxonomy'] === $m['taxonomy'] ) { update_post_meta( $other->ID, '_rk_tpl_active', '0' ); }
		}
	}
	update_post_meta( $id, '_rk_tpl_active', $on ? '1' : '0' );
}

/** A slug that is unique among templates; it is what an export file uses to recognise a template on another site. */
function rk_builder_tpl_new_slug( $title ) {
	$base = sanitize_title( $title );
	$base = '' !== $base ? substr( $base, 0, 60 ) : 'template';
	$used = array();
	foreach ( rk_builder_tpl_all() as $p ) { $used[ (string) get_post_meta( $p->ID, '_rk_tpl_slug', true ) ] = true; }
	$slug = $base;
	for ( $i = 2; isset( $used[ $slug ] ); $i++ ) { $slug = $base . '-' . $i; }
	return $slug;
}

function rk_builder_tpl_slug( $id ) {
	$s = (string) get_post_meta( $id, '_rk_tpl_slug', true );
	return '' !== $s ? $s : 'template-' . (int) $id;
}

function rk_builder_handle_update_template( $req ) {
	$post = rk_builder_tpl_post( $req['id'] );
	if ( ! $post ) { return rk_builder_not_found( 'Template not found.' ); }
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_template' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'title', 'active', 'taxonomy' ), true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	$cur = rk_builder_tpl_meta( $post->ID );
	list( $t, $tissues ) = rk_builder_tpl_target( array_intersect_key( $body, array( 'taxonomy' => 1 ) ), $cur );
	$issues = array_merge( $issues, $tissues );
	$title = null;
	if ( isset( $body['title'] ) ) {
		$title = is_string( $body['title'] ) ? trim( sanitize_text_field( $body['title'] ) ) : '';
		if ( '' === $title || rk_builder_strlen( $title ) > 80 ) { rk_builder_add_issue( $issues, 'title', 'Give the template a name (up to 80 characters)' ); }
	}
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_template', $issues ); }
	if ( null !== $title && $title !== $post->post_title ) { wp_update_post( array( 'ID' => $post->ID, 'post_title' => $title ) ); }
	if ( array_key_exists( 'taxonomy', $body ) ) { update_post_meta( $post->ID, '_rk_tpl_tax', $t['taxonomy'] ); }
	if ( array_key_exists( 'active', $body ) ) { rk_builder_tpl_set_active( (int) $post->ID, ! empty( $body['active'] ) ); }
	rk_builder_purge_all_public_cache();
	return rk_builder_no_store( array( 'item' => rk_builder_tpl_item( get_post( $post->ID ) ) ) );
}

function rk_builder_handle_delete_template( $req ) {
	$post = rk_builder_tpl_post( $req['id'] );
	if ( ! $post ) { return rk_builder_not_found( 'Template not found.' ); }
	if ( 'loop' === get_post_meta( $post->ID, '_rk_tpl_kind', true ) ) {
		$using = rk_builder_tpl_pages_using( (int) $post->ID );
		if ( $using ) { return rk_builder_error( 'rk_template_in_use', 'This card template is used by a Loop grid on ' . count( $using ) . ' page(s). Pick another card there first.', 409 ); }
	}
	wp_delete_post( $post->ID, true );
	rk_builder_purge_all_public_cache();
	return rk_builder_no_store( array( 'ok' => true ) );
}

/** Page and template IDs whose layouts use this loop template (LIKE search on the stored JSON, same as reusables). */
function rk_builder_tpl_pages_using( $id ) {
	$needle = '"templateId":' . (int) $id . ',';
	$needle2 = '"templateId":' . (int) $id . '}';
	$ids = array();
	foreach ( array( $needle, $needle2 ) as $n ) {
		$found = get_posts( array(
			'post_type' => array( 'page', RK_BUILDER_TEMPLATE_TYPE ), 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => 200, 'fields' => 'ids',
			'meta_query' => array( 'relation' => 'OR', array( 'key' => '_rk_layout_draft', 'value' => $n, 'compare' => 'LIKE' ), array( 'key' => '_rk_layout_published', 'value' => $n, 'compare' => 'LIKE' ) ),
		) );
		foreach ( is_array( $found ) ? $found : array() as $f ) { $ids[ (int) $f ] = true; }
	}
	return array_keys( $ids );
}

/** The seed layout, for a "reset to starter" button and for tests. */
function rk_builder_handle_template_starter( $req ) {
	$post = rk_builder_tpl_post( $req['id'] );
	if ( ! $post ) { return rk_builder_not_found( 'Template not found.' ); }
	$m   = rk_builder_tpl_meta( $post->ID );
	$def = rk_builder_dyn_type( $m['postType'] );
	if ( ! $def ) { return rk_builder_not_found( 'The content type of this template no longer exists.' ); }
	return rk_builder_no_store( array( 'layout' => rk_builder_tpl_starter( $m['kind'], $def, $m['taxonomy'] ) ) );
}

function rk_builder_register_template_routes( $ns ) {
	$id = array( 'id' => array( 'type' => 'integer', 'required' => true ) );
	$admin = 'rk_builder_perm_theme_write';
	register_rest_route( $ns, '/builder/templates', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_list_templates', 'permission_callback' => 'rk_builder_perm_list_pages' ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_create_template', 'permission_callback' => $admin ),
	) );
	register_rest_route( $ns, '/builder/templates/(?P<id>\d+)/update', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_update_template', 'permission_callback' => $admin, 'args' => $id ) );
	register_rest_route( $ns, '/builder/templates/(?P<id>\d+)/delete', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_delete_template', 'permission_callback' => $admin, 'args' => $id ) );
	register_rest_route( $ns, '/builder/templates/(?P<id>\d+)/starter', array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_template_starter', 'permission_callback' => 'rk_builder_perm_edit_page', 'args' => $id ) );
	register_rest_route( $ns, '/builder/dyn/render', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_dyn_render', 'permission_callback' => 'rk_builder_perm_list_pages' ) );
}

/* ------------------------------------------------------------------ *
 * Front end
 * ------------------------------------------------------------------ */

/** The dynamic request (template + queried object) once template_include decided it, else null. */
function rk_builder_dyn_request( $set = null ) {
	static $req = null;
	if ( 'reset' === $set ) { $req = null; return null; }
	if ( is_array( $set ) ) { $req = $set; }
	return $req;
}

/** Which template (if any) should draw the current request? */
function rk_builder_dyn_resolve_request() {
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! rk_builder_setting( 'enabled', true ) ) { return null; }
	if ( function_exists( 'is_singular' ) && is_singular() ) {
		$post = get_queried_object();
		if ( ! $post || ! is_object( $post ) || ! isset( $post->post_type ) || 'page' === $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) { return null; }
		if ( null === rk_builder_dyn_type( $post->post_type ) ) { return null; }
		$tpl = rk_builder_tpl_find( 'single', $post->post_type );
		return $tpl ? array( 'kind' => 'single', 'tpl' => $tpl, 'post' => $post, 'type' => $post->post_type ) : null;
	}
	$type = '';
	$tax  = '';
	if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
		$o    = get_queried_object();
		$type = $o && isset( $o->name ) ? (string) $o->name : '';
	} elseif ( function_exists( 'is_tax' ) && ( is_tax() || is_category() || is_tag() ) ) {
		$o = get_queried_object();
		if ( $o && isset( $o->taxonomy ) ) {
			$tax = (string) $o->taxonomy;
			$tx  = get_taxonomy( $tax );
			$type = $tx && ! empty( $tx->object_type ) ? (string) $tx->object_type[0] : '';
		}
	} elseif ( function_exists( 'is_home' ) && is_home() && ! ( function_exists( 'is_front_page' ) && is_front_page() ) ) {
		$type = 'post';
	}
	if ( '' === $type || null === rk_builder_dyn_type( $type ) ) { return null; }
	$tpl = '' !== $tax ? rk_builder_tpl_find( 'archive', $type, $tax ) : null;
	if ( ! $tpl ) { $tpl = rk_builder_tpl_find( 'archive', $type ); }
	return $tpl ? array( 'kind' => 'archive', 'tpl' => $tpl, 'post' => null, 'type' => $type, 'taxonomy' => $tax, 'term' => '' !== $tax ? get_queried_object() : null ) : null;
}

function rk_builder_dyn_template_include( $template ) {
	$req = rk_builder_dyn_resolve_request();
	if ( ! $req ) { return $template; }
	rk_builder_dyn_request( $req );
	if ( function_exists( 'add_theme_support' ) ) { add_theme_support( 'title-tag' ); }
	return RK_BUILDER_DIR . 'templates/dynamic-layout.php';
}

function rk_builder_dyn_enqueue() {
	$req = rk_builder_dyn_request();
	if ( ! $req ) { return; }
	rk_builder_enqueue_public_assets( rk_builder_get_theme() );
	if ( function_exists( 'rk_builder_viz_enqueue' ) ) { rk_builder_viz_enqueue( $req['tpl']['layout'] ); }
}

function rk_builder_register_template_hooks() {
	add_action( 'init', 'rk_builder_register_template_type' );
	add_filter( 'template_include', 'rk_builder_dyn_template_include', 98 );
	add_action( 'wp_enqueue_scripts', 'rk_builder_dyn_enqueue' );
}
rk_builder_register_template_hooks();
