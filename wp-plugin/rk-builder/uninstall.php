<?php
/**
 * Uninstall (runs when the plugin is DELETED from Plugins, not when it is deactivated).
 *
 * ALWAYS deleted (transient bookkeeping only):
 *   - the per-page save locks: options `rk_builder_lock_*`
 *   - the setup-wizard transient `rk_builder_show_setup` and every other `rk_builder_*` transient
 *     (migration reports, caches)
 *
 * DELETED ONLY WHEN YOU OPT IN (constant RK_BUILDER_DELETE_DATA_ON_UNINSTALL === true in wp-config.php,
 * OR the setting "Delete ALL RK Builder data" in Settings > RK Builder is ticked):
 *   - page meta: _rk_layout_draft, _rk_layout_published, _rk_revision, _rk_published_revision,
 *     _rk_published_at, _rk_revisions, _rk_builder_test_page
 *   - options: rk_theme_config, every option starting with `rk_builder_` (settings, schema version,
 *     last migration, last render error, allowed origins, app url, ...)
 *
 * NEVER deleted, with or without the opt-in: pages and their content, Service/Portfolio posts and
 * terms, uploaded media, and the prototype's `_rk_layout` meta.
 *
 * @package RK_Builder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/** Run the uninstall for the current site. */
if ( ! function_exists( 'rk_builder_uninstall_site' ) ) {
function rk_builder_uninstall_site() {
	global $wpdb;

	$like = function ( $prefix ) use ( $wpdb ) { return $wpdb->esc_like( $prefix ) . '%'; };
	$del  = function ( $prefix ) use ( $wpdb, $like ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like( $prefix ) ) );
	};

	// Always: locks and transients.
	$del( 'rk_builder_lock_' );
	$del( '_transient_rk_builder_' );
	$del( '_transient_timeout_rk_builder_' );

	// Opt-in only: the constant, or the setting stored in the settings option.
	$settings = get_option( 'rk_builder_settings', array() );
	$setting  = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
	$constant = defined( 'RK_BUILDER_DELETE_DATA_ON_UNINSTALL' ) && true === RK_BUILDER_DELETE_DATA_ON_UNINSTALL;
	if ( ! $constant && ! $setting ) { return; }

	foreach ( array( '_rk_layout_draft', '_rk_layout_published', '_rk_revision', '_rk_published_revision', '_rk_published_at', '_rk_revisions', '_rk_builder_test_page' ) as $key ) {
		delete_post_meta_by_key( $key ); // NOT '_rk_layout' (the prototype's original data is kept).
	}
	delete_option( 'rk_theme_config' );
	$del( 'rk_builder_' );
	if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); } // options were removed with raw SQL
}
}

if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $rk_site_id ) {
		switch_to_blog( $rk_site_id );
		rk_builder_uninstall_site();
		restore_current_blog();
	}
} else {
	rk_builder_uninstall_site();
}
