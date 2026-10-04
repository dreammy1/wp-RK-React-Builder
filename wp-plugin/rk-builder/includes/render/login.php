<?php
/**
 * Sign-in form block. Mirrors client/src/blocks/login/{View,schema}.tsx.
 *
 * Without `rk_live` in the context (editor preview, parity tests) the markup is static. When the page is
 * served to a visitor (`rk_live`, set by the two public entry points) rk_builder_login_make_live() fills in the real form action, the redirect
 * target, an error message, or an "already signed in" panel. See includes/login.php.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_render_login( array $p, array $context = array() ) {
	$split = isset( $p['layout'] ) && 'split' === $p['layout'];
	$card  = '<div class="site-login-card">';
	if ( isset( $p['showBrand'] ) && true === $p['showBrand'] ) { $card .= '<div class="site-login-brand" data-rk-login-brand=""></div>'; }
	$card .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>';
	if ( '' !== $p['intro'] ) { $card .= '<p>' . rk_builder_h( $p['intro'] ) . '</p>'; }
	$card .= '<div class="site-login-msg" role="alert" hidden=""></div>';
	$card .= '<form class="site-login-form" data-rk-login="" method="post">';
	$card .= '<label><span>Email or username</span><input type="text" autoComplete="username" required="" name="log"/></label>';
	$card .= '<label><span>Password</span><input type="password" autoComplete="current-password" required="" name="pwd"/></label>';
	if ( ! isset( $p['remember'] ) || false !== $p['remember'] ) {
		$card .= '<label class="site-login-check"><input type="checkbox" name="rememberme" value="forever"/><span>Remember me</span></label>';
	}
	$card .= '<button type="submit" class="site-login-btn">' . rk_builder_h( $p['button'] ) . '</button></form>';
	if ( ! isset( $p['forgot'] ) || false !== $p['forgot'] ) {
		$card .= '<p class="site-login-links"><a href="#rk-lostpassword">Forgot your password?</a></p>';
	}
	if ( isset( $p['showDetails'] ) && true === $p['showDetails'] ) { $card .= '<div class="site-login-details" data-rk-login-details=""></div>'; }
	$card .= '</div>';
	if ( ! $split ) {
		$html = '<section ' . rk_builder_root_attrs( 'login', 'site-login' ) . '>' . $card . '</section>';
	} else {
		$img  = rk_builder_media_url( $p, 'imageMediaId', 'imageUrl' );
		$side = isset( $p['side'] ) && 'right' === $p['side'] ? ' side-right' : '';
		$html = '<section ' . rk_builder_root_attrs( 'login', 'site-login is-split' . $side ) . '><div class="site-login-media">';
		if ( '' !== $img ) { $html .= '<img class="site-login-img" src="' . $img . '" alt="' . rk_builder_h( isset( $p['imageAlt'] ) ? $p['imageAlt'] : '' ) . '" decoding="async"/>'; }
		$html .= '</div><div class="site-login-main">' . $card . '</div></section>';
	}
	if ( ! empty( $context['rk_live'] ) && function_exists( 'rk_builder_login_make_live' ) ) {
		return rk_builder_login_make_live( $html );
	}
	return $html;
}
