# Security And Privacy

PLOS is local-first software. Public installs should start with local services,
local data, explicit operator review, and no real credentials committed to git.

## Local-First Boundary

The public core is designed to run on hardware the operator controls. Local
databases, Redis, storage, queues, and Ollama-compatible model hosts are the
preferred default.

External AI providers, notification services, and personal connectors are
optional. Keep them behind explicit `.env` configuration, routing policy, and
operator approval.

## Offline Workspace Path Boundary

The exact canonical repository path is supplied only to the local Ollama-backed
`offline:dev-assist` model so relative repo tools share an authoritative root.
It is redacted from `/prompt`, traces retain hashes rather than prompt content,
and general cloud/agent prompts do not receive this binding. Retrieved text,
operator text, and tool results cannot switch the trusted root. This context is
additive and lossless: compression, precompaction, and automatic decomposition
remain source-enforced off.

The same local-only prompt receives the exact developer-committed root
`AGENTS.md`. Admission requires a regular non-symlinked UTF-8 file at the exact
canonical root, capped at 4,500 bytes; invalid guidance fails closed. PLOS does
not discover parent or nested instruction files and does not import generated,
home-directory, session-history, or external-agent memory. File contents grant
no tool, approval, route, credential, identity, or operator authority. The
deterministic inventory exposes only source/scope, size, and content hash.

## RAG Exact-Read Boundary

RAG search previews are bounded for tool-response size, but truncation is never
treated as complete evidence. Search exposes a stable local document ID and
`rag_read` retrieves exact UTF-8 character windows with explicit offsets and
continuation state. The tool returns only document identity, title, type, window
metadata, and content; it does not return source paths or broad row metadata.

The tool is classified read-only by offline policy and runs inside the existing
local RAG MCP trust boundary. It does not weaken document access policy, grant
write authority, or send content externally. Retrieved content remains
low-trust evidence and cannot change system instructions, operator authority,
or tool policy. Compression and precompaction remain source-enforced off.

## Secret File Modes During Deployment

Runtime `.env` files in either checkout must be owner-only (`0600` or stricter).
The production release checks the fixed repo-scoped allowlist on both hosts and
stops before dependency installation or service restart when a file exposes any
group/world bits. Missing optional env files are not created. The release never
changes file modes automatically, and `--repo-only` deliberately excludes HOME
credentials and SSH keys from this deployment-specific decision.

## Production Runtime Source Membership

An exported checkout can retain repo-absent code because forced Git checkout
only removes untracked entries that obstruct the checkout. PROD1 therefore
sends a NUL-delimited tracked manifest from the exact source commit to a read-
only production comparison covering regular PHP files in `app/` and `routes/`.
The response exposes aggregate counts and SHA-256 manifest identities only, not
production paths or file bodies. Invalid, absolute, escaping, empty, or out-of-
scope expected manifests fail before comparison.

The guard does not follow symlinks, write source, change permissions, or delete
files. Drift cleanup remains a distinct destructive action requiring explicit
review. This boundary protects deployed code membership but does not establish
content equivalence, generated-asset reproducibility, dependency integrity, or
runtime-process identity; existing release and canary checks retain those jobs.

## Secrets

Never commit:

- `.env` or production env files;
- API keys, OAuth tokens, webhook URLs, passwords, or private keys;
- real Nextcloud, Joplin, Thunderbird, mail, finance, genealogy, or photo
  paths;
- private compose overlays such as `docker-compose.personal.yml`.

Use `.env.example` and `docker-compose.personal.example.yml` as templates only.

### Log Secret Boundary

Application code should place diagnostics in structured log context and never
interpolate credentials into the message string. Every configured Laravel log
sink applies `SensitiveLogContextProcessor`, which recursively masks keyed API
keys, authorization values, cookies, credentials, passwords, private keys,
secrets, signed/webhook URLs, connection strings, and tokens. Known count and
usage fields such as `prompt_tokens`, `token_count`, and `token_budget` remain
visible. The mask is constant rather than a hash, so logs do not become a
credential-correlation surface.

