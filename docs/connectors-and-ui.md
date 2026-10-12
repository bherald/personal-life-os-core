# PLOS v2.1 UI and optional connectors

The web UI is part of the application. It needs the configured app, databases, queues and frontend build. Optional connectors require operator-owned services and credentials. Inspect [setup prerequisites](public-install-prerequisites.md) before enabling a connector.

## Navigate the work

The paths in [the Vue router](../resources/js/src/router/index.js) are:

| Path | Purpose |
| --- | --- |
| `/knowledge` | Knowledge Hub for the configured sources. |
| `/research` | Redirects through `/ai` to `/knowledge?source=ai`; it is not a separate research page. |
| `/reviews` | Review Desk for proposed work and operator decisions. |
| `/research-hub` | Compatibility route to the review surface. |
| `/joplin` | Redirects to Knowledge Hub. |
| `/workflows` | Workflow list and editor entry point. Creating, running or activating a workflow changes application state. |

Use synthetic sources when demonstrating the UI. A screenshot confirms rendering; it does not prove live research or downstream connector delivery.

## Nextcloud: selected filesystem access and WebDAV

[NextcloudFileApiService](../app/Services/NextcloudFileApiService.php) supports direct filesystem access when `NEXTCLOUD_DATA_PATH` is configured, with WebDAV and file-ID-aware operations where appropriate. The selected library root comes from `files.registry_scan_root` with the configured `NEXTCLOUD_LIBRARY_ROOT` fallback. The filesystem path resolver checks containment and refuses an unavailable or ambiguous root; it does not guess another collection.

Supply the URL, username, password, selected library root and optional filesystem mount privately through [config/services.php](../config/services.php) and an ignored environment/Compose overlay. Use a dedicated synthetic collection for a first run. Keep unrelated files, sync state, identities and backups outside that scan scope. Read permission does not grant move/delete/share authority.

## Joplin: notes, a sync target and RAG

[JoplinPaths](../app/Support/JoplinPaths.php) derives the configured sync path and optional local read root. [JoplinSyncService](../app/Services/JoplinSyncService.php) reads notes/attachments into the selected RAG route using source identity and content hashes. It is not the desktop or mobile client sync engine. Keep original note content and identity; repeated indexing should update the same source rather than create a second identity.

[JoplinWriteService](../app/Services/JoplinWriteService.php) writes through the operator's Nextcloud-backed WebDAV sync target, with locks and conflict-aware paths. A lock conflict can return a queued operation. `success=true, queued=true` is pending work, not verified delivery. Read back the intended note title/body/destination after completion. A server read-back does not establish phone/desktop sync.

Start with the read-only statistics surface:

```bash
php artisan joplin:sync --stats --json
```

The same command without `--stats` changes the RAG index and needs a reviewed source scope and configured model/embedding route. Note creation, append or replacement are distinct approved actions; a connector being configured does not authorize them. Never put real note/notebook IDs or content in public examples.

## Pushover: a selected recipient and a short status

The public environment placeholders `PUSHOVER_API_TOKEN` and `PUSHOVER_USER_KEY` are blank. [NotificationController](../app/Controllers/NotificationController.php) refuses a send when either is missing and applies source-group/rate-limit policy. [PushoverNotify](../app/Nodes/PushoverNotify.php) implements bounded multipart delivery handling. Supply credentials and the intended recipient privately; review the payload before enabling delivery.

For a nightly demonstration, use counts and a status such as “synthetic sources: 2; pending review: 1.” Leave source text, personal names, file paths and sensitive links out. A suppressed send is not a delivered message. A normal provider response is not proof the person saw it. Emergency-priority receipt acknowledgement is a separate optional mechanism; the nightly example does not require it.

Connector execution remains disabled in the [nightly example](nightly-review-example.md). No real service, note or recipient is bundled.
