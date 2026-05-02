#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"

failures=0

info() {
    printf 'INFO: %s\n' "$*"
}

fail() {
    failures=$((failures + 1))
    printf 'FAIL: %s\n' "$*" >&2
}

require_file() {
    local path="$1"

    if [[ -f "$path" ]]; then
        info "found $path"
    else
        fail "missing required provenance file: $path"
    fi
}

require_mention() {
    local term="$1"
    shift

    if rg -F -q "$term" "$@"; then
        info "documented watch item: $term"
    else
        fail "watch item is not documented in provenance docs: $term"
    fi
}

require_file THIRD_PARTY.md
require_file NOTICE.md
require_file docs/research-provenance.md
require_file docs/model-runtime-license-map.md
require_file docs/native-ml-package-review.md
require_file docs/python-constraints-license-snapshot.md
require_file docs/public-release/npm-license-snapshot.json
require_file docs/public-release/npm-license-snapshot.md
require_file docs/public-release/python-license-snapshot-core.json
require_file docs/public-release/python-license-snapshot-core.md
require_file tests/Fixtures/PROVENANCE.md

notice_docs=(
    NOTICE.md
    THIRD_PARTY.md
    docs/python-constraints-license-snapshot.md
    docs/native-ml-package-review.md
)

for term in \
    'phpoffice/phpword' \
    'smalot/pdfparser' \
    'tecnickcom/tcpdf' \
    'mrmysql/youtube-transcript' \
    'argparse' \
    'caniuse-lite' \
    'd3-flextree' \
    'psycopg2-binary' \
    'igraph' \
    'leidenalg'
do
    require_mention "$term" "${notice_docs[@]}"
done

for term in \
    'Gramps' \
    'PhotoPrism' \
    'LibrePhotos' \
    'Joplin' \
    'XMP' \
    'IPTC' \
    'FamilySearch GEDCOM'
do
    require_mention "$term" THIRD_PARTY.md docs/research-provenance.md docs/public-release-readiness.md
done

if [[ -f artisan && -f vendor/autoload.php ]]; then
    php artisan test \
        tests/Feature/Quality/FixturesProvenanceTest.php \
        tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php
else
    info "skipping PHPUnit provenance tests because vendor/autoload.php is not installed"
fi

if ((failures > 0)); then
    printf 'FAIL: dependency provenance check found %d issue(s)\n' "$failures" >&2
    exit 1
fi

printf 'PASS: dependency provenance check completed.\n'
