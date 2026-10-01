<?php
/** Portfolio grid block: live, published `portfolio` posts (never copied into the layout). */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_portfolio( array $p, array $context = array() ) {
	return rk_builder_render_grid( 'portfolio', 'project', $p, $context );
}
