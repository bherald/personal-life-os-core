<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<int,string> */
    private function statusOrder(): array
    {
        return [
            'face_export_person_conflict',
            'face_trusted_export_no_source',
            'face_untrusted_export_review',
            'person_media_unbacked_confirmed',
            'person_media_unbacked_unconfirmed',
            'person_media_source_backed_unconfirmed',
        ];
    }

    public function up(): void
    {
        $this->updateStatusOrder($this->statusOrder());
    }

    public function down(): void
    {
        $this->updateStatusOrder([
            'face_export_person_conflict',
            'face_linked_display_mismatch',
            'face_trusted_export_no_source',
            'face_untrusted_export_review',
            'person_media_unbacked_confirmed',
            'person_media_unbacked_unconfirmed',
            'person_media_source_backed_unconfirmed',
        ]);
    }

    /** @param array<int,string> $statusOrder */
    private function updateStatusOrder(array $statusOrder): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first();

        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config_value ?? ''), true);
        if (! is_array($config) || ! is_array($config['refill_policy'] ?? null)) {
            return;
        }

        $config['refill_policy']['status_order'] = $statusOrder;

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
