<?php
/**
 * Settings > RK Builder: the settings form (Settings API), cache tools, diagnostics, and the
 * one-time setup wizard (a "Setup" tab on the same screen). Also hosts the admin-post handlers
 * (purge cache, export diagnostics, create test page) and the activation helpers.
 *
 * Every handler requires a capability (manage_options) AND a nonce; nothing here is fatal on a
 * misconfigured host: environment problems are reported as ok/warn/fail checks.
 *
 * Minimum versions (and why):
 *   PHP 7.4 (required, plugin header "Requires PHP"; the code uses typed-free 7.4 syntax only), 8.1+ recommended.
 *   WordPress 5.5 (required): register_rest_route() without permission_callback is a notice from 5.5,
 *   and every route here declares one; wp_get_environment_type()-era REST behaviour is assumed.
 *   WordPress 5.9 recommended: current block editor / theme.json era, security-supported branch floor
 *   for this plugin's testing.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_MIN_PHP' ) ) { define( 'RK_BUILDER_MIN_PHP', '7.4' ); }
if ( ! defined( 'RK_BUILDER_REC_PHP' ) ) { define( 'RK_BUILDER_REC_PHP', '8.1' ); }
if ( ! defined( 'RK_BUILDER_MIN_WP' ) ) { define( 'RK_BUILDER_MIN_WP', '5.5' ); }
if ( ! defined( 'RK_BUILDER_REC_WP' ) ) { define( 'RK_BUILDER_REC_WP', '5.9' ); }

/* ------------------------------------------------------------------ *
 * Hooks, activation
 * ------------------------------------------------------------------ */

/** Register every admin-side hook of this file, migration.php and admin.php (called once from rk-builder.php). */
function rk_builder_register_admin_hooks() {
	add_action( 'plugins_loaded', 'rk_builder_maybe_upgrade_schema' );
	add_action( 'admin_init', 'rk_builder_register_settings' );
	add_action( 'admin_init', 'rk_builder_maybe_redirect_to_setup' );
	add_action( 'admin_menu', 'rk_builder_register_settings_page' );
	add_action( 'admin_menu', 'rk_builder_register_migration_page' );
	add_action( 'admin_post_rk_builder_purge_cache', 'rk_builder_handle_purge_cache' );
	add_action( 'admin_post_rk_builder_export_diagnostics', 'rk_builder_handle_export_diagnostics' );
	add_action( 'admin_post_rk_builder_create_test_page', 'rk_builder_handle_create_test_page' );
	add_action( 'admin_post_rk_builder_migrate', 'rk_builder_handle_migrate' );
	add_filter( 'page_row_actions', 'rk_builder_page_row_actions', 10, 2 );
	add_action( 'post_submitbox_misc_actions', 'rk_builder_submitbox_link' );
	if ( function_exists( 'plugin_basename' ) ) {
		add_filter( 'plugin_action_links_' . plugin_basename( RK_BUILDER_DIR . 'rk-builder.php' ), 'rk_builder_plugin_action_links' );
	}
}

function rk_builder_plugin_action_links( $links ) {
	$links   = is_array( $links ) ? $links : array();
	array_unshift( $links, '<a href="' . esc_url( rk_builder_settings_url() ) . '">' . esc_html__( 'Settings', 'rk-builder' ) . '</a>' );
	return $links;
}

/**
 * Activation: ask for the one-time setup screen. Not for network-wide or bulk activation, where
 * nobody is looking at the result of a single plugin.
 */
function rk_builder_mark_setup_pending( $network_wide = false ) {
	if ( $network_wide ) { return false; }
	// phpcs:disable WordPress.Security.NonceVerification -- only inspecting how the activation was triggered.
	if ( isset( $_GET['activate-multi'] ) || isset( $_POST['checked'] ) || ( isset( $_REQUEST['action'] ) && 'activate-selected' === $_REQUEST['action'] ) ) { return false; }
	// phpcs:enable
	set_transient( 'rk_builder_show_setup', 1, 86400 );
	return true;
}

/**
 * admin_init: send a manage_options user to the Setup tab ONCE after activation. Skipped for
 * AJAX, cron, REST, WP-CLI, network admin and bulk activation. Returns the redirect URL or false.
 */
