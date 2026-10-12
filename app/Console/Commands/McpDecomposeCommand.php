<?php

namespace App\Console\Commands;

use App\Support\PromptIntegrityPolicy;
use Illuminate\Console\Command;

class McpDecomposeCommand extends Command
{
    protected $signature = 'ops:mcp-decompose
                            {--payload= : Base64-encoded JSON payload with prompt/config}';

    protected $description = 'Retired: automatic AI prompt decomposition is disabled by prompt-integrity policy';

    public function handle(): int
    {
        $this->line(json_encode([
            'success' => false,
            'available' => false,
            'schema' => PromptIntegrityPolicy::SCHEMA,
            'error' => PromptIntegrityPolicy::DISABLED_REASON,
            'message' => 'AI prompt compression and automatic decomposition are disabled to preserve complete detail.',
        ], JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
