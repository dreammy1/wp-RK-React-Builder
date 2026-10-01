<?php
/** Heading block (levels 2 and 3 only). Mirrors client/src/blocks/heading/View.tsx. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_heading( array $p, array $context = array() ) {
	$tag = 3 === $p['level'] ? 'h3' : 'h2'; // never a caller-supplied tag name
	return '<section ' . rk_builder_root_attrs( 'heading', 'site-heading' ) . '><' . $tag . '>' . rk_builder_h( $p['text'] ) . '</' . $tag . '></section>';
}
