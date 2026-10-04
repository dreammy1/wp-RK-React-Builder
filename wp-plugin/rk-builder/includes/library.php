<?php
/**
 * Kit Library: browse a catalogue of site kits hosted anywhere (a static folder or CDN) and add one to this site's
 * theme library in one click. The catalogue is one JSON file; each kit is a kit zip (see kit.php).
 *
 *   catalogue  { "format": "rk-kit-catalogue", "version": 1, "name", "updatedAt",
 *                "kits": [ { id, name, description, version, author, industry, license, price, preview, demo,
 *                            download, sha256, bytes, requires, tags[], requiresKey } ] }
 *              Relative `download` and `preview` addresses are read against the catalogue's own address.
 *              `scripts/build-kit-catalogue.mjs` writes this file from a folder of kit zips.
 *
 *   GET  /builder/library            → { configured, url, hasKey, name, items[], fetchedAt }   (?refresh=1 skips the cache)
 *   POST /builder/library/settings   → { url, licenseKey? }   connect or disconnect (administrators)
 *   POST /builder/library/add        → { id }                 download, verify and add to the theme library
 *
 * Safety: https only, WordPress' safe HTTP client (no private addresses), every kit must carry a SHA-256 that the
 * download is checked against, then the zip goes through the same checks as an uploaded kit. A licence key is only
 * ever sent to the catalogue's own host and is never returned by the API.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_MAX_CATALOGUE_BYTES' ) ) { define( 'RK_BUILDER_MAX_CATALOGUE_BYTES', 1024 * 1024 ); }
if ( ! defined( 'RK_BUILDER_MAX_CATALOGUE_KITS' ) ) { define( 'RK_BUILDER_MAX_CATALOGUE_KITS', 100 ); }
if ( ! defined( 'RK_BUILDER_LIBRARY_CACHE_SECONDS' ) ) { define( 'RK_BUILDER_LIBRARY_CACHE_SECONDS', 3600 ); }

/* ------------------------------------------------------------------ *
 * Pure helpers
 * ------------------------------------------------------------------ */

/** An https address with a host, no user:password, no spaces, at most 400 characters. */
function rk_builder_library_url_ok( $u ) {
	if ( ! is_string( $u ) || strlen( $u ) > 400 || 1 === preg_match( '/[\s<>"\'\\\\]/', $u ) ) { return false; }
	$p = function_exists( 'wp_parse_url' ) ? wp_parse_url( $u ) : parse_url( $u );
	return is_array( $p ) && isset( $p['scheme'], $p['host'] ) && 'https' === strtolower( $p['scheme'] ) && ! isset( $p['user'] ) && ! isset( $p['pass'] ) && '' !== $p['host'];
}

/** Read $ref against the catalogue address: absolute https stays, a relative path is joined. '' when not usable. */
function rk_builder_library_resolve( $base, $ref ) {
	if ( ! is_string( $ref ) || '' === trim( $ref ) ) { return ''; }
	$ref = trim( $ref );
	if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $ref ) || 0 === strpos( $ref, '//' ) ) { return rk_builder_library_url_ok( $ref ) ? $ref : ''; }
	$p = function_exists( 'wp_parse_url' ) ? wp_parse_url( $base ) : parse_url( $base );
	if ( ! is_array( $p ) || ! isset( $p['scheme'], $p['host'] ) ) { return ''; }
	$origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	if ( 0 === strpos( $ref, '/' ) ) { $url = $origin . $ref; }
	else {
		$dir = isset( $p['path'] ) ? preg_replace( '#[^/]*\z#', '', $p['path'] ) : '/';
		$url = $origin . ( '' === $dir ? '/' : $dir ) . $ref;
	}
	return rk_builder_library_url_ok( $url ) ? $url : '';
}

/**
 * Clean a catalogue: only well-formed kits (id, name, https download, 64-hex SHA-256) survive, text is plain and
 * capped, addresses are https. Returns array( name, kits[] ) or WP_Error-free array() issues via the second value.
 *
 * @return array{name:string,kits:array}|null null when this is not a catalogue
 */
