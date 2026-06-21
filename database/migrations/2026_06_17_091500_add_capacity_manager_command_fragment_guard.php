<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return list<string> */
    private function blockedFragments(): array
    {
        return [
            '--unsafe',
            '--canonical-write',
            '--face-identity-automation',
            '--relationship-write',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        $policy['blocked_command_fragments'] = $this->mergeList(
            is_array($policy['blocked_command_fragments'] ?? null) ? $policy['blocked_command_fragments'] : [],
            $this->blockedFragments()
        );
        $policy['feedback'] = array_replace(
            is_array($policy['feedback'] ?? null) ? $policy['feedback'] : [],
            [
                'command_fragment_policy' => 'Capacity manager blocks table-listed command fragments before waking a scheduled lane.',
            ]
        );

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        if (is_array($policy['blocked_command_fragments'] ?? null)) {
            $blockedFragments = $this->blockedFragments();
            $policy['blocked_command_fragments'] = array_values(array_filter(
                $policy['blocked_command_fragments'],
                static fn (mixed $fragment): bool => is_string($fragment)
                    && ! in_array($fragment, $blockedFragments, true)
            ));
        }
        if (is_array($policy['feedback'] ?? null)) {
            unset($policy['feedback']['command_fragment_policy']);
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<mixed>  $existing
     * @param  list<string>  $added
     * @return list<string>
     */
    private function mergeList(array $existing, array $added): array
    {
        $items = [];
        foreach (array_merge($existing, $added) as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }
            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }
};
