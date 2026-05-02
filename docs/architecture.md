# PLOS Architecture

## Purpose

This document maps the current PLOS runtime as implemented today.

It is architecture documentation for the existing system, not a target-state rewrite. The goal is to make the main execution paths, service boundaries, and coupling points explicit enough to support safer refactors and runtime hardening.

## Top-Level Runtime Surfaces

PLOS currently operates through seven major runtime surfaces:

1. Scheduled execution
2. Agent execution
3. Tool execution
4. MCP routing
5. Provider routing / LLM execution
6. Review and approval
7. Compaction / decompose / context shrinkage

These surfaces are functional today, but they are not yet documented as a single coherent runtime.

## Core Entry Points

### Scheduler

Primary scheduler command:

- [SchedulerRunCommand.php](../app/Console/Commands/SchedulerRunCommand.php)

Primary runtime service:

- [ScheduledJobService.php](../app/Services/ScheduledJobService.php)

Execution flow:

1. `SchedulerRunCommand::runJob()` decides whether the job runs in background or synchronously.
2. For synchronous runs, it installs a hard `pcntl_alarm()` wall-clock timeout and builds an adaptive timeout-extender closure.
3. It then delegates to `ScheduledJobService::runJobNow()`.
4. `ScheduledJobService::runJobNow()` loads the job, creates or reuses the run record, dispatches by `job_type`, and records completion.

Supported scheduled job types:

- `command`
- `workflow`
- `job_class`
- `agent_task`

Current architectural observation:

- `ScheduledJobService` is not just scheduling. It is the central runtime adapter between cron/job metadata and multiple execution systems.
- It also owns operational recovery behavior such as zombie cleanup, stalled-process detection, worker PID tracking, and some domain-specific recovery logic.

## Scheduled Agent Path

Primary files:

- [ScheduledJobService.php](../app/Services/ScheduledJobService.php)
- [AgentLoopService.php](../app/Services/AgentLoopService.php)

Execution path:

1. `ScheduledJobService::runJobNow()` sees `job_type = agent_task`.
2. It calls `ScheduledJobService::runAgentTask()`.
3. `runAgentTask()` parses the job `notes` JSON into runtime parameters.
4. It builds the task, options, timeout settings, and context.
5. It delegates to `AgentLoopService::execute()`.
6. It converts the resulting agent response into scheduled-job output and status.

Important current behavior:

- scheduled-agent jobs can pass adaptive timeout extension through to the agent loop
- post-run indexing/memory capture is opt-in for cron-driven agents to avoid wedging the scheduler
- some jobs have custom routing logic inside `runAgentTask()` rather than being handled purely by skill definitions

Current architectural observation:

- scheduled agents are not a separate subsystem; they are an overlay on top of the same `AgentLoopService` used elsewhere
- `ScheduledJobService::runAgentTask()` contains domain-specific routing, which increases coupling between scheduling and domain behavior

## Agent Runtime

Primary file:

- [AgentLoopService.php](../app/Services/AgentLoopService.php)

Main entrypoint:

- `AgentLoopService::execute()`

Execution stages:

1. speculative-execution check
2. nesting-depth enforcement
3. safety-card warning
4. session lock acquisition
5. session creation/load via session service
6. skill load via `SkillLoaderService`
7. monitoring pre-screen short-circuit for some agents
8. tool load via `AgentToolRegistryService`
9. system prompt construction
10. memory retrieval
11. message/context build
12. execution via:
   - agentic loop
   - hybrid workflow
   - per-person hybrid workflow

Key runtime dependencies:

- [SkillLoaderService.php](../app/Services/SkillLoaderService.php)
- [AgentToolRegistryService.php](../app/Services/AgentToolRegistryService.php)
- `AgentSessionService`
- `AgentGuardrailService`
- `AIService`

Current architectural observation:

- `AgentLoopService` is the effective runtime kernel for PLOS agents
- it currently owns too many concerns at once:
  - loop control
  - skill interpretation
  - prompt construction
  - memory injection
  - timeout extension
  - review queue submission
  - notification behavior
  - some policy logic

This is the highest-leverage boundary to clarify in future work.

