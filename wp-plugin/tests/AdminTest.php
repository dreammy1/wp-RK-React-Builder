<?php
/** Same-origin admin screen: nonce handling, escaping and capability gating. */

require_once RK_BUILDER_DIR . 'includes/admin.php';

rk_test( 'admin screen: anonymous and subscriber users get no document and no nonce', function () {
	foreach ( array( 'anon', 'subscriber' ) as $who ) {
		rk_test_login( $who );
		t_eq( rk_builder_boot_data(), null, $who );
		t_eq( rk_builder_standalone_document(), '', $who );
	}
} );

rk_test( 'admin screen: editors get a standalone document with the nonce, API base and app assets', function () {
	rk_test_login( 'editor' );
	update_option( 'rk_builder_app_url', 'https://app.example.com/embed/rk-builder.js' );
	$doc = rk_builder_standalone_document();
	t_assert( 0 === strpos( $doc, '<!doctype html>' ) );
	t_assert( false !== strpos( $doc, 'window.RK_BUILDER_BOOT = ' ) );
	t_assert( false !== strpos( $doc, '"mode":"nonce"' ) );
	t_assert( false !== strpos( $doc, 'src="https://app.example.com/embed/rk-builder.js"' ) );
	t_assert( false !== strpos( $doc, 'href="https://app.example.com/embed/rk-builder.css"' ), 'css derived from the js url' );
	t_assert( false !== strpos( $doc, '<div id="root">' ) );
	t_assert( false !== strpos( $doc, 'noindex' ) );
	update_option( 'rk_builder_app_url', '' );
} );

rk_test( 'admin screen: boot JSON cannot break out of its script tag', function () {
	rk_test_login( 'editor' );
	add_filter( 'rk_builder_frontend_url', function () { return 'https://x.example/</script><script>alert(1)</script>'; } );
	$doc = rk_builder_standalone_document();
	t_assert( false === strpos( $doc, '</script><script>alert(1)' ), 'raw closing tag must not appear' );
} );

/* ---------------- extended boot data, deep link, gating ---------------- */

rk_test( 'boot data: currentUser with capabilities, adminUrl stays the plain screen URL', function () {
	rk_test_login( 'editor' );
	$b = rk_builder_boot_data();
	t_eq( $b['currentUser'], array( 'id' => 2, 'name' => 'Ed Editor', 'capabilities' => array( 'manageTheme' => false, 'publish' => true ) ) );
	t_eq( $b['adminUrl'], 'https://cms.example.com/wp-admin/admin.php?page=rk-builder' );
	t_eq( $b['mode'], 'nonce' );
	t_eq( $b['apiBase'], 'https://cms.example.com/wp-json/rk/v1/' );
	t_eq( $b['publicSiteUrl'], 'https://cms.example.com/', 'defaults to home_url' );
	t_eq( $b['initialPageId'], null );
	rk_test_login( 'admin' );
	t_eq( rk_builder_boot_data()['currentUser']['capabilities'], array( 'manageTheme' => true, 'publish' => true ) );
} );

rk_test( 'boot data: no secrets (preview/revalidate secrets) ever reach the document', function () {
	rk_test_login( 'admin' );
	$doc = rk_builder_standalone_document();
	t_assert( false === strpos( $doc, RK_BUILDER_PREVIEW_SECRET ) && false === strpos( $doc, RK_BUILDER_REVALIDATE_SECRET ) );
} );

rk_test( 'deep link: page_id is validated against page type and edit rights', function () {
	$mine  = rk_test_page( 'draft', 'mine', 2 );
	$other = rk_test_page( 'draft', 'other', 1 );
	$post  = rk_test_page( 'draft', 'a-post', 2, array( 'post_type' => 'post' ) );
	rk_test_login( 'editor' );
	$_GET['page_id'] = (string) $mine;
	t_eq( rk_builder_boot_data()['initialPageId'], $mine );
	t_assert( false !== strpos( rk_builder_standalone_document(), '"initialPageId":' . $mine ) );
	$_GET['page_id'] = (string) $other;
	t_eq( rk_builder_boot_data()['initialPageId'], $other, 'editors may edit others pages' );
	$_GET['page_id'] = (string) $post;
	t_eq( rk_builder_boot_data()['initialPageId'], null, 'not a page' );
	foreach ( array( '0', '-5', '1e3', '0x10', '12abc', '99999999999', '999999', array( 1 ) ) as $bad ) {
		$_GET['page_id'] = $bad;
		t_eq( rk_builder_boot_data()['initialPageId'], null, var_export( $bad, true ) );
	}
	rk_test_login( 'author' ); // author 4 may not edit pages at all
	$_GET['page_id'] = (string) $mine;
	t_eq( rk_builder_boot_data(), null );
} );

