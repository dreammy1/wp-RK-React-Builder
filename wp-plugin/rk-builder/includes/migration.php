<?php
/**
 * Migration tools.
 *
 * 1. Prototype layouts (spec 17.1): pages carrying the old `_rk_layout` meta (flat blocks such as
 *    `{id,type,heading,...}`) are converted to the canonical `{id,type,props}` shape, validated,
 *    and stored as a DRAFT revision (`_rk_layout_draft`). Rules:
 *      - nothing is dropped silently: every added default, renamed value, converted value and
 *        dropped field is listed in the report;
 *      - `_rk_layout` is never deleted; `_rk_layout_published` and the page status are never touched;
 *      - a page whose blocks do not validate after conversion is reported as "needs manual fix" and
 *        is NOT migrated;
 *      - pages that already have a draft layout are skipped unless "overwrite" is chosen (the
 *        replaced draft stays in the revision history);
 *      - the default is a dry run that writes nothing.
 *    The revision kind stays `draft` (the REST/client enum is draft|publish|restore|unpublish); the
 *    migration is recorded in the option `rk_builder_last_migration`
 *    ({time, user, dry_run, migrated, skipped, failed}).
 *
 * 2. Schema upgrades: option `rk_builder_schema_version`; on plugins_loaded, lower than current ->
 *    incremental steps (none are needed at v1), outcome recorded in `rk_builder_schema_upgrade`.
 *    Steps must never delete data.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------------ *
 * Conversion (pure apart from random ids)
 * ------------------------------------------------------------------ */

/** Defaults added to prototype blocks whose required prop is missing (mirrors the client defaults). */
function rk_builder_legacy_defaults() {
	$grid = array( 'title' => '', 'limit' => 6, 'cols' => 3, 'category' => '', 'orderBy' => 'menu_order', 'order' => 'asc' );
	return array(
		'hero'      => array( 'sub' => '', 'cta' => '', 'ctaHref' => '' ),
		'heading'   => array( 'level' => 2 ),
		'text'      => array( 'text' => '' ),
		'image'     => array( 'alt' => '', 'decorative' => false ),
		'cta'       => array( 'ctaHref' => '' ),
		'services'  => $grid,
		'portfolio' => $grid,
		'spacer'    => array( 'h' => 40 ),
		'divider'   => array( 'style' => 'solid' ),
	);
}

/** Short, log-safe rendering of a value for the report (never a whole layout). */
function rk_builder_migration_preview( $v ) {
	$s = is_string( $v ) ? $v : wp_json_encode( $v );
	$s = is_string( $s ) ? $s : '';
	return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 80 ) : substr( $s, 0, 80 );
}

/** A new block id like "hero-3fa9c1" that is not in $taken (and records it there). */
function rk_builder_generate_block_id( $type, array &$taken ) {
	$prefix = ( is_string( $type ) && 1 === preg_match( '/^[a-z0-9]{1,20}\z/', $type ) ) ? $type : 'block';
	for ( $i = 0; $i < 50; $i++ ) {
		$id = $prefix . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		if ( ! isset( $taken[ $id ] ) ) {
			$taken[ $id ] = true;
			return $id;
		}
	}
	$id = $prefix . '-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 ); // practically unreachable
	$taken[ $id ] = true;
	return $id;
}

function rk_builder_legacy_note( array &$report, $index, $action, $field, $from = null, $to = null ) {
	if ( count( $report['changes'] ) >= 300 ) { $report['changes_truncated'] = true; return; }
	$row = array( 'block' => (int) $index, 'action' => $action, 'field' => (string) $field );
	if ( null !== $from ) { $row['from'] = rk_builder_migration_preview( $from ); }
	if ( null !== $to ) { $row['to'] = rk_builder_migration_preview( $to ); }
	$report['changes'][] = $row;
}

function rk_builder_legacy_issue( array &$report, $path, $message ) {
	if ( count( $report['issues'] ) < RK_BUILDER_MAX_ISSUES ) {
		$report['issues'][] = array( 'path' => (string) $path, 'message' => (string) $message );
	}
}

/**
 * Convert ONE legacy (or already canonical) block. Returns the block array or null when it cannot
 * be converted (the reason is added to $report['issues']).
 */
