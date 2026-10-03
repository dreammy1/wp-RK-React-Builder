<?php
/** Content endpoint, CPT REST field, theme-config, media and the admin boot screen. */

function rk_make_cpt( $type, $title, $extra = array(), $cats = array(), $thumb = null ) {
	$id = rk_test_page( 'publish', sanitize_for_test( $title ) );
	$p  = $GLOBALS['RK']['posts'][ $id ];
	$p->post_type = $type;
	$p->post_title = $title;
	foreach ( $extra as $k => $v ) { $p->$k = $v; }
	if ( $cats ) { $GLOBALS['RK']['terms'][ $id ][ 'service' === $type ? 'service_cat' : 'portfolio_cat' ] = $cats; }
	if ( $thumb ) { $GLOBALS['RK']['meta'][ $id ]['_thumbnail_id'] = (string) $thumb; }
	return $id;
}
function sanitize_for_test( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $s ) ); }

rk_test( 'CPTs and both category taxonomies are registered', function () {
	rk_builder_register_content_types();
	t_assert( isset( $GLOBALS['RK']['cpt']['service'], $GLOBALS['RK']['cpt']['portfolio'] ) );
	t_assert( isset( $GLOBALS['RK']['tax']['service_cat'], $GLOBALS['RK']['tax']['portfolio_cat'] ) );
	t_eq( $GLOBALS['RK']['tax']['service_cat'][0], 'service' );
} );

rk_test( 'content: only published, no passwords, shape, excerpt is plain text, image object', function () {
	$GLOBALS['RK']['attachments'][901] = array( 'url' => 'https://cms.example.com/u/s.jpg', 'w' => 640, 'h' => 480, 'title' => 's', 'srcset' => 'https://cms.example.com/u/s.jpg 640w' );
	$GLOBALS['RK']['meta'][901]['_wp_attachment_image_alt'] = 'A <b>wire</b>';
	rk_make_cpt( 'service', 'Wiring', array( 'post_excerpt' => '<p>Safe &amp; <b>sound</b></p>', 'menu_order' => 2 ), array( 'residential' ), 901 );
	rk_make_cpt( 'service', 'Solar', array( 'post_content' => '<p>Panels [gallery] on roofs</p>', 'menu_order' => 1 ) );
	rk_make_cpt( 'service', 'Draft one', array( 'post_status' => 'draft' ) );
	rk_make_cpt( 'service', 'Hidden', array( 'post_password' => 'x' ) );
	rk_make_cpt( 'portfolio', 'Project', array() );
	$res = rk_get( '/rk/v1/content/service' );
	$d   = t_ok( $res );
	t_eq( $d['total'], 2 );
	t_eq( count( $d['items'] ), 2 );
	t_eq( array_keys( $d['items'][0] ), array( 'id', 'title', 'excerpt', 'link', 'categories', 'image' ) );
	$byTitle = array_column( $d['items'], null, 'title' );
	t_eq( $byTitle['Wiring']['excerpt'], 'Safe & sound' );
	t_eq( $byTitle['Solar']['excerpt'], 'Panels on roofs' );
	t_eq( $byTitle['Wiring']['categories'], array( 'residential' ) );
	t_eq( $byTitle['Wiring']['image'], array( 'url' => 'https://cms.example.com/u/s.jpg', 'width' => 640, 'height' => 480, 'alt' => 'A wire', 'srcset' => 'https://cms.example.com/u/s.jpg 640w' ) );
	t_eq( $byTitle['Solar']['image'], null );
	t_eq( $res->get_headers()['Cache-Control'], 'public, max-age=0, s-maxage=60' );
} );

