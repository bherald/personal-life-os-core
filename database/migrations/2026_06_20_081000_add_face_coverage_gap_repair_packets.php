<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COVERAGE_FACE_STATUS = 'coverage_visible_unnamed_unlinked_face';
    private const COVERAGE_PHOTO_STATUS = 'coverage_unlinked_photo_media';

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $this->updateFaceRepairPolicy();
            $this->updateGenealogyManagerPolicy();
            $this->updateCapacityManagerPolicy();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $this->restoreFaceRepairPolicy();
        $this->restoreManagerPolicy('genealogy', 'supervisor_manager', 'face_person_source_repair_packet_refill');
        $this->restoreManagerPolicy('ops', 'capacity_utilization_manager', 'face_repair_packet_refill_runway');
    }

    private function updateFaceRepairPolicy(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first(['id', 'config_value']);

        $config = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($config)) {
            return;
        }

        $coverageStatuses = [self::COVERAGE_FACE_STATUS, self::COVERAGE_PHOTO_STATUS];
        $config['actionable_status_order'] = $this->mergeList(
            is_array($config['actionable_status_order'] ?? null) ? $config['actionable_status_order'] : [],
            $coverageStatuses
        );

        $actions = is_array($config['status_actions'] ?? null) ? $config['status_actions'] : [];
        $actions[self::COVERAGE_FACE_STATUS] = [
            'action' => 'operator_review_visible_unnamed_unlinked_face_detection',
            'manual_next_step' => 'Review the unnamed active face detection and decide whether it needs a person match, no-match marker, source search, or quarantine before any identity write.',
        ];
        $actions[self::COVERAGE_PHOTO_STATUS] = [
            'action' => 'operator_review_unlinked_photo_media_with_faces',
            'manual_next_step' => 'Review the unlinked photo media and decide whether it needs source/person linkage, no-match handling, or folder/source normalization before any person-media write.',
        ];
        $config['status_actions'] = $actions;

        $refill = is_array($config['refill_policy'] ?? null) ? $config['refill_policy'] : [];
        $refill['status_order'] = $this->mergeList(
            is_array($refill['status_order'] ?? null) ? $refill['status_order'] : [],
            $coverageStatuses
        );
        $refill['max_packets_per_run'] = max(4, (int) ($refill['max_packets_per_run'] ?? 0));
        $refill['max_pending_total'] = max(36, (int) ($refill['max_pending_total'] ?? 0));
        $refill['per_status_pending_target'] = max(4, (int) ($refill['per_status_pending_target'] ?? 0));
        $config['refill_policy'] = $refill;

        $config['feedback'] = array_replace(
            is_array($config['feedback'] ?? null) ? $config['feedback'] : [],
            [
                'coverage_gap_packets' => 'Coverage-gap statuses create only preview genealogy_review_packet rows for unnamed/unlinked face detections and unlinked photo media; they do not write identities, clusters, metadata, person-media links, or people.',
            ]
        );

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function updateGenealogyManagerPolicy(): void
    {
        $this->updateManagerPolicy(
            'genealogy',
            'supervisor_manager',
            'face_person_source_repair_packet_refill',
            'manager_notes',
            'Genea manager wakes the face repair packet refill lane when table-owned packet runway is low and face/photo coverage gaps remain.'
        );
    }

    private function updateCapacityManagerPolicy(): void
    {
        $this->updateManagerPolicy(
            'ops',
            'capacity_utilization_manager',
            'face_repair_packet_refill_runway',
            'feedback',
            'Capacity manager can spend idle private LLM/Codex runway on review-only face/photo coverage-gap packet refill when packet runway is low.'
        );
    }

    private function updateManagerPolicy(string $section, string $key, string $laneName, string $noteKey, string $note): void
    {
        $row = DB::table('system_configs')
            ->where('section', $section)
            ->where('config_key', $key)
            ->first(['id', 'config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        foreach ($policy['lanes'] as &$lane) {
            if (! is_array($lane) || ($lane['name'] ?? null) !== $laneName) {
                continue;
            }

            $triggers = is_array($lane['triggers'] ?? null) ? $lane['triggers'] : [];
            $triggers['face_coverage_gap_units_min'] = 1;
            $lane['triggers'] = $triggers;
        }
        unset($lane);

        $policy[$noteKey] = array_replace(
            is_array($policy[$noteKey] ?? null) ? $policy[$noteKey] : [],
            ['face_coverage_gap_packet_lane' => $note]
        );

        if ($section === 'ops' && is_array($policy['workload']['signals'] ?? null)) {
            $policy['workload']['signals'] = $this->mergeList(
                $policy['workload']['signals'],
                ['face_coverage_gap_units']
            );
        }

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function restoreFaceRepairPolicy(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first(['id', 'config_value']);

        $config = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($config)) {
            return;
        }

        $remove = [self::COVERAGE_FACE_STATUS, self::COVERAGE_PHOTO_STATUS];
        if (is_array($config['actionable_status_order'] ?? null)) {
            $config['actionable_status_order'] = array_values(array_filter(
                $config['actionable_status_order'],
                static fn (mixed $status): bool => is_string($status) && ! in_array($status, $remove, true)
            ));
        }
        if (is_array($config['status_actions'] ?? null)) {
            foreach ($remove as $status) {
                unset($config['status_actions'][$status]);
            }
        }
        if (is_array($config['refill_policy']['status_order'] ?? null)) {
            $config['refill_policy']['status_order'] = array_values(array_filter(
                $config['refill_policy']['status_order'],
                static fn (mixed $status): bool => is_string($status) && ! in_array($status, $remove, true)
            ));
        }
        if (is_array($config['feedback'] ?? null)) {
            unset($config['feedback']['coverage_gap_packets']);
        }

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function restoreManagerPolicy(string $section, string $key, string $laneName): void
    {
        $row = DB::table('system_configs')
            ->where('section', $section)
            ->where('config_key', $key)
            ->first(['id', 'config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        foreach ($policy['lanes'] as &$lane) {
            if (! is_array($lane) || ($lane['name'] ?? null) !== $laneName) {
                continue;
            }

            if (is_array($lane['triggers'] ?? null)) {
                unset($lane['triggers']['face_coverage_gap_units_min']);
            }
        }
        unset($lane);

        if (is_array($policy['manager_notes'] ?? null)) {
            unset($policy['manager_notes']['face_coverage_gap_packet_lane']);
        }
        if (is_array($policy['feedback'] ?? null)) {
            unset($policy['feedback']['face_coverage_gap_packet_lane']);
        }
        if (is_array($policy['workload']['signals'] ?? null)) {
            $policy['workload']['signals'] = array_values(array_filter(
                $policy['workload']['signals'],
                static fn (mixed $signal): bool => $signal !== 'face_coverage_gap_units'
            ));
        }

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /** @param list<mixed> $existing */
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
