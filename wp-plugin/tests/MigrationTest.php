<?php
/** Prototype layout migration (spec 17.1) and schema upgrade steps. */

require_once RK_BUILDER_DIR . 'includes/setup.php';
require_once RK_BUILDER_DIR . 'includes/migration.php';

function rk_test_legacy_blocks() {
	return array(
		array( 'id' => 'a1', 'type' => 'hero', 'heading' => 'Powering what\'s next', 'sub' => 'Licensed contractors.', 'cta' => 'Get a quote' ),
		array( 'type' => 'services', 'title' => 'Our Services', 'source' => 'services', 'cols' => 3 ),
		array( 'id' => 'p1', 'type' => 'portfolio', 'title' => 'Recent Work', 'source' => 'portfolio', 'cols' => '4' ),
		array( 'id' => 'c1', 'type' => 'cta', 'heading' => 'Ready?', 'cta' => 'Contact us' ),
		array( 'id' => 's1', 'type' => 'spacer', 'h' => 40 ),
	);
}
function rk_test_legacy_page( $blocks = null, $status = 'draft', $slug = 'legacy' ) {
	$id = rk_test_page( $status, $slug, 2 );
	update_post_meta( $id, '_rk_layout', wp_slash( wp_json_encode( null === $blocks ? rk_test_legacy_blocks() : $blocks ) ) );
	return $id;
}
function rk_test_actions( array $report, $action ) {
	return array_values( array_filter( $report['changes'], function ( $c ) use ( $action ) { return $c['action'] === $action; } ) );
}
function rk_test_meta_snapshot( $id ) { return isset( $GLOBALS['RK']['meta'][ $id ] ) ? $GLOBALS['RK']['meta'][ $id ] : array(); }

rk_test( 'convert: flat prototype blocks become {id,type,props}, services->service, ids generated, layout valid', function () {
	$layout = rk_builder_migrate_legacy_layout( rk_test_legacy_blocks(), $report );
	t_assert( null !== $layout, wp_json_encode( $report ) );
	t_eq( $report['ok'], true );
	t_eq( array_keys( $layout ), array( 'version', 'blocks' ) );
	t_eq( $layout['version'], 1 );
	t_eq( count( $layout['blocks'] ), 5 );
	t_eq( rk_builder_validate_layout( $layout, null ), array() );
	t_eq( $layout['blocks'][0], array( 'id' => 'a1', 'type' => 'hero', 'props' => array( 'heading' => 'Powering what\'s next', 'sub' => 'Licensed contractors.', 'cta' => 'Get a quote', 'ctaHref' => '' ) ) );
	t_eq( $layout['blocks'][1]['type'], 'services' );
	t_eq( $layout['blocks'][1]['props']['source'], 'service', 'services -> service' );
	t_eq( $layout['blocks'][2]['props']['source'], 'portfolio', 'portfolio unchanged' );
	t_eq( $layout['blocks'][2]['props']['cols'], 4, 'numeric string converted' );
	t_eq( $layout['blocks'][1]['props']['limit'], 6, 'default added' );
	t_assert( 1 === preg_match( '/^services-[a-z0-9]{6}\z/', $layout['blocks'][1]['id'] ), $layout['blocks'][1]['id'] );
	t_eq( $report['blocks_in'], 5 ); t_eq( $report['blocks_out'], 5 );
	// everything that changed is in the report
	$src = rk_test_actions( $report, 'converted' );
	$fields = array_map( function ( $c ) { return $c['block'] . ':' . $c['field'] . ':' . $c['from'] . '->' . $c['to']; }, $src );
	t_assert( in_array( '1:source:services->service', $fields, true ), implode( ' | ', $fields ) );
	t_assert( in_array( '2:cols:4->4', $fields, true ) );
	$gen = rk_test_actions( $report, 'id_generated' );
	t_eq( count( $gen ), 1 ); t_eq( $gen[0]['block'], 1 ); t_eq( $gen[0]['to'], $layout['blocks'][1]['id'] );
	$added = array_map( function ( $c ) { return $c['block'] . ':' . $c['field']; }, rk_test_actions( $report, 'added' ) );
	t_assert( in_array( '0:ctaHref', $added, true ) && in_array( '1:limit', $added, true ) && in_array( '3:ctaHref', $added, true ), implode( ',', $added ) );
	t_assert( count( rk_test_actions( $report, 'restructured' ) ) >= 5, 'each flat block reported as restructured' );
} );

