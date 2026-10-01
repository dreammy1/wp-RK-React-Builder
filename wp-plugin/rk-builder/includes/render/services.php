<?php
/** Services grid block: live, published `service` posts (never copied into the layout). */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_services( array $p, array $context = array() ) {
	return rk_builder_render_grid( 'services', 'service', $p, $context );
}
