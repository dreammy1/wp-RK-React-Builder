<?php
/** Spacer block. Mirrors client/src/blocks/spacer/View.tsx (height is a validated integer). */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_spacer( array $p, array $context = array() ) {
	return '<div ' . rk_builder_root_attrs( 'spacer', 'site-spacer' ) . ' style="height:' . (int) $p['h'] . 'px" aria-hidden="true"></div>';
}