rk_test( 'convert: accepts JSON strings, {version:0,blocks}, {blocks}, and already-canonical layouts', function () {
	$a = rk_builder_migrate_legacy_layout( wp_json_encode( rk_test_legacy_blocks() ), $r );
	t_assert( null !== $a );
	$b = rk_builder_migrate_legacy_layout( array( 'version' => 0, 'blocks' => rk_test_legacy_blocks() ), $r );
	t_assert( null !== $b );
	t_eq( count( rk_test_actions( $r, 'converted' ) ) >= 3, true, 'version 0 -> 1 recorded' );
	$canon = rk_spacer_layout( 40 );
	$c = rk_builder_migrate_legacy_layout( $canon, $r );
	t_deep( $c, $canon );
	t_eq( $r['changes'], array(), 'a canonical layout needs no changes' );
	t_eq( $r['ok'], true );
	$d = rk_builder_migrate_legacy_layout( array(), $r );
	t_eq( $d, rk_builder_empty_layout() );
} );

rk_test( 'convert: missing, invalid and duplicate ids are regenerated uniquely and reported', function () {
	$blocks = array(
		array( 'id' => 'dup', 'type' => 'spacer', 'h' => 20 ),
		array( 'id' => 'dup', 'type' => 'spacer', 'h' => 21 ),
		array( 'id' => 'Bad Id!', 'type' => 'divider' ),
		array( 'id' => 7, 'type' => 'spacer', 'h' => 22 ),
		array( 'type' => 'spacer', 'h' => 23 ),
	);
	$layout = rk_builder_migrate_legacy_layout( $blocks, $r );
	t_assert( null !== $layout, wp_json_encode( $r ) );
	$ids = array_column( $layout['blocks'], 'id' );
	t_eq( count( array_unique( $ids ) ), 5, 'unique' );
	t_eq( $ids[0], 'dup', 'first keeps its valid id' );
	foreach ( array_slice( $ids, 1 ) as $id ) { t_assert( 1 === preg_match( '/^(spacer|divider)-[a-z0-9]{6}\z/', $id ), $id ); }
	t_eq( count( rk_test_actions( $r, 'id_generated' ) ), 4 );
	t_eq( rk_test_actions( $r, 'id_generated' )[1]['from'], 'Bad Id!' );
	t_eq( $layout['blocks'][2]['props'], array( 'style' => 'solid' ), 'divider style default added' );
} );

rk_test( 'convert: generated ids never collide with ids that appear later in the list', function () {
	$blocks = array( array( 'type' => 'spacer', 'h' => 20 ) );
	for ( $i = 0; $i < 30; $i++ ) { $blocks[] = array( 'id' => 'spacer-' . str_pad( dechex( $i ), 6, '0', STR_PAD_LEFT ), 'type' => 'spacer', 'h' => 20 ); }
	$layout = rk_builder_migrate_legacy_layout( $blocks, $r );
	t_eq( count( array_unique( array_column( $layout['blocks'], 'id' ) ) ), 31 );
} );

rk_test( 'convert: unknown props are dropped but every one is reported; nothing disappears silently', function () {
	$layout = rk_builder_migrate_legacy_layout( array( array( 'id' => 'h', 'type' => 'heading', 'text' => 'Hi', 'color' => 'red', 'animate' => true ) ), $r );
	t_assert( null !== $layout );
	t_eq( $layout['blocks'][0]['props'], array( 'text' => 'Hi', 'level' => 2 ) );
	$dropped = array_column( rk_test_actions( $r, 'dropped' ), 'from', 'field' );
	t_eq( $dropped, array( 'color' => 'red', 'animate' => 'true' ) );
	// props already nested but with extra block-level keys
	$layout = rk_builder_migrate_legacy_layout( array( array( 'id' => 'h', 'type' => 'heading', 'props' => array( 'text' => 'Hi', 'level' => 3 ), 'locked' => true ) ), $r );
	t_eq( array_column( rk_test_actions( $r, 'dropped' ), 'field' ), array( 'locked' ) );
	t_eq( $layout['blocks'][0]['props']['level'], 3 );
} );

