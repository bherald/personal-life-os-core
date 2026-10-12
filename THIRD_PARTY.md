# Third-Party And Provenance Notes

PLOS uses Laravel, Vue, Tailwind, PostgreSQL/pgvector, MySQL-compatible
databases, Redis, Ollama-compatible local models, ExifTool-compatible metadata
workflows, and other open-source dependencies through normal package managers
or system installation.

For the compact release-facing notice, see `NOTICE.md`. Run
`scripts/audit-licenses.sh` before creating a public export.

Private PLOS adopts useful third-party code and artifacts under their actual
licenses whenever the intended deployment can satisfy the terms. GPL and AGPL
are not automatic rejection signals. A future public extraction is a separate
packaging decision and must not constrain lawful private-product improvement.

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
| Gramps | https://github.com/gramps-project/gramps | GitHub labels the repository GPL-2.0. The upstream COPYING file remains authoritative. | Direct-adoption candidate when GPL obligations fit the selected private integration. |
| Gramps Web | https://github.com/gramps-project/gramps-web | GitHub labels the repository AGPL-3.0. The upstream license files remain authoritative. | Direct-adoption candidate with network-source compliance. |
| webtrees | https://github.com/fisharebest/webtrees | Upstream README states GPL-3.0-or-later terms. The upstream license files remain authoritative. | Direct-adoption candidate for GEDCOM/source/media workflows. |
| PhotoPrism | https://github.com/photoprism/photoprism | Upstream README displays an AGPL license badge. The upstream license files remain authoritative. | Direct-adoption candidate with network-source compliance and operator-owned identity gates. |
| LibrePhotos | https://github.com/LibrePhotos/librephotos | Upstream README states MIT license. | Photo library workflow and ML pipeline reference; still avoid copying without attribution review. |
| Joplin | https://github.com/laurent22/joplin | Upstream LICENSE states AGPL-3.0-or-later by default, with directory-specific exceptions. Joplin Server has separate licensing. | Operator-managed sync target and notes workflow inspiration only. PLOS may implement the public sync-target format/spec for interoperability, but should not copy application, sync, or lock implementation code. |

The adoption rule is simple: copy protected implementation material only under
a license, permission, public-domain status, or other legal basis; freely learn
from unprotected ideas, workflows, procedures, systems, methods, standards, and
interoperability behavior. Preserve every obligation that applies.

## Action-Graded Severity

`app/Services/Ops/AgentActionSeverityService.php` adapts the deterministic
action-effect oracle from Action-Graded Severity revision
`c159db7b6fdcd2f2bb9782a9bbe72e5bc2069cd4`.

MIT License

Copyright (c) 2026 Harry Owiredu-Ashley

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## OpenAI Codex Fielded BM25 and Character N-gram Selectors

`app/Services/AgentRoutingShadowService.php` adapts the bounded fielded BM25
and character n-gram skill-selection algorithms from OpenAI Codex revisions
`a47c661ea9e226fe65e46cf9dbc5c5ed75c2c762` and
`c983a53f2035bb9b6e999b1355b0056946671e9e`.

Copyright 2025 OpenAI

Licensed under the Apache License, Version 2.0 (the "License"); you may not use
this file except in compliance with the License. You may obtain a copy of the
License at `https://www.apache.org/licenses/LICENSE-2.0` and in this repository
at `LICENSES/Apache-2.0.txt`.

PLOS modifications translate the selectors to native PHP, rank the active PLOS
agent catalog across identity, capabilities, and description, add deterministic
agent-routing fixtures and content-free receipts, keep fielded BM25 as the
primary shadow result, and expose character n-grams only as a measured shadow
challenger. OpenAI Codex's unrelated Ratatui NOTICE attribution does not
pertain to the adapted selector and is not reproduced here.

## Genea Agent Pattern Harvest

License snapshot refreshed on 2026-07-03 from upstream GitHub metadata and
README/license files. PLOS adapts these as native PHP/config/skill playbooks;
it does not vendor the upstream Python or JavaScript application code.

