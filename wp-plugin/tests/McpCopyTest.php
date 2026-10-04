<?php
/** AI copy rewriting over MCP: read the wording, write it back as drafts, with the safety rules. */

function rk_copy_site() {
	rk_mcp_on();
	update_option( 'blogname', 'Acme Floors' );
	$layout = array( 'version' => 1, 'blocks' => array(
		array( 'id' => 'h', 'type' => 'hero', 'props' => array( 'heading' => 'Welcome to Acme Floors', 'sub' => 'Hardwood since 1999', 'cta' => 'Call now', 'ctaHref' => '/contact' ) ),
		array( 'id' => 'g', 'type' => 'gallery', 'props' => array( 'items' => "https://cms.example.com/a.jpg|Installation|Warm floor\nhttps://cms.example.com/b.jpg|Refinishing|Before and after", 'filters' => true ) ),
		array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => 'About Acme Floors' ) ),
	) );
	t_ok( rk_post( '/rk/v1/builder/site-import', array( 'options' => array( 'dryRun' => false ), 'bundle' => rk_bundle( array( 'media' => array(), 'pages' => array( array( 'slug' => 'home', 'title' => 'Home', 'wasPublished' => true, 'layout' => $layout, 'seo' => array( 'title' => 'Acme Floors', 'description' => 'Old description' ) ) ) ) ) ) ) );
	return rk_builder_find_page_by_slug( 'home' );
}

rk_test( 'copy: the tools exist at the right levels, and the prompt is offered', function () {
	rk_mcp_on( 'read' );
	$names = rk_mcp_names();
	t_assert( in_array( 'rkb_copy_extract', $names, true ) && in_array( 'rkb_ai_brief', $names, true ) );
	t_assert( ! in_array( 'rkb_copy_apply', $names, true ), 'writing needs the write level' );
	rk_mcp_on( 'write' );
	t_assert( in_array( 'rkb_copy_apply', rk_mcp_names(), true ) );
	$i = t_ok( rk_mcp( 'initialize' ) )['result'];
	t_assert( isset( $i['capabilities']['prompts'] ) );
	t_assert( false !== strpos( $i['instructions'], 'rkb_copy_extract' ) );
	$list = t_ok( rk_mcp( 'prompts/list' ) )['result']['prompts'];
	t_eq( $list[0]['name'], 'rewrite-site-copy' );
	t_eq( t_ok( rk_mcp( 'prompts/get', array( 'name' => 'nope' ) ) )['error']['code'], -32602 );
} );

rk_test( 'copy: the prompt carries the business name, the owner\'s brief and the safety rules', function () {
	rk_mcp_on();
	t_ok( rk_post( '/rk/v1/builder/mcp/brief', array( 'about' => 'Family flooring company in Peoria', 'tone' => 'warm', 'offers' => 'Install, sand, refinish' ) ) );
	update_option( 'blogname', 'Zed Floors' );
	$p = t_ok( rk_mcp( 'prompts/get', array( 'name' => 'rewrite-site-copy', 'arguments' => array( 'language' => 'Spanish' ) ) ) )['result'];
	$text = $p['messages'][0]['content']['text'];
	foreach ( array( '"Zed Floors"', 'Family flooring company in Peoria', 'Tone: warm', 'Write in: Spanish', 'dryRun true', 'drafts only', 'Do not invent' ) as $needle ) { t_assert( false !== strpos( $text, $needle ), $needle ); }
	$custom = t_ok( rk_mcp( 'prompts/get', array( 'name' => 'rewrite-site-copy', 'arguments' => array( 'business' => 'Quick Boards' ) ) ) )['result']['messages'][0]['content']['text'];
	t_assert( false !== strpos( $custom, '"Quick Boards"' ) );
} );

