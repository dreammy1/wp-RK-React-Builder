<?php
/**
 * Storage: page meta (layouts + revisions), global theme option and the per-page save lock.
 *
 * Meta keys on the page post:
 *   _rk_layout_draft        JSON  working (draft) layout
 *   _rk_layout_published    JSON  published snapshot (never changed by draft saves)
 *   _rk_revision            int   current revision number (monotonic)
 *   _rk_published_revision  int   revision number of the published snapshot
 *   _rk_published_at        string ISO8601 UTC of the last publish
 *   _rk_revisions           JSON  list (oldest first) of up to N records {id,kind,savedAt,author_id,layout}
 *
 * Writes always go through wp_slash( wp_json_encode() ) because update_post_meta() unslashes.
 * Reads json_decode() and validate; corrupt data degrades to an empty layout, never a fatal.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_MAX_REVISIONS' ) ) { define( 'RK_BUILDER_MAX_REVISIONS', 20 ); }
if ( ! defined( 'RK_BUILDER_LOCK_TTL' ) ) { define( 'RK_BUILDER_LOCK_TTL', 30 ); }

function rk_builder_now() {
	return (int) apply_filters( 'rk_builder_now', time() );
}

function rk_builder_iso( $ts ) {
	return gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
}

/** Retention: last N revisions (default 20). Filter `rk_builder_max_revisions`, clamped to 1..200. */
function rk_builder_max_revisions() {
	$n = (int) apply_filters( 'rk_builder_max_revisions', RK_BUILDER_MAX_REVISIONS );
	return max( 1, min( 200, $n ) );
}

/**
 * Hosts allowed in absolute image URLs: the site's own host, the uploads host, plus the
 * RK_BUILDER_ALLOWED_IMAGE_HOSTS constant and the `rk_builder_allowed_image_hosts` filter.
 */
function rk_builder_allowed_image_hosts() {
	$hosts = array();
	foreach ( array( home_url(), site_url() ) as $url ) {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		$p = wp_parse_url( $url, PHP_URL_PORT );
		if ( $h ) {
			$hosts[] = $h;
			if ( $p ) { $hosts[] = $h . ':' . $p; }
		}
	}
	if ( defined( 'RK_BUILDER_ALLOWED_IMAGE_HOSTS' ) ) {
		$const = RK_BUILDER_ALLOWED_IMAGE_HOSTS;
		$hosts = array_merge( $hosts, is_array( $const ) ? $const : explode( ',', (string) $const ) );
	}
	$hosts = apply_filters( 'rk_builder_allowed_image_hosts', $hosts );
	return rk_builder_normalize_hosts( is_array( $hosts ) ? $hosts : array() );
}

/* ------------------------------------------------------------------ *
 * JSON meta helpers
 * ------------------------------------------------------------------ */

function rk_builder_write_json_meta( $page_id, $key, $data ) {
	$json = wp_json_encode( $data );
	if ( false === $json || null === $json ) { return false; }
	update_post_meta( $page_id, $key, wp_slash( $json ) );
	return true;
}

function rk_builder_read_json_meta( $page_id, $key ) {
	$raw = get_post_meta( $page_id, $key, true );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$data = json_decode( $raw, true );
	return ( JSON_ERROR_NONE === json_last_error() ) ? $data : null;
}

/** Drop cached meta so a read inside the lock sees what other PHP processes committed. */
function rk_builder_flush_meta_cache( $page_id ) {
	if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( (int) $page_id, 'post_meta' ); }
}

/* ------------------------------------------------------------------ *
 * Layouts
 * ------------------------------------------------------------------ */

/** Validated + canonical layout from decoded data, or null if invalid (structure only). */
function rk_builder_clean_stored_layout( $data ) {
	if ( ! is_array( $data ) || array() !== rk_builder_validate_layout( $data, null ) ) { return null; }
	return rk_builder_canonicalize_layout( $data );
}

function rk_builder_get_draft_layout( $page_id ) {
	$layout = rk_builder_clean_stored_layout( rk_builder_read_json_meta( $page_id, '_rk_layout_draft' ) );
	return null === $layout ? rk_builder_empty_layout() : $layout;
}

/** Raw decoded draft for re-validation: false when never saved, null when present but corrupt. */
function rk_builder_get_raw_draft( $page_id ) {
	$raw = get_post_meta( $page_id, '_rk_layout_draft', true );
	if ( ! is_string( $raw ) || '' === $raw ) { return false; }
	return rk_builder_read_json_meta( $page_id, '_rk_layout_draft' );
}

/** Published snapshot or null when there is none (or it is corrupt). */
function rk_builder_get_published_layout( $page_id ) {
	return rk_builder_clean_stored_layout( rk_builder_read_json_meta( $page_id, '_rk_layout_published' ) );
}

function rk_builder_get_revision( $page_id ) {
	return max( 0, (int) get_post_meta( $page_id, '_rk_revision', true ) );
}

function rk_builder_get_published_revision( $page_id ) {
	$v = get_post_meta( $page_id, '_rk_published_revision', true );
	return ( '' === $v || null === $v || false === $v || (int) $v < 1 ) ? null : (int) $v;
}

function rk_builder_get_published_at( $page_id ) {
	$v = get_post_meta( $page_id, '_rk_published_at', true );
	return is_string( $v ) && '' !== $v ? $v : null;
}

