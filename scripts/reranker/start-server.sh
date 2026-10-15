#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "$0")/../.." && pwd)"
python_bin="${PLOS_RERANKER_PYTHON:-$HOME/.local/share/plos/qwen-reranker/venv/bin/python}"
server_script="$project_root/scripts/reranker/qwen_reranker_server.py"
runtime_dir="$project_root/storage/app/reranker-sidecar"
log_file="$project_root/storage/logs/reranker-sidecar.log"
pid_file="$runtime_dir/server.pid"

if [[ ! -x "$python_bin" ]]; then
  echo "Reranker Python runtime is not executable: $python_bin" >&2
  exit 69
fi
if [[ ${#PLOS_RERANKER_TOKEN} -lt 32 ]]; then
  echo "PLOS_RERANKER_TOKEN must contain at least 32 characters" >&2
  exit 64
fi

mkdir -p "$runtime_dir" "$(dirname "$log_file")"
umask 077

if [[ -f "$pid_file" ]]; then
  existing_pid="$(tr -cd '0-9' <"$pid_file")"
  if [[ -n "$existing_pid" ]] && kill -0 "$existing_pid" 2>/dev/null; then
    existing_command="$(tr '\0' ' ' <"/proc/$existing_pid/cmdline" 2>/dev/null || true)"
    if [[ "$existing_command" == *"qwen_reranker_server.py"* ]]; then
      echo "Reranker sidecar is already running with PID $existing_pid" >&2
      exit 75
    fi
  fi
  rm -f "$pid_file"
fi

nohup "$python_bin" "$server_script" \
  </dev/null >>"$log_file" 2>&1 \
  3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- &
server_pid="$!"
printf '%s\n' "$server_pid" >"$pid_file"

sleep 0.5
if ! kill -0 "$server_pid" 2>/dev/null; then
  rm -f "$pid_file"
  echo "Reranker sidecar exited during startup; inspect $log_file" >&2
  exit 70
fi

echo "Reranker sidecar launched with PID $server_pid"
