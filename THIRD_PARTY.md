# Third-Party And Provenance Notes

PLOS uses Laravel, Vue, Tailwind, PostgreSQL/pgvector, MySQL-compatible
databases, Redis, Ollama-compatible local models, ExifTool-compatible metadata
workflows, and other open-source dependencies through normal package managers
or system installation.

For the compact release-facing notice, see `NOTICE.md`. Run
`scripts/audit-licenses.sh` before creating a public export.

Genealogy, photo-library, and notes projects such as Gramps, Gramps Web,
webtrees, PhotoPrism, LibrePhotos, and Joplin are used as references for
data-model, workflow, interoperability, and user-experience lessons. Do not
copy GPL/AGPL project code into the MIT public PLOS extraction unless the
license strategy is intentionally changed and the obligations are accepted.

Face-region metadata work should be framed around open metadata standards and
tool compatibility, including XMP/MWG regions, IPTC photo metadata fields, and
ExifTool-compatible read/write paths.

For the compact research/project citation map used by public README and
publication drafts, see `docs/research-provenance.md`. Python constraints
license watch items are tracked in `docs/python-constraints-license-snapshot.md`.
The release posture for optional native/ML/GPU packages is tracked in
`docs/native-ml-package-review.md`.

## Public Reference Projects

Reference-project license snapshot verified on 2026-04-26 and 2026-04-27 from
the upstream project repositories. Package-manager metadata was refreshed
locally on 2026-04-28.

| Project | Upstream | License signal | PLOS use |
| --- | --- | --- | --- |
| Gramps | https://github.com/gramps-project/gramps | GitHub labels the repository GPL-2.0. The upstream COPYING file remains authoritative. | Genealogy data-model and desktop workflow reference only. |
| Gramps Web | https://github.com/gramps-project/gramps-web | GitHub labels the repository AGPL-3.0. The upstream license files remain authoritative. | Collaborative genealogy UX and privacy reference only. |
| webtrees | https://github.com/fisharebest/webtrees | Upstream README states GPL-3.0-or-later terms. The upstream license files remain authoritative. | GEDCOM-compatible web workflow reference only. |
| PhotoPrism | https://github.com/photoprism/photoprism | Upstream README displays an AGPL license badge. The upstream license files remain authoritative. | Photo library workflow, face-review, metadata, and search reference only. |
| LibrePhotos | https://github.com/LibrePhotos/librephotos | Upstream README states MIT license. | Photo library workflow and ML pipeline reference; still avoid copying without attribution review. |
| Joplin | https://github.com/laurent22/joplin | Upstream LICENSE states AGPL-3.0-or-later by default, with directory-specific exceptions. Joplin Server has separate licensing. | Operator-managed sync target and notes workflow inspiration only. PLOS may implement the public sync-target format/spec for interoperability, but should not copy application, sync, or lock implementation code. |

The safe public-release rule is simple: copy ideas, workflows, standards, and
interoperability targets, not implementation code, unless the file-level license
review explicitly allows it and the PLOS public license strategy is updated.

## Other Referenced Project Watch Items

These items were found in the current tree and git-history review as public
release surface area or recurring references:

| Item | Evidence | Public-release posture |
| --- | --- | --- |
| Topola | Direct npm dependency for genealogy tree visualization; package metadata reports Apache-2.0. | Keep as package-manager dependency with lockfile/license metadata. Do not vendor modified upstream source without notice review. |
| Model Context Protocol SDK/servers | Direct npm dependency and tool-protocol reference. | MCP upstream is in a license transition; preserve package metadata and review bundled docs/spec notices before release. |
| Graphlit MCP server and Nextcloud MCP server | Optional npm MCP packages; current package metadata reports MIT. | Keep optional. Graphlit service/API terms and Nextcloud server AGPL terms remain separate from the MCP package code license. |
| mrmysql/youtube-transcript | Composer package used by YouTube transcript workflows; package metadata reports WTFPL. | Code license is permissive/nonstandard; YouTube service access terms are separate and must stay documented as operator responsibility. |
| pgvector and Ollama | Runtime dependencies/references for local vector search and local model routing. | Operator-installed runtime components; model weights remain outside git and carry separate terms. |
| Nextcloud and SearXNG | Optional Docker profile services for local sync and privacy-preserving search. | Both have AGPL service/image license signals; treat them as operator-pulled services, not vendored or modified PLOS source. |
| Thunderbird | Local mail/calendar client boundary and optional private bridge target. | MPL/tool boundary; do not bundle Thunderbird code in the public core. |
| dlib, face_recognition, hdbscan | Python media dependencies for face embeddings and clustering. | Permissive dependency signals; dlib model files remain external and documented in `docs/FACE-RECOGNITION.md`. |
| igraph and leidenalg | Optional community-detection dependencies in the media/genealogy Python tier. | Current constraints pin `igraph==0.11.9` and `leidenalg==0.10.2`. Upstream/license metadata signals GPL-family terms (`python-igraph` GPL-2.0-or-later signal; `leidenalg` GPL-3.0-or-later signal). Keep optional/operator-installed and review before treating a permissive public media tier as release-ready. |
| ExifTool, XMP, IPTC, MWG regions | Metadata compatibility target for face/person writeback. | Standards/tool compatibility only; avoid copied app logic. |
| FamilySearch GEDCOM 7 | File-format/specification reference. | Specification use only; not a FamilySearch API integration. |
| Genealogy provider/source sites | WikiTree, FindAGrave, BillionGraves, LOC, NARA, Internet Archive, MyHeritage, Newspapers.com, and similar sources. | Public code should prefer official APIs/public archives/manual review and should not promise scraping where source terms are unclear or subscription-gated. |

