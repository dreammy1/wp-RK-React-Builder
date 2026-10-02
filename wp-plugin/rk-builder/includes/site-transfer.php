<?php
/**
 * Whole-site export / import for administrators.
 *
 *   GET  /builder/site-export  → a JSON bundle: every page that has a builder layout (the working DRAFT), the site
 *                                theme, the media they use, and Service / Portfolio posts.
 *   POST /builder/site-import  → { bundle, options } checks the bundle and (unless dryRun) applies it.
 *
 * Safety rules:
 *   - Everything is validated with the same strict validators as the editor; an invalid page is skipped and reported,
 *     never half-imported.
 *   - Import never publishes. Pages arrive as drafts (existing pages keep their published version until you publish);
 *     content posts arrive as drafts unless the importer chooses "publish".
 *   - Media is copied INTO this site's media library (downloaded over http/https with WordPress' safe HTTP client,
 *     image types only), then layouts are rewritten to point at the local copies. Attachment IDs from another site
 *     are never trusted: an ID that could not be mapped is removed.
 *   - Re-importing the same bundle updates pages by slug and re-uses media (matched by source URL): no duplicates.
 *
 * The bundle shape is documented in SUITE.md / README.md. This file's pure helpers (rk_builder_bundle_*) have no
 * WordPress dependency so they are unit tested without WordPress.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_BUNDLE_FORMAT' ) ) { define( 'RK_BUILDER_BUNDLE_FORMAT', 'rk-builder-site' ); }
if ( ! defined( 'RK_BUILDER_MAX_IMPORT_BYTES' ) ) { define( 'RK_BUILDER_MAX_IMPORT_BYTES', 8 * 1024 * 1024 ); }
if ( ! defined( 'RK_BUILDER_MAX_TRANSFER_PAGES' ) ) { define( 'RK_BUILDER_MAX_TRANSFER_PAGES', 500 ); }
if ( ! defined( 'RK_BUILDER_MAX_TRANSFER_MEDIA' ) ) { define( 'RK_BUILDER_MAX_TRANSFER_MEDIA', 150 ); }
if ( ! defined( 'RK_BUILDER_MAX_TRANSFER_CONTENT' ) ) { define( 'RK_BUILDER_MAX_TRANSFER_CONTENT', 300 ); }

/* ------------------------------------------------------------------ *
 * Pure helpers (no WordPress calls)
 * ------------------------------------------------------------------ */

/**
 * Media references inside a layout and/or theme.
 *
 * @return array<int,array{id:?int,url:?string}>
 */
function rk_builder_bundle_media_refs( $layout, $theme = null ) {
	$refs = array();
	$add  = function ( $id, $url ) use ( &$refs ) {
		$id  = ( is_int( $id ) || ( is_float( $id ) && floor( $id ) === $id ) ) && $id > 0 ? (int) $id : null;
		$url = is_string( $url ) && '' !== $url ? $url : null;
		if ( null !== $id || null !== $url ) { $refs[] = array( 'id' => $id, 'url' => $url ); }
	};
	if ( is_array( $layout ) && isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ) {
		foreach ( $layout['blocks'] as $b ) {
			if ( ! is_array( $b ) || ! isset( $b['type'], $b['props'] ) || ! is_array( $b['props'] ) ) { continue; }
			$p = $b['props'];
			if ( 'image' === $b['type'] ) { $add( isset( $p['mediaId'] ) ? $p['mediaId'] : null, isset( $p['url'] ) ? $p['url'] : null ); }
			if ( 'hero' === $b['type'] ) { $add( isset( $p['bgMediaId'] ) ? $p['bgMediaId'] : null, isset( $p['bgUrl'] ) ? $p['bgUrl'] : null ); }
		}
	}
	if ( is_array( $theme ) ) { $add( isset( $theme['logoMediaId'] ) ? $theme['logoMediaId'] : null, isset( $theme['logoUrl'] ) ? $theme['logoUrl'] : null ); }
	return $refs;
}

/** Look up a media record by old attachment ID first, then by old URL. */
function rk_builder_bundle_lookup( array $maps, $id, $url ) {
	if ( is_int( $id ) && $id > 0 && isset( $maps['id'][ $id ] ) ) { return $maps['id'][ $id ]; }
	if ( is_string( $url ) && isset( $maps['url'][ $url ] ) ) { return $maps['url'][ $url ]; }
	return null;
}

/**
 * Point a layout's media at local copies. $maps = array( 'id' => old id => rec, 'url' => old url => rec ) where rec is
 * array( 'id' => new id, 'url' => new url, 'width' => ?int, 'height' => ?int ).
 * An attachment ID that has no mapping is REMOVED (it belongs to another site); the URL is kept.
 */
