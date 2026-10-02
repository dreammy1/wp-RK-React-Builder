<?php
/** Hero block. Markup mirrors client/src/blocks/hero/View.tsx. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_hero( array $p, array $context = array() ) {
	$bg = isset( $p['bgUrl'] ) && is_string( $p['bgUrl'] ) ? $p['bgUrl'] : '';
	$id = isset( $p['bgMediaId'] ) ? (int) $p['bgMediaId'] : 0;
	if ( $id > 0 ) {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( is_array( $src ) && ! empty( $src[0] ) && is_string( $src[0] ) ) { $bg = $src[0]; }
	}
	$bg_src = '' !== $bg ? rk_builder_src( $bg ) : '';
	$html   = '<section ' . rk_builder_root_attrs( 'hero', '' !== $bg_src ? 'site-hero has-bg' : 'site-hero' ) . '>';
	if ( '' !== $bg_src ) { $html .= '<img class="hero-bg" src="' . $bg_src . '" alt="" decoding="async" fetchPriority="high"/>'; }
	$html .= '<div class="hero-rule">01 / proposition</div>';
	$html .= '<h1>' . rk_builder_h( $p['heading'] ) . '</h1>';
	if ( '' !== $p['sub'] ) { $html .= '<p>' . rk_builder_h( $p['sub'] ) . '</p>'; }
	if ( '' !== $p['cta'] ) {
		$html .= '<a class="site-btn" href="' . rk_builder_href( $p['ctaHref'] ) . '">' . rk_builder_h( $p['cta'] ) . rk_builder_arrow_icon() . '</a>';
	}
	return $html . '</section>';
}
