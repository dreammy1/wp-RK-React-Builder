<?php
/** Cross-language fixture contract + validator edge cases. */

const RK_TEST_HOSTS = array( 'cms.example.com' );

foreach ( fx_files( 'valid' ) as $file ) {
	rk_test( 'valid fixture passes: ' . basename( $file ), function () use ( $file ) {
		$fx     = json_decode( file_get_contents( $file ), true );
		$issues = 'theme' === $fx['kind'] ? rk_builder_validate_theme( $fx['document'], RK_TEST_HOSTS ) : rk_builder_validate_layout( $fx['document'], RK_TEST_HOSTS );
		t_eq( $issues, array(), 'issues' );
	} );
}
foreach ( fx_files( 'invalid' ) as $file ) {
	rk_test( 'invalid fixture rejected: ' . basename( $file ), function () use ( $file ) {
		$fx     = json_decode( file_get_contents( $file ), true );
		$issues = 'theme' === $fx['kind'] ? rk_builder_validate_theme( $fx['document'], RK_TEST_HOSTS ) : rk_builder_validate_layout( $fx['document'], RK_TEST_HOSTS );
		t_assert( count( $issues ) >= 1, 'expected at least one issue' );
		foreach ( $issues as $i ) { t_assert( isset( $i['path'], $i['message'] ) && is_string( $i['path'] ) && is_string( $i['message'] ), 'issue shape' ); }
	} );
}

function rk_link_layout( $href ) {
	return array( 'version' => 1, 'blocks' => array( array( 'id' => 'c', 'type' => 'cta', 'props' => array( 'heading' => 'h', 'cta' => 'go', 'ctaHref' => $href ) ) ) );
}
function rk_image_layout( $url ) {
	return array( 'version' => 1, 'blocks' => array( array( 'id' => 'i', 'type' => 'image', 'props' => array( 'url' => $url, 'alt' => 'x', 'decorative' => false ) ) ) );
}

