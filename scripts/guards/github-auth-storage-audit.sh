#!/usr/bin/env bash
set -euo pipefail

allow_plaintext=false
host="${GH_AUTH_AUDIT_HOST:-github.com}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --allow-plaintext)
            allow_plaintext=true
            shift
            ;;
        --host)
            if [[ -z "${2:-}" ]]; then
                printf 'Usage: %s [--allow-plaintext] [--host github.com]\n' "$0" >&2
                exit 2
            fi
            host="$2"
            shift 2
            ;;
        *)
            printf 'Usage: %s [--allow-plaintext] [--host github.com]\n' "$0" >&2
            exit 2
            ;;
    esac
done

config_home="${XDG_CONFIG_HOME:-$HOME/.config}"
gh_config_dir="${GH_CONFIG_DIR:-$config_home/gh}"
hosts_file="$gh_config_dir/hosts.yml"
status_file="$(mktemp "${TMPDIR:-/tmp}/plos-gh-auth-status.XXXXXX")"
exit_code=0

cleanup() {
    rm -f "$status_file"
}
trap cleanup EXIT

printf '== GitHub CLI Auth Storage Audit ==\n'
printf 'INFO: GitHub host: %s\n' "$host"

if command -v gh >/dev/null 2>&1; then
    if gh auth status -h "$host" >"$status_file" 2>&1; then
        sed -E 's/(Token: ).*/\1[redacted]/; s/^/gh: /' "$status_file"
    else
        sed -E 's/(Token: ).*/\1[redacted]/; s/^/gh: /' "$status_file"
        exit_code=1
    fi
else
    printf 'WARN: gh is not installed or not on PATH.\n'
    exit_code=1
fi

if [[ -n "${GH_TOKEN:-}" || -n "${GITHUB_TOKEN:-}" ]]; then
    printf 'OK: session-scoped GH_TOKEN/GITHUB_TOKEN is present.\n'
else
    printf 'INFO: no session-scoped GH_TOKEN/GITHUB_TOKEN is present.\n'
fi

if [[ ! -f "$hosts_file" ]]; then
    printf 'OK: no GitHub CLI hosts.yml file found at %s.\n' "$hosts_file"
    exit "$exit_code"
fi

mode="$(stat -c '%a' "$hosts_file" 2>/dev/null || stat -f '%Lp' "$hosts_file" 2>/dev/null || printf 'unknown')"
printf 'INFO: GitHub CLI hosts file: %s\n' "$hosts_file"
printf 'INFO: GitHub CLI hosts file mode: %s\n' "$mode"

if [[ "$mode" != "600" && "$mode" != "unknown" ]]; then
    printf 'WARN: hosts.yml is not mode 600.\n'
    exit_code=1
fi

if grep -Eq '^[[:space:]]*oauth_token:' "$hosts_file"; then
    printf 'WARN: plaintext GitHub CLI token key is present in hosts.yml.\n'
    if [[ "$allow_plaintext" == "true" ]]; then
        printf 'INFO: --allow-plaintext supplied; keeping exit success for transitional release management.\n'
    else
        exit_code=1
    fi
else
    printf 'OK: no plaintext GitHub CLI token key found in hosts.yml.\n'
fi

exit "$exit_code"