rk_test( 'convert: blocks that cannot be made valid are reported with their path; the layout is not produced', function () {
	$cases = array(
		'unknown type'  => array( array( 'id' => 'x', 'type' => 'carousel', 'slides' => 3 ), 'blocks.0.type' ),
		'empty heading' => array( array( 'id' => 'x', 'type' => 'hero', 'heading' => '', 'sub' => '', 'cta' => '' ), 'blocks.0.props.heading' ),
		'bad link'      => array( array( 'id' => 'x', 'type' => 'cta', 'heading' => 'H', 'cta' => 'Go', 'ctaHref' => 'javascript:alert(1)' ), 'blocks.0.props.ctaHref' ),
		'bad grid'      => array( array( 'id' => 'x', 'type' => 'services', 'title' => 'T', 'source' => 'services', 'cols' => 9 ), 'blocks.0.props.cols' ),
		'image no alt'  => array( array( 'id' => 'x', 'type' => 'image', 'url' => '/a.png' ), 'blocks.0.props.alt' ),
		'foreign image' => array( array( 'id' => 'x', 'type' => 'image', 'url' => 'https://evil.example/a.png', 'alt' => 'a', 'decorative' => false ), 'blocks.0.props.url' ),
		'not an object' => array( 'just a string', 'blocks.0' ),
	);
	foreach ( $cases as $name => $c ) {
		$layout = rk_builder_migrate_legacy_layout( array( $c[0] ), $r );
		t_eq( $layout, null, $name );
		t_eq( $r['ok'], false, $name );
		t_assert( in_array( $c[1], array_column( $r['issues'], 'path' ), true ), $name . ': ' . wp_json_encode( $r['issues'] ) );
	}
	// the failing block's position is preserved in a longer list
	rk_builder_migrate_legacy_layout( array( array( 'id' => 'a', 'type' => 'spacer', 'h' => 8 ), array( 'id' => 'b', 'type' => 'cta', 'heading' => 'H', 'cta' => 'Go', 'ctaHref' => '//evil.example' ) ), $r );
	t_eq( array_column( $r['issues'], 'path' ), array( 'blocks.1.props.ctaHref' ) );
} );

rk_test( 'convert: garbage input is reported, never fatal', function () {
	foreach ( array( '{not json', '"str"', 12, null, true, array( 'foo' => 'bar' ), array( 'blocks' => 'nope' ) ) as $bad ) {
		$r = null;
		t_eq( rk_builder_migrate_legacy_layout( $bad, $r ), null, var_export( $bad, true ) );
		t_assert( count( $r['issues'] ) >= 1 );
	}
	$many = array_fill( 0, 101, array( 'type' => 'spacer', 'h' => 8 ) );
	t_eq( rk_builder_migrate_legacy_layout( $many, $r ), null );
	t_assert( false !== strpos( $r['issues'][0]['message'], 'at most 100' ) );
} );

rk_test( 'report values are truncated: no layout bodies end up in a report', function () {
	rk_builder_migrate_legacy_layout( array( array( 'id' => 'h', 'type' => 'heading', 'text' => 'Hi', 'blob' => str_repeat( 'Z', 5000 ) ) ), $r );
	t_assert( strlen( wp_json_encode( $r ) ) < 1000 );
} );

/* ---------------- pages ---------------- */

rk_test( 'find legacy layouts: only pages with _rk_layout', function () {
	$a = rk_test_legacy_page();
	$b = rk_test_legacy_page( null, 'publish', 'b' );
	rk_test_page( 'draft', 'plain' );
	$empty = rk_test_page( 'draft', 'empty' ); update_post_meta( $empty, '_rk_layout', '' );
	$c = rk_test_page( 'draft', 'arr' ); update_post_meta( $c, '_rk_layout', rk_test_legacy_blocks() ); // array meta works too
	t_eq( rk_builder_find_legacy_layouts(), array( $a, $b, $c ) );
	rk_builder_migrate_legacy_layout( get_post_meta( $c, '_rk_layout', true ), $r );
	t_eq( $r['ok'], true );
} );

