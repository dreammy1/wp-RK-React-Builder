<?php
/**
 * Content types: custom post types, taxonomies and meta fields defined from the dashboard, plus the entries
 * (posts) of any of them.
 *
 *   - Definitions live in the option `rk_builder_types`: a list of
 *       { slug, singular, plural, icon, supports[], public, hasArchive, rewrite, taxonomies[], fields[] }.
 *     Post, Services and Portfolio (`builtin`) are never re-registered; they only carry fields.
 *   - A field is { key, label, type, help, required, default, options[], min, max, subfields[] } with type one of
 *     text, textarea, number, email, url, date, color, select, toggle, image, gallery, repeater.
 *   - Values are post meta `rk_f_<key>`: scalars as strings, media as attachment IDs, gallery and repeater as JSON.
 *   - Entries are edited through /builder/entries/{type} and /builder/entry/{id}, so nobody needs wp-admin.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const RK_BUILDER_TYPES_OPTION = 'rk_builder_types';
const RK_BUILDER_MAX_TYPES    = 20;
const RK_BUILDER_MAX_FIELDS   = 40;
const RK_BUILDER_MAX_SUBFIELDS = 12;
const RK_BUILDER_MAX_OPTIONS  = 40;
const RK_BUILDER_MAX_ROWS     = 50;
const RK_BUILDER_MAX_GALLERY  = 60;

/* ------------------------------------------------------------------ *
 * Vocabulary
 * ------------------------------------------------------------------ */

/** Post types that already exist: they can get fields but are never registered or removed here. */
function rk_builder_dyn_builtin_slugs() { return array( 'post', 'service', 'portfolio' ); }

function rk_builder_dyn_field_types() {
	return array( 'text', 'textarea', 'number', 'email', 'url', 'date', 'color', 'select', 'toggle', 'image', 'gallery', 'repeater' );
}

function rk_builder_dyn_reserved_types() {
	return array( 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'rk_reusable', 'rk_template', 'action', 'author', 'order', 'theme', 'type', 'any', 'current' );
}

function rk_builder_dyn_reserved_taxonomies() {
	return array( 'category', 'post_tag', 'link_category', 'post_format', 'nav_menu', 'service_cat', 'portfolio_cat', 'term', 'type', 'name', 'author', 'order', 'theme' );
}

function rk_builder_dyn_supports_all() { return array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ); }

/* ------------------------------------------------------------------ *
 * Definitions: read
 * ------------------------------------------------------------------ */

/** The saved definitions (already sanitised on the way in; re-checked here so a bad option can never break a request). */
function rk_builder_types() {
	$raw = get_option( RK_BUILDER_TYPES_OPTION, array() );
	if ( ! is_array( $raw ) ) { return array(); }
	$out = array();
	foreach ( $raw as $t ) {
		if ( ! is_array( $t ) ) { continue; }
		$issues = array();
		$clean  = rk_builder_dyn_clean_type( $t, '', $issues );
		if ( null !== $clean && ! $issues ) { $out[ $clean['slug'] ] = $clean; }
	}
	return $out;
}

/** One definition by slug, or null. Built-in post types without saved fields get an empty definition. */
function rk_builder_dyn_type( $slug ) {
	if ( ! is_string( $slug ) ) { return null; }
	$all = rk_builder_types();
	if ( isset( $all[ $slug ] ) ) { return $all[ $slug ]; }
	if ( in_array( $slug, rk_builder_dyn_builtin_slugs(), true ) && post_type_exists( $slug ) ) { return rk_builder_dyn_builtin_def( $slug ); }
	return null;
}

function rk_builder_dyn_builtin_def( $slug ) {
	$obj = get_post_type_object( $slug );
	$l   = $obj && isset( $obj->labels ) ? (array) $obj->labels : array();
	return array(
		'slug' => $slug, 'singular' => isset( $l['singular_name'] ) ? (string) $l['singular_name'] : ucfirst( $slug ), 'plural' => isset( $l['name'] ) ? (string) $l['name'] : ucfirst( $slug ) . 's',
		'icon' => '', 'supports' => rk_builder_dyn_supports_all(), 'public' => true, 'hasArchive' => true, 'rewrite' => '', 'taxonomies' => array(), 'fields' => array(), 'builtin' => true,
		'schema' => 'post' === $slug ? 'Article' : 'WebPage', 'archiveTitle' => '', 'archiveDescription' => '',
	);
}

/** Every type the dashboard can manage: the built-ins that exist on this site, then the custom ones. */
function rk_builder_dyn_all_types() {
	$saved = rk_builder_types();
	$out   = array();
	foreach ( rk_builder_dyn_builtin_slugs() as $slug ) {
		if ( ! post_type_exists( $slug ) ) { continue; }
		$out[ $slug ] = isset( $saved[ $slug ] ) ? $saved[ $slug ] : rk_builder_dyn_builtin_def( $slug );
	}
	foreach ( $saved as $slug => $t ) {
		if ( ! isset( $out[ $slug ] ) ) { $out[ $slug ] = $t; }
	}
	return $out;
}

function rk_builder_dyn_fields( $slug ) {
	$t = rk_builder_dyn_type( $slug );
	return $t ? $t['fields'] : array();
}

function rk_builder_dyn_field( $slug, $key ) {
	foreach ( rk_builder_dyn_fields( $slug ) as $f ) {
		if ( $f['key'] === $key ) { return $f; }
	}
	return null;
}

/** Taxonomy slugs that belong to a post type (own ones first), for the editor and the loop filters. */
function rk_builder_dyn_taxonomies( $slug ) {
	$names = get_object_taxonomies( $slug, 'objects' );
	$out   = array();
	foreach ( is_array( $names ) ? $names : array() as $name => $tax ) {
		if ( empty( $tax->public ) ) { continue; }
		$out[] = array( 'slug' => (string) $name, 'name' => isset( $tax->labels->name ) ? (string) $tax->labels->name : (string) $name, 'hierarchical' => ! empty( $tax->hierarchical ) );
	}
	return $out;
}

