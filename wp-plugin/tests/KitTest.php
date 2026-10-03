<?php
/** Site kits: one zip with a manifest, the site data and the pictures; the fuller export that feeds it. */

if ( ! function_exists( 'wp_tempnam' ) ) { function wp_tempnam( $f = '' ) { return tempnam( sys_get_temp_dir(), 'rkt' ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); } }
if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/rk-kit-test-uploads' ); } }

// Core's taxonomy API is not stubbed: without it terms are skipped (the category step is covered against real WordPress).
if ( ! function_exists( 'taxonomy_exists' ) ) { function taxonomy_exists( $t ) { return false; } }

function rk_kit_manifest( array $over = array() ) {
	return array_merge( array( 'format' => 'rk-builder-kit', 'formatVersion' => 1, 'name' => 'Studio Kit', 'description' => 'A studio', 'version' => '1.2.0', 'author' => 'RK', 'requires' => array( 'plugin' => '1.0.0' ), 'counts' => array( 'images' => 1 ) ), $over );
}

/** Write a kit zip to a temp file. $entries: name => contents. */
function rk_kit_zip( array $entries ) {
	$p = tempnam( sys_get_temp_dir(), 'rkz' );
	$z = new ZipArchive();
	$z->open( $p, ZipArchive::OVERWRITE | ZipArchive::CREATE );
	foreach ( $entries as $n => $c ) { $z->addFromString( $n, $c ); }
	$z->close();
	return $p;
}

function rk_kit_good_entries( array $manifest = array(), array $extra = array() ) {
	$b = rk_bundle( array( 'media' => array( array( 'id' => 77, 'url' => 'https://old.example.com/wp-content/uploads/a.jpg', 'alt' => 'A', 'title' => 'A', 'file' => 'images/77-a.jpg' ) ) ) );
	return array_merge( array( 'manifest.json' => json_encode( rk_kit_manifest( $manifest ) ), 'site.json' => json_encode( $b ), 'images/77-a.jpg' => 'not-really-a-jpeg' ), $extra );
}

rk_test( 'kit: only manifest, site data and pictures are allowed; junk from file managers is ignored', function () {
	foreach ( array( 'manifest.json', 'site.json', 'images/77-a.jpg', 'images/hero_1.webp' ) as $ok ) { t_assert( rk_builder_kit_entry_ok( $ok ), $ok ); }
	foreach ( array( '../evil.php', 'images/../evil.php', 'images/sub/a.jpg', '/etc/passwd', 'shell.php', 'images/.htaccess', 'images/' . str_repeat( 'a', 130 ) . '.jpg', 'site.json.php', "images/a.jpg\0.php" ) as $bad ) { t_assert( ! rk_builder_kit_entry_ok( $bad ), $bad ); }
	t_assert( rk_builder_kit_entry_ignored( '__MACOSX/._site.json' ) );
	t_assert( rk_builder_kit_entry_ignored( 'images/' ) );
	t_assert( rk_builder_kit_entry_ignored( 'images/.DS_Store' ) );
	t_assert( ! rk_builder_kit_entry_ignored( 'site.json' ) );
} );

rk_test( 'kit: picture names inside the zip are safe and start with the id', function () {
	t_eq( rk_builder_kit_image_name( 77, 'Hero Image (1).JPG' ), 'images/77-Hero-Image-1-.JPG' );
	t_eq( rk_builder_kit_image_name( 5, '../../x.jpg' ), 'images/5-x.jpg' );
	t_eq( rk_builder_kit_image_name( 5, '...' ), 'images/5-image' );
	t_assert( rk_builder_kit_entry_ok( rk_builder_kit_image_name( 9, 'ünïcode name.png' ) ) );
	t_assert( rk_builder_kit_entry_ok( rk_builder_kit_image_name( 9, str_repeat( 'x', 300 ) . '.png' ) ) );
} );

