<?php
/**
 * Theme builder pieces of a site export / import (and so of the theme engine): content type definitions,
 * templates, and the entries of custom types.
 *
 *   bundle.types     : the saved type definitions (custom types, plus built-in ones that carry fields)
 *   bundle.templates : [{ id, slug, title, kind, postType, taxonomy, active, wasPublished, layout }]
 *   bundle.entries   : [{ type, slug, title, status, excerpt, content, order, terms:{tax:[names]}, featured, fields:{key:value} }]
 *
 * Media ids inside fields and layouts are remapped through the same maps the pages use, template ids inside
 * Loop grid blocks follow the templates to their new ids, and everything is validated again on the way in.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------------ *
 * Export
 * ------------------------------------------------------------------ */

/** Stored field value -> the plain data an export carries (media as IDs). */
function rk_builder_dyn_export_value( array $f, $raw, array &$media_ids ) {
	switch ( $f['type'] ) {
		case 'image':
			$id = is_numeric( $raw ) ? (int) $raw : 0;
			if ( $id > 0 ) { $media_ids[ $id ] = true; }
			return $id > 0 ? $id : null;
		case 'gallery':
			$ids = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			$out = array();
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) { $out[] = (int) $id; $media_ids[ (int) $id ] = true; }
			}
			return $out;
		case 'repeater':
			$rows = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			$out  = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$r = array();
				foreach ( $f['subfields'] as $sf ) { $r[ $sf['key'] ] = rk_builder_dyn_export_value( $sf, isset( $row[ $sf['key'] ] ) ? $row[ $sf['key'] ] : '', $media_ids ); }
				$out[] = $r;
			}
			return $out;
		case 'toggle':
			return '1' === (string) $raw;
	}
	return is_string( $raw ) ? $raw : '';
}

/**
 * @param array $refs      media references found in template layouts (appended)
 * @param array $media_ids attachment ids used by entries (appended)
 * @return array{types:array,templates:array,entries:array}
 */
