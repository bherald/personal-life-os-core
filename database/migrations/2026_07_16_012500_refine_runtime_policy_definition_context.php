<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFINITION_REGEXES = [
        '/^\s*(?:(?:public|protected|private)\s+)?(?:const\s+)?(?:\??(?:int|float|string|array|bool|mixed)\s+)?\$?(?=[A-Za-z_])[A-Za-z0-9_]*(?:Limit|Timeout|Retries?|Retry|Stale|Cooldown|Threshold|Capacity|Concurrency|Concurrent|Batch|Max_?Parallel|Workers?|Slots|Backlog|Model_?Role|Gpu_?Target|Host_?Affinity)[A-Za-z0-9_]*\s*=\s*(?:-?\d[\d_]*(?:\.\d+)?|true|false|null|[\'\"][^\'\"]*[\'\"])/i',
        '/^\s*[\'\"][a-z0-9_.-]*(?:limit|timeout|retry|stale|cooldown|threshold|capacity|concurren|batch|max_parallel|worker|slots|backlog|model_role|gpu_target|host_affinity)[a-z0-9_.-]*[\'\"]\s*=>\s*(?:-?\d[\d_]*(?:\.\d+)?|true|false|null|[\'\"][^\'\"]*[\'\"])/i',
        '/\{--[a-z0-9_-]*(?:limit|timeout|retry|stale|cooldown|threshold|capacity|concurren|batch|worker|slots|backlog)[a-z0-9_-]*=(?:-?\d[\d_]*(?:\.\d+)?|[\'\"][^\'\"]*[\'\"])/i',
        '/\b(?:config|env)\s*\(\s*[\'\"][^\'\"]*(?:limit|timeout|retry|stale|cooldown|threshold|capacity|concurren|batch|worker|slots|backlog|model_role|gpu_target|host_affinity)[^\'\"]*[\'\"]\s*,\s*(?:-?\d[\d_]*(?:\.\d+)?|true|false|null|[\'\"][^\'\"]*[\'\"])/i',
        '/\b[A-Za-z_][A-Za-z0-9_]*(?:Limit|Timeout|Retries?|Retry|Stale|Cooldown|Threshold|Capacity|Concurrency|Concurrent|Batch|MaxParallel|Workers?|Slots|Backlog|ModelRole|GpuTarget|HostAffinity)[A-Za-z0-9_]*\s*:\s*(?:-?\d[\d_]*(?:\.\d+)?|true|false|null|[\'\"][^\'\"]*[\'\"])/i',
        '/\b(?:set|with|configure)[A-Za-z0-9_]*(?:Limit|Timeout|Retries?|Retry|Stale|Cooldown|Threshold|Capacity|Concurrency|Concurrent|Batch|Workers?|Slots|Backlog|ModelRole|GpuTarget|HostAffinity)[A-Za-z0-9_]*\s*\(\s*(?:-?\d[\d_]*(?:\.\d+)?|true|false|null|[\'\"][^\'\"]*[\'\"])/i',
        '/\$?(?=[A-Za-z_])[A-Za-z0-9_]*(?:limit|timeout|retry|stale|cooldown|threshold|capacity|concurren|batch|max_?parallel|worker|slots|backlog|model_?role|gpu_?target|host_?affinity)[A-Za-z0-9_]*\s*(?:>=|<=|>|<|===|==|!==|!=)\s*(?:0?\.\d+|[2-9][\d_]*|1[\d_]+)/i',
        '/\b(?:min|max)\s*\([^)]*(?:(?:limit|timeout|retry|stale|cooldown|threshold|capacity|concurren|batch|max_?parallel|worker|slots|backlog)[^)]*(?:0?\.\d+|[2-9][\d_]*|1[\d_]+)|(?:0?\.\d+|[2-9][\d_]*|1[\d_]+)[^)]*(?:limit|timeout|retry|stale|cooldown|threshold|capacity|concurren|batch|max_?parallel|worker|slots|backlog))/i',
    ];

    public function up(): void
    {
        $this->updatePolicy(true);
    }

    public function down(): void
    {
        $this->updatePolicy(false);
    }

    private function updatePolicy(bool $add): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $current = $this->stringList($policy['classifier']['line_policy_definition_regexes'] ?? []);
        $policy['classifier']['line_policy_definition_regexes'] = $add
            ? array_values(array_unique([...$current, ...self::DEFINITION_REGEXES]))
            : array_values(array_diff($current, self::DEFINITION_REGEXES));

        if ($add) {
            $policy['classification_policy']['definition_context'] =
                'A lexical rule match is a candidate only in a table-configured literal assignment, option default, fallback, named argument, or setter definition context; other uses remain visible as runtime_value_reference.';
            $policy['classification_policy']['blanket_suppression'] =
                'Not allowed: refine structural contexts or use an exact stable fingerprint with an explicit reason.';
        } elseif (is_array($policy['classification_policy'] ?? null)) {
            unset(
                $policy['classification_policy']['definition_context'],
                $policy['classification_policy']['blanket_suppression']
            );
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        try {
            Cache::forget('syscfg:ops.runtime_policy_leak_audit');
        } catch (Throwable) {
            // The deploy cache rebuild follows migrations; policy persistence is authoritative.
        }
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }
};
