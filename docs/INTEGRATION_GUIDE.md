# Integration guide

This guide follows one shopper from the Checkout block to a paid order and shows, at each step, what the plugin does, why, and where the code is. The steps match the `Payrails integration — step N` markers in the source, so you can find them with `grep -rn "Payrails integration — step" plugin/`.

All paths are relative to the repository root. The plugin lives in [`plugin/payrails-woo/`](../plugin/payrails-woo/).

| Step | What happens | Main files |
|---|---|---|
| 1 | The gateway and the Checkout-block method are registered | `Woo/Plugin.php`, `Woo/Gateway.php`, `Woo/BlocksIntegration.php`, `assets/js/blocks.js` |
| 2 | Place order creates a pending order and redirects to the pay-for-order page | `Woo/Gateway.php` |
| 3 | The server authenticates to Payrails (token + mTLS) | `Core/Api/PayrailsClient.php`, `Core/Http/CurlTransport.php` |
| 4 | The server runs client-init for this order | `Woo/ExecutionSession.php`, `Core/ClientInitBuilder.php`, `Core/Api/PayrailsClient.php` |
| 5 | The browser mounts the Drop-in and listens for the outcome | `assets/js/pay.js`, `Woo/PayPage.php`, `theme/atelier/assets/payrails-appearance.json` |
| 6 | The server confirms: reads the execution, verifies it, completes the order under a lock | `Woo/ConfirmController.php`, `Woo/ExecutionSession.php`, `Core/ExecutionVerifier.php`, `Core/ExecutionMapper.php`, `Woo/OrderLock.php` |
| 7 | 3D Secure and retry after a decline | `assets/js/pay.js`, `Woo/ExecutionSession.php`, `Core/ExecutionMapper.php` |

Two terms come up throughout:

- **Execution.** Payrails' record of one payment flow for one order attempt, running in a *workflow* (identified by a workflow code). Client-init creates it. Its `status[]` history (`created`, `authorizeRequested`, `authorizePending`, `authorizeSuccessful`, `authorizeFailed`, ...) is the source of truth for the outcome. The execution id becomes the WooCommerce transaction id.
- **Client-init.** A server-to-server call that creates an execution and returns a short-lived session payload for the browser SDK. The payload is scoped to that one execution. It is not a merchant secret, so it can be sent to the browser.

The plugin splits into two layers:

- `includes/Core/` is plain PHP with no WordPress calls. It holds the API client, request builder, mapper and verifier, and is unit-tested on its own.
- `includes/Woo/` is the WooCommerce glue.

---

## Step 1: Register the gateway and the Checkout-block method

**What happens.** On `plugins_loaded`, the plugin adds its `WC_Payment_Gateway` subclass (id `payrails`) to WooCommerce. It also registers a payment method type with the Checkout block's registry. The block side is a few lines of plain JavaScript with no build step. It shows a "Card" option with a description and no fields.

**Why this way.** Card data never touches the checkout page. The Checkout block only needs to know that the method exists and which label to put on the button ("Continue to payment"). The card form comes later, on WooCommerce's pay-for-order page. So the block integration stays trivial, and the same gateway class serves the classic checkout.

The gateway hides itself (`is_available()`) when the configuration is incomplete or the store currency isn't supported (the example accepts `USD` only, see `Gateway::CURRENCIES`). In that case an admin notice names the missing keys.

**Files.** [`includes/Woo/Plugin.php`](../plugin/payrails-woo/includes/Woo/Plugin.php), [`includes/Woo/Gateway.php`](../plugin/payrails-woo/includes/Woo/Gateway.php), [`includes/Woo/BlocksIntegration.php`](../plugin/payrails-woo/includes/Woo/BlocksIntegration.php), [`assets/js/blocks.js`](../plugin/payrails-woo/assets/js/blocks.js)

```php
// includes/Woo/Plugin.php
// Payrails integration — step 1: register the gateway (classic) and the Checkout-block payment method.
add_filter(
	'woocommerce_payment_gateways',
	static function ( $gateways ) {
		$gateways[] = Gateway::class;
		return $gateways;
	}
);
add_action(
	'woocommerce_blocks_payment_method_type_registration',
	static function ( PaymentMethodRegistry $registry ) {
		$registry->register( new BlocksIntegration() );
	}
);
```

```js
// assets/js/blocks.js
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
```

`Plugin.php` also registers the pay page's hooks:

- `woocommerce_receipt_payrails` renders the pay panel;
- `template_redirect` runs a precheck (see step 6);
- `wc_ajax_payrails_confirm` is the confirm endpoint.

---

## Step 2: Place order, then the pay-for-order page

**What happens.** When the shopper clicks **Continue to payment**, the Checkout block calls the Store API. WooCommerce creates the order and calls the gateway's `process_payment()`. The gateway leaves the order *pending* and returns the order's pay URL (`/checkout/order-pay/{id}/?key=wc_order_...`). The browser goes there.

