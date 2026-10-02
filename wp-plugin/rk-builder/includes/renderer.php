<?php
/**
 * PHP public renderer: layout JSON -> HTML, no Node needed.
 *
 * The markup is the same as what the React views produce in `mode="public"`
 * (client/src/blocks/<type>/View.tsx via renderToStaticMarkup) so one stylesheet (assets/site.css)
 * serves both, plus the stable hooks `rk-block rk-block-<type>` classes and `data-rk-block="<type>"`
 * on each block's root element. Whitespace between tags is never emitted.
 *
 * Safety rules: only the 9 allow-listed block types render; every block's props are re-validated
 * with the same validator that guards saves (invalid stored data renders nothing for that block);
 * every value is escaped; text is plain text; there is no way for props to inject HTML or CSS.
 *
 * Also contains the logger (rk_builder_log) used across the plugin.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/render/hero.php';
require_once __DIR__ . '/render/pf.php';
require_once __DIR__ . '/render/pf2.php';
require_once __DIR__ . '/render/viz.php';
require_once __DIR__ . '/render/heading.php';
require_once __DIR__ . '/render/text.php';
require_once __DIR__ . '/render/image.php';
require_once __DIR__ . '/render/cta.php';
require_once __DIR__ . '/render/grid.php';
require_once __DIR__ . '/render/services.php';
require_once __DIR__ . '/render/portfolio.php';
require_once __DIR__ . '/render/spacer.php';
require_once __DIR__ . '/render/divider.php';
require_once __DIR__ . '/render/testimonial.php';
require_once __DIR__ . '/render/contact.php';
require_once __DIR__ . '/reusable.php';

/* ------------------------------------------------------------------ *
 * Logging (error_log only; never secrets, tokens or layout bodies)
 * ------------------------------------------------------------------ */

function rk_builder_log_levels() {
	return array( 'debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400 );
}

/** Recursively redact sensitive keys and omit content bodies. Strings are shortened. */
function rk_builder_log_redact( $value, $depth = 0 ) {
	if ( $depth > 4 ) { return '[…]'; }
	if ( is_object( $value ) ) { return '[object ' . get_class( $value ) . ']'; }
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && 1 === preg_match( '/pass|token|secret|nonce|authorization|cookie/i', $k ) ) {
				$out[ $k ] = '[redacted]';
			} elseif ( is_string( $k ) && 1 === preg_match( '/^(layout|layouts|blocks|props|body|content|html)$/i', $k ) ) {
				$out[ $k ] = '[omitted]';
			} else {
				$out[ $k ] = rk_builder_log_redact( $v, $depth + 1 );
			}
		}
		return $out;
	}
	if ( is_string( $value ) ) {
		$value = preg_replace( '/(pass\w*|token|secret|nonce|authorization|cookie)(\s*[=:]\s*)[^&\s,;"\']+/i', '$1$2[redacted]', $value );
		return strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '…' : $value;
	}
	return $value;
}

/**
 * @param string $level debug|info|warning|error. Only levels >= filter `rk_builder_log_level` (default "warning") are written.
 * @param string $event short machine name, e.g. "render_failed"
 * @param array  $ctx   small scalar context; sensitive keys are redacted
 */
function rk_builder_log( $level, $event, array $ctx = array() ) {
	$levels = rk_builder_log_levels();
	$level  = isset( $levels[ $level ] ) ? $level : 'error';
	$min    = apply_filters( 'rk_builder_log_level', 'warning' );
	$min    = ( is_string( $min ) && isset( $levels[ $min ] ) ) ? $min : 'warning';
	if ( $levels[ $level ] < $levels[ $min ] ) { return false; }
	$json = wp_json_encode( rk_builder_log_redact( $ctx ) );
	error_log( 'rk-builder ' . $level . ' ' . preg_replace( '/[^a-z0-9_.-]/i', '_', (string) $event ) . ' ' . ( false === $json ? '{}' : $json ) );
	return true;
}

/** Remember the last public render problem (time, page, code only) for the diagnostics screen. Throttled to avoid write storms. */
function rk_builder_record_render_error( $page_id, $code ) {
	$prev = get_option( 'rk_builder_last_render_error', null );
	$now  = function_exists( 'rk_builder_now' ) ? rk_builder_now() : time();
	if ( is_array( $prev ) && isset( $prev['page_id'], $prev['code'], $prev['time'] ) && (int) $prev['page_id'] === (int) $page_id && $prev['code'] === $code ) {
		$t = strtotime( (string) $prev['time'] );
		if ( false !== $t && $now - $t < 300 ) { return; }
	}
	update_option( 'rk_builder_last_render_error', array( 'time' => rk_builder_iso( $now ), 'page_id' => (int) $page_id, 'code' => (string) $code ), false );
}

/* ------------------------------------------------------------------ *
 * Escaping and shared markup pieces
 * ------------------------------------------------------------------ */

/**
 * HTML-escape text or an attribute value exactly like React's server renderer does (& < > " ').
 * (esc_html() deliberately does not double-encode existing entities, which would change what a
 * visitor sees for user-typed text such as "&amp;"; this does, and is safe for text and attributes.)
 */