function rk_builder_convert_legacy_block( $raw, $index, array &$taken, array &$reserved, array &$report ) {
	$path  = 'blocks.' . $index;
	$specs = rk_builder_block_specs();
	if ( ! is_array( $raw ) || ( array() !== $raw && rk_builder_is_list( $raw ) ) ) {
		rk_builder_legacy_issue( $report, $path, 'Expected object' );
		return null;
	}
	$type = isset( $raw['type'] ) ? $raw['type'] : null;
	if ( ! is_string( $type ) || ! isset( $specs[ $type ] ) ) {
		rk_builder_legacy_issue( $report, $path . '.type', 'Unknown block type' );
		return null;
	}
	$spec = $specs[ $type ];

	// Props: already nested, or flat (everything except id/type).
	if ( isset( $raw['props'] ) && is_array( $raw['props'] ) ) {
		$props = $raw['props'];
		foreach ( $raw as $k => $v ) {
			if ( 'id' !== $k && 'type' !== $k && 'props' !== $k ) {
				rk_builder_legacy_note( $report, $index, 'dropped', $k, $v );
			}
		}
	} else {
		$props = $raw;
		unset( $props['id'], $props['type'], $props['props'] );
		if ( array_key_exists( 'props', $raw ) ) {
			rk_builder_legacy_note( $report, $index, 'dropped', 'props', $raw['props'] );
		}
		rk_builder_legacy_note( $report, $index, 'restructured', 'props', null, 'moved flat fields into props' );
	}

	// Id.
	$id = isset( $raw['id'] ) ? $raw['id'] : null;
	if ( is_string( $id ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}\z/', $id ) && ! isset( $taken[ $id ] ) ) {
		$taken[ $id ] = true;
	} else {
		$new = rk_builder_generate_block_id( $type, $reserved );
		$taken[ $new ] = true;
		rk_builder_legacy_note( $report, $index, 'id_generated', 'id', is_scalar( $id ) ? $id : null, $new );
		$id = $new;
	}

	// Source: the block type decides it (services -> service).
	if ( 'services' === $type || 'portfolio' === $type ) {
		$expected = 'services' === $type ? 'service' : 'portfolio';
		if ( ! array_key_exists( 'source', $props ) ) {
			rk_builder_legacy_note( $report, $index, 'added', 'source', null, $expected );
		} elseif ( $props['source'] !== $expected ) {
			rk_builder_legacy_note( $report, $index, 'converted', 'source', $props['source'], $expected );
		}
		$props['source'] = $expected;
	}

	// Unknown props cannot be stored (strict schema): drop them, but say so.
	foreach ( $props as $k => $v ) {
		if ( ! isset( $spec[ $k ] ) ) {
			rk_builder_legacy_note( $report, $index, 'dropped', $k, $v );
			unset( $props[ $k ] );
		}
	}

	// Numeric strings -> integers where the schema wants integers.
	foreach ( $spec as $name => $field ) {
		if ( array_key_exists( $name, $props ) && ( 'int' === $field['t'] || ( 'enum' === $field['t'] && is_int( reset( $field['values'] ) ) ) )
			&& is_string( $props[ $name ] ) && 1 === preg_match( '/^-?[0-9]{1,10}\z/', $props[ $name ] ) ) {
			rk_builder_legacy_note( $report, $index, 'converted', $name, $props[ $name ], (int) $props[ $name ] );
			$props[ $name ] = (int) $props[ $name ];
		}
	}

	// Missing required props that have a documented default.
	$defaults = rk_builder_legacy_defaults();
	$defaults = isset( $defaults[ $type ] ) ? $defaults[ $type ] : array();
	foreach ( $defaults as $name => $value ) {
		if ( isset( $spec[ $name ] ) && ! array_key_exists( $name, $props ) ) {
			$props[ $name ] = $value;
			rk_builder_legacy_note( $report, $index, 'added', $name, null, $value );
		}
	}

	return array( 'id' => $id, 'type' => $type, 'props' => $props );
}

/**
 * Convert a legacy layout (JSON string or decoded array: a list of blocks, or {version?,blocks}) to
 * a canonical v1 layout. Returns the validated, canonical layout or null; $report receives:
 *   ok, blocks_in, blocks_out, changes[ {block,action,field,from?,to?} ], issues[ {path,message} ].
 *
 * @param mixed      $raw    Stored `_rk_layout` value.
 * @param array|null $report Filled in (by reference).
 * @return array|null
 */
