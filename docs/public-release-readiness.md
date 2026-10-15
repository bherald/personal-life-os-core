# Public release readiness

## Framework source publication — 2026-10-15

The reviewed 64-file framework update is published at `d9d8df57`.

**Root cause:** Exact embedding choices could drift to defaults; dedicated
reranking and native task/startup handling needed complete controller bindings.

**Behavior changed:** Preserve table-selected embedding roles and vector identity,
admit complete-input CPU reranking explicitly, bind its loopback transport, and
validate declared native task identities. Failed startup cleanup retains uncertain
outcomes and prevents replay; transport waits cover configured startup budgets.

**Verification:** Thirteen focused PHP controls passed with 96 assertions, and
48 native mock checks passed without model calls. Hosted Public Readiness run `38707276610`
passed its privacy/provenance audit, Compose validation, setup doctors, builds and
focused tests. Signed-out immutable reads matched all 64 reviewed file hashes.
These checks do not qualify an operator's models or hardware.

The initial Repository Governance run `38707276615`
failed because the published commit message omitted its four required literal
headings. This follow-up records that release information with the original
history intact and uses the required commit-body sections. Its own hosted checks
must pass separately.

**Deployment/rollback:** This is a public source update. Operators retain control
of model activation and matching table/native projections. Back up configuration
and data; restore reviewed source, policy and native preimages together if needed.
Preserve journals, existing vector spaces and unresolved outcomes without replay.

## Earlier release evidence

v2.1 was published on October 12, 2026. The release tag remains at `d17e803`.
Hosted CI passed for the code and test snapshot at `1df63ea1` on October 13.
Public Readiness run `38392686470` and Repository Governance run `38392686582`
are the verification records in the repository's Actions tab. Previous public smoke and
private deployment receipts are historical.

## Current evidence

| Check | State |
| --- | --- |
| Fresh public-base comparison | Public main verified at `1df63ea1` on October 13. Future source changes and exports require fresh comparison and review. |
| Confidentiality/secret audit | Final tracked source and history-free export audits pass; manual narrative scrub preserves upstream attribution. |
| Fixture/media source and license provenance | Two licensed synthetic JPEGs; fictional names, embedded XMP and independent readback tested. |
| Upstream notices/license files | Included; source-only license audit passes with 25 dependency/optional-tier warnings and zero missing npm license metadata. |
| Dependency/license snapshots and audits | Current snapshots, zero missing metadata; all three npm audits report zero vulnerabilities after reviewed Tailwind 4 upgrade. |
| Frontend/MCP builds | Hosted frontend and TypeScript builds passed. The two MCP packages passed 11 and 124 tests respectively, with one optional skip; the reviewed 31-tool contract passed. |
| Focused isolated public tests | Hosted PHP suite:687 passed/9 skipped (696 total;119920 assertions). Core CLI Node:34 passed/4 optional native skips, including actual offline/online socket-isolation checks. |
| Setup doctor without live services | Core and media local slices have zero failures; optional services remain separate. |
| Clean Docker bootstrap/upgrade | Not performed for this candidate. |
| Real model/research/media end-to-end proof | Not performed; no success claim. |
| Exact public commit-set approval | Original v2.1 source/tag/release and docs approved and verified; subsequent public changes require exact approval. |

## Extraction rules

Use the tracked-file allowlist in `scripts/public-export.sh`. Include generic
source, examples, tests and notices. Exclude private Git history, credentials,
sessions, data/evidence, host manifests, installation-specific route migrations,
weights and generated output. Fresh exports always need fresh review.

Reviewed false positives must bind the exact rule and source hash. Changed
source invalidates the exemption. Never exempt a credential or private record.

```bash
scripts/guards/public-release-audit.sh
scripts/guards/dependency-provenance-check.sh
scripts/audit-licenses.sh
git diff --check
docker compose --env-file .env.example config --quiet
```

The full `scripts/public-smoke.sh` path uses a fresh dedicated destination for
dependencies/build/setup/tests. Local test secrets and generated output must
remain untracked. Schema-changing tests require isolated databases. A passing
guard does not grant publication authority.

## Provenance watch items

Keep Gramps, PhotoPrism, LibrePhotos and Joplin source/term notes in
[THIRD_PARTY.md](../THIRD_PARTY.md). XMP, IPTC and FamilySearch GEDCOM are
interoperability references; actual output needs readback. Synthetic portrait
licenses and credit remain separate from the MIT application license.

See the [public GitHub first-push checklist](public-github-first-push-checklist.md) for fresh-repository preparation; existing public updates retain only public history.