function rk_builder_h( $s ) {
	return str_replace( '&#039;', '&#x27;', htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
}

/** A validated link as an escaped href value: empty/unsafe -> "#" (same as the React views). */
function rk_builder_href( $link ) {
	$link = is_string( $link ) ? $link : '';
	if ( '' === $link || ! rk_builder_is_safe_link( $link ) ) { return '#'; }
	$url = function_exists( 'esc_url_raw' ) ? esc_url_raw( $link, array( 'http', 'https', 'mailto', 'tel' ) ) : $link;
	return '' === $url ? '#' : rk_builder_h( $url );
}

/** An escaped image/src value restricted to http(s) or a site-relative path; '' when unusable. */
function rk_builder_src( $url ) {
	$url = is_string( $url ) ? $url : '';
	if ( '' === $url || ! rk_builder_is_safe_image_url( $url, null ) ) { return ''; }
	$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : $url;
	return '' === $clean ? '' : rk_builder_h( $clean );
}

/** The exact inline SVG React emits for lucide's ArrowUpRight (size 15, aria-hidden). */
function rk_builder_arrow_icon() {
	return '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-up-right" aria-hidden="true"><path d="M7 7h10v10"></path><path d="M7 17 17 7"></path></svg>';
}

/** `class="<base> rk-block rk-block-<type>" data-rk-block="<type>"` (class first, data attribute second). */
function rk_builder_root_attrs( $type, $base_class ) {
	return 'class="' . $base_class . ' rk-block rk-block-' . $type . '" data-rk-block="' . $type . '"';
}

/* ------------------------------------------------------------------ *
 * Dispatch
 * ------------------------------------------------------------------ */

/** Allow-list: block type => renderer. Deliberately not filterable. */
function rk_builder_block_renderers() {
	return array(
		'hero'      => 'rk_builder_render_hero',
		'heading'   => 'rk_builder_render_heading',
		'text'      => 'rk_builder_render_text',
		'image'     => 'rk_builder_render_image',
		'cta'       => 'rk_builder_render_cta',
		'services'  => 'rk_builder_render_services',
		'portfolio' => 'rk_builder_render_portfolio',
		'spacer'    => 'rk_builder_render_spacer',
		'divider'   => 'rk_builder_render_divider',
		'testimonial' => 'rk_builder_render_testimonial',
		'contact'   => 'rk_builder_render_contact',
		'navbar'    => 'rk_builder_render_navbar',
		'coverhero' => 'rk_builder_render_coverhero',
		'sitefooter' => 'rk_builder_render_sitefooter',
		'section'   => 'rk_builder_render_section',
		'split'     => 'rk_builder_render_split',
		'contactband' => 'rk_builder_render_contactband',
		'panel' => 'rk_builder_render_panel',
		'values' => 'rk_builder_render_values',
		'catalog' => 'rk_builder_render_catalog',
		'detail' => 'rk_builder_render_detail',
		'gallery' => 'rk_builder_render_gallery',
		'calculator' => 'rk_builder_render_calculator',
		'brandstrip' => 'rk_builder_render_brandstrip',
		'visualizer' => 'rk_builder_render_visualizer',
		'reusable'  => 'rk_builder_render_reusable',
	);
}

/** Validated, canonical props for a block, or null when the type is unknown or the props are invalid. */
function rk_builder_block_props( array $block, array $context = array() ) {
	$renderers = rk_builder_block_renderers();
	$type      = isset( $block['type'] ) ? $block['type'] : null;
	if ( ! is_string( $type ) || ! isset( $renderers[ $type ] ) || ! isset( $block['props'] ) || ! is_array( $block['props'] ) ) { return null; }
	$hosts = isset( $context['image_hosts'] ) ? $context['image_hosts'] : rk_builder_allowed_image_hosts();
	$specs = rk_builder_block_specs();
	$issues = array();
	rk_builder_validate_fields( $block['props'], $specs[ $type ], 'props', $issues, $hosts );
	if ( ! $issues && 'image' === $type ) {
		$p = $block['props'];
		if ( ! $p['decorative'] && '' === rk_builder_trim_ws( $p['alt'] ) ) { $issues[] = array( 'path' => 'props.alt', 'message' => 'alt required' ); }
	}
	if ( $issues ) { return null; }
	$canon = rk_builder_canonicalize_layout( array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => array( array( 'id' => 'b', 'type' => $type, 'props' => $block['props'] ) ) ) );
	return $canon['blocks'][0]['props'];
}

/**
 * Render one block to an HTML string. Unknown types, invalid props and internal failures all yield ''.
 *
 * @param array $block   {id,type,props}
 * @param array $context page_id (int), preview (bool), image_hosts (optional, computed otherwise)
 */
function rk_builder_render_block( array $block, array $context = array() ) {
	$type = isset( $block['type'] ) && is_string( $block['type'] ) ? $block['type'] : '';
	try {
		$renderers = rk_builder_block_renderers();
		if ( ! isset( $renderers[ $type ] ) ) { return ''; }
		$props = rk_builder_block_props( $block, $context );
		if ( null === $props ) {
			rk_builder_log( 'info', 'render_block_invalid', array( 'type' => $type ) );
			return '';
		}
		$html = call_user_func( $renderers[ $type ], $props, $context );
		return is_string( $html ) ? $html : '';
	} catch ( Throwable $e ) {
		rk_builder_log( 'error', 'render_block_failed', array( 'type' => $type, 'error' => get_class( $e ), 'message' => $e->getMessage() ) );
		rk_builder_record_render_error( isset( $context['page_id'] ) ? (int) $context['page_id'] : 0, 'rk_render_exception' );
		return '';
	}
}

/** Render every block of a layout in order. Anything malformed is skipped, never fatal. */
function rk_builder_render_layout( array $layout, array $context = array() ) {
	$blocks = isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ? array_values( $layout['blocks'] ) : array();
	if ( count( $blocks ) > RK_BUILDER_MAX_BLOCKS ) { $blocks = array_slice( $blocks, 0, RK_BUILDER_MAX_BLOCKS ); }
	if ( ! isset( $context['image_hosts'] ) ) { $context['image_hosts'] = rk_builder_allowed_image_hosts(); }
	$out = '';
	foreach ( $blocks as $block ) {
		if ( is_array( $block ) ) { $out .= rk_builder_render_block( $block, $context ); }
	}
	return $out;
}
