<?php
/** PHP renderer, theme CSS, public integration (theme + standalone), logging. */

/* ---------------- shared helpers (also used by SeoTest / PreviewTest / CacheTest) ---------------- */

/** Register the hooks the plugin adds at load (rk_test_reset() clears them) and reset per-request state. */
function rk_test_hooks() {
	rk_builder_register_public_hooks();
	rk_builder_register_cache_hooks();
	add_action( 'wp', 'rk_builder_seo_setup' );
	add_action( 'template_redirect', 'rk_builder_maybe_serve_preview', 0 );
	add_filter( 'rk_builder_exit_after_preview', '__return_false' );
	rk_builder_content_rendered( 'reset' );
	rk_builder_pending_content_purge( false );
	$GLOBALS['wp_query'] = new RK_Test_WP_Query_Global();
	$GLOBALS['RK']['headers'] = array();
	add_action( 'rk_builder_emit_header', function ( $line ) { $GLOBALS['RK']['headers'][] = $line; } );
}

function rk_test_block( $type, $props, $id = 'b1' ) { return array( 'id' => $id, 'type' => $type, 'props' => $props ); }
function rk_test_layout( array $blocks ) { return array( 'version' => 1, 'blocks' => $blocks ); }

/** A published page carrying $layout as its published snapshot. */
function rk_pub_page( array $layout, $slug = 'home', $extra = array() ) {
	$id = rk_test_page( 'publish', $slug, 2, $extra );
	$GLOBALS['RK']['meta'][ $id ]['_rk_layout_published'] = json_encode( $layout );
	return $id;
}

function rk_test_log_start() {
	$f = tempnam( sys_get_temp_dir(), 'rklog' );
	$GLOBALS['RK_LOGFILE'] = $f;
	$GLOBALS['RK_LOG_OLD'] = ini_set( 'error_log', $f );
}
function rk_test_log_read() {
	$s = (string) file_get_contents( $GLOBALS['RK_LOGFILE'] );
	ini_set( 'error_log', (string) $GLOBALS['RK_LOG_OLD'] );
	@unlink( $GLOBALS['RK_LOGFILE'] );
	return $s;
}

function rk_render_one( $type, $props ) { return rk_builder_render_block( rk_test_block( $type, $props ) ); }

/* ---------------- block markup ---------------- */

rk_test( 'render: exact markup for simple blocks (React parity + rk hooks)', function () {
	t_eq( rk_render_one( 'spacer', array( 'h' => 64 ) ), '<div class="site-spacer rk-block rk-block-spacer" data-rk-block="spacer" style="height:64px" aria-hidden="true"></div>' );
	t_eq( rk_render_one( 'divider', array( 'style' => 'dashed' ) ), '<hr class="site-divider dashed rk-block rk-block-divider" data-rk-block="divider"/>' );
	t_eq( rk_render_one( 'divider', array( 'style' => 'solid' ) ), '<hr class="site-divider solid rk-block rk-block-divider" data-rk-block="divider"/>' );
	t_eq( rk_render_one( 'heading', array( 'text' => 'Hi', 'level' => 2 ) ), '<section class="site-heading rk-block rk-block-heading" data-rk-block="heading"><h2>Hi</h2></section>' );
	t_eq( rk_render_one( 'heading', array( 'text' => 'Hi', 'level' => 3 ) ), '<section class="site-heading rk-block rk-block-heading" data-rk-block="heading"><h3>Hi</h3></section>' );
	t_eq( rk_render_one( 'text', array( 'text' => "One\n\n\nTwo\nstill two\n\n \n\nThree" ) ), '<section class="site-text rk-block rk-block-text" data-rk-block="text"><p>One</p><p>Two' . "\n" . 'still two</p><p>Three</p></section>' );
	$hero = rk_render_one( 'hero', array( 'heading' => 'H', 'sub' => '', 'cta' => '', 'ctaHref' => '' ) );
	t_eq( $hero, '<section class="site-hero rk-block rk-block-hero" data-rk-block="hero"><div class="hero-rule">01 / proposition</div><h1>H</h1></section>', 'no sub, no CTA' );
	$hero = rk_render_one( 'hero', array( 'heading' => 'H', 'sub' => 'S', 'cta' => 'Go', 'ctaHref' => '/x?a=1&b=2' ) );
	t_assert( false !== strpos( $hero, '<p>S</p><a class="site-btn" href="/x?a=1&amp;b=2">Go<svg ' ), $hero );
	t_assert( false !== strpos( $hero, '<h1>H</h1>' ) );
	$cta = rk_render_one( 'cta', array( 'heading' => 'C', 'cta' => 'Go', 'ctaHref' => '' ) );
	t_assert( false !== strpos( $cta, '<a class="site-btn inverse" href="#">Go<svg ' ), 'empty href falls back to # like React' );
} );