rk_test( 'unsafe link schemes are rejected', function () {
	foreach ( array( 'javascript:alert(1)', 'data:text/html,hi', 'vbscript:msgbox(1)', ' javascript:alert(1)', "java\tscript:alert(1)", 'JaVaScRiPt:alert(1)', 'JAVASCRIPT:alert(1)',
		'//evil.example', '/\\evil.example', 'http://', 'https:///x', 'ftp://x.example/a', 'x', "/ok\nbad", "\x00/x", str_repeat( 'a', 501 ), 'https://exa mple.com', "\u{00a0}/x" ) as $bad ) {
		t_assert( count( rk_builder_validate_layout( rk_link_layout( $bad ) ) ) >= 1, 'should reject ' . json_encode( $bad ) );
	}
} );
rk_test( 'safe link forms are accepted', function () {
	foreach ( array( '', '/contact', '/a/b?x=1#y', '#top', 'http://x.example', 'https://x.example/a?b=c', 'mailto:hi@example.com', 'tel:+15551234', 'HTTPS://X.EXAMPLE/' ) as $ok ) {
		t_eq( rk_builder_validate_layout( rk_link_layout( $ok ) ), array(), json_encode( $ok ) );
	}
} );
rk_test( 'image urls: data/javascript/vbscript/relative-scheme-less are rejected', function () {
	foreach ( array( 'data:image/png;base64,AAAA', 'javascript:alert(1)', 'vbscript:x', ' javascript:x', 'JaVaScRiPt:x', '//cms.example.com/a.jpg', 'a.jpg', '', 'mailto:a@b.c' ) as $bad ) {
		t_assert( count( rk_builder_validate_layout( rk_image_layout( $bad ), RK_TEST_HOSTS ) ) >= 1, 'should reject ' . json_encode( $bad ) );
	}
} );
rk_test( 'absolute image from a foreign host is rejected, allowed host accepted', function () {
	t_assert( count( rk_builder_validate_layout( rk_image_layout( 'https://evil.example/a.jpg' ), RK_TEST_HOSTS ) ) === 1 );
	t_eq( rk_builder_validate_layout( rk_image_layout( 'https://cms.example.com/a.jpg' ), RK_TEST_HOSTS ), array() );
	t_eq( rk_builder_validate_layout( rk_image_layout( 'https://cdn.example.org/a.jpg' ), array( 'cms.example.com', 'cdn.example.org' ) ), array() );
	t_eq( rk_builder_validate_layout( rk_image_layout( '/uploads/a.jpg' ), array() ), array(), 'relative is always fine' );
	t_assert( count( rk_builder_validate_layout( rk_image_layout( 'https://cms.example.com@evil.example/a.jpg' ), RK_TEST_HOSTS ) ) === 1, 'userinfo trick' );
	t_assert( count( rk_builder_validate_layout( rk_image_layout( 'https://cms.example.com.evil.example/a.jpg' ), RK_TEST_HOSTS ) ) === 1, 'suffix trick' );
	t_assert( count( rk_builder_validate_layout( rk_image_layout( 'https://cms.example.com:8443/a.jpg' ), RK_TEST_HOSTS ) ) === 0, 'port on allowed host' );
	t_eq( rk_builder_validate_layout( rk_image_layout( 'https://evil.example/a.jpg' ), null ), array(), 'null hosts = structural only (re-reading stored data)' );
} );
rk_test( '101 blocks are rejected, 100 accepted', function () {
	$blocks = array();
	for ( $i = 0; $i < 101; $i++ ) { $blocks[] = array( 'id' => 'b' . $i, 'type' => 'divider', 'props' => array( 'style' => 'solid' ) ); }
	t_assert( count( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => $blocks ) ) ) >= 1 );
	t_eq( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array_slice( $blocks, 0, 100 ) ) ), array() );
} );
rk_test( 'type strictness: strings for numbers, bools for ints, floats, null', function () {
	$mk = function ( $h ) { return array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'spacer', 'props' => array( 'h' => $h ) ) ) ); };
	foreach ( array( '40', true, null, 40.5, 7, 241, array() ) as $bad ) { t_assert( count( rk_builder_validate_layout( $mk( $bad ) ) ) >= 1, 'h=' . json_encode( $bad ) ); }
	t_eq( rk_builder_validate_layout( $mk( 40.0 ) ), array(), '40.0 is an integer in JSON/JS' );
	t_assert( count( rk_builder_validate_layout( array( 'version' => '1', 'blocks' => array() ) ) ) >= 1, 'version "1"' );
	t_assert( count( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array(), 'extra' => 1 ) ) ) >= 1, 'unknown top-level key' );
	t_assert( count( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array( 'x' => array() ) ) ) ) >= 1, 'blocks must be a list' );
	t_assert( count( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'spacer', 'props' => 'x' ) ) ) ) ) >= 1, 'props must be an object' );
	t_assert( count( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'spacer', 'props' => array( 'h' => 40 ), 'x' => 1 ) ) ) ) ) >= 1, 'unknown block key' );
	t_assert( count( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array( 5 ) ) ) ) >= 1, 'block must be an object' );
} );
rk_test( 'text limits and UTF-16 length semantics', function () {
	$mk = function ( $t ) { return array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'text', 'props' => array( 'text' => $t ) ) ) ); };
	t_eq( rk_builder_validate_layout( $mk( str_repeat( 'x', 5000 ) ) ), array() );
	t_assert( count( rk_builder_validate_layout( $mk( str_repeat( 'x', 5001 ) ) ) ) === 1 );
	t_eq( rk_builder_validate_layout( $mk( str_repeat( 'é', 5000 ) ) ), array(), 'multibyte counts chars, not bytes' );
	t_assert( count( rk_builder_validate_layout( $mk( str_repeat( "\u{1F600}", 2501 ) ) ) ) === 1, 'astral chars count as 2 UTF-16 units like JS' );
	t_assert( count( rk_builder_validate_layout( $mk( "\xff\xfe" ) ) ) === 1, 'invalid UTF-8' );
	$h = array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'heading', 'props' => array( 'text' => '', 'level' => 2 ) ) ) );
	t_assert( count( rk_builder_validate_layout( $h ) ) === 1, 'heading min 1' );
	$h['blocks'][0]['props'] = array( 'text' => 'ok', 'level' => 4 );
	t_assert( count( rk_builder_validate_layout( $h ) ) === 1, 'heading level 2|3' );
} );
rk_test( 'image alt: whitespace-only alt is not enough unless decorative', function () {
	$mk = function ( $alt, $dec ) { return array( 'version' => 1, 'blocks' => array( array( 'id' => 'i', 'type' => 'image', 'props' => array( 'url' => '/a.jpg', 'alt' => $alt, 'decorative' => $dec ) ) ) ); };
	t_assert( count( rk_builder_validate_layout( $mk( '   ', false ) ) ) === 1 );
	t_eq( rk_builder_validate_layout( $mk( '   ', true ) ), array() );
	t_eq( rk_builder_validate_layout( $mk( 'ok', false ) ), array() );
} );
rk_test( 'issue paths follow the TS dotted format', function () {
	$issues = rk_builder_validate_layout( rk_link_layout( 'javascript:x' ) );
	t_eq( $issues[0]['path'], 'blocks.0.props.ctaHref' );
	$issues = rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'nope', 'props' => array() ) ) ) );
	t_eq( $issues[0]['path'], 'blocks.0.type' );
} );
rk_test( 'theme: strictness, social, header, footer', function () {
	$base = fx( 'valid/theme-minimal.json' )['document'];
	t_assert( count( rk_builder_validate_theme( $base + array( 'social' => array( 'instagram' => 'http://x.example' ) ) ) ) === 1, 'social must be https' );
	t_assert( count( rk_builder_validate_theme( $base + array( 'social' => array( 'twitter' => 'https://x.example' ) ) ) ) === 1, 'unknown social key' );
	t_assert( count( rk_builder_validate_theme( $base + array( 'header' => array( 'sticky' => 'yes' ) ) ) ) === 1 );
	t_assert( count( rk_builder_validate_theme( $base + array( 'header' => array( 'x' => 1 ) ) ) ) === 1 );
	t_assert( count( rk_builder_validate_theme( $base + array( 'footer' => array( 'columns' => 0 ) ) ) ) === 1 );
	t_assert( count( rk_builder_validate_theme( $base + array( 'logoMediaId' => -1 ) ) ) === 1 );
	t_assert( count( rk_builder_validate_theme( $base + array( 'logoUrl' => 'https://evil.example/l.png' ), RK_TEST_HOSTS ) ) === 1, 'logo host check' );
	t_eq( rk_builder_validate_theme( $base + array( 'social' => array(), 'header' => array(), 'footer' => array() ) ), array(), 'empty sub-objects are valid' );
	foreach ( array( '#fff', '#GGGGGG', "#aabbcc\n", 'aabbcc', '#aabbccdd' ) as $c ) {
		t_assert( count( rk_builder_validate_theme( array_merge( $base, array( 'primary' => $c ) ) ) ) === 1, 'color ' . json_encode( $c ) );
	}
} );
rk_test( 'canonicalize keeps every prop and turns 40.0 into int 40', function () {
	$doc = fx( 'valid/layout-full.json' )['document'];
	t_deep( rk_builder_canonicalize_layout( $doc ), $doc );
	$doc2 = rk_spacer_layout( 40.0 );
	t_eq( rk_builder_canonicalize_layout( $doc2 )['blocks'][0]['props']['h'], 40 );
} );
