# Public Release Readiness

Status on 2026-05-02: **public-extraction candidate files pass the local audit,
repeatable local export smoke, deploy smoke/health gates, focused media/face
regression tests, fixture/Joplin provenance checks, CI parity checks, npm/Python
license snapshot checks, npm audit gates for exported workspaces, and a
disposable Docker/live-database proof for the core Compose stack**. A separate
Ubuntu Hyper-V proof VM also passes the history-free public export smoke from
scratch and boots the Docker Compose core stack with live MySQL,
PostgreSQL/pgvector, Redis, app, web, worker, and scheduler services. The first
public push still needs GitHub Actions validation in a fresh public repository
plus formal dependency freeze/signoff, final native/ML package signoff,
license-warning triage, and any optional media add-on proof advertised beyond
the current evidence. On 2026-05-01, the operator approved this Ubuntu Hyper-V
VM as the designated clean media proof machine for first-release core + media
evidence, with no private prod data attached. GPU is approved as
optional/experimental for the first public release; clean-host GPU proof is
required only before making a supported GPU claim.
The release process is content-bounded: create a clean public repository, leave
private history/data/docs behind, and verify the exported tree before
publishing.

Public docs IA evidence is now source-backed rather than plan-only. A
2026-05-01 local source-tree and export-allowlist check confirms the first-push
reader path is present and exported: root `README.md`, `LICENSE`, `NOTICE.md`,
`SECURITY.md`, `CONTRIBUTING.md`, `THIRD_PARTY.md`; `docs/README.md`;
`docs/quickstart.md`; `docs/operation.md`; `docs/troubleshooting.md`;
`docs/roadmap.md`; `docs/security-privacy.md`;
`docs/clean-room-references.md`; `docs/public-install-prerequisites.md`;
`docs/public-release-readiness.md`; `docs/public-github-first-push-checklist.md`;
and `docs/architecture.md`. `NOTICE.md` is accepted as the compact
public-facing notices entry point, with `THIRD_PARTY.md` and the license maps as
the detailed ledgers. `docs/public-github-first-push-checklist.md` is accepted
as a separate executable first-push runbook rather than folded into this
readiness overview. `docs/plos-runtime-architecture.md` remains as a
compatibility pointer for historical references. Public Docs IA naming and
placement decisions are resolved for first-push validation.

Run the local blocker scan:

```bash
scripts/guards/public-release-audit.sh
```

The guard exits non-zero when blockers exist in the tracked public-candidate surface. It excludes known private-only docs by path and is not a prod deploy gate.

The first public GitHub push steps live in
`docs/public-github-first-push-checklist.md`. Use that checklist only from the
history-free export, never from the private source repository.

## Pre-Tag Operator Gates

Before creating the first public tag, run a dependency freeze phase after the
final clean export smoke. During the freeze, changes to `composer.lock`,
`package-lock.json`, `mcp-server/package-lock.json`, or
`requirements*.constraints.txt` restart the smoke and license signoff loop.

Release evidence should include passing output from
`scripts/guards/public-release-audit.sh`, `scripts/audit-licenses.sh`,
`scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"`,
the generated `PUBLIC_EXPORT_MANIFEST.md`, clean exported `git status --short`,
`docs/public-release/privacy-secret-scan-baseline-2026-04-29.md`, and the
GitHub `Public Readiness` workflow. If GitHub secret scanning is enabled,
record that result with the same evidence bundle. The public smoke currently
proves `setup:doctor --profile=core --skip-services` plus
`setup:doctor --profile=media --skip-services --only=assets,browser,docker`;
GPU and full profile evidence remains outside the smoke until clean-host proof
exists.

Every license warning must be triaged before tagging as fixed, accepted with
rationale, optional operator-installed, or release-blocking. The governance
diligence packet for review is `README.md`, `LICENSE`, `SECURITY.md`,
`CONTRIBUTING.md`, `THIRD_PARTY.md`, `docs/model-runtime-license-map.md`,
`docs/python-constraints-license-snapshot.md`,
`docs/native-ml-package-review.md`, and this document.
Use `docs/public-release/final-signoff-trail-2026-05-01.md` as the compact
final signoff checklist before the first public tag.

