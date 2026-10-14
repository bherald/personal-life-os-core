# PLOS — Personal Life Operating System

A local-first research and automation workbench. PLOS brings documents, web
sources, AI-assisted analysis, workflows and human review into one system on
hardware you control.

**Release status:** v2.1, October 14, 2026.
[Release notes](docs/releases/v2.1.md)
and [verification status](docs/public-release-readiness.md) distinguish shipped
source from tested installation and research outcomes.

Public CI installs and probes Bubblewrap for its Linux MCP isolation fixture;
the focused-test job uses Ubuntu 22.04. Public Readiness and Repository Governance
passed for the preceding source snapshot at `41d2407` on October 14, 2026.
The additional corrections below have isolated source checks; hosted verification
follows their publication.

Laravel 12 · Vue · MySQL/MariaDB · PostgreSQL + pgvector · Redis · Docker ·
operator-configured local AI. Application code is MIT licensed; third-party
components and demo assets retain their [separate terms](THIRD_PARTY.md).

Routing uses table-owned provider/model bindings and
retires prompt-compressor integration while retaining complete original input.
See [routing and upgrade notes](docs/table-owned-llm-routing.md).

Offline vision retains its registered instance/model, MCP sidecar defaults come
from Laravel table policy, and enabled research requires an explicit provider
and model role. Matching MCP metadata and isolated regression checks accompany
these route-default corrections.

Verbatim RAG publication preserves complete embedding inputs and verifies chunk
model/revision consistency. Knowledge-graph batches honor registered table route
defaults. Scheduled recovery retains unknown domain outcomes after verified
physical closure, and image audits report bounded error codes without private
exception text. Optional upstream metadata preparation uses explicit public GET
scope and remains disabled until configured by the installation operator.

## Research with sources you can inspect

PLOS provides the working parts of a research process: persistent questions,
source retrieval, local corpus search, model-assisted synthesis and a place to
review the evidence. It can support recurring topic monitoring, technical
investigation, document comparison and family-history research.

| Research need | PLOS capability |
| --- | --- |
| Work from your own documents | File indexing, hybrid RAG/GraphRAG retrieval and source-bound references. |
| Investigate a question over time | Research topics and missions with scheduling, execution history and review context. |
| Bring in current material | Configured multi-source web retrieval, deduplication and visible partial coverage. |
| Inspect a proposed answer | Retained source context, citation-readiness states and research review queues. |
| Keep the operator in control | Policy-gated AI/tool routes and human approval for sensitive changes. |
| Reuse work across tools | Workflow nodes, application APIs and optional MCP adapters. |

Start with the [research workbench walkthrough](docs/research-workbench.md).
Source references help you check an answer; they do not make the answer correct.
The release does not claim benchmarked research accuracy or autonomous scientific
discovery. Models, embedding routes and external source access are configured
by the operator.

## More than a chat window

The same framework supports scheduled workflows, review queues, file/media
extraction, source-backed genealogy packets and optional Joplin, Nextcloud,
browser, YouTube, transcription and notification adapters. The current review
UI uses Decision Desk controls. Photos can retain operator-approved names and
face-region metadata; mathematical face embeddings are separate private data.

PLOS ships application source and synthetic/permitted examples. It does not ship
the maintainer's databases, private model routes, accounts, family records,
photos, credentials or runtime sessions.

## Quick start: Docker core

```bash
cp .env.example .env
# Set WEB_UI_MASTER_PASSWORD and database passwords in .env.
docker compose build app
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan passport:keys --force --no-interaction
docker compose up -d mysql postgres redis app web
docker compose exec -T mysql sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < database/schema/mysql-schema.sql
docker compose exec -T postgres sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < database/schema/pgsql-schema.sql
docker compose exec app php artisan db:seed --class=PublicBaselineSeeder --force
docker compose exec app php artisan setup:doctor --profile=core
docker compose up -d worker scheduler vite
```

Open `http://localhost:8000`. The core doctor should report zero failures before
you add research/model/media services. Placeholder passwords intentionally fail
the configuration checks. See [quick start](docs/quickstart.md) and
[Docker setup](docker/README.md) for host ports and schema/migration guidance.

## Add the services you need

| Profile | Adds |
| --- | --- |
| Core | App, scheduler, queues, review queues and basic retrieval infrastructure. |
| Media | Thumbnails, extraction, OCR, face detection and metadata tooling. |
| GPU | Optional heavier local inference/transcription dependencies. |
| Full | Configured research, search and connector services. |
| Personal | Operator-owned mail, sync and notification adapters. |

Use `php artisan setup:doctor --profile=<profile>` to inspect prerequisites.
For the current AI contracts and separately reported live configuration, run
`php artisan codex:offline-pipeline-tools --json`. Installing a package does not
activate its route, download model weights or grant permission for an action.

Recent source-preservation, review-ownership and uncertain-operation repairs are described in the [v2.1 notes](docs/releases/v2.1.md). Their synthetic source tests do not certify configured runtime delivery.
Explicit Codex preference retains privacy and routing admission. The cancelled privileged maintenance adapter is excluded from the candidate.

## Documentation

- [Research workbench](docs/research-workbench.md)
- [Portable photo knowledge demo](docs/photo-knowledge-demo.md)
- [UI and connectors](docs/connectors-and-ui.md), [four fictional nightly use cases](docs/nightly-use-cases.md) and [inactive retrieval example](docs/nightly-review-example.md) and [portable methods](docs/portable-methods.md)
- [Public skills and MCP scope](docs/public-methods/skills-and-mcp.md)
- [Offline controller contracts](examples/offline-controller/README.md) and [release article](docs/articles/plos-v2.1.md)
- [Quick start](docs/quickstart.md) and [installation prerequisites](docs/public-install-prerequisites.md)
- [Operation](docs/operation.md) and [troubleshooting](docs/troubleshooting.md)
- [Architecture](docs/architecture.md)
- [Security and privacy](docs/security-privacy.md)
- [Research and upstream provenance](docs/research-provenance.md)
- [Contributor guide](CONTRIBUTING.md), [notices](NOTICE.md) and [third-party terms](THIRD_PARTY.md)

## Contribute and support

Use synthetic or explicitly permitted data in examples and bug reports. Preserve
source content and operator decisions. Keep sensitive actions and face identity
under human review.

PLOS is free. Optional one-time tips through the repository's GitHub Sponsors
button help cover hardware, storage, electricity, hosting and testing. A tip
does not purchase support, access, priority or a deliverable.

## Release verification

Run `scripts/guards/public-release-audit.sh`,
`scripts/guards/dependency-provenance-check.sh` and `scripts/audit-licenses.sh`
before preparing a public update. Use the correct optional setup profile:

```bash
php artisan setup:doctor --profile=core
php artisan setup:doctor --profile=media
php artisan setup:doctor --profile=gpu
php artisan setup:doctor --profile=full
```

Pushover is optional, not a required public dependency.

The v2.1 web build requires modern browsers: Safari 16.4+, Chrome 111+ or Firefox 128+. See [frontend/dependency qualification](docs/public-release/ui-dependency-upgrade-2026-10-12.md).

License snapshot generation uses verified exact-version registry evidence for
reviewed entries so fresh npm installs retain reproducible license metadata.
Conflicting installed license claims are refused.
