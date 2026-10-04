<?php
/** Kit Library: a catalogue of kit zips hosted anywhere; browse, verify and add one to the theme library. */

const RK_LIB_URL = 'https://kits.example.com/catalogue/index.json';

/** Route every library request through $fn( $url, $args ) => response array | WP_Error. Returns a call log. */
function rk_lib_http( callable $fn ) {
	$GLOBALS['RK_LIB_CALLS'] = array();
	add_filter( 'rk_builder_library_http_pre', function ( $pre, $url, $args ) use ( $fn ) {
		$GLOBALS['RK_LIB_CALLS'][] = array( 'url' => $url, 'args' => $args );
		return $fn( $url, $args );
	}, 10, 3 );
}

function rk_lib_kit_zip( $name = 'Studio Kit', $version = '1.0.0', array $manifest_over = array() ) {
	$blk = array( 'version' => 1, 'blocks' => array( array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => 'x' ) ) ) );
	$e = array( 'manifest.json' => json_encode( rk_kit_manifest( array_merge( array( 'name' => $name, 'version' => $version ), $manifest_over ) ) ), 'site.json' => json_encode( rk_bundle( array( 'media' => array(), 'pages' => array( array( 'slug' => 'home', 'title' => 'Home', 'wasPublished' => true, 'layout' => $blk ) ) ) ) ) );
	return rk_kit_zip( $e );
}

function rk_lib_catalogue( array $kits ) {
	return json_encode( array( 'format' => 'rk-kit-catalogue', 'version' => 1, 'name' => 'Test Kits', 'kits' => $kits ) );
}

function rk_lib_item( $zip, array $over = array() ) {
	return array_merge( array( 'id' => 'studio-kit', 'name' => 'Studio Kit', 'version' => '1.0.0', 'industry' => 'Design', 'price' => 'Free', 'download' => 'studio-kit-1.0.0.zip', 'sha256' => hash_file( 'sha256', $zip ), 'preview' => 'studio.jpg', 'requires' => '1.0.0' ), $over );
}

/** Answer the catalogue and the zip. */
function rk_lib_serve( $catalogue_json, $zip_path, $code_zip = 200 ) {
	rk_lib_http( function ( $url, $args ) use ( $catalogue_json, $zip_path, $code_zip ) {
		if ( '.json' === substr( $url, -5 ) ) { return array( 'response' => array( 'code' => 200 ), 'body' => $catalogue_json ); }
		if ( ! empty( $args['filename'] ) && 200 === $code_zip ) { copy( $zip_path, $args['filename'] ); }
		return array( 'response' => array( 'code' => $code_zip ), 'body' => '' );
	} );
}

rk_test( 'library: only https addresses; relative download and preview addresses are read against the catalogue', function () {
	foreach ( array( 'https://kits.example.com/index.json', 'https://kits.example.com:8443/a/b.json?x=1' ) as $ok ) { t_assert( rk_builder_library_url_ok( $ok ), $ok ); }
	foreach ( array( 'http://kits.example.com/i.json', 'ftp://x.example/i', 'javascript:alert(1)', 'https://user:pw@kits.example.com/i.json', 'https:///x', 'https://kits.example.com/a b', '', 'kits.example.com/i.json', str_repeat( 'a', 401 ) ) as $bad ) { t_assert( ! rk_builder_library_url_ok( $bad ), $bad ); }
	$base = 'https://kits.example.com/catalogue/index.json';
	t_eq( rk_builder_library_resolve( $base, 'a.zip' ), 'https://kits.example.com/catalogue/a.zip' );
	t_eq( rk_builder_library_resolve( $base, '/files/a.zip' ), 'https://kits.example.com/files/a.zip' );
	t_eq( rk_builder_library_resolve( $base, 'https://cdn.example.net/a.zip' ), 'https://cdn.example.net/a.zip' );
	t_eq( rk_builder_library_resolve( $base, 'http://cdn.example.net/a.zip' ), '' );
	t_eq( rk_builder_library_resolve( $base, '//cdn.example.net/a.zip' ), '' );
	t_eq( rk_builder_library_resolve( $base, 'javascript:alert(1)' ), '' );
	t_eq( rk_builder_library_resolve( $base, '' ), '' );
} );