function rk_builder_bundle_remap_layout( array $layout, array $maps ) {
	if ( ! isset( $layout['blocks'] ) || ! is_array( $layout['blocks'] ) ) { return $layout; }
	foreach ( $layout['blocks'] as $i => $b ) {
		if ( ! is_array( $b ) || ! isset( $b['type'], $b['props'] ) || ! is_array( $b['props'] ) ) { continue; }
		$p = $b['props'];
		if ( 'image' === $b['type'] ) {
			$rec = rk_builder_bundle_lookup( $maps, isset( $p['mediaId'] ) && is_numeric( $p['mediaId'] ) ? (int) $p['mediaId'] : null, isset( $p['url'] ) ? $p['url'] : null );
			unset( $p['mediaId'], $p['srcset'] );
			if ( null !== $rec ) {
				$p['mediaId'] = (int) $rec['id'];
				$p['url']     = (string) $rec['url'];
				if ( ! empty( $rec['width'] ) ) { $p['width'] = (int) $rec['width']; }
				if ( ! empty( $rec['height'] ) ) { $p['height'] = (int) $rec['height']; }
			}
		} elseif ( 'hero' === $b['type'] ) {
			$rec = rk_builder_bundle_lookup( $maps, isset( $p['bgMediaId'] ) && is_numeric( $p['bgMediaId'] ) ? (int) $p['bgMediaId'] : null, isset( $p['bgUrl'] ) ? $p['bgUrl'] : null );
			unset( $p['bgMediaId'] );
			if ( null !== $rec ) {
				$p['bgMediaId'] = (int) $rec['id'];
				$p['bgUrl']     = (string) $rec['url'];
			}
		}
		$layout['blocks'][ $i ]['props'] = $p;
	}
	return $layout;
}

/**
 * Point reusable-block references at this site's library. $id_map: old id => new id. A reference that has no mapping
 * (the bundle did not carry that reusable) is removed, because the old id means something else on this site.
 */
function rk_builder_bundle_remap_reusables( array $layout, array $id_map, &$dropped ) {
	$dropped = 0;
	if ( ! isset( $layout['blocks'] ) || ! is_array( $layout['blocks'] ) ) { return $layout; }
	$blocks = array();
	foreach ( $layout['blocks'] as $b ) {
		if ( is_array( $b ) && isset( $b['type'] ) && 'reusable' === $b['type'] ) {
			$old = isset( $b['props']['refId'] ) && is_numeric( $b['props']['refId'] ) ? (int) $b['props']['refId'] : 0;
			if ( $old > 0 && isset( $id_map[ $old ] ) ) { $b['props']['refId'] = (int) $id_map[ $old ]; $blocks[] = $b; }
			else { $dropped++; }
			continue;
		}
		$blocks[] = $b;
	}
	$layout['blocks'] = $blocks;
	return $layout;
}

/** Same for the theme logo. */
function rk_builder_bundle_remap_theme( array $theme, array $maps ) {
	$rec = rk_builder_bundle_lookup( $maps, isset( $theme['logoMediaId'] ) && is_numeric( $theme['logoMediaId'] ) ? (int) $theme['logoMediaId'] : null, isset( $theme['logoUrl'] ) ? $theme['logoUrl'] : null );
	unset( $theme['logoMediaId'] );
	if ( null !== $rec ) {
		$theme['logoMediaId'] = (int) $rec['id'];
		$theme['logoUrl']     = (string) $rec['url'];
	}
	return $theme;
}

/** Hosts that appear in the bundle (source site + media URLs): needed to validate layouts before media is localised. */
function rk_builder_bundle_hosts( array $bundle ) {
	$hosts = array();
	$urls  = array();
	if ( isset( $bundle['source']['url'] ) && is_string( $bundle['source']['url'] ) ) { $urls[] = $bundle['source']['url']; }
	if ( isset( $bundle['media'] ) && is_array( $bundle['media'] ) ) {
		foreach ( $bundle['media'] as $m ) { if ( is_array( $m ) && isset( $m['url'] ) && is_string( $m['url'] ) ) { $urls[] = $m['url']; } }
	}
	foreach ( $urls as $u ) {
		$auth = rk_builder_parse_http_authority( $u );
		if ( null !== $auth ) {
			$hosts[] = $auth[0];
			if ( '' !== $auth[1] ) { $hosts[] = $auth[0] . ':' . $auth[1]; }
		}
	}
	return $hosts;
}