rk_test( 'deep link helper builds admin.php?page=rk-builder&page_id=N', function () {
	t_eq( rk_builder_edit_link( 42 ), 'https://cms.example.com/wp-admin/admin.php?page=rk-builder&page_id=42' );
	t_eq( rk_builder_edit_link(), 'https://cms.example.com/wp-admin/admin.php?page=rk-builder' );
} );

rk_test( 'menu: top-level entry needs edit_pages', function () {
	rk_builder_register_admin_menu();
	$m = end( $GLOBALS['RK']['menu'] );
	t_eq( $m['slug'], 'rk-builder' );
	t_eq( $m['cap'], 'edit_pages' );
} );

rk_test( 'publicSiteUrl follows the rk_builder_frontend_url filter', function () {
	rk_test_login( 'editor' );
	add_filter( 'rk_builder_frontend_url', function () { return 'https://www.example.com/'; } );
	t_eq( rk_builder_boot_data()['publicSiteUrl'], 'https://www.example.com/' );
} );

rk_test( 'enabled=false: no boot data, no document, wp_die message (with a settings link for admins only)', function () {
	update_option( 'rk_builder_settings', array( 'enabled' => false ) );
	rk_test_login( 'editor' );
	t_eq( rk_builder_boot_data(), null );
	t_eq( rk_builder_standalone_document(), '' );
	$msg = '';
	try { rk_builder_render_standalone(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'wp_die' ) && false !== strpos( $msg, 'disabled' ), $msg );
	t_assert( false === strpos( $msg, 'rk-builder-settings' ), 'editors get no settings link' );
	rk_test_login( 'admin' );
	t_eq( rk_builder_standalone_document(), '' );
	$msg = '';
	try { rk_builder_render_standalone(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'rk-builder-settings' ), 'admins are pointed to the setting' );
} );

rk_test( 'row action: only for pages the user can edit, only when enabled', function () {
	$own   = rk_test_page( 'publish', 'own', 4 );
	$page  = get_post( $own );
	$cpt   = get_post( rk_test_page( 'publish', 'svc', 2, array( 'post_type' => 'service' ) ) );
	rk_test_login( 'editor' );
	$a = rk_builder_page_row_actions( array( 'edit' => 'x' ), $page );
	t_assert( isset( $a['rk_builder'] ) && false !== strpos( $a['rk_builder'], 'admin.php?page=rk-builder&amp;page_id=' . $own ), $a['rk_builder'] );
	t_assert( false !== strpos( $a['rk_builder'], 'Open in RK Builder' ) );
	t_eq( rk_builder_page_row_actions( array( 'edit' => 'x' ), $cpt ), array( 'edit' => 'x' ), 'not for other post types' );
	rk_test_login( 'subscriber' );
	t_eq( rk_builder_page_row_actions( array(), $page ), array() );
	rk_test_login( 'anon' );
	t_eq( rk_builder_page_row_actions( array(), $page ), array() );
	rk_test_login( 'editor' );
	update_option( 'rk_builder_settings', array( 'enabled' => false ) );
	t_eq( rk_builder_page_row_actions( array(), $page ), array() );
	t_eq( rk_builder_page_row_actions( 'not-an-array', $page ), 'not-an-array' );
} );

rk_test( 'classic editor Publish box link: printed for editable pages only', function () {
	$id = rk_test_page( 'draft', 'p', 2 );
	rk_test_login( 'editor' );
	ob_start(); rk_builder_submitbox_link( get_post( $id ) ); $out = ob_get_clean();
	t_assert( false !== strpos( $out, 'Open in RK Builder' ) && false !== strpos( $out, 'page_id=' . $id ) );
	rk_test_login( 'subscriber' );
	ob_start(); rk_builder_submitbox_link( get_post( $id ) ); t_eq( ob_get_clean(), '' );
} );

rk_test( 'admin hooks are registered (row action, submitbox, settings/migration pages, handlers)', function () {
	rk_builder_register_admin_hooks();
	foreach ( array( 'page_row_actions', 'post_submitbox_misc_actions', 'admin_menu', 'admin_init', 'plugins_loaded', 'admin_post_rk_builder_purge_cache', 'admin_post_rk_builder_export_diagnostics', 'admin_post_rk_builder_create_test_page', 'admin_post_rk_builder_migrate' ) as $tag ) {
		t_assert( ! empty( $GLOBALS['RK']['filters'][ $tag ] ), 'hook ' . $tag );
	}
} );

