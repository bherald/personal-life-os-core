# Research And Provenance Notes

This note is the compact public-release register for projects and research that
influenced PLOS. It separates three different things that should not be mixed:
direct package-manager dependencies, system/runtime tools an operator installs,
and inspiration/reference projects whose code should not be copied.

## Release Risk Summary

The highest public-release risk is still copied GPL/AGPL implementation code,
not citation, interoperability, or package-manager use. Treat Gramps, Gramps
Web, webtrees, PhotoPrism, and Joplin as design references unless the public
license strategy intentionally changes. See `THIRD_PARTY.md` for the active
license gate.

Direct dependencies such as Topola, MCP SDK packages, pgvector clients, and
normal Laravel/Vue/npm/Composer packages are handled through package-manager
license review. Keep their package metadata and lockfiles intact, preserve
required notices, and avoid vendoring upstream source outside the dependency
manager.

Model weights, dlib face model files, spaCy models, Docker images, Ollama model
files, and service binaries are not redistributed by PLOS. Public docs should
tell operators how to install them and where to verify upstream terms.
Redis-compatible service images should remain a release checklist item; PLOS
uses a queue/cache service API, not bundled Redis source.

## Referenced Project Register

| Project or standard | PLOS relationship | Release posture |
| --- | --- | --- |
| Laravel framework and first-party Laravel tools | Primary PHP application framework, queue dashboard, OAuth/API auth, REPL, logs, formatter, Docker helper, and Vite integration. | Direct Composer/npm dependencies; preserve lockfile/license metadata. |
| Vue | Primary frontend framework. | Direct npm dependency; preserve package-manager metadata. |
| Tailwind CSS | Utility CSS framework for public UI. | Direct npm dependency; preserve package-manager metadata. |
| MySQL, MariaDB, PostgreSQL, Redis-compatible service | Core data stores and queue/cache/runtime services. | Operator-installed or Docker-provided services; document supported versions and image/license choices. |
| Apache Tika | Optional document text-extraction service. | Operator-installed service; document setup and upstream terms. |
| Nextcloud | Optional same-host/LAN file, calendar, contacts, media, and Joplin sync layer. | AGPL service/image reference; PLOS should pull/run it as an operator service, not vendor or modify Nextcloud code in the MIT core. |
| Thunderbird | Local mail/calendar client boundary for desktop and Android workflows. | MPL software/tool boundary; PLOS should document local integration points, not bundle Thunderbird code. |
| SearXNG | Optional privacy-preserving metasearch service for research/search workflows. | AGPL service/image reference; keep as optional operator-pulled service unless license strategy changes. |
| Gramps | Genealogy data-model and mature desktop workflow reference. | GPL family; inspiration only unless license strategy changes. |
| Gramps Web | Collaborative genealogy, privacy, charts, maps, DNA/chromosome workflow reference. | AGPL family; inspiration only unless license strategy changes. |
| webtrees | PHP/web GEDCOM-compatible collaborative genealogy reference. | GPL family; inspiration only unless license strategy changes. |
| PhotoPrism | Photo-library workflow reference for face review, people curation, semantic search, and metadata writeback discussions. | AGPL family; do not copy code into MIT public core. |
| LibrePhotos | Photo-library and ML workflow reference. | MIT signal, but still review before copying; current posture is inspiration. |
| Joplin | Operator-managed notes sync target and notes workflow reference. | AGPL family; PLOS may implement interoperable sync-target behavior, but should not copy Joplin app, sync, lock, or server source. |
| Topola | Direct npm dependency for genealogy tree visualization. | Apache-2.0 package-manager dependency; preserve lockfile/license metadata and avoid vendoring modified upstream source without notice review. |
| Model Context Protocol SDK/servers | Direct npm dependency and public tool protocol reference. | MCP project is transitioning license posture; keep dependency use through npm and review bundled docs/spec notices before release. |
| Graphlit MCP server | Optional npm MCP server package for Graphlit-backed search/content tooling. | Package metadata reports MIT; Graphlit service/API terms remain separate and should be optional in public installs. |
| Nextcloud MCP server | Optional npm MCP bridge for local/LAN Nextcloud workflows. | Package metadata reports MIT; Nextcloud server itself remains an AGPL external service boundary. |
| pgvector | PostgreSQL vector extension/runtime capability. | Operator-installed database extension; cite and document as runtime dependency. |
| Ollama | Local model runtime/API compatibility target. | Ollama code is MIT, but model weights served by Ollama have separate terms. Do not redistribute model files. |
| dlib and face_recognition | Python face detection/embedding stack. | Permissive code dependency signals, but dlib model files stay external with checksums in `docs/FACE-RECOGNITION.md`. |
| hdbscan | Face/person clustering support. | Permissive Python dependency; cite library/paper and keep package-manager install path. |
| ExifTool, XMP, IPTC, MWG regions | Metadata read/write compatibility for face/person data. | Standards/tool compatibility, not copied application code. Prefer sidecars or previewed writes for risky originals. |
| FamilySearch GEDCOM 7 | Genealogy file-format/spec reference. | Specification reference only. This is not FamilySearch API integration. |
| mrmysql/youtube-transcript | Composer package used in the YouTube transcript fallback chain. | Package metadata reports WTFPL; YouTube access still has service terms separate from code license. Keep public docs clear that operators are responsible for API/site terms. |
| Ancestry and FamilySearch | Manual/browser-only genealogy source references. | Public core should not advertise unsupported autonomous APIs or background scraping for these services. |
| MyHeritage and Newspapers.com | Private opt-in research sources for operator-owned credentials and terms-compliant access. | Keep out of public defaults; document as private connector/profile behavior when enabled. |
| FindAGrave, BillionGraves, WikiTree, LOC, NARA, Internet Archive | Genealogy source providers and research references. | Prefer official APIs, public-domain archives, respectful rate limits, and manual/browser review where terms are unclear. Do not present scraping as guaranteed public functionality. |

