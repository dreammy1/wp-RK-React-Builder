<?php
/**
 * Install wizard helpers: "undo the last theme install" and "your business details" find-and-replace.
 *
 * Before a theme is installed we remember, for everything the install could touch:
 *   - the pages, templates, reusable blocks, posts and entries that already exist at the addresses the theme uses
 *     (title, status, text and the builder's own saved layouts / SEO / fields, exactly as they were),
 *   - which template was active, the theme / layout / redirect / business settings, the front page, the content types,
 *   - and afterwards: what the install created and what it hid.
 * Undo puts all of that back. Things the install created go to the Trash (never deleted outright); pictures it copied
 * stay in the Media library. One level only: the latest install.
 *
 * The record lives in a file in the (closed) kit folder, with a small summary in an option.
 *
 *   GET  /builder/themes         → { items, undo: { name, at, counts } | null }
 *   POST /builder/themes/undo    → puts the site back
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_MAX_UNDO_BYTES' ) ) { define( 'RK_BUILDER_MAX_UNDO_BYTES', 40 * 1024 * 1024 ); }

/* ------------------------------------------------------------------ *
 * Your business details: find and replace across a bundle
 * ------------------------------------------------------------------ */

/** Pairs of { find, with }: trimmed, at most 10, find at least 2 characters and different from its replacement. */
function rk_builder_replace_pairs_clean( $in ) {
	$out = array();
	foreach ( is_array( $in ) ? array_slice( $in, 0, 10 ) : array() as $p ) {
		if ( ! is_array( $p ) || ! isset( $p['find'], $p['with'] ) || ! is_string( $p['find'] ) || ! is_string( $p['with'] ) ) { continue; }
		$find = trim( $p['find'] );
		$with = trim( $p['with'] );
		if ( rk_builder_strlen( $find ) < 2 || rk_builder_strlen( $find ) > 200 || rk_builder_strlen( $with ) > 300 || $find === $with ) { continue; }
		$out[ $find ] = $with;
	}
	return $out;
}

/** Keys whose values are identifiers, never wording. */
function rk_builder_replace_skips_key( $k ) {
	if ( ! is_string( $k ) ) { return false; }
	return in_array( $k, array( 'slug', 'id', 'type', 'kind', 'postType', 'taxonomy', 'key', 'format', 'version', 'status', 'featured', 'refId', 'templateId', 'wasPublished', 'active' ), true )
		|| 1 === preg_match( '/(MediaId|Id)\z/', $k );
}

/** Replace in every string of $v that is wording (not a web address, path, anchor or identifier). */
function rk_builder_replace_in( $v, array $pairs, $key = '' ) {
	if ( rk_builder_replace_skips_key( $key ) ) { return $v; }
	if ( is_array( $v ) ) {
		foreach ( $v as $k => $item ) { $v[ $k ] = rk_builder_replace_in( $item, $pairs, $k ); }
		return $v;
	}
	if ( ! is_string( $v ) || '' === $v || 1 === preg_match( '#^(https?://|/|\#)#i', $v ) ) { return $v; }
	return strtr( $v, $pairs );
}

/** Replace in the parts of a bundle that carry wording. */
function rk_builder_replace_in_bundle( array $bundle, array $pairs ) {
	if ( ! $pairs ) { return $bundle; }
	foreach ( array( 'pages', 'reusables', 'templates', 'content', 'entries', 'theme', 'seo', 'site' ) as $k ) {
		if ( isset( $bundle[ $k ] ) ) { $bundle[ $k ] = rk_builder_replace_in( $bundle[ $k ], $pairs, $k ); }
	}
	return $bundle;
}

/** What the wizard offers to replace: the demo's own business details, as the kit carries them. */
function rk_builder_replace_suggestions( array $bundle ) {
	$org = isset( $bundle['seo']['organization'] ) && is_array( $bundle['seo']['organization'] ) ? $bundle['seo']['organization'] : array();
	$out = array();
	$add = function ( $label, $find ) use ( &$out ) {
		$find = is_string( $find ) ? trim( $find ) : '';
		if ( rk_builder_strlen( $find ) >= 2 && rk_builder_strlen( $find ) <= 200 ) { $out[] = array( 'label' => $label, 'find' => $find ); }
	};
	$add( 'Business name', isset( $org['name'] ) ? $org['name'] : ( isset( $bundle['site']['title'] ) ? $bundle['site']['title'] : '' ) );
	$add( 'Phone', isset( $org['telephone'] ) ? $org['telephone'] : '' );
	$add( 'Email', isset( $org['email'] ) ? $org['email'] : '' );
	$add( 'Street address', isset( $org['street'] ) ? $org['street'] : '' );
	$add( 'City', isset( $org['city'] ) ? $org['city'] : '' );
	return $out;
}