/* ---------------- bundled assets ---------------- */

rk_test( 'assets: with a built plugin the default editor URL is the bundled file with ?ver= from builder.asset.php', function () {
	$dir = rk_test_assets_dir( array( 'builder.js' => '//', 'builder.css' => '/**/', 'builder.asset.php' => "<?php return array( 'dependencies' => array(), 'version' => 'abc123' );" ) );
	add_filter( 'rk_builder_assets_dir', function () use ( $dir ) { return $dir; } );
	t_eq( rk_builder_app_url(), 'https://cms.example.com/wp-content/plugins/rk-builder/assets/builder.js?ver=abc123' );
	t_eq( rk_builder_app_css_url(), 'https://cms.example.com/wp-content/plugins/rk-builder/assets/builder.css?ver=abc123' );
	rk_test_login( 'editor' );
	$doc = rk_builder_standalone_document();
	t_assert( false !== strpos( $doc, '<script type="module" crossorigin src="https://cms.example.com/wp-content/plugins/rk-builder/assets/builder.js?ver=abc123"></script>' ) );
	t_assert( false !== strpos( $doc, 'href="https://cms.example.com/wp-content/plugins/rk-builder/assets/builder.css?ver=abc123"' ) );
	t_assert( false === strpos( $doc, 'role="alert"' ), 'no missing-assets notice' );
	t_eq( rk_builder_assets_status(), array( 'builder.js' => true, 'builder.css' => true, 'builder.asset.php' => true, 'site.css' => false ) );
} );

rk_test( 'assets: option and filter override the bundled editor (headless setups)', function () {
	$dir = rk_test_assets_dir( array( 'builder.js' => '//', 'builder.asset.php' => "<?php return array( 'dependencies' => array(), 'version' => 'v9' );" ) );
	add_filter( 'rk_builder_assets_dir', function () use ( $dir ) { return $dir; } );
	update_option( 'rk_builder_app_url', 'https://app.example.com/embed/rk-builder.js' );
	t_eq( rk_builder_app_url(), 'https://app.example.com/embed/rk-builder.js' );
	t_eq( rk_builder_app_css_url(), 'https://app.example.com/embed/rk-builder.css', 'css derived from the override, not the bundled file' );
	update_option( 'rk_builder_app_url', '' );
	add_filter( 'rk_builder_app_url', function ( $u ) { return 'https://filter.example.com/a.js'; } );
	t_eq( rk_builder_app_url(), 'https://filter.example.com/a.js' );
	add_filter( 'rk_builder_app_css_url', function () { return 'https://filter.example.com/custom.css'; } );
	t_eq( rk_builder_app_css_url(), 'https://filter.example.com/custom.css' );
} );

rk_test( 'assets: version falls back to the plugin version and is sanitised', function () {
	$dir = rk_test_assets_dir( array( 'builder.js' => '//', 'builder.asset.php' => "<?php return 'nope';" ) );
	add_filter( 'rk_builder_assets_dir', function () use ( $dir ) { return $dir; } );
	t_eq( rk_builder_asset_version(), RK_BUILDER_VERSION );
	file_put_contents( $dir . 'builder.asset.php', "<?php return array( 'version' => 'a\"b<c>1.2' );" );
	t_eq( rk_builder_asset_version(), 'abc1.2' );
	t_eq( rk_builder_asset_url( '../../wp-config.php' ), '', 'only known bundled files are served' );
	t_eq( rk_builder_asset_url( 'site.css' ), '', 'missing file gives no URL' );
} );

rk_test( 'assets: a source checkout (no assets) shows a clear notice and never fatals', function () {
	rk_test_login( 'editor' );
	t_eq( rk_builder_app_url(), '' );
	t_eq( rk_builder_app_css_url(), '' );
	$doc = rk_builder_standalone_document();
	t_assert( false !== strpos( $doc, 'role="alert"' ) && false !== strpos( $doc, 'pnpm build:plugin' ), 'notice explains the build' );
	t_assert( false === strpos( $doc, 'type="module"' ) );
	t_assert( false !== strpos( $doc, 'window.RK_BUILDER_BOOT' ), 'boot object still printed' );
} );