| Project | Upstream | License signal | PLOS use |
| --- | --- | --- | --- |
| Gramps MCP | https://github.com/Knuckles-Team/gramps-mcp | MIT at revision `f515a2ece87e9bf13afb87eef33fdd13df87889e`. | Search-to-entity-detail routing informs native `genealogy_search` and `genealogy_context`; no upstream Python runtime or Gramps component is bundled. |
| GEDCOM LLM Safety | https://github.com/labourdayos-afk/gedcom-llm-safety | MIT at revision `5af40e6973d155b789de271642db1cd8c038ec69`, reviewed 2026-07-14. | PLOS independently adapts protected-root ancestor and collateral-reachability tripwires to its database transaction; no upstream Python, skill text, GEDCOM file, or runtime is bundled. |
| Ancestry Agent | https://github.com/javaberlin/ancestry-agent | GitHub reports MIT. | Homonym disambiguation and human-in-loop merge/source-add prevention pattern. |
| AI Genealogy | https://github.com/evz/ai-genealogy | GitHub reports MIT. | Graph-first extraction, hybrid retrieval, and local model-routing pattern. |
| Genealogy AI | https://github.com/Scheldt-LLC/genealogy_ai | GitHub reports MIT. | Local-first OCR/entity extraction and source-versus-interpretation separation pattern. |
| Genealogy Research Assistant | https://github.com/jonahlyn/genealogy-research-assistant | GitHub reports Apache-2.0. | Offline research-log, family-group-sheet, and citation-card pattern. |
| Genealogy Postgres MCP | https://github.com/peterdewit/genealogy-postgres-mcp | GitHub reports MIT. | Persistent evidence/assertion memory shape comparison. |
| Open Genealogy | https://github.com/DigitalArchivst/Open-Genealogy | README/search metadata signal CC-BY-NC-SA-4.0. | Adopt only in a use that satisfies noncommercial, attribution, and share-alike terms. |

## Other Referenced Project Watch Items

These items were found in the current tree and git-history review as public
release surface area or recurring references:

| Item | Evidence | Public-release posture |
| --- | --- | --- |
| Topola | Direct npm dependency for genealogy tree visualization; package metadata reports Apache-2.0. | Keep as package-manager dependency with lockfile/license metadata. Do not vendor modified upstream source without notice review. |
| ipaddr.js | Exact direct npm dependency v2.4.0, MIT, tag revision `61bd6dfd22a53c7d7c044aefe3acad4b0dd7f197`. | Used unmodified for IPv4/IPv6 parsing, mapped-address normalization, and special-range classification in the browser hostname-resolution gate. Preserve package-manager license metadata and the upstream copyright notice. |
| Model Context Protocol TypeScript SDK/servers | Direct MIT npm dependency v1.31.0, pinned in all public workspace lockfiles and tool-protocol reference. | PLOS uses the patched package-managed v1 client/server and stdio transport; preserve package metadata and review bundled docs/spec notices before release. v2 remains prerelease and is not adopted. |
| Puppeteer | Unmodified Apache-2.0 npm dependency v25.12.0 with browsers v3.2.3, pinned by the root override and lockfile. | Retains the MIT-licensed archived MCP wrapper v2025.5.12 and its seven-tool API. The maintained browser library removes extract-zip; wrapper migration remains separate. Preserve package licenses and notices. |
| Graphlit MCP server and Nextcloud MCP server | Optional npm MCP packages; current package metadata reports MIT. | Keep optional. Graphlit service/API terms and Nextcloud server AGPL terms remain separate from the MCP package code license. |
| mrmysql/youtube-transcript | Composer package used by YouTube transcript workflows; package metadata reports WTFPL. | Code license is permissive/nonstandard; YouTube service access terms are separate and must stay documented as operator responsibility. |
| pgvector and Ollama | Runtime dependencies/references for local vector search and local model routing. | Operator-installed runtime components; model weights remain outside git and carry separate terms. |
| Colibri and GLM-5.2 community weights | Optional private text-generation runtime and separately installed model candidate. Colibri reports Apache-2.0; the active `v1.1.1-plos-377db82` runtime applies public contribution tip `be60ec7cd1bc34cb40ea722036064c8d7f06fada` to upstream development base `cd278c227cf515b1f123c5fcc6bcf3c4820eafc9` and verifies assembled tree `073b72cda4653a702653bb643c05b38b07921af1`. The `mateogrgic/GLM-5.2-colibri-int4-with-int8-mtp` model is pinned to revision `3cc8db99b1b13fc79325d987ba3c1c430766b3b8`; its model card reports MIT and identifies the official MIT-licensed GLM-5.2 base. | Operator-built, testing/override-only candidate. Keep runtime binaries and the 383,760,077,466-byte model outside git and public exports; preserve upstream notices/source availability/model cards and do not infer route promotion from installation. |
| Colibri Kimi K3 selective private candidate | Apache-2.0 Colibri source pinned to `33302b82daaeba895ef8c21a61d6030bdb305a9d`, with selected fixes traced to public commits `bbaf16137fef9dbc9621e5c0619b2236a90cea15`, `aa4c51a14c32c918cdf408d5ef7ee45926eef3a3`, and `948cf0237d710754fffe3c97426a12afffd066b6`, reviewed through public main `6546cdde7296f28771e2ba1a1d7c1d4b0cb550aa`; private companion changes add prefill cancellation and unpublished-state reset. | Private, non-serving mapped/state candidates only. Preserve Colibri license/notices and source availability. Kimi K3 weights retain their separate model license. No route, deployment, or public contribution is implied. |
| Nextcloud and SearXNG | Optional Docker profile services for local sync and privacy-preserving search. | Both have AGPL service/image license signals; treat them as operator-pulled services, not vendored or modified PLOS source. |
| Thunderbird | Local mail/calendar client boundary and optional private bridge target. | MPL/tool boundary; do not bundle Thunderbird code in the public core. |
| dlib, face_recognition, hdbscan | Python media dependencies for face embeddings and clustering. | Permissive dependency signals; dlib model files remain external and documented in `docs/FACE-RECOGNITION.md`. |
| igraph and leidenalg | Optional community-detection dependencies in the media/genealogy Python tier. | Current constraints pin `igraph==0.11.9` and `leidenalg==0.10.2`. Upstream/license metadata signals GPL-family terms (`python-igraph` GPL-2.0-or-later signal; `leidenalg` GPL-3.0-or-later signal). Keep optional/operator-installed and review before treating a permissive public media tier as release-ready. |
| ExifTool, XMP, IPTC, MWG regions | Metadata compatibility target for face/person writeback. | Standards/tool compatibility only; avoid copied app logic. |
| FamilySearch GEDCOM 7 | File-format/specification reference. | Specification use only; not a FamilySearch API integration. |
| Genealogy provider/source sites | WikiTree, FindAGrave, BillionGraves, LOC, NARA, Internet Archive, MyHeritage, Newspapers.com, and similar sources. | Public code should prefer official APIs/public archives/manual review and should not promise scraping where source terms are unclear or subscription-gated. |

## Package Manager License Snapshot

Local package-manager evidence refreshed on 2026-07-21:

- `scripts/audit-licenses.sh` passed with 15 warnings. It scanned Composer,
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

### Piper Local Speech Runtime

- Runtime: `OHF-Voice/piper1-gpl` `v1.4.2`, installed in an isolated host
  environment by `scripts/install-piper-runtime.sh`; GPL-3.0.
- Voice repository: `rhasspy/piper-voices` revision
  `2525f22960f62209936f0ae3cce3ffe7b2c18f1f`.
- Voice: `en_US-lessac-medium`; repository license signal MIT. The downloaded
  `MODEL_CARD` and its dataset/license reference are retained beside the model.
- PLOS modification: current Piper CLI flags are adapted through
  `OfflineTtsService`; generated WAV files enter the existing assistant
  attachment lifecycle. Piper source and model files are not committed here.
