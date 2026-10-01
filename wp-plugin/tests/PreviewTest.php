<?php
/** Public preview route, token purpose/TTL, preview-token URL, REST invalid preview. */

function rk_preview_get( $id, $token, $extra = array() ) {
	return array_merge( array( 'rk_preview' => '1', 'page' => (string) $id, 'token' => $token ), $extra );
}
function rk_preview_header( $name ) {
	foreach ( $GLOBALS['RK']['headers'] as $h ) { if ( 0 === stripos( $h, $name . ':' ) ) { return trim( substr( $h, strlen( $name ) + 1 ) ); } }
	return null;
}
function rk_draft_page_with_published( $draft_text = 'DRAFT TEXT', $pub_text = 'PUBLISHED TEXT' ) {
	$id = rk_pub_page( rk_test_layout( array( rk_test_block( 'heading', array( 'text' => $pub_text, 'level' => 2 ) ) ) ), 'live' );
	$GLOBALS['RK']['meta'][ $id ]['_rk_layout_draft'] = json_encode( rk_test_layout( array( rk_test_block( 'heading', array( 'text' => $draft_text, 'level' => 2 ) ) ) ) );
	return $id;
}

rk_test( 'preview token: carries the purpose, other purposes and legacy-less tokens behave as documented', function () {
	$id = rk_test_page();
	$t  = rk_builder_create_preview_token( $id )['token'];
	$GLOBALS['RK_SECRETS'][] = $t;
	list( $p, $s ) = explode( '.', $t );
	$payload = json_decode( rk_builder_b64url_decode( $p ), true );
	t_eq( $payload['u'], 'rk_builder_preview' );
	t_eq( $payload['p'], $id );
	t_assert( rk_builder_verify_preview_token( $t, $id ) );
	$sign = function ( array $data ) { $pl = rk_builder_b64url_encode( json_encode( $data ) ); return $pl . '.' . rk_builder_b64url_encode( hash_hmac( 'sha256', $pl, rk_builder_preview_secret(), true ) ); };
	$exp  = rk_builder_now() + 100;
	t_assert( rk_builder_verify_preview_token( $sign( array( 'p' => $id, 'e' => $exp ) ), $id ), 'tokens issued before the purpose claim still verify until they expire' );
	t_assert( ! rk_builder_verify_preview_token( $sign( array( 'p' => $id, 'e' => $exp, 'u' => 'something_else' ) ), $id ), 'correctly signed, wrong purpose' );
} );

rk_test( 'preview token: ttl comes from the preview_ttl setting; filter still applies', function () {
	t_eq( rk_builder_preview_ttl(), 900 );
	update_option( 'rk_builder_settings', array( 'preview_ttl' => 120 ) );
	t_eq( rk_builder_preview_ttl(), 120 );
	add_filter( 'rk_builder_preview_ttl', function ( $t ) { return $t + 5; } );
	t_eq( rk_builder_preview_ttl(), 125 );
} );

rk_test( 'preview-token endpoint returns the preview URL on this site (setting TTL honoured)', function () {
	$id = rk_test_page( 'draft', 'wip' );
	update_option( 'rk_builder_settings', array( 'preview_ttl' => 300 ) );
	rk_test_login( 'editor' );
	$d = t_ok( rk_post( '/rk/v1/builder/preview-token/' . $id ) );
	$GLOBALS['RK_SECRETS'][] = $d['token'];
	t_eq( $d['url'], 'https://cms.example.com/?rk_preview=1&page=' . $id . '&token=' . $d['token'] );
	t_eq( strtotime( $d['expiresAt'] ), rk_builder_now() + 300 );
} );

rk_test( 'preview-token: headless mode (RK_BUILDER_FRONTEND_URL) returns no url; RK_BUILDER_PREVIEW_TTL overrides the setting (fresh processes)', function () {
	$run = function ( $mode ) {
		$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/subprocess-constants.php' ) . ' ' . escapeshellarg( $mode ) . ' 2>&1' );
		$d   = json_decode( (string) $out, true );
		t_assert( is_array( $d ), 'subprocess output: ' . $out );
		return $d;
	};
	t_eq( $run( 'headless' )['keys'], array( 'token', 'expiresAt' ) );
	$d = $run( 'ttl' );
	t_eq( $d['ttl'], 77 );
	t_assert( in_array( 'url', $d['keys'], true ), 'url present when no frontend constant' );
} );