## Package Manager License Snapshot

Local package-manager evidence from 2026-04-28:

- `scripts/audit-licenses.sh` passed with 16 warnings. It scanned Composer,
  tracked npm lockfiles, Python constraint presence, and the model/native review
  files. Treat the warning set below as the current public-release watch list.
- `composer licenses --format=json` checked 143 packages and is mostly
  MIT/BSD/Apache-compatible.
  Release checklist item: confirm LGPL packages are used as normal Composer
  dependencies and document any redistribution obligations for
  `phpoffice/phpword 1.4.0` (`LGPL-3.0-only`), `smalot/pdfparser v2.12.1`
  (`LGPL-3.0`), and `tecnickcom/tcpdf 6.10.1`
  (`LGPL-3.0-or-later`). `mrmysql/youtube-transcript v0.0.5` reports WTFPL.
  Dual-licensed Nette packages include GPL alternatives but are usable under
  BSD-3-Clause according to Composer metadata.
- Tracked npm lockfiles scanned on 2026-04-28:
  `package-lock.json` checked 998 package entries,
  `mcp-server/package-lock.json` checked 106 package entries, and
  `mcp-servers/plos/package-lock.json` checked 119 package entries. Root npm
  watch items are `@mistralai/mistralai 1.14.0` and `cohere-ai 7.20.0`
  missing lockfile and installed-manifest license fields, `caniuse-lite`
  `CC-BY-4.0`, `argparse` `Python-2.0`, `d3-flextree` `WTFPL`, and
  `mcp-agent` `Apache 2.0` non-SPDX spelling. `dompurify` reports
  `(MPL-2.0 OR Apache-2.0)`, with Apache-2.0 available.
- `mcp-server/package-lock.json` has 89 package entries without lockfile
  license metadata. A local read of installed `mcp-server/node_modules`
  package manifests resolved those missing entries to MIT, ISC, Apache-2.0,
  BSD-3-Clause, and BSD-2-Clause signals, but a dedicated npm license checker
  should still be run before formal release because lockfile evidence is
  incomplete.
- Python requirement files are unpinned ranges for operator-installed packages.
  The core public profile has `requirements-core.constraints.txt` generated
  from a passing public smoke environment. Media and GPU profiles now have
  resolver snapshots in `requirements-media.constraints.txt` and
  `requirements-gpu.constraints.txt`; treat them as reproducibility aids, not
  final legal signoff. Native face/NLP/graph packages, LGPL/GPL-signaled graph
  dependencies, PyTorch/CUDA packages, NVIDIA runtime packages, and downloaded
  model assets are reviewed in `docs/native-ml-package-review.md` and should be
  re-checked before a public tag. See
  `docs/python-constraints-license-snapshot.md`.

This is an engineering triage, not final legal advice. Treat unknown, LGPL,
non-SPDX, model-weight, and service-image licenses as release checklist items.

## Runtime And Model Assets

PLOS does not redistribute local AI model weights, dlib face model weights,
spaCy language models, Redis binaries, or Docker base images. Operators install
or pull those assets separately and accept the upstream license/terms for each
asset. Public docs should keep model setup as download instructions, not
vendored files.

Specific watch items for the first public release:

- Redis service images have their own license posture; PLOS should support a
  compatible Redis/Valkey service rather than bundling assumptions into source.
- `dlib` and `face_recognition` are permissive code dependencies, but the large
  face-recognition model files stay outside git. Public setup instructions and
  checksums live in `docs/FACE-RECOGNITION.md`.
- spaCy models are downloaded by the operator and are not fixture data.
- Ollama-served model weights such as Llama, Qwen, Phi, LLaVA, and embedding
  models each carry separate upstream terms.
- GraphRAG concepts should be treated as architecture/research references unless
  a specific implementation file is intentionally vendored with compatible
  license attribution.
- Keep the active model/runtime license map at
  `docs/model-runtime-license-map.md` aligned before the first formal public
  tag and whenever the README recommends specific Ollama, Whisper,
  transformer, HTR, or embedding weights.

## Fixture Provenance

Public fixtures are documented in `tests/Fixtures/PROVENANCE.md`. New fixtures
must be synthetic, public-domain with a recorded source, or generated locally
with enough detail to recreate them. Private photos, real family trees, real
mail, private notes, and local machine paths do not belong in public fixtures.