/* ------------------------------------------------------------------ *
 * Definitions: sanitise
 * ------------------------------------------------------------------ */

function rk_builder_dyn_clean_str( $v, $max ) {
	if ( ! is_string( $v ) ) { return ''; }
	$v = sanitize_text_field( $v );
	return rk_builder_strlen( $v ) > $max ? '' : $v;
}

/** One field definition, or null (issues describe what is wrong). */
function rk_builder_dyn_clean_field( $f, $path, array &$issues, $depth = 0 ) {
	if ( ! is_array( $f ) ) { rk_builder_add_issue( $issues, $path, 'Expected object' ); return null; }
	$key = isset( $f['key'] ) && is_string( $f['key'] ) ? $f['key'] : '';
	if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}\z/', $key ) ) { rk_builder_add_issue( $issues, $path . '.key', 'Use a lowercase key: letters, numbers and underscores, starting with a letter' ); return null; }
	$label = rk_builder_dyn_clean_str( isset( $f['label'] ) ? $f['label'] : '', 60 );
	if ( '' === $label ) { rk_builder_add_issue( $issues, $path . '.label', 'Give the field a label (up to 60 characters)' ); return null; }
	$type = isset( $f['type'] ) && is_string( $f['type'] ) ? $f['type'] : '';
	$allowed = rk_builder_dyn_field_types();
	if ( $depth > 0 ) { $allowed = array_values( array_diff( $allowed, array( 'repeater', 'gallery' ) ) ); }
	if ( ! in_array( $type, $allowed, true ) ) { rk_builder_add_issue( $issues, $path . '.type', 'Unknown or unsupported field type' ); return null; }
	$out = array(
		'key' => $key, 'label' => $label, 'type' => $type,
		'help' => rk_builder_dyn_clean_str( isset( $f['help'] ) ? $f['help'] : '', 160 ),
		'required' => ! empty( $f['required'] ),
		'default' => rk_builder_dyn_clean_str( isset( $f['default'] ) ? $f['default'] : '', 200 ),
		'options' => array(), 'min' => null, 'max' => null, 'subfields' => array(),
	);
	if ( 'select' === $type ) {
		$opts = isset( $f['options'] ) && is_array( $f['options'] ) ? array_values( $f['options'] ) : array();
		if ( count( $opts ) > RK_BUILDER_MAX_OPTIONS ) { rk_builder_add_issue( $issues, $path . '.options', 'At most ' . RK_BUILDER_MAX_OPTIONS . ' choices' ); return null; }
		$seen = array();
		foreach ( $opts as $i => $o ) {
			$value = is_array( $o ) && isset( $o['value'] ) ? rk_builder_dyn_clean_str( (string) $o['value'], 60 ) : '';
			$lab   = is_array( $o ) && isset( $o['label'] ) ? rk_builder_dyn_clean_str( (string) $o['label'], 60 ) : $value;
			if ( '' === $value ) { continue; }
			if ( isset( $seen[ $value ] ) ) { rk_builder_add_issue( $issues, $path . '.options.' . $i, 'Duplicate choice' ); continue; }
			$seen[ $value ]  = true;
			$out['options'][] = array( 'value' => $value, 'label' => '' !== $lab ? $lab : $value );
		}
		if ( ! $out['options'] ) { rk_builder_add_issue( $issues, $path . '.options', 'Add at least one choice' ); return null; }
	}
	if ( 'number' === $type ) {
		foreach ( array( 'min', 'max' ) as $b ) {
			if ( isset( $f[ $b ] ) && '' !== $f[ $b ] && is_numeric( $f[ $b ] ) ) { $out[ $b ] = $f[ $b ] + 0; }
		}
		if ( null !== $out['min'] && null !== $out['max'] && $out['min'] > $out['max'] ) { rk_builder_add_issue( $issues, $path . '.min', 'Minimum is above the maximum' ); return null; }
	}
	if ( 'repeater' === $type ) {
		$subs = isset( $f['subfields'] ) && is_array( $f['subfields'] ) ? array_values( $f['subfields'] ) : array();
		if ( ! $subs || count( $subs ) > RK_BUILDER_MAX_SUBFIELDS ) { rk_builder_add_issue( $issues, $path . '.subfields', 'A repeater needs 1 to ' . RK_BUILDER_MAX_SUBFIELDS . ' sub-fields' ); return null; }
		$keys = array();
		foreach ( $subs as $i => $sf ) {
			$c = rk_builder_dyn_clean_field( $sf, $path . '.subfields.' . $i, $issues, 1 );
			if ( null === $c ) { continue; }
			if ( isset( $keys[ $c['key'] ] ) ) { rk_builder_add_issue( $issues, $path . '.subfields.' . $i . '.key', 'Duplicate key' ); continue; }
			$keys[ $c['key'] ]  = true;
			$out['subfields'][] = $c;
		}
		if ( ! $out['subfields'] ) { return null; }
	}
	return $out;
}

