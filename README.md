# PLOS

PLOS is a local-first personal automation platform for workflows, file
indexing, RAG/GraphRAG search, review queues, and operator-guided agents that
run on hardware the operator controls.

Status: alpha public-core extraction candidate. License: MIT. Primary stack:
Laravel 12, Vue, MySQL/MariaDB, PostgreSQL 16 + pgvector, Redis, Docker, and
optional Ollama-compatible local AI.

## What You Can Do

- Index local files and run hybrid RAG/GraphRAG search across them.
- Run scheduled workflows with human review queues before sensitive actions.
- Route AI calls to local Ollama-compatible hosts by default, with optional
  external providers behind explicit policy gates.
- Use agent and MCP tooling with offline/hybrid policy checks and audit
  receipts.
- Enable optional media, face-region metadata, genealogy, browser automation,
  Joplin, Nextcloud, Thunderbird, YouTube, research, and notification modules.

## Quick Start: Docker

Docker is the preferred public proof path for the core stack.

```bash
cp .env.example .env
# Edit .env now: set WEB_UI_MASTER_PASSWORD to a real local value.
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

Open `http://localhost:8000`. Change placeholder passwords in `.env` before
exposing the stack outside a private development machine.

Run normal `php artisan migrate` afterward only when applying migrations beyond
the shipped schema dumps. See [docker/README.md](docker/README.md) for host-port
remaps, optional service profiles, and private compose overlays.

## Install Tiers

| Tier | Adds | Setup Doctor |
| --- | --- | --- |
| Core | Laravel app, scheduler, queues, review queues, setup doctor, basic RAG | `php artisan setup:doctor --profile=core` |
| Media | File/media extraction, thumbnails, OCR, face detection, metadata checks | `php artisan setup:doctor --profile=media` |
| GPU | PyTorch, Transformers, Whisper, heavier local AI workloads | `php artisan setup:doctor --profile=gpu` |
| Full | Core + media/GPU plus Tika, SearXNG, Ollama, Nextcloud-style services | `php artisan setup:doctor --profile=full` |
| Personal | Operator-only paths and adapters such as Pushover, private Joplin/Nextcloud, Thunderbird bridge | `php artisan setup:doctor --profile=personal` |

`personal` is for private installs. Public CI and public examples should use
`core`, `media`, `gpu`, or `full`.
Media can run against the default stack when local assets are present. Full
expects optional services such as Tika, SearXNG, Ollama, and optional
Nextcloud-style surfaces to be started first. GPU remains host-specific and
needs matching PyTorch/CUDA setup before its doctor result is release evidence.

## Bare-Metal Install

Preflight:

1. Install PHP 8.3, Composer 2, Node.js 20+, npm, Python 3.10+, MySQL/MariaDB,
   PostgreSQL 16 with `pgvector`, Redis, and `ripgrep`.
2. Create the MySQL/MariaDB database and user named in `.env`.
3. Create the PostgreSQL RAG database and role named by `RAG_DB_DATABASE` and
   `RAG_DB_USERNAME`; enable `CREATE EXTENSION IF NOT EXISTS vector;`.
4. Start Redis.
5. Set `WEB_UI_MASTER_PASSWORD` to a real local value. The example `change-me`
   intentionally fails setup checks.

```bash
composer install
npm install
python3 -m venv .venv
.venv/bin/python -m pip install -c requirements-core.constraints.txt -r requirements-core.txt
cp .env.example .env
sed -i 's#^PYTHON_BINARY=.*#PYTHON_BINARY=.venv/bin/python#' .env
php artisan key:generate
php artisan passport:keys --force --no-interaction
php artisan setup:doctor --profile=core
php artisan migrate --schema-path=database/schema/mysql-schema.sql
PGPASSWORD="${RAG_DB_PASSWORD}" psql -U "${RAG_DB_USERNAME:-plos_rag}" -h "${RAG_DB_HOST:-127.0.0.1}" "${RAG_DB_DATABASE:-plos_rag}" < database/schema/pgsql-schema.sql
php artisan db:seed --class=PublicBaselineSeeder --force
npm run build
```

For media/genealogy work, install with `-c requirements-media.constraints.txt
-r requirements-media.txt`. GPU/transformer workloads need host-specific
PyTorch/CUDA setup before using `requirements-gpu.constraints.txt` with
`requirements-gpu.txt`; that snapshot was generated on Linux x86_64 with
Python 3.12 and default PyPI indexes.

## Setup Doctor

`php artisan setup:doctor` is read-only. It checks env, PHP extensions,
binaries, Python modules, services, Passport keys, database features, browser
runtimes, runtime assets, and Docker assets. It exits `1` on failures; with
`--strict`, warnings also exit `1`.

```bash
php artisan setup:doctor --profile=core --json
php artisan setup:doctor --profile=media --skip-services
php artisan setup:doctor --profile=full --only=python,services,database
```

Use `--skip-services` or a narrower `--only=...` slice when optional services
are not running. The public smoke currently proves `core --skip-services` and
the media `assets,browser,docker` slice; GPU and full evidence remain
clean-host/tag-gate work.