## Developer-Agent Reference Watch List

These projects are references for approval gates, command execution boundaries,
local-model fallback, trace logging, and public-release safety. They are not
PLOS dependencies. Do not copy their source, prompts, rules, fixtures, UI text,
or documentation wording.

Snapshot verified on 2026-04-29 from public project pages and repositories.
Re-check before citing in release notes or outward-facing articles.

| Project | Public source | License signal | PLOS-safe reference posture |
| --- | --- | --- | --- |
| OpenAI Codex CLI | https://github.com/openai/codex | Apache-2.0 license file. | Approval-gated terminal coding-agent reference. Source-readable, but no copying. |
| Cline | https://github.com/cline/cline and https://cline.bot/ | Apache-2.0 project signal; service terms are a separate review surface. | IDE agent reference for approval-before-action patterns. Prefer docs-level study before source reuse decisions. |
| Roo Code | https://github.com/RooCodeInc/Roo-Code | Apache-2.0 license file. | Cline-lineage reference for local/offline model support and role/mode concepts. |
| Aider | https://github.com/aider-ai/aider and https://aider.chat/ | Apache-2.0 project signal. | Terminal pair-programming reference for repo maps, diff review, and git checkpoints. |
| Continue | https://github.com/continuedev/continue and https://continue.dev/ | Apache-2.0 project/repository signal. | Reference for source-controlled AI checks and model-provider configurability. |
| OpenHands | https://github.com/OpenHands/OpenHands and https://docs.openhands.dev/ | MIT for core work, with a separate `enterprise/` license boundary. | Containerized multi-tool agent reference; license-boundary case study. |
| SWE-agent | https://github.com/swe-agent/swe-agent and https://swe-agent.com/ | MIT project signal. | Academic issue-to-patch and agent-computer-interface reference; avoid offensive-security framing in public PLOS docs. |
| goose | https://github.com/aaif-goose/goose and https://goose-docs.ai/ | Apache-2.0 project signal; AAIF/Linux Foundation transition noted by upstream. | MCP-extension-first local agent reference. |
| Claw Code | https://claw-code.codes/ | Claims open-source clean-room rewrite; repository/license must be verified before any source-level study. | High-caution citation only. Use as a public example of why clean-room boundaries and source-provenance review matter. |