/** One type definition, or null. */
function rk_builder_dyn_clean_type( $t, $path, array &$issues ) {
	$p    = '' === $path ? '' : $path . '.';
	$slug = isset( $t['slug'] ) && is_string( $t['slug'] ) ? $t['slug'] : '';
	$builtin = in_array( $slug, rk_builder_dyn_builtin_slugs(), true );
	if ( ! $builtin ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,19}\z/', $slug ) ) { rk_builder_add_issue( $issues, $p . 'slug', 'Use 2 to 20 lowercase letters, numbers or underscores, starting with a letter' ); return null; }
		if ( in_array( $slug, rk_builder_dyn_reserved_types(), true ) ) { rk_builder_add_issue( $issues, $p . 'slug', '"' . $slug . '" is reserved by WordPress' ); return null; }
	}
	$singular = rk_builder_dyn_clean_str( isset( $t['singular'] ) ? $t['singular'] : '', 40 );
	$plural   = rk_builder_dyn_clean_str( isset( $t['plural'] ) ? $t['plural'] : '', 40 );
	if ( ! $builtin && ( '' === $singular || '' === $plural ) ) { rk_builder_add_issue( $issues, $p . 'singular', 'Give the type a singular and a plural name' ); return null; }
	$supports = array();
	foreach ( isset( $t['supports'] ) && is_array( $t['supports'] ) ? $t['supports'] : rk_builder_dyn_supports_all() as $s ) {
		if ( is_string( $s ) && in_array( $s, rk_builder_dyn_supports_all(), true ) && ! in_array( $s, $supports, true ) ) { $supports[] = $s; }
	}
	$rewrite = isset( $t['rewrite'] ) && is_string( $t['rewrite'] ) ? strtolower( trim( $t['rewrite'], "/ \t" ) ) : '';
	if ( '' !== $rewrite && 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}\z/', $rewrite ) ) { rk_builder_add_issue( $issues, $p . 'rewrite', 'The address can use lowercase letters, numbers and dashes' ); return null; }
	$icon = isset( $t['icon'] ) && is_string( $t['icon'] ) && 1 === preg_match( '/^dashicons-[a-z0-9-]{1,40}\z/', $t['icon'] ) ? $t['icon'] : '';
	$out = array(
		'slug' => $slug, 'singular' => $singular, 'plural' => $plural, 'icon' => $icon, 'supports' => $supports,
		'public' => ! array_key_exists( 'public', $t ) || ! empty( $t['public'] ),
		'hasArchive' => ! array_key_exists( 'hasArchive', $t ) || ! empty( $t['hasArchive'] ),
		'rewrite' => $rewrite, 'taxonomies' => array(), 'fields' => array(), 'builtin' => $builtin,
		'schema' => isset( $t['schema'] ) && is_string( $t['schema'] ) && in_array( $t['schema'], array( 'WebPage', 'Article', 'Service' ), true ) ? $t['schema'] : 'WebPage',
		'archiveTitle' => rk_builder_dyn_clean_str( isset( $t['archiveTitle'] ) ? $t['archiveTitle'] : '', 70 ),
		'archiveDescription' => rk_builder_dyn_clean_str( isset( $t['archiveDescription'] ) ? $t['archiveDescription'] : '', 300 ),
	);
	if ( ! $builtin ) {
		$taxes = isset( $t['taxonomies'] ) && is_array( $t['taxonomies'] ) ? array_values( $t['taxonomies'] ) : array();
		if ( count( $taxes ) > 6 ) { rk_builder_add_issue( $issues, $p . 'taxonomies', 'At most 6 taxonomies per type' ); return null; }
		$seen = array();
		foreach ( $taxes as $i => $x ) {
			$ts = is_array( $x ) && isset( $x['slug'] ) && is_string( $x['slug'] ) ? $x['slug'] : '';
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,31}\z/', $ts ) || in_array( $ts, rk_builder_dyn_reserved_taxonomies(), true ) || isset( $seen[ $ts ] ) ) {
				rk_builder_add_issue( $issues, $p . 'taxonomies.' . $i . '.slug', 'Use a unique, lowercase taxonomy key (not a WordPress reserved word)' );
				continue;
			}
			$tn = rk_builder_dyn_clean_str( isset( $x['plural'] ) ? $x['plural'] : '', 40 );
			$to = rk_builder_dyn_clean_str( isset( $x['singular'] ) ? $x['singular'] : '', 40 );
			if ( '' === $tn || '' === $to ) { rk_builder_add_issue( $issues, $p . 'taxonomies.' . $i . '.plural', 'Give the taxonomy a singular and a plural name' ); continue; }
			$seen[ $ts ]        = true;
			$out['taxonomies'][] = array( 'slug' => $ts, 'singular' => $to, 'plural' => $tn, 'hierarchical' => ! isset( $x['hierarchical'] ) || ! empty( $x['hierarchical'] ) );
		}
	}
	$fields = isset( $t['fields'] ) && is_array( $t['fields'] ) ? array_values( $t['fields'] ) : array();
	if ( count( $fields ) > RK_BUILDER_MAX_FIELDS ) { rk_builder_add_issue( $issues, $p . 'fields', 'At most ' . RK_BUILDER_MAX_FIELDS . ' fields per type' ); return null; }
	$keys = array();
	foreach ( $fields as $i => $f ) {
		$c = rk_builder_dyn_clean_field( $f, $p . 'fields.' . $i, $issues );
		if ( null === $c ) { continue; }
		if ( isset( $keys[ $c['key'] ] ) ) { rk_builder_add_issue( $issues, $p . 'fields.' . $i . '.key', 'Duplicate key' ); continue; }
		$keys[ $c['key'] ] = true;
		$out['fields'][]   = $c;
	}
	return $out;
}

/** Validate the whole list; returns array( list|null, issues ). */
function rk_builder_dyn_clean_types( $list ) {
	$issues = array();
	if ( ! is_array( $list ) || ( array() !== $list && ! rk_builder_is_list( $list ) ) ) { return array( null, array( array( 'path' => 'types', 'message' => 'Expected a list' ) ) ); }
	if ( count( $list ) > RK_BUILDER_MAX_TYPES + 3 ) { return array( null, array( array( 'path' => 'types', 'message' => 'Too many content types' ) ) ); }
	$out  = array();
	$seen = array();
	foreach ( $list as $i => $t ) {
		if ( ! is_array( $t ) ) { rk_builder_add_issue( $issues, 'types.' . $i, 'Expected object' ); continue; }
		$c = rk_builder_dyn_clean_type( $t, 'types.' . $i, $issues );
		if ( null === $c ) { continue; }
		if ( isset( $seen[ $c['slug'] ] ) ) { rk_builder_add_issue( $issues, 'types.' . $i . '.slug', 'Duplicate type' ); continue; }
		$seen[ $c['slug'] ] = true;
		$out[]              = $c;
	}
	// Taxonomy keys are global in WordPress: two types cannot share one.
	$tax = array();
	foreach ( $out as $t ) {
		foreach ( $t['taxonomies'] as $x ) {
			if ( isset( $tax[ $x['slug'] ] ) ) { rk_builder_add_issue( $issues, 'types', 'The taxonomy key "' . $x['slug'] . '" is used twice' ); }
			$tax[ $x['slug'] ] = true;
		}
	}
	return array( $issues ? null : $out, $issues );
}

