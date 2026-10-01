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
$rk_html  = $rk_page ? rk_builder_render_layout( $rk_page['layout'], array( 'page_id' => (int) $rk_page['page']->ID, 'preview' => false ) ) : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) : // block themes already print the viewport tag ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'rk-standalone' ); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); }
// All fragments below are escaped by the renderer (see includes/renderer.php).
echo '<div class="site-root rk-root">';
echo rk_builder_site_header_html( $rk_theme ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<main id="main">' . $rk_html . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo rk_builder_site_footer_html( $rk_theme ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '</div>';
wp_footer();
?>
</body>
</html>
