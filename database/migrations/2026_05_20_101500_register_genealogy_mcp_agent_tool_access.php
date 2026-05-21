<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $tools = [
            $this->readTool('family_profile', 'Read a complete tree-scoped family profile with spouses, children, family/member media, sources, and citations.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the family'],
                'family_id' => ['type' => 'integer', 'description' => 'Family ID to inspect'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum rows per related section', 'default' => 25],
            ], ['tree_id', 'family_id'], 30),
            $this->readTool('rag_status', 'Read genealogy person/source/media RAG and person embedding coverage for one tree or all trees.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID to inspect'],
            ], [], 20),
            $this->readTool('media_profile', 'Read one genealogy media row with paths, text excerpts, links, citations, and face-match hints.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the media row'],
                'media_id' => ['type' => 'integer', 'description' => 'Genealogy media ID to inspect'],
                'face_limit' => ['type' => 'integer', 'description' => 'Maximum face-match rows', 'default' => 25],
            ], ['tree_id', 'media_id'], 40),
            $this->readTool('media_review_packet', 'Read a compact document/OCR/media evidence packet with text quality, links, citations, hints, and next actions.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the media row'],
                'media_id' => ['type' => 'integer', 'description' => 'Genealogy media ID to inspect'],
                'text_limit' => ['type' => 'integer', 'description' => 'Maximum characters per text excerpt', 'default' => 1600],
                'summary_only' => ['type' => 'boolean', 'description' => 'Suppress long text excerpts while preserving counts and hints', 'default' => false],
            ], ['tree_id', 'media_id'], 40),
            $this->readTool('media_ocr_escalation_batch', 'Read compact OCR/HTR/vision escalation candidates for weak or missing media text.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to inspect'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum candidates', 'default' => 50],
                'bucket' => ['type' => 'string', 'description' => 'all, weak_text, html_text_extraction, document_text_extraction, image_ocr_or_vision, or processing_failed', 'default' => 'all'],
            ], ['tree_id'], 20),
            $this->readTool('person_fact_extract', 'Read candidate DOB/DOD/place/name/family facts from one media, source, or reviewed text packet with conflict flags.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the evidence and target person'],
                'media_id' => ['type' => 'integer', 'description' => 'Optional media packet to inspect'],
                'source_id' => ['type' => 'integer', 'description' => 'Optional source packet to inspect'],
                'person_id' => ['type' => 'integer', 'description' => 'Optional target person'],
                'document_text' => ['type' => 'string', 'description' => 'Optional reviewed text packet'],
                'text_limit' => ['type' => 'integer', 'description' => 'Maximum evidence text characters', 'default' => 5000],
            ], ['tree_id'], 30),
            $this->readTool('proposal_queue', 'Read tree-scoped genealogy proposal queue rows for pending, approved, rejected, or applied proposals.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to inspect'],
                'status' => ['type' => 'string', 'description' => 'pending, approved, rejected, or applied', 'default' => 'pending'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum rows per proposal type', 'default' => 50],
            ], ['tree_id'], 30),
            $this->readTool('memory_report', 'Read Genea memory signals: semantic facts, non-FT name guardrails, review signals, learned procedures, and episodes.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID for tree-scoped memory'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum recent semantic memories', 'default' => 20],
            ], [], 20),

            $this->writeTool('name_variant_add', 'Dry-run-first addition of a vetted maiden, married, alias, nickname, religious, or phonetic name variant for one tree-scoped person.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the person'],
                'person_id' => ['type' => 'integer', 'description' => 'Person ID to receive the variant'],
                'name_type' => ['type' => 'string', 'description' => 'birth, married, maiden, alias, nickname, religious, or phonetic'],
                'given_names' => ['type' => 'string', 'description' => 'Variant given names'],
                'surname' => ['type' => 'string', 'description' => 'Variant surname'],
                'source_id' => ['type' => 'integer', 'description' => 'Optional same-tree source ID'],
                'notes' => ['type' => 'string', 'description' => 'Evidence note explaining why this variant is valid'],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
            ], ['tree_id', 'person_id', 'name_type'], 20),
            $this->writeTool('nara_placeholder_capture_batch', 'Dry-run-first bounded capture of NARA Catalog URL-only genealogy_media placeholders into tree-scoped FT storage.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID whose NARA placeholder media rows should be captured'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum placeholder media rows', 'default' => 25],
                'media_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'confirm_download' => ['type' => 'boolean', 'default' => false],
                'confirm_storage_write' => ['type' => 'boolean', 'default' => false],
                'metadata_snapshot' => ['type' => 'boolean', 'default' => true],
                'compact' => ['type' => 'boolean', 'default' => true],
                'max_bytes' => ['type' => 'integer'],
            ], ['tree_id'], 10),
            $this->writeTool('person_media_link_retire', 'Dry-run-first retirement of bad person-media link rows with optional matching imported-media citation cleanup.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the person-media links'],
                'person_media_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'reason' => ['type' => 'string', 'description' => 'Evidence/review reason'],
                'retire_imported_citations' => ['type' => 'boolean', 'default' => true],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
            ], ['tree_id', 'person_media_ids', 'reason'], 20),
            $this->writeTool('media_link_integrity', 'Dry-run-first audit and deterministic repair of missing person-media links from primary photos, citations, and approved face matches.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to inspect or repair'],
                'repair' => ['type' => 'boolean', 'default' => false],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'limit' => ['type' => 'integer', 'default' => 25],
            ], ['tree_id'], 10),
            $this->writeTool('person_source_link_integrity', 'Dry-run-first audit and deterministic repair of missing person-source links from existing citation rows.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to inspect or repair'],
                'repair' => ['type' => 'boolean', 'default' => false],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'limit' => ['type' => 'integer', 'default' => 25],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
            ], ['tree_id'], 10),
            $this->writeTool('media_rag_batch', 'Run bounded genealogy media RAG indexing stats, dry-run, or confirmed batch without shell access.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID to scope indexing'],
                'limit' => ['type' => 'integer', 'default' => 20],
                'max_seconds' => ['type' => 'integer', 'default' => 45],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'stats' => ['type' => 'boolean', 'default' => false],
            ], [], 20),
            $this->writeTool('rag_index_batch', 'Run bounded genealogy person/place/source RAG indexing stats, dry-run, or confirmed batch without shell access.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID to scope indexing'],
                'limit' => ['type' => 'integer', 'default' => 20],
                'type' => ['type' => 'string', 'default' => 'persons'],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'stats' => ['type' => 'boolean', 'default' => false],
                'reindex' => ['type' => 'boolean', 'default' => false],
                'exclude_living' => ['type' => 'boolean', 'default' => false],
            ], [], 20),
            $this->writeTool('person_embedding_batch', 'Run bounded genealogy person embedding stats, dry-run preview, or confirmed batch without shell access.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID to scope embedding'],
                'limit' => ['type' => 'integer', 'default' => 50],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'stats' => ['type' => 'boolean', 'default' => false],
                'reindex' => ['type' => 'boolean', 'default' => false],
                'exclude_living' => ['type' => 'boolean', 'default' => false],
            ], [], 20),
            $this->writeTool('media_htr_batch', 'Run bounded genealogy HTR transcription status, dry-run, or confirmed batch without shell access.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID to scope transcription candidates'],
                'limit' => ['type' => 'integer', 'default' => 10],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'status' => ['type' => 'boolean', 'default' => false],
            ], [], 10),
            $this->writeTool('media_intake_memory_batch', 'Backfill saved genealogy media-intake run outcomes into tree-scoped Genea semantic memory.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID whose saved intake runs should be memorized'],
                'limit' => ['type' => 'integer', 'default' => 50],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
            ], ['tree_id'], 20),
            $this->writeTool('review_decision_memory_batch', 'Backfill accepted/rejected genealogy proposal decisions into tree-scoped Genea semantic memory.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to process'],
                'status' => ['type' => 'string', 'default' => 'applied,rejected'],
                'limit' => ['type' => 'integer', 'default' => 50],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
            ], ['tree_id'], 20),
            $this->writeTool('review_packet_memory_batch', 'Backfill accepted/rejected genealogy review-packet outcomes into tree-scoped Genea semantic memory.', [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to process'],
                'status' => ['type' => 'string', 'default' => 'reviewed,rejected'],
                'limit' => ['type' => 'integer', 'default' => 50],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
            ], ['tree_id'], 20),
        ];

        foreach ($tools as $tool) {
            DB::statement("
                INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description,
                     permissions, risk_level, category, requires_confirmation, max_calls_per_run,
                     mcp_server, mcp_tool, enabled, source, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config', ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    service_class = VALUES(service_class),
                    method = VALUES(method),
                    description = VALUES(description),
                    parameters = VALUES(parameters),
                    returns_description = VALUES(returns_description),
                    permissions = VALUES(permissions),
                    risk_level = VALUES(risk_level),
                    category = VALUES(category),
                    requires_confirmation = VALUES(requires_confirmation),
                    max_calls_per_run = VALUES(max_calls_per_run),
                    mcp_server = VALUES(mcp_server),
                    mcp_tool = VALUES(mcp_tool),
                    enabled = VALUES(enabled),
                    source = VALUES(source),
                    notes = VALUES(notes),
                    updated_at = NOW()
            ", [
                $tool['name'],
                'App\\Engine\\MCPRouter',
                'callTool',
                $tool['description'],
                json_encode($tool['parameters'], JSON_UNESCAPED_SLASHES),
                'Returns the Genea MCP payload with compact read data or dry-run/write-audit status.',
                json_encode($tool['permissions'], JSON_UNESCAPED_SLASHES),
                $tool['risk_level'],
                'genealogy',
                0,
                $tool['max_calls_per_run'],
                'genealogy',
                $tool['name'],
                'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', [
                'family_profile',
                'rag_status',
                'media_profile',
                'media_review_packet',
                'media_ocr_escalation_batch',
                'person_fact_extract',
                'proposal_queue',
                'memory_report',
                'name_variant_add',
                'nara_placeholder_capture_batch',
                'person_media_link_retire',
                'media_link_integrity',
                'person_source_link_integrity',
                'media_rag_batch',
                'rag_index_batch',
                'person_embedding_batch',
                'media_htr_batch',
                'media_intake_memory_batch',
                'review_decision_memory_batch',
                'review_packet_memory_batch',
            ])
            ->where('mcp_server', 'genealogy')
            ->delete();
    }

    private function readTool(string $name, string $description, array $properties, array $required = [], int $maxCalls = 20): array
    {
        return $this->tool($name, $description, 'read', ['genealogy:read'], $properties, $required, $maxCalls);
    }

    private function writeTool(string $name, string $description, array $properties, array $required = [], int $maxCalls = 10): array
    {
        return $this->tool($name, $description, 'write', ['genealogy:read', 'genealogy:write'], $properties, $required, $maxCalls);
    }

    private function tool(
        string $name,
        string $description,
        string $riskLevel,
        array $permissions,
        array $properties,
        array $required,
        int $maxCalls,
    ): array {
        $parameters = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $parameters['required'] = $required;
        }

        return [
            'name' => $name,
            'description' => $description,
            'risk_level' => $riskLevel,
            'permissions' => $permissions,
            'parameters' => $parameters,
            'max_calls_per_run' => $maxCalls,
        ];
    }
};
