<?php
/**
 * Dynamic blocks: they show the entry a template is drawing (title, fields, gallery, repeater, terms) and the
 * Loop grid that lists entries. The entry comes from the render context (`post`); with no entry the editor gets
 * placeholder text (`sample`) and the public site gets nothing.
 *
 * There is no React twin for these blocks: the editor asks POST /builder/dyn/render for this very markup, so what
 * you see while editing is what is published.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------------ *
 * Context
 * ------------------------------------------------------------------ */

function rk_builder_dyn_ctx_post( array $c ) { return isset( $c['post'] ) && is_object( $c['post'] ) ? $c['post'] : null; }

/** The post type a block works on: the entry's, else the template's (editor) or the request's. */
function rk_builder_dyn_ctx_type( array $c ) {
	$post = rk_builder_dyn_ctx_post( $c );
	if ( $post ) { return (string) $post->post_type; }
	if ( ! empty( $c['type'] ) && is_string( $c['type'] ) ) { return $c['type']; }
	$req = function_exists( 'rk_builder_dyn_request' ) ? rk_builder_dyn_request() : null;
	return $req && ! empty( $req['type'] ) ? (string) $req['type'] : '';
}

function rk_builder_dyn_sample( array $c ) { return ! empty( $c['sample'] ); }

/** `field:key` -> the field definition for the context's type, or null. */
function rk_builder_dyn_source_field( $source, array $c ) {
	if ( 0 !== strpos( (string) $source, 'field:' ) ) { return null; }
	return rk_builder_dyn_field( rk_builder_dyn_ctx_type( $c ), substr( $source, 6 ) );
}

/* ------------------------------------------------------------------ *
 * Values -> HTML
 * ------------------------------------------------------------------ */

function rk_builder_dyn_date_html( $ymd ) {
	$ts = strtotime( $ymd . ' 12:00:00' );
	if ( false === $ts ) { return rk_builder_h( $ymd ); }
	$fmt = (string) get_option( 'date_format', '' );
	$fmt = '' !== $fmt ? $fmt : 'F j, Y';
	return rk_builder_h( function_exists( 'date_i18n' ) ? date_i18n( $fmt, $ts ) : gmdate( $fmt, $ts ) );
}

function rk_builder_dyn_number_html( $n ) {
	if ( ! is_numeric( $n ) ) { return ''; }
	$n = $n + 0;
	return rk_builder_h( is_int( $n ) || (float) (int) $n === (float) $n ? number_format( (float) $n, 0, '.', ',' ) : rtrim( rtrim( number_format( (float) $n, 2, '.', ',' ), '0' ), '.' ) );
}

/** A media item as <img> (width/height keep the layout stable; srcset when known). */
function rk_builder_dyn_img_html( $m, $sizes = '100vw', $class = '' ) {
	if ( ! is_array( $m ) || empty( $m['url'] ) ) { return ''; }
	$src = rk_builder_src( $m['url'] );
	if ( '' === $src ) { return ''; }
	$srcset = isset( $m['srcset'] ) && is_string( $m['srcset'] ) ? $m['srcset'] : '';
	return '<img' . ( '' !== $class ? ' class="' . $class . '"' : '' ) . ' src="' . $src . '" alt="' . rk_builder_h( isset( $m['alt'] ) ? $m['alt'] : '' ) . '"'
		. ( ! empty( $m['width'] ) ? ' width="' . (int) $m['width'] . '"' : '' ) . ( ! empty( $m['height'] ) ? ' height="' . (int) $m['height'] . '"' : '' )
		. ( '' !== $srcset ? ' srcset="' . rk_builder_h( $srcset ) . '"' : '' ) . ' sizes="' . rk_builder_h( $sizes ) . '" loading="lazy" decoding="async"/>';
}

/**
 * One presented field value as inline-safe HTML ('' when empty). Block-level text (textarea) is returned as paragraphs;
 * callers that wrap it in a heading or span switch to a div (see rk_builder_dyn_is_block_value()).
 */
