<?php
/** Gallery block: layout options validated, classes and lightbox attributes rendered. */

function rk_gal_layout( array $props ) {
	return array( 'version' => 1, 'blocks' => array( array( 'id' => 'g', 'type' => 'gallery', 'props' => array_merge( array( 'items' => 'https://cms.example.com/a.jpg|Cat|Caption' ), $props ) ) ) );
}

rk_test( 'gallery: options are optional and validated', function () {
	t_eq( rk_builder_validate_layout( rk_gal_layout( array() ), RK_TEST_HOSTS ), array(), 'the original shape still validates' );
	t_eq( rk_builder_validate_layout( rk_gal_layout( array( 'columns' => 4, 'shape' => 'wide', 'gap' => 'lg', 'featured' => false, 'lightbox' => true, 'captions' => 'hover', 'filters' => true ) ), RK_TEST_HOSTS ), array() );
	foreach ( array( array( 'columns' => 1 ), array( 'columns' => 5 ), array( 'columns' => '3' ), array( 'shape' => 'blob' ), array( 'gap' => 'huge' ), array( 'featured' => 'yes' ), array( 'lightbox' => 1 ), array( 'captions' => 'left' ), array( 'unknown' => true ) ) as $bad ) {
		t_assert( count( rk_builder_validate_layout( rk_gal_layout( $bad ), RK_TEST_HOSTS ) ) >= 1, json_encode( $bad ) );
	}
} );

rk_test( 'gallery: the section carries a class per non-default option, and only those', function () {
	t_eq( rk_builder_gallery_classes( array( 'items' => '' ) ), 'pf-section pf-gallery' );
	t_eq( rk_builder_gallery_classes( array( 'items' => '', 'columns' => 3, 'shape' => 'rows', 'gap' => 'md', 'featured' => true, 'captions' => 'overlay', 'lightbox' => false ) ), 'pf-section pf-gallery', 'defaults print nothing' );
	t_eq( rk_builder_gallery_classes( array( 'items' => '', 'columns' => 4, 'shape' => 'square', 'gap' => 'sm', 'featured' => false, 'captions' => 'below', 'lightbox' => true ) ), 'pf-section pf-gallery cols-4 shape-square gap-sm no-feature cap-below has-lightbox' );
} );

rk_test( 'gallery: rendering honours featured, lightbox and filters', function () {
	$items = "https://cms.example.com/a.jpg|Installation|First\nhttps://cms.example.com/b.jpg|Refinishing|Second";
	$html  = rk_builder_render_gallery( array( 'items' => $items, 'filters' => true, 'featured' => false, 'lightbox' => true, 'columns' => 2 ) );
	t_assert( false !== strpos( $html, 'cols-2' ) && false !== strpos( $html, 'has-lightbox' ) && false !== strpos( $html, 'no-feature' ) );
	t_assert( false === strpos( $html, 'class="big"' ), 'no featured photo' );
	t_eq( substr_count( $html, 'tabindex="0"' ), 2, 'each photo can be reached with the keyboard' );
	t_assert( false !== strpos( $html, 'data-filter="Refinishing"' ) );
	$plain = rk_builder_render_gallery( array( 'items' => $items ) );
	t_assert( false !== strpos( $plain, 'class="big"' ), 'the first photo is large by default' );
	t_assert( false === strpos( $plain, 'tabindex' ) && false === strpos( $plain, 'has-lightbox' ) );
	t_assert( false !== strpos( rk_builder_site_script(), 'pf-lightbox' ), 'the page script carries the lightbox' );
} );

rk_test( 'gallery: automatic sources validate; the filter is a setting, not wording for the AI', function () {
	t_eq( rk_builder_validate_layout( rk_gal_layout( array( 'source' => 'media', 'limit' => 24, 'filter' => 'floor' ) ), RK_TEST_HOSTS ), array() );
	t_eq( rk_builder_validate_layout( rk_gal_layout( array( 'source' => 'portfolio', 'limit' => 6, 'filter' => 'hardwood-floors', 'items' => '' ) ), RK_TEST_HOSTS ), array() );
	foreach ( array( array( 'source' => 'flickr' ), array( 'limit' => 0 ), array( 'limit' => 41 ), array( 'limit' => '8' ), array( 'filter' => str_repeat( 'x', 81 ) ) ) as $bad ) {
		t_assert( count( rk_builder_validate_layout( rk_gal_layout( $bad ), RK_TEST_HOSTS ) ) >= 1, json_encode( $bad ) );
	}
	t_assert( ! isset( rk_builder_copy_text_props( 'gallery' )['filter'] ), 'the AI copy tool never rewrites the filter' );
	t_assert( isset( rk_builder_copy_text_props( 'hero' )['heading'] ), 'but still rewrites real wording' );
} );

rk_test( 'gallery: slug labels read like the filter buttons', function () {
	t_eq( rk_builder_gallery_slug_label( 'hardwood-floors' ), 'Hardwood Floors' );
	t_eq( rk_builder_gallery_slug_label( 'refinishing' ), 'Refinishing' );
	t_eq( rk_builder_gallery_slug_label( '' ), '' );
	t_eq( rk_builder_gallery_slug_label( 'a_b  c' ), 'A B C' );
} );
