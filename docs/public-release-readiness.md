# Public release readiness

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
