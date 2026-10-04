<?php
/**
 * Site kits: a whole site in ONE zip that carries its own pictures.
 *
 *   manifest.json   { format:"rk-builder-kit", formatVersion:1, name, description, version, author, industry, license,
 *                     demo, requires:{ plugin }, counts, createdAt }
 *   site.json       a normal site bundle (see site-transfer.php); every media entry may add  "file": "images/<name>"
 *   images/<name>   the picture itself
 *
 * The kit is read, never run: only the three kinds of entry above are accepted, each is size-checked, pictures are
 * re-checked as images by WordPress on the way in, and a picture missing from the zip falls back to its old address.
 *
 *   POST /builder/kits/export   { name, description?, version?, author?, industry?, license?, demo? }  → the .zip
 *   POST /builder/kits/upload   multipart "file"                                                       → adds it to the theme library
 *
 * A kit in the library is installed, exported (as the same zip) and deleted through the /builder/themes routes.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_KIT_FORMAT' ) ) { define( 'RK_BUILDER_KIT_FORMAT', 'rk-builder-kit' ); }
if ( ! defined( 'RK_BUILDER_MAX_KIT_JSON' ) ) { define( 'RK_BUILDER_MAX_KIT_JSON', 16 * 1024 * 1024 ); }
if ( ! defined( 'RK_BUILDER_MAX_KIT_BYTES' ) ) { define( 'RK_BUILDER_MAX_KIT_BYTES', 200 * 1024 * 1024 ); }

/* ------------------------------------------------------------------ *
 * Pure helpers
 * ------------------------------------------------------------------ */

/** The only entry names a kit may hold. */
function rk_builder_kit_entry_ok( $name ) {
	return is_string( $name ) && 1 === preg_match( '#^(manifest\.json|site\.json|images/[A-Za-z0-9][A-Za-z0-9._-]{0,119})\z#', $name );
}

/** Junk that file managers add when they zip a folder; ignored rather than rejected. */
function rk_builder_kit_entry_ignored( $name ) {
	return '/' === substr( $name, -1 ) || 0 === strpos( $name, '__MACOSX/' ) || '.DS_Store' === basename( $name );
}

/** A picture's name inside the zip: its id first (so two files with one name never clash), then a safe version of its name. */
function rk_builder_kit_image_name( $id, $filename ) {
	$base = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $filename );
	$base = ltrim( trim( $base, '-.' ), '.-' );
	if ( '' === $base ) { $base = 'image'; }
	return 'images/' . (int) $id . '-' . substr( $base, 0, 100 );
}

/** Clean manifest fields (what the library shows). */
function rk_builder_kit_meta_clean( $in ) {
	$in   = is_array( $in ) ? $in : array();
	$meta = rk_builder_theme_meta_clean( $in );
	$meta['industry'] = rk_builder_theme_text( isset( $in['industry'] ) ? $in['industry'] : '', 60 );
	$meta['license']  = rk_builder_theme_text( isset( $in['license'] ) ? $in['license'] : '', 80 );
	$demo = isset( $in['demo'] ) && is_string( $in['demo'] ) ? trim( $in['demo'] ) : '';
	$meta['demo'] = '' !== $demo && null !== rk_builder_parse_http_authority( $demo ) && strlen( $demo ) <= 300 ? $demo : '';
	return $meta;
}

/** Problem with a manifest's compatibility, or ''. */
function rk_builder_kit_manifest_problem( $m, $plugin_version ) {
	if ( ! is_array( $m ) || ! isset( $m['format'] ) || RK_BUILDER_KIT_FORMAT !== $m['format'] ) { return 'This zip is not an RK Builder site kit (manifest.json is missing or has the wrong format).'; }
	if ( ! isset( $m['formatVersion'] ) || 1 !== $m['formatVersion'] ) { return 'This kit uses a newer file format than this plugin can read. Update RK Builder and try again.'; }
	$need = isset( $m['requires']['plugin'] ) && is_string( $m['requires']['plugin'] ) ? $m['requires']['plugin'] : '';
	if ( '' !== $need && 1 === preg_match( '/^[0-9][0-9A-Za-z.+-]{0,19}\z/', $need ) && version_compare( $plugin_version, $need, '<' ) ) {
		return 'This kit needs RK Builder ' . $need . ' or newer (this site has ' . $plugin_version . ').';
	}
	return '';
}

