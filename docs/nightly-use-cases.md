# Four fictional nightly review examples for PLOS v2.1

A useful nightly process should preserve its sources, prepare a reviewable result and tell the operator what was actually delivered. These four examples show that process with original synthetic material. No real news, community, forecast, video, private dataset or copied transcript is included. All website locators use `example.test` names and must not be fetched as real sources.

The examples are **inactive**. No schedule, model, source ingestion, indexing, external request, note write or notification has been performed.

| Case | Bundled sources | Illustrative draft and unresolved issue |
| --- | --- | --- |
| [Cyber-news digest](../examples/nightly-use-cases/cyber-news/) | Two fictional Aster Forge Bulletin notices about a made-up server. | A recommended version is reported, but the later scope correction contradicts the original all-deployments wording. No independent fix receipt is supplied. |
| [Cedar Valley Ledger local news](../examples/nightly-use-cases/local-news/) | Original fictional RSS and two full HTML articles. The publisher and community are invented. | Proposed maintenance times differ. Neither article establishes a completed closure or supplies the detour route. |
| [Fictional weather outlook](../examples/nightly-use-cases/weather/) | Two invented outlooks, with no real location, coordinates, station or dates. | Rain probability and temperature differ; missing issue/version information prevents choosing the newer outlook. Forecast rainfall is not an observation. |
| [Original fictional video transcript](../examples/nightly-use-cases/video-transcript/) | A newly written demonstration transcript with illustrative time markers. | The narrator corrects a blanket portability claim. The story mentions a read-back but supplies no executed-test receipt. |

Each directory includes untouched source fixtures, `summary-draft.md`, `retrieval-inactive.native.json`, `full-flow.plan.json` and `delivery-illustrations.json`. [Provenance](../examples/nightly-use-cases/PROVENANCE.json) records original authorship intent and source hashes. Drafts were prepared manually from those texts; they are examples, not model-output evidence.

## Native stage versus complete process

The four retrieval-stage definitions use the existing `RAGSearch` node and native `plos.portable_workflow.v2` schema. Each has `active = false`, `schedule = null`, `retry = null` and a selected synthetic document type. Structural validation and integrity hashes passed with zero errors/warnings, without starting the application or using a database. The native DSL remains version `2.0`; the product release is `2.1`.

`full-flow.plan.json` is explicitly planning JSON. It is not a native importer definition or an executable nightly pipeline. `delivery-illustrations.json` is a set of hypothetical states, not receipts from a real connector. No source file or report activates a job.

To preview one native stage on an operator's configured installation:

```bash
php artisan workflow:import examples/nightly-use-cases/local-news/retrieval-inactive.native.json --json
```

Preview is the default and also checks installed database/name state. No import/activation flags are part of this example. The operator must separately approve/index only the chosen synthetic collection and configure its embedding/model route before retrieval can work. Source structural validation does not establish installed node admission or useful search results.

## Follow one complete process

Keep original bytes, hashes and locators. Extract the selected text and index only the fictional case with stable source identity. For the RSS example, treat feed items as discovery references to full articles rather than silently counting the feed and each article as separate supporting reports.

Retrieve relevant material, then inspect exact complete text through the registered RAG continuation reader. Keep source versions and contradictions visible. Prepare a proposed summary and send it for human review. In the local-news example, the useful conclusion is that the start time needs confirmation; choosing 14:00 or 16:00 silently would lose the main issue.

Optional Joplin delivery follows review of the exact destination and body. Preserve existing note identity/content and read the completed note back. A queued operation remains pending. Exact server read-back does not establish desktop/phone sync. Optional Pushover should contain only counts and a status, using privately configured credentials/recipient. A suppressed or failed send is not delivery, and provider acceptance does not prove human reading.

The final report waits for every requested stage and delivery read-back. If optional deliveries were not requested, say so. If a requested write is queued, unknown or failed, keep that state visible. Inspect an uncertain action's original durable identity before deciding whether another attempt is appropriate; do not automatically repeat a side effect.

The illustrated delivery files include a queued Joplin state, a hypothetical matching-body server read-back, a suppressed/failed notification and an unknown physical write. Every file says `illustrative_only = true` and `actual_connector_calls = 0`. A matching hash in an illustration is a description of the expected check, not proof that any note was written.

## Useful follow-on recipes

Five further methods fit naturally inside PLOS documentation and synthetic tests, without another broad repository:

1. **Complete-source reading:** compare a bounded preview with exact UTF-8 continuation, then record which material remains unread.
2. **Source/change provenance:** use synthetic input/chunking fingerprints to distinguish a changed source from a changed indexing configuration.
3. **Unknown-outcome recovery:** demonstrate a lost acknowledgement that preserves the original action and forbids automatic duplicate delivery.
4. **Private diagnostics:** show nested test-credential/text redaction while retaining safe counts, exception types and token metrics.
5. **Optional typed judgments:** show a fake-provider schema/policy/skip fallback without enabling a provider or transmitting private input.

These are implementation recipes, not accuracy, autonomy or security-certification claims. Their source/test paths are described in [portable methods](portable-methods.md).