/* ------------------------------------------------------------------ *
 * Registration
 * ------------------------------------------------------------------ */

function rk_builder_dyn_register_types() {
	foreach ( rk_builder_types() as $t ) {
		if ( $t['builtin'] || post_type_exists( $t['slug'] ) ) { continue; }
		$base = '' !== $t['rewrite'] ? $t['rewrite'] : $t['slug'];
		register_post_type( $t['slug'], array(
			'labels'       => array(
				'name' => $t['plural'], 'singular_name' => $t['singular'], 'add_new_item' => 'Add ' . $t['singular'], 'edit_item' => 'Edit ' . $t['singular'],
				'search_items' => 'Search ' . $t['plural'], 'all_items' => 'All ' . $t['plural'],
			),
			'public'       => $t['public'],
			'show_ui'      => true,
			'show_in_rest' => true,
			'rest_base'    => $t['slug'],
			'has_archive'  => $t['public'] && $t['hasArchive'] ? $base : false,
			'rewrite'      => $t['public'] ? array( 'slug' => $base, 'with_front' => false ) : false,
			'menu_icon'    => '' !== $t['icon'] ? $t['icon'] : 'dashicons-database',
			'supports'     => $t['supports'] ? $t['supports'] : array( 'title' ),
		) );
		foreach ( $t['taxonomies'] as $x ) {
			register_taxonomy( $x['slug'], $t['slug'], array(
				'labels'       => array( 'name' => $x['plural'], 'singular_name' => $x['singular'] ),
				'public'       => $t['public'],
				'show_in_rest' => true,
				'hierarchical' => $x['hierarchical'],
				'rewrite'      => $t['public'] ? array( 'slug' => $x['slug'], 'with_front' => false ) : false,
			) );
		}
	}
	// Routes of a type that was just added or renamed are only known after a flush.
	if ( get_option( 'rk_builder_types_flush' ) ) {
		delete_option( 'rk_builder_types_flush' );
		flush_rewrite_rules( false );
	}
}

/** Editing a managed entry changes what the grids and templates show: purge like any other content change. */
function rk_builder_dyn_on_save_post( $post_id, $post = null, $update = true ) {
	if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	$post = is_object( $post ) ? $post : get_post( $post_id );
	if ( $post && null !== rk_builder_dyn_type( $post->post_type ) && function_exists( 'rk_builder_content_changed' ) ) { rk_builder_content_changed( 'content' ); }
}

/* ------------------------------------------------------------------ *
 * Field values
 * ------------------------------------------------------------------ */

function rk_builder_dyn_meta_key( $key ) { return 'rk_f_' . $key; }

/** A media item `{id,url,alt,title,width,height,srcset?}`; for a file `{id,url,title,name}`. Null when the attachment is gone. */
function rk_builder_dyn_media( $id, $as_file = false ) {
	$id = (int) $id;
	if ( $id <= 0 ) { return null; }
	if ( $as_file ) {
		$att = get_post( $id );
		$url = $att ? wp_get_attachment_url( $id ) : '';
		if ( ! $att || 'attachment' !== $att->post_type || ! $url ) { return null; }
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return array( 'id' => $id, 'url' => (string) $url, 'title' => rk_builder_plain( $att->post_title ), 'name' => basename( $path ) );
	}
	return rk_builder_media_item( $id );
}

/** The raw stored value of a field. */
function rk_builder_dyn_raw( $post_id, array $f ) {
	return get_post_meta( (int) $post_id, rk_builder_dyn_meta_key( $f['key'] ), true );
}

/** Stored value -> what the API/renderer works with (typed, media resolved). */
function rk_builder_dyn_present( array $f, $raw ) {
	switch ( $f['type'] ) {
		case 'number':
			return is_numeric( $raw ) ? $raw + 0 : null;
		case 'toggle':
			return '1' === (string) $raw || true === $raw;
		case 'image':
			return rk_builder_dyn_media( is_numeric( $raw ) ? (int) $raw : 0 );
		case 'file':
			return rk_builder_dyn_media( is_numeric( $raw ) ? (int) $raw : 0, true );
		case 'gallery':
			$ids = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			$out = array();
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				$m = rk_builder_dyn_media( is_numeric( $id ) ? (int) $id : 0 );
				if ( $m ) { $out[] = $m; }
			}
			return $out;
		case 'repeater':
			$rows = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			$out  = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$r = array();
				foreach ( $f['subfields'] as $sf ) { $r[ $sf['key'] ] = rk_builder_dyn_present( $sf, isset( $row[ $sf['key'] ] ) ? $row[ $sf['key'] ] : '' ); }
				$out[] = $r;
			}
			return $out;
	}
	return is_string( $raw ) ? $raw : '';
}

/** Is a presented value empty (so the renderer shows the fallback / skips it)? */
function rk_builder_dyn_is_empty( array $f, $value ) {
	if ( 'toggle' === $f['type'] ) { return false; }
	return null === $value || '' === $value || array() === $value;
}

