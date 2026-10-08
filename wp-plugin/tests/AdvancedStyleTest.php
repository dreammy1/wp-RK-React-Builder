<?php
/** Per-block advanced styles: the validator and canonicaliser must accept them, strictly. */

/** A minimal layout with an `advanced` object attached to one hero block. */
function rk_advanced_layout( $advanced ) {
	$block = array( 'id' => 'a', 'type' => 'hero', 'props' => array( 'heading' => 'h', 'sub' => '', 'cta' => '', 'ctaHref' => '/' ) );
	if ( null !== $advanced ) { $block['advanced'] = $advanced; }
	return array( 'version' => 1, 'blocks' => array( $block ) );
}

rk_test( 'advanced styles: a full object validates, and stays optional', function () {
	t_eq( rk_builder_validate_layout( rk_advanced_layout( null ), RK_TEST_HOSTS ), array(), 'absent advanced' );
	t_eq( rk_builder_validate_layout( rk_advanced_layout( array() ), RK_TEST_HOSTS ), array(), 'empty advanced' );
	$full = array(
		'spacing'    => array( 'top' => '24px', 'bottom' => '24px' ),
		'size'       => array( 'width' => 'narrow', 'minHeight' => '320px', 'colSpan' => 2 ),
		'background' => array( 'color' => '#112233', 'gradient' => 'fade', 'imageUrl' => 'https://cms.example.com/a.jpg', 'imageFit' => 'cover', 'imagePosition' => 'top' ),
		'border'     => array( 'width' => '2px', 'color' => '#000000', 'style' => 'dashed', 'radius' => '12px' ),
		'shadow'     => array( 'preset' => 'lg' ),
		'typography' => array( 'size' => '40px', 'weight' => 700, 'align' => 'center', 'color' => '#ffffff' ),
		'visibility' => array( 'hidePhone' => true ),
		'overrides'  => array( 'phone' => array( 'spacing' => array( 'top' => '8px' ), 'typography' => array( 'size' => '18px' ) ) ),
		'cssClass'   => 'hero-custom',
		'name'       => 'Hero',
	);
	t_eq( rk_builder_validate_layout( rk_advanced_layout( $full ), RK_TEST_HOSTS ), array(), 'full object' );
} );

rk_test( 'advanced styles: unknown keys are still reported', function () {
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'nope' => 1 ) ) ) ) === 1, 'unknown group' );
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'spacing' => array( 'middle' => '8px' ) ) ) ) ) === 1, 'unknown side' );
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'shadow' => array( 'value' => 'x' ) ) ) ) ) === 1, 'unknown shadow key' );
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'overrides' => array( 'desktop' => array() ) ) ) ) ) === 1, 'unknown breakpoint' );
} );

rk_test( 'advanced styles: only strict pixel lengths and colours can reach CSS', function () {
	foreach ( array( '24', '2.5px', '24em', 'calc(100% - 4px)', '-4px', '24px;' ) as $bad ) {
		t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'spacing' => array( 'top' => $bad ) ) ) ) ) >= 1, 'length ' . $bad );
	}
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'border' => array( 'width' => '2' ) ) ) ) ) >= 1, 'bare number' );
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'background' => array( 'color' => '#000;}body{display:none' ) ) ) ) ) >= 1, 'css injection' );
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'typography' => array( 'color' => 'red' ) ) ) ) ) >= 1, 'named colour' );
	t_eq( rk_builder_validate_layout( rk_advanced_layout( array( 'background' => array( 'color' => 'transparent' ) ) ) ), array(), 'transparent is allowed' );
} );

rk_test( 'advanced styles: a foreign-host background image is rejected but a relative one is fine', function () {
	t_assert( count( rk_builder_validate_layout( rk_advanced_layout( array( 'background' => array( 'imageUrl' => 'https://evil.example/a.jpg' ) ) ), RK_TEST_HOSTS ) ) === 1 );
	t_eq( rk_builder_validate_layout( rk_advanced_layout( array( 'background' => array( 'imageUrl' => 'https://cms.example.com/a.jpg' ) ) ), RK_TEST_HOSTS ), array() );
	t_eq( rk_builder_validate_layout( rk_advanced_layout( array( 'background' => array( 'imageUrl' => '/uploads/a.jpg' ) ) ), RK_TEST_HOSTS ), array() );
} );

rk_test( 'advanced styles: survive canonicalisation in place', function () {
	$layout = rk_advanced_layout( array( 'spacing' => array( 'top' => '24px' ), 'cssClass' => 'x' ) );
	$canon  = rk_builder_canonicalize_layout( $layout );
	t_deep( $canon['blocks'][0]['advanced'], array( 'spacing' => array( 'top' => '24px' ), 'cssClass' => 'x' ) );
	t_eq( array_keys( $canon['blocks'][0] ), array( 'id', 'type', 'props', 'advanced' ), 'advanced comes after props' );
	$plain = rk_builder_canonicalize_layout( rk_advanced_layout( null ) );
	t_eq( array_keys( $plain['blocks'][0] ), array( 'id', 'type', 'props' ), 'no advanced key when unstyled' );
} );
