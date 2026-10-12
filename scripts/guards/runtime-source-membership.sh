#!/bin/bash

set -euo pipefail

ROOT="."
EXPECTED0="-"
JSON=false
COMPACT=false
FAIL_ON_DRIFT=false

usage() {
    cat <<'EOF'
Usage: runtime-source-membership.sh [options]

Compare deployed PHP runtime-source membership with a NUL-delimited tracked
manifest. This command is read-only and never removes or modifies source files.

Options:
  --root=PATH          Laravel checkout root (default: current directory)
  --expected0=PATH     NUL-delimited expected paths, or - for stdin
  --json               Emit JSON
  --compact            Emit one-line JSON (requires --json)
  --fail-on-drift      Exit 1 when missing or extra runtime sources exist
  --help               Show this help
EOF
}

for argument in "$@"; do
    case "$argument" in
        --root=*) ROOT="${argument#*=}" ;;
        --expected0=*) EXPECTED0="${argument#*=}" ;;
        --json) JSON=true ;;
        --compact) COMPACT=true ;;
        --fail-on-drift) FAIL_ON_DRIFT=true ;;
        --help) usage; exit 0 ;;
        *) echo "Unknown option: $argument" >&2; usage >&2; exit 2 ;;
    esac
done

if [ "$COMPACT" = true ] && [ "$JSON" != true ]; then
    echo "--compact requires --json" >&2
    exit 2
fi

if [ ! -f "$ROOT/artisan" ] || [ ! -d "$ROOT/app" ] || [ ! -d "$ROOT/routes" ]; then
    echo "Root must contain artisan, app/, and routes/: $ROOT" >&2
    exit 2
fi

TEMP_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/plos-runtime-source-membership.XXXXXX")
cleanup() {
    find "$TEMP_ROOT" -maxdepth 1 -type f -delete 2>/dev/null || true
    rmdir "$TEMP_ROOT" 2>/dev/null || true
}
trap cleanup EXIT

RAW_EXPECTED="$TEMP_ROOT/expected-raw.bin"
EXPECTED="$TEMP_ROOT/expected.bin"
ACTUAL="$TEMP_ROOT/actual.bin"
MISSING="$TEMP_ROOT/missing.bin"
EXTRA="$TEMP_ROOT/extra.bin"

if [ "$EXPECTED0" = "-" ]; then
    cat > "$RAW_EXPECTED"
elif [ -f "$EXPECTED0" ]; then
    cat "$EXPECTED0" > "$RAW_EXPECTED"
else
    echo "Expected manifest is not a regular file: $EXPECTED0" >&2
    exit 2
fi

if [ ! -s "$RAW_EXPECTED" ]; then
    echo "Expected manifest is empty" >&2
    exit 2
fi

INVALID_EXPECTED_PATH=false
while IFS= read -r -d '' path; do
    case "$path" in
        /*|../*|*/../*|*/..)
            INVALID_EXPECTED_PATH=true
            ;;
        app/*.php|routes/*.php)
            printf '%s\0' "$path" >> "$EXPECTED"
            ;;
        app/*|routes/*)
            ;;
        *)
            INVALID_EXPECTED_PATH=true
            ;;
    esac
done < "$RAW_EXPECTED"

if [ "$INVALID_EXPECTED_PATH" = true ]; then
    echo "Expected manifest contains a path outside app/ or routes/" >&2
    exit 2
fi

if [ ! -s "$EXPECTED" ]; then
    echo "Expected manifest contains no PHP runtime-source paths" >&2
    exit 2
fi

LC_ALL=C sort -z -u "$EXPECTED" -o "$EXPECTED"
(
    cd "$ROOT"
    find app routes -xdev -type f -name '*.php' -print0 | LC_ALL=C sort -z -u
) > "$ACTUAL"

LC_ALL=C comm -z -23 "$EXPECTED" "$ACTUAL" > "$MISSING"
LC_ALL=C comm -z -13 "$EXPECTED" "$ACTUAL" > "$EXTRA"

count_paths() {
    tr -cd '\000' < "$1" | wc -c | tr -d '[:space:]'
}

digest_paths() {
    sha256sum "$1" | awk '{print $1}'
}

EXPECTED_COUNT=$(count_paths "$EXPECTED")
ACTUAL_COUNT=$(count_paths "$ACTUAL")
MISSING_COUNT=$(count_paths "$MISSING")
EXTRA_COUNT=$(count_paths "$EXTRA")
EXPECTED_DIGEST=$(digest_paths "$EXPECTED")
ACTUAL_DIGEST=$(digest_paths "$ACTUAL")
MISSING_DIGEST=$(digest_paths "$MISSING")
EXTRA_DIGEST=$(digest_paths "$EXTRA")

STATUS="pass"
if [ "$MISSING_COUNT" -gt 0 ] || [ "$EXTRA_COUNT" -gt 0 ]; then
    STATUS="fail"
fi

if [ "$JSON" = true ]; then
    if [ "$COMPACT" = true ]; then
        printf '{"schema":"plos.runtime_source_membership_audit.v1","status":"%s","mode":"read_only","expected_files":%d,"actual_files":%d,"missing_files":%d,"extra_files":%d,"expected_manifest_sha256":"%s","actual_manifest_sha256":"%s","missing_manifest_sha256":"%s","extra_manifest_sha256":"%s","path_details_returned":false,"writes_files":false,"deletes_files":false}\n' \
            "$STATUS" "$EXPECTED_COUNT" "$ACTUAL_COUNT" "$MISSING_COUNT" "$EXTRA_COUNT" \
            "$EXPECTED_DIGEST" "$ACTUAL_DIGEST" "$MISSING_DIGEST" "$EXTRA_DIGEST"
    else
        cat <<EOF
{
  "schema": "plos.runtime_source_membership_audit.v1",
  "status": "$STATUS",
  "mode": "read_only",
  "scope": ["app_php", "routes_php"],
  "expected_files": $EXPECTED_COUNT,
  "actual_files": $ACTUAL_COUNT,
  "missing_files": $MISSING_COUNT,
  "extra_files": $EXTRA_COUNT,
  "expected_manifest_sha256": "$EXPECTED_DIGEST",
  "actual_manifest_sha256": "$ACTUAL_DIGEST",
  "missing_manifest_sha256": "$MISSING_DIGEST",
  "extra_manifest_sha256": "$EXTRA_DIGEST",
  "path_details_returned": false,
  "writes_files": false,
  "deletes_files": false
}
EOF
    fi
else
    printf 'Runtime source membership: %s expected=%d actual=%d missing=%d extra=%d\n' \
        "$(printf '%s' "$STATUS" | tr '[:lower:]' '[:upper:]')" \
        "$EXPECTED_COUNT" "$ACTUAL_COUNT" "$MISSING_COUNT" "$EXTRA_COUNT"
fi

if [ "$FAIL_ON_DRIFT" = true ] && [ "$STATUS" != "pass" ]; then
    exit 1
fi

exit 0
