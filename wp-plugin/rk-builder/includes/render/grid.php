<?php
/** Shared Services/Portfolio grid. Mirrors client/src/blocks/ContentGrid.tsx (public mode, "ready"/"error" states). */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One card: featured image (or placeholder), plain-text title and excerpt. */
function rk_builder_render_card( array $item, $cols ) {
	$html = '<article class="content-card">';
	$img  = isset( $item['image'] ) && is_array( $item['image'] ) ? $item['image'] : null;
	$src  = $img ? rk_builder_src( isset( $img['url'] ) ? $img['url'] : '' ) : '';
	if ( $src ) {
		$srcset = isset( $img['srcset'] ) && is_string( $img['srcset'] ) ? $img['srcset'] : '';
		$html  .= '<img src="' . $src . '" alt="' . rk_builder_h( isset( $img['alt'] ) ? $img['alt'] : '' ) . '"'
			. ' width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '"'
			. ( '' !== $srcset ? ' srcSet="' . rk_builder_h( $srcset ) . '"' : '' )
			. ' sizes="(min-width: 900px) ' . (int) round( 100 / $cols ) . 'vw, 100vw" loading="lazy" decoding="async"/>';
	} else {
		$html .= '<div class="card-placeholder" aria-hidden="true"></div>';
	}
	$html .= '<div><h3>' . rk_builder_h( $item['title'] ) . '</h3>';
	if ( '' !== (string) $item['excerpt'] ) { $html .= '<p>' . rk_builder_h( $item['excerpt'] ) . '</p>'; }
	return $html . '</div></article>';
}

/**
 * @param string $type  'services' | 'portfolio' (block type, used for the root classes)
 * @param string $noun  'service' | 'project'
 * @param array  $p     validated block props
 */
function rk_builder_render_grid( $type, $noun, array $p, array $context = array() ) {
	$root  = '<section ' . rk_builder_root_attrs( $type, 'site-grid' );
	$head  = '<div class="grid-head"><h2>' . rk_builder_h( $p['title'] ) . '</h2></div>';
	$cols  = (int) $p['cols'];
	$state = 'ready';
	$items = array();
	try {
		$res = rk_builder_query_content( $p['source'], array( 'limit' => $p['limit'], 'category' => $p['category'], 'orderby' => $p['orderBy'], 'order' => $p['order'] ) );
		// A disabled content type behaves like "nothing to show yet".
		$items = null === $res ? array() : $res['items'];
	} catch ( Throwable $e ) {
		$state = 'error';
		rk_builder_log( 'warning', 'grid_query_failed', array( 'source' => $p['source'], 'error' => get_class( $e ) ) );
		rk_builder_record_render_error( isset( $context['page_id'] ) ? (int) $context['page_id'] : 0, 'rk_grid_query_failed' );
	}
	$body = '';
	if ( 'error' === $state ) {
		$body = '<p class="grid-note">' . rk_builder_h( ucfirst( $noun ) . 's are temporarily unavailable.' ) . '</p>';
	} elseif ( ! $items ) {
		$body = '<p class="grid-note">No ' . rk_builder_h( $noun ) . 's to show yet.</p>';
	} else {
		$body = '<div class="cards" style="grid-template-columns:repeat(' . $cols . ', minmax(0, 1fr))">';
		foreach ( $items as $item ) { $body .= rk_builder_render_card( $item, $cols ); }
		$body .= '</div>';
	}
	return $root . ' data-state="' . $state . '">' . $head . $body . '</section>';
}