function rk_builder_dyn_id_of( $v ) {
	if ( is_array( $v ) && isset( $v['id'] ) ) { $v = $v['id']; }
	return is_numeric( $v ) ? (int) $v : 0;
}

/**
 * Input -> storable value. Returns array( value, error|null ); value is a string (scalars), or an array
 * (gallery IDs, repeater rows) that the caller JSON-encodes.
 */
function rk_builder_dyn_clean_value( array $f, $v ) {
	$empty = ( null === $v || '' === $v || array() === $v );
	switch ( $f['type'] ) {
		case 'text':
			return array( $empty ? '' : sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ), null );
		case 'textarea':
			return array( $empty ? '' : sanitize_textarea_field( is_scalar( $v ) ? (string) $v : '' ), null );
		case 'number':
			if ( $empty ) { return array( '', null ); }
			if ( ! is_numeric( $v ) ) { return array( '', 'Enter a number' ); }
			if ( null !== $f['min'] && $v < $f['min'] ) { return array( '', 'Must be at least ' . $f['min'] ); }
			if ( null !== $f['max'] && $v > $f['max'] ) { return array( '', 'Must be at most ' . $f['max'] ); }
			return array( (string) ( $v + 0 ), null );
		case 'email':
			if ( $empty ) { return array( '', null ); }
			$e = sanitize_email( is_string( $v ) ? $v : '' );
			return '' !== $e && is_email( $e ) ? array( $e, null ) : array( '', 'Enter a valid email address' );
		case 'url':
			if ( $empty ) { return array( '', null ); }
			$u = is_string( $v ) ? trim( $v ) : '';
			return '' !== $u && rk_builder_is_safe_link( $u ) && strlen( $u ) <= 500 ? array( $u, null ) : array( '', 'Enter a valid link (https://, mailto: or tel:)' );
		case 'date':
			if ( $empty ) { return array( '', null ); }
			return is_string( $v ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $v ) ? array( $v, null ) : array( '', 'Use the date format YYYY-MM-DD' );
		case 'color':
			if ( $empty ) { return array( '', null ); }
			return is_string( $v ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}\z/', $v ) ? array( strtolower( $v ), null ) : array( '', 'Use a hex colour such as #8b5a2b' );
		case 'select':
			if ( $empty ) { return array( '', null ); }
			foreach ( $f['options'] as $o ) {
				if ( (string) $v === $o['value'] ) { return array( $o['value'], null ); }
			}
			return array( '', 'Choose one of the listed options' );
		case 'toggle':
			return array( ! empty( $v ) && 'false' !== $v && '0' !== $v ? '1' : '0', null );
		case 'image':
		case 'file':
			if ( $empty ) { return array( '', null ); }
			$id = rk_builder_dyn_id_of( $v );
			$att = $id > 0 ? get_post( $id ) : null;
			if ( ! $att || 'attachment' !== $att->post_type ) { return array( '', 'That file is not in the media library' ); }
			if ( 'image' === $f['type'] && function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $id ) ) { return array( '', 'Choose an image' ); }
			return array( (string) $id, null );
		case 'gallery':
			if ( $empty ) { return array( array(), null ); }
			if ( ! is_array( $v ) || count( $v ) > RK_BUILDER_MAX_GALLERY ) { return array( array(), 'A gallery holds up to ' . RK_BUILDER_MAX_GALLERY . ' images' ); }
			$ids = array();
			foreach ( $v as $item ) {
				$id  = rk_builder_dyn_id_of( $item );
				$att = $id > 0 ? get_post( $id ) : null;
				if ( $att && 'attachment' === $att->post_type && ! in_array( $id, $ids, true ) ) { $ids[] = $id; }
			}
			return array( $ids, null );
		case 'repeater':
			if ( $empty ) { return array( array(), null ); }
			if ( ! is_array( $v ) || ! rk_builder_is_list( $v ) || count( $v ) > RK_BUILDER_MAX_ROWS ) { return array( array(), 'A repeater holds up to ' . RK_BUILDER_MAX_ROWS . ' rows' ); }
			$rows = array();
			foreach ( $v as $ri => $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$r    = array();
				$any  = false;
				foreach ( $f['subfields'] as $sf ) {
					list( $cv, $err ) = rk_builder_dyn_clean_value( $sf, isset( $row[ $sf['key'] ] ) ? $row[ $sf['key'] ] : '' );
					if ( null !== $err ) { return array( array(), 'Row ' . ( $ri + 1 ) . ', ' . $sf['label'] . ': ' . $err ); }
					if ( ! is_array( $cv ) && '' !== $cv && '0' !== $cv ) { $any = true; }
					$r[ $sf['key'] ] = $cv;
				}
				if ( $any ) { $rows[] = $r; }
			}
			return array( $rows, null );
	}
	return array( '', 'Unknown field type' );
}

function rk_builder_dyn_store( $post_id, array $f, $clean ) {
	$key = rk_builder_dyn_meta_key( $f['key'] );
	if ( is_array( $clean ) ) {
		if ( ! $clean ) { delete_post_meta( $post_id, $key ); return; }
		update_post_meta( $post_id, $key, wp_slash( wp_json_encode( $clean ) ) );
		return;
	}
	if ( '' === $clean ) { delete_post_meta( $post_id, $key ); return; }
	update_post_meta( $post_id, $key, wp_slash( $clean ) );
}

/* ------------------------------------------------------------------ *
 * Entries
 * ------------------------------------------------------------------ */

/** An empty PHP array would encode as JSON `[]`; these are maps, so send `{}`. */
function rk_builder_dyn_obj( array $a ) { return array() === $a ? new stdClass() : $a; }

