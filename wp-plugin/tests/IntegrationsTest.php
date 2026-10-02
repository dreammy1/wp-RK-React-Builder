<?php
/** Business profile, custom code, reviews, redirects and the default social image. */

function rk_int_head( $id ) {
	rk_test_hooks();
	rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => true ) );
	do_action( 'wp' );
	ob_start();
	do_action( 'wp_head' );
	return ob_get_clean();
}

rk_test( 'integrations: every admin route needs an administrator; the public reviews route is open', function () {
	foreach ( array( array( 'GET', '/rk/v1/builder/code' ), array( 'POST', '/rk/v1/builder/code' ), array( 'GET', '/rk/v1/builder/reviews-admin' ), array( 'POST', '/rk/v1/builder/reviews-admin/sync' ), array( 'GET', '/rk/v1/builder/redirects' ), array( 'POST', '/rk/v1/builder/redirects' ) ) as $r ) {
		t_err( 'GET' === $r[0] ? rk_get( $r[1] ) : rk_post( $r[1], array() ), 'rk_unauthorized', 401 );
	}
	rk_test_login( 'editor' );
	t_err( rk_get( '/rk/v1/builder/code' ), 'rk_forbidden', 403 );
	t_err( rk_get( '/rk/v1/builder/redirects' ), 'rk_forbidden', 403 );
	rk_test_login( 'anon' );
	t_eq( t_ok( rk_get( '/rk/v1/reviews' ) )['items'], array() );
} );

rk_test( 'hours: ranges, lists and 12-hour padding become OpeningHoursSpecification', function () {
	$h = rk_builder_parse_hours( "Mon-Fri 8:00-17:00; Sat,Sun 09:00-13:00\nnonsense\nFri 10:00 to 11:30" );
	t_eq( count( $h ), 3 );
	t_eq( $h[0]['dayOfWeek'], array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ) );
	t_eq( $h[0]['opens'], '08:00' );
	t_eq( $h[1]['dayOfWeek'], array( 'Saturday', 'Sunday' ) );
	t_eq( $h[2]['closes'], '11:30' );
	t_eq( rk_builder_parse_hours( 'closed on holidays' ), array() );
} );

rk_test( 'code: tokens, IDs and snippets are validated; only people with unfiltered_html change snippets', function () {
	rk_test_login( 'admin' );
	$r = t_ok( rk_post( '/rk/v1/builder/code', array( 'gsc' => '<meta name="google-site-verification" content="abc123_DEF-456ghi789" />', 'bing' => 'BING0123456789ABCDEF', 'ga4' => 'g-abc123xyz4', 'gtm' => 'gtm-abc1234', 'head' => '<script>window.x=1</script>', 'footer' => '<b>f</b>' ) ) );
	t_eq( $r['code']['gsc'], 'abc123_DEF-456ghi789', 'the content is extracted from a pasted meta tag' );
	t_eq( $r['code']['ga4'], 'G-ABC123XYZ4' );
	t_eq( $r['code']['gtm'], 'GTM-ABC1234' );
	t_eq( $r['code']['head'], '<script>window.x=1</script>' );
	t_err( rk_post( '/rk/v1/builder/code', array( 'ga4' => 'UA-1' ) ), 'rk_invalid_code', 400 );
	t_err( rk_post( '/rk/v1/builder/code', array( 'gsc' => 'bad token!' ) ), 'rk_invalid_code', 400 );
	t_err( rk_post( '/rk/v1/builder/code', array( 'wat' => 1 ) ), 'rk_invalid_code', 400 );
	t_eq( t_ok( rk_post( '/rk/v1/builder/code', array( 'ga4' => '' ) ) )['code']['ga4'], '' );
	// an account without unfiltered_html cannot change snippets
	rk_test_login( 'editor' );
	t_eq( rk_builder_code_can_raw(), false );
	$kept = rk_builder_code_sanitize( array( 'head' => '<script>evil()</script>' ), false );
	t_eq( $kept['head'], '<script>window.x=1</script>', 'snippets are kept when the account may not edit them' );
} );

