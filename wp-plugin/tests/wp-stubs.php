<?php
/**
 * Minimal fake of the WordPress APIs the plugin uses, so REST handlers can be unit tested with
 * plain `php` (no WordPress, no composer). Deliberately faithful where it matters for bugs:
 *  - update_post_meta() UNSLASHES the value (hence the plugin must wp_slash()),
 *  - update_option() does not,
 *  - permission callbacks returning false become `rest_forbidden`,
 *  - route regexes + named groups are dispatched like core does.
 * It is NOT a WordPress reimplementation; the optional scripts/wp-smoke.mjs covers real WP.
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

$GLOBALS['RK'] = array();

function rk_test_reset() {
	$GLOBALS['RK'] = array(
		'options' => array(), 'posts' => array(), 'meta' => array(), 'terms' => array(), 'attachments' => array(),
		'filters' => array(), 'http' => array(), 'user' => 0, 'next_id' => 100, 'menu' => array(),
		'fail_update_post' => false, 'routes' => isset( $GLOBALS['RK']['routes'] ) ? $GLOBALS['RK']['routes'] : array(),
		'users' => array(
			1 => array( 'name' => 'Ada Admin', 'role' => 'administrator' ),
			2 => array( 'name' => 'Ed Editor', 'role' => 'editor' ),
			3 => array( 'name' => 'Sue Subscriber', 'role' => 'subscriber' ),
			4 => array( 'name' => 'Al Author', 'role' => 'author' ),
		),
	);
	$GLOBALS['wpdb'] = new RK_Test_WPDB();
}

/* ---------------- classes ---------------- */

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }

class WP_REST_Response {
	public $data; public $status; public $headers = array();
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
	public function get_headers() { return $this->headers; }
}

class WP_REST_Request implements ArrayAccess {
	public $method; public $route; public $url = array(); public $query = array(); public $body = ''; public $hdr = array();
	public function __construct( $method = 'GET', $route = '' ) { $this->method = $method; $this->route = $route; }
	public function get_body() { return $this->body; }
	public function get_json_params() {
		if ( '' === $this->body ) { return null; }
		$d = json_decode( $this->body, true );
		return JSON_ERROR_NONE === json_last_error() ? $d : null;
	}
	public function get_param( $k ) {
		$json = $this->get_json_params();
		if ( is_array( $json ) && array_key_exists( $k, $json ) ) { return $json[ $k ]; }
		if ( array_key_exists( $k, $this->query ) ) { return $this->query[ $k ]; }
		if ( array_key_exists( $k, $this->url ) ) { return $this->url[ $k ]; }
		return null;
	}
	public function offsetExists( $k ): bool { return null !== $this->get_param( $k ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $k ) { return $this->get_param( $k ); }
	public function offsetSet( $k, $v ): void { $this->url[ $k ] = $v; }
	public function offsetUnset( $k ): void { unset( $this->url[ $k ] ); }
}

class RK_Test_WPDB {
	public $options = 'wp_options';
	public function prepare( $q ) {
		$args = array_slice( func_get_args(), 1 );
		return preg_replace_callback( '/%s/', function () use ( &$args ) { return "'" . addslashes( (string) array_shift( $args ) ) . "'"; }, $q );
	}
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function query( $sql ) {
		if ( preg_match( "/INSERT IGNORE INTO .* VALUES \( '((?:[^'\\\\]|\\\\.)*)', '((?:[^'\\\\]|\\\\.)*)', 'no' \)/", $sql, $m ) ) {
			$key = stripslashes( $m[1] );
			if ( array_key_exists( $key, $GLOBALS['RK']['options'] ) ) { return 0; }
			$GLOBALS['RK']['options'][ $key ] = stripslashes( $m[2] );
			return 1;
		}
		return 0;
	}
}

class WP_Query {
	public $posts = array(); public $found_posts = 0;
	public function __construct( $args = array() ) { list( $this->posts, $this->found_posts ) = rk_test_query( $args ); }
}

/* ---------------- hooks ---------------- */

function add_filter( $tag, $fn, $prio = 10, $n = 1 ) { $GLOBALS['RK']['filters'][ $tag ][ $prio ][] = $fn; return true; }
function add_action( $tag, $fn, $prio = 10, $n = 1 ) { return add_filter( $tag, $fn, $prio, $n ); }
function remove_filter( $tag, $fn, $prio = 10 ) {
	if ( isset( $GLOBALS['RK']['filters'][ $tag ][ $prio ] ) ) {
		$GLOBALS['RK']['filters'][ $tag ][ $prio ] = array_values( array_filter( $GLOBALS['RK']['filters'][ $tag ][ $prio ], function ( $f ) use ( $fn ) { return $f !== $fn; } ) );
	}
	return true;
}
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	if ( empty( $GLOBALS['RK']['filters'][ $tag ] ) ) { return $value; }
	$by = $GLOBALS['RK']['filters'][ $tag ];
	ksort( $by );
	foreach ( $by as $fns ) {
		foreach ( $fns as $fn ) { $args[0] = call_user_func_array( $fn, $args ); }
	}
	return $args[0];
}
function do_action( $tag ) { $a = func_get_args(); $a[] = null; call_user_func_array( 'apply_filters', $a ); }
function register_activation_hook( $f, $fn ) {}
function __return_true() { return true; }

