<?php
/**
 * Public rendering integration (spec §11.1): serve a page's PUBLISHED RK snapshot from PHP.
 *
 * Mode `theme` (default): the main-query `the_content` of a published page is replaced by the rendered
 * layout, inside the active theme's normal page shell. Mode `standalone`: `template_include` swaps in
 * templates/public-layout.php (a complete minimal document using wp_head()/wp_footer()).
 * Drafts, private, password-protected, trashed and missing pages are never touched: WordPress's own
 * 404 / permission handling applies. The output needs no JavaScript.
 *
 * Fonts: the three approved fonts have system fallbacks in the stacks below. Google Fonts are NOT
 * loaded unless the `rk_builder_load_google_fonts` filter returns true (then allow
 * style-src https://fonts.googleapis.com and font-src https://fonts.gstatic.com in your CSP).
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_GOOGLE_FONTS_URL' ) ) {
	define( 'RK_BUILDER_GOOGLE_FONTS_URL', 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Space+Grotesk:wght@400;500;700&display=swap' );
}

/* ------------------------------------------------------------------ *
 * Theme -> CSS (validated tokens only)
 * ------------------------------------------------------------------ */

/** font name => CSS stack; mirrors FONT_STACKS in client/src/lib/schema/theme.ts. */
function rk_builder_font_stacks() {
	return array(
		'Space Grotesk' => "'Space Grotesk', system-ui, sans-serif",
		'IBM Plex Mono' => "'IBM Plex Mono', ui-monospace, monospace",
		'Georgia'       => "Georgia, 'Times New Roman', serif",
	);
}

/**
 * Custom properties for a theme. The theme is re-validated here; anything invalid falls back to the
 * defaults, and only hex colours and the three font stacks can ever reach the output.
 */
