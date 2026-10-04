<?php
/**
 * Dashboard API: everything the builder's own dashboard needs so nobody has to open wp-admin.
 *
 *   GET  /builder/overview                    counts, recent pages, attention list, visualizer + theme status
 *   POST /builder/pages                       { title, slug?, starter? }  new draft page
 *   POST /builder/pages/{id}/update           { title?, slug? }           rename / change the address
 *   POST /builder/pages/{id}/duplicate        copy as a new draft (layout + SEO)
 *   POST /builder/pages/{id}/trash            move to the trash (recoverable in WordPress)
 *   POST /builder/pages/{id}/front            make this published page the site front page
 *   GET|POST /builder/pages/{id}/seo          { title, description, image, noindex }
 *   GET|POST /builder/site                    site title, tagline, search visibility, front page, business details
 *   GET|POST /builder/visualizer-admin        visualizer provider settings (secrets are never returned) + leads
 *   POST /builder/visualizer-admin/leads/delete   { email } or { all: true }
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Page row used by the dashboard lists. */
function rk_builder_dash_page_row( $post ) {
	$id  = (int) $post->ID;
	$seo = rk_builder_seo_read( $id );
	return array(
		'id'                => $id,
		'title'             => rk_builder_plain( get_the_title( $post ) ),
		'slug'              => (string) $post->post_name,
		'status'            => (string) $post->post_status,
		'modified'          => rk_builder_page_modified( $post ),
		'revision'          => rk_builder_get_revision( $id ),
		'publishedRevision' => rk_builder_get_published_revision( $id ),
		'link'              => (string) get_permalink( $id ),
		'isFront'           => (int) get_option( 'page_on_front' ) === $id && 'page' === get_option( 'show_on_front' ),
		'noindex'           => ! empty( $seo['noindex'] ),
		'hasDescription'    => isset( $seo['description'] ) && '' !== $seo['description'],
	);
}

function rk_builder_dash_all_pages() {
	return get_posts( array(
		'post_type'      => 'page',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => 500,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	) );
}

function rk_builder_dash_count_posts( $type ) {
	if ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( $type ) || ! function_exists( 'wp_count_posts' ) ) { return 0; }
	$c = wp_count_posts( $type );
	return is_object( $c ) ? (int) ( isset( $c->publish ) ? $c->publish : 0 ) + (int) ( isset( $c->draft ) ? $c->draft : 0 ) : 0;
}

/* ------------------------------------------------------------------ *
 * Overview
 * ------------------------------------------------------------------ */

function rk_builder_handle_overview( $req ) {
	$posts = rk_builder_dash_all_pages();
	$rows  = array();
	foreach ( $posts as $p ) {
		if ( current_user_can( 'edit_post', $p->ID ) ) { $rows[] = rk_builder_dash_page_row( $p ); }
	}
	$by = array( 'publish' => 0, 'draft' => 0, 'other' => 0 );
	$changes = array();
	$no_desc = array();
	foreach ( $rows as $r ) {
		if ( 'publish' === $r['status'] ) { $by['publish']++; } elseif ( 'draft' === $r['status'] ) { $by['draft']++; } else { $by['other']++; }
		if ( 'publish' === $r['status'] && null !== $r['publishedRevision'] && $r['revision'] > $r['publishedRevision'] ) { $changes[] = $r; }
		if ( 'publish' === $r['status'] && ! $r['hasDescription'] && ! $r['noindex'] ) { $no_desc[] = $r; }
	}
	$viz   = rk_builder_viz_settings();
	$leads = rk_builder_viz_leads();
	$media = 0;
	if ( function_exists( 'wp_count_attachments' ) ) {
		foreach ( (array) wp_count_attachments( 'image' ) as $k => $n ) { if ( 0 === strpos( (string) $k, 'image/' ) ) { $media += (int) $n; } }
	}
	$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	return rk_builder_no_store( array(
		'pages'      => array( 'total' => count( $rows ), 'publish' => $by['publish'], 'draft' => $by['draft'], 'other' => $by['other'] ),
		'recent'     => array_slice( $rows, 0, 6 ),
		'attention'  => array(
			'unpublishedChanges' => array_slice( $changes, 0, 8 ),
			'missingDescription' => array_slice( $no_desc, 0, 8 ),
		),
		'media'      => $media,
		'setup'      => rk_builder_dash_setup(),
		'content'    => array( 'services' => rk_builder_dash_count_posts( 'service' ), 'projects' => rk_builder_dash_count_posts( 'portfolio' ) ),
		'themes'     => count( rk_builder_themes_index() ),
		'visualizer' => array(
			'enabled'  => ! empty( $viz['enabled'] ),
			'ready'    => rk_builder_viz_ready( $viz ),
			'provider' => $viz['provider'],
			'label'    => rk_builder_viz_providers()[ $viz['provider'] ],
			'leads'    => count( $leads ),
			'lastLead' => $leads && isset( $leads[0]['at'] ) ? (string) $leads[0]['at'] : '',
		),
		'site'       => array(
			'name'          => (string) get_bloginfo( 'name' ),
			'tagline'       => (string) get_bloginfo( 'description' ),
			'url'           => home_url( '/' ),
			'adminUrl'      => admin_url( '/' ),
			'frontPageId'   => $front,
			'searchVisible' => '0' !== (string) get_option( 'blog_public', '1' ),
			'plugin'        => RK_BUILDER_VERSION,
		),
	) );
}

