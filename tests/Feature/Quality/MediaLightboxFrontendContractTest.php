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

    public function test_media_lightbox_path_display_uses_bounded_safe_labels(): void
    {
        $source = file_get_contents(resource_path('js/src/components/media/MediaLightbox.vue'));

        foreach ([
            'function displayMediaPath(path)',
            '{{ displayMediaPath(item.current_path) }}',
            '<MetaField label="Current Path" :value="displayMediaPath(fileData.current_path)" mono full />',
            '<MetaField v-if="fileData.original_path && fileData.original_path !== fileData.current_path" label="Original Path" :value="displayMediaPath(fileData.original_path)" mono full />',
            'Configured media location',
            'replace(/^(home|users)\\/[^/]+\\//i, \'\')',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        foreach ([
            '{{ item.current_path }}',
            '<MetaField label="Current Path" :value="fileData.current_path" mono full />',
            'label="Original Path" :value="fileData.original_path"',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }
}
