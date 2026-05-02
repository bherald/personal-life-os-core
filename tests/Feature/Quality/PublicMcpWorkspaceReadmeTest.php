<?php

namespace Tests\Feature\Quality;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicMcpWorkspaceReadmeTest extends TestCase
{
    #[Test]
    public function exported_mcp_workspaces_have_readmes_and_env_examples(): void
    {
        foreach ([
            'mcp-server/README.md',
            'mcp-servers/plos/README.md',
            'mcp-servers/plos/.env.example',
        ] as $path) {
            $this->assertFileExists(base_path($path), "{$path} must exist for public extraction");
        }
    }

    #[Test]
    public function modern_mcp_package_is_in_public_export_allowlist(): void
    {
        $script = file_get_contents(base_path('scripts/public-export.sh'));

        foreach ([
            'mcp-servers/plos/.env.example',
            'mcp-servers/plos/README.md',
            'mcp-servers/plos/package-lock.json',
            'mcp-servers/plos/package.json',
            'mcp-servers/plos/src',
            'mcp-servers/plos/tsconfig.json',
        ] as $path) {
            $this->assertStringContainsString($path, $script, "{$path} must be exported");
        }
    }

    #[Test]
    public function legacy_mcp_server_has_no_case_duplicate_readme(): void
    {
        $this->assertFileDoesNotExist(
            base_path('mcp-server/Readme.md'),
            'Case-only duplicate readmes break clean public exports on case-insensitive filesystems.'
        );
    }
}
