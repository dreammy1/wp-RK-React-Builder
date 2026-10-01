<?php
/**
 * Plugin settings (option `rk_builder_settings`). This file defines the data contract only; the
 * Settings → RK Builder screen is built on top of it in includes/setup.php.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Defaults for every setting. Add keys here first; everything else reads through rk_builder_setting(). */
function rk_builder_setting_defaults() {
	return array(
		'enabled'               => true,        // bool: builder + public rendering on/off
		'public_rendering_mode' => 'theme',     // 'theme' = inside the active theme's page content; 'standalone' = plugin template
		'allowed_image_hosts'   => '',          // string: comma/newline separated extra hosts for absolute image URLs
		'preview_ttl'           => 900,         // int seconds, 60..86400
		'max_revisions'         => 20,          // int, 1..200
		'default_grid_limit'    => 6,           // int, 1..24
		'enable_service_cpt'    => true,        // bool
		'enable_portfolio_cpt'  => true,        // bool
		'cache_purge'           => true,        // bool: call known cache plugins' purge APIs
		'allowed_origins'       => '',          // string: only if direct cross-origin browser access is wanted
		'delete_data_on_uninstall' => false,    // bool (mirrors RK_BUILDER_DELETE_DATA_ON_UNINSTALL; constant wins)
	);
}

/** All settings merged over defaults (unknown stored keys are ignored). */
function rk_builder_get_settings() {
	$stored = get_option( 'rk_builder_settings', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$defaults = rk_builder_setting_defaults();
	return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
}

/** One setting. Falls back to $fallback (or the default) when the key is unknown. */
function rk_builder_setting( $key, $fallback = null ) {
	$all = rk_builder_get_settings();
	return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
}

/**
 * Validate and normalise a settings array coming from the settings form. Unknown keys are dropped,
 * values are clamped/typed, and bad values fall back to the default (the form shows what was kept).
 */
function rk_builder_sanitize_settings( $input ) {
	$d   = rk_builder_setting_defaults();
	$in  = is_array( $input ) ? $input : array();
	$out = $d;
	foreach ( array( 'enabled', 'enable_service_cpt', 'enable_portfolio_cpt', 'cache_purge', 'delete_data_on_uninstall' ) as $k ) {
		$out[ $k ] = ! empty( $in[ $k ] );
	}
	$out['public_rendering_mode'] = ( isset( $in['public_rendering_mode'] ) && 'standalone' === $in['public_rendering_mode'] ) ? 'standalone' : 'theme';
	$int = function ( $k, $min, $max ) use ( $in, $d ) {
		return isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? max( $min, min( $max, (int) $in[ $k ] ) ) : $d[ $k ];
	};
	$out['preview_ttl']        = $int( 'preview_ttl', 60, 86400 );
	$out['max_revisions']      = $int( 'max_revisions', 1, 200 );
	$out['default_grid_limit'] = $int( 'default_grid_limit', 1, 24 );
	foreach ( array( 'allowed_image_hosts', 'allowed_origins' ) as $k ) {
		$out[ $k ] = isset( $in[ $k ] ) && is_string( $in[ $k ] ) ? substr( trim( preg_replace( '/[^A-Za-z0-9.:,\/\-\s_]/', '', $in[ $k ] ) ), 0, 2000 ) : '';
	}
	// Tell the admin which origins will be ignored (they stay stored; the CORS code drops them).
	if ( '' !== $out['allowed_origins'] && function_exists( 'add_settings_error' ) ) {
		foreach ( rk_builder_split_list( $out['allowed_origins'] ) as $o ) {
			if ( 1 !== preg_match( '~^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?/?\z~', $o ) ) {
				add_settings_error( 'rk_builder_settings', 'rk_builder_bad_origin', 'Ignored allowed origin (use scheme://host[:port], no wildcards): ' . $o, 'warning' );
			}
		}
	}
	return $out;
}

/** Split a comma/whitespace separated settings string into a list of non-empty entries (pure). */
function rk_builder_split_list( $value ) {
	if ( is_array( $value ) ) { $value = implode( ',', array_filter( $value, 'is_string' ) ); }
	if ( ! is_string( $value ) ) { return array(); }
	return array_values( array_filter( preg_split( '/[\s,]+/', $value ), 'strlen' ) );
}
