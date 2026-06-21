<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS = 'face_linked_display_mismatch';

    public function up(): void
    {
        $this->updateRefillStatusOrder(include: true);
    }

    public function down(): void
    {
        $this->updateRefillStatusOrder(include: false);
    }

    private function updateRefillStatusOrder(bool $include): void
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

        $statusOrder = is_array($config['refill_policy']['status_order'] ?? null)
            ? array_values(array_filter($config['refill_policy']['status_order'], 'is_string'))
            : [];

        if ($include) {
            if (! in_array(self::STATUS, $statusOrder, true)
                && in_array(self::STATUS, $config['actionable_status_order'] ?? [], true)
                && array_key_exists(self::STATUS, $config['status_actions'] ?? [])) {
                $statusOrder = $this->insertAfter($statusOrder, 'face_export_person_conflict', self::STATUS);
            }
        } else {
            $statusOrder = array_values(array_filter(
                $statusOrder,
                static fn (string $status): bool => $status !== self::STATUS
            ));
        }

        $config['refill_policy']['status_order'] = $statusOrder;
        $config['feedback'] = is_array($config['feedback'] ?? null) ? $config['feedback'] : [];
        if ($include) {
            $config['feedback']['display_mismatch_packets'] =
                'Display-mismatch rows create only preview genealogy_review_packet rows for operator review; no face identity, cluster, metadata, person-media, or person rows are changed.';
        } else {
            unset($config['feedback']['display_mismatch_packets']);
        }

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function insertAfter(array $values, string $after, string $value): array
    {
        $result = [];
        $inserted = false;

        foreach ($values as $item) {
            $result[] = $item;
            if ($item === $after) {
                $result[] = $value;
                $inserted = true;
            }
        }

        if (! $inserted) {
            $result[] = $value;
        }

        return array_values(array_unique($result));
    }
};
