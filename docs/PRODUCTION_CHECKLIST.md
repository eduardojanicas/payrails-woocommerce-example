# Production checklist

This repository is a reference example. It runs locally, against Payrails **staging**, with test cards. It deliberately leaves out several things a production store needs. This page lists them, with a starting point for each.

The part most worth reusing is the payment core: the server-side confirm, verification, idempotency and locking. It is covered by unit, integration and staging tests, but it hasn't been through a production security review or load testing. Review it as you would any code you adopt. The runtime around it (web server, secrets storage, hosting) is local-only by design.

The last two sections cover [going live](#going-live) and the [questions to settle with Payrails](#questions-to-settle-with-payrails-before-production) before production.

## Summary

| Area | In this example | Production needs |
|---|---|---|
| Web server | PHP's built-in server with `scripts/router.php` | nginx or Caddy + PHP-FPM, HTTPS only |
| Secrets | An INI file in `.secrets/` inside the project | Environment variables or a file outside the docroot, with per-environment credentials |
| CSP and headers | None beyond WooCommerce's defaults | A Content Security Policy and security headers |
| Rate limiting | None | Limits on order creation, the pay page and confirm |
| Capture, refunds, voids | Not implemented | Your capture policy, `process_refund()`, voids |
| Asynchronous outcomes | Browser polling + precheck on reload | Webhooks that trigger a server re-read |
| Wallets | Hidden (cards only) | Domain verification, CSP and wallet approvals |
| Stored cards | Off for guests. Workflow defaults for logged-in customers | A deliberate policy for authenticated customers only |
| Pending orders | Accumulate (stock management is off in the seed) | Scheduled cleanup that checks Payrails first |
| Logging | WooCommerce logs, shapes and codes only | Central logging and alerts, still without secrets |
| WordPress | Local-only hardening | Full hardening |

## HTTPS and a real web server

- Serve through **nginx or Caddy with PHP-FPM**. Never expose `php -S`, and don't rely on `router.php` as a security boundary.
- Port the router's deny rules to the web server:
  - the database directory and `*.sqlite*` files;
  - dotfiles, except `/.well-known/`;
  - `*.log`, `*.env`, `*.pem`, `*.key` and `*.crt`;
  - `wp-config*.php`;
  - `wp-content/uploads/**/*.php`;
  - `wp-content/uploads/wc-logs/`;
  - the plugin's PHP internals (`wp-content/plugins/payrails-woo/` except `assets/`);
  - `xmlrpc.php`.
- Use **HTTPS only**: a valid public certificate, a redirect from 80 to 443, HSTS, `https://` in `WP_HOME` and `WP_SITEURL`, `FORCE_SSL_ADMIN`, and secure cookies.
- **The origin and return URL.** Client-init sends `clientContext.origin` and `returnUrl` built from `home_url()`. Make sure they carry your real public host name, and that Payrails has that origin configured for your client. Payrails rejects IP-address origins.
- Prefer MySQL or MariaDB on a server. SQLite is used here only to keep the local setup dependency-free.

## Where secrets live

- Put the `PAYRAILS_*` values in **environment variables of the PHP-FPM pool** (`env[PAYRAILS_CLIENT_SECRET] = ...`, and mind `clear_env`). Alternatively, use an INI file **outside the docroot**, such as `/etc/payrails/store.env`, mode `0640`, owned by `root` with the PHP-FPM group, and point `PAYRAILS_SECRETS_FILE` at it. Treat the mTLS certificate and key the same way.
- Never keep secrets in the repository, the database or wp-admin. The plugin never reads them from the database.
- Use **separate credentials per environment** (staging, production), and rotate them if a copy is ever shared.
- **Move the token cache out of `wp_options`.** The bearer token is cached in a WordPress transient, which is a database row without an object cache. Use APCu, Redis with authentication, or a `0600` file next to the secrets. `Core\Api\TokenCache` is the interface to implement, and `Woo\Services::client()` is where it's wired.
- The plugin already refuses any `PAYRAILS_API_URL` that isn't `https://*.payrails.io`.

## Content Security Policy

WordPress sends no CSP by default. Start with this policy in **Report-Only** mode on the storefront and pay page, watch the reports for a week, then enforce it.

```
Content-Security-Policy-Report-Only:
  default-src 'self';
  script-src 'self' 'unsafe-inline' https://assets.payrails.io;
  style-src 'self' 'unsafe-inline' https://assets.payrails.io;
  font-src 'self' data: https://assets.payrails.io;
  img-src 'self' data: https://assets.payrails.io;
  connect-src 'self' https://*.payrails.io;
  frame-src https://*.payrails.io https:;
  form-action 'self' https://*.payrails.io https:;
  frame-ancestors 'self';
  base-uri 'self';
  object-src 'none';
  upgrade-insecure-requests;
  report-to csp
```

Notes:

- **Inline scripts.** WordPress and WooCommerce print inline scripts (`wp_add_inline_script`, `wcSettings`). Either keep `'unsafe-inline'` for scripts, or move to nonces through the `wp_inline_script_attributes` and `wp_script_attributes` filters.
- **3DS pages.** `frame-src https:` and `form-action https:` are there because 3DS challenge pages come from each card issuer's own domain.
- **Wallets.** When you enable them, add `https://pay.google.com` for Google Pay and `https://applepay.cdn-apple.com` for Apple Pay to `script-src` and `frame-src`.
- **Other headers.** Also send `Referrer-Policy: strict-origin-when-cross-origin` and `Permissions-Policy: payment=(self "https://assets.payrails.io")`. Set `expose_php = Off`.
- **Symptom of a blocked SDK.** If the CSP blocks the SDK, the pay page shows `PR-SDK-LOAD` or `PR-SDK-INIT`.

## Rate limiting

These endpoints are unauthenticated for guests, and none of them is throttled in the example:

- Every pay-page view reads the execution from Payrails.
- A new execution is created after each decline, and there's no cap on attempts.
- Every confirm or poll re-reads the execution.

That's an abuse surface (API quota, junk executions) and, with production credentials, a card-testing surface.

- **At the edge**, limit per IP: `/?wc-ajax=payrails_confirm` (for example 1 request/s, burst 5), `/wp-json/wc/store/v1/checkout` (order creation), `/checkout/order-pay/*` and `wp-login.php`. For example, with nginx `limit_req`:

  ```nginx
  # http {} block: count only confirm requests (an empty key is not counted).
  map $request_uri $payrails_confirm_key {
      default                    "";
      ~wc-ajax=payrails_confirm  $binary_remote_addr;
  }
  limit_req_zone $payrails_confirm_key zone=payrails_confirm:10m rate=1r/s;

  # server {} block, in the location that passes requests to PHP-FPM:
  limit_req zone=payrails_confirm burst=5 nodelay;
  ```

  A CDN or WAF (for example Cloudflare rate limiting rules) can express the same limits.
- **In WooCommerce**, enable the Store API rate limiter (the `woocommerce_store_api_rate_limit_options` filter).
- **In the plugin**, add:
  - a cap on executions per order (the `_payrails_attempt` counter is already there), with a "please contact us" message once it's reached;
  - a short per-order throttle on confirm reads, returning the last result for about a second;
  - a per-IP counter on client-init creation.

## Capture, refunds and voids

- **Capture.** The example moves the order to *Processing* once the authorization is verified. Choose your policy: auto-capture in the Payrails workflow, or capture on fulfilment (for example on the transition to *Completed*). If the workflow only authorizes, the funds stay authorized until you capture them or the authorization expires.
- **Refunds.** Add `refunds` to the gateway's `supports` and implement `process_refund( $order_id, $amount, $reason )`. It calls the Payrails refund operation for the order's execution (the transaction id). Record the result in an order note.
- **Voids and cancellations.** Void the authorization when an unpaid or authorized-only order is cancelled. The example already writes an order note when a **second** authorization exists for an already-paid order (two tabs), or when an authorization doesn't match the order (`PR-VERIFY`, on hold). Today those have to be voided by hand in the Payrails dashboard. Automate them in production.

See the Payrails guides to [capture](https://docs.payrails.com/docs/capture-a-payment), [refund](https://docs.payrails.com/docs/refund-a-payment) and [cancel](https://docs.payrails.com/docs/cancel-a-payment) a payment for the operations your workflow exposes.

## Webhooks for asynchronous outcomes

The example relies on the open pay page (polling) and on the precheck when the pay link is opened again. Add a server-to-server notification endpoint so that orders update even when the shopper has left:

1. Register a handler, for example on `woocommerce_api_payrails` (`/wc-api/payrails`).
2. Authenticate the notification as described in [Receive notifications](https://docs.payrails.com/docs/receive-notifications), and reject anything that doesn't verify.
3. Find the order by `merchantReference`, and check that the execution id is on `_payrails_execution_ids`.
4. Call `ExecutionSession::read( $order, $id, true )` and then `ExecutionSession::settle()`. The notification is a trigger to re-read. The outcome still comes from the execution itself, and completion still goes through the single locked writer.
5. Answer quickly and idempotently. The same notification may arrive more than once.

## Wallets (Apple Pay, Google Pay)

The example is cards-only. The Drop-in's wallet rows are hidden, and the `payrails_woo_cards_only` filter (default `true`) controls that. To offer wallets:

- Return `false` from `payrails_woo_cards_only`, and enable the wallets in your Payrails workflow.
- **Apple Pay** needs HTTPS on a registered domain. Register each exact domain through Payrails, then serve the domain-association file at `https://<your-domain>/.well-known/apple-developer-merchantid-domain-association`, byte for byte, with no redirect. Your web server must allow that path. The local `router.php` already serves existing static files under `/.well-known/` and nothing else there. `localhost` and tunnel host names can't be registered.
- **Google Pay** in production requires Google's approval of the integration.
- Add the wallet origins to the CSP (see above).

Wallet payments still go through the same server-side confirm and verification.

## Stored cards

- **Guests** get a random, per-order `holderReference` and a Drop-in with no stored cards and no "save card" option. Keep it that way: a guest's e-mail is unverified.
- Offer stored cards **only to authenticated customers**. Each WordPress user gets one random `wc-customer-{32 hex}` holder reference, kept in user meta, so saved cards follow the account without exposing the user id. Consider requiring a verified e-mail address and a recent login before showing saved cards.
- For logged-in customers the example leaves the Drop-in's card options at their defaults, so the workflow configuration decides. Set `paymentMethodsConfiguration.cards` explicitly in `pay.js`, so the behavior is intentional.
- If a customer account is deleted or merged, handle the holder's stored instruments accordingly. The holder reference is in the user's `_payrails_holder_reference` meta.

## Cleanup of pending orders

- Each "Continue to payment" creates a WooCommerce order. Abandoned ones stay *Pending payment*. The seed turns stock management off, so WooCommerce's hold-stock timer never cancels them.
- In production, with stock management on, WooCommerce cancels unpaid orders after `woocommerce_hold_stock_minutes`. A shopper may still be completing 3DS at that point. Before cancelling or deleting an order, re-read its current execution. Settle it if it's authorized, and void it if you cancel.
- Delete the `payrails_woo_ci_{order id}` transients once an order is paid or cancelled, or with a daily job. They hold client-init responses, which contain SDK session data and shopper details. They expire after 15 minutes, but WordPress deletes expired transients only lazily.

## Monitoring and logging without secrets

- The plugin logs to **WooCommerce > Status > Logs** (source `payrails-woo`). Warnings and errors are always logged. Info lines are logged only when the gateway's **Debug log** setting is on, which it isn't by default. The logs contain public codes, Payrails error codes, the cURL error number for network errors, and the *shape* of responses (key names). They never contain values, tokens, secrets or exception messages. Keep it that way if you add logging.
- Ship the logs to your central logging, and alert on:
  - orders going *On hold* with `PR-VERIFY`;
  - `PR-UPSTREAM-429` and `PR-UPSTREAM-5xx`;
  - `PR-AUTH-*`, which means credentials or certificates need attention;
  - the "second authorization" order note.
- Watch the ratio of *Failed* to *Processing* orders and the number of executions per order, which is an early sign of card testing.
- Keep `WP_DEBUG` off and `display_errors` off, and write PHP's error log outside the docroot.

## WordPress hardening

- A random admin username, a strong password and a second factor. Put `/wp-admin` behind an IP allow-list or extra authentication, and throttle logins.
- `WP_ENVIRONMENT_TYPE` set to `production`, `WP_DEBUG` false, `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS` true, and fresh salts (`wp config shuffle-salts`).
- XML-RPC off, and `/wp-json/wp/v2/users` restricted for anonymous visitors. The local `mu-plugin/atelier-hardening.php` does both.
- Automatic security updates for core, WooCommerce and your theme, or a weekly patch routine.
- Remove `readme.html`, `license.txt` and `wp-admin/install.php` from the public root.
- Start from a fresh database, with no test orders, customers or coupons.
- Before you switch to production credentials, walk the whole flow on the hosted store against Payrails staging: card, 3DS, decline and retry. The bundled end-to-end suite drives a local store (it restarts the local server and uses WP-CLI), so treat it as a model for your own hosted checks.

## Going live

Moving from staging to production is mostly configuration, done together with your Payrails contact:

- **Production credentials.** Payrails issues a production client id, client secret and workspace id. Keep them separate from staging, and store them as described in [Where secrets live](#where-secrets-live).
- **The mTLS certificate.** Production calls need a production client certificate and key. Ask Payrails how certificates are issued for your client, how long they are valid, and how rotation works, so you can plan renewals before expiry. Keep the private key on the server only.
- **The API URL.** Your Payrails contact provides the production API URL along with the certificate. Set it as `PAYRAILS_API_URL`. The plugin accepts only `https://*.payrails.io` hosts. Once the host is no longer a staging host, the "use test cards only" notice disappears from the pay page.
- **Allowed origins.** Ask Payrails to allow your production origin (for example `https://shop.example.com`) for the production client. Client-init sends it as `clientContext.origin`, built from `home_url()`.
- **The workflow.** Confirm the production workflow code and its configuration: cards enabled, 3DS, and the capture policy.
- **A final check.** Before switching, walk the whole flow on the hosted store against staging: card, 3DS, decline and retry. Then do a low-value live transaction and refund it.

See also the Payrails [mTLS configuration](https://docs.payrails.com/docs/mtls-configuration-1) guide.

## Questions to settle with Payrails before production

These are normal onboarding topics. Settling them early avoids surprises at go-live.

- **Terminal status codes.** Which execution status codes your workflow can report, and which are terminal. The example treats an exact `authorizeSuccessful` or `captureSuccessful` as paid, a failure, cancel, void, reversal, refund or expiry as not paid, and anything unknown as pending. Confirm that matches your workflow. The [status codes reference](https://docs.payrails.com/docs/workflow-studio-status-codes) is a starting point.
- **Session lifetime.** How long a client-init session stays valid. The example caches it for 15 minutes and starts a new one when the SDK reports `sessionExpired`.
- **Webhook events and signing.** Which notification events you'll receive, how they are signed, and how retries work. See [Notifications](https://docs.payrails.com/docs/notifications).
- **Capture, refund and void.** Which operations your workflow exposes and how to call them. See [Capture](https://docs.payrails.com/docs/capture-a-payment), [Refund](https://docs.payrails.com/docs/refund-a-payment) and [Cancel](https://docs.payrails.com/docs/cancel-a-payment).
- **Restricting the Drop-in to cards.** Whether the workflow can offer only cards. The example otherwise hides wallet rows in the page, which relies on Drop-in markup.
- **PCI scope.** Card data is entered in Payrails-hosted fields and never reaches your server, which typically places the store in the scope of SAQ A. Confirm the exact scope with Payrails and your acquirer or QSA.
- **Allowed-origins management.** How to add or change allowed origins for each environment and client, including staging and preview hosts.