function rk_builder_dyn_value_html( array $f, $v ) {
	switch ( $f['type'] ) {
		case 'text':
			return '' === (string) $v ? '' : rk_builder_h( $v );
		case 'textarea':
			if ( '' === trim( (string) $v ) ) { return ''; }
			$paras = preg_split( '/\n{2,}/', str_replace( "\r", '', trim( (string) $v ) ) );
			return implode( '', array_map( function ( $p ) { return '<p>' . str_replace( "\n", '<br>', rk_builder_h( $p ) ) . '</p>'; }, $paras ) );
		case 'number':
			return rk_builder_dyn_number_html( $v );
		case 'email':
			return '' === (string) $v ? '' : '<a href="mailto:' . rk_builder_h( $v ) . '">' . rk_builder_h( $v ) . '</a>';
		case 'url':
			if ( '' === (string) $v ) { return ''; }
			$text = preg_replace( '#^https?://(www\.)?#i', '', (string) $v );
			return '<a href="' . rk_builder_href( $v ) . '">' . rk_builder_h( rtrim( (string) $text, '/' ) ) . '</a>';
		case 'date':
			return '' === (string) $v ? '' : rk_builder_dyn_date_html( (string) $v );
		case 'color':
			return 1 === preg_match( '/^#[0-9a-f]{6}\z/', (string) $v ) ? '<span class="dyn-swatch" style="background:' . $v . '"></span> ' . rk_builder_h( strtoupper( $v ) ) : '';
		case 'select':
			foreach ( $f['options'] as $o ) { if ( (string) $v === $o['value'] ) { return rk_builder_h( $o['label'] ); } }
			return '';
		case 'toggle':
			return $v ? 'Yes' : 'No';
		case 'image':
			return rk_builder_dyn_img_html( $v, '(min-width: 900px) 33vw, 100vw' );
		case 'file':
			return is_array( $v ) && ! empty( $v['url'] ) ? '<a href="' . rk_builder_href( $v['url'] ) . '" download>' . rk_builder_h( '' !== (string) $v['title'] ? $v['title'] : $v['name'] ) . '</a>' : '';
	}
	return '';
}

function rk_builder_dyn_is_block_value( array $f ) { return in_array( $f['type'], array( 'textarea', 'image' ), true ); }

/** Terms of an entry as links. */
function rk_builder_dyn_terms_html( $post, $tax, $link = true ) {
	if ( ! is_object_in_taxonomy( $post->post_type, $tax ) ) { return ''; }
	$terms = get_the_terms( $post, $tax );
	if ( ! is_array( $terms ) || ! $terms ) { return ''; }
	$out = array();
	foreach ( $terms as $t ) {
		$href = $link ? get_term_link( $t ) : '';
		$out[] = ! is_wp_error( $href ) && '' !== (string) $href ? '<a class="dyn-term" href="' . rk_builder_h( $href ) . '">' . rk_builder_h( $t->name ) . '</a>' : '<span class="dyn-term">' . rk_builder_h( $t->name ) . '</span>';
	}
	return implode( ' ', $out );
}

/** Words in an entry's text (tags and shortcodes ignored). */
function rk_builder_dyn_word_count( $post ) {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $post->post_content ) ) );
	return '' === $text ? 0 : count( explode( ' ', $text ) );
}

/** "4 min read": about 200 words a minute, never below one minute. */
function rk_builder_dyn_readtime( $post ) {
	return max( 1, (int) ceil( rk_builder_dyn_word_count( $post ) / 200 ) ) . ' min read';
}

