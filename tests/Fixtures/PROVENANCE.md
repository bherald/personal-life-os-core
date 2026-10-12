# Public Fixture Provenance

All fixtures in this directory are public-release candidates. They must stay
synthetic, public-domain, or generated from clearly documented local test data.
Do not add private family records, real photos, email exports, credentials,
cloud paths, or machine-specific values.

## Fixture Families

| Path | Source | License/Status | Notes |
| --- | --- | --- | --- |
| `genealogy/synthetic-family.ged` | Hand-written synthetic GEDCOM | MIT with PLOS fixtures | Fictional people and places; no private genealogy data. |
| `genealogy/source-backed-review-packet.json` | Hand-written synthetic genealogy review packet | MIT with PLOS fixtures | Fictional person, claim, and archive-style locators only; no private genealogy data. |
| `mail/rfc2606-newsletter.eml` | Hand-written synthetic message | MIT with PLOS fixtures | Uses RFC 2606 `.example` domain and RFC 5737 documentation IPs only. |
| `search/rss-sample.xml` | Hand-written synthetic RSS | MIT with PLOS fixtures | Neutral local search/news seed. |
| `media/README.md` | Hand-written fixture note | MIT with PLOS fixtures | Explains why binary media is intentionally absent from the first public slice. |
| `media/face-regions-sample.xmp` | Hand-written synthetic XMP sidecar | MIT with PLOS fixtures | Demonstrates face-region metadata shape without shipping a real face image. |
| `dev-agent/offline-dev-assist-tool-catalog.php` | Hand-written synthetic tool catalog | MIT with PLOS fixtures | Uses neutral `repo-dev` tool names for offline dev-agent readiness tests; no private server names or paths. |
| `dev-agent/repo-context-map-fixture.php` | Hand-written synthetic PHP source | MIT with PLOS fixtures | Uses fictional symbols and a sentinel source body to verify metadata-only context maps. |
| `prompts/genealogy_local_document_worker_prompt_cases.php` | Hand-written prompt test cases | MIT with PLOS fixtures | Public-safe synthetic prompt cases only. |
| `mcp-output-schema-server.cjs` | Hand-written synthetic MCP stdio server | MIT with PLOS fixtures | Returns one fictional structured count and a deliberate nonconforming sentinel for client-side output-schema rejection tests; no external source or private data. |
| `mcp-paginated-tools-server.cjs` | Hand-written synthetic MCP stdio server | MIT with PLOS fixtures | Emits fictional paginated tool catalogs plus invalid, repeated, endless, and duplicate cursor cases for bounded discovery tests; no external source or private data. |

## Rules For New Fixtures

- Use `example.com`, `example.org`, `example.net`, `.test`, or `.invalid`
  domains for emails, URLs, and hostnames.
- Use documentation-only IP ranges when an IP address is required.
- Use fictional names, fictional record identifiers, and neutral dates.
- Prefer generated text fixtures over copied external material.
- If a public-domain source is needed, include the title, URL, license/public
  domain statement, retrieval date, and any transformation made.
- Never include real user photos. Use generated or public-domain media with
  explicit provenance, or keep media tests text-only.

| `Ollama/durable-startup-test.ps1` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `OsTools/windows-path-discovery/native-smoke.php` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `Scripts/model-host-gpu-resources.ps1` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `Scripts/model-host-readiness.ps1` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `cyber/managed-protocol.php` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `face-comparison-contract-before-extraction.php` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `face_anonymous_generation_checks.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `face_anonymous_preview_checks.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `face_detector_snapshot_checks.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `file_registry_atomic_move_checks.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `file_registry_atomic_move_fsync_failure.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `file_registry_atomic_move_source_reoccupied.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `file_registry_atomic_move_timeout.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `file_registry_atomic_move_wrong_postimage.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `htr_office_image_write_failure.php` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `htr_transkribus_upload_reads.php` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `htr_worker_completion_checks.py` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `model-host-controller/selected-health-behavior.ps1` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `model-host-controller/shared-engine-behavior.ps1` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `model-host-controller/switch-budget-behavior.ps1` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `offline-genealogy/offline-tree.ged` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `operator-lemma-mcp.mjs` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
| `scheduler_timeout_custody.php` | PLOS synthetic test fixture | MIT with PLOS fixtures | Reviewed source-code fixture; fictional values only, no private media. |
