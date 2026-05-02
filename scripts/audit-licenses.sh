#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"

failures=0
warnings=0

info() {
    printf 'INFO: %s\n' "$*"
}

warn() {
    warnings=$((warnings + 1))
    printf 'WARN: %s\n' "$*" >&2
}

fail() {
    failures=$((failures + 1))
    printf 'FAIL: %s\n' "$*" >&2
}

consume_report() {
    local report="$1"
    local level message

    while IFS=$'\t' read -r level message; do
        [[ -z "${level:-}" ]] && continue

        case "$level" in
            INFO) info "$message" ;;
            WARN) warn "$message" ;;
            FAIL) fail "$message" ;;
            *) fail "Unknown audit result level: $level $message" ;;
        esac
    done <<< "$report"
}

python_snapshot_matches_constraints() {
    local constraints="$1"
    local snapshot="$2"

    python3 - "$constraints" "$snapshot" <<'PY'
import json
import re
import sys
from pathlib import Path


def canonicalize(name: str) -> str:
    return re.sub(r'[-_.]+', '-', name).lower()


def constraints_pins(path: str) -> dict[str, str]:
    pins = {}
    for raw in Path(path).read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or line.startswith('-'):
            continue
        match = re.match(r'^([A-Za-z0-9_.-]+)==([^;\s]+)', line)
        if match:
            pins[canonicalize(match.group(1))] = match.group(2)
    return pins


constraints, snapshot = sys.argv[1:3]
payload = json.loads(Path(snapshot).read_text(encoding='utf-8'))
snapshot_pins = {
    row['normalized_name']: row['pinned_version']
    for row in payload.get('packages', [])
    if row.get('normalized_name') and row.get('pinned_version')
}

if payload.get('constraints') != constraints:
    print(f'snapshot constraints field is {payload.get("constraints")!r}, expected {constraints!r}', file=sys.stderr)
    sys.exit(1)

pins = constraints_pins(constraints)
if pins != snapshot_pins:
    missing = sorted(set(pins) - set(snapshot_pins))
    extra = sorted(set(snapshot_pins) - set(pins))
    changed = sorted(name for name in set(pins) & set(snapshot_pins) if pins[name] != snapshot_pins[name])
    print(f'constraint pins differ: missing={missing} extra={extra} changed={changed}', file=sys.stderr)
    sys.exit(1)
PY
}

if ! command -v composer >/dev/null 2>&1; then
    fail "composer is required for PHP dependency license audit"
fi

if ! command -v node >/dev/null 2>&1; then
    fail "node is required for npm lockfile license audit"
fi

if command -v composer >/dev/null 2>&1 && command -v node >/dev/null 2>&1; then
    composer_json="$(mktemp)"
    trap 'rm -f "$composer_json"' EXIT

    if composer licenses --format=json > "$composer_json"; then
        composer_report="$(
            node - "$composer_json" <<'NODE'
const fs = require('fs');

const file = process.argv[2];
const payload = JSON.parse(fs.readFileSync(file, 'utf8'));
const rawDependencies = payload.dependencies || [];
const dependencies = Array.isArray(rawDependencies)
  ? rawDependencies
  : Object.entries(rawDependencies).map(([name, metadata]) => ({ name, ...metadata }));

function emit(level, message) {
  process.stdout.write(`${level}\t${message}\n`);
}

function licensesFor(dependency) {
  const raw = dependency.license ?? dependency.licenses ?? [];
  const values = Array.isArray(raw) ? raw : [raw];

  return values
    .flatMap((value) => String(value).split(/\s*,\s*/))
    .map((value) => value.trim())
    .filter(Boolean);
}

function hasPermissiveAlternative(text) {
  return /\b(MIT|BSD-2-Clause|BSD-3-Clause|Apache-2\.0|ISC|0BSD)\b/i.test(text);
}

let scanned = 0;

for (const dependency of dependencies) {
  scanned += 1;
  const licenses = licensesFor(dependency);
  const text = licenses.join(' OR ') || 'UNKNOWN';
  const lower = text.toLowerCase();
  const name = dependency.name || '(unknown composer package)';

  if (licenses.length === 0 || lower === 'unknown' || lower === 'proprietary') {
    emit('FAIL', `composer ${name} has missing or unknown license metadata`);
    continue;
  }

  if (lower.includes('agpl')) {
    emit('FAIL', `composer ${name} reports ${text}`);
    continue;
  }

  if (/\bgpl\b|gpl-\d/i.test(text) && !hasPermissiveAlternative(text) && !lower.includes('lgpl')) {
    emit('FAIL', `composer ${name} reports ${text}`);
    continue;
  }

  if (lower.includes('lgpl')) {
    emit('WARN', `composer ${name} reports ${text}; keep it as an unmodified dependency and review redistribution obligations`);
  } else if (lower.includes('wtfpl')) {
    emit('WARN', `composer ${name} reports ${text}; nonstandard permissive license`);
  } else if (/\bgpl\b|gpl-\d/i.test(text) && hasPermissiveAlternative(text)) {
    emit('INFO', `composer ${name} includes GPL alternatives but also permissive ${text}`);
  }
}

