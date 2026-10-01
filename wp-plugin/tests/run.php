<?php
/**
 * Plain-PHP test runner: `php wp-plugin/tests/run.php` (exit code 1 on any failure).
 * No composer / PHPUnit. Test files (*Test.php) register cases with rk_test( 'name', fn ).
 */

error_reporting( E_ALL );
set_error_handler( function ( $no, $str, $file, $line ) {
	throw new ErrorException( $str, 0, $no, $file, $line ); // warnings/notices fail tests
} );

require __DIR__ . '/wp-stubs.php';
rk_test_reset();

// Constants the plugin reads. The preview secret is test-only.
define( 'RK_BUILDER_PREVIEW_SECRET', 'test-only-preview-secret-do-not-print' );
define( 'RK_BUILDER_REVALIDATE_URL', 'https://frontend.example/api/revalidate' );
define( 'RK_BUILDER_REVALIDATE_SECRET', 'test-only-revalidate-secret-do-not-print' );

require dirname( __DIR__ ) . '/rk-builder/rk-builder.php';
do_action( 'init' );
do_action( 'rest_api_init' ); // registers routes once; rk_test_reset() keeps them

/* ---------------- tiny framework ---------------- */

class RK_Assertion extends Exception {}
$GLOBALS['RK_TESTS'] = array();
$GLOBALS['RK_SECRETS'] = array(); // strings that must never appear in output

function rk_test( $name, $fn ) { $GLOBALS['RK_TESTS'][] = array( $name, $fn ); }
function t_assert( $cond, $msg = 'assertion failed' ) { if ( ! $cond ) { throw new RK_Assertion( $msg ); } }
function t_eq( $actual, $expected, $msg = '' ) {
	if ( $actual !== $expected ) {
		throw new RK_Assertion( ( $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) );
	}
}
/** Deep equality ignoring key order. */
function t_deep( $actual, $expected, $msg = '' ) {
	$norm = function ( $v ) use ( &$norm ) { return json_decode( json_encode( $v ), true ); };
	t_assert( rk_builder_deep_equal( $norm( $actual ), $norm( $expected ) ), ( $msg ?: 'documents differ' ) );
}
function t_err( $res, $code, $status, $msg = '' ) {
	t_assert( $res instanceof WP_Error, ( $msg ?: 'expected WP_Error ' . $code ) . ' but got a success response' );
	t_eq( $res->get_error_code(), $code, $msg );
	$data = $res->get_error_data();
	t_eq( $data['status'], $status, ( $msg ?: $code ) . ' status' );
	return $data;
}
function t_ok( $res, $msg = '' ) {
	if ( $res instanceof WP_Error ) { throw new RK_Assertion( ( $msg ?: 'unexpected error' ) . ': ' . $res->get_error_code() . ' ' . $res->get_error_message() ); }
	return $res->get_data();
}

function fx( $rel ) {
	$d = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/contracts/' . $rel ), true );
	if ( ! is_array( $d ) ) { throw new RuntimeException( 'bad fixture ' . $rel ); }
	return $d;
}
function fx_files( $dir ) { $f = glob( dirname( __DIR__, 2 ) . '/contracts/' . $dir . '/*.json' ); sort( $f ); return $f; }

function rk_get( $path, $query = array() ) { return rk_test_request( 'GET', $path, array( 'query' => $query ) ); }
function rk_post( $path, $body = null ) { return rk_test_request( 'POST', $path, null === $body ? array() : array( 'body' => $body ) ); }

/** Page + helper: log in as $who, save $layout at the current revision. Returns the save data. */
function rk_save( $id, $layout, $expected, $extra = array() ) {
	return rk_post( '/rk/v1/builder/layout/' . $id, array_merge( array( 'layout' => $layout, 'expectedRevision' => $expected, 'status' => 'draft' ), $extra ) );
}
function rk_spacer_layout( $h ) { return array( 'version' => 1, 'blocks' => array( array( 'id' => 'sp', 'type' => 'spacer', 'props' => array( 'h' => $h ) ) ) ); }

/* ---------------- load tests ---------------- */

foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) { require $file; }

/* ---------------- run ---------------- */

ob_start();
$pass = 0; $fail = array();
foreach ( $GLOBALS['RK_TESTS'] as $t ) {
	list( $name, $fn ) = $t;
	rk_test_reset();
	rk_test_login( 'anon' );
	try {
		$fn();
		$pass++;
		echo "  ok   $name\n";
	} catch ( Throwable $e ) {
		$fail[] = $name;
		echo "  FAIL $name\n       " . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
	}
}
echo "\n" . $pass . ' passed, ' . count( $fail ) . ' failed, ' . count( $GLOBALS['RK_TESTS'] ) . " total\n";

// Secrets/tokens must never leak into test output.
$out = ob_get_clean();
$leak = false;
foreach ( array_merge( array( RK_BUILDER_PREVIEW_SECRET, RK_BUILDER_REVALIDATE_SECRET ), $GLOBALS['RK_SECRETS'] ) as $secret ) {
	if ( '' !== $secret && false !== strpos( $out, $secret ) ) { $leak = true; }
}
echo $out;
if ( $leak ) { echo "FAIL: a token or secret appeared in the test output\n"; }
exit( ( $fail || $leak ) ? 1 : 0 );
