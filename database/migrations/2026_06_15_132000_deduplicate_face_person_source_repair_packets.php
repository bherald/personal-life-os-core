<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        $seen = [];
        $rows = DB::table('agent_review_queue')
            ->select(['id', 'details', 'reviewer_notes'])
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

            $packetKey = $this->text($details['packet_key'] ?? null)
                ?? $this->text($details['packet']['packet_key'] ?? null);
            if ($packetKey === null) {
                continue;
            }

            if (! isset($seen[$packetKey])) {
                $seen[$packetKey] = (int) $row->id;

                continue;
            }

            $note = trim((string) ($row->reviewer_notes ?? ''));
            $line = sprintf(
                'Auto-ignored duplicate face/photo repair packet on 2026-06-15; kept pending review row #%d for packet_key %s.',
                $seen[$packetKey],
                $packetKey
            );

            DB::table('agent_review_queue')
                ->where('id', $row->id)
                ->update([
                    'status' => 'ignored',
                    'reviewer_notes' => $note === '' ? $line : $note."\n".$line,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Duplicate review packets should not be resurrected automatically.
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