rk_test( 'content: category filter, ordering, limit', function () {
	rk_make_cpt( 'portfolio', 'B job', array( 'menu_order' => 2, 'post_date' => '2026-02-01 00:00:00' ), array( 'residential' ) );
	rk_make_cpt( 'portfolio', 'A job', array( 'menu_order' => 3, 'post_date' => '2026-03-01 00:00:00' ), array( 'commercial' ) );
	rk_make_cpt( 'portfolio', 'C job', array( 'menu_order' => 1, 'post_date' => '2026-01-15 00:00:00' ), array( 'residential' ) );
	t_eq( t_ok( rk_get( '/rk/v1/content/portfolio', array( 'category' => 'residential' ) ) )['total'], 2 );
	t_eq( array_column( t_ok( rk_get( '/rk/v1/content/portfolio', array( 'orderby' => 'title', 'order' => 'asc' ) ) )['items'], 'title' ), array( 'A job', 'B job', 'C job' ) );
	t_eq( array_column( t_ok( rk_get( '/rk/v1/content/portfolio', array( 'orderby' => 'menu_order', 'order' => 'asc' ) ) )['items'], 'title' ), array( 'C job', 'B job', 'A job' ) );
	t_eq( array_column( t_ok( rk_get( '/rk/v1/content/portfolio', array( 'orderby' => 'date', 'order' => 'desc' ) ) )['items'], 'title' ), array( 'A job', 'B job', 'C job' ) );
	$d = t_ok( rk_get( '/rk/v1/content/portfolio', array( 'limit' => '2' ) ) );
	t_eq( count( $d['items'] ), 2 );
	t_eq( $d['total'], 3 );
	t_eq( t_ok( rk_get( '/rk/v1/content/portfolio', array( 'category' => 'residential', 'orderby' => 'title', 'order' => 'DESC' ) ) )['items'][0]['title'], 'C job' );
} );

rk_test( 'content: unknown type 404; bad params 400 (standard rest_invalid_param)', function () {
	t_err( rk_get( '/rk/v1/content/page' ), 'rk_not_found', 404 );
	t_err( rk_get( '/rk/v1/content/post' ), 'rk_not_found', 404 );
	foreach ( array( array( 'limit' => '0' ), array( 'limit' => '25' ), array( 'limit' => 'x' ), array( 'limit' => '-1' ), array( 'orderby' => 'rand' ), array( 'orderby' => 'ID' ), array( 'order' => 'sideways' ), array( 'category' => 'Bad Slug!' ), array( 'category' => str_repeat( 'a', 61 ) ) ) as $q ) {
		t_err( rk_get( '/rk/v1/content/service', $q ), 'rest_invalid_param', 400, json_encode( $q ) );
	}
	// handler is safe even when route-level validation is bypassed
	$req = new WP_REST_Request( 'GET', '' ); $req->url['type'] = 'service'; $req->query = array( 'limit' => '999' );
	t_err( rk_builder_handle_content( $req ), 'rest_invalid_param', 400 );
} );

rk_test( 'core CPT REST responses get featured_image next to featured_image_url', function () {
	rk_builder_register_rest_fields();
	foreach ( array( 'service', 'portfolio' ) as $t ) { t_assert( isset( $GLOBALS['RK']['fields'][ $t ]['featured_image'], $GLOBALS['RK']['fields'][ $t ]['featured_image_url'] ) ); }
	$GLOBALS['RK']['attachments'][902] = array( 'url' => 'https://cms.example.com/u/p.jpg', 'w' => 10, 'h' => 20, 'title' => 'p' );
	$id = rk_make_cpt( 'portfolio', 'P', array(), array(), 902 );
	$f  = $GLOBALS['RK']['fields']['portfolio'];
	t_eq( call_user_func( $f['featured_image']['get_callback'], array( 'id' => $id ) ), array( 'url' => 'https://cms.example.com/u/p.jpg', 'width' => 10, 'height' => 20, 'alt' => '' ) );
	t_eq( call_user_func( $f['featured_image_url']['get_callback'], array( 'id' => $id ) ), 'https://cms.example.com/u/p.jpg' );
	$none = rk_make_cpt( 'portfolio', 'Q' );
	t_eq( call_user_func( $f['featured_image']['get_callback'], array( 'id' => $none ) ), null );
} );

/* ---------------- theme-config ---------------- */

rk_test( 'theme-config GET is public, returns defaults, and is cacheable', function () {
	$res = rk_get( '/rk/v1/theme-config' );
	t_eq( t_ok( $res ), array( 'version' => 1, 'primary' => '#C7F36B', 'bg' => '#F8F5ED', 'ink' => '#1B2430', 'font' => 'Space Grotesk' ) );
	t_assert( isset( $res->get_headers()['Cache-Control'] ) );
} );

