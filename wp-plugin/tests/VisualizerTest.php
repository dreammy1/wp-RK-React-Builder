<?php
/** AI flooring visualizer: quota, input checks, providers (HTTP is intercepted), leads, settings, page script. */

/** A solid-colour PNG of the given size, written to a temp file. */
function rk_viz_png( $w = 700, $h = 520 ) {
	$raw = '';
	$row = "\0" . str_repeat( "\x80\x60\x40", $w );
	for ( $y = 0; $y < $h; $y++ ) { $raw .= $row; }
	$chunk = function ( $type, $data ) { return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) ); };
	$png = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, 2, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( $raw ) ) . $chunk( 'IEND', '' );
	$f = tempnam( sys_get_temp_dir(), 'rkviz' );
	file_put_contents( $f, $png );
	return $f;
}

function rk_viz_options( $extra = array() ) {
	return array_merge( array( 'roomType' => 'kitchen', 'projectType' => 'new_installation', 'preferredStyle' => 'light_natural', 'woodSpecies' => 'oak', 'floorDirection' => 'parallel', 'finishPreference' => 'unsure', 'sheen' => 'matte' ), $extra );
}

function rk_viz_request( $file, $options = null ) {
	$r = new WP_REST_Request( 'POST', '/rk/v1/visualizer/generate' );
	$r->files = null === $file ? array() : array( 'image' => array( 'tmp_name' => $file, 'error' => 0, 'size' => filesize( $file ) ) );
	$r->query = array( 'options' => json_encode( null === $options ? rk_viz_options() : $options ) );
	return $r;
}

function rk_viz_setup( $provider = 'mock', $extra = array() ) {
	$_COOKIE['rk_viz'] = str_repeat( 'a', 32 );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	$d = sys_get_temp_dir() . '/rk-test-uploads';
	if ( ! is_dir( $d ) ) { mkdir( $d, 0755, true ); }
	$GLOBALS['RK']['upload'] = array( 'basedir' => $d, 'baseurl' => 'https://cms.example.com/wp-content/uploads', 'error' => false );
	update_option( 'rk_builder_visualizer', array_merge( array( 'enabled' => true, 'provider' => $provider ), $extra ) );
}

function rk_test_reset_http() { unset( $GLOBALS['RK']['filters']['rk_builder_viz_http'] ); }

function rk_viz_http_stub( array $script, array &$calls ) {
	add_filter( 'rk_builder_viz_http', function ( $pre, $method, $url, $args ) use ( &$script, &$calls ) {
		$calls[] = array( $method, $url, $args );
		$next = array_shift( $script );
		return null === $next ? new WP_Error( 'unexpected', 'no scripted response' ) : $next;
	}, 10, 4 );
}

rk_test( 'visualizer quota: free, needs contact details, bonus, cooldown, rolling', function () {
	$s = rk_builder_viz_defaults(); $now = 1000000;
	$st = array( 'used' => 0, 'bonus_used' => 0, 'lead' => false, 'next_at' => 0 );
	$q = rk_builder_viz_quota( $st, $s, $now );
	t_eq( $q['phase'], 'free' ); t_eq( $q['freeRemaining'], 2 ); t_assert( $q['canGenerate'] );
	$st = rk_builder_viz_consume( rk_builder_viz_consume( $st, 'free', $s, $now ), 'free', $s, $now );
	$q = rk_builder_viz_quota( $st, $s, $now );
	t_eq( $q['phase'], 'needs_lead' ); t_assert( ! $q['canGenerate'] );
	$st['lead'] = true;
	t_eq( rk_builder_viz_quota( $st, $s, $now )['phase'], 'bonus' );
	$st = rk_builder_viz_consume( $st, 'bonus', $s, $now );
	$q  = rk_builder_viz_quota( $st, $s, $now );
	t_eq( $q['phase'], 'cooldown' ); t_assert( ! $q['canGenerate'] ); t_assert( null !== $q['cooldownUntil'] );
	t_eq( rk_builder_viz_quota( $st, $s, $now + 24 * 3600 )['phase'], 'rolling' );
	$none = array_merge( $s, array( 'bonus_count' => 0 ) );
	$st2  = array( 'used' => 2, 'bonus_used' => 0, 'lead' => false, 'next_at' => 0 );
	t_eq( rk_builder_viz_quota( $st2, $none, $now )['phase'], 'rolling', 'no bonus configured: straight to the waiting period logic' );
} );

