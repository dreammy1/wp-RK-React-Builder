<?php
/** Setup wizard: checks, redirect-once, test page, activation; and the uninstall policy. */

require_once RK_BUILDER_DIR . 'includes/setup.php';
require_once RK_BUILDER_DIR . 'includes/migration.php';

function rk_test_ok_env() {
	return array(
		'php_version' => '8.2.0', 'wp_version' => '6.5', 'rest_prefix' => 'wp-json', 'rest_routes' => true, 'permalinks' => '/%postname%/',
		'upload_error' => '', 'upload_writable' => true, 'editor_bundled' => true, 'editor_url' => true,
		'cpt' => array( 'service' => true, 'portfolio' => true ), 'cpt_enabled' => array( 'service' => true, 'portfolio' => true ), 'legacy_count' => 0,
	);
}
function rk_test_check( array $env, $id ) {
	foreach ( rk_builder_setup_evaluate( $env ) as $c ) { if ( $c['id'] === $id ) { return $c; } }
	throw new RK_Assertion( 'no check ' . $id );
}

rk_test( 'setup checks: a healthy environment is all ok; every check has id/label/status/message', function () {
	$checks = rk_builder_setup_evaluate( rk_test_ok_env() );
	t_eq( array_column( $checks, 'id' ), array( 'php', 'wp', 'rest', 'permalinks', 'uploads', 'assets', 'cpt', 'legacy' ) );
	foreach ( $checks as $c ) {
		t_eq( $c['status'], 'ok', $c['id'] );
		t_assert( '' !== $c['message'] && '' !== $c['label'] );
	}
	t_eq( rk_builder_checks_overall( $checks ), 'ok' );
} );

rk_test( 'setup checks: PHP version fail / warn / ok', function () {
	$e = rk_test_ok_env();
	$e['php_version'] = '7.3.9'; t_eq( rk_test_check( $e, 'php' )['status'], 'fail' );
	$e['php_version'] = '7.4.0'; t_eq( rk_test_check( $e, 'php' )['status'], 'warn' );
	$e['php_version'] = '8.0.30'; t_eq( rk_test_check( $e, 'php' )['status'], 'warn' );
	$e['php_version'] = '8.1.0'; t_eq( rk_test_check( $e, 'php' )['status'], 'ok' );
	$e['php_version'] = ''; t_eq( rk_test_check( $e, 'php' )['status'], 'fail' );
} );

rk_test( 'setup checks: WordPress version fail / warn / ok / unknown', function () {
	$e = rk_test_ok_env();
	$e['wp_version'] = '5.4.2'; t_eq( rk_test_check( $e, 'wp' )['status'], 'fail' );
	$e['wp_version'] = '5.5'; t_eq( rk_test_check( $e, 'wp' )['status'], 'warn' );
	$e['wp_version'] = '5.8.9'; t_eq( rk_test_check( $e, 'wp' )['status'], 'warn' );
	$e['wp_version'] = '5.9'; t_eq( rk_test_check( $e, 'wp' )['status'], 'ok' );
	$e['wp_version'] = '6.7-beta1'; t_eq( rk_test_check( $e, 'wp' )['status'], 'ok' );
	$e['wp_version'] = ''; t_eq( rk_test_check( $e, 'wp' )['status'], 'warn' );
} );

rk_test( 'setup checks: REST routes registered / missing / unknown / disabled prefix', function () {
	$e = rk_test_ok_env();
	$e['rest_routes'] = false; t_eq( rk_test_check( $e, 'rest' )['status'], 'fail' );
	$e['rest_routes'] = null; t_eq( rk_test_check( $e, 'rest' )['status'], 'warn' );
	$e['rest_routes'] = true; $e['rest_prefix'] = ''; t_eq( rk_test_check( $e, 'rest' )['status'], 'fail' );
} );

rk_test( 'setup checks: plain permalinks warn, uploads problems fail', function () {
	$e = rk_test_ok_env();
	$e['permalinks'] = ''; t_eq( rk_test_check( $e, 'permalinks' )['status'], 'warn' );
	$e = rk_test_ok_env();
	$e['upload_error'] = 'Unable to create directory wp-content/uploads/2026.'; $c = rk_test_check( $e, 'uploads' );
	t_eq( $c['status'], 'fail' ); t_assert( false !== strpos( $c['message'], 'Unable to create directory' ) );
	$e = rk_test_ok_env();
	$e['upload_writable'] = false; t_eq( rk_test_check( $e, 'uploads' )['status'], 'fail' );
} );