/** What is already configured (drives the overview checklist). */
function rk_builder_dash_setup() {
	$code = rk_builder_code_settings();
	$org  = rk_builder_seo_organization();
	$rev  = rk_builder_reviews_store();
	return array(
		'searchConsole' => '' !== $code['gsc'],
		'analytics'     => '' !== $code['ga4'] || '' !== $code['gtm'],
		'businessProfile' => isset( $org['profiles']['googleBusiness'] ),
		'localBusiness' => isset( $org['city'] ) || isset( $org['street'] ),
		'socialImage'   => isset( $org['defaultImage'] ),
		'reviews'       => count( array_filter( $rev['items'], function ( $r ) { return empty( $r['hidden'] ); } ) ),
		'redirects'     => count( rk_builder_redirects_list() ),
	);
}

/* ------------------------------------------------------------------ *
 * Page management
 * ------------------------------------------------------------------ */

function rk_builder_dash_body( $req, array $allowed, $code = 'rk_invalid_page' ) {
	$body = rk_builder_json_body( $req, $code );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, $allowed, true ) ) { rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' ); }
	}
	return $issues ? rk_builder_invalid( $code, $issues ) : $body;
}

/** A clean slug from a request value ('' when none given). */
function rk_builder_dash_slug( $v ) {
	if ( ! is_string( $v ) || '' === trim( $v ) ) { return ''; }
	return substr( trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $v ) ), '-' ), 0, 190 );
}

function rk_builder_dash_unique_slug( $slug, $id = 0 ) {
	if ( function_exists( 'wp_unique_post_slug' ) ) { return wp_unique_post_slug( $slug, (int) $id, 'publish', 'page', 0 ); }
	return $slug;
}

function rk_builder_dash_title( $v ) {
	$t = is_string( $v ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $v ) ) ) : '';
	return ( '' !== $t && rk_builder_strlen( $t ) <= 200 ) ? $t : '';
}

/** Header and footer reusable blocks a new page starts with (when the site has them). */
function rk_builder_dash_starter_layout() {
	$blocks = array();
	$have   = array();
	foreach ( rk_builder_reusable_list() as $r ) { $have[ $r['slug'] ] = (int) $r['id']; }
	if ( isset( $have['site-header'] ) ) { $blocks[] = array( 'id' => 'site-header', 'type' => 'reusable', 'props' => array( 'refId' => $have['site-header'] ) ); }
	if ( isset( $have['site-footer'] ) ) { $blocks[] = array( 'id' => 'site-footer', 'type' => 'reusable', 'props' => array( 'refId' => $have['site-footer'] ) ); }
	return array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => $blocks );
}