rk_test( 'render: every text prop is escaped (XSS payloads render inert)', function () {
	$x = '<script>alert(1)</script>"><img src=x onerror=alert(1)>\'';
	$blocks = array(
		rk_test_block( 'hero', array( 'heading' => $x, 'sub' => $x, 'cta' => $x, 'ctaHref' => '/ok' ), 'a1' ),
		rk_test_block( 'heading', array( 'text' => $x, 'level' => 2 ), 'a2' ),
		rk_test_block( 'text', array( 'text' => $x ), 'a3' ),
		rk_test_block( 'cta', array( 'heading' => $x, 'cta' => $x, 'ctaHref' => '#"onmouseover="x' ), 'a4' ),
		rk_test_block( 'image', array( 'url' => 'https://cms.example.com/a.jpg', 'alt' => $x, 'decorative' => false ), 'a5' ),
		rk_test_block( 'services', array( 'title' => $x, 'source' => 'service', 'limit' => 6, 'cols' => 3, 'category' => '', 'orderBy' => 'date', 'order' => 'desc' ), 'a6' ),
		rk_test_block( 'portfolio', array( 'title' => $x, 'source' => 'portfolio', 'limit' => 6, 'cols' => 3, 'category' => '', 'orderBy' => 'date', 'order' => 'desc' ), 'a7' ),
	);
	$GLOBALS['RK']['attachments'][950] = array( 'url' => 'https://cms.example.com/u/x.jpg', 'w' => 10, 'h' => 10, 'title' => 't', 'srcset' => 'https://cms.example.com/u/x.jpg 10w' );
	$GLOBALS['RK']['meta'][950]['_wp_attachment_image_alt'] = $x;
	rk_make_cpt( 'service', 'T &lt;script&gt;alert(1)&lt;/script&gt;', array( 'post_excerpt' => '<b onclick=x>E</b> &lt;i&gt;', 'post_title' => 'T &lt;script&gt;alert(1)&lt;/script&gt;' ), array(), 950 );
	$html = rk_builder_render_layout( rk_test_layout( $blocks ) );
	t_assert( '' !== $html );
	foreach ( array( '<script', '<img src=x', '<b onclick', '"onmouseover=' ) as $bad ) {
		t_assert( false === strpos( $html, $bad ), 'raw ' . $bad . ' leaked: ' . $html );
	}
	t_assert( false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;&quot;&gt;&lt;img src=x onerror=alert(1)&gt;&#x27;' ), 'escaped payload present' );
	t_eq( substr_count( $html, 'data-rk-block=' ), 7 );
} );

rk_test( 'render: invalid or unknown stored data renders nothing (and never fatals)', function () {
	t_eq( rk_render_one( 'heading', array( 'text' => 'x', 'level' => 4 ) ), '', 'level 4 not allowed' );
	t_eq( rk_render_one( 'heading', array( 'text' => 'x', 'level' => 2, 'tag' => 'script' ) ), '', 'unknown prop' );
	t_eq( rk_render_one( 'hero', array( 'heading' => 'x', 'sub' => '', 'cta' => 'Go', 'ctaHref' => 'javascript:alert(1)' ) ), '', 'unsafe link' );
	t_eq( rk_render_one( 'hero', array( 'heading' => '' ) ), '', 'missing/empty required' );
	t_eq( rk_render_one( 'spacer', array( 'h' => 100000 ) ), '', 'out of range' );
	t_eq( rk_render_one( 'image', array( 'url' => 'https://evil.example/a.jpg', 'alt' => 'x', 'decorative' => false ) ), '', 'foreign image host' );
	t_eq( rk_render_one( 'image', array( 'url' => '/a.jpg', 'alt' => '', 'decorative' => false ) ), '', 'alt required unless decorative' );
	t_eq( rk_render_one( 'script', array() ), '', 'unknown type' );
	t_eq( rk_builder_render_block( array( 'type' => 'spacer', 'props' => 'nope' ) ), '' );
	t_eq( rk_builder_render_block( array() ), '' );
	t_eq( rk_builder_render_block( array( 'type' => array( 'x' ), 'props' => array() ) ), '' );
	t_eq( rk_builder_render_layout( array( 'blocks' => 'garbage' ) ), '' );
	t_eq( rk_builder_render_layout( array() ), '' );
	// the good blocks around a bad one still render
	$html = rk_builder_render_layout( rk_test_layout( array(
		rk_test_block( 'spacer', array( 'h' => 8 ), 'a' ),
		rk_test_block( 'bogus', array(), 'b' ),
		rk_test_block( 'heading', array( 'text' => 'x', 'level' => 9 ), 'c' ),
		rk_test_block( 'divider', array( 'style' => 'solid' ), 'd' ),
	) ) );
	t_eq( substr_count( $html, 'data-rk-block=' ), 2 );
	foreach ( fx_files( 'invalid' ) as $f ) {
		$d = json_decode( file_get_contents( $f ), true );
		$doc = isset( $d['document'] ) ? $d['document'] : null;
		if ( is_array( $doc ) && isset( $doc['blocks'] ) ) { rk_builder_render_layout( $doc ); } // must not throw
	}
} );

rk_test( 'render: image uses the attachment (url, size, srcset, live alt), falls back to stored props, decorative has empty alt', function () {
	$GLOBALS['RK']['attachments'][12] = array( 'url' => 'https://cms.example.com/wp/new.jpg', 'w' => 640, 'h' => 480, 'title' => 't', 'srcset' => 'https://cms.example.com/wp/new-320.jpg 320w, https://cms.example.com/wp/new.jpg 640w' );
	$GLOBALS['RK']['meta'][12]['_wp_attachment_image_alt'] = 'Fresh alt';
	$stored = array( 'mediaId' => 12, 'url' => 'https://cms.example.com/wp/old.jpg', 'alt' => 'Old alt', 'decorative' => false, 'width' => 1, 'height' => 1, 'srcset' => 'old 1w' );
	$html = rk_render_one( 'image', $stored );
	t_eq( $html, '<section class="site-image rk-block rk-block-image" data-rk-block="image"><img src="https://cms.example.com/wp/new.jpg" alt="Fresh alt" width="640" height="480" srcSet="https://cms.example.com/wp/new-320.jpg 320w, https://cms.example.com/wp/new.jpg 640w" sizes="(min-width: 1040px) 1040px, 100vw" loading="lazy" decoding="async"/></section>' );
	// unknown attachment id -> stored props
	$html = rk_render_one( 'image', array_merge( $stored, array( 'mediaId' => 999 ) ) );
	t_assert( false !== strpos( $html, 'src="https://cms.example.com/wp/old.jpg" alt="Old alt" width="1" height="1" srcSet="old 1w"' ), $html );
	// no mediaId, no size, no srcset
	$html = rk_render_one( 'image', array( 'url' => '/p.svg', 'alt' => 'P', 'decorative' => false ) );
	t_eq( $html, '<section class="site-image rk-block rk-block-image" data-rk-block="image"><img src="/p.svg" alt="P" loading="lazy" decoding="async"/></section>' );
	// decorative -> alt="" even when an alt string is stored or the media library has one
	$html = rk_render_one( 'image', array_merge( $stored, array( 'decorative' => true ) ) );
	t_assert( false !== strpos( $html, ' alt="" ' ), $html );
	t_assert( false === strpos( $html, 'Fresh alt' ) && false === strpos( $html, 'Old alt' ) );
} );

/* ---------------- grids ---------------- */

function rk_grid_props( $source, $over = array() ) {
	return array_merge( array( 'title' => 'T', 'source' => $source, 'limit' => 6, 'cols' => 3, 'category' => '', 'orderBy' => 'date', 'order' => 'desc' ), $over );
}

rk_test( 'grid: published only, no passwords, limit/category/order, cols, featured image, plain excerpt', function () {
	$GLOBALS['RK']['attachments'][901] = array( 'url' => 'https://cms.example.com/u/s.jpg', 'w' => 640, 'h' => 480, 'title' => 's', 'srcset' => 'https://cms.example.com/u/s.jpg 640w' );
	$GLOBALS['RK']['meta'][901]['_wp_attachment_image_alt'] = 'A wire';
	rk_make_cpt( 'service', 'Wiring', array( 'post_excerpt' => '<p>Safe &amp; <b>sound</b></p>', 'menu_order' => 2, 'post_date' => '2026-01-01 00:00:00' ), array( 'residential' ), 901 );
	rk_make_cpt( 'service', 'Solar', array( 'post_content' => '<p>Panels [gallery] on roofs</p>', 'menu_order' => 1, 'post_date' => '2026-02-01 00:00:00' ) );
	rk_make_cpt( 'service', 'Draft one', array( 'post_status' => 'draft' ) );
	rk_make_cpt( 'service', 'Private one', array( 'post_status' => 'private' ) );
	rk_make_cpt( 'service', 'Hidden', array( 'post_password' => 'x' ) );
	rk_make_cpt( 'portfolio', 'Project', array() );
	$html = rk_render_one( 'services', rk_grid_props( 'service', array( 'cols' => 2, 'orderBy' => 'menu_order', 'order' => 'asc' ) ) );
	t_assert( 0 === strpos( $html, '<section class="site-grid rk-block rk-block-services" data-rk-block="services" data-state="ready"><div class="grid-head"><h2>T</h2></div><div class="cards" style="grid-template-columns:repeat(2, minmax(0, 1fr))">' ), $html );
	t_assert( strpos( $html, 'Solar' ) < strpos( $html, 'Wiring' ), 'menu_order asc' );
	foreach ( array( 'Draft one', 'Private one', 'Hidden', 'Project' ) as $no ) { t_assert( false === strpos( $html, $no ), $no . ' must not render' ); }
	t_assert( false !== strpos( $html, '<p>Safe &amp; sound</p>' ) && false !== strpos( $html, '<p>Panels on roofs</p>' ) );
	t_assert( false !== strpos( $html, '<img src="https://cms.example.com/u/s.jpg" alt="A wire" width="640" height="480" srcSet="https://cms.example.com/u/s.jpg 640w" sizes="(min-width: 900px) 50vw, 100vw" loading="lazy" decoding="async"/>' ), $html );
	t_assert( false !== strpos( $html, '<div class="card-placeholder" aria-hidden="true"></div>' ), 'no featured image -> placeholder' );
	$desc = rk_render_one( 'services', rk_grid_props( 'service', array( 'orderBy' => 'date', 'order' => 'desc' ) ) );
	t_assert( strpos( $desc, 'Solar' ) < strpos( $desc, 'Wiring' ), 'date desc' );
	$title = rk_render_one( 'services', rk_grid_props( 'service', array( 'orderBy' => 'title', 'order' => 'asc' ) ) );
	t_assert( strpos( $title, 'Solar' ) < strpos( $title, 'Wiring' ) );
	$cat = rk_render_one( 'services', rk_grid_props( 'service', array( 'category' => 'residential' ) ) );
	t_assert( false !== strpos( $cat, 'Wiring' ) && false === strpos( $cat, 'Solar' ), 'category slug' );
	$lim = rk_render_one( 'services', rk_grid_props( 'service', array( 'limit' => 1 ) ) );
	t_eq( substr_count( $lim, '<article ' ), 1 );
} );

rk_test( 'grid: empty state, disabled content type, query failure', function () {
	$html = rk_render_one( 'portfolio', rk_grid_props( 'portfolio', array( 'cols' => 4 ) ) );
	t_eq( $html, '<section class="site-grid rk-block rk-block-portfolio" data-rk-block="portfolio" data-state="ready"><div class="grid-head"><h2>T</h2></div><p class="grid-note">No projects to show yet.</p></section>' );
	t_assert( false !== strpos( rk_render_one( 'services', rk_grid_props( 'service' ) ), 'No services to show yet.' ) );
	rk_make_cpt( 'service', 'Wiring', array() );
	update_option( 'rk_builder_settings', array( 'enable_service_cpt' => false ) );
	t_assert( false !== strpos( rk_render_one( 'services', rk_grid_props( 'service' ) ), 'No services to show yet.' ), 'disabled CPT -> empty state' );
	update_option( 'rk_builder_settings', array() );
	// a failing query degrades to a note, records a (content-free) error and never throws
	rk_test_log_start();
	$GLOBALS['RK']['posts'][5] = 'corrupt';
	$html = rk_render_one( 'services', rk_grid_props( 'service' ) );
	$log  = rk_test_log_read();
	t_assert( false !== strpos( $html, 'data-state="error"' ) && false !== strpos( $html, 'Services are temporarily unavailable.' ), $html );
	$err = get_option( 'rk_builder_last_render_error' );
	t_eq( array_keys( $err ), array( 'time', 'page_id', 'code' ) );
	t_eq( $err['code'], 'rk_grid_query_failed' );
	t_assert( false !== strpos( $log, 'grid_query_failed' ) );
} );

rk_test( 'content: default_grid_limit setting is the /content default; CPT settings gate registration', function () {
	for ( $i = 0; $i < 8; $i++ ) { rk_make_cpt( 'service', 'S' . $i, array() ); }
	t_eq( count( t_ok( rk_get( '/rk/v1/content/service' ) )['items'] ), 6, 'default setting 6' );
	update_option( 'rk_builder_settings', array( 'default_grid_limit' => 3 ) );
	t_eq( count( t_ok( rk_get( '/rk/v1/content/service' ) )['items'] ), 3 );
	t_eq( count( t_ok( rk_get( '/rk/v1/content/service', array( 'limit' => '5' ) ) )['items'] ), 5, 'explicit limit wins' );
	$GLOBALS['RK']['cpt'] = $GLOBALS['RK']['tax'] = array();
	update_option( 'rk_builder_settings', array( 'enable_service_cpt' => false ) );
	rk_builder_register_content_types();
	t_assert( ! isset( $GLOBALS['RK']['cpt']['service'] ) && ! isset( $GLOBALS['RK']['tax']['service_cat'] ) );
	t_assert( isset( $GLOBALS['RK']['cpt']['portfolio'], $GLOBALS['RK']['tax']['portfolio_cat'] ) );
	t_err( rk_get( '/rk/v1/content/service' ), 'rk_not_found', 404 );
	update_option( 'rk_builder_settings', array( 'enable_portfolio_cpt' => false ) );
	$GLOBALS['RK']['cpt'] = array();
	rk_builder_register_content_types();
	t_assert( isset( $GLOBALS['RK']['cpt']['service'] ) && ! isset( $GLOBALS['RK']['cpt']['portfolio'] ) );
} );

/* ---------------- theme css ---------------- */

rk_test( 'theme css: both variable sets from validated tokens; malicious stored values never reach CSS', function () {
	$css = rk_builder_theme_css( rk_builder_default_theme() );
	t_eq( $css, ".rk-root{--site-primary:#C7F36B;--rk-primary:#C7F36B;--site-bg:#F8F5ED;--rk-bg:#F8F5ED;--site-ink:#1B2430;--rk-ink:#1B2430;--site-font:'Space Grotesk', system-ui, sans-serif;--rk-font:'Space Grotesk', system-ui, sans-serif}" );
	t_assert( false !== strpos( rk_builder_theme_css( array_merge( rk_builder_default_theme(), array( 'font' => 'Georgia' ) ) ), "--site-font:Georgia, 'Times New Roman', serif" ) );
	// direct garbage -> defaults
	$evil = array_merge( rk_builder_default_theme(), array( 'primary' => 'red;}body{display:none', 'font' => 'x;}</style><script>' ) );
	t_eq( rk_builder_theme_css( $evil ), $css );
	// garbage in storage -> migrate falls back per field
	update_option( 'rk_theme_config', array( 'version' => 1, 'primary' => '#112233;}</style><script>alert(1)</script>', 'bg' => 'url(javascript:x)', 'ink' => '#000000', 'font' => 'Comic Sans' ) );
	$out = rk_builder_theme_css( rk_builder_get_theme() );
	foreach ( array( '<', '>', 'javascript', 'Comic', 'url(', '}body' ) as $bad ) { t_assert( false === strpos( $out, $bad ), $bad . ' leaked: ' . $out ); }
	t_assert( false !== strpos( $out, '--site-primary:#C7F36B' ) && false !== strpos( $out, '--site-ink:#000000' ) );
	t_eq( 1, substr_count( $out, '}' ) );
} );

/* ---------------- public integration ---------------- */

function rk_hello_layout() { return rk_test_layout( array( rk_test_block( 'heading', array( 'text' => 'Published heading', 'level' => 2 ) ) ) ); }

rk_test( 'public (theme mode): published page content is replaced once, wrapped, with css enqueued', function () {
	rk_test_hooks();
	$id = rk_pub_page( rk_hello_layout(), 'home', array( 'post_content' => 'ORIGINAL BODY' ) );
	rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => true ) );
	$out = apply_filters( 'the_content', 'ORIGINAL BODY [gallery]' );
	t_eq( $out, '<div class="site-root rk-root"><section class="site-heading rk-block rk-block-heading" data-rk-block="heading"><h2>Published heading</h2></section></div>' );
	t_eq( apply_filters( 'the_content', 'SECOND CALL' ), 'SECOND CALL', 'only once per request' );
	do_action( 'wp_enqueue_scripts' );
	$s = $GLOBALS['RK']['styles']['rk-builder-site'];
	t_eq( $s['src'], RK_BUILDER_URL . 'assets/site.css' );
	t_eq( $s['ver'], RK_BUILDER_VERSION );
	t_eq( $s['inline'][0], rk_builder_theme_css( rk_builder_default_theme() ) );
	t_assert( ! isset( $GLOBALS['RK']['styles']['rk-builder-fonts'] ), 'no external fonts by default' );
	add_filter( 'rk_builder_load_google_fonts', '__return_true' );
	do_action( 'wp_enqueue_scripts' );
	t_assert( 0 === strpos( $GLOBALS['RK']['styles']['rk-builder-fonts']['src'], 'https://fonts.googleapis.com/css2?' ) );
	t_eq( rk_builder_filter_template_include( '/theme/page.php' ), '/theme/page.php', 'theme mode keeps the theme template' );
} );

