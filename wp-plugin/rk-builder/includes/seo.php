<?php
/**
 * SEO output for published RK pages (spec §15): <title> part, meta description, canonical, Open Graph,
 * Twitter card. Preview documents carry `noindex, nofollow` (see preview.php).
 *
 * When a known SEO plugin is active it owns all of this, and we output nothing and change nothing
 * (no duplicate title/meta/OG/canonical). When we do output, values pass through these filters:
 *   rk_builder_seo_title( $title, $page_id )          rk_builder_seo_description( $text, $page_id )
 *   rk_builder_canonical_url( $url, $page_id )        rk_builder_og_image( $url, $page_id )
 * plus rk_builder_active_seo_plugin( $slug ) to declare an unlisted SEO plugin.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Slug of the active SEO plugin that owns the tags, or ''. */
function rk_builder_active_seo_plugin() {
	$found = '';
	if ( defined( 'WPSEO_VERSION' ) ) { $found = 'yoast'; }
	elseif ( defined( 'RANK_MATH_VERSION' ) ) { $found = 'rank-math'; }
	elseif ( defined( 'SEOPRESS_VERSION' ) ) { $found = 'seopress'; }
	elseif ( defined( 'AIOSEO_VERSION' ) ) { $found = 'aioseo'; }
	elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) || class_exists( 'The_SEO_Framework\\Load' ) ) { $found = 'seo-framework'; }
	$found = apply_filters( 'rk_builder_active_seo_plugin', $found );
	return is_string( $found ) ? $found : '';
}

/** True when RK (not a SEO plugin) outputs the tags. */
function rk_builder_seo_owns_output() {
	return '' === rk_builder_active_seo_plugin();
}

/* ------------------------------------------------------------------ *
 * Per-page SEO fields. Stored as post meta using RK SEO's own keys, so both read the same values.
 * ------------------------------------------------------------------ */

function rk_builder_substr( $s, $start, $len ) {
	return function_exists( 'mb_substr' ) ? mb_substr( $s, $start, $len, 'UTF-8' ) : substr( $s, $start, $len );
}

function rk_builder_seo_meta_keys() {
	return array(
		'title'       => '_rk_seo_title',
		'description' => '_rk_seo_desc',
		'image'       => '_rk_seo_og_image',
		'noindex'     => '_rk_seo_noindex',
		'service'     => '_rk_builder_seo_service',
		'parent'      => '_rk_builder_seo_parent',
	);
}

/** @return array<string,string> */
function rk_builder_seo_read( $id ) {
	$out = array();
	foreach ( rk_builder_seo_meta_keys() as $field => $key ) {
		$v = get_post_meta( (int) $id, $key, true );
		if ( is_string( $v ) && '' !== $v ) { $out[ $field ] = $v; }
	}
	if ( isset( $out['noindex'] ) ) { $out['noindex'] = '1' === $out['noindex']; }
	return $out;
}

/** Validated copy of a bundle page's `seo` object (unknown keys dropped; empty when absent). */
function rk_builder_seo_clean( $in ) {
	$out = array();
	if ( ! is_array( $in ) ) { return $out; }
	$limits = array( 'title' => 200, 'description' => 400, 'service' => 400, 'parent' => 200 );
	foreach ( $limits as $field => $max ) {
		if ( isset( $in[ $field ] ) && is_string( $in[ $field ] ) ) {
			$v = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $in[ $field ] ) ) );
			if ( '' !== $v ) { $out[ $field ] = rk_builder_substr( $v, 0, $max ); }
		}
	}
	if ( isset( $in['image'] ) && is_string( $in['image'] ) ) {
		$u = rk_builder_absolute_url( $in['image'] );
		if ( '' !== $u ) { $out['image'] = $u; }
	}
	if ( ! empty( $in['noindex'] ) ) { $out['noindex'] = true; }
	return $out;
}

/** Replace a page's SEO fields (fields not given are cleared). */
function rk_builder_seo_write( $id, array $seo ) {
	foreach ( rk_builder_seo_meta_keys() as $field => $key ) {
		$v = isset( $seo[ $field ] ) ? $seo[ $field ] : '';
		if ( 'noindex' === $field ) { $v = ! empty( $seo['noindex'] ) ? '1' : ''; }
		if ( '' === $v ) { delete_post_meta( (int) $id, $key ); } else { update_post_meta( (int) $id, $key, $v ); }
	}
}