rk_test( 'dry run (the default) writes nothing but records a summary', function () {
	rk_test_login( 'admin' );
	$a = rk_test_legacy_page();
	$bad = rk_test_legacy_page( array( array( 'id' => 'x', 'type' => 'carousel' ) ), 'draft', 'bad' );
	$before = $GLOBALS['RK']['meta'];
	$posts = $GLOBALS['RK']['posts'];
	$res = rk_builder_run_migration();
	t_eq( $GLOBALS['RK']['meta'], $before, 'no meta written' );
	t_eq( $GLOBALS['RK']['posts'], $posts );
	t_eq( $res['summary']['dry_run'], true );
	t_eq( $res['rows'][0]['status'], 'would_migrate' );
	t_eq( $res['rows'][1]['status'], 'failed' );
	t_eq( $res['rows'][1]['needs_manual_fix'], true );
	$last = get_option( 'rk_builder_last_migration' );
	t_eq( array_keys( $last ), array( 'time', 'user', 'dry_run', 'migrated', 'skipped', 'failed' ) );
	t_eq( $last['migrated'], 1 ); t_eq( $last['failed'], 1 ); t_eq( $last['skipped'], 0 ); t_eq( $last['user'], 1 ); t_eq( $last['dry_run'], true );
	t_eq( rk_builder_last_migration(), $last );
	t_eq( rk_builder_get_revision( $a ), 0 );
} );

rk_test( 'run: writes the draft as a draft revision only; _rk_layout, published snapshot and status are untouched', function () {
	rk_test_login( 'admin' );
	$id = rk_test_legacy_page( null, 'publish', 'home' );
	// pre-existing published snapshot but no draft yet
	$pub = rk_spacer_layout( 99 );
	rk_builder_write_json_meta( $id, '_rk_layout_published', $pub );
	update_post_meta( $id, '_rk_published_revision', 5 );
	update_post_meta( $id, '_rk_published_at', '2026-01-01T00:00:00Z' );
	$legacy_before = get_post_meta( $id, '_rk_layout', true );
	$status_before = get_post( $id )->post_status;

	$res = rk_builder_run_migration( array( 'dry_run' => false ) );
	t_eq( $res['rows'][0]['status'], 'migrated' );
	t_eq( $res['summary']['dry_run'], false );
	$draft = rk_builder_get_draft_layout( $id );
	t_eq( count( $draft['blocks'] ), 5 );
	t_eq( rk_builder_validate_layout( $draft, null ), array() );
	t_eq( rk_builder_get_revision( $id ), 1 );
	$recs = rk_builder_get_revision_records( $id );
	t_eq( count( $recs ), 1 ); t_eq( $recs[0]['kind'], 'draft' ); t_eq( $recs[0]['author_id'], 1 );
	t_eq( get_post_meta( $id, '_rk_layout', true ), $legacy_before, '_rk_layout kept byte for byte' );
	t_deep( rk_builder_get_published_layout( $id ), $pub );
	t_eq( (int) get_post_meta( $id, '_rk_published_revision', true ), 5 );
	t_eq( get_post_meta( $id, '_rk_published_at', true ), '2026-01-01T00:00:00Z' );
	t_eq( get_post( $id )->post_status, $status_before );
	t_eq( get_option( 'rk_builder_last_migration' )['migrated'], 1 );
	t_eq( rk_builder_lock( $id ), true, 'lock released' );
	rk_builder_unlock( $id );
	// the migrated draft loads through the REST layer like any other
	$r = t_ok( rk_get( '/rk/v1/builder/layout/' . $id ) );
	t_eq( $r['revision'], 1 );
} );