/** A source (`title`, `field:price`, ...) resolved for the context: array( html, isBlock ). */
function rk_builder_dyn_source_html( $source, array $c, $link = false, array $opts = array() ) {
	$post = rk_builder_dyn_ctx_post( $c );
	$kind = (string) $source;
	if ( 0 === strpos( $kind, 'field:' ) ) {
		$f = rk_builder_dyn_source_field( $kind, $c );
		if ( ! $f ) { return array( '', false ); }
		if ( ! $post ) { return rk_builder_dyn_sample( $c ) ? array( '<span class="dyn-sample">' . rk_builder_h( $f['label'] ) . '</span>', false ) : array( '', false ); }
		$v = rk_builder_dyn_present( $f, rk_builder_dyn_raw( $post->ID, $f ) );
		if ( rk_builder_dyn_is_empty( $f, $v ) ) { return array( '', false ); }
		if ( in_array( $f['type'], array( 'gallery', 'repeater' ), true ) ) { return array( '', false ); }
		$html = rk_builder_dyn_value_html( $f, $v );
		if ( 'number' === $f['type'] && '' !== $html ) { $html = ( isset( $opts['prefix'] ) ? rk_builder_h( $opts['prefix'] ) : '' ) . $html . ( isset( $opts['suffix'] ) ? rk_builder_h( $opts['suffix'] ) : '' ); }
		if ( $link && $post && '' !== $html && ! rk_builder_dyn_is_block_value( $f ) && ! in_array( $f['type'], array( 'email', 'url', 'file' ), true ) ) { $html = '<a href="' . rk_builder_h( get_permalink( $post->ID ) ) . '">' . $html . '</a>'; }
		return array( $html, rk_builder_dyn_is_block_value( $f ) );
	}
	if ( ! $post ) {
		if ( ! rk_builder_dyn_sample( $c ) ) { return array( '', false ); }
		$demo = array( 'title' => 'Entry title', 'excerpt' => 'A short summary of the entry appears here.', 'content' => 'The full text of the entry appears here.', 'date' => 'Published date', 'modified' => 'Updated date', 'readtime' => '5 min read', 'author' => 'Author name' );
		if ( 0 === strpos( $kind, 'terms:' ) ) { return array( '<span class="dyn-term dyn-sample">Category</span>', false ); }
		return isset( $demo[ $kind ] ) ? array( '<span class="dyn-sample">' . $demo[ $kind ] . '</span>', 'content' === $kind ) : array( '', false );
	}
	$href = $link ? rk_builder_h( get_permalink( $post->ID ) ) : '';
	$wrap = function ( $html ) use ( $href ) { return '' !== $href && '' !== $html ? '<a href="' . $href . '">' . $html . '</a>' : $html; };
	switch ( true ) {
		case 'title' === $kind:
			return array( $wrap( rk_builder_h( rk_builder_plain( get_the_title( $post ) ) ) ), false );
		case 'excerpt' === $kind:
			return array( $wrap( rk_builder_h( rk_builder_excerpt( $post ) ) ), false );
		case 'content' === $kind:
			$body = trim( (string) preg_replace( '/<!--.*?-->/s', '', (string) $post->post_content ) );
			return array( '' === $body ? '' : wpautop( wp_kses_post( $body ) ), true );
		case 'date' === $kind:
			return array( $wrap( rk_builder_h( get_the_date( '', $post ) ) ), false );
		case 'modified' === $kind:
			return array( $wrap( rk_builder_h( get_the_modified_date( '', $post ) ) ), false );
		case 'readtime' === $kind:
			return array( rk_builder_h( rk_builder_dyn_readtime( $post ) ), false );
		case 'author' === $kind:
			return array( rk_builder_h( get_the_author_meta( 'display_name', (int) $post->post_author ) ), false );
		case 0 === strpos( $kind, 'terms:' ):
			return array( rk_builder_dyn_terms_html( $post, substr( $kind, 6 ), ! $link ), false );
	}
	return array( '', false );
}

/** The label a source has in the dashboard ("Price", "Title", ...). */
function rk_builder_dyn_source_label( $source, array $c ) {
	$names = array( 'title' => 'Title', 'excerpt' => 'Summary', 'content' => 'Description', 'date' => 'Published', 'modified' => 'Updated', 'readtime' => 'Reading time', 'author' => 'Author' );
	if ( isset( $names[ $source ] ) ) { return $names[ $source ]; }
	if ( 0 === strpos( $source, 'terms:' ) ) {
		$tx = get_taxonomy( substr( $source, 6 ) );
		return $tx && isset( $tx->labels->name ) ? (string) $tx->labels->name : ucfirst( substr( $source, 6 ) );
	}
	$f = rk_builder_dyn_source_field( $source, $c );
	return $f ? $f['label'] : '';
}

/* ------------------------------------------------------------------ *
 * Blocks
 * ------------------------------------------------------------------ */

