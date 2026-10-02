<?php
/**
 * Public cache invalidation (spec §16).
 *
 * Actions other code (and hosts) can hook:
 *   rk_builder_layout_changed( $page_id, $reason )   reason: publish|unpublish|restore|status|delete|content
 *   rk_builder_theme_changed()
 *   rk_builder_public_cache_purge( $page_id )         fired once per purged page (0 = "everything RK")
 *
 * The public output is correct with no cache plugin at all; this only tells caches to drop stale HTML.
 * Third-party purge APIs run only when setting `cache_purge` is on, only when they exist, and every
 * call is isolated: a failing cache plugin can never break a save.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------------ *
 * Firing (called from publish/unpublish/restore/theme handlers and the content hooks)
 * ------------------------------------------------------------------ */

function rk_builder_fire( $tag, array $args ) {
	try {
		call_user_func_array( 'do_action', array_merge( array( $tag ), $args ) );
	} catch ( Throwable $e ) {
		rk_builder_log( 'warning', 'hook_failed', array( 'hook' => $tag, 'error' => get_class( $e ), 'message' => $e->getMessage() ) );
	}
}

function rk_builder_layout_changed( $page_id, $reason ) {
	rk_builder_fire( 'rk_builder_layout_changed', array( (int) $page_id, (string) $reason ) );
}

function rk_builder_theme_changed() {
	rk_builder_fire( 'rk_builder_theme_changed', array() );
}

/* ------------------------------------------------------------------ *
 * Purging
 * ------------------------------------------------------------------ */

/** Run one third-party integration; any throwable is logged and swallowed. */
function rk_builder_guarded( $label, $fn ) {
	try {
		call_user_func( $fn );
	} catch ( Throwable $e ) {
		rk_builder_log( 'warning', 'cache_purge_failed', array( 'integration' => $label, 'error' => get_class( $e ), 'message' => $e->getMessage() ) );
	}
}

/** Drop cached HTML for one page. $page_id 0 means "all RK pages" for hosts that cannot target a post. */
function rk_builder_purge_page_cache( $page_id ) {
	$page_id = (int) $page_id;
	if ( $page_id > 0 ) {
		if ( function_exists( 'clean_post_cache' ) ) { rk_builder_guarded( 'core', function () use ( $page_id ) { clean_post_cache( $page_id ); } ); }
		if ( rk_builder_setting( 'cache_purge', true ) ) {
			if ( function_exists( 'wp_cache_post_change' ) ) { rk_builder_guarded( 'wp-super-cache', function () use ( $page_id ) { wp_cache_post_change( $page_id ); } ); }
			if ( function_exists( 'w3tc_flush_post' ) ) { rk_builder_guarded( 'w3-total-cache', function () use ( $page_id ) { w3tc_flush_post( $page_id ); } ); }
			if ( function_exists( 'rocket_clean_post' ) ) { rk_builder_guarded( 'wp-rocket', function () use ( $page_id ) { rocket_clean_post( $page_id ); } ); }
			rk_builder_guarded( 'litespeed', function () use ( $page_id ) { do_action( 'litespeed_purge_post', $page_id ); } );
		}
	}
	rk_builder_guarded( 'host-hook', function () use ( $page_id ) { do_action( 'rk_builder_public_cache_purge', $page_id ); } );
}

/** IDs of published pages that carry a published RK snapshot (capped; cheap query). */
function rk_builder_published_page_ids() {
	$posts = get_posts( array(
		'post_type'              => 'page',
		'post_status'            => 'publish',
		'posts_per_page'         => 1000,
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'fields'                 => 'ids',
		'meta_key'               => '_rk_layout_published',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'suppress_filters'       => true,
	) );
	$ids = array();
	foreach ( is_array( $posts ) ? $posts : array() as $p ) {
		$id = is_object( $p ) ? (int) $p->ID : (int) $p;
		if ( $id > 0 && '' !== (string) get_post_meta( $id, '_rk_layout_published', true ) ) { $ids[] = $id; }
	}
	return $ids;
}

/**
 * Purge every RK page (theme/content/media changes affect all of them). Also used by the settings
 * screen's "purge now" button.
 *
 * @return int Number of pages purged.
 */
function rk_builder_purge_all_public_cache() {
	$ids = rk_builder_published_page_ids();
	foreach ( $ids as $id ) { rk_builder_purge_page_cache( $id ); }
	rk_builder_guarded( 'host-hook', function () { do_action( 'rk_builder_public_cache_purge', 0 ); } );
	return count( $ids );
}

/* ------------------------------------------------------------------ *
 * Listeners
 * ------------------------------------------------------------------ */

function rk_builder_on_layout_changed( $page_id, $reason = '' ) {
	rk_builder_purge_page_cache( $page_id );
	// A template decides how many entries look: purge every RK page and the host's caches, not just this post.
	if ( 'rk_template' === get_post_type( $page_id ) ) { rk_builder_purge_all_public_cache(); }
}