function rk_builder_library_clean_catalogue( $data, $base ) {
	if ( ! is_array( $data ) || ! isset( $data['format'], $data['version'] ) || 'rk-kit-catalogue' !== $data['format'] || 1 !== $data['version'] || ! isset( $data['kits'] ) || ! is_array( $data['kits'] ) ) { return null; }
	$text = function ( $v, $max ) { return is_string( $v ) ? rk_builder_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $v ) ) ), 0, $max ) : ''; };
	$kits = array();
	$seen = array();
	foreach ( array_slice( $data['kits'], 0, RK_BUILDER_MAX_CATALOGUE_KITS ) as $k ) {
		if ( ! is_array( $k ) ) { continue; }
		$id   = isset( $k['id'] ) && is_string( $k['id'] ) && 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,59}\z/', $k['id'] ) ? $k['id'] : '';
		$name = $text( isset( $k['name'] ) ? $k['name'] : '', 80 );
		$dl   = rk_builder_library_resolve( $base, isset( $k['download'] ) ? $k['download'] : '' );
		$sha  = isset( $k['sha256'] ) && is_string( $k['sha256'] ) && 1 === preg_match( '/^[a-fA-F0-9]{64}\z/', $k['sha256'] ) ? strtolower( $k['sha256'] ) : '';
		if ( '' === $id || '' === $name || '' === $dl || '' === $sha || isset( $seen[ $id ] ) ) { continue; }
		$seen[ $id ] = true;
		$ver = $text( isset( $k['version'] ) ? $k['version'] : '', 20 );
		$req = $text( isset( $k['requires'] ) ? $k['requires'] : '', 20 );
		$demo = isset( $k['demo'] ) ? rk_builder_library_resolve( $base, $k['demo'] ) : '';
		$tags = array();
		foreach ( isset( $k['tags'] ) && is_array( $k['tags'] ) ? array_slice( $k['tags'], 0, 8 ) : array() as $t ) { $t = $text( $t, 30 ); if ( '' !== $t ) { $tags[] = $t; } }
		$kits[] = array(
			'id' => $id, 'name' => $name, 'description' => $text( isset( $k['description'] ) ? $k['description'] : '', 300 ),
			'version' => 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+-]*\z/', $ver ) ? $ver : '1.0.0',
			'author' => $text( isset( $k['author'] ) ? $k['author'] : '', 80 ), 'industry' => $text( isset( $k['industry'] ) ? $k['industry'] : '', 60 ),
			'license' => $text( isset( $k['license'] ) ? $k['license'] : '', 80 ), 'price' => $text( isset( $k['price'] ) ? $k['price'] : '', 30 ),
			'preview' => rk_builder_library_resolve( $base, isset( $k['preview'] ) ? $k['preview'] : '' ), 'demo' => $demo,
			'download' => $dl, 'sha256' => $sha, 'bytes' => isset( $k['bytes'] ) && is_int( $k['bytes'] ) && $k['bytes'] > 0 ? $k['bytes'] : 0,
			'requires' => 1 === preg_match( '/^[0-9][0-9A-Za-z.+-]*\z/', $req ) ? $req : '', 'tags' => $tags,
			'requiresKey' => ! empty( $k['requiresKey'] ),
		);
	}
	return array( 'name' => $text( isset( $data['name'] ) ? $data['name'] : '', 80 ), 'kits' => $kits );
}

/** Where a kit stands against this site: new, added (same version), update (newer in the catalogue), needs-plugin (too new). */
function rk_builder_library_state( array $kit, array $index, $plugin_version ) {
	if ( '' !== $kit['requires'] && version_compare( $plugin_version, $kit['requires'], '<' ) ) { return 'needs-plugin'; }
	foreach ( $index as $s ) {
		if ( isset( $s['libraryId'] ) && $s['libraryId'] === $kit['id'] ) {
			return version_compare( $kit['version'], isset( $s['version'] ) ? (string) $s['version'] : '0', '>' ) ? 'update' : 'added';
		}
	}
	return 'new';
}

/* ------------------------------------------------------------------ *
 * Settings and HTTP
 * ------------------------------------------------------------------ */

/** @return array{url:string,licenseKey:string} */
function rk_builder_library_settings() {
	$o   = get_option( 'rk_builder_library', array() );
	$o   = is_array( $o ) ? $o : array();
	$url = defined( 'RK_BUILDER_KIT_LIBRARY_URL' ) ? (string) RK_BUILDER_KIT_LIBRARY_URL : ( isset( $o['url'] ) ? (string) $o['url'] : '' );
	$url = (string) apply_filters( 'rk_builder_kit_library_url', $url );
	return array( 'url' => rk_builder_library_url_ok( $url ) ? $url : '', 'licenseKey' => isset( $o['licenseKey'] ) && is_string( $o['licenseKey'] ) ? $o['licenseKey'] : '' );
}

