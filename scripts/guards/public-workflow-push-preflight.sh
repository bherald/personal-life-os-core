#!/usr/bin/env bash
set -euo pipefail

host="${GH_AUTH_AUDIT_HOST:-github.com}"
base_ref=""

usage() {
    printf 'Usage: %s [--host github.com] [--base REF]\n' "$0"
    printf '\n'
    printf 'Preflight guard for public GitHub pushes. If .github/workflows files\n'
    printf 'changed in the pending public push surface, require a session-scoped\n'
    printf 'GH_TOKEN/GITHUB_TOKEN with workflow scope via github-auth-storage-audit.\n'
}

validate_host() {
    if [[ -z "$host" || "$host" == -* ]]; then
        printf 'FAIL: GitHub host must be a hostname, not an empty or option-like value.\n' >&2
        usage >&2
        exit 2
    fi

    if [[ "$host" == *"://"* || "$host" == */* || "$host" == *[[:space:]]* || "$host" == *..* ]]; then
        printf 'FAIL: GitHub host must be a bare hostname, not a URL, path, or spaced value.\n' >&2
        usage >&2
        exit 2
    fi

    if [[ ! "$host" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*[A-Za-z0-9]$ ]]; then
        printf 'FAIL: GitHub host must be a hostname with only letters, numbers, dots, and hyphens.\n' >&2
        usage >&2
        exit 2
    fi

    local label
    local -a host_labels
    IFS='.' read -r -a host_labels <<< "$host"
    for label in "${host_labels[@]}"; do
        if [[ -z "$label" || "$label" == -* || "$label" == *- ]]; then
            printf 'FAIL: GitHub host labels must not be empty or start/end with hyphens.\n' >&2
            usage >&2
            exit 2
        fi
    done
}

add_path() {
    local path="$1"

    if [[ "$path" == .github/workflows/* ]]; then
        workflow_paths["$path"]=1
    fi
}

collect_diff_paths() {
    local path

    while IFS= read -r -d '' path; do
        add_path "$path"
    done < <(git diff -z --name-only -- .github/workflows 2>/dev/null || true)

    while IFS= read -r -d '' path; do
        add_path "$path"
    done < <(git diff -z --name-only --cached -- .github/workflows 2>/dev/null || true)

    while IFS= read -r -d '' path; do
        add_path "$path"
    done < <(git ls-files -z --others --exclude-standard -- .github/workflows 2>/dev/null || true)
}

collect_commit_paths() {
    local compare_ref="$1"
    local before_count
    local path

    if [[ -z "$compare_ref" ]]; then
        return
    fi

    before_count="${#workflow_paths[@]}"
    while IFS= read -r -d '' path; do
        add_path "$path"
    done < <(git diff -z --name-only "$compare_ref"...HEAD -- .github/workflows 2>/dev/null || true)

    if [[ "${#workflow_paths[@]}" -eq "$before_count" ]]; then
        while IFS= read -r -d '' path; do
            add_path "$path"
        done < <(git diff -z --name-only "$compare_ref" HEAD -- .github/workflows 2>/dev/null || true)
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            usage
            exit 0
            ;;
        --host)
            if [[ -z "${2:-}" || "${2:-}" == -* ]]; then
                usage >&2
                exit 2
            fi
            host="$2"
            shift 2
            ;;
        --base)
            if [[ -z "${2:-}" || "${2:-}" == -* ]]; then
                usage >&2
                exit 2
            fi
            base_ref="$2"
            shift 2
            ;;
        *)
            usage >&2
            exit 2
            ;;
    esac
done

validate_host

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
auth_guard="$script_dir/github-auth-storage-audit.sh"

if [[ ! -x "$auth_guard" ]]; then
    printf 'FAIL: required auth guard is missing or not executable: %s\n' "$auth_guard" >&2
    exit 1
fi

if ! git_root="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    printf 'FAIL: public workflow preflight must run inside a git repository.\n' >&2
    exit 2
fi

cd "$git_root"

if [[ -n "$base_ref" ]] && ! git rev-parse --verify --quiet "$base_ref^{commit}" >/dev/null; then
    printf 'FAIL: --base must resolve to a commit before workflow preflight can compare the public push surface.\n' >&2
    exit 2
fi

declare -A workflow_paths=()

collect_diff_paths

if [[ -n "$base_ref" ]]; then
    collect_commit_paths "$base_ref"
elif upstream_ref="$(git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null)"; then
    collect_commit_paths "$upstream_ref"
elif git rev-parse --verify HEAD >/dev/null 2>&1; then
    # First-push posture: compare the current public tree to an empty tree so
    # workflow files in the initial public commit require an appropriate token.
    collect_commit_paths "4b825dc642cb6eb9a060e54bf8d69288fbee4904"
fi

if [[ "${#workflow_paths[@]}" -eq 0 ]]; then
    printf 'OK: no .github/workflows changes detected; workflow scope is not required for this push surface.\n'
    exit 0
fi

printf 'INFO: detected .github/workflows changes requiring GitHub workflow scope:\n'
printf '%s\n' "${!workflow_paths[@]}" | sort | sed 's/^/- /'
printf 'INFO: verifying session-scoped GitHub token posture before push.\n'

"$auth_guard" --allow-plaintext --require-session-token --require-workflow-scope --host "$host"
