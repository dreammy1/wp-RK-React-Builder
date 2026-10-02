<?php
/**
 * Public document for a theme-builder template (single entry, archive / listing). Selected by
 * rk_builder_dyn_template_include(). A complete minimal document like public-layout.php; the template's own
 * header / footer blocks replace the default chrome. No JavaScript is emitted by this file.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$rk_req    = rk_builder_dyn_request();
$rk_theme  = rk_builder_get_theme();
$rk_layout = $rk_req ? $rk_req['tpl']['layout'] : array( 'version' => 1, 'blocks' => array() );
$rk_ctx    = array(
	'post'        => $rk_req ? $rk_req['post'] : null,
	'type'        => $rk_req ? $rk_req['type'] : '',
	'term'        => $rk_req && isset( $rk_req['term'] ) ? $rk_req['term'] : null,
	'template_id' => $rk_req ? (int) $rk_req['tpl']['id'] : 0,
	'preview'     => false,
);
$rk_html = rk_builder_render_layout( $rk_layout, $rk_ctx );
$rk_has  = function ( $type ) use ( $rk_layout ) {
	foreach ( isset( $rk_layout['blocks'] ) ? $rk_layout['blocks'] : array() as $b ) {
		if ( ! is_array( $b ) || ! isset( $b['type'] ) ) { continue; }
		if ( $type === $b['type'] ) { return true; }
		if ( 'reusable' === $b['type'] && isset( $b['props']['refId'] ) && rk_builder_reusable_type( (int) $b['props']['refId'] ) === $type ) { return true; }
	}
	return false;
};
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) : ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'rk-standalone rk-dynamic' ); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); }
echo '<div class="site-root rk-root">';
if ( ! $rk_has( 'navbar' ) ) { echo rk_builder_site_header_html( $rk_theme ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<main id="main">' . $rk_html . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
if ( ! $rk_has( 'sitefooter' ) ) { echo rk_builder_site_footer_html( $rk_theme ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '</div>';
wp_footer();
?>
</body>
</html>
