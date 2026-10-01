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
	$title = rk_builder_plain( get_the_title( $page ) );
	$title = apply_filters( 'rk_builder_seo_title', $title, $id );
	$desc  = rk_builder_describe( isset( $page->post_excerpt ) ? $page->post_excerpt : '', $layout );
	$desc  = apply_filters( 'rk_builder_seo_description', $desc, $id );
	$canon = (string) get_permalink( $id );
	$canon = apply_filters( 'rk_builder_canonical_url', $canon, $id );
	$image = '';
	$feat  = rk_builder_featured_image( $id );
	if ( $feat ) { $image = $feat['url']; }
	if ( '' === $image ) { $image = rk_builder_first_layout_image( $layout ); }
	$image = apply_filters( 'rk_builder_og_image', $image, $id );
	return array(
		'title'       => is_string( $title ) ? trim( rk_builder_plain( $title ) ) : '',
		'description' => is_string( $desc ) ? trim( preg_replace( '/\s+/u', ' ', rk_builder_plain( $desc ) ) ) : '',
		'canonical'   => rk_builder_absolute_url( $canon ),
		'image'       => rk_builder_absolute_url( $image ),
		'site_name'   => (string) get_bloginfo( 'name' ),
	);
}

/** The <head> tags (everything except <title>, which goes through WordPress's title API). */
function rk_builder_seo_head_html( array $d ) {
	$m = function ( $attr, $name, $value ) { return '<meta ' . $attr . '="' . $name . '" content="' . rk_builder_h( $value ) . '">' . "\n"; };
	$out = '';
	if ( '' !== $d['description'] ) { $out .= $m( 'name', 'description', $d['description'] ); }
	if ( '' !== $d['canonical'] ) { $out .= '<link rel="canonical" href="' . rk_builder_h( $d['canonical'] ) . '">' . "\n"; }
	$out .= $m( 'property', 'og:type', 'website' ) . $m( 'property', 'og:site_name', $d['site_name'] );
	$out .= $m( 'property', 'og:title', $d['title'] );
	if ( '' !== $d['description'] ) { $out .= $m( 'property', 'og:description', $d['description'] ); }
	if ( '' !== $d['canonical'] ) { $out .= $m( 'property', 'og:url', $d['canonical'] ); }
	if ( '' !== $d['image'] ) { $out .= $m( 'property', 'og:image', $d['image'] ); }
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
	$title = apply_filters( 'rk_builder_seo_title', rk_builder_plain( get_the_title( $rk['page'] ) ), (int) $rk['page']->ID );
	if ( is_string( $title ) && '' !== trim( $title ) ) { $parts['title'] = trim( rk_builder_plain( $title ) ); }
	return $parts;
}

/** `wp` action: claim the tags for this request (or step aside for a SEO plugin). */
function rk_builder_seo_setup() {
	if ( ! rk_builder_seo_owns_output() || null === rk_builder_current_request_page() ) { return; }
	remove_action( 'wp_head', 'rel_canonical' ); // we print the canonical (filterable) ourselves
	add_filter( 'document_title_parts', 'rk_builder_seo_title_parts', 20 );
	add_action( 'wp_head', 'rk_builder_seo_print_head', 1 );
}

add_action( 'wp', 'rk_builder_seo_setup' );