Post-release support setup is also a tag gate: confirm the public issue/security
intake, maintainer response owner, supported install profile, and unsupported
optional profiles before announcing the tag.

The install/dependency target inventory now lives in `docs/public-install-prerequisites.md`. It includes Docker, same-host Nextcloud with filesystem-first access, WebDAV fallback, media/RAG/genealogy binaries, Python packages, browser tooling, and public/private data boundaries.

Seed a fresh public-candidate tree with:

```bash
scripts/public-export.sh --force "$HOME/tmp/personal-life-os-core"
```

The export script uses a tracked-file allowlist, rejects destinations inside the
private repo, initializes a new git repository, stages the copied files, writes a
`PUBLIC_EXPORT_MANIFEST.md`, and runs the public audit guard. It is a
content-bounded extraction, not a branch-based release process.

Run the repeatable local smoke path with:

```bash
scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"
```

2026-04-30 smoke evidence: the selected-path smoke run passed after Composer
install, root and MCP-workspace `npm ci`, root and MCP-workspace `npm audit`,
Python core venv install, key generation, frontend build, both setup doctor
slices, public audit, npm/Python license snapshot checks, license audit, full
public-release script syntax checks, staged diff checks, and the focused public
PHPUnit suite. Result: 130 tests / 19,901 assertions. Expected non-blocking
warnings remain the documented license-audit warnings, optional media asset
warnings from the local media doctor slice, and the Vite `GenealogyView` chunk
size warning. After npm snapshot license-file fallback and SPDX normalization,
the license audit warning count is 12. The local first-push export manifest was
also refreshed at `2026-04-30T20:12:45Z` with 1,569 tracked files staged; the
manifest records the exact source commit for that export.

2026-05-01 smoke evidence: after adding the media Python license
snapshot to the export allowlist, `scripts/public-smoke.sh --force
"$HOME/tmp/personal-life-os-core-smoke"` passed again from source commit
`e7235d02bdb1703282da0d36b18a158e16f97fc5`. The exported manifest was generated
at `2026-05-01T12:59:33Z` with 1,584 tracked files staged. Composer install,
root/MCP workspace `npm ci`, root/MCP workspace `npm audit`, Python core venv
install, key generation, frontend build, setup doctor slices, public audit,
npm/Python license snapshot checks, license audit with 12 documented warnings,
script syntax, staged diff checks, and focused public tests passed. Result: 131
tests / 20,117 assertions. The first-push working tree at
`$HOME/tmp/personal-life-os-core` was later refreshed from source commit
`10558bf273e1fd8243ae2b06dbf2bbc3ed87682e`; its manifest was generated at
`2026-05-01T13:07:49Z` with 1,584 tracked files staged.

2026-05-02 current smoke evidence: after refreshing public smoke blockers,
adding the read-only genealogy remediation preview slice, and deploying the
read-only review backlog report,
`scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"`
passed from source commit `23e9a62248403e97b1a2212a3f496543a04d5c2b`. The
exported manifest was generated at `2026-05-02T14:11:15Z` with 1,611 tracked
files staged. Public audit, Composer install, root/MCP workspace `npm ci`, root
and MCP workspace `npm audit`, Python core venv install through the pinned core
constraints, key generation, frontend build, setup doctor core and media smoke
slices, npm/Python license snapshot checks, license audit with 12 documented
warnings, script syntax, staged diff checks, and focused public tests passed.
Result: 138 tests / 22,081 assertions. The media setup doctor slice still
reports expected optional/recommended warnings for missing local add-on assets
and writable directories; those remain documentation/signoff scope, not current
smoke blockers. The first-push working tree at `$HOME/tmp/personal-life-os-core`
was later refreshed from source commit
`23e9a62248403e97b1a2212a3f496543a04d5c2b`; its manifest was generated at
`2026-05-02T14:11:52Z` with 1,611 tracked files staged.

