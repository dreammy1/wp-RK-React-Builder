<?php
/**
 * Standalone public template: a complete, minimal HTML document for a published RK page.
 * Selected by rk_builder_filter_template_include() when setting `public_rendering_mode` is "standalone".
 * No JavaScript is emitted by this template.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$rk_page  = rk_builder_current_request_page();
$rk_theme = rk_builder_get_theme();
$rk_ctx   = $rk_page ? array( 'page_id' => (int) $rk_page['page']->ID, 'preview' => false ) : array();
// A live header / footer template (Templates > Header, Footer) replaces the page's own navbar / footer blocks.
$rk_parts = $rk_page ? rk_builder_tpl_apply_parts( $rk_page['layout'], $rk_ctx ) : array( 'layout' => array(), 'ctx' => $rk_ctx, 'header' => '', 'footer' => '', 'has_header' => false, 'has_footer' => false );
$rk_html  = $rk_page ? rk_builder_render_layout( $rk_parts['layout'], $rk_parts['ctx'] ) : '';
// A layout that brings its own header / footer blocks (navbar, sitefooter, or a reusable that holds one) replaces the default chrome.
$rk_has = function ( $type ) use ( $rk_page ) {
	if ( ! $rk_page || empty( $rk_page['layout']['blocks'] ) ) { return false; }
	foreach ( $rk_page['layout']['blocks'] as $b ) {
		if ( ! is_array( $b ) || ! isset( $b['type'] ) ) { continue; }
		if ( $type === $b['type'] ) { return true; }
		if ( 'reusable' === $b['type'] && isset( $b['props']['refId'] ) && function_exists( 'rk_builder_reusable_type' ) && rk_builder_reusable_type( (int) $b['props']['refId'] ) === $type ) { return true; }
	}
	return false;
};
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) : // block themes already print the viewport tag ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php endif; ?>
<?php echo rk_builder_topbar_head_script( $rk_parts['header'] . $rk_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the key is digits only ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( $rk_parts['has_header'] && ! empty( $rk_parts['ctx']['solid_nav'] ) ? 'rk-standalone rk-solid-nav' : 'rk-standalone' ); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); }
// All fragments below are escaped by the renderer (see includes/renderer.php).
echo '<div class="site-root rk-root">';
if ( $rk_parts['has_header'] ) { echo $rk_parts['header']; } elseif ( ! $rk_has( 'navbar' ) ) { echo rk_builder_site_header_html( $rk_theme ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<main id="main">' . $rk_html . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
if ( $rk_parts['has_footer'] ) { echo $rk_parts['footer']; } elseif ( ! $rk_has( 'sitefooter' ) ) { echo rk_builder_site_footer_html( $rk_theme ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '</div>';
wp_footer();
?>
</body>
</html>