rk_test( 'kit: manifest problems — wrong format, newer format, newer plugin required', function () {
	t_eq( rk_builder_kit_manifest_problem( rk_kit_manifest(), '1.25.0' ), '' );
	t_assert( '' !== rk_builder_kit_manifest_problem( array(), '1.25.0' ) );
	t_assert( '' !== rk_builder_kit_manifest_problem( rk_kit_manifest( array( 'format' => 'rk-builder-site' ) ), '1.25.0' ) );
	t_assert( '' !== rk_builder_kit_manifest_problem( rk_kit_manifest( array( 'formatVersion' => 2 ) ), '1.25.0' ) );
	$m = rk_builder_kit_manifest_problem( rk_kit_manifest( array( 'requires' => array( 'plugin' => '1.26.0' ) ) ), '1.25.0' );
	t_assert( false !== strpos( $m, '1.26.0' ) );
	t_eq( rk_builder_kit_manifest_problem( rk_kit_manifest( array( 'requires' => array( 'plugin' => 'garbage; drop' ) ) ), '1.25.0' ), '', 'a malformed requirement is ignored, not trusted' );
} );

rk_test( 'kit: meta is cleaned (text only, demo must be http(s))', function () {
	$m = rk_builder_kit_meta_clean( array( 'name' => '<b>Studio</b>', 'industry' => 'Interior <i>design</i>', 'license' => 'Regular', 'demo' => 'javascript:alert(1)', 'version' => '2.0.0' ) );
	t_eq( $m['name'], 'Studio' );
	t_eq( $m['industry'], 'Interior design' );
	t_eq( $m['demo'], '' );
	t_eq( rk_builder_kit_meta_clean( array( 'demo' => 'https://demo.example.com/studio' ) )['demo'], 'https://demo.example.com/studio' );
} );

rk_test( 'kit: a good zip opens; counts its pictures; bundle and manifest come back', function () {
	$p = rk_kit_zip( rk_kit_good_entries() );
	$k = rk_builder_kit_open( $p );
	t_assert( ! is_wp_error( $k ), is_wp_error( $k ) ? $k->get_error_message() : '' );
	t_eq( $k['images'], 1 );
	t_eq( $k['manifest']['name'], 'Studio Kit' );
	t_eq( $k['bundle']['format'], 'rk-builder-site' );
	$k['zip']->close();
	@unlink( $p );
} );

rk_test( 'kit: a zip with a stray file, missing parts or the wrong manifest is refused', function () {
	$cases = array(
		'stray php'   => rk_kit_good_entries( array(), array( 'shell.php' => '<?php' ) ),
		'traversal'   => rk_kit_good_entries( array(), array( '../evil.php' => 'x' ) ),
		'nested'      => rk_kit_good_entries( array(), array( 'images/sub/a.jpg' => 'x' ) ),
		'no site'     => array( 'manifest.json' => json_encode( rk_kit_manifest() ) ),
		'no manifest' => array( 'site.json' => json_encode( rk_bundle() ) ),
		'bad format'  => rk_kit_good_entries( array( 'format' => 'nope' ) ),
		'too new'     => rk_kit_good_entries( array( 'requires' => array( 'plugin' => '99.0.0' ) ) ),
		'bad site'    => array( 'manifest.json' => json_encode( rk_kit_manifest() ), 'site.json' => '{"format":"other"}' ),
		'not json'    => array( 'manifest.json' => '{', 'site.json' => '{' ),
	);
	foreach ( $cases as $name => $entries ) {
		$p = rk_kit_zip( $entries );
		$k = rk_builder_kit_open( $p );
		t_assert( is_wp_error( $k ), $name . ' should be refused' );
		t_eq( $k->get_error_code(), 'rk_invalid_kit', $name );
		@unlink( $p );
	}
	$p = tempnam( sys_get_temp_dir(), 'rkz' );
	file_put_contents( $p, 'this is not a zip' );
	t_err( rk_builder_kit_open( $p ), 'rk_invalid_kit', 400 );
	@unlink( $p );
} );

rk_test( 'kit: ignores __MACOSX and .DS_Store that a file manager adds', function () {
	$p = rk_kit_zip( rk_kit_good_entries( array(), array( '__MACOSX/._site.json' => 'x', 'images/.DS_Store' => 'x' ) ) );
	$k = rk_builder_kit_open( $p );
	t_assert( ! is_wp_error( $k ) );
	t_eq( $k['images'], 1 );
	$k['zip']->close();
	@unlink( $p );
} );

