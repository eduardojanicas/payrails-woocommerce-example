#!/usr/bin/env bash
# Wipes the site database (orders, products, settings) and rebuilds it.
# Keeps downloaded core/plugins so it is fast. `reset.sh --hard` deletes wordpress/ entirely.
source "$(dirname "$0")/_env.sh"
"$ROOT/scripts/stop.sh" >/dev/null || true
if [ "${1:-}" = "--hard" ]; then
  rm -rf "$WP_DIR"
else
  rm -rf "$WP_DIR/wp-content/database"
fi
rm -f "$RUN_DIR/wp-debug.log"
"$ROOT/scripts/setup.sh"
[ -x "$ROOT/scripts/seed.sh" ] && "$ROOT/scripts/seed.sh"
echo "Reset complete. Run scripts/start.sh"
