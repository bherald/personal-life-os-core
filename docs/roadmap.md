# Roadmap

PLOS is an alpha public-core extraction candidate. This roadmap describes the
public direction without exporting the private active priority list.

In the private source tree, this roadmap is represented as one operator TODO
row. It should not be treated as a second private queue.

## Near Term

- Keep Docker-first core install reproducible.
- Improve setup doctor clarity and troubleshooting docs.
- Keep public smoke, CI, license audit, fixture provenance, and privacy guards
  passing.
- Finish public docs information architecture cleanup.
- Keep the first public GitHub Actions validation green as release docs change.

## Medium Term

- Build clearer operator evidence views for queue health, review backlog,
  local AI state, and degraded-mode status.
- Improve review workflows for agent-generated proposals.
- Continue RAG/GraphRAG reliability work with visible backlog and throughput
  evidence.
- Expand public-safe fixtures for file, media, and genealogy workflows.
- Prove optional media and GPU paths on clean hosts before advertising them as
  more than optional/experimental.
- Improve connector documentation for local-only Nextcloud, Joplin,
  Thunderbird, and notification adapters.

## Longer Term

- Define public plugin and adapter contracts.
- Improve local model evaluation and provider routing transparency.
- Broaden optional module support after core reliability is healthier.
- Build more complete admin screens for operators.
- Keep public release governance, third-party notices, and clean-room reference
  practices lightweight but explicit.

## Public-Core Non-Goals

- Private operator parity.
- Autonomous sensitive actions without review.
- Bundled model weights or private data.
- Subscription-site scraping as a public default.
- Production-readiness claims before public evidence supports them.
- Copying GPL/AGPL reference implementation code into the MIT public core.