rk_test( 'visualizer refund gives a spent generation back', function () {
	$s = rk_builder_viz_defaults(); $now = 5;
	$before = array( 'used' => 1, 'bonus_used' => 0, 'lead' => false, 'next_at' => 0 );
	$after  = rk_builder_viz_consume( $before, 'free', $s, $now );
	t_eq( $after['used'], 2 );
	t_eq( rk_builder_viz_refund( $after, 'free', $before )['used'], 1 );
} );

rk_test( 'visualizer prompt reflects the choices and keeps the room untouched', function () {
	$p = rk_builder_viz_prompt( rk_viz_options( array( 'woodSpecies' => 'hickory', 'preferredStyle' => 'gray_weathered', 'floorDirection' => 'herringbone', 'sheen' => 'satin', 'finishPreference' => 'rubio_monocoat', 'customStyleDescription' => '' ) ) );
	t_assert( false !== strpos( $p, 'hickory hardwood' ) && false !== strpos( $p, 'a gray, weathered tone' ) && false !== strpos( $p, 'herringbone' ), 'species, style and direction' );
	t_assert( false !== strpos( $p, 'a satin sheen' ) && false !== strpos( $p, 'hardwax oil' ), 'sheen and finish' );
	t_assert( false !== strpos( $p, 'Do not change the walls.' ), 'preservation rules' );
	$c = rk_builder_viz_prompt( rk_viz_options( array( 'preferredStyle' => 'custom', 'customStyleDescription' => 'deep walnut' ) ) );
	t_assert( false !== strpos( $c, 'in deep walnut' ), 'custom style' );
} );

rk_test( 'visualizer settings: secrets are kept when blank, never printed, bad values fall back', function () {
	update_option( 'rk_builder_visualizer', array( 'hf_token' => 'hf_secret_token_value', 'custom_key' => 'k1' ) );
	$GLOBALS['RK_SECRETS'][] = 'hf_secret_token_value';
	$out = rk_builder_viz_sanitize( array( 'enabled' => '1', 'provider' => 'nonsense', 'hf_token' => '', 'custom_url' => 'javascript:alert(1)', 'custom_header' => 'bad header!', 'free_count' => '99', 'notify_email' => 'not-an-email', 'timeout' => '5' ) );
	t_eq( $out['hf_token'], 'hf_secret_token_value', 'blank keeps the saved token' );
	t_eq( $out['provider'], 'huggingface' ); t_eq( $out['custom_url'], '' ); t_eq( $out['custom_header'], 'Authorization' );
	t_eq( $out['free_count'], 20 ); t_eq( $out['timeout'], 20 ); t_eq( $out['notify_email'], '' );
	$cleared = rk_builder_viz_sanitize( array( 'clear_hf_token' => '1' ) );
	t_eq( $cleared['hf_token'], '' );
} );

rk_test( 'visualizer generate: off or unconfigured is a 503', function () {
	$f = rk_viz_png();
	update_option( 'rk_builder_visualizer', array( 'enabled' => false ) );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $f ) ), 'rk_viz_unavailable', 503 );
	update_option( 'rk_builder_visualizer', array( 'enabled' => true, 'provider' => 'custom', 'custom_url' => '' ) );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $f ) ), 'rk_viz_unavailable', 503, 'custom without a URL' );
	$q = rk_builder_viz_handle_quota( new WP_REST_Request( 'GET', '/' ) );
	t_eq( $q->get_data()['available'], false );
	unlink( $f );
} );