rk_test( 'setup checks: bundled assets missing fails unless an external editor URL is configured', function () {
	$e = rk_test_ok_env();
	$e['editor_bundled'] = false; $e['editor_url'] = false;
	$c = rk_test_check( $e, 'assets' );
	t_eq( $c['status'], 'fail' ); t_assert( false !== strpos( $c['message'], 'pnpm build:plugin' ) );
	$e['editor_url'] = true; t_eq( rk_test_check( $e, 'assets' )['status'], 'ok' );
} );

rk_test( 'setup checks: content types info; legacy layouts warn when found', function () {
	$e = rk_test_ok_env();
	$e['cpt_enabled']['portfolio'] = false;
	$c = rk_test_check( $e, 'cpt' );
	t_eq( $c['status'], 'ok' ); t_assert( false !== strpos( $c['message'], 'Portfolio: disabled' ) );
	$e['cpt']['service'] = false; t_eq( rk_test_check( $e, 'cpt' )['status'], 'warn' );
	$e = rk_test_ok_env(); $e['legacy_count'] = 3;
	$c = rk_test_check( $e, 'legacy' );
	t_eq( $c['status'], 'warn' ); t_assert( false !== strpos( $c['message'], '3 page(s)' ) );
	t_eq( rk_builder_checks_overall( rk_builder_setup_evaluate( $e ) ), 'warn' );
	$e['php_version'] = '7.0'; t_eq( rk_builder_checks_overall( rk_builder_setup_evaluate( $e ) ), 'fail' );
} );

rk_test( 'setup checks: the live environment is gathered from WordPress (stubbed) without fatals', function () {
	$env = rk_builder_environment();
	t_eq( $env['rest_routes'], true, 'rk/v1 routes were registered' );
	t_eq( $env['wp_version'], '6.5.0' );
	t_eq( $env['upload_writable'], true );
	t_eq( $env['editor_bundled'], false, 'source checkout' );
	t_eq( $env['legacy_count'], 0 );
	$GLOBALS['RK']['upload'] = array( 'basedir' => '/nonexistent/uploads', 'error' => 'denied' );
	$GLOBALS['RK']['wp_version'] = '5.0';
	$routes = $GLOBALS['RK']['routes'];
	$GLOBALS['RK']['routes'] = array();
	add_filter( 'rk_builder_environment', function ( $e ) { $e['permalinks'] = ''; return $e; } );
	$checks = rk_builder_setup_checks();
	$GLOBALS['RK']['routes'] = $routes; // reset keeps routes between tests
	$by = array_column( $checks, 'status', 'id' );
	t_eq( $by['uploads'], 'fail' ); t_eq( $by['wp'], 'fail' ); t_eq( $by['rest'], 'fail' ); t_eq( $by['permalinks'], 'warn' ); t_eq( $by['assets'], 'fail' );
	t_eq( rk_builder_checks_overall( $checks ), 'fail' );
} );

rk_test( 'setup tab renders every check, never fatals, and escapes messages', function () {
	rk_test_login( 'admin' );
	$_GET['tab'] = 'setup';
	ob_start(); rk_builder_render_settings_page(); $html = ob_get_clean();
	foreach ( array( 'PHP version', 'REST API', 'Permalinks', 'Uploads folder', 'Bundled editor files', 'Create a test page', 'migration tool' ) as $s ) { t_assert( false !== strpos( $html, $s ), $s ); }
	t_assert( false !== strpos( $html, 'name="action" value="rk_builder_create_test_page"' ) );
	t_assert( false !== strpos( $html, 'Problem' ), 'missing assets reported in text, not only colour' );
} );

/* ---------------- redirect once ---------------- */

rk_test( 'activation marks setup pending, except network-wide and bulk activation', function () {
	t_eq( rk_builder_mark_setup_pending( true ), false );
	t_eq( get_transient( 'rk_builder_show_setup' ), false );
	$_POST['checked'] = array( 'rk-builder/rk-builder.php' );
	t_eq( rk_builder_mark_setup_pending( false ), false );
	unset( $_POST['checked'] );
	$_REQUEST['action'] = 'activate-selected';
	t_eq( rk_builder_mark_setup_pending( false ), false );
	unset( $_REQUEST['action'] );
	t_eq( rk_builder_mark_setup_pending( false ), true );
	t_assert( (bool) get_transient( 'rk_builder_show_setup' ) );
} );

