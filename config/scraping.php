<?php

return [
    // Current runtime policy: do not let robots.txt block partner-app scraping.
    // We still record the bypass decision in scrape metadata for auditability.
    'bypass_robots_txt' => env('SCRAPING_BYPASS_ROBOTS_TXT', true),

    // Treat external scraped content as untrusted data and neutralize common
    // prompt-injection patterns before it is reused in LLM-facing pipelines.
    'sanitize_untrusted_content' => env('SCRAPING_SANITIZE_UNTRUSTED_CONTENT', true),

    // Domains that may be kept as citation targets or operator-opened links,
    // but must not be scraped, logged in to, or browser-automated by PLOS.
    'manual_only_domains' => array_values(array_filter([
        'ancestry.com',
        'familysearch.org',
        'fold3.com',
        env('NEWSPAPERS_PERSONAL_AUTOMATION_ENABLED', (bool) env('NEWSPAPERS_BARCODE')) ? null : 'newspapers.com',
        env('MYHERITAGE_PERSONAL_AUTOMATION_ENABLED', false) ? null : 'myheritage.com',
        'americanancestors.org',
        'nehgs.org',
        'findmypast.com',
    ])),
];
