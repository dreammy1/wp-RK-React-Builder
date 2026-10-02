<?php
/** Content types, fields, entries, templates, dynamic blocks and the Loop grid. */

function rk_dyn_boot() {
	rk_builder_register_content_types();
	rk_builder_register_template_type();
	$GLOBALS['RK']['attachments'][801] = array( 'url' => 'https://cms.example.com/u/a.jpg', 'w' => 800, 'h' => 600, 'title' => 'A', 'srcset' => 'https://cms.example.com/u/a.jpg 800w' );
	$GLOBALS['RK']['attachments'][802] = array( 'url' => 'https://cms.example.com/u/b.jpg', 'w' => 400, 'h' => 300, 'title' => 'B' );
	$GLOBALS['RK']['posts'][801] = (object) array( 'ID' => 801, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'A', 'post_name' => 'a', 'post_author' => 1, 'post_password' => '', 'menu_order' => 0 );
	$GLOBALS['RK']['posts'][802] = (object) array( 'ID' => 802, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'B', 'post_name' => 'b', 'post_author' => 1, 'post_password' => '', 'menu_order' => 0 );
}

function rk_dyn_def() {
	return array(
		'slug' => 'listing', 'singular' => 'Listing', 'plural' => 'Listings', 'rewrite' => 'listings',
		'taxonomies' => array( array( 'slug' => 'listing_cat', 'singular' => 'Category', 'plural' => 'Categories', 'hierarchical' => true ) ),
		'fields' => array(
			array( 'key' => 'price', 'label' => 'Price', 'type' => 'number', 'min' => 0 ),
			array( 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => array( array( 'value' => 'sale', 'label' => 'For sale' ), array( 'value' => 'sold', 'label' => 'Sold' ) ) ),
			array( 'key' => 'featured', 'label' => 'Featured', 'type' => 'toggle' ),
			array( 'key' => 'agent_email', 'label' => 'Agent email', 'type' => 'email' ),
			array( 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea' ),
			array( 'key' => 'hero', 'label' => 'Hero', 'type' => 'image' ),
			array( 'key' => 'photos', 'label' => 'Photos', 'type' => 'gallery' ),
			array( 'key' => 'specs', 'label' => 'Specs', 'type' => 'repeater', 'subfields' => array(
				array( 'key' => 'name', 'label' => 'Name', 'type' => 'text' ),
				array( 'key' => 'value', 'label' => 'Value', 'type' => 'text' ),
			) ),
		),
	);
}

/** Save the listing type as admin and register it. */
function rk_dyn_types() {
	rk_dyn_boot();
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/types', array( 'types' => array( rk_dyn_def() ) ) ) );
	rk_builder_dyn_register_types();
}

function rk_dyn_entry( $title, $fields = array(), $extra = array() ) {
	return t_ok( rk_post( '/rk/v1/builder/entries/listing', array_merge( array( 'title' => $title, 'status' => 'publish', 'fields' => $fields ), $extra ) ) )['entry'];
}

/* ---------------- types ---------------- */

rk_test( 'types: only administrators save them; any editor can read them', function () {
	rk_dyn_boot();
	t_err( rk_get( '/rk/v1/builder/types' ), 'rk_unauthorized', 401 );
	rk_test_login( 'editor' );
	t_ok( rk_get( '/rk/v1/builder/types' ) );
	t_err( rk_post( '/rk/v1/builder/types', array( 'types' => array() ) ), 'rk_forbidden', 403 );
} );

rk_test( 'types: the post, services and portfolio types are listed and can hold fields', function () {
	rk_dyn_boot();
	rk_test_login( 'admin' );
	$d = t_ok( rk_get( '/rk/v1/builder/types' ) );
	$slugs = array_map( function ( $t ) { return $t['slug']; }, $d['types'] );
	t_deep( $slugs, array( 'post', 'service', 'portfolio' ) );
	$saved = t_ok( rk_post( '/rk/v1/builder/types', array( 'types' => array( array( 'slug' => 'service', 'fields' => array( array( 'key' => 'from_price', 'label' => 'From price', 'type' => 'number' ) ) ) ) ) ) );
	$svc = array_values( array_filter( $saved['types'], function ( $t ) { return 'service' === $t['slug']; } ) )[0];
	t_eq( $svc['fields'][0]['key'], 'from_price' );
	t_assert( $svc['builtin'] );
} );

rk_test( 'types: slugs, taxonomies, fields and choices are validated', function () {
	rk_dyn_boot();
	rk_test_login( 'admin' );
	$bad = function ( array $over ) {
		$res = rk_post( '/rk/v1/builder/types', array( 'types' => array( array_merge( rk_dyn_def(), $over ) ) ) );
		t_err( $res, 'rk_invalid_types', 400 );
	};
	$bad( array( 'slug' => 'Page' ) );
	$bad( array( 'slug' => 'page' ) );
	$bad( array( 'slug' => 'rk_template' ) );
	$bad( array( 'slug' => 'a' ) );
	$bad( array( 'singular' => '' ) );
	$bad( array( 'rewrite' => 'Bad Path!' ) );
	$bad( array( 'taxonomies' => array( array( 'slug' => 'category', 'singular' => 'C', 'plural' => 'Cs' ) ) ) );
	$f = rk_dyn_def()['fields'];
	$bad( array( 'fields' => array_merge( $f, array( array( 'key' => 'price', 'label' => 'Again', 'type' => 'text' ) ) ) ) );
	$bad( array( 'fields' => array( array( 'key' => 'Bad Key', 'label' => 'x', 'type' => 'text' ) ) ) );
	$bad( array( 'fields' => array( array( 'key' => 'x', 'label' => 'x', 'type' => 'nope' ) ) ) );
	$bad( array( 'fields' => array( array( 'key' => 'x', 'label' => 'x', 'type' => 'select', 'options' => array() ) ) ) );
	$bad( array( 'fields' => array( array( 'key' => 'x', 'label' => 'x', 'type' => 'repeater', 'subfields' => array() ) ) ) );
	$bad( array( 'fields' => array( array( 'key' => 'x', 'label' => 'x', 'type' => 'repeater', 'subfields' => array( array( 'key' => 'g', 'label' => 'G', 'type' => 'gallery' ) ) ) ) ) );
	$many = array();
	for ( $i = 0; $i < 41; $i++ ) { $many[] = array( 'key' => 'f' . $i, 'label' => 'F', 'type' => 'text' ); }
	$bad( array( 'fields' => $many ) );
	t_err( rk_post( '/rk/v1/builder/types', array( 'types' => array( rk_dyn_def(), array_merge( rk_dyn_def(), array( 'taxonomies' => rk_dyn_def()['taxonomies'], 'slug' => 'other' ) ) ) ) ), 'rk_invalid_types', 400, 'a taxonomy key cannot be shared' );
} );

rk_test( 'types: saving registers the post type, its taxonomy and asks for a rewrite flush', function () {
	rk_dyn_types();
	t_assert( isset( $GLOBALS['RK']['cpt']['listing'] ), 'post type registered' );
	t_eq( $GLOBALS['RK']['cpt']['listing']['has_archive'], 'listings' );
	t_eq( $GLOBALS['RK']['cpt']['listing']['rewrite']['slug'], 'listings' );
	t_assert( isset( $GLOBALS['RK']['tax']['listing_cat'] ) );
	t_assert( ! empty( $GLOBALS['RK']['flushed'] ), 'rewrite rules flushed after the type was added' );
	$before = $GLOBALS['RK']['flushed'];
	t_ok( rk_post( '/rk/v1/builder/types', array( 'types' => array( rk_dyn_def() ) ) ) );
	rk_builder_dyn_register_types();
	t_eq( $GLOBALS['RK']['flushed'], $before, 'saving the same types again does not flush' );
} );

/* ---------------- entries ---------------- */

rk_test( 'entries: create, read back, update and list with typed values', function () {
	rk_dyn_types();
	$e = rk_dyn_entry( 'Maple House', array(
		'price' => 450000, 'status' => 'sale', 'featured' => true, 'agent_email' => 'ann@example.com', 'notes' => "Line one\n\nLine two",
		'hero' => 801, 'photos' => array( 801, array( 'id' => 802 ), 999 ),
		'specs' => array( array( 'name' => 'Beds', 'value' => '3' ), array( 'name' => '', 'value' => '' ), array( 'name' => 'Baths', 'value' => '2' ) ),
	), array( 'terms' => array( 'listing_cat' => array( 'Houses' ) ), 'image' => 802 ) );
	t_eq( $e['title'], 'Maple House' );
	t_eq( $e['status'], 'publish' );
	t_eq( $e['fields']['price'], 450000 );
	t_eq( $e['fields']['featured'], true );
	t_eq( $e['fields']['status'], 'sale' );
	t_eq( $e['fields']['hero']['id'], 801 );
	t_eq( array_map( function ( $m ) { return $m['id']; }, $e['fields']['photos'] ), array( 801, 802 ), 'unknown attachment ids are dropped' );
	t_eq( count( $e['fields']['specs'] ), 2, 'empty repeater rows are dropped' );
	t_eq( $e['fields']['specs'][1]['name'], 'Baths' );
	t_eq( $e['terms']['listing_cat'], array( 'Houses' ) );
	t_eq( $e['image']['id'], 802 );
	$got = t_ok( rk_get( '/rk/v1/builder/entry/' . $e['id'] ) )['entry'];
	t_deep( $got['fields'], $e['fields'] );
	// partial update keeps what is not sent
	$u = t_ok( rk_post( '/rk/v1/builder/entry/' . $e['id'], array( 'title' => 'Maple House II', 'fields' => array( 'price' => 460000 ) ) ) )['entry'];
	t_eq( $u['title'], 'Maple House II' );
	t_eq( $u['fields']['price'], 460000 );
	t_eq( $u['fields']['status'], 'sale' );
	t_eq( count( $u['fields']['photos'] ), 2 );
	// clearing
	$c = t_ok( rk_post( '/rk/v1/builder/entry/' . $e['id'], array( 'fields' => array( 'photos' => array(), 'hero' => null, 'featured' => false ) ) ) )['entry'];
	t_eq( $c['fields']['photos'], array() );
	t_eq( $c['fields']['hero'], null );
	t_eq( $c['fields']['featured'], false );
	$l = t_ok( rk_get( '/rk/v1/builder/entries/listing' ) );
	t_eq( $l['total'], 1 );
	t_eq( $l['items'][0]['title'], 'Maple House II' );
} );

rk_test( 'entries: bad values are refused with the field named', function () {
	rk_dyn_types();
	$post = function ( $fields, $extra = array() ) {
		return rk_post( '/rk/v1/builder/entries/listing', array_merge( array( 'title' => 'X', 'fields' => $fields ), $extra ) );
	};
	$d = t_err( $post( array( 'price' => 'abc' ) ), 'rk_invalid_entry', 400 );
	t_eq( $d['issues'][0]['path'], 'fields.price' );
	t_err( $post( array( 'price' => -5 ) ), 'rk_invalid_entry', 400, 'min' );
	t_err( $post( array( 'status' => 'nope' ) ), 'rk_invalid_entry', 400, 'select' );
	t_err( $post( array( 'agent_email' => 'not-an-email' ) ), 'rk_invalid_entry', 400, 'email' );
	t_err( $post( array( 'hero' => 12345 ) ), 'rk_invalid_entry', 400, 'image must exist' );
	t_err( $post( array( 'specs' => 'x' ) ), 'rk_invalid_entry', 400, 'repeater shape' );
	t_err( rk_post( '/rk/v1/builder/entries/listing', array( 'title' => '' ) ), 'rk_invalid_entry', 400, 'title' );
	t_err( rk_post( '/rk/v1/builder/entries/listing', array( 'title' => 'x', 'bogus' => 1 ) ), 'rk_invalid_entry', 400, 'unknown key' );
	t_err( rk_post( '/rk/v1/builder/entries/nope', array( 'title' => 'x' ) ), 'rk_not_found', 404 );
} );

rk_test( 'entries: required fields, defaults, permissions and publish rights', function () {
	rk_dyn_boot();
	rk_test_login( 'admin' );
	$def = rk_dyn_def();
	$def['fields'][0]['required'] = true;
	$def['fields'][1]['default'] = 'sale';
	t_ok( rk_post( '/rk/v1/builder/types', array( 'types' => array( $def ) ) ) );
	rk_builder_dyn_register_types();
	t_err( rk_post( '/rk/v1/builder/entries/listing', array( 'title' => 'x' ) ), 'rk_invalid_entry', 400, 'price is required' );
	$e = rk_dyn_entry( 'Ok', array( 'price' => 1 ) );
	t_eq( $e['fields']['status'], 'sale', 'default applied on create' );
	rk_test_login( 'subscriber' );
	t_err( rk_get( '/rk/v1/builder/entries/listing' ), 'rk_forbidden', 403 );
	t_err( rk_get( '/rk/v1/builder/entry/' . $e['id'] ), 'rk_forbidden', 403 );
	rk_test_login( 'anon' );
	t_err( rk_post( '/rk/v1/builder/entries/listing', array( 'title' => 'x', 'fields' => array( 'price' => 1 ) ) ), 'rk_unauthorized', 401 );
} );

rk_test( 'entries: the title is escaped, content is sanitised, duplicate and trash work', function () {
	rk_dyn_types();
	$e = rk_dyn_entry( '<b>Bold</b> & co', array(), array( 'content' => '<p>Hi</p><script>alert(1)</script>', 'excerpt' => 'Short' ) );
	t_eq( $e['title'], 'Bold & co' );
	t_assert( false === strpos( $e['content'], '<script' ), 'script removed from content' );
	$dup = t_ok( rk_post( '/rk/v1/builder/entry/' . $e['id'] . '/duplicate' ) )['entry'];
	t_eq( $dup['status'], 'draft' );
	t_assert( false !== strpos( $dup['title'], '(copy)' ) );
	t_eq( t_ok( rk_post( '/rk/v1/builder/entry/' . $dup['id'] . '/trash' ) )['ok'], true );
	t_err( rk_get( '/rk/v1/builder/entry/' . $e['id'] . '9999' ), 'rk_not_found', 404 );
} );

/* ---------------- dynamic blocks ---------------- */

function rk_dyn_block( $type, array $props, array $ctx = array() ) {
	return rk_builder_render_block( array( 'id' => 'b', 'type' => $type, 'props' => array_merge( rk_builder_dyn_block_defaults( $type ), $props ) ), $ctx );
}

rk_test( 'dynamic blocks: the source pattern and every option are validated like any block', function () {
	$ok = array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'dynfield', 'props' => rk_builder_dyn_block_defaults( 'dynfield' ) ) ) );
	t_deep( rk_builder_validate_layout( $ok, null ), array() );
	foreach ( array( 'field:Price', 'meta:x', '<script>', 'field:', 'terms:' ) as $src ) {
		$bad = $ok;
		$bad['blocks'][0]['props']['source'] = $src;
		t_assert( count( rk_builder_validate_layout( $bad, null ) ) > 0, 'rejects source ' . $src );
	}
	$bad = $ok;
	$bad['blocks'][0]['props']['tag'] = 'script';
	t_assert( count( rk_builder_validate_layout( $bad, null ) ) > 0, 'tag is an allow-list' );
	$loop = array( 'version' => 1, 'blocks' => array( array( 'id' => 'l', 'type' => 'loopgrid', 'props' => rk_builder_dyn_block_defaults( 'loopgrid' ) ) ) );
	t_deep( rk_builder_validate_layout( $loop, null ), array() );
	$loop['blocks'][0]['props']['postType'] = 'Bad Type';
	t_assert( count( rk_builder_validate_layout( $loop, null ) ) > 0 );
} );

