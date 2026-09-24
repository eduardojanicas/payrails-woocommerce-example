<?php
/**
 * Seeds the Atelier store. Run through `wp eval-file scripts/seed.php <products.json>`
 * (see seed.sh). Idempotent: products are keyed by SKU, terms by slug, images by
 * source file name, so every run converges on the same state.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- $args is provided by wp eval-file.
$json_file = $args[0] ?? '';
if ( ! is_readable( $json_file ) ) {
	WP_CLI::error( 'Usage: wp eval-file seed.php <products.json>' );
}
$data = json_decode( (string) file_get_contents( $json_file ), true );
if ( ! is_array( $data ) || empty( $data['products'] ) ) {
	WP_CLI::error( 'products.json is not valid' );
}
$image_dir = dirname( $json_file );

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Term id for a slug, creating the term when missing.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $name     Name.
 * @param string $slug     Slug.
 */
function atelier_seed_term( string $taxonomy, string $name, string $slug ): int {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term ) {
		if ( $term->name !== $name ) {
			wp_update_term( $term->term_id, $taxonomy, array( 'name' => $name ) );
		}
		return (int) $term->term_id;
	}
	$res = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $res ) ) {
		WP_CLI::error( "Could not create $taxonomy $slug: " . $res->get_error_message() );
	}
	WP_CLI::log( "  + $taxonomy $slug" );
	return (int) $res['term_id'];
}

/**
 * Attachment id for a bundled image, importing it once (keyed by source file name).
 *
 * @param string $path Absolute path.
 * @param string $alt  Alt text.
 * @param string $title Title.
 */
function atelier_seed_image( string $path, string $alt, string $title ): int {
	$source = basename( $path );
	$found  = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_atelier_source_image', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $found ) {
		$id = (int) $found[0];
	} else {
		if ( ! is_readable( $path ) ) {
			WP_CLI::warning( "Image missing: $path" );
			return 0;
		}
		$tmp = wp_tempnam( $source );
		copy( $path, $tmp );
		$id = media_handle_sideload(
			array(
				'name'     => $source,
				'tmp_name' => $tmp,
			),
			0,
			$title
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( "Image import failed for $source: " . $id->get_error_message() );
			return 0;
		}
		update_post_meta( $id, '_atelier_source_image', $source );
		WP_CLI::log( "  + image $source" );
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return (int) $id;
}

// 1. Terms ---------------------------------------------------------------------------
WP_CLI::log( '==> Categories and tags' );
$cat_ids = array();
foreach ( $data['categories'] as $c ) {
	$cat_ids[ $c['slug'] ] = atelier_seed_term( 'product_cat', $c['name'], $c['slug'] );
}
$tag_ids = array();
foreach ( $data['tags'] as $t ) {
	$tag_ids[ $t['slug'] ] = atelier_seed_term( 'product_tag', $t['name'], $t['slug'] );
}

// 2. Products (idempotent by SKU) ----------------------------------------------------
WP_CLI::log( '==> Products' );
foreach ( $data['products'] as $p ) {
	$id      = wc_get_product_id_by_sku( $p['sku'] );
	$product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
	$verb    = $id ? 'updated' : 'created';

	$product->set_name( $p['name'] );
	$product->set_slug( $p['slug'] );
	if ( ! $id ) {
		$product->set_sku( $p['sku'] );
	}
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( $p['price'] );
	$product->set_sale_price( '' );
	$product->set_description( $p['description'] );
	$product->set_short_description( $p['short_description'] );
	$product->set_menu_order( (int) $p['menu_order'] );
	$product->set_manage_stock( false ); // No hold-stock auto-cancel of pending orders.
	$product->set_stock_status( 'instock' );
	$product->set_virtual( false );
	$product->set_category_ids( array( $cat_ids[ $p['category_slug'] ] ) );
	$product->set_tag_ids(
		array_values(
			array_filter(
				array_map(
					static function ( $slug ) use ( $tag_ids ) {
						return $tag_ids[ $slug ] ?? 0;
					},
					$p['tags']
				)
			)
		)
	);
	$product->update_meta_data( '_atelier_color', $p['color'] );
	$product->update_meta_data( '_atelier_material', $p['material'] );

	// Visible attributes so the product "Additional information" tab has content.
	$attrs = array();
	foreach ( array( 'Colour' => $p['color'], 'Material' => $p['material'] ) as $label => $value ) {
		$a = new WC_Product_Attribute();
		$a->set_name( $label );
		$a->set_options( array( $value ) );
		$a->set_visible( true );
		$a->set_variation( false );
		$attrs[] = $a;
	}
	$product->set_attributes( $attrs );

	$pid = $product->save();
	$att = atelier_seed_image( $image_dir . '/' . $p['image'], $p['image_alt'], $p['name'] );
	if ( $att && (int) $product->get_image_id() !== $att ) {
		$product->set_image_id( $att );
		$product->save();
		wp_update_post(
			array(
				'ID'          => $att,
				'post_parent' => $pid,
			)
		);
	}
	WP_CLI::log( sprintf( '  %s %s (%s) #%d $%s', $verb, $p['name'], $p['sku'], $pid, $p['price'] ) );
}

