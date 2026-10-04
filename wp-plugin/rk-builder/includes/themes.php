<?php
/**
 * Theme engine: turn the current site into a reusable theme package, keep a library of packages on
 * this site, install one with a single call, and export / import a whole package as one JSON file.
 *
 * A package is a normal site bundle (see site-transfer.php) plus `themeMeta`
 * { name, slug, description, version, author, createdAt, preview }, so a theme file also works with
 * the plain "Import site" screen.
 *
 *   GET  /builder/themes          → { items: [ summary ] }
 *   POST /builder/themes          → { name, description?, version?, author? }  save the current site
 *   POST /builder/themes/import   → { bundle, name? }                           add a file to the library
 *   POST /builder/themes/install  → { slug, options? }                          apply a package
 *   GET  /builder/themes/export   → ?slug=                                      the package file
 *   POST /builder/themes/delete   → { slug }
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_MAX_THEMES' ) ) { define( 'RK_BUILDER_MAX_THEMES', 12 ); }

/** @return array<string,array> slug => summary */
function rk_builder_themes_index() {
	$i = get_option( 'rk_builder_theme_index', array() );
	return is_array( $i ) ? $i : array();
}

function rk_builder_theme_slugify( $name ) {
	$s = strtolower( trim( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $name ), '-' ) );
	return '' === $s ? 'theme' : substr( $s, 0, 60 );
}

function rk_builder_theme_slug_ok( $slug ) {
	return is_string( $slug ) && 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,59}\z/', $slug );
}

/** Clean text field: tags stripped, whitespace collapsed, capped. */
function rk_builder_theme_text( $v, $max ) {
	if ( ! is_string( $v ) ) { return ''; }
	$v = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $v ) ) );
	return rk_builder_substr( $v, 0, $max );
}

/** @return array{name:string,description:string,version:string,author:string} */
function rk_builder_theme_meta_clean( $in ) {
	$in      = is_array( $in ) ? $in : array();
	$version = isset( $in['version'] ) ? rk_builder_theme_text( $in['version'], 20 ) : '';
	if ( 1 !== preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+-]*\z/', $version ) ) { $version = '1.0.0'; }
	return array(
		'name'        => rk_builder_theme_text( isset( $in['name'] ) ? $in['name'] : '', 80 ),
		'description' => rk_builder_theme_text( isset( $in['description'] ) ? $in['description'] : '', 300 ),
		'version'     => $version,
		'author'      => rk_builder_theme_text( isset( $in['author'] ) ? $in['author'] : '', 80 ),
	);
}

/** First usable image address in the first pages (cover/hero background, image or split image). */
function rk_builder_theme_preview_url( array $bundle ) {
	$pages = isset( $bundle['pages'] ) && is_array( $bundle['pages'] ) ? $bundle['pages'] : array();
	foreach ( array_slice( $pages, 0, 3 ) as $pg ) {
		$blocks = isset( $pg['layout']['blocks'] ) && is_array( $pg['layout']['blocks'] ) ? $pg['layout']['blocks'] : array();
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b ) || empty( $b['props'] ) || ! is_array( $b['props'] ) ) { continue; }
			foreach ( array( 'bgUrl', 'imageUrl', 'url' ) as $k ) {
				if ( isset( $b['props'][ $k ] ) && is_string( $b['props'][ $k ] ) ) {
					$u = rk_builder_absolute_url( $b['props'][ $k ] );
					if ( '' !== $u ) { return $u; }
				}
			}
		}
	}
	return '';
}