/**
 * Structural check of a bundle. Fatal problems only (wrong format, wrong types, over the limits); per-page and
 * per-item problems are found later and reported without aborting the import.
 *
 * @return array issues (empty when usable)
 */
function rk_builder_bundle_check_shape( $bundle ) {
	$issues = array();
	if ( ! rk_builder_is_object( $bundle ) ) {
		rk_builder_add_issue( $issues, '', 'Expected a JSON object' );
		return $issues;
	}
	if ( ! isset( $bundle['format'] ) || RK_BUILDER_BUNDLE_FORMAT !== $bundle['format'] ) {
		rk_builder_add_issue( $issues, 'format', 'Not an RK Builder site export (expected format "' . RK_BUILDER_BUNDLE_FORMAT . '")' );
	}
	if ( ! isset( $bundle['version'] ) || 1 !== $bundle['version'] ) {
		rk_builder_add_issue( $issues, 'version', 'Unsupported bundle version (this site reads version 1)' );
	}
	$limits = array( 'pages' => RK_BUILDER_MAX_TRANSFER_PAGES, 'media' => RK_BUILDER_MAX_TRANSFER_MEDIA, 'content' => RK_BUILDER_MAX_TRANSFER_CONTENT, 'reusables' => RK_BUILDER_MAX_REUSABLES );
	foreach ( $limits as $key => $max ) {
		if ( ! isset( $bundle[ $key ] ) ) { continue; }
		if ( ! is_array( $bundle[ $key ] ) || ( array() !== $bundle[ $key ] && rk_builder_is_object( $bundle[ $key ] ) ) ) {
			rk_builder_add_issue( $issues, $key, 'Expected an array' );
		} elseif ( count( $bundle[ $key ] ) > $max ) {
			rk_builder_add_issue( $issues, $key, 'At most ' . $max . ' item(s) per import' );
		}
	}
	return $issues;
}

/** Slug rule shared by pages and content posts. */
function rk_builder_bundle_slug_ok( $slug ) {
	return is_string( $slug ) && 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,199}\z/', $slug );
}

/** Usable media entries keyed numerically; bad entries are returned in $bad with a reason. */
function rk_builder_bundle_media_entries( array $bundle, array &$bad ) {
	$out  = array();
	$seen = array();
	$list = isset( $bundle['media'] ) && is_array( $bundle['media'] ) ? $bundle['media'] : array();
	foreach ( $list as $i => $m ) {
		$url = is_array( $m ) && isset( $m['url'] ) ? $m['url'] : null;
		if ( ! is_string( $url ) || null === rk_builder_parse_http_authority( $url ) || null !== rk_builder_image_url_problem( $url, null ) ) {
			$bad[] = array( 'url' => is_string( $url ) ? substr( $url, 0, 120 ) : '(missing)', 'reason' => 'Not a valid http(s) image URL' );
			continue;
		}
		if ( isset( $seen[ $url ] ) ) { continue; }
		$seen[ $url ] = true;
		$out[] = array(
			'id'    => isset( $m['id'] ) && rk_builder_is_intlike( $m['id'] ) && $m['id'] > 0 ? (int) $m['id'] : null,
			'url'   => $url,
			'alt'   => isset( $m['alt'] ) && is_string( $m['alt'] ) ? substr( $m['alt'], 0, 1200 ) : '',
			'title' => isset( $m['title'] ) && is_string( $m['title'] ) ? substr( $m['title'], 0, 200 ) : '',
		);
	}
	return $out;
}

/* ------------------------------------------------------------------ *
 * Export
 * ------------------------------------------------------------------ */

