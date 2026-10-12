# Research workbench

PLOS connects a research question to retrieved material, AI-assisted analysis,
and a reviewable result. It supports recurring topic research, local document
investigation, technical source comparisons and family-history evidence review.
The operator supplies sources, models and decisions.

## A practical research session

1. Index documents you may use. Keep original files and source locators.
   Configure PostgreSQL/pgvector and the selected embedding route.
2. Open Knowledge Hub (`/knowledge?source=ai`) and the configured topic/mission
   surfaces to scope the question and choose permitted sources. Inspect
   [UI routes](connectors-and-ui.md); start with a small manual run before scheduling it.
3. Configure web search when fresh external evidence is needed. Local retrieval
   and external access have distinct network requirements.
4. Inspect results, references, dates and coverage warnings. Search success with
   an unavailable engine means degraded coverage.
5. Ask the configured model to work from retrieved evidence. Preserve the
   original question and source text; distinguish inference from quotation.
6. Use Review Desk (`/reviews`) and domain review queues to inspect proposed facts and
   follow-up work. Human approval remains necessary for sensitive changes.

For example, compare claims in a small collection of openly licensed reports,
then repeat the question after adding a new report. Inspect which sources
support each proposed answer. This is a suggested workflow, not a research
benchmark or an included corpus.

## What the code implements

| Capability | Implementation | Research use |
| --- | --- | --- |
| Topics and missions | [ResearchTopicController](../app/Http/Controllers/Api/ResearchTopicController.php), [UnifiedResearchController](../app/Http/Controllers/Api/UnifiedResearchController.php) | Persistent questions and execution context. |
| Multi-source retrieval | [WebResearchService](../app/Services/WebResearchService.php), [ResearchService](../app/Services/ResearchService.php) | Deduplicated results with source provenance and partial-coverage warnings. |
| Evidence-aware analysis | [ResearchTopicRunner](../app/Nodes/ResearchTopicRunner.php) | Source context, references and bounded execution. |
| Source references | [RagSourceReferenceService](../app/Services/RagSourceReferenceService.php) | Stable references with explicit citation readiness. |
| Proposed-knowledge checks | [LLMKnowledgeVettingService](../app/Services/Research/LLMKnowledgeVettingService.php) | Source/RAG cross-checks; model confidence is not proof. |

## Configure the required pieces

The Docker core provides the app, databases, queues and web UI. AI research also
needs an operator-selected model route. Semantic retrieval needs an embedding
route and an indexed corpus. Web research needs configured source/search access.
Browser, transcription and media tools have their own setup-doctor checks.
See [installation prerequisites](public-install-prerequisites.md).

Inspect the authoritative code/config AI inventory, with live database status
reported separately:

```bash
php artisan codex:offline-pipeline-tools --json
```

## Limits and reproducibility

Keep the question, source versions, retrieval dates, configured route and
review decisions. Citation-ready means a usable source binding or locator,
not a measured accuracy result. Models, corpus quality and source access
determine practical results. Optional hardware/controller paths need separate
qualification. This release includes no private routes or datasets.

Release checks establish packaging, isolated behavior and build results.
They are separate from end-to-end research evaluation with real sources.