/** Library summary of a package (what the list shows). */
function rk_builder_theme_summary( $slug, array $bundle, array $meta, $bytes ) {
	return array(
		'slug'        => $slug,
		'name'        => $meta['name'],
		'description' => $meta['description'],
		'version'     => $meta['version'],
		'author'      => $meta['author'],
		'pages'       => isset( $bundle['pages'] ) && is_array( $bundle['pages'] ) ? count( $bundle['pages'] ) : 0,
		'reusables'   => isset( $bundle['reusables'] ) && is_array( $bundle['reusables'] ) ? count( $bundle['reusables'] ) : 0,
		'media'       => isset( $bundle['media'] ) && is_array( $bundle['media'] ) ? count( $bundle['media'] ) : 0,
		'content'     => ( isset( $bundle['content'] ) && is_array( $bundle['content'] ) ? count( $bundle['content'] ) : 0 ) + ( isset( $bundle['entries'] ) && is_array( $bundle['entries'] ) ? count( $bundle['entries'] ) : 0 ),
		'templates'   => isset( $bundle['templates'] ) && is_array( $bundle['templates'] ) ? count( $bundle['templates'] ) : 0,
		'types'       => isset( $bundle['types'] ) && is_array( $bundle['types'] ) ? count( $bundle['types'] ) : 0,
		'preview'     => rk_builder_theme_preview_url( $bundle ),
		'createdAt'   => isset( $bundle['themeMeta']['createdAt'] ) && is_string( $bundle['themeMeta']['createdAt'] ) ? $bundle['themeMeta']['createdAt'] : rk_builder_iso( rk_builder_now() ),
		'bytes'       => (int) $bytes,
		'industry'    => isset( $meta['industry'] ) ? $meta['industry'] : '',
		'license'     => isset( $meta['license'] ) ? $meta['license'] : '',
		'demo'        => isset( $meta['demo'] ) ? $meta['demo'] : '',
	);
}

/** Save a bundle into the library under $slug (replaces a package with the same slug). @return array|WP_Error summary */
function rk_builder_theme_store( $slug, array $bundle, array $meta ) {
	$index = rk_builder_themes_index();
	if ( ! isset( $index[ $slug ] ) && count( $index ) >= RK_BUILDER_MAX_THEMES ) {
		return rk_builder_error( 'rk_limit', 'The library holds at most ' . RK_BUILDER_MAX_THEMES . ' themes. Delete one first.', 409 );
	}
	$bundle['themeMeta'] = array_merge( $meta, array(
		'slug'      => $slug,
		'createdAt' => rk_builder_iso( rk_builder_now() ),
		'preview'   => rk_builder_theme_preview_url( $bundle ),
	) );
	$json = wp_json_encode( $bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) ) { return rk_builder_error( 'rk_server_error', 'Could not encode the theme.', 500 ); }
	$replacing_kit = '' !== rk_builder_theme_kit_path( $slug );
	if ( strlen( $json ) > RK_BUILDER_MAX_IMPORT_BYTES ) {
		return rk_builder_error( 'rk_payload_too_large', 'The theme is larger than ' . ( RK_BUILDER_MAX_IMPORT_BYTES / 1048576 ) . ' MB.', 413 );
	}
	if ( $replacing_kit ) { rk_builder_theme_kit_forget( $slug ); }
	update_option( 'rk_builder_theme_pkg_' . $slug, $json, false );
	$index[ $slug ] = rk_builder_theme_summary( $slug, $bundle, $meta, strlen( $json ) );
	update_option( 'rk_builder_theme_index', $index, false );
	return $index[ $slug ];
}

/** @return array|null the stored package (a site bundle with themeMeta) */
function rk_builder_theme_load( $slug ) {
	if ( ! rk_builder_theme_slug_ok( $slug ) || ! isset( rk_builder_themes_index()[ $slug ] ) ) { return null; }
	$raw = get_option( 'rk_builder_theme_pkg_' . $slug, '' );
	$b   = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
	return is_array( $b ) ? $b : null;
}

function rk_builder_theme_not_found() {
	return rk_builder_error( 'rk_not_found', 'That theme is not in the library.', 404 );
}

/** A unique slug for a new package: the name's slug, or the slug of the file being replaced. */
function rk_builder_theme_pick_slug( $name, $replace_slug = '' ) {
	return rk_builder_theme_slug_ok( $replace_slug ) ? $replace_slug : rk_builder_theme_slugify( $name );
}

/* ------------------------------------------------------------------ *
 * Handlers
 * ------------------------------------------------------------------ */

