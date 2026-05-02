#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"

TIER="core"
VENV=""
INSTALL=0
CHECK=0

usage() {
    cat <<'USAGE'
Usage: scripts/snapshot-python-licenses.sh [options]

Generate a public-release Python dependency license snapshot for one tier.

Options:
  --tier=core|media|gpu   Dependency tier to snapshot. Default: core.
  --venv=PATH             Existing virtualenv to inspect. Default: .venv.
  --install               Create a temporary venv and install the tier first.
  --check                 Recompute and compare with committed snapshot.
  -h, --help              Show this help.

The script writes docs/public-release/python-license-snapshot-<tier>.json and
docs/public-release/python-license-snapshot-<tier>.md. It writes metadata only:
no wheels, model files, caches, private paths, or package archives.
USAGE
}

while (($#)); do
    case "$1" in
        --tier=*)
            TIER="${1#*=}"
            ;;
        --tier)
            shift
            TIER="${1:-}"
            ;;
        --venv=*)
            VENV="${1#*=}"
            ;;
        --venv)
            shift
            VENV="${1:-}"
            ;;
        --install)
            INSTALL=1
            ;;
        --check)
            CHECK=1
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown option: %s\n\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
    shift
done

case "$TIER" in
    core|media|gpu) ;;
    *)
        printf 'Invalid tier: %s\n' "$TIER" >&2
        exit 2
        ;;
esac

REQ="requirements-${TIER}.txt"
CONSTRAINTS="requirements-${TIER}.constraints.txt"
OUT_DIR="docs/public-release"
OUT_JSON="$OUT_DIR/python-license-snapshot-${TIER}.json"
OUT_MD="$OUT_DIR/python-license-snapshot-${TIER}.md"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

if [[ ! -f "$REQ" || ! -f "$CONSTRAINTS" ]]; then
    printf 'Missing %s or %s\n' "$REQ" "$CONSTRAINTS" >&2
    exit 1
fi

mkdir -p "$OUT_DIR"

if ((INSTALL)); then
    VENV="$TMP_DIR/venv-${TIER}"
    python3 -m venv "$VENV"
    "$VENV/bin/python" -m pip install --upgrade pip >/dev/null
    "$VENV/bin/python" -m pip install -c "$CONSTRAINTS" -r "$REQ"
elif [[ -z "$VENV" ]]; then
    VENV=".venv"
fi

PYTHON="$VENV/bin/python"
if [[ ! -x "$PYTHON" ]]; then
    printf 'Virtualenv python not found: %s\n' "$PYTHON" >&2
    printf 'Use --install or --venv=PATH.\n' >&2
    exit 1
fi

if ((CHECK)) && [[ -f "$OUT_JSON" ]]; then
    export PY_LICENSE_SNAPSHOT_GENERATED_AT
    PY_LICENSE_SNAPSHOT_GENERATED_AT="$("$PYTHON" - "$OUT_JSON" <<'PY'
import json
import sys

with open(sys.argv[1], encoding='utf-8') as fh:
    print(json.load(fh).get('generated_at', ''), end='')
PY
)"
fi

"$PYTHON" - "$TIER" "$REQ" "$CONSTRAINTS" "$TMP_DIR/snapshot.json" "$TMP_DIR/snapshot.md" <<'PY'
from __future__ import annotations

import datetime as dt
import importlib.metadata as metadata
import json
import os
import re
import sys
from pathlib import Path

tier, requirements, constraints, out_json, out_md = sys.argv[1:6]


def canonicalize_name(name: str) -> str:
    return re.sub(r'[-_.]+', '-', name).lower()


def read_constraints(path: str) -> list[dict[str, str]]:
    rows = []
    for raw in Path(path).read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or line.startswith('-'):
            continue
        match = re.match(r'^([A-Za-z0-9_.-]+)==([^;\s]+)', line)
        if not match:
            continue
        rows.append({
            'name': match.group(1),
            'normalized_name': canonicalize_name(match.group(1)),
            'pinned_version': match.group(2),
        })
    return rows


def metadata_for(normalized: str):
    for dist in metadata.distributions():
        name = dist.metadata.get('Name') or ''
        if canonicalize_name(name) == normalized:
            return dist
    return None


def license_text(meta) -> str:
    if meta is None:
        return ''
    expression = (meta.metadata.get('License-Expression') or '').strip()
    if expression and expression.upper() != 'UNKNOWN':
        return expression
    value = (meta.metadata.get('License') or '').strip()
    if value and value.upper() not in {'UNKNOWN', 'UNKNOWN\n'}:
        return value
    classifiers = [
        c.split('::')[-1].strip()
        for c in meta.metadata.get_all('Classifier', [])
        if c.strip().startswith('License ::')
    ]
    return ' OR '.join(dict.fromkeys(classifiers))


def project_urls(meta) -> dict[str, str]:
    if meta is None:
        return {}
    urls = {}
    for value in meta.metadata.get_all('Project-URL', []) or []:
        if ',' not in value:
            continue
        label, url = value.split(',', 1)
        urls[label.strip()] = url.strip()
    home = (meta.metadata.get('Home-page') or '').strip()
    if home and 'Home-page' not in urls:
        urls['Home-page'] = home
    return urls