## Laravel Toolchain Used

PLOS is not just "built on Laravel" in a generic sense. The public dependency
and citation surface should name the Laravel pieces that carry operational
responsibility:

| Tool | PLOS use |
| --- | --- |
| Laravel Framework | HTTP routing, service container, console commands, events, queues, scheduler, filesystem, validation, and database integration. |
| Laravel Horizon | Redis queue supervision, worker health, queue metrics, failed-job visibility, and long-running workflow isolation. |
| Laravel Passport | OAuth key generation and API authentication support for public installs. |
| Laravel Tinker | Controlled local/prod inspection through `artisan tinker` and service-level diagnostics. |
| Laravel Pint | PHP formatting gate used before commits and public smoke work. |
| Laravel Pail | Local log tailing in the `composer dev` workflow. |
| Laravel Sail | Development environment reference, even though PLOS also ships its own Docker compose scaffold. |
| Laravel Vite Plugin | Frontend build integration between Laravel, Vite, and Vue. |

## Research Citation Backbone

Use these as the default citation set for README expansions, white papers,
articles, and conference-style writeups. The publication drafts in
`docs/papers-and-newsletters/` should cite these directly instead of relying on
generic phrases such as "research-based RAG."

- Otwell, T. and Laravel contributors. Laravel Framework: The PHP Framework for
  Web Artisans. https://github.com/laravel/framework and https://laravel.com/
- Laravel contributors. Laravel Horizon: dashboard and code-driven
  configuration for Laravel queues. https://github.com/laravel/horizon and
  https://laravel.com/docs/horizon
- Laravel contributors. Laravel Passport: OAuth2 server support for Laravel.
  https://github.com/laravel/passport
- Laravel contributors. Laravel Tinker, Pint, Pail, Sail, and Vite Plugin.
  https://github.com/laravel/tinker
  https://github.com/laravel/pint
  https://github.com/laravel/pail
  https://github.com/laravel/sail
  https://github.com/laravel/vite-plugin
- You, E. (2014-present). Vue.js: The Progressive JavaScript Framework.
  https://github.com/vuejs/core and https://vuejs.org/
- Tailwind Labs. Tailwind CSS. https://github.com/tailwindlabs/tailwindcss and
  https://tailwindcss.com/
- PostgreSQL Global Development Group. PostgreSQL. https://www.postgresql.org/
- Oracle and contributors. MySQL. https://www.mysql.com/
- MariaDB Foundation. MariaDB Server. https://mariadb.org/
- Redis/Valkey-compatible queue and cache service references:
  https://redis.io/ and https://valkey.io/
- Apache Software Foundation. Apache Tika. https://tika.apache.org/
- Nextcloud contributors. Nextcloud server and Docker image references.
  https://github.com/nextcloud/server and https://github.com/nextcloud/docker
- Mozilla. Thunderbird and Mozilla Public License 2.0.
  https://www.thunderbird.net/ and https://www.mozilla.org/en-US/MPL/2.0/
- SearXNG contributors. SearXNG metasearch engine.
  https://github.com/searxng/searxng and https://docs.searxng.org/
- Staufer, L., Feng, K., Wei, K., Bailey, L., Duan, Y., Yang, M., Ozisik, A. P.,
  Casper, S., & Kolt, N. (2026). The 2025 AI Agent Index: Documenting Technical
  and Safety Features of Deployed Agentic AI Systems. arXiv:2602.17753.
  https://arxiv.org/abs/2602.17753 and https://aiagentindex.mit.edu/