- Alternatives monitored: `thewh1teagle/kokoro-onnx` (MIT code, Apache-2.0
  model) and `KittenML/KittenTTS` (Apache-2.0).

### OCR Candidate Evaluation

- Taxonomy/reference: `Yuliang-Liu/MultimodalOCR` (OCRBench v2), MIT revision
  `8b24ed51d3cf810a470b141ed28fb50074cd5680`, reviewed 2026-07-15.
- PLOS use: independently authored native PHP validation for seven mandatory
  OCR capability families and absolute per-case promotion floors. No upstream
  source file, prompt, runtime, model, weight, or benchmark artifact is copied.
- Dataset boundary: OCRBench v2 component datasets retain their separate terms
  and are not bundled or authorized by the repository's code license.

### Offline Feature-Extension Evaluation

- Repository: `microsoft/FEA-Bench`, MIT revision
  `fb3c11274796f6057bf447410540fcf2fa1d90b1`, reviewed 2026-07-15.
- Paper: Li et al., "FEA-Bench: A Benchmark for Evaluating Repository-Level
  Code Generation for Feature Implementation," ACL 2025, DOI
  `10.18653/v1/2025.acl-long.839`, CC-BY-4.0.
- PLOS use: independently authored native PHP fixture for creating one new
  production component and integrating it into existing code, with protected
  public/hidden verification and null/golden/adversarial calibration.
- Artifact boundary: no upstream Python harness, task, scraped repository,
  dataset, prompt, model output, or runtime is copied or bundled. Benchmark
  tasks and source repositories retain their separate licenses.

### OpenTelemetry GenAI Trace Vocabulary

- Standard source: `open-telemetry/semantic-conventions-genai`, Apache-2.0,
  reviewed 2026-07-12 while its GenAI conventions remain development-stage.
- PLOS use: independently authored PHP mapping of existing model and agent audit
  rows to OTLP/JSON through `ops:gen-ai-telemetry-export`; no upstream runtime,
  SDK, collector, or source file is bundled.
- Privacy posture: content capture is always off. Prompts, completions, input and
  output summaries, context, tool arguments/results, raw session IDs, error
  text, and filesystem paths are excluded. Correlation identifiers are hashed.
- `Arize-ai/openinference` (Apache-2.0) is integrated as a dependency-free
  semantic mapping: every span carries its required kind and applicable
  content-free model, embedding, agent, tool, or installation-keyed session attributes.
  Input/output values, messages, documents, vectors, tool parameters, raw
  sessions, and exception details remain excluded.
- Phoenix is not installed or configured. Current Phoenix can accept OTLP and
  convert OpenTelemetry GenAI attributes at ingest, but any future pilot must
  remain operator-controlled with private binding, authentication, finite
  retention, and analytics disabled. Phoenix's Elastic-2.0 license and product
  boundary remain separate from the Apache-2.0 OpenInference specification.
- Research influence: Dong, Lu, and Zhu, "AgentOps: Enabling Observability of
  LLM Agents," arXiv:2411.05285 (2024), taxonomy/ideas only.

### Agent Memory Research And Evaluation

- `getzep/graphiti`, `mem0ai/mem0`, and `mem0ai/memory-benchmarks` are
  Apache-2.0 research and behavior references; no package, service, or upstream
  source file from them is bundled by this integration.
- PLOS independently implements its MySQL/pgvector episodic memory and
  PostgreSQL bi-temporal knowledge graph. Current upstream evidence informed an
  additive-link rule: vector similarity may establish related/extends links but
  cannot establish contradiction, replacement, or supersession.
- MemoryAgentBench (Hu, Wang, and McAuley, arXiv:2507.05257, 2025) informed the
  future deterministic competency taxonomy: retrieval, test-time learning,
  long-range understanding, and selective forgetting.
- `episodic:memory --prune-archived-embeddings` is PLOS-authored maintenance;
  it deletes only embeddings for summaries already archived under PLOS policy.

