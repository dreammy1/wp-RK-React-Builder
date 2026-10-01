<?php
/**
 * Bundled assets. The build script (`pnpm build:plugin`) puts the editor and the public stylesheet
 * into the plugin's `assets/` folder:
 *
 *   assets/builder.js         the React editor (ES module)
 *   assets/builder.css        the editor stylesheet
 *   assets/builder.asset.php  returns array( 'dependencies' => array(), 'version' => '<hash>' )
 *   assets/site.css           public stylesheet used by the PHP renderer
 *
 * By default the editor is loaded from these files. The constants RK_BUILDER_APP_URL /
 * RK_BUILDER_APP_CSS_URL, the option `rk_builder_app_url` and the filters `rk_builder_app_url` /
 * `rk_builder_app_css_url` still override them (headless setups: the editor served elsewhere).
 * A source checkout has no `assets/` folder; that is reported, never fatal.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Absolute path (with trailing slash) of the bundled assets folder. Filter: `rk_builder_assets_dir`. */
function rk_builder_assets_dir() {
	return (string) apply_filters( 'rk_builder_assets_dir', RK_BUILDER_DIR . 'assets/' );
}

/** The bundled file names the plugin knows about. */
function rk_builder_asset_files() {
	return array( 'builder.js', 'builder.css', 'builder.asset.php', 'site.css' );
}

/** Whether one bundled file exists on disk. Only known file names are accepted. */
function rk_builder_asset_exists( $file ) {
	return in_array( $file, rk_builder_asset_files(), true ) && is_file( rk_builder_assets_dir() . $file );
}

/** Which bundled files exist: array( file => bool ). */
function rk_builder_assets_status() {
	$out = array();
	foreach ( rk_builder_asset_files() as $f ) { $out[ $f ] = rk_builder_asset_exists( $f ); }
	return $out;
}

/** True when the editor bundle (js + its asset manifest) is on disk. */
function rk_builder_editor_assets_present() {
	return rk_builder_asset_exists( 'builder.js' ) && rk_builder_asset_exists( 'builder.asset.php' );
}

/** Cache-busting version: the hash from builder.asset.php, else the plugin version. */
function rk_builder_asset_version() {
	$ver = '';
	if ( rk_builder_asset_exists( 'builder.asset.php' ) ) {
		$asset = include rk_builder_assets_dir() . 'builder.asset.php';
		if ( is_array( $asset ) && isset( $asset['version'] ) && ( is_string( $asset['version'] ) || is_int( $asset['version'] ) ) ) {
			$ver = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $asset['version'] );
		}
	}
	return '' !== $ver ? $ver : RK_BUILDER_VERSION;
}

/** Public URL of a bundled file (with ?ver=), or '' when it is not on disk. */
function rk_builder_asset_url( $file ) {
	if ( ! rk_builder_asset_exists( $file ) ) { return ''; }
	return RK_BUILDER_URL . 'assets/' . $file . '?ver=' . rawurlencode( rk_builder_asset_version() );
}

/** URL of the bundled editor module, '' when it is not built. */
function rk_builder_bundled_app_url() {
	return rk_builder_editor_assets_present() ? rk_builder_asset_url( 'builder.js' ) : '';
}

/**
 * URL of the editor bundle (module script). Order: constant, option, then the plugin's own
 * bundled file; the `rk_builder_app_url` filter has the last word. '' when nothing is available.
 * A configured but invalid value (e.g. javascript:) yields '' rather than silently falling back.
 */
function rk_builder_app_url() {
	$url = defined( 'RK_BUILDER_APP_URL' ) ? (string) RK_BUILDER_APP_URL : (string) get_option( 'rk_builder_app_url', '' );
	if ( '' === $url ) { $url = rk_builder_bundled_app_url(); }
	$url = (string) apply_filters( 'rk_builder_app_url', $url );
	return esc_url_raw( $url, array( 'http', 'https' ) );
}

/**
 * URL of the editor stylesheet: RK_BUILDER_APP_CSS_URL; else the bundled builder.css when the
 * bundled editor is in use; else the app URL with a .css extension. Filter: `rk_builder_app_css_url`.
 */
function rk_builder_app_css_url() {
	$app = rk_builder_app_url();
	if ( defined( 'RK_BUILDER_APP_CSS_URL' ) ) {
		$css = (string) RK_BUILDER_APP_CSS_URL;
	} elseif ( '' !== $app && $app === esc_url_raw( rk_builder_bundled_app_url(), array( 'http', 'https' ) ) ) {
		$css = rk_builder_asset_url( 'builder.css' );
	} else {
		$css = preg_replace( '/\.js(\?.*)?$/', '.css$1', $app );
	}
	return esc_url_raw( (string) apply_filters( 'rk_builder_app_css_url', $css ), array( 'http', 'https' ) );
}

/** Human message shown when the editor cannot be loaded because the plugin was not built. */
function rk_builder_missing_assets_message() {
	return 'The RK Builder editor files are missing (assets/builder.js). This looks like a source checkout: run "pnpm build:plugin" and install the generated plugin ZIP (Plugins > Add New > Upload Plugin). Headless setups can instead set RK_BUILDER_APP_URL to a separately hosted editor.';
}