emit('INFO', `composer license scan checked ${scanned} packages`);
NODE
        )" || fail "composer license parser failed"

        consume_report "${composer_report:-}"
    else
        fail "composer licenses --format=json failed"
    fi
fi

if command -v node >/dev/null 2>&1; then
    mapfile -t package_locks < <(git ls-files '*package-lock.json' 2>/dev/null || true)

    if ((${#package_locks[@]} == 0)); then
        warn "no package-lock.json files found for npm license audit"
    else
        npm_report="$(
            node - "${package_locks[@]}" <<'NODE'
const fs = require('fs');

const lockfiles = process.argv.slice(2);
const snapshotPath = 'docs/public-release/npm-license-snapshot.json';
const documentedWatch = new Set([
  '@mistralai/mistralai',
  'cohere-ai',
  'argparse',
  'caniuse-lite',
  'd3-flextree',
  'mcp-agent',
]);

function emit(level, message) {
  process.stdout.write(`${level}\t${message}\n`);
}

function loadSnapshot() {
  if (!fs.existsSync(snapshotPath)) {
    return { packages: new Map(), loaded: false };
  }

  const payload = JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));
  const packages = new Map();
  for (const tree of payload.trees || []) {
    for (const row of tree.packages || []) {
      if (!row.lockfile || !row.name) continue;
      packages.set(`${row.lockfile}\0${row.name}`, row);
    }
  }

  return {
    packages,
    loaded: true,
    generatedAt: payload.generated_at || 'unknown',
  };
}

function packageNameFromPath(packagePath, metadata) {
  if (metadata.name) {
    return metadata.name;
  }

  const parts = packagePath.split('node_modules/');
  return parts[parts.length - 1] || packagePath;
}

function licenseText(metadata) {
  if (metadata.license) {
    return normalizeLicense(metadata.license);
  }

  if (Array.isArray(metadata.licenses)) {
    return normalizeLicense(metadata.licenses
      .map((license) => typeof license === 'string' ? license : license.type)
      .filter(Boolean)
      .join(' OR '));
  }

  return '';
}

function snapshotLicense(snapshot, lockfile, name) {
  const row = snapshot.packages.get(`${lockfile}\0${name}`);
  const text = row?.license ? normalizeLicense(row.license) : '';

  return text || '';
}

function normalizeLicense(license) {
  const text = String(license || '').trim();
  if (!text) return '';
  if (/^Apache\s*(License)?\s*2(\.0)?$/i.test(text)) return 'Apache-2.0';
  if (/^MIT License$/i.test(text)) return 'MIT';
  return text;
}

function hasPermissiveAlternative(text) {
  return /\b(MIT|BSD-2-Clause|BSD-3-Clause|Apache-2\.0|ISC|0BSD|Unlicense|CC0-1\.0)\b/i.test(text);
}

const seen = new Set();
const snapshot = loadSnapshot();
if (snapshot.loaded) {
  emit('INFO', `npm license snapshot loaded from ${snapshotPath} generated=${snapshot.generatedAt}`);
  const snapshotMtime = fs.statSync(snapshotPath).mtimeMs;
  for (const lockfile of lockfiles) {
    if (fs.existsSync(lockfile) && fs.statSync(lockfile).mtimeMs > snapshotMtime) {
      emit('WARN', `npm ${lockfile} is newer than ${snapshotPath}; refresh npm license snapshot`);
    }
  }
}

for (const lockfile of lockfiles) {
  const payload = JSON.parse(fs.readFileSync(lockfile, 'utf8'));
  const packages = payload.packages || {};
  let scanned = 0;
  let missing = 0;

  for (const [packagePath, metadata] of Object.entries(packages)) {
    if (!packagePath.includes('node_modules/')) {
      continue;
    }

    scanned += 1;
    const name = packageNameFromPath(packagePath, metadata);
    const lockfileText = licenseText(metadata);
    const text = lockfileText || snapshotLicense(snapshot, lockfile, name);

    if (!text) {
      if (documentedWatch.has(name)) {
        emit('WARN', `npm ${lockfile} ${name} has missing lockfile license metadata; documented watch item`);
      } else {
        missing += 1;
      }
      continue;
    }

    const key = `${lockfile}\0${name}\0${text}`;
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);

    const lower = text.toLowerCase();

    if (lower === 'unknown') {
      const level = documentedWatch.has(name) ? 'WARN' : 'FAIL';
      emit(level, `npm ${lockfile} ${name} reports UNKNOWN license metadata`);
      continue;
    }

    if (lower.includes('agpl')) {
      emit('FAIL', `npm ${lockfile} ${name} reports ${text}`);
      continue;
    }

    if (/\bgpl\b|gpl-\d/i.test(text) && !hasPermissiveAlternative(text) && !lower.includes('lgpl')) {
      emit('FAIL', `npm ${lockfile} ${name} reports ${text}`);
      continue;
    }

    if (lower.includes('lgpl')) {
      emit('WARN', `npm ${lockfile} ${name} reports ${text}; review before browser/public redistribution`);
    } else if (/wtfpl|python-2\.0|cc-by|mpl-2\.0|apache 2\.0/i.test(text)) {
      if (/mpl-2\.0/i.test(text) && hasPermissiveAlternative(text)) {
        emit('INFO', `npm ${lockfile} ${name} includes MPL alternatives but also permissive ${text}`);
        continue;
      }
      emit('WARN', `npm ${lockfile} ${name} reports ${text}; documented watch item`);
    }
  }

  if (missing > 0) {
    emit('WARN', `npm ${lockfile} has ${missing} package entries without lockfile license metadata; use a dedicated npm license checker before formal release`);
  }

  emit('INFO', `npm license scan checked ${scanned} package entries in ${lockfile}`);
}
NODE
        )" || fail "npm lockfile license parser failed"

        consume_report "${npm_report:-}"
    fi
