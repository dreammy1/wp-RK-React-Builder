<?php
/**
 * Authenticated builder endpoints: page list, layout load/save, revisions, publish/unpublish,
 * preview tokens, media and the global theme.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_mysql_gmt_to_iso( $mysql ) {
	$ts = ( is_string( $mysql ) && '' !== $mysql && 0 !== strpos( $mysql, '0000' ) ) ? strtotime( $mysql . ' UTC' ) : false;
	return rk_builder_iso( false === $ts ? 0 : $ts );
}

/** Last time the builder or WordPress changed the page, ISO8601 UTC. */
function rk_builder_page_modified( $page ) {
	$saved = rk_builder_last_saved_at( $page->ID );
	return null !== $saved ? $saved : rk_builder_mysql_gmt_to_iso( $page->post_modified_gmt );
}

function rk_builder_plain( $html ) {
	return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
}

function rk_builder_save_response( $page_id, array $commit ) {
	$page = get_post( $page_id );
	return rk_builder_no_store( array(
		'ok'        => true,
		'pageId'    => (int) $page_id,
		'revision'  => $commit['revision'],
		'status'    => $page ? $page->post_status : 'draft',
		'updatedAt' => $commit['savedAt'],
	) );
}

/* ------------------------------------------------------------------ *
 * GET /builder/pages
 * ------------------------------------------------------------------ */

function rk_builder_handle_list_pages( $req ) {
	$per_page = (int) $req->get_param( 'per_page' );
	$per_page = $per_page >= 1 ? min( 100, $per_page ) : 20;
	$paged    = max( 1, (int) $req->get_param( 'page' ) );
	$status   = $req->get_param( 'status' );
	$statuses = ( is_string( $status ) && '' !== $status && 'any' !== $status ) ? array( $status ) : array( 'publish', 'draft', 'pending', 'private', 'future' );
	$args     = array(
		'post_type'      => 'page',
		'post_status'    => $statuses,
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	$search = $req->get_param( 'search' );
	if ( is_string( $search ) && '' !== trim( $search ) ) { $args['s'] = trim( $search ); }
	if ( ! current_user_can( 'edit_others_pages' ) ) { $args['author'] = get_current_user_id(); }

	$query = new WP_Query( $args );
	$pages = array();
	foreach ( $query->posts as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) { continue; }
		$pages[] = array(
			'id'                => (int) $post->ID,
			'title'             => rk_builder_plain( get_the_title( $post ) ),
			'slug'              => (string) $post->post_name,
			'status'            => (string) $post->post_status,
			'modified'          => rk_builder_page_modified( $post ),
			'revision'          => rk_builder_get_revision( $post->ID ),
			'publishedRevision' => rk_builder_get_published_revision( $post->ID ),
		);
	}
	return rk_builder_no_store( array( 'pages' => $pages, 'total' => (int) $query->found_posts ) );
}

/* ------------------------------------------------------------------ *
 * GET/POST /builder/layout/{id}
 * ------------------------------------------------------------------ */

function rk_builder_handle_get_layout( $req ) {
	$page = rk_builder_get_page( $req['id'] );
	if ( ! $page ) { return rk_builder_not_found( 'Page not found.' ); }
	return rk_builder_no_store( array(
		'page'              => array(
			'id'     => (int) $page->ID,
			'title'  => rk_builder_plain( get_the_title( $page ) ),
			'slug'   => (string) $page->post_name,
			'status' => (string) $page->post_status,
			'link'   => (string) get_permalink( $page->ID ),
		),
		'layout'            => rk_builder_get_draft_layout( $page->ID ),
		'theme'             => rk_builder_theme_for_output( rk_builder_get_theme() ),
		'revision'          => rk_builder_get_revision( $page->ID ),
		'publishedRevision' => rk_builder_get_published_revision( $page->ID ),
		'updatedAt'         => rk_builder_page_modified( $page ),
		'capabilities'      => array(
			'manageTheme' => (bool) current_user_can( 'manage_options' ),
			'publish'     => (bool) current_user_can( 'publish_post', $page->ID ),
		),
	) );
}

