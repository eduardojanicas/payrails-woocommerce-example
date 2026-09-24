# Sourced by every script in scripts/. Never executed directly.
# Holds paths, the PHP binary and the wp() wrapper.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="$ROOT/wordpress"

# PHP >= 8.3: $PHP if set, else Homebrew's php@8.3, else the first `php` on PATH.
if [ -z "${PHP:-}" ]; then
  brew_php="$(brew --prefix php@8.3 2>/dev/null || true)/bin/php"
  if [ -x "$brew_php" ]; then PHP="$brew_php"; else PHP="$(command -v php || true)"; fi
fi
[ -n "$PHP" ] && [ -x "$PHP" ] || { echo "PHP 8.3+ not found (set PHP=/path/to/php, or brew install php@8.3)" >&2; exit 1; }
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || { echo "PHP 8.3+ required, $PHP is $("$PHP" -r 'echo PHP_VERSION;')" >&2; exit 1; }

WP_PHAR="${WP_PHAR:-$(command -v wp || true)}"
HOST="${HOST:-127.0.0.1}"             # interface the PHP server binds to
# Public host name of the site: a name, not an IP literal. Payrails refuses a
# client-init whose clientContext.origin/returnUrl has an IPv4 host, and "localhost"
# resolves to the loopback address the server listens on.
SITE_HOST="${SITE_HOST:-localhost}"
PORT="${PORT:-8080}"
SITE_URL="http://$SITE_HOST:$PORT"
RUN_DIR="$ROOT/.run"            # pid + server log (gitignored)
PID_FILE="$RUN_DIR/php-server.pid"
LOG_FILE="$RUN_DIR/php-server.log"
SECRETS_FILE="$ROOT/.secrets/payrails.env"

[ -n "$WP_PHAR" ] || { echo "wp-cli not found (brew install wp-cli)" >&2; exit 1; }

# wp-cli is a PHAR with a "#!/usr/bin/env php" shebang: run it through $PHP explicitly.
wp() { "$PHP" -d memory_limit=512M "$WP_PHAR" --path="$WP_DIR" "$@"; }
