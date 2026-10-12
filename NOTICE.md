# PLOS Third-Party Notices

PLOS source code is released under the MIT license unless a file states
otherwise. Package-manager lockfiles remain the authoritative dependency
inventory for a given release. This notice is an engineering release aid, not
legal advice.

## Direct Platform Stack

PLOS is built on Laravel, Vue, Tailwind CSS, PostgreSQL/pgvector,
MySQL-compatible databases, Redis-compatible queues, Ollama-compatible local AI
hosts, ExifTool-compatible metadata workflows, and optional Docker services.
Laravel framework packages and first-party Laravel tools used by PLOS, including
Horizon, Passport, Tinker, Pail, Pint, Sail, and the Laravel Vite plugin, report
MIT license terms through package metadata.

## Dependency Watch Items

PLOS `AgentActionSeverityService` is an adapted portion of Action-Graded
Severity, copyright (c) 2026 Harry Owiredu-Ashley, used under the MIT License
from revision `c159db7b6fdcd2f2bb9782a9bbe72e5bc2069cd4`. The complete notice and
license text are retained in `THIRD_PARTY.md`.

Run `scripts/audit-licenses.sh` and
`scripts/guards/dependency-provenance-check.sh` before creating or publishing a
public export.
The current local audit was run on 2026-07-21 and passed with 15 warnings.
Current known watch items include:

- `@modelcontextprotocol/sdk 1.30.1` is an unmodified direct MIT npm
  dependency used for the PLOS MCP server and its trusted local contract-audit
  client. `mcp-servers/plos/package-lock.json` records the exact published
  artifact associated with official tag revision
  `289ac2c3af7e1536160e80414296b175171a1a87`.
- `puppeteer 25.12.0` and `@puppeteer/browsers 3.2.3` are unmodified
  Apache-2.0 npm packages. The exact root override removes the vulnerable
  `extract-zip` dependency while preserving the existing MIT-licensed, archived
  Puppeteer MCP wrapper at 2025.5.12. Preserve all bundled upstream notices.
- `ipaddr.js 2.4.0` is an unmodified direct MIT npm dependency used for
  IPv4/IPv6 parsing and special-range classification in the browser navigation
  DNS gate. `package-lock.json` records the exact published artifact; preserve
  its upstream copyright and license notice when redistributing dependencies.
- PHP packages `phpoffice/phpword`, `smalot/pdfparser`, and `tecnickcom/tcpdf`
  report LGPL-3.x license terms. They should remain normal Composer
  dependencies unless release obligations are reviewed.
- Nette packages include GPL alternatives but are usable under their permissive
  BSD alternatives according to package metadata.
- `mrmysql/youtube-transcript` reports WTFPL terms. YouTube service access terms
  are separate from the code license and remain the operator's responsibility.
- Remaining npm warning items are `argparse` under Python-2.0, `caniuse-lite`
  under CC-BY-4.0, and `d3-flextree` under WTFPL. The npm license snapshot now
  resolves `@mistralai/mistralai 1.14.0` as Apache-2.0 from its local license
  file, `cohere-ai 7.20.0` as MIT from its local license file, `mcp-agent` as
  Apache-2.0 after SPDX normalization, and the exported MCP workspace lockfiles
  from installed package manifests.
- The root npm license snapshot predates the direct `ipaddr.js` lockfile update
  and the MCP workspace SDK 1.30.1 update. Refresh it from a clean installed
  dependency tree before a public export; the current mixed development tree
  would introduce unrelated package-version churn into that generated artifact.
- Python requirement files are operator-installed dependency profiles. The
  public core, media, and GPU tiers now have resolver snapshots in
  `requirements-core.constraints.txt`, `requirements-media.constraints.txt`,
  and `requirements-gpu.constraints.txt`. Media/GPU tiers have a working
  native/ML package review, but still need final signoff and host evidence
  before a formal public release. Python watch items are tracked in
  `docs/python-constraints-license-snapshot.md`; the native/ML review matrix is
  tracked in `docs/native-ml-package-review.md`.

## Runtime Assets

PLOS does not redistribute local AI model weights, dlib face model weights,
spaCy language models, Docker base images, Redis/Valkey binaries, ExifTool,
Tesseract, FFmpeg, LibreOffice, Thunderbird, Nextcloud, SearXNG, Ollama, or
Colibri. The optional source-built Colibri `v1.1.1-plos-377db82` runtime
(Apache-2.0 upstream and public-fork signal) and pinned GLM-5.2 community model
are separately installed private-runtime assets;
their exact revisions and release posture are recorded in
`docs/model-runtime-license-map.md`.
Operators install those assets separately and accept upstream terms for each.
If a public guide recommends specific local AI, Whisper, transformer, HTR, or
embedding model weights, keep `docs/model-runtime-license-map.md` aligned
before tagging.

## Reference Projects

Gramps, Gramps Web, webtrees, PhotoPrism, LibrePhotos, and Joplin are reference
projects for workflow, data-model, interoperability, and user-experience
lessons. Use them as inspiration. Do not copy GPL/AGPL implementation code into
the MIT public PLOS extraction unless the license strategy intentionally changes
and the obligations are accepted.

