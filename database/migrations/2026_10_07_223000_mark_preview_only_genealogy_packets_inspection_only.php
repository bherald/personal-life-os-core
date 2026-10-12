<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REVIEW_TYPE = 'genealogy_review_packet';

    private const DECISION_SERVICE = 'App\\Services\\Genealogy\\GenealogyReviewPacketDecisionService';

    private const INSPECTION_REASON = 'preview_receipt_only';

    public function up(): void
    {
        if (! $this->requiredColumnsExist()) {
            return;
        }

        $type = DB::table('review_type_registry')
            ->where('name', self::REVIEW_TYPE)
            ->first(['id', 'service_class', 'approve_method', 'ui_schema']);

        if ($type === null
            || $type->service_class !== self::DECISION_SERVICE
            || ! in_array($type->approve_method, ['approve', 'markReviewed'], true)) {
            return;
        }

        $uiSchema = $this->decodeSchema($type->ui_schema);
        if ($uiSchema === null) {
            return;
        }

        if (($uiSchema['inspection_only'] ?? false) === true
            && ($uiSchema['inspection_reason'] ?? null) !== self::INSPECTION_REASON) {
            return;
        }

        if (($uiSchema['inspection_only'] ?? false) === true
            && ($uiSchema['inspection_reason'] ?? null) === self::INSPECTION_REASON) {
            return;
        }

        $uiSchema['inspection_only'] = true;
        $uiSchema['inspection_reason'] = self::INSPECTION_REASON;

        DB::table('review_type_registry')
            ->where('id', $type->id)
            ->update([
                'ui_schema' => json_encode($uiSchema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_type_registry') || ! Schema::hasColumn('review_type_registry', 'ui_schema')) {
            return;
        }

        $type = DB::table('review_type_registry')
            ->where('name', self::REVIEW_TYPE)
            ->first(['id', 'ui_schema']);

        if ($type === null) {
            return;
        }

        $uiSchema = $this->decodeSchema($type->ui_schema);
        if ($uiSchema === null
            || ($uiSchema['inspection_only'] ?? false) !== true
            || ($uiSchema['inspection_reason'] ?? null) !== self::INSPECTION_REASON) {
            return;
        }

        unset($uiSchema['inspection_only'], $uiSchema['inspection_reason']);

        DB::table('review_type_registry')
            ->where('id', $type->id)
            ->update([
                'ui_schema' => json_encode($uiSchema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        Cache::forget('review_type_registry');
    }

    private function requiredColumnsExist(): bool
    {
        return Schema::hasTable('review_type_registry')
            && Schema::hasColumn('review_type_registry', 'ui_schema')
            && Schema::hasColumn('review_type_registry', 'service_class')
            && Schema::hasColumn('review_type_registry', 'approve_method');
    }

    /** @return array<string, mixed> */
    private function decodeSchema(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value === [] || ! array_is_list($value) ? $value : null;
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (! is_string($value)) {
            return null;
        }

        $object = json_decode($value);
        if (! is_object($object)) {
            return null;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
};
