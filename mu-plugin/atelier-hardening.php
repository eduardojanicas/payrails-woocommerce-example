<?php
/**
 * Plugin Name: Atelier local hardening
 * Description: Basic hardening for the local example store: XML-RPC off and no user enumeration for anonymous visitors. Symlinked into wp-content/mu-plugins by scripts/setup.sh.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

// XML-RPC off (brute force via system.multicall). router.php also answers 403 for /xmlrpc.php.
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

// No user enumeration for anonymous visitors: hide the REST users endpoints...
add_filter(
	'rest_endpoints',
	static function ( $endpoints ) {
		if ( ! is_user_logged_in() ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}
);

// ...and the ?author=N → /author/{login}/ redirect.
add_action(
	'template_redirect',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
		if ( ! is_user_logged_in() && ( isset( $_GET['author'] ) || is_author() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	},
	1
);