function rk_builder_render_dynfield( array $p, array $c = array() ) {
	$kind = $p['source'];
	list( $html, $is_block ) = rk_builder_dyn_source_html( $kind, $c, $p['link'], array( 'prefix' => $p['prefix'], 'suffix' => $p['suffix'] ) );
	if ( '' === $html ) {
		if ( '' === $p['fallback'] ) { return ''; }
		$html = rk_builder_h( $p['fallback'] );
		$is_block = false;
	}
	$tag = $is_block && ! in_array( $p['tag'], array( 'div' ), true ) ? 'div' : $p['tag'];
	$cls = 'dyn-field dyn-style-' . $p['style'] . ' dyn-align-' . $p['align'] . ' dyn-src-' . preg_replace( '/[^a-z0-9]+/', '-', strtolower( strtok( $kind, ':' ) ) );
	$label = '' !== $p['label'] ? '<span class="dyn-label">' . rk_builder_h( $p['label'] ) . '</span> ' : '';
	return '<' . $tag . ' ' . rk_builder_root_attrs( 'dynfield', $cls ) . '>' . $label . $html . '</' . $tag . '>';
}

function rk_builder_render_dynimage( array $p, array $c = array() ) {
	$post = rk_builder_dyn_ctx_post( $c );
	$m    = null;
	if ( 'featured' === $p['source'] ) {
		$m = $post ? rk_builder_featured_image( $post->ID ) : null;
	} else {
		$f = rk_builder_dyn_source_field( $p['source'], $c );
		if ( $f && 'image' === $f['type'] && $post ) { $m = rk_builder_dyn_present( $f, rk_builder_dyn_raw( $post->ID, $f ) ); }
	}
	$cls = 'dyn-image ratio-' . $p['ratio'];
	$img = $m ? rk_builder_dyn_img_html( $m, '(min-width: 900px) 50vw, 100vw' ) : '';
	if ( '' === $img ) {
		if ( 'placeholder' !== $p['fallback'] && ! rk_builder_dyn_sample( $c ) ) { return ''; }
		$img = '<div class="card-placeholder" aria-hidden="true"></div>';
	}
	if ( $p['link'] && $post ) { $img = '<a href="' . rk_builder_h( get_permalink( $post->ID ) ) . '" tabindex="-1" aria-hidden="true">' . $img . '</a>'; }
	return '<figure ' . rk_builder_root_attrs( 'dynimage', $cls ) . '>' . $img . '</figure>';
}

function rk_builder_render_dyngallery( array $p, array $c = array() ) {
	$post = rk_builder_dyn_ctx_post( $c );
	$f    = rk_builder_dyn_source_field( $p['source'], $c );
	$items = array();
	if ( $f && 'gallery' === $f['type'] && $post ) { $items = rk_builder_dyn_present( $f, rk_builder_dyn_raw( $post->ID, $f ) ); }
	if ( $p['limit'] > 0 ) { $items = array_slice( $items, 0, (int) $p['limit'] ); }
	if ( ! $items ) {
		if ( ! rk_builder_dyn_sample( $c ) ) { return ''; }
		return '<div ' . rk_builder_root_attrs( 'dyngallery', 'dyn-gallery dyn-empty' ) . '><p class="dyn-sample">' . ( $f ? rk_builder_h( $f['label'] ) . ': photos appear here.' : 'Choose a gallery field.' ) . '</p></div>';
	}
	$cls  = 'dyn-gallery cols-' . (int) $p['cols'] . ' gap-' . $p['gap'] . ' ratio-' . $p['ratio'];
	$html = '';
	foreach ( $items as $m ) {
		$img = rk_builder_dyn_img_html( $m, '(min-width: 900px) ' . (int) round( 100 / max( 1, (int) $p['cols'] ) ) . 'vw, 50vw' );
		if ( '' !== $img ) { $html .= '<figure>' . $img . '</figure>'; }
	}
	return '<div ' . rk_builder_root_attrs( 'dyngallery', $cls ) . '>' . $html . '</div>';
}

