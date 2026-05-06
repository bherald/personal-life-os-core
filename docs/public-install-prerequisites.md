# Public Install Prerequisites

This is the target dependency and setup inventory for supported public PLOS
installs. The repository includes `.env.example`, tiered Python requirements
files, a Docker development scaffold, schema-first database bootstrap files, and
the read-only `setup:doctor` health command. A disposable Docker proof for the
core public stack is tracked in `docs/public-release-readiness.md`.

## Verifying an Install

Run `php artisan setup:doctor` after `composer install` and `cp .env.example .env`. The command is read-only — it does not install anything, it does not write outside its report, and service/database probes stay local to the configured install. Useful invocations:

```bash
php artisan setup:doctor                                    # core profile, human-readable
php artisan setup:doctor --profile=full --json              # machine-readable
php artisan setup:doctor --profile=media --strict           # warnings count as failures
php artisan setup:doctor --skip-services                    # offline (no service/database probes)
php artisan setup:doctor --only=env,php,binaries,browser    # filter to specific groups
```

Profiles map to the install tiers below: `core`, `media`, `gpu`, `full` (all public tiers), and `personal` (full plus operator-local adapters). Check groups: `env`, `php`, `binaries`, `python`, `services`, `passport`, `database`, `browser`, `assets`, and `docker`. Exit code is `1` when any check fails; with `--strict`, warnings also exit `1`. The manifest of required env keys, PHP extensions, OS binaries, Python tiers, services, database features, browser runtimes, runtime assets, and Docker assets lives in `config/setup.php`.

For Docker installs, use the same profile vocabulary from inside the app
container:

| Profile | Docker Command |
| --- | --- |
| `core` | `docker compose exec app php artisan setup:doctor --profile=core` |
| `media` | `docker compose exec app php artisan setup:doctor --profile=media --skip-services` |
| `gpu` | `docker compose exec app php artisan setup:doctor --profile=gpu --skip-services` |
| `full` | `docker compose exec app php artisan setup:doctor --profile=full` |

Keep `personal` out of public CI and public examples; it is for private local
overlays with operator-owned paths, credentials, and adapters.

Current media-profile setup checks include PostgreSQL `pgvector` and `fuzzystrmatch` via `pgsql_rag`, optional `pg_trgm`, Python module imports, spaCy model availability, selected binary version floors, Tika version when reachable, Playwright Chromium availability, Puppeteer Chrome/Chromium availability, Docker binary/compose/daemon availability, writable Laravel storage paths, `NEXTCLOUD_DATA_PATH` readability when configured, browser helper scripts, and the dlib face-model files used by batch face detection. Missing dlib model files warn because they are large third-party assets, not files the doctor should install or commit.

Fresh public databases need the schema dumps because the historical baseline migration does not create the earliest PLOS tables:

```bash
php artisan migrate --schema-path=database/schema/mysql-schema.sql
PGPASSWORD="${RAG_DB_PASSWORD}" psql -U "${RAG_DB_USERNAME:-plos_rag}" -h "${RAG_DB_HOST:-127.0.0.1}" "${RAG_DB_DATABASE:-plos_rag}" < database/schema/pgsql-schema.sql
php artisan db:seed --class=PublicBaselineSeeder --force
```

Run normal `php artisan migrate` afterward only when applying future migrations beyond the shipped schema dump. The public baseline seeder fills the system rows that old data-seeding migrations no longer run after a schema-dump bootstrap: local Ollama routing, model profiles, routing/offline controls, conservative notification defaults, and free/manual genealogy provider rows.

For Docker installs, load schema dumps through the database containers rather
than from the app container:

```bash
docker compose exec -T mysql sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < database/schema/mysql-schema.sql
docker compose exec -T postgres sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < database/schema/pgsql-schema.sql
docker compose exec app php artisan db:seed --class=PublicBaselineSeeder --force
docker compose exec app php artisan setup:doctor --profile=core
```