rk_test( 'visualizer generate: photo and option checks', function () {
	rk_viz_setup( 'mock' );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( null ) ), 'rk_viz_no_image', 400 );
	$txt = tempnam( sys_get_temp_dir(), 'rkviz' ); file_put_contents( $txt, 'not an image' );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $txt ) ), 'rk_viz_bad_type', 415 );
	$small = rk_viz_png( 200, 100 );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $small ) ), 'rk_viz_too_small', 400 );
	$ok = rk_viz_png();
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $ok, rk_viz_options( array( 'sheen' => 'shiny' ) ) ) ), 'rk_viz_bad_options', 400 );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $ok, rk_viz_options( array( 'preferredStyle' => 'custom' ) ) ) ), 'rk_viz_bad_options', 400, 'custom needs a description' );
	t_eq( rk_builder_viz_state( str_repeat( 'a', 32 ) )['used'], 0, 'rejected requests spend nothing' );
	foreach ( array( $txt, $small, $ok ) as $f ) { unlink( $f ); }
} );

rk_test( 'visualizer mock backend: success, stored image, quota counts down, then asks for contact details', function () {
	rk_viz_setup( 'mock' );
	$f = rk_viz_png();
	$r = rk_builder_viz_handle_generate( rk_viz_request( $f ) );
	t_ok( $r );
	$d = $r->get_data();
	t_eq( $d['status'], 'success' );
	t_assert( 0 === strpos( $d['imageUrl'], 'https://cms.example.com/wp-content/uploads/rk-visualizer/' ), 'a stored result' );
	t_eq( $d['quota']['freeRemaining'], 1 );
	t_eq( $r->get_headers()['Cache-Control'], 'no-store, private' );
	t_ok( rk_builder_viz_handle_generate( rk_viz_request( $f ) ) );
	$denied = rk_builder_viz_handle_generate( rk_viz_request( $f ) );
	t_eq( $denied->get_status(), 429 );
	t_eq( $denied->get_data()['kind'], 'quota_denied' ); t_eq( $denied->get_data()['phase'], 'needs_lead' );
	unlink( $f );
} );

rk_test( 'visualizer lead: validated, stored once per email, unlocks a bonus, then a waiting period', function () {
	rk_viz_setup( 'mock' );
	$f = rk_viz_png();
	rk_builder_viz_handle_generate( rk_viz_request( $f ) ); rk_builder_viz_handle_generate( rk_viz_request( $f ) );
	$bad = new WP_REST_Request( 'POST', '/rk/v1/visualizer/lead' ); $bad->body = json_encode( array( 'name' => '', 'email' => 'nope', 'phone' => '12' ) );
	$e = t_err( rk_builder_viz_handle_lead( $bad ), 'rk_viz_lead_invalid', 400 );
	t_assert( isset( $e['fields']['name'], $e['fields']['email'], $e['fields']['phone'] ), 'every bad field is named' );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
	$good = new WP_REST_Request( 'POST', '/rk/v1/visualizer/lead' ); $good->body = json_encode( array( 'name' => 'Pat Smith', 'email' => 'Pat@Example.com', 'phone' => '(309) 555-0100' ) );
	$r = rk_builder_viz_handle_lead( $good );
	t_ok( $r ); t_eq( $r->get_data()['phase'], 'bonus' );
	t_eq( count( rk_builder_viz_leads() ), 1 ); t_eq( rk_builder_viz_leads()[0]['email'], 'pat@example.com' );
	rk_builder_viz_handle_lead( $good );
	t_eq( count( rk_builder_viz_leads() ), 1, 'same email is refreshed, not duplicated' );
	t_ok( rk_builder_viz_handle_generate( rk_viz_request( $f ) ), 'the unlocked one works' );
	$denied = rk_builder_viz_handle_generate( rk_viz_request( $f ) );
	t_eq( $denied->get_data()['phase'], 'cooldown' );
	unlink( $f );
} );

