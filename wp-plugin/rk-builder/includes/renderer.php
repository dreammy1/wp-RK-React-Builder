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
require_once __DIR__ . '/render/login.php';
require_once __DIR__ . '/render/dynamic.php';
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

/**
 * `class="<base> rk-block rk-block-<type>" data-rk-block="<type>"` (class first, data attribute second).
 * Kept as the single place block roots build their attributes; advanced styles use a wrapper instead
 * (see rk_builder_render_block) so the markup matches the React views exactly.
 */
function rk_builder_root_attrs( $type, $base_class ) {
	return 'class="' . $base_class . ' rk-block rk-block-' . $type . '" data-rk-block="' . $type . '"';
}

/* ------------------------------------------------------------------ *
 * Per-block advanced styles (mirrors client/src/lib/schema/style.ts)
 * ------------------------------------------------------------------ */

/** The class a block root carries when it has an `advanced` object; empty string otherwise. */
function rk_builder_style_class( $block ) {
	if ( ! is_array( $block ) || empty( $block['advanced'] ) || ! is_array( $block['advanced'] ) ) { return ''; }
	$id = isset( $block['id'] ) && is_string( $block['id'] ) ? $block['id'] : '';
	return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}\z/', $id ) ? 'rk-style-' . $id : '';
}

/** Breakpoint min-widths, mirroring BREAKPOINT_MIN_WIDTH in style.ts. */
function rk_builder_style_breakpoints() {
	return array( 'tablet' => 981, 'phone' => 641 );
}

/** $length is a validated `Npx`; returns the value or '' when absent/invalid. */
function rk_builder_style_len( $v ) {
	return is_string( $v ) && 1 === preg_match( '/^(0|[1-9][0-9]{0,3})px\z/', $v ) ? $v : '';
}

/** A validated colour (#RRGGBB or transparent) or '' . */
function rk_builder_style_color( $v ) {
	return is_string( $v ) && 1 === preg_match( '/^(#[0-9a-fA-F]{6}|transparent)\z/', $v ) ? $v : '';
}

/**
 * CSS declarations for one breakpoint's worth of advanced style. Mirrors declarations() in style.ts,
 * including the fixed option tables, so both renderers emit identical rules.
 *
 * @return string[]
 */
