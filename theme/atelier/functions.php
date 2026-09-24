<?php
/**
 * Atelier child theme (Twenty Twenty-Five).
 *
 * Kept deliberately minimal: fonts, palette and element styles live in
 * theme.json. This file only enqueues the child stylesheet and the
 * pay-for-order stylesheet.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Child stylesheet: Woo block styling, product cards, header, footer.
 * The parent theme enqueues its own style.css itself.
 */
function atelier_enqueue_styles() {
	$theme = wp_get_theme();

	wp_enqueue_style(
		'atelier-style',
		get_stylesheet_uri(),
		array( 'twentytwentyfive-style' ),
		$theme->get( 'Version' )
	);

	// Pay-for-order page (where the Payrails Drop-in mounts). Scoped CSS for the pay panel.
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
		wp_enqueue_style(
			'atelier-pay',
			get_theme_file_uri( 'assets/pay.css' ),
			array( 'atelier-style' ),
			$theme->get( 'Version' )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'atelier_enqueue_styles', 20 );

/**
 * Load the child stylesheet in the Site Editor too, so blocks preview correctly.
 */
function atelier_editor_styles() {
	add_editor_style( 'style.css' );
}
add_action( 'after_setup_theme', 'atelier_editor_styles' );