rk_test( 'visualizer leads: delete one by email, or all', function () {
	rk_builder_viz_store_lead( array( 'name' => 'A', 'email' => 'a@example.com', 'phone' => '' ) );
	rk_builder_viz_store_lead( array( 'name' => 'B', 'email' => 'b@example.com', 'phone' => '' ) );
	t_eq( rk_builder_viz_delete_leads( ' A@Example.com ' ), 1 );
	t_eq( count( rk_builder_viz_leads() ), 1 ); t_eq( rk_builder_viz_leads()[0]['email'], 'b@example.com' );
	t_eq( rk_builder_viz_delete_leads( 'nobody@example.com' ), 0 );
	t_eq( rk_builder_viz_delete_leads( '', true ), 1 );
	t_eq( rk_builder_viz_leads(), array() );
} );

rk_test( 'visualizer huggingface: submit, poll and fetch through the router, token only in the Authorization header', function () {
	rk_viz_setup( 'huggingface', array( 'hf_token' => 'hf_test_token_123' ) );
	$GLOBALS['RK_SECRETS'][] = 'hf_test_token_123';
	$calls = array();
	rk_viz_http_stub( array(
		array( 'code' => 200, 'body' => json_encode( array( 'request_id' => 'r1', 'status_url' => 'https://queue.fal.run/fal-ai/flux-kontext/requests/r1/status', 'response_url' => 'https://queue.fal.run/fal-ai/flux-kontext/requests/r1' ) ) ),
		array( 'code' => 200, 'body' => json_encode( array( 'status' => 'IN_PROGRESS' ) ) ),
		array( 'code' => 200, 'body' => json_encode( array( 'status' => 'COMPLETED' ) ) ),
		array( 'code' => 200, 'body' => json_encode( array( 'images' => array( array( 'url' => 'https://v3.fal.media/files/out.png' ) ) ) ) ),
	), $calls );
	$f = rk_viz_png();
	$r = rk_builder_viz_handle_generate( rk_viz_request( $f ) );
	t_ok( $r ); $d = $r->get_data();
	t_eq( $d['status'], 'pending' ); t_assert( 1 === preg_match( '/^[a-f0-9]{24}$/', $d['job'] ) );
	t_eq( $calls[0][1], RK_BUILDER_VIZ_HF_URL ); t_eq( $calls[0][2]['headers']['Authorization'], 'Bearer hf_test_token_123' );
	$body = json_decode( $calls[0][2]['body'], true );
	t_assert( 0 === strpos( $body['image_url'], 'data:image/png;base64,' ) && false !== strpos( $body['prompt'], 'photorealistic' ), 'prompt and image sent' );
	$status = new WP_REST_Request( 'GET', '/rk/v1/visualizer/status' ); $status->query = array( 'job' => $d['job'] );
	t_eq( rk_builder_viz_handle_status( $status )->get_data()['status'], 'pending' );
	$done = rk_builder_viz_handle_status( $status )->get_data();
	t_eq( $done['status'], 'success' ); t_eq( $done['imageUrl'], 'https://v3.fal.media/files/out.png' );
	t_eq( $calls[1][1], 'https://router.huggingface.co/fal-ai/fal-ai/flux-kontext/requests/r1/status?_subdomain=queue', 'queue URLs go back through the router' );
	t_err( rk_builder_viz_handle_status( $status ), 'rk_viz_no_job', 404, 'a finished job is gone' );
	unlink( $f );
} );