function rk_builder_theme_css( array $theme, $selector = '.rk-root' ) {
	if ( array() !== rk_builder_validate_theme( $theme, null ) ) { $theme = rk_builder_default_theme(); }
	$stacks = rk_builder_font_stacks();
	$font   = isset( $stacks[ $theme['font'] ] ) ? $stacks[ $theme['font'] ] : $stacks['Space Grotesk'];
	$vars   = array();
	foreach ( array( 'primary', 'bg', 'ink' ) as $k ) {
		$v = ( isset( $theme[ $k ] ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}\z/', (string) $theme[ $k ] ) ) ? $theme[ $k ] : rk_builder_default_theme()[ $k ];
		$vars[ '--site-' . $k ] = $v;
		$vars[ '--rk-' . $k ]   = $v;
	}
	$vars['--site-font'] = $font;
	$vars['--rk-font']   = $font;
	$decl = array();
	foreach ( $vars as $k => $v ) { $decl[] = $k . ':' . $v; }
	return $selector . '{' . implode( ';', $decl ) . '}';
}

/* ------------------------------------------------------------------ *
 * Which requests do we take over?
 * ------------------------------------------------------------------ */

/**
 * The published page + its published layout when $page_id may be served by RK, else null.
 * Requires: setting `enabled`, a real `page` that is `publish` and not password protected, and a valid
 * published snapshot.
 *
 * @return array{page:object,layout:array}|null
 */
function rk_builder_public_page( $page_id ) {
	if ( ! rk_builder_setting( 'enabled', true ) ) { return null; }
	$page_id = (int) $page_id;
	$page    = $page_id > 0 ? get_post( $page_id ) : null;
	if ( ! $page || ! is_object( $page ) || 'page' !== $page->post_type || 'publish' !== $page->post_status ) { return null; }
	if ( '' !== (string) $page->post_password ) { return null; }
	if ( '' === (string) get_post_meta( $page_id, '_rk_layout_published', true ) ) { return null; }
	$layout = rk_builder_get_published_layout( $page_id );
	if ( null === $layout ) {
		rk_builder_log( 'warning', 'published_snapshot_corrupt', array( 'page_id' => $page_id ) );
		rk_builder_record_render_error( $page_id, 'rk_snapshot_invalid' );
		return null;
	}
	return array( 'page' => $page, 'layout' => $layout );
}

/** The RK page for the current main query (singular page), or null. */
function rk_builder_current_request_page() {
	if ( ! function_exists( 'is_singular' ) || ( function_exists( 'is_admin' ) && is_admin() ) || ! is_singular( 'page' ) ) { return null; }
	return rk_builder_public_page( (int) get_queried_object_id() );
}

function rk_builder_rendering_mode() {
	return 'standalone' === rk_builder_setting( 'public_rendering_mode', 'theme' ) ? 'standalone' : 'theme';
}

/* ------------------------------------------------------------------ *
 * Markup
 * ------------------------------------------------------------------ */

/** `<div class="site-root rk-root">` + blocks (theme mode). */
function rk_builder_render_public_html( array $layout, array $context = array() ) {
	return '<div class="site-root rk-root">' . rk_builder_render_layout( $layout, $context ) . '</div>';
}

function rk_builder_theme_logo_url( array $theme ) {
	if ( ! empty( $theme['logoMediaId'] ) ) {
		$u = wp_get_attachment_image_url( (int) $theme['logoMediaId'], 'full' );
		if ( is_string( $u ) && '' !== $u ) { return $u; }
	}
	return isset( $theme['logoUrl'] ) && is_string( $theme['logoUrl'] ) ? $theme['logoUrl'] : '';
}

/** Site header (brand) — same markup as the Node server's shell. */
function rk_builder_site_header_html( array $theme ) {
	$name = (string) get_bloginfo( 'name' );
	$logo = rk_builder_src( rk_builder_theme_logo_url( $theme ) );
	$brand = '' !== $logo
		? '<img class="site-logo" src="' . $logo . '" alt="' . rk_builder_h( $name ) . '" height="32">'
		: '<span class="site-wordmark">' . rk_builder_h( $name ) . '</span>';
	$sticky = ! empty( $theme['header']['sticky'] ) ? ' sticky' : '';
	return '<header class="site-header' . $sticky . '"><a href="' . rk_builder_h( home_url( '/' ) ) . '" class="site-brand">' . $brand . '</a></header>';
}

/** Site footer with the social links from the theme (https only). */
function rk_builder_site_footer_html( array $theme ) {
	$links = array();
	foreach ( array( 'instagram', 'linkedin' ) as $k ) {
		$u = isset( $theme['social'][ $k ] ) ? $theme['social'][ $k ] : '';
		if ( is_string( $u ) && '' !== $u && 0 === strpos( $u, 'https://' ) && null === rk_builder_check_social_url( $u ) ) {
			$links[] = '<a href="' . rk_builder_h( $u ) . '" rel="noopener noreferrer">' . rk_builder_h( $k ) . '</a>';
		}
	}
	return '<footer class="site-footer"><span>' . rk_builder_h( (string) get_bloginfo( 'name' ) ) . '</span><span class="site-social">' . implode( ' ', $links ) . '</span></footer>';
}

/** URL of the public stylesheet (copied from client/src/styles/site.css at build time). */
function rk_builder_site_css_url() {
	return RK_BUILDER_URL . 'assets/site.css';
}

/* ------------------------------------------------------------------ *
 * Hooks
 * ------------------------------------------------------------------ */

/**
 * The page's small behaviours, one inline script: estimate calculator, active nav link + mobile menu, filter
 * buttons (stains, gallery) and the product pop-ups. Everything degrades to the plain markup without it.
 */
function rk_builder_site_script() {
	$js = <<<'JS'
(function(){
var d=document;
d.querySelectorAll(".pf-calc").forEach(function(r){var s=r.querySelector("[data-calc-type]"),a=r.querySelector("[data-calc-amount]"),u=r.querySelector("[data-calc-unit]"),t=r.querySelector("[data-calc-total]");if(!s||!a||!t)return;function go(){var o=s.options[s.selectedIndex];if(!o)return;if(u)u.textContent=o.getAttribute("data-unit")||"";var n=Number(a.value)||0;t.textContent="$"+Math.round(Number(o.getAttribute("data-rate"))*n).toLocaleString("en-US",{maximumFractionDigits:0})}s.addEventListener("change",go);a.addEventListener("input",go)});
d.querySelectorAll(".pf-nav").forEach(function(h){var b=h.querySelector("[data-nav-toggle]"),p=location.pathname.replace(/\/+$/,"")||"/";h.querySelectorAll("nav a,.pf-nav-panel a").forEach(function(a){var u;try{u=new URL(a.href,location.href)}catch(e){return}if(u.origin!==location.origin)return;var q=u.pathname.replace(/\/+$/,"")||"/";if(q!=="/"&&(p===q||p.indexOf(q+"/")===0))a.setAttribute("aria-current","page")});if(b)b.addEventListener("click",function(){var o=h.classList.toggle("open");b.setAttribute("aria-expanded",o?"true":"false");b.setAttribute("aria-label",o?"Close menu":"Open menu")})});
d.querySelectorAll(".pf-filters").forEach(function(f){var sc=f.closest(".pf-catalog,.pf-gallery");if(!sc)return;f.addEventListener("click",function(e){var b=e.target.closest("[data-filter]");if(!b)return;var t=b.getAttribute("data-filter"),first=true;f.querySelectorAll("[data-filter]").forEach(function(x){x.classList.toggle("on",x===b)});sc.querySelectorAll("[data-tag]").forEach(function(c){var show=t==="All"||c.getAttribute("data-tag")===t;c.hidden=!show;if(c.tagName==="FIGURE")c.classList.toggle("big",show&&first);if(show)first=false})})});
d.addEventListener("click",function(e){var o=e.target.closest("[data-modal-open]");if(o){var s=o.closest(".pf-catalog"),m=s&&s.querySelector('dialog[data-modal="'+o.getAttribute("data-modal-open")+'"]');if(m&&m.showModal)m.showModal();return}var c=e.target.closest("[data-modal-close]");if(c){var m2=c.closest("dialog");if(m2)m2.close();return}if(e.target.tagName==="DIALOG"&&e.target.classList.contains("pf-modal"))e.target.close()});
})();
JS;
	return trim( $js );
}

function rk_builder_enqueue_public_assets( array $theme ) {
	wp_enqueue_style( 'rk-builder-site', rk_builder_site_css_url(), array(), RK_BUILDER_VERSION );
	// Tiny behaviours (calculator, menu, filters, pop-ups); no file, so nothing extra to build or ship.
	wp_register_script( 'rk-builder-site', false, array(), RK_BUILDER_VERSION, true );
	wp_enqueue_script( 'rk-builder-site' );
	wp_add_inline_script( 'rk-builder-site', rk_builder_site_script() );
	wp_add_inline_style( 'rk-builder-site', rk_builder_theme_css( $theme ) );
	if ( apply_filters( 'rk_builder_load_google_fonts', false ) ) {
		wp_enqueue_style( 'rk-builder-fonts', RK_BUILDER_GOOGLE_FONTS_URL, array(), null );
	}
}

function rk_builder_on_enqueue_scripts() {
	if ( null === rk_builder_current_request_page() ) { return; }
	rk_builder_enqueue_public_assets( rk_builder_get_theme() );
	$rk = rk_builder_current_request_page();
	if ( is_array( $rk ) && isset( $rk['layout'] ) && is_array( $rk['layout'] ) ) { rk_builder_viz_enqueue( $rk['layout'] ); }
}

/** Tracks "already replaced this request" so the layout is rendered once. Pass true to set, 'reset' to clear. */
function rk_builder_content_rendered( $set = null ) {
	static $done = false;
	if ( true === $set ) { $done = true; }
	if ( 'reset' === $set ) { $done = false; }
	return $done;
}

function rk_builder_filter_the_content( $content ) {
	if ( 'theme' !== rk_builder_rendering_mode() || rk_builder_content_rendered() ) { return $content; }
	if ( ! function_exists( 'is_main_query' ) || ! is_main_query() || ! function_exists( 'in_the_loop' ) || ! in_the_loop() ) { return $content; }
	if ( function_exists( 'doing_filter' ) && ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_head' ) ) ) { return $content; }
	$rk = rk_builder_current_request_page();
	if ( null === $rk || (int) get_the_ID() !== (int) $rk['page']->ID ) { return $content; }
	rk_builder_content_rendered( true );
	return rk_builder_render_public_html( $rk['layout'], array( 'page_id' => (int) $rk['page']->ID, 'preview' => false ) );
}

function rk_builder_filter_template_include( $template ) {
	if ( 'standalone' !== rk_builder_rendering_mode() || null === rk_builder_current_request_page() ) { return $template; }
	if ( function_exists( 'add_theme_support' ) ) { add_theme_support( 'title-tag' ); } // so wp_head() prints <title>
	return RK_BUILDER_DIR . 'templates/public-layout.php';
}

/**
 * Old nested URLs (/services/<slug>) 404 once pages are flat. The slug a request ends in, when the request has a
 * parent segment and nothing else is allowed to claim it; '' otherwise.
 */
function rk_builder_legacy_slug( $path ) {
	$parts = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ), 'strlen' ) );
	if ( count( $parts ) < 2 || count( $parts ) > 3 ) { return ''; }
	$last = $parts[ count( $parts ) - 1 ];
	return 1 === preg_match( '/^[a-z0-9-]{1,100}\z/', $last ) ? $last : '';
}

/** 404 on a nested URL whose last segment is a published page: send the visitor there permanently. */
function rk_builder_maybe_redirect_legacy() {
	if ( ! function_exists( 'is_404' ) || ! is_404() || ! function_exists( 'get_page_by_path' ) ) { return; }
	$req  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $req, PHP_URL_PATH );
	$slug = rk_builder_legacy_slug( $path );
	if ( '' === $slug ) { return; }
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! $page || 'publish' !== $page->post_status || ! empty( $page->post_password ) ) { return; }
	wp_safe_redirect( get_permalink( $page ), 301 );
	exit;
}

function rk_builder_register_public_hooks() {
	add_action( 'template_redirect', 'rk_builder_maybe_redirect_legacy' );
	add_action( 'wp_enqueue_scripts', 'rk_builder_on_enqueue_scripts' );
	// Last, so no other the_content filter (wpautop, shortcodes, ...) can touch or execute anything in the layout.
	add_filter( 'the_content', 'rk_builder_filter_the_content', PHP_INT_MAX );
	add_filter( 'template_include', 'rk_builder_filter_template_include', 99 );
}
rk_builder_register_public_hooks();