function rk_builder_dyn_export_bundle( array &$refs, array &$media_ids ) {
	$types = array_values( rk_builder_types() );
	$templates = array();
	foreach ( rk_builder_tpl_all() as $post ) {
		$m      = rk_builder_tpl_meta( $post->ID );
		$layout = rk_builder_get_draft_layout( $post->ID );
		$templates[] = array(
			'id' => (int) $post->ID, 'slug' => rk_builder_tpl_slug( $post->ID ), 'title' => rk_builder_plain( $post->post_title ),
			'kind' => $m['kind'], 'postType' => $m['postType'], 'taxonomy' => $m['taxonomy'], 'active' => $m['active'],
			'wasPublished' => 'publish' === $post->post_status, 'layout' => $layout,
		);
		$refs = array_merge( $refs, rk_builder_bundle_media_refs( $layout ) );
	}
	$entries = array();
	foreach ( $types as $t ) {
		if ( $t['builtin'] || ! post_type_exists( $t['slug'] ) ) { continue; }
		$posts = get_posts( array( 'post_type' => $t['slug'], 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => RK_BUILDER_MAX_TRANSFER_CONTENT, 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $posts as $p ) {
			$thumb = (int) get_post_thumbnail_id( $p->ID );
			if ( $thumb > 0 ) { $media_ids[ $thumb ] = true; }
			$fields = array();
			foreach ( $t['fields'] as $f ) { $fields[ $f['key'] ] = rk_builder_dyn_export_value( $f, rk_builder_dyn_raw( $p->ID, $f ), $media_ids ); }
			$terms = array();
			foreach ( $t['taxonomies'] as $x ) {
				$names = wp_get_object_terms( $p->ID, $x['slug'], array( 'fields' => 'names' ) );
				$terms[ $x['slug'] ] = is_wp_error( $names ) ? array() : array_values( array_map( 'strval', (array) $names ) );
			}
			$entries[] = array(
				'type' => $t['slug'], 'slug' => '' !== (string) $p->post_name ? (string) $p->post_name : sanitize_title( $p->post_title ), 'title' => rk_builder_plain( get_the_title( $p ) ), 'status' => (string) $p->post_status,
				'excerpt' => (string) $p->post_excerpt, 'content' => (string) $p->post_content, 'order' => (int) $p->menu_order,
				'terms' => $terms, 'featured' => $thumb > 0 ? $thumb : null, 'fields' => $fields,
				'seo' => rk_builder_seo_read( (int) $p->ID ),
			);
		}
	}
	return array( 'types' => $types, 'templates' => $templates, 'entries' => $entries );
}

/* ------------------------------------------------------------------ *
 * Import: remapping helpers
 * ------------------------------------------------------------------ */

/** Loop grid `templateId` follows the templates to their new ids (0 = built-in card when the template is not in the file). */
function rk_builder_bundle_remap_templates( array $layout, array $tpl_map ) {
	foreach ( isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ? $layout['blocks'] : array() as $i => $b ) {
		if ( is_array( $b ) && isset( $b['type'] ) && 'loopgrid' === $b['type'] && isset( $b['props']['templateId'] ) ) {
			$old = (int) $b['props']['templateId'];
			$layout['blocks'][ $i ]['props']['templateId'] = $old > 0 && isset( $tpl_map[ $old ] ) ? (int) $tpl_map[ $old ] : 0;
		}
	}
	return $layout;
}

/** A field value as found in a bundle -> input for rk_builder_dyn_clean_value(), media ids mapped to this site's. */
function rk_builder_dyn_remap_value( array $f, $v, array $maps ) {
	$map = function ( $old ) use ( $maps ) {
		$rec = rk_builder_bundle_lookup( $maps, is_numeric( $old ) ? (int) $old : null, null );
		return null === $rec ? null : (int) $rec['id'];
	};
	switch ( $f['type'] ) {
		case 'image':
			return null === $v ? null : $map( $v );
		case 'gallery':
			$out = array();
			foreach ( is_array( $v ) ? $v : array() as $id ) { $n = $map( $id ); if ( null !== $n ) { $out[] = $n; } }
			return $out;
		case 'repeater':
			$out = array();
			foreach ( is_array( $v ) ? $v : array() as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$r = array();
				foreach ( $f['subfields'] as $sf ) { $r[ $sf['key'] ] = rk_builder_dyn_remap_value( $sf, isset( $row[ $sf['key'] ] ) ? $row[ $sf['key'] ] : null, $maps ); }
				$out[] = $r;
			}
			return $out;
	}
	return $v;
}

/* ------------------------------------------------------------------ *
 * Import: steps (called from rk_builder_site_import_run)
 * ------------------------------------------------------------------ */

/** Valid type definitions of a bundle. Returns array( list, warnings ). */
function rk_builder_dyn_import_types_in( array $bundle ) {
	$in = isset( $bundle['types'] ) && is_array( $bundle['types'] ) ? array_values( $bundle['types'] ) : array();
	if ( ! $in ) { return array( array(), array() ); }
	list( $list, $issues ) = rk_builder_dyn_clean_types( $in );
	if ( $issues ) { return array( array(), array( 'The content types in the file are not valid and were not imported (' . $issues[0]['message'] . ').' ) ); }
	return array( $list, array() );
}

/** Merge the bundle's types into this site's (same slug: the file wins), register them and ask for a rewrite flush. */
function rk_builder_dyn_import_types_apply( array $list ) {
	$have = rk_builder_types();
	foreach ( $list as $t ) { $have[ $t['slug'] ] = $t; }
	list( $merged, $issues ) = rk_builder_dyn_clean_types( array_values( $have ) );
	if ( $issues || null === $merged ) { return false; }
	update_option( RK_BUILDER_TYPES_OPTION, $merged, false );
	update_option( 'rk_builder_types_flush', 1, false );
	rk_builder_dyn_register_types();
	return true;
}

function rk_builder_dyn_find_template_by_slug( $slug ) {
	foreach ( rk_builder_tpl_all() as $p ) {
		if ( rk_builder_tpl_slug( $p->ID ) === $slug ) { return (int) $p->ID; }
	}
	return 0;
}

/** Valid templates of a bundle: array( list, skipped ). */
function rk_builder_dyn_import_templates_in( array $bundle, $hosts ) {
	$in      = isset( $bundle['templates'] ) && is_array( $bundle['templates'] ) ? $bundle['templates'] : array();
	$ok      = array();
	$skipped = array();
	$slugs   = array();
	foreach ( $in as $i => $t ) {
		$slug  = is_array( $t ) && isset( $t['slug'] ) ? $t['slug'] : null;
		$label = is_string( $slug ) ? $slug : '#' . $i;
		$old   = is_array( $t ) && isset( $t['id'] ) && rk_builder_is_intlike( $t['id'] ) && $t['id'] > 0 ? (int) $t['id'] : 0;
		$title = is_array( $t ) && isset( $t['title'] ) && is_string( $t['title'] ) ? trim( $t['title'] ) : '';
		if ( ! rk_builder_bundle_slug_ok( $slug ) || isset( $slugs[ $slug ] ) || '' === $title || rk_builder_strlen( $title ) > 80 || ! in_array( isset( $t['kind'] ) ? $t['kind'] : '', rk_builder_tpl_kinds(), true ) ) {
			$skipped[] = array( 'slug' => $label, 'issues' => array( 'Invalid template entry' ) );
			continue;
		}
		$problems = isset( $t['layout'] ) ? rk_builder_validate_layout( $t['layout'], $hosts ) : array( array( 'path' => 'layout', 'message' => 'Required' ) );
		if ( $problems ) {
			$skipped[] = array( 'slug' => $slug, 'issues' => array_map( function ( $p ) { return $p['path'] . ': ' . $p['message']; }, array_slice( $problems, 0, 5 ) ) );
			continue;
		}
		$slugs[ $slug ] = true;
		$ok[] = array(
			'oldId' => $old, 'slug' => $slug, 'title' => sanitize_text_field( $title ), 'kind' => $t['kind'],
			'postType' => isset( $t['postType'] ) && is_string( $t['postType'] ) ? $t['postType'] : '', 'taxonomy' => isset( $t['taxonomy'] ) && is_string( $t['taxonomy'] ) ? $t['taxonomy'] : '',
			'active' => ! empty( $t['active'] ), 'wasPublished' => ! empty( $t['wasPublished'] ), 'layout' => rk_builder_canonicalize_layout( $t['layout'] ),
		);
	}
	return array( $ok, $skipped );
}

/**
 * Create or update the templates (drafts; the theme installer publishes them). Returns array( tpl_map oldId=>newId, done list ).
 *
 * @param array $re_map reusable id map
 */
function rk_builder_dyn_import_templates_apply( array $list, array $maps, array $re_map, $real_hosts, array &$report ) {
	$tpl_map = array();
	$ids     = array();
	// Pass 1: make sure every template exists, so card templates have ids before layouts refer to them.
	foreach ( $list as $t ) {
		if ( ! rk_builder_tpl_sitewide( $t['kind'] ) && null === rk_builder_dyn_type( $t['postType'] ) ) {
			$report['templates']['skipped'][] = array( 'slug' => $t['slug'], 'issues' => array( 'Its content type "' . $t['postType'] . '" is not on this site.' ) );
			continue;
		}
		$id = rk_builder_dyn_find_template_by_slug( $t['slug'] );
		$action = 'updated';
		if ( $id < 1 ) {
			$id = wp_insert_post( array( 'post_type' => RK_BUILDER_TEMPLATE_TYPE, 'post_status' => 'draft', 'post_title' => $t['title'] ), true );
			if ( is_wp_error( $id ) || ! $id ) { $report['templates']['skipped'][] = array( 'slug' => $t['slug'], 'issues' => array( 'Could not create it' ) ); continue; }
			$action = 'created';
		} else {
			wp_update_post( array( 'ID' => $id, 'post_title' => $t['title'] ) );
		}
		$id = (int) $id;
		update_post_meta( $id, '_rk_tpl_slug', $t['slug'] );
		update_post_meta( $id, '_rk_tpl_kind', $t['kind'] );
		update_post_meta( $id, '_rk_tpl_type', $t['postType'] );
		update_post_meta( $id, '_rk_tpl_tax', $t['taxonomy'] );
		if ( '' === (string) get_post_meta( $id, '_rk_tpl_active', true ) ) { update_post_meta( $id, '_rk_tpl_active', '0' ); }
		if ( $t['oldId'] > 0 ) { $tpl_map[ $t['oldId'] ] = $id; }
		$ids[ $t['slug'] ] = array( $id, $action );
	}
	// Pass 2: layouts.
	foreach ( $list as $t ) {
		if ( ! isset( $ids[ $t['slug'] ] ) ) { continue; }
		list( $id, $action ) = $ids[ $t['slug'] ];
		$layout  = rk_builder_bundle_remap_layout( $t['layout'], $maps );
		$dropped = 0;
		$layout  = rk_builder_bundle_remap_reusables( $layout, $re_map, $dropped );
		$layout  = rk_builder_bundle_remap_templates( $layout, $tpl_map );
		$final   = rk_builder_validate_layout( $layout, $real_hosts );
		if ( $final ) {
			$report['templates']['skipped'][] = array( 'slug' => $t['slug'], 'issues' => array_map( function ( $p ) { return $p['path'] . ': ' . $p['message']; }, array_slice( $final, 0, 5 ) ) );
			continue;
		}
		$layout = rk_builder_canonicalize_layout( $layout );
		$commit = rk_builder_with_lock( $id, function () use ( $id, $layout ) { return rk_builder_commit_revision( $id, 'draft', $layout ); } );
		if ( is_wp_error( $commit ) ) { $report['templates']['skipped'][] = array( 'slug' => $t['slug'], 'issues' => array( $commit->get_error_message() ) ); continue; }
		$report['templates']['done'][] = array( 'slug' => $t['slug'], 'id' => $id, 'action' => $action, 'revision' => $commit['revision'], 'kind' => $t['kind'], 'active' => $t['active'], 'wasPublished' => $t['wasPublished'] );
	}
	return $tpl_map;
}

/** Valid entries of a bundle, for types the site has (after the types step). */
function rk_builder_dyn_import_entries_in( array $bundle, array $known_types ) {
	$out = array();
	$in  = isset( $bundle['entries'] ) && is_array( $bundle['entries'] ) ? $bundle['entries'] : array();
	foreach ( $in as $e ) {
		if ( ! is_array( $e ) || ! isset( $e['type'], $e['slug'], $e['title'] ) || ! is_string( $e['type'] ) || ! isset( $known_types[ $e['type'] ] ) || ! rk_builder_bundle_slug_ok( $e['slug'] ) || ! is_string( $e['title'] ) || '' === trim( $e['title'] ) ) { continue; }
		$out[] = $e;
	}
	return $out;
}

/** Create or update the entries; $status is the status new entries get. */
function rk_builder_dyn_import_entries_apply( array $entries, array $maps, $status, array &$report ) {
	foreach ( $entries as $e ) {
		$def = rk_builder_dyn_type( $e['type'] );
		if ( ! $def || ! post_type_exists( $e['type'] ) ) { continue; }
		$existing = get_posts( array( 'post_type' => $e['type'], 'name' => $e['slug'], 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$data = array(
			'post_type' => $e['type'], 'post_title' => sanitize_text_field( $e['title'] ), 'post_name' => $e['slug'],
			'post_excerpt' => isset( $e['excerpt'] ) && is_string( $e['excerpt'] ) ? sanitize_textarea_field( $e['excerpt'] ) : '',
			'post_content' => isset( $e['content'] ) && is_string( $e['content'] ) ? wp_kses_post( $e['content'] ) : '',
			'menu_order' => isset( $e['order'] ) && is_int( $e['order'] ) ? $e['order'] : 0,
		);
		if ( $existing ) { $data['ID'] = (int) $existing[0]; $pid = wp_update_post( wp_slash( $data ), true ); }
		else { $data['post_status'] = $status; $pid = wp_insert_post( wp_slash( $data ), true ); }
		if ( is_wp_error( $pid ) || ! $pid ) { $report['warnings'][] = 'Could not import the entry "' . $e['slug'] . '".'; continue; }
		$pid = (int) $pid;
		$report['entries'][ $existing ? 'updated' : 'created' ]++;
		$report['touched']['posts'][] = $pid;
		if ( $existing ) { rk_builder_theme_unhide_post( $pid, $status ); }
		$fields = isset( $e['fields'] ) && is_array( $e['fields'] ) ? $e['fields'] : array();
		foreach ( $def['fields'] as $f ) {
			if ( ! array_key_exists( $f['key'], $fields ) ) { continue; }
			list( $clean, $err ) = rk_builder_dyn_clean_value( $f, rk_builder_dyn_remap_value( $f, $fields[ $f['key'] ], $maps ) );
			if ( null === $err ) { rk_builder_dyn_store( $pid, $f, $clean ); }
		}
		$allowed = array();
		foreach ( rk_builder_dyn_taxonomies( $e['type'] ) as $x ) { $allowed[ $x['slug'] ] = true; }
		foreach ( isset( $e['terms'] ) && is_array( $e['terms'] ) ? $e['terms'] : array() as $tax => $names ) {
			if ( ! isset( $allowed[ $tax ] ) || ! is_array( $names ) ) { continue; }
			$clean = array();
			foreach ( array_slice( $names, 0, 50 ) as $n ) { $n = is_string( $n ) ? trim( sanitize_text_field( $n ) ) : ''; if ( '' !== $n ) { $clean[] = $n; } }
			wp_set_object_terms( $pid, $clean, $tax, false );
		}
		if ( isset( $e['featured'] ) && is_int( $e['featured'] ) ) {
			$rec = rk_builder_bundle_lookup( $maps, $e['featured'], null );
			if ( null !== $rec ) { set_post_thumbnail( $pid, (int) $rec['id'] ); }
		}
		if ( isset( $e['seo'] ) ) { rk_builder_seo_write( $pid, rk_builder_seo_remap( rk_builder_seo_clean( $e['seo'] ), $maps ) ); }
	}
}