rk_test( 'visualizer huggingface: a failed submit or a failed job gives the generation back', function () {
	rk_viz_setup( 'huggingface', array( 'hf_token' => 'hf_test_token_123' ) );
	$calls = array();
	rk_viz_http_stub( array( array( 'code' => 500, 'body' => 'oops' ) ), $calls );
	$f = rk_viz_png();
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $f ) ), 'rk_viz_provider', 502 );
	t_eq( rk_builder_viz_state( str_repeat( 'a', 32 ) )['used'], 0, 'refunded' );

	rk_test_reset_http();
	$calls = array();
	rk_viz_http_stub( array(
		array( 'code' => 200, 'body' => json_encode( array( 'status_url' => 'https://queue.fal.run/x/status', 'response_url' => 'https://queue.fal.run/x' ) ) ),
		array( 'code' => 200, 'body' => json_encode( array( 'status' => 'FAILED' ) ) ),
	), $calls );
	$d = rk_builder_viz_handle_generate( rk_viz_request( $f ) )->get_data();
	t_eq( rk_builder_viz_state( str_repeat( 'a', 32 ) )['used'], 1, 'spent while it runs' );
	$st = new WP_REST_Request( 'GET', '/' ); $st->query = array( 'job' => $d['job'] );
	t_err( rk_builder_viz_handle_status( $st ), 'rk_viz_provider', 502 );
	t_eq( rk_builder_viz_state( str_repeat( 'a', 32 ) )['used'], 0, 'refunded after the failed job' );
	unlink( $f );
} );

rk_test( 'visualizer huggingface: queue URLs that are not fal.run are refused', function () {
	t_eq( rk_builder_viz_router_url( 'https://evil.example.com/a/b' ), '' );
	t_eq( rk_builder_viz_router_url( 'https://queue.fal.run/a/b' ), 'https://router.huggingface.co/fal-ai/a/b?_subdomain=queue' );
} );

rk_test( 'visualizer gemini: inline photo in, edited image out; key only in the header; failures refund', function () {
	rk_viz_setup( 'gemini', array( 'gemini_key' => 'AIza_test_gemini_key_77', 'gemini_model' => 'gemini-2.5-flash-image' ) );
	$GLOBALS['RK_SECRETS'][] = 'AIza_test_gemini_key_77';
	$f = rk_viz_png(); $png = file_get_contents( $f );
	$calls = array();
	rk_viz_http_stub( array( array( 'code' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array(
		array( 'text' => 'Here is your room.' ), array( 'inlineData' => array( 'mimeType' => 'image/png', 'data' => base64_encode( $png ) ) ),
	) ) ) ) ) ) ) ), $calls );
	$d = rk_builder_viz_handle_generate( rk_viz_request( $f ) )->get_data();
	t_eq( $d['status'], 'success' );
	t_assert( 0 === strpos( $d['imageUrl'], 'https://cms.example.com/wp-content/uploads/rk-visualizer/' ), 'result is stored' );
	t_eq( $calls[0][1], 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent', 'model is in the path, key is not in the URL' );
	t_eq( $calls[0][2]['headers']['x-goog-api-key'], 'AIza_test_gemini_key_77' );
	$sent = json_decode( $calls[0][2]['body'], true );
	$parts = $sent['contents'][0]['parts'];
	t_assert( false !== strpos( $parts[0]['text'], 'photorealistic' ) && 'image/png' === $parts[1]['inline_data']['mime_type'] && base64_decode( $parts[1]['inline_data']['data'] ) === $png, 'prompt and photo sent inline' );

	rk_test_reset_http(); $calls = array();
	rk_viz_http_stub( array( array( 'code' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'inline_data' => array( 'mime_type' => 'image/png', 'data' => base64_encode( $png ) ) ) ) ) ) ) ) ) ) ), $calls );
	$_COOKIE['rk_viz'] = str_repeat( 'b', 32 );
	t_eq( rk_builder_viz_handle_generate( rk_viz_request( $f ) )->get_data()['status'], 'success', 'snake_case inline_data is accepted too' );

	rk_test_reset_http(); $calls = array();
	rk_viz_http_stub( array( array( 'code' => 400, 'body' => json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) ) ) ), $calls );
	$_COOKIE['rk_viz'] = str_repeat( 'c', 32 );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $f ) ), 'rk_viz_provider', 502 );
	t_eq( rk_builder_viz_state( str_repeat( 'c', 32 ) )['used'], 0, 'refunded' );

	rk_test_reset_http(); $calls = array();
	rk_viz_http_stub( array( array( 'code' => 200, 'body' => json_encode( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) ) ) ), $calls );
	$_COOKIE['rk_viz'] = str_repeat( 'd', 32 );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $f ) ), 'rk_viz_provider', 502, 'no image part' );
	unlink( $f );
} );

