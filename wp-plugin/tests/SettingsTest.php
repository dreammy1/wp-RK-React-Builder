<?php
/** Settings contract, sanitising, consumers (storage/cors), and the admin-post handlers' gates. */

require_once RK_BUILDER_DIR . 'includes/setup.php';

/** Run a handler with redirects not exiting; returns array( redirect url|null, thrown message|'' , output ). */
function rk_test_run_handler( $fn ) {
	add_filter( 'rk_builder_exit_after_redirect', function () { return false; } );
	$GLOBALS['RK']['redirects'] = array();
	$msg = '';
	ob_start();
	try { $fn(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	$out = ob_get_clean();
	return array( end( $GLOBALS['RK']['redirects'] ) ?: null, $msg, $out );
}

rk_test( 'settings: defaults when nothing is stored; unknown stored keys are ignored', function () {
	t_eq( rk_builder_get_settings(), rk_builder_setting_defaults() );
	update_option( 'rk_builder_settings', array( 'max_revisions' => 7, 'evil' => 'x' ) );
	$s = rk_builder_get_settings();
	t_eq( $s['max_revisions'], 7 );
	t_assert( ! array_key_exists( 'evil', $s ) );
	t_eq( rk_builder_setting( 'nope', 'fb' ), 'fb' );
	update_option( 'rk_builder_settings', 'garbage' );
	t_eq( rk_builder_get_settings(), rk_builder_setting_defaults() );
} );

rk_test( 'sanitize: types, clamps and unknown keys', function () {
	$o = rk_builder_sanitize_settings( array(
		'enabled' => '1', 'public_rendering_mode' => 'standalone', 'preview_ttl' => '5', 'max_revisions' => '9999', 'default_grid_limit' => 'abc',
		'enable_service_cpt' => '', 'hacker' => 'x',
	) );
	t_eq( $o['enabled'], true );
	t_eq( $o['public_rendering_mode'], 'standalone' );
	t_eq( $o['preview_ttl'], 60, 'clamped up' );
	t_eq( $o['max_revisions'], 200, 'clamped down' );
	t_eq( $o['default_grid_limit'], 6, 'non-numeric -> default' );
	t_eq( $o['enable_service_cpt'], false );
	t_eq( $o['enable_portfolio_cpt'], false, 'unchecked checkbox is absent from the form' );
	t_assert( ! array_key_exists( 'hacker', $o ) );
	t_eq( array_keys( $o ), array_keys( rk_builder_setting_defaults() ) );
	t_eq( rk_builder_sanitize_settings( array( 'public_rendering_mode' => 'weird' ) )['public_rendering_mode'], 'theme' );
	t_eq( rk_builder_sanitize_settings( 'x' )['preview_ttl'], 900 );
	t_eq( rk_builder_sanitize_settings( array( 'preview_ttl' => 86401 ) )['preview_ttl'], 86400 );
	t_eq( rk_builder_sanitize_settings( array( 'default_grid_limit' => 0 ) )['default_grid_limit'], 1 );
	// idempotent
	t_eq( rk_builder_sanitize_settings( $o ), $o );
} );

rk_test( 'sanitize: host/origin strings lose markup and quotes; length is capped', function () {
	$o = rk_builder_sanitize_settings( array( 'allowed_image_hosts' => "cdn.example.com\n<script>alert(1)</script>\"'", 'allowed_origins' => 'https://a.example.com, javascript:alert(1)' ) );
	t_assert( false === strpos( $o['allowed_image_hosts'], '<' ) && false === strpos( $o['allowed_image_hosts'], '"' ) && false === strpos( $o['allowed_image_hosts'], "'" ) && false === strpos( $o['allowed_image_hosts'], '(' ) );
	t_assert( false !== strpos( $o['allowed_image_hosts'], 'cdn.example.com' ) );
	t_assert( false === strpos( $o['allowed_origins'], '(' ) );
	t_eq( strlen( rk_builder_sanitize_settings( array( 'allowed_origins' => str_repeat( 'a', 5000 ) ) )['allowed_origins'] ), 2000 );
	t_eq( rk_builder_sanitize_settings( array( 'allowed_image_hosts' => array( 'x' ) ) )['allowed_image_hosts'], '' );
	t_eq( rk_builder_split_list( "a.com, b.com\nc.com  " ), array( 'a.com', 'b.com', 'c.com' ) );
} );

rk_test( 'sanitize: ignored origins are reported to the admin', function () {
	rk_builder_sanitize_settings( array( 'allowed_origins' => 'https://ok.example.com ftp:/bad' ) );
	$codes = array_column( $GLOBALS['RK']['settings_errors'], 'code' );
	t_eq( $codes, array( 'rk_builder_bad_origin' ) );
} );

rk_test( 'settings API: registered with sanitize callback, defaults and all sections', function () {
	rk_builder_register_settings();
	$r = $GLOBALS['RK']['settings']['rk_builder_settings'];
	t_eq( $r['group'], 'rk_builder' );
	t_eq( $r['args']['sanitize_callback'], 'rk_builder_sanitize_settings' );
	t_eq( $r['args']['type'], 'array' );
	t_eq( $r['args']['default'], rk_builder_setting_defaults() );
	t_eq( array_keys( $GLOBALS['RK']['sections']['rk-builder-settings'] ), array( 'rk_general', 'rk_security', 'rk_content', 'rk_cache', 'rk_uninstall' ) );
	$keys = array();
	foreach ( $GLOBALS['RK']['fields']['rk-builder-settings'] as $sec ) { foreach ( $sec as $f ) { $keys[] = $f['args']['key']; } }
	t_eq( $keys, array( 'enabled', 'public_rendering_mode', 'allowed_image_hosts', 'preview_ttl', 'max_revisions', 'allowed_origins', 'enable_service_cpt', 'enable_portfolio_cpt', 'default_grid_limit', 'cache_purge', 'delete_data_on_uninstall' ) );
} );

rk_test( 'settings page: renders every field, escapes stored values, uses settings_fields nonce', function () {
	rk_builder_register_settings();
	update_option( 'rk_builder_settings', array( 'allowed_image_hosts' => '</textarea><script>x</script>', 'public_rendering_mode' => 'standalone' ) );
	rk_test_login( 'admin' );
	ob_start(); rk_builder_render_settings_page(); $html = ob_get_clean();
	t_assert( false !== strpos( $html, 'name="option_page" value="rk_builder"' ), 'settings_fields' );
	t_assert( false === strpos( $html, '</textarea><script>' ), 'stored value escaped' );
	t_assert( false !== strpos( $html, 'name="rk_builder_settings[preview_ttl]"' ) );
	t_assert( false !== strpos( $html, 'value="standalone" checked="checked"' ) );
	t_assert( false !== strpos( $html, 'Purge public cache now' ) && false !== strpos( $html, 'Export diagnostics' ) );
	t_assert( false !== strpos( $html, 'no wildcards' ), 'cross-origin note present' );
	rk_test_login( 'editor' );
	$msg = ''; try { rk_builder_render_settings_page(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'wp_die' ), 'editors cannot open the settings screen' );
} );

rk_test( 'settings pages require manage_options', function () {
	rk_builder_register_settings_page();
	rk_builder_register_migration_page();
	foreach ( $GLOBALS['RK']['submenu'] as $s ) { t_eq( $s['cap'], 'manage_options', $s['slug'] ); }
	t_eq( array_column( $GLOBALS['RK']['submenu'], 'parent' ), array( 'options-general.php', 'tools.php' ) );
} );

rk_test( 'consumers: max_revisions setting is used; constant/filter priority is kept', function () {
	t_eq( rk_builder_max_revisions(), 20 );
	update_option( 'rk_builder_settings', array( 'max_revisions' => 3 ) );
	t_eq( rk_builder_max_revisions(), 3 );
	add_filter( 'rk_builder_max_revisions', function ( $n ) { return $n + 1; } );
	t_eq( rk_builder_max_revisions(), 4, 'filter sees and wins over the setting' );
	// retention actually follows it
	$id = rk_test_page( 'draft', 'p', 2 );
	rk_test_login( 'editor' );
	$rev = 0;
	for ( $i = 1; $i <= 7; $i++ ) { $rev = t_ok( rk_save( $id, rk_spacer_layout( 8 + $i ), $rev ) )['revision']; }
	t_eq( count( rk_builder_get_revision_records( $id ) ), 4 );
} );

rk_test( 'consumers: allowed_image_hosts setting extends the allow-list (newline or comma separated)', function () {
	t_assert( ! in_array( 'cdn.example.org', rk_builder_allowed_image_hosts(), true ) );
	update_option( 'rk_builder_settings', array( 'allowed_image_hosts' => "cdn.example.org\nimg.example.net, Static.Example.com" ) );
	$h = rk_builder_allowed_image_hosts();
	t_assert( in_array( 'cdn.example.org', $h, true ) && in_array( 'img.example.net', $h, true ) && in_array( 'static.example.com', $h, true ), implode( ',', $h ) );
	t_assert( in_array( 'cms.example.com', $h, true ), 'own host still allowed' );
	t_eq( rk_builder_validate_layout( array( 'version' => 1, 'blocks' => array( array( 'id' => 'i', 'type' => 'image', 'props' => array( 'url' => 'https://cdn.example.org/a.png', 'alt' => 'x', 'decorative' => false ) ) ) ), $h ), array() );
	add_filter( 'rk_builder_allowed_image_hosts', function () { return array( 'only.example.com' ); } );
	t_eq( rk_builder_allowed_image_hosts(), array( 'only.example.com' ), 'filter has the last word' );
} );

rk_test( 'consumers: allowed_origins setting feeds CORS (exact match only, wildcards ignored)', function () {
	t_eq( rk_builder_allowed_origins(), array() );
	update_option( 'rk_builder_settings', array( 'allowed_origins' => "https://app.example.com\nhttps://*.evil.com, http://localhost:5173" ) );
	t_eq( rk_builder_allowed_origins(), array( 'https://app.example.com', 'http://localhost:5173' ) );
	t_eq( rk_builder_match_origin( 'https://app.example.com', rk_builder_allowed_origins() ), 'https://app.example.com' );
	t_eq( rk_builder_match_origin( 'https://x.evil.com', rk_builder_allowed_origins() ), null );
} );

rk_test( 'validation.php stays pure PHP (no WordPress calls)', function () {
	$src = file_get_contents( RK_BUILDER_DIR . 'includes/validation.php' );
	foreach ( array( 'get_option', 'rk_builder_setting', 'apply_filters', 'home_url', 'wp_parse_url', 'esc_', 'get_post' ) as $call ) {
		t_assert( false === strpos( $src, $call . '(' ) && false === strpos( $src, $call . ' (' ), 'validation.php calls ' . $call );
	}
} );

/* ---------------- admin-post handlers ---------------- */

rk_test( 'purge handler: capability and nonce required; calls the purge function when present', function () {
	foreach ( array( 'editor', 'subscriber', 'anon' ) as $who ) {
		rk_test_login( $who );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_purge_cache' );
		list( , $msg ) = rk_test_run_handler( 'rk_builder_handle_purge_cache' );
		t_assert( false !== strpos( $msg, 'wp_die' ), $who );
	}
	rk_test_login( 'admin' );
	unset( $_REQUEST['_wpnonce'] );
	list( $url, $msg ) = rk_test_run_handler( 'rk_builder_handle_purge_cache' );
	t_assert( false !== strpos( $msg, 'expired' ), 'missing nonce dies' );
	$_REQUEST['_wpnonce'] = 'forged';
	list( , $msg ) = rk_test_run_handler( 'rk_builder_handle_purge_cache' );
	t_assert( false !== strpos( $msg, 'expired' ), 'bad nonce dies' );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_purge_cache' );
	list( $url, $msg ) = rk_test_run_handler( 'rk_builder_handle_purge_cache' );
	t_eq( $msg, '' );
	t_assert( false !== strpos( (string) $url, 'options-general.php?page=rk-builder-settings' ) );
	t_assert( function_exists( 'rk_builder_purge_all_public_cache' ) ? false !== strpos( $url, 'purged' ) : false !== strpos( $url, 'purge_unavailable' ), $url );
} );

rk_test( 'export diagnostics: capability + nonce; JSON has no secrets, tokens, nonces or layout bodies', function () {
	$id = rk_test_page( 'draft', 'p', 2 );
	rk_test_login( 'editor' );
	rk_save( $id, array( 'version' => 1, 'blocks' => array( array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => 'SECRET-LAYOUT-BODY-TEXT' ) ) ) ), 0 );
	update_option( 'rk_builder_last_render_error', array( 'time' => '2026-01-01T00:00:00Z', 'page_id' => $id, 'code' => 'rk_render_failed', 'trace' => 'SECRET-TRACE' ) );
	rk_test_login( 'editor' );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_export_diagnostics' );
	list( , $msg ) = rk_test_run_handler( 'rk_builder_handle_export_diagnostics' );
	t_assert( false !== strpos( $msg, 'wp_die' ), 'editor denied' );
	rk_test_login( 'admin' );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_export_diagnostics' );
	list( , $msg, $out ) = rk_test_run_handler( 'rk_builder_handle_export_diagnostics' );
	t_eq( $msg, '' );
	$d = json_decode( $out, true );
	t_assert( is_array( $d ), 'valid JSON' );
	foreach ( array( RK_BUILDER_PREVIEW_SECRET, RK_BUILDER_REVALIDATE_SECRET, RK_BUILDER_REVALIDATE_URL, 'nonce-for-user', 'SECRET-LAYOUT-BODY-TEXT', 'SECRET-TRACE', '_rk_layout_draft' ) as $needle ) {
		t_assert( false === strpos( $out, $needle ), 'diagnostics leaked: ' . $needle );
	}
	t_eq( $d['plugin_version'], RK_BUILDER_VERSION );
	t_eq( $d['last_render_error'], array( 'time' => '2026-01-01T00:00:00Z', 'page_id' => $id, 'code' => 'rk_render_failed' ) );
	t_eq( $d['constants_defined']['RK_BUILDER_PREVIEW_SECRET'], true, 'only whether it is set' );
	t_assert( isset( $d['php_version'], $d['wp_version'], $d['rest'], $d['settings'], $d['checks'] ) );
	t_assert( false === strpos( $out, '"layout"' ), 'no layout bodies' );
} );