/* ------------------------------------------------------------------ *
 * Reading a kit
 * ------------------------------------------------------------------ */

function rk_builder_kit_unsupported() {
	return rk_builder_error( 'rk_unsupported', 'This server cannot open zip files (the PHP zip extension is missing).', 501 );
}

/**
 * Open and check a kit zip.
 *
 * @return array{zip:ZipArchive,manifest:array,bundle:array,images:int}|WP_Error  the caller closes `zip`
 */
function rk_builder_kit_open( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) { return rk_builder_kit_unsupported(); }
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) { return rk_builder_error( 'rk_invalid_kit', 'That file is not a readable zip.', 400 ); }
	$fail = function ( $msg ) use ( $zip ) { $zip->close(); return rk_builder_error( 'rk_invalid_kit', $msg, 400 ); };

	if ( $zip->numFiles > RK_BUILDER_MAX_TRANSFER_MEDIA + 40 ) { return $fail( 'The kit has too many files.' ); }
	$total  = 0;
	$images = 0;
	$have   = array();
	$img_max = rk_builder_max_upload_bytes();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$st = $zip->statIndex( $i );
		if ( ! is_array( $st ) || ! isset( $st['name'], $st['size'] ) ) { return $fail( 'The zip could not be read.' ); }
		$name = (string) $st['name'];
		if ( rk_builder_kit_entry_ignored( $name ) ) { continue; }
		if ( ! rk_builder_kit_entry_ok( $name ) ) { return $fail( 'The kit holds a file it should not: "' . substr( $name, 0, 60 ) . '". A kit contains only manifest.json, site.json and an images folder.' ); }
		$size = (int) $st['size'];
		$total += $size;
		if ( 0 === strpos( $name, 'images/' ) ) {
			$images++;
			if ( $size > $img_max ) { return $fail( 'A picture in the kit is larger than the upload limit (' . $name . ').' ); }
		} elseif ( $size > RK_BUILDER_MAX_KIT_JSON ) {
			return $fail( $name . ' is too large.' );
		}
		$have[ $name ] = true;
	}
	if ( $total > RK_BUILDER_MAX_KIT_BYTES ) { return $fail( 'The kit is larger than ' . ( RK_BUILDER_MAX_KIT_BYTES / 1048576 ) . ' MB unpacked.' ); }
	if ( empty( $have['manifest.json'] ) || empty( $have['site.json'] ) ) { return $fail( 'The kit needs both manifest.json and site.json.' ); }

	$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
	$problem  = rk_builder_kit_manifest_problem( $manifest, RK_BUILDER_VERSION );
	if ( '' !== $problem ) { return $fail( $problem ); }
	$bundle = json_decode( (string) $zip->getFromName( 'site.json' ), true );
	$shape  = rk_builder_bundle_check_shape( $bundle );
	if ( $shape ) { return $fail( 'site.json is not a usable RK Builder site export: ' . $shape[0]['path'] . ' ' . $shape[0]['message'] ); }
	return array( 'zip' => $zip, 'manifest' => $manifest, 'bundle' => $bundle, 'images' => $images );
}

/**
 * Copy one picture out of the zip into a temp file (never trusting the declared size).
 *
 * @return string|WP_Error temp file path
 */
function rk_builder_kit_extract( $zip, $entry, $max ) {
	if ( ! rk_builder_kit_entry_ok( $entry ) || 0 !== strpos( $entry, 'images/' ) ) { return rk_builder_error( 'rk_invalid_kit', 'Not a kit picture.', 400 ); }
	$in = $zip->getStream( $entry );
	if ( ! is_resource( $in ) ) { return rk_builder_error( 'rk_invalid_kit', 'The picture is not in the kit.', 400 ); }
	$tmp = wp_tempnam( basename( $entry ) );
	$out = $tmp ? fopen( $tmp, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $out ) { fclose( $in ); return rk_builder_error( 'rk_server_error', 'Could not create a temporary file.', 500 ); } // phpcs:ignore WordPress.WP.AlternativeFunctions
	$written = 0;
	while ( ! feof( $in ) ) {
		$chunk = fread( $in, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $chunk ) { break; }
		$written += strlen( $chunk );
		if ( $written > $max ) { break; }
		fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $written > $max || 0 === $written ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return rk_builder_error( 'rk_invalid_kit', 'A picture in the kit is empty or too large.', 400 );
	}
	return $tmp;
}

