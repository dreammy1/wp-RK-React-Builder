<?php
/**
 * Extra WordPress stubs for the admin / settings / setup / migration tests. Required from
 * wp-stubs.php. Every function is guarded so this file never clashes with other stub additions.
 */

if ( ! function_exists( 'plugin_dir_url' ) ) { function plugin_dir_url( $f ) { return 'https://cms.example.com/wp-content/plugins/rk-builder/'; } }
if ( ! function_exists( 'plugin_basename' ) ) { function plugin_basename( $f ) { return 'rk-builder/rk-builder.php'; } }
if ( ! function_exists( '__return_false' ) ) { function __return_false() { return false; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return esc_html( $s ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }

/* menus + Settings API (recorded in $GLOBALS['RK']) */
if ( ! function_exists( 'add_options_page' ) ) {
	function add_options_page( $title, $menu, $cap, $slug, $cb = '' ) { $GLOBALS['RK']['submenu'][] = array( 'parent' => 'options-general.php', 'title' => $title, 'cap' => $cap, 'slug' => $slug, 'cb' => $cb ); return 'settings_page_' . $slug; }
}
if ( ! function_exists( 'add_management_page' ) ) {
	function add_management_page( $title, $menu, $cap, $slug, $cb = '' ) { $GLOBALS['RK']['submenu'][] = array( 'parent' => 'tools.php', 'title' => $title, 'cap' => $cap, 'slug' => $slug, 'cb' => $cb ); return 'tools_page_' . $slug; }
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $group, $name, $args = array() ) { $GLOBALS['RK']['settings'][ $name ] = array( 'group' => $group, 'args' => $args ); }
}
if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $cb, $page ) { $GLOBALS['RK']['sections'][ $page ][ $id ] = array( 'title' => $title, 'cb' => $cb ); }
}
if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $cb, $page, $section, $args = array() ) { $GLOBALS['RK']['fields'][ $page ][ $section ][ $id ] = array( 'title' => $title, 'cb' => $cb, 'args' => $args ); }
}
if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $group . '-options' ) ) . '">'; }
}
if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( $page ) {
		$sections = isset( $GLOBALS['RK']['sections'][ $page ] ) ? $GLOBALS['RK']['sections'][ $page ] : array();
		foreach ( $sections as $sid => $s ) {
			echo '<h2>' . esc_html( $s['title'] ) . '</h2>';
			if ( is_callable( $s['cb'] ) ) { call_user_func( $s['cb'] ); }
			$fields = isset( $GLOBALS['RK']['fields'][ $page ][ $sid ] ) ? $GLOBALS['RK']['fields'][ $page ][ $sid ] : array();
			foreach ( $fields as $f ) { echo '<div class="field"><label>' . esc_html( $f['title'] ) . '</label>'; call_user_func( $f['cb'], $f['args'] ); echo '</div>'; }
		}
	}
}
if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( $setting, $code, $message, $type = 'error' ) { $GLOBALS['RK']['settings_errors'][] = compact( 'setting', 'code', 'message', 'type' ); }
}
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = 'Save Changes' ) { echo '<input type="submit" class="button button-primary" value="' . esc_attr( $t ) . '">'; } }

/* nonces + redirects */
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">'; }
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1 ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=' . wp_create_nonce( $action ); }
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
		if ( ! isset( $_REQUEST[ $name ] ) || $_REQUEST[ $name ] !== wp_create_nonce( $action ) ) { wp_die( 'The link you followed has expired.' ); }
		return 1;
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $url, $status = 302 ) { $GLOBALS['RK']['redirects'][] = $url; return true; }
}

/* transients */
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return get_option( '_transient_' . $k, false ); } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { return update_option( '_transient_' . $k, $v ); } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { return delete_option( '_transient_' . $k ); } }

/* environment */
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax() { return ! empty( $GLOBALS['RK']['doing_ajax'] ); } }
if ( ! function_exists( 'wp_doing_cron' ) ) { function wp_doing_cron() { return ! empty( $GLOBALS['RK']['doing_cron'] ); } }
if ( ! function_exists( 'is_network_admin' ) ) { function is_network_admin() { return ! empty( $GLOBALS['RK']['network_admin'] ); } }
if ( ! function_exists( 'rest_get_url_prefix' ) ) { function rest_get_url_prefix() { return isset( $GLOBALS['RK']['rest_prefix'] ) ? $GLOBALS['RK']['rest_prefix'] : 'wp-json'; } }
if ( ! class_exists( 'RK_Test_REST_Server' ) ) {
	class RK_Test_REST_Server {
		public function get_routes() {
			$out = array();
			foreach ( $GLOBALS['RK']['routes'] as $r ) { $out[ '/' . $r['ns'] . $r['route'] ] = $r['handlers']; }
			return $out;
		}
	}
}
if ( ! function_exists( 'rest_get_server' ) ) { function rest_get_server() { return new RK_Test_REST_Server(); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'version' === $k ? ( isset( $GLOBALS['RK']['wp_version'] ) ? $GLOBALS['RK']['wp_version'] : '6.5.0' ) : 'Test Site'; } }
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { return isset( $GLOBALS['RK']['upload'] ) ? $GLOBALS['RK']['upload'] : array( 'basedir' => sys_get_temp_dir(), 'error' => false ); }
}
if ( ! function_exists( 'wp_is_writable' ) ) { function wp_is_writable( $p ) { return is_writable( $p ); } }
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return isset( $GLOBALS['RK']['cpt'][ $t ] ); } }
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $arr, $err = false ) {
		$id = $GLOBALS['RK']['next_id']++;
		$GLOBALS['RK']['posts'][ $id ] = (object) array_merge( array(
			'ID' => $id, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => '', 'post_name' => '', 'post_author' => get_current_user_id(),
			'post_excerpt' => '', 'post_content' => '', 'post_password' => '', 'post_modified_gmt' => '2026-01-02 03:04:05', 'post_date' => '2026-01-01 00:00:00', 'menu_order' => 0,
		), $arr );
		return $id;
	}
}
if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
	function delete_post_meta_by_key( $key ) { foreach ( $GLOBALS['RK']['meta'] as $id => $m ) { unset( $GLOBALS['RK']['meta'][ $id ][ $key ] ); } return true; }
}

/** Test helper: a temp assets dir containing the given files (name => contents); returns its path with a trailing slash. */
if ( ! function_exists( 'rk_test_assets_dir' ) ) {
	function rk_test_assets_dir( array $files ) {
		$dir = sys_get_temp_dir() . '/rk-assets-' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $dir );
		foreach ( $files as $name => $body ) { file_put_contents( $dir . $name, $body ); }
		return $dir;
	}
}