rk_test( 'redirect to setup happens exactly once, only for manage_options users', function () {
	add_filter( 'rk_builder_exit_after_redirect', function () { return false; } );
	rk_test_login( 'admin' );
	t_eq( rk_builder_maybe_redirect_to_setup(), false, 'nothing pending' );
	set_transient( 'rk_builder_show_setup', 1 );
	rk_test_login( 'editor' );
	t_eq( rk_builder_maybe_redirect_to_setup(), false, 'editors are not redirected...' );
	t_assert( (bool) get_transient( 'rk_builder_show_setup' ), '...and it stays pending' );
	rk_test_login( 'admin' );
	$url = rk_builder_maybe_redirect_to_setup();
	t_eq( $url, 'https://cms.example.com/wp-admin/options-general.php?page=rk-builder-settings&tab=setup' );
	t_eq( $GLOBALS['RK']['redirects'], array( $url ) );
	t_eq( get_transient( 'rk_builder_show_setup' ), false );
	t_eq( rk_builder_maybe_redirect_to_setup(), false, 'second load: no redirect' );
	t_eq( count( $GLOBALS['RK']['redirects'] ), 1 );
} );

rk_test( 'no redirect for AJAX, cron, REST, network admin or bulk activation; bulk clears the flag', function () {
	rk_test_login( 'admin' );
	foreach ( array( 'doing_ajax', 'doing_cron', 'network_admin' ) as $flag ) {
		set_transient( 'rk_builder_show_setup', 1 );
		$GLOBALS['RK'][ $flag ] = true;
		t_eq( rk_builder_maybe_redirect_to_setup(), false, $flag );
		t_assert( (bool) get_transient( 'rk_builder_show_setup' ), $flag . ' keeps it pending' );
		$GLOBALS['RK'][ $flag ] = false;
	}
	set_transient( 'rk_builder_show_setup', 1 );
	$_GET['activate-multi'] = 'true';
	t_eq( rk_builder_maybe_redirect_to_setup(), false );
	t_eq( get_transient( 'rk_builder_show_setup' ), false, 'bulk activation never shows the wizard' );
	unset( $_GET['activate-multi'] );
	set_transient( 'rk_builder_show_setup', 1 );
	$_GET['page'] = 'rk-builder-settings';
	t_eq( rk_builder_maybe_redirect_to_setup(), false, 'already on the screen' );
	t_eq( get_transient( 'rk_builder_show_setup' ), false );
	t_eq( $GLOBALS['RK']['redirects'] ?? array(), array() );
} );

/* ---------------- test page ---------------- */

rk_test( 'test page layout passes the strict validator', function () {
	t_eq( rk_builder_validate_layout( rk_builder_test_layout(), null ), array() );
} );