2026-05-01 foreign VM proof: the history-free export from source commit
`1db129e171cab9667bb13f0f7956d42c9f028b5b` was copied to a separate Ubuntu
24.04.4 Hyper-V proof VM and passed the public non-Docker smoke path from
scratch. The exported manifest staged 1,582 tracked files, root and MCP
workspace npm audits reported 0 vulnerabilities, the public audit passed,
license snapshot checks passed, `scripts/audit-licenses.sh` completed with the
documented 12 warnings, and the focused public PHPUnit suite passed with 131
tests / 20,082 assertions. Core setup doctor reported 55 pass, 1 warn, 0 fail,
3 skip before Docker was installed; the only core warning was a missing Docker
binary.

After Docker Engine `29.1.3` and Compose `2.40.3` were installed on the same
proof VM, the same exported tree built the public app image, started MySQL,
PostgreSQL/pgvector, Redis, app, web, worker, and scheduler with
`COMPOSE_PROJECT_NAME=personal_life_os_core_public_proof_vm`, loaded both
schema dumps through the database containers, ran `PublicBaselineSeeder`,
returned HTTP 200 through Nginx on port `18080`, and passed the Docker-safe
container test slice with 95 tests / 206 assertions. The app-container core
setup doctor result was `warn`, with 53 pass, 5 warn, 0 fail, 2 skip; the
warnings are expected because Node/npm live in host/CI/Vite surfaces, Docker is
a host concern, and Compose service probes skip non-localhost hosts. The
Node/npm-dependent license snapshot checks and `scripts/audit-licenses.sh`
passed on the VM host from the same exported tree with the documented 12
warnings.

The same proof VM also closed the first clean-host media setup-doctor evidence
slice. After installing required media OS packages and build prerequisites, the
public export installed `requirements-media.txt` through
`requirements-media.constraints.txt`; `dlib 19.24.9` and
`face_recognition_models 0.3.0` both built local wheels. With Docker-remapped
database/Redis ports, `setup:doctor --profile=media --json` reported `warn`,
with 101 pass, 9 warn, 0 fail, 1 skip. The remaining warnings are optional
add-ons: Nextcloud data path, LibreOffice / `soffice`, spaCy
`en_core_web_sm`, Tika, SearXNG, Playwright Chromium, and external dlib model
files. A media Python license snapshot now exists in
`docs/public-release/python-license-snapshot-media.*`; it improves diligence
evidence but does not remove the GPL/LGPL/operator-installed signoff posture.
Basic media functional proof also passed on the VM using generated/public-safe
temp artifacts: ExifTool parsed the synthetic XMP face-region fixture,
Tesseract OCR read a generated PNG, ffmpeg/ffprobe generated and probed a
one-second video, and the media Python stack imported plus ran tiny HDBSCAN and
igraph/leidenalg checks.

Operator decision on 2026-05-01: this Ubuntu Hyper-V proof VM is the official
clean media proof machine for first-release core + media evidence. Use it for
media setup doctor refreshes, generated-fixture checks, and public smoke
refreshes as needed. Do not use it for private prod data or to claim supported
GPU behavior.

## Current Blockers

