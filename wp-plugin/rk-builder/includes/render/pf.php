<?php
/** Navbar, cover hero and site footer blocks. Mirror client/src/blocks/{navbar,coverhero,sitefooter}/View.tsx and client/src/blocks/links.ts. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One link per line, "Label|/path"; no label or an unsafe target drops the line. Mirrors parseLinks(). */
function rk_builder_parse_links( $source, $max = 12 ) {
	$out = array();
	foreach ( explode( "\n", (string) $source ) as $raw ) {
		$line = trim( $raw );
		$cut  = strpos( $line, '|' );
		if ( false === $cut || $cut < 1 ) { continue; }
		$label = trim( substr( $line, 0, $cut ) );
		$href  = trim( substr( $line, $cut + 1 ) );
		if ( '' === $label || '' === $href || ! rk_builder_is_safe_link( $href ) ) { continue; }
		$out[] = array( 'label' => $label, 'href' => $href );
		if ( count( $out ) >= $max ) { break; }
	}
	return $out;
}

/**
 * A menu with drop-downs: "Label|/path" per line, and "- Label|/path" for an item under the line above (one level).
 * Mirrors parseMenu().
 */
function rk_builder_parse_menu( $source, $max = 12 ) {
	$out = array();
	foreach ( explode( "\n", (string) $source ) as $raw ) {
		$line = trim( $raw );
		$sub  = '' !== $line && '-' === $line[0];
		if ( $sub ) { $line = trim( substr( $line, 1 ) ); }
		$cut = strpos( $line, '|' );
		if ( false === $cut || $cut < 1 ) { continue; }
		$label = trim( substr( $line, 0, $cut ) );
		$href  = trim( substr( $line, $cut + 1 ) );
		if ( '' === $label || '' === $href || ! rk_builder_is_safe_link( $href ) ) { continue; }
		if ( $sub ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && count( $out[ $last ]['children'] ) < 8 ) { $out[ $last ]['children'][] = array( 'label' => $label, 'href' => $href ); }
			continue;
		}
		if ( count( $out ) >= $max ) { continue; }
		$out[] = array( 'label' => $label, 'href' => $href, 'children' => array() );
	}
	return $out;
}

/** The exact inline SVG React emits for lucide's Phone (size 15). */
function rk_builder_phone_icon() {
	return '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-phone" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>';
}

function rk_builder_arrow_right_icon() {
	return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right" aria-hidden="true"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';
}

/** Attachment id wins over the stored URL (so a renamed/moved file still resolves). */
function rk_builder_media_url( array $p, $id_key, $url_key ) {
	$url = isset( $p[ $url_key ] ) && is_string( $p[ $url_key ] ) ? $p[ $url_key ] : '';
	$id  = isset( $p[ $id_key ] ) ? (int) $p[ $id_key ] : 0;
	if ( $id > 0 ) {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( is_array( $src ) && ! empty( $src[0] ) && is_string( $src[0] ) ) { $url = $src[0]; }
	}
	return '' !== $url ? rk_builder_src( $url ) : '';
}