rk_test( 'kit: a picture is copied out of the zip, only from images/, and never past the size limit', function () {
	$p = rk_kit_zip( rk_kit_good_entries() );
	$k = rk_builder_kit_open( $p );
	$tmp = rk_builder_kit_extract( $k['zip'], 'images/77-a.jpg', 1000 );
	t_assert( ! is_wp_error( $tmp ) );
	t_eq( file_get_contents( $tmp ), 'not-really-a-jpeg' );
	@unlink( $tmp );
	t_err( rk_builder_kit_extract( $k['zip'], 'images/77-a.jpg', 5 ), 'rk_invalid_kit', 400 );
	t_err( rk_builder_kit_extract( $k['zip'], 'site.json', 100000 ), 'rk_invalid_kit', 400 );
	t_err( rk_builder_kit_extract( $k['zip'], 'images/missing.jpg', 1000 ), 'rk_invalid_kit', 400 );
	$k['zip']->close();
	@unlink( $p );
} );

rk_test( 'kit: media entries keep caption, description and a safe zip path', function () {
	$bad = array();
	$out = rk_builder_bundle_media_entries( array( 'media' => array(
		array( 'id' => 1, 'url' => 'https://old.example.com/a.jpg', 'caption' => 'Cap', 'description' => 'Desc', 'file' => 'images/1-a.jpg' ),
		array( 'id' => 2, 'url' => 'https://old.example.com/b.jpg', 'file' => '../../etc/passwd' ),
	) ), $bad );
	t_eq( $out[0]['caption'], 'Cap' );
	t_eq( $out[0]['description'], 'Desc' );
	t_eq( $out[0]['file'], 'images/1-a.jpg' );
	t_eq( $out[1]['file'], '', 'a path outside images/ is dropped' );
} );

rk_test( 'export: images inside blog posts are found, urls and html are remapped', function () {
	t_eq( rk_builder_content_media_ids( '<img class="alt wp-image-12 size-full" src="x"> <img class="wp-image-12"> <img class="wp-image-300">' ), array( 12, 300 ) );
	t_eq( rk_builder_content_media_ids( 'no images' ), array() );
	$maps = array( 'id' => array( 12 => array( 'id' => 90, 'url' => 'https://new.example.com/n.jpg' ) ), 'url' => array( 'https://old.example.com/o.jpg' => array( 'id' => 90, 'url' => 'https://new.example.com/n.jpg' ) ) );
	t_eq( rk_builder_bundle_remap_html( $maps, '<img class="wp-image-12" src="https://old.example.com/o.jpg"> wp-image-123' ), '<img class="wp-image-90" src="https://new.example.com/n.jpg"> wp-image-123' );
	t_eq( rk_builder_bundle_remap_url( $maps, 'https://old.example.com/o.jpg' ), 'https://new.example.com/n.jpg' );
	t_eq( rk_builder_bundle_remap_url( $maps, 'https://other.example.com/z.jpg' ), 'https://other.example.com/z.jpg' );
} );

rk_test( 'export: the bundle carries site info, layout settings and redirects; blog posts with their SEO', function () {
	rk_test_login( 'admin' );
	rk_theme_site();
	update_option( 'blogname', 'Acme Studio' );
	update_option( 'blogdescription', 'We design things' );
	update_option( 'rk_builder_global', array( 'layout_width' => 1280 ) );
	update_option( 'rk_builder_redirects', array( array( 'from' => '/old', 'to' => '/new', 'code' => 301 ) ) );
	$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello', 'post_name' => 'hello', 'post_content' => '<p>Hi</p>' ) );
	rk_builder_seo_write( $post, array( 'title' => 'Hello SEO' ) );
	$b = rk_builder_build_site_bundle();
	t_assert( ! is_wp_error( $b ) );
	t_eq( $b['site']['title'], 'Acme Studio' );
	t_eq( $b['site']['tagline'], 'We design things' );
	t_eq( $b['global']['layout_width'], 1280 );
	t_eq( $b['redirects'][0]['from'], '/old' );
	$posts = array_values( array_filter( $b['content'], function ( $c ) { return 'post' === $c['type']; } ) );
	t_eq( count( $posts ), 1 );
	t_eq( $posts[0]['slug'], 'hello' );
	t_eq( $posts[0]['seo']['title'], 'Hello SEO' );
	t_eq( rk_builder_bundle_check_shape( $b ), array() );
} );