## Reproducible setup slices

```bash
php artisan setup:doctor --profile=core --skip-services --json
php artisan setup:doctor --profile=media --skip-services --only=assets,browser,docker --json
```

The previous [public-release/privacy-secret-scan-baseline-2026-04-29.md](public-release/privacy-secret-scan-baseline-2026-04-29.md) remains historical. Current candidate evidence is recorded separately.

GPU and full profile evidence remains pending for this candidate; local core/media slices do not qualify those profiles.

## Optional examples

Offline controller contracts:8 passed/1 PowerShell skip; no inference or Windows fleet qualification. Photo Knowledge companion:15 passed. Cyber Review Workbench companion:17 passed with owned local synthetic fixtures. Standalone helper exporter3 passed; registry sync13 passed/1 Windows skip.

## Fictional nightly cases

Four original synthetic cases cover cyber news, Cedar Valley Ledger local RSS/HTML, location-free weather and a fictional video transcript. Four inactive native retrieval stages pass structural/integrity checks; no indexing, model, scheduler or connector was run. Full flows are non-executable plans and delivery states hypothetical. Operator-specific source/location seeds are excluded; a newly installed nightly ops template defaults disabled without altering installed job choices. Focused default/packaging checks:45 passed.

## Recent source repair follow-up

Selected later committed YouTube/source-custody, RAG/cache identity and review/typed-stop repairs were transplanted as narrow source deltas with their generic synthetic tests. The matching review-type actionability projection and fake transport receipt helper are included.268 focused tests pass in isolated SQLite/array-cache fixtures, with external requests blocked. The original public confidentiality guard remains in force; no private deployment scripts, operational receipts, host manifests, source data or private Git history enter this follow-up.

These are source/test qualifications. They do not establish live source processing, model quality, device synchronization or production release of the broader private tree. Full bootstrap/upgrade and configured runtime qualification remain separate.

## Maintenance retirement and privacy admission sync

The cancelled privileged maintenance adapter, its configuration, inventory entry and tests are removed. Public MCP metadata is reviewed independently from private installations. Preferred Codex dispatch uses the existing privacy/profile/role admission; local-only requests, malformed profiles and foreign required providers are held before connector dispatch. Generic SQLite/mock tests preserve approved routes. These source changes do not deploy the private application or enable models, services or connectors.

Current sync verification:189 isolated repair/privacy tests,51 packaging/provenance checks and124 primary MCP tests pass (one optional skip). The regenerated31-tool stdio contract has zero drift or violations. Earlier broader-suite/build results remain evidence for their original snapshots; no new UI or dependency change was made.

## Fresh-install license metadata

Seven exact-version npm entries use verified registry metadata for reproducible
license snapshots across installed and absent package manifests. SPDX licenses
remain unchanged; conflicting installed license claims are refused. Regression
checks cover hydration-independent snapshots, conflicting licenses and version
mismatches. Public CI retains all license/provenance gates.

## Portable worker-fixture runtime

Managed worker fixtures resolve Node.js from PATH using the existing Symfony
executable lookup. This exercises the Node installation selected by CI and local
operators. Owned-worker timeout, cancellation, partial-output retention and
no-replay checks remain unchanged. Three affected controls also pass through a
private PATH-selected Node shim; the full framework test file passes69 tests.

## Linux sandbox CI prerequisite

Public CI installs Bubblewrap before the synthetic MCP network-isolation test
and checks real PID/network namespace admission. The test retains offline socket
denial and explicit online socket access assertions. Failed PHP admission now
includes its synthetic fixture diagnostic. No test is skipped and no sandbox
policy is relaxed. An earlier run passed687 PHP tests with9 skips but failed
this MCP fixture; that failure remains historical evidence.

The subsequent hosted probe failed with `Failed RTM_NEWADDR: Operation not
permitted` while creating sandbox loopback. The focused-test job now selects
GitHub's supported Ubuntu 22.04 VM. No kernel setting, AppArmor profile or
application isolation rule is changed. The subsequent Ubuntu 22.04 run at
`1df63ea1` passed the sandbox probe, offline/online socket fixture and all Public
Readiness jobs. Repository Governance also passed. These checks do not establish
a full Docker upgrade or configured model/controller/connector qualification.

The guarded framework update preserves complete processing input, exact vision
bindings and compatible embedding retrieval. Disabled upstream worker registration
and proof-bound fresh scheduled checkpoints are documented in [framework upgrade boundaries](framework-upgrade-boundaries.md).

