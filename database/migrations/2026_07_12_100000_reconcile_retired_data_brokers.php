<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CLUSTRMAPS_MARKER = '[PLOS-BROKER-RECONCILE-2026-07-12] Retired: maintained BADBOOL removed ClustrMaps as dead on 2026-06-28; source https://github.com/yaelwrites/Big-Ass-Data-Broker-Opt-Out-List (CC-BY-NC-SA).';

    private const CUBIB_MARKER = '[PLOS-BROKER-RECONCILE-2026-07-12] Retired: Cubib was superseded by Xlek; research sources https://www.optery.com/xlek-com-how-to-opt-out-of-xlek-com-step-by-step-instructions/ and https://www.atlas.net/opt-out-guides/xlek.';

    private const XLEK_NOTES = '[PLOS-BROKER-RECONCILE-2026-07-12] Added as the active successor to Cubib. Opt-out endpoint https://xlek.com/optout returned HTTP 403 from an automated probe, consistent with browser/Cloudflare handling. Research sources: https://www.optery.com/xlek-com-how-to-opt-out-of-xlek-com-step-by-step-instructions/ and https://www.atlas.net/opt-out-guides/xlek.';

    public function up(): void
    {
        if (! Schema::hasTable('data_brokers')) {
            return;
        }

        $this->retire('clustrmaps.com', self::CLUSTRMAPS_MARKER);
        $this->retire('cubib.com', self::CUBIB_MARKER);

        DB::table('data_brokers')->insertOrIgnore([
            'name' => 'Xlek',
            'domain' => 'xlek.com',
            'category' => 'people_search',
            'removal_method' => 'web_form',
            'removal_url' => 'https://xlek.com/optout',
            'automation_tier' => 3,
            'requires_captcha' => 1,
            'uses_javascript' => 1,
            'discovered_by' => 'manual',
            'discovery_notes' => self::XLEK_NOTES,
            'is_active' => 1,
            'health_status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('data_brokers')) {
            return;
        }

        $this->restore('clustrmaps.com', self::CLUSTRMAPS_MARKER);
        $this->restore('cubib.com', self::CUBIB_MARKER);

        $xlek = DB::table('data_brokers')
            ->where('domain', 'xlek.com')
            ->where('discovery_notes', self::XLEK_NOTES)
            ->first(['id']);
        if ($xlek === null) {
            return;
        }

        $hasRequests = Schema::hasTable('removal_requests')
            && DB::table('removal_requests')->where('broker_id', $xlek->id)->exists();
        if (! $hasRequests) {
            DB::table('data_brokers')->where('id', $xlek->id)->delete();
        }
    }

    private function retire(string $domain, string $marker): void
    {
        $broker = DB::table('data_brokers')->where('domain', $domain)->first(['id', 'removal_notes']);
        if ($broker === null) {
            return;
        }

        $notes = trim((string) $broker->removal_notes);
        if (! str_contains($notes, $marker)) {
            $notes = $notes === '' ? $marker : $notes."\n".$marker;
        }

        DB::table('data_brokers')->where('id', $broker->id)->update([
            'is_active' => 0,
            'health_status' => 'retired',
            'removal_notes' => $notes,
            'updated_at' => now(),
        ]);
    }

    private function restore(string $domain, string $marker): void
    {
        $broker = DB::table('data_brokers')->where('domain', $domain)->first(['id', 'removal_notes']);
        if ($broker === null || ! str_contains((string) $broker->removal_notes, $marker)) {
            return;
        }

        $notes = trim(str_replace($marker, '', (string) $broker->removal_notes));
        DB::table('data_brokers')->where('id', $broker->id)->update([
            'is_active' => 1,
            'health_status' => 'broken',
            'removal_notes' => $notes === '' ? null : $notes,
            'updated_at' => now(),
        ]);
    }
};