rk_test( 'import: layout settings, redirects and site name only change when asked for', function () {
	rk_test_login( 'admin' );
	update_option( 'blogname', 'Mine' );
	update_option( 'rk_builder_redirects', array( array( 'from' => '/keep', 'to' => '/kept', 'code' => 301 ) ) );
	$b = rk_bundle( array( 'media' => array(), 'pages' => array( array( 'slug' => 'about', 'title' => 'About', 'layout' => array( 'version' => 1, 'blocks' => array( array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => 'x' ) ) ) ) ) ),
		'site' => array( 'title' => 'Demo Site', 'tagline' => 'Demo tagline' ), 'global' => array( 'layout_width' => 1400 ), 'redirects' => array( array( 'from' => '/a', 'to' => '/b', 'code' => 301 ), array( 'from' => 'javascript:x', 'to' => '/b' ) ) ) );
	$r = t_ok( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => $b, 'options' => array( 'dryRun' => false ) ) ) );
	t_eq( get_option( 'blogname' ), 'Mine' );
	t_eq( rk_builder_global()['layout_width'], 1144 );
	t_eq( count( rk_builder_redirects_list() ), 1 );
	t_eq( $r['site'], array( 'settings' => false, 'redirects' => 0, 'siteInfo' => false ) );

	$r = t_ok( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => $b, 'options' => array( 'dryRun' => false, 'settings' => true, 'redirects' => true, 'siteInfo' => true ) ) ) );
	t_eq( get_option( 'blogname' ), 'Demo Site' );
	t_eq( get_option( 'blogdescription' ), 'Demo tagline' );
	t_eq( rk_builder_global()['layout_width'], 1400 );
	$from = array_column( rk_builder_redirects_list(), 'from' );
	sort( $from );
	t_eq( $from, array( '/a', '/keep' ), 'new rules are added, existing ones kept, a bad rule skipped' );
	t_eq( $r['site'], array( 'settings' => true, 'redirects' => 1, 'siteInfo' => true ) );
} );

rk_test( 'import: blog posts are imported with their SEO', function () {
	rk_test_login( 'admin' );
	$b = rk_bundle( array( 'media' => array(), 'pages' => array( array( 'slug' => 'about', 'title' => 'About', 'layout' => array( 'version' => 1, 'blocks' => array( array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => 'x' ) ) ) ) ) ),
		'content' => array( array( 'type' => 'post', 'slug' => 'news-one', 'title' => 'News one', 'status' => 'publish', 'content' => '<p>Body</p>', 'terms' => array( array( 'slug' => 'news', 'name' => 'News' ) ), 'seo' => array( 'title' => 'News SEO', 'description' => 'D' ) ) ) ) );
	$r = t_ok( rk_post( '/rk/v1/builder/site-import', array( 'bundle' => $b, 'options' => array( 'dryRun' => false, 'content' => true ) ) ) );
	t_eq( $r['content']['created'], 1 );
	$id = get_posts( array( 'post_type' => 'post', 'name' => 'news-one', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
	t_eq( rk_builder_seo_read( $id )['title'], 'News SEO' );
} );

rk_test( 'kits: routes need an administrator', function () {
	t_err( rk_post( '/rk/v1/builder/kits/export', array( 'name' => 'X' ) ), 'rk_unauthorized', 401 );
	t_err( rk_post( '/rk/v1/builder/kits/upload', array() ), 'rk_unauthorized', 401 );
	rk_test_login( 'editor' );
	t_err( rk_post( '/rk/v1/builder/kits/export', array( 'name' => 'X' ) ), 'rk_forbidden', 403 );
} );

rk_test( 'kits: exporting needs a name and builder pages', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/kits/export', array() ), 'rk_invalid_theme', 400 );
	t_err( rk_post( '/rk/v1/builder/kits/export', array( 'name' => 'X', 'surprise' => 1 ) ), 'rk_invalid_theme', 400 );
	t_err( rk_post( '/rk/v1/builder/kits/export', array( 'name' => 'Empty' ) ), 'rk_empty', 409 );
} );

rk_test( 'kits: building a kit packs the manifest and site data into one zip', function () {
	rk_test_login( 'admin' );
	rk_theme_site();
	$built = rk_builder_kit_build( rk_builder_kit_meta_clean( array( 'name' => 'Peoria <b>Kit</b>', 'version' => '3.0.0', 'industry' => 'Flooring' ) ) );
	t_assert( ! is_wp_error( $built ), is_wp_error( $built ) ? $built->get_error_message() : '' );
	$k = rk_builder_kit_open( $built['path'] );
	t_assert( ! is_wp_error( $k ), is_wp_error( $k ) ? $k->get_error_message() : '' );
	t_eq( $k['manifest']['name'], 'Peoria Kit' );
	t_eq( $k['manifest']['version'], '3.0.0' );
	t_eq( $k['manifest']['industry'], 'Flooring' );
	t_eq( $k['manifest']['requires']['plugin'], RK_BUILDER_VERSION );
	t_eq( $k['manifest']['counts']['pages'], 2 );
	t_eq( count( $k['bundle']['pages'] ), 2 );
	$k['zip']->close();
	@unlink( $built['path'] );
} );

