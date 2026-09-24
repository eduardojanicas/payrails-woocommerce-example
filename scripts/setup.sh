#!/usr/bin/env bash
# Idempotent local install: WordPress (latest) on SQLite + WooCommerce (latest),
# with plugin/payrails-woo and theme/atelier symlinked in. Safe to re-run.
# No Docker, no MySQL. Uses PHP 8.3 explicitly (see scripts/_env.sh).
source "$(dirname "$0")/_env.sh"

ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.test}"
# Admin password: random, generated once into .secrets/admin.txt (0600), never
# printed and never "admin". reset.sh reuses the same file.
ADMIN_FILE="$ROOT/.secrets/admin.txt"
if [ ! -s "$ADMIN_FILE" ]; then
  mkdir -p "$ROOT/.secrets" && chmod 700 "$ROOT/.secrets"
  ( umask 077; printf 'user=%s\npassword=%s\n' "$ADMIN_USER" "$(openssl rand -base64 30 | tr -d '/+=\n' | cut -c1-28)" > "$ADMIN_FILE" )
fi
chmod 600 "$ADMIN_FILE"
ADMIN_USER="$(sed -n 's/^user=//p' "$ADMIN_FILE")"
admin_pass() { sed -n 's/^password=//p' "$ADMIN_FILE"; }
# Tested versions (override with env vars, e.g. WC_VERSION=latest).
WP_VERSION="${WP_VERSION:-7.1.2}"
WC_VERSION="${WC_VERSION:-11.1.2}"
SQLITE_VERSION="${SQLITE_VERSION:-3.0.2}"
TT25_VERSION="${TT25_VERSION:-1.5}"
if [ "$SQLITE_VERSION" = latest ]; then SQLITE_ZIP_URL="https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip"
else SQLITE_ZIP_URL="https://downloads.wordpress.org/plugin/sqlite-database-integration.$SQLITE_VERSION.zip"; fi
ver_arg() { [ "$1" = latest ] || printf -- '--version=%s' "$1"; }

mkdir -p "$WP_DIR" "$RUN_DIR"

# 1. Core -----------------------------------------------------------------
if [ ! -f "$WP_DIR/wp-includes/version.php" ]; then
  echo "==> Downloading WordPress core"
  wp core download --locale=en_US $(ver_arg "$WP_VERSION") --quiet
fi

# 2. SQLite Database Integration plugin + db.php drop-in -------------------
#    Installed by hand (curl+unzip) because `wp plugin install` needs a working
#    DB connection, and the drop-in IS the DB connection.
SQLITE_DIR="$WP_DIR/wp-content/plugins/sqlite-database-integration"
if [ ! -f "$SQLITE_DIR/load.php" ]; then
  echo "==> Installing SQLite Database Integration plugin"
  tmp="$(mktemp -d)"
  curl -fsSL -o "$tmp/sqlite.zip" "$SQLITE_ZIP_URL"
  unzip -q -o "$tmp/sqlite.zip" -d "$WP_DIR/wp-content/plugins"
  rm -rf "$tmp"
fi
if [ ! -f "$WP_DIR/wp-content/db.php" ]; then
  echo "==> Installing db.php drop-in"
  sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#" \
      -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
      "$SQLITE_DIR/db.copy" > "$WP_DIR/wp-content/db.php"
fi

# 3. wp-config.php ---------------------------------------------------------
if [ ! -f "$WP_DIR/wp-config.php" ]; then
  echo "==> Creating wp-config.php"
  # DB_* values are placeholders: the SQLite drop-in ignores them.
  wp config create --dbname=wordpress --dbuser=unused --dbpass=unused --dbhost=localhost \
    --skip-check --quiet --extra-php <<PHP
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
define( 'WP_HOME', '$SITE_URL' );
define( 'WP_SITEURL', '$SITE_URL' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$RUN_DIR/wp-debug.log' );
define( 'WP_DEBUG_DISPLAY', false );   // keep notices out of AJAX / Store API JSON
@ini_set( 'display_errors', '0' );
define( 'SCRIPT_DEBUG', false );
define( 'DISABLE_WP_CRON', true );     // no loopback cron on the built-in server; see start.sh
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'PAYRAILS_SECRETS_FILE', '$SECRETS_FILE' );
PHP
fi

# Keep the site URL (host + PORT) and the secrets-file constant in step with _env.sh.
for c in WP_HOME WP_SITEURL; do
  [ "$(wp config get "$c" 2>/dev/null)" = "$SITE_URL" ] || wp config set "$c" "$SITE_URL" --quiet
done
# Payrails credentials: the plugin reads PAYRAILS_* environment variables, else the INI
# file named by this constant (see .secrets.example/). Nothing else is read by default.
[ "$(wp config get PAYRAILS_SECRETS_FILE 2>/dev/null)" = "$SECRETS_FILE" ] || wp config set PAYRAILS_SECRETS_FILE "$SECRETS_FILE" --quiet
[ -f "$SECRETS_FILE" ] || echo "   note: $SECRETS_FILE not found yet; copy .secrets.example/payrails.env.example there (the gateway stays hidden until then)"

