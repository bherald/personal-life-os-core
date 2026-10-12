<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Process;

class McpToolContractAuditService
{
    private const SCHEMA = 'plos.mcp_tool_contract_audit.v1';

    /** @return array<string,mixed> */
    public function audit(bool $failOnViolations = false): array
    {
        $directory = base_path('mcp-servers/plos');
        $script = $directory.'/dist/tool-contract-audit.js';
        $baseline = $directory.'/tool-contract-baseline.json';

        if (! is_file($script) || ! is_file($baseline)) {
            return $this->errorPayload('audit_artifact_missing', null, 'none', $failOnViolations);
        }

        $command = ['node', 'dist/tool-contract-audit.js'];
        if ($failOnViolations) {
            $command[] = '--fail-on-violations';
            $command[] = '--fail-on-drift';
        }

        $result = Process::path($directory)
            ->timeout(15)
            ->env([
                'PLOS_MCP_DOTENV_MODE' => 'skip',
                'SKIP_SSH_TUNNEL' => 'true',
            ])
            ->run($command);
        $payload = json_decode(trim($result->output()), true);

        if (! is_array($payload) || ($payload['schema'] ?? null) !== self::SCHEMA) {
            return $this->errorPayload(
                'invalid_audit_output',
                $result->exitCode(),
                $this->fingerprint($result->errorOutput()),
                $failOnViolations
            );
        }

        if ($result->exitCode() > 1 || ($result->exitCode() !== 0 && ($payload['status'] ?? null) === 'pass')) {
            return $this->errorPayload(
                'audit_runner_failed',
                $result->exitCode(),
                $this->fingerprint($result->errorOutput()),
                $failOnViolations
            );
        }

        $payload['runner'] = [
            'exit_code' => $result->exitCode(),
            'strict' => $failOnViolations,
            'stderr_sha256' => $this->fingerprint($result->errorOutput()),
        ];

        return $payload;
    }

    /** @return array<string,mixed> */
    private function errorPayload(string $code, ?int $exitCode, string $stderrSha256, bool $strict): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'error',
            'compact' => true,
            'tool_count' => 0,
            'content_sha256' => null,
            'expected_content_sha256' => null,
            'expected_digest_matched' => null,
            'summary' => [
                'violations' => 1,
                'drift_detected' => false,
                'tools_added' => 0,
                'tools_removed' => 0,
                'tools_changed' => 0,
            ],
            'drift' => [
                'detected' => false,
                'added' => [],
                'removed' => [],
                'changed' => [],
            ],
            'violations' => [[
                'severity' => 'block',
                'code' => $code,
                'tool' => 'plos_mcp_server',
            ]],
            'posture' => [
                'live_tools_list_exercised' => false,
                'arbitrary_mcp_configs_executed' => false,
                'network_used' => false,
                'metadata_uploaded' => false,
                'raw_descriptions_or_schemas_emitted' => false,
            ],
            'runner' => [
                'exit_code' => $exitCode,
                'strict' => $strict,
                'stderr_sha256' => $stderrSha256,
            ],
        ];
    }

    private function fingerprint(string $value): string
    {
        return $value === '' ? 'none' : hash('sha256', $value);
    }
}