rk_test( 'library: the catalogue keeps only complete kits and cleans their text', function () {
	$sha = str_repeat( 'a', 64 );
	$c = rk_builder_library_clean_catalogue( json_decode( rk_lib_catalogue( array(
		array( 'id' => 'ok-kit', 'name' => '<b>Good</b> Kit', 'download' => 'g.zip', 'sha256' => strtoupper( $sha ), 'description' => 'A <script>x</script> kit', 'tags' => array( 'a', '<i>b</i>' ), 'preview' => 'javascript:x', 'requiresKey' => true, 'version' => 'bad version!' ),
		array( 'id' => 'no-sha', 'name' => 'X', 'download' => 'x.zip' ),
		array( 'id' => 'bad sha', 'name' => 'X', 'download' => 'x.zip', 'sha256' => 'zz' ),
		array( 'id' => 'Bad_Id', 'name' => 'X', 'download' => 'x.zip', 'sha256' => $sha ),
		array( 'id' => 'http-dl', 'name' => 'X', 'download' => 'http://x.example/x.zip', 'sha256' => $sha ),
		array( 'id' => 'ok-kit', 'name' => 'Dup', 'download' => 'd.zip', 'sha256' => $sha ),
		'junk',
	) ), true ), 'https://kits.example.com/i.json' );
	t_eq( count( $c['kits'] ), 1 );
	$k = $c['kits'][0];
	t_eq( $k['name'], 'Good Kit' );
	t_eq( $k['sha256'], $sha );
	t_eq( $k['download'], 'https://kits.example.com/g.zip' );
	t_eq( $k['preview'], '' );
	t_eq( $k['version'], '1.0.0', 'a malformed version falls back' );
	t_eq( $k['tags'], array( 'a', 'b' ) );
	t_assert( false === strpos( $k['description'], '<' ) );
	t_eq( $k['requiresKey'], true );
	t_eq( rk_builder_library_clean_catalogue( array( 'format' => 'other', 'version' => 1, 'kits' => array() ), 'https://x.example/i.json' ), null );
	t_eq( rk_builder_library_clean_catalogue( 'x', 'https://x.example/i.json' ), null );
} );

rk_test( 'library: a kit is new, added, an update, or needs a newer plugin', function () {
	$k = array( 'id' => 'a', 'version' => '1.1.0', 'requires' => '' );
	t_eq( rk_builder_library_state( $k, array(), '1.28.0' ), 'new' );
	t_eq( rk_builder_library_state( $k, array( 'x' => array( 'libraryId' => 'a', 'version' => '1.1.0' ) ), '1.28.0' ), 'added' );
	t_eq( rk_builder_library_state( $k, array( 'x' => array( 'libraryId' => 'a', 'version' => '1.0.0' ) ), '1.28.0' ), 'update' );
	t_eq( rk_builder_library_state( $k, array( 'x' => array( 'libraryId' => 'other', 'version' => '0.1' ) ), '1.28.0' ), 'new' );
	t_eq( rk_builder_library_state( array_merge( $k, array( 'requires' => '9.0.0' ) ), array(), '1.28.0' ), 'needs-plugin' );
} );

rk_test( 'library: routes are for administrators (settings) and page editors (browse, add)', function () {
	t_err( rk_get( '/rk/v1/builder/library' ), 'rk_unauthorized', 401 );
	t_err( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ), 'rk_unauthorized', 401 );
	rk_test_login( 'editor' );
	t_err( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ), 'rk_forbidden', 403 );
	rk_test_login( 'subscriber' );
	t_err( rk_get( '/rk/v1/builder/library' ), 'rk_forbidden', 403 );
} );

rk_test( 'library: connect needs an https address; the licence key is stored but never returned; disconnect forgets it', function () {
	rk_test_login( 'admin' );
	$r = t_ok( rk_get( '/rk/v1/builder/library' ) );
	t_eq( $r['configured'], false );
	t_err( rk_post( '/rk/v1/builder/library/settings', array( 'url' => 'http://kits.example.com/i.json' ) ), 'rk_invalid_library', 400 );
	t_err( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL, 'surprise' => 1 ) ), 'rk_invalid_theme', 400 );
	t_err( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL, 'licenseKey' => "a\nb" ) ), 'rk_invalid_library', 400 );
	rk_lib_http( function () { return array( 'response' => array( 'code' => 200 ), 'body' => rk_lib_catalogue( array() ) ); } );
	$r = t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL, 'licenseKey' => 'SECRET-KEY-123' ) ) );
	t_eq( $r['configured'], true );
	t_eq( $r['hasKey'], true );
	t_assert( false === strpos( json_encode( $r ), 'SECRET-KEY-123' ), 'the key is never returned' );
	t_assert( false === strpos( json_encode( t_ok( rk_get( '/rk/v1/builder/library' ) ) ), 'SECRET-KEY-123' ) );
	t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ) );
	t_eq( rk_builder_library_settings()['licenseKey'], 'SECRET-KEY-123', 'changing only the address keeps the key' );
	$r = t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => '' ) ) );
	t_eq( $r['configured'], false );
	t_eq( rk_builder_library_settings()['licenseKey'], '', 'disconnecting forgets the key' );
} );

