<?php
/** Divider block. Mirrors client/src/blocks/divider/View.tsx (style is an enum). */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_divider( array $p, array $context = array() ) {
	$style = 'dashed' === $p['style'] ? 'dashed' : 'solid';
	return '<hr ' . rk_builder_root_attrs( 'divider', 'site-divider ' . $style ) . '/>';
}
