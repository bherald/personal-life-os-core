#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
temp_root="${PLOS_PUBLIC_TEMP_ROOT:-$HOME/tmp}"
execute=false
keep_latest=1

usage() {
    cat <<'USAGE'
Usage: scripts/guards/public-temp-artifact-cleanup.sh [options]

Dry-run-first cleanup for generated public release temp trees.

Targets only top-level directories matching:
  personal-life-os-core-export-*
  personal-life-os-core-smoke-*

Options:
  --root DIR          Temp root to scan. Default: $PLOS_PUBLIC_TEMP_ROOT or ~/tmp.
  --keep-latest N    Keep newest N directories per artifact kind. Default: 1.
  --dry-run           Scan and report only. This is also the default mode.
  --execute          Delete selected generated temp trees.
  -h, --help         Show this help text.

The public sync clone and first-push repo names are intentionally not matched.
USAGE
}

while (($#)); do
    case "$1" in
        --root)
            if [[ -z "${2:-}" ]]; then
                usage >&2
                exit 2
            fi
            temp_root="$2"
            shift 2
            ;;
        --keep-latest)
            if [[ -z "${2:-}" || ! "$2" =~ ^[0-9]+$ ]]; then
                usage >&2
                exit 2
            fi
            keep_latest="$2"
            shift 2
            ;;
        --execute)
            execute=true
            shift
            ;;
        --dry-run)
            execute=false
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            usage >&2
            exit 2
            ;;
    esac
done

temp_root="$(realpath -m "$temp_root")"

case "$temp_root" in
    /|"")
        printf 'Refusing to scan dangerous temp root: %s\n' "$temp_root" >&2
        exit 2
        ;;
    "$ROOT"|"$ROOT"/*)
        printf 'Refusing to scan inside private source repository: %s\n' "$temp_root" >&2
        exit 2
        ;;
esac

if [[ ! -d "$temp_root" ]]; then
    printf 'Temp root does not exist: %s\n' "$temp_root" >&2
    exit 2
fi

mode="dry-run"
if [[ "$execute" == true ]]; then
    mode="execute"
fi

printf 'Public temp artifact cleanup\n'
printf 'Mode: %s\n' "$mode"
printf 'Root: %s\n' "$temp_root"
printf 'Keep latest per kind: %s\n' "$keep_latest"

declare -A seen_by_kind=()
delete_paths=()
candidate_count=0
delete_count=0
delete_kib=0

while IFS= read -r entry; do
    [[ -n "$entry" ]] || continue

    path="${entry#* }"
    name="$(basename "$path")"
    kind="unknown"

    case "$name" in
        personal-life-os-core-export-*)
            kind="export"
            ;;
        personal-life-os-core-smoke-*)
            kind="smoke"
            ;;
        *)
            continue
            ;;
    esac

    seen_by_kind[$kind]=$(( ${seen_by_kind[$kind]:-0} + 1 ))
    candidate_count=$((candidate_count + 1))
    size_kib="$(du -sk "$path" 2>/dev/null | awk '{print $1}')"
    size_kib="${size_kib:-0}"

    if (( seen_by_kind[$kind] <= keep_latest )); then
        printf 'keep kind=%s size_kib=%s path=%s\n' "$kind" "$size_kib" "$path"
        continue
    fi

    printf 'delete_candidate kind=%s size_kib=%s path=%s\n' "$kind" "$size_kib" "$path"
    delete_paths+=("$path")
    delete_count=$((delete_count + 1))
    delete_kib=$((delete_kib + size_kib))
done < <(
    find "$temp_root" \
        -mindepth 1 \
        -maxdepth 1 \
        -type d \
        \( -name 'personal-life-os-core-export-*' -o -name 'personal-life-os-core-smoke-*' \) \
        -printf '%T@ %p\n' \
        | sort -rn
)

if [[ "$execute" == true ]]; then
    for path in "${delete_paths[@]}"; do
        rm -rf -- "$path"
    done
fi

printf 'Summary: candidates=%s delete_candidates=%s reclaimable_kib=%s\n' \
    "$candidate_count" \
    "$delete_count" \
    "$delete_kib"

if [[ "$execute" != true && "$delete_count" -gt 0 ]]; then
    printf 'Dry-run only. Re-run with --execute to delete selected generated temp trees.\n'
fi
