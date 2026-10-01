<?php
/**
 * Plugin Name: RK Builder (Headless) - Layouts, Theme Config & CPTs
 * Description: WordPress backend for the headless RK React Builder: strict layout/theme validation, draft/publish with revisions, preview tokens, public read endpoints, Service/Portfolio content types.
 * Version: 1.0.0
 * Author: Rakib Hasan
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: rk-builder
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RK_BUILDER_VERSION', '1.0.0' );
define( 'RK_BUILDER_NS', 'rk/v1' );
define( 'RK_BUILDER_DIR', __DIR__ . '/' );

require_once RK_BUILDER_DIR . 'includes/validation.php';
require_once RK_BUILDER_DIR . 'includes/storage.php';
require_once RK_BUILDER_DIR . 'includes/preview.php';
require_once RK_BUILDER_DIR . 'includes/revalidate.php';
require_once RK_BUILDER_DIR . 'includes/cors.php';
require_once RK_BUILDER_DIR . 'includes/rest.php';
require_once RK_BUILDER_DIR . 'includes/builder.php';
require_once RK_BUILDER_DIR . 'includes/content.php';
require_once RK_BUILDER_DIR . 'includes/admin.php';

add_action( 'init', 'rk_builder_register_content_types' );
add_action( 'rest_api_init', 'rk_builder_register_rest_fields' );
add_action( 'rest_api_init', 'rk_builder_register_routes' );
add_action( 'rest_api_init', 'rk_builder_install_cors', 15 );
add_action( 'admin_menu', 'rk_builder_register_admin_menu' );

register_activation_hook( __FILE__, 'rk_builder_activate' );
function rk_builder_activate() {
	rk_builder_register_content_types();
	flush_rewrite_rules();
}