rk_test( 'dynamic blocks: fields render typed and escaped, empty ones fall back or vanish', function () {
	rk_dyn_types();
	$e = rk_dyn_entry( 'A <i>house</i>', array( 'price' => 1234.5, 'status' => 'sold', 'featured' => true, 'agent_email' => 'ann@example.com', 'notes' => "One\n\nTwo & three", 'hero' => 801 ), array( 'excerpt' => 'Nice place' ) );
	$ctx = array( 'post' => get_post( $e['id'] ), 'type' => 'listing' );
	$title = rk_dyn_block( 'dynfield', array( 'source' => 'title', 'tag' => 'h1' ), $ctx );
	t_assert( false !== strpos( $title, '<h1 ' ) && false !== strpos( $title, 'A house' ) && false === strpos( $title, '<i>' ), 'title text escaped: ' . $title );
	t_assert( false !== strpos( rk_dyn_block( 'dynfield', array( 'source' => 'field:price', 'prefix' => '$' ), $ctx ), '$1,234.5' ) );
	t_assert( false !== strpos( rk_dyn_block( 'dynfield', array( 'source' => 'field:status' ), $ctx ), 'Sold' ), 'select shows the label' );
	t_assert( false !== strpos( rk_dyn_block( 'dynfield', array( 'source' => 'field:featured' ), $ctx ), 'Yes' ) );
	t_assert( false !== strpos( rk_dyn_block( 'dynfield', array( 'source' => 'field:agent_email' ), $ctx ), 'href="mailto:ann@example.com"' ) );
	$notes = rk_dyn_block( 'dynfield', array( 'source' => 'field:notes', 'tag' => 'span' ), $ctx );
	t_assert( 0 === strpos( $notes, '<div ' ) && false !== strpos( $notes, '<p>One</p><p>Two &amp; three</p>' ), 'block text switches to a div and keeps paragraphs: ' . $notes );
	t_assert( false !== strpos( rk_dyn_block( 'dynfield', array( 'source' => 'field:price', 'label' => 'Asking' ), $ctx ), 'Asking' ) );
	t_eq( rk_dyn_block( 'dynfield', array( 'source' => 'field:hero_missing' ), $ctx ), '', 'unknown field renders nothing' );
	t_eq( rk_dyn_block( 'dynfield', array( 'source' => 'terms:listing_cat' ), $ctx ), '', 'no terms, no output' );
	t_assert( false !== strpos( rk_dyn_block( 'dynfield', array( 'source' => 'terms:listing_cat', 'fallback' => 'Uncategorised' ), $ctx ), 'Uncategorised' ) );
	t_eq( rk_dyn_block( 'dynfield', array( 'source' => 'title' ), array() ), '', 'no entry and not the editor: nothing' );
	t_assert( false !== strpos( rk_dyn_block( 'dynfield', array( 'source' => 'title' ), array( 'sample' => true, 'type' => 'listing' ) ), 'dyn-sample' ), 'editor placeholder' );
	$linked = rk_dyn_block( 'dynfield', array( 'source' => 'title', 'link' => true ), $ctx );
	t_assert( false !== strpos( $linked, '<a href="' ) );
} );

