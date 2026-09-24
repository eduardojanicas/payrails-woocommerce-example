#!/usr/bin/env bash
# Seeds the Atelier catalogue and store settings. Idempotent (products keyed by SKU).
# Run after setup.sh. Safe to re-run at any time.
source "$(dirname "$0")/_env.sh"

[ -f "$WP_DIR/wp-config.php" ] || { echo "Not installed yet: run scripts/setup.sh first" >&2; exit 1; }

# Theme + plugin (setup.sh links them; activation is repeated here so seed works on its own).
wp theme is-active atelier 2>/dev/null || wp theme activate atelier --quiet
if [ -L "$WP_DIR/wp-content/plugins/payrails-woo" ]; then
  wp plugin is-active payrails-woo 2>/dev/null || wp plugin activate payrails-woo --quiet
fi

# Run as the admin created by setup.sh (user name from .secrets/admin.txt, else $ADMIN_USER / admin).
ADMIN_USER="${ADMIN_USER:-$(sed -n 's/^user=//p' "$ROOT/.secrets/admin.txt" 2>/dev/null || true)}"
ADMIN_USER="${ADMIN_USER:-admin}"
wp eval-file "$ROOT/scripts/seed.php" "$ROOT/theme/assets/products/products.json" --user="$ADMIN_USER"
wp rewrite flush --hard --quiet
echo "==> Seed complete"
