<?php
/**
 * Per-page schema (JSON-LD) choices, made in the dashboard under Pages > Search & sharing.
 *
 * A page keeps one small JSON document (meta `_rk_builder_schema`, listed with the other SEO fields so
 * duplicating, exporting and importing a page carry it along). A page without it behaves exactly as before:
 * WebPage + BreadcrumbList (+ Service when the theme package set a service text).
 *
 * Each optional type is one entry of rk_builder_schema_types(): a label, a help line and a `build`
 * callback. Add your own through the `rk_builder_schema_types` filter; the callback gets
 * ( $cfg, $ctx, $page ) and returns a node array, or null to output nothing:
 *
 *   add_filter( 'rk_builder_schema_types', function ( $types ) {
 *       $types['event'] = array( 'label' => 'Event', 'help' => '…', 'build' => function ( $cfg, $ctx, $page ) { … } );
 *       return $types;
 *   } );
 *
 * $ctx holds: url, name, description, image, home, org_id, site_id, webpage_id, legacy_service.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_schema_page_types() {
	return array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ProfilePage' );
}

/** Every setting with its default; a page that never opened the panel reads exactly this. */
function rk_builder_schema_defaults() {
	return array(
		'pageType'   => 'WebPage',
		'breadcrumb' => true,
		'business'   => false,
		'article'    => array( 'on' => false, 'type' => 'Article' ),
		'service'    => array( 'on' => false, 'name' => '', 'description' => '' ),
		'product'    => array( 'on' => false, 'name' => '', 'price' => '', 'currency' => 'USD', 'availability' => 'InStock', 'brand' => '' ),
		'faq'        => array( 'on' => false, 'items' => '' ),
		'review'     => array( 'on' => false, 'rating' => '', 'count' => '' ),
	);
}

function rk_builder_schema_text( $v, $max ) {
	if ( ! is_string( $v ) ) { return ''; }
	return rk_builder_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $v ) ) ), 0, $max );
}

/** A complete, valid settings array from anything (unknown keys dropped, bad values back to the default). */
function rk_builder_schema_sanitize( $in, $base = null ) {
	$d   = rk_builder_schema_defaults();
	$out = is_array( $base ) ? array_replace_recursive( $d, $base ) : $d;
	if ( ! is_array( $in ) ) { return $out; }
	if ( isset( $in['pageType'] ) && in_array( $in['pageType'], rk_builder_schema_page_types(), true ) ) { $out['pageType'] = $in['pageType']; }
	foreach ( array( 'breadcrumb', 'business' ) as $k ) { if ( array_key_exists( $k, $in ) ) { $out[ $k ] = ! empty( $in[ $k ] ); } }
	foreach ( array( 'article', 'service', 'product', 'faq', 'review' ) as $k ) {
		if ( ! isset( $in[ $k ] ) || ! is_array( $in[ $k ] ) ) { continue; }
		if ( array_key_exists( 'on', $in[ $k ] ) ) { $out[ $k ]['on'] = ! empty( $in[ $k ]['on'] ); }
	}
	$a = isset( $in['article'] ) && is_array( $in['article'] ) ? $in['article'] : array();
	if ( isset( $a['type'] ) && in_array( $a['type'], array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) ) { $out['article']['type'] = $a['type']; }
	$s = isset( $in['service'] ) && is_array( $in['service'] ) ? $in['service'] : array();
	if ( isset( $s['name'] ) ) { $out['service']['name'] = rk_builder_schema_text( $s['name'], 120 ); }
	if ( isset( $s['description'] ) ) { $out['service']['description'] = rk_builder_schema_text( $s['description'], 400 ); }
	$p = isset( $in['product'] ) && is_array( $in['product'] ) ? $in['product'] : array();
	if ( isset( $p['name'] ) ) { $out['product']['name'] = rk_builder_schema_text( $p['name'], 120 ); }
	if ( isset( $p['brand'] ) ) { $out['product']['brand'] = rk_builder_schema_text( $p['brand'], 80 ); }
	if ( isset( $p['price'] ) ) {
		$v = is_scalar( $p['price'] ) ? trim( (string) $p['price'] ) : '';
		$out['product']['price'] = 1 === preg_match( '/^\d{1,9}(\.\d{1,2})?\z/', $v ) ? $v : '';
	}
	if ( isset( $p['currency'] ) ) {
		$v = is_string( $p['currency'] ) ? strtoupper( trim( $p['currency'] ) ) : '';
		$out['product']['currency'] = 1 === preg_match( '/^[A-Z]{3}\z/', $v ) ? $v : 'USD';
	}
	if ( isset( $p['availability'] ) && in_array( $p['availability'], array( 'InStock', 'OutOfStock', 'PreOrder', 'LimitedAvailability' ), true ) ) { $out['product']['availability'] = $p['availability']; }
	$f = isset( $in['faq'] ) && is_array( $in['faq'] ) ? $in['faq'] : array();
	if ( isset( $f['items'] ) && is_string( $f['items'] ) ) { $out['faq']['items'] = rk_builder_substr( trim( (string) preg_replace( "/[ \t]+/", ' ', wp_strip_all_tags( $f['items'] ) ) ), 0, 8000 ); }
	$r = isset( $in['review'] ) && is_array( $in['review'] ) ? $in['review'] : array();
	if ( isset( $r['rating'] ) ) {
		$v = is_scalar( $r['rating'] ) ? trim( (string) $r['rating'] ) : '';
		$out['review']['rating'] = ( 1 === preg_match( '/^\d(\.\d)?\z/', $v ) && (float) $v >= 1 && (float) $v <= 5 ) ? $v : '';
	}
	if ( isset( $r['count'] ) ) {
		$v = is_scalar( $r['count'] ) ? trim( (string) $r['count'] ) : '';
		$out['review']['count'] = ( 1 === preg_match( '/^\d{1,7}\z/', $v ) && (int) $v >= 1 ) ? (string) (int) $v : '';
	}
	return $out;
}

