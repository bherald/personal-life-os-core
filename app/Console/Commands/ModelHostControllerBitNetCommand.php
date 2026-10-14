<?php

namespace App\Console\Commands;

use App\Services\BitLlmPrivacyGateService;
use App\Services\ModelHostControllerInferenceService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use RuntimeException;

class ModelHostControllerBitNetCommand extends Command
{
    protected $signature = 'model-host:bitnet
        {--text= : Redacted request description to classify}
        {--request-id= : Exact request identity}
        {--lease-id= : Exact controller lease identity}
        {--timeout=20 : Bounded inference timeout in seconds}
        {--sensitive-data : Mark the already-redacted description as sensitive}
        {--execute : Required managed lifecycle gate}
        {--json : Emit compact JSON}';

    protected $description = 'Run one explicit BitNet privacy decision through the serialized .87 controller lifecycle';

    public function __construct(private readonly ModelHostControllerInferenceService $inference)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('execute')) {
            $this->error('Managed BitNet inference requires --execute.');

            return self::FAILURE;
        }

        $text = trim((string) $this->option('text'));
        $requestId = trim((string) $this->option('request-id'));
        $leaseId = trim((string) $this->option('lease-id'));
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
        if ($text === '' || $requestId === '' || $leaseId === '') {
            $this->error('Managed BitNet inference requires --text, --request-id, and --lease-id.');

            return self::FAILURE;
        }
        if ($timeout === false || $timeout < 1 || $timeout > 120) {
            $this->error('Managed BitNet inference timeout must be between 1 and 120 seconds.');

            return self::FAILURE;
        }

        try {
            $payload = $this->inference->process(
                BitLlmPrivacyGateService::MODEL_ROLE,
                $text,
                [
                    'max_tokens' => 4,
                    'timeout' => $timeout,
                    'sensitive_data' => (bool) $this->option('sensitive-data'),
                ],
                $requestId,
                $leaseId,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
        $receipt = is_array($payload['controller_receipt'] ?? null) ? $payload['controller_receipt'] : [];
        $green = ($response['success'] ?? false) === true
            && in_array($response['decision'] ?? null, ['allow', 'deny'], true)
            && ($response['provider'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id')
            && ($receipt['result'] ?? null) === 'green';

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                'Managed BitNet decision=%s provider=%s controller=%s',
                (string) ($response['decision'] ?? 'error'),
                (string) ($response['provider'] ?? '-'),
                (string) ($receipt['result'] ?? 'error'),
            ));
        }

        return $green ? self::SUCCESS : self::FAILURE;
    }
}
