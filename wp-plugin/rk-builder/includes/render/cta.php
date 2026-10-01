<?php
/** CTA banner block. Mirrors client/src/blocks/cta/View.tsx. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_cta( array $p, array $context = array() ) {
	return '<section ' . rk_builder_root_attrs( 'cta', 'site-cta' ) . '><h3>' . rk_builder_h( $p['heading'] ) . '</h3>'
		. '<a class="site-btn inverse" href="' . rk_builder_href( $p['ctaHref'] ) . '">' . rk_builder_h( $p['cta'] ) . rk_builder_arrow_icon() . '</a></section>';
}
