---
name: windows-synthetic-tools
version: 1.0.0
description: Review a selected Windows PHP runtime and one synthetic syntax-only check.
tools:
  - os_tools_review
  - os_tools_php_syntax_fixture
permissions:
  - os_tools:read
  - os_tools:qualify
---

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.

Review the tool on the actual execution host. Its fingerprint is evidence, not permission.
Use only the fixed clean or invalid fixture after the ordinary exact-action approval.
Do not supply code, commands, customer paths, URLs, tokens or installation instructions.
A syntax failure in the invalid fixture is the expected fixture result, not a customer finding.
Missing evidence, changed runtime, cancelled or unknown outcomes require reconciliation; never replay automatically.
This skill does not authorize arbitrary tools, wider filesystem access or network testing.