- Edge, D., Trinh, H., Cheng, N., Bradley, J., Chao, A., Mody, A., Truitt, S.,
  Metropolitansky, D., Ness, R. O., & Larson, J. (2024). From Local to Global:
  A Graph RAG Approach to Query-Focused Summarization. arXiv:2404.16130.
  https://arxiv.org/abs/2404.16130
- Sarthi, P., Abdullah, S., Tuli, A., Khanna, S., Goldie, A., & Manning, C. D.
  (2024). RAPTOR: Recursive Abstractive Processing for Tree-Organized
  Retrieval. arXiv:2401.18059. https://arxiv.org/abs/2401.18059
- Formal, T., Piwowarski, B., & Clinchant, S. (2021). SPLADE: Sparse Lexical and
  Expansion Model for First Stage Ranking. SIGIR 2021. https://arxiv.org/abs/2107.05720
- Santhanam, K., Khattab, O., Saad-Falcon, J., Potts, C., & Zaharia, M. (2022).
  ColBERTv2: Effective and Efficient Retrieval via Lightweight Late Interaction.
  NAACL 2022. https://aclanthology.org/2022.naacl-main.272/
- Cormack, G. V., Clarke, C. L. A., & Buettcher, S. (2009). Reciprocal Rank
  Fusion Outperforms Condorcet and Individual Rank Learning Methods. SIGIR 2009.
- Carbonell, J., & Goldstein, J. (1998). The use of MMR, diversity-based
  reranking for reordering documents and producing summaries. SIGIR 1998.
- Malkov, Y. A., & Yashunin, D. A. (2018). Efficient and robust approximate
  nearest neighbor search using Hierarchical Navigable Small World graphs. IEEE
  TPAMI.
- Campello, R. J. G. B., Moulavi, D., & Sander, J. (2013). Density-Based
  Clustering Based on Hierarchical Density Estimates. PAKDD 2013.
- McInnes, L., Healy, J., & Astels, S. (2017). hdbscan: Hierarchical density
  based clustering. Journal of Open Source Software. https://joss.theoj.org/papers/10.21105/joss.00205
- King, D. E. (2009). Dlib-ml: A Machine Learning Toolkit. Journal of Machine
  Learning Research.
- Katz, A. (2023). pgvector: Open-source vector similarity search for Postgres.
  https://github.com/pgvector/pgvector
- Ollama Team. (2023). Ollama: Get up and running with large language models
  locally. https://github.com/ollama/ollama
- MrMySQL. youtube-transcript. https://github.com/MrMySQL/youtube-transcript
- Graphlit. graphlit-mcp-server. https://github.com/graphlit/graphlit-mcp-server
- Abdullah MASHUK. nextcloud-mcp-server.
  https://github.com/abdullahMASHUK/nextcloud-mcp-server
- FamilySearch. FamilySearch GEDCOM 7 specification.
  https://gedcom.io/specifications/FamilySearchGEDCOMv7.html
- ExifTool tag references for XMP/MWG/IPTC-compatible face and person metadata.
  https://exiftool.org/TagNames/MWG.html and https://exiftool.org/TagNames/XMP.html
- IPTC Photo Metadata Standard. https://iptc.org/standards/photo-metadata/
- Adobe XMP specification index. https://developer.adobe.com/xmp/docs/
- Board for Certification of Genealogists. (2000). The BCG Genealogical
  Standards Manual. Use as the genealogy proof-standard citation in public
  genealogy articles.

## Public Release Guidance

When preparing a public repository, make the README dense and practical:
describe what PLOS does, how to install it, the local-first boundary, the public
core/private overlay split, and where provenance lives. Keep this file and
`THIRD_PARTY.md` as the canonical answer for "what did PLOS learn from?" and
"what licenses need attention?"

Future watch-list projects such as vLLM, LiteLLM, LangGraph, CrewAI, MCPEval,
and agent-context-engine are research or integration ideas until PLOS adds them
as dependencies or copies implementation material. Track them here only when
they become public-release surface area.