rk_test( 'theme-config GET migrates the prototype shape (logo string, font Inter, loose social)', function () {
	update_option( 'rk_theme_config', array(
		'primary' => '#2f6df6', 'bg' => '#ffffff', 'ink' => '#141a22', 'font' => 'Inter',
		'logo' => 'https://cms.example.com/logo.png', 'social' => array( 'instagram' => 'https://instagram.com/x', 'twitter' => 'https://t.example', 'linkedin' => 'javascript:x' ),
		'header' => array( 'sticky' => true ), 'footer' => array( 'columns' => 3 ),
	) );
	$d = t_ok( rk_get( '/rk/v1/theme-config' ) );
	t_eq( $d['version'], 1 );
	t_eq( $d['primary'], '#2f6df6' );
	t_eq( $d['font'], 'Space Grotesk', 'unsupported font falls back' );
	t_eq( $d['logoUrl'], 'https://cms.example.com/logo.png' );
	t_assert( ! array_key_exists( 'logo', $d ) );
	t_eq( $d['social'], array( 'instagram' => 'https://instagram.com/x' ), 'unsafe/unknown social entries dropped on migrate' );
	t_eq( $d['header'], array( 'sticky' => true ) );
	t_eq( $d['footer'], array( 'columns' => 3 ) );
	t_eq( rk_builder_validate_theme( json_decode( json_encode( $d ), true ), null ), array(), 'migrated theme is a valid v1 theme' );
	// garbage in the option never breaks the endpoint
	update_option( 'rk_theme_config', 'garbage' );
	t_eq( t_ok( rk_get( '/rk/v1/theme-config' ) )['font'], 'Space Grotesk' );
} );