rk_test( 'run: pages that already have a draft are skipped; overwrite replaces it and keeps history', function () {
	rk_test_login( 'admin' );
	$id = rk_test_legacy_page();
	t_ok( rk_save( $id, rk_spacer_layout( 77 ), 0 ) );
	$res = rk_builder_run_migration( array( 'dry_run' => false ) );
	t_eq( $res['rows'][0]['status'], 'skipped' );
	t_assert( false !== strpos( $res['rows'][0]['reason'], 'overwrite' ) );
	t_eq( $res['summary']['skipped'], 1 ); t_eq( $res['summary']['migrated'], 0 );
	t_deep( rk_builder_get_draft_layout( $id ), rk_spacer_layout( 77 ), 'draft untouched' );
	t_eq( rk_builder_get_revision( $id ), 1 );
	$res = rk_builder_run_migration( array( 'dry_run' => false, 'overwrite' => true ) );
	t_eq( $res['rows'][0]['status'], 'migrated' );
	t_eq( count( rk_builder_get_draft_layout( $id )['blocks'] ), 5 );
	t_eq( rk_builder_get_revision( $id ), 2 );
	$recs = rk_builder_get_revision_records( $id );
	t_eq( count( $recs ), 2 );
	t_deep( $recs[0]['layout'], rk_spacer_layout( 77 ), 'the replaced draft is still in the history' );
	// idempotent re-run without overwrite does not stack revisions
	$res = rk_builder_run_migration( array( 'dry_run' => false ) );
	t_eq( $res['rows'][0]['status'], 'skipped' );
	t_eq( rk_builder_get_revision( $id ), 2 );
} );

rk_test( 'run: an invalid page is "needs manual fix", is not migrated, and does not stop the others', function () {
	rk_test_login( 'admin' );
	$bad  = rk_test_legacy_page( array( array( 'id' => 'a', 'type' => 'hero', 'heading' => 'ok', 'sub' => '', 'cta' => '' ), array( 'id' => 'b', 'type' => 'carousel' ) ), 'draft', 'bad' );
	$good = rk_test_legacy_page( null, 'draft', 'good' );
	$res  = rk_builder_run_migration( array( 'dry_run' => false ) );
	t_eq( array_column( $res['rows'], 'status', 'page_id' ), array( $bad => 'failed', $good => 'migrated' ) );
	t_eq( get_post_meta( $bad, '_rk_layout_draft', true ), '', 'nothing stored for the bad page' );
	t_eq( rk_builder_get_revision( $bad ), 0 );
	$row = $res['rows'][0];
	t_eq( $row['needs_manual_fix'], true );
	t_eq( array_column( $row['issues'], 'path' ), array( 'blocks.1.type' ) );
	t_assert( false !== strpos( $row['reason'], 'manual fix' ) );
	t_eq( $res['summary']['failed'], 1 ); t_eq( $res['summary']['migrated'], 1 );
	t_assert( '' !== get_post_meta( $bad, '_rk_layout', true ), 'original kept' );
} );

rk_test( 'run: a held page lock is reported as a failure, not a silent overwrite', function () {
	rk_test_login( 'admin' );
	$id = rk_test_legacy_page();
	rk_builder_lock( $id );
	$res = rk_builder_run_migration( array( 'dry_run' => false ) );
	t_eq( $res['rows'][0]['status'], 'failed' );
	t_eq( get_post_meta( $id, '_rk_layout_draft', true ), '' );
	rk_builder_unlock( $id );
} );

rk_test( 'run: page_ids subset only touches those pages', function () {
	rk_test_login( 'admin' );
	$a = rk_test_legacy_page( null, 'draft', 'a' );
	$b = rk_test_legacy_page( null, 'draft', 'b' );
	$res = rk_builder_run_migration( array( 'dry_run' => false, 'page_ids' => array( $b ) ) );
	t_eq( count( $res['rows'] ), 1 );
	t_eq( rk_builder_get_revision( $a ), 0 ); t_eq( rk_builder_get_revision( $b ), 1 );
} );

rk_test( 'reports contain no secrets or tokens', function () {
	rk_test_login( 'admin' );
	rk_test_legacy_page();
	$res = rk_builder_run_migration( array( 'dry_run' => false ) );
	$json = wp_json_encode( $res ) . wp_json_encode( get_option( 'rk_builder_last_migration' ) );
	foreach ( array( RK_BUILDER_PREVIEW_SECRET, RK_BUILDER_REVALIDATE_SECRET, 'nonce-for-user' ) as $s ) { t_assert( false === strpos( $json, $s ) ); }
} );

/* ---------------- admin tool ---------------- */