function rk_builder_style_declarations( $style ) {
	$out = array();
	if ( ! is_array( $style ) ) { return $out; }

	$spacing = isset( $style['spacing'] ) && is_array( $style['spacing'] ) ? $style['spacing'] : array();
	foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
		$v = isset( $spacing[ $side ] ) ? rk_builder_style_len( $spacing[ $side ] ) : '';
		if ( '' !== $v ) { $out[] = 'padding-' . $side . ':' . $v; }
	}

	$size = isset( $style['size'] ) && is_array( $style['size'] ) ? $style['size'] : array();
	$widths = array( 'full' => 'none', 'wide' => '1320px', 'boxed' => 'var(--site-container, 1144px)', 'narrow' => '760px' );
	if ( isset( $size['width'] ) && is_string( $size['width'] ) && isset( $widths[ $size['width'] ] ) ) {
		$out[] = 'max-width:' . $widths[ $size['width'] ];
		if ( 'full' !== $size['width'] ) { $out[] = 'margin-inline:auto'; }
	}
	if ( isset( $size['minHeight'] ) && '' !== ( $mh = rk_builder_style_len( $size['minHeight'] ) ) ) { $out[] = 'min-height:' . $mh; }
	if ( isset( $size['colSpan'] ) && rk_builder_is_intlike( $size['colSpan'] ) && $size['colSpan'] >= 1 && $size['colSpan'] <= 4 ) { $out[] = 'grid-column:span ' . (int) $size['colSpan']; }

	$bg = isset( $style['background'] ) && is_array( $style['background'] ) ? $style['background'] : array();
	// `!important` because every block root paints its own background/colour; without it the chosen
	// value would be hidden behind the block's default and appear to do nothing.
	if ( isset( $bg['color'] ) && '' !== ( $c = rk_builder_style_color( $bg['color'] ) ) ) { $out[] = 'background-color:' . $c . '!important'; }
	$gradients = array(
		'fade'     => 'linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,.45) 100%)',
		'diagonal' => 'linear-gradient(135deg, rgba(0,0,0,.35) 0%, rgba(0,0,0,0) 70%)',
		'radial'   => 'radial-gradient(120% 120% at 50% 0%, rgba(0,0,0,.35) 0%, rgba(0,0,0,0) 60%)',
	);
	if ( isset( $bg['gradient'], $gradients[ $bg['gradient'] ] ) ) { $out[] = 'background-image:' . $gradients[ $bg['gradient'] ] . '!important'; }
	if ( isset( $bg['imageUrl'] ) && is_string( $bg['imageUrl'] ) && null === rk_builder_image_url_problem( $bg['imageUrl'], null ) ) {
		$out[] = 'background-image:url("' . $bg['imageUrl'] . '")!important';
		$fits = array( 'cover', 'contain', 'fill' );
		$pos  = array( 'center', 'top', 'bottom', 'left', 'right' );
		$out[] = 'background-size:' . ( isset( $bg['imageFit'] ) && in_array( $bg['imageFit'], $fits, true ) ? $bg['imageFit'] : 'cover' ) . '!important';
		$out[] = 'background-position:' . ( isset( $bg['imagePosition'] ) && in_array( $bg['imagePosition'], $pos, true ) ? $bg['imagePosition'] : 'center' ) . '!important';
		$out[] = 'background-repeat:no-repeat!important';
	}

	$border = isset( $style['border'] ) && is_array( $style['border'] ) ? $style['border'] : array();
	$bw     = isset( $border['width'] ) ? rk_builder_style_len( $border['width'] ) : '';
	if ( '' !== $bw && '0px' !== $bw ) {
		$styles = array( 'solid', 'dashed', 'dotted' );
		$bs = isset( $border['style'] ) && in_array( $border['style'], $styles, true ) ? $border['style'] : 'solid';
		$bc = isset( $border['color'] ) ? rk_builder_style_color( $border['color'] ) : '';
		$out[] = 'border:' . $bw . ' ' . $bs . ' ' . ( '' !== $bc ? $bc : 'currentColor' ) . '!important';
	}
	if ( isset( $border['radius'] ) && '' !== ( $br = rk_builder_style_len( $border['radius'] ) ) ) {
		$out[] = 'border-radius:' . $br . '!important';
	}

	$shadows = array( 'sm' => '0 1px 2px rgba(0,0,0,.08)', 'md' => '0 6px 18px rgba(0,0,0,.10)', 'lg' => '0 18px 50px rgba(0,0,0,.16)', 'glow' => '0 0 0 4px rgba(199,243,107,.35)' );
	if ( isset( $style['shadow']['preset'], $shadows[ $style['shadow']['preset'] ] ) ) { $out[] = 'box-shadow:' . $shadows[ $style['shadow']['preset'] ] . '!important'; }

	$typo = isset( $style['typography'] ) && is_array( $style['typography'] ) ? $style['typography'] : array();
	if ( isset( $typo['size'] ) && '' !== ( $ts = rk_builder_style_len( $typo['size'] ) ) ) { $out[] = '--rk-block-size:' . $ts; }
	if ( isset( $typo['weight'] ) && rk_builder_is_intlike( $typo['weight'] ) && $typo['weight'] >= 300 && $typo['weight'] <= 900 ) { $out[] = '--rk-block-weight:' . (int) $typo['weight']; }
	if ( isset( $typo['align'] ) && in_array( $typo['align'], array( 'left', 'center', 'right' ), true ) ) { $out[] = 'text-align:' . $typo['align'] . '!important'; }
	if ( isset( $typo['color'] ) && '' !== ( $tc = rk_builder_style_color( $typo['color'] ) ) ) { $out[] = 'color:' . $tc . '!important'; }

	return $out;
}

/** `selector{decls}` or '' when there is nothing to say. */
function rk_builder_style_rule( $selector, array $decls ) {
	return $decls ? $selector . '{' . implode( ';', $decls ) . '}' : '';
}

/**
 * The advanced-style CSS for one block.
 *
 * The `rk-style-<id>` class sits on a wrapper around the block, but each block View paints its own
 * background and text colour on its own root element, so the declarations are applied to the wrapper
 * and to the block root inside it. The editor wraps the block in a transparent `.canvas-view` shim,
 * hence the third selector. Mirrors selectorsFor() in client/src/lib/schema/style.ts.
 * Returns '' for a block without styles, so unstyled documents emit exactly what they did before.
 */
