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
