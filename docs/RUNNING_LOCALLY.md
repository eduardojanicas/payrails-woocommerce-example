# Running locally

The example runs on your machine with no Docker and no MySQL. It uses WordPress on SQLite, served by PHP's built-in server, with WooCommerce installed by the setup script. Payments go to Payrails **staging**, with test cards. Nothing is charged.

## Requirements

| Component | Version |
|---|---|
| PHP (CLI) | 8.3 or later |
| WordPress | 6.6 or later (the Atelier theme needs 6.7 or later) |
| WooCommerce | 9.0 or later |
| WP-CLI | any current release |
| Node.js | 22, for the browser tests only |

`scripts/setup.sh` installs WordPress, WooCommerce, the SQLite Database Integration plugin and Twenty Twenty-Five at the versions the example was tested with. You can override each pin with an environment variable, or set it to `latest`:

| Variable | Default (tested) |
|---|---|
| `WP_VERSION` | `7.1.2` |
| `WC_VERSION` | `11.1.2` |
| `SQLITE_VERSION` | `3.0.2` |
| `TT25_VERSION` | `1.5` |

The pins apply when `setup.sh` first downloads each component. To change versions on an existing install, run `scripts/reset.sh --hard` first.

**macOS (tested):**

```bash
brew install php@8.3 wp-cli
brew install node@22        # only needed for the browser tests; any Node 22 install works
```

The scripts also use `curl`, `unzip`, `openssl` and `lsof`, which macOS ships with.

**Linux:**

- **PHP 8.3 or later (CLI)**, with the `curl`, `sqlite3`/`pdo_sqlite`, `mbstring`, `xml`, `zip` and `gd` extensions. On Debian or Ubuntu that's the `php8.3-*` packages, from your distribution or a PHP PPA.
- **WP-CLI**, from the official phar:

  ```bash
  curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp
  ```

- **The command-line tools** `curl`, `unzip`, `openssl` and `lsof`.
- **Node 22**, for the browser tests only.

**Which PHP the scripts use.** [`scripts/_env.sh`](../scripts/_env.sh) picks the binary in this order:

1. the `PHP` environment variable, if set;
2. Homebrew's `php@8.3` (`brew --prefix php@8.3`);
3. the first `php` on your `PATH`.

It stops with a message if the binary is older than 8.3. Set `WP_PHAR` if `wp` isn't on your `PATH`, for example:

```bash
export PHP=/usr/bin/php8.3
export WP_PHAR=/usr/local/bin/wp
```

The scripts are bash scripts. Run them directly (`scripts/setup.sh`) or with `bash`, rather than sourcing them into another shell.

## Get the code

Clone the repository with git, rather than downloading an archive, so that the scripts keep their executable bits and you can pull updates:

```bash
git clone <repository-url> payrails-woocommerce-example
cd payrails-woocommerce-example
```

## What to request from Payrails

Ask your Payrails contact for a **staging** setup with:

| Item | Used as |
|---|---|
| The staging merchant API URL | `PAYRAILS_API_URL` (**required**, no default) |
| Client id and client secret | `PAYRAILS_CLIENT_ID`, `PAYRAILS_CLIENT_SECRET` |
| Your workspace id | `PAYRAILS_WORKSPACE_ID` (**required**: without it, client-init answers `401`) |
| The mTLS client certificate and its private key (PEM) | `.secrets/client.crt`, `.secrets/client.key` |
| A workflow code with **cards enabled** (3D Secure on, if you want to try the challenge flow) | `PAYRAILS_WORKFLOW_CODE`. The default is `payment-acceptance` |
| **`http://localhost:8080`** as an allowed origin, or your own `PORT` | The store sends it as `clientContext.origin` |

Use `localhost`, not an IP address such as `127.0.0.1`, as the origin. Payrails rejects client-init requests whose origin or return URL is an IP address. The local server still only listens on the loopback interface.

## Credentials

Put the credentials in `.secrets/`, which is gitignored:

```bash
mkdir -p .secrets && chmod 700 .secrets
cp .secrets.example/payrails.env.example .secrets/payrails.env
cp /path/to/your/client.crt /path/to/your/client.key .secrets/
chmod 600 .secrets/*
# now edit .secrets/payrails.env: client id, client secret, workspace id, workflow code
```

`payrails.env` is an INI file, and the plugin parses it raw, so write values as they are, without escaping (a `$` in the secret is fine). The certificate and key paths in the example file are relative to `.secrets/`. See [`.secrets.example/README.md`](../.secrets.example/README.md).