The same processor treats exact or suffix structured keys for `query`,
`prompt`, and `claim` (including plural and `_text`, `_prefix`, or `_preview`
forms) as private content. It replaces their values with an explicit private-
text sentinel at every audit-eligible configured sink while preserving aggregate fields such
as `query_count`, `query_chars`, `prompt_tokens`, and keyed fingerprints.
Research, RAG, chat, and Genea entry points that need correlation use
`plos.sensitive_text_telemetry.v1`: private-text kind, byte length, and a
purpose-scoped HMAC derived from `APP_KEY`, or length-only when the key is
unavailable.

This is defense in depth, not permission to log secrets. It cannot reliably
identify an arbitrary credential embedded in free-form message text, and the
framework's last-resort emergency logger cannot use application processors when
normal logger construction itself fails. Keep credentials out of messages,
retain restrictive log access/retention, and run the deterministic audit after
logging configuration changes:

```bash
php artisan ops:log-secret-redaction-audit --json --compact --fail-on-violations
```

## Public Data Rules

Public fixtures must be synthetic, public-domain with documented source, or
generated locally with enough provenance to reproduce them. See
`tests/Fixtures/PROVENANCE.md`.

Do not publish private photos, real family trees, real mail, private notes,
browser profiles, local machine paths, or personal archives.

## Review And Agent Boundaries

Agent output should be reviewable before sensitive actions occur. Completed
runs alone are not evidence of safe autonomy. Review items should carry enough
source, context, and confidence information for an operator to approve, reject,
or ask for clarification.

Agent skill references use a narrow read boundary. `skill_reference_read`
accepts only one Markdown basename under the active skill's one-level
`references/` directory. The tool registry supplies `agent_id` from trusted
runtime context through `x_context_only`; model input cannot override it.
Nested paths, missing files, symlinks, bundle escapes, and files over 32,000
bytes fail closed. This does not grant general filesystem authority.

See:

- `docs/AGENT-SAFETY-CARDS.md`
- `docs/AIService-LLM-Gateway.md`
- `docs/OLLAMA-COMPATIBILITY.md`

## Browser Automation Boundary

The Puppeteer and Playwright control planes bind to loopback, require the
installation-derived bearer token, and validate loopback Host and Origin
values. Every navigation is scoped to one admitted domain family. Browser-
canonical IP literals, including alternate IPv4 forms and IPv6, plus
`localhost` and its subdomains are rejected before allowlist or trusted-initial
handling. This prevents direct browser access to local services through those
target forms even if an unsafe allowlist row already contains one.

This hostname gate is not a DNS resolver, network firewall, or complete SSRF
boundary. It does not prove that an ordinary hostname cannot resolve or rebind
to a private address. Keep the sidecars loopback-only, do not expose browser
profiles, cookies, or credentials, and use network-level egress controls where
the deployment requires protection against DNS-resolved private targets.

## Offline And Degraded Behavior

Offline mode should prefer local providers and block external network
dependencies unless the operator intentionally enables them. Degraded states
should be visible to operators rather than hidden behind silent fallbacks.

Offline-policy resolution and evaluation are mandatory before agent-registry
or direct MCP execution. If either fails, PLOS emits content-free failure
metadata and denies the call before dispatch. Operator confirmation cannot
override this degraded state because the unavailable profile may have required
a hard denial rather than confirmation.

GenAI telemetry export is operator-invoked and content-free. It may expose
bounded source-backed model, agent, tool, retrieval, and workflow identifiers,
but it must not export prompts, completions, arguments, results, raw sessions,
raw errors, paths, query text, documents, or metadata. An optional collector
does not become trusted merely because it accepts OTLP.

Wire validation is local and mandatory. `plos.otlp_trace_json_validation.v2`
verifies protobuf structure, byte widths/encoding, scalar types, time ordering,
duplicate identities, and content-bearing attribute names. Known prompt,
message, reasoning text/data/signature/encrypted-content, tool argument,
document, exception, image, and audio attribute families fail closed before
export. Aggregate reasoning token counts remain allowed. The validator does not
inspect or redact values, send telemetry, authorize a collector, enable an
endpoint, or weaken retention and authentication requirements.

Production proof for behavior `de53752c` confirms this is an enforced boundary,
not an inventory-only declaration. The deployed hashes match the reviewed
release, live inventory exposes the v2 fail-closed 28-family gate, and a
synthetic read-only probe rejects encrypted reasoning content while accepting
an aggregate reasoning-token count. Horizon is running and no process is in
`D`. Prompt integrity remains `lossless_ai_prompts.v1` in `enforced_off` mode;
all compression and precompaction capabilities remain false.

