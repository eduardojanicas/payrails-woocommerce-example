/**
 * Payrails integration — step 1 (front end): register the "Card" method with the Checkout block.
 *
 * Registers the Payrails "Card" method in the Checkout block. Plain JS (no build),
 * same pattern as WooCommerce's own cheque method. No fields and no payment data:
 * Place order calls the Store API → process_payment() → redirect to the pay page.
 */
( function () {
	'use strict';
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settings || ! window.wp || ! window.wp.element ) {
		return;
	}
	var h = window.wp.element.createElement;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( s ) { return s; };
	var data = settings.getPaymentMethodData( 'payrails', {} ) || {};
	var title = decode( data.title || 'Card' );

	function Content() {
		return h(
			'p',
			{ className: 'payrails-block-desc' },
			decode( data.description || '' )
		);
	}
	function Label( props ) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;
		return h( PaymentMethodLabel, { text: title } );
	}

	registry.registerPaymentMethod( {
		name: 'payrails',
		label: h( Label ),
		content: h( Content ),
		edit: h( Content ),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		placeOrderButtonLabel: decode( data.buttonLabel || 'Continue to payment' ),
		supports: { features: data.supports || [ 'products' ] }
	} );
} )();