rk_test( 'migrate handler: manage_options + nonce; a real run also needs the confirm box', function () {
	add_filter( 'rk_builder_exit_after_redirect', function () { return false; } );
	$id = rk_test_legacy_page();
	rk_test_login( 'editor' );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_migrate' ); $_POST['mode'] = 'run'; $_POST['confirm'] = '1';
	$msg = ''; try { rk_builder_handle_migrate(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'wp_die' ) );
	t_eq( rk_builder_get_revision( $id ), 0 );
	rk_test_login( 'admin' );
	$_REQUEST['_wpnonce'] = 'forged';
	$msg = ''; try { rk_builder_handle_migrate(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'expired' ) );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rk_builder_migrate' );
	unset( $_POST['confirm'] );
	$url = rk_builder_handle_migrate();
	t_assert( false !== strpos( $url, 'confirm_required' ), $url );
	t_eq( rk_builder_get_revision( $id ), 0, 'no confirm, no write' );
	$_POST['confirm'] = '1';
	$url = rk_builder_handle_migrate();
	t_assert( false !== strpos( $url, 'rk_notice=migrated' ) );
	t_eq( rk_builder_get_revision( $id ), 1 );
	// default mode is the dry run
	$other = rk_test_legacy_page( null, 'draft', 'o' );
	unset( $_POST['mode'], $_POST['confirm'] );
	$url = rk_builder_handle_migrate();
	t_assert( false !== strpos( $url, 'rk_notice=dry_run' ) );
	t_eq( rk_builder_get_revision( $other ), 0 );
} );

rk_test( 'migration screen: admin only; shows the report with escaped titles and the dry-run/run controls', function () {
	rk_test_login( 'admin' );
	$id = rk_test_legacy_page( null, 'draft', 'x' );
	$GLOBALS['RK']['posts'][ $id ]->post_title = '<img src=x onerror=alert(1)>';
	rk_builder_run_migration();
	set_transient( 'rk_builder_migration_report_1', rk_builder_run_migration() );
	ob_start(); rk_builder_render_migration_page(); $html = ob_get_clean();
	t_assert( false === strpos( $html, '<img src=x' ), 'title escaped' );
	t_assert( false !== strpos( $html, 'Would migrate' ) && false !== strpos( $html, 'Run dry-run report' ) && false !== strpos( $html, 'Run migration' ) );
	t_assert( false !== strpos( $html, 'name="confirm"' ) && false !== strpos( $html, 'name="overwrite"' ) && false !== strpos( $html, 'name="_wpnonce"' ) );
	t_assert( false !== strpos( $html, 'never deleted' ) );
	rk_test_login( 'editor' );
	$msg = ''; try { rk_builder_render_migration_page(); } catch ( Exception $e ) { $msg = $e->getMessage(); }
	t_assert( false !== strpos( $msg, 'wp_die' ) );
} );

/* ---------------- schema steps ---------------- */

rk_test( 'schema upgrade: runs the registered step for the target version and stores it', function () {
	$ran = array();
	add_filter( 'rk_builder_schema_steps', function () use ( &$ran ) {
		return array(
			1 => function () use ( &$ran ) { $ran[] = 1; return true; },
		);
	} );
	t_eq( rk_builder_maybe_upgrade_schema(), 'upgraded' );
	t_eq( $ran, array( 1 ) );
	t_eq( (int) get_option( 'rk_builder_schema_version' ), 1 );
} );

rk_test( 'schema upgrade: failure is recorded and data is not touched', function () {
	$page = rk_test_page( 'draft', 'keep', 2 );
	update_post_meta( $page, '_rk_layout_draft', 'precious' );
	update_option( 'rk_builder_schema_version', 0 );
	add_filter( 'rk_builder_schema_steps', function () { return array( 1 => function () { throw new RuntimeException( 'disk full' ); } ); } );
	t_eq( rk_builder_maybe_upgrade_schema(), 'failed' );
	t_eq( (int) get_option( 'rk_builder_schema_version' ), 0, 'version not bumped' );
	$u = get_option( 'rk_builder_schema_upgrade' );
	t_eq( $u['status'], 'failed' ); t_assert( false !== strpos( $u['error'], 'step 1' ) );
	t_eq( get_post_meta( $page, '_rk_layout_draft', true ), 'precious' );
} );