rk_test( 'library: browsing lists the kits with their state, caches, refreshes, and explains failures', function () {
	rk_test_login( 'admin' );
	$zip = rk_lib_kit_zip();
	rk_lib_serve( rk_lib_catalogue( array( rk_lib_item( $zip ), rk_lib_item( $zip, array( 'id' => 'future-kit', 'name' => 'Future', 'requires' => '99.0.0', 'download' => 'f.zip' ) ) ) ), $zip );
	t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ) );
	$calls = count( $GLOBALS['RK_LIB_CALLS'] );
	$r = t_ok( rk_get( '/rk/v1/builder/library' ) );
	t_eq( $r['name'], 'Test Kits' );
	t_eq( array_column( $r['items'], 'state', 'id' ), array( 'studio-kit' => 'new', 'future-kit' => 'needs-plugin' ) );
	t_eq( $r['items'][0]['preview'], 'https://kits.example.com/catalogue/studio.jpg' );
	t_eq( count( $GLOBALS['RK_LIB_CALLS'] ), $calls, 'served from the cache' );
	t_ok( rk_get( '/rk/v1/builder/library', array( 'refresh' => '1' ) ) );
	t_eq( count( $GLOBALS['RK_LIB_CALLS'] ), $calls + 1, 'refresh asks again' );
	t_eq( $GLOBALS['RK_LIB_CALLS'][ $calls ]['args']['limit_response_size'], RK_BUILDER_MAX_CATALOGUE_BYTES );
	@unlink( $zip );

	// failures
	// the screen stays connected and says why: configured, the address kept, no kits, an error text
	$fails = array(
		array( function () { return array( 'response' => array( 'code' => 401 ), 'body' => '' ); }, 'licence key' ),
		array( function () { return array( 'response' => array( 'code' => 500 ), 'body' => '' ); }, 'error (500)' ),
		array( function () { return new WP_Error( 'http_request_failed', 'cURL error 6' ); }, 'Could not reach' ),
		array( function () { return array( 'response' => array( 'code' => 200 ), 'body' => '{"hello":1}' ); }, 'not an RK kit catalogue' ),
	);
	foreach ( $fails as $f ) {
		rk_lib_http( $f[0] );
		$r = t_ok( rk_get( '/rk/v1/builder/library', array( 'refresh' => '1' ) ) );
		t_eq( $r['configured'], true );
		t_eq( $r['url'], RK_LIB_URL );
		t_eq( $r['items'], array() );
		t_assert( false !== strpos( $r['error'], $f[1] ), $f[1] . ' in ' . $r['error'] );
	}
} );

function rk_test_reset_cache() { foreach ( array_keys( $GLOBALS['RK']['options'] ) as $k ) { if ( 0 === strpos( $k, '_transient_rk_builder_library_' ) ) { unset( $GLOBALS['RK']['options'][ $k ] ); } } }

rk_test( 'library: add downloads, checks the checksum, adds the kit to the theme library and remembers where it came from', function () {
	rk_test_login( 'admin' );
	$zip = rk_lib_kit_zip();
	rk_lib_serve( rk_lib_catalogue( array( rk_lib_item( $zip ) ) ), $zip );
	t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ) );
	t_err( rk_post( '/rk/v1/builder/library/add', array() ), 'rk_invalid_library', 400 );
	t_err( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'nope' ) ), 'rk_not_found', 404 );
	$r = t_ok( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) ) );
	t_eq( $r['theme']['slug'], 'studio-kit' );
	t_eq( $r['theme']['kit'], true );
	t_eq( $r['check']['pages']['create'], 1 );
	t_assert( ! isset( $r['theme']['kitFile'] ) );
	$dl = array_values( array_filter( $GLOBALS['RK_LIB_CALLS'], function ( $c ) { return ! empty( $c['args']['stream'] ); } ) );
	t_eq( $dl[0]['url'], 'https://kits.example.com/catalogue/studio-kit-1.0.0.zip' );
	t_eq( $dl[0]['args']['limit_response_size'], RK_BUILDER_MAX_KIT_BYTES );
	$items = t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'];
	t_eq( count( $items ), 1 );
	t_assert( ! empty( rk_builder_themes_index()['studio-kit']['libraryId'] ) );
	t_eq( t_ok( rk_get( '/rk/v1/builder/library' ) )['items'][0]['state'], 'added' );
	t_assert( '' !== rk_builder_theme_kit_path( 'studio-kit' ) );
	t_ok( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'studio-kit' ) ) );
	@unlink( $zip );
} );

