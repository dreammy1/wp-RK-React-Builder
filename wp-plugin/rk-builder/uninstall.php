<?php
/**
 * Uninstall: removes only transient-like data (the per-page save locks).
 *
 * Page layouts, revisions and the theme are NOT deleted by default, because they are the site's
 * content. To purge them on uninstall define RK_BUILDER_DELETE_DATA_ON_UNINSTALL as true in
 * wp-config.php before deleting the plugin.
 *
 * @package RK_Builder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'rk_builder_lock_' ) . '%' ) );

if ( defined( 'RK_BUILDER_DELETE_DATA_ON_UNINSTALL' ) && true === RK_BUILDER_DELETE_DATA_ON_UNINSTALL ) {
	foreach ( array( '_rk_layout_draft', '_rk_layout_published', '_rk_revision', '_rk_published_revision', '_rk_published_at', '_rk_revisions' ) as $rk_key ) {
		delete_post_meta_by_key( $rk_key );
	}
	delete_option( 'rk_theme_config' );
	delete_option( 'rk_builder_allowed_origins' );
	delete_option( 'rk_builder_app_url' );
}