rk_test( 'visualizer gemini: needs a key, keeps it when the field is blank, falls back to the default model', function () {
	update_option( 'rk_builder_visualizer', array( 'enabled' => true, 'provider' => 'gemini', 'gemini_key' => '' ) );
	t_assert( ! rk_builder_viz_ready( rk_builder_viz_settings() ), 'no key, not ready' );
	update_option( 'rk_builder_visualizer', array( 'enabled' => true, 'provider' => 'gemini', 'gemini_key' => 'k' ) );
	t_assert( rk_builder_viz_ready( rk_builder_viz_settings() ) );
	$out = rk_builder_viz_sanitize( array( 'provider' => 'gemini', 'gemini_key' => '', 'gemini_model' => 'bad model/../x' ) );
	t_eq( $out['gemini_key'], 'k' ); t_eq( $out['gemini_model'], 'gemini-2.5-flash-image' ); t_eq( $out['provider'], 'gemini' );
	t_eq( rk_builder_viz_sanitize( array( 'gemini_model' => 'gemini-3-pro-image-preview' ) )['gemini_model'], 'gemini-3-pro-image-preview' );
} );

rk_test( 'visualizer custom backend: image URL, base64 and polled responses; key header is configurable', function () {
	rk_viz_setup( 'custom', array( 'custom_url' => 'https://api.example.com/visualize', 'custom_key' => 'sk_custom_key_9', 'custom_header' => 'X-API-Key' ) );
	$GLOBALS['RK_SECRETS'][] = 'sk_custom_key_9';
	$f = rk_viz_png();
	$calls = array();
	rk_viz_http_stub( array( array( 'code' => 200, 'body' => json_encode( array( 'imageUrl' => 'https://cdn.example.com/out.jpg' ) ) ) ), $calls );
	$d = rk_builder_viz_handle_generate( rk_viz_request( $f ) )->get_data();
	t_eq( $d['status'], 'success' ); t_eq( $d['imageUrl'], 'https://cdn.example.com/out.jpg' );
	t_eq( $calls[0][2]['headers']['X-API-Key'], 'sk_custom_key_9' ); t_assert( ! isset( $calls[0][2]['headers']['Authorization'] ) );
	$sent = json_decode( $calls[0][2]['body'], true );
	t_assert( isset( $sent['prompt'], $sent['image'], $sent['options']['roomType'] ), 'the backend gets prompt, image and options' );

	rk_test_reset_http();
	$png = file_get_contents( $f ); $calls = array();
	rk_viz_http_stub( array( array( 'code' => 200, 'body' => json_encode( array( 'image' => base64_encode( $png ) ) ) ) ), $calls );
	$d = rk_builder_viz_handle_generate( rk_viz_request( $f ) )->get_data();
	t_assert( 0 === strpos( $d['imageUrl'], 'https://cms.example.com/wp-content/uploads/rk-visualizer/' ), 'base64 result is stored' );

	rk_test_reset_http();
	$calls = array();
	rk_viz_http_stub( array(
		array( 'code' => 202, 'body' => json_encode( array( 'statusUrl' => 'https://api.example.com/jobs/1' ) ) ),
		array( 'code' => 200, 'body' => json_encode( array( 'status' => 'processing' ) ) ),
		array( 'code' => 200, 'body' => json_encode( array( 'status' => 'completed', 'imageUrl' => 'https://cdn.example.com/two.jpg' ) ) ),
	), $calls );
	$_COOKIE['rk_viz'] = str_repeat( 'b', 32 ); // a fresh visitor
	$d = rk_builder_viz_handle_generate( rk_viz_request( $f ) )->get_data();
	t_eq( $d['status'], 'pending' );
	$st = new WP_REST_Request( 'GET', '/' ); $st->query = array( 'job' => $d['job'] );
	t_eq( rk_builder_viz_handle_status( $st )->get_data()['status'], 'pending' );
	t_eq( rk_builder_viz_handle_status( $st )->get_data()['imageUrl'], 'https://cdn.example.com/two.jpg' );

	rk_test_reset_http();
	$calls = array();
	rk_viz_http_stub( array( array( 'code' => 200, 'body' => json_encode( array( 'message' => 'no image here' ) ) ) ), $calls );
	$_COOKIE['rk_viz'] = str_repeat( 'c', 32 );
	t_err( rk_builder_viz_handle_generate( rk_viz_request( $f ) ), 'rk_viz_provider', 502 );
	unlink( $f );
} );