rk_test( 'dynamic blocks: image, gallery, repeater and the details list', function () {
	rk_dyn_types();
	$e = rk_dyn_entry( 'Pine', array( 'hero' => 801, 'photos' => array( 801, 802 ), 'price' => 10, 'status' => 'sale', 'specs' => array( array( 'name' => 'Beds', 'value' => '3' ), array( 'name' => 'Baths', 'value' => '2' ) ) ), array( 'image' => 802 ) );
	$ctx = array( 'post' => get_post( $e['id'] ), 'type' => 'listing' );
	$img = rk_dyn_block( 'dynimage', array( 'source' => 'featured', 'ratio' => 'wide' ), $ctx );
	t_assert( false !== strpos( $img, 'b.jpg' ) && false !== strpos( $img, 'ratio-wide' ), $img );
	t_assert( false !== strpos( rk_dyn_block( 'dynimage', array( 'source' => 'field:hero' ), $ctx ), 'a.jpg' ) );
	$none = array( 'post' => get_post( rk_dyn_entry( 'Bare' )['id'] ), 'type' => 'listing' );
	t_eq( rk_dyn_block( 'dynimage', array(), $none ), '', 'no image, hidden' );
	t_assert( false !== strpos( rk_dyn_block( 'dynimage', array( 'fallback' => 'placeholder' ), $none ), 'card-placeholder' ) );
	$g = rk_dyn_block( 'dyngallery', array( 'source' => 'field:photos', 'cols' => 2, 'limit' => 1 ), $ctx );
	t_eq( substr_count( $g, '<figure>' ), 1, 'limit' );
	t_assert( false !== strpos( $g, 'cols-2' ) );
	t_eq( substr_count( rk_dyn_block( 'dyngallery', array( 'source' => 'field:photos' ), $ctx ), '<figure>' ), 2 );
	t_eq( rk_dyn_block( 'dyngallery', array( 'source' => 'field:photos' ), $none ), '' );
	$table = rk_dyn_block( 'dynrepeater', array( 'source' => 'field:specs', 'layout' => 'table', 'heading' => 'Specs' ), $ctx );
	t_assert( false !== strpos( $table, '<th scope="col">Name</th>' ) && false !== strpos( $table, '<td data-label="Value">2</td>' ) && false !== strpos( $table, '<h2 class="dyn-heading">Specs</h2>' ), $table );
	t_assert( false !== strpos( rk_dyn_block( 'dynrepeater', array( 'source' => 'field:specs', 'layout' => 'list' ), $ctx ), '<li><strong>Beds</strong> <span>3</span></li>' ) );
	t_assert( false !== strpos( rk_dyn_block( 'dynrepeater', array( 'source' => 'field:specs', 'layout' => 'cards', 'cols' => 2 ), $ctx ), 'dyn-cards cols-2' ) );
	t_eq( rk_dyn_block( 'dynrepeater', array( 'source' => 'field:specs' ), $none ), '' );
	$info = rk_dyn_block( 'dyninfo', array( 'sources' => 'field:price, field:status, nope, field:specs, title, field:price' ), $ctx );
	t_assert( false !== strpos( $info, '<dt>Price</dt><dd>10</dd>' ) && false !== strpos( $info, '<dt>Status</dt><dd>For sale</dd>' ), $info );
	t_eq( substr_count( $info, '<dt>' ), 2, 'repeated, unknown and title entries are skipped' );
} );

