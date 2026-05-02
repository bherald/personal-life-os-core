#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"

usage() {
    cat <<'USAGE'
Usage: scripts/guards/production-fix-commit-message-check.sh [--range <base..head>]

Fail when a fix: commit touches production-behavior paths without the required
commit-body sections documented in CONTRIBUTING.md and .gitmessage.

Required headings:
  Root cause:
  Behavior changed:
  Verification:
  Deployment/rollback:
USAGE
}

range=""

while (($#)); do
    case "$1" in
        --range)
            if (($# < 2)); then
                printf 'Missing value for --range.\n' >&2
                exit 2
            fi
            range="$2"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown argument: %s\n\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
    shift
done

is_zero_sha() {
    [[ "$1" =~ ^0{40}$ ]]
}

if [[ -z "$range" ]]; then
    event="${PLOS_GOVERNANCE_EVENT:-${GITHUB_EVENT_NAME:-}}"
    head="${PLOS_GOVERNANCE_HEAD:-${GITHUB_SHA:-HEAD}}"
    pr_base="${PLOS_GOVERNANCE_PR_BASE:-}"
    push_before="${PLOS_GOVERNANCE_PUSH_BEFORE:-}"

    if [[ "$event" == pull_request* && -n "$pr_base" ]]; then
        range="${pr_base}..${head}"
    elif [[ -n "$push_before" ]] && ! is_zero_sha "$push_before"; then
        range="${push_before}..${head}"
    elif git rev-parse --verify -q "${head}^" >/dev/null; then
        range="${head}^..${head}"
    else
        range="$head"
    fi
fi

is_fix_subject() {
    local subject="$1"

    grep -Eq '^fix(\([^)]+\))?!?:[[:space:]]*' <<< "$subject"
}

is_production_path() {
    local path="$1"

    case "$path" in
        .env.example|.env.testing.example|artisan|composer.json|composer.lock|docker-compose.yml|docker-compose.*.yml|package.json|package-lock.json|postcss.config.js|tailwind.config.js|vite.config.js)
            return 0
            ;;
        app/*|bootstrap/*|config/*|database/*|docker/*|mcp-server/src/*|mcp-servers/plos/src/*|public/index.php|resources/css/*|resources/js/*|resources/views/*|routes/*|scripts/*)
            return 0
            ;;
        mcp-server/package.json|mcp-server/package-lock.json|mcp-server/tsconfig.json|mcp-servers/plos/package.json|mcp-servers/plos/package-lock.json|mcp-servers/plos/tsconfig.json)
            return 0
            ;;
    esac

    return 1
}

missing_required_headings() {
    local message="$1"
    local missing=()

    for heading in \
        'Root cause:' \
        'Behavior changed:' \
        'Verification:' \
        'Deployment/rollback:'
    do
        if ! grep -Eiq "^${heading}[[:space:]]*$" <<< "$message"; then
            missing+=("$heading")
        fi
    done

    if ((${#missing[@]} > 0)); then
        printf '%s\n' "${missing[@]}"
    fi
}

mapfile -t commits < <(git rev-list --no-merges --reverse "$range")

failures=0

for commit in "${commits[@]}"; do
    subject="$(git log -1 --format=%s "$commit")"

    if ! is_fix_subject "$subject"; then
        continue
    fi

    mapfile -t changed_files < <(git diff-tree --root --no-commit-id --name-only -r "$commit")
    production_files=()
    for path in "${changed_files[@]}"; do
        if is_production_path "$path"; then
            production_files+=("$path")
        fi
    done

    if ((${#production_files[@]} == 0)); then
        continue
    fi

    message="$(git log -1 --format=%B "$commit")"
    mapfile -t missing < <(missing_required_headings "$message")

    if ((${#missing[@]} == 0)); then
        continue
    fi

    failures=$((failures + 1))
    printf 'FAIL: %s %s\n' "$(git rev-parse --short "$commit")" "$subject" >&2
    printf '  touches production-behavior paths:\n' >&2
    printf '    %s\n' "${production_files[@]}" >&2
    printf '  missing commit-body headings:\n' >&2
    printf '    %s\n' "${missing[@]}" >&2
done

if ((failures > 0)); then
    printf 'FAIL: %d production fix commit(s) need governance body sections.\n' "$failures" >&2
    exit 1
fi

printf 'PASS: production fix commit-message check completed for %s.\n' "$range"