function rk_builder_handle_save_layout( $req ) {
	$page = rk_builder_get_page( $req['id'] );
	if ( ! $page ) { return rk_builder_not_found( 'Page not found.' ); }
	$page_id = (int) $page->ID;

	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req );
	if ( is_wp_error( $body ) ) { return $body; }

	$issues = array();
	foreach ( $body as $k => $_ ) {
		if ( ! in_array( (string) $k, array( 'layout', 'theme', 'expectedRevision', 'status' ), true ) ) {
			rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' );
		}
	}
	if ( ! array_key_exists( 'status', $body ) ) {
		rk_builder_add_issue( $issues, 'status', 'Required' );
	} elseif ( 'draft' !== $body['status'] ) {
		rk_builder_add_issue( $issues, 'status', 'Only "draft" can be saved here. Use the publish endpoint to publish.' );
	}
	$expected = rk_builder_read_expected_revision( $body, $issues );

	$hosts        = rk_builder_allowed_image_hosts();
	$layout       = null;
	$layout_issue = array();
	if ( ! array_key_exists( 'layout', $body ) ) {
		rk_builder_add_issue( $layout_issue, 'layout', 'Required' );
	} else {
		$layout_issue = rk_builder_prefix_issues( rk_builder_validate_layout( $body['layout'], $hosts ), 'layout' );
		if ( ! $layout_issue ) { $layout = rk_builder_canonicalize_layout( $body['layout'] ); }
	}
	$theme       = null;
	$theme_issue = array();
	if ( array_key_exists( 'theme', $body ) ) {
		$theme_issue = rk_builder_prefix_issues( rk_builder_validate_theme( $body['theme'], $hosts ), 'theme' );
		if ( ! $theme_issue ) { $theme = rk_builder_canonicalize_theme( $body['theme'] ); }
	}
	if ( $issues || $layout_issue ) {
		return rk_builder_invalid( 'rk_invalid_layout', array_merge( $issues, $layout_issue, $theme_issue ) );
	}
	if ( $theme_issue ) { return rk_builder_invalid( 'rk_invalid_theme', $theme_issue ); }

	$theme_changed = false;
	if ( null !== $theme && ! rk_builder_deep_equal( $theme, rk_builder_get_theme() ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return rk_builder_forbidden( 'Only administrators can change the site theme.' );
		}
		$theme_changed = true;
	}

	$commit = rk_builder_with_lock( $page_id, function () use ( $page_id, $expected, $layout, $theme, $theme_changed ) {
		if ( rk_builder_get_revision( $page_id ) !== $expected ) { return rk_builder_conflict( $page_id ); }
		$commit = rk_builder_commit_revision( $page_id, 'draft', $layout );
		if ( $theme_changed ) { rk_builder_store_theme( $theme ); }
		return $commit;
	} );
	if ( is_wp_error( $commit ) ) { return $commit; }
	if ( $theme_changed ) { rk_builder_revalidate( 'theme', 0, '' ); }
	return rk_builder_save_response( $page_id, $commit );
}

/* ------------------------------------------------------------------ *
 * Revisions
 * ------------------------------------------------------------------ */

function rk_builder_handle_list_revisions( $req ) {
	$records = rk_builder_get_revision_records( (int) $req['id'] );
	$out     = array();
	foreach ( array_reverse( $records ) as $r ) { $out[] = rk_builder_revision_summary( $r ); }
	return rk_builder_no_store( array( 'revisions' => $out, 'retained' => count( $out ) ) );
}