/* ------------------------------------------------------------------ *
 * Capture and restore
 * ------------------------------------------------------------------ */

function rk_builder_undo_meta_keys( $post_type ) {
	$keys = array_merge(
		array( '_rk_layout_draft', '_rk_layout_published', '_rk_revision', '_rk_published_revision', '_rk_published_at', '_rk_revisions', '_rk_reusable_block', '_rk_tpl_kind', '_rk_tpl_type', '_rk_tpl_tax', '_rk_tpl_per_page', '_rk_tpl_active', '_rk_tpl_slug', '_thumbnail_id' ),
		array_values( rk_builder_seo_meta_keys() )
	);
	if ( function_exists( 'rk_builder_dyn_type' ) && is_string( $post_type ) ) {
		$def = rk_builder_dyn_type( $post_type );
		foreach ( $def && isset( $def['fields'] ) ? $def['fields'] : array() as $f ) { $keys[] = rk_builder_dyn_meta_key( $f['key'] ); }
	}
	return array_values( array_unique( $keys ) );
}

/** One post as it is now: its fields and the meta this plugin cares about (absent keys are remembered as absent). */
function rk_builder_undo_capture_post( $id ) {
	$p = get_post( (int) $id );
	if ( ! $p ) { return null; }
	$meta    = array();
	$missing = array();
	foreach ( rk_builder_undo_meta_keys( $p->post_type ) as $k ) {
		if ( array() === get_post_meta( $p->ID, $k, false ) ) { $missing[] = $k; } else { $meta[ $k ] = get_post_meta( $p->ID, $k, true ); }
	}
	return array(
		'id'      => (int) $p->ID,
		'type'    => (string) $p->post_type,
		'fields'  => array( 'post_title' => (string) $p->post_title, 'post_name' => (string) $p->post_name, 'post_status' => (string) $p->post_status, 'post_content' => (string) $p->post_content, 'post_excerpt' => (string) $p->post_excerpt, 'menu_order' => (int) $p->menu_order ),
		'meta'    => $meta,
		'missing' => $missing,
	);
}

function rk_builder_undo_restore_post( array $s ) {
	$p = get_post( (int) $s['id'] );
	if ( ! $p ) { return false; }
	wp_update_post( wp_slash( array_merge( array( 'ID' => (int) $s['id'] ), $s['fields'] ) ) );
	foreach ( $s['missing'] as $k ) { delete_post_meta( (int) $s['id'], $k ); }
	foreach ( $s['meta'] as $k => $v ) { update_post_meta( (int) $s['id'], $k, wp_slash( $v ) ); }
	if ( function_exists( 'rk_builder_flush_meta_cache' ) ) { rk_builder_flush_meta_cache( (int) $s['id'] ); }
	return true;
}

/** Options an install can change (stored as value or "was not set"). */
function rk_builder_undo_option_names( $slug, $prev ) {
	$n = array( 'rk_theme_config', 'rk_builder_global', 'rk_builder_redirects', 'rk_builder_seo_org', 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'rk_builder_active_theme', 'rk_builder_theme_items_' . $slug );
	if ( defined( 'RK_BUILDER_TYPES_OPTION' ) ) { $n[] = RK_BUILDER_TYPES_OPTION; }
	if ( '' !== $prev ) { $n[] = 'rk_builder_theme_items_' . $prev; }
	return array_values( array_unique( $n ) );
}