/* ---------------- templates ---------------- */

function rk_dyn_template( $kind = 'single', $extra = array() ) {
	rk_test_login( 'admin' );
	return t_ok( rk_post( '/rk/v1/builder/templates', array_merge( array( 'title' => 'T ' . $kind, 'kind' => $kind, 'postType' => 'listing' ), $extra ) ) )['item'];
}

function rk_dyn_publish_template( $id ) {
	$rev = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) )['revision'];
	return t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => $rev ) ) );
}

rk_test( 'templates: only administrators manage them; creation validates the target', function () {
	rk_dyn_types();
	rk_test_login( 'editor' );
	t_ok( rk_get( '/rk/v1/builder/templates' ) );
	t_err( rk_post( '/rk/v1/builder/templates', array( 'title' => 'x', 'kind' => 'single', 'postType' => 'listing' ) ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/templates', array( 'title' => '', 'kind' => 'single', 'postType' => 'listing' ) ), 'rk_invalid_template', 400 );
	t_err( rk_post( '/rk/v1/builder/templates', array( 'title' => 'x', 'kind' => 'footer', 'postType' => 'listing' ) ), 'rk_invalid_template', 400 );
	t_err( rk_post( '/rk/v1/builder/templates', array( 'title' => 'x', 'kind' => 'single', 'postType' => 'ghost' ) ), 'rk_invalid_template', 400 );
	t_err( rk_post( '/rk/v1/builder/templates', array( 'title' => 'x', 'kind' => 'single', 'postType' => 'listing', 'taxonomy' => 'listing_cat' ) ), 'rk_invalid_template', 400, 'taxonomy only for archives' );
	t_ok( rk_post( '/rk/v1/builder/templates', array( 'title' => 'x', 'kind' => 'archive', 'postType' => 'listing', 'taxonomy' => 'listing_cat' ) ) );
} );

rk_test( 'templates: the starter layout is built from the type\'s fields and is a valid layout', function () {
	rk_dyn_types();
	foreach ( array( 'single', 'archive', 'loop' ) as $kind ) {
		$t = rk_dyn_template( $kind );
		$d = t_ok( rk_get( '/rk/v1/builder/layout/' . $t['id'] ) );
		t_eq( $d['template']['kind'], $kind );
		t_eq( $d['template']['postType'], 'listing' );
		t_eq( $d['page']['link'], '' );
		t_deep( rk_builder_validate_layout( $d['layout'], null ), array(), $kind . ' starter is valid' );
		$types = array_map( function ( $b ) { return $b['type']; }, $d['layout']['blocks'] );
		if ( 'single' === $kind ) {
			foreach ( array( 'dynimage', 'dynfield', 'dyninfo', 'dyngallery', 'dynrepeater', 'loopgrid' ) as $need ) { t_assert( in_array( $need, $types, true ), "single starter has $need" ); }
			$info = array_values( array_filter( $d['layout']['blocks'], function ( $b ) { return 'dyninfo' === $b['type']; } ) )[0];
			t_assert( false !== strpos( $info['props']['sources'], 'field:price' ) && false === strpos( $info['props']['sources'], 'photos' ), 'info lists scalar fields only' );
		}
		if ( 'archive' === $kind ) { t_assert( in_array( 'loopgrid', $types, true ) ); }
		if ( 'loop' === $kind ) { t_deep( array_slice( $types, 0, 1 ), array( 'dynimage' ) ); }
	}
} );

rk_test( 'templates: the layout endpoints open a template but page-only routes refuse it', function () {
	rk_dyn_types();
	$t = rk_dyn_template( 'single' );
	$id = $t['id'];
	t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	t_ok( rk_get( '/rk/v1/builder/revisions/' . $id ) );
	t_err( rk_post( '/rk/v1/builder/pages/' . $id . '/front' ), 'rk_not_found', 404 );
	t_err( rk_post( '/rk/v1/builder/pages/' . $id . '/trash' ), 'rk_not_found', 404 );
	t_err( rk_post( '/rk/v1/builder/preview-token/' . $id ), 'rk_not_found', 404 );
	$list = t_ok( rk_get( '/rk/v1/builder/pages' ) );
	t_eq( count( $list['pages'] ), 0, 'templates are not pages' );
} );

rk_test( 'templates: only a published, active template is live, and one per target', function () {
	rk_dyn_types();
	$a = rk_dyn_template( 'single' );
	t_eq( rk_builder_tpl_find( 'single', 'listing' ), null, 'draft is not live' );
	rk_dyn_publish_template( $a['id'] );
	t_eq( rk_builder_tpl_find( 'single', 'listing' ), null, 'published but not active' );
	t_ok( rk_post( '/rk/v1/builder/templates/' . $a['id'] . '/update', array( 'active' => true ) ) );
	$found = rk_builder_tpl_find( 'single', 'listing' );
	t_eq( $found['id'], $a['id'] );
	t_eq( rk_builder_tpl_find( 'archive', 'listing' ), null );
	$b = rk_dyn_template( 'single' );
	rk_dyn_publish_template( $b['id'] );
	t_ok( rk_post( '/rk/v1/builder/templates/' . $b['id'] . '/update', array( 'active' => true ) ) );
	t_eq( rk_builder_tpl_find( 'single', 'listing' )['id'], $b['id'], 'the newer one is live' );
	$items = t_ok( rk_get( '/rk/v1/builder/templates' ) )['items'];
	$byId = array();
	foreach ( $items as $i ) { $byId[ $i['id'] ] = $i; }
	t_eq( $byId[ $a['id'] ]['active'], false, 'the previous one switched off' );
	t_eq( $byId[ $b['id'] ]['live'], true );
	t_ok( rk_post( '/rk/v1/builder/unpublish/' . $b['id'], array() ) );
	t_eq( rk_builder_tpl_find( 'single', 'listing' ), null, 'unpublished means off' );
} );

rk_test( 'templates: delete, and a loop template in use cannot be deleted', function () {
	rk_dyn_types();
	$loop = rk_dyn_template( 'loop' );
	rk_dyn_publish_template( $loop['id'] );
	$arch = rk_dyn_template( 'archive' );
	$d = t_ok( rk_get( '/rk/v1/builder/layout/' . $arch['id'] ) );
	$layout = $d['layout'];
	foreach ( $layout['blocks'] as $i => $b ) { if ( 'loopgrid' === $b['type'] ) { $layout['blocks'][ $i ]['props']['templateId'] = $loop['id']; } }
	t_ok( rk_save( $arch['id'], $layout, $d['revision'] ) );
	t_err( rk_post( '/rk/v1/builder/templates/' . $loop['id'] . '/delete' ), 'rk_template_in_use', 409 );
	t_eq( t_ok( rk_post( '/rk/v1/builder/templates/' . $arch['id'] . '/delete' ) )['ok'], true );
	t_eq( t_ok( rk_post( '/rk/v1/builder/templates/' . $loop['id'] . '/delete' ) )['ok'], true );
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/templates' ) )['items'] ), 0 );
} );

rk_test( 'templates: a request for an entry or archive picks the live template', function () {
	rk_dyn_types();
	$e = rk_dyn_entry( 'Maple', array( 'price' => 5 ) );
	$post = get_post( $e['id'] );
	$post->post_status = 'publish';
	rk_test_set_query( array( 'singular' => true, 'id' => $post->ID, 'object' => $post ) );
	t_eq( rk_builder_dyn_resolve_request(), null, 'no template yet: WordPress draws it' );
	$t = rk_dyn_template( 'single' );
	rk_dyn_publish_template( $t['id'] );
	t_ok( rk_post( '/rk/v1/builder/templates/' . $t['id'] . '/update', array( 'active' => true ) ) );
	$req = rk_builder_dyn_resolve_request();
	t_eq( $req['kind'], 'single' );
	t_eq( $req['post']->ID, $post->ID );
	$page = rk_test_page( 'publish', 'about' );
	rk_test_set_query( array( 'singular' => true, 'id' => $page, 'object' => get_post( $page ) ) );
	t_eq( rk_builder_dyn_resolve_request(), null, 'pages never use entry templates' );
	$GLOBALS['RK']['q'] = array( 'singular' => false, 'main' => true, 'loop' => false, 'id' => 0, 'admin' => false, 'archive' => true, 'object' => (object) array( 'name' => 'listing' ) );
	t_eq( rk_builder_dyn_resolve_request(), null, 'archive has no template' );
	$a = rk_dyn_template( 'archive' );
	rk_dyn_publish_template( $a['id'] );
	t_ok( rk_post( '/rk/v1/builder/templates/' . $a['id'] . '/update', array( 'active' => true ) ) );
	t_eq( rk_builder_dyn_resolve_request()['kind'], 'archive' );
	$draft = get_post( $e['id'] );
	$draft->post_status = 'draft';
	rk_test_set_query( array( 'singular' => true, 'id' => $draft->ID, 'object' => $draft ) );
	t_eq( rk_builder_dyn_resolve_request(), null, 'a draft entry is never templated' );
} );

/* ---------------- loop grid ---------------- */

rk_test( 'loop grid: lists entries with the card template, filters, search, pagination and related', function () {
	rk_dyn_types();
	$ids = array();
	foreach ( array( 'Alpha' => 'Houses', 'Bravo' => 'Houses', 'Charlie' => 'Land', 'Delta' => 'Land', 'Echo' => 'Houses' ) as $title => $cat ) {
		$ids[ $title ] = rk_dyn_entry( $title, array(), array( 'terms' => array( 'listing_cat' => array( $cat ) ) ) )['id'];
	}
	$draft = rk_dyn_entry( 'Hidden', array(), array( 'status' => 'draft' ) )['id'];
	$grid = function ( array $p, array $ctx = array() ) { return rk_dyn_block( 'loopgrid', $p, array_merge( array( 'type' => 'listing' ), $ctx ) ); };
	$html = $grid( array( 'postType' => 'listing', 'orderBy' => 'title', 'order' => 'asc', 'limit' => 3, 'cols' => 2, 'heading' => 'All' ) );
	t_eq( substr_count( $html, '<article class="dyn-item">' ), 3 );
	t_assert( false === strpos( $html, 'Hidden' ), 'drafts never listed' );
	t_assert( false !== strpos( $html, 'cols-2' ) && false !== strpos( $html, '<h2>All</h2>' ) );
	t_assert( strpos( $html, 'Alpha' ) < strpos( $html, 'Bravo' ), 'ordered by title' );
	t_assert( false !== strpos( $html, '<h3 ' ), 'the default card has a title heading' );
	// pagination
	$_GET['rk_page'] = '2';
	$p2 = $grid( array( 'postType' => 'listing', 'orderBy' => 'title', 'order' => 'asc', 'limit' => 3 ) );
	t_eq( substr_count( $p2, '<article class="dyn-item">' ), 2, 'second page holds the rest' );
	t_assert( false !== strpos( $p2, 'dyn-pager' ) && false !== strpos( $p2, 'aria-current="page">2<' ) );
	t_assert( false === strpos( $grid( array( 'postType' => 'listing', 'limit' => 3, 'pagination' => false ) ), 'dyn-pager' ) );
	unset( $_GET['rk_page'] );
	// filters
	$_GET['rk_term'] = 'land';
	$land = $grid( array( 'postType' => 'listing', 'filters' => true, 'orderBy' => 'title', 'order' => 'asc' ) );
	t_eq( substr_count( $land, '<article class="dyn-item">' ), 2 );
	t_assert( false !== strpos( $land, 'dyn-filters' ) && false !== strpos( $land, 'Charlie' ) && false === strpos( $land, 'Alpha' ), 'term filter' );
	$ignored = $grid( array( 'postType' => 'listing', 'filters' => false ) );
	t_eq( substr_count( $ignored, '<article class="dyn-item">' ), 5, 'the filter parameter only counts when the block has filters on' );
	unset( $_GET['rk_term'] );
	// search
	$_GET['rk_q'] = 'brav';
	t_eq( substr_count( $grid( array( 'postType' => 'listing', 'search' => true ) ), '<article class="dyn-item">' ), 1 );
	t_eq( substr_count( $grid( array( 'postType' => 'listing', 'search' => false ) ), '<article class="dyn-item">' ), 5 );
	unset( $_GET['rk_q'] );
	// fixed term
	t_eq( substr_count( $grid( array( 'postType' => 'listing', 'taxonomy' => 'listing_cat', 'term' => 'houses' ) ), '<article class="dyn-item">' ), 3 );
	// related: not itself, same category only
	$rel = $grid( array( 'postType' => 'current', 'related' => true ), array( 'post' => get_post( $ids['Alpha'] ) ) );
	t_eq( substr_count( $rel, '<article class="dyn-item">' ), 2 );
	t_assert( false === strpos( $rel, 'Alpha' ) && false !== strpos( $rel, 'Bravo' ) && false === strpos( $rel, 'Charlie' ), $rel );
	// empty, unknown type, no nesting
	t_assert( false !== strpos( $grid( array( 'postType' => 'listing', 'term' => 'none', 'taxonomy' => 'listing_cat', 'emptyText' => 'Nothing yet' ) ), 'Nothing yet' ), 'empty text' );
	t_eq( $grid( array( 'postType' => 'ghost' ) ), '', 'unknown type renders nothing publicly' );
	t_eq( $grid( array( 'postType' => 'listing' ), array( 'in_loop' => true ) ), '', 'a grid never nests in a card' );
	t_assert( false !== strpos( $grid( array( 'postType' => 'ghost' ), array( 'editor' => true ) ), 'Choose a content type' ), 'the editor is told' );
} );

rk_test( 'loop grid: a published loop template draws the cards; an unpublished one falls back', function () {
	rk_dyn_types();
	rk_dyn_entry( 'Maple', array( 'price' => 99 ) );
	$t = rk_dyn_template( 'loop' );
	$d = t_ok( rk_get( '/rk/v1/builder/layout/' . $t['id'] ) );
	$layout = array( 'version' => 1, 'blocks' => array( array( 'id' => 'f', 'type' => 'dynfield', 'props' => array_merge( rk_builder_dyn_block_defaults( 'dynfield' ), array( 'source' => 'field:price', 'prefix' => '$', 'tag' => 'div' ) ) ) ) );
	t_ok( rk_save( $t['id'], $layout, $d['revision'] ) );
	$grid = function () use ( $t ) { return rk_dyn_block( 'loopgrid', array( 'postType' => 'listing', 'templateId' => $t['id'] ), array( 'type' => 'listing' ) ); };
	t_assert( false === strpos( $grid(), '$99' ), 'a draft card template is not used' );
	rk_dyn_publish_template( $t['id'] );
	t_assert( false !== strpos( $grid(), '$99' ), 'the published card template is used' );
} );

/* ---------------- editor preview ---------------- */

rk_test( 'editor preview: renders dynamic blocks only, with a real entry or placeholders', function () {
	rk_dyn_types();
	$req = function ( $block, $extra = array() ) { return rk_post( '/rk/v1/builder/dyn/render', array_merge( array( 'block' => $block, 'postType' => 'listing' ), $extra ) ); };
	$field = array( 'type' => 'dynfield', 'props' => array_merge( rk_builder_dyn_block_defaults( 'dynfield' ), array( 'source' => 'title' ) ) );
	rk_test_login( 'anon' );
	t_err( $req( $field ), 'rk_unauthorized', 401 );
	rk_test_login( 'subscriber' );
	t_err( $req( $field ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	$sample = t_ok( $req( $field ) );
	t_eq( $sample['sample'], true );
	t_assert( false !== strpos( $sample['html'], 'dyn-sample' ), 'placeholder without entries' );
	$e = rk_dyn_entry( 'Real title' );
	$real = t_ok( $req( $field ) );
	t_eq( $real['sample'], false );
	t_assert( false !== strpos( $real['html'], 'Real title' ) );
	$other = rk_dyn_entry( 'Other one' );
	t_assert( false !== strpos( t_ok( $req( $field, array( 'sampleId' => $other['id'] ) ) )['html'], 'Other one' ), 'a chosen sample entry' );
	t_err( $req( array( 'type' => 'hero', 'props' => array() ) ), 'rk_invalid_block', 400, 'only dynamic blocks' );
	$bad = t_ok( $req( array( 'type' => 'dynfield', 'props' => array( 'source' => '<x>' ) ) ) );
	t_eq( $bad['valid'], false );
	t_eq( $bad['html'], '' );
	t_eq( t_ok( $req( $field, array( 'postType' => 'ghost' ) ) )['html'], '', 'unknown type' );
} );

/* ---------------- export / import (theme engine) ---------------- */

rk_test( 'transfer: types, templates (card template ids follow), entries and their media survive an export and an import on another site', function () {
	rk_dyn_types();
	$e = rk_dyn_entry( 'Maple', array( 'price' => 5, 'status' => 'sale', 'featured' => true, 'hero' => 801, 'photos' => array( 801, 802 ), 'specs' => array( array( 'name' => 'Beds', 'value' => '3' ) ) ), array( 'terms' => array( 'listing_cat' => array( 'Houses' ) ), 'image' => 802 ) );
	$loop = rk_dyn_template( 'loop' );
	rk_dyn_publish_template( $loop['id'] );
	$arch = rk_dyn_template( 'archive' );
	$d = t_ok( rk_get( '/rk/v1/builder/layout/' . $arch['id'] ) );
	$layout = $d['layout'];
	foreach ( $layout['blocks'] as $i => $b ) { if ( 'loopgrid' === $b['type'] ) { $layout['blocks'][ $i ]['props']['templateId'] = $loop['id']; } }
	t_ok( rk_save( $arch['id'], $layout, $d['revision'] ) );
	rk_dyn_publish_template( $arch['id'] );
	t_ok( rk_post( '/rk/v1/builder/templates/' . $arch['id'] . '/update', array( 'active' => true ) ) );
	$page = rk_test_page( 'publish', 'home' );
	rk_builder_commit_revision( $page, 'draft', array( 'version' => 1, 'blocks' => array( array( 'id' => 'g', 'type' => 'loopgrid', 'props' => array_merge( rk_builder_dyn_block_defaults( 'loopgrid' ), array( 'postType' => 'listing', 'templateId' => $loop['id'] ) ) ) ) ) );

	$bundle = rk_builder_build_site_bundle();
	t_assert( ! is_wp_error( $bundle ) );
	$bundle = json_decode( wp_json_encode( $bundle ), true );
	t_deep( array_map( function ( $t ) { return $t['slug']; }, $bundle['types'] ), array( 'listing' ) );
	t_eq( count( $bundle['templates'] ), 2);
	t_eq( count( $bundle['entries'] ), 1);
	t_eq( $bundle['entries'][0]['fields']['hero'], 801);
	t_eq( $bundle['entries'][0]['fields']['photos'], array( 801, 802 ));
	$urls = array_map( function ( $m ) { return $m['url']; }, $bundle['media'] );
	t_assert( in_array( 'https://cms.example.com/u/a.jpg', $urls, true ) && in_array( 'https://cms.example.com/u/b.jpg', $urls, true ), 'entry media travels with the bundle' );
	foreach ( $bundle['templates'] as $t ) { t_deep( rk_builder_validate_layout( $t['layout'], null ), array() ); }

	// another site: different attachment ids (the importer reuses images it already has), ids shifted so a wrong map would show
	rk_test_reset();
	rk_test_login( 'admin' );
	rk_builder_register_content_types();
	rk_builder_register_template_type();
	foreach ( array( 901 => array( 'a.jpg', 'A' ), 902 => array( 'b.jpg', 'B' ) ) as $id => $info ) {
		$GLOBALS['RK']['attachments'][ $id ] = array( 'url' => 'https://new.example.com/u/' . $info[0], 'w' => 800, 'h' => 600, 'title' => $info[1] );
		$GLOBALS['RK']['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $info[1], 'post_name' => $info[0], 'post_author' => 1, 'post_password' => '', 'menu_order' => 0 );
		update_post_meta( $id, '_rk_import_source', 'https://cms.example.com/u/' . $info[0] );
	}
	rk_test_page( 'draft', 'a' ); rk_test_page( 'draft', 'b' ); rk_test_page( 'draft', 'c' );
	t_eq( rk_builder_dyn_type( 'listing' ), null, 'the new site has no such type yet');

	$dry = t_ok( rk_builder_site_import_run( $bundle, array( 'dryRun' => true, 'theme' => true, 'content' => true, 'contentStatus' => 'publish' ) ) );
	t_eq( $dry['types']['included'], 1);
	t_eq( $dry['templates']['create'], 2);
	t_eq( $dry['entries']['included'], 1);
	t_eq( rk_builder_dyn_type( 'listing' ), null, 'a dry run changes nothing');

	$r = t_ok( rk_builder_site_import_run( $bundle, array( 'dryRun' => false, 'theme' => true, 'content' => true, 'contentStatus' => 'publish' ) ) );
	t_eq( $r['types']['applied'], true);
	t_eq( $r['templates']['create'], 2);
	t_eq( $r['entries']['created'], 1);
	t_assert( null !== rk_builder_dyn_type( 'listing' ) && isset( $GLOBALS['RK']['cpt']['listing'] ), 'the type is registered straight away' );
	t_eq( count( rk_builder_dyn_fields( 'listing' ) ), 8);

	$items = t_ok( rk_get( '/rk/v1/builder/templates' ) )['items'];
	t_eq( count( $items ), 2);
	$byKind = array();
	foreach ( $items as $i ) { $byKind[ $i['kind'] ] = $i; }
	t_eq( $byKind['loop']['live'], false, 'imported as drafts');
	$al = t_ok( rk_get( '/rk/v1/builder/layout/' . $byKind['archive']['id'] ) )['layout'];
	$tid = null;
	foreach ( $al['blocks'] as $b ) { if ( 'loopgrid' === $b['type'] ) { $tid = $b['props']['templateId']; } }
	t_eq( $tid, $byKind['loop']['id'], 'the archive points at the NEW card template');
	t_assert( $tid !== $loop['id'], 'and not the old id' );
	$pg = t_ok( rk_get( '/rk/v1/builder/pages' ) );
	t_eq( count( $pg['pages'] ), 4);
	$home = rk_builder_find_page_by_slug( 'home' );
	t_assert( $home > 0, 'the page came along' );
	$hl = rk_builder_get_draft_layout( $home );
	t_eq( $hl['blocks'][0]['props']['templateId'], $byKind['loop']['id'], 'a Loop grid on a page follows too');

	$list = t_ok( rk_get( '/rk/v1/builder/entries/listing' ) );
	t_eq( $list['total'], 1);
	$entry = t_ok( rk_get( '/rk/v1/builder/entry/' . $list['items'][0]['id'] ) )['entry'];
	t_eq( $entry['title'], 'Maple');
	t_eq( $entry['status'], 'publish');
	t_eq( $entry['fields']['price'], 5);
	t_eq( $entry['fields']['hero']['id'], 901, 'media ids are the new site\'s');
	t_eq( array_map( function ( $m ) { return $m['id']; }, $entry['fields']['photos'] ), array( 901, 902 ));
	t_eq( $entry['image']['id'], 902);
	t_eq( $entry['terms']['listing_cat'], array( 'Houses' ));
	t_eq( $entry['fields']['specs'][0]['name'], 'Beds');

	// importing again updates by slug: no duplicates
	$again = t_ok( rk_builder_site_import_run( $bundle, array( 'dryRun' => false, 'theme' => true, 'content' => true, 'contentStatus' => 'publish' ) ) );
	t_eq( $again['templates']['create'], 0);
	t_eq( $again['templates']['update'], 2);
	t_eq( $again['entries']['updated'], 1);
	t_eq( count( t_ok( rk_get( '/rk/v1/builder/templates' ) )['items'] ), 2);
	t_eq( t_ok( rk_get( '/rk/v1/builder/entries/listing' ) )['total'], 1);
} );

rk_test( 'transfer: a bundle without the theme flag brings no types or templates; a template of an unknown type is skipped', function () {
	rk_dyn_types();
	rk_dyn_template( 'single' );
	$bundle = json_decode( wp_json_encode( rk_builder_build_site_bundle() ), true );
	$bundle['templates'][0]['postType'] = 'ghost';
	rk_test_reset();
	rk_test_login( 'admin' );
	rk_builder_register_content_types();
	rk_builder_register_template_type();
	$plain = t_ok( rk_builder_site_import_run( $bundle, array( 'dryRun' => false, 'theme' => false, 'content' => false, 'contentStatus' => 'draft' ) ) );
	t_eq( $plain['types']['included'], 0 );
	t_eq( $plain['templates']['create'], 0 );
	t_eq( rk_builder_dyn_type( 'listing' ), null );
	$with = t_ok( rk_builder_site_import_run( $bundle, array( 'dryRun' => false, 'theme' => true, 'content' => false, 'contentStatus' => 'draft' ) ) );
	t_eq( $with['templates']['create'], 0 );
	t_eq( count( $with['templates']['skipped'] ), 1 );
	t_assert( false !== strpos( $with['templates']['skipped'][0]['issues'][0], 'ghost' ) );
} );