function rk_builder_maybe_redirect_to_setup() {
	if ( ! get_transient( 'rk_builder_show_setup' ) ) { return false; }
	if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( function_exists( 'is_network_admin' ) && is_network_admin() ) ) {
		return false;
	}
	// phpcs:ignore WordPress.Security.NonceVerification
	if ( isset( $_GET['activate-multi'] ) ) { delete_transient( 'rk_builder_show_setup' ); return false; }
	if ( ! current_user_can( 'manage_options' ) ) { return false; } // stays pending for the next admin
	delete_transient( 'rk_builder_show_setup' ); // once
	if ( isset( $_GET['page'] ) && 'rk-builder-settings' === $_GET['page'] ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification
	return rk_builder_finish_request( rk_builder_settings_url( 'setup' ) );
}

/* ------------------------------------------------------------------ *
 * Shared admin-post plumbing
 * ------------------------------------------------------------------ */

/** Capability + nonce gate for admin-post handlers. Dies (403) on failure. */
function rk_builder_require_admin_action( $nonce_action, $cap = 'manage_options' ) {
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'rk-builder' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $nonce_action );
}

/** Redirect and stop. Filter `rk_builder_exit_after_redirect` exists so tests can keep running. */
function rk_builder_finish_request( $url ) {
	wp_safe_redirect( $url );
	if ( apply_filters( 'rk_builder_exit_after_redirect', true ) ) { exit; }
	return $url;
}

function rk_builder_settings_url( $tab = '', $extra = array() ) {
	$url = admin_url( 'options-general.php?page=rk-builder-settings' );
	if ( '' !== $tab ) { $url .= '&tab=' . rawurlencode( $tab ); }
	foreach ( $extra as $k => $v ) { $url .= '&' . rawurlencode( $k ) . '=' . rawurlencode( (string) $v ); }
	return $url;
}

/* ------------------------------------------------------------------ *
 * Settings API
 * ------------------------------------------------------------------ */

function rk_builder_register_settings_page() {
	add_options_page( 'RK Builder', 'RK Builder', 'manage_options', 'rk-builder-settings', 'rk_builder_render_settings_page' );
}

function rk_builder_register_settings() {
	register_setting( 'rk_builder', 'rk_builder_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'rk_builder_sanitize_settings',
		'default'           => rk_builder_setting_defaults(),
		'show_in_rest'      => false,
	) );
	$page = 'rk-builder-settings';
	add_settings_section( 'rk_general', 'General', '__return_false', $page );
	add_settings_section( 'rk_security', 'Security', 'rk_builder_security_section_intro', $page );
	add_settings_section( 'rk_content', 'Content', '__return_false', $page );
	add_settings_section( 'rk_cache', 'Cache', '__return_false', $page );
	add_settings_section( 'rk_uninstall', 'Uninstall', '__return_false', $page );

	$f = array(
		array( 'rk_general', 'enabled', 'checkbox', 'Enable RK Builder', 'Turns the editor screen and public rendering of builder pages on or off. Saved layouts are never deleted.' ),
		array( 'rk_general', 'public_rendering_mode', 'radio', 'Public rendering mode', '', array( 'theme' => 'Inside the active theme (the layout replaces the page content)', 'standalone' => 'Standalone template (the plugin prints the whole page)' ) ),
		array( 'rk_security', 'allowed_image_hosts', 'textarea', 'Allowed image hosts', 'Extra hosts allowed in absolute image URLs, one per line or comma separated (e.g. cdn.example.com). Your own site is always allowed.' ),
		array( 'rk_security', 'preview_ttl', 'number', 'Preview link lifetime (seconds)', '60 to 86400. Default 900 (15 minutes).', array( 'min' => 60, 'max' => 86400 ) ),
		array( 'rk_security', 'max_revisions', 'number', 'Maximum revisions per page', '1 to 200. Older revisions are removed when the limit is reached.', array( 'min' => 1, 'max' => 200 ) ),
		array( 'rk_security', 'allowed_origins', 'textarea', 'Allowed origins (CORS)', 'Only needed if a separately hosted editor or site talks to this WordPress directly from the browser. One origin per line (scheme://host[:port]); no wildcards. Leave empty for a normal all-in-one install.' ),
		array( 'rk_content', 'enable_service_cpt', 'checkbox', 'Enable the Service content type', '' ),
		array( 'rk_content', 'enable_portfolio_cpt', 'checkbox', 'Enable the Portfolio content type', '' ),
		array( 'rk_content', 'default_grid_limit', 'number', 'Default grid limit', 'Items shown by Services/Portfolio grids when a block does not set a limit (1 to 24).', array( 'min' => 1, 'max' => 24 ) ),
		array( 'rk_cache', 'cache_purge', 'checkbox', 'Purge known cache plugins after publishing', 'Calls the purge functions of supported cache plugins when a page is published or unpublished.' ),
		array( 'rk_uninstall', 'delete_data_on_uninstall', 'checkbox', 'Delete ALL RK Builder data when the plugin is deleted', 'Layouts, revisions, theme and settings are removed on uninstall. Pages themselves, Service/Portfolio posts, media and the legacy _rk_layout meta are never deleted. Off by default.' ),
	);
	foreach ( $f as $row ) {
		$args = array( 'key' => $row[1], 'type' => $row[2], 'label' => $row[3], 'desc' => $row[4], 'opts' => isset( $row[5] ) ? $row[5] : array(), 'label_for' => 'rk_field_' . $row[1] );
		add_settings_field( 'rk_' . $row[1], $row[3], 'rk_builder_render_field', $page, $row[0], $args );
	}
}

