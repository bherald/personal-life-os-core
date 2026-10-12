<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerResourcesCommand extends Command
{
    protected $signature = 'model-host:resources
        {profile? : Exact table-owned resource profile to assess}
        {--snapshot= : Local JSON telemetry snapshot; advisory input, never activation authority}
        {--project-lifecycle= : Emit a lifecycle manifest with exact table-owned capacity bindings; no installation}
        {--json : Emit JSON}';

    protected $description = 'Validate table-owned resource profiles or assess a supplied snapshot without host actions';

    public function handle(ModelHostControllerResourcePolicyService $policies, ModelHostControllerPolicyService $controls): int
    {
        try {
            $profile = $this->argument('profile');
            $path = $this->option('snapshot');
            $projection = $this->option('project-lifecycle');
            if ($projection !== null) {
                if ($profile !== null || $path !== null || ! is_file($projection) || ! is_readable($projection)) {
                    throw new \RuntimeException('Projection requires only a readable lifecycle manifest.');
                }
                $manifest = json_decode(file_get_contents($projection), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($manifest)) {
                    throw new \RuntimeException('Invalid lifecycle manifest.');
                }
                $payload = $policies->projectLifecycle($controls->projectLifecycleControls($manifest));
                // Windows validates these as JSON maps, including when empty.
                foreach ($payload['targets'] as &$target) {
                    foreach (['control_roots', 'required_process_arguments'] as $field) {
                        $target[$field] = (object) $target[$field];
                    }
                }
                unset($target);
                $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if (($profile === null) !== ($path === null)) {
                $this->error('Provide both a profile and --snapshot, or neither to validate policy.');

                return self::FAILURE;
            }
            if ($profile === null) {
                $policy = $policies->required();
                $payload = [
                    'result' => 'policy_valid', 'policy_revision' => $policy['revision'],
                    'enabled' => $policy['enabled'], 'profiles' => array_keys($policy['profiles']),
                    'temperature_mode' => $policy['temperature_mode'], 'mutation_available' => false,
                ];
            } else {
                if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                    throw new \RuntimeException('Snapshot unavailable.');
                }
                $snapshot = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($snapshot)) {
                    throw new \RuntimeException('Snapshot invalid.');
                }
                $payload = $policies->assess($profile, $snapshot, (int) floor(microtime(true) * 1000));
                $payload['source'] = 'operator_supplied_snapshot';
                $payload['live_host_verified'] = false;
            }
        } catch (Throwable) {
            // Do not expose SQL, connection data or raw snapshot content in CLI errors.
            $this->error('Resource assessment unavailable: check the table policy and snapshot format. No host action was taken.');

            return self::FAILURE;
        }

        $this->line($this->option('json')
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : $payload['result'].'; temperature observation only; no host action.');

        return self::SUCCESS;
    }
}
