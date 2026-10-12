# Useful PLOS v2.1 methods

PLOS includes code for preserving source material, reviewing proposed findings and keeping uncertain execution outcomes visible. These methods are useful to inspect individually, even when someone does not adopt the full application. They are implementation patterns with focused tests; they are not research-accuracy benchmarks.

## Read beyond a search preview

[RAGService](../app/Services/RAGService.php) exposes `readDocumentWindow(documentId, offset, maxChars)`. The registered `rag_read` MCP operation in [MCPRouter](../app/Engine/MCPRouter.php) returns UTF-8 character positions, `has_more` and `next_offset`. Follow the returned offset until the requested material is complete rather than treating a shortened search preview as the source. These are windows of the currently stored document; the API does not promise a version-pinned snapshot across separate requests.

[RagSourceReferenceService](../app/Services/RagSourceReferenceService.php) distinguishes source-bound, locator-only and incomplete references. It exposes citation readiness and scope categories without exposing raw scope values. Its opaque references use the installation key; that is not anonymous publication of the underlying documents. Citation-ready means the source has a usable binding or locator, not that its claims are true.

## Preserve material and record transformations

[RagChunkingProvenanceService](../app/Services/RagChunkingProvenanceService.php) records the input hash, chunking options, implementation contract and chunk-set fingerprint. This helps distinguish a changed source from a changed indexing procedure. Keep the original text and full source references beside any proposed summary.

[LosslessConversation](../app/Services/UnifiedCli/LosslessConversation.php) retains original provider messages, validates tool-call order and blocks silent truncation when the admitted context is full. Explicit compaction is bound to the original history and cannot hide unresolved tool actions. [PromptIntegrityPolicy](../app/Support/PromptIntegrityPolicy.php) disables the application's lossy prompt-transform routes. These controls preserve evidence for review; they do not guarantee that every parser or source is accurate.

## Keep uncertain outcomes out of automatic retry

[TaskCustodyService](../app/Services/Custody/TaskCustodyService.php) uses durable ownership records and conservative liveness checks. Expiry alone does not establish that work stopped. [ComputeExecutionCustodyService](../app/Services/Compute/ComputeExecutionCustodyService.php) requires an owned terminal receipt before releasing a compute slot.

[CliFrameworkRemoteAction](../app/Services/UnifiedCli/CliFrameworkRemoteAction.php) binds an action to its exact caller/request and reads an existing result without dispatching it again. If execution may have happened but verified delivery is missing, it raises [CliToolOutcomeUnknown](../app/Services/UnifiedCli/CliToolOutcomeUnknown.php). Inspect the original action identity before deciding what to do next. A lost acknowledgement is not permission to repeat a side effect.

## Use optional typed judgments within explicit policy

[TypeSafeJevDecisionService](../app/Services/TypeSafeJevDecisionService.php) checks registered task scope, data classification, offline/network policy, excluded authentication material and response shape. Optional judgments have bounded requests/retries and a `continue_existing_route` skip fallback. An installed skill does not enable a task or grant write authority. No provider key or current private policy row belongs in an example.

## Check proposed tool use without dispatching it

[ColibriToolContractEvalCommand](../app/Console/Commands/ColibriToolContractEvalCommand.php) has synthetic cases and a plan mode. Its optional model-execution mode measures proposed tool selection and schema shape without dispatching the proposed tool. Plan mode does not invoke a model; executing the model needs configured, admitted runtime access. This is separate from grounding quality and audited research execution.

## Keep diagnostics useful and content private

[SensitiveLogContextProcessor](../app/Logging/SensitiveLogContextProcessor.php) masks nested credential/private-text keys while retaining selected counts and token metrics. Exception diagnostics retain type/code information without original messages and traces. [WorkflowExecutionRedactionService](../app/Services/WorkflowExecutionRedactionService.php) sanitizes presentation while preserving original stored values for legitimate execution/recovery. Logs, stored execution records and private source text remain different surfaces and need separate controls.

## Keep people and media decisions with the operator

[ReviewedFaceMetadataReplacementService](../app/Services/Files/ReviewedFaceMetadataReplacementService.php) previews exact metadata changes and binds writes to current source/region evidence and approved face decisions. Recognition candidates, identity approval and public metadata sharing are separate steps. The [photo metadata example](photo-knowledge-demo.md) uses synthetic portraits and fictional labels.

For the full application inventory, use the authoritative `php artisan codex:offline-pipeline-tools --json` surface. Static code/config inventory and live database availability are reported separately.
