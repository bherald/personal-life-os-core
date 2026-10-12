<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROPERTY = ['type' => 'string', 'format' => 'uuid'];

    private const TOOLS = [
        'file_exact_capture_date_plan' => 'planExactCaptureDateCorrection',
        'file_exact_capture_date_write' => 'writeExactCaptureDateCorrection',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::TOOLS as $name => $method) {
                $row = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if (! $row || $row->service_class !== 'App\\Services\\Files\\ScanDateEstimateReviewDecisionService'
                    || $row->method !== $method) {
                    throw new RuntimeException('Exact capture-date CLI registration changed.');
                }
                $parameters = json_decode((string) $row->parameters, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($parameters['properties'] ?? null) || ($parameters['additionalProperties'] ?? null) !== false) {
                    throw new RuntimeException('Exact capture-date CLI parameter schema is malformed.');
                }
                $existing = $parameters['properties']['restoreFromReceiptId'] ?? null;
                if ($existing !== null && $existing !== self::PROPERTY) {
                    throw new RuntimeException('Exact capture-date restoration selector conflicts with operator configuration.');
                }
                $parameters['properties']['restoreFromReceiptId'] = self::PROPERTY;
                DB::table('agent_tool_registry')->where('id', $row->id)->update([
                    'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        foreach (DB::table('review_desk_receipts')->where('action', 'write_exact_capture_date')->get() as $receipt) {
            $payload = json_decode((string) $receipt->payload, true);
            if (($payload['request']['restore_from_receipt_id'] ?? null) !== null) {
                throw new RuntimeException('Retain exact date restoration receipt history and selector.');
            }
        }
        DB::transaction(function (): void {
            foreach (self::TOOLS as $name => $method) {
                $row = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if (! $row || $row->service_class !== 'App\\Services\\Files\\ScanDateEstimateReviewDecisionService'
                    || $row->method !== $method) {
                    throw new RuntimeException('Exact capture-date CLI registration changed.');
                }
                $parameters = json_decode((string) $row->parameters, true, flags: JSON_THROW_ON_ERROR);
                if (($parameters['properties']['restoreFromReceiptId'] ?? null) !== self::PROPERTY) {
                    throw new RuntimeException('Exact capture-date restoration selector was customized.');
                }
                unset($parameters['properties']['restoreFromReceiptId']);
                DB::table('agent_tool_registry')->where('id', $row->id)->update([
                    'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
        });
    }
};
