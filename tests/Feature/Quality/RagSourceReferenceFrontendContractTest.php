<?php

namespace Tests\Feature\Quality;

use Tests\TestCase;

class RagSourceReferenceFrontendContractTest extends TestCase
{
    public function test_chat_source_list_displays_backend_citation_readiness(): void
    {
        $view = file_get_contents(resource_path('js/src/views/AIHubView.vue'));

        $this->assertStringContainsString('source.source?.source_type', $view);
        $this->assertStringContainsString('source.source?.citation_status', $view);
        $this->assertStringContainsString("source_bound: 'Source linked'", $view);
        $this->assertStringContainsString("locator_only: 'Link available'", $view);
        $this->assertStringContainsString("document_only: 'Indexed copy'", $view);
    }
}