/* ---------------- utils ---------------- */

function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $d, $flags = 0 ) { return json_encode( $d, $flags ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://cms.example.com' . $p; }
function site_url( $p = '' ) { return 'https://cms.example.com' . $p; }
function admin_url( $p = '' ) { return 'https://cms.example.com/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://cms.example.com/wp-json/' . $p; }
function wp_salt( $s = 'auth' ) { return 'test-salt-' . $s . '-0123456789abcdef0123456789abcdef'; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]*\]/', '', $s ); }
function wp_trim_words( $t, $n = 55, $more = '…' ) { $w = preg_split( '/\s+/', trim( strip_tags( $t ) ), -1, PREG_SPLIT_NO_EMPTY ); return count( $w ) > $n ? implode( ' ', array_slice( $w, 0, $n ) ) . $more : implode( ' ', $w ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( '' === $url ) { return ''; }
	if ( preg_match( '~^([a-z][a-z0-9+.-]*):~i', $url, $m ) && null !== $protocols && ! in_array( strtolower( $m[1] ), $protocols, true ) ) { return ''; }
	return $url;
}
function esc_url( $url ) { return esc_html( esc_url_raw( $url ) ); }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_die( $m = '', $t = '', $a = array() ) { throw new Exception( 'wp_die: ' . $m ); }
function add_menu_page( $title, $menu, $cap, $slug, $cb, $icon = '', $pos = null ) { $GLOBALS['RK']['menu'][] = compact( 'title', 'menu', 'cap', 'slug', 'cb', 'icon', 'pos' ); }
function flush_rewrite_rules() {}
function register_post_type( $t, $a = array() ) { $GLOBALS['RK']['cpt'][ $t ] = $a; }
function register_taxonomy( $t, $o, $a = array() ) { $GLOBALS['RK']['tax'][ $t ] = array( $o, $a ); }
function register_rest_field( $t, $n, $a ) { $GLOBALS['RK']['fields'][ $t ][ $n ] = $a; }
function rest_ensure_response( $x ) { return is_wp_error( $x ) ? $x : ( $x instanceof WP_REST_Response ? $x : new WP_REST_Response( $x ) ); }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['RK']['http'][] = array( 'url' => $url, 'args' => $args ); return array(); }

/* ---------------- options ---------------- */

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['RK']['options'] ) ? $GLOBALS['RK']['options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['RK']['options'][ $k ] = $v; return true; } // NOT unslashed, like core
function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['RK']['options'] ) ) { return false; } $GLOBALS['RK']['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['RK']['options'][ $k ] ); return true; }

/* ---------------- users / caps ---------------- */

function rk_test_login( $who ) {
	$ids = array( 'anon' => 0, 'admin' => 1, 'editor' => 2, 'subscriber' => 3, 'author' => 4 );
	$GLOBALS['RK']['user'] = $ids[ $who ];
}
function is_user_logged_in() { return $GLOBALS['RK']['user'] > 0; }
function get_current_user_id() { return (int) $GLOBALS['RK']['user']; }
function get_userdata( $id ) {
	if ( ! isset( $GLOBALS['RK']['users'][ $id ] ) ) { return false; }
	return (object) array( 'ID' => $id, 'display_name' => $GLOBALS['RK']['users'][ $id ]['name'] );
}
function rk_test_role_caps( $role ) {
	$sub = array( 'read' );
	$aut = array( 'read', 'edit_posts', 'upload_files', 'publish_posts' );
	$edi = array( 'read', 'edit_posts', 'upload_files', 'edit_pages', 'edit_others_pages', 'publish_pages', 'edit_others_posts' );
	$map = array( 'subscriber' => $sub, 'author' => $aut, 'editor' => $edi, 'administrator' => array_merge( $edi, array( 'manage_options' ) ) );
	return $map[ $role ];
}
function current_user_can( $cap, $id = null ) {
	$uid = get_current_user_id();
	if ( $uid < 1 ) { return false; }
	$caps = rk_test_role_caps( $GLOBALS['RK']['users'][ $uid ]['role'] );
	if ( 'edit_post' === $cap || 'publish_post' === $cap ) {
		$post = get_post( $id );
		if ( ! $post ) { return false; }
		if ( 'publish_post' === $cap ) { return in_array( 'publish_pages', $caps, true ); }
		if ( in_array( 'edit_others_pages', $caps, true ) ) { return true; }
		return in_array( 'edit_pages', $caps, true ) && (int) $post->post_author === $uid;
	}
	return in_array( $cap, $caps, true );
}

/* ---------------- posts / meta ---------------- */

function rk_test_page( $status = 'draft', $slug = 'about', $author = 2, $extra = array() ) {
	$id = $GLOBALS['RK']['next_id']++;
	$GLOBALS['RK']['posts'][ $id ] = (object) array_merge( array(
		'ID' => $id, 'post_type' => 'page', 'post_status' => $status, 'post_title' => ucfirst( $slug ), 'post_name' => $slug,
		'post_author' => $author, 'post_excerpt' => '', 'post_content' => '', 'post_password' => '',
		'post_modified_gmt' => '2026-01-02 03:04:05', 'post_date' => '2026-01-01 00:00:00', 'menu_order' => 0,
	), $extra );
	return $id;
}
function get_post( $id ) { return isset( $GLOBALS['RK']['posts'][ (int) ( is_object( $id ) ? $id->ID : $id ) ] ) ? $GLOBALS['RK']['posts'][ (int) ( is_object( $id ) ? $id->ID : $id ) ] : null; }
function get_the_title( $p ) { $p = get_post( $p ); return $p ? $p->post_title : ''; }
function get_permalink( $id ) { $p = get_post( $id ); return $p ? ( 'publish' === $p->post_status ? home_url( '/' . $p->post_name . '/' ) : home_url( '/?p=' . $p->ID ) ) : false; }
function wp_update_post( $arr, $err = false ) {
	if ( $GLOBALS['RK']['fail_update_post'] ) { return $err ? new WP_Error( 'x', 'fail' ) : 0; }
	$p = get_post( $arr['ID'] );
	if ( ! $p ) { return 0; }
	foreach ( $arr as $k => $v ) { $p->$k = $v; }
	if ( 'publish' === $p->post_status && '' === $p->post_name ) { $p->post_name = 'page-' . $p->ID; }
	return $p->ID;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$v = isset( $GLOBALS['RK']['meta'][ $id ][ $key ] ) ? $GLOBALS['RK']['meta'][ $id ][ $key ] : null;
	if ( null === $v ) { return $single ? '' : array(); }
	return $single ? $v : array( $v );
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['RK']['meta'][ $id ][ $key ] = wp_unslash( $value ); return true; } // unslashes, like core
function delete_post_meta( $id, $key ) { unset( $GLOBALS['RK']['meta'][ $id ][ $key ] ); return true; }
function get_post_thumbnail_id( $id ) { $v = get_post_meta( $id, '_thumbnail_id', true ); return '' === $v ? 0 : (int) $v; }
function wp_get_attachment_image_src( $id, $size = 'full' ) {
	$a = isset( $GLOBALS['RK']['attachments'][ $id ] ) ? $GLOBALS['RK']['attachments'][ $id ] : null;
	return $a ? array( $a['url'], $a['w'], $a['h'], false ) : false;
}
function wp_get_attachment_image_url( $id, $size = 'full' ) { $s = wp_get_attachment_image_src( $id, $size ); return $s ? $s[0] : false; }
function wp_get_attachment_image_srcset( $id, $size = 'full' ) { return isset( $GLOBALS['RK']['attachments'][ $id ]['srcset'] ) ? $GLOBALS['RK']['attachments'][ $id ]['srcset'] : false; }
function wp_get_object_terms( $id, $tax, $args = array() ) { return isset( $GLOBALS['RK']['terms'][ $id ][ $tax ] ) ? $GLOBALS['RK']['terms'][ $id ][ $tax ] : array(); }
function wp_create_nonce( $a = -1 ) { return 'nonce-for-user-' . get_current_user_id(); }

function rk_test_query( $args ) {
	$all = $GLOBALS['RK']['posts'];
	foreach ( $GLOBALS['RK']['attachments'] as $id => $a ) {
		if ( ! isset( $all[ $id ] ) ) { $all[ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $a['title'], 'post_name' => 'att', 'post_author' => 1, 'post_password' => '', 'menu_order' => 0, 'post_date' => '2026-01-01 00:00:00' ); }
	}
	$out = array();
	foreach ( $all as $p ) {
		if ( isset( $args['post_type'] ) && $p->post_type !== $args['post_type'] ) { continue; }
		if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && ! in_array( $p->post_status, (array) $args['post_status'], true ) ) { continue; }
		if ( isset( $args['name'] ) && $p->post_name !== $args['name'] ) { continue; }
		if ( isset( $args['author'] ) && (int) $p->post_author !== (int) $args['author'] ) { continue; }
		if ( isset( $args['s'] ) && false === stripos( $p->post_title, $args['s'] ) ) { continue; }
		if ( array_key_exists( 'has_password', $args ) && false === $args['has_password'] && '' !== (string) $p->post_password ) { continue; }
		if ( ! empty( $args['tax_query'] ) ) {
			$ok = true;
			foreach ( $args['tax_query'] as $tq ) {
				$have = isset( $GLOBALS['RK']['terms'][ $p->ID ][ $tq['taxonomy'] ] ) ? $GLOBALS['RK']['terms'][ $p->ID ][ $tq['taxonomy'] ] : array();
				if ( ! array_intersect( $tq['terms'], $have ) ) { $ok = false; }
			}
			if ( ! $ok ) { continue; }
		}
		$out[] = $p;
	}
	$ob = isset( $args['orderby'] ) ? $args['orderby'] : 'date';
	$dir = isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 1 : -1;
	if ( is_array( $ob ) ) { $first = key( $ob ); $dir = 'ASC' === $ob[ $first ] ? 1 : -1; $ob = $first; }
	usort( $out, function ( $a, $b ) use ( $ob, $dir ) {
		$k = array( 'title' => 'post_title', 'menu_order' => 'menu_order', 'modified' => 'post_modified_gmt', 'date' => 'post_date' );
		$f = isset( $k[ $ob ] ) ? $k[ $ob ] : 'post_date';
		$c = strcmp( (string) $a->$f, (string) $b->$f );
		return 0 === $c ? $a->ID - $b->ID : $dir * $c;
	} );
	$found = count( $out );
	$per   = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
	$paged = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
	return array( array_slice( $out, ( $paged - 1 ) * $per, $per ), $found );
}
function get_posts( $args ) { list( $posts ) = rk_test_query( $args ); return $posts; }

