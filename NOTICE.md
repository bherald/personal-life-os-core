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

Run `scripts/audit-licenses.sh` and
`scripts/guards/dependency-provenance-check.sh` before creating or publishing a
public export.
The current local audit was run on 2026-04-30 and passed with 12 warnings.
Current known watch items include:

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
Tesseract, FFmpeg, LibreOffice, Thunderbird, Nextcloud, SearXNG, or Ollama.
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

## Attribution

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