rk_test( 'kits: upload checks the file, adds the kit to the library, and the list never shows the disk name', function () {
	rk_test_login( 'admin' );
	$req = new WP_REST_Request( 'POST', '/rk/v1/builder/kits/upload' );
	t_err( rk_builder_handle_kit_upload( $req ), 'rk_invalid_kit', 400 );
	$bad = rk_kit_zip( array( 'shell.php' => 'x' ) );
	$req->files = array( 'file' => array( 'name' => 'k.zip', 'tmp_name' => $bad, 'error' => UPLOAD_ERR_OK, 'size' => 10 ) );
	t_err( rk_builder_handle_kit_upload( $req ), 'rk_invalid_kit', 400 );
	@unlink( $bad );

	$entries = rk_kit_good_entries( array( 'name' => 'Studio Kit', 'industry' => 'Design', 'demo' => 'https://demo.example.com/' ) );
	$entries['site.json'] = json_encode( rk_bundle( array( 'media' => array(), 'pages' => array( array( 'slug' => 'home', 'title' => 'Home', 'layout' => array( 'version' => 1, 'blocks' => array( array( 'id' => 't', 'type' => 'text', 'props' => array( 'text' => 'x' ) ) ) ) ) ) ) ) );
	unset( $entries['images/77-a.jpg'] );
	$good = rk_kit_zip( $entries );
	$req->files = array( 'file' => array( 'name' => 'k.zip', 'tmp_name' => $good, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $good ) ) );
	$r = rk_builder_handle_kit_upload( $req );
	t_assert( ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	$d = $r->get_data();
	t_eq( $d['theme']['slug'], 'studio-kit' );
	t_eq( $d['theme']['kit'], true );
	t_eq( $d['theme']['industry'], 'Design' );
	t_eq( $d['theme']['demo'], 'https://demo.example.com/' );
	t_assert( ! isset( $d['theme']['kitFile'] ), 'the on-disk file name is not exposed' );
	t_eq( $d['check']['pages']['create'], 1 );
	$list = t_ok( rk_get( '/rk/v1/builder/themes' ) );
	t_eq( count( $list['items'] ), 1 );
	t_assert( ! isset( $list['items'][0]['kitFile'] ) );
	$path = rk_builder_theme_kit_path( 'studio-kit' );
	t_assert( '' !== $path && is_readable( $path ), 'the zip is kept on disk' );
	t_assert( is_readable( dirname( $path ) . '/.htaccess' ), 'and the folder is closed to browsing' );

	// installing reads the pages from the zip
	$i = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'studio-kit', 'options' => array( 'publish' => false ) ) ) );
	t_eq( $i['pages']['create'], 1 );
	t_assert( rk_builder_find_page_by_slug( 'home' ) > 0 );

	// delete removes the zip too
	t_ok( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'studio-kit' ) ) );
	t_assert( ! is_readable( $path ), 'the zip is gone' );
	@unlink( $good );
} );

rk_test( 'themes: saving with industry, license and demo link works, and the installed theme is marked active', function () {
	rk_test_login( 'admin' );
	rk_theme_site();
	$r = t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Active One', 'industry' => 'Flooring', 'license' => 'Pro', 'demo' => 'https://demo.example.com/' ) ) );
	t_eq( $r['theme']['industry'], 'Flooring' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'][0]['active'], false );
	t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'active-one', 'options' => array( 'publish' => false ) ) ) );
	t_eq( t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'][0]['active'], true );
	t_ok( rk_post( '/rk/v1/builder/themes/delete', array( 'slug' => 'active-one' ) ) );
	t_eq( get_option( 'rk_builder_active_theme', '' ), '' );
} );