rk_test( 'visualizer: a job belongs to the browser that started it', function () {
	rk_viz_setup( 'huggingface', array( 'hf_token' => 't' ) );
	$calls = array();
	rk_viz_http_stub( array( array( 'code' => 200, 'body' => json_encode( array( 'status_url' => 'https://queue.fal.run/x/status', 'response_url' => 'https://queue.fal.run/x' ) ) ) ), $calls );
	$f = rk_viz_png();
	$d = rk_builder_viz_handle_generate( rk_viz_request( $f ) )->get_data();
	$_COOKIE['rk_viz'] = str_repeat( 'd', 32 );
	$st = new WP_REST_Request( 'GET', '/' ); $st->query = array( 'job' => $d['job'] );
	t_err( rk_builder_viz_handle_status( $st ), 'rk_viz_no_job', 404 );
	unlink( $f );
} );

rk_test( 'visualizer: per-IP hourly cap', function () {
	rk_viz_setup( 'mock', array( 'free_count' => 20, 'ip_per_hour' => 2 ) );
	$f = rk_viz_png();
	t_ok( rk_builder_viz_handle_generate( rk_viz_request( $f ) ) ); t_ok( rk_builder_viz_handle_generate( rk_viz_request( $f ) ) );
	$r = rk_builder_viz_handle_generate( rk_viz_request( $f ) );
	t_eq( $r->get_status(), 429 ); t_eq( $r->get_data()['phase'], 'rate_limited' );
	unlink( $f );
} );

rk_test( 'visualizer: the page script is added only for pages with the block', function () {
	$GLOBALS['RK']['scripts'] = array();
	rk_builder_viz_enqueue( rk_test_layout( array( rk_test_block( 'heading', array( 'text' => 'x', 'level' => 2, 'align' => 'left' ) ) ) ) );
	t_assert( empty( $GLOBALS['RK']['scripts']['rk-builder-viz'] ), 'no block, no script' );
	rk_builder_viz_enqueue( rk_test_layout( array( rk_test_block( 'visualizer', array( 'cities' => '', 'submitLabel' => 'Go', 'ctaLabel' => '', 'ctaHref' => '' ) ) ) ) );
	$js = implode( '', $GLOBALS['RK']['scripts']['rk-builder-viz']['inline'] );
	t_assert( 0 === strpos( $js, 'window.rkViz={"api":"' ) && false !== strpos( $js, '/visualizer' ), 'api root is set' );
	t_assert( false !== strpos( $js, 'data-viz-form' ), 'behaviour is included' );
} );

rk_test( 'visualizer block renders its form, every choice and the preview', function () {
	$html = rk_builder_render_visualizer( array( 'cities' => "Peoria\nPeoria Heights", 'submitLabel' => 'Create', 'ctaLabel' => 'Talk', 'ctaHref' => '/contact' ) );
	foreach ( array( 'name="roomType"', 'value="retail_gym"', 'value="semi_gloss"', 'name="serviceCity"', 'value="peoria_heights"', 'data-viz-img', 'data-viz-lead', 'Upload a room photo' ) as $needle ) {
		t_assert( false !== strpos( $html, $needle ), 'missing ' . $needle );
	}
	t_assert( false === strpos( $html, '<script' ), 'no script in the markup' );
} );