1. **Clean extraction proof is complete for local and Docker core paths, but the first public repository still needs final push validation**: do not publish this private repository or its history directly. `scripts/public-export.sh` provides the reviewed allowlist, and `scripts/public-smoke.sh` proves the non-Docker local export path. On 2026-05-02 the latest smoke passed Composer install, root and MCP-workspace `npm ci`, root and MCP-workspace `npm audit`, Python venv core requirements pinned by `requirements-core.constraints.txt`, key generation, frontend build, `setup:doctor --profile=core --skip-services`, `setup:doctor --profile=media --skip-services --only=assets,browser,docker`, public audit, npm/Python license snapshot checks, license audit with 12 documented warnings, script syntax checks, staged diff checks, and focused public tests with 138 tests / 22,081 assertions from a 1,611-file export at source commit `23e9a62248403e97b1a2212a3f496543a04d5c2b`. The PostgreSQL schema dump was refreshed from prod so public bootstrap includes current RAG eligibility columns. A disposable Docker proof also built the app image, started MySQL, PostgreSQL/pgvector, Redis, app, web, worker, and scheduler, loaded both schema dumps through the database containers, ran `PublicBaselineSeeder`, returned HTTP 200 through Nginx, started Horizon and the scheduler, and passed the Docker-safe setup/test slice. The public CI workflow now includes public audit, public-release shell linting, Docker Compose config validation, exported MCP workspace installs, npm/Python license snapshot checks, npm audits, setup doctor slices, and focused public tests. Remaining proof is a real GitHub Actions run on the fresh public repository plus final dependency/native-ML signoff before tagging.
   A separate Ubuntu proof VM also passed the public smoke from scratch on
   2026-05-01 with 131 tests / 20,082 assertions and 0 npm audit
   vulnerabilities in the root and MCP workspaces, then booted the Docker core
   stack and passed the Docker-safe app-container slice with 95 tests / 206
   assertions. The same VM installed the media Python tier and reached media
   setup-doctor with 101 pass / 9 warn / 0 fail / 1 skip, then passed basic
   media functional checks for XMP parsing, OCR, ffmpeg/ffprobe, and Python
   graph/cluster imports. This closes the foreign clean-host core-control proof,
   first media install proof, and basic media functional proof. GPU remains
   optional/experimental for first release; open gates are GitHub Actions,
   optional media add-on proof if advertised, and final signoff. On 2026-05-01,
   the operator approved this Ubuntu Hyper-V VM as the official clean media
   proof machine for first-release core + media evidence, excluding GPU proof.
2. **License and provenance review**: the root `LICENSE` exists, public fixtures have provenance rows, all Python tiers have constraints snapshots, core/media Python license snapshots exist, `docs/model-runtime-license-map.md` records model/runtime asset posture, `docs/python-constraints-license-snapshot.md` records Python package watch items, `docs/native-ml-package-review.md` records the optional native/ML/GPU package posture, and the Joplin/Photo/media reviewed surfaces have non-derivation guardrails. The media constraints now have disposable venv install/import proof in the local verification environment after pinning `setuptools<81` for `face_recognition_models` plus a separate Ubuntu proof VM install and basic generated-fixture functional proof. The GPU constraints remain a Linux x86_64/Python 3.12/default-PyPI resolver snapshot, not final legal or host-compatibility signoff. Remaining formal release work is dependency-license signoff, optional media add-on proof if Playwright/Tika/SearXNG/Nextcloud/dlib model files are advertised as proven, and host-specific GPU proof if GPU is advertised beyond experimental. If PLOS stays permissive, use Gramps, Gramps Web, webtrees, PhotoPrism, LibrePhotos, and Joplin as workflow/data-model/interoperability references only.

   Public-release diligence posture: document the release as a good-faith open-source diligence record, not as legal advice or a legal warranty. PLOS-authored source remains MIT unless a file states otherwise; third-party dependencies, tools, models, datasets, and optional runtime packages retain their upstream licenses and should be listed with source links, versions where practical, SPDX identifiers where available, and core-vs-optional status. GPL/LGPL/AGPL/CUDA/model/media/ML signals should stay documented as operator-installed optional extras unless the project owner intentionally accepts the obligations or obtains formal legal review. If the project grows into commercial use, packaged binaries/images, or broad public adoption, this diligence packet becomes the starting evidence for professional legal review rather than a substitute for it.