function rk_builder_handle_site_export( $req ) {
	$page_ids = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => RK_BUILDER_MAX_TRANSFER_PAGES + 1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'meta_key'       => '_rk_layout_draft',
		'meta_compare'   => 'EXISTS',
	) );
	if ( count( $page_ids ) > RK_BUILDER_MAX_TRANSFER_PAGES ) {
		return rk_builder_error( 'rk_payload_too_large', 'This site has more than ' . RK_BUILDER_MAX_TRANSFER_PAGES . ' builder pages; export is limited to that many.', 413 );
	}
	$theme   = rk_builder_get_theme();
	$pages   = array();
	$refs    = rk_builder_bundle_media_refs( null, $theme );
	foreach ( $page_ids as $pid ) {
		$post   = get_post( $pid );
		$layout = rk_builder_get_draft_layout( $pid );
		$pages[] = array(
			'slug'         => (string) $post->post_name,
			'title'        => rk_builder_plain( get_the_title( $post ) ),
			'wasPublished' => 'publish' === $post->post_status,
			'layout'       => $layout,
		);
		$refs = array_merge( $refs, rk_builder_bundle_media_refs( $layout ) );
	}

	$reusables = array();
	foreach ( rk_builder_reusable_list() as $r ) {
		$reusables[] = array( 'id' => $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'block' => $r['block'] );
		$refs = array_merge( $refs, rk_builder_bundle_media_refs( array( 'blocks' => array( array( 'type' => $r['block']['type'], 'props' => $r['block']['props'] ) ) ) ) );
	}

	$content   = array();
	$media_ids = array();
	foreach ( array( 'service', 'portfolio' ) as $type ) {
		if ( ! post_type_exists( $type ) ) { continue; }
		$posts = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => RK_BUILDER_MAX_TRANSFER_CONTENT, 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $posts as $p ) {
			$terms = wp_get_object_terms( $p->ID, $type . '_cat' );
			$thumb = (int) get_post_thumbnail_id( $p->ID );
			if ( $thumb > 0 ) { $media_ids[ $thumb ] = true; }
			$content[] = array(
				'type'     => $type,
				'slug'     => (string) $p->post_name,
				'title'    => rk_builder_plain( get_the_title( $p ) ),
				'status'   => (string) $p->post_status,
				'excerpt'  => (string) $p->post_excerpt,
				'content'  => (string) $p->post_content,
				'order'    => (int) $p->menu_order,
				'terms'    => is_array( $terms ) ? array_map( function ( $t ) { return array( 'slug' => (string) $t->slug, 'name' => (string) $t->name ); }, $terms ) : array(),
				'featured' => $thumb > 0 ? $thumb : null,
			);
		}
	}
	foreach ( $refs as $r ) { if ( null !== $r['id'] ) { $media_ids[ $r['id'] ] = true; } }

	$media = array();
	foreach ( array_keys( $media_ids ) as $mid ) {
		$item = rk_builder_media_item( (int) $mid );
		if ( null === $item ) { continue; }
		$media[] = array_intersect_key( $item, array_flip( array( 'id', 'url', 'alt', 'title', 'width', 'height' ) ) );
	}

	return rk_builder_no_store( array(
		'format'     => RK_BUILDER_BUNDLE_FORMAT,
		'version'    => 1,
		'exportedAt' => rk_builder_iso( rk_builder_now() ),
		'source'     => array( 'url' => home_url( '/' ), 'plugin' => RK_BUILDER_VERSION ),
		'theme'      => rk_builder_theme_for_output( $theme ),
		'media'      => $media,
		'reusables'  => $reusables,
		'pages'      => $pages,
		'content'    => $content,
	) );
}

/* ------------------------------------------------------------------ *
 * Import
 * ------------------------------------------------------------------ */