/* ---------------- REST dispatch (mini WP_REST_Server) ---------------- */

function register_rest_route( $ns, $route, $args ) {
	$handlers = isset( $args['methods'] ) ? array( $args ) : $args;
	$GLOBALS['RK']['routes'][] = array( 'ns' => $ns, 'route' => $route, 'handlers' => $handlers );
}

/**
 * Dispatch like core: match route, validate args, run permission_callback, run callback.
 *
 * @param string $method GET|POST
 * @param string $path   e.g. '/rk/v1/builder/layout/12'
 * @param array  $opts   query => array, body => array|string (array is json-encoded)
 * @return WP_REST_Response|WP_Error
 */
function rk_test_request( $method, $path, $opts = array() ) {
	foreach ( $GLOBALS['RK']['routes'] as $r ) {
		if ( ! preg_match( '#^/' . $r['ns'] . $r['route'] . '$#', $path, $m ) ) { continue; }
		foreach ( $r['handlers'] as $h ) {
			if ( $h['methods'] !== $method ) { continue; }
			$req = new WP_REST_Request( $method, $path );
			foreach ( $m as $k => $v ) { if ( is_string( $k ) ) { $req->url[ $k ] = $v; } }
			$req->query = isset( $opts['query'] ) ? $opts['query'] : array();
			if ( isset( $opts['body'] ) ) { $req->body = is_string( $opts['body'] ) ? $opts['body'] : json_encode( $opts['body'] ); }
			if ( ! empty( $h['args'] ) ) {
				foreach ( $h['args'] as $name => $spec ) {
					if ( array_key_exists( $name, $req->query ) && isset( $spec['validate_callback'] ) && ! call_user_func( $spec['validate_callback'], $req->query[ $name ], $req, $name ) ) {
						return new WP_Error( 'rest_invalid_param', 'Invalid parameter(s): ' . $name, array( 'status' => 400 ) );
					}
					if ( ! array_key_exists( $name, $req->query ) && ! array_key_exists( $name, $req->url ) && array_key_exists( 'default', $spec ) && 'GET' === $method ) { $req->query[ $name ] = $spec['default']; }
				}
			}
			$perm = call_user_func( $h['permission_callback'], $req );
			if ( is_wp_error( $perm ) ) { return $perm; }
			if ( ! $perm ) { return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => get_current_user_id() ? 403 : 401 ) ); }
			return call_user_func( $h['callback'], $req );
		}
	}
	return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
}