rk_test( 'themes: switching hides the previous theme (nothing is deleted) and switching back restores it', function () {
	rk_test_login( 'admin' );
	rk_theme_site(); // pages: home, about
	t_ok( rk_post( '/rk/v1/builder/themes', array( 'name' => 'Theme A' ) ) );
	$blk = function ( $t ) { return array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'text', 'props' => array( 'text' => $t ) ) ) ); };
	$b = rk_bundle( array( 'media' => array(), 'pages' => array(
		array( 'slug' => 'home', 'title' => 'Home B', 'wasPublished' => true, 'layout' => $blk( 'B home' ) ),
		array( 'slug' => 'pricing', 'title' => 'Pricing', 'wasPublished' => true, 'layout' => $blk( 'B pricing' ) ),
	) ) );
	t_ok( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => $b, 'name' => 'Theme B' ) ) );
	$pub = array( 'publish' => true, 'frontPage' => false );
	t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'theme-a', 'options' => $pub ) ) );
	$about = rk_builder_find_page_by_slug( 'about' );
	t_eq( get_post( $about )->post_status, 'publish' );

	$plan = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'theme-b', 'options' => array( 'dryRun' => true ) ) ) );
	t_eq( $plan['hidden']['pages'], 1, 'the dry run says what would be hidden' );
	t_eq( $plan['hidden']['from'], 'Theme A' );
	t_eq( get_post( $about )->post_status, 'publish', 'a dry run changes nothing' );

	$r = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'theme-b', 'options' => $pub ) ) );
	t_eq( $r['hidden']['pages'], 1 );
	t_eq( get_post( $about )->post_status, 'draft', 'A-only page is hidden' );
	t_eq( get_post( rk_builder_find_page_by_slug( 'home' ) )->post_status, 'publish', 'a shared page stays live' );
	t_eq( get_post( rk_builder_find_page_by_slug( 'pricing' ) )->post_status, 'publish' );
	t_assert( null !== get_post( $about ), 'nothing was deleted' );
	$items = t_ok( rk_get( '/rk/v1/builder/themes' ) )['items'];
	$act = array_column( $items, 'active', 'slug' );
	t_eq( $act, array( 'theme-a' => false, 'theme-b' => true ) );

	$back = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'theme-a', 'options' => $pub ) ) );
	t_eq( $back['hidden']['pages'], 1, 'B-only page is hidden now' );
	t_eq( get_post( $about )->post_status, 'publish', 'A is live again' );
	t_eq( get_post( rk_builder_find_page_by_slug( 'pricing' ) )->post_status, 'draft' );

	$keep = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'theme-b', 'options' => array( 'publish' => true, 'frontPage' => false, 'switch' => false ) ) ) );
	t_eq( $keep['hidden']['pages'], 0, 'switch off keeps the old merge behaviour' );
	t_eq( get_post( $about )->post_status, 'publish' );
} );

rk_test( 'themes: content hidden by a switch comes back when its theme is installed again', function () {
	rk_test_login( 'admin' );
	$blk = array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'text', 'props' => array( 'text' => 'x' ) ) ) );
	$mk = function ( $slug, $pages, $content ) use ( $blk ) {
		return rk_bundle( array( 'media' => array(), 'pages' => array_map( function ( $s ) use ( $blk ) { return array( 'slug' => $s, 'title' => ucfirst( $s ), 'wasPublished' => true, 'layout' => $blk ); }, $pages ), 'content' => $content ) );
	};
	$svc = array( array( 'type' => 'post', 'slug' => 'only-in-one', 'title' => 'Only in one', 'status' => 'publish', 'content' => '<p>x</p>' ) );
	t_ok( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => $mk( 'one', array( 'home' ), $svc ), 'name' => 'One' ) ) );
	t_ok( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => $mk( 'two', array( 'home' ), array() ), 'name' => 'Two' ) ) );
	$o = array( 'publish' => true, 'frontPage' => false );
	t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'one', 'options' => $o ) ) );
	$id = get_posts( array( 'post_type' => 'post', 'name' => 'only-in-one', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
	t_eq( get_post( $id )->post_status, 'publish' );
	t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'two', 'options' => $o ) ) );
	t_eq( get_post( $id )->post_status, 'draft', 'hidden by the switch' );
	t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'one', 'options' => $o ) ) );
	t_eq( get_post( $id )->post_status, 'publish', 'back with its theme' );
} );