function rk_builder_security_section_intro() {
	echo '<p class="description">' . esc_html__( 'Layout validation always rejects unsafe URLs and unknown fields; these options only widen what is allowed.', 'rk-builder' ) . '</p>';
}

/** Settings API field callback: one input per setting, always escaped. */
function rk_builder_render_field( $args ) {
	$key  = $args['key'];
	$val  = rk_builder_setting( $key );
	$name = 'rk_builder_settings[' . $key . ']';
	$id   = 'rk_field_' . $key;
	switch ( $args['type'] ) {
		case 'checkbox':
			echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $val ? ' checked="checked"' : '' ) . '> ' . esc_html( $args['label'] ) . '</label>';
			break;
		case 'number':
			echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $val ) . '" min="' . (int) $args['opts']['min'] . '" max="' . (int) $args['opts']['max'] . '">';
			break;
		case 'textarea':
			echo '<textarea class="large-text code" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $val ) . '</textarea>';
			break;
		case 'radio':
			foreach ( $args['opts'] as $v => $label ) {
				echo '<label style="display:block;margin-bottom:4px"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '"' . ( (string) $val === (string) $v ? ' checked="checked"' : '' ) . '> ' . esc_html( $label ) . '</label>';
			}
			break;
	}
	if ( '' !== $args['desc'] ) { echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>'; }
}

/* ------------------------------------------------------------------ *
 * Environment checks (setup wizard)
 * ------------------------------------------------------------------ */

/** Everything the checks look at, gathered in one place (filter `rk_builder_environment` lets tests and hosts override). */
function rk_builder_environment() {
	$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'error' => 'wp_upload_dir() is not available', 'basedir' => '' );
	$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
	$routes  = null;
	if ( function_exists( 'rest_get_server' ) ) {
		$server = rest_get_server();
		if ( is_object( $server ) && method_exists( $server, 'get_routes' ) ) {
			$routes = false;
			foreach ( array_keys( (array) $server->get_routes() ) as $r ) {
				if ( 0 === strpos( (string) $r, '/' . RK_BUILDER_NS ) ) { $routes = true; break; }
			}
		}
	}
	$env = array(
		'php_version'     => PHP_VERSION,
		'wp_version'      => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
		'rest_prefix'     => function_exists( 'rest_get_url_prefix' ) ? (string) rest_get_url_prefix() : 'wp-json',
		'rest_routes'     => $routes,
		'permalinks'      => (string) get_option( 'permalink_structure', '' ),
		'upload_error'    => ( ! empty( $uploads['error'] ) ) ? (string) $uploads['error'] : '',
		'upload_writable' => '' !== $basedir && function_exists( 'wp_is_writable' ) && wp_is_writable( $basedir ),
		'editor_bundled'  => rk_builder_editor_assets_present(),
		'editor_url'      => '' !== rk_builder_app_url(),
		'cpt'             => array(
			'service'   => function_exists( 'post_type_exists' ) ? post_type_exists( 'service' ) : null,
			'portfolio' => function_exists( 'post_type_exists' ) ? post_type_exists( 'portfolio' ) : null,
		),
		'cpt_enabled'     => array( 'service' => (bool) rk_builder_setting( 'enable_service_cpt', true ), 'portfolio' => (bool) rk_builder_setting( 'enable_portfolio_cpt', true ) ),
		'legacy_count'    => count( rk_builder_find_legacy_layouts() ),
	);
	$filtered = apply_filters( 'rk_builder_environment', $env );
	return is_array( $filtered ) ? array_merge( $env, $filtered ) : $env;
}

