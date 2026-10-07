<?php
/**
 * UI Library unit tests: endpoints for summary, applying theme tokens, creating pages,
 * creating templates, and creating reusable blocks.
 */

rk_test( 'ui library: summary endpoint returns categories, archetypes and counts', function () {
	rk_test_reset();
	rk_test_login( 'editor' );

	$data = t_ok( rk_get( '/rk/v1/builder/ui-library/summary' ), 'ui library summary' );

	t_assert( isset( $data['categories'] ) && is_array( $data['categories'] ), 'categories present' );
	t_eq( count( $data['categories'] ), 5, '5 categories' );
	t_assert( isset( $data['archetypes'] ) && is_array( $data['archetypes'] ), 'archetypes present' );
	t_eq( count( $data['archetypes'] ), 5, '5 archetypes' );
	t_eq( $data['totalVariants'], 25, '25 total variants' );
} );

rk_test( 'ui library: apply theme requires manage_options and validates tokens', function () {
	rk_test_reset();

	$valid_theme = array(
		'version'       => 1,
		'primary'       => '#4F46E5',
		'bg'            => '#FFFFFF',
		'ink'           => '#0F172A',
		'font'          => 'System Sans',
		'accent'        => '#6366F1',
		'dark'          => '#0F172A',
		'surface'       => '#F8FAFC',
		'headingFont'   => 'System Sans',
		'headingWeight' => 700,
		'radius'        => 'soft',
		'buttonStyle'   => 'solid',
	);

	// Editor without manage_options is forbidden
	rk_test_login( 'editor' );
	t_err( rk_post( '/rk/v1/builder/ui-library/apply-theme', array( 'theme' => $valid_theme ) ), 'rk_forbidden', 403, 'editor cannot apply theme' );

	// Admin with manage_options succeeds
	rk_test_login( 'admin' );
	$data = t_ok( rk_post( '/rk/v1/builder/ui-library/apply-theme', array( 'theme' => $valid_theme ) ), 'admin applies theme' );
	t_assert( ! empty( $data['ok'] ), 'apply theme ok' );
	t_eq( $data['theme']['primary'], '#4F46E5' );
	t_eq( $data['theme']['radius'], 'soft' );

	// Invalid theme payload returns 400
	$bad_res = rk_post( '/rk/v1/builder/ui-library/apply-theme', array( 'theme' => array( 'version' => 1, 'primary' => 'not-a-color' ) ) );
	t_err( $bad_res, 'rk_invalid_theme', 400, 'invalid theme rejected' );
} );

rk_test( 'ui library: create page creates draft with layout', function () {
	rk_test_reset();
	rk_test_login( 'editor' );

	$layout = array(
		'version' => 1,
		'blocks'  => array(
			array(
				'id'    => 'hero-ui-1',
				'type'  => 'hero',
				'props' => array(
					'heading' => 'Welcome to Modern SaaS',
					'sub'     => 'Designed with premium UI library components.',
					'cta'     => 'Get Started',
					'ctaHref' => '/start',
				),
			),
		),
	);

	$data = t_ok( rk_post( '/rk/v1/builder/ui-library/create-page', array(
		'title'  => 'SaaS Landing Page',
		'layout' => $layout,
	) ), 'create page from template' );

	t_assert( ! empty( $data['ok'] ), 'created page ok' );
	t_assert( isset( $data['id'] ) && $data['id'] > 0, 'page id returned' );

	$page = get_post( $data['id'] );
	t_assert( null !== $page, 'page exists in db' );
	t_eq( $page->post_status, 'draft', 'page is draft' );
	t_eq( $page->post_title, 'SaaS Landing Page', 'title matches' );

	$saved_layout = rk_builder_get_draft_layout( $data['id'] );
	t_assert( null !== $saved_layout, 'draft layout exists' );
	t_eq( count( $saved_layout['blocks'] ), 1, '1 block' );
	t_eq( $saved_layout['blocks'][0]['type'], 'hero' );
} );

rk_test( 'ui library: create header and footer template', function () {
	rk_test_reset();
	rk_test_login( 'admin' );

	$nav_layout = array(
		'version' => 1,
		'blocks'  => array(
			array(
				'id'    => 'nav-ui-1',
				'type'  => 'navbar',
				'props' => array(
					'brand'     => 'Modern Brand',
					'links'     => "Home|/\nAbout|/about\nContact|/contact",
					'phone'     => '555-0199',
					'phoneHref' => 'tel:5550199',
					'overlay'   => false,
				),
			),
		),
	);

	$data = t_ok( rk_post( '/rk/v1/builder/ui-library/create-template', array(
		'title'    => 'Modern SaaS Header',
		'kind'     => 'header',
		'layout'   => $nav_layout,
		'activate' => true,
	) ), 'create header template' );

	t_assert( ! empty( $data['ok'] ), 'created template ok' );
	t_assert( isset( $data['id'] ) && $data['id'] > 0, 'template id returned' );

	$tpl = get_post( $data['id'] );
	t_assert( null !== $tpl, 'template exists' );
	t_eq( $tpl->post_type, RK_BUILDER_TEMPLATE_TYPE );
	t_eq( get_post_meta( $data['id'], '_rk_tpl_kind', true ), 'header' );
	t_eq( get_post_meta( $data['id'], '_rk_tpl_active', true ), '1', 'activated site-wide' );
} );

rk_test( 'ui library: create reusable block from section', function () {
	rk_test_reset();
	rk_test_login( 'editor' );

	$block = array(
		'type'  => 'cta',
		'props' => array(
			'heading' => 'Ready to transform your workflow?',
			'cta'     => 'Join the Beta',
			'ctaHref' => '/signup',
		),
	);

	$data = t_ok( rk_post( '/rk/v1/builder/ui-library/create-reusable', array(
		'name'  => 'CTA Banner Section',
		'block' => $block,
	) ), 'create reusable from section' );

	t_assert( ! empty( $data['ok'] ), 'created reusable ok' );
	t_assert( isset( $data['id'] ) && $data['id'] > 0, 'reusable id returned' );

	$reusable = rk_builder_reusable_get( $data['id'] );
	t_assert( null !== $reusable, 'reusable exists' );
	t_eq( $reusable['name'], 'CTA Banner Section' );
	t_eq( $reusable['block']['type'], 'cta' );
} );