/** The licence key goes only to the catalogue's own host. */
function rk_builder_library_headers( $target, array $s ) {
	$h  = array( 'Accept' => 'application/json, application/zip' );
	$th = function_exists( 'wp_parse_url' ) ? wp_parse_url( $target, PHP_URL_HOST ) : parse_url( $target, PHP_URL_HOST );
	$bh = function_exists( 'wp_parse_url' ) ? wp_parse_url( $s['url'], PHP_URL_HOST ) : parse_url( $s['url'], PHP_URL_HOST );
	if ( '' !== $s['licenseKey'] && is_string( $th ) && is_string( $bh ) && strtolower( $th ) === strtolower( $bh ) ) { $h['X-RK-License'] = $s['licenseKey']; }
	return $h;
}

/** @return array|WP_Error the HTTP response (array with response.code and body) */
function rk_builder_library_http( $url, array $args ) {
	$pre = apply_filters( 'rk_builder_library_http_pre', null, $url, $args );
	if ( null !== $pre ) { return $pre; }
	return wp_safe_remote_get( $url, $args );
}

/** @return array|WP_Error { name, kits[], fetchedAt } */
function rk_builder_library_fetch( $force = false ) {
	$s = rk_builder_library_settings();
	if ( '' === $s['url'] ) { return rk_builder_error( 'rk_library_not_connected', 'No kit library is connected yet.', 409 ); }
	$cache_key = 'rk_builder_library_' . md5( $s['url'] );
	if ( ! $force ) {
		$c = get_transient( $cache_key );
		if ( is_array( $c ) && isset( $c['kits'] ) ) { return $c; }
	}
	$res = rk_builder_library_http( $s['url'], array( 'timeout' => 20, 'redirection' => 3, 'limit_response_size' => RK_BUILDER_MAX_CATALOGUE_BYTES, 'headers' => rk_builder_library_headers( $s['url'], $s ) ) );
	if ( is_wp_error( $res ) ) { return rk_builder_error( 'rk_library_unreachable', 'Could not reach the kit library. Check the address and try again.', 502 ); }
	$code = isset( $res['response']['code'] ) ? (int) $res['response']['code'] : 0;
	if ( 401 === $code || 403 === $code ) { return rk_builder_error( 'rk_library_denied', 'The kit library did not accept the licence key.', 403 ); }
	if ( 200 !== $code ) { return rk_builder_error( 'rk_library_unreachable', 'The kit library answered with an error (' . $code . ').', 502 ); }
	$body = isset( $res['body'] ) && is_string( $res['body'] ) ? $res['body'] : '';
	if ( strlen( $body ) > RK_BUILDER_MAX_CATALOGUE_BYTES ) { return rk_builder_error( 'rk_library_invalid', 'The kit catalogue is too large.', 502 ); }
	$clean = rk_builder_library_clean_catalogue( json_decode( $body, true ), $s['url'] );
	if ( null === $clean ) { return rk_builder_error( 'rk_library_invalid', 'That address is not an RK kit catalogue.', 502 ); }
	$clean['fetchedAt'] = rk_builder_iso( rk_builder_now() );
	set_transient( $cache_key, $clean, RK_BUILDER_LIBRARY_CACHE_SECONDS );
	return $clean;
}

/* ------------------------------------------------------------------ *
 * REST
 * ------------------------------------------------------------------ */

function rk_builder_handle_library( $req ) {
	$s = rk_builder_library_settings();
	$out = array( 'configured' => '' !== $s['url'], 'url' => $s['url'], 'hasKey' => '' !== $s['licenseKey'], 'name' => '', 'items' => array(), 'fetchedAt' => '', 'error' => '' );
	if ( '' === $s['url'] ) { return rk_builder_no_store( $out ); }
	$cat = rk_builder_library_fetch( '1' === (string) $req->get_param( 'refresh' ) );
	if ( is_wp_error( $cat ) ) { $out['error'] = $cat->get_error_message(); return rk_builder_no_store( $out ); } // still connected: the screen keeps the address and shows why
	$index = rk_builder_themes_index();
	foreach ( $cat['kits'] as $k ) { $k['state'] = rk_builder_library_state( $k, $index, RK_BUILDER_VERSION ); $out['items'][] = $k; }
	$out['name']      = $cat['name'];
	$out['fetchedAt'] = $cat['fetchedAt'];
	return rk_builder_no_store( $out );
}