function rk_builder_check( $id, $label, $status, $message ) {
	return array( 'id' => $id, 'label' => $label, 'status' => $status, 'message' => $message );
}

/**
 * Pure: evaluate an environment array into checks. Each check is
 * array( id, label, status ok|warn|fail, message ). Never throws.
 */
function rk_builder_setup_evaluate( array $env ) {
	$c = array();

	$php = isset( $env['php_version'] ) ? (string) $env['php_version'] : '';
	if ( '' === $php || version_compare( $php, RK_BUILDER_MIN_PHP, '<' ) ) {
		$c[] = rk_builder_check( 'php', 'PHP version', 'fail', sprintf( 'PHP %s is too old. RK Builder needs PHP %s or newer (%s or newer recommended).', $php, RK_BUILDER_MIN_PHP, RK_BUILDER_REC_PHP ) );
	} elseif ( version_compare( $php, RK_BUILDER_REC_PHP, '<' ) ) {
		$c[] = rk_builder_check( 'php', 'PHP version', 'warn', sprintf( 'PHP %s works, but PHP %s or newer is recommended (faster and still supported).', $php, RK_BUILDER_REC_PHP ) );
	} else {
		$c[] = rk_builder_check( 'php', 'PHP version', 'ok', 'PHP ' . $php );
	}

	$wp = isset( $env['wp_version'] ) ? (string) $env['wp_version'] : '';
	if ( '' === $wp ) {
		$c[] = rk_builder_check( 'wp', 'WordPress version', 'warn', 'Could not determine the WordPress version.' );
	} elseif ( version_compare( $wp, RK_BUILDER_MIN_WP, '<' ) ) {
		$c[] = rk_builder_check( 'wp', 'WordPress version', 'fail', sprintf( 'WordPress %s is too old. RK Builder needs %s or newer (%s or newer recommended).', $wp, RK_BUILDER_MIN_WP, RK_BUILDER_REC_WP ) );
	} elseif ( version_compare( $wp, RK_BUILDER_REC_WP, '<' ) ) {
		$c[] = rk_builder_check( 'wp', 'WordPress version', 'warn', sprintf( 'WordPress %s works; %s or newer is recommended.', $wp, RK_BUILDER_REC_WP ) );
	} else {
		$c[] = rk_builder_check( 'wp', 'WordPress version', 'ok', 'WordPress ' . $wp );
	}

	$prefix = isset( $env['rest_prefix'] ) ? (string) $env['rest_prefix'] : '';
	if ( '' === $prefix ) {
		$c[] = rk_builder_check( 'rest', 'REST API', 'fail', 'The REST API prefix is empty; the REST API looks disabled.' );
	} elseif ( isset( $env['rest_routes'] ) && true === $env['rest_routes'] ) {
		$c[] = rk_builder_check( 'rest', 'REST API', 'ok', 'Routes are registered under /' . $prefix . '/' . RK_BUILDER_NS . '/.' );
	} elseif ( isset( $env['rest_routes'] ) && false === $env['rest_routes'] ) {
		$c[] = rk_builder_check( 'rest', 'REST API', 'fail', 'The RK Builder REST routes are not registered. A security or REST-disabling plugin may be blocking them.' );
	} else {
		$c[] = rk_builder_check( 'rest', 'REST API', 'warn', 'Could not inspect the REST server from here. Open the editor to confirm it loads.' );
	}

	if ( empty( $env['permalinks'] ) ) {
		$c[] = rk_builder_check( 'permalinks', 'Permalinks', 'warn', 'Plain permalinks are active. They work, but pretty permalinks (Settings > Permalinks) are recommended for clean page URLs and REST URLs.' );
	} else {
		$c[] = rk_builder_check( 'permalinks', 'Permalinks', 'ok', 'Pretty permalinks: ' . $env['permalinks'] );
	}

	if ( ! empty( $env['upload_error'] ) ) {
		$c[] = rk_builder_check( 'uploads', 'Uploads folder', 'fail', 'WordPress reports an uploads problem: ' . $env['upload_error'] );
	} elseif ( empty( $env['upload_writable'] ) ) {
		$c[] = rk_builder_check( 'uploads', 'Uploads folder', 'fail', 'The uploads folder is not writable, so images cannot be added in the editor.' );
	} else {
		$c[] = rk_builder_check( 'uploads', 'Uploads folder', 'ok', 'Writable.' );
	}

	if ( ! empty( $env['editor_bundled'] ) ) {
		$c[] = rk_builder_check( 'assets', 'Bundled editor files', 'ok', 'assets/builder.js is present.' );
	} elseif ( ! empty( $env['editor_url'] ) ) {
		$c[] = rk_builder_check( 'assets', 'Bundled editor files', 'ok', 'Not bundled; the editor is loaded from a configured URL (RK_BUILDER_APP_URL or the rk_builder_app_url option/filter).' );
	} else {
		$c[] = rk_builder_check( 'assets', 'Bundled editor files', 'fail', rk_builder_missing_assets_message() );
	}

	$parts = array();
	$warn  = false;
	foreach ( array( 'service' => 'Service', 'portfolio' => 'Portfolio' ) as $slug => $label ) {
		$enabled = ! empty( $env['cpt_enabled'][ $slug ] );
		$reg     = isset( $env['cpt'][ $slug ] ) ? $env['cpt'][ $slug ] : null;
		$parts[] = $label . ': ' . ( $enabled ? 'enabled' : 'disabled' ) . ( $enabled && false === $reg ? ' (not registered yet; reload the page or re-save permalinks)' : '' );
		if ( $enabled && false === $reg ) { $warn = true; }
	}
	$c[] = rk_builder_check( 'cpt', 'Content types', $warn ? 'warn' : 'ok', implode( '; ', $parts ) . '. Service and Portfolio power the grid blocks.' );

	$legacy = isset( $env['legacy_count'] ) ? (int) $env['legacy_count'] : 0;
	$c[]    = rk_builder_check( 'legacy', 'Prototype layouts', $legacy > 0 ? 'warn' : 'ok', $legacy > 0 ? sprintf( '%d page(s) still carry a prototype layout (_rk_layout). Import them with the migration tool.', $legacy ) : 'No prototype layouts found.' );
	return $c;
}

