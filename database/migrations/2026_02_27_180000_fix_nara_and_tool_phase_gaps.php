<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // ═══════════════════════════════════════════════════════════════
        // PART 1: Register nara_search in agent_tool_registry
        // Original migration (2026_02_22_120000) used wrong column names
        // (tool_name/service/is_enabled vs actual name/service_class/enabled)
        // so the INSERT silently failed. Fix migration (2026_02_24_151000)
        // tried UPDATE on non-existent row — also no-op.
        // ═══════════════════════════════════════════════════════════════

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, proposed_by, notes, created_at, updated_at)
            VALUES
                ('nara_search', 'App\\\\Services\\\\Genealogy\\\\GenealogySourceService', 'searchNARA',
                 'Search the US National Archives catalog (37M+ records). Covers military, census, immigration, naturalization, court records, genealogy, land patents, presidential documents, photographs, maps. Primary source government documents with highest authority. Requires API key.',
                 ?,
                 '[]', 1, 'read', 'genealogy', 'manual', 'system',
                 'NARA v2 API. Rate limit: 10K calls/month. Key stored in genealogy_research_providers.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                enabled = 1,
                notes = VALUES(notes),
                updated_at = NOW()
        ", [
            json_encode([
                'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query (person name, record type, location)'],
                'options' => ['type' => 'array', 'default' => [], 'description' => 'Options: record_type (marriage, birth, death, military, immigration), limit, page'],
            ]),
        ]);

        // ═══════════════════════════════════════════════════════════════
        // PART 2: Insert NARA provider row into genealogy_research_providers
        // with API key. Original seeder didn't include NARA.
        // ═══════════════════════════════════════════════════════════════

        $existing = DB::selectOne("SELECT id FROM genealogy_research_providers WHERE provider_id = 'nara'");
        if (!$existing) {
            DB::insert("
                INSERT INTO genealogy_research_providers
                    (provider_id, provider_name, provider_class, provider_type, base_url, api_key_env, auth_type,
                     capabilities, config, rate_limit_rpm, is_active, priority, signup_url, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'nara',
                'National Archives (NARA)',
                'App\\Services\\Genealogy\\GenealogySourceService',
                'api',
                'https://catalog.archives.gov/api/v2',
                null, // Key stored in api_key column, not env var
                'api_key',
                json_encode(['search_records' => true, 'search_persons' => false, 'military_records' => true, 'census_records' => true, 'immigration_records' => true]),
                json_encode(['search_endpoint' => '/records/search', 'header_key' => 'x-api-key', 'rate_limit_monthly' => 10000]),
                17, // ~10K/month ÷ 30 days ÷ 24hr ÷ 60min ≈ 0.23 RPM, but bursts OK
                1,
                25,
                'https://www.archives.gov/research/catalog/help/api',
                'NARA v2 API. 10K calls/month. Email Catalog_API@nara.gov for key.',
            ]);
        }

        // Store the API key — separate UPDATE so it works whether row existed or was just inserted
        try {
            // Check if api_key column exists
            $columns = DB::select("SHOW COLUMNS FROM genealogy_research_providers LIKE 'api_key'");
            if (!empty($columns)) {
                DB::update("UPDATE genealogy_research_providers SET api_key = ? WHERE provider_id = 'nara'", [
                    'e6nVyhdMoyaajucJfjtfd4EyMoFh49pJ5zIG4hzJ',
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Could not set NARA API key: " . $e->getMessage());
        }
    }

    public function down(): void
    {
        // Don't delete — the tool should stay registered
    }
};