rk_test( 'theme-config POST: admin only, strict validation, returns {ok,theme}; editor 403', function () {
	$theme = fx( 'valid/theme-full.json' )['document'];
	rk_test_login( 'editor' );
	t_err( rk_post( '/rk/v1/theme-config', $theme ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	$d = t_ok( rk_post( '/rk/v1/theme-config', $theme ) );
	t_eq( $d['ok'], true );
	t_deep( $d['theme'], $theme );
	t_deep( t_ok( rk_get( '/rk/v1/theme-config' ) ), $theme );
	foreach ( fx_files( 'invalid' ) as $file ) {
		$fx = json_decode( file_get_contents( $file ), true );
		if ( 'theme' !== $fx['kind'] ) { continue; }
		t_err( rk_post( '/rk/v1/theme-config', $fx['document'] ), 'rk_invalid_theme', 400, basename( $file ) );
	}
	t_err( rk_post( '/rk/v1/theme-config', array_merge( $theme, array( 'logoUrl' => 'https://evil.example/l.png' ) ) ), 'rk_invalid_theme', 400, 'foreign logo host' );
	t_deep( t_ok( rk_get( '/rk/v1/theme-config' ) ), $theme, 'rejected posts changed nothing' );
} );

rk_test( 'theme with empty sub-objects round-trips as {} not []', function () {
	rk_test_login( 'admin' );
	$theme = fx( 'valid/theme-minimal.json' )['document'];
	$theme['social'] = array(); $theme['header'] = array();
	t_ok( rk_post( '/rk/v1/theme-config', json_encode( $theme ) ) );
	$json = json_encode( t_ok( rk_get( '/rk/v1/theme-config' ) ) );
	t_assert( false !== strpos( $json, '"social":{}' ) && false !== strpos( $json, '"header":{}' ), $json );
} );

/* ---------------- media ---------------- */

rk_test( 'media: images only, documented shape, upload_files required', function () {
	$GLOBALS['RK']['attachments'][910] = array( 'url' => 'https://cms.example.com/u/m.jpg', 'w' => 1200, 'h' => 800, 'title' => 'Crew', 'srcset' => 'https://cms.example.com/u/m-600.jpg 600w' );
	$GLOBALS['RK']['meta'][910]['_wp_attachment_image_alt'] = 'Crew at work';
	rk_test_login( 'editor' );
	$d = t_ok( rk_get( '/rk/v1/builder/media' ) );
	t_eq( $d['items'], array( array( 'id' => 910, 'url' => 'https://cms.example.com/u/m.jpg', 'alt' => 'Crew at work', 'title' => 'Crew', 'width' => 1200, 'height' => 800, 'srcset' => 'https://cms.example.com/u/m-600.jpg 600w' ) ) );
	t_err( rk_get( '/rk/v1/builder/media', array( 'per_page' => '51' ) ), 'rest_invalid_param', 400 );
	rk_test_login( 'subscriber' );
	t_err( rk_get( '/rk/v1/builder/media' ), 'rk_forbidden', 403 );
} );

/* ---------------- admin screen ---------------- */

rk_test( 'admin menu is registered for edit_pages', function () {
	rk_builder_register_admin_menu();
	t_eq( $GLOBALS['RK']['menu'][0]['cap'], 'edit_pages' );
	t_eq( $GLOBALS['RK']['menu'][0]['menu'], 'RK Builder' );
} );

rk_test( 'admin boot: nonce only printed for logged-in users with edit_pages; JSON is script-safe', function () {
	rk_test_login( 'anon' );
	t_eq( rk_builder_standalone_document(), '' );
	rk_test_login( 'subscriber' );
	t_eq( rk_builder_standalone_document(), '' );
	t_assert( false !== strpos( ( function () { try { rk_builder_render_admin_page(); } catch ( Exception $e ) { return $e->getMessage(); } } )(), 'wp_die' ), 'denied users get wp_die' );
	rk_test_login( 'editor' );
	$html = rk_builder_standalone_document();
	t_assert( false !== strpos( $html, '<div id="root">' ) );
	t_assert( false !== strpos( $html, 'window.RK_BUILDER_BOOT = ' ) );
	t_assert( false !== strpos( $html, '"mode":"nonce"' ) && false !== strpos( $html, '"nonce":"nonce-for-user-2"' ) );
	t_assert( false !== strpos( $html, 'https:\/\/cms.example.com\/wp-json\/rk\/v1\/' ) );
	// app URL: constant/option/filter, http(s) only, escaped
	t_assert( false === strpos( $html, 'type="module"' ), 'no app URL configured -> no script tag' );
	update_option( 'rk_builder_app_url', 'https://app.example.com/assets/index.js?x=1&y="2"' );
	$html = rk_builder_standalone_document();
	t_assert( false !== strpos( $html, '<script type="module" crossorigin src="https://app.example.com/assets/index.js?x=1&amp;y=&quot;2&quot;"></script>' ), $html );
	update_option( 'rk_builder_app_url', 'javascript:alert(1)' );
	t_assert( false === strpos( rk_builder_standalone_document(), 'javascript:' ) );
	// a hostile apiBase/nonce cannot break out of the script element
	add_filter( 'rk_builder_app_url', function () { return 'https://a.example/</script><script>alert(1)'; } );
	t_assert( false === strpos( rk_builder_standalone_document(), '</script><script>alert' ) );
} );

rk_test( 'media: alt, title, caption and description are edited with limits; the list can show details and only images missing alt text; delete needs the right and removes the file', function () {
	$GLOBALS['RK']['attachments'][920] = array( 'url' => 'https://cms.example.com/u/n.jpg', 'w' => 600, 'h' => 400, 'title' => 'n' );
	$GLOBALS['RK']['posts'][920] = (object) array( 'ID' => 920, 'post_type' => 'attachment', 'post_title' => 'n', 'post_excerpt' => '', 'post_content' => '', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' );
	rk_test_login( 'editor' );
	$u = t_ok( rk_post( '/rk/v1/builder/media/920', array( 'alt' => 'Sanded <b>oak</b> floor', 'title' => 'Oak floor', 'caption' => 'After refinishing', 'description' => 'A   long description.' ) ) )['item'];
	t_eq( $u['alt'], 'Sanded oak floor', 'tags stripped' );
	t_eq( $u['title'], 'Oak floor' );
	t_eq( $u['caption'], 'After refinishing' );
	t_eq( $u['description'], 'A long description.', 'whitespace collapsed' );
	t_eq( $GLOBALS['RK']['meta'][920]['_wp_attachment_image_alt'], 'Sanded oak floor' );
	t_eq( isset( t_ok( rk_get( '/rk/v1/builder/media' ) )['items'][0]['caption'] ), false, 'the picker keeps the documented shape' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/media', array( 'detail' => '1' ) ) )['items'][0]['caption'], 'After refinishing', 'details on request' );
	t_err( rk_post( '/rk/v1/builder/media/920', array( 'alt' => array( 'x' ) ) ), 'rk_invalid_media', 400 );
	t_err( rk_post( '/rk/v1/builder/media/920', array( 'bogus' => 'x' ) ), 'rk_invalid_media', 400 );
	t_ok( rk_post( '/rk/v1/builder/media/920', array( 'alt' => '' ) ) );
	t_eq( isset( $GLOBALS['RK']['meta'][920]['_wp_attachment_image_alt'] ), false, 'empty alt clears the meta' );
	t_err( rk_post( '/rk/v1/builder/media/9999', array( 'alt' => 'x' ) ), 'rk_not_found', 404 );
	$d = t_ok( rk_post( '/rk/v1/builder/media/920/delete', array() ) );
	t_eq( $d['deleted'], 920 );
	t_eq( isset( $GLOBALS['RK']['posts'][920] ), false, 'gone' );
	rk_test_login( 'subscriber' );
	t_err( rk_post( '/rk/v1/builder/media/910', array( 'alt' => 'x' ) ), 'rk_forbidden', 403 );
} );
