# Quickstart

This is the shortest public path to a working core PLOS install. It assumes a
local development machine with Docker Engine and Docker Compose available.

For the complete dependency inventory and non-Docker setup notes, see
`docs/public-install-prerequisites.md`.

## 1. Prepare Environment

```bash
cp .env.example .env
```

Edit `.env` before first boot:

- set `WEB_UI_MASTER_PASSWORD` to a real local password;
- leave private connector paths blank unless you are configuring them locally;
- do not add real credentials to committed files.

## 2. Build And Generate Keys

```bash
docker compose build app
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan passport:keys --force --no-interaction
```

## 3. Start Core Services

```bash
docker compose up -d mysql postgres redis app web
```

Load the shipped schema dumps through the database containers:

```bash
docker compose exec -T mysql sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < database/schema/mysql-schema.sql
docker compose exec -T postgres sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < database/schema/pgsql-schema.sql
docker compose exec app php artisan db:seed --class=PublicBaselineSeeder --force
```

## 4. Check Health

```bash
docker compose exec app php artisan setup:doctor --profile=core
```

The core doctor checks application configuration, database access, Redis,
runtime directories, and Passport keys. Fix failures before starting optional
profiles.

In Docker, zero failures is the first-boot target. Warnings for host-owned
tools such as Node/npm or the Docker binary can be normal inside the PHP app
container because frontend builds run in the `vite` service and Docker runs on
the host.

Setup doctor profiles are additive health gates:

| Profile | Use When | Docker Command |
| --- | --- | --- |
| `core` | First boot, scheduler, queues, review queues, basic RAG | `docker compose exec app php artisan setup:doctor --profile=core` |
| `media` | File/media extraction, OCR, thumbnails, face metadata | `docker compose exec app php artisan setup:doctor --profile=media --skip-services` |
| `gpu` | Host-specific local AI, Whisper, Transformers, heavier Python packages | `docker compose exec app php artisan setup:doctor --profile=gpu --skip-services` |
| `full` | Core plus optional media/GPU/service surfaces | `docker compose exec app php artisan setup:doctor --profile=full` |
| `personal` | Private-only connectors and operator credentials | Do not use for public CI or public examples |

Use `--json` for machine-readable reports, `--strict` when warnings should fail
CI, and `--only=env,php,binaries,python,services,database,browser,assets,docker`
to narrow a failing check group.

## 5. Start Workers And UI

```bash
docker compose up -d worker scheduler vite
```

Open `http://localhost:8000`, or the host port configured in your compose
environment.

## Common Commands

```bash
docker compose ps
docker compose logs -f app worker scheduler
docker compose down
docker compose up -d
docker compose build app
```

Run migrations only when applying changes beyond the shipped schema dumps:

```bash
docker compose exec app php artisan migrate
```

## Optional Profiles

The first boot does not require model weights, private data volumes,
Nextcloud/Joplin/Thunderbird paths, Pushover credentials, or personal compose
overlays.

- Media setup: see `docs/FACE-RECOGNITION.md` and run the media setup doctor.
- GPU setup: treat host-specific CUDA/PyTorch installation as optional and
  experimental until validated on your host.
- Full setup: start optional compose profiles such as Tika, SearXNG, Ollama, or
  Nextcloud before expecting full service checks to pass.
- Personal connectors: see `docs/personal-connectors.md`; keep real paths and
  credentials in ignored local files.
- Troubleshooting: see `docs/troubleshooting.md`.

For a Docker full-service evaluation, keep the core stack healthy first, then
start optional services and rerun the full profile check:

```bash
docker compose --profile full up -d
docker compose exec app php artisan setup:doctor --profile=full
```

## Public Smoke

Maintainers can reproduce the history-free public proof outside the private repo:

```bash
scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"
```

The smoke script exports a clean tree, installs public dependencies, generates
keys, runs setup doctor slices (`core --skip-services` and
`media --skip-services --only=assets,browser,docker`), runs public
audit/license checks, checks staged diff whitespace, and executes the focused
public test set.