function rk_builder_handle_get_revision( $req ) {
	$record = rk_builder_find_revision_record( (int) $req['id'], (int) $req['revisionId'] );
	if ( ! $record ) { return rk_builder_not_found( 'Revision not found (it may have been pruned).' ); }
	$layout = rk_builder_clean_stored_layout( $record['layout'] );
	if ( null === $layout ) { return rk_builder_error( 'rk_server_error', 'This revision is corrupt.', 500 ); }
	return rk_builder_no_store( array( 'revision' => rk_builder_revision_summary( $record ), 'layout' => $layout ) );
}

function rk_builder_handle_restore_revision( $req ) {
	$page_id = (int) $req['id'];
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues   = array();
	$expected = rk_builder_read_expected_revision( $body, $issues );
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_layout', $issues ); }

	$commit = rk_builder_with_lock( $page_id, function () use ( $page_id, $req, $expected ) {
		if ( rk_builder_get_revision( $page_id ) !== $expected ) { return rk_builder_conflict( $page_id ); }
		$record = rk_builder_find_revision_record( $page_id, (int) $req['revisionId'] );
		if ( ! $record ) { return rk_builder_not_found( 'Revision not found (it may have been pruned).' ); }
		$issues = rk_builder_validate_layout( $record['layout'], null );
		if ( $issues ) { return rk_builder_invalid( 'rk_invalid_layout', rk_builder_prefix_issues( $issues, 'layout' ), 'The stored revision is not a valid layout.' ); }
		return rk_builder_commit_revision( $page_id, 'restore', rk_builder_canonicalize_layout( $record['layout'] ) );
	} );
	if ( is_wp_error( $commit ) ) { return $commit; }
	return rk_builder_save_response( $page_id, $commit );
}

/* ------------------------------------------------------------------ *
 * Publish / unpublish / preview token
 * ------------------------------------------------------------------ */

function rk_builder_handle_publish( $req ) {
	$page_id = (int) $req['id'];
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues   = array();
	$expected = rk_builder_read_expected_revision( $body, $issues );
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_layout', $issues ); }

	$commit = rk_builder_with_lock( $page_id, function () use ( $page_id, $expected ) {
		if ( rk_builder_get_revision( $page_id ) !== $expected ) { return rk_builder_conflict( $page_id ); }
		$raw = rk_builder_get_raw_draft( $page_id );
		if ( false === $raw ) { $raw = rk_builder_empty_layout(); }
		$issues = rk_builder_validate_layout( $raw, rk_builder_allowed_image_hosts() );
		if ( $issues ) {
			return rk_builder_invalid( 'rk_invalid_layout', rk_builder_prefix_issues( $issues, 'layout' ), 'The stored draft is not valid and cannot be published.' );
		}
		$updated = wp_update_post( array( 'ID' => $page_id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $updated ) || ! $updated ) {
			return rk_builder_error( 'rk_server_error', 'Could not publish the page.', 500 );
		}
		return rk_builder_commit_revision( $page_id, 'publish', rk_builder_canonicalize_layout( $raw ), array( 'published' => true ) );
	} );
	if ( is_wp_error( $commit ) ) { return $commit; }

	$page = get_post( $page_id );
	rk_builder_revalidate( 'publish', $page_id, $page ? (string) $page->post_name : '' );
	return rk_builder_no_store( array(
		'ok'                => true,
		'pageId'            => $page_id,
		'revision'          => $commit['revision'],
		'status'            => 'publish',
		'updatedAt'         => $commit['savedAt'],
		'publishedRevision' => $commit['revision'],
		'publishedAt'       => $commit['savedAt'],
		'link'              => (string) get_permalink( $page_id ),
	) );
}