function rk_builder_handle_themes_list( $req ) {
	$active = (string) get_option( 'rk_builder_active_theme', '' );
	$items  = array();
	foreach ( rk_builder_themes_index() as $slug => $s ) { $s = rk_builder_kit_public_summary( $s ); $s['active'] = ( $slug === $active ); $items[] = $s; }
	return rk_builder_no_store( array( 'items' => $items, 'undo' => rk_builder_undo_summary() ) );
}

function rk_builder_themes_body( $req, array $allowed ) {
	if ( strlen( (string) $req->get_body() ) > RK_BUILDER_MAX_IMPORT_BYTES ) {
		return rk_builder_error( 'rk_payload_too_large', 'The file is larger than ' . ( RK_BUILDER_MAX_IMPORT_BYTES / 1048576 ) . ' MB.', 413 );
	}
	$body = rk_builder_json_body( $req, 'rk_invalid_theme' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, $allowed, true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	return $issues ? rk_builder_invalid( 'rk_invalid_theme', $issues ) : $body;
}

/** Save the current site as a theme package. */
function rk_builder_handle_theme_capture( $req ) {
	$body = rk_builder_themes_body( $req, array( 'name', 'description', 'version', 'author', 'industry', 'license', 'demo' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$meta = rk_builder_kit_meta_clean( $body );
	if ( '' === $meta['name'] ) { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'name', 'message' => 'Give the theme a name.' ) ) ); }
	$bundle = rk_builder_build_site_bundle();
	if ( is_wp_error( $bundle ) ) { return $bundle; }
	if ( empty( $bundle['pages'] ) ) {
		return rk_builder_error( 'rk_empty', 'This site has no builder pages to package yet.', 409 );
	}
	$saved = rk_builder_theme_store( rk_builder_theme_pick_slug( $meta['name'] ), $bundle, $meta );
	return is_wp_error( $saved ) ? $saved : rk_builder_no_store( array( 'theme' => $saved ) );
}

/** Add an exported theme (or plain site export) file to the library without applying it. */
function rk_builder_handle_theme_import( $req ) {
	$body = rk_builder_themes_body( $req, array( 'bundle', 'name' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	if ( ! isset( $body['bundle'] ) ) { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'bundle', 'message' => 'Required' ) ) ); }
	$bundle = $body['bundle'];
	$shape  = rk_builder_bundle_check_shape( $bundle );
	if ( $shape ) { return rk_builder_invalid( 'rk_invalid_theme', $shape, 'This is not a usable RK Builder theme or site export.' ); }
	$in   = isset( $bundle['themeMeta'] ) && is_array( $bundle['themeMeta'] ) ? $bundle['themeMeta'] : array();
	if ( isset( $body['name'] ) ) { $in['name'] = $body['name']; }
	$meta = rk_builder_theme_meta_clean( $in );
	if ( '' === $meta['name'] ) { $meta['name'] = 'Imported theme'; }
	unset( $bundle['themeMeta'] );
	$check = rk_builder_site_import_run( $bundle, array( 'dryRun' => true, 'theme' => true, 'content' => true, 'contentStatus' => 'draft' ) );
	if ( is_wp_error( $check ) ) { return $check; }
	$report = $check instanceof WP_REST_Response ? $check->get_data() : $check;
	if ( empty( $report['pages']['create'] ) && empty( $report['pages']['update'] ) ) {
		return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'pages', 'message' => 'The file has no usable pages.' ) ), 'There is nothing in this file to install.' );
	}
	$saved = rk_builder_theme_store( rk_builder_theme_pick_slug( $meta['name'] ), $bundle, $meta );
	return is_wp_error( $saved ) ? $saved : rk_builder_no_store( array( 'theme' => $saved, 'check' => $report ) );
}

