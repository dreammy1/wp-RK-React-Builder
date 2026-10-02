<?php
/** Stubs for the content-type / template tests: post types, taxonomies, terms, small WordPress string helpers. All guarded. */

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'attachment' ), true ) || isset( $GLOBALS['RK']['cpt'][ $t ] ); }
	function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
	function get_post_type_object( $t ) {
		if ( ! post_type_exists( $t ) ) { return null; }
		$a = isset( $GLOBALS['RK']['cpt'][ $t ] ) ? $GLOBALS['RK']['cpt'][ $t ] : array();
		$l = isset( $a['labels'] ) ? $a['labels'] : array( 'name' => ucfirst( $t ) . 's', 'singular_name' => ucfirst( $t ) );
		return (object) array( 'name' => $t, 'labels' => (object) $l, 'public' => isset( $a['public'] ) ? $a['public'] : true );
	}
	function get_taxonomy( $t ) {
		if ( ! isset( $GLOBALS['RK']['tax'][ $t ] ) ) { return false; }
		list( $o, $a ) = $GLOBALS['RK']['tax'][ $t ];
		return (object) array( 'name' => $t, 'object_type' => (array) $o, 'public' => ! isset( $a['public'] ) || $a['public'], 'hierarchical' => ! empty( $a['hierarchical'] ), 'labels' => (object) ( isset( $a['labels'] ) ? $a['labels'] : array( 'name' => ucfirst( $t ) ) ) );
	}
	function get_object_taxonomies( $type, $output = 'names' ) {
		$out = array();
		foreach ( isset( $GLOBALS['RK']['tax'] ) ? $GLOBALS['RK']['tax'] : array() as $name => $_ ) {
			$tx = get_taxonomy( $name );
			if ( in_array( $type, $tx->object_type, true ) ) { $out[ $name ] = 'objects' === $output ? $tx : $name; }
		}
		return 'objects' === $output ? $out : array_values( $out );
	}
	function is_object_in_taxonomy( $type, $tax ) { $tx = get_taxonomy( $tax ); return $tx && in_array( $type, $tx->object_type, true ); }
	function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( strip_tags( (string) $s ) ) ), '-' ); }
	function wp_set_object_terms( $id, $names, $tax, $append = false ) {
		$slugs = array();
		foreach ( (array) $names as $n ) {
			$slug = sanitize_title( $n );
			$GLOBALS['RK']['term_defs'][ $tax ][ $slug ] = $n;
			$slugs[] = $slug;
		}
		$GLOBALS['RK']['terms'][ $id ][ $tax ] = $append ? array_values( array_unique( array_merge( isset( $GLOBALS['RK']['terms'][ $id ][ $tax ] ) ? $GLOBALS['RK']['terms'][ $id ][ $tax ] : array(), $slugs ) ) ) : $slugs;
		return $slugs;
	}
	function rk_test_term_obj( $tax, $slug ) {
		return (object) array( 'slug' => $slug, 'name' => isset( $GLOBALS['RK']['term_defs'][ $tax ][ $slug ] ) ? $GLOBALS['RK']['term_defs'][ $tax ][ $slug ] : $slug, 'term_id' => rk_test_term_id( $slug ), 'taxonomy' => $tax );
	}
	function get_the_terms( $post, $tax ) {
		$id = is_object( $post ) ? $post->ID : $post;
		$out = array();
		foreach ( isset( $GLOBALS['RK']['terms'][ $id ][ $tax ] ) ? $GLOBALS['RK']['terms'][ $id ][ $tax ] : array() as $s ) { $out[] = rk_test_term_obj( $tax, $s ); }
		return $out ? $out : false;
	}
	function get_terms( $args ) {
		$tax = $args['taxonomy'];
		$seen = array();
		foreach ( isset( $GLOBALS['RK']['terms'] ) ? $GLOBALS['RK']['terms'] : array() as $by ) {
			foreach ( isset( $by[ $tax ] ) ? $by[ $tax ] : array() as $s ) { $seen[ $s ] = true; }
		}
		return array_map( function ( $s ) use ( $tax ) { return rk_test_term_obj( $tax, $s ); }, array_keys( $seen ) );
	}
	function get_term_link( $t ) { return home_url( '/' . $t->taxonomy . '/' . $t->slug . '/' ); }
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_email( $s ) { return preg_replace( '/[^a-z0-9+_.@-]/i', '', (string) $s ); }
	function is_email( $s ) { return false !== filter_var( $s, FILTER_VALIDATE_EMAIL ); }
	function wp_kses_post( $s ) { return preg_replace( '#<script\b.*?</script>#is', '', (string) $s ); }
	function wpautop( $s ) { return '<p>' . trim( (string) $s ) . "</p>\n"; }
	function wp_count_posts( $type ) {
		$c = array( 'publish' => 0, 'draft' => 0 );
		foreach ( $GLOBALS['RK']['posts'] as $p ) { if ( $p->post_type === $type && isset( $c[ $p->post_status ] ) ) { $c[ $p->post_status ]++; } }
		return (object) $c;
	}
	function set_post_thumbnail( $id, $att ) { update_post_meta( $id, '_thumbnail_id', (int) $att ); return true; }
	function delete_post_thumbnail( $id ) { delete_post_meta( $id, '_thumbnail_id' ); return true; }
	function get_the_date( $f, $p ) { return 'January 1, 2026'; }
	function get_the_modified_date( $f, $p ) { return 'January 2, 2026'; }
	function get_the_author_meta( $f, $id ) { return isset( $GLOBALS['RK']['users'][ $id ] ) ? $GLOBALS['RK']['users'][ $id ]['name'] : ''; }
	function wp_attachment_is_image( $id ) { return isset( $GLOBALS['RK']['attachments'][ $id ] ); }
	function wp_get_attachment_url( $id ) { return isset( $GLOBALS['RK']['attachments'][ $id ] ) ? $GLOBALS['RK']['attachments'][ $id ]['url'] : false; }
	function get_queried_object() { return isset( $GLOBALS['RK']['q']['object'] ) ? $GLOBALS['RK']['q']['object'] : null; }
	function is_post_type_archive( $t = '' ) { return ! empty( $GLOBALS['RK']['q']['archive'] ); }
	function date_i18n( $f, $ts ) { return gmdate( $f, $ts ); }
	function remove_query_arg( $keys, $url ) {
		$parts = explode( '?', $url, 2 );
		if ( ! isset( $parts[1] ) ) { return $url; }
		parse_str( $parts[1], $q );
		foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
		return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' );
	}
	function add_query_arg( $k, $v, $url = '' ) {
		$parts = explode( '?', $url, 2 );
		$q = array();
		if ( isset( $parts[1] ) ) { parse_str( $parts[1], $q ); }
		$q[ $k ] = $v;
		return $parts[0] . '?' . http_build_query( $q );
	}
	if ( ! function_exists( 'is_404' ) ) { function is_404() { return ! empty( $GLOBALS['RK']['q']['notfound'] ); } }
	function is_tax() { return ! empty( $GLOBALS['RK']['q']['tax'] ); }
	function is_category() { return false; }
	function is_tag() { return false; }
	function is_home() { return ! empty( $GLOBALS['RK']['q']['home'] ); }
	function get_post_type_archive_link( $t ) { return home_url( '/' . $t . 's/' ); }
}
