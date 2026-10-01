<?php
/** Text block: plain text, paragraphs split on blank lines. Mirrors client/src/blocks/text/View.tsx. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_text( array $p, array $context = array() ) {
	$html = '<section ' . rk_builder_root_attrs( 'text', 'site-text' ) . '>';
	$parts = preg_split( '/\n{2,}/', $p['text'] );
	foreach ( false === $parts ? array() : $parts as $para ) {
		if ( '' === rk_builder_trim_ws( $para ) ) { continue; }
		$html .= '<p>' . rk_builder_h( $para ) . '</p>';
	}
	return $html . '</section>';
}