For the Docker core path, treat failures as blockers. Warnings for host-owned
tools such as Node/npm or the Docker binary can be expected inside the PHP app
container because frontend builds run in the `vite` service and Docker runs on
the host.

## Install Tiers

Use public install tiers plus one private/operator profile for this working tree:

- **Core PLOS**: Laravel app, Vue UI, scheduler, queues, workflow engine, local AI routing, review queues, file registry metadata, and basic RAG.
- **Media PLOS**: core plus file/media extraction, thumbnails, OCR, face detection/clustering, and metadata checks.
- **GPU PLOS**: media plus host-specific PyTorch/CUDA, local transcription, embeddings, transformer, and heavier model workloads.
- **Full PLOS**: core plus media/GPU-capable tooling, same-host Nextcloud files, genealogy intake, browser automation, search, Joplin/Thunderbird adapters, and optional local GPU acceleration.
- **Personal PLOS**: full plus operator-local credentials and adapters such as Pushover, local Thunderbird bridge, private Joplin/Nextcloud paths, and opt-in private genealogy adapters. This profile is for private installs, not public CI. FamilySearch, Ancestry, Fold3, NEHGS/AmericanAncestors, and FindMyPast automation are intentionally not part of either public or private setup; keep them manual/browser-only source references. Newspapers.com remains private/personal-gated for operator-owned access, and MyHeritage automation is disabled unless explicitly enabled for a private install.

Do not require every media/genealogy dependency for a minimal first boot. Public setup should make optional modules fail visibly but cleanly when their tools are not installed.

Full-profile modules should advertise their runtime checks in the UI or installer. Existing extraction code already follows a fallback pattern for many tools, so the public setup should expose those checks instead of letting optional binaries fail silently.

## Optional Add-Ons

Public documentation should describe the bells and whistles as optional install layers, not as core requirements. Pushover is the mobile notification/review adapter; it is seeded disabled and becomes active only when an operator provides credentials and enables it. Nextcloud is the preferred same-host or LAN file/calendar/contact/media layer, with WebDAV as a compatibility path. Joplin and Thunderbird are interoperability adapters for operator-managed local data, not bundled services. Ollama, Tika, SearXNG, browser automation, media/face tooling, and genealogy source adapters are similarly additive: useful for the full PLOS experience, but not required for a first core boot.

## Required Core Runtime

- Linux host, preferably Ubuntu or Debian for first public support.
- PHP 8.3 recommended. Composer allows PHP `^8.2`, but the current operating baseline is PHP 8.3.
- Composer 2.
- Node.js 20+ and npm for Vite/Vue assets.
- `ripgrep` for public-release audit scripts and `python3-venv` for
  bare-metal smoke-test virtual environments on Debian/Ubuntu hosts.
- MySQL or MariaDB for Laravel transactional tables.
- PostgreSQL 16+ with `pgvector` for RAG, embeddings, graph search, and face vectors.
- MySQL client and `psql` for bare-metal schema-dump bootstrap; Docker installs
  can use the database containers' built-in clients.
- Enable `pgvector` in each target PostgreSQL database with `CREATE EXTENSION IF NOT EXISTS vector;` before running vector indexes or similarity search.
- Redis for queues, cache, locks, Horizon, and agent concurrency controls.
- Set `WEB_UI_MASTER_PASSWORD` to a real local password before running
  `setup:doctor`. The example value `change-me` is intentionally treated as a
  failed core check.
- Generate Passport RSA keys with `php artisan passport:keys --force --no-interaction` before
  `setup:doctor`; run `passport:install` later only if you need full OAuth
  clients backed by the database.
- Web server: Apache or Nginx with PHP-FPM. The public edition should document one default path rather than both on day one.
- Laravel Horizon or an equivalent queue worker managed by systemd or Docker.

Likely PHP extensions for the supported full install:

- `bcmath`, `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pcntl`, `pdo_mysql`, `pdo_pgsql`, `redis`, `simplexml`, `sockets`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `xsl`, `zip`.
- `pdo_sqlite` and `sqlite3` are optional for local test helpers and lightweight development databases.
- `imagick` is strongly recommended for PDF/image thumbnail fallback, but the code also uses CLI fallbacks such as `pdftoppm`.

## Docker Target

Docker is now represented by a public-safe `docker-compose.yml`, PHP-FPM image, Nginx config, and `docker/README.md` starter guide for reproducible local development and evaluation.
`setup:doctor` also warns when Docker, `docker compose`, or the Docker daemon
are unavailable. Those warnings are useful for Docker-based public installs but
should not block bare-metal installs unless the installer explicitly chooses
Docker. Node.js and npm are recommended core binaries for building assets, but
they are not required inside the PHP app container when the `vite` service owns
frontend builds.

Inside Docker, `.env` host-side `*_PORT` values remap published ports only. The
compose file pins canonical service-network ports inside `app`, `worker`, and
`scheduler`: `DB_PORT=3306`, `RAG_DB_PORT=5432`, and `REDIS_PORT=6379`.
`PYTHON_BINARY=python3` is also pinned in those containers so bare-metal
virtualenv paths such as `.venv/bin/python` do not leak into container runs.

On 2026-05-01, a separate Ubuntu 24.04.4 Hyper-V proof VM built the public app
image, started MySQL, PostgreSQL/pgvector, Redis, app, web, worker, and
scheduler, loaded both schema dumps through the database containers, ran
`PublicBaselineSeeder`, returned HTTP 200 through Nginx, started Horizon and
the scheduler, and passed the Docker-safe public setup/test slice with 95 tests
/ 206 assertions. `setup:doctor --profile=core` inside the app container is
expected to return warnings, not failures, because Node/npm live in host/CI/Vite
surfaces, Docker lives on the host, and service probes skip non-localhost
Compose service names.

Profile expectations for Docker evidence:

- `core`: current Docker proof covers the first boot path.
- `media`: use `--profile=media --skip-services --only=assets,browser,docker`
  for local asset/browser/docker checks without optional live services.
- `gpu`: not yet Docker-proven; validate on a host with matching CUDA/PyTorch
  wheels before treating it as release evidence.
- `full`: run only after starting optional compose services such as Tika,
  SearXNG, Ollama, and any private Nextcloud overlay.

Recommended containers:

- `app`: PHP-FPM or Laravel runtime.
- `web`: Nginx or Apache reverse proxy.
- `mysql`: transactional database.
- `postgres`: PostgreSQL with `pgvector`.
- `redis`: queue/cache/lock store.
- `worker`: Horizon or queue worker.
- `scheduler`: Laravel scheduler loop.
- `vite`: frontend dev server for local development.
- `tika`: Apache Tika server.
- `ollama`: optional local LLM container when GPU/device support is available.
- `searxng`: optional privacy-preserving search service.
- `nextcloud`: optional but recommended personal file service for full PLOS.

Personal/private services should be separated from public code:

- Public repo ships examples and empty volumes only.
- Personal data volumes, Nextcloud data, Ollama models, genealogy media, email archives, browser profiles, and credentials stay outside git.
- Compose files should use named volumes or documented bind mounts, with `.env.example` placeholders rather than private paths or LAN hosts.
- `docker-compose.personal.example.yml` shows how to attach private services without exposing the operator's real paths. Copy it to ignored `docker-compose.personal.yml`, set private paths in `.env`, and run with `docker compose -f docker-compose.yml -f docker-compose.personal.yml --profile full up -d`.

## Nextcloud File Layer

For full PLOS, local Nextcloud on the same machine is an advantage, not just an integration detail.

Preferred public architecture:

- Run Nextcloud on the same host or in the same Docker compose project as PLOS.
- Leave `NEXTCLOUD_DATA_PATH` blank for minimal installs. Configure it only
  when a real same-host Nextcloud data directory is mounted so PLOS can read
  heavy file workloads directly from the filesystem.