/** The JSON stored with the page's SEO fields, or '' when everything is still default (nothing to store). */
function rk_builder_schema_encode( $in ) {
	$clean = rk_builder_schema_sanitize( is_string( $in ) ? json_decode( $in, true ) : $in );
	return $clean === rk_builder_schema_defaults() ? '' : (string) wp_json_encode( $clean );
}

/** Settings of a page from its SEO fields (see rk_builder_seo_read()). */
function rk_builder_schema_from_seo( array $seo ) {
	return rk_builder_schema_sanitize( isset( $seo['schema'] ) && is_string( $seo['schema'] ) ? json_decode( $seo['schema'], true ) : null );
}

/* ------------------------------------------------------------------ *
 * Builders: one per optional type
 * ------------------------------------------------------------------ */

function rk_builder_schema_dt( $gmt, $fallback = '' ) {
	$t = is_string( $gmt ) && '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
	return false === $t ? $fallback : gmdate( 'c', $t );
}

function rk_builder_schema_build_article( $cfg, $ctx, $page ) {
	$n = array(
		'@type'            => $cfg['type'],
		'@id'              => $ctx['url'] . '#article',
		'headline'         => rk_builder_substr( $ctx['name'], 0, 110 ),
		'mainEntityOfPage' => array( '@id' => $ctx['webpage_id'] ),
		'publisher'        => array( '@id' => $ctx['org_id'] ),
	);
	if ( '' !== $ctx['description'] ) { $n['description'] = $ctx['description']; }
	if ( '' !== $ctx['image'] ) { $n['image'] = array( $ctx['image'] ); }
	$pub = rk_builder_schema_dt( isset( $page->post_date_gmt ) ? $page->post_date_gmt : '' );
	$mod = rk_builder_schema_dt( isset( $page->post_modified_gmt ) ? $page->post_modified_gmt : '', $pub );
	if ( '' !== $pub ) { $n['datePublished'] = $pub; }
	if ( '' !== $mod ) { $n['dateModified'] = $mod; }
	$author = function_exists( 'get_the_author_meta' ) && ! empty( $page->post_author ) ? (string) get_the_author_meta( 'display_name', (int) $page->post_author ) : '';
	$n['author'] = array( '@type' => 'Person', 'name' => '' !== $author ? $author : $ctx['site_name'] );
	return $n;
}

function rk_builder_schema_build_service( $cfg, $ctx, $page ) {
	$desc = '' !== $cfg['description'] ? $cfg['description'] : ( '' !== $ctx['legacy_service'] ? $ctx['legacy_service'] : $ctx['description'] );
	$n = array(
		'@type'            => 'Service',
		'@id'              => $ctx['url'] . '#service',
		'name'             => '' !== $cfg['name'] ? $cfg['name'] : $ctx['name'],
		'url'              => $ctx['url'],
		'provider'         => array( '@id' => $ctx['org_id'] ),
		'mainEntityOfPage' => array( '@id' => $ctx['webpage_id'] ),
	);
	if ( '' !== $desc ) { $n['description'] = $desc; }
	if ( '' !== $ctx['image'] ) { $n['image'] = $ctx['image']; }
	return $n;
}