/** Checks for the live site. */
function rk_builder_setup_checks() {
	return rk_builder_setup_evaluate( rk_builder_environment() );
}

/** 'ok' | 'warn' | 'fail' - the worst status in a list of checks. */
function rk_builder_checks_overall( array $checks ) {
	$rank = array( 'ok' => 0, 'warn' => 1, 'fail' => 2 );
	$top  = 'ok';
	foreach ( $checks as $c ) { if ( $rank[ $c['status'] ] > $rank[ $top ] ) { $top = $c['status']; } }
	return $top;
}

/* ------------------------------------------------------------------ *
 * Test page
 * ------------------------------------------------------------------ */

/** A small valid layout used by the "create test page" button. */
function rk_builder_test_layout() {
	return array(
		'version' => RK_BUILDER_SCHEMA_VERSION,
		'blocks'  => array(
			array( 'id' => 'test-hero', 'type' => 'hero', 'props' => array( 'heading' => 'Hello from RK Builder', 'sub' => 'This page was created by the setup wizard. Edit it in RK Builder, then publish.', 'cta' => 'Learn more', 'ctaHref' => '/' ) ),
			array( 'id' => 'test-heading', 'type' => 'heading', 'props' => array( 'text' => 'It works', 'level' => 2 ) ),
			array( 'id' => 'test-text', 'type' => 'text', 'props' => array( 'text' => 'If you can read this in the editor, the REST API, nonce and storage are all working.' ) ),
		),
	);
}

/** The existing test page id (not trashed), or 0. */
function rk_builder_find_test_page() {
	$ids = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => '_rk_builder_test_page', 'meta_value' => '1', 'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	if ( ! $ids ) { return 0; }
	$first = reset( $ids );
	return is_object( $first ) ? (int) $first->ID : (int) $first;
}

/**
 * Create the draft "RK Builder test page" with a small valid layout, saved as a draft revision
 * through the normal storage functions. Idempotent: returns the existing page when there is one.
 *
 * @return int|WP_Error Page id.
 */
