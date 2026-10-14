# Table-owned LLM routing and compressor retirement

PLOS runtime endpoints, model-role maps and provider eligibility come from `llm_instances` and the existing inference pool. The registration migration imports current route aliases, controller/canary/evaluation bindings and controller transport into `system_configs.routing.llm_bindings`. It leaves existing operator bindings and provider activation unchanged. Installation settings are import inputs; credentials and timeouts remain configuration-owned.

Missing or unreadable registration refuses inference without recreating a local endpoint from environment configuration. Core PLOS can start without Ollama. An installed model does not grant another role or bypass controller admission. MCP helpers retain the selected instance, model and role through the Laravel AI service with exact-provider, local-only and no-fallback constraints. Unknown, incomplete or mismatched results fail without a second route attempt. Offline CLI policy reads stay in their pinned registry; refresh that registry after migration.

The prompt-compressor package and launch definitions are retired. Existing registry entries are disabled audit tombstones. Contextual-compression compatibility calls preserve the complete original input and all batch chunks. Code-description helpers preserve complete files. Oversized scheduled knowledge-graph input is refused rather than shortened.

Deploy the source and registration migrations together through the installation's normal release procedure. Verify actual runtime source, table routes and core health. Retiring an existing inference runtime also requires completion tests for every used role; embedding tests must prove model identity, full input, finite vector shape and retrieval behavior. Vector dimensions alone do not prove embedding compatibility.

For rollback, restore the previous source and an exact reviewed route-policy preimage. Binding records survive migration rollback, and compressor registrations stay disabled. This change does not install models or alter an operating-system kernel, display service or GPU driver.

Focused regression checks cover empty/disabled/unreadable registries, stale configuration, arbitrary replacement identifiers, migration idempotence, retained original content and exact provider responses. Public-candidate test and audit results are recorded in the prepared update packet before approval.
