<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3InferenceService;
use Illuminate\Console\Command;
use RuntimeException;

class ModelHostControllerMhc08K3Command extends Command
{
    protected $signature = 'model-host:mhc08-k3
                            {--execute : Required explicit mutation gate}
                            {--role= : ultra_deep or archival}
                            {--request-id= : Base request identity}
                            {--lease-id= : Controller lease identity}
                            {--cancellation-run-id= : Required UUID owned by the calling PLOS job or session}
                            {--canary : Use the fixed synthetic content-free canary prompt}
                            {--max-tokens=128 : Maximum output tokens, no greater than 128}';

    protected $description = 'Run one explicit, serialized MHC-08 K3 inference lifecycle';

    public function handle(ModelHostControllerMhc08K3InferenceService $inference): int
    {
        if (! $this->option('execute')) {
            $this->error('MHC-08 K3 inference requires --execute.');

            return self::FAILURE;
        }

        $role = trim((string) $this->option('role'));
        $requestId = trim((string) $this->option('request-id'));
        $leaseId = trim((string) $this->option('lease-id'));
        $cancellationRunId = trim((string) $this->option('cancellation-run-id'));
        if ($role === '' || $requestId === '' || $leaseId === '' || $cancellationRunId === '' || ! $this->option('canary')) {
            $this->error('MHC-08 K3 requires --role, --request-id, --lease-id, --cancellation-run-id, and --canary.');

            return self::FAILURE;
        }

        try {
            $result = $inference->process($role, 'Reply with exactly MHC-08-OK.', [
                'max_tokens' => (string) $this->option('max-tokens'),
                'cancellation_run_id' => $cancellationRunId,
            ], $requestId, $leaseId);
            $receipt = $result['controller_receipt'] ?? null;
            if (! is_array($receipt)) {
                throw new RuntimeException('MHC-08 K3 returned no lifecycle receipt.');
            }
            if (trim((string) data_get($result, 'response.response', '')) !== 'MHC-08-OK') {
                throw new RuntimeException('MHC-08 K3 canary output was not exact.');
            }
            // Never print model output from this command; receipts are content-free.
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return ($receipt['result'] ?? null) === 'green' ? self::SUCCESS : self::FAILURE;
        } catch (\JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