rk_test( 'code: head, body and footer output; analytics is skipped for signed-in editors; verification never is', function () {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/code', array( 'gsc' => 'abc123_DEF-456ghi789', 'ga4' => 'G-ABC123XYZ4', 'gtm' => 'GTM-ABC1234', 'head' => '<meta name="x" content="h">', 'bodyStart' => '<!-- body -->', 'footer' => '<!-- foot -->' ) ) );
	ob_start(); rk_builder_code_print_head(); $head = ob_get_clean();
	t_assert( false === strpos( $head, 'googletagmanager' ), 'a signed-in editor is not tracked' );
	t_assert( false !== strpos( $head, 'name="google-site-verification" content="abc123_DEF-456ghi789"' ), 'verification is always printed' );
	t_assert( false !== strpos( $head, '<meta name="x" content="h">' ) );
	rk_test_login( 'anon' );
	ob_start(); rk_builder_code_print_head(); $head = ob_get_clean();
	t_assert( false !== strpos( $head, 'gtag/js?id=G-ABC123XYZ4' ) && false !== strpos( $head, "gtag('config','G-ABC123XYZ4')" ), 'GA4 for visitors' );
	t_assert( false !== strpos( $head, 'gtm.js?id=' ), 'Tag Manager for visitors' );
	ob_start(); rk_builder_code_print_body(); $body = ob_get_clean();
	t_assert( false !== strpos( $body, 'ns.html?id=GTM-ABC1234' ) && false !== strpos( $body, '<!-- body -->' ) );
	ob_start(); rk_builder_code_print_footer(); $foot = ob_get_clean();
	t_eq( trim( $foot ), '<!-- foot -->' );
} );

rk_test( 'reviews: save the profile, add and hide reviews, public list is sorted, filtered and capped', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/reviews-admin', array( 'profileUrl' => 'javascript:x' ) ), 'rk_invalid_reviews', 400 );
	t_err( rk_post( '/rk/v1/builder/reviews-admin', array( 'placeId' => 'no spaces' ) ), 'rk_invalid_reviews', 400 );
	t_err( rk_post( '/rk/v1/builder/reviews-admin', array( 'apiKey' => 'short' ) ), 'rk_invalid_reviews', 400 );
	$c = t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'profileUrl' => 'https://g.page/r/abc', 'placeId' => 'ChIJabcdefghijk123', 'apiKey' => 'AIzaFakeKeyFakeKeyFakeKeyFake123', 'summary' => array( 'rating' => 4.84, 'count' => 87 ) ) ) );
	t_eq( $c['config']['keySet'], true );
	t_eq( strpos( json_encode( $c ), 'AIzaFake' ), false, 'the key is never returned' );
	t_eq( $c['summary'], array( 'rating' => 4.8, 'count' => 87 ) );
	t_eq( $c['config']['writeUrl'], 'https://search.google.com/local/writereview?placeid=ChIJabcdefghijk123' );
	$items = array(
		array( 'author' => 'Ann', 'rating' => 5, 'text' => 'Great <b>floor</b>', 'date' => '2 weeks ago' ),
		array( 'author' => 'Bob', 'rating' => 3, 'text' => 'Fine' ),
		array( 'author' => 'Cy', 'rating' => 5, 'text' => 'Wow', 'hidden' => true ),
		array( 'author' => 'Di', 'rating' => 4, 'text' => str_repeat( 'long ', 100 ), 'source' => 'google', 'url' => 'javascript:x' ),
	);
	t_err( rk_post( '/rk/v1/builder/reviews-admin/items', array( 'items' => array( array( 'author' => '', 'rating' => 5 ) ) ) ), 'rk_invalid_reviews', 400 );
	t_err( rk_post( '/rk/v1/builder/reviews-admin/items', array( 'items' => array( array( 'author' => 'X', 'rating' => 9 ) ) ) ), 'rk_invalid_reviews', 400 );
	$saved = t_ok( rk_post( '/rk/v1/builder/reviews-admin/items', array( 'items' => $items ) ) );
	t_eq( count( $saved['items'] ), 4 );
	t_eq( $saved['items'][0]['text'], 'Great floor', 'tags are stripped' );
	t_eq( $saved['items'][3]['url'], '', 'unsafe links are dropped' );
	rk_test_login( 'anon' );
	$pub = t_ok( rk_get( '/rk/v1/reviews' ) );
	t_eq( array_map( function ( $r ) { return $r['author']; }, $pub['items'] ), array( 'Ann', 'Di', 'Bob' ), 'best first, hidden left out' );
	t_eq( $pub['links']['profile'], 'https://g.page/r/abc' );
	t_eq( rk_builder_reviews_public( 1, 1 )['items'][0]['author'], 'Ann' );
	t_eq( count( rk_builder_reviews_public( 12, 4 )['items'] ), 2 );
} );