function rk_builder_schema_build_product( $cfg, $ctx, $page ) {
	if ( '' === $cfg['price'] ) { return null; } // an Offer without a price is not valid
	$n = array(
		'@type'            => 'Product',
		'@id'              => $ctx['url'] . '#product',
		'name'             => '' !== $cfg['name'] ? $cfg['name'] : $ctx['name'],
		'url'              => $ctx['url'],
		'mainEntityOfPage' => array( '@id' => $ctx['webpage_id'] ),
		'offers'           => array(
			'@type'         => 'Offer',
			'price'         => $cfg['price'],
			'priceCurrency' => $cfg['currency'],
			'availability'  => 'https://schema.org/' . $cfg['availability'],
			'url'           => $ctx['url'],
		),
	);
	if ( '' !== $ctx['description'] ) { $n['description'] = $ctx['description']; }
	if ( '' !== $ctx['image'] ) { $n['image'] = array( $ctx['image'] ); }
	if ( '' !== $cfg['brand'] ) { $n['brand'] = array( '@type' => 'Brand', 'name' => $cfg['brand'] ); }
	return $n;
}

/** "Question|Answer" per line -> up to 20 pairs. */
function rk_builder_schema_faq_pairs( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = explode( '|', $line, 2 );
		$q = rk_builder_schema_text( $parts[0], 200 );
		$a = isset( $parts[1] ) ? rk_builder_schema_text( $parts[1], 1000 ) : '';
		if ( '' !== $q && '' !== $a ) { $out[] = array( $q, $a ); }
		if ( count( $out ) >= 20 ) { break; }
	}
	return $out;
}

function rk_builder_schema_build_faq( $cfg, $ctx, $page ) {
	$pairs = rk_builder_schema_faq_pairs( $cfg['items'] );
	if ( ! $pairs ) { return null; }
	$main = array();
	foreach ( $pairs as $p ) { $main[] = array( '@type' => 'Question', 'name' => $p[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $p[1] ) ); }
	return array( '@type' => 'FAQPage', '@id' => $ctx['url'] . '#faq', 'url' => $ctx['url'], 'mainEntity' => $main );
}

/**
 * The optional types, in output order. `review` has no node of its own: its rating is attached to the
 * page's Product, Service or business node (see rk_builder_schema_attach_rating()).
 */
function rk_builder_schema_types() {
	return apply_filters( 'rk_builder_schema_types', array(
		'article' => array( 'label' => 'Article', 'help' => 'Blog posts and news: headline, dates and author.', 'build' => 'rk_builder_schema_build_article' ),
		'service' => array( 'label' => 'Service', 'help' => 'A service your business offers.', 'build' => 'rk_builder_schema_build_service' ),
		'product' => array( 'label' => 'Product', 'help' => 'A product with a price (needs a price to be valid).', 'build' => 'rk_builder_schema_build_product' ),
		'faq'     => array( 'label' => 'FAQ', 'help' => 'Questions and answers shown in search results.', 'build' => 'rk_builder_schema_build_faq' ),
	) );
}

/** The extra nodes a page asks for. */
function rk_builder_schema_nodes( array $cfg, array $ctx, $page ) {
	$nodes = array();
	foreach ( rk_builder_schema_types() as $key => $def ) {
		$c = isset( $cfg[ $key ] ) && is_array( $cfg[ $key ] ) ? $cfg[ $key ] : array();
		if ( 'service' === $key && empty( $c['on'] ) && '' !== $ctx['legacy_service'] ) { $c = array_merge( rk_builder_schema_defaults()['service'], $c, array( 'on' => true ) ); }
		if ( empty( $c['on'] ) || ! isset( $def['build'] ) || ! is_callable( $def['build'] ) ) { continue; }
		$n = call_user_func( $def['build'], $c, $ctx, $page );
		if ( is_array( $n ) && $n ) { $nodes[] = $n; }
	}
	return $nodes;
}

/** Puts the rating on the first Product, Service or business node of the graph. */
function rk_builder_schema_attach_rating( array $graph, array $cfg ) {
	$r = $cfg['review'];
	if ( empty( $r['on'] ) || '' === $r['rating'] || '' === $r['count'] ) { return $graph; }
	$rating = array( '@type' => 'AggregateRating', 'ratingValue' => $r['rating'], 'reviewCount' => $r['count'], 'bestRating' => '5', 'worstRating' => '1' );
	foreach ( array( 'Product', 'Service' ) as $type ) {
		foreach ( $graph as $i => $n ) {
			if ( isset( $n['@type'] ) && $type === $n['@type'] ) { $graph[ $i ]['aggregateRating'] = $rating; return $graph; }
		}
	}
	foreach ( $graph as $i => $n ) {
		if ( isset( $n['@id'] ) && '#localbusiness' === substr( (string) $n['@id'], -14 ) ) { $graph[ $i ]['aggregateRating'] = $rating; return $graph; }
	}
	return $graph;
}