/** Find one reusable block by slug, or 0. */
function rk_builder_find_reusable_by_slug( $slug ) {
	$ids = get_posts( array( 'post_type' => RK_BUILDER_REUSABLE_TYPE, 'name' => $slug, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	return $ids ? (int) $ids[0] : 0;
}

/** Find one page by slug (any status), or 0. */
function rk_builder_find_page_by_slug( $slug ) {
	$ids = get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Copy one remote image into the media library (or re-use the copy from an earlier import).
 *
 * @return array|WP_Error rec array( id, url, width, height )
 */
function rk_builder_import_media_entry( array $m ) {
	$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_rk_import_source', 'meta_value' => $m['url'], 'posts_per_page' => 1, 'fields' => 'ids' ) );
	if ( $existing ) {
		$item = rk_builder_media_item( (int) $existing[0] );
		if ( null !== $item ) { return array( 'id' => $item['id'], 'url' => $item['url'], 'width' => isset( $item['width'] ) ? $item['width'] : null, 'height' => isset( $item['height'] ) ? $item['height'] : null, 'reused' => true ); }
	}
	if ( ! wp_http_validate_url( $m['url'] ) ) { return rk_builder_error( 'rk_invalid_media', 'The image URL is not allowed (private or malformed address).', 400 ); }
	rk_builder_load_media_includes();
	$tmp = download_url( $m['url'], 30 );
	if ( is_wp_error( $tmp ) ) { return rk_builder_error( 'rk_invalid_media', 'Could not download the image.', 502 ); }
	if ( (int) @filesize( $tmp ) > rk_builder_max_upload_bytes() ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return rk_builder_error( 'rk_payload_too_large', 'The image is larger than the upload limit.', 413 );
	}
	$path = (string) wp_parse_url( $m['url'], PHP_URL_PATH );
	$name = sanitize_file_name( basename( $path ) );
	$kind = rk_builder_check_image_file( $tmp, $name );
	if ( is_wp_error( $kind ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return $kind;
	}
	if ( '' === $name || ! preg_match( '/\.' . preg_quote( $kind['ext'], '/' ) . '\z/i', $name ) ) { $name = 'imported-image.' . $kind['ext']; }
	$id = rk_builder_create_attachment( array( 'name' => $name, 'tmp_name' => $tmp ), sanitize_text_field( $m['alt'] ), sanitize_text_field( $m['title'] ) );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return $id;
	}
	update_post_meta( $id, '_rk_import_source', $m['url'] );
	$item = rk_builder_media_item( $id );
	if ( null === $item ) { return rk_builder_error( 'rk_server_error', 'The image was saved but could not be read back.', 500 ); }
	return array( 'id' => $item['id'], 'url' => $item['url'], 'width' => isset( $item['width'] ) ? $item['width'] : null, 'height' => isset( $item['height'] ) ? $item['height'] : null, 'reused' => false );
}

/** Set a post's terms by slug/name, creating missing terms. */
function rk_builder_import_terms( $post_id, $taxonomy, $terms ) {
	if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $terms ) ) { return; }
	$ids = array();
	foreach ( $terms as $t ) {
		if ( ! is_array( $t ) || ! isset( $t['slug'] ) || ! rk_builder_bundle_slug_ok( $t['slug'] ) ) { continue; }
		$found = get_term_by( 'slug', $t['slug'], $taxonomy );
		if ( ! $found ) {
			$name  = isset( $t['name'] ) && is_string( $t['name'] ) && '' !== trim( $t['name'] ) ? sanitize_text_field( $t['name'] ) : $t['slug'];
			$added = wp_insert_term( $name, $taxonomy, array( 'slug' => $t['slug'] ) );
			if ( is_wp_error( $added ) ) { continue; }
			$ids[] = (int) $added['term_id'];
		} else {
			$ids[] = (int) $found->term_id;
		}
	}
	wp_set_object_terms( $post_id, $ids, $taxonomy );
}

function rk_builder_handle_site_import( $req ) {
	if ( strlen( (string) $req->get_body() ) > RK_BUILDER_MAX_IMPORT_BYTES ) {
		return rk_builder_error( 'rk_payload_too_large', 'The import file is larger than ' . ( RK_BUILDER_MAX_IMPORT_BYTES / 1048576 ) . ' MB.', 413 );
	}
	$body = rk_builder_json_body( $req, 'rk_invalid_bundle' );
	if ( is_wp_error( $body ) ) { return $body; }
	$extra = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'bundle', 'options' ), true ) ) { rk_builder_add_issue( $extra, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	if ( ! isset( $body['bundle'] ) ) { rk_builder_add_issue( $extra, 'bundle', 'Required' ); }
	if ( $extra ) { return rk_builder_invalid( 'rk_invalid_bundle', $extra ); }

	$opts = array( 'dryRun' => true, 'theme' => false, 'content' => false, 'contentStatus' => 'draft' );
	if ( isset( $body['options'] ) ) {
		if ( ! rk_builder_is_object( $body['options'] ) && array() !== $body['options'] ) { return rk_builder_invalid( 'rk_invalid_bundle', array( array( 'path' => 'options', 'message' => 'Expected object' ) ) ); }
		foreach ( $body['options'] as $k => $v ) {
			if ( in_array( $k, array( 'dryRun', 'theme', 'content' ), true ) && is_bool( $v ) ) { $opts[ $k ] = $v; }
			elseif ( 'contentStatus' === $k && in_array( $v, array( 'draft', 'publish' ), true ) ) { $opts[ $k ] = $v; }
			else { return rk_builder_invalid( 'rk_invalid_bundle', array( array( 'path' => 'options.' . $k, 'message' => 'Unrecognized or invalid option' ) ) ); }
		}
	}
	$bundle = $body['bundle'];
	$shape  = rk_builder_bundle_check_shape( $bundle );
	if ( $shape ) { return rk_builder_invalid( 'rk_invalid_bundle', $shape, 'This is not a usable RK Builder site export.' ); }
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors

	$real_hosts = rk_builder_allowed_image_hosts();
	$pre_hosts  = array_merge( $real_hosts, rk_builder_bundle_hosts( $bundle ) );
	$warnings   = array();

	/* 1 · pages: validate each layout independently */
	$pages_in = isset( $bundle['pages'] ) && is_array( $bundle['pages'] ) ? $bundle['pages'] : array();
	$ok_pages = array();
	$skipped  = array();
	$slugs    = array();
	foreach ( $pages_in as $i => $pg ) {
		$slug = is_array( $pg ) && isset( $pg['slug'] ) ? $pg['slug'] : null;
		$label = is_string( $slug ) ? $slug : '#' . $i;
		if ( ! rk_builder_bundle_slug_ok( $slug ) ) { $skipped[] = array( 'slug' => $label, 'issues' => array( 'Invalid slug' ) ); continue; }
		if ( isset( $slugs[ $slug ] ) ) { $skipped[] = array( 'slug' => $slug, 'issues' => array( 'Duplicate slug in the file' ) ); continue; }
		$slugs[ $slug ] = true;
		$title = isset( $pg['title'] ) && is_string( $pg['title'] ) ? trim( $pg['title'] ) : '';
		if ( '' === $title || rk_builder_strlen( $title ) > 200 ) { $skipped[] = array( 'slug' => $slug, 'issues' => array( 'Title must be 1-200 characters' ) ); continue; }
		$problems = isset( $pg['layout'] ) ? rk_builder_validate_layout( $pg['layout'], $pre_hosts ) : array( array( 'path' => 'layout', 'message' => 'Required' ) );
		if ( $problems ) {
			$skipped[] = array( 'slug' => $slug, 'issues' => array_map( function ( $p ) { return $p['path'] . ': ' . $p['message']; }, array_slice( $problems, 0, 5 ) ) );
			continue;
		}
		$ok_pages[] = array( 'slug' => $slug, 'title' => sanitize_text_field( $title ), 'layout' => rk_builder_canonicalize_layout( $pg['layout'] ), 'wasPublished' => ! empty( $pg['wasPublished'] ) );
	}

	/* 1b · reusable blocks (a page that uses one needs it imported first) */
	$ok_reusables = array();
	$re_skipped   = array();
	$re_slugs     = array();
	$re_in        = isset( $bundle['reusables'] ) && is_array( $bundle['reusables'] ) ? $bundle['reusables'] : array();
	foreach ( $re_in as $i => $re ) {
		$slug = is_array( $re ) && isset( $re['slug'] ) ? $re['slug'] : null;
		$label = is_string( $slug ) ? $slug : '#' . $i;
		$name  = is_array( $re ) && isset( $re['name'] ) && is_string( $re['name'] ) ? trim( $re['name'] ) : '';
		$old   = is_array( $re ) && isset( $re['id'] ) && rk_builder_is_intlike( $re['id'] ) && $re['id'] > 0 ? (int) $re['id'] : 0;
		if ( ! rk_builder_bundle_slug_ok( $slug ) || isset( $re_slugs[ $slug ] ) || '' === $name || rk_builder_strlen( $name ) > 80 || $old < 1 ) {
			$re_skipped[] = array( 'slug' => $label, 'issues' => array( 'Invalid or duplicate reusable block entry' ) );
			continue;
		}
		$problems = isset( $re['block'] ) ? rk_builder_reusable_block_issues( $re['block'], $pre_hosts ) : array( array( 'path' => 'block', 'message' => 'Required' ) );
		if ( $problems ) {
			$re_skipped[] = array( 'slug' => $slug, 'issues' => array_map( function ( $p ) { return $p['path'] . ': ' . $p['message']; }, array_slice( $problems, 0, 5 ) ) );
			continue;
		}
		$re_slugs[ $slug ] = true;
		$ok_reusables[]    = array( 'oldId' => $old, 'slug' => $slug, 'name' => sanitize_text_field( $name ), 'block' => rk_builder_reusable_canonical_block( $re['block'] ) );
	}
	$re_existing = 0;
	foreach ( $ok_reusables as $re ) { if ( rk_builder_find_reusable_by_slug( $re['slug'] ) > 0 ) { $re_existing++; } }

	/* 2 · theme */
	$theme_in = null;
	if ( $opts['theme'] && isset( $bundle['theme'] ) ) {
		$tp = rk_builder_validate_theme( $bundle['theme'], $pre_hosts );
		if ( $tp ) { $warnings[] = 'The theme in the file is not valid and was not imported.'; } else { $theme_in = rk_builder_canonicalize_theme( $bundle['theme'] ); }
	}

	/* 3 · media (only what the importable pages / theme / content actually use is needed, but copying the listed set is simpler and predictable) */
	$bad_media = array();
	$entries   = rk_builder_bundle_media_entries( $bundle, $bad_media );

	/* 4 · content */
	$content_in = array();
	if ( $opts['content'] && isset( $bundle['content'] ) && is_array( $bundle['content'] ) ) {
		foreach ( $bundle['content'] as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['type'], $c['slug'], $c['title'] ) || ! in_array( $c['type'], array( 'service', 'portfolio' ), true ) || ! post_type_exists( $c['type'] ) || ! rk_builder_bundle_slug_ok( $c['slug'] ) || ! is_string( $c['title'] ) || '' === trim( $c['title'] ) ) {
				$warnings[] = 'A content item was skipped because it is not valid.';
				continue;
			}
			$content_in[] = $c;
		}
	}

	/* dry run: report what would happen */
	$existing_pages = 0;
	foreach ( $ok_pages as $pg ) { if ( rk_builder_find_page_by_slug( $pg['slug'] ) > 0 ) { $existing_pages++; } }
	$report = array(
		'dryRun'   => (bool) $opts['dryRun'],
		'pages'    => array( 'create' => count( $ok_pages ) - $existing_pages, 'update' => $existing_pages, 'skipped' => $skipped, 'done' => array() ),
		'media'    => array( 'total' => count( $entries ), 'imported' => 0, 'reused' => 0, 'failed' => $bad_media ),
		'reusables' => array( 'create' => count( $ok_reusables ) - $re_existing, 'update' => $re_existing, 'skipped' => $re_skipped ),
		'theme'    => array( 'included' => null !== $theme_in, 'applied' => false ),
		'content'  => array( 'included' => count( $content_in ), 'created' => 0, 'updated' => 0 ),
		'warnings' => $warnings,
	);
	if ( $opts['dryRun'] ) {
		$already = 0;
		foreach ( $entries as $m ) {
			$have = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_rk_import_source', 'meta_value' => $m['url'], 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( $have ) { $already++; }
		}
		$report['media']['reused']   = $already;
		$report['media']['imported'] = count( $entries ) - $already;
		return rk_builder_no_store( $report );
	}

	/* 5 · apply: media first so layouts can be rewritten */
	$maps = array( 'id' => array(), 'url' => array() );
	foreach ( $entries as $m ) {
		$rec = rk_builder_import_media_entry( $m );
		if ( is_wp_error( $rec ) ) {
			$report['media']['failed'][] = array( 'url' => substr( $m['url'], 0, 120 ), 'reason' => $rec->get_error_message() );
			continue;
		}
		$report['media'][ $rec['reused'] ? 'reused' : 'imported' ]++;
		if ( null !== $m['id'] ) { $maps['id'][ $m['id'] ] = $rec; }
		$maps['url'][ $m['url'] ] = $rec;
	}
	if ( $report['media']['failed'] ) { $report['warnings'][] = 'Some images could not be copied; those blocks keep their original image address.'; }

	/* reusable blocks: update by slug or create; old id => new id */
	$re_map = array();
	$report['reusables']['create'] = 0;
	$report['reusables']['update'] = 0;
	foreach ( $ok_reusables as $re ) {
		$wrapped = rk_builder_bundle_remap_layout( array( 'blocks' => array( array( 'type' => $re['block']['type'], 'props' => $re['block']['props'] ) ) ), $maps );
		$block   = array( 'type' => $re['block']['type'], 'props' => $wrapped['blocks'][0]['props'] );
		$problems = rk_builder_reusable_block_issues( $block, $real_hosts );
		if ( $problems ) {
			$report['reusables']['skipped'][] = array( 'slug' => $re['slug'], 'issues' => array_map( function ( $p ) { return $p['path'] . ': ' . $p['message']; }, array_slice( $problems, 0, 5 ) ) );
			continue;
		}
		$block = rk_builder_reusable_canonical_block( $block );
		$rid   = rk_builder_find_reusable_by_slug( $re['slug'] );
		if ( $rid > 0 ) {
			wp_update_post( array( 'ID' => $rid, 'post_title' => $re['name'] ) );
			$report['reusables']['update']++;
		} else {
			$rid = wp_insert_post( array( 'post_type' => RK_BUILDER_REUSABLE_TYPE, 'post_status' => 'publish', 'post_title' => $re['name'], 'post_name' => $re['slug'] ), true );
			if ( is_wp_error( $rid ) || ! $rid ) { $report['reusables']['skipped'][] = array( 'slug' => $re['slug'], 'issues' => array( 'Could not create it' ) ); continue; }
			$report['reusables']['create']++;
		}
		rk_builder_write_json_meta( (int) $rid, '_rk_reusable_block', $block );
		$re_map[ $re['oldId'] ] = (int) $rid;
	}

	/* pages (drafts only) */
	foreach ( $ok_pages as $pg ) {
		$layout = rk_builder_bundle_remap_layout( $pg['layout'], $maps );
		$dropped = 0;
		$layout  = rk_builder_bundle_remap_reusables( $layout, $re_map, $dropped );
		if ( $dropped > 0 ) { $report['warnings'][] = '"' . $pg['slug'] . '": ' . $dropped . ' reusable block reference(s) were removed because the file does not include that block.'; }
		$final  = rk_builder_validate_layout( $layout, $real_hosts );
		if ( $final ) {
			$why = array_map( function ( $p ) { return $p['path'] . ': ' . $p['message']; }, array_slice( $final, 0, 5 ) );
			if ( false !== strpos( implode( ' ', $why ), 'Image host is not allowed' ) ) {
				$why[] = 'An image could not be copied. Fix it, or allow its host under Settings > RK Builder > Allowed image hosts, then import again.';
			}
			$report['pages']['skipped'][] = array( 'slug' => $pg['slug'], 'issues' => $why );
			continue;
		}
		$layout = rk_builder_canonicalize_layout( $layout );
		$id     = rk_builder_find_page_by_slug( $pg['slug'] );
		$action = 'updated';
		if ( $id < 1 ) {
			$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $pg['title'], 'post_name' => $pg['slug'] ), true );
			if ( is_wp_error( $id ) || ! $id ) {
				$report['pages']['skipped'][] = array( 'slug' => $pg['slug'], 'issues' => array( 'Could not create the page' ) );
				continue;
			}
			$action = 'created';
		}
		$commit = rk_builder_with_lock( (int) $id, function () use ( $id, $layout ) {
			return rk_builder_commit_revision( (int) $id, 'draft', $layout );
		} );
		if ( is_wp_error( $commit ) ) {
			$report['pages']['skipped'][] = array( 'slug' => $pg['slug'], 'issues' => array( $commit->get_error_message() ) );
			continue;
		}
		$report['pages']['done'][] = array( 'slug' => $pg['slug'], 'id' => (int) $id, 'action' => $action, 'revision' => $commit['revision'], 'link' => (string) get_permalink( $id ) );
	}
	$report['pages']['create'] = count( array_filter( $report['pages']['done'], function ( $d ) { return 'created' === $d['action']; } ) );
	$report['pages']['update'] = count( $report['pages']['done'] ) - $report['pages']['create'];

	/* theme */
	if ( null !== $theme_in ) {
		$theme = rk_builder_bundle_remap_theme( $theme_in, $maps );
		if ( array() === rk_builder_validate_theme( $theme, $real_hosts ) ) {
			rk_builder_store_theme( $theme );
			rk_builder_revalidate( 'theme', 0, '' );
			rk_builder_theme_changed();
			$report['theme']['applied'] = true;
		} else {
			$report['warnings'][] = 'The theme could not be applied after its logo was copied; it was left unchanged.';
		}
	}

	/* content */
	foreach ( $content_in as $c ) {
		$existing = get_posts( array( 'post_type' => $c['type'], 'name' => $c['slug'], 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$data = array(
			'post_type'    => $c['type'],
			'post_title'   => sanitize_text_field( $c['title'] ),
			'post_name'    => $c['slug'],
			'post_excerpt' => isset( $c['excerpt'] ) && is_string( $c['excerpt'] ) ? wp_kses_post( $c['excerpt'] ) : '',
			'post_content' => isset( $c['content'] ) && is_string( $c['content'] ) ? wp_kses_post( $c['content'] ) : '',
			'menu_order'   => isset( $c['order'] ) && is_int( $c['order'] ) ? $c['order'] : 0,
		);
		if ( $existing ) { $data['ID'] = (int) $existing[0]; $pid = wp_update_post( $data, true ); }
		else { $data['post_status'] = $opts['contentStatus']; $pid = wp_insert_post( $data, true ); }
		if ( is_wp_error( $pid ) || ! $pid ) { $report['warnings'][] = 'Could not import "' . $c['slug'] . '".'; continue; }
		$report['content'][ $existing ? 'updated' : 'created' ]++;
		rk_builder_import_terms( (int) $pid, $c['type'] . '_cat', isset( $c['terms'] ) ? $c['terms'] : array() );
		if ( isset( $c['featured'] ) ) {
			$rec = rk_builder_bundle_lookup( $maps, is_int( $c['featured'] ) ? $c['featured'] : null, null );
			if ( null !== $rec ) { set_post_thumbnail( (int) $pid, (int) $rec['id'] ); }
		}
	}
	if ( $report['pages']['done'] ) { $report['warnings'][] = 'Pages were imported as drafts. Review them, then publish.'; }
	return rk_builder_no_store( $report );
}