### Agent Behavior Evaluation

- `promptfoo/promptfoo` (MIT) informed the security fixture categories:
  prompt injection, data leakage, structured output, MCP/tool calls, and
  deterministic regression assertions.
- `app/Support/SpreadsheetSafeCsv.php` adapts Promptfoo's small MIT-licensed
  `escapeCsvFormula` trigger-detection pattern at revision
  `fcde2e89a89dc4ca79dcc3012927f50193251759`. PLOS rewrites it in PHP, removes
  the disable override, adds OWASP full-width and leading-control triggers, and
  centralizes RFC-style encoding for native genealogy/research exports. The
  upstream copyright and MIT permission notice are retained in `NOTICE.md`.
- `confident-ai/deepeval` revision
  `58c9ef78a4634ba119c7d2cc145f5cf9aeb24524` (Apache-2.0) informed exact
  JSON-shape, tool-call, and input-parameter correctness checks plus the
  separation between deterministic correctness and model-judged optimality.
  The latest delta changes release metadata only.
- PLOS did not install, vendor, or copy either framework. The independently
  authored `ops:agent-behavior-golden-eval` command runs config-backed fixtures
  against existing PLOS production services. It performs no LLM, network, or
  database I/O and never returns raw fixture content. Contamination audit logs
  retain only content length and a short SHA-256 fingerprint, not content
  previews.
- The same integration moved fact-check aggregation into the shared
  `EvidenceVerdictPolicyService`, corrected single-item abstention confidence,
  and added regression coverage for mixed/contradicting evidence and unknown
  offline profiles.
- Zhao et al., "Debugging the Debuggers: Failure-Anchored Structured
  Recovery for Software Engineering Agents," arXiv `2605.08717v2`, revised
  2026-06-05, is used for factual method and design ideas under the arXiv
  non-exclusive distribution boundary. PLOS independently implements a
  content-free deterministic diagnosis/action gate over its existing
  trajectory evidence. No paper text, code, dataset, prompt, model, judge,
  trace, or runtime is copied, and the result grants no execution authority.

### Logging Privacy Guidance

- OWASP Cheat Sheet Series revision
  `ae0b3f22f5b24381455f742af9b5fa84063ad770` is licensed CC-BY-SA-4.0.
  Its Logging and Logging Vocabulary cheat sheets informed the PLOS policy to
  exclude/de-identify sensitive content, avoid full prompt/tool payloads, and
  remove query-bearing URLs from logs.
- PLOS independently implements that policy in native PHP structured-context
  processing and purpose-scoped receipts. No OWASP prose, example payload,
  runtime, package, or implementation source is copied into PLOS.
- Monolog 3.10.0 revision
  `b321dd6749f0bf7189444158a3ce785cc16d69b0` is an existing MIT Composer
  dependency. Its normalizer serializes exception messages, files, traces, and
  previous exceptions by default; PLOS independently intercepts `Throwable`
  values before that formatter boundary and retains only type, bounded code,
  and previous types. Current upstream head
  `57eb1028342134e701e77c617565d51b6e5a2a53` was reviewed 2026-07-16.
- OpenTelemetry Semantic Conventions 1.43.0 revision
  `436fa25713048054e035896c14a72139f8638ea8` is Apache-2.0. Its stable
  exception-log convention warns that exception messages may be sensitive and
  permits type-based classification when the message is omitted. PLOS uses the
  standard as a behavioral reference; no OpenTelemetry SDK, collector, source,
  telemetry export, or runtime is added.
- `nikic/php-parser` 5.8.0 revision
  `044a6a392ff8ad0d61f14370a5fbbd0a0107152f` is a direct BSD-3-Clause
  Composer dependency. PLOS uses its AST API in a native, fail-closed audit of
  PHP log-message expression shapes; no parser source is vendored or modified.
  Current upstream main `fbd47f7ebcbb450138d92642a0a53b72a5285dda`
  was reviewed 2026-07-16.