rk_test( 'wizard: business details are replaced in wording only, never in addresses, ids or slugs', function () {
	$pairs = rk_builder_replace_pairs_clean( array( array( 'find' => 'Acme Floors', 'with' => 'Zed Co' ), array( 'find' => '555-0100', 'with' => '555-9999' ), array( 'find' => 'x', 'with' => 'y' ), array( 'find' => 'Same', 'with' => 'Same' ), 'junk' ) );
	t_eq( $pairs, array( 'Acme Floors' => 'Zed Co', '555-0100' => '555-9999' ) );
	$b = rk_builder_replace_in_bundle( array(
		'format' => 'rk-builder-site', 'version' => 1,
		'pages' => array( array( 'slug' => 'acme-floors', 'title' => 'About Acme Floors', 'layout' => array( 'blocks' => array( array( 'id' => 'Acme Floors', 'type' => 'text', 'props' => array( 'text' => 'Call Acme Floors', 'href' => 'tel:555-0100', 'url' => 'https://acme-floors.example.com/Acme Floors', 'mediaId' => 5 ) ) ) ) ) ),
		'seo' => array( 'organization' => array( 'name' => 'Acme Floors', 'telephone' => '555-0100' ) ),
		'site' => array( 'title' => 'Acme Floors' ),
		'media' => array( array( 'url' => 'https://x.example.com/Acme Floors.jpg' ) ),
	), $pairs );
	$blk = $b['pages'][0]['layout']['blocks'][0];
	t_eq( $b['pages'][0]['slug'], 'acme-floors' );
	t_eq( $b['pages'][0]['title'], 'About Zed Co' );
	t_eq( $blk['id'], 'Acme Floors' );
	t_eq( $blk['props']['text'], 'Call Zed Co' );
	t_eq( $blk['props']['href'], 'tel:555-9999' );
	t_eq( $blk['props']['url'], 'https://acme-floors.example.com/Acme Floors' );
	t_eq( $b['seo']['organization']['name'], 'Zed Co' );
	t_eq( $b['seo']['organization']['telephone'], '555-9999' );
	t_eq( $b['site']['title'], 'Zed Co' );
	t_eq( $b['media'][0]['url'], 'https://x.example.com/Acme Floors.jpg' );
	$sug = rk_builder_replace_suggestions( array( 'seo' => array( 'organization' => array( 'name' => 'Acme Floors', 'telephone' => '555-0100', 'email' => '', 'city' => 'Peoria' ) ) ) );
	t_eq( array_column( $sug, 'label' ), array( 'Business name', 'Phone', 'City' ) );
} );

