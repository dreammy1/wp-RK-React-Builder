<?php
/** CORS: exact-match allow-list, never reflect, never wildcard. */

$rk_allowed = array( 'https://editor.example.com', 'http://localhost:5173' );

rk_test( 'allowed origin is matched exactly and gets the full header set', function () use ( $rk_allowed ) {
	$h = rk_builder_cors_headers( 'https://editor.example.com', $rk_allowed );
	t_eq( $h['Access-Control-Allow-Origin'], 'https://editor.example.com' );
	t_eq( $h['Vary'], 'Origin' );
	t_eq( $h['Access-Control-Allow-Methods'], 'GET, POST, OPTIONS' );
	t_eq( $h['Access-Control-Allow-Headers'], 'Authorization, Content-Type, X-WP-Nonce' );
	t_eq( $h['Access-Control-Allow-Credentials'], 'true' );
	t_eq( rk_builder_match_origin( 'http://localhost:5173', $rk_allowed ), 'http://localhost:5173' );
} );

rk_test( 'evil, null, lookalike and empty origins are rejected; no wildcard is ever sent', function () use ( $rk_allowed ) {
	foreach ( array( 'https://evil.example', 'null', '', 'https://editor.example.com.evil.example', 'http://editor.example.com', 'https://EDITOR.example.com', 'https://editor.example.com/', 'https://editor.example.com:444', '*', 'http://localhost:5174' ) as $o ) {
		t_eq( rk_builder_cors_headers( $o, $rk_allowed ), array(), 'origin ' . json_encode( $o ) );
	}
	t_eq( rk_builder_cors_headers( 'https://anything.example', array() ), array(), 'empty allow-list allows nothing' );
	foreach ( rk_builder_cors_headers( 'https://editor.example.com', $rk_allowed ) as $v ) { t_assert( '*' !== $v ); }
} );

rk_test( 'wildcards and junk in the configured list are dropped, not honoured', function () {
	t_eq( rk_builder_normalize_origins( '*, https://a.example, https://*.example.com, javascript:x, https://b.example/, https://a.example' ), array( 'https://a.example', 'https://b.example' ) );
	t_eq( rk_builder_match_origin( 'https://anything.example', rk_builder_normalize_origins( '*' ) ), null );
} );

rk_test( 'allow-list sources: constant string, option, filter (merged, still exact)', function () {
	update_option( 'rk_builder_allowed_origins', 'https://opt.example' );
	add_filter( 'rk_builder_allowed_origins', function ( $l ) { $l[] = 'https://filter.example'; return $l; } );
	$list = rk_builder_allowed_origins();
	t_assert( in_array( 'https://opt.example', $list, true ) && in_array( 'https://filter.example', $list, true ) );
	t_eq( rk_builder_match_origin( 'https://evil.example', $list ), null );
} );

rk_test( 'core CORS filter is replaced by ours', function () {
	rk_builder_install_cors();
	t_assert( ! empty( $GLOBALS['RK']['filters']['rest_pre_serve_request'] ) );
	$all = array();
	foreach ( $GLOBALS['RK']['filters']['rest_pre_serve_request'] as $fns ) { $all = array_merge( $all, $fns ); }
	t_assert( in_array( 'rk_builder_send_cors_headers', $all, true ) );
	t_assert( ! in_array( 'rest_send_cors_headers', $all, true ) );
} );
