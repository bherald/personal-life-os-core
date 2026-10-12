<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONFIG_KEY = 'face_person_source_repair_plan';

    private const JOB_NAME = 'face_person_source_repair_packet_refill';

    private const MARKER = '2026_07_19_retire_incomplete_face_repair_review_packets';

    private const RETIREMENT_NOTE = '{"schema":"genealogy_photo_diagnostic_retirement.v1","reason":"candidate_comparison_or_media_missing","migration":"2026_07_19_retire_incomplete_face_repair_review_packets","canonical_genealogy_writes":false,"identity_writes":false}';

    public function up(): void
    {
        $this->disableInvalidPacketRefill();
        $this->disableScheduledRefill();
        $this->retireIncompletePackets();
    }

    public function down(): void
    {
        $this->restorePacketRefill();
        $this->restoreScheduledRefill();

        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        DB::table('agent_review_queue')
            ->where('review_type', 'genealogy_review_packet')
            ->where('finding_type', 'face_person_source_repair_plan')
            ->where('status', 'expired')
            ->where('reviewer_notes', self::RETIREMENT_NOTE)
            ->update([
                'status' => 'pending',
                'reviewed_at' => null,
                'reviewer_notes' => null,
                'updated_at' => now(),
            ]);
    }

    private function disableInvalidPacketRefill(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', self::CONFIG_KEY)
            ->first(['id', 'config_value']);
        if ($row === null) {
            return;
        }

        $config = json_decode((string) $row->config_value, true);
        if (! is_array($config) || ! is_array($config['refill_policy'] ?? null)) {
            return;
        }

        $extensions = is_array($config['_migration_extensions'] ?? null)
            ? $config['_migration_extensions']
            : [];
        if (isset($extensions[self::MARKER])) {
            return;
        }

        $extensions[self::MARKER] = [
            'previous_enabled' => $config['refill_policy']['enabled'] ?? null,
            'previous_disabled_reason' => $config['refill_policy']['disabled_reason'] ?? null,
        ];
        $config['_migration_extensions'] = $extensions;
        $config['refill_policy']['enabled'] = false;
        $config['refill_policy']['disabled_reason'] = self::MARKER;

        DB::table('system_configs')->where('id', $row->id)->update([
            'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function restorePacketRefill(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', self::CONFIG_KEY)
            ->first(['id', 'config_value']);
        if ($row === null) {
            return;
        }

        $config = json_decode((string) $row->config_value, true);
        $previous = is_array($config)
            ? ($config['_migration_extensions'][self::MARKER] ?? null)
            : null;
        if (! is_array($previous)
            || ($config['refill_policy']['disabled_reason'] ?? null) !== self::MARKER) {
            return;
        }

        $config['refill_policy']['enabled'] = $previous['previous_enabled'] ?? false;
        if (($previous['previous_disabled_reason'] ?? null) === null) {
            unset($config['refill_policy']['disabled_reason']);
        } else {
            $config['refill_policy']['disabled_reason'] = $previous['previous_disabled_reason'];
        }
        unset($config['_migration_extensions'][self::MARKER]);
        if ($config['_migration_extensions'] === []) {
            unset($config['_migration_extensions']);
        }

        DB::table('system_configs')->where('id', $row->id)->update([
            'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function disableScheduledRefill(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $columns = ['id', 'enabled', 'notes'];
        if (Schema::hasColumn('scheduled_jobs', 'next_run_at')) {
            $columns[] = 'next_run_at';
        }
        $job = DB::table('scheduled_jobs')->where('name', self::JOB_NAME)->first($columns);
        if ($job === null) {
            return;
        }

        $notes = json_decode((string) ($job->notes ?? ''), true);
        $notes = is_array($notes) ? $notes : ['previous_notes_text' => $job->notes];
        $extensions = is_array($notes['_migration_extensions'] ?? null)
            ? $notes['_migration_extensions']
            : [];
        if (isset($extensions[self::MARKER])) {
            return;
        }

        $extensions[self::MARKER] = [
            'previous_enabled' => (bool) $job->enabled,
            'previous_next_run_at' => property_exists($job, 'next_run_at') ? $job->next_run_at : null,
        ];
        $notes['_migration_extensions'] = $extensions;
        $notes['review_surface'] = 'retired_until_complete_two_sided_photo_evidence';

        $updates = [
            'enabled' => 0,
            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('scheduled_jobs', 'next_run_at')) {
            $updates['next_run_at'] = null;
        }

        DB::table('scheduled_jobs')->where('id', $job->id)->update($updates);
    }

    private function restoreScheduledRefill(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $job = DB::table('scheduled_jobs')->where('name', self::JOB_NAME)->first(['id', 'enabled', 'notes']);
        if ($job === null || (int) $job->enabled !== 0) {
            return;
        }

        $notes = json_decode((string) ($job->notes ?? ''), true);
        $previous = is_array($notes)
            ? ($notes['_migration_extensions'][self::MARKER] ?? null)
            : null;
        if (! is_array($previous)
            || ($notes['review_surface'] ?? null) !== 'retired_until_complete_two_sided_photo_evidence') {
            return;
        }

        unset($notes['_migration_extensions'][self::MARKER], $notes['review_surface']);
        if (($notes['_migration_extensions'] ?? null) === []) {
            unset($notes['_migration_extensions']);
        }
        $restoredNotes = array_key_exists('previous_notes_text', $notes)
            ? $notes['previous_notes_text']
            : json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $updates = [
            'enabled' => (bool) ($previous['previous_enabled'] ?? false),
            'notes' => $restoredNotes,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('scheduled_jobs', 'next_run_at')) {
            $updates['next_run_at'] = $previous['previous_next_run_at'] ?? null;
        }

        DB::table('scheduled_jobs')->where('id', $job->id)->update($updates);
    }

    private function retireIncompletePackets(): void
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        DB::table('agent_review_queue')
            ->where('review_type', 'genealogy_review_packet')
            ->where('finding_type', 'face_person_source_repair_plan')
            ->where('status', 'pending')
            ->orderBy('id')
            ->get(['id', 'details'])
            ->each(function (object $row): void {
                $details = json_decode((string) ($row->details ?? ''), true);
                if (! is_array($details) || $this->hasCompletePhotoComparisons($details)) {
                    return;
                }

                DB::table('agent_review_queue')->where('id', $row->id)->update([
                    'status' => 'expired',
                    'reviewed_at' => now(),
                    'reviewer_notes' => self::RETIREMENT_NOTE,
                    'updated_at' => now(),
                ]);
            });
    }

    /** @param array<string,mixed> $details */
    private function hasCompletePhotoComparisons(array $details): bool
    {
        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : [];
        $metadata = is_array($packet['metadata'] ?? null) ? $packet['metadata'] : [];
        $plans = is_array($metadata['plans'] ?? null) ? $metadata['plans'] : [];
        if ($plans === []) {
            return false;
        }

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                return false;
            }

            $evidence = is_array($plan['evidence'] ?? null) ? $plan['evidence'] : [];
            $selector = is_array($plan['selector'] ?? null) ? $plan['selector'] : [];
            $linkedName = $this->firstText($evidence, ['linked_canonical_name', 'person_name']);
            $comparisonName = $this->firstText($evidence, ['matched_export_name', 'matched_variant_name']);
            $mediaIds = is_array($selector['media_ids'] ?? null) ? $selector['media_ids'] : [];
            $hasMedia = array_filter($mediaIds, static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0) !== [];

            if ($linkedName === null
                || $comparisonName === null
                || strcasecmp($linkedName, $comparisonName) === 0
                || ! $hasMedia) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $values */
    private function firstText(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! is_scalar($values[$key] ?? null)) {
                continue;
            }

            $value = trim((string) $values[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
};
