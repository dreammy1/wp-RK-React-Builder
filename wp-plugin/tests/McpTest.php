<?php
/** MCP server: off by default, access levels, tools run the real routes with the signed-in user's rights, audit log. */

function rk_mcp( $method, $params = array(), $id = 1 ) {
	$r = rk_post( '/rk/v1/builder-mcp', array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ) );
	return $r;
}
function rk_mcp_call( $tool, $args = array() ) {
	$r = t_ok( rk_mcp( 'tools/call', array( 'name' => $tool, 'arguments' => $args ) ) );
	$res = $r['result'];
	return array( 'error' => $res['isError'], 'data' => json_decode( $res['content'][0]['text'], true ) );
}
function rk_mcp_on( $level = 'write' ) {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/mcp', array( 'enabled' => true, 'level' => $level ) ) );
}
function rk_mcp_names() {
	$l = t_ok( rk_mcp( 'tools/list' ) )['result']['tools'];
	return array_map( function ( $t ) { return $t['name']; }, $l );
}

rk_test( 'mcp: off until an administrator turns it on; then it needs a signed-in user who may edit pages', function () {
	rk_test_login( 'editor' );
	t_err( rk_mcp( 'initialize' ), 'rk_mcp_disabled', 403 );
	rk_mcp_on();
	rk_test_login( 'anon' );
	t_err( rk_mcp( 'initialize' ), 'rk_unauthorized', 401 );
	rk_test_login( 'subscriber' );
	t_err( rk_mcp( 'initialize' ), 'rk_forbidden', 403 );
	rk_test_login( 'editor' );
	$i = t_ok( rk_mcp( 'initialize', array( 'protocolVersion' => '2025-03-26' ) ) )['result'];
	t_eq( $i['protocolVersion'], '2025-03-26' );
	t_eq( $i['serverInfo']['name'], 'rk-builder' );
	t_assert( false !== strpos( $i['instructions'], 'rkb_overview' ) );
} );

rk_test( 'mcp: settings are for administrators; level and flags are validated', function () {
	rk_test_login( 'editor' );
	t_err( rk_get( '/rk/v1/builder/mcp' ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	$d = t_ok( rk_get( '/rk/v1/builder/mcp' ) );
	t_eq( $d['settings'], array( 'enabled' => false, 'level' => 'write' ) );
	t_eq( $d['endpoint'], 'https://cms.example.com/wp-json/rk/v1/builder-mcp' );
	t_err( rk_post( '/rk/v1/builder/mcp', array( 'level' => 'root' ) ), 'rk_invalid_mcp', 400 );
	t_err( rk_post( '/rk/v1/builder/mcp', array( 'enabled' => 'yes' ) ), 'rk_invalid_mcp', 400 );
	t_err( rk_post( '/rk/v1/builder/mcp', array( 'bogus' => 1 ) ), 'rk_invalid_mcp', 400 );
	t_eq( t_ok( rk_post( '/rk/v1/builder/mcp', array( 'enabled' => true, 'level' => 'read' ) ) )['settings'], array( 'enabled' => true, 'level' => 'read' ) );
} );

rk_test( 'mcp: the access level decides which tools exist (read < write < full)', function () {
	rk_mcp_on( 'read' );
	$read = rk_mcp_names();
	t_assert( in_array( 'rkb_list_pages', $read, true ) && in_array( 'rkb_block_catalog', $read, true ) );
	t_assert( ! in_array( 'rkb_create_page', $read, true ) && ! in_array( 'rkb_delete_media', $read, true ), 'no write tools at read level' );
	rk_mcp_on( 'write' );
	$write = rk_mcp_names();
	t_assert( in_array( 'rkb_create_page', $write, true ) && in_array( 'rkb_trash_page', $write, true ) );
	t_assert( ! in_array( 'rkb_delete_media', $write, true ) && ! in_array( 'rkb_set_code', $write, true ), 'no permanent deletes or code injection at write level' );
	rk_mcp_on( 'full' );
	t_assert( in_array( 'rkb_delete_media', rk_mcp_names(), true ) && in_array( 'rkb_set_code', rk_mcp_names(), true ) );
	$t = t_ok( rk_mcp( 'tools/list' ) )['result']['tools'];
	foreach ( $t as $tool ) {
		t_eq( $tool['inputSchema']['type'], 'object', $tool['name'] );
		t_assert( isset( $tool['annotations']['readOnlyHint'] ), $tool['name'] );
	}
} );

rk_test( 'mcp: a tool above the current level is refused even if named directly', function () {
	rk_mcp_on( 'read' );
	rk_test_login( 'editor' );
	$r = rk_mcp_call( 'rkb_create_page', array( 'title' => 'Nope' ) );
	t_eq( $r['error'], true );
	t_eq( $r['data']['code'], 'rk_mcp_unknown_tool' );
} );

rk_test( 'mcp: tools run the real routes, with the validation and rights of the signed-in user', function () {
	rk_mcp_on( 'write' );
	rk_test_login( 'editor' );
	$made = rk_mcp_call( 'rkb_create_page', array( 'title' => 'AI page' ) );
	t_eq( $made['error'], false );
	$id = (int) $made['data']['page']['id'];
	t_assert( $id > 0 );
	$list = rk_mcp_call( 'rkb_list_pages', array( 'search' => 'AI' ) );
	t_eq( $list['error'], false );
	t_eq( count( $list['data']['pages'] ), 1 );
	$lay = rk_mcp_call( 'rkb_get_layout', array( 'id' => $id ) );
	$rev = (int) $lay['data']['revision'];
	$bad = rk_mcp_call( 'rkb_save_layout', array( 'id' => $id, 'status' => 'draft', 'expectedRevision' => $rev, 'layout' => array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'hero', 'props' => array( 'heading' => 'Hi', 'evil' => 1 ) ) ) ) ) );
	t_eq( $bad['error'], true );
	t_eq( $bad['data']['code'], 'rk_invalid_layout', 'strict validation applies' );
	$ok = rk_mcp_call( 'rkb_save_layout', array( 'id' => $id, 'status' => 'draft', 'expectedRevision' => $rev, 'layout' => rk_spacer_layout( 24 ) ) );
	t_eq( $ok['error'], false );
	t_eq( $ok['data']['revision'], $rev + 1 );
	$no = rk_mcp_call( 'rkb_get_site' );
	t_eq( $no['error'], true );
	t_eq( $no['data']['code'], 'rk_forbidden', 'an editor still cannot read admin settings through MCP' );
	t_eq( rk_mcp_call( 'rkb_get_layout', array() )['data']['code'], 'rk_mcp_bad_arguments', 'a missing path argument is reported' );
	t_eq( rk_mcp_call( 'rkb_trash_page', array( 'id' => $id ) )['error'], false );
} );