// 3. Site + store options ------------------------------------------------------------
WP_CLI::log( '==> Options' );
update_option( 'blogname', 'ATELIER' );
update_option( 'blogdescription', 'Considered essentials' );
update_option( 'woocommerce_currency', $data['currency'] ?? 'USD' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'woocommerce_permalinks', array_merge( (array) get_option( 'woocommerce_permalinks', array() ), array( 'product_base' => '/product' ) ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );

// Free shipping everywhere, so the pay page shows "Complimentary" and totals stay simple.
$zones = WC_Shipping_Zones::get_zones();
$has   = false;
foreach ( $zones as $z ) {
	if ( 'Everywhere' === $z['zone_name'] ) {
		$has = true;
	}
}
if ( ! $has ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'Everywhere' );
	$zone->set_zone_order( 0 );
	$zone->save();
	$zone->add_shipping_method( 'free_shipping' );
	WP_CLI::log( '  + shipping zone Everywhere (free shipping)' );
}
// Also cover "locations not covered by your other zones".
$rest = new WC_Shipping_Zone( 0 );
if ( ! $rest->get_shipping_methods() ) {
	$rest->add_shipping_method( 'free_shipping' );
}

// 4. Gateway -------------------------------------------------------------------------
WP_CLI::log( '==> Payrails gateway' );
$settings = get_option( 'woocommerce_payrails_settings', array() );
$settings = array_merge(
	is_array( $settings ) ? $settings : array(),
	array(
		'enabled'     => 'yes',
		'title'       => 'Card',
		'description' => 'Pay securely by card on the next step. 3D Secure supported. Powered by Payrails.',
		'debug'       => 'no',
	)
);
update_option( 'woocommerce_payrails_settings', $settings );
// Payrails first in the method list.
$order = (array) get_option( 'woocommerce_gateway_order', array() );
$order = array( 'payrails' => 0 ) + $order;
update_option( 'woocommerce_gateway_order', $order );

// 5. Checkout block: Place order label ------------------------------------------------
$checkout_id = wc_get_page_id( 'checkout' );
if ( $checkout_id > 0 ) {
	$post    = get_post( $checkout_id );
	$content = (string) $post->post_content;
	$label   = 'Continue to payment';
	$updated = preg_replace_callback(
		'#<!-- wp:woocommerce/checkout-actions-block(\s+(\{.*?\}))?\s*(/?)-->#s',
		static function ( $m ) use ( $label ) {
			$attrs                          = isset( $m[2] ) && '' !== $m[2] ? (array) json_decode( $m[2], true ) : array();
			$attrs['placeOrderButtonLabel'] = $label;
			return '<!-- wp:woocommerce/checkout-actions-block ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' ' . ( $m[3] ?? '' ) . '-->';
		},
		$content,
		1,
		$count
	);
	if ( $count && $updated !== $content ) {
		wp_update_post(
			array(
				'ID'           => $checkout_id,
				'post_content' => wp_slash( $updated ),
			)
		);
		WP_CLI::log( '  checkout block: placeOrderButtonLabel set' );
	} elseif ( ! $count ) {
		WP_CLI::warning( 'Checkout page has no checkout-actions-block; Place order label not set on the block (the payment method still sets it).' );
	}
}

WP_CLI::success( sprintf( 'Seeded %d products.', count( $data['products'] ) ) );
