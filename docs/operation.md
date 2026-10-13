# Operation

Start with [Quick start](quickstart.md), then run `setup:doctor` for the installed
profile. Core installation, optional media dependencies and model configuration
are separate checks.

The public Linux MCP isolation test requires a trusted Bubblewrap installation
and working PID/network namespaces. Public CI installs the distro package and
probes those capabilities before the real socket fixture. Admission failures
remain failures; inspect their diagnostic rather than bypassing isolation.
The focused-test job selects GitHub's supported Ubuntu 22.04 VM; audit and
Compose jobs keep their existing runner. Host policy and application sandbox
rules remain unchanged. Other Ubuntu releases require their own qualification.

## Research and evidence

Use the configured topic/mission surfaces and Review Desk (`/reviews`) for
evidence and proposed conclusions. See [UI routes](connectors-and-ui.md). Configure source access and the selected
AI route before scheduling work. See [Research workbench](research-workbench.md).

Keep original documents, retrieved text, source URLs and retrieval dates. A model
summary is an interpretation; it does not replace a source. Citation readiness
means a result has a usable source reference, not that the claim is proved.

## Queues and scheduling

Bring up workers and the scheduler after core services pass setup doctor. Inspect
execution status and failure receipts before retrying. Interrupted work may retain
custody while completion is uncertain; do not erase that evidence to force a retry.
Review queues keep sensitive changes under human control.

## Local AI and external sources

The public baseline provides operator-configured local AI defaults. Add only
the models and endpoints you actually run. Optional external providers and web
search require explicit credentials and network policy. Offline mode blocks
external LLM routes; it does not supply fresh web evidence.

## Photos and family history

Detection proposes regions. A person decides identities. Preserve the original
image and existing metadata before any write, compare exact source versions,
and read back stored regions and names. Never substitute a family association
for an approved face identity. Defer a metadata update when source or identity
proof is missing. Numerical face embeddings remain private application data;
portable name and region metadata is a separate output.

## Backups and secrets

Back up the databases, original media, configuration and operator-managed
connector state separately. Test restoration. Keep credentials and authentication
keys outside Git, apply restrictive file permissions, and never expose an
authenticated installation on a public interface without reviewing its access
policy. See [Security and privacy](security-privacy.md).

## Optional controller experiments

Machine-specific manifests, private runtime receipts and installation-specific
route migrations are excluded from the public extraction. Controller transport
defaults are empty; optional controller and LAN-observation paths require
explicit host configuration plus their existing reviewed activation policy.
Their presence in source is not a public hardware qualification claim.

## Bounded agent diagnostics

```bash
php artisan plos:agent-trace-tail --limit=20 --since=24 --json --compact
```

Inspect `plos_artisan list` for the loaded command allowlist revision. A stale
allowlist is a tooling mismatch, not permission to bypass a policy refusal.

## Release checks and pending operations

Run `php artisan ops:release-documentation-audit --staged --json --compact --fail-on-gaps` before committing source changes. The public configuration requires the public README, release notes, operation guide, architecture and source/license references when their corresponding surfaces change; it retains every release-audit category without requiring private work records.

Use `ops:release-cache-invalidate --json` for named configuration/provider inventory invalidation. Global `cache:clear` is held because the store also contains live execution custody and response state. On an uncertain physical action outcome, retain its original durable identity for evidence reconciliation; a timeout, stop or lost acknowledgement does not permit an automatic replay. Human review owns photo/identity and substantive finding approval.

## Public maintenance scope

The cancelled privileged maintenance MCP adapter and host helper are excluded.
Use your installation's reviewed deployment and service procedures; the public
example does not install privileged helpers or qualify mounts. Explicit Codex
preference retains offline/local-only, privacy, profile, role and exact provider
admission; preference alone does not grant permission to send source data.

License-snapshot checks bind reviewed registry metadata to exact npm package
versions. Installed and absent manifests produce the same reviewed entries; a
conflicting installed license is refused. Keep the license/provenance checks
enabled when preparing a clean public installation.