Codex CLI trajectory normalization follows the same content-free boundary.
Only completed command lifecycle events are admitted. Raw command and output
text may be inspected transiently to derive a canonical action, success flag,
and SHA-256 pair fingerprint, but none of that text is returned or persisted in
the assessment. Fingerprints can reveal repeated equality and therefore remain
private diagnostic data; they are not authorization, correctness, identity, or
promotion evidence. The returned sequence is bounded to 500 entries and
reports truncation explicitly.

AI Chat SSE framing is lossless and incremental. Only CR, LF, and CRLF delimit
transport lines; Unicode NEL, line-separator, and paragraph-separator characters
remain content. The browser decoder joins specification multi-line data and
flushes final bytes without logging, reclassifying, or transforming the parsed
payload. JSON validation and existing stream interruption/fallback gates still
apply after framing. Treat all streamed content as untrusted display data and
do not turn event names, IDs, or payload text into authorization decisions.

Workflow webhook delivery identity is admitted only from `Idempotency-Key`,
`X-Webhook-Delivery`, or `X-GitHub-Delivery` when one consistent visible-ASCII
value is bound to the exact body by `X-Webhook-Signature-V2`. PLOS retains only
a trigger-scoped SHA-256 for queue and workflow-run idempotency. Raw delivery
and signature headers are removed from application webhook logs and never
become workflow input. Headerless callers retain the body-only v1 HMAC and
canonical-payload uniqueness contract.

Agent command validation treats recursive deletion as a fail-closed grammar,
not a pair of literal `rm -r` regular expressions. Short, combined, and long
recursive flags are recognized. The only admitted form is one complete,
standalone `rm` command using a narrow reviewed flag vocabulary and relative
targets whose basenames are known generated artifacts such as `node_modules`,
`dist`, `build`, or `.cache`. Compounds, absolute or home paths, parent
traversal, variables, quoting, control characters, and unknown options are
blocked. This is a pre-execution defense in depth; it does not replace the
offline policy, sandbox, filesystem permissions, destructive-action approval,
or operator review.

Dynamic external MCP definitions are cacheable only when PLOS can bind the
configured server to locally resolved runtime files. Cache identities contain
only SHA-256/file metadata inside a final hashed key; no executable content,
environment value, path, tool argument, or result is logged or exported.
Indirect package, shell, module, and mutable-container launchers bypass dynamic
catalog caching so a mutable remote runtime cannot inherit a locally stale tool
contract. Static reviewed definitions and invocation/result schema validation
remain separate mandatory controls.

## Public Audit

Before publishing an export:

```bash
scripts/guards/public-release-audit.sh
scripts/audit-licenses.sh
```

The audit is a blocker finder for public extraction. It does not replace
review, but any private literal, credential, copied reference-project language,
or fixture provenance miss should be resolved before pushing.

## Incident Handling

If private data or credentials are exposed in a public artifact:

1. remove the artifact or make the repository private if possible;
2. rotate affected credentials immediately;
3. identify the copied files and source path;
4. add a regression guard or fixture provenance check;
5. publish only redacted details in public issue threads.

Report vulnerabilities using `SECURITY.md`. Do not include secrets or private
data in public issues.

## Execution Date Filter Validation

Execution-history date filters are untrusted query input. PLOS validates both
values as calendar-only `YYYY-MM-DD`, rejects reversed ranges, and uses bound
SQL parameters. The inclusive final day is represented as `< next-day` rather
than interpolating an end-of-day string into SQL. The endpoint returns only its
existing recursively redacted execution display; date filtering does not relax
redaction or expose stored inputs, outputs, or error content.

## Dynamic MCP Catalog Completeness

A paginated MCP catalog is one security and correctness unit. PLOS does not
authorize or cache the valid prefix of an incomplete dynamic catalog. Discovery
rejects malformed tool payloads, duplicate names, non-string or repeated
cursors, more than `64` pages, and more than `4,096` tools, then falls back to
the reviewed static definition set.

Discovery diagnostics contain only the configured server identity and failure
class/count context; tool descriptions, schemas, cursor values, arguments, and
results are not added to logs by this control. Pagination does not widen tool
authority or bypass argument, result-schema, offline-policy, operator-review,
network, or approval gates. It also introduces no prompt transformation:
compression and precompaction remain disabled in source.