- `psr/log` 3.0.2 revision
  `f16e1d5863e37f8d8c2a01719f5b34baa2b714d3` is an existing MIT Composer
  dependency. Its message, placeholder, context, and `exception` contracts
  inform the audit categories and stable-event guidance.
- Kip et al., *Privacy-Preserving Redaction of Diagnosis Data through Source
  Code Analysis*, arXiv:2409.17535v1 and DOI
  `10.1145/3603719.3603734`, and Chen et al., *Towards Secure Logging:
  Characterizing and Benchmarking Logging Code Security Issues with LLMs*,
  arXiv:2604.20211v1, are used for cited facts and ideas only. PLOS copies no
  paper text, code, corpus, dataset, prompt, model output, or repair runtime and
  does not claim paper-equivalent data-flow analysis.

### Deployment Source Membership Guidance

- Git revision `d35c5399e3e54ac277bb391fc2f6be3e816d312b` is GPL-2.0-only.
  Official checkout and clean documentation establishes that forced checkout
  removes untracked entries only when they obstruct checkout, while complete
  untracked cleanup is a distinct force-gated operation. PLOS uses these facts
  only and copies no Git source or documentation text.
- rsync revision `f5fa55672db9c65dffb8698eeab3173a93692e16` is
  GPL-3.0-or-later. Its official manual recommends a dry run before deletion.
  PLOS adopts the preview-first safety principle but neither installs nor
  invokes rsync, copies source, or enables a deletion path.
- Capistrano revision `6007a972497ce7d05415b78dbba451c89438f996` is MIT.
  Its fresh release-directory and final symlink activation structure is a
  longer-term deployment reference. PLOS imports no Ruby package, task,
  runtime, or source.
- Lamb and Zacchiroli, *Reproducible Builds: Increasing the Integrity of
  Software Supply Chains*, arXiv:2104.06020v1, is used for cited source-to-
  artifact identity and manifest/checksum concepts only. PLOS does not claim
  bit-for-bit reproducible builds, independent builders, or paper-equivalent
  attestation and copies no paper artifact.

### Retrieval And Reranker Evaluation

- `deepset-ai/haystack` revision
  `acc9e58a66d038622cafe40fe863691f812a66e6` is Apache-2.0.
  `app/Services/ContextPositioningService.php` adapts its
  `LostInTheMiddleRanker` ordering algorithm and retains upstream copyright,
  SPDX license, source revision, and PLOS modification notes in the file.
  PLOS operates on native RAG result arrays, retains retrieval rank, uses
  stable document identities, and exposes context position. No Haystack
  package or Python runtime is installed.
- Liu et al., "Lost in the Middle: How Language Models Use Long Contexts,"
  TACL 12 (2024), pages 157-173, provides the positional-bias research basis.
  PLOS copies no paper text or data.
- `embeddings-benchmark/mteb` revision
  `08f42e7615f3dc931cf6f0cf844813f7cfc26bf7` (Apache-2.0),
  `beir-cellar/beir` revision
  `ef83d29307061c65d04b035b4f4e7c18bd8374af` (Apache-2.0 code), and
  `amazon-science/RAGChecker` revision
  `6091f08c00e676e87a970f2aeb4a23a484746348` (Apache-2.0) informed
  heterogeneous cases, graded qrels, hard negatives, completeness, and noise
  diagnostics in `rag:rerank-eval` v2.
- `QwenLM/Qwen3-Embedding` revision
  `44548aa5f0a0aed1c76d64e19afe47727a325b8f` (Apache-2.0 repository)
  informed query-side task-instruction formatting for embedding challengers.
  Official `Qwen/Qwen3-Reranker-0.6B` model revision
  `e61197ed45024b0ed8a2d74b80b4d909f1255473` reports Apache-2.0. Its
  1,191,588,280-byte safetensors artifact is operator-downloaded and verified
  against SHA-256
  `27cd75a405b9c1b46b59abfd88aaa209e6fed2a1972cde9b70e7659537c5e65b`;
  model weights are not bundled.