rk_test( 'reviews: sync pulls rating, count and reviews from Places (faked HTTP) and keeps hand-written ones', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/reviews-admin/sync', array() ), 'rk_conflict', 409 );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'placeId' => 'ChIJabcdefghijk123', 'apiKey' => 'AIzaFakeKeyFakeKeyFakeKeyFake123' ) ) );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin/items', array( 'items' => array( array( 'author' => 'Hand', 'rating' => 5, 'text' => 'Mine' ) ) ) ) );
	$seen = array();
	add_filter( 'rk_builder_http', function ( $pre, $method, $url, $args ) use ( &$seen ) {
		$seen = array( $method, $url, $args );
		return array( 'code' => 200, 'body' => json_encode( array(
			'rating' => 4.86, 'userRatingCount' => 120, 'googleMapsUri' => 'https://maps.google.com/?cid=1',
			'reviews' => array(
				array( 'name' => 'places/x/reviews/1', 'rating' => 5, 'relativePublishTimeDescription' => 'a month ago', 'text' => array( 'text' => 'Excellent work' ), 'authorAttribution' => array( 'displayName' => 'Gina', 'uri' => 'https://maps.google.com/u/1' ) ),
				array( 'name' => 'places/x/reviews/2', 'rating' => 4, 'originalText' => array( 'text' => 'Good' ), 'authorAttribution' => array( 'displayName' => 'Hal' ) ),
			),
		) ) );
	}, 10, 4 );
	$r = t_ok( rk_post( '/rk/v1/builder/reviews-admin/sync', array() ) );
	t_eq( $seen[0], 'GET' );
	t_eq( $seen[1], 'https://places.googleapis.com/v1/places/ChIJabcdefghijk123' );
	t_eq( $seen[2]['headers']['X-Goog-Api-Key'], 'AIzaFakeKeyFakeKeyFakeKeyFake123', 'the key travels in a header, never in the address' );
	t_eq( $r['summary'], array( 'rating' => 4.9, 'count' => 120 ) );
	t_eq( count( $r['items'] ), 3 );
	t_eq( $r['items'][0]['author'], 'Gina' );
	t_eq( $r['items'][0]['source'], 'google' );
	t_eq( $r['items'][2]['author'], 'Hand' );
	t_assert( '' !== $r['config']['syncedAt'] );
	// hiding a synced review survives the next sync
	$items = $r['items'];
	$items[0]['hidden'] = true;
	t_ok( rk_post( '/rk/v1/builder/reviews-admin/items', array( 'items' => $items ) ) );
	$again = t_ok( rk_post( '/rk/v1/builder/reviews-admin/sync', array() ) );
	t_eq( $again['items'][0]['hidden'], true );
	// an upstream error is reported and remembered
	add_filter( 'rk_builder_http', function () { return array( 'code' => 403, 'body' => json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) ) ); }, 20, 4 );
	t_err( rk_post( '/rk/v1/builder/reviews-admin/sync', array() ), 'rk_upstream', 502 );
	t_assert( false !== strpos( t_ok( rk_get( '/rk/v1/builder/reviews-admin' ) )['config']['lastError'], 'API key not valid' ) );
	t_eq( t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'apiKey' => 'AIzaFakeKeyFakeKeyFakeKeyFake999' ) ) )['config']['lastError'], '', 'a new key clears the old error' );
} );

rk_test( 'reviews block: summary, links, cards, escaping, hidden and low ratings left out', function () {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'profileUrl' => 'https://g.page/r/abc', 'placeId' => 'ChIJabcdefghijk123', 'summary' => array( 'rating' => 4.7, 'count' => 1 ) ) ) );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin/items', array( 'items' => array(
		array( 'author' => 'Ann <i>A</i>', 'rating' => 5, 'text' => 'Loved "it"', 'date' => 'May 2026', 'source' => 'google' ),
		array( 'author' => 'Low', 'rating' => 2, 'text' => 'meh' ),
	) ) ) );
	$props = array( 'eyebrow' => 'Reviews', 'heading' => 'Say <hi>', 'intro' => '', 'limit' => 6, 'minRating' => 4, 'cols' => 3, 'showSummary' => true, 'showLinks' => true, 'tone' => 'muted' );
	$h = rk_builder_render_reviews( $props );
	t_assert( false !== strpos( $h, '<h2>Say &lt;hi&gt;</h2>' ) );
	t_assert( false !== strpos( $h, '<strong>4.7</strong><span>1 Google review</span>' ) );
	t_assert( false !== strpos( $h, 'href="https://g.page/r/abc" target="_blank" rel="noopener noreferrer">See all reviews on Google' ) );
	t_assert( false !== strpos( $h, 'Leave a review' ) );
	t_assert( false !== strpos( $h, '<strong>Ann A</strong><small>May 2026</small><span class="pf-rv-src">Google</span>' ) );
	t_assert( false === strpos( $h, 'meh' ), 'reviews below the minimum rating are left out' );
	t_eq( substr_count( $h, '<article>' ), 1 );
	t_eq( substr_count( $h, 'lucide-star on' ), 10, 'the summary and the card show five filled stars each' );
	$off = rk_builder_render_reviews( array_merge( $props, array( 'showSummary' => false, 'showLinks' => false ) ) );
	t_assert( false === strpos( $off, 'pf-rv-summary' ) && false === strpos( $off, 'pf-rv-links' ) );
	t_assert( false === strpos( $h, 'ld+json' ), 'no review markup is added to the structured data' );
} );