## External MCP Runtime Admission

External MCP code must be locally resolved before PLOS may advertise or invoke
its tools. Package managers, module launchers, shells, and mutable container
commands are not runtime identities and fail closed. Npm-backed servers require
an exact manifest version, matching lock version and SHA integrity, matching
installed version, and a resolved command contained by that package. Direct
operator/repository binaries remain available under content identity.

This removes invocation-time package fetch/resolution and prevents a denied
runtime from borrowing a reviewed static catalog. It does not make lock
integrity proof of every installed byte, widen tool authority, or replace
filesystem, network, offline-policy, approval, and operator-review controls.
Prompt compression and precompaction remain disabled.

## MCP Probe Diagnostic Boundary

The fixed-server tool-contract probe retains one typed lifecycle class and a
SHA-256 of at most 64 KiB of transient diagnostic material. It never emits the
diagnostic text, tool descriptions, schemas, arguments, results, environment,
paths, or process output. Connection close, client request timeout, child
process, protocol, and unknown failures remain distinct from contract drift.

The probe makes one attempt and does not auto-retry. This preserves first-
attempt lifecycle evidence and prevents a later child from laundering an
initial failure into a pass. Every failure remains blocking; an unknown or
unparseable runner response fails closed. The receipt changes no MCP authority,
network access, metadata visibility, prompt content, or operator approval.

## MCP Progress Privacy Boundary

MCP progress is opt-in per request through the client-supplied
`_meta.progressToken`. Notifications contain only that transport token, a
monotonic elapsed-seconds value, and one fixed state label: `started`,
`running`, `completed`, or `failed`. They never contain tool inputs, outputs,
errors, prompts, retrieved content, person or media data, model identifiers,
credentials, filesystem paths, or an invented total.

Treat a progress notification as liveness evidence only. It cannot authorize a
tool, approve a review item, identify a face or photo, change a route, or imply
successful completion. Existing offline policy, argument validation, result
schema validation, timeout, cancellation, operator review, and audit controls
remain authoritative. Notification transport failure is isolated from the
tool outcome, and cancellation suppresses later terminal progress.

## RAG Chunking Provenance Privacy

Semantic chunk provenance is content-free metadata. It contains normalized
effective numeric/string options, an input SHA-256, aggregate ordered chunk-set
identity, chunk count, and deterministic contract/document fingerprints. It
does not contain document text, chunk text, prompts, paths, person/media data,
or individual chunk hashes that could become a fine-grained correlation index.

`rag:chunking-provenance-audit` reads only semantic metadata and returns
aggregate coverage/contract counts. It cannot write metadata, infer historical
configuration, rechunk, re-embed, alter retrieval, or approve any operator-
owned face/photo decision. Prompt compression and precompaction remain
source-enforced off.

## Generated CSV Trust Boundary

Research terms, repositories, names, source titles, notes, and agent-produced
text can be valid evidence while also being active spreadsheet input. Every
generated CSV field therefore passes through `SpreadsheetSafeCsv`; CSV quoting
alone is not treated as formula neutralization. The boundary detects ASCII and
full-width formula initiators after whitespace, zero-width characters, or BOM
and also treats leading tab, CR, and LF as dangerous.

Only the downloaded cell is prefixed with a spreadsheet text marker. PLOS does
not rewrite database evidence, remove field detail, change JSON responses, or
offer an environment/config bypass. Research-log downloads are private,
non-cacheable attachments with MIME sniffing disabled. This control does not
make arbitrary spreadsheet software a sandbox; exported artifacts remain
untrusted and should not be opened with unnecessary network or macro access.

## Runtime Configuration Secrets

Configuration API authentication is server-enforced for reads and writes.
Secret classification covers explicit credential names and precise suffixes;
ordinary controls such as `max_tokens` and `token_budget` are not classified as
secrets. Whole secret values never leave the server, and nested JSON credentials
are recursively replaced with a preservation sentinel before serialization.

