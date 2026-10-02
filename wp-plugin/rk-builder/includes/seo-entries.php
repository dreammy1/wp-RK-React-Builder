<?php
/**
 * Search and social output for template-drawn entries and listing pages (the page equivalent is seo.php).
 *
 *   - An entry has the same four fields as a page (search title, description, social image, noindex), stored in the
 *     same `_rk_seo_*` post meta; each content type picks a schema type (WebPage, Article or Service).
 *   - A listing (archive) uses the type's own archive title and description.
 *   - Tags are printed only for requests a template draws, and only when no SEO plugin owns the output.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** First `$max` characters of a text, cut at a word, with an ellipsis. */
function rk_builder_dyn_clip( $text, $max ) {
	$text = trim( (string) preg_replace( '/\s+/u', ' ', rk_builder_plain( $text ) ) );
	if ( rk_builder_strlen( $text ) <= $max ) { return $text; }
	$cut = rk_builder_substr( $text, 0, $max - 1 );
	$sp  = strrpos( $cut, ' ' );
	return rtrim( false !== $sp && $sp > $max * 0.6 ? substr( $cut, 0, $sp ) : $cut, " ,;:-" ) . '…';
}

/**
 * Title, description, canonical, image and robots for the request a template draws, or null.
 *
 * @return array|null title, description, canonical, image, site_name, locale, noindex, kind, type
 */
function rk_builder_dyn_seo_data() {
	$req = rk_builder_dyn_request();
	if ( ! $req ) { return null; }
	$org = rk_builder_seo_organization();
	$def = rk_builder_dyn_type( $req['type'] );
	$out = array( 'site_name' => (string) get_bloginfo( 'name' ), 'locale' => (string) get_locale(), 'kind' => $req['kind'], 'type' => $req['type'], 'noindex' => false );
	if ( 'single' === $req['kind'] ) {
		$post  = $req['post'];
		$seo   = rk_builder_seo_read( (int) $post->ID );
		$title = isset( $seo['title'] ) ? $seo['title'] : rk_builder_plain( get_the_title( $post ) );
		$desc  = isset( $seo['description'] ) ? $seo['description'] : rk_builder_dyn_clip( rk_builder_excerpt( $post ), 160 );
		$canon = apply_filters( 'rk_builder_canonical_url', (string) get_permalink( $post->ID ), (int) $post->ID );
		$image = isset( $seo['image'] ) ? $seo['image'] : '';
		if ( '' === $image ) {
			$f = rk_builder_featured_image( $post->ID );
			$image = $f ? $f['url'] : ( isset( $org['defaultImage'] ) ? $org['defaultImage'] : '' );
		}
		$out['noindex'] = ! empty( $seo['noindex'] );
		$out['has_title'] = isset( $seo['title'] );
		$out['post'] = $post;
	} else {
		$term  = isset( $req['term'] ) && is_object( $req['term'] ) ? $req['term'] : null;
		$title = $term && isset( $term->name ) ? (string) $term->name : ( $def && '' !== $def['archiveTitle'] ? $def['archiveTitle'] : ( $def ? $def['plural'] : '' ) );
		$desc  = $term && ! empty( $term->description ) ? rk_builder_dyn_clip( $term->description, 160 ) : ( $def ? $def['archiveDescription'] : '' );
		$link  = $term ? get_term_link( $term ) : get_post_type_archive_link( $req['type'] );
		$canon = ! is_wp_error( $link ) && is_string( $link ) ? $link : '';
		$image = isset( $org['defaultImage'] ) ? $org['defaultImage'] : '';
		$out['has_title'] = ! $term && $def && '' !== $def['archiveTitle'];
	}
	$out['title']       = trim( rk_builder_plain( $title ) );
	$out['description'] = trim( rk_builder_plain( $desc ) );
	$out['canonical']   = rk_builder_absolute_url( $canon );
	$out['image']       = rk_builder_absolute_url( $image );
	return $out;
}

