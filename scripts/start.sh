#!/usr/bin/env bash
# Starts PHP's built-in server on $HOST:$PORT (default 127.0.0.1:8080) in the background, plus a tiny
# cron loop (WP-Cron is disabled in wp-config, so Action Scheduler runs here).
source "$(dirname "$0")/_env.sh"
mkdir -p "$RUN_DIR"

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  echo "Already running (pid $(cat "$PID_FILE")) at $SITE_URL"; exit 0
fi
# Stale pid files (crash, reboot): the process is gone, so drop them.
rm -f "$PID_FILE" "$RUN_DIR/cron.pid"
[ -f "$WP_DIR/wp-config.php" ] || { echo "Not installed yet: run scripts/setup.sh first" >&2; exit 1; }
if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  echo "Port $PORT is in use by another process" >&2; exit 1
fi

# >1 worker so a request that loops back to the site (Store API from the block
# checkout, REST from the editor, site-health) never deadlocks the server.
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" \
nohup "$PHP" -d display_errors=0 -d log_errors=1 -d memory_limit=512M \
  -d upload_max_filesize=32M -d post_max_size=32M \
  -S "$HOST:$PORT" -t "$WP_DIR" "$ROOT/scripts/router.php" >>"$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"

# Cron loop: run due WP-Cron events (incl. Action Scheduler's queue runner) every 60s.
( while kill -0 "$(cat "$PID_FILE" 2>/dev/null)" 2>/dev/null; do
    wp cron event run --due-now --quiet >/dev/null 2>&1 || true
    sleep 60
  done ) >/dev/null 2>&1 &
echo $! > "$RUN_DIR/cron.pid"

for _ in $(seq 1 30); do
  curl -fsS -o /dev/null "$SITE_URL/wp-login.php" 2>/dev/null && break
  sleep 0.3
done
echo "Atelier running at $SITE_URL (log: $LOG_FILE)"