3. **Private-only materials must be filtered**: keep `CLAUDE.md`, `.claude.json`, `.mcp.json`, prod operations docs, real Nextcloud paths, genealogy data, email/finance archives, personal scripts, and operator strategic reports out of the public extraction.
4. **Installer proof follow-through**: initial public-safe fixtures and provenance docs now exist under `tests/Fixtures`, and the local history-free export smoke covers dependency install, build, setup doctor, audit, and focused tests. Docker startup and live schema proof now cover the core Compose stack. The Docker proof loads MySQL via the `mysql` container and PostgreSQL via the `postgres` container because the app image intentionally does not include database client CLIs. The app-container `setup:doctor --profile=core` result is allowed to be `warn` with zero failures: Node/npm live in the Vite container, Docker lives on the host, and service probes skip non-localhost Compose service names. Full-profile Docker proof for Tika, SearXNG, Ollama, Nextcloud, and media/genealogy dependencies remains optional follow-up, not a blocker for the core public v0.1 extraction.
5. **Photo/media provenance**: face-region metadata writeback is documented in `docs/face-metadata-writeback.md` as a standards-based PLOS feature. Do not imply code was copied from PhotoPrism, LibrePhotos, digiKam, or other GPL/AGPL projects unless the license strategy intentionally changes. Public communication about face-region writeback should use the standards-aligned PhotoPrism comment at `docs/papers-and-newsletters/photoprism-face-metadata-article-followup-2026-04-26.md` and the separate LinkedIn article at `docs/papers-and-newsletters/face-metadata-linkedin-article-2026-04-27.md`. The GitHub comment was posted to the PhotoPrism thread on 2026-04-28; keep Issues #402 and #747 as related context without cross-posting unless a maintainer asks for a new issue.

Important cautions that are no longer primary audit blockers:

- Public-bound source no longer points at private planning paths, and
  the private LAN literal found in the privacy-routing test fixture was replaced
  with a reserved documentation address. The public audit guard
  (`PUBLIC_AUDIT_LIMIT=200 scripts/guards/public-release-audit.sh`) passed on
  2026-04-30 after this cleanup.
- Public-bound Claude provider wording no longer assumes a personal
  subscription or private billing posture. Fresh-install seed data
  now labels the optional CLI provider neutrally, and the public audit guard
  blocks provider billing assumptions in public-candidate files.
- `.claude.json`, `.mcp.json`, and `CLAUDE.md` are useful in this working copy but should stay private or be sanitized before publishing.
- PhotoPrism provenance-risk wording has been narrowed to generic face-region metadata language. Keep legitimate reference links and compatibility notes, but avoid claims that imply copied implementation unless a file-by-file provenance review supports them.
- The branded operator-console theme was renamed to the neutral `ops-*` token family in source, Tailwind, review schemas, and the layout wrapper. A data migration updates existing `review_type_registry` rows so private/prod review cards keep styling after deploy.

## Extraction Shape

Selected first release shape:

- `personal-life-os-core`: one fresh public repository for v0.1, seeded from reviewed source only. Include agent framework, workflow engine, file registry, local RAG/GraphRAG, scheduler, review queues, offline policy, setup doctor, Docker scaffold, and MCP/server adapters.
- `plos-media`: keep inside `personal-life-os-core` initially as optional modules unless licensing or dependency weight forces a later split. Include only generic media catalog, face detection/linking, face-region metadata writeback, and import/export adapters.
- `plos-genealogy`: keep optional and fixture-driven. Public release needs synthetic/public-domain GEDCOM, sample documents, privacy rules, and clear non-cloud data boundaries.
- `plos-personal`: keep private. This includes production deployment details, personal genealogy data, email/finance integrations, local machine paths, and operator-specific Claude/MCP memory.

Use a fresh repository rather than a long-lived public branch. Branches and subtrees are too easy to contaminate with private history or path-bounded assumptions; this split is content-bounded, not just directory-bounded.