function rk_builder_render_dynrepeater( array $p, array $c = array() ) {
	$post = rk_builder_dyn_ctx_post( $c );
	$f    = rk_builder_dyn_source_field( $p['source'], $c );
	$rows = array();
	if ( $f && 'repeater' === $f['type'] && $post ) { $rows = rk_builder_dyn_present( $f, rk_builder_dyn_raw( $post->ID, $f ) ); }
	$head = '' !== $p['heading'] ? '<h2 class="dyn-heading">' . rk_builder_h( $p['heading'] ) . '</h2>' : '';
	if ( ! $rows ) {
		if ( ! rk_builder_dyn_sample( $c ) ) { return ''; }
		return '<section ' . rk_builder_root_attrs( 'dynrepeater', 'dyn-repeater dyn-empty' ) . '>' . $head . '<p class="dyn-sample">' . ( $f ? rk_builder_h( $f['label'] ) . ': rows appear here.' : 'Choose a repeater field.' ) . '</p></section>';
	}
	$subs = $f['subfields'];
	$cell = function ( $sf, $row ) { return rk_builder_dyn_value_html( $sf, $row[ $sf['key'] ] ); };
	if ( 'table' === $p['layout'] ) {
		$h = '<thead><tr>';
		foreach ( $subs as $sf ) { $h .= '<th scope="col">' . rk_builder_h( $sf['label'] ) . '</th>'; }
		$h .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$h .= '<tr>';
			foreach ( $subs as $sf ) { $h .= '<td data-label="' . rk_builder_h( $sf['label'] ) . '">' . $cell( $sf, $row ) . '</td>'; }
			$h .= '</tr>';
		}
		$body = '<div class="dyn-table-wrap"><table class="dyn-table">' . $h . '</tbody></table></div>';
	} elseif ( 'cards' === $p['layout'] ) {
		$body = '<div class="dyn-cards cols-' . (int) $p['cols'] . '">';
		foreach ( $rows as $row ) {
			$body .= '<article class="dyn-card">';
			foreach ( $subs as $i => $sf ) {
				$v = $cell( $sf, $row );
				if ( '' === $v ) { continue; }
				$body .= ( 'image' === $sf['type'] || 'color' === $sf['type'] ) ? '<div class="dyn-card-media">' . $v . '</div>' : ( 0 === $i ? '<strong>' . $v . '</strong>' : '<div class="dyn-card-line"><span class="dyn-label">' . rk_builder_h( $sf['label'] ) . '</span> ' . $v . '</div>' );
			}
			$body .= '</article>';
		}
		$body .= '</div>';
	} else {
		$body = '<ul class="dyn-list">';
		foreach ( $rows as $row ) {
			$parts = array();
			foreach ( $subs as $i => $sf ) {
				$v = $cell( $sf, $row );
				if ( '' !== $v ) { $parts[] = 0 === $i ? '<strong>' . $v . '</strong>' : '<span>' . $v . '</span>'; }
			}
			$body .= '<li>' . implode( ' ', $parts ) . '</li>';
		}
		$body .= '</ul>';
	}
	return '<section ' . rk_builder_root_attrs( 'dynrepeater', 'dyn-repeater layout-' . $p['layout'] ) . '>' . $head . $body . '</section>';
}

/** Comma-separated sources that are valid `dyninfo` entries. */
function rk_builder_dyn_info_sources( $csv ) {
	$out = array();
	foreach ( explode( ',', (string) $csv ) as $s ) {
		$s = trim( $s );
		if ( 1 === preg_match( rk_builder_dyn_source_re(), $s ) && ! in_array( $s, array( 'title', 'content' ), true ) && ! in_array( $s, $out, true ) ) { $out[] = $s; }
	}
	return array_slice( $out, 0, 16 );
}

function rk_builder_render_dyninfo( array $p, array $c = array() ) {
	$rows = '';
	foreach ( rk_builder_dyn_info_sources( $p['sources'] ) as $s ) {
		list( $html ) = rk_builder_dyn_source_html( $s, $c );
		if ( '' === $html ) { continue; }
		$label = rk_builder_dyn_source_label( $s, $c );
		$rows .= '<div class="dyn-row">' . ( $p['labels'] && '' !== $label ? '<dt>' . rk_builder_h( $label ) . '</dt>' : '' ) . '<dd>' . $html . '</dd></div>';
	}
	if ( '' === $rows ) {
		if ( ! rk_builder_dyn_sample( $c ) && ! rk_builder_dyn_ctx_post( $c ) ) { return ''; }
		return rk_builder_dyn_sample( $c ) ? '<div ' . rk_builder_root_attrs( 'dyninfo', 'dyn-info dyn-empty' ) . '><p class="dyn-sample">Pick the fields to list in the block settings.</p></div>' : '';
	}
	$head = '' !== $p['heading'] ? '<h2 class="dyn-heading">' . rk_builder_h( $p['heading'] ) . '</h2>' : '';
	return '<section ' . rk_builder_root_attrs( 'dyninfo', 'dyn-info layout-' . $p['layout'] ) . '>' . $head . '<dl>' . $rows . '</dl></section>';
}

