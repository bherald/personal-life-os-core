<?php

$providerSpecificDomains = array_values(array_unique(array_filter(array_map(
    'trim',
    array_merge(
        [
            'ancestry.com',
            'familysearch.org',
            'fold3.com',
            env('NEWSPAPERS_PERSONAL_AUTOMATION_ENABLED', (bool) env('NEWSPAPERS_BARCODE')) ? null : 'newspapers.com',
            env('MYHERITAGE_PERSONAL_AUTOMATION_ENABLED', false) ? null : 'myheritage.com',
            'americanancestors.org',
            'nehgs.org',
            'findmypast.com',
        ],
        explode(',', (string) env('SCRAPING_PROVIDER_SPECIFIC_DOMAINS', '')),
        explode(',', (string) env('SCRAPING_MANUAL_ONLY_DOMAINS', ''))
    )
))));

return [
    // Current runtime policy: do not let robots.txt block partner-app scraping.
    // We still record the bypass decision in scrape metadata for auditability.
    'bypass_robots_txt' => env('SCRAPING_BYPASS_ROBOTS_TXT', true),

    // Treat external scraped content as untrusted data and neutralize common
    // prompt-injection patterns before it is reused in LLM-facing pipelines.
    'sanitize_untrusted_content' => env('SCRAPING_SANITIZE_UNTRUSTED_CONTENT', true),

    // Operator-configured advisory list for domains that need source-specific
    // posture. Private installs can still route these through configured APIs,
    // private adapters, headed-browser capture, or review-first media capture.
    'provider_specific_domains' => $providerSpecificDomains,

    // Backward-compatible alias for older tests/config rows. Do not use this
    // name for new UI/docs; provider_specific_domains is the current posture.
    'manual_only_domains' => $providerSpecificDomains,

    // Strict/public mode restores the historical hard block for provider-
    // specific domains. Private PLOS defaults this off so broad holdout labels
    // do not prevent permitted public/API/operator-owned capture.
    'enforce_provider_specific_domains' => env(
        'SCRAPING_ENFORCE_PROVIDER_SPECIFIC_DOMAINS',
        env('SCRAPING_ENFORCE_MANUAL_ONLY_DOMAINS', false)
    ),
    'enforce_manual_only_domains' => env(
        'SCRAPING_ENFORCE_MANUAL_ONLY_DOMAINS',
        env('SCRAPING_ENFORCE_PROVIDER_SPECIFIC_DOMAINS', false)
    ),
];
