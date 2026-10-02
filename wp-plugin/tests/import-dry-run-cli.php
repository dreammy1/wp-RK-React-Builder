<?php
/**
 * Run a site-export bundle through the importer's DRY RUN (the real validators, no WordPress) and print the report.
 *
 *   php wp-plugin/tests/import-dry-run-cli.php dist/peoria/1-core.json [--theme] [--content]
 *
 * Exit code 1 when any page, reusable block or the theme is rejected.
 */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
error_reporting( E_ALL );
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );
require __DIR__ . '/wp-stubs.php';
rk_test_reset();
require dirname( __DIR__ ) . '/rk-builder/rk-builder.php';
do_action( 'init' );
do_action( 'rest_api_init' );

$file = isset( $argv[1] ) ? $argv[1] : '';
if ( ! is_file( $file ) ) { fwrite( STDERR, "usage: php import-dry-run-cli.php <bundle.json> [--theme] [--content]\n" ); exit( 2 ); }
$bundle = json_decode( (string) file_get_contents( $file ), true );
if ( ! is_array( $bundle ) ) { fwrite( STDERR, "not JSON\n" ); exit( 2 ); }

function rk_cli_json_body( $bundle, $opts ) { return array( 'bundle' => $bundle, 'options' => array_merge( array( 'dryRun' => true ), $opts ) ); }
function rk_cli_post( $path, $body ) {
	$req = new WP_REST_Request( 'POST', $path );
	$req->body = json_encode( $body );
	$req->hdr['content-type'] = 'application/json';
	return rk_builder_handle_site_import( $req );
}
rk_test_login( 'admin' );
$opts = array( 'theme' => in_array( '--theme', $argv, true ), 'content' => in_array( '--content', $argv, true ) );
$res = rk_cli_post( '/rk/v1/builder/site-import', rk_cli_json_body( $bundle, $opts ) );
if ( $res instanceof WP_Error ) { fwrite( STDERR, 'ERROR ' . $res->get_error_code() . ': ' . $res->get_error_message() . "\n" . json_encode( $res->get_error_data() ) . "\n" ); exit( 1 ); }
$r = $res->get_data();
echo json_encode( array(
	'pages' => array( 'create' => $r['pages']['create'], 'update' => $r['pages']['update'], 'skipped' => $r['pages']['skipped'] ),
	'reusables' => $r['reusables'],
	'media' => array( 'total' => $r['media']['total'], 'failed' => $r['media']['failed'] ),
	'theme' => $r['theme'],
	'content' => $r['content'],
	'warnings' => $r['warnings'],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
$bad = count( $r['pages']['skipped'] ) + count( $r['reusables']['skipped'] ) + count( $r['media']['failed'] ) + ( $opts['theme'] && ! $r['theme']['included'] ? 1 : 0 );
exit( $bad > 0 ? 1 : 0 );