Private growth should happen through a companion layer, not by mixing private
history into the public repository. Keep private deploy scripts, real
Nextcloud/Joplin/Thunderbird paths, genealogy data, credentials, and operator
notes in a separate private repository or ignored local overlay. The public repo
should expose stable adapter contracts, `.env.example` placeholders, fixture
data, and `docker-compose.personal.example.yml`; the private layer supplies the
real bind mounts, secrets, data volumes, and local-only service choices.
The connector posture is local-device Thunderbird on desktop and Android for
mail, plus local-LAN Nextcloud for calendar, contacts, documents,
pictures/media, and full Joplin note sync across desktop and Android. Public
installs ship placeholders only; private bind mounts and credentials live in the
personal compose overlay and the operator `.env`. See
`docs/public-install-prerequisites.md` for the longer privacy framing.

## Cleanup Order

1. Keep tracked `node_modules`, generated archives, ad hoc screenshots, local control files, and private docs out of the extraction.
2. Use the existing public-safe `README.md`, `LICENSE`, `SECURITY.md`, `CONTRIBUTING.md`, `.env.example`, and `THIRD_PARTY.md` as the starting governance set.
3. Expand fixture design: the first synthetic GEDCOM, RFC 2606-style mail fixture, neutral RSS seed, and XMP face-region fixture now exist. Add generated/public-domain sample images only with explicit provenance.
4. Verify setup on a clean machine or container. The current local smoke command is `scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"`; the current Docker proof command shape is `docker compose build app`, `docker compose up -d mysql postgres redis app web`, MySQL schema load through the `mysql` container, PostgreSQL RAG schema load through the `postgres` container, `php artisan db:seed --class=PublicBaselineSeeder --force`, `php artisan setup:doctor --profile=core`, `docker compose up -d worker scheduler`, HTTP 200 through Nginx, and a targeted Docker-safe test pass. Run `--profile=full` only after starting optional compose profiles such as Tika, SearXNG, Ollama, and Nextcloud. Keep `--profile=personal` private-only.
5. Keep `php artisan setup:doctor` aligned with Docker, Python tiers, media binaries, local AI, browser runtimes, and optional Nextcloud/Joplin/Thunderbird adapters.
6. Run `scripts/guards/public-release-audit.sh` in the fresh public tree, then scan history/provenance separately before the first GitHub push.

## Recently Improved

Face-to-genealogy observability is now visible in the face-management workflow.
The Faces screen resolves the current genealogy tree from the URL or saved tree
selection and shows named faces, linked faces, pending queue depth, fuzzy queue
depth, oldest pending age, stale queue warnings, and approved bridge rows that
failed to create a `genealogy_person_media` link. This closes the immediate
"what is stuck?" visibility gap before any face-model changes.

The face-to-genealogy bridge now has a concrete implementation in `App\Services\Genealogy\FaceLinkBridgeService`. Face approvals, direct links, cluster identifies, and auto-link processing can create or update `genealogy_person_media` while keeping `file_registry_faces` and cluster state aligned. That makes the face workflow more credible for both private use and public extraction, provided fixtures and privacy rules are cleaned first.

The public-bound runtime defaults have also been neutralized further. File catalog scans, media browser paths, genealogy media roots, genealogy CLI commands, Internet Archive imports into genealogy trees, and optional Thunderbird mbox indexing now read configured library roots and archive paths instead of assuming the private top-level library. The operator-specific regional-news scraper is omitted from public export, and face naming now has a genealogy-person search endpoint with server-side ID validation before linking.

`mcp-server/node_modules/` has also been removed from git tracking locally while left on disk for the working copy. That eliminates roughly 42K tracked dependency files from future commits; fresh checkouts should run `cd mcp-server && npm install` before building that legacy MCP workspace. Runtime PID files, generated workflow JSON, extension packages, storage zip bundles, the canonical-docs ZIP archive, ad hoc scheduled-job screenshots, local Claude/MCP control files, Claude handoff files, and one-off storage tools were also de-tracked locally.

