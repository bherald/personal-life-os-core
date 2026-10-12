<?php

namespace App\Console\Commands;

use App\Services\Ops\McpToolContractAuditService;
use Illuminate\Console\Command;

class OpsMcpToolContractAuditCommand extends Command
{
    protected $signature = 'ops:mcp-tool-contract-audit
        {--json : Emit machine-readable JSON}
        {--compact : Emit the content-free compact contract}
        {--fail-on-violations : Exit non-zero for security violations, baseline drift, or audit errors}';

    protected $description = 'Audit the live PLOS MCP tools/list contract against security policy and a reviewed hash baseline';

    public function handle(McpToolContractAuditService $service): int
    {
        $strict = (bool) $this->option('fail-on-violations');
        $payload = $service->audit($strict);

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode MCP tool-contract audit JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $this->line(sprintf(
                'MCP tool contract: %s tools=%d violations=%d drift=%s digest=%s',
                strtoupper((string) ($payload['status'] ?? 'error')),
                (int) ($payload['tool_count'] ?? 0),
                (int) data_get($payload, 'summary.violations', 0),
                data_get($payload, 'summary.drift_detected', false) ? 'yes' : 'no',
                (string) ($payload['content_sha256'] ?? 'unavailable')
            ));
        }

        if ($strict && ($payload['status'] ?? null) !== 'pass') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
