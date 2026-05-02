# Docker Development Scaffold

This compose stack is for public-safe local development and evaluation. It is not a production deployment recipe.

## First Run

```bash
cp .env.example .env
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

Run normal `php artisan migrate` afterward only when applying future migrations
beyond the shipped schema dumps.

Open PLOS at `http://localhost:8000` and Vite at `http://localhost:5173`.

Change the placeholder passwords in `.env` before exposing the stack outside a private development machine.
MySQL, PostgreSQL, and Redis bind to `127.0.0.1` by default.

## Setup Doctor Profiles

Run profile checks from the `app` container after the matching services,
packages, or runtime assets are present:

| Profile | Use When | Command |
| --- | --- | --- |
| `core` | First boot, scheduler, queues, review queues, basic RAG | `docker compose exec app php artisan setup:doctor --profile=core` |
| `media` | File/media extraction, OCR, thumbnails, face metadata | `docker compose exec app php artisan setup:doctor --profile=media --skip-services` |
| `gpu` | Host-specific local AI, Whisper, Transformers, heavier Python packages | `docker compose exec app php artisan setup:doctor --profile=gpu --skip-services` |
| `full` | Core plus optional media/GPU/service surfaces | `docker compose exec app php artisan setup:doctor --profile=full` |
| `personal` | Private-only connectors and operator credentials | Keep out of public CI and public examples |

Use `--json` for machine-readable output and `--strict` when warnings should
fail CI. The `core`, `media`, `gpu`, and `full` profiles are the public
documentation and CI vocabulary; `personal` is for ignored local overlays.

## Host Ports vs Container Ports

`APP_HTTP_PORT`, `MYSQL_PORT`, `POSTGRES_PORT`, `REDIS_PORT`, `TIKA_PORT`,
`SEARXNG_PORT`, `OLLAMA_PORT`, and `NEXTCLOUD_PORT` remap only the host-side
published ports. Inside the compose network, services reach each other by
service name on canonical container ports: `mysql:3306`, `postgres:5432`,
`redis:6379`, `tika:9998`, `searxng:8080`, `ollama:11434`, and
`nextcloud:80`.

The compose file pins `DB_PORT=3306`, `RAG_DB_PORT=5432`,
`REDIS_PORT=6379`, and `PYTHON_BINARY=python3` inside `app`, `worker`, and
`scheduler`. This keeps host port remaps and bare-metal `.venv` Python paths
from leaking into container-to-container traffic.

The PHP image installs `requirements-core.txt` by default and includes the PHP
extensions required by the full setup profile, including `imagick`. For a full
media/genealogy image, set `PYTHON_REQUIREMENTS=requirements-media.txt` before
`docker compose build app`. For transformer/Whisper workloads, install the
right PyTorch CPU/CUDA wheels for the host before relying on
`requirements-gpu.txt`.

The app image does not include Node/npm or database client CLIs. Frontend work
belongs in the `vite` container or on the host, and schema dump loading should
use the `mysql` and `postgres` containers as shown above. It is normal for
`setup:doctor --profile=core` inside the app container to report warnings for
Node/npm, non-localhost Docker service probes, and the Docker host binary while
still reporting zero failures.

## Full Profile

The full profile adds local services used by media, RAG, search, AI, and file workflows:

```bash
docker compose --profile full up -d
```

Included full-profile services:

- Tika at `http://localhost:9998`
- SearXNG at `http://localhost:8888`
- Ollama at `http://localhost:11434`
- Nextcloud at `http://localhost:8080`

Granular profiles are also available: `tika`, `search`, `ollama`, and `nextcloud`.

The bundled Ollama service is CPU-only by default. For GPU-backed inference, run Ollama on the host or add NVIDIA Container Toolkit device reservations in a private compose override, then point `OLLAMA_API_URL` at that service.

## Personal Override

Keep private files and machine paths out of the public compose file. Copy the
example override and adjust only ignored/local files:

```bash
cp docker-compose.personal.example.yml docker-compose.personal.yml
docker compose -f docker-compose.yml -f docker-compose.personal.yml --profile full up -d
```

Set the real paths in `.env`, for example:

```text
PLOS_PERSONAL_NEXTCLOUD_ROOT=/srv/nextcloud/data/plos
PLOS_PERSONAL_NEXTCLOUD_DATA_PATH=/srv/personal/nextcloud/files
PLOS_PERSONAL_JOPLIN_PATH=/Joplin-data
PLOS_PERSONAL_THUNDERBIRD_MCP_URL=http://host.docker.internal:8766
PLOS_PERSONAL_OLLAMA_MODELS=/srv/ollama
```

The public example intentionally uses placeholders. The real
`docker-compose.personal.yml` should remain ignored and private.

## Nextcloud Filesystem Path

The `nextcloud_data` volume is mounted into the PLOS containers at `/srv/nextcloud/data` as read-only. Minimal installs leave filesystem-first reads disabled:

```text
NEXTCLOUD_DATA_PATH=
```

When the `nextcloud` profile or a real same-host Nextcloud mount is attached,
set the path to the matching user directory, for example:

```text
NEXTCLOUD_DATA_PATH=/srv/nextcloud/data/{user}/files
```

That gives the file registry, media extraction, thumbnails, face detection, and RAG indexing a fast filesystem-first path. WebDAV remains the compatibility path for Nextcloud file IDs, sharing, server-side moves/copies, calendar, contacts, and remote deployments.

If you change `NEXTCLOUD_USERNAME`, update `NEXTCLOUD_DATA_PATH` to match the new user directory.

Inside Docker, PLOS containers use service names such as `http://nextcloud`, `http://ollama:11434`, `http://tika:9998`, and `http://searxng:8080`. The localhost URLs in `.env.example` are for non-Docker host installs.

Without the `nextcloud` profile, the mounted `nextcloud_data` volume is empty. Minimal installs should treat Nextcloud filesystem reads as unavailable and use WebDAV or local app storage until a real Nextcloud volume is attached.

## Useful Commands

```bash
docker compose logs -f app
docker compose exec app php artisan test
docker compose exec app php artisan ops:validate-sql
docker compose exec app php artisan setup:doctor --profile=core
docker compose exec app php artisan setup:doctor --profile=full --json
docker compose exec app composer install
docker compose run --rm vite npm run build
docker compose down
```

`setup:doctor` is the read-only public-install health command. It walks env,
PHP, binaries, Python, services, database, browser, assets, and docker check
groups against `config/setup.php` and exits `1` on any failure (or any warning
with `--strict`). Use `--skip-services` to skip socket probes when running
outside the compose network.

The `composer_vendor` named volume is seeded on first build. If `composer.json` changes and dependencies look stale, run `docker compose down -v` or remove the `composer_vendor` volume before rebuilding.

Use named volumes for local data. Do not bind real personal archives or credentials into a public demo unless they are private and intentionally excluded from git.