function rk_builder_create_test_page() {
	$existing = rk_builder_find_test_page();
	if ( $existing > 0 ) { return $existing; }
	$layout = rk_builder_test_layout();
	if ( array() !== rk_builder_validate_layout( $layout, null ) ) {
		return new WP_Error( 'rk_test_layout_invalid', 'The built-in test layout failed validation.' );
	}
	$id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'RK Builder test page',
		'post_content' => '',
		'post_author'  => (int) get_current_user_id(),
	), true );
	if ( is_wp_error( $id ) || ! $id ) {
		return is_wp_error( $id ) ? $id : new WP_Error( 'rk_test_page_failed', 'Could not create the page.' );
	}
	update_post_meta( $id, '_rk_builder_test_page', '1' );
	$res = rk_builder_with_lock( $id, function () use ( $id, $layout ) {
		return rk_builder_commit_revision( $id, 'draft', rk_builder_canonicalize_layout( $layout ) );
	} );
	if ( is_wp_error( $res ) ) { return $res; }
	return (int) $id;
}

function rk_builder_handle_create_test_page() {
	rk_builder_require_admin_action( 'rk_builder_create_test_page' );
	$id = rk_builder_create_test_page();
	if ( is_wp_error( $id ) ) { return rk_builder_finish_request( rk_builder_settings_url( 'setup', array( 'rk_notice' => 'test_page_failed' ) ) ); }
	return rk_builder_finish_request( rk_builder_settings_url( 'setup', array( 'rk_notice' => 'test_page_created', 'rk_test_page' => $id ) ) );
}

/* ------------------------------------------------------------------ *
 * Cache + diagnostics handlers
 * ------------------------------------------------------------------ */

function rk_builder_handle_purge_cache() {
	rk_builder_require_admin_action( 'rk_builder_purge_cache' );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) {
		rk_builder_purge_all_public_cache();
		return rk_builder_finish_request( rk_builder_settings_url( '', array( 'rk_notice' => 'purged' ) ) );
	}
	return rk_builder_finish_request( rk_builder_settings_url( '', array( 'rk_notice' => 'purge_unavailable' ) ) );
}

/**
 * Support information. Contains NO secrets, tokens, nonces, URLs with credentials, or layout bodies:
 * only versions, booleans, counts, settings values and the names (never values) of configured constants.
 */
function rk_builder_diagnostics() {
	$env   = rk_builder_environment();
	$const = array();
	foreach ( array( 'RK_BUILDER_PREVIEW_SECRET', 'RK_BUILDER_REVALIDATE_URL', 'RK_BUILDER_REVALIDATE_SECRET', 'RK_BUILDER_ALLOWED_ORIGINS', 'RK_BUILDER_ALLOWED_IMAGE_HOSTS', 'RK_BUILDER_APP_URL', 'RK_BUILDER_APP_CSS_URL', 'RK_BUILDER_FRONTEND_URL', 'RK_BUILDER_MAX_REVISIONS', 'RK_BUILDER_DELETE_DATA_ON_UNINSTALL' ) as $name ) {
		$const[ $name ] = defined( $name );
	}
	$err = get_option( 'rk_builder_last_render_error', null );
	if ( is_array( $err ) ) {
		$err = array(
			'time'    => isset( $err['time'] ) && is_scalar( $err['time'] ) ? (string) $err['time'] : '',
			'page_id' => isset( $err['page_id'] ) ? (int) $err['page_id'] : 0,
			'code'    => isset( $err['code'] ) && is_scalar( $err['code'] ) ? preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $err['code'] ) : '',
		);
	} else {
		$err = null;
	}
	$upg = get_option( 'rk_builder_schema_upgrade', null );
	return array(
		'generated_at'        => rk_builder_iso( rk_builder_now() ),
		'plugin_version'      => RK_BUILDER_VERSION,
		'schema_version'      => (int) get_option( 'rk_builder_schema_version', 0 ),
		'schema_current'      => rk_builder_current_schema_version(),
		'php_version'         => (string) $env['php_version'],
		'wp_version'          => (string) $env['wp_version'],
		'memory_limit'        => (string) ini_get( 'memory_limit' ),
		'multisite'           => function_exists( 'is_multisite' ) ? (bool) is_multisite() : false,
		'rest'                => array( 'prefix' => (string) $env['rest_prefix'], 'rk_routes_registered' => $env['rest_routes'] ),
		'permalink_structure' => '' === (string) $env['permalinks'] ? 'plain' : (string) $env['permalinks'],
		'uploads'             => array( 'ok' => '' === (string) $env['upload_error'], 'writable' => (bool) $env['upload_writable'] ),
		'assets'              => rk_builder_assets_status(),
		'asset_version'       => rk_builder_asset_version(),
		'editor_source'       => ! empty( $env['editor_bundled'] ) ? 'bundled' : ( ! empty( $env['editor_url'] ) ? 'configured' : 'none' ),
		'content_types'       => array( 'registered' => $env['cpt'], 'enabled' => $env['cpt_enabled'] ),
		'settings'            => rk_builder_get_settings(),
		'constants_defined'   => $const,
		'legacy_layouts'      => (int) $env['legacy_count'],
		'last_migration'      => rk_builder_last_migration(),
		'last_schema_upgrade' => is_array( $upg ) ? $upg : null,
		'last_render_error'   => $err,
		'checks'              => rk_builder_setup_evaluate( $env ),
	);
}