Agent skill definitions moved from Laravel runtime storage to `resources/agents/skills` with `config('agents.skills_path')` as the lookup point. This keeps reusable agent source in source-controlled application resources while leaving runtime handoffs under ignored `storage/`.

PhotoPrism provenance-risk wording in runtime comments and public project planning docs has been narrowed to generic face-region metadata and standalone media-management language. The audit guard now flags risky provenance phrases instead of every legitimate reference link or compatibility note.

The public theme surface now uses neutral `ops-*` names across CSS variables, Tailwind utilities, Vue classes, review-schema JSON, and the page wrapper component. The audit guard scans these terms case-insensitively and includes Tailwind config so the old branded token family cannot drift back into public-bound source.

`php artisan setup:doctor` now provides a public install health gate for core/media/gpu/full profiles, including pgvector/fuzzystrmatch, Python import/spaCy model, binary versions, Tika version, browser-runtime, writable-storage, runtime-asset, Docker engine/compose/daemon, Python tier, and service checks. A separate `personal` profile covers operator-only Pushover, Thunderbird, private note-sync checks, and opt-in private genealogy adapters so public `full` stays usable for CI. FamilySearch OAuth/API, Ancestry login/API, Fold3, NEHGS/AmericanAncestors, and FindMyPast automation are intentionally omitted for both public and private defaults; keep those services as manual source/citation references only. Newspapers.com remains private/personal-gated for operator-owned access, and MyHeritage automation is disabled unless explicitly enabled for a private install. Private adapter flag changes require `php artisan config:clear` when config caching is in use. `.env.example` includes Docker, same-host Nextcloud, local AI, Python tier, and optional private source-sync settings. The guard also detects private database names and historical credential literals so credential cleanup cannot be missed during extraction.

Public-release scaffolding now includes `.github/workflows/public-readiness.yml`
for audit/setup tests, `scripts/public-export.sh` for clean repository seeding,
`docker-compose.personal.example.yml` for private local overrides, and
`tests/Fixtures/PROVENANCE.md` with the first public-safe genealogy, mail,
search, and face-region metadata fixtures. `FixturesProvenanceTest` now checks
that each fixture has a provenance row, stays under the public size ceiling, and
does not contain private fixture tokens.

The first schema-only dumps now live at `database/schema/mysql-schema.sql` and
`database/schema/pgsql-schema.sql`. They are required for a public first boot
because the historical baseline migration records an existing private schema
rather than creating the oldest tables from scratch. `PublicBaselineSeeder`
now covers the first public system-table defaults that schema dumps do not
carry: routing/offline config, optional add-on flags, local Ollama routing,
model profiles, disabled optional cloud providers, and realistic public
genealogy providers. Keep expanding this seed contract as more migration-seeded
runtime tables are proven necessary for first boot.

Docker proof is no longer theoretical. After installing Docker Engine and
Compose on the workstation, a history-free public smoke export was booted with
`COMPOSE_PROJECT_NAME=personal_life_os_core_public_proof` on remapped host ports,
including MySQL `13306`, PostgreSQL `15432`, Redis `16379`, and web `18080`.
The proof exposed and fixed public-stack drift risks: Compose now pins
`DB_PORT=3306`, `RAG_DB_PORT=5432`, `REDIS_PORT=6379`, and
`PYTHON_BINARY=python3` inside app/worker/scheduler containers, and
`scripts/public-smoke.sh` now updates env keys without appending a duplicate
`.venv` value. The Docker-safe test slice passed with 94 tests and 204
assertions; the full packaging/license audit remains a host/CI or Vite-container
concern because the app image intentionally excludes Node/npm.

Generated front-end bundles and packaged extension `.xpi` files are no longer
tracked; they should be rebuilt from source in private/prod deploys and omitted
from the public extraction. The audit guard now flags future tracked
`public/build/**`, `.xpi`, legacy private project-brand terms, and private
tokens inside public fixture files.