/** Business details used by the Organization schema node. */
function rk_builder_seo_organization() {
	$o = get_option( 'rk_builder_seo_org', array() );
	return is_array( $o ) ? $o : array();
}

function rk_builder_seo_organization_save( $in ) {
	if ( ! is_array( $in ) ) { return; }
	$out = array();
	foreach ( array( 'name' => 120, 'telephone' => 40, 'email' => 120, 'description' => 300 ) as $f => $max ) {
		if ( isset( $in[ $f ] ) && is_string( $in[ $f ] ) ) {
			$v = trim( wp_strip_all_tags( $in[ $f ] ) );
			if ( '' !== $v ) { $out[ $f ] = rk_builder_substr( $v, 0, $max ); }
		}
	}
	if ( isset( $in['logo'] ) && is_string( $in['logo'] ) && '' !== rk_builder_absolute_url( $in['logo'] ) ) { $out['logo'] = rk_builder_absolute_url( $in['logo'] ); }
	update_option( 'rk_builder_seo_org', $out, false );
}

/** Keep only an absolute http(s) URL ('' otherwise); relative paths become absolute on this site. */
function rk_builder_absolute_url( $url ) {
	$url = is_string( $url ) ? trim( $url ) : '';
	if ( '' === $url ) { return ''; }
	if ( '/' === $url[0] && ( strlen( $url ) < 2 || '/' !== $url[1] ) ) { $url = home_url( $url ); }
	if ( 1 !== preg_match( '~^https?://~i', $url ) ) { return ''; }
	$clean = esc_url_raw( $url, array( 'http', 'https' ) );
	return is_string( $clean ) ? $clean : '';
}

/** First usable image of a layout (resolved through the media library when it has a mediaId). */
function rk_builder_first_layout_image( array $layout ) {
	foreach ( isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ? $layout['blocks'] : array() as $block ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) || 'image' !== $block['type'] ) { continue; }
		$props = rk_builder_block_props( $block );
		if ( null === $props ) { continue; }
		$img = rk_builder_resolve_image( $props );
		if ( '' !== $img['url'] ) { return $img['url']; }
	}
	return '';
}

/**
 * @param object $page   post object
 * @param array  $layout validated layout (published for public pages)
 * @return array{title:string,description:string,canonical:string,image:string,site_name:string}
 */
function rk_builder_seo_data( $page, array $layout ) {
	$id    = (int) $page->ID;
	$seo   = rk_builder_seo_read( $id );
	$title = rk_builder_plain( get_the_title( $page ) );
	if ( isset( $seo['title'] ) ) { $title = $seo['title']; }
	$title = apply_filters( 'rk_builder_seo_title', $title, $id );
	$desc  = isset( $seo['description'] ) ? $seo['description'] : rk_builder_describe( isset( $page->post_excerpt ) ? $page->post_excerpt : '', $layout );
	$desc  = apply_filters( 'rk_builder_seo_description', $desc, $id );
	$canon = (string) get_permalink( $id );
	$canon = apply_filters( 'rk_builder_canonical_url', $canon, $id );
	$image = '';
	$feat  = rk_builder_featured_image( $id );
	if ( $feat ) { $image = $feat['url']; }
	if ( '' === $image ) { $image = rk_builder_first_layout_image( $layout ); }
	if ( isset( $seo['image'] ) ) { $image = $seo['image']; }
	$image = apply_filters( 'rk_builder_og_image', $image, $id );
	return array(
		'title'       => is_string( $title ) ? trim( rk_builder_plain( $title ) ) : '',
		'description' => is_string( $desc ) ? trim( preg_replace( '/\s+/u', ' ', rk_builder_plain( $desc ) ) ) : '',
		'canonical'   => rk_builder_absolute_url( $canon ),
		'image'       => rk_builder_absolute_url( $image ),
		'site_name'   => (string) get_bloginfo( 'name' ),
		'locale'      => (string) get_locale(),
	);
}