/** Ids of existing posts at the addresses a bundle uses. */
function rk_builder_undo_existing_ids( array $bundle ) {
	$ids = array();
	foreach ( isset( $bundle['pages'] ) && is_array( $bundle['pages'] ) ? $bundle['pages'] : array() as $p ) {
		if ( is_array( $p ) && isset( $p['slug'] ) && is_string( $p['slug'] ) ) { $id = rk_builder_find_page_by_slug( $p['slug'] ); if ( $id > 0 ) { $ids[ $id ] = true; } }
	}
	foreach ( isset( $bundle['reusables'] ) && is_array( $bundle['reusables'] ) ? $bundle['reusables'] : array() as $r ) {
		if ( is_array( $r ) && isset( $r['slug'] ) && is_string( $r['slug'] ) ) { $id = rk_builder_find_reusable_by_slug( $r['slug'] ); if ( $id > 0 ) { $ids[ $id ] = true; } }
	}
	foreach ( isset( $bundle['templates'] ) && is_array( $bundle['templates'] ) ? $bundle['templates'] : array() as $t ) {
		if ( is_array( $t ) && isset( $t['slug'] ) && is_string( $t['slug'] ) ) { $id = rk_builder_dyn_find_template_by_slug( $t['slug'] ); if ( $id > 0 ) { $ids[ $id ] = true; } }
	}
	foreach ( array_merge( isset( $bundle['content'] ) && is_array( $bundle['content'] ) ? $bundle['content'] : array(), isset( $bundle['entries'] ) && is_array( $bundle['entries'] ) ? $bundle['entries'] : array() ) as $c ) {
		if ( ! is_array( $c ) || ! isset( $c['type'], $c['slug'] ) || ! is_string( $c['type'] ) || ! is_string( $c['slug'] ) ) { continue; }
		$f = get_posts( array( 'post_type' => $c['type'], 'name' => $c['slug'], 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		if ( $f ) { $ids[ (int) $f[0] ] = true; }
	}
	return array_keys( $ids );
}

/** Take the "before" picture. Call just before the bundle is imported. */
function rk_builder_undo_begin( array $bundle, $slug, $prev ) {
	$posts = array();
	foreach ( rk_builder_undo_existing_ids( $bundle ) as $id ) {
		$c = rk_builder_undo_capture_post( $id );
		if ( null !== $c ) { $posts[] = $c; }
	}
	$tpl = array();
	foreach ( rk_builder_tpl_all() as $t ) { $tpl[ (int) $t->ID ] = rk_builder_tpl_meta( $t->ID )['active']; }
	$opts = array();
	foreach ( rk_builder_undo_option_names( $slug, $prev ) as $n ) {
		$v = get_option( $n, '__rk_unset__' );
		$opts[ $n ] = array( 'set' => '__rk_unset__' !== $v, 'value' => '__rk_unset__' !== $v ? $v : null );
	}
	$re_slugs = array();
	foreach ( isset( $bundle['reusables'] ) && is_array( $bundle['reusables'] ) ? $bundle['reusables'] : array() as $r ) { if ( is_array( $r ) && isset( $r['slug'] ) && is_string( $r['slug'] ) ) { $re_slugs[] = $r['slug']; } }
	$had = array();
	foreach ( $posts as $c ) { $had[ $c['id'] ] = true; }
	return array( 'v' => 1, 'at' => rk_builder_iso( rk_builder_now() ), 'slug' => $slug, 'posts' => $posts, 'had' => $had, 'templatesActive' => $tpl, 'options' => $opts, 'reusableSlugs' => $re_slugs );
}

/** Complete the picture with what the install did, and keep it. @return array|null summary (null when it could not be kept) */
function rk_builder_undo_finish( array $snap, array $report, array $hidden, $theme_name ) {
	$created = array( 'pages' => array(), 'templates' => array(), 'reusables' => array(), 'posts' => array() );
	foreach ( isset( $report['pages']['done'] ) ? $report['pages']['done'] : array() as $d ) { if ( 'created' === $d['action'] && empty( $snap['had'][ (int) $d['id'] ] ) ) { $created['pages'][] = (int) $d['id']; } }
	foreach ( isset( $report['templates']['done'] ) ? $report['templates']['done'] : array() as $d ) { if ( 'created' === $d['action'] && empty( $snap['had'][ (int) $d['id'] ] ) ) { $created['templates'][] = (int) $d['id']; } }
	foreach ( $snap['reusableSlugs'] as $s ) { $id = rk_builder_find_reusable_by_slug( $s ); if ( $id > 0 && empty( $snap['had'][ $id ] ) ) { $created['reusables'][] = $id; } }
	foreach ( isset( $report['touched']['posts'] ) ? $report['touched']['posts'] : array() as $id ) { if ( empty( $snap['had'][ (int) $id ] ) ) { $created['posts'][] = (int) $id; } }
	$record = array_merge( $snap, array( 'created' => $created, 'hidden' => $hidden, 'name' => $theme_name ) );
	unset( $record['had'], $record['reusableSlugs'] );
	$json = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) || strlen( $json ) > RK_BUILDER_MAX_UNDO_BYTES ) { return null; }
	rk_builder_undo_forget();
	$file = 'undo-' . bin2hex( random_bytes( 8 ) ) . '.json';
	if ( false === @file_put_contents( rk_builder_kit_dir() . '/' . $file, $json ) ) { return null; } // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	$counts  = array( 'created' => count( $created['pages'] ) + count( $created['templates'] ) + count( $created['reusables'] ) + count( $created['posts'] ), 'changed' => count( $snap['posts'] ), 'hidden' => count( $hidden['pages'] ) + count( $hidden['templates'] ) + count( $hidden['posts'] ) );
	$summary = array( 'name' => (string) $theme_name, 'at' => $snap['at'], 'counts' => $counts );
	update_option( 'rk_builder_install_undo', array_merge( $summary, array( 'file' => $file ) ), false );
	return $summary;
}