function rk_builder_handle_unpublish( $req ) {
	$page_id = (int) $req['id'];
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = $req->get_json_params();
	$body = is_array( $body ) ? $body : array();
	$expected = null;
	if ( array_key_exists( 'expectedRevision', $body ) ) {
		$issues   = array();
		$expected = rk_builder_read_expected_revision( $body, $issues );
		if ( $issues ) { return rk_builder_invalid( 'rk_invalid_layout', $issues ); }
	}

	$commit = rk_builder_with_lock( $page_id, function () use ( $page_id, $expected ) {
		if ( null !== $expected && rk_builder_get_revision( $page_id ) !== $expected ) { return rk_builder_conflict( $page_id ); }
		$page = get_post( $page_id );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return array( 'revision' => rk_builder_get_revision( $page_id ), 'savedAt' => rk_builder_iso( rk_builder_now() ), 'noop' => true );
		}
		$updated = wp_update_post( array( 'ID' => $page_id, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $updated ) || ! $updated ) {
			return rk_builder_error( 'rk_server_error', 'Could not unpublish the page.', 500 );
		}
		return rk_builder_commit_revision( $page_id, 'unpublish', rk_builder_get_draft_layout( $page_id ) );
	} );
	if ( is_wp_error( $commit ) ) { return $commit; }
	if ( empty( $commit['noop'] ) ) {
		$page = get_post( $page_id );
		rk_builder_revalidate( 'unpublish', $page_id, $page ? (string) $page->post_name : '' );
	}
	return rk_builder_save_response( $page_id, $commit );
}

function rk_builder_handle_preview_token( $req ) {
	return rk_builder_no_store( rk_builder_create_preview_token( (int) $req['id'] ) );
}

/* ------------------------------------------------------------------ *
 * Media
 * ------------------------------------------------------------------ */

function rk_builder_handle_media( $req ) {
	$per_page = (int) $req->get_param( 'per_page' );
	$per_page = $per_page >= 1 ? min( 50, $per_page ) : 20;
	$args     = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image',
		'posts_per_page' => $per_page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	$search = $req->get_param( 'search' );
	if ( is_string( $search ) && '' !== trim( $search ) ) { $args['s'] = trim( $search ); }
	$items = array();
	foreach ( get_posts( $args ) as $att ) {
		$src = wp_get_attachment_image_src( $att->ID, 'full' );
		if ( ! $src ) { continue; }
		$item = array(
			'id'    => (int) $att->ID,
			'url'   => (string) $src[0],
			'alt'   => rk_builder_plain( get_post_meta( $att->ID, '_wp_attachment_image_alt', true ) ),
			'title' => rk_builder_plain( $att->post_title ),
		);
		if ( ! empty( $src[1] ) ) { $item['width'] = (int) $src[1]; }
		if ( ! empty( $src[2] ) ) { $item['height'] = (int) $src[2]; }
		$srcset = wp_get_attachment_image_srcset( $att->ID, 'full' );
		if ( is_string( $srcset ) && '' !== $srcset ) { $item['srcset'] = $srcset; }
		$items[] = $item;
	}
	return rk_builder_no_store( array( 'items' => $items ) );
}

/* ------------------------------------------------------------------ *
 * Theme
 * ------------------------------------------------------------------ */

function rk_builder_handle_get_theme( $req ) {
	$response = rest_ensure_response( rk_builder_theme_for_output( rk_builder_get_theme() ) );
	$response->header( 'Cache-Control', 'public, max-age=0, s-maxage=60' );
	return $response;
}

function rk_builder_handle_save_theme( $req ) {
	$too_big = rk_builder_check_payload( $req );
	if ( $too_big ) { return $too_big; }
	$body = rk_builder_json_body( $req, 'rk_invalid_theme' );
	if ( is_wp_error( $body ) ) { return $body; }
	$issues = rk_builder_validate_theme( $body, rk_builder_allowed_image_hosts() );
	if ( $issues ) { return rk_builder_invalid( 'rk_invalid_theme', $issues ); }
	$theme = rk_builder_canonicalize_theme( $body );
	rk_builder_store_theme( $theme );
	rk_builder_revalidate( 'theme', 0, '' );
	return rk_builder_no_store( array( 'ok' => true, 'theme' => rk_builder_theme_for_output( $theme ) ) );
}