function rk_builder_migrate_legacy_layout( $raw, &$report = null ) {
	$report = array( 'ok' => false, 'blocks_in' => 0, 'blocks_out' => 0, 'changes' => array(), 'issues' => array() );
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			rk_builder_legacy_issue( $report, '', 'Stored layout is not valid JSON' );
			return null;
		}
		$raw = $decoded;
	}
	if ( ! is_array( $raw ) ) {
		rk_builder_legacy_issue( $report, '', 'Unrecognised layout (expected a list of blocks or {blocks: [...]})' );
		return null;
	}
	if ( array() !== $raw && rk_builder_is_list( $raw ) ) {
		$blocks = $raw;
		rk_builder_legacy_note( $report, 0, 'restructured', 'layout', null, 'wrapped the block list in {version:1, blocks}' );
	} elseif ( isset( $raw['blocks'] ) && is_array( $raw['blocks'] ) ) {
		$blocks = $raw['blocks'];
		foreach ( $raw as $k => $v ) {
			if ( 'blocks' !== $k && 'version' !== $k ) { rk_builder_legacy_note( $report, 0, 'dropped', $k, $v ); }
		}
		if ( ! isset( $raw['version'] ) || 1 !== (int) $raw['version'] ) {
			rk_builder_legacy_note( $report, 0, 'converted', 'version', isset( $raw['version'] ) ? $raw['version'] : null, 1 );
		}
	} elseif ( array() === $raw ) {
		$blocks = array();
	} else {
		rk_builder_legacy_issue( $report, '', 'Unrecognised layout (expected a list of blocks or {blocks: [...]})' );
		return null;
	}
	if ( ! rk_builder_is_list( $blocks ) ) {
		rk_builder_legacy_issue( $report, 'blocks', 'Expected array' );
		return null;
	}
	$report['blocks_in'] = count( $blocks );
	if ( count( $blocks ) > RK_BUILDER_MAX_BLOCKS ) {
		rk_builder_legacy_issue( $report, 'blocks', 'Array must contain at most ' . RK_BUILDER_MAX_BLOCKS . ' element(s)' );
		return null;
	}

	// Ids already in use by well-formed blocks are reserved so generated ids never collide with them.
	$reserved = array();
	foreach ( $blocks as $b ) {
		if ( is_array( $b ) && isset( $b['id'] ) && is_string( $b['id'] ) ) { $reserved[ $b['id'] ] = true; }
	}
	$taken  = array();
	$hosts  = function_exists( 'rk_builder_allowed_image_hosts' ) ? rk_builder_allowed_image_hosts() : array();
	$out    = array();
	$failed = false;
	foreach ( array_values( $blocks ) as $i => $b ) {
		$block = rk_builder_convert_legacy_block( $b, $i, $taken, $reserved, $report );
		if ( null === $block ) { $failed = true; continue; }
		// Validate each block on its own so every problem is reported with its own path.
		$issues = rk_builder_validate_layout( array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => array( $block ) ), $hosts );
		foreach ( $issues as $issue ) {
			$failed = true;
			rk_builder_legacy_issue( $report, preg_replace( '/^blocks\.0/', 'blocks.' . $i, $issue['path'] ), $issue['message'] );
		}
		$out[] = $block;
	}
	if ( $failed ) { return null; }
	$layout = array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => $out );
	$final  = rk_builder_validate_layout( $layout, $hosts );
	if ( array() !== $final ) {
		foreach ( $final as $issue ) { rk_builder_legacy_issue( $report, $issue['path'], $issue['message'] ); }
		return null;
	}
	$layout               = rk_builder_canonicalize_layout( $layout );
	$report['ok']         = true;
	$report['blocks_out'] = count( $layout['blocks'] );
	return $layout;
}

/* ------------------------------------------------------------------ *
 * Pages
 * ------------------------------------------------------------------ */

/** IDs of pages that carry the prototype's `_rk_layout` meta (any non-trashed status), oldest first. */
function rk_builder_find_legacy_layouts( $limit = 1000 ) {
	$ids = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => 'any',
		'meta_key'       => '_rk_layout', // phpcs:ignore WordPress.DB.SlowDBQuery
		'posts_per_page' => max( 1, min( 5000, (int) $limit ) ),
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	$out = array();
	foreach ( (array) $ids as $p ) {
		$id = is_object( $p ) ? (int) $p->ID : (int) $p;
		$raw = get_post_meta( $id, '_rk_layout', true );
		if ( '' !== $raw && null !== $raw && false !== $raw && array() !== $raw ) { $out[] = $id; }
	}
	return $out;
}