- Keep WebDAV enabled as the compatibility and remote-access layer for operations that need Nextcloud semantics, sharing, file IDs, calendar, contacts, or deployments where direct filesystem access is unavailable.

Why this matters:

- Direct filesystem scans, hashing, extraction, thumbnails, face detection, and RAG indexing are much faster and less fragile than recursive WebDAV.
- WebDAV remains valuable for server-side copy/move/delete, remote deployments, Nextcloud file IDs, and public-compatible fallback behavior.
- WebDAV is an HTTP API in this model; PLOS does not require a kernel WebDAV mount such as `davfs2`. Configure WebDAV through `NEXTCLOUD_URL` and credentials.
- The public docs should describe this as "filesystem-first, WebDAV fallback" and make the mount path explicit through environment variables.

Privacy framing for articles and public release:

- Mail is not a cloud-sync surface in the PLOS model. The operator uses Thunderbird locally on desktop and Android phone, with tools that process local mail stores or controlled local bridges.
- The intentional sync surface is local-LAN Nextcloud for calendar, contacts, documents, pictures, media, and full Joplin note sync across desktop and Android.
- Public messaging should emphasize this distinction: PLOS is not asking users to pour email and life data into a third-party cloud. It favors local device data plus self-hosted LAN sync for the domains that need cross-device access.

Personal connector expectations:

- **Nextcloud**: public code should work with WebDAV placeholders and optionally accelerate full-profile workloads through `NEXTCLOUD_DATA_PATH` when Nextcloud data is mounted on the same host.
- **Joplin**: PLOS expects an operator-managed Joplin sync target, typically through Nextcloud, with `NEXTCLOUD_JOPLIN_PATH` pointing at the mounted note data. Public installs should treat this as optional personal data, not a bundled service. Watch Later organization additionally needs an operator-supplied `JOPLIN_WATCH_LATER_FOLDER_ID`; public defaults must not include private notebook IDs or personal taxonomies.
- **Thunderbird**: email workflows depend on a local Thunderbird bridge/MCP endpoint such as `THUNDERBIRD_MCP_URL`. The public stack ships placeholders only; the bridge runs on the operator's device or trusted LAN host.
- **Email archives**: optional mbox RAG indexing reads from `NEXTCLOUD_DATA_PATH` plus `THUNDERBIRD_ARCHIVE_PROFILE_PATH`. Leave it unset or point it at a private mounted archive folder; do not ship real mailboxes.

Required public config placeholders:

- `NEXTCLOUD_URL`
- `NEXTCLOUD_USERNAME`
- `NEXTCLOUD_PASSWORD` or app password
- `NEXTCLOUD_DATA_PATH`
- `NEXTCLOUD_JOPLIN_PATH`
- `JOPLIN_WATCH_LATER_FOLDER_ID`
- `NEXTCLOUD_LIBRARY_ROOT` is the public placeholder for the top-level imported/media library path. `NEXTCLOUD_WINDOWS_BASE` is still accepted as a private backward-compatible shim.
- `THUNDERBIRD_ARCHIVE_PROFILE_PATH` is the Nextcloud-relative mbox archive folder used by optional email RAG indexing.

## OS Packages and Binaries

Install these for the full media/RAG/genealogy stack:

- `exiftool` or `libimage-exiftool-perl`: EXIF/XMP/IPTC extraction and face-region metadata writeback.
- `ffmpeg` and `ffprobe`: audio/video metadata, extraction, thumbnails, transcription preparation, and video hashing.
- `poppler-utils`: `pdftotext` and `pdftoppm` for PDF text and thumbnails.
- `tesseract-ocr` plus English language data: OCR fallback and image/PDF text extraction.
- `libreoffice` or `soffice`: Office document conversion and thumbnails.
- `docx2txt` and `antiword`: Word document text extraction fallbacks.
- `p7zip-full`, `tar`, and `genisoimage` or `isoinfo`: archive and ISO listing.
- `curl`, `wget`, `git`, `unzip`, and CA certificates.
- `build-essential`, `cmake`, Python development headers, `libopenblas-dev`, and `liblapack-dev` for Python face-recognition packages that compile native modules.
- Java 11+ runtime for Apache Tika 2.x when Tika is not containerized. The bundled `scripts/install-tika.sh` reference installer uses OpenJDK 17 and the Tika 2.9.2 standard server jar; Docker installs use the `apache/tika:2.9.2.1-full` image.
- Optional NVIDIA driver, CUDA runtime, and `nvidia-smi` for GPU-backed local inference, face processing, OCR, or transcription.
- Optional `yt-dlp` for YouTube transcript/media fallback paths.
- Optional `openai-whisper` Python package or compatible `whisper` CLI for audio/video transcription and YouTube transcript fallback paths.

## Python Packages

Python dependencies are split into tiered requirement files:

- `requirements-core.txt`: light helpers used by public scripts and database/vector helpers.
- `requirements-media.txt`: optional media, genealogy, face-recognition, clustering, and NLP dependencies.
- `requirements-gpu.txt`: optional PyTorch, transformer, embedding, HTR, and Whisper dependencies.

Each tier has a companion constraints snapshot:

- `requirements-core.constraints.txt`: generated from the passing public smoke environment.
- `requirements-media.constraints.txt`: generated from a Linux x86_64, Python 3.12, default-PyPI resolver dry run.
- `docs/public-release/python-license-snapshot-media.*`: generated from the
  2026-05-01 Ubuntu proof VM media virtualenv after installing the pinned media
  tier.
- `requirements-gpu.constraints.txt`: generated from a Linux x86_64, Python 3.12, default-PyPI resolver dry run. It may resolve a CUDA package family that is wrong for another host, driver, or PyTorch index.

The media/GPU snapshots pin `setuptools==80.9.0` because `face_recognition_models 0.3.0` still imports `pkg_resources`; setuptools 81+ broke that import path in the media install proof environment.
The license watch snapshot for these tiers lives in
`docs/python-constraints-license-snapshot.md`; the native/ML package review
matrix lives in `docs/native-ml-package-review.md`.