# Local hardening (idempotent): no plugin/theme file editor in wp-admin; wp-config holds the salts.
cfg_true() { [ "$(wp config get "$1" 2>/dev/null)" = "1" ] || wp config set "$1" true --raw --quiet; }
cfg_true DISALLOW_FILE_EDIT
chmod 600 "$WP_DIR/wp-config.php"

# 4. Install ---------------------------------------------------------------
if ! wp core is-installed 2>/dev/null; then
  echo "==> Installing WordPress"
  # Password over stdin (--prompt), so it never appears in argv or output.
  admin_pass | wp core install --url="$SITE_URL" --title="Atelier" \
    --admin_user="$ADMIN_USER" --prompt=admin_password \
    --admin_email="$ADMIN_EMAIL" --skip-email --quiet >/dev/null
fi
wp plugin is-active sqlite-database-integration 2>/dev/null || wp plugin activate sqlite-database-integration --quiet

# 5. WooCommerce + base theme ------------------------------------------------
wp plugin is-installed woocommerce 2>/dev/null || wp plugin install woocommerce $(ver_arg "$WC_VERSION") --quiet
wp plugin is-active woocommerce 2>/dev/null || wp plugin activate woocommerce --quiet
wp theme is-installed twentytwentyfive 2>/dev/null || wp theme install twentytwentyfive $(ver_arg "$TT25_VERSION") --quiet

# 6. Symlink our code into wp-content (absolute links; setup.sh recreates them if the repo moves) ---
link() { # link <repo-src> <wp-content-dest>
  local src="$ROOT/$1" dest="$WP_DIR/wp-content/$2"
  if [ -e "$src" ]; then
    if [ ! -L "$dest" ]; then rm -rf "$dest"; ln -s "$src" "$dest"; echo "==> Linked $2"; fi
  else
    echo "   (skip $2: $1 does not exist yet)"
  fi
}
link plugin/payrails-woo plugins/payrails-woo
link theme/atelier       themes/atelier
mkdir -p "$WP_DIR/wp-content/mu-plugins"
link mu-plugin/atelier-hardening.php mu-plugins/atelier-hardening.php

if [ -L "$WP_DIR/wp-content/themes/atelier" ] && [ -f "$ROOT/theme/atelier/style.css" ]; then
  wp theme activate atelier --quiet
else
  wp theme activate twentytwentyfive --quiet
fi
if [ -L "$WP_DIR/wp-content/plugins/payrails-woo" ] && [ -f "$ROOT/plugin/payrails-woo/payrails-woo.php" ]; then
  wp plugin is-active payrails-woo 2>/dev/null || wp plugin activate payrails-woo --quiet
fi

# 7. Site + WooCommerce settings ------------------------------------------
wp rewrite structure '/%postname%/' --hard --quiet
# `wp option update` exits 1 when update_option() returns false (e.g. a
# pre_update filter or a no-op on some options), which would abort under set -e.
opt() { wp option update "$@" --quiet 2>/dev/null || [ "$(wp option get "$1" 2>/dev/null)" = "$2" ] || echo "   warn: could not set option $1"; }
opt blogdescription "Considered essentials"
opt timezone_string "UTC"

opt woocommerce_currency USD
opt woocommerce_default_country "US:NY"
opt woocommerce_price_num_decimals 2
opt woocommerce_calc_taxes no
opt woocommerce_enable_guest_checkout yes
opt woocommerce_enable_checkout_login_reminder no
opt woocommerce_enable_signup_and_login_from_checkout no
opt woocommerce_allow_tracking no
opt woocommerce_show_marketplace_suggestions no
opt woocommerce_coming_soon no          # Woo 9.1+ "coming soon" mode would hide the shop
opt woocommerce_store_pages_only no
opt woocommerce_onboarding_profile '{"skipped":true}' --format=json
opt woocommerce_task_list_hidden yes
# HPOS on, no sync to posts table (fresh install, nothing to sync).
opt woocommerce_custom_orders_table_enabled yes
opt woocommerce_custom_orders_table_data_sync_enabled no

# Shop/Cart/Checkout/My account pages. Woo creates them on first activation;
# this re-creates any that are missing. Cart/Checkout use the blocks by default.
wp wc tool run install_pages --user="$ADMIN_USER" --quiet 2>/dev/null || true

# Drain anything WooCommerce queued in Action Scheduler during install.
wp action-scheduler run --quiet 2>/dev/null || true

echo "==> Setup complete. Run scripts/start.sh, then open $SITE_URL (admin: $ADMIN_USER, password in .secrets/admin.txt)"