fi

for requirements in requirements-core.txt requirements-media.txt requirements-gpu.txt; do
    if [[ -f "$requirements" ]]; then
        case "$requirements" in
            requirements-core.txt)
                constraints="requirements-core.constraints.txt"
                tier="core"
                ;;
            requirements-media.txt)
                constraints="requirements-media.constraints.txt"
                tier="media"
                ;;
            requirements-gpu.txt)
                constraints="requirements-gpu.constraints.txt"
                tier="gpu"
                ;;
            *)
                constraints="${requirements%.txt}.constraints.txt"
                tier="${requirements#requirements-}"
                tier="${tier%.txt}"
                ;;
        esac

        if [[ -f "$constraints" ]]; then
            info "Python dependency profile $requirements has pinned constraints at $constraints"
            snapshot="docs/public-release/python-license-snapshot-${tier}.json"
            if [[ -f "$snapshot" ]]; then
                info "Python dependency profile $requirements has license snapshot at $snapshot"
                if [[ "$constraints" -nt "$snapshot" ]]; then
                    if command -v python3 >/dev/null 2>&1 && python_snapshot_matches_constraints "$constraints" "$snapshot"; then
                        info "Python dependency profile $requirements license snapshot pins match current constraints despite newer file timestamp"
                    else
                        warn "Python dependency profile $requirements constraints are newer than $snapshot; refresh Python license snapshot"
                    fi
                fi
            elif [[ "$requirements" == "requirements-core.txt" ]]; then
                warn "Python dependency profile $requirements is missing core license snapshot; run scripts/snapshot-python-licenses.sh --tier=core"
            fi
            if [[ "$requirements" == "requirements-media.txt" ]]; then
                warn "Python media constraints are a resolver snapshot only; review native face/NLP/graph package licenses before formal public release"
                if grep -Eq '^(igraph|leidenalg)==' "$constraints"; then
                    warn "Python media constraints include GPL-signaled graph packages (igraph/leidenalg); keep optional/operator-installed and review before a permissive public release"
                fi
                if grep -Eq '^psycopg2-binary==' "$constraints"; then
                    warn "Python media constraints include LGPL-signaled psycopg2-binary; review redistribution obligations before formal public release"
                fi
            elif [[ "$requirements" == "requirements-gpu.txt" ]]; then
                warn "Python GPU constraints are platform-sensitive; review PyTorch/CUDA/model package licenses and host wheel fit before formal public release"
                if grep -Eq '^(cuda-|nvidia-)' "$constraints"; then
                    warn "Python GPU constraints include NVIDIA software-license/proprietary package signals; keep operator-installed and review runtime terms"
                fi
            fi
        else
            warn "Python dependency profile $requirements is not a license lock; generate constraints and review native/model package licenses before formal public release"
        fi
    fi
done

if [[ -f docs/model-runtime-license-map.md ]]; then
    info "model/runtime license map present at docs/model-runtime-license-map.md"
else
    warn "model/runtime license map missing; document external model weights and runtime terms before formal public release"
fi

if [[ -f docs/native-ml-package-review.md ]]; then
    info "native/ML package review present at docs/native-ml-package-review.md"
else
    warn "native/ML package review missing; document optional Python native, GPU, and model-adjacent package posture before formal public release"
fi

if ((failures > 0)); then
    printf 'FAIL: license audit found %d failure(s) and %d warning(s)\n' "$failures" "$warnings" >&2
    exit 1
fi

printf 'PASS: license audit completed with %d warning(s)\n' "$warnings"