rk_test( 'public: drafts, private, password-protected, missing, unpublished, disabled, non-main/loop are untouched', function () {
	rk_test_hooks();
	$lay = rk_hello_layout();
	$cases = array(
		'draft'    => rk_test_page( 'draft', 'd' ),
		'private'  => rk_test_page( 'private', 'p' ),
		'pending'  => rk_test_page( 'pending', 'pe' ),
		'password' => rk_pub_page( $lay, 'pw', array( 'post_password' => 'secret' ) ),
		'nosnap'   => rk_test_page( 'publish', 'ns' ),
	);
	foreach ( array( 'draft', 'private', 'pending' ) as $k ) { $GLOBALS['RK']['meta'][ $cases[ $k ] ]['_rk_layout_published'] = json_encode( $lay ); }
	foreach ( $cases as $k => $id ) {
		rk_builder_content_rendered( 'reset' );
		rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => true ) );
		t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL', $k );
		$GLOBALS['RK']['styles'] = array();
		do_action( 'wp_enqueue_scripts' );
		t_assert( empty( $GLOBALS['RK']['styles'] ), $k . ' enqueues nothing' );
	}
	rk_test_set_query( array( 'singular' => true, 'id' => 99999, 'loop' => true ) );
	t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL', 'missing' );
	$id = rk_pub_page( $lay, 'ok' );
	rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => false ) );
	t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL', 'outside the loop' );
	rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => true, 'main' => false ) );
	t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL', 'not the main query' );
	rk_test_set_query( array( 'singular' => false, 'id' => $id, 'loop' => true ) );
	t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL', 'archive' );
	rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => true ) );
	update_option( 'rk_builder_settings', array( 'enabled' => false ) );
	t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL', 'setting enabled=false' );
	update_option( 'rk_builder_settings', array() );
	// a corrupt snapshot never fatals and leaves the theme content alone (and records why)
	$bad = rk_test_page( 'publish', 'bad' );
	$GLOBALS['RK']['meta'][ $bad ]['_rk_layout_published'] = '{"version":1,"blocks":"nope"}';
	rk_test_set_query( array( 'singular' => true, 'id' => $bad, 'loop' => true ) );
	rk_builder_content_rendered( 'reset' );
	rk_test_log_start();
	t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL' );
	t_assert( false !== strpos( rk_test_log_read(), 'published_snapshot_corrupt' ) );
	t_eq( get_option( 'rk_builder_last_render_error' )['code'], 'rk_snapshot_invalid' );
} );

