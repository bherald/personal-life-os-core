#!/usr/bin/env bash
set -euo pipefail

engine="$1"
project_root="$(cd "$(dirname "$0")/../.." && pwd)"

case "$engine" in
  puppeteer)
    server_script="$project_root/scripts/browser-server/puppeteer-server.cjs"
    port="9222"
    ;;
  playwright)
    server_script="$project_root/scripts/browser-server/playwright-server.cjs"
    port="9223"
    ;;
  *)
    echo "Unknown browser engine: $engine" >&2
    exit 64
    ;;
esac

runtime_dir="$project_root/storage/app/browser-server"
log_dir="$project_root/storage/logs"
pid_file="$runtime_dir/$engine.pid"
log_file="$log_dir/browser-$engine.log"

mkdir -p "$runtime_dir" "$log_dir"
umask 077

if [[ -f "$pid_file" ]]; then
  existing_pid="$(tr -cd '0-9' <"$pid_file")"
  if [[ -n "$existing_pid" ]] && kill -0 "$existing_pid" 2>/dev/null; then
    existing_command="$(tr '\0' ' ' <"/proc/$existing_pid/cmdline" 2>/dev/null || true)"
    if [[ "$existing_command" == *"$server_script"* ]]; then
      echo "$engine server process is already running with PID $existing_pid" >&2
      exit 75
    fi
  fi
  rm -f "$pid_file"
fi

# Symfony Process terminates an asynchronous child when its handle is destroyed.
# Give the persistent Node sidecar its own stdio and session lifecycle instead.
nohup node "$server_script" "$port" \
  </dev/null >>"$log_file" 2>&1 \
  3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- &
server_pid="$!"
printf '%s\n' "$server_pid" >"$pid_file"

sleep 0.2
if ! kill -0 "$server_pid" 2>/dev/null; then
  rm -f "$pid_file"
  echo "$engine server exited during startup; inspect $log_file" >&2
  exit 70
fi

echo "$engine server launched with PID $server_pid"