function rk_builder_block_style_css( $block, $scope = '.site-root' ) {
	$class = rk_builder_style_class( $block );
	if ( '' === $class || ! is_array( $block['advanced'] ) ) { return ''; }
	$style  = $block['advanced'];
	$wrap   = $scope . ' .' . $class;
	$base   = $wrap . ',' . $wrap . ' > *' . ',' . $wrap . ' > .canvas-view > *';
	$out    = array();

	$vis = isset( $style['visibility'] ) && is_array( $style['visibility'] ) ? $style['visibility'] : array();
	$bps = rk_builder_style_breakpoints();
	if ( ! empty( $vis['hideDesktop'] ) ) { $out[] = $base . '{display:none!important}'; }
	if ( ! empty( $vis['hideTablet'] ) ) { $out[] = '@media (min-width:' . $bps['tablet'] . 'px){' . $base . '{display:none!important}}'; }
	if ( ! empty( $vis['hidePhone'] ) ) { $out[] = '@media (min-width:' . $bps['phone'] . 'px){' . $base . '{display:none!important}}'; }

	$out[] = rk_builder_style_rule( $base, rk_builder_style_declarations( $style ) );

	$typo = isset( $style['typography'] ) && is_array( $style['typography'] ) ? $style['typography'] : array();
	if ( ! empty( $typo['size'] ) || ! empty( $typo['weight'] ) ) {
		$parts = array();
		if ( ! empty( $typo['size'] ) ) { $parts[] = 'font-size:var(--rk-block-size)!important'; }
		if ( ! empty( $typo['weight'] ) ) { $parts[] = 'font-weight:var(--rk-block-weight)!important'; }
		$out[] = rk_builder_style_rule( $wrap . ' :is(h1,h2,h3,h4,.pf-kicker)', $parts );
	}

	if ( isset( $style['overrides'] ) && is_array( $style['overrides'] ) ) {
		foreach ( $bps as $bp => $min ) {
			if ( ! isset( $style['overrides'][ $bp ] ) ) { continue; }
			$decls = rk_builder_style_declarations( $style['overrides'][ $bp ] );
			if ( $decls ) { $out[] = '@media (min-width:' . $min . 'px){' . rk_builder_style_rule( $base, $decls ) . '}'; }
		}
	}

	return implode( '', array_filter( $out ) );
}

/** All advanced-style CSS for a layout ('' when nothing is styled). */
function rk_builder_layout_style_css( array $layout, $scope = '.site-root' ) {
	$blocks = isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ? $layout['blocks'] : array();
	$out    = '';
	foreach ( $blocks as $block ) {
		if ( is_array( $block ) ) { $out .= rk_builder_block_style_css( $block, $scope ); }
	}
	return $out;
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
		'login'     => 'rk_builder_render_login',
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
		'reviews'    => 'rk_builder_render_reviews',
		'visualizer' => 'rk_builder_render_visualizer',
		'dynfield'    => 'rk_builder_render_dynfield',
		'dynimage'    => 'rk_builder_render_dynimage',
		'dyngallery'  => 'rk_builder_render_dyngallery',
		'dynrepeater' => 'rk_builder_render_dynrepeater',
		'dyninfo'     => 'rk_builder_render_dyninfo',
		'loopgrid'    => 'rk_builder_render_loopgrid',
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
		// Advanced styles ride along with the block: a wrapper carrying the block's style class (the
		// same wrapper the React LayoutRenderer emits, so both match). The CSS itself is emitted once
		// for the whole layout by rk_builder_render_layout().
		$style_class = rk_builder_style_class( $block );
		$html = call_user_func( $renderers[ $type ], $props, $context );
		$html = is_string( $html ) ? $html : '';
		if ( '' !== $style_class ) {
			$html = '<div class="' . $style_class . '">' . $html . '</div>';
		}
		return $html;
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
	// Advanced-style CSS is emitted once for the whole layout, before the markup, exactly where the
	// React LayoutRenderer puts its single <style>.
	$css = rk_builder_layout_style_css( $layout );
	return ( '' !== $css ? '<style>' . $css . '</style>' : '' ) . $out;
}
