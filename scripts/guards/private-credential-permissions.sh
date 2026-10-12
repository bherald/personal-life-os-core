#!/usr/bin/env bash
set -euo pipefail

fix=false
repo_only=false

usage() {
  cat <<'EOF'
Usage: scripts/guards/private-credential-permissions.sh [--fix] [--repo-only]

Checks local secret-bearing env files, Passport key files, and private SSH keys
for group/world permissions. With --fix, removes group/world permissions from
files that exist. With --repo-only, checks only secret-bearing files inside the
repository. This mode is suitable for a non-mutating deployment gate.
EOF
}

while [[ "$#" -gt 0 ]]; do
  case "$1" in
    -h|--help)
      usage
      exit 0
      ;;
    --fix)
      fix=true
      shift
      ;;
    --repo-only)
      repo_only=true
      shift
      ;;
    *)
      printf 'Error: unknown option: %s\n' "$1" >&2
      exit 2
      ;;
  esac
done

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
home="${HOME:-}"

paths=(
  "$repo_dir/.env"
  "$repo_dir/.env.production"
  "$repo_dir/.env.testing"
  "$repo_dir/mcp-server/.env"
  "$repo_dir/mcp-servers/plos/.env"
  "$repo_dir/storage/oauth-private.key"
  "$repo_dir/storage/oauth-public.key"
)

if [[ "$repo_only" == false && -n "$home" ]]; then
  paths+=(
    "$home/.config/plos/prod1.env"
    "$home/.config/plos/offline-dev.env"
    "$home/.config/plos/mcp-plos.env"
  )

  if [[ -d "$home/.ssh" ]]; then
    while IFS= read -r key_path; do
      paths+=("$key_path")
    done < <(
      find "$home/.ssh" -maxdepth 1 -type f \
        ! -name '*.pub' \
        ! -name 'known_hosts*' \
        ! -name 'config' \
        -print | sort
    )
  fi
fi

status=0

for path in "${paths[@]}"; do
  if [[ ! -e "$path" ]]; then
    printf 'skip missing path=%s\n' "$path"
    continue
  fi

  if [[ ! -f "$path" ]]; then
    printf 'warn non_file path=%s\n' "$path"
    status=1
    continue
  fi

  mode="$(stat -c '%a' "$path")"
  if (( (8#$mode) & 0177 )); then
    if [[ "$fix" == true ]]; then
      chmod 600 "$path"
      mode="$(stat -c '%a' "$path")"
      printf 'fixed mode=%s path=%s\n' "$mode" "$path"
    else
      printf 'fail mode=%s path=%s\n' "$mode" "$path"
      status=1
    fi
  else
    printf 'ok mode=%s path=%s\n' "$mode" "$path"
  fi
done

exit "$status"