/* ------------------------------------------------------------------ *
 * Loop grid
 * ------------------------------------------------------------------ */

/** Current URL with some query args changed; '#' while rendering for the editor. */
function rk_builder_dyn_url( array $c, array $set, array $unset = array() ) {
	if ( ! empty( $c['editor'] ) ) { return '#'; }
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only used to build a link, escaped on output
	$url = remove_query_arg( array_merge( array( 'rk_page' ), $unset ), $uri );
	foreach ( $set as $k => $v ) { $url = add_query_arg( $k, $v, $url ); }
	return $url;
}

function rk_builder_dyn_get_param( $name ) {
	return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? (string) wp_unslash( $_GET[ $name ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- public read-only filter
}

/** WP_Query args for a Loop grid, or null when the type is unknown. */
function rk_builder_dyn_loop_args( array $p, array $c, &$type_out ) {
	$type = 'current' === $p['postType'] ? rk_builder_dyn_ctx_type( $c ) : $p['postType'];
	$def  = '' !== $type ? rk_builder_dyn_type( $type ) : null;
	$type_out = $type;
	if ( ! $def || ! post_type_exists( $type ) ) { return null; }
	$orderby = $p['orderBy'];
	$order   = strtoupper( $p['order'] );
	$args = array(
		'post_type' => $type, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => (int) $p['limit'],
		'orderby' => $orderby, 'order' => $order, 'ignore_sticky_posts' => true,
	);
	if ( 'menu_order' === $orderby ) { $args['orderby'] = array( 'menu_order' => $order, 'title' => 'ASC' ); }
	$tax_query = array();
	if ( '' !== $p['taxonomy'] && '' !== $p['term'] && is_object_in_taxonomy( $type, $p['taxonomy'] ) ) { $tax_query[] = array( 'taxonomy' => $p['taxonomy'], 'field' => 'slug', 'terms' => array( $p['term'] ) ); }
	// A category/term archive lists that term only.
	$req  = function_exists( 'rk_builder_dyn_request' ) ? rk_builder_dyn_request() : null;
	$term = isset( $c['term'] ) ? $c['term'] : ( $req && isset( $req['term'] ) ? $req['term'] : null );
	if ( 'current' === $p['postType'] && is_object( $term ) && isset( $term->taxonomy, $term->term_id ) ) { $tax_query[] = array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( (int) $term->term_id ) ); }
	$post = rk_builder_dyn_ctx_post( $c );
	if ( $p['related'] && $post ) {
		$args['post__not_in'] = array( (int) $post->ID );
		$shared = array();
		foreach ( rk_builder_dyn_taxonomies( $type ) as $x ) {
			$ids = wp_get_object_terms( $post->ID, $x['slug'], array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && $ids ) { $shared[] = array( 'taxonomy' => $x['slug'], 'field' => 'term_id', 'terms' => array_map( 'intval', (array) $ids ) ); }
		}
		if ( $shared ) { $tax_query[] = array_merge( array( 'relation' => 'OR' ), $shared ); }
	}
	if ( $p['filters'] ) {
		$slug = sanitize_title( rk_builder_dyn_get_param( 'rk_term' ) );
		$tx   = rk_builder_dyn_filter_taxonomy( $p, $type );
		if ( '' !== $slug && '' !== $tx ) { $tax_query[] = array( 'taxonomy' => $tx, 'field' => 'slug', 'terms' => array( $slug ) ); }
	}
	if ( $tax_query ) { $args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax_query ); }
	if ( $p['search'] ) {
		$q = trim( sanitize_text_field( rk_builder_dyn_get_param( 'rk_q' ) ) );
		if ( '' !== $q ) { $args['s'] = $q; }
	}
	$args['paged'] = $p['pagination'] ? max( 1, (int) rk_builder_dyn_get_param( 'rk_page' ) ) : 1;
	return $args;
}