rk_test( 'public (standalone): template_include selects the plugin template; the document is complete, escaped and JS-free', function () {
	rk_test_hooks();
	update_option( 'rk_builder_settings', array( 'public_rendering_mode' => 'standalone' ) );
	update_option( 'rk_theme_config', array( 'version' => 1, 'primary' => '#C7F36B', 'bg' => '#F8F5ED', 'ink' => '#1B2430', 'font' => 'Georgia', 'social' => array( 'instagram' => 'https://instagram.com/rk?a=1&b=2' ), 'header' => array( 'sticky' => true ) ) );
	$id = rk_pub_page( rk_hello_layout(), 'home' );
	rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => true ) );
	t_eq( rk_builder_filter_template_include( '/theme/page.php' ), RK_BUILDER_DIR . 'templates/public-layout.php' );
	t_eq( apply_filters( 'template_include', '/theme/page.php' ), RK_BUILDER_DIR . 'templates/public-layout.php' );
	t_eq( apply_filters( 'the_content', 'ORIGINAL' ), 'ORIGINAL', 'standalone does not use the_content' );
	add_action( 'wp_head', function () { echo '<!--wp_head-->'; } );
	add_action( 'wp_footer', function () { echo '<!--wp_footer-->'; } );
	ob_start();
	include RK_BUILDER_DIR . 'templates/public-layout.php';
	$html = ob_get_clean();
	t_assert( 0 === strpos( $html, '<!doctype html>' ) );
	foreach ( array( '<!--wp_head-->', '<!--wp_footer-->', '<main id="main"><section', '<h2>Published heading</h2>', '<header class="site-header sticky">', '<span class="site-wordmark">Test Site</span>', '<footer class="site-footer">',
		'<a href="https://instagram.com/rk?a=1&amp;b=2" rel="noopener noreferrer">instagram</a>', 'class="site-root rk-root"' ) as $needle ) {
		t_assert( false !== strpos( $html, $needle ), 'missing ' . $needle . "\n" . $html );
	}
	t_assert( false === stripos( $html, '<script' ), 'no JS' );
	// draft pages never switch template
	$d = rk_test_page( 'draft', 'dr' );
	$GLOBALS['RK']['meta'][ $d ]['_rk_layout_published'] = json_encode( rk_hello_layout() );
	rk_test_set_query( array( 'singular' => true, 'id' => $d ) );
	t_eq( rk_builder_filter_template_include( '/theme/page.php' ), '/theme/page.php' );
} );