/** Schema graph for an entry or a listing: site nodes, the page node, the type's node, breadcrumbs. */
function rk_builder_dyn_seo_graph( array $d ) {
	$nodes = rk_builder_seo_site_nodes( $d );
	$graph = $nodes['graph'];
	$org   = $nodes['ids']['org'];
	$home  = $nodes['ids']['home'];
	$url   = '' !== $d['canonical'] ? $d['canonical'] : $home;
	$def   = rk_builder_dyn_type( $d['type'] );
	$wp = array( '@type' => 'single' === $d['kind'] ? 'WebPage' : 'CollectionPage', '@id' => $url . '#webpage', 'url' => $url, 'name' => $d['title'] );
	if ( '' !== $d['description'] ) { $wp['description'] = $d['description']; }
	if ( '' !== $d['image'] ) { $wp['primaryImageOfPage'] = array( '@type' => 'ImageObject', 'url' => $d['image'] ); }
	$wp['isPartOf'] = array( '@id' => $nodes['ids']['site'] );
	$wp['about']    = array( '@id' => $org );
	$graph[] = $wp;
	$crumbs = array( array( 'Home', $home ) );
	if ( 'single' === $d['kind'] ) {
		$post = $d['post'];
		$type = $def ? $def['schema'] : 'WebPage';
		if ( 'Article' === $type ) {
			$a = array( '@type' => 'Article', '@id' => $url . '#article', 'headline' => rk_builder_dyn_clip( $d['title'], 110 ), 'url' => $url, 'datePublished' => get_the_date( 'c', $post ), 'dateModified' => get_the_modified_date( 'c', $post ), 'author' => array( '@id' => $org ), 'publisher' => array( '@id' => $org ), 'mainEntityOfPage' => array( '@id' => $url . '#webpage' ) );
			if ( '' !== $d['description'] ) { $a['description'] = $d['description']; }
			if ( '' !== $d['image'] ) { $a['image'] = array( $d['image'] ); }
			$graph[] = $a;
		} elseif ( 'Service' === $type ) {
			$s = array( '@type' => 'Service', '@id' => $url . '#service', 'name' => $d['title'], 'url' => $url, 'provider' => array( '@id' => $org ), 'mainEntityOfPage' => array( '@id' => $url . '#webpage' ) );
			if ( '' !== $d['description'] ) { $s['description'] = $d['description']; }
			if ( '' !== $d['image'] ) { $s['image'] = $d['image']; }
			$graph[] = $s;
		}
		$archive = $def && $def['hasArchive'] && $def['public'] ? get_post_type_archive_link( $d['type'] ) : '';
		if ( is_string( $archive ) && '' !== $archive ) { $crumbs[] = array( '' !== $def['archiveTitle'] ? $def['archiveTitle'] : $def['plural'], rk_builder_absolute_url( $archive ) ); }
		$crumbs[] = array( rk_builder_plain( get_the_title( $post ) ), $url );
	} else {
		$crumbs[] = array( $d['title'], $url );
	}
	$items = array();
	foreach ( $crumbs as $i => $c ) { $items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] ); }
	$graph[] = array( '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items );
	return array( '@context' => 'https://schema.org', '@graph' => $graph );
}