rk_test( 'preview route: valid token renders the DRAFT in a noindex, no-store document with status 200', function () {
	rk_test_hooks();
	$id    = rk_draft_page_with_published();
	$token = rk_builder_create_preview_token( $id )['token'];
	$GLOBALS['RK_SECRETS'][] = $token;
	$res = rk_builder_preview_response( rk_preview_get( $id, $token ) );
	t_eq( $res['status'], 200 );
	$html = $res['html'];
	t_assert( 0 === strpos( $html, '<!doctype html>' ) );
	t_assert( false !== strpos( $html, '<meta name="robots" content="noindex, nofollow">' ) );
	t_assert( false !== strpos( $html, 'DRAFT TEXT' ) && false === strpos( $html, 'PUBLISHED TEXT' ), 'the draft, never the snapshot' );
	t_assert( false !== strpos( $html, 'Draft preview' ) && false === stripos( $html, '<script' ) );
	t_assert( false === strpos( $html, $token ), 'the token is not echoed into the document' );
	t_assert( false === strpos( $html, 'rel="canonical"' ) && false === strpos( $html, 'og:' ), 'no canonical/OG in a preview' );
	// full request path: headers + body + status
	$_GET = rk_preview_get( $id, $token );
	ob_start();
	rk_builder_maybe_serve_preview();
	$body = ob_get_clean();
	t_eq( $body, $html );
	t_eq( $GLOBALS['RK']['status'], 200 );
	t_eq( rk_preview_header( 'Cache-Control' ), 'no-store, private' );
	t_eq( rk_preview_header( 'X-Robots-Tag' ), 'noindex, nofollow' );
	t_eq( rk_preview_header( 'Referrer-Policy' ), 'no-referrer' );
	t_assert( ! empty( $GLOBALS['RK']['nocache'] ), 'nocache_headers() called' );
	// it is a registered template_redirect handler
	t_assert( isset( $GLOBALS['RK']['filters']['template_redirect'][0] ) );
} );

rk_test( 'preview route: unpublished (draft) pages preview too; non-preview requests are ignored', function () {
	rk_test_hooks();
	$id = rk_test_page( 'draft', 'wip' );
	$GLOBALS['RK']['meta'][ $id ]['_rk_layout_draft'] = json_encode( rk_test_layout( array( rk_test_block( 'spacer', array( 'h' => 96 ) ) ) ) );
	$token = rk_builder_create_preview_token( $id )['token'];
	t_eq( rk_builder_preview_response( rk_preview_get( $id, $token ) )['status'], 200 );
	t_eq( rk_builder_preview_response( array() ), null );
	t_eq( rk_builder_preview_response( array( 'p' => '1', 'token' => $token ) ), null );
	$_GET = array();
	ob_start();
	rk_builder_maybe_serve_preview();
	t_eq( ob_get_clean(), '' );
	t_assert( ! isset( $GLOBALS['RK']['status'] ), 'no status/headers for ordinary requests' );
} );