/** Revision records, oldest first. Malformed records are skipped. */
function rk_builder_get_revision_records( $page_id ) {
	$data = rk_builder_read_json_meta( $page_id, '_rk_revisions' );
	if ( ! is_array( $data ) ) { return array(); }
	$kinds = array( 'draft', 'publish', 'restore', 'unpublish' );
	$out   = array();
	foreach ( $data as $r ) {
		if ( is_array( $r ) && isset( $r['id'], $r['kind'], $r['savedAt'], $r['layout'] )
			&& is_int( $r['id'] ) && in_array( $r['kind'], $kinds, true ) && is_string( $r['savedAt'] ) && is_array( $r['layout'] ) ) {
			$r['author_id'] = isset( $r['author_id'] ) ? (int) $r['author_id'] : 0;
			$out[]          = $r;
		}
	}
	return $out;
}

function rk_builder_find_revision_record( $page_id, $revision_id ) {
	foreach ( rk_builder_get_revision_records( $page_id ) as $r ) {
		if ( (int) $r['id'] === (int) $revision_id ) { return $r; }
	}
	return null;
}

function rk_builder_revision_summary( array $record ) {
	$user   = $record['author_id'] ? get_userdata( $record['author_id'] ) : false;
	$layout = $record['layout'];
	return array(
		'id'      => (int) $record['id'],
		'kind'    => $record['kind'],
		'savedAt' => $record['savedAt'],
		'author'  => ( $user && isset( $user->display_name ) && '' !== $user->display_name ) ? (string) $user->display_name : 'Unknown',
		'blocks'  => ( isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ) ? count( $layout['blocks'] ) : 0,
	);
}

/**
 * Append a revision and make `$layout` the working draft. The caller MUST hold the page lock
 * (rk_builder_with_lock) and must already have validated $layout.
 *
 * $opts['published'] = true also snapshots $layout as the published layout.
 *
 * @return array{revision:int,savedAt:string}
 */
function rk_builder_commit_revision( $page_id, $kind, array $layout, array $opts = array() ) {
	$now     = rk_builder_now();
	$revision = rk_builder_get_revision( $page_id ) + 1;
	$saved   = rk_builder_iso( $now );
	$records = rk_builder_get_revision_records( $page_id );
	$records[] = array(
		'id'        => $revision,
		'kind'      => $kind,
		'savedAt'   => $saved,
		'author_id' => (int) get_current_user_id(),
		'layout'    => $layout,
	);
	$records = array_slice( $records, -rk_builder_max_revisions() );

	rk_builder_write_json_meta( $page_id, '_rk_layout_draft', $layout );
	if ( ! empty( $opts['published'] ) ) {
		rk_builder_write_json_meta( $page_id, '_rk_layout_published', $layout );
		update_post_meta( $page_id, '_rk_published_revision', $revision );
		update_post_meta( $page_id, '_rk_published_at', $saved );
	}
	rk_builder_write_json_meta( $page_id, '_rk_revisions', $records );
	update_post_meta( $page_id, '_rk_revision', $revision ); // commit point: written last
	return array( 'revision' => $revision, 'savedAt' => $saved );
}

/** Timestamp of the last builder change, or null. */
function rk_builder_last_saved_at( $page_id ) {
	$records = rk_builder_get_revision_records( $page_id );
	if ( ! $records ) { return null; }
	$last = end( $records );
	return $last['savedAt'];
}

/* ------------------------------------------------------------------ *
 * Per-page lock (best effort mutual exclusion for read-check-write)
 * ------------------------------------------------------------------ */

/**
 * Takes an option-row lock with an atomic INSERT IGNORE (the same technique as WP_Upgrader).
 * Residual risks, by design: a stale lock is taken over after RK_BUILDER_LOCK_TTL seconds, and
 * without $wpdb we fall back to add_option(), which has a narrow check-then-insert window.
 * A stricter lock (e.g. MySQL GET_LOCK or an external mutex) can be swapped in here.
 */
function rk_builder_lock( $page_id ) {
	global $wpdb;
	$key = 'rk_builder_lock_' . (int) $page_id;
	$now = time();
	if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) ) {
		$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' ) /* RK LOCK */", $key, (string) $now ) );
	} else {
		$ok = add_option( $key, $now, '', 'no' );
	}
	if ( $ok ) { return true; }
	$held = (int) get_option( $key );
	if ( $held && $held > $now - RK_BUILDER_LOCK_TTL ) { return false; }
	update_option( $key, $now ); // stale: take over
	return true;
}

function rk_builder_unlock( $page_id ) {
	delete_option( 'rk_builder_lock_' . (int) $page_id );
}

/**
 * Run $fn() while holding the page lock. Returns whatever $fn returns, or a 409 WP_Error if the
 * lock could not be obtained.
 */
function rk_builder_with_lock( $page_id, $fn ) {
	if ( ! rk_builder_lock( $page_id ) ) {
		return rk_builder_error( 'rk_revision_conflict', 'Another save is in progress. Reload and try again.', 409, array( 'currentRevision' => rk_builder_get_revision( $page_id ) ) );
	}
	try {
		rk_builder_flush_meta_cache( $page_id );
		return $fn();
	} finally {
		rk_builder_unlock( $page_id );
	}
}

/* ------------------------------------------------------------------ *
 * Theme
 * ------------------------------------------------------------------ */

/** Current global theme (always valid v1; old stored shapes are migrated on read). */
function rk_builder_get_theme() {
	return rk_builder_migrate_theme( get_option( 'rk_theme_config', array() ) );
}

/** Persist an already-validated theme. */
function rk_builder_store_theme( array $theme ) {
	update_option( 'rk_theme_config', rk_builder_canonicalize_theme( $theme ), false );
}

function rk_builder_error( $code, $message, $status, array $extra = array() ) {
	return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
}
