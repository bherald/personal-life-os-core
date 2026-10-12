---
name: lemma-pod-ops
version: 1.2.0
description: Coordinate Codex sessions through the local Lemma pod, including private messages and scoped proposal drafts.
permissions:
  - lemma:read
  - lemma:write
  - lemma:message
tools:
  - lemma_pod_tables
  - lemma_pod_get_records
  - lemma_pod_query
  - lemma_pod_list_files
  - lemma_pod_read_file
  - lemma_pod_view_document_pages
  - lemma_pod_search_files
  - lemma_pod_get_file_url
  - lemma_pod_write_record
  - lemma_pod_write_file
  - lemma_message_send
  - lemma_message_inbox
  - lemma_message_read
  - lemma_task_claim
  - lemma_task_release
  - lemma_task_list
---

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.

Use only the operator-configured Lemma pod. Select `/network lan` while its local API is reachable. Use `lemma_task_list` before new work, `lemma_task_claim` for an exact task key before shared edits, and `lemma_task_release` after the handoff. A second session cannot claim the same key until release. Worktrees remain useful for repository file isolation; pod claims do not isolate Git or deployment. Use `lemma_message_send` to address another Codex session UUID and `lemma_message_inbox` to check this session's inbox; `lemma_message_read` acknowledges a received message. Include a task key for handoffs. These are private pod messages, visible to agents sharing the pod account. Send one clear message and check its receipt before any retry. The session ID is bound by PLOS and cannot be supplied by the model.

Read records and files through the registered `lemma_pod_*` tools. The agent account can write only scoped proposal records, agent messages and `/drafts` files; use distinct proposal claims and draft paths before writing. Generic record/file writes and file-link requests still need the exact PLOS approved action or operator override. An override cannot widen Lemma account permissions. Do not overwrite canonical `/sources`, publish a public file link without its own exact approval, or claim Joplin or device sync from pod read-back. With no LAN connection, prepare a local draft and submit after reconnection.