/** What a theme put on the site: page, template and post ids (remembered so switching themes can hide them). */
function rk_builder_theme_items( $slug ) {
	$i = get_option( 'rk_builder_theme_items_' . $slug, array() );
	$i = is_array( $i ) ? $i : array();
	return array(
		'pages'     => isset( $i['pages'] ) && is_array( $i['pages'] ) ? array_map( 'intval', $i['pages'] ) : array(),
		'templates' => isset( $i['templates'] ) && is_array( $i['templates'] ) ? array_map( 'intval', $i['templates'] ) : array(),
		'posts'     => isset( $i['posts'] ) && is_array( $i['posts'] ) ? array_map( 'intval', $i['posts'] ) : array(),
	);
}

/**
 * What switching away from theme $prev would hide: its live pages, templates and content, except what the new theme
 * (slugs in $bundle) takes over by the same address. Nothing is deleted. With $apply false only the plan is returned.
 *
 * @return array{pages:int[],templates:int[],posts:int[]}
 */
function rk_builder_theme_hide_old( $prev, array $bundle, $apply ) {
	$keep_pages = array();
	foreach ( isset( $bundle['pages'] ) && is_array( $bundle['pages'] ) ? $bundle['pages'] : array() as $p ) { if ( is_array( $p ) && isset( $p['slug'] ) ) { $keep_pages[ (string) $p['slug'] ] = true; } }
	$keep_tpl = array();
	foreach ( isset( $bundle['templates'] ) && is_array( $bundle['templates'] ) ? $bundle['templates'] : array() as $t ) { if ( is_array( $t ) && isset( $t['slug'] ) ) { $keep_tpl[ (string) $t['slug'] ] = true; } }
	$keep_post = array();
	foreach ( array_merge( isset( $bundle['content'] ) && is_array( $bundle['content'] ) ? $bundle['content'] : array(), isset( $bundle['entries'] ) && is_array( $bundle['entries'] ) ? $bundle['entries'] : array() ) as $c ) {
		if ( is_array( $c ) && isset( $c['type'], $c['slug'] ) ) { $keep_post[ $c['type'] . '/' . $c['slug'] ] = true; }
	}
	$old  = rk_builder_theme_items( $prev );
	$plan = array( 'pages' => array(), 'templates' => array(), 'posts' => array() );
	foreach ( $old['pages'] as $id ) {
		$p = get_post( $id );
		if ( $p && 'publish' === $p->post_status && empty( $keep_pages[ (string) $p->post_name ] ) ) { $plan['pages'][] = $id; }
	}
	foreach ( $old['templates'] as $id ) {
		$p = get_post( $id );
		if ( $p && 'publish' === $p->post_status && empty( $keep_tpl[ rk_builder_tpl_slug( $id ) ] ) ) { $plan['templates'][] = $id; }
	}
	foreach ( $old['posts'] as $id ) {
		$p = get_post( $id );
		if ( $p && 'publish' === $p->post_status && empty( $keep_post[ $p->post_type . '/' . $p->post_name ] ) ) { $plan['posts'][] = $id; }
	}
	if ( ! $apply ) { return $plan; }
	foreach ( array_merge( $plan['pages'], $plan['templates'] ) as $id ) {
		rk_builder_handle_unpublish( rk_builder_internal_request( 'POST', '/rk/v1/builder/unpublish/' . (int) $id, array( 'id' => (int) $id ), array() ) );
	}
	foreach ( $plan['templates'] as $id ) { rk_builder_tpl_set_active( (int) $id, false ); }
	foreach ( $plan['posts'] as $id ) { wp_update_post( array( 'ID' => (int) $id, 'post_status' => 'draft' ) ); update_post_meta( (int) $id, '_rk_theme_hidden', '1' ); }
	if ( $plan['pages'] || $plan['templates'] || $plan['posts'] ) { rk_builder_purge_all_public_cache(); }
	return $plan;
}

/** A content item that a theme switch hid comes back with the theme that owns it (when that install publishes). */
function rk_builder_theme_unhide_post( $pid, $status ) {
	if ( '1' !== (string) get_post_meta( (int) $pid, '_rk_theme_hidden', true ) ) { return; }
	delete_post_meta( (int) $pid, '_rk_theme_hidden' );
	if ( 'publish' === $status ) { wp_update_post( array( 'ID' => (int) $pid, 'post_status' => 'publish' ) ); }
}