/* ------------------------------------------------------------------ *
 * Building a kit
 * ------------------------------------------------------------------ */

/**
 * Pack this site into a kit zip in a temp file.
 *
 * @param array $meta cleaned manifest fields (rk_builder_kit_meta_clean)
 * @return array{path:string,images:int,bytes:int,bundle:array}|WP_Error
 */
function rk_builder_kit_build( array $meta ) {
	if ( ! class_exists( 'ZipArchive' ) ) { return rk_builder_kit_unsupported(); }
	$bundle = rk_builder_build_site_bundle();
	if ( is_wp_error( $bundle ) ) { return $bundle; }
	if ( empty( $bundle['pages'] ) ) { return rk_builder_error( 'rk_empty', 'This site has no builder pages to package yet.', 409 ); }
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! function_exists( 'wp_tempnam' ) ) { rk_builder_load_media_includes(); }

	$path = wp_tempnam( 'rk-kit.zip' );
	$zip  = new ZipArchive();
	if ( ! $path || true !== $zip->open( $path, ZipArchive::OVERWRITE | ZipArchive::CREATE ) ) { return rk_builder_error( 'rk_server_error', 'Could not create the zip file.', 500 ); }

	$img_max = rk_builder_max_upload_bytes();
	$total   = 0;
	$images  = 0;
	foreach ( $bundle['media'] as $i => $m ) {
		$file = isset( $m['id'] ) && function_exists( 'get_attached_file' ) ? (string) get_attached_file( (int) $m['id'] ) : '';
		if ( '' === $file || ! is_readable( $file ) ) { continue; }
		$size = (int) filesize( $file );
		if ( $size < 1 || $size > $img_max || $total + $size > RK_BUILDER_MAX_KIT_BYTES ) { continue; }
		$name = rk_builder_kit_image_name( (int) $m['id'], basename( $file ) );
		if ( ! $zip->addFile( $file, $name ) ) { continue; }
		$bundle['media'][ $i ]['file'] = $name;
		$total += $size;
		$images++;
	}

	$bundle['themeMeta'] = array( 'name' => $meta['name'], 'slug' => rk_builder_theme_slugify( $meta['name'] ) );
	$manifest = array(
		'format'        => RK_BUILDER_KIT_FORMAT,
		'formatVersion' => 1,
		'name'          => $meta['name'],
		'description'   => $meta['description'],
		'version'       => $meta['version'],
		'author'        => $meta['author'],
		'industry'      => $meta['industry'],
		'license'       => $meta['license'],
		'demo'          => $meta['demo'],
		'requires'      => array( 'plugin' => RK_BUILDER_VERSION ),
		'createdAt'     => rk_builder_iso( rk_builder_now() ),
		'counts'        => array(
			'pages' => count( $bundle['pages'] ), 'reusables' => count( $bundle['reusables'] ), 'templates' => count( $bundle['templates'] ),
			'types' => count( $bundle['types'] ), 'content' => count( $bundle['content'] ) + count( $bundle['entries'] ), 'images' => $images,
		),
	);
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
	$site  = wp_json_encode( $bundle, $flags );
	$man   = wp_json_encode( $manifest, $flags );
	if ( ! is_string( $site ) || ! is_string( $man ) || strlen( $site ) > RK_BUILDER_MAX_KIT_JSON ) {
		$zip->close();
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return rk_builder_error( 'rk_payload_too_large', 'The site data is too large for one kit.', 413 );
	}
	$zip->addFromString( 'manifest.json', $man );
	$zip->addFromString( 'site.json', $site );
	if ( ! $zip->close() ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return rk_builder_error( 'rk_server_error', 'Could not write the zip file.', 500 );
	}
	return array( 'path' => $path, 'images' => $images, 'bytes' => (int) filesize( $path ), 'bundle' => $bundle );
}