function rk_builder_dyn_seo_print_head() {
	if ( ! rk_builder_seo_owns_output() ) { return; }
	$d = rk_builder_dyn_seo_data();
	if ( ! $d ) { return; }
	remove_action( 'wp_head', 'rel_canonical' ); // printed (filterable) below
	echo rk_builder_seo_head_html( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per value
}

function rk_builder_dyn_seo_print_schema() {
	if ( ! rk_builder_seo_owns_output() || class_exists( '\\RK\\SEO\\Schema', false ) ) { return; }
	$d = rk_builder_dyn_seo_data();
	if ( ! $d || $d['noindex'] ) { return; }
	echo '<script type="application/ld+json">' . wp_json_encode( rk_builder_dyn_seo_graph( $d ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, tag-safe
}

/** `<title>`: a custom search title (or archive title) replaces WordPress's own and gets the site name after a "|". */
function rk_builder_dyn_seo_title_parts( $parts ) {
	if ( ! is_array( $parts ) || ! rk_builder_seo_owns_output() ) { return $parts; }
	$d = rk_builder_dyn_seo_data();
	if ( ! $d || '' === $d['title'] ) { return $parts; }
	if ( 'single' === $d['kind'] || ! empty( $d['has_title'] ) ) {
		$parts['title'] = $d['title'];
		if ( ! empty( $d['has_title'] ) && empty( $parts['site'] ) ) { $parts['site'] = (string) get_bloginfo( 'name' ); unset( $parts['tagline'] ); }
	}
	return $parts;
}

function rk_builder_dyn_seo_separator( $sep ) {
	if ( ! rk_builder_seo_owns_output() ) { return $sep; }
	$d = rk_builder_dyn_seo_data();
	return $d && ! empty( $d['has_title'] ) ? '|' : $sep;
}

function rk_builder_dyn_seo_robots( $robots ) {
	if ( ! is_array( $robots ) || ! rk_builder_seo_owns_output() ) { return $robots; }
	$d = rk_builder_dyn_seo_data();
	if ( $d && $d['noindex'] ) {
		unset( $robots['index'], $robots['max-image-preview'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['nofollow'] );
	}
	return $robots;
}

add_action( 'wp_head', 'rk_builder_dyn_seo_print_head', 1 );
add_action( 'wp_head', 'rk_builder_dyn_seo_print_schema', 20 );
add_filter( 'document_title_parts', 'rk_builder_dyn_seo_title_parts', 20 );
add_filter( 'document_title_separator', 'rk_builder_dyn_seo_separator', 20 );
add_filter( 'wp_robots', 'rk_builder_dyn_seo_robots', 20 );

/* ------------------------------------------------------------------ *
 * Sitemap (WordPress's own wp-sitemap.xml already lists every public post type, so custom types appear by themselves)
 *   - entries and pages marked noindex are left out
 *   - every URL gets a lastmod date
 *   - a type's listing page is added to its first sitemap when a live listing template draws it
 * Nothing changes when an SEO plugin owns the output (it has its own sitemap) or the site discourages search engines.
 * ------------------------------------------------------------------ */

function rk_builder_dyn_sitemap_query_args( $args, $post_type = '' ) {
	if ( ! is_array( $args ) || ! rk_builder_seo_owns_output() ) { return $args; }
	$mq   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
	$mq[] = array(
		'relation' => 'OR',
		array( 'key' => '_rk_seo_noindex', 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_rk_seo_noindex', 'value' => '1', 'compare' => '!=' ),
	);
	$args['meta_query'] = $mq;
	return $args;
}

function rk_builder_dyn_sitemap_entry( $entry, $post = null ) {
	if ( ! is_array( $entry ) || ! is_object( $post ) || ! rk_builder_seo_owns_output() ) { return $entry; }
	if ( empty( $entry['lastmod'] ) && ! empty( $post->post_modified_gmt ) && 0 !== strpos( (string) $post->post_modified_gmt, '0000' ) ) {
		$ts = strtotime( $post->post_modified_gmt . ' UTC' );
		if ( false !== $ts ) { $entry['lastmod'] = gmdate( 'c', $ts ); }
	}
	return $entry;
}

/** The listing URL of a content type that has a live archive template, or ''. */
function rk_builder_dyn_sitemap_archive_url( $post_type ) {
	$def = rk_builder_dyn_type( (string) $post_type );
	if ( ! $def || $def['builtin'] || ! $def['public'] || ! $def['hasArchive'] || null === rk_builder_tpl_find( 'archive', $def['slug'] ) ) { return ''; }
	$u = get_post_type_archive_link( $def['slug'] );
	return is_string( $u ) ? $u : '';
}

/** Adds the listing page to page 1 of the type's sitemap (the type's own entries are produced by core). */
function rk_builder_dyn_sitemap_pre_url_list( $list, $post_type, $page_num ) {
	static $busy = false;
	if ( null !== $list || $busy || 1 !== (int) $page_num || ! rk_builder_seo_owns_output() || ! class_exists( 'WP_Sitemaps_Posts' ) ) { return $list; }
	$url = rk_builder_dyn_sitemap_archive_url( $post_type );
	if ( '' === $url ) { return $list; }
	$busy = true;
	try {
		$core = ( new WP_Sitemaps_Posts() )->get_url_list( 1, $post_type );
	} finally {
		$busy = false;
	}
	array_unshift( $core, array( 'loc' => $url ) );
	return $core;
}

add_filter( 'wp_sitemaps_posts_query_args', 'rk_builder_dyn_sitemap_query_args', 20, 2 );
add_filter( 'wp_sitemaps_posts_entry', 'rk_builder_dyn_sitemap_entry', 20, 2 );
add_filter( 'wp_sitemaps_posts_pre_url_list', 'rk_builder_dyn_sitemap_pre_url_list', 20, 3 );
