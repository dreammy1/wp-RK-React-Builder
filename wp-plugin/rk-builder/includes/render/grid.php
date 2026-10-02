<?php
/** Shared Services/Portfolio grid. Mirrors client/src/blocks/ContentGrid.tsx (public mode, "ready"/"error" states). */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Section classes from the display options. Mirrors gridClass() in blocks/gridOptions.ts. */
function rk_builder_grid_classes( array $p ) {
	$c = 'site-grid';
	if ( isset( $p['tone'] ) && 'muted' === $p['tone'] ) { $c .= ' tone-muted'; }
	if ( ! empty( $p['equalHeight'] ) ) { $c .= ' eq'; }
	if ( isset( $p['imageRatio'] ) ) { $c .= ' ratio-' . $p['imageRatio']; }
	if ( isset( $p['cardStyle'] ) ) { $c .= ' style-' . $p['cardStyle']; }
	if ( isset( $p['gap'] ) ) { $c .= ' gap-' . $p['gap']; }
	if ( isset( $p['mobileCols'] ) && 2 === (int) $p['mobileCols'] ) { $c .= ' m2'; }
	return $c;
}

/** One card: featured image (or placeholder), title, categories, excerpt and an optional link or button. */
function rk_builder_render_card( array $item, $cols, array $p = array() ) {
	$html = '<article class="content-card">';
	if ( ! isset( $p['showImage'] ) || $p['showImage'] ) {
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
	}
	$mode = isset( $p['cardLink'] ) ? $p['cardLink'] : 'none';
	$href = 'none' !== $mode && isset( $item['link'] ) ? rk_builder_safe_href( (string) $item['link'] ) : '';
	$html .= '<div>';
	if ( ! empty( $p['showCategories'] ) && ! empty( $item['categories'] ) ) {
		$html .= '<p class="card-tags">';
		foreach ( $item['categories'] as $c ) { $html .= '<span>' . rk_builder_h( $c ) . '</span>'; }
		$html .= '</p>';
	}
	$title = rk_builder_h( $item['title'] );
	$html .= '<h3>' . ( 'title' === $mode && '' !== $href ? '<a href="' . $href . '">' . $title . '</a>' : $title ) . '</h3>';
	if ( ( ! isset( $p['showExcerpt'] ) || $p['showExcerpt'] ) && '' !== (string) $item['excerpt'] ) {
		$lines = isset( $p['excerptLines'] ) ? (int) $p['excerptLines'] : 0;
		$html .= ( $lines > 0 ? '<p class="clamp" style="-webkit-line-clamp:' . $lines . '">' : '<p>' ) . rk_builder_h( $item['excerpt'] ) . '</p>';
	}
	if ( 'button' === $mode && '' !== $href ) {
		$label = isset( $p['buttonLabel'] ) && '' !== $p['buttonLabel'] ? $p['buttonLabel'] : 'Learn more';
		$html .= '<a class="card-btn" href="' . $href . '">' . rk_builder_h( $label ) . '</a>';
	}
	return $html . '</div></article>';
}

/**
 * @param string $type  'services' | 'portfolio' (block type, used for the root classes)
 * @param string $noun  'service' | 'project'
 * @param array  $p     validated block props
 */
function rk_builder_render_grid( $type, $noun, array $p, array $context = array() ) {
	$root  = '<section ' . rk_builder_root_attrs( $type, rk_builder_grid_classes( $p ) );
	$eyebrow = isset( $p['eyebrow'] ) ? $p['eyebrow'] : '';
	$intro   = isset( $p['intro'] ) ? $p['intro'] : '';
	$title   = '<h2>' . rk_builder_h( $p['title'] ) . '</h2>';
	$head    = '<div class="grid-head">' . ( '' !== $eyebrow || '' !== $intro
		? '<div>' . ( '' !== $eyebrow ? '<p class="pf-kicker">' . rk_builder_h( $eyebrow ) . '</p>' : '' ) . $title . ( '' !== $intro ? '<p class="grid-intro">' . rk_builder_h( $intro ) . '</p>' : '' ) . '</div>'
		: $title ) . '</div>';
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
		foreach ( $items as $item ) { $body .= rk_builder_render_card( $item, $cols, $p ); }
		$body .= '</div>';
		$more_href = isset( $p['viewAllHref'] ) ? rk_builder_safe_href( (string) $p['viewAllHref'] ) : '';
		if ( ! empty( $p['viewAllLabel'] ) && '' !== $more_href ) {
			$body .= '<div class="grid-more"><a class="card-btn" href="' . $more_href . '">' . rk_builder_h( $p['viewAllLabel'] ) . '</a></div>';
		}
	}
	return $root . ' data-state="' . $state . '">' . $head . $body . '</section>';
}
