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
		'content'     => isset( $bundle['content'] ) && is_array( $bundle['content'] ) ? count( $bundle['content'] ) : 0,
		'preview'     => rk_builder_theme_preview_url( $bundle ),
		'createdAt'   => isset( $bundle['themeMeta']['createdAt'] ) && is_string( $bundle['themeMeta']['createdAt'] ) ? $bundle['themeMeta']['createdAt'] : rk_builder_iso( rk_builder_now() ),
		'bytes'       => (int) $bytes,
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
	if ( strlen( $json ) > RK_BUILDER_MAX_IMPORT_BYTES ) {
		return rk_builder_error( 'rk_payload_too_large', 'The theme is larger than ' . ( RK_BUILDER_MAX_IMPORT_BYTES / 1048576 ) . ' MB.', 413 );
	}
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
	return rk_builder_no_store( array( 'items' => array_values( rk_builder_themes_index() ) ) );
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
	$body = rk_builder_themes_body( $req, array( 'name', 'description', 'version', 'author' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$meta = rk_builder_theme_meta_clean( $body );
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
	$o = array( 'dryRun' => false, 'theme' => true, 'content' => true, 'publish' => false, 'frontPage' => false );
	if ( isset( $body['options'] ) ) {
		if ( ! is_array( $body['options'] ) ) { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'options', 'message' => 'Expected object' ) ) ); }
		foreach ( $body['options'] as $k => $v ) {
			if ( array_key_exists( $k, $o ) && is_bool( $v ) ) { $o[ $k ] = $v; }
			else { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'options.' . $k, 'message' => 'Unrecognized or invalid option' ) ) ); }
		}
	}
	$bundle = rk_builder_theme_load( $slug );
	if ( null === $bundle ) { return rk_builder_theme_not_found(); }

	$result = rk_builder_site_import_run( $bundle, array(
		'dryRun'        => $o['dryRun'],
		'theme'         => $o['theme'],
		'content'       => $o['content'],
		'contentStatus' => $o['publish'] ? 'publish' : 'draft',
	) );
	if ( is_wp_error( $result ) ) { return $result; }
	$report = $result instanceof WP_REST_Response ? $result->get_data() : $result;
	$report['published'] = 0;
	$report['frontPage'] = false;
	if ( $o['dryRun'] ) { return rk_builder_no_store( $report ); }

	if ( $o['publish'] ) {
		$home_id = 0;
		foreach ( isset( $report['pages']['done'] ) ? $report['pages']['done'] : array() as $d ) {
			$pub = rk_builder_handle_publish( rk_builder_internal_request( 'POST', '/rk/v1/builder/publish/' . (int) $d['id'], array( 'id' => (int) $d['id'] ), array( 'expectedRevision' => (int) $d['revision'] ) ) );
			if ( is_wp_error( $pub ) ) {
				$report['warnings'][] = 'Could not publish "' . $d['slug'] . '": ' . $pub->get_error_message();
				continue;
			}
			$report['published']++;
			if ( 'home' === $d['slug'] ) { $home_id = (int) $d['id']; }
		}
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
	return rk_builder_no_store( $report );
}

function rk_builder_handle_theme_export( $req ) {
	$slug   = (string) $req->get_param( 'slug' );
	$bundle = rk_builder_theme_load( $slug );
	return null === $bundle ? rk_builder_theme_not_found() : rk_builder_no_store( $bundle );
}

function rk_builder_handle_theme_delete( $req ) {
	$body = rk_builder_themes_body( $req, array( 'slug' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$slug  = isset( $body['slug'] ) ? $body['slug'] : null;
	$index = rk_builder_themes_index();
	if ( ! rk_builder_theme_slug_ok( $slug ) || ! isset( $index[ $slug ] ) ) { return rk_builder_theme_not_found(); }
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
}