function rk_builder_dyn_filter_taxonomy( array $p, $type ) {
	if ( '' !== $p['taxonomy'] && is_object_in_taxonomy( $type, $p['taxonomy'] ) ) { return $p['taxonomy']; }
	$all = rk_builder_dyn_taxonomies( $type );
	return $all ? $all[0]['slug'] : '';
}

function rk_builder_render_loopgrid( array $p, array $c = array() ) {
	if ( ! empty( $c['in_loop'] ) ) { return ''; } // no grids inside cards
	$type = '';
	$args = rk_builder_dyn_loop_args( $p, $c, $type );
	$editor = ! empty( $c['editor'] );
	if ( null === $args ) {
		return $editor ? '<div ' . rk_builder_root_attrs( 'loopgrid', 'dyn-loop dyn-empty' ) . '><p class="dyn-sample">Choose a content type for this grid.</p></div>' : '';
	}
	$def  = rk_builder_dyn_type( $type );
	$loop = $p['templateId'] > 0 ? rk_builder_tpl_loop_layout( (int) $p['templateId'] ) : null;
	if ( null === $loop ) { $loop = rk_builder_tpl_starter( 'loop', $def ); }
	$q = new WP_Query( $args );

	$eyebrow = '' !== $p['eyebrow'] ? '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>' : '';
	$title   = '' !== $p['heading'] ? '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>' : '';
	$intro   = '' !== $p['intro'] ? '<p class="grid-intro">' . rk_builder_h( $p['intro'] ) . '</p>' : '';
	$head    = '' !== $eyebrow . $title . $intro ? '<div class="grid-head"><div>' . $eyebrow . $title . $intro . '</div></div>' : '';

	$bar = '';
	if ( $p['search'] ) {
		$qv  = rk_builder_dyn_get_param( 'rk_q' );
		$hid = '' !== rk_builder_dyn_get_param( 'rk_term' ) ? '<input type="hidden" name="rk_term" value="' . rk_builder_h( sanitize_title( rk_builder_dyn_get_param( 'rk_term' ) ) ) . '">' : '';
		$bar .= '<form class="dyn-search" method="get" action="" role="search"><input type="search" name="rk_q" value="' . rk_builder_h( sanitize_text_field( $qv ) ) . '" placeholder="Search ' . rk_builder_h( strtolower( $def['plural'] ) ) . '" aria-label="Search ' . rk_builder_h( $def['plural'] ) . '">' . $hid . '<button type="submit">Search</button></form>';
	}
	if ( $p['filters'] ) {
		$tx = rk_builder_dyn_filter_taxonomy( $p, $type );
		if ( '' !== $tx ) {
			$cur   = sanitize_title( rk_builder_dyn_get_param( 'rk_term' ) );
			$terms = get_terms( array( 'taxonomy' => $tx, 'hide_empty' => true, 'number' => 40 ) );
			if ( is_array( $terms ) && $terms ) {
				$bar .= '<nav class="dyn-filters" aria-label="Filter"><a class="' . ( '' === $cur ? 'on' : '' ) . '" href="' . rk_builder_h( rk_builder_dyn_url( $c, array(), array( 'rk_term' ) ) ) . '">All</a>';
				foreach ( $terms as $t ) { $bar .= '<a class="' . ( $cur === $t->slug ? 'on' : '' ) . '" href="' . rk_builder_h( rk_builder_dyn_url( $c, array( 'rk_term' => $t->slug ) ) ) . '">' . rk_builder_h( $t->name ) . '</a>'; }
				$bar .= '</nav>';
			}
		}
	}
	$bar = '' !== $bar ? '<div class="dyn-toolbar">' . $bar . '</div>' : '';

	$items = '';
	foreach ( $q->posts as $post ) {
		$items .= '<article class="dyn-item">' . rk_builder_render_layout( $loop, array_merge( $c, array( 'post' => $post, 'in_loop' => true, 'sample' => false ) ) ) . '</article>';
	}
	if ( '' === $items ) {
		$items = '' !== $p['emptyText'] ? '<p class="dyn-empty-text">' . rk_builder_h( $p['emptyText'] ) . '</p>' : '';
		$grid  = $items;
	} else {
		$grid = '<div class="dyn-grid cols-' . (int) $p['cols'] . ' m' . (int) $p['mobileCols'] . ' gap-' . $p['gap'] . ( $p['equalHeight'] ? ' eq' : '' ) . '">' . $items . '</div>';
	}

	$pager = '';
	$pages = (int) $q->max_num_pages;
	if ( $p['pagination'] && $pages > 1 ) {
		$cur   = (int) $args['paged'];
		$pager = '<nav class="dyn-pager" aria-label="Pages">';
		if ( $cur > 1 ) { $pager .= '<a class="prev" href="' . rk_builder_h( rk_builder_dyn_url( $c, $cur - 1 > 1 ? array( 'rk_page' => $cur - 1 ) : array() ) ) . '" rel="prev">Previous</a>'; }
		for ( $i = 1; $i <= $pages && $i <= 20; $i++ ) {
			$pager .= $i === $cur ? '<span class="on" aria-current="page">' . $i . '</span>' : '<a href="' . rk_builder_h( rk_builder_dyn_url( $c, $i > 1 ? array( 'rk_page' => $i ) : array() ) ) . '">' . $i . '</a>';
		}
		if ( $cur < $pages ) { $pager .= '<a class="next" href="' . rk_builder_h( rk_builder_dyn_url( $c, array( 'rk_page' => $cur + 1 ) ) ) . '" rel="next">Next</a>'; }
		$pager .= '</nav>';
	}
	$cls = 'dyn-loop' . ( 'muted' === $p['tone'] ? ' tone-muted' : '' );
	return '<section ' . rk_builder_root_attrs( 'loopgrid', $cls ) . '>' . $head . $bar . $grid . $pager . '</section>';
}