Python 3.10+ should be the documented floor for current PyTorch, Transformers, spaCy, and face-recognition dependency compatibility.
On Debian/Ubuntu hosts that enforce PEP 668, install these packages into a virtual environment instead of system Python:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install -c requirements-core.constraints.txt -r requirements-core.txt
sed -i 's#^PYTHON_BINARY=.*#PYTHON_BINARY=.venv/bin/python#' .env
php artisan setup:doctor --profile=core --skip-services --json
```

`setup:doctor` honors `PYTHON_BINARY`; leave it as `python3` for system or Docker installs, and point it at `.venv/bin/python` for bare-metal virtualenv installs.

Use the same constraints pattern for optional tiers when the host matches the snapshot assumptions:

```bash
.venv/bin/python -m pip install -c requirements-media.constraints.txt -r requirements-media.txt
.venv/bin/python -m pip install -c requirements-gpu.constraints.txt -r requirements-gpu.txt
```

Packages implied by current scripts:

- Face detection and embedding: `face_recognition`, `dlib`, `numpy`, `Pillow`.
- Batch face detector model files: `shape_predictor_68_face_landmarks.dat` and `dlib_face_recognition_resnet_model_v1.dat` beside or configured for `scripts/face_detector_batch.py`; see `docs/FACE-RECOGNITION.md` for download URLs and checksums.
- Face clustering: `hdbscan`, `numpy`, optional `psycopg2` or `psycopg2-binary` for direct PostgreSQL/pgvector mode.
- Community detection: `igraph`, `leidenalg`.
- NLP extraction: `spacy` plus `python -m spacy download en_core_web_sm`.
- HTR and transformer workloads: `torch`, `torchvision`, `transformers`, `Pillow`.
- HTR runs locally through `scripts/htr_transcribe.py` against a TrOCR model cached by Hugging Face. `TRANSKRIBUS_API_KEY` is an optional external-service placeholder; leave it blank for fully local installs.
- SPLADE and embedding scripts: `torch`, `transformers`, `sentence-transformers`.
- Common transitive helpers likely needed in a pinned public requirements set: `scikit-learn`, `scipy`, `tqdm`.

GPU installs should be documented separately because CUDA, PyTorch wheels, dlib, and consumer GPU support vary by host. `requirements-gpu.txt` is intentionally generic, and `requirements-gpu.constraints.txt` is only the default PyPI/Python 3.12/Linux resolver snapshot. CUDA users should install the matching PyTorch wheel/index for their driver before relying on it or regenerate a host-specific constraints file.

## Model Caches

Local AI scripts use standard Hugging Face cache locations for HTR, embeddings,
sentence-transformers, and transformer-based vision or Whisper workloads. Set
`HF_HOME` or `TRANSFORMERS_CACHE` to a writable path with enough free space;
several GB per model is normal. Whisper CLI installs use their own cache under
`~/.cache/whisper/` unless a custom `WHISPER_PATH` or runtime cache is set.

Model files are not vendored in this repository. Public installs download them
from upstream on first use; air-gapped installs should pre-seed caches from a
trusted machine.

## Node and Browser Tooling

Normal app setup uses `npm install` and `npm run build`, but full functionality also needs browser runtimes:

- Playwright browsers, at least Chromium: `npx playwright install --with-deps chromium`.
- Puppeteer browser cache or a configured system Chrome/Chromium path.
- `PUPPETEER_EXECUTABLE_PATH` or equivalent env config for hardened/server deployments.
- Optional Node browser helper servers under `scripts/browser-server`.
- Optional MCP packages from `package.json`, including filesystem, memory, puppeteer, sequential-thinking, Nextcloud, and graphlit MCP packages.
- Legacy `mcp-server/` workspace requires its own `cd mcp-server && npm ci && npm run build` if it remains in the public edition. It has a separate lockfile and a small direct dependency surface: MCP SDK, dotenv, Express, MySQL, ws, Zod, TypeScript, and Node types.

## Local AI and External Services

Required for offline-first local AI:

- Ollama installed locally, in Docker, or on a LAN host.
- Public `.env.example` entries for one or more Ollama base URLs and model names.
- Model bootstrap docs for a small default model, an embedding model, and optional vision/code models.

Optional services:

- Apache Tika server for broad document extraction.
- SearXNG for privacy-preserving research/search.
- Joplin sync target via Nextcloud for full local/LAN note synchronization across desktop and Android.
- Thunderbird MCP/service for local email workflows. Treat this as a local-device bridge, not a cloud email sync dependency.
- Pushover or other notification provider.
- External LLM providers for non-private escalation, explicitly disabled by default for sensitive personal data.
- Optional Claude Code CLI for high-capability local subprocess fallback and development orchestration. Prompts should be sent through stdin when used from automation.
- `setup:doctor --profile=personal` includes operator-local checks for Pushover, local Thunderbird, and private Joplin/Nextcloud path configuration. FamilySearch OAuth and Ancestry login/API checks are intentionally absent because those integrations are not reliable or allowed for automated use. Newspapers.com and MyHeritage are private opt-ins, not public defaults; run `php artisan config:clear` after changing their opt-in env flags. Public CI should use `core`, `media`, `gpu`, or `full`.

## Known Verification Gaps

Before a public installer is considered reliable, verify or close these gaps:

- `imagick` is now installed by the Docker PHP image for full-profile parity, and the core Docker build/first-run proof passed in the local verification environment. An independent clean-host repeat is still useful before calling the installer broadly reliable.
- Apache Tika versions are pinned to the 2.9 line: Docker compose uses the `apache/tika:2.9.2.1-full` image tag and `scripts/install-tika.sh` uses the official `tika-server-standard-2.9.2.jar` artifact. Keep docs, setup probes, and installer examples on the 2.9+ floor.
- `setup:doctor` now probes PostgreSQL `pgvector`, Playwright/Chromium availability, Puppeteer/Chrome configuration, and dlib model files, but the checks still need clean-machine evidence inside the clean public repo.
- Python import and spaCy model probes are wired. The core tier has a passing public smoke install, and the media tier passed both a disposable local venv install/import proof and a separate Ubuntu 24.04.4 proof VM install after pinning `setuptools<81` for `face_recognition_models`. The same proof VM passed basic generated-fixture checks for XMP parsing, OCR, ffmpeg/ffprobe, and Python graph/cluster imports. The native/ML package posture is documented in `docs/native-ml-package-review.md`. The future installer still needs optional media add-on proof for Playwright/Tika/SearXNG/Nextcloud/dlib model files if those are advertised as proven, plus host-specific GPU validation.
- Storage writability and `NEXTCLOUD_DATA_PATH` probes are wired, but the Docker/public installer still needs to create the recommended media cache directories up front.
- Docker engine/compose/daemon probes are wired as warnings, and the compose file now has app/Tika healthchecks. The core Docker-first happy path is documented and proven in both local and proof-VM environments; still run optional full-profile proof before broad release.
- The personal compose override example exists, but it still needs a human dry run with a real local Nextcloud/Joplin/Thunderbird setup before being treated as a supported recipe.
- `TIKA_URL` is a recommended media setting, not a required first-boot setting; public docs still need to show exactly when document extraction quality improves with Tika enabled.
- A standalone `docs/GPU-SETUP.md` for CUDA/PyTorch/dlib host configuration is intentionally not yet written. Until clean-machine GPU evidence exists, use the inline Python guidance and install the matching PyTorch wheel before `pip install -c requirements-gpu.constraints.txt -r requirements-gpu.txt`, or regenerate GPU constraints for the target host.
- Binary version floors are wired for ExifTool, FFmpeg/FFprobe, Tesseract, and Java; expand only after clean-machine evidence shows a real version-related failure.
- Tika version probing is wired for reachable localhost services and warns below Tika 2.9.
- `mcp-servers/plos/`, `thunderbird-extension/`, and any browser-helper workspaces need public-safe READMEs if they ship.
- Public fixtures now have a first `tests/Fixtures/PROVENANCE.md` covering synthetic GEDCOM, mail, RSS/search, and XMP face-region metadata samples. Add binary media only after provenance is explicit.

## Public Release Caveats

Before publishing:

- Keep the root `LICENSE`, `SECURITY.md`, public `README.md`, and installer/setup health checks aligned as setup decisions change. `composer.json` currently declares MIT; public fixtures now have provenance rows, the Python tiers have constraints snapshots, core/media Python license snapshots exist, model/runtime posture is tracked in `docs/model-runtime-license-map.md`, and native/ML package posture is tracked in `docs/native-ml-package-review.md`. The public repo still needs formal dependency-license signoff, optional media add-on proof if advertised as proven, and host-specific GPU evidence before a formal public tag.
- Replace private operator home paths, LAN ranges, production hostnames, passwords, app tokens, and personal paths with env variables and examples.
- Keep `CLAUDE.md`, `.claude.json`, `.mcp.json`, personal Nextcloud paths, genealogy data, email archives, and prod deployment files out of the public extraction.
- Keep the neutral `ops-*` public theme names. If a private branded theme is restored later, ship it only as a private package or local override.
- Present Joplin, PhotoPrism, LibrePhotos, Gramps, Gramps Web, and webtrees as workflow/interoperability references only. Do not copy GPL/AGPL code unless PLOS intentionally adopts a compatible license.
- Treat face metadata writeback as a PLOS feature based on open metadata standards and ExifTool-compatible fields, with consent, backup, and dry-run modes.
- Publish fake fixtures and sample media only. Never ship real family tree data, private photos, email, health records, or personal documents.