/** The <head> tags (everything except <title>, which goes through WordPress's title API). */
function rk_builder_seo_head_html( array $d ) {
	$m = function ( $attr, $name, $value ) { return '<meta ' . $attr . '="' . $name . '" content="' . rk_builder_h( $value ) . '">' . "\n"; };
	$out = '';
	if ( '' !== $d['description'] ) { $out .= $m( 'name', 'description', $d['description'] ); }
	if ( '' !== $d['canonical'] ) { $out .= '<link rel="canonical" href="' . rk_builder_h( $d['canonical'] ) . '">' . "\n"; }
	if ( ! empty( $d['locale'] ) ) { $out .= $m( 'property', 'og:locale', $d['locale'] ); }
	$out .= $m( 'property', 'og:type', 'website' ) . $m( 'property', 'og:site_name', $d['site_name'] );
	$out .= $m( 'property', 'og:title', $d['title'] );
	if ( '' !== $d['description'] ) { $out .= $m( 'property', 'og:description', $d['description'] ); }
	if ( '' !== $d['canonical'] ) { $out .= $m( 'property', 'og:url', $d['canonical'] ); }
	if ( '' !== $d['image'] ) { $out .= $m( 'property', 'og:image', $d['image'] ) . $m( 'property', 'og:image:alt', $d['title'] ); }
	$out .= $m( 'name', 'twitter:card', '' !== $d['image'] ? 'summary_large_image' : 'summary' );
	$out .= $m( 'name', 'twitter:title', $d['title'] );
	if ( '' !== $d['description'] ) { $out .= $m( 'name', 'twitter:description', $d['description'] ); }
	if ( '' !== $d['image'] ) { $out .= $m( 'name', 'twitter:image', $d['image'] ); }
	return $out;
}