rk_test( 'mcp: the block catalogue comes from the validator specs', function () {
	rk_mcp_on( 'read' );
	$c = rk_mcp_call( 'rkb_block_catalog' )['data'];
	t_eq( $c['blocks']['hero']['heading']['type'], 'text' );
	t_eq( $c['blocks']['hero']['heading']['required'], true );
	t_eq( $c['blocks']['hero']['bgUrl']['required'], false );
} );

rk_test( 'mcp: permanent deletes need the full level; every call is logged; the log can be cleared', function () {
	$GLOBALS['RK']['attachments'][930] = array( 'url' => 'https://cms.example.com/u/z.jpg', 'w' => 10, 'h' => 10, 'title' => 'z' );
	$GLOBALS['RK']['posts'][930] = (object) array( 'ID' => 930, 'post_type' => 'attachment', 'post_title' => 'z', 'post_excerpt' => '', 'post_content' => '', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' );
	rk_mcp_on( 'write' );
	t_eq( rk_mcp_call( 'rkb_delete_media', array( 'id' => 930 ) )['data']['code'], 'rk_mcp_unknown_tool' );
	t_assert( isset( $GLOBALS['RK']['posts'][930] ), 'still there' );
	rk_mcp_on( 'full' );
	t_eq( rk_mcp_call( 'rkb_delete_media', array( 'id' => 930 ) )['data']['deleted'], 930 );
	t_assert( ! isset( $GLOBALS['RK']['posts'][930] ) );
	$log = t_ok( rk_get( '/rk/v1/builder/mcp' ) )['log'];
	t_eq( $log[0]['tool'], 'rkb_delete_media' );
	t_eq( $log[0]['ok'], true );
	t_eq( $log[1]['ok'], false, 'the refused call is in the log too' );
	t_eq( t_ok( rk_post( '/rk/v1/builder/mcp', array( 'clearLog' => true ) ) )['log'], array() );
} );

rk_test( 'mcp: unknown methods and bad bodies get JSON-RPC errors; notifications get no answer', function () {
	rk_mcp_on();
	rk_test_login( 'editor' );
	t_eq( t_ok( rk_mcp( 'nope' ) )['error']['code'], -32601 );
	$r = rk_post( '/rk/v1/builder-mcp', 'not json' );
	t_eq( t_ok( $r )['error']['code'], -32700 );
	$n = rk_post( '/rk/v1/builder-mcp', array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
	t_eq( $n->get_status(), 202 );
	t_assert( is_object( t_ok( rk_mcp( 'ping' ) )['result'] ), 'ping answers with an empty object' );
} );

function rk_mcp_with_key( $token, $method, $params = array(), $header = 'X-RK-API-Key' ) {
	$h = 'Authorization' === $header ? array( 'Authorization' => 'Bearer ' . $token ) : array( $header => $token );
	return rk_test_request( 'POST', '/rk/v1/builder-mcp', array( 'body' => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ), 'headers' => $h ) );
}

rk_test( 'mcp keys: made by an administrator, shown once, stored only as a hash', function () {
	rk_test_login( 'editor' );
	t_err( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'x' ) ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/mcp/keys', array( 'level' => 'write' ) ), 'rk_invalid_mcp', 400 );
	t_err( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'x', 'level' => 'root' ) ), 'rk_invalid_mcp', 400 );
	$d = t_ok( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'Claude <b>Desktop</b>', 'level' => 'read' ) ) );
	$token = $d['created']['token'];
	t_assert( 0 === strpos( $token, 'rkb_' ) && strlen( $token ) >= 40 );
	t_eq( $d['keys'][0]['name'], 'Claude Desktop' );
	t_eq( $d['keys'][0]['prefix'], substr( $token, 0, 8 ) );
	t_eq( $d['keys'][0]['user'], 'user1' );
	t_eq( isset( $d['keys'][0]['hash'] ), false, 'the hash is not sent back' );
	t_assert( false === strpos( json_encode( t_ok( rk_get( '/rk/v1/builder/mcp' ) ) ), $token ), 'the key is never shown again' );
	t_assert( false === strpos( json_encode( get_option( 'rk_builder_mcp_keys' ) ), $token ), 'the key is not stored' );
	t_eq( get_option( 'rk_builder_mcp_keys' )[0]['hash'], hash( 'sha256', $token ) );
} );