/** Whether the page already has a (non-empty) draft layout stored. */
function rk_builder_page_has_draft( $page_id ) {
	return false !== rk_builder_get_raw_draft( $page_id );
}

/**
 * Migrate (or dry-run) one page. Never touches `_rk_layout`, `_rk_layout_published` or the post status.
 *
 * @param int   $page_id
 * @param array $opts    dry_run (default true), overwrite (default false)
 * @return array Report row: page_id,title,status(would_migrate|migrated|skipped|failed),reason,blocks_in,blocks_out,changes,issues,needs_manual_fix.
 */
function rk_builder_migrate_page( $page_id, array $opts = array() ) {
	$dry       = ! array_key_exists( 'dry_run', $opts ) || ! empty( $opts['dry_run'] );
	$overwrite = ! empty( $opts['overwrite'] );
	$row       = array(
		'page_id' => (int) $page_id, 'title' => (string) get_the_title( $page_id ), 'status' => 'skipped', 'reason' => '',
		'blocks_in' => 0, 'blocks_out' => 0, 'changes' => array(), 'issues' => array(), 'needs_manual_fix' => false,
	);
	$raw = get_post_meta( $page_id, '_rk_layout', true );
	if ( '' === $raw || null === $raw || false === $raw || array() === $raw ) {
		$row['reason'] = 'No legacy layout on this page.';
		return $row;
	}
	if ( ! $overwrite && rk_builder_page_has_draft( $page_id ) ) {
		$row['reason'] = 'Already has a draft layout (tick "overwrite existing drafts" to replace it; the old draft stays in the revision history).';
		return $row;
	}
	$layout = rk_builder_migrate_legacy_layout( $raw, $report );
	foreach ( array( 'blocks_in', 'blocks_out', 'changes', 'issues' ) as $k ) { $row[ $k ] = $report[ $k ]; }
	if ( ! empty( $report['changes_truncated'] ) ) { $row['changes_truncated'] = true; }
	if ( null === $layout ) {
		$row['status']           = 'failed';
		$row['needs_manual_fix'] = true;
		$row['reason']           = 'Needs manual fix: the legacy blocks do not validate. Nothing was changed for this page.';
		return $row;
	}
	if ( $dry ) {
		$row['status'] = 'would_migrate';
		return $row;
	}
	$res = rk_builder_with_lock( $page_id, function () use ( $page_id, $layout ) {
		return rk_builder_commit_revision( $page_id, 'draft', $layout );
	} );
	if ( is_wp_error( $res ) ) {
		$row['status'] = 'failed';
		$row['reason'] = 'Could not save: ' . $res->get_error_message();
		return $row;
	}
	$row['status']   = 'migrated';
	$row['revision'] = (int) $res['revision'];
	return $row;
}

/**
 * Run (or dry-run) the migration for every legacy page and record the summary.
 *
 * @param array $opts dry_run (default true), overwrite (default false), page_ids (optional subset)
 * @return array{rows:array,summary:array}
 */
function rk_builder_run_migration( array $opts = array() ) {
	$dry  = ! array_key_exists( 'dry_run', $opts ) || ! empty( $opts['dry_run'] );
	$ids  = rk_builder_find_legacy_layouts();
	if ( isset( $opts['page_ids'] ) && is_array( $opts['page_ids'] ) ) { $ids = array_values( array_intersect( $ids, array_map( 'intval', $opts['page_ids'] ) ) ); }
	$rows = array();
	$sum  = array( 'time' => rk_builder_iso( rk_builder_now() ), 'user' => (int) get_current_user_id(), 'dry_run' => $dry, 'migrated' => 0, 'skipped' => 0, 'failed' => 0 );
	foreach ( $ids as $id ) {
		$row    = rk_builder_migrate_page( $id, array( 'dry_run' => $dry, 'overwrite' => ! empty( $opts['overwrite'] ) ) );
		$rows[] = $row;
		if ( 'migrated' === $row['status'] || 'would_migrate' === $row['status'] ) { $sum['migrated']++; }
		elseif ( 'failed' === $row['status'] ) { $sum['failed']++; }
		else { $sum['skipped']++; }
	}
	update_option( 'rk_builder_last_migration', $sum, false );
	return array( 'rows' => $rows, 'summary' => $sum );
}

