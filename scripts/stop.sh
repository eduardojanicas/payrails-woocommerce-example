#!/usr/bin/env bash
source "$(dirname "$0")/_env.sh"
for f in "$RUN_DIR/cron.pid" "$PID_FILE"; do
  if [ -f "$f" ]; then
    pid="$(cat "$f")"
    # Workers are children of the master; kill the group's children first.
    pkill -P "$pid" 2>/dev/null || true
    kill "$pid" 2>/dev/null || true
    rm -f "$f"
  fi
done
echo "Stopped."