/** Internal REST-style request (used to publish pages the same way the editor does). */
function rk_builder_internal_request( $method, $route, array $params, array $body ) {
	$r    = new WP_REST_Request( $method, $route );
	$json = wp_json_encode( $body );
	if ( method_exists( $r, 'set_url_params' ) ) { $r->set_url_params( $params ); } else { $r->url = $params; }
	if ( method_exists( $r, 'set_body' ) ) { $r->set_body( $json ); $r->set_header( 'content-type', 'application/json' ); } else { $r->body = $json; }
	return $r;
}

/** Apply a stored package: pages, reusable blocks, images, theme settings, services and projects, SEO. */
function rk_builder_handle_theme_install( $req ) {
	$body = rk_builder_themes_body( $req, array( 'slug', 'options' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$slug = isset( $body['slug'] ) ? $body['slug'] : null;
	if ( ! rk_builder_theme_slug_ok( $slug ) ) { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'slug', 'message' => 'Required' ) ) ); }
	$o = array( 'dryRun' => false, 'theme' => true, 'content' => true, 'publish' => false, 'frontPage' => false, 'settings' => true, 'redirects' => false, 'siteInfo' => false, 'switch' => true, 'pages' => true );
	$pairs = array();
	if ( isset( $body['options'] ) ) {
		if ( ! is_array( $body['options'] ) ) { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'options', 'message' => 'Expected object' ) ) ); }
		foreach ( $body['options'] as $k => $v ) {
			if ( array_key_exists( $k, $o ) && is_bool( $v ) ) { $o[ $k ] = $v; }
			elseif ( 'replace' === $k && is_array( $v ) ) { $pairs = rk_builder_replace_pairs_clean( $v ); }
			else { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'options.' . $k, 'message' => 'Unrecognized or invalid option' ) ) ); }
		}
	}
	$kit_path = rk_builder_theme_kit_path( $slug );
	$kit      = null;
	if ( '' !== $kit_path ) {
		$kit = rk_builder_kit_open( $kit_path );
		if ( is_wp_error( $kit ) ) { return $kit; }
		$bundle = $kit['bundle'];
	} else {
		$bundle = rk_builder_theme_load( $slug );
	}
	if ( null === $bundle ) { return rk_builder_theme_not_found(); }
	$suggest = rk_builder_replace_suggestions( $bundle );
	$bundle  = rk_builder_replace_in_bundle( $bundle, $pairs );
	if ( ! $o['pages'] ) { // design only: header, footer, templates, types, blocks and settings, without pages or demo content
		$bundle['pages'] = array();
		$bundle['content'] = array();
		$bundle['entries'] = array();
	}

	if ( $o['publish'] && ! $o['dryRun'] ) {
		// Content this theme put on the site that is a draft now was hidden by a switch: it returns with the theme.
		foreach ( rk_builder_theme_items( $slug )['posts'] as $pid ) {
			$p = get_post( $pid );
			if ( $p && 'draft' === $p->post_status ) { update_post_meta( (int) $pid, '_rk_theme_hidden', '1' ); }
		}
	}
	$prev_for_undo = (string) get_option( 'rk_builder_active_theme', '' );
	$snap = $o['dryRun'] ? null : rk_builder_undo_begin( $bundle, $slug, $prev_for_undo );
	$result = rk_builder_site_import_run( $bundle, array(
		'dryRun'        => $o['dryRun'],
		'theme'         => $o['theme'],
		'content'       => $o['content'],
		'contentStatus' => $o['publish'] ? 'publish' : 'draft',
		'settings'      => $o['settings'],
		'redirects'     => $o['redirects'],
		'siteInfo'      => $o['siteInfo'],
		'kitZip'        => null !== $kit ? $kit['zip'] : null,
	) );
	if ( null !== $kit ) { $kit['zip']->close(); }
	if ( is_wp_error( $result ) ) { return $result; }
	$report = $result instanceof WP_REST_Response ? $result->get_data() : $result;
	$report['published'] = 0;
	$report['frontPage'] = false;
	$report['suggest']   = $suggest;
	$prev = (string) get_option( 'rk_builder_active_theme', '' );
	$hide = ( $o['switch'] && '' !== $prev && $prev !== $slug );
	if ( $o['dryRun'] ) {
		$plan = $hide ? rk_builder_theme_hide_old( $prev, $bundle, false ) : array( 'pages' => array(), 'templates' => array(), 'posts' => array() );
		$report['hidden'] = array( 'from' => $hide && isset( rk_builder_themes_index()[ $prev ] ) ? rk_builder_themes_index()[ $prev ]['name'] : '', 'pages' => count( $plan['pages'] ), 'templates' => count( $plan['templates'] ), 'posts' => count( $plan['posts'] ) );
		unset( $report['touched'] );
		return rk_builder_no_store( $report );
	}
	$mine = array( 'pages' => array(), 'templates' => array(), 'posts' => isset( $report['touched']['posts'] ) ? array_map( 'intval', $report['touched']['posts'] ) : array() );
	foreach ( isset( $report['pages']['done'] ) ? $report['pages']['done'] : array() as $d ) { $mine['pages'][] = (int) $d['id']; }
	foreach ( isset( $report['templates']['done'] ) ? $report['templates']['done'] : array() as $d ) { $mine['templates'][] = (int) $d['id']; }
	update_option( 'rk_builder_theme_items_' . $slug, $mine, false );
	update_option( 'rk_builder_active_theme', $slug, false );
	$report['hidden'] = array( 'from' => '', 'pages' => 0, 'templates' => 0, 'posts' => 0 );

	$gone = array( 'pages' => array(), 'templates' => array(), 'posts' => array() );
	if ( $hide ) {
		// Hide after the new theme is in, so a page both themes share by address is replaced, never unpublished.
		$gone = rk_builder_theme_hide_old( $prev, $bundle, true );
		$report['hidden'] = array( 'from' => isset( rk_builder_themes_index()[ $prev ] ) ? rk_builder_themes_index()[ $prev ]['name'] : '', 'pages' => count( $gone['pages'] ), 'templates' => count( $gone['templates'] ), 'posts' => count( $gone['posts'] ) );
	}
	if ( $o['publish'] ) {
		$home_id = 0;
		$front_slug = isset( $bundle['site']['frontPage'] ) && is_string( $bundle['site']['frontPage'] ) && '' !== $bundle['site']['frontPage'] ? $bundle['site']['frontPage'] : 'home';
		foreach ( isset( $report['pages']['done'] ) ? $report['pages']['done'] : array() as $d ) {
			$pub = rk_builder_handle_publish( rk_builder_internal_request( 'POST', '/rk/v1/builder/publish/' . (int) $d['id'], array( 'id' => (int) $d['id'] ), array( 'expectedRevision' => (int) $d['revision'] ) ) );
			if ( is_wp_error( $pub ) ) {
				$report['warnings'][] = 'Could not publish "' . $d['slug'] . '": ' . $pub->get_error_message();
				continue;
			}
			$report['published']++;
			if ( $front_slug === $d['slug'] ) { $home_id = (int) $d['id']; }
		}
		$report['publishedTemplates'] = 0;
		foreach ( isset( $report['templates']['done'] ) ? $report['templates']['done'] : array() as $d ) {
			$pub = rk_builder_handle_publish( rk_builder_internal_request( 'POST', '/rk/v1/builder/publish/' . (int) $d['id'], array( 'id' => (int) $d['id'] ), array( 'expectedRevision' => (int) $d['revision'] ) ) );
			if ( is_wp_error( $pub ) ) {
				$report['warnings'][] = 'Could not publish the template "' . $d['slug'] . '": ' . $pub->get_error_message();
				continue;
			}
			$report['publishedTemplates']++;
			if ( 'loop' !== $d['kind'] && ! empty( $d['active'] ) ) { rk_builder_tpl_set_active( (int) $d['id'], true ); }
		}
		if ( $report['publishedTemplates'] > 0 ) { rk_builder_purge_all_public_cache(); }
		if ( $o['frontPage'] && $home_id > 0 && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $home_id );
			$report['frontPage'] = true;
		}
	} elseif ( $o['frontPage'] ) {
		$report['warnings'][] = 'The front page was not changed: it is only set when the pages are published.';
	}
	// "Pages were imported as drafts" no longer holds once they are live.
	if ( $o['publish'] && ! empty( $report['warnings'] ) ) {
		$report['warnings'] = array_values( array_filter( $report['warnings'], function ( $w ) { return false === strpos( $w, 'imported as drafts' ); } ) );
	}
	$meta_name = isset( rk_builder_themes_index()[ $slug ]['name'] ) ? rk_builder_themes_index()[ $slug ]['name'] : $slug;
	$report['undo'] = null !== $snap ? rk_builder_undo_finish( $snap, $report, $gone, $meta_name ) : null;
	unset( $report['touched'] );
	if ( null === $report['undo'] ) { $report['warnings'][] = 'A copy of the site from before the install could not be kept, so this install cannot be undone.'; }
	return rk_builder_no_store( $report );
}

