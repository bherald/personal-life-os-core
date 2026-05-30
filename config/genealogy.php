<?php

/**
 * Genealogy pipeline operational limits (N87).
 */
$libraryRoot = rtrim((string) env('NEXTCLOUD_LIBRARY_ROOT', '/Library'), '/');
$genealogyRoot = rtrim((string) env('GENEALOGY_NEXTCLOUD_ROOT', $libraryRoot.'/Genealogy'), '/');
$faceSyncRoot = rtrim((string) env('GENEALOGY_FACE_SYNC_ROOT', $libraryRoot.'/Media'), '/');
$ftReferenceRoot = rtrim((string) env('GENEALOGY_FT_REFERENCE_ROOT', $libraryRoot.'/FamilyTree/__intake'), '/');
$legacyMediaRoot = rtrim((string) env('GENEALOGY_LEGACY_MEDIA_ROOT', $libraryRoot.'/Media'), '/');
$mediaScanRoots = env(
    'GENEALOGY_MEDIA_SCAN_ROOTS',
    implode(',', [
        $genealogyRoot,
        $faceSyncRoot,
        $libraryRoot.'/Photos',
        $libraryRoot.'/Pictures',
        $libraryRoot.'/Documents',
    ])
);

return [
    'nextcloud_root' => $genealogyRoot,
    'face_sync_root' => $faceSyncRoot,
    'ft_reference_root' => $ftReferenceRoot,
    'legacy_media_root' => $legacyMediaRoot,
    'media_scan_roots' => array_values(array_filter(array_map('trim', explode(',', $mediaScanRoots)))),

    'evidence_asset_capture' => [
        'downloads_enabled' => (bool) env('GENEALOGY_EVIDENCE_ASSET_CAPTURE_DOWNLOADS_ENABLED', true),
        'storage_writes_enabled' => (bool) env('GENEALOGY_EVIDENCE_ASSET_CAPTURE_STORAGE_WRITES_ENABLED', true),
        'genealogy_links_enabled' => (bool) env('GENEALOGY_EVIDENCE_ASSET_CAPTURE_LINKS_ENABLED', true),
        'max_bytes' => (int) env('GENEALOGY_EVIDENCE_ASSET_CAPTURE_MAX_BYTES', 26214400),
        'blocked_remote_host_suffixes' => array_values(array_filter([
            env('GENEALOGY_EVIDENCE_CAPTURE_BLOCK_BILLIONGRAVES', true) ? 'billiongraves.com' : null,
        ])),
    ],

    // HTR (TrOCR) path-scoped policy — reserves the GTX 1060 for genealogy
    // trees. HtrTranscriptionService::transcribe() skips work when the
    // image path is not under any enabled prefix, unless the caller opts
    // in with ['force' => true] (e.g., ContentExtractionService's HTR
    // fallback, the audit command, tests). Empty list = fail-open
    // (HTR runs everywhere; preserves current behavior).
    'htr_enabled_paths' => array_values(array_filter([
        env('GENEALOGY_HTR_ROOT', $genealogyRoot),
        env('GENEALOGY_HTR_FT_ROOT', $ftReferenceRoot),
        env('GENEALOGY_HTR_MASTER_ROOT'),
    ])),

    // N135 ingest pipeline config.
    //
    // skip_folders matches the immediate parent folder name (lowercased)
    // against this list — a file whose parent folder matches is skipped
    // during ingest. Default is intentionally narrow (only auto-generated
    // thumbnail folders), because operator folders are not cleanly sorted:
    // a JPG under `photos/` might still be a scanned certificate worth
    // ingesting, and even genuine portraits carry EXIF face data that the
    // downstream face pipeline uses to bond them to tree persons. Keep
    // the list minimal and let downstream classifiers decide per-file.
    'ingest' => [
        'skip_folders' => [
            'thumbnails',
        ],
    ],

    'media_consolidation_base' => env(
        'GENEALOGY_MEDIA_CONSOLIDATION_BASE',
        '/srv/genealogy/library'
    ),

    // FANClusterService — census neighbor lookup per person per event
    'fan_census_neighbor_limit' => (int) env('GENEALOGY_FAN_CENSUS_NEIGHBOR_LIMIT', 100), // Max census neighbors evaluated per person per event in FAN cluster build

    // RecordHintService — relationship scoring query
    'record_hint_rel_scoring_limit' => (int) env('GENEALOGY_RECORD_HINT_REL_SCORING_LIMIT', 12), // Max relatives evaluated for corroboration scoring

    // Queue-mode source_add URL construction (AgentLoopService::normalizeQueueModeSourceLocator).
    //
    // Each key is a normalized source-name substring (lower-cased, alphanumerics + spaces).
    // Each value is a sprintf-style URL template with {id} or {ark} placeholders.
    // Adding a provider = add one entry here, no PHP code change.
    'source_url_templates' => [
        'national archives' => 'https://catalog.archives.gov/id/{id}',
        'nara' => 'https://catalog.archives.gov/id/{id}',
        'findagrave' => 'https://www.findagrave.com/memorial/{id}',
        'find a grave' => 'https://www.findagrave.com/memorial/{id}',
        'billiongraves' => 'https://billiongraves.com/grave/{id}',
        'familysearch' => 'https://www.familysearch.org/{ark}',
        'library of congress' => 'https://www.loc.gov/resource/{id}',
        'chronicling america' => 'https://chroniclingamerica.loc.gov/lccn/{id}',
        'ancestry' => 'https://www.ancestry.com/discoveryui-content/view/{id}',
        'wikitree' => 'https://www.wikitree.com/wiki/{id}',
        'fold3' => 'https://www.fold3.com/image/{id}',
        'ellis island' => 'https://heritage.statueofliberty.org/passenger/detail/{id}',
        'dar' => 'https://services.dar.org/Public/DAR_Research/search_adb/?action=details&p_id={id}',
    ],

    // Field names to check when walking a result row for a URL locator.
    // Tools vary: some use 'url', some 'link', some put a URL in 'id'.
    'source_locator_fields' => [
        'url', 'record_url', 'link', 'href', 'catalog_url', 'memorial_url',
        'id', 'record_id', 'external_id', 'ark', 'permalink',
    ],

    // Hosts that sit behind Cloudflare and refuse non-browser traffic with
    // HTTP 403 regardless of User-Agent. `genealogy:backfill-source-media
    // --puppeteer` uses this list to decide when to retry a blocked fetch
    // via the Puppeteer MCP server. Operator can append hosts without code
    // changes; add only the bare host (no scheme, no path).
    // 2.1a — per-provider cap on searchAll results. Prevents any single
    // provider (LoC historically dominated) from monopolizing the
    // aggregated result set. Set to 0 to disable capping.
    'search_all' => [
        'per_provider_cap' => (int) env('GENEALOGY_SEARCH_PROVIDER_CAP', 10),
    ],

    // Public genealogy web sources the planner may use for source-scoped web
    // searches when no first-class API wrapper exists. These are discovery
    // surfaces only: hits still require source vetting and identity bridges
    // before they can become source_add proposals.
    'public_web_sources' => [
        ['name' => 'Digitalarkivet Norway', 'domain' => 'digitalarkivet.no', 'url' => 'https://www.digitalarkivet.no', 'regions' => ['scandinavia'], 'record_types' => ['church', 'census', 'emigration']],
        ['name' => 'Danish National Archives', 'domain' => 'sa.dk', 'url' => 'https://www.sa.dk', 'regions' => ['scandinavia'], 'record_types' => ['church', 'census', 'probate']],
        ['name' => 'Swedish National Archives', 'domain' => 'riksarkivet.se', 'url' => 'https://sok.riksarkivet.se', 'regions' => ['scandinavia'], 'record_types' => ['church', 'census', 'military']],
        ['name' => 'Antenati Italy', 'domain' => 'antenati.cultura.gov.it', 'url' => 'https://antenati.cultura.gov.it', 'regions' => ['italy'], 'record_types' => ['vital']],
        ['name' => 'FranceArchives', 'domain' => 'francearchives.gouv.fr', 'url' => 'https://francearchives.gouv.fr', 'regions' => ['france'], 'record_types' => ['vital', 'church', 'military']],
        ['name' => 'Geneteka Poland', 'domain' => 'geneteka.genealodzy.pl', 'url' => 'https://geneteka.genealodzy.pl', 'regions' => ['eastern_europe'], 'record_types' => ['vital', 'church']],
        ['name' => 'Matricula Online', 'domain' => 'matricula-online.eu', 'url' => 'https://www.matricula-online.eu', 'regions' => ['german_origin', 'eastern_europe', 'italy', 'france'], 'record_types' => ['church']],
        ['name' => 'FreeBMD England and Wales', 'domain' => 'freebmd.org.uk', 'url' => 'https://www.freebmd.org.uk', 'regions' => ['uk_ireland'], 'record_types' => ['vital']],
        ['name' => 'UK National Archives Discovery', 'domain' => 'nationalarchives.gov.uk', 'url' => 'https://discovery.nationalarchives.gov.uk', 'regions' => ['uk_ireland'], 'record_types' => ['military', 'probate', 'land']],
    ],

    // Catalogs that can seed new source candidates for review. Catalog trust
    // stops at source discovery: imported candidates must still pass domain,
    // access-mode, API/scrape, and evidence-posture vetting before planner use.
    'trusted_source_catalogs' => [
        ['name' => 'FamilySearch Research Wiki', 'url' => 'https://www.familysearch.org/en/wiki/FamilySearch_Research_Wiki', 'posture' => 'locality_guidance_manual_only'],
        ['name' => "Cyndi's List", 'url' => 'https://www.cyndislist.com', 'posture' => 'curated_directory_candidate_seed'],
        ['name' => 'Archives Portal Europe', 'url' => 'https://www.archivesportaleurope.net', 'posture' => 'archive_repository_catalog'],
        ['name' => 'Europeana', 'url' => 'https://www.europeana.eu', 'posture' => 'api_backed_cultural_record_catalog'],
        ['name' => 'Wikidata', 'url' => 'https://www.wikidata.org', 'posture' => 'structured_institution_metadata_seed'],
    ],

    'search_planner' => [
        'enabled' => (bool) env('GENEALOGY_LLM_SEARCH_PLANNER_ENABLED', true),
        'max_calls' => (int) env('GENEALOGY_LLM_SEARCH_PLANNER_MAX_CALLS', 6),
        'ai_timeout_seconds' => (int) env('GENEALOGY_LLM_SEARCH_PLANNER_TIMEOUT', 25),
        'allowed_tools' => [
            'source_search_all',
            'generate_record_hints',
            'newspaper_search',
            'newspaper_search_obituaries',
            'nara_search',
            'internet_archive_search',
            'wikitree_search',
            'ellis_island_search',
            'freedmens_bureau_search',
            'german_church_records_search',
            'europeana_search',
            'dar_search',
            'mcp_searxng_search',
            'mcp_genealogy_search',
        ],
    ],

    'cloudflare_blocked_hosts' => [
        'www.loc.gov',
        'chroniclingamerica.loc.gov',
        'www.familysearch.org',
    ],

    // ProximityNameMatcher — max tokens between given and surname when
    // verifying a candidate record refers to the target person. Rejects
    // cross-document token co-occurrence as same-person evidence.
    // Operator-found flaw 2026-04-18 — see project memory
    // project_genealogy_name_match_proximity.md.
    'name_match' => [
        'proximity_window' => (int) env('GENEALOGY_NAME_MATCH_PROXIMITY_WINDOW', 3),
        'relationship_proximity_window' => (int) env('GENEALOGY_NAME_MATCH_REL_PROXIMITY_WINDOW', 8),

        // Per-provider opt-out from query-layer phrase wrapping.
        // Default tight — multi-word queries are wrapped in double quotes
        // so the provider returns exact-phrase hits instead of OR-across-
        // tokens. Set a provider's flag true (or pass `allow_loose` in
        // options) when the operator needs diagnostic loose matching.
        'fallback_loose' => [
            'nara' => (bool) env('GENEALOGY_NARA_LOOSE', false),
            'chronicling_america' => (bool) env('GENEALOGY_LOC_LOOSE', false),
            'newspapers_com' => (bool) env('GENEALOGY_NEWSPAPERS_LOOSE', false),
            'europeana' => (bool) env('GENEALOGY_EUROPEANA_LOOSE', false),
        ],
    ],

    // 2.1e provenance classification — drives scoreRelationships branching.
    // `structured` providers return records where relationship fields
    // (spouse_name, father_name, …) are tied to the primary entity; trust
    // the field. `full_text` providers return records where relationship
    // names may have been scraped from arbitrary positions in a document
    // body; require the relative's name to appear as a proximity-valid
    // phrase AND within `relationship_proximity_window` tokens of the
    // target's name span. Missing provider → treat as full_text (strict).
    'provider_extraction_mode' => [
        'familysearch' => 'structured',
        'nara_census' => 'structured',
        'ellis_island' => 'structured',
        'findagrave' => 'structured',
        'billiongraves' => 'structured',
        'wikitree' => 'structured',
        'dar' => 'structured',
        'chronicling_america' => 'full_text',
        'newspapers_com' => 'full_text',
        'europeana' => 'full_text',
        'nara' => 'full_text',
        'ancestry' => 'full_text',
        'fold3' => 'full_text',
    ],
];
