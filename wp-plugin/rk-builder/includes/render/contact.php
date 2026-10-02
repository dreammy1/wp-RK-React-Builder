<?php
/** Contact details block. Mirrors client/src/blocks/contact/{View,schema}.tsx. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Digits and "+" only; empty when fewer than three digits. */
function rk_builder_phone_href( $phone ) {
	$d = preg_replace( '/[^0-9+]/', '', (string) $phone );
	return strlen( str_replace( '+', '', $d ) ) >= 3 ? 'tel:' . $d : '';
}

function rk_builder_email_href( $email ) {
	return 1 === preg_match( '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\z/', (string) $email ) ? 'mailto:' . $email : '';
}

function rk_builder_contact_row( $label, $value, $href = '' ) {
	if ( '' === $value ) { return ''; }
	$inner = '' !== $href ? '<a href="' . rk_builder_h( $href ) . '">' . rk_builder_h( $value ) . '</a>' : '<strong>' . rk_builder_h( $value ) . '</strong>';
	return '<li><span>' . $label . '</span>' . $inner . '</li>';
}

function rk_builder_render_contact( array $p, array $context = array() ) {
	$html = '<section ' . rk_builder_root_attrs( 'contact', 'site-contact' ) . ' id="contact"><h2>' . rk_builder_h( $p['heading'] ) . '</h2>';
	if ( '' !== $p['intro'] ) { $html .= '<p>' . rk_builder_h( $p['intro'] ) . '</p>'; }
	$html .= '<ul>'
		. rk_builder_contact_row( 'Call', $p['phone'], rk_builder_phone_href( $p['phone'] ) )
		. rk_builder_contact_row( 'Email', $p['email'], rk_builder_email_href( $p['email'] ) )
		. rk_builder_contact_row( 'Visit', $p['address'] )
		. rk_builder_contact_row( 'Hours', $p['hours'] );
	return $html . '</ul></section>';
}