function rk_builder_handle_library_settings( $req ) {
	$body = rk_builder_themes_body( $req, array( 'url', 'licenseKey' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$cur = get_option( 'rk_builder_library', array() );
	$cur = is_array( $cur ) ? $cur : array();
	$url = isset( $body['url'] ) && is_string( $body['url'] ) ? trim( $body['url'] ) : ( isset( $cur['url'] ) ? $cur['url'] : '' );
	if ( '' !== $url && ! rk_builder_library_url_ok( $url ) ) { return rk_builder_invalid( 'rk_invalid_library', array( array( 'path' => 'url', 'message' => 'Use the https:// address of the catalogue file.' ) ) ); }
	$key = isset( $cur['licenseKey'] ) ? (string) $cur['licenseKey'] : '';
	if ( array_key_exists( 'licenseKey', $body ) ) {
		if ( ! is_string( $body['licenseKey'] ) || strlen( $body['licenseKey'] ) > 200 || 1 === preg_match( '/[\r\n]/', $body['licenseKey'] ) ) { return rk_builder_invalid( 'rk_invalid_library', array( array( 'path' => 'licenseKey', 'message' => 'The licence key is not valid.' ) ) ); }
		$key = trim( $body['licenseKey'] );
	}
	if ( '' === $url ) { $key = ''; } // disconnecting forgets the key too
	update_option( 'rk_builder_library', array( 'url' => $url, 'licenseKey' => $key ), false );
	return rk_builder_handle_library( $req );
}

function rk_builder_handle_library_add( $req ) {
	$body = rk_builder_themes_body( $req, array( 'id' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$id = isset( $body['id'] ) && is_string( $body['id'] ) ? $body['id'] : '';
	if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,59}\z/', $id ) ) { return rk_builder_invalid( 'rk_invalid_library', array( array( 'path' => 'id', 'message' => 'Required' ) ) ); }
	$cat = rk_builder_library_fetch( false );
	if ( is_wp_error( $cat ) ) { return $cat; }
	$kit = null;
	foreach ( $cat['kits'] as $k ) { if ( $k['id'] === $id ) { $kit = $k; } }
	if ( null === $kit ) { return rk_builder_error( 'rk_not_found', 'That kit is not in the library any more. Refresh the list.', 404 ); }
	if ( 'needs-plugin' === rk_builder_library_state( $kit, array(), RK_BUILDER_VERSION ) ) { return rk_builder_error( 'rk_invalid_kit', 'This kit needs RK Builder ' . $kit['requires'] . ' or newer.', 409 ); }

	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! function_exists( 'wp_tempnam' ) ) { rk_builder_load_media_includes(); }
	$tmp = wp_tempnam( 'rk-library-kit.zip' );
	if ( ! $tmp ) { return rk_builder_error( 'rk_server_error', 'Could not create a temporary file.', 500 ); }
	$s   = rk_builder_library_settings();
	$res = rk_builder_library_http( $kit['download'], array( 'timeout' => 300, 'redirection' => 3, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => RK_BUILDER_MAX_KIT_BYTES, 'headers' => rk_builder_library_headers( $kit['download'], $s ) ) );
	$fail = function ( $code, $msg, $status ) use ( $tmp ) { @unlink( $tmp ); return rk_builder_error( $code, $msg, $status ); }; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	if ( is_wp_error( $res ) ) { return $fail( 'rk_library_unreachable', 'Could not download the kit.', 502 ); }
	$http = isset( $res['response']['code'] ) ? (int) $res['response']['code'] : 0;
	if ( 401 === $http || 403 === $http ) { return $fail( 'rk_library_denied', $kit['requiresKey'] ? 'This kit needs a valid licence key. Add yours under Kit Library settings.' : 'The kit library refused the download.', 403 ); }
	if ( 200 !== $http ) { return $fail( 'rk_library_unreachable', 'The kit download failed (' . $http . ').', 502 ); }
	if ( ! is_readable( $tmp ) || (int) filesize( $tmp ) < 100 || (int) filesize( $tmp ) > RK_BUILDER_MAX_KIT_BYTES ) { return $fail( 'rk_invalid_kit', 'The downloaded kit is empty or too large.', 502 ); }
	if ( ! hash_equals( $kit['sha256'], strtolower( (string) hash_file( 'sha256', $tmp ) ) ) ) { return $fail( 'rk_invalid_kit', 'The downloaded kit does not match its checksum, so it was not added. Try again, or tell the kit author.', 502 ); }
	$added = rk_builder_kit_add_file( $tmp, $kit['id'] );
	@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	if ( is_wp_error( $added ) ) { return $added; }
	return rk_builder_no_store( array( 'theme' => rk_builder_kit_public_summary( $added['theme'] ), 'check' => $added['check'] ) );
}

function rk_builder_register_library_routes( $ns ) {
	register_rest_route( $ns, '/builder/library', array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_library', 'permission_callback' => 'rk_builder_perm_site_transfer' ) );
	register_rest_route( $ns, '/builder/library/settings', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_library_settings', 'permission_callback' => 'rk_builder_perm_theme_write' ) );
	register_rest_route( $ns, '/builder/library/add', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_library_add', 'permission_callback' => 'rk_builder_perm_site_transfer' ) );
}