/* ---------------- logging ---------------- */

rk_test( 'log: levels are gated, secrets/tokens/layout bodies are redacted', function () {
	rk_test_log_start();
	t_eq( rk_builder_log( 'info', 'quiet', array( 'x' => 1 ) ), false, 'info is below the default warning level' );
	t_eq( rk_builder_log( 'warning', 'loud', array(
		'page_id' => 7, 'token' => 'TOK123', 'X-WP-Nonce' => 'NON456', 'password' => 'PW789', 'Authorization' => 'Bearer AUTH000', 'Cookie' => 'wordpress_logged_in=COOK1',
		'layout' => array( 'blocks' => array( array( 'props' => array( 'text' => 'LAYOUTBODY' ) ) ) ), 'nested' => array( 'secret' => 'SEC2', 'ok' => 'fine' ),
		'url' => 'https://x.example/?rk_preview=1&token=QTOK9&page=1', 'msg' => 'secret=SHH77',
	) ), true );
	add_filter( 'rk_builder_log_level', function () { return 'debug'; } );
	t_eq( rk_builder_log( 'info', 'verbose', array( 'x' => 1 ) ), true );
	$log = rk_test_log_read();
	t_assert( false !== strpos( $log, 'loud' ) && false !== strpos( $log, '"page_id":7' ) && false !== strpos( $log, 'fine' ) && false !== strpos( $log, 'verbose' ) );
	t_assert( false === strpos( $log, 'quiet' ) );
	foreach ( array( 'TOK123', 'NON456', 'PW789', 'AUTH000', 'COOK1', 'LAYOUTBODY', 'SEC2', 'QTOK9', 'SHH77' ) as $secret ) {
		t_assert( false === strpos( $log, $secret ), $secret . ' leaked into the log: ' . $log );
	}
} );
