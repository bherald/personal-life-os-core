#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"

CHECK=0
if [[ "${1:-}" == "--check" ]]; then
    CHECK=1
    shift
fi

if (($# > 0)); then
    printf 'Usage: %s [--check]\n' "$0" >&2
    exit 2
fi

if ! command -v node >/dev/null 2>&1; then
    printf 'node is required to snapshot npm licenses\n' >&2
    exit 1
fi

OUT_DIR="docs/public-release"
OUT_JSON="$OUT_DIR/npm-license-snapshot.json"
OUT_MD="$OUT_DIR/npm-license-snapshot.md"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$OUT_DIR"

if ((CHECK)) && [[ -f "$OUT_JSON" ]]; then
    export NPM_LICENSE_SNAPSHOT_GENERATED_AT
    NPM_LICENSE_SNAPSHOT_GENERATED_AT="$(node -e "const fs=require('fs'); const p=JSON.parse(fs.readFileSync(process.argv[1], 'utf8')); process.stdout.write(p.generated_at || '');" "$OUT_JSON")"
fi

node - "$TMP_DIR/snapshot.json" "$TMP_DIR/snapshot.md" <<'NODE'
const fs = require('fs');
const path = require('path');

const outJson = process.argv[2];
const outMd = process.argv[3];

const trees = [
  { label: 'root', path: '.', lockfile: 'package-lock.json', modules: 'node_modules' },
  { label: 'mcp-server', path: 'mcp-server', lockfile: 'mcp-server/package-lock.json', modules: 'mcp-server/node_modules' },
  { label: 'mcp-servers/plos', path: 'mcp-servers/plos', lockfile: 'mcp-servers/plos/package-lock.json', modules: 'mcp-servers/plos/node_modules' },
].filter((tree) => fs.existsSync(tree.lockfile));

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function packageNameFromPath(packagePath, metadata, packageJson) {
  if (packageJson?.name) return packageJson.name;
  if (metadata?.name) return metadata.name;
  const parts = packagePath.split('node_modules/');
  return parts[parts.length - 1] || packagePath;
}

function licenseText(...sources) {
  for (const source of sources) {
    if (!source) continue;
    if (source.license) return normalizeLicense(source.license);
    if (Array.isArray(source.licenses)) {
      const text = source.licenses
        .map((license) => typeof license === 'string' ? license : license.type)
        .filter(Boolean)
        .join(' OR ');
      if (text) return normalizeLicense(text);
    }
  }

  return '';
}

function normalizeLicense(license) {
  const text = String(license || '').trim();
  if (!text) return '';
  if (/^Apache\s*(License)?\s*2(\.0)?$/i.test(text)) return 'Apache-2.0';
  if (/^MIT License$/i.test(text)) return 'MIT';
  return text;
}

function licenseFromFile(packageDir) {
  for (const filename of ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'license', 'license.md', 'license.txt']) {
    const file = path.join(packageDir, filename);
    if (!fs.existsSync(file)) continue;

    const text = fs.readFileSync(file, 'utf8').slice(0, 8192);
    if (/Apache License\s+Version 2\.0/i.test(text)) return 'Apache-2.0';
    if (/MIT License/i.test(text) && /Permission is hereby granted/i.test(text)) return 'MIT';
  }

  return '';
}

function normalizeRepository(repository) {
  if (!repository) return '';
  if (typeof repository === 'string') return repository;
  return repository.url || '';
}

function packageJsonPath(tree, packagePath) {
  const relative = packagePath.replace(/^node_modules\//, '');
  return path.join(tree.path, 'node_modules', relative, 'package.json');
}

function licenseBucket(license) {
  if (!license) return 'missing';
  const lower = license.toLowerCase();
  if (lower.includes('agpl')) return 'agpl';
  if ((/\bgpl\b|gpl-\d/i).test(license) && !lower.includes('lgpl')) return 'gpl';
  if (lower.includes('lgpl')) return 'lgpl';
  if (/(cc-by|python-2\.0|wtfpl|mpl-2\.0|apache 2\.0)/i.test(license)) return 'watch';
  if (/\b(MIT|BSD-2-Clause|BSD-3-Clause|Apache-2\.0|ISC|0BSD|Unlicense|CC0-1\.0)\b/i.test(license)) return 'permissive';
  return 'other';
}

const generatedAt = process.env.NPM_LICENSE_SNAPSHOT_GENERATED_AT || new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const payload = {
  schema_version: 1,
  generated_at: generatedAt,
  source: 'installed node_modules package manifests plus package-lock fallbacks; platform-specific optional packages prefer lockfile metadata when licensed there',
  trees: [],
};

for (const tree of trees) {
  const lock = readJson(tree.lockfile);
  const packages = lock.packages || {};
  const rows = [];
  const buckets = {};

  for (const [packagePath, metadata] of Object.entries(packages)) {
    if (!packagePath.includes('node_modules/')) continue;

    const manifestPath = packageJsonPath(tree, packagePath);
    let packageJson = null;
    if (fs.existsSync(manifestPath)) {
      packageJson = readJson(manifestPath);
    }

    if (metadata.optional && metadata.license && (metadata.os || metadata.cpu)) {
      packageJson = null;
    }

    const packageLicense = licenseText(packageJson);
    const lockfileLicense = licenseText(metadata);
    let license = packageLicense || lockfileLicense;
    let source = packageLicense ? 'package.json' : (lockfileLicense ? 'package-lock' : '');

    if (!license && packageJson) {
      license = licenseFromFile(path.dirname(manifestPath));
      source = license ? 'license-file' : 'package.json';
    }

    const row = {
      name: packageNameFromPath(packagePath, metadata, packageJson),
      version: String(metadata.version || packageJson?.version || ''),
      license,
      bucket: licenseBucket(license),
      lockfile: tree.lockfile,
      package_path: packagePath,
      repository: normalizeRepository(packageJson?.repository || metadata.repository),
      homepage: String(packageJson?.homepage || metadata.homepage || ''),
      source: source || (packageJson ? 'package.json' : 'package-lock'),
    };

    rows.push(row);
    buckets[row.bucket] = (buckets[row.bucket] || 0) + 1;
  }

  rows.sort((a, b) => a.name.localeCompare(b.name) || a.package_path.localeCompare(b.package_path));
  payload.trees.push({
    label: tree.label,
    path: tree.path,
    lockfile: tree.lockfile,
    package_count: rows.length,
    buckets,
    packages: rows,
  });
}

fs.writeFileSync(outJson, `${JSON.stringify(payload, null, 2)}\n`);

const md = [];
md.push('# npm License Snapshot');
md.push('');
md.push(`Generated: ${generatedAt}`);
md.push('');
md.push('This snapshot is generated from installed `node_modules` package manifests, with package-lock metadata as a fallback. Platform-specific optional package entries prefer package-lock metadata when the lockfile already has a license, so OS/CPU-specific optional installs do not make the snapshot flap. It is release-diligence evidence, not legal advice.');
md.push('');
md.push('## Summary');
md.push('');
md.push('| Tree | Packages | Permissive | Watch | LGPL | GPL | AGPL | Missing | Other |');
md.push('|---|---:|---:|---:|---:|---:|---:|---:|---:|');

for (const tree of payload.trees) {
  const b = tree.buckets;
  md.push(`| ${tree.label} | ${tree.package_count} | ${b.permissive || 0} | ${b.watch || 0} | ${b.lgpl || 0} | ${b.gpl || 0} | ${b.agpl || 0} | ${b.missing || 0} | ${b.other || 0} |`);
}

md.push('');
md.push('## Watch And Unresolved Packages');
md.push('');
md.push('| Tree | Package | Version | License | Bucket | Source |');
md.push('|---|---|---:|---|---|---|');

let watchRows = 0;
for (const tree of payload.trees) {
  for (const row of tree.packages) {
    if (!['watch', 'lgpl', 'gpl', 'agpl', 'missing', 'other'].includes(row.bucket)) continue;
    watchRows += 1;
    md.push(`| ${tree.label} | ${row.name} | ${row.version || ''} | ${row.license || 'MISSING'} | ${row.bucket} | ${row.source} |`);
  }
}

if (watchRows === 0) {
  md.push('| all | none |  |  |  |  |');
}

md.push('');
md.push('Keep `scripts/audit-licenses.sh` as the gate. This file only gives the audit richer npm metadata.');
fs.writeFileSync(outMd, `${md.join('\n')}\n`);
NODE

if ((CHECK)); then
    if [[ ! -f "$OUT_JSON" || ! -f "$OUT_MD" ]]; then
        printf 'npm license snapshot files are missing; run scripts/snapshot-npm-licenses.sh\n' >&2
        exit 1
    fi

    diff -u "$OUT_JSON" "$TMP_DIR/snapshot.json"
    diff -u "$OUT_MD" "$TMP_DIR/snapshot.md"
    printf 'npm license snapshot is current.\n'
else
    cp "$TMP_DIR/snapshot.json" "$OUT_JSON"
    cp "$TMP_DIR/snapshot.md" "$OUT_MD"
    printf 'Wrote %s and %s\n' "$OUT_JSON" "$OUT_MD"
fi
