# Personal Connectors

PLOS is local-first. The public stack should expose connector contracts and
safe placeholders; each operator supplies the private services, bind mounts,
credentials, and data paths in an ignored local override.

## Nextcloud

Same-host or same-LAN Nextcloud is the preferred full-profile file layer.
PLOS can use WebDAV for compatibility, file IDs, sharing, server-side copy and
move operations, calendar, contacts, and remote deployments. For heavy local
workloads, configure `NEXTCLOUD_DATA_PATH` so PLOS can read files directly from
the mounted Nextcloud data directory.

The filesystem-first path is what makes large scans practical: hashing,
metadata extraction, thumbnails, OCR preparation, face detection, and RAG
indexing avoid slow recursive WebDAV reads. WebDAV remains the fallback and the
semantic Nextcloud interface.

## Joplin

Joplin is treated as an operator-managed notes surface and interoperability
target, not a bundled public service or copied application component. A typical
private setup uses Joplin desktop and Android clients synced through local-LAN
Nextcloud. PLOS then reads the mounted sync target through
`NEXTCLOUD_JOPLIN_PATH` or a private connector bridge.

Public installs should keep Joplin optional. If the path is not configured,
Joplin workflows should fail visibly or skip cleanly rather than blocking core
PLOS setup.

Private single-operator PLOS use is not blocked by this adapter. The public
release risk is redistribution of copied upstream application code; the PLOS
adapter should stay limited to independently implemented filesystem/WebDAV
interoperability with the operator's sync target.

Operator-side prerequisites stay outside PLOS itself: Joplin desktop and/or
mobile clients should sync against the operator's local Nextcloud, with the
resulting sync directory mounted into the PLOS host or container at
`NEXTCLOUD_JOPLIN_PATH`. PLOS reads that directory; it does not run Joplin sync.
Optional Joplin automation such as YouTube Watch Later organization must use
operator-supplied IDs such as `JOPLIN_WATCH_LATER_FOLDER_ID`; the public repo
should ship neutral defaults only.

## Thunderbird

Mail is not a cloud-sync surface in the PLOS model. The operator can use
Thunderbird locally on desktop and phone-side mail tools, while PLOS talks to a
controlled local bridge such as `THUNDERBIRD_MCP_URL`.

Thunderbird has two separate integration surfaces:

- read/search/RAG indexing can use a replicated Thunderbird profile or archive
  path, but should only parse the mbox stores under `Mail/` and `ImapMail/`;
- sending uses a live Thunderbird instance with the PLOS extension connected, so
  Thunderbird handles the configured accounts, OAuth/app-password state, and
  SMTP submission instead of PLOS storing mail credentials.

For private installs, point the email archive indexer at the replicated profile
with `THUNDERBIRD_ARCHIVE_PROFILE_PATH` relative to `NEXTCLOUD_DATA_PATH`. Do not
index the whole Thunderbird profile: files such as credential stores, cookies,
OpenPGP state, caches, and extension storage are backup/runtime data, not RAG
content. If a replicated profile is used to seed the sending machine, copy or
restore it into a stable local Thunderbird profile first; do not have Windows
and the sending host write the same synced profile concurrently.

The public repository should not ship a real mailbox, profile path, or cloud
mail dependency. It should document the endpoint placeholder and leave the
bridge process to the operator's trusted machine or LAN.

Operator-side prerequisites are a local Thunderbird or compatible mail bridge
exposing the endpoint named by `THUNDERBIRD_MCP_URL`. The public repo ships the
client-side adapter and placeholder URL; the bridge process and trusted device
placement are the operator's responsibility.

## Private Compose Overlay

Use `docker-compose.personal.example.yml` as a template for attaching real
paths and optional services:

```bash
cp docker-compose.personal.example.yml docker-compose.personal.yml
docker compose -f docker-compose.yml -f docker-compose.personal.yml --profile full up -d
```

Keep `docker-compose.personal.yml` ignored and private. Set only placeholder
values in `.env.example`; real paths, local service URLs, OAuth credentials,
and private data volumes belong in the private overlay or the operator's local
environment.