def bucket_for(text: str) -> str:
    if not text:
        return 'missing'
    lower = text.lower()
    if 'agpl' in lower:
        return 'agpl'
    if re.search(r'\bgpl\b|gpl-\d', text, re.I) and 'lgpl' not in lower:
        return 'gpl'
    if 'lgpl' in lower:
        return 'lgpl'
    if re.search(r'MPL|CC-BY|NVIDIA|proprietary', text, re.I):
        return 'watch'
    if re.search(r'\b(MIT|MIT-CMU|BSD|Apache|ISC|0BSD|Unlicense|CC0|Zlib|Boost Software License|Python Software Foundation)\b', text, re.I):
        return 'permissive'
    return 'other'


def markdown_cell(value: str, limit: int = 180) -> str:
    value = re.sub(r'\s+', ' ', value or '').strip()
    value = value.replace('|', '\\|')
    if len(value) > limit:
        return value[:limit - 1].rstrip() + '...'
    return value


generated_at = os.environ.get('PY_LICENSE_SNAPSHOT_GENERATED_AT') or dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z')
rows = []
buckets: dict[str, int] = {}

for item in read_constraints(constraints):
    dist = metadata_for(item['normalized_name'])
    text = license_text(dist)
    bucket = bucket_for(text)
    row = {
        'name': dist.metadata.get('Name') if dist else item['name'],
        'normalized_name': item['normalized_name'],
        'pinned_version': item['pinned_version'],
        'installed_version': dist.version if dist else '',
        'license': text,
        'license_bucket': bucket,
        'summary': (dist.metadata.get('Summary') or '').strip() if dist else '',
        'project_urls': project_urls(dist),
        'metadata_source': 'installed_metadata' if dist else 'constraints_only',
    }
    rows.append(row)
    buckets[bucket] = buckets.get(bucket, 0) + 1

rows.sort(key=lambda r: r['normalized_name'])

payload = {
    'schema_version': 1,
    'generated_at': generated_at,
    'tier': tier,
    'requirements': requirements,
    'constraints': constraints,
    'python_version': f'{sys.version_info.major}.{sys.version_info.minor}',
    'source': 'installed virtualenv metadata plus constraints pins',
    'package_count': len(rows),
    'buckets': buckets,
    'packages': rows,
}

Path(out_json).write_text(json.dumps(payload, indent=2) + '\n', encoding='utf-8')

md = [
    f'# Python License Snapshot - {tier}',
    '',
    f'Generated: {generated_at}',
    '',
    f'Requirements: `{requirements}`',
    f'Constraints: `{constraints}`',
    '',
    'This snapshot is generated from installed virtualenv package metadata and pinned constraints. It is engineering release-diligence evidence, not legal advice.',
    '',
    '## Summary',
    '',
    '| Packages | Permissive | Watch | LGPL | GPL | AGPL | Missing | Other |',
    '|---:|---:|---:|---:|---:|---:|---:|---:|',
    f'| {len(rows)} | {buckets.get("permissive", 0)} | {buckets.get("watch", 0)} | {buckets.get("lgpl", 0)} | {buckets.get("gpl", 0)} | {buckets.get("agpl", 0)} | {buckets.get("missing", 0)} | {buckets.get("other", 0)} |',
    '',
    '## Watch And Unresolved Packages',
    '',
    '| Package | Pinned | Installed | License | Bucket | Metadata Source |',
    '|---|---:|---:|---|---|---|',
]

watch_count = 0
for row in rows:
    if row['license_bucket'] not in {'watch', 'lgpl', 'gpl', 'agpl', 'missing', 'other'}:
        continue
    watch_count += 1
    md.append(f'| {row["name"]} | {row["pinned_version"]} | {row["installed_version"]} | {markdown_cell(row["license"] or "MISSING")} | {row["license_bucket"]} | {row["metadata_source"]} |')

if watch_count == 0:
    md.append('| none |  |  |  |  |  |')

md.extend([
    '',
    'Keep `scripts/audit-licenses.sh` as the gate. This file only gives the audit richer Python package metadata.',
])

Path(out_md).write_text('\n'.join(md) + '\n', encoding='utf-8')
PY

if ((CHECK)); then
    if [[ ! -f "$OUT_JSON" || ! -f "$OUT_MD" ]]; then
        printf 'Python license snapshot files are missing for tier %s; run scripts/snapshot-python-licenses.sh --tier=%s\n' "$TIER" "$TIER" >&2
        exit 1
    fi

    diff -u "$OUT_JSON" "$TMP_DIR/snapshot.json"
    diff -u "$OUT_MD" "$TMP_DIR/snapshot.md"
    printf 'Python %s license snapshot is current.\n' "$TIER"
else
    cp "$TMP_DIR/snapshot.json" "$OUT_JSON"
    cp "$TMP_DIR/snapshot.md" "$OUT_MD"
    printf 'Wrote %s and %s\n' "$OUT_JSON" "$OUT_MD"
fi
