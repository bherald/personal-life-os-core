# Public Fixture Provenance

All fixtures in this directory are public-release candidates. They must stay
synthetic, public-domain, or generated from clearly documented local test data.
Do not add private family records, real photos, email exports, credentials,
cloud paths, or machine-specific values.

## Fixture Families

| Path | Source | License/Status | Notes |
| --- | --- | --- | --- |
| `genealogy/synthetic-family.ged` | Hand-written synthetic GEDCOM | MIT with PLOS fixtures | Fictional people and places; no private genealogy data. |
| `mail/rfc2606-newsletter.eml` | Hand-written synthetic message | MIT with PLOS fixtures | Uses RFC 2606 `.example` domain and RFC 5737 documentation IPs only. |
| `search/rss-sample.xml` | Hand-written synthetic RSS | MIT with PLOS fixtures | Neutral local search/news seed. |
| `media/README.md` | Hand-written fixture note | MIT with PLOS fixtures | Explains why binary media is intentionally absent from the first public slice. |
| `media/face-regions-sample.xmp` | Hand-written synthetic XMP sidecar | MIT with PLOS fixtures | Demonstrates face-region metadata shape without shipping a real face image. |
| `dev-agent/offline-dev-assist-tool-catalog.php` | Hand-written synthetic tool catalog | MIT with PLOS fixtures | Uses neutral `repo-dev` tool names for offline dev-agent readiness tests; no private server names or paths. |
| `prompts/genealogy_local_document_worker_prompt_cases.php` | Hand-written prompt test cases | MIT with PLOS fixtures | Public-safe synthetic prompt cases only. |

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