function rk_builder_handle_export_diagnostics() {
	rk_builder_require_admin_action( 'rk_builder_export_diagnostics' );
	$json = wp_json_encode( rk_builder_diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rk-builder-diagnostics-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store' );
	}
	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download, not HTML.
	if ( apply_filters( 'rk_builder_exit_after_redirect', true ) ) { exit; }
}

/* ------------------------------------------------------------------ *
 * Screen
 * ------------------------------------------------------------------ */

function rk_builder_status_label( $status ) {
	$map = array( 'ok' => 'OK', 'warn' => 'Warning', 'fail' => 'Problem' );
	return isset( $map[ $status ] ) ? $map[ $status ] : '';
}

function rk_builder_render_checks( array $checks ) {
	$colors = array( 'ok' => '#00a32a', 'warn' => '#dba617', 'fail' => '#d63638' );
	echo '<table class="widefat striped" style="max-width:900px"><tbody>';
	foreach ( $checks as $c ) {
		echo '<tr><th scope="row" style="width:210px">' . esc_html( $c['label'] ) . '</th>';
		echo '<td style="width:90px"><strong style="color:' . esc_attr( $colors[ $c['status'] ] ) . '">' . esc_html( rk_builder_status_label( $c['status'] ) ) . '</strong></td>';
		echo '<td>' . esc_html( $c['message'] ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

function rk_builder_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'rk-builder' ), '', array( 'response' => 403 ) );
	}
	$tab = isset( $_GET['tab'] ) && 'setup' === $_GET['tab'] ? 'setup' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap"><h1>' . esc_html__( 'RK Builder', 'rk-builder' ) . '</h1>';
	echo '<h2 class="nav-tab-wrapper">';
	echo '<a class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( rk_builder_settings_url() ) . '">' . esc_html__( 'Settings', 'rk-builder' ) . '</a>';
	echo '<a class="nav-tab' . ( 'setup' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( rk_builder_settings_url( 'setup' ) ) . '">' . esc_html__( 'Setup', 'rk-builder' ) . '</a></h2>';
	rk_builder_render_notice();
	if ( 'setup' === $tab ) {
		rk_builder_render_setup_tab();
	} else {
		rk_builder_render_settings_tab();
	}
	echo '</div>';
}

