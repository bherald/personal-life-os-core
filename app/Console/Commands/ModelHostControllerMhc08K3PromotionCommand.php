<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3PromotionService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerMhc08K3PromotionCommand extends Command
{
    protected $signature = 'model-host:mhc08-k3-promotion
                            {action=status : status, stage-state, rollback-state, promote, complete, or rollback}
                            {--execute : Required for any state-changing action}
                            {--canary-receipt= : Tracked content-free canary receipt}
                            {--cancellation-receipt= : Tracked content-free cross-process cancellation receipt}
                            {--host-receipt= : Tracked content-free post-canary host-clean receipt}
                            {--final-smoke-receipt= : Tracked content-free real API/worker acceptance receipt}';

    protected $description = 'Guarded reversible MHC-08 K3 State staging and explicit-only promotion';

    public function handle(ModelHostControllerMhc08K3PromotionService $promotion): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'stage-state', 'rollback-state', 'promote', 'complete', 'rollback'], true)) {
            $this->line($this->json([
                'schema' => ModelHostControllerMhc08K3PromotionService::RECEIPT_SCHEMA,
                'result' => 'rejected',
                'reason' => 'invalid_action',
            ]));

            return self::FAILURE;
        }
        if ($action !== 'status' && ! $this->option('execute')) {
            $this->line($this->json([
                'schema' => ModelHostControllerMhc08K3PromotionService::RECEIPT_SCHEMA,
                'result' => 'rejected',
                'reason' => 'execute_required',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]));

            return self::FAILURE;
        }

        try {
            $receipt = match ($action) {
                'status' => $promotion->status(),
                'stage-state' => $promotion->stageState(),
                'rollback-state' => $promotion->rollbackState(),
                'promote' => $promotion->promote(
                    $this->option('canary-receipt') ?: null,
                    $this->option('cancellation-receipt') ?: null,
                    $this->option('host-receipt') ?: null,
                ),
                'complete' => $promotion->complete($this->option('final-smoke-receipt') ?: null),
                'rollback' => $promotion->rollback(),
            };
        } catch (Throwable) {
            $this->line($this->json([
                'schema' => ModelHostControllerMhc08K3PromotionService::RECEIPT_SCHEMA,
                'result' => 'rejected',
                'cleanup_result' => 'unproven',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]));

            return self::FAILURE;
        }

        $this->line($this->json($receipt));

        return ($receipt['result'] ?? null) === 'rejected' ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string,mixed> $receipt */
    private function json(array $receipt): string
    {
        return json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