function rk_builder_handle_create_page( $req ) {
	$body = rk_builder_dash_body( $req, array( 'title', 'slug', 'starter' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$title = rk_builder_dash_title( isset( $body['title'] ) ? $body['title'] : '' );
	if ( '' === $title ) { return rk_builder_invalid( 'rk_invalid_page', array( array( 'path' => 'title', 'message' => 'Give the page a title (up to 200 characters).' ) ) ); }
	$slug = rk_builder_dash_slug( isset( $body['slug'] ) ? $body['slug'] : $title );
	$id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_name' => '' !== $slug ? rk_builder_dash_unique_slug( $slug ) : '' ), true );
	if ( is_wp_error( $id ) || ! $id ) { return rk_builder_error( 'rk_server_error', 'Could not create the page.', 500 ); }
	$layout = ! isset( $body['starter'] ) || $body['starter'] ? rk_builder_dash_starter_layout() : rk_builder_empty_layout();
	$commit = rk_builder_with_lock( (int) $id, function () use ( $id, $layout ) { return rk_builder_commit_revision( (int) $id, 'draft', $layout ); } );
	if ( is_wp_error( $commit ) ) { return $commit; }
	return rk_builder_no_store( array( 'page' => rk_builder_dash_page_row( get_post( $id ) ) ) );
}

function rk_builder_handle_update_page_meta( $req ) {
	$page = rk_builder_get_page( $req['id'] );
	$body = rk_builder_dash_body( $req, array( 'title', 'slug' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$data = array( 'ID' => (int) $page->ID );
	if ( isset( $body['title'] ) ) {
		$t = rk_builder_dash_title( $body['title'] );
		if ( '' === $t ) { return rk_builder_invalid( 'rk_invalid_page', array( array( 'path' => 'title', 'message' => 'Give the page a title (up to 200 characters).' ) ) ); }
		$data['post_title'] = $t;
	}
	if ( isset( $body['slug'] ) ) {
		$s = rk_builder_dash_slug( $body['slug'] );
		if ( '' === $s ) { return rk_builder_invalid( 'rk_invalid_page', array( array( 'path' => 'slug', 'message' => 'Use letters, numbers and dashes.' ) ) ); }
		$data['post_name'] = rk_builder_dash_unique_slug( $s, (int) $page->ID );
	}
	if ( count( $data ) < 2 ) { return rk_builder_invalid( 'rk_invalid_page', array( array( 'path' => '', 'message' => 'Nothing to change.' ) ) ); }
	$r = wp_update_post( $data, true );
	if ( is_wp_error( $r ) || ! $r ) { return rk_builder_error( 'rk_server_error', 'Could not update the page.', 500 ); }
	rk_builder_revalidate( 'publish', (int) $page->ID, (string) get_post( $page->ID )->post_name );
	rk_builder_layout_changed( (int) $page->ID, 'publish' );
	return rk_builder_no_store( array( 'page' => rk_builder_dash_page_row( get_post( $page->ID ) ) ) );
}

function rk_builder_handle_duplicate_page( $req ) {
	$src = rk_builder_get_page( $req['id'] );
	$title = 'Copy of ' . rk_builder_plain( get_the_title( $src ) );
	if ( rk_builder_strlen( $title ) > 200 ) { $title = rk_builder_substr( $title, 0, 200 ); }
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_name' => rk_builder_dash_unique_slug( rk_builder_dash_slug( $title ) ) ), true );
	if ( is_wp_error( $id ) || ! $id ) { return rk_builder_error( 'rk_server_error', 'Could not duplicate the page.', 500 ); }
	$layout = rk_builder_get_draft_layout( (int) $src->ID );
	$commit = rk_builder_with_lock( (int) $id, function () use ( $id, $layout ) { return rk_builder_commit_revision( (int) $id, 'draft', $layout ); } );
	if ( is_wp_error( $commit ) ) { return $commit; }
	$seo = rk_builder_seo_read( (int) $src->ID );
	unset( $seo['noindex'] );
	rk_builder_seo_write( (int) $id, $seo );
	return rk_builder_no_store( array( 'page' => rk_builder_dash_page_row( get_post( $id ) ) ) );
}

function rk_builder_handle_trash_page( $req ) {
	$page = rk_builder_get_page( $req['id'] );
	if ( ! current_user_can( 'delete_post', $page->ID ) ) { return rk_builder_forbidden(); }
	if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $page->ID ) {
		return rk_builder_error( 'rk_conflict', 'This is the site front page. Choose another front page first.', 409 );
	}
	$slug = (string) $page->post_name;
	if ( ! wp_trash_post( (int) $page->ID ) ) { return rk_builder_error( 'rk_server_error', 'Could not move the page to the trash.', 500 ); }
	rk_builder_revalidate( 'unpublish', (int) $page->ID, $slug );
	rk_builder_layout_changed( (int) $page->ID, 'unpublish' );
	return rk_builder_no_store( array( 'trashed' => (int) $page->ID ) );
}

function rk_builder_set_front_page( $id ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $id );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
}

function rk_builder_handle_front_page( $req ) {
	$page = rk_builder_get_page( $req['id'] );
	if ( ! current_user_can( 'manage_options' ) ) { return rk_builder_forbidden(); }
	if ( 'publish' !== $page->post_status ) { return rk_builder_error( 'rk_conflict', 'Publish the page before making it the front page.', 409 ); }
	rk_builder_set_front_page( (int) $page->ID );
	return rk_builder_no_store( array( 'page' => rk_builder_dash_page_row( get_post( $page->ID ) ) ) );
}

/* ------------------------------------------------------------------ *
 * SEO per page
 * ------------------------------------------------------------------ */

function rk_builder_handle_get_page_seo( $req ) {
	$page = rk_builder_get_page( $req['id'] );
	$seo  = rk_builder_seo_read( (int) $page->ID );
	return rk_builder_no_store( array( 'seo' => array(
		'title'       => isset( $seo['title'] ) ? $seo['title'] : '',
		'description' => isset( $seo['description'] ) ? $seo['description'] : '',
		'image'       => isset( $seo['image'] ) ? $seo['image'] : '',
		'noindex'     => ! empty( $seo['noindex'] ),
		'schema'      => rk_builder_schema_from_seo( $seo ),
		'pageTitle'   => rk_builder_plain( get_the_title( $page ) ),
	) ) );
}

function rk_builder_handle_set_page_seo( $req ) {
	$page = rk_builder_get_page( $req['id'] );
	$body = rk_builder_dash_body( $req, array( 'title', 'description', 'image', 'noindex', 'schema' ), 'rk_invalid_seo' );
	if ( is_wp_error( $body ) ) { return $body; }
	if ( array_key_exists( 'schema', $body ) && ! is_array( $body['schema'] ) ) { return rk_builder_invalid( 'rk_invalid_seo', array( array( 'path' => 'schema', 'message' => 'Expected an object' ) ) ); }
	$seo = rk_builder_seo_clean( $body );
	// The service / parent fields are managed by theme packages, not by this form.
	$old = rk_builder_seo_read( (int) $page->ID );
	foreach ( array( 'service', 'parent' ) as $keep ) { if ( isset( $old[ $keep ] ) ) { $seo[ $keep ] = $old[ $keep ]; } }
	if ( ! array_key_exists( 'schema', $body ) && isset( $old['schema'] ) ) { $seo['schema'] = $old['schema']; }
	rk_builder_seo_write( (int) $page->ID, $seo );
	rk_builder_revalidate( 'publish', (int) $page->ID, (string) $page->post_name );
	rk_builder_layout_changed( (int) $page->ID, 'publish' );
	return rk_builder_handle_get_page_seo( $req );
}

/* ------------------------------------------------------------------ *
 * Site settings
 * ------------------------------------------------------------------ */

/** Business details with every field present (strings; profiles as an object). */
function rk_builder_org_payload( array $org ) {
	$out = array();
	foreach ( array( 'name', 'telephone', 'email', 'description', 'logo', 'defaultImage', 'favicon', 'businessType', 'street', 'city', 'region', 'postal', 'country', 'hours', 'areaServed', 'priceRange' ) as $f ) { $out[ $f ] = isset( $org[ $f ] ) ? (string) $org[ $f ] : ''; }
	$out['profiles'] = array();
	foreach ( rk_builder_profile_keys() as $k ) { $out['profiles'][ $k ] = isset( $org['profiles'][ $k ] ) ? (string) $org['profiles'][ $k ] : ''; }
	return $out;
}

function rk_builder_site_settings() {
	$org   = rk_builder_seo_organization();
	$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	return array(
		'name'          => (string) get_bloginfo( 'name' ),
		'tagline'       => (string) get_bloginfo( 'description' ),
		'searchVisible' => '0' !== (string) get_option( 'blog_public', '1' ),
		'frontPageId'   => $front,
		'loginPageId'   => rk_builder_login_page_id(),
		'loginEnabled'  => rk_builder_login_enabled(),
		'loginImage'    => (string) get_option( RK_BUILDER_LOGIN_IMAGE_OPTION, '' ),
		'loginUrl'      => rk_builder_login_page_url(),
		'loginLink'     => rk_builder_login_page_link(),
		'organization'  => rk_builder_org_payload( $org ),
	);
}

function rk_builder_handle_get_site( $req ) {
	$pages = array();
	foreach ( rk_builder_dash_all_pages() as $p ) {
		if ( 'publish' === $p->post_status ) { $pages[] = array( 'id' => (int) $p->ID, 'title' => rk_builder_plain( get_the_title( $p ) ) ); }
	}
	return rk_builder_no_store( array( 'site' => rk_builder_site_settings(), 'pages' => $pages, 'loginPages' => rk_builder_login_candidates() ) );
}

function rk_builder_handle_set_site( $req ) {
	$body = rk_builder_dash_body( $req, array( 'name', 'tagline', 'searchVisible', 'frontPageId', 'loginPageId', 'loginEnabled', 'loginImage', 'organization' ), 'rk_invalid_site' );
	if ( is_wp_error( $body ) ) { return $body; }
	if ( isset( $body['name'] ) ) {
		$n = rk_builder_theme_text( $body['name'], 120 );
		if ( '' === $n ) { return rk_builder_invalid( 'rk_invalid_site', array( array( 'path' => 'name', 'message' => 'The site needs a title.' ) ) ); }
		update_option( 'blogname', $n );
	}
	if ( isset( $body['tagline'] ) ) { update_option( 'blogdescription', rk_builder_theme_text( $body['tagline'], 200 ) ); }
	if ( isset( $body['searchVisible'] ) && is_bool( $body['searchVisible'] ) ) { update_option( 'blog_public', $body['searchVisible'] ? '1' : '0' ); }
	if ( isset( $body['frontPageId'] ) ) {
		$fid = is_int( $body['frontPageId'] ) ? $body['frontPageId'] : -1;
		if ( 0 === $fid ) {
			update_option( 'show_on_front', 'posts' );
			update_option( 'page_on_front', 0 );
		} else {
			$p = $fid > 0 ? get_post( $fid ) : null;
			if ( ! $p || 'page' !== $p->post_type || 'publish' !== $p->post_status ) {
				return rk_builder_invalid( 'rk_invalid_site', array( array( 'path' => 'frontPageId', 'message' => 'Choose a published page.' ) ) );
			}
			rk_builder_set_front_page( $fid );
		}
	}
	if ( array_key_exists( 'loginPageId', $body ) ) {
		$lid = is_int( $body['loginPageId'] ) ? $body['loginPageId'] : -1;
		if ( 0 === $lid ) {
			delete_option( RK_BUILDER_LOGIN_OPTION );
		} else {
			$rk = $lid > 0 ? rk_builder_public_page( $lid ) : null;
			if ( null === $rk || ! rk_builder_layout_has_login( $rk['layout'] ) ) {
				return rk_builder_invalid( 'rk_invalid_site', array( array( 'path' => 'loginPageId', 'message' => 'Choose a published page that has the Sign-in form block, or create one.' ) ) );
			}
			update_option( RK_BUILDER_LOGIN_OPTION, $lid );
		}
	}
	if ( array_key_exists( 'loginImage', $body ) ) {
		$img = is_string( $body['loginImage'] ) ? trim( $body['loginImage'] ) : null;
		if ( null === $img || ( '' !== $img && ! rk_builder_is_safe_image_url( $img, null ) ) ) {
			return rk_builder_invalid( 'rk_invalid_site', array( array( 'path' => 'loginImage', 'message' => 'Use an image from the media library or an http(s) image address.' ) ) );
		}
		if ( '' === $img ) { delete_option( RK_BUILDER_LOGIN_IMAGE_OPTION ); } else { update_option( RK_BUILDER_LOGIN_IMAGE_OPTION, esc_url_raw( $img, array( 'http', 'https' ) ) ); }
	}
	if ( array_key_exists( 'loginEnabled', $body ) ) {
		if ( ! is_bool( $body['loginEnabled'] ) ) { return rk_builder_invalid( 'rk_invalid_site', array( array( 'path' => 'loginEnabled', 'message' => 'Expected true or false' ) ) ); }
		update_option( RK_BUILDER_LOGIN_ENABLED_OPTION, $body['loginEnabled'] ? '1' : '0' );
	}
	if ( isset( $body['organization'] ) ) { rk_builder_seo_organization_save( $body['organization'] ); }
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return rk_builder_handle_get_site( $req );
}

/* ------------------------------------------------------------------ *
 * Visualizer admin
 * ------------------------------------------------------------------ */

function rk_builder_viz_admin_payload() {
	$s = rk_builder_viz_settings();
	$public = $s;
	foreach ( array( 'hf_token', 'custom_key', 'gemini_key' ) as $secret ) {
		$public[ $secret . '_set' ] = '' !== (string) $s[ $secret ];
		unset( $public[ $secret ] );
	}
	$public['hf_token_env']    = '' !== rk_builder_viz_hf_token( array_merge( $s, array( 'hf_token' => '' ) ) );
	$public['gemini_key_env']  = '' !== rk_builder_viz_gemini_key( array_merge( $s, array( 'gemini_key' => '' ) ) );
	return array(
		'settings'  => $public,
		'providers' => rk_builder_viz_providers(),
		'ready'     => rk_builder_viz_ready( $s ),
		'leads'     => array_map( function ( $l ) {
			return array(
				'name'  => isset( $l['name'] ) ? (string) $l['name'] : '',
				'email' => isset( $l['email'] ) ? (string) $l['email'] : '',
				'phone' => isset( $l['phone'] ) ? (string) $l['phone'] : '',
				'at'    => isset( $l['at'] ) ? (string) $l['at'] : '',
			);
		}, rk_builder_viz_leads() ),
	);
}

function rk_builder_handle_get_viz_admin( $req ) { return rk_builder_no_store( rk_builder_viz_admin_payload() ); }

function rk_builder_handle_set_viz_admin( $req ) {
	$body = rk_builder_json_body( $req, 'rk_invalid_settings' );
	if ( is_wp_error( $body ) ) { return $body; }
	// Secrets arrive only when the user typed one; a blank value keeps what is stored.
	update_option( 'rk_builder_visualizer', rk_builder_viz_sanitize( $body ) );
	return rk_builder_no_store( rk_builder_viz_admin_payload() );
}

function rk_builder_handle_delete_viz_leads( $req ) {
	$body = rk_builder_dash_body( $req, array( 'email', 'all' ), 'rk_invalid_settings' );
	if ( is_wp_error( $body ) ) { return $body; }
	$all = ! empty( $body['all'] );
	if ( ! $all && ( ! isset( $body['email'] ) || ! is_string( $body['email'] ) || '' === trim( $body['email'] ) ) ) {
		return rk_builder_invalid( 'rk_invalid_settings', array( array( 'path' => 'email', 'message' => 'Say which lead to delete.' ) ) );
	}
	rk_builder_viz_delete_leads( isset( $body['email'] ) ? $body['email'] : '', $all );
	return rk_builder_no_store( rk_builder_viz_admin_payload() );
}

/** Routes (called from rk_builder_register_routes()). */
/* ------------------------------------------------------------------ *
 * Media: the fields search engines read (alt, title, caption, description), and removal
 * ------------------------------------------------------------------ */

function rk_builder_perm_media_item( $req ) { return rk_builder_authorize_media( $req, 'edit_post' ); }
function rk_builder_perm_media_delete( $req ) { return rk_builder_authorize_media( $req, 'delete_post' ); }

function rk_builder_authorize_media( $req, $cap ) {
	$gate = rk_builder_authorize_caps( array( 'upload_files' ) );
	if ( true !== $gate ) { return $gate; }
	$att = get_post( isset( $req['id'] ) ? (int) $req['id'] : 0 );
	if ( ! $att || 'attachment' !== $att->post_type ) { return rk_builder_not_found( 'Image not found.' ); }
	return current_user_can( $cap, $att->ID ) ? true : rk_builder_forbidden();
}

function rk_builder_handle_update_media( $req ) {
	$att  = get_post( (int) $req['id'] );
	$body = rk_builder_dash_body( $req, array( 'alt', 'title', 'caption', 'description' ), 'rk_invalid_media' );
	if ( is_wp_error( $body ) ) { return $body; }
	$limits = array( 'alt' => 400, 'title' => 200, 'caption' => 500, 'description' => 2000 );
	$issues = array();
	foreach ( $limits as $k => $max ) {
		if ( array_key_exists( $k, $body ) && ! is_string( $body[ $k ] ) ) { rk_builder_add_issue( $issues, $k, 'Expected text' ); }
	}
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_media', $issues ); }
	$clean = function ( $k ) use ( $body, $limits ) { return rk_builder_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $body[ $k ] ) ) ), 0, $limits[ $k ] ); };
	$post  = array( 'ID' => (int) $att->ID );
	if ( array_key_exists( 'title', $body ) ) { $post['post_title'] = $clean( 'title' ); }
	if ( array_key_exists( 'caption', $body ) ) { $post['post_excerpt'] = $clean( 'caption' ); }
	if ( array_key_exists( 'description', $body ) ) { $post['post_content'] = $clean( 'description' ); }
	if ( count( $post ) > 1 && ! wp_update_post( wp_slash( $post ) ) ) { return rk_builder_error( 'rk_server_error', 'Could not save the image.', 500 ); }
	if ( array_key_exists( 'alt', $body ) ) {
		$alt = $clean( 'alt' );
		if ( '' === $alt ) { delete_post_meta( (int) $att->ID, '_wp_attachment_image_alt' ); } else { update_post_meta( (int) $att->ID, '_wp_attachment_image_alt', wp_slash( $alt ) ); }
	}
	rk_builder_purge_all_public_cache_if_any();
	return rk_builder_no_store( array( 'item' => rk_builder_media_item( (int) $att->ID, true ) ) );
}