function rk_builder_render_notice() {
	$map = array(
		'purged'            => array( 'success', 'Public cache purged.' ),
		'purge_unavailable' => array( 'warning', 'Cache purging is not available in this build.' ),
		'test_page_created' => array( 'success', 'Test page ready. Open it in RK Builder to check that the editor works.' ),
		'test_page_failed'  => array( 'error', 'The test page could not be created.' ),
	);
	$n = isset( $_GET['rk_notice'] ) && is_string( $_GET['rk_notice'] ) ? sanitize_key( wp_unslash( $_GET['rk_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( isset( $map[ $n ] ) ) {
		echo '<div class="notice notice-' . esc_attr( $map[ $n ][0] ) . '"><p>' . esc_html( $map[ $n ][1] ) . '</p></div>';
	}
}

function rk_builder_render_settings_tab() {
	if ( ! rk_builder_editor_assets_present() && '' === rk_builder_app_url() ) {
		echo '<div class="notice notice-error"><p>' . esc_html( rk_builder_missing_assets_message() ) . '</p></div>';
	}
	echo '<form method="post" action="options.php">';
	settings_fields( 'rk_builder' );
	do_settings_sections( 'rk-builder-settings' );
	submit_button();
	echo '</form>';

	echo '<h2>' . esc_html__( 'Cache tools', 'rk-builder' ) . '</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rk_builder_purge_cache">';
	wp_nonce_field( 'rk_builder_purge_cache' );
	echo '<p><button type="submit" class="button">' . esc_html__( 'Purge public cache now', 'rk-builder' ) . '</button> <span class="description">' . esc_html__( 'Clears cached public pages (and supported cache plugins when enabled above).', 'rk-builder' ) . '</span></p></form>';

	$d = rk_builder_diagnostics();
	echo '<h2>' . esc_html__( 'Diagnostics', 'rk-builder' ) . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
	$rows = array(
		'Plugin version' => $d['plugin_version'],
		'PHP version'    => $d['php_version'],
		'WordPress'      => $d['wp_version'],
		'REST API'       => true === $d['rest']['rk_routes_registered'] ? 'RK routes registered under /' . $d['rest']['prefix'] . '/' : ( false === $d['rest']['rk_routes_registered'] ? 'RK routes NOT registered' : 'unknown' ),
		'Permalinks'     => $d['permalink_structure'],
		'Data schema'    => $d['schema_version'] . ' (current ' . $d['schema_current'] . ')',
		'Editor files'   => $d['editor_source'],
		'Legacy layouts' => (string) $d['legacy_layouts'],
	);
	$m = $d['last_migration'];
	$rows['Last migration'] = $m ? sprintf( '%s: %d migrated, %d skipped, %d failed%s', $m['time'], $m['migrated'], $m['skipped'], $m['failed'], $m['dry_run'] ? ' (dry run)' : '' ) : 'none yet';
	$e = $d['last_render_error'];
	$rows['Last public render error'] = $e ? sprintf( '%s, page #%d, code %s', $e['time'], $e['page_id'], $e['code'] ) : 'none recorded';
	foreach ( $rows as $label => $value ) {
		echo '<tr><th scope="row" style="width:210px">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
	}
	echo '</tbody></table>';
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=rk_builder_export_diagnostics' ), 'rk_builder_export_diagnostics' );
	echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export diagnostics (JSON)', 'rk-builder' ) . '</a> <span class="description">' . esc_html__( 'Contains versions, settings and check results only: no secrets, tokens or page content.', 'rk-builder' ) . '</span></p>';
}

function rk_builder_render_setup_tab() {
	$checks  = rk_builder_setup_checks();
	$overall = rk_builder_checks_overall( $checks );
	echo '<p>' . esc_html__( 'These checks confirm your hosting is ready for RK Builder. Nothing here changes your site.', 'rk-builder' ) . '</p>';
	if ( 'fail' === $overall ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Some checks failed. Fix those first.', 'rk-builder' ) . '</p></div>';
	} elseif ( 'warn' === $overall ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'RK Builder will work, but some items deserve attention.', 'rk-builder' ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Everything looks good.', 'rk-builder' ) . '</p></div>';
	}
	rk_builder_render_checks( $checks );

	echo '<h2>' . esc_html__( 'Next steps', 'rk-builder' ) . '</h2>';
	$test = rk_builder_find_test_page();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rk_builder_create_test_page">';
	wp_nonce_field( 'rk_builder_create_test_page' );
	echo '<p><button type="submit" class="button button-primary">' . ( $test ? esc_html__( 'Go to the test page', 'rk-builder' ) : esc_html__( 'Create a test page', 'rk-builder' ) ) . '</button> ';
	echo '<span class="description">' . esc_html__( 'Creates a draft page called "RK Builder test page" with a small layout. Safe to click twice.', 'rk-builder' ) . '</span></p></form>';
	$shown = $test;
	if ( ! $shown && isset( $_GET['rk_test_page'] ) && is_scalar( $_GET['rk_test_page'] ) ) { $shown = (int) $_GET['rk_test_page']; } // phpcs:ignore WordPress.Security.NonceVerification
	if ( $shown > 0 && current_user_can( 'edit_post', $shown ) ) {
		echo '<p><a class="button" href="' . esc_url( rk_builder_edit_link( $shown ) ) . '">' . esc_html__( 'Open the test page in RK Builder', 'rk-builder' ) . '</a></p>';
	}
	echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=rk-builder-migration' ) ) . '">' . esc_html__( 'Import prototype layouts (migration tool)', 'rk-builder' ) . '</a></p>';
	echo '<p><a href="' . esc_url( rk_builder_edit_link() ) . '">' . esc_html__( 'Open RK Builder', 'rk-builder' ) . '</a></p>';
}
