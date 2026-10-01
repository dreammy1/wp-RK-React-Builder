<?php
/** Hero block. Markup mirrors client/src/blocks/hero/View.tsx. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_hero( array $p, array $context = array() ) {
	$html  = '<section ' . rk_builder_root_attrs( 'hero', 'site-hero' ) . '>';
	$html .= '<div class="hero-rule">01 / proposition</div>';
	$html .= '<h1>' . rk_builder_h( $p['heading'] ) . '</h1>';
	if ( '' !== $p['sub'] ) { $html .= '<p>' . rk_builder_h( $p['sub'] ) . '</p>'; }
	if ( '' !== $p['cta'] ) {
		$html .= '<a class="site-btn" href="' . rk_builder_href( $p['ctaHref'] ) . '">' . rk_builder_h( $p['cta'] ) . rk_builder_arrow_icon() . '</a>';
	}
	return $html . '</section>';
}