/** Drop the kept record (file and option). */
function rk_builder_undo_forget() {
	$o = get_option( 'rk_builder_install_undo', null );
	if ( is_array( $o ) && isset( $o['file'] ) && is_string( $o['file'] ) && 1 === preg_match( '/^undo-[a-f0-9]{16}\.json\z/', $o['file'] ) ) { @unlink( rk_builder_kit_dir() . '/' . $o['file'] ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	delete_option( 'rk_builder_install_undo' );
}

/** What the library shows: null when there is nothing to undo. */
function rk_builder_undo_summary() {
	$o = get_option( 'rk_builder_install_undo', null );
	if ( ! is_array( $o ) || ! isset( $o['file'], $o['name'], $o['at'], $o['counts'] ) ) { return null; }
	return array( 'name' => (string) $o['name'], 'at' => (string) $o['at'], 'counts' => $o['counts'] );
}

/** Put everything back. @return array|WP_Error what was done */
function rk_builder_undo_apply() {
	$o = get_option( 'rk_builder_install_undo', null );
	if ( ! is_array( $o ) || ! isset( $o['file'] ) || ! is_string( $o['file'] ) || 1 !== preg_match( '/^undo-[a-f0-9]{16}\.json\z/', $o['file'] ) ) { return rk_builder_error( 'rk_nothing_to_undo', 'There is no install to undo.', 404 ); }
	$path = rk_builder_kit_dir() . '/' . $o['file'];
	$rec  = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $rec ) || ! isset( $rec['posts'], $rec['created'], $rec['hidden'], $rec['options'] ) ) {
		rk_builder_undo_forget();
		return rk_builder_error( 'rk_nothing_to_undo', 'The saved copy of the site is missing, so this install cannot be undone.', 410 );
	}
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors

	$done = array( 'trashed' => 0, 'restored' => 0, 'shown' => 0 );
	foreach ( $rec['created'] as $ids ) {
		foreach ( $ids as $id ) { if ( get_post( (int) $id ) && wp_trash_post( (int) $id ) ) { $done['trashed']++; } }
	}
	foreach ( $rec['posts'] as $s ) { if ( rk_builder_undo_restore_post( $s ) ) { $done['restored']++; } }
	foreach ( $rec['hidden'] as $ids ) {
		foreach ( $ids as $id ) {
			$p = get_post( (int) $id );
			if ( $p && 'publish' !== $p->post_status ) { wp_update_post( array( 'ID' => (int) $id, 'post_status' => 'publish' ) ); $done['shown']++; }
		}
	}
	foreach ( $rec['templatesActive'] as $id => $on ) { if ( get_post( (int) $id ) ) { update_post_meta( (int) $id, '_rk_tpl_active', $on ? '1' : '0' ); } }
	foreach ( $rec['options'] as $name => $s ) {
		if ( ! empty( $s['set'] ) ) { update_option( $name, $s['value'], false ); } else { delete_option( $name ); }
	}
	update_option( 'rk_builder_types_flush', 1, false );
	if ( function_exists( 'rk_builder_dyn_register_types' ) ) { rk_builder_dyn_register_types(); }
	if ( function_exists( 'rk_builder_theme_changed' ) ) { rk_builder_theme_changed(); }
	rk_builder_purge_all_public_cache();
	rk_builder_undo_forget();
	return $done;
}

function rk_builder_handle_theme_undo( $req ) {
	$r = rk_builder_undo_apply();
	return is_wp_error( $r ) ? $r : rk_builder_no_store( array( 'undone' => $r ) );
}