## Local AI

PLOS is designed to stay useful without internet access. Local
Ollama-compatible hosts provide model inference, embeddings, and vision where
configured. External providers are optional and should remain behind explicit
routing, sensitivity, and offline-mode policy checks.

## Local-First Connectors

The public repo ships placeholders and adapter contracts. Operators attach real
paths and credentials through `.env` and ignored local overlays.

- **Nextcloud**: same-host or same-LAN file/calendar/contact/media sync.
  `NEXTCLOUD_DATA_PATH` enables fast filesystem-first scans; WebDAV remains the
  compatibility path for sharing, file IDs, calendar, contacts, and remote
  deployments.
- **Joplin**: optional operator-managed notes surface, typically synced through
  local Nextcloud. PLOS reads `NEXTCLOUD_JOPLIN_PATH`; it does not run Joplin
  sync or bundle Joplin application code.
- **Thunderbird**: local mail bridge via `THUNDERBIRD_MCP_URL`. Mail is treated
  as a local-device surface, not a general cloud mailbox sync.
- **Pushover**: optional notification/review adapter, seeded disabled until an
  operator provides credentials and enables it; it is not a required public dependency.

Use [docker-compose.personal.example.yml](docker-compose.personal.example.yml)
as the template for private bind mounts. Keep the real
`docker-compose.personal.yml` out of git. See
[docs/personal-connectors.md](docs/personal-connectors.md).

## What Stays Private

Do not publish private history or this private working tree directly. The
public release should be a fresh, history-free repository generated from the
reviewed allowlist.

Never include real credentials, real Nextcloud/Joplin/Thunderbird paths,
personal genealogy data, email/finance archives, private browser profiles,
operator deployment scripts, `.env`, `.mcp.json`, `.claude.json`, `CLAUDE.md`,
or `docker-compose.personal.yml` in the public repo.

FamilySearch, Ancestry, Fold3, NEHGS/AmericanAncestors, and FindMyPast
automation is intentionally absent. Keep them as manual/browser-only source
references. Newspapers.com and MyHeritage belong behind private opt-in
configuration for operator-owned access, not public defaults.

## Provenance And Licensing

PLOS source is MIT-licensed unless a file states otherwise. Projects such as
Gramps, Gramps Web, webtrees, PhotoPrism, LibrePhotos, and Joplin are workflow,
data-model, and interoperability references only. Do not copy GPL/AGPL
implementation code into a permissive PLOS extraction unless the license
strategy intentionally changes.

Face-region metadata writeback is standards-oriented: ExifTool-compatible
XMP/MWG/IPTC metadata paths, not copied photo-manager implementation code.

Release references:

- [NOTICE.md](NOTICE.md): compact third-party notices.
- [THIRD_PARTY.md](THIRD_PARTY.md): dependency and provenance watch items.
- [docs/research-provenance.md](docs/research-provenance.md): research and
  project citation map.
- [tests/Fixtures/PROVENANCE.md](tests/Fixtures/PROVENANCE.md): fixture source
  and license/status register.

## Public Release Tools

Maintainers preparing a public extraction from a private working tree should
run:

```bash
scripts/guards/public-release-audit.sh
scripts/audit-licenses.sh
scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"
```

Seed a fresh public-candidate tree with:

```bash
scripts/public-export.sh --force "$HOME/tmp/personal-life-os-core"
```

See [docs/public-release-readiness.md](docs/public-release-readiness.md).

## Documentation

- [docs/README.md](docs/README.md): canonical documentation map.
- [docs/quickstart.md](docs/quickstart.md): shortest Docker-first first boot.
- [docs/public-install-prerequisites.md](docs/public-install-prerequisites.md):
  dependency and setup inventory.
- [docs/operation.md](docs/operation.md): public operation and maintenance.
- [docs/troubleshooting.md](docs/troubleshooting.md): common install and runtime failures.
- [docs/security-privacy.md](docs/security-privacy.md): local-first privacy and public audit guidance.
- [docs/roadmap.md](docs/roadmap.md): public-core direction.
- [docs/clean-room-references.md](docs/clean-room-references.md): reference-project non-derivation rules.
- [docs/python-constraints-license-snapshot.md](docs/python-constraints-license-snapshot.md):
  Python package license watch items.
- [docs/public-release-readiness.md](docs/public-release-readiness.md): release
  checklist and remaining validation.
- [docs/schema-reference.md](docs/schema-reference.md): database schema
  reference.
- [docs/FACE-RECOGNITION.md](docs/FACE-RECOGNITION.md): dlib model setup and
  face-recognition safety.
- [docs/face-metadata-writeback.md](docs/face-metadata-writeback.md): portable
  face/person metadata writeback.
- [docs/AGENT-SAFETY-CARDS.md](docs/AGENT-SAFETY-CARDS.md): agent safety and
  operator rules.

## Security

For public releases, run the public audit and keep real credentials, private
paths, personal data, and operator-only deployment files out of git. See
[SECURITY.md](SECURITY.md) and [CONTRIBUTING.md](CONTRIBUTING.md).