## Exact prompt dependencies — October 15, 2026

A current Colibri gateway imports its image module even for text requests.
The prompt counter previously admitted only the older four- and five-asset
contracts, so a selected current gateway could fail before counting a prompt.

The existing tokenizer binding now accepts an optional `image_engine_sha256`
with its V4.1 dependency. The worker verifies every selected asset before it
executes source and blocks unpinned modules from ambient Python paths. Counting
selects the same managed lossless renderer mode, preserving original whitespace.
Older supported contracts remain valid. This change does not activate a model,
change routing, or add a capability.

Ten focused process-boundary tests passed with 47 assertions and isolated
SQLite. They cover pinned source, missing or changed dependencies, ambient
imports, original whitespace and legacy contracts. A separate synthetic prompt
count used the real selected tokenizer; it was not model inference or a speed
benchmark.

Deploy the application and selected runtime/tokenizer binding as one coordinated
change. Keep the prior runtime and binding values for rollback, and use the
normal controller completion and cleanup path before extending a model's role.

## Owned loading heartbeats — October 15, 2026

A busy Ollama metadata probe could time out while a previously admitted cold
request still owned its process, listener and inference connection. The combined
snapshot discarded the independent residency observation, and the heartbeat
then rejected the owned request as an identity failure.

Tags and residency are now observed separately. A timeout remains an unknown
observation. Only an exact owned in-flight dispatch with successful activation,
prior pre-dispatch heartbeat, reserved residency and pinned task, executable,
process and listener may continue through that timeout. Explicit metadata
refusals, malformed responses, foreign models and identity drift remain denied.
Existing loaded-capacity reserves still apply. No model route, timeout increase,
prompt transformation or second custody mechanism is introduced.

Eighty-six public owned-dispatch controls and twelve snapshot/status controls passed in
native Windows PowerShell with model, HTTP, OS and persistent effects mocked.
They cover partial and total timeouts, retained foreign observations, mismatched
identities, missing admission proof and capacity
shortages. These controls make no model inference or performance claim. The
public checks import function definitions only and do not run the native action
dispatcher. Native verification replayed the exact public functions and test
bodies in memory; file-loading headers were syntax checked. Hosted Windows CI
runs the complete exported scripts. The separate private HTTP controller is not included; its five
release-route controls are optional and excluded from the public count.

Run the exported checks with Windows PowerShell:

```powershell
& .\scripts\plos-cli\test-controller-owned-dispatch.ps1 -LifecyclePath .\scripts\plos-model-host-controller-lifecycle.ps1
& .\scripts\plos-cli\test-controller-ollama-metadata.ps1 -LifecyclePath .\scripts\plos-model-host-controller-lifecycle.ps1
```

Deploy the lifecycle/controller/service pin chain together after the current
request physically finishes. Preserve prior installed bytes and original
outcomes; use the normal registered recovery path for unknown custody and never
automatically replay the original request. A normal model completion and cleanup
remain separate production acceptance. Roll back the exact native checkpoint
and bounded application source together.

## Ordinary YouTube node roles — October 15, 2026

An ordinary key-point node passed its output budget and temperature to AIService
but omitted its configured model role. Explicit-only providers also require
configured allowed/preferred instance choices. A manual CLI selection supplied
those choices, concealing the missing forwarding during ordinary execution.

The node now forwards an optional nonblank `model_role` and configured
`allowed_ollama_instance_ids` / `preferred_ollama_instance_ids` arrays through
AIService. Existing tables map the role to a model, and the existing pool still
enforces the provider's explicit-instance policy. Without configuration,
selection is unchanged. Explicit CLI role/model choices override node defaults,
even when the node's default provider is unavailable. Complete originals,
output budgets and normal source-preserving delivery remain unchanged.

Twenty-eight focused tests passed with 158 assertions against the exact public
candidate using synthetic inputs and per-test SQLite. Controls reproduce the
missing forwarding and exercise the actual preference normalizer and explicit
instance policy, role-only refusal, unchanged defaults, full CLI precedence and
restoration, complete originals and output contracts. These checks make no model
quality or hardware performance claim.

Deploy the generic source first, then bind an appropriate existing registered
node role and instance preferences through installation-owned configuration.
Preserve an existing operator choice. This public batch contains no private
binding, model, host, runtime recipe or activation, and does not relax explicit
provider selection. Use normal configured node processing and canonical delivery
read-back; restore prior source and exact prior settings on rollback. An unknown
request never authorizes automatic replay.
