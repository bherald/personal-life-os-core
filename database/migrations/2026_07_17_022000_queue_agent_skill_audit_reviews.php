<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'agent_skill_drift_audit';

    private const OLD_COMMAND = 'agents:skill-audit --format=json --compact --compare-latest --fail-on-drift --write-report';

    private const NEW_COMMAND = self::OLD_COMMAND.' --queue-review';

    private const NEW_DESCRIPTION = 'PLOS skill integrity drift audit with acknowledgement-only operator review packets.';

    private const MARKER = '2026_07_17_agent_skill_audit_review_queue';

    /** @return array<string,mixed> */
    private function managedNoteValues(): array
    {
        return [
            'summary' => 'Scans complete configured PLOS skill bundles, writes a content-free SHA-256 manifest, and creates or reuses an acknowledgement-only review packet when operator attention is required.',
            'policy_source' => 'system_configs.agents.skill_audit_runtime_policy',
            'runtime.queue_review' => true,
            'runtime.skillspector_timeout_source' => 'system_configs.agents.skill_audit_runtime_policy.skillspector.timeout_seconds',
            'safety.read_only' => false,
            'safety.review_queue_write_only' => true,
            'safety.queue_materialization_only' => true,
            'safety.skill_mutation_allowed' => false,
            'safety.approval_effect' => 'acknowledge_report_only_no_skill_write',
            'safety.rejection_effect' => 'dismiss_report_only_no_skill_write',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id', 'command', 'description', 'runtime_mode', 'notes']);
        if ($row === null || (string) $row->command !== self::OLD_COMMAND) {
            return;
        }

        $notes = json_decode((string) ($row->notes ?? ''), true);
        $notes = is_array($notes) ? $notes : [];
        $extensions = is_array($notes['_migration_extensions'] ?? null)
            ? $notes['_migration_extensions']
            : [];
        if (is_array($extensions[self::MARKER] ?? null)) {
            return;
        }

        $previousPaths = [];
        foreach ($this->managedNoteValues() as $path => $value) {
            [$present, $previous] = $this->readPath($notes, $path);
            $previousPaths[$path] = ['present' => $present, 'value' => $previous];
            $this->writePath($notes, $path, $value);
        }

        $notes['_migration_extensions'] = $extensions;
        $notes['_migration_extensions'][self::MARKER] = [
            'previous' => [
                'command' => (string) $row->command,
                'description' => $row->description,
                'runtime_mode' => $row->runtime_mode,
                'notes' => $previousPaths,
            ],
        ];

        DB::table('scheduled_jobs')
            ->where('id', $row->id)
            ->update([
                'command' => self::NEW_COMMAND,
                'description' => self::NEW_DESCRIPTION,
                'runtime_mode' => 'maintenance',
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id', 'command', 'description', 'runtime_mode', 'notes']);
        if ($row === null) {
            return;
        }

        $notes = json_decode((string) ($row->notes ?? ''), true);
        $notes = is_array($notes) ? $notes : [];
        $marker = $notes['_migration_extensions'][self::MARKER] ?? null;
        if (! is_array($marker)) {
            return;
        }

        $previous = is_array($marker['previous'] ?? null) ? $marker['previous'] : [];
        foreach ($this->managedNoteValues() as $path => $managedValue) {
            [$present, $current] = $this->readPath($notes, $path);
            if (! $present || $current !== $managedValue) {
                continue;
            }

            $prior = $previous['notes'][$path] ?? ['present' => false, 'value' => null];
            if (($prior['present'] ?? false) === true) {
                $this->writePath($notes, $path, $prior['value'] ?? null);
            } else {
                $this->removePath($notes, $path);
            }
        }

        unset($notes['_migration_extensions'][self::MARKER]);
        if (($notes['_migration_extensions'] ?? []) === []) {
            unset($notes['_migration_extensions']);
        }

        $updates = [
            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
        if ((string) $row->command === self::NEW_COMMAND) {
            $updates['command'] = $previous['command'] ?? self::OLD_COMMAND;
        }
        if ((string) $row->description === self::NEW_DESCRIPTION) {
            $updates['description'] = $previous['description'] ?? null;
        }
        if ((string) $row->runtime_mode === 'maintenance') {
            $updates['runtime_mode'] = $previous['runtime_mode'] ?? null;
        }

        DB::table('scheduled_jobs')->where('id', $row->id)->update($updates);
    }

    /** @return array{0:bool,1:mixed} */
    private function readPath(array $values, string $path): array
    {
        $cursor = $values;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return [false, null];
            }
            $cursor = $cursor[$segment];
        }

        return [true, $cursor];
    }

    private function writePath(array &$values, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor = &$values;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }
            if (! is_array($cursor[$segment] ?? null)) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }

    private function removePath(array &$values, string $path): void
    {
        $segments = explode('.', $path);
        $cursor = &$values;
        foreach ($segments as $index => $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return;
            }
            if ($index === count($segments) - 1) {
                unset($cursor[$segment]);

                return;
            }
            $cursor = &$cursor[$segment];
        }
    }
};