- PLOS did not install, vendor, or copy these frameworks, datasets, model
  weights, or source files. The PHP metric implementation and all eight
  PLOS/Genea fixtures are independently authored. BEIR and other benchmark
  datasets remain subject to their individual source licenses and citations.
- The optional CPU runtime pins package-managed Torch `2.11.0`, Transformers
  `4.57.6`, Tokenizers `0.22.2`, and Safetensors `0.7.0` in an isolated venv.
  The sidecar is loopback-only, installation-key authenticated, content-log
  free, and available only to read-only evaluation; it is not a production RAG
  route.

### MCP Lifecycle And Structured Results

- The MCP `2025-06-18` tools specification and `PrefectHQ/fastmcp`
  (Apache-2.0) informed request-context, timeout, annotations, and structured
  result behavior. PLOS continues using its existing official TypeScript MCP
  SDK dependency; FastMCP is not installed or copied.
- The PLOS stdio server now propagates sender cancellation, refuses requests
  cancelled before dispatch, aborts cooperating handlers on timeout, and clears
  successful-call timers. Every tool publishes conservative read/write/
  destructive/open-world hints without weakening server policy.
- JSON-object results retain the required backward-compatible text block and
  also expose `structuredContent`; native Genealogy context/batch tools publish
  object output schemas. JSON arrays stay text-only until the relevant newer
  protocol contract is stable in the production SDK/client set.

### Agent Skills Portability

- The `agentskills/agentskills` specification and repository were reviewed at
  revision `38a2ff82958afee88dadf4831509e6f7e9d8ef4e` on 2026-07-15. The
  upstream code license is Apache-2.0 and its documentation is CC-BY-4.0.
- PLOS independently implements the specification checks in
  `AgentSkillAuditService`: required YAML `name` and activation-oriented
  `description`, portable naming and directory alignment, optional standard
  fields, a non-empty Markdown instruction body, and cautions above the
  recommended 500-line or estimated 5,000-token activation budgets. PLOS also
  provides an independently authored context-bound one-level reference reader.
- `SkillLoaderService` can return standard discovery fields and render a
  portable `SKILL.md`. PLOS-specific capability and execution metadata is
  isolated under `x_plos` in the structured export and omitted from portable
  frontmatter. No upstream runtime, package, or implementation source is
  installed or bundled.
- NVIDIA SkillSpector revision
  `a68496dc9220c78c3daa13cfb2f18036f1a79494` remains an Apache-2.0 optional
  advisory scanner. PLOS independently adds complete bundle hashing and
  scheduled content-drift checks; no new SkillSpector source or dependency is
  bundled by this continuation.
- NVIDIA Agent Skills catalog revision
  `80a66a7a45c5f696a0a539154cc8b172f6b1cf1f` is an Apache-2.0 code and
  CC-BY-4.0 documentation trust-pipeline reference. PLOS records the presence
  of skill cards, detached signatures, evaluation data, and benchmarks without
  copying catalog skills or claiming signature verification.
- opencode MIT revision `a27ffb20f0a740823142f7c79706efb81d75d287`
  informed the independently authored trusted delegation-depth control. No
  opencode source, package, prompt, model, or runtime is bundled.
- The community `mukul975/Anthropic-Cybersecurity-Skills` repository was
  reviewed at Apache-2.0 revision
  `673da1f3b0b7be34ffc9624ef3858fe45f1c3bed`. Selected defensive guidance
  informed PLOS's independently authored indirect-injection tool gate. No
  upstream skill text, offensive workflow, package, model, prompt, or runtime
  is bundled.

### Browser Automation Control Plane

- `microsoft/playwright-mcp` (Apache-2.0) informed isolated browser contexts,
  allowlisted network posture, service-worker/download controls, accessibility
  snapshots, and the explicit warning that browser automation is not itself a
  security boundary. PLOS did not install or copy the Playwright MCP server.