rk_test( 'library: a newer version in the catalogue shows as an update, and adding it replaces the old kit', function () {
	rk_test_login( 'admin' );
	$v1 = rk_lib_kit_zip( 'Studio Kit', '1.0.0' );
	rk_lib_serve( rk_lib_catalogue( array( rk_lib_item( $v1 ) ) ), $v1 );
	t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ) );
	t_ok( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) ) );
	$old_file = rk_builder_theme_kit_path( 'studio-kit' );
	$v2 = rk_lib_kit_zip( 'Studio Kit', '1.1.0' );
	rk_lib_serve( rk_lib_catalogue( array( rk_lib_item( $v2, array( 'version' => '1.1.0', 'download' => 'studio-kit-1.1.0.zip' ) ) ) ), $v2 );
	t_eq( t_ok( rk_get( '/rk/v1/builder/library', array( 'refresh' => '1' ) ) )['items'][0]['state'], 'update' );
	t_ok( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) ) );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'] ), 1, 'replaced, not duplicated' );
	t_eq( rk_builder_themes_index()['studio-kit']['version'], '1.1.0' );
	t_assert( ! is_readable( $old_file ), 'the old zip is gone' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/library' ) )['items'][0]['state'], 'added' );
	t_ok( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'studio-kit' ) ) );
	@unlink( $v1 );
	@unlink( $v2 );
} );

rk_test( 'library: a download that does not match its checksum is refused and nothing is added', function () {
	rk_test_login( 'admin' );
	$zip = rk_lib_kit_zip();
	rk_lib_serve( rk_lib_catalogue( array( rk_lib_item( $zip, array( 'sha256' => str_repeat( '0', 64 ) ) ) ) ), $zip );
	t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ) );
	t_err( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) ), 'rk_invalid_kit', 502 );
	t_eq( t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'], array() );
	@unlink( $zip );
} );

rk_test( 'library: a failed or refused download, a too-new kit and a broken zip are explained', function () {
	rk_test_login( 'admin' );
	$zip = rk_lib_kit_zip();
	$cat = rk_lib_catalogue( array( rk_lib_item( $zip, array( 'requiresKey' => true ) ), rk_lib_item( $zip, array( 'id' => 'future-kit', 'name' => 'Future', 'requires' => '99.0.0', 'download' => 'f.zip' ) ) ) );
	rk_lib_serve( $cat, $zip, 403 );
	t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL ) ) );
	$e = rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) );
	t_err( $e, 'rk_library_denied', 403 );
	t_assert( false !== strpos( $e->get_error_message(), 'licence key' ) );
	t_err( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'future-kit' ) ), 'rk_invalid_kit', 409 );
	rk_lib_serve( $cat, $zip, 500 );
	t_err( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) ), 'rk_library_unreachable', 502 );
	// a zip that is not a kit, with a matching checksum, still goes through the kit checks
	$bad = rk_kit_zip( array( 'shell.php' => '<?php' ) );
	rk_lib_serve( rk_lib_catalogue( array( rk_lib_item( $bad ) ) ), $bad );
	rk_test_reset_cache();
	t_ok( rk_get( '/rk/v1/builder/library', array( 'refresh' => '1' ) ) );
	t_err( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) ), 'rk_invalid_kit', 400 );
	t_eq( t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'], array() );
	@unlink( $zip );
	@unlink( $bad );
} );

rk_test( 'library: the licence key goes only to the catalogue host, never to another host', function () {
	rk_test_login( 'admin' );
	$zip = rk_lib_kit_zip();
	rk_lib_serve( rk_lib_catalogue( array( rk_lib_item( $zip, array( 'download' => 'https://cdn.other.example/kit.zip' ) ), rk_lib_item( $zip, array( 'id' => 'own-host', 'name' => 'Own', 'download' => 'own.zip' ) ) ) ), $zip );
	t_ok( rk_post( '/rk/v1/builder/library/settings', array( 'url' => RK_LIB_URL, 'licenseKey' => 'KEY-1' ) ) );
	t_eq( $GLOBALS['RK_LIB_CALLS'][0]['args']['headers']['X-RK-License'], 'KEY-1', 'sent to the catalogue host' );
	t_ok( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'studio-kit' ) ) );
	$other = array_values( array_filter( $GLOBALS['RK_LIB_CALLS'], function ( $c ) { return 'https://cdn.other.example/kit.zip' === $c['url']; } ) );
	t_assert( ! isset( $other[0]['args']['headers']['X-RK-License'] ), 'not sent to another host' );
	t_ok( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'studio-kit' ) ) );
	t_ok( rk_post( '/rk/v1/builder/library/add', array( 'id' => 'own-host' ) ) );
	$own = array_values( array_filter( $GLOBALS['RK_LIB_CALLS'], function ( $c ) { return 'https://kits.example.com/catalogue/own.zip' === $c['url']; } ) );
	t_eq( $own[0]['args']['headers']['X-RK-License'], 'KEY-1' );
	@unlink( $zip );
} );