/* ------------------------------------------------------------------ *
 * Library storage
 * ------------------------------------------------------------------ */

/** Folder that holds the zips of kits in the library (not browsable). */
function rk_builder_kit_dir() {
	$u   = wp_upload_dir();
	$dir = rtrim( (string) $u['basedir'], '/' ) . '/rk-builder-kits';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		@file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
	return $dir;
}

/** Full path of a library kit's zip, or '' when the package is a plain JSON one (or the file is gone). */
function rk_builder_theme_kit_path( $slug ) {
	$idx = rk_builder_themes_index();
	if ( ! isset( $idx[ $slug ]['kitFile'] ) || ! is_string( $idx[ $slug ]['kitFile'] ) || 1 !== preg_match( '/^[a-z0-9-]{1,60}-[a-f0-9]{16}\.zip\z/', $idx[ $slug ]['kitFile'] ) ) { return ''; }
	$p = rk_builder_kit_dir() . '/' . $idx[ $slug ]['kitFile'];
	return is_readable( $p ) ? $p : '';
}

/** Remove a library kit's zip from disk. */
function rk_builder_theme_kit_forget( $slug ) {
	$p = rk_builder_theme_kit_path( $slug );
	if ( '' !== $p ) { @unlink( $p ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}

/** Add a checked kit to the library (replacing a package with the same slug). @return array|WP_Error summary */
function rk_builder_kit_store( $slug, array $bundle, array $meta, $zip_path, array $manifest ) {
	$index = rk_builder_themes_index();
	if ( ! isset( $index[ $slug ] ) && count( $index ) >= RK_BUILDER_MAX_THEMES ) {
		return rk_builder_error( 'rk_limit', 'The library holds at most ' . RK_BUILDER_MAX_THEMES . ' themes. Delete one first.', 409 );
	}
	$file = $slug . '-' . bin2hex( random_bytes( 8 ) ) . '.zip';
	$dest = rk_builder_kit_dir() . '/' . $file;
	$moved = ( function_exists( 'is_uploaded_file' ) && is_uploaded_file( $zip_path ) ) ? @move_uploaded_file( $zip_path, $dest ) : @copy( $zip_path, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	if ( ! $moved ) { return rk_builder_error( 'rk_server_error', 'Could not save the kit on this server.', 500 ); }
	rk_builder_theme_kit_forget( $slug );
	$bundle['themeMeta'] = array_merge( $meta, array( 'slug' => $slug, 'createdAt' => isset( $manifest['createdAt'] ) && is_string( $manifest['createdAt'] ) ? $manifest['createdAt'] : rk_builder_iso( rk_builder_now() ) ) );
	$summary = rk_builder_theme_summary( $slug, $bundle, $meta, (int) filesize( $dest ) );
	$summary['kit']     = true;
	$summary['kitFile'] = $file;
	$summary['images']  = isset( $manifest['counts']['images'] ) ? (int) $manifest['counts']['images'] : 0;
	foreach ( array( 'industry', 'license', 'demo' ) as $k ) { $summary[ $k ] = isset( $meta[ $k ] ) ? $meta[ $k ] : ''; }
	$index[ $slug ] = $summary;
	update_option( 'rk_builder_theme_index', $index, false );
	return $summary;
}

/** What the library list shows: never the on-disk file name. */
function rk_builder_kit_public_summary( array $s ) {
	unset( $s['kitFile'] );
	return $s;
}

/* ------------------------------------------------------------------ *
 * REST
 * ------------------------------------------------------------------ */

/** Send a file as a download from inside a REST callback, then delete it (WordPress would otherwise JSON-encode the response). */
function rk_builder_kit_stream_once( $route, $path, $filename ) {
	add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) use ( $route, $path, $filename ) {
		if ( $served || ! is_object( $request ) || $request->get_route() !== $route ) { return $served; }
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}, 10, 3 );
}

function rk_builder_handle_kit_export( $req ) {
	$body = rk_builder_themes_body( $req, array( 'name', 'description', 'version', 'author', 'industry', 'license', 'demo' ) );
	if ( is_wp_error( $body ) ) { return $body; }
	$meta = rk_builder_kit_meta_clean( $body );
	if ( '' === $meta['name'] ) { return rk_builder_invalid( 'rk_invalid_theme', array( array( 'path' => 'name', 'message' => 'Give the kit a name.' ) ) ); }
	$built = rk_builder_kit_build( $meta );
	if ( is_wp_error( $built ) ) { return $built; }
	$fn = 'rk-kit-' . rk_builder_theme_slugify( $meta['name'] ) . '-' . $meta['version'] . '.zip';
	rk_builder_kit_stream_once( $req->get_route(), $built['path'], $fn );
	register_shutdown_function( function () use ( $built ) { @unlink( $built['path'] ); } ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	return rk_builder_no_store( array( 'file' => $fn, 'images' => $built['images'], 'bytes' => $built['bytes'] ) );
}

function rk_builder_handle_kit_upload( $req ) {
	$files = $req->get_file_params();
	$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
	if ( null === $file || ! isset( $file['tmp_name'], $file['error'] ) || is_array( $file['tmp_name'] ) ) {
		return rk_builder_invalid( 'rk_invalid_kit', array( array( 'path' => 'file', 'message' => 'Required: send the kit as multipart field "file".' ) ), 'No kit was uploaded.' );
	}
	if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
		$big = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return rk_builder_error( $big ? 'rk_payload_too_large' : 'rk_invalid_kit', $big ? 'The kit is larger than this server accepts. Ask your host to raise upload_max_filesize, or install the kit from a smaller file.' : 'The upload failed. Try again.', $big ? 413 : 400 );
	}
	$added = rk_builder_kit_add_file( (string) $file['tmp_name'] );
	return is_wp_error( $added ) ? $added : rk_builder_no_store( array( 'theme' => rk_builder_kit_public_summary( $added['theme'] ), 'check' => $added['check'] ) );
}

/**
 * Check a kit zip on disk and add it to the library (shared by the upload and the Kit Library download).
 *
 * @return array{theme:array,check:array}|WP_Error
 */
function rk_builder_kit_add_file( $path, $library_id = '' ) {
	$open = rk_builder_kit_open( $path );
	if ( is_wp_error( $open ) ) { return $open; }
	$open['zip']->close();
	$meta = rk_builder_kit_meta_clean( $open['manifest'] );
	if ( '' === $meta['name'] ) { $meta['name'] = 'Imported kit'; }
	$check = rk_builder_site_import_run( $open['bundle'], array( 'dryRun' => true, 'theme' => true, 'content' => true, 'contentStatus' => 'draft' ) );
	if ( is_wp_error( $check ) ) { return $check; }
	$report = $check instanceof WP_REST_Response ? $check->get_data() : $check;
	if ( empty( $report['pages']['create'] ) && empty( $report['pages']['update'] ) ) {
		return rk_builder_invalid( 'rk_invalid_kit', array( array( 'path' => 'pages', 'message' => 'The kit has no usable pages.' ) ), 'There is nothing in this kit to install.' );
	}
	$saved = rk_builder_kit_store( rk_builder_theme_pick_slug( $meta['name'] ), $open['bundle'], $meta, $path, $open['manifest'] );
	if ( is_wp_error( $saved ) ) { return $saved; }
	if ( '' !== $library_id ) {
		$index = rk_builder_themes_index();
		if ( isset( $index[ $saved['slug'] ] ) ) {
			$index[ $saved['slug'] ]['libraryId'] = $library_id;
			update_option( 'rk_builder_theme_index', $index, false );
			$saved['libraryId'] = $library_id;
		}
	}
	return array( 'theme' => $saved, 'check' => $report );
}

function rk_builder_register_kit_routes( $ns ) {
	$perm = 'rk_builder_perm_site_transfer';
	register_rest_route( $ns, '/builder/kits/export', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_kit_export', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/builder/kits/upload', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_kit_upload', 'permission_callback' => $perm ) );
}