## Skill Loading

Primary file:

- [SkillLoaderService.php](../app/Services/SkillLoaderService.php)

How skills work today:

- skills live under `resources/agents/skills/{skill}/SKILL.md` by default
- `SkillLoaderService::getSkillIndex()` builds a compact catalog
- `SkillLoaderService::loadSkill()` loads full frontmatter + markdown body on demand
- skill frontmatter controls:
  - tools
  - permissions
  - workflow mode
  - iteration mode
  - model role
  - timeout ceilings

Current architectural observation:

- the skill system is the configuration boundary for agents
- timeout ceilings and tool availability already flow from skill definitions into runtime behavior
- this is the right place for agent capability declaration, but not the only place where behavior is currently decided

## Tool Runtime

Primary file:

- [AgentToolRegistryService.php](../app/Services/AgentToolRegistryService.php)

Main runtime responsibilities:

- load tool configuration
- validate required parameters
- enforce guardrails
- request confirmation when needed
- route execution
- serialize results for the LLM
- record tool-call analytics

Execution modes:

1. blocked tool rejection
2. guardrail validation
3. confirmation-pending path
4. composite-tool path via `ToolCompositionService`
5. MCP tool path via `MCPRouter`
6. direct PHP service/method invocation path

Current architectural observation:

- the tool registry is a real dispatch layer, not just metadata lookup
- it is the narrowest operational choke point for tool governance, analytics, and runtime policy
- that makes it a strong long-term boundary for capability-based runtime rules

## MCP Runtime

Primary file:

- [MCPRouter.php](../app/Engine/MCPRouter.php)

How MCP works today:

- `MCPRouter` loads server definitions from `config('mcp.servers')`
- it supports:
  - external MCP servers via stdio/JSON-RPC
  - internal service-backed MCP tools
- `AgentToolRegistryService` routes registered MCP-backed tools into `MCPRouter::callTool()`
- other services also call `MCPRouter` directly for web research, Thunderbird, Graphlit, Puppeteer, and other integrations

Current architectural observation:

- MCP is not only an agent tool surface; it is also used directly by multiple application services
- there are two overlapping integration styles today:
  - agent tool -> registry -> MCP router
  - service -> MCP router directly

That is flexible, but it means MCP behavior is not fully normalized through one runtime path.

## Provider / LLM Runtime

Primary file:

- [AIService.php](../app/Services/AIService.php)

Provider routing behavior today:

- `AIService::process()` is the central inference entrypoint
- it handles:
  - model auto-selection
  - factual-mode adjustments
  - semantic cache
  - in-flight deduplication
  - Claude-preferred routing
  - Ollama-first routing
  - cascade escalation
  - external-provider fallback chain
  - rate-limit behavior
  - auto-decompose for large prompts

Concrete provider slice:

- `AIService::tryClaudeCLI()` handles slot acquisition, model fallback within Claude CLI, retries, and rate-limit-aware fallback behavior

Current architectural observation:

- `AIService` is both provider router and high-level policy engine
- it already contains important runtime intelligence:
  - decompose
  - cache
  - dedup
  - fallback logic
  - cascade escalation
- that makes it one of the most important runtime services in PLOS, not just a thin provider adapter

## Review and Approval Runtime

Primary backend file:

- [AgentLoopService.php](../app/Services/AgentLoopService.php)

Primary API/controller files:

- [ResearchHubController.php](../app/Http/Controllers/Api/ResearchHubController.php)
- [routes/api.php](../routes/api.php)

Backend flow:

1. an agent calls `submitForReview()` or tool logic produces a review submission
2. `AgentLoopService::submitForReview()`:
   - applies auto-approve rules
   - applies low-confidence rejection rules
   - deduplicates
   - inserts into `agent_review_queue`
   - triggers audit and optional notifications
3. pending items are exposed via the research hub
4. `resolveReview()` updates status and optionally dispatches approval handlers
5. approval handlers are resolved dynamically through `review_type_registry`

HTTP surface:

- `/api/research-hub/items`
- `/api/research-hub/approve/{unifiedId}`
- `/api/research-hub/reject/{unifiedId}`
- `/api/research-hub/quick-approve/{unifiedId}`
- `/api/research-hub/quick-reject/{unifiedId}`
- remediation/action endpoints also live under `/api/research-hub/...`