/** The last recorded migration summary, or null. */
function rk_builder_last_migration() {
	$v = get_option( 'rk_builder_last_migration', null );
	return is_array( $v ) ? $v : null;
}

/* ------------------------------------------------------------------ *
 * Schema upgrades
 * ------------------------------------------------------------------ */

function rk_builder_current_schema_version() {
	return (int) RK_BUILDER_SCHEMA_VERSION;
}

/**
 * Incremental upgrade steps: array( target_version => callable ). A step returns true on success
 * (false or WP_Error on failure) and must never delete data. None exist at v1.
 */
function rk_builder_schema_steps() {
	$steps = apply_filters( 'rk_builder_schema_steps', array() );
	$steps = is_array( $steps ) ? $steps : array();
	ksort( $steps );
	return $steps;
}

/**
 * plugins_loaded: bring the stored schema version up to the current one, step by step. Outcome is
 * recorded in `rk_builder_schema_upgrade`. A failed step stops the run and keeps the last good version.
 *
 * @return string 'current' | 'upgraded' | 'failed'
 */
function rk_builder_maybe_upgrade_schema() {
	$stored  = (int) get_option( 'rk_builder_schema_version', 0 );
	$current = rk_builder_current_schema_version();
	if ( $stored >= $current ) { return 'current'; }
	$steps   = rk_builder_schema_steps();
	$version = $stored;
	$error   = '';
	for ( $v = $stored + 1; $v <= $current; $v++ ) {
		if ( isset( $steps[ $v ] ) && is_callable( $steps[ $v ] ) ) {
			try {
				$ok = call_user_func( $steps[ $v ] );
				if ( is_wp_error( $ok ) ) { $error = $ok->get_error_code(); }
				elseif ( true !== $ok ) { $error = 'step_returned_false'; }
			} catch ( Throwable $e ) {
				$error = get_class( $e );
			}
			if ( '' !== $error ) { $error = 'step ' . $v . ': ' . $error; break; }
		}
		$version = $v;
		update_option( 'rk_builder_schema_version', $version, false );
	}
	update_option( 'rk_builder_schema_upgrade', array(
		'time'   => rk_builder_iso( rk_builder_now() ),
		'from'   => $stored,
		'to'     => $version,
		'status' => '' === $error ? 'ok' : 'failed',
		'error'  => $error,
		'plugin' => RK_BUILDER_VERSION,
	), false );
	return '' === $error ? 'upgraded' : 'failed';
}

/* ------------------------------------------------------------------ *
 * Tools > RK Builder migration (admin UI)
 * ------------------------------------------------------------------ */

function rk_builder_register_migration_page() {
	add_management_page( 'RK Builder migration', 'RK Builder migration', 'manage_options', 'rk-builder-migration', 'rk_builder_render_migration_page' );
}

function rk_builder_migration_page_url( $notice = '' ) {
	$url = admin_url( 'tools.php?page=rk-builder-migration' );
	return '' === $notice ? $url : $url . '&rk_notice=' . rawurlencode( $notice );
}