rk_test( 'copy: the brief is saved by administrators only, validated, and read by the assistant', function () {
	rk_test_login( 'editor' );
	t_err( rk_post( '/rk/v1/builder/mcp/brief', array( 'about' => 'x' ) ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/mcp/brief', array( 'surprise' => 'x' ) ), 'rk_invalid_brief', 400 );
	t_err( rk_post( '/rk/v1/builder/mcp/brief', array( 'about' => str_repeat( 'a', 601 ) ) ), 'rk_invalid_brief', 400 );
	$r = t_ok( rk_post( '/rk/v1/builder/mcp/brief', array( 'about' => '<b>Peoria</b> floors', 'audience' => 'Homeowners' ) ) );
	t_eq( $r['brief']['about'], 'Peoria floors' );
	rk_mcp_on();
	update_option( 'rk_builder_seo_org', array( 'name' => 'Acme', 'telephone' => '555-0100' ) );
	$b = rk_mcp_call( 'rkb_ai_brief' )['data'];
	t_eq( $b['brief']['audience'], 'Homeowners' );
	t_eq( $b['organization']['telephone'], '555-0100' );
	t_assert( false !== strpos( $b['note'], 'invented' ) );
	t_eq( t_ok( rk_get( '/rk/v1/builder/mcp' ) )['brief']['about'], 'Peoria floors' );
} );

rk_test( 'copy: extract lists only plain wording with its limit, marks list-style texts, pages through', function () {
	$id = rk_copy_site();
	$r  = rk_mcp_call( 'rkb_copy_extract', array( 'includeSeo' => true ) )['data'];
	$doc = null;
	foreach ( $r['documents'] as $d ) { if ( 'page' === $d['kind'] && $d['id'] === $id ) { $doc = $d; } }
	t_assert( null !== $doc );
	t_eq( $doc['seo'], array( 'title' => 'Acme Floors', 'description' => 'Old description' ) );
	$by = array();
	foreach ( $doc['blocks'] as $b ) { foreach ( $b['fields'] as $f ) { $by[ $b['blockId'] . '.' . $f['prop'] ] = $f; } }
	t_eq( $by['h.heading']['text'], 'Welcome to Acme Floors' );
	t_assert( $by['h.heading']['max'] > 0 );
	t_assert( ! isset( $by['h.ctaHref'] ), 'links are not wording' );
	t_assert( ! isset( $by['g.filters'] ), 'settings are not wording' );
	t_eq( $by['g.items']['structured'], true );
	t_assert( ! isset( $by['h.heading']['structured'] ) );
	// paging
	$one = rk_mcp_call( 'rkb_copy_extract', array( 'scope' => 'pages', 'limit' => 1 ) )['data'];
	t_eq( count( $one['documents'] ), 1 );
	t_eq( $one['total'], 1 );
	t_eq( $one['next'], null );
	$single = rk_mcp_call( 'rkb_copy_extract', array( 'kind' => 'page', 'id' => $id ) )['data'];
	t_eq( $single['documents'][0]['id'], $id );
	t_eq( rk_mcp_call( 'rkb_copy_extract', array( 'kind' => 'page', 'id' => 999999 ) )['data']['code'], 'rk_not_found' );
} );

rk_test( 'copy: apply writes drafts only, keeps what is live, and reports what it skipped and why', function () {
	$id    = rk_copy_site();
	t_ok( rk_post( '/rk/v1/builder/publish/' . $id, array( 'expectedRevision' => rk_builder_get_revision( $id ) ) ) );
	$live  = rk_builder_get_published_layout( $id );
	$edits = array(
		array( 'kind' => 'page', 'id' => $id, 'blockId' => 'h', 'prop' => 'heading', 'text' => 'Welcome to <b>Zed</b> Floors' ),
		array( 'kind' => 'page', 'id' => $id, 'blockId' => 'h', 'prop' => 'ctaHref', 'text' => 'https://evil.example/' ),
		array( 'kind' => 'page', 'id' => $id, 'blockId' => 'h', 'prop' => 'heading', 'text' => str_repeat( 'x', 5000 ) ),
		array( 'kind' => 'page', 'id' => $id, 'blockId' => 'nope', 'prop' => 'text', 'text' => 'x' ),
		array( 'kind' => 'page', 'id' => $id, 'blockId' => 't', 'prop' => 'text', 'text' => 'About Zed Floors' ),
		array( 'kind' => 'page', 'id' => $id, 'blockId' => 'g', 'prop' => 'items', 'text' => "https://cms.example.com/a.jpg|Installation|Warm floor\nhttps://cms.example.com/b.jpg|Refinishing|Before and after" ),
	);
	$dry = rk_mcp_call( 'rkb_copy_apply', array( 'edits' => $edits, 'dryRun' => true ) )['data'];
	t_eq( $dry['dryRun'], true );
	t_eq( $dry['applied'], 2 );
	t_eq( rk_builder_get_draft_layout( $id )['blocks'][0]['props']['heading'], 'Welcome to Acme Floors', 'a dry run saves nothing' );
	$reasons = implode( ' | ', array_column( $dry['skipped'], 'reason' ) );
	foreach ( array( 'not editable wording', 'Too long', 'No block with that id' ) as $needle ) { t_assert( false !== strpos( $reasons, $needle ), $needle . ' in ' . $reasons ); }

	$rev = rk_builder_get_revision( $id );
	$r   = rk_mcp_call( 'rkb_copy_apply', array( 'edits' => $edits ) )['data'];
	t_eq( $r['applied'], 2 );
	t_eq( $r['documents'][0]['saved'], true );
	$draft = rk_builder_get_draft_layout( $id );
	t_eq( $draft['blocks'][0]['props']['heading'], 'Welcome to Zed Floors', 'tags are stripped' );
	t_eq( $draft['blocks'][0]['props']['ctaHref'], '/contact', 'a link cannot be changed' );
	t_eq( $draft['blocks'][2]['props']['text'], 'About Zed Floors' );
	t_assert( rk_builder_get_revision( $id ) > $rev, 'saved as a new revision (history keeps the old one)' );
	t_deep( rk_builder_get_published_layout( $id ), $live, 'the live page is untouched until published' );
	t_assert( false !== strpos( $r['note'], 'drafts only' ) );
} );

rk_test( 'copy: list-style texts keep their lines and every link, address and colour', function () {
	$id = rk_copy_site();
	$bad = array(
		'fewer lines'    => "https://cms.example.com/a.jpg|Install|Floor",
		'changed address' => "https://cms.example.com/OTHER.jpg|Installation|Warm floor\nhttps://cms.example.com/b.jpg|Refinishing|Before and after",
		'lost a field'   => "https://cms.example.com/a.jpg|Warm floor\nhttps://cms.example.com/b.jpg|Refinishing|Before and after",
	);
	foreach ( $bad as $name => $text ) {
		$r = rk_mcp_call( 'rkb_copy_apply', array( 'edits' => array( array( 'kind' => 'page', 'id' => $id, 'blockId' => 'g', 'prop' => 'items', 'text' => $text ) ) ) )['data'];
		t_eq( $r['applied'], 0, $name );
		t_eq( count( $r['skipped'] ), 1, $name );
	}
	$ok = "https://cms.example.com/a.jpg|Montaje|Piso calido\nhttps://cms.example.com/b.jpg|Acabado|Antes y despues";
	$r  = rk_mcp_call( 'rkb_copy_apply', array( 'edits' => array( array( 'kind' => 'page', 'id' => $id, 'blockId' => 'g', 'prop' => 'items', 'text' => $ok ) ) ) )['data'];
	t_eq( $r['applied'], 1 );
	t_eq( rk_builder_get_draft_layout( $id )['blocks'][1]['props']['items'], $ok );
	t_eq( rk_builder_copy_is_locked_field( 'tel:+15551234' ), true );
	t_eq( rk_builder_copy_is_locked_field( '#c8b79a' ), true );
	t_eq( rk_builder_copy_is_locked_field( 'Warm floor' ), false );
} );

rk_test( 'copy: search title and description are saved, with limits; reusables are rewritten too; bad calls are explained', function () {
	$id = rk_copy_site();
	$r = rk_mcp_call( 'rkb_copy_apply', array( 'seo' => array( array( 'id' => $id, 'title' => 'Zed Floors in Peoria', 'description' => 'Hardwood <i>floors</i> in Peoria' ), array( 'id' => $id, 'description' => str_repeat( 'd', 500 ) ), array( 'id' => 999999, 'title' => 'x' ) ) ) )['data'];
	$seo = rk_builder_seo_read( $id );
	t_eq( $seo['title'], 'Zed Floors in Peoria' );
	t_eq( $seo['description'], 'Hardwood floors in Peoria' );
	t_assert( count( $r['skipped'] ) >= 2 );

	$rid = wp_insert_post( array( 'post_type' => RK_BUILDER_REUSABLE_TYPE, 'post_status' => 'publish', 'post_title' => 'Strip', 'post_name' => 'strip' ) );
	rk_builder_write_json_meta( $rid, '_rk_reusable_block', array( 'type' => 'text', 'props' => array( 'text' => 'Acme Floors quality' ) ) );
	$ex = rk_mcp_call( 'rkb_copy_extract', array( 'scope' => 'reusables' ) )['data'];
	t_eq( $ex['documents'][0]['kind'], 'reusable' );
	t_eq( $ex['documents'][0]['blocks'][0]['fields'][0]['text'], 'Acme Floors quality' );
	$ap = rk_mcp_call( 'rkb_copy_apply', array( 'edits' => array( array( 'kind' => 'reusable', 'id' => $rid, 'blockId' => 'block', 'prop' => 'text', 'text' => 'Zed Floors quality' ) ) ) )['data'];
	t_eq( $ap['applied'], 1 );
	t_eq( rk_builder_reusable_get( $rid )['block']['props']['text'], 'Zed Floors quality' );

	t_eq( rk_mcp_call( 'rkb_copy_apply', array() )['data']['code'], 'rk_mcp_bad_arguments' );
	$many = array_fill( 0, 301, array( 'kind' => 'page', 'id' => $id, 'blockId' => 'h', 'prop' => 'heading', 'text' => 'x' ) );
	t_eq( rk_mcp_call( 'rkb_copy_apply', array( 'edits' => $many ) )['data']['code'], 'rk_mcp_bad_arguments' );
	$bad = rk_mcp_call( 'rkb_copy_apply', array( 'edits' => array( array( 'id' => $id, 'blockId' => 'h', 'prop' => 'heading', 'text' => 'x' ) ) ) )['data'];
	t_eq( $bad['applied'], 0 );
	t_assert( false !== strpos( $bad['skipped'][0]['reason'], 'kind' ) );
} );

rk_test( 'copy: an account that may not edit pages cannot use the copy tools at all', function () {
	$id = rk_copy_site();
	rk_test_login( 'author' );
	t_err( rk_mcp( 'tools/call', array( 'name' => 'rkb_copy_apply', 'arguments' => array( 'edits' => array( array( 'kind' => 'page', 'id' => $id, 'blockId' => 'h', 'prop' => 'heading', 'text' => 'Hijacked' ) ) ) ) ), 'rk_forbidden', 403 );
	t_err( rk_mcp( 'tools/call', array( 'name' => 'rkb_copy_extract', 'arguments' => array() ) ), 'rk_forbidden', 403 );
	t_eq( rk_builder_get_draft_layout( $id )['blocks'][0]['props']['heading'], 'Welcome to Acme Floors' );
} );