- `modelcontextprotocol/inspector` revision
  `638f2bfa5b90f790e89dfb0a2c48feef7af226e4` (Apache-2.0/MIT transition;
  documentation CC-BY-4.0) informed bearer authentication, loopback binding,
  Origin/DNS-rebinding protection, CLI contract checks, and typed client
  connection/timeout evidence. PLOS independently implements a content-free
  one-attempt probe receipt and did not install or copy its UI, proxy, source,
  config, or runtime.
- PLOS independently applies those controls to its existing Puppeteer and
  Playwright HTTP sidecars. The bearer token is derived from the installation
  `APP_KEY`, is not stored in browser content, and is required on every endpoint.
  Playwright accessibility snapshots are capped and labeled untrusted page
  content; they are evidence for deterministic UI checks, never agent
  instructions or unattended-action authority.
- `snyk/agent-scan` (Apache-2.0), pinned at
  `f3a4621a089c937ee3586917e04a4c26a6c6d27c`, informed MCP metadata threat
  categories. PLOS did not copy or install its scanner, execute arbitrary MCP
  configs, call its external service, or upload tool metadata.
- Cohen et al., *MCP Security Bench*, arXiv:2510.15994v2, informed planning-,
  invocation-, and response-stage attack categories. PLOS independently added
  a native planning-stage tool-contract gate and copied no paper text,
  benchmark corpus, model, prompt, response, or implementation artifact.
- Microsoft Agent Governance Toolkit `v4.1.0`, MIT revision
  `b680c49cc956727c5249771ddba7ee21a635a676`, and Liu, *From Tool Connection
  to Execution Control*, arXiv:2606.29073v1, informed deterministic
  pre-dispatch governance, scoped invocation, and content-free denial evidence.
  PLOS independently validates advertised tool schemas before dispatch and did
  not install or copy the toolkit runtime, paper, benchmark, or reference code.

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
- Colibri runtime binaries and GLM-5.2 community model weights are installed on
  an operator-owned private host and remain outside PLOS source and exports.
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

## Coding-Agent Repeatability Research

PLOS cites Brian Sam-Bodden, *What Context Does a Coding Agent Actually Need to
Act?*, arXiv:2607.09691v1 (CC-BY-4.0), for its reported temperature-zero
repeatability finding. PLOS independently implemented a native repeat-consistency
promotion gate. No paper text, instrument, code, dataset, prompt, patch, output,
model, or runtime is distributed with PLOS. The paper's context-compression
approach is not adopted.

## Synthetic portrait demonstration

`examples/photo-knowledge/` contains portraits that Kreavo describes as AI-generated, licensed under CC BY 4.0, separate from the application MIT license. Photo: [Kreavo (kreavo.ai)](https://kreavo.ai/free-ai-influencer-photos), [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Demonstration derivatives are resized and add fictional names and face-region metadata. See [complete provenance](examples/photo-knowledge/PROVENANCE.md) and `PROVENANCE.json` for source URLs, changes and file hashes. No numerical face embeddings are included.

## October 12, 2026 public-candidate dependency review

The current dependency snapshot and [license notes](docs/public-release/npm-license-notes-2026-10-12.md) record exact-version optional-platform metadata and the selected MIT/Apache license options where upstream offers alternatives. The full mixed MCP reference-server source license is preserved in `LICENSES/MCP-servers-2026.7.4.txt`. SDK1.31.0 and proxy-addr2.0.8 address the corresponding published advisories; remaining dependency findings are tracked in candidate readiness. The unused optional Graphlit MCP npm package is excluded from the default public bundle because its dependency chain has unresolved advisories. Native Graphlit service source remains optional/operator-configured.

## v2.1 frontend compiler

Tailwind CSS/Oxide 4.3.3 retain MIT notices. Lightning CSS 1.32.0 and its platform wrappers retain MPL-2.0 terms and applicable binary notice/source-availability obligations. No native dependency binaries are committed. See the locked npm license snapshot and [frontend qualification](docs/public-release/ui-dependency-upgrade-2026-10-12.md).
