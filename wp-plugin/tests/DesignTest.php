<?php
/** Design system: optional theme tokens, validated, printed as CSS only when set, saved and carried by exports. */

function rk_design_theme( array $over = array() ) {
	return array_merge( rk_builder_default_theme(), $over );
}

rk_test( 'design: a theme without the new tokens prints no extra rules; one with them prints variables and rules', function () {
	t_eq( rk_builder_theme_design_rules( rk_builder_default_theme() ), '' );
	$t   = rk_design_theme( array( 'accent' => '#112233', 'dark' => '#000000', 'surface' => '#EEEEEE', 'bodyFont' => 'System Sans', 'headingFont' => 'Classic Serif', 'headingWeight' => 800, 'radius' => 'round', 'buttonStyle' => 'outline' ) );
	t_eq( rk_builder_validate_theme( $t ), array() );
	$css = rk_builder_theme_css( $t );
	foreach ( array( '--site-accent:#112233', '--site-dark:#000000', '--site-surface:#EEEEEE', '--site-btn-radius:999px', '--site-radius:16px', '--site-heading-font:\'Iowan Old Style\'', '--site-body-font:system-ui', 'font-weight:800!important', '.site-btn{background:transparent' ) as $needle ) {
		t_assert( false !== strpos( $css, $needle ), $needle . ' missing in ' . $css );
	}
	t_assert( false !== strpos( $css, '--site-font:system-ui' ), 'the body font also drives the legacy font variable' );
} );

rk_test( 'design: bad values are rejected by the validator and never reach CSS', function () {
	foreach ( array( array( 'accent' => 'red' ), array( 'dark' => '#12345' ), array( 'surface' => 'url(x)' ), array( 'headingWeight' => 550 ), array( 'radius' => 'blob' ), array( 'buttonStyle' => 'glow' ), array( 'headingFont' => 'Comic Sans' ), array( 'bodyFont' => 5 ), array( 'nope' => 1 ) ) as $bad ) {
		t_assert( count( rk_builder_validate_theme( rk_design_theme( $bad ) ) ) >= 1, json_encode( $bad ) );
	}
	$css = rk_builder_theme_css( rk_design_theme( array( 'accent' => 'red;}body{display:none', 'radius' => 'x;}' ) ) );
	t_eq( $css, rk_builder_theme_css( rk_builder_default_theme() ), 'an invalid theme falls back to the default, nothing leaks' );
} );

rk_test( 'design: the theme saves through the API with the new tokens and comes back the same', function () {
	rk_test_login( 'admin' );
	$t = rk_design_theme( array( 'accent' => '#112233', 'radius' => 'soft', 'headingWeight' => 600, 'buttonStyle' => 'outline', 'headingFont' => 'Georgia' ) );
	t_ok( rk_post( '/rk/v1/theme-config', $t ) );
	$got = rk_builder_get_theme();
	t_eq( $got['accent'], '#112233' );
	t_eq( $got['radius'], 'soft' );
	t_eq( $got['headingWeight'], 600 );
	t_eq( $got['buttonStyle'], 'outline' );
	t_eq( $got['headingFont'], 'Georgia' );
	t_err( rk_post( '/rk/v1/theme-config', rk_design_theme( array( 'radius' => 'blob' ) ) ), 'rk_invalid_theme', 400 );
	// a stored theme from before the design system migrates untouched
	update_option( 'rk_theme_config', rk_builder_default_theme() );
	t_eq( rk_builder_get_theme(), rk_builder_default_theme() );
} );

rk_test( 'design: the tokens travel in a site export and are applied by an install', function () {
	rk_test_login( 'admin' );
	rk_theme_site();
	t_ok( rk_post( '/rk/v1/theme-config', rk_design_theme( array( 'accent' => '#AA5500', 'radius' => 'round' ) ) ) );
	$b = rk_builder_build_site_bundle();
	t_eq( $b['theme']['accent'], '#AA5500' );
	t_eq( $b['theme']['radius'], 'round' );
	update_option( 'rk_theme_config', rk_builder_default_theme() );
	$r = t_ok( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => $b, 'options' => array( 'dryRun' => false, 'theme' => true ) ) ) );
	t_eq( $r['theme']['applied'], true );
	t_eq( rk_builder_get_theme()['accent'], '#AA5500' );
} );