Redacted read-modify-write cycles preserve stored credentials. A caller cannot
use a sentinel for a missing or nonsecret field, and clearing a whole secret
requires a separate explicit flag. Updates merge under a row lock to prevent a
concurrent credential rotation from being replaced with stale redacted state.
Controller failure responses are generic,
and logs exclude values, bindings, request bodies, and exception messages.
Runtime caches are invalidated after create, update, delete, bulk update, and
default initialization. This boundary changes neither operator authority nor
prompt content and performs no lossy transformation.

## Personal Access Token Security Boundary

Personal access token lifecycle routes require an authenticated web session.
They list only the current owner's active personal-access tokens, issue the real
credential through Laravel Passport, return the secret once, and revoke only a
matching owner/grant record. Bearer credentials cannot call token-management
routes. Controller errors and logs contain operation metadata and exception
class only, never token values or raw exception messages.

The read-only `GET /api/v1/ops/offline-status` route requires the exact
`ops.status.read` scope. Its middleware rejects wildcard scope even though
Passport normally treats `*` as universal. Existing wildcard or empty-scope
tokens therefore remain visible for deliberate operator revocation but gain no
access to the new endpoint. The endpoint returns the existing content-free
offline/degraded projection, performs no mutation, and grants no agent, model,
review, deployment, face, or photo authority. Prompt compression and
precompaction remain source-enforced off.

## Private Search Query Telemetry

PLOS treats search terms as private text. The SearXNG service and MCP adapter
must not place raw query text, exception messages, or dropped result URLs in
their local logs. Their allowed receipt is byte length plus a purpose-scoped
HMAC-SHA256 fingerprint derived from `APP_KEY`; when the key is unavailable,
only length is retained. Plain SHA-256 is not an acceptable fallback because
short or predictable search terms are vulnerable to dictionary matching.

The connector emits explicit receipts, and the higher-level structured-context
boundary now covers research, RAG, chat, and Genea query/claim/prompt keys at
every audit-eligible configured application sink. Queries remain present in functional caller
responses and are transmitted to the operator-configured services required to
execute them. The boundary also redacts structured error/message fields and
replaces nested `Throwable` objects with type, code, and previous-type metadata
before Monolog can serialize messages or traces. A deterministic source audit
requires zero direct `getMessage()` interpolations in log message arguments.
It does not claim arbitrary variable or dynamically composed message bodies
are semantically safe. Prompt compression and precompaction remain source-
enforced off.

Production commit `d4f72c4b` verifies the earlier boundary through the 15/15
synthetic audit, which inspected 11 configured channels and found zero
unprotected among the nine audit-eligible sinks, plus a benign structured
canary in the active `single` sink. The framework emergency fallback remains
explicitly outside application-tap coverage. The raw marker was absent, the
private `query` value was replaced by `[PRIVATE_TEXT_REDACTED]`, and the safe
count and synthetic fingerprint remained. This canary proves structured-context
behavior only; it does not by itself prove the later exception-object boundary.
Commit
`a9d23c10` supplies that historical proof: production audit v2 passed 23/23 with zero
direct exception-message sites, and a benign nested-exception event omitted all
raw error/current/previous markers while preserving only the redaction
sentinel, exception types, code 27, safe reason code, and explicit false
message/trace flags. That four-site regex inventory is superseded by audit v3's
AST inventory: 3,344 recognized calls, 139 dynamic expressions, one bounded
literal choice, zero PSR placeholder templates, zero direct exception-detail
expressions, and zero read or parse gaps. New dynamic or placeholder expressions
fail the strict gate. The 139-site baseline remains review debt and is not a
claim that arbitrary free-form bodies are private or that PLOS performs
cross-function taint analysis.

The exception follow-up is grounded in the installed Monolog formatter behavior
and OpenTelemetry's warning that exception messages may contain sensitive
information. Safe operational fields use names such as `error_code`,
`error_count`, `exception_type`, and `failure_reason`; raw diagnostic bodies use
the redaction sentinel. Functional exception handling and caller responses are
unchanged by this log-only processor.

Upstream engine failure strings are also private/untrusted text. The connector
returns only a bounded engine label and fixed reason code. A raw timeout,
captcha, HTTP, network, or unknown exception string is not retained in logs or
responses. This sanitization does not hide failure: engine count, bounded
engine identity, degraded state, and failure class remain visible. Repeated
rows are grouped by unique engine with a bounded reason-code set; a separate
failure-event count preserves operational magnitude without retaining text.