The 2026-07-03 Genea agent playbook harvest adapts patterns from permissive
MIT/Apache-2.0 projects such as Gramps MCP, GEDCOM LLM Safety, Ancestry Agent,
AI Genealogy, Genealogy AI, Genealogy Research Assistant, and Genealogy
Postgres MCP into PLOS-native config, service code, and skill instructions.
PLOS does not vendor those upstream applications. Open Genealogy is tracked as
reference-only because its current public signal is CC-BY-NC-SA-4.0.

## Attribution

### Adapted Apache-2.0 Source

`app/Services/ContextPositioningService.php` adapts the document-ordering
algorithm from deepset Haystack's `LostInTheMiddleRanker`, revision
`acc9e58a66d038622cafe40fe863691f812a66e6`.

Copyright 2022-present deepset GmbH <info@deepset.ai>. Licensed under the
Apache License, Version 2.0. PLOS modifications operate on native RAG result
arrays, retain retrieval ranks, use stable document identities, and expose
prompt context positions. The source file carries the exact upstream link,
SPDX identifiers, and modification notice.

`app/Services/AgentRoutingShadowService.php` adapts the bounded fielded BM25
and character n-gram skill selectors from OpenAI Codex revisions
`a47c661ea9e226fe65e46cf9dbc5c5ed75c2c762` and
`c983a53f2035bb9b6e999b1355b0056946671e9e`.

Copyright 2025 OpenAI. Licensed under the Apache License, Version 2.0. PLOS
modifies the algorithms into native PHP over its agent catalog, substitutes
agent identity/capabilities/description fields, emits content-free primary and
challenger shadow receipts, and leaves routing authority unchanged. The source
file carries the exact revisions and modification notice; the license text is
retained at `LICENSES/Apache-2.0.txt`.

### Adapted MIT Source

`app/Support/SpreadsheetSafeCsv.php` adapts the formula-trigger detection in
Promptfoo's `escapeCsvFormula`, revision
`fcde2e89a89dc4ca79dcc3012927f50193251759`.

Copyright (c) Promptfoo 2025

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

PLOS modifications translate the boundary to PHP, remove the disable override,
cover full-width and leading-control triggers from OWASP guidance, preserve
stored values, and apply one encoder to native genealogy/research CSV exports.

### LGPL Composer Dependencies

PLOS depends on the following packages under LGPL-3.x and uses them as
unmodified Composer dependencies. Operators may relink against modified
versions by running `composer update` or replacing the vendored copy in
`vendor/`.

- `phpoffice/phpword 1.4.0` — LGPL-3.0-only.
- `smalot/pdfparser v2.12.1` — LGPL-3.0.
- `tecnickcom/tcpdf 6.10.1` — LGPL-3.0-or-later.

PLOS does not modify these packages, does not statically link them, and does
not redistribute them outside normal package-manager install paths.

### Apache-2.0 Composer Dependencies

PLOS uses `opis/json-schema 2.6.0` as an unmodified Composer dependency for
local validation of structured Codex responses. Its transitive dependencies
`opis/string 2.1.0` and `opis/uri 1.1.0` also report Apache-2.0. Package-manager
metadata and `composer.lock` record their exact source revisions.

### Nonstandard Permissive Licenses

The following dependencies report nonstandard permissive code licenses. Code
licenses are separate from upstream service terms, which remain the operator's
responsibility.

- `mrmysql/youtube-transcript v0.0.5` — WTFPL.
- `d3-flextree 2.1.2` — WTFPL.

### Data And Documentation Licenses

Some npm dependencies carry data-license or non-source-code license terms.
PLOS uses them through normal package-manager paths.

- `argparse 2.0.1` — Python-2.0.
- `caniuse-lite 1.0.30001767` — CC-BY-4.0 browser-support data.

### Native And Python Copyleft Watch List

The following operator-installed packages carry copyleft or proprietary-runtime
signals. PLOS does not vendor wheels, binaries, model files, or CUDA runtime
packages for them. They remain package-manager dependencies and are reviewed in
`docs/native-ml-package-review.md`.

- `psycopg2-binary 2.9.12` — LGPL-family signal.
- `igraph 0.11.9` — GPL-family signal; optional media tier.
- `leidenalg 0.10.2` — GPL-family signal; optional media tier.
- NVIDIA CUDA Python package family — proprietary-runtime signals; optional GPU tier.

## Research And Fixture Provenance

The compact citation map for public README work, articles, and publication
drafts lives in `docs/research-provenance.md`. Public fixtures are tracked in
`tests/Fixtures/PROVENANCE.md`. Model/runtime assets are tracked in
`docs/model-runtime-license-map.md`; Python constraint package watch items are
tracked in `docs/python-constraints-license-snapshot.md` and
`docs/native-ml-package-review.md`.

Before public release:

1. Run `scripts/audit-licenses.sh`.
2. Run `scripts/guards/dependency-provenance-check.sh`.
3. Run `scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"`.
4. Confirm no copied GPL/AGPL source, private data, private paths, private
   credentials, or model weights are present in the exported tree.