**Why this way.**

- **A fixed amount.** By the time Payrails is involved, the order exists with a fixed number, amount and currency. Client-init needs exactly those, and the confirm step checks them again later.
- **Retries on the same order.** The pay-for-order page is standard WooCommerce. It works for guests (the order key in the URL is the authority), and it keeps working after a failed attempt, because `needs_payment()` is true for `failed` orders too.
- **One path for both checkouts.** The classic and the block checkout both end up here.

`get_checkout_payment_url( true )` returns the *receipt* variant of the pay page, which fires `woocommerce_receipt_payrails` instead of WooCommerce's own "pay for order" form.

**File.** [`includes/Woo/Gateway.php`](../plugin/payrails-woo/includes/Woo/Gateway.php) (`process_payment()`)

```php
// includes/Woo/Gateway.php
if ( $order->has_status( 'pending' ) ) {
	$order->add_order_note( __( 'Awaiting Payrails payment.', 'payrails-woo' ) );
} else {
	$order->update_status( 'pending', __( 'Awaiting Payrails payment.', 'payrails-woo' ) );
}
$order->save();
return array(
	'result'   => 'success',
	'redirect' => $order->get_checkout_payment_url( true ),
);
```

---

## Step 3: Server authentication (token + mTLS)

**What happens.** Every merchant-API call is made from PHP, never from the browser. The client:

1. gets a bearer token from `POST /auth/token/{clientId}`, sending the client secret in the `x-api-key` header;
2. caches the token until 60 seconds before it expires;
3. sends every call over mutual TLS, presenting the client certificate and key that Payrails issued.

On a `401` the client refreshes the token and retries once.

**Why this way.**

- **Keys stay on the server.** The client secret and the mTLS key never leave it.
- **Two factors per call.** A leaked bearer token isn't enough on its own, because every call also needs the client certificate.
- **Raw cURL.** The transport uses cURL directly, because the WordPress HTTP API has no first-class option for client certificates. PHP never reads the PEM contents: cURL gets the file paths.
- **TLS checks stay on.** Verification is on, and only HTTPS is allowed.
- **Payrails hosts only.** The configuration refuses any `PAYRAILS_API_URL` that isn't an `https://*.payrails.io` URL, because the secret is sent to that host.

**Files.** [`includes/Core/Api/PayrailsClient.php`](../plugin/payrails-woo/includes/Core/Api/PayrailsClient.php) (`token()`, `authed()`), [`includes/Core/Http/CurlTransport.php`](../plugin/payrails-woo/includes/Core/Http/CurlTransport.php), [`includes/Core/Config.php`](../plugin/payrails-woo/includes/Core/Config.php)

```php
// includes/Core/Api/PayrailsClient.php, token()
$res = $this->transport->send(
	new HttpRequest(
		'POST',
		$this->config->api_url . '/auth/token/' . rawurlencode( $this->config->client_id ),
		array(
			'Accept'    => 'application/json',
			'x-api-key' => $this->config->client_secret(),
		)
	)
);
```

```php
// includes/Core/Http/CurlTransport.php, send()
CURLOPT_SSLCERT        => $this->cert_path,
CURLOPT_SSLCERTTYPE    => 'PEM',
CURLOPT_SSLKEY         => $this->key_path,
CURLOPT_SSL_VERIFYPEER => true,
CURLOPT_SSL_VERIFYHOST => 2,
CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
```

---

## Step 4: Client-init, once per order

**What happens.** When the pay page renders, `ExecutionSession::prepare()` decides what to show.

- If the order already has an execution, it reads it first. The order might already be paid, failed or in flight.
- If a cached client-init response for this order is still usable, it reuses it.
- Otherwise it:
  1. builds a client-init body from a snapshot of the order;
  2. calls `POST /merchant/client/init` with an idempotency key;
  3. decodes the response to find the execution id;
  4. stores that id on the order;
  5. caches the response for 15 minutes.

The response (`{version, data}`) is printed into the page, unchanged, as a JSON data island for the SDK.

**Why per order, on the pay page.** Client-init creates an execution tied to one amount, currency and reference. So it runs after the order exists (step 2) and only for that order. Running it when the pay page renders, rather than at checkout, means a reload doesn't create a second execution: it reuses the cached response. The cache is dropped whenever:

- the amount, currency or workflow changes (the fingerprint differs);
- the current execution failed;
- the SDK reports that its session expired (step 5).

### The request body