rk_test( 'preview route: invalid, expired, tampered, missing or wrong-page tokens are a real 404 and never show published content', function () {
	rk_test_hooks();
	$id    = rk_draft_page_with_published();
	$other = rk_draft_page_with_published( 'OTHER DRAFT', 'OTHER PUBLISHED' );
	$token = rk_builder_create_preview_token( $id )['token'];
	list( $p, $s ) = explode( '.', $token );
	$tampered = $p . '.' . ( 'A' === $s[0] ? 'B' : 'A' ) . substr( $s, 1 );
	$cases = array(
		'tampered'   => rk_preview_get( $id, $tampered ),
		'wrong page' => rk_preview_get( $other, $token ),
		'no token'   => array( 'rk_preview' => '1', 'page' => (string) $id ),
		'empty'      => rk_preview_get( $id, '' ),
		'junk'       => rk_preview_get( $id, 'x.y' ),
		'array'      => rk_preview_get( $id, array( 'a' ) ),
		'no page'    => array( 'rk_preview' => '1', 'token' => $token ),
		'bad page'   => rk_preview_get( 'abc', $token ),
		'missing'    => rk_preview_get( 99999, $token ),
		'neg page'   => rk_preview_get( '-1', $token ),
	);
	foreach ( $cases as $name => $get ) {
		$res = rk_builder_preview_response( $get );
		t_eq( $res['status'], 404, $name );
		t_eq( $res['html'], '', $name );
		unset( $GLOBALS['RK']['status'] );
		$GLOBALS['RK']['headers'] = array();
		$_GET = $get;
		ob_start();
		rk_builder_maybe_serve_preview();
		t_eq( ob_get_clean(), '', $name . ': nothing is echoed (the theme renders its own 404)' );
		t_eq( $GLOBALS['RK']['status'], 404, $name . ' status_header(404)' );
		t_eq( rk_preview_header( 'Cache-Control' ), 'no-store, private', $name );
		t_assert( $GLOBALS['wp_query']->is_404 && array() === $GLOBALS['wp_query']->posts, $name . ' query turned into a 404' );
		$GLOBALS['wp_query'] = new RK_Test_WP_Query_Global();
	}
	// expired
	add_filter( 'rk_builder_now', function ( $t ) { return $t + 901; } );
	t_eq( rk_builder_preview_response( rk_preview_get( $id, $token ) )['status'], 404, 'expired' );
	// plugin disabled
	remove_filter( 'rk_builder_now', $GLOBALS['RK']['filters']['rk_builder_now'][10][0] );
	update_option( 'rk_builder_settings', array( 'enabled' => false ) );
	t_eq( rk_builder_preview_response( rk_preview_get( $id, $token ) )['status'], 404, 'disabled' );
} );

rk_test( 'REST public page: an invalid/expired preview token is 404 rk_preview_invalid, never the published snapshot; no param is unchanged', function () {
	$id = rk_pub_page( rk_spacer_layout( 40 ), 'about' );
	t_eq( t_ok( rk_get( '/rk/v1/public/page/about' ) )['layout']['blocks'][0]['props']['h'], 40 );
	$data = t_err( rk_get( '/rk/v1/public/page/about', array( 'preview' => 'nope.nope' ) ), 'rk_preview_invalid', 404 );
	t_assert( ! isset( $data['layout'] ) );
	t_err( rk_get( '/rk/v1/public/page/about', array( 'preview' => '' ) ), 'rk_preview_invalid', 404 );
	$t = rk_builder_create_preview_token( $id )['token'];
	t_eq( t_ok( rk_get( '/rk/v1/public/page/about', array( 'preview' => $t ) ) )['preview'], true );
	t_err( rk_get( '/rk/v1/public/page/other', array( 'preview' => $t ) ), 'rk_preview_invalid', 404, 'a token for another page is not valid here' );
	t_err( rk_get( '/rk/v1/public/page/other' ), 'rk_not_found', 404, 'unknown slug without a preview stays a plain 404' );
} );

rk_test( 'logs never contain preview tokens or the secret', function () {
	rk_test_hooks();
	add_filter( 'rk_builder_log_level', function () { return 'debug'; } );
	rk_test_log_start();
	$id    = rk_draft_page_with_published();
	$token = rk_builder_create_preview_token( $id )['token'];
	$bad   = $token . 'zz';
	rk_builder_preview_response( rk_preview_get( $id, $bad ) );
	rk_builder_preview_response( rk_preview_get( $id, $token ) );
	rk_builder_log( 'error', 'oops', array( 'url' => 'https://x.example/?token=' . $token ) );
	$log = rk_test_log_read();
	t_assert( false !== strpos( $log, 'preview_rejected' ) );
	t_assert( false === strpos( $log, $token ) && false === strpos( $log, 'test-only-preview-secret' ), $log );
} );