function rk_builder_handle_theme_export( $req ) {
	$slug = (string) $req->get_param( 'slug' );
	$zip  = rk_builder_theme_kit_path( $slug );
	if ( '' !== $zip ) {
		$s = rk_builder_themes_index()[ $slug ];
		rk_builder_kit_stream_once( $req->get_route(), $zip, 'rk-kit-' . $slug . '-' . $s['version'] . '.zip' );
		return rk_builder_no_store( array( 'file' => $slug . '.zip', 'kit' => true ) );
	}
	$bundle = rk_builder_theme_load( $slug );
	return null === $bundle ? rk_builder_theme_not_found() : rk_builder_no_store( $bundle );
}

function rk_builder_handle_theme_delete( $req ) {
	$body = rk_builder_themes_body( $req, array( 'slug' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$slug  = isset( $body['slug'] ) ? $body['slug'] : null;
	$index = rk_builder_themes_index();
	if ( ! rk_builder_theme_slug_ok( $slug ) || ! isset( $index[ $slug ] ) ) { return rk_builder_theme_not_found(); }
	rk_builder_theme_kit_forget( $slug );
	delete_option( 'rk_builder_theme_items_' . $slug );
	if ( get_option( 'rk_builder_active_theme', '' ) === $slug ) { delete_option( 'rk_builder_active_theme' ); }
	unset( $index[ $slug ] );
	update_option( 'rk_builder_theme_index', $index, false );
	delete_option( 'rk_builder_theme_pkg_' . $slug );
	return rk_builder_no_store( array( 'deleted' => $slug ) );
}

/** Routes (called from rk_builder_register_routes()). */
function rk_builder_register_theme_routes( $ns ) {
	$perm = 'rk_builder_perm_site_transfer';
	register_rest_route( $ns, '/builder/themes', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_themes_list', 'permission_callback' => $perm ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_theme_capture', 'permission_callback' => $perm ),
	) );
	register_rest_route( $ns, '/builder/themes/import', array(
		'methods' => 'POST', 'callback' => 'rk_builder_handle_theme_import', 'permission_callback' => $perm,
	) );
	register_rest_route( $ns, '/builder/themes/install', array(
		'methods' => 'POST', 'callback' => 'rk_builder_handle_theme_install', 'permission_callback' => $perm,
	) );
	register_rest_route( $ns, '/builder/themes/export', array(
		'methods' => 'GET', 'callback' => 'rk_builder_handle_theme_export', 'permission_callback' => $perm,
	) );
	register_rest_route( $ns, '/builder/themes/delete', array(
		'methods' => 'POST', 'callback' => 'rk_builder_handle_theme_delete', 'permission_callback' => $perm,
	) );
	register_rest_route( $ns, '/builder/themes/undo', array(
		'methods' => 'POST', 'callback' => 'rk_builder_handle_theme_undo', 'permission_callback' => $perm,
	) );
	rk_builder_register_kit_routes( $ns );
	rk_builder_register_library_routes( $ns );
}
