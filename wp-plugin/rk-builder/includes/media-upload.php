<?php
/**
 * POST /builder/media: upload an image into the WordPress media library from the editor.
 *
 * multipart/form-data: `file` (required), `alt`, `title`. Same capability as browsing media (upload_files).
 * Images only (JPEG, PNG, GIF, WebP, AVIF). SVG is refused on purpose: it can carry script.
 * The type is decided from the file's content, not its name or the client's Content-Type.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** extension => mime for every image type the builder accepts. */
function rk_builder_upload_mimes() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	);
}

/** Largest accepted image in bytes: WordPress' own upload limit, capped at 10 MB (filterable). */
function rk_builder_max_upload_bytes() {
	$wp  = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 10 * 1024 * 1024;
	$cap = (int) apply_filters( 'rk_builder_max_upload_bytes', 10 * 1024 * 1024 );
	return max( 1024, min( $wp > 0 ? $wp : $cap, $cap ) );
}

/** Make the core upload/image helpers available in a REST request. */
function rk_builder_load_media_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}

/**
 * Check a PHP upload/temp file against the allow-list.
 *
 * @param string $tmp  temp file path
 * @param string $name client file name (only used for the extension hint)
 * @return array{ext:string,type:string}|WP_Error
 */
function rk_builder_check_image_file( $tmp, $name ) {
	$check = wp_check_filetype_and_ext( $tmp, $name, rk_builder_upload_mimes() );
	if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $check['type'], rk_builder_upload_mimes(), true ) ) {
		return rk_builder_error( 'rk_invalid_media', 'Only JPEG, PNG, GIF, WebP and AVIF images can be uploaded.', 415 );
	}
	return array( 'ext' => (string) $check['ext'], 'type' => (string) $check['type'] );
}

/**
 * Add a checked image file to the media library. $file is { name, tmp_name } (the temp file is consumed).
 *
 * @return int|WP_Error attachment ID
 */
function rk_builder_create_attachment( array $file, $alt = '', $title = '' ) {
	rk_builder_load_media_includes();
	$post_data = array();
	if ( '' !== $title ) { $post_data['post_title'] = $title; }
	$id = media_handle_sideload( array( 'name' => $file['name'], 'tmp_name' => $file['tmp_name'] ), 0, null, $post_data );
	if ( is_wp_error( $id ) ) {
		return rk_builder_error( 'rk_invalid_media', 'The image could not be added to the media library.', 400 );
	}
	if ( '' !== $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', $alt ); }
	return (int) $id;
}

function rk_builder_handle_upload_media( $req ) {
	$files = $req->get_file_params();
	$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
	if ( null === $file || ! isset( $file['tmp_name'], $file['name'], $file['error'] ) ) {
		return rk_builder_invalid( 'rk_invalid_media', array( array( 'path' => 'file', 'message' => 'Required: send the image as multipart field "file".' ) ), 'No image was uploaded.' );
	}
	if ( is_array( $file['name'] ) || is_array( $file['tmp_name'] ) ) {
		return rk_builder_invalid( 'rk_invalid_media', array( array( 'path' => 'file', 'message' => 'Upload one image at a time.' ) ), 'Upload one image at a time.' );
	}
	if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
		$too_big = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return $too_big
			? rk_builder_error( 'rk_payload_too_large', 'The image is larger than the server allows.', 413 )
			: rk_builder_error( 'rk_invalid_media', 'The upload did not complete. Try again.', 400 );
	}
	$size = isset( $file['size'] ) ? (int) $file['size'] : (int) @filesize( $file['tmp_name'] );
	if ( $size > rk_builder_max_upload_bytes() ) {
		return rk_builder_error( 'rk_payload_too_large', 'The image is larger than ' . round( rk_builder_max_upload_bytes() / 1048576, 1 ) . ' MB.', 413 );
	}
	if ( $size < 1 ) {
		return rk_builder_error( 'rk_invalid_media', 'The image file is empty.', 400 );
	}
	$name = sanitize_file_name( (string) $file['name'] );
	$kind = rk_builder_check_image_file( $file['tmp_name'], $name );
	if ( is_wp_error( $kind ) ) { return $kind; }

	$alt   = sanitize_text_field( (string) $req->get_param( 'alt' ) );
	$title = sanitize_text_field( (string) $req->get_param( 'title' ) );
	$id    = rk_builder_create_attachment( array( 'name' => $name, 'tmp_name' => $file['tmp_name'] ), $alt, $title );
	if ( is_wp_error( $id ) ) { return $id; }
	$item = rk_builder_media_item( $id );
	if ( null === $item ) { return rk_builder_error( 'rk_server_error', 'The image was saved but could not be read back.', 500 ); }
	$response = rk_builder_no_store( array( 'item' => $item ) );
	if ( $response instanceof WP_REST_Response ) { $response->set_status( 201 ); }
	return $response;
}
