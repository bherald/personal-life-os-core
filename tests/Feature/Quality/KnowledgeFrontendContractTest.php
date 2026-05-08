<?php

namespace Tests\Feature\Quality;

use Tests\TestCase;

class KnowledgeFrontendContractTest extends TestCase
{
    public function test_knowledge_preview_panel_uses_safe_path_and_structured_fallbacks(): void
    {
        $source = file_get_contents(resource_path('js/src/components/knowledge/KnowledgePreviewPanel.vue'));

        foreach ([
            'function displayKnowledgePath(path)',
            'function describeStructuredContent(data)',
            'function displayAttachmentName(att)',
            'function escapeHtml(value)',
            ':title="displayKnowledgePath(item.path)"',
            '{{ displayKnowledgePath(item.path) }}',
            'textContent.value = typeof data === \'string\' ? data : describeStructuredContent(data)',
            'Structured response (${Object.keys(data).length} fields)',
            'Structured response (${data.length} items)',
            "return 'Attachment'",
            '${escapeHtml(filename)}',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        foreach ([
            ':title="item.path">{{ item.path }}',
            'JSON.stringify(data, null, 2)',
            'att.filename || att.resource_id',
            '${filename}</a>',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }

    public function test_knowledge_content_grid_does_not_expose_raw_file_paths_as_tooltips(): void
    {
        $source = file_get_contents(resource_path('js/src/components/knowledge/KnowledgeContentGrid.vue'));

        foreach ([
            'function displayKnowledgePath(path)',
            ':title="displayKnowledgePath(item.path || item.current_path)"',
            '{{ displayKnowledgePath(item.path || item.current_path) }}',
            'Configured file location',
            'replace(/^(home|users)\\/[^/]+\\//i, \'\')',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        foreach ([
            ':title="item.path || item.current_path"',
            '{{ shortenPath(item.path || item.current_path) }}',
            'function shortenPath(path)',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }
}
