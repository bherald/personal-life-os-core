<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('genealogy_person_coverage')) {
            if (! Schema::hasColumn('genealogy_person_coverage', 'agent_last_worked_at')) {
                Schema::table('genealogy_person_coverage', function (Blueprint $table): void {
                    $table->timestamp('agent_last_worked_at')
                        ->nullable()
                        ->after('last_searched_at');
                });
            }

            if ($this->indexExists('genealogy_person_coverage', 'idx_agent_last_worked')) {
                Schema::table('genealogy_person_coverage', function (Blueprint $table): void {
                    $table->dropIndex('idx_agent_last_worked');
                });
            }

            if (! $this->indexExists('genealogy_person_coverage', 'idx_tree_agent_last_worked')) {
                Schema::table('genealogy_person_coverage', function (Blueprint $table): void {
                    $table->index(['tree_id', 'agent_last_worked_at'], 'idx_tree_agent_last_worked');
                });
            }

            DB::statement('
                UPDATE genealogy_person_coverage c
                LEFT JOIN (
                    SELECT tree_id, person_id, MAX(worked_at) AS last_worked_at
                    FROM (
                        SELECT tree_id, person_id, completed_at AS worked_at
                        FROM genealogy_research_queue
                        WHERE completed_at IS NOT NULL
                        UNION ALL
                        SELECT tree_id, person_id, completed_at AS worked_at
                        FROM genealogy_research_tasks
                        WHERE completed_at IS NOT NULL
                    ) worked
                    GROUP BY tree_id, person_id
                ) w ON w.tree_id = c.tree_id AND w.person_id = c.person_id
                SET c.agent_last_worked_at = w.last_worked_at
                WHERE w.last_worked_at IS NOT NULL
            ');
        }

        if (Schema::hasTable('scheduled_jobs')) {
            $this->mergeScheduledJobNotes('genealogy_agent_assess', [
                'runtime' => [
                    'empty_queue_refill_enabled' => true,
                    'empty_queue_refill_cooldown_hours' => 12,
                    'empty_queue_refill_limit' => 10,
                ],
                'training' => [
                    'agent_work_rotation_policy' => 'Use genealogy_person_coverage.agent_last_worked_at as the per-person FT4 work-spread flag. Normal cooldown prevents immediate repeats; empty-queue refill may use a smaller table-owned cooldown and batch size to keep work moving without collapsing back onto the same people.',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            $row = DB::table('scheduled_jobs')
                ->where('name', 'genealogy_agent_assess')
                ->first(['notes']);

            if ($row && is_string($row->notes ?? null)) {
                $notes = json_decode((string) $row->notes, true);
                if (is_array($notes)) {
                    unset($notes['runtime']['empty_queue_refill_enabled']);
                    unset($notes['runtime']['empty_queue_refill_cooldown_hours']);
                    unset($notes['runtime']['empty_queue_refill_limit']);
                    unset($notes['training']['agent_work_rotation_policy']);

                    if (($notes['runtime'] ?? null) === []) {
                        unset($notes['runtime']);
                    }
                    if (($notes['training'] ?? null) === []) {
                        unset($notes['training']);
                    }

                    DB::table('scheduled_jobs')
                        ->where('name', 'genealogy_agent_assess')
                        ->update([
                            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        if (Schema::hasTable('genealogy_person_coverage')
            && Schema::hasColumn('genealogy_person_coverage', 'agent_last_worked_at')) {
            Schema::table('genealogy_person_coverage', function (Blueprint $table): void {
                if ($this->indexExists('genealogy_person_coverage', 'idx_tree_agent_last_worked')) {
                    $table->dropIndex('idx_tree_agent_last_worked');
                }
                if ($this->indexExists('genealogy_person_coverage', 'idx_agent_last_worked')) {
                    $table->dropIndex('idx_agent_last_worked');
                }
                $table->dropColumn('agent_last_worked_at');
            });
        }
    }

    /**
     * @param  array<string,mixed>  $patch
     */
    private function mergeScheduledJobNotes(string $name, array $patch): void
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', $name)
            ->first(['notes']);

        $current = [];
        if ($row && is_string($row->notes ?? null) && trim((string) $row->notes) !== '') {
            $decoded = json_decode((string) $row->notes, true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }

        DB::table('scheduled_jobs')
            ->where('name', $name)
            ->update([
                'notes' => json_encode($this->recursiveMerge($current, $patch), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne('
            SELECT COUNT(*) AS count
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ', [DB::connection()->getDatabaseName(), $table, $index]);

        return (int) ($row->count ?? 0) > 0;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function recursiveMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $base[$key] = $value;

                continue;
            }

            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->recursiveMerge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
};