rk_test( 'redirects: validated, normalized, de-duplicated, and applied', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/redirects', array( 'items' => array( array( 'from' => 'nope', 'to' => '/x' ) ) ) ), 'rk_invalid_redirects', 400 );
	t_err( rk_post( '/rk/v1/builder/redirects', array( 'items' => array( array( 'from' => '/a', 'to' => '/a' ) ) ) ), 'rk_invalid_redirects', 400 );
	t_err( rk_post( '/rk/v1/builder/redirects', array( 'items' => array( array( 'from' => '/a', 'to' => 'javascript:x' ) ) ) ), 'rk_invalid_redirects', 400 );
	$r = t_ok( rk_post( '/rk/v1/builder/redirects', array( 'items' => array(
		array( 'from' => '/Old-Page/', 'to' => '/new-page?x=1', 'code' => 301 ),
		array( 'from' => '/old-page', 'to' => '/dup' ),
		array( 'from' => '/promo', 'to' => 'https://example.com/offer', 'code' => 302 ),
	) ) ) );
	t_eq( count( $r['items'] ), 2 );
	t_eq( $r['items'][0], array( 'from' => '/old-page', 'to' => '/new-page?x=1', 'code' => 301 ) );
	t_eq( rk_builder_redirect_match( '/OLD-page/?utm=1' )['to'], '/new-page?x=1' );
	t_eq( rk_builder_redirect_match( '/promo' )['code'], 302 );
	t_eq( rk_builder_redirect_match( '/nothing' ), null );
} );

rk_test( 'business profile: sameAs, LocalBusiness with address, hours and areas; default social image', function () {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/site', array( 'organization' => array(
		'name' => 'Acme Floors', 'telephone' => '+1555', 'businessType' => 'HomeAndConstructionBusiness', 'street' => '1 Main St', 'city' => 'Peoria', 'region' => 'IL', 'postal' => '61602', 'country' => 'us',
		'hours' => 'Mon-Fri 08:00-17:00', 'areaServed' => 'Peoria, Dunlap', 'priceRange' => '$$', 'defaultImage' => 'https://cdn.example/og.jpg',
		'profiles' => array( 'googleBusiness' => 'https://g.page/r/abc', 'facebook' => 'https://facebook.com/acme', 'x' => 'javascript:x', 'nope' => 'https://x.y' ),
	) ) ) );
	$o = rk_builder_seo_organization();
	t_eq( $o['country'], 'US' );
	t_eq( array_keys( $o['profiles'] ), array( 'googleBusiness', 'facebook' ), 'unsafe and unknown profiles are dropped' );
	$id = rk_pub_page( rk_test_layout( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ) ), 'about', array( 'post_title' => 'About' ) );
	$head = rk_int_head( $id );
	t_assert( false !== strpos( $head, 'og:image" content="https://cdn.example/og.jpg"' ), 'the site default is used when a page has no image' );
	preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $head, $m );
	$g = json_decode( $m[1], true );
	$types = array_map( function ( $n ) { return $n['@type']; }, $g['@graph'] );
	t_eq( $types, array( 'Organization', 'WebSite', 'HomeAndConstructionBusiness', 'WebPage', 'BreadcrumbList' ) );
	t_eq( $g['@graph'][0]['sameAs'], array( 'https://g.page/r/abc', 'https://facebook.com/acme' ) );
	$lb = $g['@graph'][2];
	t_eq( $lb['address']['addressLocality'], 'Peoria' );
	t_eq( $lb['openingHoursSpecification'][0]['opens'], '08:00' );
	t_eq( $lb['areaServed'][1]['name'], 'Dunlap' );
	t_eq( $lb['hasMap'], 'https://g.page/r/abc' );
	rk_builder_seo_write( $id, array( 'image' => 'https://cdn.example/page.jpg' ) );
	t_assert( false !== strpos( rk_int_head( $id ), 'og:image" content="https://cdn.example/page.jpg"' ), 'a page image wins over the default' );
} );
