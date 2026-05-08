<?php

namespace Tests\Feature\Quality;

use Tests\TestCase;

class MediaLightboxFrontendContractTest extends TestCase
{
    public function test_media_lightbox_metadata_does_not_dump_structured_json_values(): void
    {
        $source = file_get_contents(resource_path('js/src/components/media/MediaLightbox.vue'));

        foreach ([
            'formatMetadataDisplayValue(val)',
            'function formatMetadataDisplayValue(val)',
            'Structured metadata (${val.length} items)',
            'Structured metadata (${keys.length} fields)',
            "typeof val === 'object') return formatMetadataDisplayValue(val)",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        foreach ([
            "typeof val === 'object' ? JSON.stringify(val) : val",
            "val.map(v => typeof v === 'object' ? JSON.stringify(v) : v).join(', ')",
            'JSON.stringify(val, null, 1)',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }
}
