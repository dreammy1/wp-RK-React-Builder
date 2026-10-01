<?php
/**
 * Image block. Mirrors client/src/blocks/image/View.tsx. The attachment (mediaId) is authoritative for
 * url, size and srcset; stored props are only the fallback (unknown attachment, or no mediaId). Alt: the attachment's alt text when it has one.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolve what to render for validated image props.
 *
 * @return array{url:string,alt:string,width:?int,height:?int,srcset:string}
 */
function rk_builder_resolve_image( array $p ) {
	$out = array(
		'url'    => (string) $p['url'],
		'alt'    => ! empty( $p['decorative'] ) ? '' : (string) $p['alt'],
		'width'  => isset( $p['width'] ) ? (int) $p['width'] : null,
		'height' => isset( $p['height'] ) ? (int) $p['height'] : null,
		'srcset' => isset( $p['srcset'] ) && is_string( $p['srcset'] ) ? $p['srcset'] : '',
	);
	$id = isset( $p['mediaId'] ) ? (int) $p['mediaId'] : 0;
	if ( $id > 0 ) {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( is_array( $src ) && ! empty( $src[0] ) && is_string( $src[0] ) ) {
			$out['url'] = $src[0];
			if ( ! empty( $src[1] ) ) { $out['width'] = (int) $src[1]; }
			if ( ! empty( $src[2] ) ) { $out['height'] = (int) $src[2]; }
			$srcset = wp_get_attachment_image_srcset( $id, 'full' );
			$out['srcset'] = ( is_string( $srcset ) && '' !== $srcset ) ? $srcset : '';
			if ( empty( $p['decorative'] ) ) {
				// The media library's alt text is live; the stored alt is the fallback.
				$live = rk_builder_plain( get_post_meta( $id, '_wp_attachment_image_alt', true ) );
				if ( '' !== $live ) { $out['alt'] = $live; }
			}
		}
	}
	return $out;
}

function rk_builder_render_image( array $p, array $context = array() ) {
	$img = rk_builder_resolve_image( $p );
	$src = rk_builder_src( $img['url'] );
	if ( '' === $src ) { return ''; }
	$html  = '<section ' . rk_builder_root_attrs( 'image', 'site-image' ) . '>';
	$html .= '<img src="' . $src . '" alt="' . rk_builder_h( $img['alt'] ) . '"';
	if ( null !== $img['width'] ) { $html .= ' width="' . $img['width'] . '"'; }
	if ( null !== $img['height'] ) { $html .= ' height="' . $img['height'] . '"'; }
	if ( '' !== $img['srcset'] ) {
		// "srcSet" is the casing React emits; HTML attribute names are case-insensitive.
		$html .= ' srcSet="' . rk_builder_h( $img['srcset'] ) . '" sizes="(min-width: 1040px) 1040px, 100vw"';
	}
	return $html . ' loading="lazy" decoding="async"/></section>';
}
