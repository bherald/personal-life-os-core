# PLOS v2.1 nightly review example

This example shows a useful target pipeline: preserve sources, retrieve relevant evidence, propose a summary, get review, optionally deliver it, then report the verified outcome. It includes two fictional workshop notices with contradictory times. Neither establishes a real event.

There are two separate artifacts:

- [retrieval-inactive.native.json](../examples/nightly/retrieval-inactive.native.json) is a native `plos.portable_workflow.v2` definition for the retrieval stage. It is inactive, has no schedule or retries, reads only the selected `public_demo` document type and contains no connector nodes. Native structural validation and its integrity hash passed. The DSL version is `2.0`; the product release is `2.1`.
- [nightly-plan.json](../examples/nightly/nightly-plan.json) describes the full desired process and completion checks. It is explicitly **planning JSON, not a native import definition or executable pipeline**. It has no enabled schedule or delivery. Do not pass it to `workflow:import`.

## Establish the local example

1. Keep the two [synthetic originals](../examples/nightly/sources/) unchanged. Record their hashes and source labels. Index only this example into an operator-selected isolated corpus using the existing RAG index path. Indexing is a state change and is not performed by opening these docs.
2. Preview the native retrieval stage against the installed application:

   ```bash
   php artisan workflow:import examples/nightly/retrieval-inactive.native.json --json
   ```

   Preview is the default. The command additionally checks installed database state and existing workflow names. No `--execute`, `--confirm` or activation is part of this example.
3. Inspect the registered tools through `php artisan codex:offline-pipeline-tools --json`. Search the synthetic corpus, then use exact `rag_read` continuation offsets to inspect relevant complete text. Source-reference readiness does not prove the workshop time.
4. Prepare a draft that cites both notices and retains the contradiction: “The notices disagree between 10:00 and 11:00; confirm the intended time.” Review the proposed conclusion at `/reviews`. Do not silently choose one notice or approve a new fact.

## Add a delivery path only after review

An operator can choose one end-to-end nightly job after the manual pipeline works. Configure its source scope, model route, timeout, schedule, owner and notification recipient in the existing tables/workflow surfaces. Use [WorkflowPortableDefinitionService](../app/Services/WorkflowPortableDefinitionService.php) and `workflow:export` for actual native definitions. Preserve its integrity hash and review the portable secret omissions; import produces an inactive workflow and does not activate a schedule.

An optional Joplin output should use one exact reviewed destination and body. Preserve existing content/identity and read the resulting note back. A queued write remains pending; server verification and device sync are separate. Optional Pushover should carry only counts/status and use its privately configured recipient. Retain its provider outcome, including skipped/suppressed/failed/unknown states.

The final completion report waits for every requested downstream result and read-back. If no connector was requested, report local preparation with the disabled deliveries marked “not requested.” If a requested write remains queued or uncertain, report pending delivery. On an unknown physical outcome, inspect the exact durable action identity rather than automatically replaying it.

## Verification limits

The native example was checked by `WorkflowPortableDefinitionService::read()` and `validate()` without initializing an application or using a database. It passed structural and SHA-256 integrity checks with zero warnings/errors. This does not validate installed node registry admission, corpus preparation, an import transaction, model quality, scheduling or connector delivery. No import, schedule, provider request or notification has been performed.

The corresponding existing test surface is [WorkflowPortableDefinitionCommandTest](../tests/Feature/Console/WorkflowPortableDefinitionCommandTest.php), which uses a dedicated in-memory SQLite connection. Include that test in a reviewed public packaging batch before advertising tested import behavior.

For source-specific cyber news, fictional Cedar Valley Ledger RSS/HTML, weather and video examples, see [four nightly use cases](nightly-use-cases.md). Their source texts and drafts are original synthetic material; their complete flows and delivery states are illustrative.