function rk_builder_on_theme_changed() {
	rk_builder_purge_all_public_cache();
}

/** Services/portfolio/media changed: purge all RK pages once per request, at shutdown, and tell the Node frontend. */
function rk_builder_content_changed( $reason = 'content' ) {
	rk_builder_pending_content_purge( true );
	add_action( 'shutdown', 'rk_builder_flush_content_purge' );
}

function rk_builder_pending_content_purge( $set = null ) {
	static $pending = false;
	if ( null !== $set ) { $pending = (bool) $set; }
	return $pending;
}

function rk_builder_flush_content_purge() {
	if ( ! rk_builder_pending_content_purge() ) { return; }
	rk_builder_pending_content_purge( false );
	rk_builder_guarded( 'purge-all', function () { rk_builder_purge_all_public_cache(); } );
	rk_builder_guarded( 'revalidate', function () { rk_builder_revalidate( 'content', 0, '' ); } );
}

/** Real post types whose output the grids depend on. */
function rk_builder_is_content_post( $post ) {
	return is_object( $post ) && isset( $post->post_type ) && in_array( $post->post_type, array( 'service', 'portfolio', 'attachment' ), true );
}

function rk_builder_on_save_content_post( $post_id, $post = null, $update = true ) {
	if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	rk_builder_content_changed( 'content' );
}

function rk_builder_on_deleted_post( $post_id, $post = null ) {
	// WP < 5.5 passes no post object: purge to be safe.
	if ( null === $post || rk_builder_is_content_post( $post ) ) { rk_builder_content_changed( 'content' ); }
}

function rk_builder_on_before_delete_post( $post_id, $post = null ) {
	$post = is_object( $post ) ? $post : get_post( $post_id );
	if ( $post && 'page' === $post->post_type && '' !== (string) get_post_meta( $post_id, '_rk_layout_published', true ) ) {
		rk_builder_layout_changed( (int) $post_id, 'delete' );
	}
}

function rk_builder_on_trash_post( $post_id ) {
	rk_builder_on_before_delete_post( $post_id );
}

/** A page that has a published RK snapshot entered/left "publish" outside the builder (quick edit, bulk, trash). */
function rk_builder_on_transition_post_status( $new, $old, $post ) {
	if ( $new === $old || ! is_object( $post ) || 'page' !== $post->post_type ) { return; }
	if ( 'publish' !== $new && 'publish' !== $old ) { return; }
	if ( '' === (string) get_post_meta( $post->ID, '_rk_layout_published', true ) ) { return; }
	rk_builder_layout_changed( (int) $post->ID, 'status' );
}

/** Featured image / alt text changes. */
function rk_builder_on_post_meta_change( $meta_id, $post_id, $meta_key = '', $value = null ) {
	if ( '_thumbnail_id' !== $meta_key && '_wp_attachment_image_alt' !== $meta_key ) { return; }
	$post = get_post( $post_id );
	if ( ! $post ) { return; }
	if ( 'page' === $post->post_type ) {
		if ( '' !== (string) get_post_meta( $post_id, '_rk_layout_published', true ) ) { rk_builder_layout_changed( (int) $post_id, 'content' ); }
	} elseif ( rk_builder_is_content_post( $post ) ) {
		rk_builder_content_changed( 'content' );
	}
}

function rk_builder_on_edit_attachment( $post_id ) {
	rk_builder_content_changed( 'content' );
}

function rk_builder_on_set_object_terms( $object_id, $terms = null, $tt_ids = null, $taxonomy = '' ) {
	if ( 'service_cat' === $taxonomy || 'portfolio_cat' === $taxonomy ) { rk_builder_content_changed( 'content' ); }
}

function rk_builder_register_cache_hooks() {
	add_action( 'rk_builder_layout_changed', 'rk_builder_on_layout_changed', 10, 2 );
	add_action( 'rk_builder_theme_changed', 'rk_builder_on_theme_changed', 10, 0 );
	add_action( 'save_post_service', 'rk_builder_on_save_content_post', 10, 3 );
	add_action( 'save_post_portfolio', 'rk_builder_on_save_content_post', 10, 3 );
	add_action( 'deleted_post', 'rk_builder_on_deleted_post', 10, 2 );
	add_action( 'before_delete_post', 'rk_builder_on_before_delete_post', 10, 2 );
	add_action( 'wp_trash_post', 'rk_builder_on_trash_post', 10, 1 );
	add_action( 'transition_post_status', 'rk_builder_on_transition_post_status', 10, 3 );
	add_action( 'edit_attachment', 'rk_builder_on_edit_attachment', 10, 1 );
	add_action( 'updated_post_meta', 'rk_builder_on_post_meta_change', 10, 4 );
	add_action( 'added_post_meta', 'rk_builder_on_post_meta_change', 10, 4 );
	add_action( 'deleted_post_meta', 'rk_builder_on_post_meta_change', 10, 4 );
	add_action( 'set_object_terms', 'rk_builder_on_set_object_terms', 10, 4 );
}
rk_builder_register_cache_hooks();