/** Full entry for the dashboard editor. */
function rk_builder_dyn_entry( $post ) {
	$def    = rk_builder_dyn_type( $post->post_type );
	$fields = array();
	foreach ( $def ? $def['fields'] : array() as $f ) { $fields[ $f['key'] ] = rk_builder_dyn_present( $f, rk_builder_dyn_raw( $post->ID, $f ) ); }
	$terms = array();
	foreach ( rk_builder_dyn_taxonomies( $post->post_type ) as $tax ) {
		$names = wp_get_object_terms( $post->ID, $tax['slug'], array( 'fields' => 'names' ) );
		$terms[ $tax['slug'] ] = is_wp_error( $names ) ? array() : array_values( array_map( 'strval', (array) $names ) );
	}
	return array(
		'id' => (int) $post->ID, 'type' => (string) $post->post_type, 'title' => rk_builder_plain( get_the_title( $post ) ), 'slug' => (string) $post->post_name,
		'status' => (string) $post->post_status, 'excerpt' => (string) $post->post_excerpt, 'content' => (string) $post->post_content,
		'image' => rk_builder_dyn_thumb( $post->ID ), 'menuOrder' => (int) $post->menu_order, 'terms' => rk_builder_dyn_obj( $terms ), 'fields' => rk_builder_dyn_obj( $fields ),
		'link' => (string) get_permalink( $post->ID ), 'modified' => rk_builder_mysql_gmt_to_iso( $post->post_modified_gmt ),
		'seo' => rk_builder_dyn_seo_out( $post->ID ),
	);
}

/** The four search / sharing fields of an entry, always all present. */
function rk_builder_dyn_seo_out( $id ) {
	$s = rk_builder_seo_read( (int) $id );
	return array( 'title' => isset( $s['title'] ) ? $s['title'] : '', 'description' => isset( $s['description'] ) ? $s['description'] : '', 'image' => isset( $s['image'] ) ? $s['image'] : '', 'noindex' => ! empty( $s['noindex'] ) );
}

function rk_builder_dyn_thumb( $post_id ) {
	$id = (int) get_post_thumbnail_id( $post_id );
	return $id > 0 ? rk_builder_media_item( $id ) : null;
}

function rk_builder_dyn_row( $post ) {
	$thumb = rk_builder_dyn_thumb( $post->ID );
	return array(
		'id' => (int) $post->ID, 'title' => rk_builder_plain( get_the_title( $post ) ), 'slug' => (string) $post->post_name, 'status' => (string) $post->post_status,
		'modified' => rk_builder_mysql_gmt_to_iso( $post->post_modified_gmt ), 'link' => (string) get_permalink( $post->ID ), 'image' => $thumb ? $thumb['url'] : null,
	);
}

function rk_builder_perm_entries( $req ) { return rk_builder_authorize_caps( array( 'edit_posts' ) ); }

function rk_builder_perm_entry( $req ) {
	$gate = rk_builder_authorize_caps( array( 'edit_posts' ) );
	if ( true !== $gate ) { return $gate; }
	$post = rk_builder_dyn_get_entry_post( isset( $req['id'] ) ? $req['id'] : 0 );
	if ( ! $post ) { return rk_builder_not_found( 'Entry not found.' ); }
	return current_user_can( 'edit_post', $post->ID ) ? true : rk_builder_forbidden();
}

/** The post when it belongs to a managed type, else null. */
function rk_builder_dyn_get_entry_post( $id ) {
	$id   = (int) $id;
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || ! is_object( $post ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) { return null; }
	return null !== rk_builder_dyn_type( $post->post_type ) ? $post : null;
}

function rk_builder_handle_get_types( $req ) {
	$out = array();
	foreach ( rk_builder_dyn_all_types() as $t ) {
		$counts = wp_count_posts( $t['slug'] );
		$tax    = array();
		foreach ( rk_builder_dyn_taxonomies( $t['slug'] ) as $x ) {
			$terms = get_terms( array( 'taxonomy' => $x['slug'], 'hide_empty' => false, 'number' => 200 ) );
			$x['terms'] = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) { $x['terms'][] = array( 'slug' => (string) $term->slug, 'name' => (string) $term->name ); }
			$tax[] = $x;
		}
		$t['count']      = $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
		$t['taxonomyTerms'] = $tax;
		$out[] = $t;
	}
	return rk_builder_no_store( array( 'types' => $out, 'fieldTypes' => rk_builder_dyn_field_types() ) );
}

function rk_builder_handle_set_types( $req ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_types' );
	if ( is_wp_error( $body ) ) { return $body; }
	list( $list, $issues ) = rk_builder_dyn_clean_types( isset( $body['types'] ) ? $body['types'] : null );
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_types', $issues ); }
	$before = rk_builder_types();
	$keep   = array();
	foreach ( $list as $t ) { $keep[ $t['slug'] ] = true; }
	// Removing a type keeps its entries in the database; they just stop being reachable until it is added back.
	update_option( RK_BUILDER_TYPES_OPTION, $list, false );
	$changed = array_keys( $before ) !== array_keys( rk_builder_types() );
	foreach ( rk_builder_types() as $slug => $t ) {
		if ( ! isset( $before[ $slug ] ) || $before[ $slug ]['rewrite'] !== $t['rewrite'] || $before[ $slug ]['hasArchive'] !== $t['hasArchive'] || $before[ $slug ]['taxonomies'] !== $t['taxonomies'] ) { $changed = true; }
	}
	if ( $changed ) { update_option( 'rk_builder_types_flush', 1, false ); }
	if ( function_exists( 'rk_builder_content_changed' ) ) { rk_builder_content_changed( 'content' ); }
	return rk_builder_handle_get_types( $req );
}