/* ------------------------------------------------------------------ *
 * Editor preview
 * ------------------------------------------------------------------ */

function rk_builder_dyn_block_types() { return array( 'dynfield', 'dynimage', 'dyngallery', 'dynrepeater', 'dyninfo', 'loopgrid' ); }

/**
 * POST /builder/dyn/render {block:{type,props}, postType, sampleId?} -> {html, sample}
 * The editor shows this markup inside the canvas. Only dynamic block types are accepted.
 */
function rk_builder_handle_dyn_render( $req ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_block' );
	if ( is_wp_error( $body ) ) { return $body; }
	$block = isset( $body['block'] ) && is_array( $body['block'] ) ? $body['block'] : array();
	$type  = isset( $block['type'] ) && is_string( $block['type'] ) ? $block['type'] : '';
	if ( ! in_array( $type, rk_builder_dyn_block_types(), true ) || ! isset( $block['props'] ) || ! is_array( $block['props'] ) ) {
		return rk_builder_invalid( 'rk_invalid_block', array( array( 'path' => 'block', 'message' => 'Only dynamic blocks can be previewed.' ) ) );
	}
	$pt = isset( $body['postType'] ) && is_string( $body['postType'] ) ? $body['postType'] : '';
	if ( '' === $pt || null === rk_builder_dyn_type( $pt ) ) { return rk_builder_no_store( array( 'html' => '', 'sample' => true, 'valid' => true ) ); }
	$post = null;
	$sid  = isset( $body['sampleId'] ) ? (int) $body['sampleId'] : 0;
	if ( $sid > 0 ) {
		$cand = get_post( $sid );
		if ( $cand && $cand->post_type === $pt && current_user_can( 'edit_post', $cand->ID ) ) { $post = $cand; }
	}
	if ( ! $post ) {
		$found = get_posts( array( 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
		$post  = is_array( $found ) && $found ? $found[0] : null;
	}
	$ctx  = array( 'post' => $post, 'type' => $pt, 'editor' => true, 'sample' => null === $post, 'preview' => true );
	$html = rk_builder_render_block( array( 'id' => 'preview', 'type' => $type, 'props' => $block['props'] ), $ctx );
	return rk_builder_no_store( array( 'html' => $html, 'sample' => null === $post, 'valid' => null !== rk_builder_block_props( array( 'id' => 'preview', 'type' => $type, 'props' => $block['props'] ) ) ) );
}