function rk_builder_render_navbar( array $p, array $context = array() ) {
	$logo    = rk_builder_media_url( $p, 'logoMediaId', 'logoUrl' );
	$menu    = rk_builder_parse_menu( $p['links'] );
	$bg      = isset( $p['bg'] ) ? $p['bg'] : 'auto';
	$size    = isset( $p['size'] ) ? $p['size'] : 'regular';
	$buttons = isset( $p['buttons'] ) ? $p['buttons'] : 'auto';
	$heights = array( 'sm' => '40', 'md' => '64', 'lg' => '88' );
	$height  = $heights[ isset( $p['logoSize'] ) ? $p['logoSize'] : 'md' ];
	$cls     = ( $p['overlay'] && empty( $context['solid_nav'] ) ? 'pf-nav overlay' : 'pf-nav' )
		. ( 'auto' !== $bg ? ' bg-' . $bg : '' )
		. ( 'regular' !== $size ? ' size-' . $size : '' )
		. ( isset( $p['align'] ) && 'left' === $p['align'] ? ' align-left' : '' )
		. ( 'auto' !== $buttons ? ' btn-' . $buttons : '' )
		. ( ! empty( $p['shadow'] ) ? ' shadow' : '' );
	$cta_text = isset( $p['ctaText'] ) ? $p['ctaText'] : '';
	$cta_href = isset( $p['ctaHref'] ) ? $p['ctaHref'] : '';
	$has_cta  = '' !== $cta_text && '' !== $cta_href;
	$html  = '<header ' . rk_builder_root_attrs( 'navbar', $cls ) . '>';
	$html .= '<a class="pf-brand" href="/">' . ( '' !== $logo ? '<img src="' . $logo . '" alt="' . rk_builder_h( $p['brand'] ) . '" height="' . $height . '"/>' : '<span>' . rk_builder_h( $p['brand'] ) . '</span>' ) . '</a>';
	if ( $menu ) {
		$html .= '<nav aria-label="Main"><ul>';
		foreach ( $menu as $l ) {
			if ( $l['children'] ) {
				$html .= '<li class="has-sub"><a href="' . rk_builder_href( $l['href'] ) . '">' . rk_builder_h( $l['label'] ) . '</a>';
				$html .= '<button type="button" class="pf-sub-toggle" aria-label="' . rk_builder_h( $l['label'] ) . ' submenu" aria-expanded="false"><span class="pf-chev" aria-hidden="true"></span></button>';
				$html .= '<ul class="pf-sub">';
				foreach ( $l['children'] as $c ) { $html .= '<li><a href="' . rk_builder_href( $c['href'] ) . '">' . rk_builder_h( $c['label'] ) . '</a></li>'; }
				$html .= '</ul></li>';
			} else {
				$html .= '<li><a href="' . rk_builder_href( $l['href'] ) . '">' . rk_builder_h( $l['label'] ) . '</a></li>';
			}
		}
		$html .= '</ul></nav>';
	}
	if ( '' !== $p['phone'] && '' !== $p['phoneHref'] ) {
		$html .= '<a class="pf-nav-phone" href="' . rk_builder_href( $p['phoneHref'] ) . '">' . rk_builder_phone_icon() . rk_builder_h( $p['phone'] ) . '</a>';
	}
	if ( $has_cta ) { $html .= '<a class="pf-nav-cta" href="' . rk_builder_href( $cta_href ) . '">' . rk_builder_h( $cta_text ) . '</a>'; }
	if ( $menu ) {
		$html .= '<button type="button" class="pf-nav-toggle" aria-label="Open menu" aria-expanded="false" data-nav-toggle=""><span class="pf-burger" aria-hidden="true"></span></button>';
		$html .= '<div class="pf-nav-panel"><ul>';
		foreach ( $menu as $l ) {
			$html .= '<li><a href="' . rk_builder_href( $l['href'] ) . '">' . rk_builder_h( $l['label'] ) . '</a>';
			if ( $l['children'] ) {
				$html .= '<ul class="pf-panel-sub">';
				foreach ( $l['children'] as $c ) { $html .= '<li><a href="' . rk_builder_href( $c['href'] ) . '">' . rk_builder_h( $c['label'] ) . '</a></li>'; }
				$html .= '</ul>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		if ( '' !== $p['phone'] && '' !== $p['phoneHref'] ) { $html .= '<a class="pf-nav-panel-call" href="' . rk_builder_href( $p['phoneHref'] ) . '">Call ' . rk_builder_h( $p['phone'] ) . '</a>'; }
		if ( $has_cta ) { $html .= '<a class="pf-nav-panel-cta" href="' . rk_builder_href( $cta_href ) . '">' . rk_builder_h( $cta_text ) . '</a>'; }
		$html .= '</div>';
	}
	return $html . '</header>';
}

function rk_builder_render_coverhero( array $p, array $context = array() ) {
	$bg   = rk_builder_media_url( $p, 'bgMediaId', 'bgUrl' );
	$html = '<section ' . rk_builder_root_attrs( 'coverhero', 'pf-hero ' . $p['size'] ) . '>';
	if ( '' !== $bg ) { $html .= '<img class="pf-hero-bg" src="' . $bg . '" alt="" decoding="async" fetchPriority="high"/>'; }
	$html .= '<div class="pf-hero-shade"></div><div class="pf-hero-body">';
	$crumbs = array();
	foreach ( rk_builder_parse_rows( $p['crumb'], 2, 3 ) as $r ) { if ( '' !== $r[0] ) { $crumbs[] = $r; } }
	if ( $crumbs ) {
		$html .= '<nav class="pf-crumbs" aria-label="Breadcrumb"><a href="/">Home</a>';
		foreach ( $crumbs as $i => $r ) {
			$linked = '' !== rk_builder_safe_href( $r[1] ) && $i < count( $crumbs ) - 1;
			$html  .= '<span><i aria-hidden="true">›</i>' . ( $linked ? '<a href="' . rk_builder_safe_href( $r[1] ) . '">' . rk_builder_h( $r[0] ) . '</a>' : rk_builder_h( $r[0] ) ) . '</span>';
		}
		$html .= '</nav>';
	}
	if ( '' !== $p['eyebrow'] ) { $html .= '<p class="pf-eyebrow">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
	$html .= '<h1>' . rk_builder_h( $p['heading'] ) . '</h1>';
	if ( '' !== $p['sub'] ) { $html .= '<p class="pf-lead">' . rk_builder_h( $p['sub'] ) . '</p>'; }
	if ( '' !== $p['cta'] || '' !== $p['cta2'] ) {
		$html .= '<div class="pf-actions">';
		if ( '' !== $p['cta'] ) { $html .= '<a class="pf-btn solid" href="' . rk_builder_href( $p['ctaHref'] ) . '">' . ( 0 === strpos( $p['ctaHref'], 'tel:' ) ? rk_builder_icon( 'phone' ) : '' ) . rk_builder_h( $p['cta'] ) . '</a>'; }
		if ( '' !== $p['cta2'] ) { $html .= '<a class="pf-btn ghost" href="' . rk_builder_href( $p['cta2Href'] ) . '">' . rk_builder_h( $p['cta2'] ) . rk_builder_arrow_right_icon() . '</a>'; }
		$html .= '</div>';
	}
	return $html . '</div></section>';
}

function rk_builder_footer_column( $title, $source ) {
	$links = rk_builder_parse_links( $source );
	if ( ! $links ) { return ''; }
	$html = '<div class="pf-foot-col">' . ( '' !== $title ? '<h3>' . rk_builder_h( $title ) . '</h3>' : '' ) . '<ul>';
	foreach ( $links as $l ) { $html .= '<li><a href="' . rk_builder_href( $l['href'] ) . '">' . rk_builder_h( $l['label'] ) . '</a></li>'; }
	return $html . '</ul></div>';
}

function rk_builder_footer_social( $source ) {
	$links = rk_builder_parse_links( $source, 8 );
	if ( ! $links ) { return ''; }
	$html = '<ul class="pf-foot-social">';
	foreach ( $links as $l ) { $html .= '<li><a href="' . rk_builder_href( $l['href'] ) . '" rel="noopener noreferrer">' . rk_builder_h( $l['label'] ) . '</a></li>'; }
	return $html . '</ul>';
}

function rk_builder_render_sitefooter( array $p, array $context = array() ) {
	$tone  = isset( $p['tone'] ) ? $p['tone'] : 'dark';
	$html  = '<footer ' . rk_builder_root_attrs( 'sitefooter', 'dark' !== $tone ? 'pf-foot tone-' . $tone : 'pf-foot' ) . '><div class="pf-foot-grid">';
	$logo  = rk_builder_media_url( $p, 'logoMediaId', 'logoUrl' );
	$html .= '<div class="pf-foot-col">' . ( '' !== $logo ? '<img class="pf-foot-logo" src="' . $logo . '" alt="' . rk_builder_h( $p['brand'] ) . '" height="64"/>' : '<strong class="pf-foot-brand">' . rk_builder_h( $p['brand'] ) . '</strong>' ) . ( '' !== $p['tagline'] ? '<p>' . rk_builder_h( $p['tagline'] ) . '</p>' : '' ) . rk_builder_footer_social( isset( $p['social'] ) ? $p['social'] : '' ) . '</div>';
	$html .= rk_builder_footer_column( $p['colATitle'], $p['colALinks'] ) . rk_builder_footer_column( $p['colBTitle'], $p['colBLinks'] );
	if ( '' !== $p['phone'] || '' !== $p['email'] || '' !== $p['address'] ) {
		$tel  = rk_builder_phone_href( $p['phone'] );
		$mail = rk_builder_email_href( $p['email'] );
		$html .= '<div class="pf-foot-col">' . ( '' !== $p['contactTitle'] ? '<h3>' . rk_builder_h( $p['contactTitle'] ) . '</h3>' : '' ) . '<ul>';
		if ( '' !== $p['phone'] ) { $html .= '<li>' . rk_builder_icon( 'phone' ) . ( '' !== $tel ? '<a href="' . rk_builder_h( $tel ) . '">' . rk_builder_h( $p['phone'] ) . '</a>' : rk_builder_h( $p['phone'] ) ) . '</li>'; }
		if ( '' !== $p['email'] ) { $html .= '<li>' . rk_builder_icon( 'mail' ) . ( '' !== $mail ? '<a href="' . rk_builder_h( $mail ) . '">' . rk_builder_h( $p['email'] ) . '</a>' : rk_builder_h( $p['email'] ) ) . '</li>'; }
		if ( '' !== $p['address'] ) { $html .= '<li>' . rk_builder_icon( 'map-pin' ) . '<span>' . rk_builder_h( $p['address'] ) . '</span></li>'; }
		$html .= '</ul></div>';
	}
	$html .= '</div>';
	$legal = rk_builder_parse_links( isset( $p['legal'] ) ? $p['legal'] : '', 6 );
	if ( '' !== $p['copyright'] || '' !== $p['note'] || $legal ) {
		$html .= '<div class="pf-foot-base">' . ( '' !== $p['copyright'] ? '<p>' . rk_builder_h( $p['copyright'] ) . '</p>' : '' );
		if ( $legal ) {
			$html .= '<ul class="pf-foot-legal">';
			foreach ( $legal as $l ) { $html .= '<li><a href="' . rk_builder_href( $l['href'] ) . '">' . rk_builder_h( $l['label'] ) . '</a></li>'; }
			$html .= '</ul>';
		}
		$html .= ( '' !== $p['note'] ? '<p>' . rk_builder_h( $p['note'] ) . '</p>' : '' ) . '</div>';
	}
	return $html . '</footer>';
}

/** Plain text -> paragraphs on blank lines (same rule as the text block). */
function rk_builder_paragraphs_html( $source ) {
	$html  = '';
	$parts = preg_split( '/\n{2,}/', (string) $source );
	foreach ( false === $parts ? array() : $parts as $para ) {
		if ( '' === rk_builder_trim_ws( $para ) ) { continue; }
		$html .= '<p class="pf-body">' . rk_builder_h( $para ) . '</p>';
	}
	return $html;
}

/** One fact per line, "Value|Label". Mirrors parseFacts(). */
function rk_builder_parse_facts( $source, $max = 6 ) {
	$out = array();
	foreach ( explode( "\n", (string) $source ) as $raw ) {
		$line = trim( $raw );
		$cut  = strpos( $line, '|' );
		if ( false === $cut || $cut < 1 ) { continue; }
		$value = trim( substr( $line, 0, $cut ) );
		$label = trim( substr( $line, $cut + 1 ) );
		if ( '' === $value || '' === $label ) { continue; }
		$out[] = array( 'value' => $value, 'label' => $label );
		if ( count( $out ) >= $max ) { break; }
	}
	return $out;
}

function rk_builder_render_section( array $p, array $context = array() ) {
	$html  = '<section ' . rk_builder_root_attrs( 'section', 'pf-section ' . $p['tone'] . ( ! empty( $p['center'] ) ? ' center' : '' ) ) . '><div class="pf-wrap"><div class="pf-section-head"><div>';
	if ( '' !== $p['eyebrow'] ) { $html .= '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
	if ( '' !== $p['heading'] ) { $html .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>'; }
	$html .= '</div>';
	if ( '' !== $p['linkLabel'] && '' !== $p['linkHref'] ) {
		$html .= '<a class="pf-more" href="' . rk_builder_href( $p['linkHref'] ) . '">' . rk_builder_h( $p['linkLabel'] ) . rk_builder_arrow_icon() . '</a>';
	}
	$html .= '</div>' . rk_builder_paragraphs_html( $p['body'] );
	if ( ! empty( $p['pill'] ) ) { $html .= '<p class="pf-pill">' . rk_builder_icon( 'map-pin', 14 ) . rk_builder_h( $p['pill'] ) . '</p>'; }
	return $html . '</div></section>';
}

function rk_builder_render_split( array $p, array $context = array() ) {
	$img   = rk_builder_media_url( $p, 'imageMediaId', 'imageUrl' );
	$facts = rk_builder_parse_facts( $p['facts'] );
	$html  = '<section ' . rk_builder_root_attrs( 'split', 'pf-section pf-split ' . $p['tone'] . ' ' . $p['side'] ) . '><div class="pf-wrap pf-split-grid">';
	if ( '' !== $img ) { $html .= '<img class="pf-split-img" src="' . $img . '" alt="' . rk_builder_h( $p['imageAlt'] ) . '" decoding="async" loading="lazy"/>'; }
	$html .= '<div class="pf-split-copy">';
	if ( '' !== $p['eyebrow'] ) { $html .= '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
	$html .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>' . rk_builder_paragraphs_html( $p['body'] );
	if ( $facts ) {
		$html .= '<dl class="pf-facts">';
		foreach ( $facts as $f ) { $html .= '<div><dt>' . rk_builder_h( $f['value'] ) . '</dt><dd>' . rk_builder_h( $f['label'] ) . '</dd></div>'; }
		$html .= '</dl>';
	}
	if ( '' !== $p['cta'] && '' !== $p['ctaHref'] ) {
		$html .= '<a class="pf-btn dark" href="' . rk_builder_href( $p['ctaHref'] ) . '">' . rk_builder_h( $p['cta'] ) . rk_builder_arrow_right_icon() . '</a>';
	}
	$links = rk_builder_parse_links( isset( $p['links'] ) ? $p['links'] : '', 4 );
	if ( $links ) {
		$html .= '<div class="pf-split-links">';
		foreach ( $links as $l ) { $html .= '<a class="pf-more" href="' . rk_builder_href( $l['href'] ) . '">' . rk_builder_h( $l['label'] ) . rk_builder_arrow_icon() . '</a>'; }
		$html .= '</div>';
	}
	return $html . '</div></div></section>';
}