function rk_builder_handle_list_entries( $req ) {
	$type = (string) $req['type'];
	if ( null === rk_builder_dyn_type( $type ) ) { return rk_builder_not_found( 'Unknown content type.' ); }
	$per_page = (int) $req->get_param( 'per_page' );
	$per_page = $per_page >= 1 ? min( 100, $per_page ) : 20;
	$status   = $req->get_param( 'status' );
	$args = array(
		'post_type' => $type, 'post_status' => in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? array( $status ) : array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => $per_page, 'paged' => max( 1, (int) $req->get_param( 'page' ) ), 'orderby' => 'modified', 'order' => 'DESC',
	);
	$search = $req->get_param( 'search' );
	if ( is_string( $search ) && '' !== trim( $search ) ) { $args['s'] = trim( $search ); }
	$q    = new WP_Query( $args );
	$rows = array();
	foreach ( $q->posts as $post ) {
		if ( current_user_can( 'edit_post', $post->ID ) ) { $rows[] = rk_builder_dyn_row( $post ); }
	}
	return rk_builder_no_store( array( 'items' => $rows, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages ) );
}

function rk_builder_handle_get_entry( $req ) {
	$post = rk_builder_dyn_get_entry_post( $req['id'] );
	if ( ! $post ) { return rk_builder_not_found( 'Entry not found.' ); }
	return rk_builder_no_store( array( 'entry' => rk_builder_dyn_entry( $post ) ) );
}

/** Validate the body and write it to $post_id (0 = create). Returns the post ID or a WP_Error. */
function rk_builder_dyn_save_entry( $req, $type, $post_id ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_entry' );
	if ( is_wp_error( $body ) ) { return $body; }
	$def = rk_builder_dyn_type( $type );
	if ( ! $def ) { return rk_builder_not_found( 'Unknown content type.' ); }
	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'title', 'slug', 'status', 'excerpt', 'content', 'image', 'menuOrder', 'terms', 'fields', 'seo' ), true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	$create = 0 === $post_id;
	$title  = isset( $body['title'] ) && is_string( $body['title'] ) ? trim( sanitize_text_field( $body['title'] ) ) : null;
	if ( $create && ( null === $title || '' === $title ) ) { rk_builder_add_issue( $issues, 'title', 'Give the entry a title' ); }
	if ( null !== $title && rk_builder_strlen( $title ) > 200 ) { rk_builder_add_issue( $issues, 'title', 'Title is too long' ); }
	$status = isset( $body['status'] ) && is_string( $body['status'] ) ? $body['status'] : null;
	if ( null !== $status && ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) { rk_builder_add_issue( $issues, 'status', 'Unknown status' ); }
	$values = array();
	$given  = isset( $body['fields'] ) && is_array( $body['fields'] ) ? $body['fields'] : array();
	foreach ( $def['fields'] as $f ) {
		if ( ! array_key_exists( $f['key'], $given ) ) {
			if ( $create && '' !== $f['default'] && ! in_array( $f['type'], array( 'image', 'file', 'gallery', 'repeater' ), true ) ) { $given[ $f['key'] ] = $f['default']; } else { continue; }
		}
		list( $clean, $err ) = rk_builder_dyn_clean_value( $f, $given[ $f['key'] ] );
		if ( null !== $err ) { rk_builder_add_issue( $issues, 'fields.' . $f['key'], $err ); continue; }
		$values[ $f['key'] ] = array( $f, $clean );
	}
	foreach ( $def['fields'] as $f ) {
		if ( empty( $f['required'] ) || 'toggle' === $f['type'] ) { continue; }
		$will = isset( $values[ $f['key'] ] ) ? $values[ $f['key'] ][1] : ( $create ? '' : rk_builder_dyn_raw( $post_id, $f ) );
		if ( '' === $will || array() === $will || null === $will ) {
			if ( $create || array_key_exists( $f['key'], $given ) ) { rk_builder_add_issue( $issues, 'fields.' . $f['key'], $f['label'] . ' is required' ); }
		}
	}
	$image = null;
	if ( array_key_exists( 'image', $body ) ) {
		$iid = rk_builder_dyn_id_of( $body['image'] );
		if ( $iid > 0 ) {
			$att = get_post( $iid );
			if ( ! $att || 'attachment' !== $att->post_type ) { rk_builder_add_issue( $issues, 'image', 'That image is not in the media library' ); } else { $image = $iid; }
		} else { $image = 0; }
	}
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_entry', $issues ); }

	$args = array( 'post_type' => $type );
	if ( null !== $title ) { $args['post_title'] = $title; }
	if ( isset( $body['slug'] ) && is_string( $body['slug'] ) ) { $args['post_name'] = sanitize_title( $body['slug'] ); }
	if ( isset( $body['excerpt'] ) && is_string( $body['excerpt'] ) ) { $args['post_excerpt'] = sanitize_textarea_field( $body['excerpt'] ); }
	if ( isset( $body['content'] ) && is_string( $body['content'] ) ) { $args['post_content'] = wp_kses_post( $body['content'] ); }
	if ( isset( $body['menuOrder'] ) && is_numeric( $body['menuOrder'] ) ) { $args['menu_order'] = max( -9999, min( 9999, (int) $body['menuOrder'] ) ); }
	if ( null === $status ) { $status = $create ? 'draft' : null; }
	if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) { $status = 'pending'; }
	if ( null !== $status ) { $args['post_status'] = $status; }
	if ( $create ) {
		$args['post_author'] = get_current_user_id();
		$id = wp_insert_post( wp_slash( $args ), true );
	} else {
		$args['ID'] = $post_id;
		$id = wp_update_post( wp_slash( $args ), true );
	}
	if ( is_wp_error( $id ) || ! $id ) { return rk_builder_error( 'rk_server_error', 'Could not save the entry.', 500 ); }
	$id = (int) $id;
	foreach ( $values as $pair ) { rk_builder_dyn_store( $id, $pair[0], $pair[1] ); }
	if ( null !== $image ) {
		if ( $image > 0 ) { set_post_thumbnail( $id, $image ); } else { delete_post_thumbnail( $id ); }
	}
	if ( isset( $body['seo'] ) && is_array( $body['seo'] ) ) {
		$seo = rk_builder_seo_clean( array_intersect_key( $body['seo'], array_flip( array( 'title', 'description', 'image', 'noindex' ) ) ) );
		$keep = rk_builder_seo_read( $id );
		foreach ( array( 'service', 'parent' ) as $k ) { if ( isset( $keep[ $k ] ) ) { $seo[ $k ] = $keep[ $k ]; } }
		rk_builder_seo_write( $id, $seo );
	}
	if ( isset( $body['terms'] ) && is_array( $body['terms'] ) ) {
		$allowed = array();
		foreach ( rk_builder_dyn_taxonomies( $type ) as $x ) { $allowed[ $x['slug'] ] = true; }
		foreach ( $body['terms'] as $tax => $names ) {
			if ( ! isset( $allowed[ $tax ] ) || ! is_array( $names ) ) { continue; }
			$clean = array();
			foreach ( array_slice( $names, 0, 50 ) as $n ) {
				$n = is_string( $n ) ? trim( sanitize_text_field( $n ) ) : '';
				if ( '' !== $n && rk_builder_strlen( $n ) <= 80 ) { $clean[] = $n; }
			}
			wp_set_object_terms( $id, $clean, $tax, false );
		}
	}
	return $id;
}

