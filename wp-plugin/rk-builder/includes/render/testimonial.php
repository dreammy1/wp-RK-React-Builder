<?php
/** Testimonial block. Mirrors client/src/blocks/testimonial/View.tsx. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_testimonial( array $p, array $context = array() ) {
	$html  = '<section ' . rk_builder_root_attrs( 'testimonial', 'site-testimonial' ) . '><figure>';
	$html .= '<blockquote><p>' . rk_builder_h( $p['quote'] ) . '</p></blockquote>';
	$html .= '<figcaption><strong>' . rk_builder_h( $p['author'] ) . '</strong>';
	if ( '' !== $p['role'] ) { $html .= '<span>' . rk_builder_h( $p['role'] ) . '</span>'; }
	return $html . '</figcaption></figure></section>';
}
