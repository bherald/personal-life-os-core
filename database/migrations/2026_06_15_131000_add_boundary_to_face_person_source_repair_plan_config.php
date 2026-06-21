<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $config = $this->upsertConfigBoundary();
        $this->backfillPendingPackets($config);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $this->removeConfigBoundary();
        $this->removePendingPacketBoundary();
    }

    /** @return array<string,mixed>|null */
    private function upsertConfigBoundary(): ?array
    {
        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first();

        if ($row === null) {
            return null;
        }

        $config = json_decode((string) ($row->config_value ?? ''), true);
        if (! is_array($config)) {
            return null;
        }

        $config['sprint_boundary_label'] ??= 'FT4 face/photo provenance repair review';
        $config['packet_identity_label'] ??= 'FT4 face/photo provenance repair packet';
        $config['packet_identity_scope'] ??= 'face_photo_provenance_repair';

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        return $config;
    }

    private function removeConfigBoundary(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first();

        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config_value ?? ''), true);
        if (! is_array($config)) {
            return;
        }

        unset(
            $config['sprint_boundary_label'],
            $config['packet_identity_label'],
            $config['packet_identity_scope']
        );

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /** @param array<string,mixed>|null $config */
    private function backfillPendingPackets(?array $config): void
    {
        if ($config === null || ! Schema::hasTable('agent_review_queue')) {
            return;
        }

        $boundary = $this->text($config['sprint_boundary_label'] ?? null);
        $identityLabel = $this->text($config['packet_identity_label'] ?? null);
        $identityScope = $this->text($config['packet_identity_scope'] ?? null);
        if ($boundary === null || $identityLabel === null || $identityScope === null) {
            return;
        }

        $rows = DB::table('agent_review_queue')
            ->select(['id', 'details'])
            ->where('review_type', 'genealogy_review_packet')
            ->where('status', 'pending')
            ->where('title', 'like', '%Face/photo repair plan%')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details) || ! $this->isRepairPacket($details)) {
                continue;
            }

            $packet = is_array($details['packet'] ?? null) ? $details['packet'] : [];
            $metadata = is_array($packet['metadata'] ?? null) ? $packet['metadata'] : [];
            $filters = is_array($metadata['filters'] ?? null) ? $metadata['filters'] : [];
            $treeId = is_numeric($packet['tree_id'] ?? null) ? (int) $packet['tree_id'] : 4;
            $statusBucket = $this->text($filters['status'] ?? null) ?? 'all_actionable_statuses';

            $identity = [
                'label' => $identityLabel,
                'scope' => $identityScope,
                'tree_id' => $treeId,
                'status_bucket' => $statusBucket,
            ];

            $details['identity'] = is_array($details['identity'] ?? null) && $details['identity'] !== []
                ? $details['identity']
                : $identity;
            $details['sprint'] = is_array($details['sprint'] ?? null) ? $details['sprint'] : [];
            $details['sprint']['boundary_label'] ??= $boundary;

            $packet['identity'] = is_array($packet['identity'] ?? null) && $packet['identity'] !== []
                ? $packet['identity']
                : $identity;
            $packet['sprint'] = is_array($packet['sprint'] ?? null) ? $packet['sprint'] : [];
            $packet['sprint']['boundary_label'] ??= $boundary;
            $metadata['sprint_boundary_label'] ??= $boundary;
            $packet['metadata'] = $metadata;
            $details['packet'] = $packet;

            DB::table('agent_review_queue')
                ->where('id', $row->id)
                ->update([
                    'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    private function removePendingPacketBoundary(): void
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        $rows = DB::table('agent_review_queue')
            ->select(['id', 'details'])
            ->where('review_type', 'genealogy_review_packet')
            ->where('status', 'pending')
            ->where('title', 'like', '%Face/photo repair plan%')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details) || ! $this->isRepairPacket($details)) {
                continue;
            }

            unset($details['identity'], $details['sprint']['boundary_label']);
            if (($details['sprint'] ?? []) === []) {
                unset($details['sprint']);
            }

            if (is_array($details['packet'] ?? null)) {
                unset(
                    $details['packet']['identity'],
                    $details['packet']['sprint']['boundary_label'],
                    $details['packet']['metadata']['sprint_boundary_label']
                );
                if (($details['packet']['sprint'] ?? []) === []) {
                    unset($details['packet']['sprint']);
                }
            }

            DB::table('agent_review_queue')
                ->where('id', $row->id)
                ->update([
                    'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @param array<string,mixed> $details */
    private function isRepairPacket(array $details): bool
    {
        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : [];
        $metadata = is_array($packet['metadata'] ?? null) ? $packet['metadata'] : [];

        return ($metadata['origin'] ?? null) === 'ops:face-person-source-repair-plan';
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
};