function rk_builder_handle_create_entry( $req ) {
	$id = rk_builder_dyn_save_entry( $req, (string) $req['type'], 0 );
	if ( is_wp_error( $id ) ) { return $id; }
	$r = rk_builder_no_store( array( 'entry' => rk_builder_dyn_entry( get_post( $id ) ) ) );
	if ( $r instanceof WP_REST_Response ) { $r->set_status( 201 ); }
	return $r;
}

function rk_builder_handle_update_entry( $req ) {
	$post = rk_builder_dyn_get_entry_post( $req['id'] );
	if ( ! $post ) { return rk_builder_not_found( 'Entry not found.' ); }
	$id = rk_builder_dyn_save_entry( $req, $post->post_type, (int) $post->ID );
	if ( is_wp_error( $id ) ) { return $id; }
	return rk_builder_no_store( array( 'entry' => rk_builder_dyn_entry( get_post( $id ) ) ) );
}

function rk_builder_handle_trash_entry( $req ) {
	$post = rk_builder_dyn_get_entry_post( $req['id'] );
	if ( ! $post ) { return rk_builder_not_found( 'Entry not found.' ); }
	if ( ! current_user_can( 'delete_post', $post->ID ) ) { return rk_builder_forbidden(); }
	wp_trash_post( $post->ID );
	return rk_builder_no_store( array( 'ok' => true ) );
}

function rk_builder_handle_duplicate_entry( $req ) {
	$post = rk_builder_dyn_get_entry_post( $req['id'] );
	if ( ! $post ) { return rk_builder_not_found( 'Entry not found.' ); }
	$id = wp_insert_post( wp_slash( array(
		'post_type' => $post->post_type, 'post_status' => 'draft', 'post_title' => $post->post_title . ' (copy)', 'post_content' => $post->post_content,
		'post_excerpt' => $post->post_excerpt, 'menu_order' => $post->menu_order, 'post_author' => get_current_user_id(),
	) ), true );
	if ( is_wp_error( $id ) || ! $id ) { return rk_builder_error( 'rk_server_error', 'Could not duplicate the entry.', 500 ); }
	foreach ( rk_builder_dyn_fields( $post->post_type ) as $f ) {
		$raw = rk_builder_dyn_raw( $post->ID, $f );
		if ( '' !== $raw && null !== $raw ) { update_post_meta( $id, rk_builder_dyn_meta_key( $f['key'] ), wp_slash( $raw ) ); }
	}
	$thumb = get_post_thumbnail_id( $post->ID );
	if ( $thumb ) { set_post_thumbnail( $id, (int) $thumb ); }
	foreach ( rk_builder_dyn_taxonomies( $post->post_type ) as $x ) {
		$names = wp_get_object_terms( $post->ID, $x['slug'], array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $names ) && $names ) { wp_set_object_terms( $id, $names, $x['slug'], false ); }
	}
	return rk_builder_no_store( array( 'entry' => rk_builder_dyn_entry( get_post( $id ) ) ) );
}

function rk_builder_register_type_routes( $ns ) {
	$id   = array( 'id' => array( 'type' => 'integer', 'required' => true ) );
	$type = array( 'type' => array( 'type' => 'string', 'required' => true ) );
	register_rest_route( $ns, '/builder/types', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_types', 'permission_callback' => 'rk_builder_perm_list_pages' ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_types', 'permission_callback' => 'rk_builder_perm_theme_write' ),
	) );
	register_rest_route( $ns, '/builder/entries/(?P<type>[a-z][a-z0-9_]{1,19})', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_list_entries', 'permission_callback' => 'rk_builder_perm_entries', 'args' => $type ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_create_entry', 'permission_callback' => 'rk_builder_perm_entries', 'args' => $type ),
	) );
	register_rest_route( $ns, '/builder/entry/(?P<id>\d+)', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_entry', 'permission_callback' => 'rk_builder_perm_entry', 'args' => $id ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_update_entry', 'permission_callback' => 'rk_builder_perm_entry', 'args' => $id ),
	) );
	register_rest_route( $ns, '/builder/entry/(?P<id>\d+)/trash', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_trash_entry', 'permission_callback' => 'rk_builder_perm_entry', 'args' => $id ) );
	register_rest_route( $ns, '/builder/entry/(?P<id>\d+)/duplicate', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_duplicate_entry', 'permission_callback' => 'rk_builder_perm_entry', 'args' => $id ) );
}

function rk_builder_register_type_hooks() {
	add_action( 'init', 'rk_builder_dyn_register_types', 20 );
	add_action( 'save_post', 'rk_builder_dyn_on_save_post', 10, 3 );
}
rk_builder_register_type_hooks();
