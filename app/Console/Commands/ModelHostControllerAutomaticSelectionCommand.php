<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerAutomaticSelectionService;
use Illuminate\Console\Command;
use RuntimeException;

class ModelHostControllerAutomaticSelectionCommand extends Command
{
    protected $signature = 'model-host:auto-select
                            {--role=* : Exact admitted PLOS role(s)}
                            {--request-id=* : Base request identity corresponding to each role}
                            {--lease-id=* : Controller lease identity corresponding to each role}
                            {--inspect-custody : Read durable custody without controller calls or mutation}
                            {--reconcile-custody : Verify recorded cleanup; --execute settles only the durable PLOS batch slot}
                            {--corrective-original-request-id= : Original closed request for separately verified corrective cleanup}
                            {--corrective-lease-id= : Exact original lease}
                            {--corrective-release-request-id= : Distinct persisted corrective release identity}
                            {--custody-digest= : Exact digest from the read-only reconciliation result}
                            {--execute : Required mutation gate}';

    protected $description = 'Run the inactive-by-default guarded MHC-06 automatic serial selection boundary';

    public function handle(ModelHostControllerAutomaticSelectionService $service): int
    {
        $corrective = ['original_request_id' => $this->option('corrective-original-request-id'),
            'lease_id' => $this->option('corrective-lease-id'),
            'release_request_id' => $this->option('corrective-release-request-id')];
        $hasCorrective = count(array_filter($corrective, static fn ($value) => $value !== null)) > 0;
        if ($hasCorrective && (! $this->option('reconcile-custody') || in_array(null, $corrective, true))) {
            $this->error('Corrective cleanup requires reconciliation and all three exact identities.');

            return self::FAILURE;
        }
        if ($this->option('reconcile-custody')) {
            if ($this->option('inspect-custody') || $this->option('role') !== []
                || $this->option('request-id') !== [] || $this->option('lease-id') !== []) {
                $this->error('Custody reconciliation cannot be combined with selection or inspection.');

                return self::FAILURE;
            }
            $receipt = $hasCorrective
                ? $service->reconcileCustody((bool) $this->option('execute'), $this->option('custody-digest'), $corrective)
                : $service->reconcileCustody((bool) $this->option('execute'), $this->option('custody-digest'));
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return in_array($receipt['result'], ['ready', 'reconciled'], true) ? self::SUCCESS : self::FAILURE;
        }
        if ($this->option('custody-digest') !== null) {
            $this->error('A custody digest is valid only for reconciliation.');

            return self::FAILURE;
        }
        if ($this->option('inspect-custody')) {
            $this->line(json_encode($service->inspectCustody(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if (! $this->option('execute')) {
            $this->error('MHC-06 automatic selection requires --execute.');

            return self::FAILURE;
        }

        $roles = array_values(array_filter(
            array_map(static fn (mixed $role): string => trim((string) $role), (array) $this->option('role')),
            static fn (string $role): bool => $role !== ''
        ));
        if ($roles === []) {
            $this->error('MHC-06 automatic selection requires at least one --role.');

            return self::FAILURE;
        }
        $requestIds = array_values(array_map('strval', (array) $this->option('request-id')));
        $leaseIds = array_values(array_map('strval', (array) $this->option('lease-id')));
        if (count($roles) !== count($requestIds) || count($roles) !== count($leaseIds)) {
            $this->error('MHC-06 requires one --request-id and --lease-id for each --role.');

            return self::FAILURE;
        }

        try {
            $requests = array_map(static fn (string $role, int $index): array => [
                'role' => $role,
                'request_id' => $requestIds[$index],
                'lease_id' => $leaseIds[$index],
            ], $roles, array_keys($roles));
            $receipt = $service->run($requests);
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return ($receipt['result'] ?? null) === 'green' || ($receipt['result'] ?? null) === 'inactive'
                ? self::SUCCESS
                : self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
