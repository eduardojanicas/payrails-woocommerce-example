#!/usr/bin/env bash
# PHP unit tests for the WordPress-free core (no network, no WordPress).
# Downloads the PHPUnit 12 PHAR once into tests/php/tools/ (gitignored).
#   scripts/test-php.sh                 unit tests
#   scripts/test-php.sh --integration   also the order state machine against the local WP
#                                       (Payrails replaced by a fake via the
#                                       payrails_woo_http_transport filter; no network)
source "$(dirname "$0")/_env.sh"
TOOLS="$ROOT/tests/php/tools"
PHAR="$TOOLS/phpunit.phar"
mkdir -p "$TOOLS"
if [ ! -f "$PHAR" ]; then
  echo "==> Downloading PHPUnit 12 PHAR"
  curl -fsSL -o "$PHAR.tmp" https://phar.phpunit.de/phpunit-12.phar
  mv "$PHAR.tmp" "$PHAR"
fi
integration=0
args=()
for a in "$@"; do
  if [ "$a" = "--integration" ]; then integration=1; else args+=("$a"); fi
done
"$PHP" "$PHAR" -c "$ROOT/tests/php/phpunit.xml" ${args[@]+"${args[@]}"}
if [ "$integration" = 1 ]; then
  echo "==> Integration: order state machine (local WordPress, fake Payrails, no network)"
  # Dummy, non-secret config: complete PAYRAILS_* environment variables take precedence
  # over the secrets file, so this test never uses (or needs) real credentials. The
  # *.payrails.invalid host is accepted only because PAYRAILS_WOO_TESTING is defined.
  PAYRAILS_API_URL=https://api.payrails.invalid PAYRAILS_CLIENT_ID=integration-test \
  PAYRAILS_CLIENT_SECRET=integration-test-not-a-secret PAYRAILS_WORKSPACE_ID=integration-test PAYRAILS_WORKFLOW_CODE=payment-acceptance \
  PAYRAILS_CERT_PATH=/dev/null PAYRAILS_KEY_PATH=/dev/null \
    wp --exec="define( 'PAYRAILS_WOO_TESTING', true );" eval-file "$ROOT/tests/php/integration/state-machine.php"
fi