Current architectural observation:

- review is not a separate bounded context yet
- the queue, auto-approval rules, notification rules, and approval dispatch still live largely inside `AgentLoopService`
- the API side is more modular than the submission side

## Compaction / Decompose Runtime

Primary file:

- [AIService.php](../app/Services/AIService.php)

Supporting files:

- [RecursiveCallService.php](../app/Services/RecursiveCallService.php)
- [ContextualCompressionService.php](../app/Services/ContextualCompressionService.php)

Current main path:

- `AIService::process()` checks large-prompt size before normal provider execution
- `AIService::tryAutoDecompose()`:
  - checks recursion master switch and service config
  - splits prompt into instruction + content chunks
  - processes chunks as sub-calls with a fast model role
  - synthesizes the results with a quality model role
  - records the recursion/decompose call

Current architectural observation:

- compaction today is primarily LLM-mediated decomposition, not deterministic pre-compaction
- the runtime already has a real context-shrinkage subsystem, but it is centered in `AIService`
- there is still room for deterministic, reversible pre-compaction before escalation

## API-Facing Runtime Surfaces

Primary API route file:

- [api.php](../routes/api.php)

Important runtime-facing HTTP surfaces include:

- workflows
- executions
- AI status
- RAG search and graph
- MCP status/tool calls
- orchestrator
- chat
- email
- Joplin
- research hub

Current architectural observation:

- PLOS exposes many runtime surfaces directly via HTTP
- some of these are pure controllers over services, but some are effectively operational entrypoints into the runtime
- documentation should distinguish user/product APIs from operator/runtime APIs

## Major Coupling Points

These are the strongest current coupling zones:

### 1. Scheduler <-> Domain Logic

- `ScheduledJobService` contains generic scheduling behavior plus domain-specific special cases

### 2. Agent Runtime <-> Review Runtime

- `AgentLoopService` owns both execution and significant portions of review submission/approval logic

### 3. Tool Runtime <-> MCP Runtime

- MCP tools can be reached through both the tool registry and direct service calls

### 4. Provider Runtime <-> Compaction Runtime

- `AIService` handles both provider routing and large-prompt decomposition

### 5. Naming Heuristics <-> Runtime Policy

- some important runtime decisions still rely on naming conventions instead of stable typed/capability signals

## Current Boundary Map

Best current practical boundary model:

- scheduler/runtime job control:
  - [SchedulerRunCommand.php](../app/Console/Commands/SchedulerRunCommand.php)
  - [ScheduledJobService.php](../app/Services/ScheduledJobService.php)
- agent execution kernel:
  - [AgentLoopService.php](../app/Services/AgentLoopService.php)
- skill configuration:
  - [SkillLoaderService.php](../app/Services/SkillLoaderService.php)
- tool dispatch/governance:
  - [AgentToolRegistryService.php](../app/Services/AgentToolRegistryService.php)
- MCP dispatch:
  - [MCPRouter.php](../app/Engine/MCPRouter.php)
- provider routing and auto-decompose:
  - [AIService.php](../app/Services/AIService.php)
- review/API aggregation:
  - [ResearchHubController.php](../app/Http/Controllers/Api/ResearchHubController.php)
  - `ReviewTypeRegistryService`

## What This Architecture Implies

The current PLOS runtime is already powerful enough to support:

- scheduled agents
- long-running hybrid agents
- guardrailed tool execution
- MCP-backed capabilities
- multi-provider routing
- human review and approval
- context shrinkage through RLM auto-decompose

But it also implies the next hardening priorities:

1. document runtime ownership clearly
2. reduce name-based policy decisions
3. separate agent execution from review/notification concerns more cleanly
4. normalize MCP usage patterns
5. add deterministic pre-compaction ahead of provider escalation

## Recommended Next Document

Write `docs/plos-runtime-inventory.md`.

This architecture note explains how the runtime flows. The inventory note should enumerate what concrete agents, jobs, services, MCP tools, and compaction paths currently exist inside that architecture.
