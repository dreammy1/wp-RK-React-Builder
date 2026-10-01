<?php
/**
 * Print the PHP renderer's HTML for a contracts layout fixture (used by the cross-language parity test).
 *
 *   php wp-plugin/tests/render-cli.php contracts/valid/layout-full.json
 *
 * Boots the plugin on top of the test stubs (no WordPress). Output is ONLY the rendered layout HTML.
 * Grid content comes from a deterministic stub (two items per source, see rk_cli_seed_content()):
 *   Alpha <service>  excerpt "First & best", category "energy", 800x500 featured image "Alpha alt"
 *   Beta             no excerpt, no category, no image
 * Image blocks with a mediaId get an attachment stub with the SAME url/size/srcset/alt as the fixture.
 */

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
error_reporting( E_ALL );
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );

require __DIR__ . '/wp-stubs.php';
rk_test_reset();
require dirname( __DIR__ ) . '/rk-builder/rk-builder.php';
do_action( 'init' );

if ( $argc < 2 || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "usage: php render-cli.php <layout fixture.json>\n" );
	exit( 2 );
}
$fixture = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $fixture ) ) { fwrite( STDERR, "fixture is not JSON\n" ); exit( 2 ); }
$layout = isset( $fixture['document'] ) ? $fixture['document'] : $fixture;

function rk_cli_seed_content() {
	$GLOBALS['RK']['attachments'][801] = array( 'url' => 'https://cms.example.com/a.jpg', 'w' => 800, 'h' => 500, 'title' => 'a' );
	$GLOBALS['RK']['meta'][801]['_wp_attachment_image_alt'] = 'Alpha alt';
	$tax = array( 'service' => 'service_cat', 'portfolio' => 'portfolio_cat' );
	foreach ( $tax as $type => $taxonomy ) {
		$a = rk_test_page( 'publish', $type . '-alpha', 1, array( 'post_type' => $type, 'post_title' => 'Alpha &lt;service&gt;', 'post_excerpt' => 'First &amp; best', 'menu_order' => 1, 'post_date' => '2026-02-01 00:00:00' ) );
		$GLOBALS['RK']['terms'][ $a ][ $taxonomy ] = array( 'energy' );
		$GLOBALS['RK']['meta'][ $a ]['_thumbnail_id'] = '801';
		rk_test_page( 'publish', $type . '-beta', 1, array( 'post_type' => $type, 'post_title' => 'Beta', 'menu_order' => 2, 'post_date' => '2026-01-01 00:00:00' ) );
	}
}

function rk_cli_seed_attachments( array $layout ) {
	foreach ( isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ? $layout['blocks'] : array() as $b ) {
		if ( isset( $b['type'], $b['props']['mediaId'], $b['props']['url'] ) && 'image' === $b['type'] ) {
			$p = $b['props'];
			$GLOBALS['RK']['attachments'][ (int) $p['mediaId'] ] = array(
				'url' => $p['url'], 'w' => isset( $p['width'] ) ? $p['width'] : 0, 'h' => isset( $p['height'] ) ? $p['height'] : 0, 'title' => 't',
				'srcset' => isset( $p['srcset'] ) ? $p['srcset'] : false,
			);
			$GLOBALS['RK']['meta'][ (int) $p['mediaId'] ]['_wp_attachment_image_alt'] = isset( $p['alt'] ) ? $p['alt'] : '';
		}
	}
}

rk_cli_seed_content();
rk_cli_seed_attachments( $layout );
echo rk_builder_render_layout( $layout, array( 'page_id' => 0, 'preview' => false ) );