rk_test( 'mcp keys: a key signs in without a WordPress session, in either header; its level can only lower the global one', function () {
	rk_mcp_on( 'write' );
	$ro = t_ok( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'Reader', 'level' => 'read' ) ) )['created']['token'];
	$rw = t_ok( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'Writer', 'level' => 'full' ) ) )['created']['token'];
	rk_test_login( 'anon' );
	$names = function ( $r ) { return array_map( function ( $t ) { return $t['name']; }, t_ok( $r )['result']['tools'] ); };
	$read = $names( rk_mcp_with_key( $ro, 'tools/list' ) );
	t_assert( in_array( 'rkb_overview', $read, true ) && ! in_array( 'rkb_create_page', $read, true ), 'a read key only reads' );
	rk_test_login( 'anon' );
	$full = $names( rk_mcp_with_key( $rw, 'tools/list', array(), 'Authorization' ) );
	t_assert( in_array( 'rkb_create_page', $full, true ), 'Bearer works too' );
	t_assert( ! in_array( 'rkb_delete_media', $full, true ), 'a full key is still held to the global write level' );
	rk_test_login( 'anon' );
	$r = t_ok( rk_mcp_with_key( $rw, 'tools/call', array( 'name' => 'rkb_get_site', 'arguments' => array() ) ) );
	t_eq( $r['result']['isError'], false, 'runs as the administrator who made the key' );
	rk_test_login( 'anon' );
	$blocked = t_ok( rk_mcp_with_key( $ro, 'tools/call', array( 'name' => 'rkb_create_page', 'arguments' => array( 'title' => 'x' ) ) ) );
	t_eq( json_decode( $blocked['result']['content'][0]['text'], true )['code'], 'rk_mcp_unknown_tool' );
	$log = t_ok( rk_get( '/rk/v1/builder/mcp' ) );
	rk_test_login( 'admin' );
	$log = t_ok( rk_get( '/rk/v1/builder/mcp' ) )['log'];
	t_eq( $log[0]['via'], 'Reader', 'the log says which key was used' );
	t_assert( '' !== get_option( 'rk_builder_mcp_keys' )[0]['used'], 'last used is recorded' );
} );

rk_test( 'mcp keys: wrong, revoked or switched-off keys are refused', function () {
	rk_mcp_on( 'write' );
	$d = t_ok( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'Temp', 'level' => 'write' ) ) );
	$token = $d['created']['token'];
	$id = $d['created']['id'];
	rk_test_login( 'anon' );
	t_err( rk_mcp_with_key( 'rkb_wrong', 'initialize' ), 'rk_unauthorized', 401 );
	t_ok( rk_mcp_with_key( $token, 'initialize' ) );
	rk_test_login( 'admin' );
	t_assert( 0 === count( t_ok( rk_post( '/rk/v1/builder/mcp/keys/' . $id . '/revoke' ) )['keys'] ) );
	t_err( rk_post( '/rk/v1/builder/mcp/keys/' . $id . '/revoke' ), 'rk_not_found', 404 );
	rk_test_login( 'anon' );
	t_err( rk_mcp_with_key( $token, 'initialize' ), 'rk_unauthorized', 401 );
	rk_test_login( 'admin' );
	$again = t_ok( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'Again' ) ) )['created']['token'];
	t_ok( rk_post( '/rk/v1/builder/mcp', array( 'enabled' => false ) ) );
	rk_test_login( 'anon' );
	t_err( rk_mcp_with_key( $again, 'initialize' ), 'rk_mcp_disabled', 403 );
} );

rk_test( 'mcp keys: at most ten', function () {
	rk_mcp_on();
	for ( $i = 0; $i < 10; $i++ ) { t_ok( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'k' . $i ) ) ); }
	t_err( rk_post( '/rk/v1/builder/mcp/keys', array( 'name' => 'eleventh' ) ), 'rk_mcp_too_many_keys', 409 );
} );