| Field | Value | Why |
|---|---|---|
| `type` | `dropIn` | The page mounts the Drop-in |
| `workflowCode` | from configuration (default `payment-acceptance`) | Selects the Payrails workflow, which must have cards enabled |
| `workspaceId` | from configuration | Required for this setup: a workspace-scoped client gets `401` from client-init without it |
| `amount` | order total, as a decimal string, plus currency | Converted from minor units, so no floating-point rounding |
| `merchantReference` | the WooCommerce order number | Lets the server check later that an execution belongs to this order. Also what you search for in the Payrails dashboard |
| `holderReference` | a random `wc-customer-{32 hex}` per WordPress user, or a random `wc-guest-{32 hex}` per guest order | Identifies the payer in Payrails, which is what stored cards hang off (see below) |
| `meta.CIT` | `true` | Marks the payment as customer-initiated: the shopper is present and can authenticate (3DS). The opposite would be a merchant-initiated charge, such as a subscription renewal |
| `meta.customer` | reference (the holder reference), e-mail, name, billing country | Customer context for the workflow and the processor |
| `meta.order.reference` | the order number | Shown alongside the execution in Payrails |
| `meta.order.description` | `Order #{number}` by default | A human-readable description. Change it with the `payrails_woo_order_description` filter |
| `meta.order.lines` | line items, shipping and fees, including tax | Sent only when the lines sum exactly to `amount`, because a mismatch rejects the whole request. Uneven splits (for example a fixed-cart coupon) are folded into a single line of quantity 1. If the sum still can't match, the lines are left out and a warning is logged |
| `meta.clientContext` | `ipAddress` (`REMOTE_ADDR` only), `origin` (from `home_url()`, never from the request), `osType` (`web`), `userAgent` (up to 255 characters), `returnUrl` (this order's pay page) | Browser context for risk checks and 3DS. `returnUrl` is where the shopper comes back after a full-page redirect. `origin` must be a host name that Payrails has allowed for your client |

**Use a host name, not an IP address.** The origin and return URL must be host names such as `localhost`: Payrails answers `403` to IP-literal URLs. If one slips through, the builder leaves that field out rather than fail.

**Holder references.** Both kinds are random, and neither is derived from personal data or internal ids:

- **Logged-in customers** get one reference per WordPress user, kept in user meta. That way the holder, and therefore any stored cards, stays stable across the customer's orders, without exposing the internal user id. A unique `add_user_meta()` makes sure two concurrent first orders can't create two holders.
- **Guests** get a new reference per order, kept in order meta. It is never derived from the e-mail: a guest's e-mail is unverified, and an e-mail-derived holder would let anyone who knows someone's address become the same Payrails holder and see their stored cards. So a returning guest is a new holder with no stored cards.

### A sample request and response

This is the body `ClientInitBuilder::build()` produces for a one-item guest order, with placeholder ids:

```json
{
    "type": "dropIn",
    "holderReference": "wc-guest-<32 hex characters>",
    "merchantReference": "26",
    "workflowCode": "payment-acceptance",
    "amount": { "value": "185.00", "currency": "USD" },
    "workspaceId": "<your-workspace-id>",
    "meta": {
        "CIT": true,
        "customer": {
            "reference": "wc-guest-<32 hex characters>",
            "email": "jane.demo@example.com",
            "name": "Jane Demo",
            "country": { "code": "US" }
        },
        "order": {
            "reference": "26",
            "description": "Order #26",
            "lines": [
                {
                    "id": "item-12",
                    "name": "Merino Wool Sweater",
                    "quantity": 1,
                    "unitPrice": { "value": "185.00", "currency": "USD" }
                }
            ]
        },
        "clientContext": {
            "ipAddress": "127.0.0.1",
            "origin": "http://localhost:8080",
            "osType": "web",
            "userAgent": "Mozilla/5.0 (...)",
            "returnUrl": "http://localhost:8080/checkout/order-pay/26/?key=wc_order_<key>"
        }
    }
}
```

The response is `{"version": "...", "data": "<base64>"}`. The plugin passes it to the browser unchanged. Decoded, `data` looks like this (abridged; the full shape, with placeholder values, is in [`tests/php/fixtures/client-init-response.json`](../tests/php/fixtures/client-init-response.json)):

```json
{
    "token": "<token>",
    "holderReference": "<holderReference>",
    "dataEncryptionId": "<dataEncryptionId>",
    "vaultConfiguration": { "...": "..." },
    "amount": { "value": "45.00", "currency": "USD" },
    "execution": {
        "id": "<id>",
        "status": [ { "code": "created", "time": "2026-01-01T00:00:00.000000000Z" } ],
        "merchantReference": "<merchantReference>",
        "holderReference": "<holderReference>",
        "amount": { "value": "45.00", "currency": "USD" },
        "workflow": { "code": "payment-acceptance", "version": 56 },
        "meta": { "CIT": true, "clientContext": { "...": "..." }, "customer": { "...": "..." }, "order": { "...": "..." } },
        "links": { "self": "https://<self>", "authorize": { "...": "..." }, "startPaymentSession": { "...": "..." } },
        "initialResults": [ { "...": "payment options returned by the workflow" } ]
    },
    "links": { "...": "..." },
    "type": "dropIn",
    "featureConfig": { "sdkVersion": "<sdkVersion>", "...": "..." }
}
```

The server only needs `execution.id` from it (`PayrailsClient::extract_execution_id()`). Everything else is for the SDK. See also the Payrails reference for [client-init](https://docs.payrails.com/reference/clientinit).

### The idempotency key

Payrails returns the same execution for the same `x-idempotency-key`, and it does so **even if the body is different**. So the key has to change whenever a new client-init is wanted, and stay the same otherwise.

The plugin derives the key deterministically, as a UUIDv5 of a seed made of:

- a random per-install id, so that a reset database reusing order ids never collides with old keys;
- the order id;
- a fingerprint of amount, currency and workflow;
- an **attempt number**.

The attempt number goes up with **every new client-init**, that is, whenever there is no usable cached session. Those are the cases listed above: a failed execution, a changed amount, an expired cache, or a session the SDK reported as expired. Reusing the old key after the cache expired would make Payrails replay the original response, including its SDK session token, which may itself have expired by then.

The effects:

- Within one attempt, the key is stable. Concurrent page loads share one execution, and a reload within the cache lifetime makes no call at all.
- Across attempts, the key differs, so a retry or a changed total gets a new execution.

**The 401 retry.** If client-init gets a `401` (an expired token), the client refreshes the token and retries once with its own deterministic key, the seed plus `-r1`. A `401` is rejected at authentication, before the request is processed, so no execution exists for the first key. Using a separate key also avoids an idempotency layer answering the retry with the cached `401`.

```php
// includes/Woo/ExecutionSession.php, prepare()
$seed = 'wc-' . Services::install_id() . '-' . $order->get_id() . '-' . $fp . '-' . $attempt;
try {
	// Payrails integration — step 4 (per order): one execution per order and
	// attempt; the deterministic seed makes a double page load reuse it.
	$ci = $client->client_init( $body, $seed );
```

### The execution id and the allow-list

The execution id is at `data.execution.id`. The plugin stores it twice, in order meta:

- as the *current* execution (`_payrails_execution_id`);
- in a list of every execution it ever created for this order (`_payrails_execution_ids`).

That list is the allow-list the confirm endpoint checks in step 6. A browser can only ever ask about an execution that this server created for this order.

**Files.** [`includes/Woo/ExecutionSession.php`](../plugin/payrails-woo/includes/Woo/ExecutionSession.php) (`prepare()`), [`includes/Core/ClientInitBuilder.php`](../plugin/payrails-woo/includes/Core/ClientInitBuilder.php), [`includes/Core/Api/PayrailsClient.php`](../plugin/payrails-woo/includes/Core/Api/PayrailsClient.php) (`client_init()`, `idempotency_key()`), [`includes/Woo/SnapshotFactory.php`](../plugin/payrails-woo/includes/Woo/SnapshotFactory.php), [`includes/Core/HolderReference.php`](../plugin/payrails-woo/includes/Core/HolderReference.php)

```php
// includes/Core/ClientInitBuilder.php, build()
$body = array(
	'type'              => 'dropIn',
	'holderReference'   => $s->holder_reference,
	'merchantReference' => $s->order_number,
	'workflowCode'      => $workflow_code,
	'amount'            => $money( $s->amount_minor ),
);
if ( null !== $workspace_id && '' !== $workspace_id ) {
	$body['workspaceId'] = $workspace_id;
}
```

---

## Step 5: Mount the Drop-in and handle the outcome

**What happens.** `PayPage` prints the pay panel into the page:

- a `<section id="woo-payrails-pay">`;
- an empty `#woo-payrails-dropin` container;
- the client-init response, in `<script type="application/json" id="woo-payrails-client-init">`.

It also passes `pay.js` a small configuration object: the confirm URL, a nonce, the order id and key, the SDK loader URL, the Drop-in appearance and the UI copy.

`pay.js` then:

1. imports the SDK loader (vendored in the plugin, see [ARCHITECTURE.md](ARCHITECTURE.md#sdk-loading));
2. calls `Payrails.init()` with the client-init response;
3. subscribes to the Drop-in events;
4. mounts the Drop-in into `#woo-payrails-dropin`.

The card fields render in a Payrails-hosted iframe, so card data goes from the shopper's browser straight to Payrails.

| SDK event | What the page does |
|---|---|
| `requestStart` | Shows "Confirming your payment" |
| `actionRequired` | Shows "Verify with your bank" while the 3DS challenge is on screen |
| `success` | Calls the confirm endpoint (step 6) |
| `pending` | Starts polling the confirm endpoint |
| `failed` | Calls the confirm endpoint too: the server decides |
| `sessionExpired` | Reloads the page with `?payrails_reinit=1`, which drops the cached client-init so the server starts a fresh session |

The event only picks which request to send and what to show while it runs. None of the events marks the order paid.

**Session expiry.** On `sessionExpired`, `pay.js` records the time in `sessionStorage` and reloads with `?payrails_reinit=1`. The server drops the order's cached client-init, and the render starts a new attempt (a new execution) with a fresh session. If a second expiry happens within a minute, the page shows "This payment session has expired" (`PR-SESSION`) instead of reloading again, so it can't loop. After the reload, the flag is removed from the address bar.

```js
// assets/js/pay.js, reinit()
if ( last && Date.now() - last < 60000 ) {
	return ui( 'error', { code: 'PR-SESSION', copy: T.expired } );
}
var url = new URL( window.location.href );
url.searchParams.set( 'payrails_reinit', '1' );
window.location.assign( url.toString() );
```

**Guests.** For guest orders the Drop-in is configured with no stored cards and no "save card" checkbox (`showStoredInstruments`, `showStoreInstrumentCheckbox` and `alwaysStoreInstrument` are all false).

**Theming.** The pay panel has three layers of styling:

- **The plugin's baseline stylesheet**, [`assets/css/payrails-woo.css`](../plugin/payrails-woo/assets/css/payrails-woo.css), lays out the panel and its states with neutral defaults. It reads `--woo-payrails-*` custom properties for fonts, colors and radii.
- **The theme's brand tokens.** The Atelier theme's [`assets/pay.css`](../theme/atelier/assets/pay.css) only sets tokens: the `--woo-payrails-*` tokens and the Drop-in's own `--payrails-*` tokens (colors, font, radii, control heights), on `.woo-payrails-pay`.
- **The card-field appearance.** The iframe card fields are styled by the appearance JSON in the active theme's [`assets/payrails-appearance.json`](../theme/atelier/assets/payrails-appearance.json), which `pay.js` passes to the Drop-in.

**Theming tip: the prefix.** The plugin's classes and ids all use the `woo-payrails-` prefix. The Drop-in's stylesheet resets any element whose class or id starts with `payrails-`, so leave that prefix to the SDK's own markup. If you add your own wrappers, don't name them `payrails-...`.

**Cards only.** A workflow can also return wallets such as Google Pay and Apple Pay. The example is cards-only by default (filter `payrails_woo_cards_only`, default `true`): it hides wallet rows inside the Drop-in and opens the Card method automatically. This relies on the Drop-in's element ids, so it's kept to that one switch. If your Payrails workflow only enables cards, return `false` from the filter, and nothing depends on Drop-in internals.

**Files.** [`assets/js/pay.js`](../plugin/payrails-woo/assets/js/pay.js) (`mountLive()`, `reinit()`), [`includes/Woo/PayPage.php`](../plugin/payrails-woo/includes/Woo/PayPage.php), [`assets/css/payrails-woo.css`](../plugin/payrails-woo/assets/css/payrails-woo.css), [`theme/atelier/assets/pay.css`](../theme/atelier/assets/pay.css), [`theme/atelier/assets/payrails-appearance.json`](../theme/atelier/assets/payrails-appearance.json)

```js
// assets/js/pay.js, mountLive()
return import( C.sdkUrl ).then( function ( mod ) {
	var Payrails = mod.Payrails;
	return Payrails.init( clientInit, {
		events: {
			onClientInitialized: function () {
				if ( 'loading' === root.getAttribute( 'data-state' ) ) {
					ui( 'ready' );
				}
			}
		},
		returnInfo: { success: C.returnUrl, error: C.returnUrl, cancel: C.returnUrl, pending: C.returnUrl }
	} ).then( function ( payrails ) {
```

```js
// assets/js/pay.js, mountLive() (continued)
payrails.on( 'success', function ( e ) {
	confirm( e && e.executionId, 'success' );
} );
payrails.on( 'pending', function ( e ) {
	poll( ( e && e.executionId ) || C.executionId );
} );
payrails.on( 'failed', function ( e ) {
	window.console && console.warn( '[payrails] failed', e && e.data && e.data.code );
	confirm( ( e && e.executionId ) || C.executionId, 'failed' );
} );
payrails.on( 'sessionExpired', reinit );
```

Every `returnInfo` URL is the pay page itself. After a full-page redirect (for example a bank verification), the shopper lands back on the pay page, and the server resolves the payment there (step 6, precheck).

For the SDK side in general, see the Payrails [Web SDK quick start](https://docs.payrails.com/docs/web-sdk-quick-start) and [Drop-in](https://docs.payrails.com/docs/drop-in) documentation.

---

## Step 6: Confirm on the server

This step is the core of the integration. The order becomes paid only here, and only because of what the server reads from Payrails.

### What the browser sends

`pay.js` makes a single `POST` to the WooCommerce AJAX endpoint `?wc-ajax=payrails_confirm` with four fields: order id, order key, execution id and nonce. It sends **ids only**. Any extra field (such as a claimed `state=authorized`) is ignored.

The endpoint checks, in this order:

1. The request is a `POST`, otherwise `405`.
2. The nonce is valid for this order id, otherwise `403 stale_session`.
3. The order exists and the order key matches, compared with `hash_equals`. Otherwise `404`, with the same body in both cases.
4. The order was placed with this gateway, otherwise `400`.
5. The execution id is on the order's allow-list, otherwise `400 unknown_execution`. A malformed id falls back to the order's current execution.
6. An order already paid by this very execution returns `authorized` without another read, so replays are idempotent.

```php
// includes/Woo/ConfirmController.php, handle()
if ( ! check_ajax_referer( 'payrails_confirm_' . $order_id, 'nonce', false ) ) {
	self::send( 403, self::err( 'stale_session', __( 'Please reload the page.', 'payrails-woo' ) ) );
}
$order = $order_id ? wc_get_order( $order_id ) : false;
if ( ! $order instanceof \WC_Order || ! is_string( $key ) || ! hash_equals( $order->get_order_key(), $key ) ) {
	self::send( 404, self::err( 'not_found' ) );
}
if ( 'payrails' !== $order->get_payment_method() ) {
	self::send( 400, self::err( 'wrong_gateway' ) );
}
$exec = $exec ?? ExecutionSession::current_id( $order );
if ( null === $exec || ! ExecutionVerifier::is_known( $exec, ExecutionSession::known_ids( $order ) ) ) {
	self::send( 400, self::err( 'unknown_execution' ) );
}
```

### Why the server re-reads the execution

Anything the browser reports can be forged: an event payload, a query parameter, a replayed request. It can also simply be out of date. So the server fetches the execution itself, over the authenticated mTLS channel, with [`GET /merchant/workflows/{workflowCode}/executions/{id}`](https://docs.payrails.com/reference/getexecution), and decides from that alone.

### How an execution maps to paid, failed or pending

`ExecutionMapper` works in four steps:

1. **Sort by timestamp.** It sorts `status[]` by each entry's timestamp, to nanosecond precision. It doesn't rely on array order.
2. **Find the latest attempt.** That's the entries since the last `authorizeRequested`. A retry after a decline starts a new attempt.
3. **Let the latest terminal code decide.** It walks that attempt in time order, and the latest terminal code gives the result:

   | Latest terminal code in the attempt | Mapped state |
   |---|---|
   | Exactly `authorizeSuccessful` or `captureSuccessful` (an anchored match, so a code such as `preAuthorizeSuccessful` never counts) | **authorized** |
   | A failure, cancel, void, reversal, refund or expiry code: `...Failed`, `...Declined`, `...Cancelled`, `...Rejected`, `...Expired`, `...Voided`, `...Reversed`, `...Refunded`, or `void/cancel/reversal/refund...Successful` | **failed**, so a success that was later cancelled or voided is *not* paid |
   | None yet | **pending** |

4. **Treat codes it doesn't recognize conservatively.** Known in-progress codes (`created`, `authorizeRequested`, `authorizePending`, `captureRequested`, `capturePending`) don't change a terminal outcome. That matters because a decline can be followed by a trailing `authorizePending`, and a success by a capture request. Any **unknown** code after the latest terminal one makes the result **pending**, never paid. An event the integration doesn't recognize can delay an order, but it can't mark one paid.

The Payrails [status codes reference](https://docs.payrails.com/docs/workflow-studio-status-codes) lists the codes a workflow can report.

### Amount, currency and reference verification

Before any authorized execution completes the order, `ExecutionVerifier` checks it against the order *as it is now*:

- the execution id is on the allow-list, and the execution Payrails returned has that same id;
- `merchantReference` equals the order number;
- the currency equals the order currency;
- the amount, converted to minor units, equals the current order total.

```php
// includes/Core/ExecutionVerifier.php, verify()
if ( null === $r->id || $r->id !== $requested_id ) {
	return 'id_mismatch';
}
if ( null === $r->merchant_reference || $r->merchant_reference !== $s->order_number ) {
	return 'reference_mismatch';
}
if ( null === $r->amount_currency || strtoupper( $r->amount_currency ) !== $s->currency ) {
	return 'currency_mismatch';
}
```

`ConfirmPolicy` turns the mapped state plus the verification result into a decision:

| Mapped state | Verified? | Order becomes | Browser sees |
|---|---|---|---|
| authorized | yes | *Processing*, via `payment_complete( $execution_id )`. The execution id is the transaction id | `authorized` + redirect to order-received |
| authorized | no | *On hold*, with a `PR-VERIFY` note listing expected and actual reference, amount and currency. Never paid | `review` ("We couldn't verify this payment... Do not pay again.") |
| failed | - | *Failed* (still payable). Each declined execution adds one order note | `failed` |
| pending | - | unchanged | `pending` (the page keeps polling) |

A mismatch can happen legitimately. For example, an admin edits the order total after the shopper opened the pay page, and the shopper then pays the old amount in that tab. The order is correctly left unpaid, and the authorization on the old amount needs to be voided in the Payrails dashboard.

### The per-order lock

The confirm endpoint, the pay-page precheck and `prepare()` can all see an authorized execution at the same moment: two tabs, a reload during a confirm, or a return from a bank redirect. Without care, each of them would complete the order. That would mean duplicate `payment_complete()` calls, notes and e-mails.

`ExecutionSession::settle()` is therefore the **only** code path that changes an order because of a Payrails result. It works like this:

1. The caller reads the execution *before* locking, so no Payrails request is ever made while the lock is held.
2. It takes a per-order lock. The lock is a row in `wp_options` inserted with `INSERT IGNORE`: `option_name` is unique, so exactly one request wins. The row holds an owner token and an expiry (120 seconds). A stale lock is taken over, and a request only ever releases its own lock.
3. It drops WooCommerce's order cache and reads the order again from the database.
4. It never completes an order that is already paid. If a *different* authorized execution is confirmed for a paid order (two tabs each paid), it adds one note asking the merchant to void it.
5. If the lock is busy, the caller answers `pending` with `retryAfterMs: 1000`, and the page polls.

```php
// includes/Woo/ExecutionSession.php, settle()
	if ( $order->is_paid() || '' !== (string) $order->get_transaction_id() ) {
		self::note_second_authorization( $order, $execution_id, $res );
		return array(
			'state'    => 'authorized',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}
	return self::apply( $order, $execution_id, $res );
} finally {
	OrderLock::release( $order_id, $token );
}
```

### The precheck

`PayPage::precheck()` runs on `template_redirect` for the pay page, before any output. If the order's current execution is already authorized or failed, it settles the order the same way. An authorized order is then redirected to order-received.

This covers three cases: a reload after paying, a return from a full-page bank verification, and a shopper who closed the tab before the confirm finished and later opens the pay link again.

### Errors

When Payrails can't be reached or answers with an error, the confirm endpoint returns `state: unknown` with a public code. The page keeps polling, because "unknown" is never treated as "failed". The public codes contain no secrets: `PR-AUTH-401`, `PR-NET-6`, `PR-UPSTREAM-503`, `PR-API-404` and so on.

Every error is logged with codes only:

- the public code;
- the Payrails error code, for API errors;
- the cURL error number, for network errors.

On the pay page, each distinct error code also adds one order note. An execution read is retried once after a `5xx` or a network error.

**Files.** [`includes/Woo/ConfirmController.php`](../plugin/payrails-woo/includes/Woo/ConfirmController.php), [`includes/Woo/ExecutionSession.php`](../plugin/payrails-woo/includes/Woo/ExecutionSession.php) (`read()`, `settle()`, `apply()`), [`includes/Core/ExecutionMapper.php`](../plugin/payrails-woo/includes/Core/ExecutionMapper.php), [`includes/Core/ExecutionVerifier.php`](../plugin/payrails-woo/includes/Core/ExecutionVerifier.php), [`includes/Core/ConfirmPolicy.php`](../plugin/payrails-woo/includes/Core/ConfirmPolicy.php), [`includes/Woo/OrderLock.php`](../plugin/payrails-woo/includes/Woo/OrderLock.php), [`includes/Woo/PayPage.php`](../plugin/payrails-woo/includes/Woo/PayPage.php) (`precheck()`), [`assets/js/pay.js`](../plugin/payrails-woo/assets/js/pay.js) (`confirm()`, `poll()`)

---

## Step 7: 3D Secure and retry after a decline

### 3D Secure

The Drop-in handles 3DS itself. When the issuer asks for a challenge, the SDK fires `actionRequired`, shows the challenge in the page, and reports `success`, `failed` or `pending` once it's done. The page then confirms as in step 6. See also the Payrails [3D Secure documentation](https://docs.payrails.com/docs/3d-secure).

The SDK may emit `pending` before a challenge is on screen, while the execution is waiting for authentication (`authorizePending`). The integration handles that with a second route to the same verification:

1. While polling, the confirm response includes the execution's 3DS link as `action: { type: 'redirect', url }`. The server only accepts `https` URLs on `payrails.io`, because the browser will be sent there.
2. The page shows "Waiting for your bank" with a **Continue to bank verification** button, which does the verification as a full-page redirect.
3. The redirect returns to the pay page (`returnUrl`), where the precheck reads the execution and completes the order.

```php
// includes/Woo/ExecutionSession.php, apply()
default:
	$out = array( 'state' => 'pending' );
	if ( null !== $res->action_url && 'authorizePending' === $res->last_code ) {
		// Payrails integration — step 7 (server side): a 3DS step is waiting.
		// The SDK may emit `pending` before the challenge is shown; returning the
		// execution's 3DS link lets the page offer the bank verification as a
		// full-page redirect as well.
		$out['action'] = array(
			'type' => 'redirect',
			'url'  => $res->action_url,
		);
	}
	return $out;
```

**Polling.** Polling starts at 1.5 s and backs off by a factor of 1.5, up to 6 s. After 15 s the page adds "Still checking. Please don't pay again". After 90 s it shows "We couldn't confirm this payment yet" with a **Check again** button. The payment is never shown as declined unless the server says it failed.

### Retry after a decline

Each Drop-in session belongs to one execution. So after a decline, the example starts a fresh execution via **Try again**:

1. The server's confirm reports `failed`, and the order becomes *Failed*, with a note for that execution. The page hides the finished Drop-in and shows **Try again** and **Return to cart**.
2. **Try again** reloads the page. `prepare()` reads the current execution, sees it failed, and clears the cached client-init.
3. With no usable cache, the next client-init gets a new attempt number, and therefore a new idempotency key and a new execution for the same order. The new execution id is added to the allow-list, so both are known.
4. Paying succeeds, and the same order moves from *Failed* to *Processing*.

```js
// assets/js/pay.js
// Payrails integration — step 7: "Try again" after a decline or an error reloads the
// page; the server then creates a fresh execution for the (failed) order, so the next
// card starts a clean Drop-in session (each session belongs to one execution).
var retry = root.querySelector( '[data-action="retry"]' );
if ( retry ) {
	retry.addEventListener( 'click', function () {
		window.location.reload();
	} );
}
```

**Files.** [`assets/js/pay.js`](../plugin/payrails-woo/assets/js/pay.js) (`poll()`, the retry handler), [`includes/Woo/ExecutionSession.php`](../plugin/payrails-woo/includes/Woo/ExecutionSession.php) (`prepare()`, `apply()`), [`includes/Core/ExecutionMapper.php`](../plugin/payrails-woo/includes/Core/ExecutionMapper.php) (`action_url()`), [`assets/css/payrails-woo.css`](../plugin/payrails-woo/assets/css/payrails-woo.css)

---

## Adapting this to your store

### Classic checkout or the Checkout block

- **The Checkout block** (the WooCommerce default since 8.3) uses `BlocksIntegration` and `blocks.js`.
- **The classic `[woocommerce_checkout]` shortcode** uses the same `Gateway` class with no extra code. `has_fields` is false, and `process_payment()` redirects to the same pay page.

In both cases the card form lives on the pay-for-order page, so steps 2 to 7 are identical. The browser tests exercise the Checkout block.

If you would rather embed the Drop-in inside the checkout page itself, bear in mind that client-init needs a final amount and a reference. You would have to create the order (or at least fix its number and total) before mounting, and still confirm server-side as in step 6. The pay-page approach avoids that complexity.

### Capture policy

The example moves an order to *Processing* as soon as the authorization is verified. Whether the funds are also captured depends on the Payrails workflow: an execution that also reports `captureSuccessful` is noted as "captured" in the order note. Decide which policy you need:

- **Auto-capture in the workflow.** Simplest for digital goods or immediate fulfilment.
- **Authorize now, capture on fulfilment.** Common for physical goods. Hook a capture call into your fulfilment step, for example when the order moves to *Completed*. See [Capture a payment](https://docs.payrails.com/docs/capture-a-payment).

Refunds and voids aren't implemented. The gateway declares only `products` support. See [PRODUCTION_CHECKLIST.md](PRODUCTION_CHECKLIST.md#capture-refunds-and-voids).

### Webhooks for asynchronous outcomes

The example learns outcomes by polling from an open browser tab, plus the precheck when the pay link is reopened. If the shopper closes the tab mid-3DS, or an outcome arrives later (some payment methods settle asynchronously), nothing updates the order until someone opens the pay page again.

In production, add a server-to-server notification endpoint, for example WooCommerce's `woocommerce_api_payrails` hook (`/wc-api/payrails`):

1. Authenticate the notification as described in [Receive notifications](https://docs.payrails.com/docs/receive-notifications).
2. Look up the order by `merchantReference`.
3. Check that the execution id is on the order's allow-list.
4. Call `ExecutionSession::read()` followed by `ExecutionSession::settle()`.

Use the notification as a trigger to re-read the execution, never as the outcome itself. Reusing `settle()` keeps one locked writer for every source: browser, precheck and webhook.

### Other things you will likely change

- **Currencies.** `Gateway::CURRENCIES` is `USD` only. Add your currencies, and check that each has the right exponent in `Money::EXPONENTS`.
- **Workflow.** Set `PAYRAILS_WORKFLOW_CODE`, or use the override on the gateway settings page.
- **Order description.** Use the `payrails_woo_order_description` filter. All the filters are listed in [ARCHITECTURE.md](ARCHITECTURE.md#filters).
- **Theme.** Set the `--woo-payrails-*` and `--payrails-*` tokens in your theme (see Theming in step 5), and ship your own `assets/payrails-appearance.json` for the card fields.
- **Staging notice.** "Payrails staging: use test cards only." shows under the pay panel only when the configured API host is a staging host.