/** admin-post: dry run or real migration. Requires manage_options + nonce (+ confirm for a real run). */
function rk_builder_handle_migrate() {
	rk_builder_require_admin_action( 'rk_builder_migrate' );
	$mode = isset( $_POST['mode'] ) && 'run' === $_POST['mode'] ? 'run' : 'dry'; // phpcs:ignore WordPress.Security.NonceVerification -- checked in rk_builder_require_admin_action().
	if ( 'run' === $mode && empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return rk_builder_finish_request( rk_builder_migration_page_url( 'confirm_required' ) );
	}
	$result = rk_builder_run_migration( array( 'dry_run' => 'run' !== $mode, 'overwrite' => ! empty( $_POST['overwrite'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	set_transient( 'rk_builder_migration_report_' . (int) get_current_user_id(), $result, 600 );
	return rk_builder_finish_request( rk_builder_migration_page_url( 'run' === $mode ? 'migrated' : 'dry_run' ) );
}

function rk_builder_render_migration_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'rk-builder' ), '', array( 'response' => 403 ) );
	}
	$notices = array(
		'dry_run'          => array( 'info', 'Dry-run report generated. Nothing was written.' ),
		'migrated'         => array( 'success', 'Migration finished. Migrated pages now have a draft layout; review each one in RK Builder and publish when happy.' ),
		'confirm_required' => array( 'error', 'Tick the confirmation box to run the migration.' ),
	);
	$n = isset( $_GET['rk_notice'] ) && is_string( $_GET['rk_notice'] ) ? sanitize_key( wp_unslash( $_GET['rk_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap"><h1>' . esc_html__( 'RK Builder: migrate prototype layouts', 'rk-builder' ) . '</h1>';
	if ( isset( $notices[ $n ] ) ) {
		echo '<div class="notice notice-' . esc_attr( $notices[ $n ][0] ) . '"><p>' . esc_html( $notices[ $n ][1] ) . '</p></div>';
	}
	echo '<p>' . esc_html__( 'Back up your database and uploads first. This tool converts pages that still carry the prototype layout (post meta _rk_layout) into the RK Builder format and stores the result as a DRAFT. The original _rk_layout meta is never deleted, published pages are never changed, and pages that already have a draft are skipped unless you choose to overwrite.', 'rk-builder' ) . '</p>';

	$ids = rk_builder_find_legacy_layouts();
	echo '<p><strong>' . esc_html( sprintf( '%d page(s) with a legacy layout found.', count( $ids ) ) ) . '</strong></p>';
	$last = rk_builder_last_migration();
	if ( $last ) {
		echo '<p>' . esc_html( sprintf( 'Last run: %s by user #%d (%s): %d migrated, %d skipped, %d failed.', $last['time'], $last['user'], $last['dry_run'] ? 'dry run' : 'real run', $last['migrated'], $last['skipped'], $last['failed'] ) ) . '</p>';
	}
	if ( $ids ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rk_builder_migrate">';
		wp_nonce_field( 'rk_builder_migrate' );
		echo '<p><label><input type="checkbox" name="overwrite" value="1"> ' . esc_html__( 'Overwrite existing drafts (the replaced draft stays in the revision history)', 'rk-builder' ) . '</label></p>';
		echo '<p><button type="submit" name="mode" value="dry" class="button button-secondary">' . esc_html__( 'Run dry-run report', 'rk-builder' ) . '</button></p>';
		echo '<p><label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'I have a database backup and want to write draft layouts now.', 'rk-builder' ) . '</label><br>';
		echo '<button type="submit" name="mode" value="run" class="button button-primary">' . esc_html__( 'Run migration', 'rk-builder' ) . '</button></p>';
		echo '</form>';
	}
	$report = get_transient( 'rk_builder_migration_report_' . (int) get_current_user_id() );
	if ( is_array( $report ) && isset( $report['rows'] ) ) { rk_builder_render_migration_report( $report ); }
	echo '</div>';
}

function rk_builder_render_migration_report( array $report ) {
	$labels = array( 'would_migrate' => 'Would migrate', 'migrated' => 'Migrated', 'skipped' => 'Skipped', 'failed' => 'Needs manual fix' );
	echo '<h2>' . esc_html( $report['summary']['dry_run'] ? 'Dry-run report' : 'Migration report' ) . '</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Page</th><th>Result</th><th>Blocks</th><th>Details</th></tr></thead><tbody>';
	foreach ( $report['rows'] as $r ) {
		echo '<tr><td>' . esc_html( '#' . $r['page_id'] . ' ' . $r['title'] ) . '</td>';
		echo '<td>' . esc_html( isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : $r['status'] ) . '</td>';
		echo '<td>' . esc_html( $r['blocks_in'] . ' -> ' . $r['blocks_out'] ) . '</td><td>';
		if ( '' !== $r['reason'] ) { echo '<p>' . esc_html( $r['reason'] ) . '</p>'; }
		foreach ( $r['issues'] as $i ) { echo '<div>' . esc_html( 'Problem at ' . $i['path'] . ': ' . $i['message'] ) . '</div>'; }
		foreach ( $r['changes'] as $c ) {
			$line = $c['action'] . ' ' . $c['field'] . ' (block ' . $c['block'] . ')'
				. ( isset( $c['from'] ) ? ' from ' . $c['from'] : '' ) . ( isset( $c['to'] ) ? ' to ' . $c['to'] : '' );
			echo '<div>' . esc_html( $line ) . '</div>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}