rk_test( 'wizard: install with your own details; design-only skips pages; undo puts everything back', function () {
	rk_test_login( 'admin' );
	$blk = function ( $t ) { return array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'text', 'props' => array( 'text' => $t ) ) ) ); };
	// the live site before: a Home page the install will replace, a name, a layout setting
	t_ok( rk_post( '/rk/v1/builder/site-import', array( 'options' => array( 'dryRun' => false ), 'bundle' => rk_bundle( array( 'media' => array(), 'pages' => array( array( 'slug' => 'home', 'title' => 'My Home', 'wasPublished' => true, 'layout' => $blk( 'my own text' ) ) ) ) ) ) ) );
	$home = rk_builder_find_page_by_slug( 'home' );
	t_ok( rk_post( '/rk/v1/builder/publish/' . $home, array( 'expectedRevision' => rk_builder_get_revision( $home ) ) ) );
	update_option( 'blogname', 'My Site' );
	$before_layout = rk_builder_get_draft_layout( $home );

	$bundle = rk_bundle( array( 'media' => array(), 'pages' => array(
		array( 'slug' => 'home', 'title' => 'Demo Home', 'wasPublished' => true, 'layout' => $blk( 'Welcome to Acme Floors' ) ),
		array( 'slug' => 'brand-new', 'title' => 'Brand New', 'wasPublished' => true, 'layout' => $blk( 'New page' ) ),
	), 'site' => array( 'title' => 'Acme Floors', 'tagline' => 'Demo' ), 'global' => array( 'layout_width' => 1500 ), 'seo' => array( 'organization' => array( 'name' => 'Acme Floors', 'telephone' => '555-0100' ) ) ) );
	t_ok( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => $bundle, 'name' => 'Wizard Kit' ) ) );

	// the wizard first asks the dry run what to offer
	$plan = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'wizard-kit', 'options' => array( 'dryRun' => true ) ) ) );
	t_eq( array_column( $plan['suggest'], 'find' ), array( 'Acme Floors', '555-0100' ) );
	t_eq( get_post( $home )->post_title, 'My Home', 'a dry run changes nothing' );

	$opts = array( 'publish' => true, 'frontPage' => false, 'siteInfo' => true, 'settings' => true, 'replace' => array( array( 'find' => 'Acme Floors', 'with' => 'Zed Co' ), array( 'find' => '555-0100', 'with' => '555-9999' ) ) );
	$r = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'wizard-kit', 'options' => $opts ) ) );
	t_eq( $r['undo']['counts']['created'], 1 );
	t_eq( $r['undo']['counts']['changed'], 1 );
	t_eq( get_option( 'blogname' ), 'Zed Co', 'your name replaced the demo name' );
	t_eq( rk_builder_get_draft_layout( $home )['blocks'][0]['props']['text'], 'Welcome to Zed Co' );
	t_eq( rk_builder_seo_organization()['telephone'], '555-9999' );
	t_eq( rk_builder_global()['layout_width'], 1500 );
	$new = rk_builder_find_page_by_slug( 'brand-new' );
	t_assert( $new > 0 );
	t_eq( t_ok( rk_get( '/rk/v1/builder/themes' ) )['undo']['name'], 'Wizard Kit' );

	$u = t_ok( rk_post( '/rk/v1/builder/themes/undo', array() ) );
	t_eq( $u['undone']['trashed'], 1 );
	t_eq( $u['undone']['restored'], 1 );
	t_eq( get_post( $new )->post_status, 'trash', 'the page the install added is in the trash, not deleted' );
	t_eq( get_post( $home )->post_title, 'My Home' );
	t_eq( get_post( $home )->post_status, 'publish' );
	t_deep( rk_builder_get_draft_layout( $home ), $before_layout, 'the layout is exactly as it was' );
	t_eq( get_option( 'blogname' ), 'My Site' );
	t_eq( rk_builder_global()['layout_width'], 1144 );
	t_eq( get_option( 'rk_builder_active_theme', '' ), '' );
	t_eq( t_ok( rk_get( '/rk/v1/builder/themes' ) )['undo'], null );
	t_err( rk_post( '/rk/v1/builder/themes/undo', array() ), 'rk_nothing_to_undo', 404 );

	// design only: no pages come with it
	$d = t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'wizard-kit', 'options' => array( 'pages' => false, 'publish' => true ) ) ) );
	t_eq( $d['pages']['create'] + $d['pages']['update'], 0 );
	t_eq( get_post( $home )->post_title, 'My Home' );
} );

rk_test( 'wizard: undo also reverses a switch (the hidden theme comes back) and is admin-only', function () {
	rk_test_login( 'admin' );
	$blk = array( 'version' => 1, 'blocks' => array( array( 'id' => 'a', 'type' => 'text', 'props' => array( 'text' => 'x' ) ) ) );
	$mk  = function ( array $slugs ) use ( $blk ) { return rk_bundle( array( 'media' => array(), 'pages' => array_map( function ( $s ) use ( $blk ) { return array( 'slug' => $s, 'title' => ucfirst( $s ), 'wasPublished' => true, 'layout' => $blk ); }, $slugs ) ) ); };
	t_ok( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => $mk( array( 'home', 'only-a' ) ), 'name' => 'Alpha' ) ) );
	t_ok( rk_post( '/rk/v1/builder/themes/import', array( 'bundle' => $mk( array( 'home', 'only-b' ) ), 'name' => 'Beta' ) ) );
	$pub = array( 'publish' => true, 'frontPage' => false );
	t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'alpha', 'options' => $pub ) ) );
	t_ok( rk_post( '/rk/v1/builder/themes/install', array( 'slug' => 'beta', 'options' => $pub ) ) );
	$a = rk_builder_find_page_by_slug( 'only-a' );
	$b = rk_builder_find_page_by_slug( 'only-b' );
	t_eq( get_post( $a )->post_status, 'draft' );
	rk_test_login( 'editor' );
	t_err( rk_post( '/rk/v1/builder/themes/undo', array() ), 'rk_forbidden', 403 );
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/themes/undo', array() ) );
	t_eq( get_post( $a )->post_status, 'publish', 'Alpha is live again' );
	t_eq( get_post( $b )->post_status, 'trash', 'Beta page it added is gone to the trash' );
	t_eq( get_option( 'rk_builder_active_theme', '' ), 'alpha' );
} );