/** Removes the file and its sizes for good (WordPress has no trash for media unless MEDIA_TRASH is set). */
function rk_builder_handle_delete_media( $req ) {
	$att = get_post( (int) $req['id'] );
	if ( ! wp_delete_attachment( (int) $att->ID, true ) ) { return rk_builder_error( 'rk_server_error', 'Could not delete the image.', 500 ); }
	rk_builder_purge_all_public_cache_if_any();
	return rk_builder_no_store( array( 'deleted' => (int) $att->ID ) );
}

function rk_builder_purge_all_public_cache_if_any() {
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
}

function rk_builder_register_dashboard_routes( $ns ) {
	$id    = array( 'id' => array( 'type' => 'integer', 'required' => true ) );
	$admin = 'rk_builder_perm_theme_write';
	$edit  = 'rk_builder_perm_edit_real_page';
	register_rest_route( $ns, '/builder/media/(?P<id>\\d+)', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_update_media', 'permission_callback' => 'rk_builder_perm_media_item', 'args' => $id ) );
	register_rest_route( $ns, '/builder/media/(?P<id>\\d+)/delete', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_delete_media', 'permission_callback' => 'rk_builder_perm_media_delete', 'args' => $id ) );
	register_rest_route( $ns, '/builder/overview', array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_overview', 'permission_callback' => 'rk_builder_perm_list_pages' ) );
	register_rest_route( $ns, '/builder/pages/new', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_create_page', 'permission_callback' => 'rk_builder_perm_list_pages' ) );
	register_rest_route( $ns, '/builder/pages/(?P<id>\d+)/update', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_update_page_meta', 'permission_callback' => $edit, 'args' => $id ) );
	register_rest_route( $ns, '/builder/pages/(?P<id>\d+)/duplicate', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_duplicate_page', 'permission_callback' => $edit, 'args' => $id ) );
	register_rest_route( $ns, '/builder/pages/(?P<id>\d+)/trash', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_trash_page', 'permission_callback' => $edit, 'args' => $id ) );
	register_rest_route( $ns, '/builder/pages/(?P<id>\d+)/front', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_front_page', 'permission_callback' => $edit, 'args' => $id ) );
	register_rest_route( $ns, '/builder/pages/(?P<id>\d+)/seo', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_page_seo', 'permission_callback' => $edit, 'args' => $id ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_page_seo', 'permission_callback' => $edit, 'args' => $id ),
	) );
	register_rest_route( $ns, '/builder/login/create', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_create_login_page', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/builder/site', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_site', 'permission_callback' => $admin ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_site', 'permission_callback' => $admin ),
	) );
	register_rest_route( $ns, '/builder/visualizer-admin', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_viz_admin', 'permission_callback' => $admin ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_viz_admin', 'permission_callback' => $admin ),
	) );
	register_rest_route( $ns, '/builder/visualizer-admin/leads/delete', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_delete_viz_leads', 'permission_callback' => $admin ) );
}