rk_test( 'diagnostics: tolerates a corrupt last render error and missing data', function () {
	update_option( 'rk_builder_last_render_error', 'garbage' );
	t_eq( rk_builder_diagnostics()['last_render_error'], null );
	t_eq( rk_builder_diagnostics()['last_migration'], null );
} );

/* ---------------- global settings ---------------- */

rk_test( 'global settings: defaults, clamping, validation, admin-only REST', function () {
	rk_test_reset();
	$d = rk_builder_global();
	t_eq( $d['layout_width'], 1144 );
	t_eq( $d['admin_bar'], 'default' );
	t_eq( $d['theme_styles'], true );
	t_eq( rk_builder_global_css(), '.rk-root{--site-container:1144px;--site-gutter:28px;--site-gutter-m:16px}' );
	rk_test_login( 'editor' );
	t_err( rk_get( '/rk/v1/builder/global' ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	$r = t_ok( rk_post( '/rk/v1/builder/global', array( 'layout_width' => 1320, 'gutter' => 40, 'admin_bar' => 'builder', 'no_emojis' => true, 'theme_styles' => false ) ) );
	t_eq( $r['global']['layout_width'], 1320 );
	t_eq( $r['global']['admin_bar'], 'builder' );
	t_eq( $r['global']['theme_styles'], false );
	t_eq( $r['global']['gutter_mobile'], 16, 'keys not sent are kept' );
	t_eq( rk_builder_global_css(), '.rk-root{--site-container:1320px;--site-gutter:40px;--site-gutter-m:16px}' );
	t_err( rk_post( '/rk/v1/builder/global', array( 'layout_width' => 100 ) ), 'rk_invalid_global', 400 );
	t_err( rk_post( '/rk/v1/builder/global', array( 'layout_width' => 'wide' ) ), 'rk_invalid_global', 400 );
	t_err( rk_post( '/rk/v1/builder/global', array( 'admin_bar' => 'neon' ) ), 'rk_invalid_global', 400 );
	t_err( rk_post( '/rk/v1/builder/global', array( 'no_emojis' => 'yes' ) ), 'rk_invalid_global', 400 );
	t_err( rk_post( '/rk/v1/builder/global', array( 'surprise' => 1 ) ), 'rk_invalid_global', 400 );
	t_eq( rk_builder_global()['layout_width'], 1320, 'a bad request changes nothing' );
	// stored garbage falls back to defaults
	update_option( 'rk_builder_global', array( 'layout_width' => 'x', 'admin_bar' => '<script>', 'gutter' => 9999 ) );
	t_eq( rk_builder_global()['layout_width'], 1144 );
	t_eq( rk_builder_global()['admin_bar'], 'default' );
} );

rk_test( 'global settings: which toolbar items the builder style keeps', function () {
	foreach ( array( array( 'site-name', 'root-default' ), array( 'view-site', 'site-name' ), array( 'my-account', 'top-secondary' ), array( 'logout', 'user-actions' ), array( 'rk-edit', '' ), array( 'rk-dashboard', '' ) ) as $k ) {
		t_assert( rk_builder_adminbar_keeps( $k[0], $k[1] ), 'keeps ' . $k[0] );
	}
	foreach ( array( array( 'customize', '' ), array( 'comments', '' ), array( 'new-content', '' ), array( 'edit', '' ), array( 'updates', '' ), array( 'wp-logo', '' ), array( 'hostinger-menu', '' ) ) as $k ) {
		t_assert( ! rk_builder_adminbar_keeps( $k[0], $k[1] ), 'drops ' . $k[0] );
	}
} );
