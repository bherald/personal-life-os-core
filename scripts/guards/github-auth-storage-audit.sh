#!/usr/bin/env bash
set -euo pipefail

allow_plaintext=false
require_session_token=false
host="${GH_AUTH_AUDIT_HOST:-github.com}"
gh_available=false

usage() {
    printf 'Usage: %s [--allow-plaintext] [--require-session-token] [--host github.com]\n' "$0"
    printf '\n'
    printf 'Audits GitHub CLI auth storage without printing token values.\n'
    printf 'Target posture: use session-scoped GH_TOKEN/GITHUB_TOKEN for CLI/API work.\n'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            usage
            exit 0
            ;;
        --allow-plaintext)
            allow_plaintext=true
            shift
            ;;
        --require-session-token)
            require_session_token=true
            shift
            ;;
        --host)
            if [[ -z "${2:-}" ]]; then
                usage >&2
                exit 2
            fi
            host="$2"
            shift 2
            ;;
        *)
            usage >&2
            exit 2
            ;;
    esac
done

config_home="${XDG_CONFIG_HOME:-$HOME/.config}"
gh_config_dir="${GH_CONFIG_DIR:-$config_home/gh}"
hosts_file="$gh_config_dir/hosts.yml"
status_file="$(mktemp "${TMPDIR:-/tmp}/plos-gh-auth-status.XXXXXX")"
session_config_dir=""
exit_code=0

cleanup() {
    rm -f "$status_file"
    if [[ -n "$session_config_dir" ]]; then
        rm -rf "$session_config_dir"
    fi
}
trap cleanup EXIT

printf '== GitHub CLI Auth Storage Audit ==\n'
printf 'INFO: GitHub host: %s\n' "$host"
printf 'INFO: Target posture: use session-scoped GH_TOKEN/GITHUB_TOKEN for GitHub CLI/API work; persistent gh auth is transitional only.\n'

if command -v gh >/dev/null 2>&1; then
    gh_available=true
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
    printf 'OK: session-scoped GH_TOKEN/GITHUB_TOKEN is present and should be preferred for CLI/API work.\n'
    if [[ "$require_session_token" == "true" && "$gh_available" == "true" ]]; then
        session_config_dir="$(mktemp -d "${TMPDIR:-/tmp}/plos-gh-auth-session.XXXXXX")"
        printf 'INFO: Verifying session-scoped token with an isolated GitHub CLI config.\n'
        if GH_CONFIG_DIR="$session_config_dir" gh auth status -h "$host" >"$status_file" 2>&1; then
            sed -E 's/(Token: ).*/\1[redacted]/; s/^/session-gh: /' "$status_file"
            printf 'OK: session-scoped GH_TOKEN/GITHUB_TOKEN can authenticate gh without persistent hosts.yml.\n'
        else
            sed -E 's/(Token: ).*/\1[redacted]/; s/^/session-gh: /' "$status_file"
            printf 'FAIL: session-scoped GH_TOKEN/GITHUB_TOKEN did not authenticate gh with isolated config.\n'
            exit_code=1
        fi
    fi
elif [[ "$require_session_token" == "true" ]]; then
    printf 'FAIL: no session-scoped GH_TOKEN/GITHUB_TOKEN is present.\n'
    exit_code=1
else
    printf 'INFO: no session-scoped GH_TOKEN/GITHUB_TOKEN is present; current persistent gh auth should be treated as a temporary release-management bridge.\n'
fi

if [[ ! -f "$hosts_file" ]]; then
    printf 'OK: no GitHub CLI hosts.yml file found at %s; no persistent gh auth file to inspect.\n' "$hosts_file"
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
    printf 'WARN: plaintext GitHub CLI token key is present in hosts.yml; prefer session-scoped GH_TOKEN/GITHUB_TOKEN for new CLI/API work.\n'
    if [[ "$allow_plaintext" == "true" ]]; then
        printf 'INFO: --allow-plaintext supplied; keeping exit success for the operator-approved transitional release-management session.\n'
    else
        exit_code=1
    fi
else
    printf 'OK: no plaintext GitHub CLI token key found in hosts.yml.\n'
fi

exit "$exit_code"