rk_test( 'test page: created as a draft with a draft revision, idempotent, never published', function () {
	rk_test_login( 'admin' );
	$id = rk_builder_create_test_page();
	t_assert( is_int( $id ) && $id > 0 );
	$p = get_post( $id );
	t_eq( $p->post_type, 'page' ); t_eq( $p->post_status, 'draft' ); t_eq( $p->post_title, 'RK Builder test page' );
	t_eq( rk_builder_get_revision( $id ), 1 );
	t_eq( rk_builder_get_draft_layout( $id ), rk_builder_canonicalize_layout( rk_builder_test_layout() ) );
	t_eq( rk_builder_get_published_layout( $id ), null );
	$recs = rk_builder_get_revision_records( $id );
	t_eq( $recs[0]['kind'], 'draft' );
	t_eq( rk_builder_create_test_page(), $id, 'second call returns the same page' );
	t_eq( rk_builder_get_revision( $id ), 1, 'no extra revision' );
	t_eq( count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any' ) ) ), 1 );
	// the REST layer can load and save it like any page
	$r = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	t_eq( count( $r['layout']['blocks'] ), 3 );
	t_eq( rk_builder_lock( $id ), true, 'lock was released' );
} );

rk_test( 'test page handler: needs manage_options and a nonce; redirects to the setup tab', function () {
	add_filter( 'rk_builder_exit_after_redirect', function () { return false; } );
	rk_test_login( 'editor' );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_create_test_page' );
	$msg = ''; try { rk_builder_handle_create_test_page(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'wp_die' ) );
	t_eq( count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any' ) ) ), 0, 'nothing created' );
	rk_test_login( 'admin' );
	$_REQUEST['_wpnonce'] = 'bad';
	$msg = ''; try { rk_builder_handle_create_test_page(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'expired' ) );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_create_test_page' );
	$url = rk_builder_handle_create_test_page();
	t_assert( false !== strpos( $url, 'tab=setup' ) && false !== strpos( $url, 'rk_notice=test_page_created' ) && false !== strpos( $url, 'rk_test_page=' ), $url );
} );

/* ---------------- schema version ---------------- */

rk_test( 'schema upgrade: stores the version, records the outcome, no-op when current', function () {
	t_eq( rk_builder_maybe_upgrade_schema(), 'upgraded' );
	t_eq( (int) get_option( 'rk_builder_schema_version' ), rk_builder_current_schema_version() );
	$u = get_option( 'rk_builder_schema_upgrade' );
	t_eq( $u['status'], 'ok' ); t_eq( $u['from'], 0 ); t_eq( $u['to'], rk_builder_current_schema_version() );
	t_eq( rk_builder_maybe_upgrade_schema(), 'current' );
} );

/* ---------------- uninstall ---------------- */

function rk_test_run_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'rk-builder/rk-builder.php' ); }
	( function () { require RK_BUILDER_DIR . 'uninstall.php'; } )();
}
function rk_test_uninstall_fixture() {
	$id = rk_test_page( 'publish', 'home', 2 );
	foreach ( array( '_rk_layout_draft', '_rk_layout_published', '_rk_revision', '_rk_published_revision', '_rk_published_at', '_rk_revisions', '_rk_builder_test_page' ) as $k ) { update_post_meta( $id, $k, 'x' ); }
	update_post_meta( $id, '_rk_layout', wp_slash( '[{"id":"a","type":"hero"}]' ) );
	update_option( 'rk_theme_config', array( 'version' => 1 ) );
	update_option( 'rk_builder_lock_' . $id, '123' );
	update_option( 'rk_builder_last_migration', array( 'time' => 'x' ) );
	update_option( 'rk_builder_schema_version', 1 );
	set_transient( 'rk_builder_show_setup', 1 );
	set_transient( 'rk_builder_migration_report_1', array( 'rows' => array() ) );
	return $id;
}

rk_test( 'uninstall (default): removes only locks and transients; content, layouts, theme and settings stay', function () {
	$id = rk_test_uninstall_fixture();
	update_option( 'rk_builder_settings', array( 'delete_data_on_uninstall' => false ) );
	rk_test_run_uninstall();
	t_eq( get_option( 'rk_builder_lock_' . $id, 'gone' ), 'gone' );
	t_eq( get_transient( 'rk_builder_show_setup' ), false );
	t_eq( get_transient( 'rk_builder_migration_report_1' ), false );
	foreach ( array( '_rk_layout_draft', '_rk_layout_published', '_rk_revisions', '_rk_layout', '_rk_builder_test_page' ) as $k ) { t_assert( '' !== get_post_meta( $id, $k, true ), $k . ' kept' ); }
	t_assert( null !== get_option( 'rk_theme_config', null ) && null !== get_option( 'rk_builder_last_migration', null ) && null !== get_option( 'rk_builder_settings', null ) );
	t_assert( null !== get_post( $id ), 'page kept' );
} );

rk_test( 'uninstall (opt-in via setting): removes layouts, revisions, theme, options; keeps pages and legacy _rk_layout', function () {
	$id = rk_test_uninstall_fixture();
	update_option( 'rk_builder_settings', array( 'delete_data_on_uninstall' => true ) );
	rk_test_run_uninstall();
	foreach ( array( '_rk_layout_draft', '_rk_layout_published', '_rk_revision', '_rk_published_revision', '_rk_published_at', '_rk_revisions', '_rk_builder_test_page' ) as $k ) { t_eq( get_post_meta( $id, $k, true ), '', $k . ' removed' ); }
	t_assert( '' !== get_post_meta( $id, '_rk_layout', true ), 'the prototype layout is never deleted' );
	t_eq( get_option( 'rk_theme_config', 'gone' ), 'gone' );
	foreach ( array( 'rk_builder_settings', 'rk_builder_last_migration', 'rk_builder_schema_version' ) as $o ) { t_eq( get_option( $o, 'gone' ), 'gone', $o ); }
	t_assert( null !== get_post( $id ), 'pages are never deleted' );
} );