function rk_builder_seo_print_head() {
	if ( ! rk_builder_seo_owns_output() ) { return; }
	$rk = rk_builder_current_request_page();
	if ( null === $rk ) { return; }
	echo rk_builder_seo_head_html( rk_builder_seo_data( $rk['page'], $rk['layout'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per value
}

function rk_builder_seo_title_parts( $parts ) {
	if ( ! is_array( $parts ) || ! rk_builder_seo_owns_output() ) { return $parts; }
	$rk = rk_builder_current_request_page();
	if ( null === $rk ) { return $parts; }
	$seo   = rk_builder_seo_read( (int) $rk['page']->ID );
	$title = apply_filters( 'rk_builder_seo_title', isset( $seo['title'] ) ? $seo['title'] : rk_builder_plain( get_the_title( $rk['page'] ) ), (int) $rk['page']->ID );
	if ( is_string( $title ) && '' !== trim( $title ) ) { $parts['title'] = trim( rk_builder_plain( $title ) ); }
	if ( isset( $seo['title'] ) && empty( $parts['site'] ) ) { // front page: "Title | Site" like every other page
		$parts['site'] = (string) get_bloginfo( 'name' );
		unset( $parts['tagline'] );
	}
	return $parts;
}

/** `wp` action: claim the tags for this request (or step aside for a SEO plugin). */
function rk_builder_seo_setup() {
	if ( ! rk_builder_seo_owns_output() || null === rk_builder_current_request_page() ) { return; }
	remove_action( 'wp_head', 'rel_canonical' ); // we print the canonical (filterable) ourselves
	add_filter( 'document_title_parts', 'rk_builder_seo_title_parts', 20 );
	add_action( 'wp_head', 'rk_builder_seo_print_head', 1 );
	add_action( 'wp_head', 'rk_builder_seo_print_schema', 20 );
	add_filter( 'wp_robots', 'rk_builder_seo_robots', 20 );
	add_filter( 'document_title_separator', 'rk_builder_seo_separator', 20 );
}

/** Schema.org graph (Organization, WebSite, WebPage, optional Service, BreadcrumbList), like the source site. */
function rk_builder_seo_graph( $page, array $d ) {
	$home = home_url( '/' );
	$url  = '' !== $d['canonical'] ? $d['canonical'] : (string) get_permalink( $page );
	$org  = rk_builder_seo_organization();
	$org_id = $home . '#organization';
	$site_id = $home . '#website';
	$name = isset( $org['name'] ) ? $org['name'] : $d['site_name'];
	$o = array( '@type' => 'Organization', '@id' => $org_id, 'name' => $name, 'url' => $home );
	foreach ( array( 'telephone', 'email', 'description' ) as $f ) { if ( isset( $org[ $f ] ) ) { $o[ $f ] = $org[ $f ]; } }
	if ( isset( $org['logo'] ) ) { $o['logo'] = $org['logo']; }
	$graph = array(
		$o,
		array( '@type' => 'WebSite', '@id' => $site_id, 'url' => $home, 'name' => $name, 'publisher' => array( '@id' => $org_id ) ),
	);
	$wp = array( '@type' => 'WebPage', '@id' => $url . '#webpage', 'url' => $url, 'name' => $d['title'] );
	if ( '' !== $d['description'] ) { $wp['description'] = $d['description']; }
	$wp['isPartOf'] = array( '@id' => $site_id );
	$wp['about']    = array( '@id' => $org_id );
	$graph[] = $wp;
	$seo = rk_builder_seo_read( (int) $page->ID );
	if ( isset( $seo['service'] ) ) {
		$graph[] = array( '@type' => 'Service', '@id' => $url . '#service', 'name' => rk_builder_plain( get_the_title( $page ) ), 'description' => $seo['service'], 'url' => $url, 'provider' => array( '@id' => $org_id ), 'mainEntityOfPage' => array( '@id' => $url . '#webpage' ) );
	}
	if ( ! is_front_page() ) {
		$crumbs = array( array( 'Home', $home ) );
		if ( isset( $seo['parent'] ) ) {
			$pr = rk_builder_parse_rows_plain( $seo['parent'] );
			if ( '' !== $pr[0] && '' !== $pr[1] ) { $crumbs[] = array( $pr[0], rk_builder_absolute_url( $pr[1] ) ); }
		}
		$crumbs[] = array( rk_builder_plain( get_the_title( $page ) ), $url );
		$items = array();
		foreach ( $crumbs as $i => $c ) { $items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] ); }
		$graph[] = array( '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items );
	}
	return array( '@context' => 'https://schema.org', '@graph' => $graph );
}

/** "Label|/link" -> array( label, link ). */
function rk_builder_parse_rows_plain( $row ) {
	$parts = explode( '|', (string) $row, 2 );
	return array( trim( $parts[0] ), isset( $parts[1] ) ? trim( $parts[1] ) : '' );
}

function rk_builder_seo_print_schema() {
	if ( ! rk_builder_seo_owns_output() || class_exists( '\\RK\\SEO\\Schema', false ) ) { return; }
	$rk = rk_builder_current_request_page();
	if ( null === $rk ) { return; }
	$seo = rk_builder_seo_read( (int) $rk['page']->ID );
	if ( ! empty( $seo['noindex'] ) ) { return; } // like the source: utility pages carry no schema
	$graph = rk_builder_seo_graph( $rk['page'], rk_builder_seo_data( $rk['page'], $rk['layout'] ) );
	echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, tag-safe
}

/** noindex pages: "noindex, follow" through WordPress's robots API. */
function rk_builder_seo_robots( $robots ) {
	if ( ! is_array( $robots ) || ! rk_builder_seo_owns_output() ) { return $robots; }
	$rk = rk_builder_current_request_page();
	if ( null === $rk ) { return $robots; }
	$seo = rk_builder_seo_read( (int) $rk['page']->ID );
	if ( ! empty( $seo['noindex'] ) ) {
		unset( $robots['index'], $robots['max-image-preview'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['nofollow'] );
	}
	return $robots;
}

/** Source titles read "Title | Site". */
function rk_builder_seo_separator( $sep ) {
	if ( ! rk_builder_seo_owns_output() ) { return $sep; }
	$rk = rk_builder_current_request_page();
	if ( null === $rk ) { return $sep; }
	$seo = rk_builder_seo_read( (int) $rk['page']->ID );
	return isset( $seo['title'] ) ? '|' : $sep;
}

add_action( 'wp', 'rk_builder_seo_setup' );