The plugin reads the credentials only from `PAYRAILS_*` environment variables, or from the file that the `PAYRAILS_SECRETS_FILE` constant names. `scripts/setup.sh` writes that constant into `wp-config.php`. Nothing is stored in the database. See [ARCHITECTURE.md](ARCHITECTURE.md#configuration-and-secrets).

## Setup, seed, start, stop, reset

```bash
scripts/setup.sh    # WordPress + WooCommerce on SQLite, plugin and theme symlinked in (idempotent)
scripts/seed.sh     # 6 products, categories, free shipping, the "Card" method enabled (idempotent)
scripts/start.sh    # starts the store in the background
```

Then open **http://localhost:8080**.

| Command | What it does |
|---|---|
| `scripts/setup.sh` | Downloads WordPress, installs the SQLite integration, writes `wp-config.php` (debug log in `.run/wp-debug.log`, display off, WP-Cron off), installs WooCommerce and Twenty Twenty-Five at the pinned versions, symlinks `plugin/payrails-woo`, `theme/atelier` and the mu-plugin into `wordpress/wp-content/`, and sets store options (USD, guest checkout, HPOS). Safe to re-run |
| `scripts/seed.sh` | Loads the 6 products from `theme/assets/products/products.json`, with categories, tags and images. Sets up free shipping, enables the **Card** method and sets the checkout button to "Continue to payment". Safe to re-run |
| `scripts/start.sh` | Starts `php -S` on `127.0.0.1:$PORT` with 4 workers, plus a loop that runs due WP-Cron events every 60 seconds. Logs go to `.run/php-server.log` |
| `scripts/stop.sh` | Stops the server and the cron loop |
| `scripts/reset.sh` | Stops the server, wipes the database (orders, customers, products, settings), then runs setup and seed again. Keeps the downloaded files and the admin password. `scripts/reset.sh --hard` also deletes `wordpress/`. Run `scripts/start.sh` afterwards |

**Admin.** The WordPress admin is at `http://localhost:8080/wp-admin/`. `setup.sh` generates a random admin password once and stores it, with the user name (`admin` unless you set `ADMIN_USER` on the first run), in `.secrets/admin.txt`. It never prints the password.

**Port.** The default port is 8080. To use another one, export `PORT` for every script, including the tests:

```bash
export PORT=8081
scripts/setup.sh    # rewrites WP_HOME / WP_SITEURL to http://localhost:8081
scripts/start.sh
```

WordPress stores its own URL, and `setup.sh` sets it from `PORT`, so re-run `setup.sh` whenever you change the port. If you start the server on a different port than the one `setup.sh` last used, WordPress redirects you to the old one. The allowed origin at Payrails must match too.

## Demo click-path

This takes about two minutes.

1. **Home.** Open `http://localhost:8080`: the Atelier home page with six products. The navigation leads to the shop and the Women and Men collections.
2. **Add to cart.** Open **Merino Wool Sweater** and click **Add to cart**. The cart count updates.
3. **Checkout.** Go to **Checkout**, which is the WooCommerce Checkout block. Enter an e-mail and a US address. The payment method is **Card**.
4. **Continue to payment.** WooCommerce creates a *Pending payment* order and redirects to `/checkout/order-pay/{id}/?key=...`. By now the server has run client-init over mTLS and stored the execution id on the order.
5. **The Drop-in.** The pay page shows the Payrails Drop-in with the card form open, styled with the store's colors, next to the order summary.
6. **Pay.** Enter card number `4242 4242 4242 4242`, month `12`, year `30` and CVC `123`, then **Pay**. The page shows **Confirming your payment**, then **Payment confirmed**, then the order-received page. The browser only asked the store to check the execution. The store read it from Payrails, verified it against the order, and marked the order **Processing**, with the execution id as the transaction id. See **WooCommerce > Orders** and the order notes.
7. **3D Secure.** Repeat with your processor's 3DS-challenge test card. A test 3DS page appears in the Drop-in. **Complete** authorizes, and the order becomes *Processing*. **Fail** shows **Payment declined**.
8. **Decline and retry.** Use your processor's decline test card. The page shows **Payment declined**, and the order is *Failed*. Click **Try again**, which starts a fresh execution for the same order, and pay with `4242 4242 4242 4242`. The same order becomes *Processing*.

In the Payrails staging dashboard, you can find each execution by its merchant reference, which is the WooCommerce order number.

## Test cards

The Drop-in has separate fields for the month (MM) and the year (YY).

| Card number | Month / Year / CVC | Result |
|---|---|---|
| `4242 4242 4242 4242` | any future date, e.g. `12` / `30`, CVC `123` | Authorized, no 3DS |
| `4111 1111 1111 1111` | same | Authorized, no 3DS |
| Your processor's 3DS-challenge test card | same | 3DS challenge. **Complete** authorizes, **Fail** declines |
| Your processor's decline test card | same | Declined |

Test card numbers depend on the payment processor your Payrails staging workflow routes cards to, so ask your Payrails contact for the set that matches your workflow. The two card numbers above are common processor test cards and are the e2e suite's defaults (override with `E2E_CARD_OK` and `E2E_CARD_VISA`). The 3DS-challenge and decline cards are processor-specific: set `E2E_CARD_3DS` and `E2E_CARD_DECLINE` to run those tests, which are skipped otherwise. Never use real card numbers on staging.

## Running the tests

**PHP unit tests.** No WordPress, no network. The first run downloads the PHPUnit 12 phar into `tests/php/tools/`.

```bash
scripts/test-php.sh
```

**PHP integration test.** It runs against the local WordPress, so run `setup.sh` and `seed.sh` first. Payrails is replaced by a fake through the `payrails_woo_http_transport` filter, with dummy configuration and the test-only `PAYRAILS_WOO_TESTING` switch: no network and no credentials. It creates throwaway orders and deletes them afterwards.

```bash
scripts/test-php.sh --integration
```

**Browser tests** (Playwright, real Chromium):

```bash
npm ci
npx playwright install chromium
npm run test:e2e:staging     # real Payrails staging, about 8 test authorizations
npm run test:e2e:authfail    # failure paths, no Payrails traffic
npm run test:e2e             # both, in that order
```

Keep these points in mind:

- **They manage the local server.** The suites stop and restart the server on `PORT` in the mode each test needs. When they finish, they leave it running against staging.
- **The authfail suite changes wp-config temporarily.** It repoints `PAYRAILS_SECRETS_FILE` and restores it afterwards.
- **`E2E_RESET=1`** runs `scripts/reset.sh` first, which wipes all orders.
- **Output.** Screenshots and `results.json` go to `tests/e2e/artifacts/`. Traces of failed tests go to `test-results/`.
- **The staging suite needs your working credentials** in `.secrets/`.

## Troubleshooting

The pay page shows a short code with every error. The same code appears in the order notes. Log lines go to **WooCommerce > Status > Logs** (source `payrails-woo`). Errors are always logged; turn on the gateway's **Debug log** setting for info lines too.

| Symptom | Cause and fix |
|---|---|
| No **Card** method at checkout, or `PR-CONFIG` on the pay page | A credential is missing, or the certificate or key isn't readable. The admin notice and the gateway settings page (**WooCommerce > Settings > Payments > Payrails**, status **Incomplete**) name the missing key, never its value |
| `PR-AUTH-401` | The request was refused twice at authentication. Check the client id and secret in `.secrets/payrails.env` (don't escape characters in the secret), and check that `PAYRAILS_WORKSPACE_ID` is the workspace this client belongs to |
| `PR-API-403` | The origin or return URL isn't accepted. Browse via `http://localhost:<port>`, not `127.0.0.1`. Check that `WP_HOME` matches (re-run `scripts/setup.sh` with the right `PORT`), and that Payrails has that origin configured for your client |
| `PR-NET-35`, `PR-NET-58` or another `PR-NET-*` | A TLS or network failure: the certificate and key don't match or can't be read, or there's no connection. The number is the cURL error code |
| `PR-UPSTREAM-429` / `PR-UPSTREAM-5xx` | Payrails asked the store to slow down, or had a temporary error. Wait and choose **Try again** |
| `PR-SDK-LOAD` or `PR-SDK-INIT` | The browser couldn't load the SDK from `assets.payrails.io` (offline, an ad or script blocker, a CSP) |
| **This payment session has expired** (`PR-SESSION`) | The payment session expired twice within a minute. Reload the page to start a new one |
| **Waiting for your bank** with a **Continue to bank verification** button | The execution is waiting for 3DS, and no challenge is on screen. Click the button: it does the verification as a full-page redirect and brings you back to the pay page, which completes the order |
| **We couldn't confirm this payment yet** | No outcome after 90 seconds of polling. Use **Check again**, or reload the pay page later. It's never treated as a decline |
| **This page has expired** (`stale_session`) | The page's nonce no longer validates. Reload the pay page |
| An order stays *Pending payment* after the tab was closed | Open its pay link again (it's in the admin order screen). The page reads the execution from Payrails and completes the order if it was authorized |
| An order is *On hold* with a `PR-VERIFY` note | The authorized execution didn't match the order's current reference, amount or currency, for example because the order was edited after the pay page opened. The order is correctly not paid. Void the authorization in the Payrails dashboard (the execution id is in the note) and ask the shopper to pay again |
| Order note "a second authorization ... exists" | Two tabs each authorized the same order. The order was completed once. Void the extra execution in the Payrails dashboard |
| Browser console messages about `postMessage` target origins | These come from the cross-origin messaging of the secure card frame and don't affect the payment |
| A `wp config set` change seems ignored for a moment | PHP's opcache revalidates files every 2 seconds on the built-in server. Wait briefly, or run `scripts/stop.sh && scripts/start.sh` |
| `Port 8080 is in use by another process` | Another program holds the port. Stop it, or use `PORT=8081` (and re-run `setup.sh`) |
| `PHP 8.3+ required` or `PHP 8.3+ not found` | Install PHP 8.3 or later, or point `PHP` at it (see [Requirements](#requirements)) |
| PHP warnings or notices | They are in `.run/wp-debug.log`. Display is off so that AJAX and Store API responses stay valid JSON |

## Housekeeping

Every **Continue to payment** creates a WooCommerce order, and abandoned ones stay *Pending payment*. The seed turns stock management off, so WooCommerce never cancels them. To clean up:

- run `scripts/reset.sh`, which wipes orders, customers and test coupons and reseeds the store;
- or bulk-trash them in **WooCommerce > Orders > Pending payment**.

Deactivating and deleting the plugin in wp-admin runs [`uninstall.php`](../plugin/payrails-woo/uninstall.php). It removes the plugin's settings, install id, transients and lock rows, and keeps the execution ids and notes on orders.
