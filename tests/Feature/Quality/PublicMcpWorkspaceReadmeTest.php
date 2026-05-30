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
    public function modern_mcp_public_docs_use_neutral_remote_env_names(): void
    {
        $readme = file_get_contents(base_path('mcp-servers/plos/README.md'));
        $envExample = file_get_contents(base_path('mcp-servers/plos/.env.example'));
        $combined = $readme.$envExample;

        foreach ([
            'PLOS_REMOTE_HOST',
            'PLOS_REMOTE_USER',
            'PLOS_REMOTE_SSH_KEY',
            'PLOS_REMOTE_PROJECT_ROOT',
        ] as $name) {
            $this->assertStringContainsString($name, $readme, "{$name} must be documented in the modern MCP README.");
            $this->assertStringContainsString($name, $envExample, "{$name} must be present in the modern MCP env example.");
        }

        $this->assertStringContainsString('Legacy PROD_* names are still accepted', $envExample);
        foreach ([
            'PLOS_PROD_HOST',
            'PLOS_PROD_USER',
            'PLOS_PROD_PATH',
            'PROD_HOST=',
            'PROD_USER=',
            'PROD_PATH=',
            'PRODUCTION_HOST',
        ] as $legacyName) {
            $this->assertStringNotContainsString($legacyName, $combined);
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

    #[Test]
    public function operator_docs_pin_compact_trace_tail_and_stale_allowlist_guidance(): void
    {
        $operation = file_get_contents(base_path('docs/operation.md'));
        $troubleshooting = file_get_contents(base_path('docs/troubleshooting.md'));
        $combined = $operation.$troubleshooting;

        $exactTraceTail = 'php artisan plos:agent-trace-tail --limit=20 --since=24 --json --compact';

        $this->assertStringContainsString($exactTraceTail, $operation);
        $this->assertStringContainsString($exactTraceTail, $troubleshooting);
        $this->assertStringContainsString('plos_artisan list', $troubleshooting);
        $this->assertStringContainsString('allowlist revision', strtolower($combined));
        $this->assertStringNotContainsString('php artisan plos:agent-trace-tail --json', $combined);
        $this->assertStringNotContainsString('php artisan plos:agent-trace-tail --since=24 --limit=20 --json', $combined);
        $this->assertStringNotContainsString('php artisan plos:agent-trace-tail --limit=50', $combined);
    }
}
