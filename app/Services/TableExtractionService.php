<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RAG-15: Table Extraction — detect, parse, and index structured tables from documents.
 *
 * Many genealogy records contain structured data in tables:
 * census schedules, cemetery registers, land deed indices, passenger manifests.
 * Standard RAG embeds the whole document, diluting the dense numeric/relational
 * content of tables. This service extracts each table as its own child
 * rag_document (with parent_id pointing back to the source) so that tables
 * can be searched, reranked, and retrieved independently.
 *
 * Pipeline:
 *   1. detectTables() — scan content for markdown-pipe and ASCII-grid tables (pure)
 *   2. parseTableBlock() — extract headers + rows (pure)
 *   3. tableSummary() — generate a compact text description from structure (pure)
 *   4. describeTable() — optional LLM description for richer semantics (LLM)
 *   5. storeTableChunks() — insert as child rag_documents (DB)
 *
 * Reference: MMA-RAG (2025)
 */
class TableExtractionService
{
    /** Minimum number of rows (excluding header) to consider a table worth indexing */
    public const MIN_DATA_ROWS = 1;

    /** Maximum tables to extract per document (cost guard) */
    public const MAX_TABLES_PER_DOC = 8;

    /** Minimum columns for a valid table */
    public const MIN_COLUMNS = 2;

    /** LLM description snippet length */
    public const DESCRIBE_SNIPPET_CHARS = 1500;

    public function __construct(private readonly AIService $aiService) {}

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Extract tables from document content and store as child rag_documents.
     *
     * @param  string $content     Full document text
     * @param  int    $parentId    rag_documents.id of the parent document
     * @param  string $parentTitle Parent document title (used to name table chunks)
     * @return array{found: int, stored: int}
     */
    public function indexTables(string $content, int $parentId, string $parentTitle = ''): array
    {
        $blocks = $this->detectTables($content);
        if (empty($blocks)) {
            return ['found' => 0, 'stored' => 0];
        }

        $tables = [];
        foreach (array_slice($blocks, 0, self::MAX_TABLES_PER_DOC) as $block) {
            $parsed = $this->parseTableBlock($block);
            if ($parsed['column_count'] < self::MIN_COLUMNS || count($parsed['rows']) < self::MIN_DATA_ROWS) {
                continue;
            }
            $tables[] = $parsed;
        }

        if (empty($tables)) {
            return ['found' => count($blocks), 'stored' => 0];
        }

        $stored = $this->storeTableChunks($parentId, $parentTitle, $tables);
        return ['found' => count($blocks), 'stored' => $stored];
    }

    // =========================================================================
    // Table detection (pure)
    // =========================================================================

    /**
     * Detect markdown-pipe table blocks in text content.
     * A block is a contiguous group of lines where every line starts and ends with '|'.
     * Returns the raw table text for each detected block.
     *
     * @return string[]
     */
    public function detectTables(string $content): array
    {
        $lines   = explode("\n", $content);
        $tables  = [];
        $current = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($this->isPipeRow($trimmed)) {
                $current[] = $trimmed;
            } else {
                if (!empty($current)) {
                    $tables[] = implode("\n", $current);
                    $current  = [];
                }
            }
        }

        if (!empty($current)) {
            $tables[] = implode("\n", $current);
        }

        // Filter out separator-only blocks (e.g. |---|---|)
        return array_values(array_filter($tables, fn($t) => !$this->isSeparatorOnly($t)));
    }

    /**
     * Check whether a line looks like a pipe-delimited table row.
     * Pure — no I/O.
     */
    public function isPipeRow(string $line): bool
    {
        return strlen($line) >= 3
            && str_starts_with($line, '|')
            && str_ends_with($line, '|');
    }

    /**
     * Return true if the block consists only of separator lines (|---|---|).
     * Pure — no I/O.
     */
    public function isSeparatorOnly(string $block): bool
    {
        $lines = array_filter(explode("\n", trim($block)));
        foreach ($lines as $line) {
            // A data line has at least one non-dash, non-pipe, non-space character
            if (preg_match('/[^|\-\s:]/', $line)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Quick check: does text content likely contain at least one table?
     * Pure — avoids full parse overhead for documents without any pipe chars.
     */
    public function hasTableIndicators(string $content): bool
    {
        return substr_count($content, '|') >= 6; // At least 3 cells × 2 pipes each
    }

    // =========================================================================
    // Table parsing (pure)
    // =========================================================================

    /**
     * Parse a raw markdown table block into structured data.
     * Returns headers, rows, and metadata.
     *
     * @return array{headers: string[], rows: string[][], raw: string, column_count: int, row_count: int}
     */
    public function parseTableBlock(string $block): array
    {
        $lines   = array_values(array_filter(explode("\n", trim($block))));
        $headers = [];
        $rows    = [];

        foreach ($lines as $i => $line) {
            $cells = $this->splitCells($line);
            if (empty($cells)) {
                continue;
            }
            // Separator line (all dashes/colons) — skip
            if ($this->isSeparatorLine($line)) {
                continue;
            }
            if (empty($headers)) {
                $headers = $cells;
            } else {
                $rows[] = $cells;
            }
        }

        return [
            'headers'      => $headers,
            'rows'         => $rows,
            'raw'          => $block,
            'column_count' => count($headers),
            'row_count'    => count($rows),
        ];
    }

    /**
     * Split a pipe-delimited row into trimmed cell values.
     * Pure — no I/O.
     *
     * @return string[]
     */
    public function splitCells(string $line): array
    {
        // Remove leading/trailing pipes then split
        $line  = trim($line, '|');
        $cells = explode('|', $line);
        return array_map('trim', $cells);
    }

    /**
     * Return true if the line is a markdown separator (|---|---|).
     * Pure — no I/O.
     */
    public function isSeparatorLine(string $line): bool
    {
        $stripped = str_replace(['|', '-', ':', ' '], '', $line);
        return $stripped === '';
    }

    // =========================================================================
    // Description generation (pure + LLM)
    // =========================================================================

    /**
     * Generate a compact human-readable description of a parsed table.
     * Pure — uses only the table structure, no LLM.
     *
     * @param  array $table  Output of parseTableBlock()
     * @return string
     */
    public function tableSummary(array $table): string
    {
        $colCount = $table['column_count'];
        $rowCount = $table['row_count'];
        $headers  = $table['headers'] ?? [];

        if (empty($headers)) {
            return "Table with {$colCount} columns and {$rowCount} data rows.";
        }

        $headerList = implode(', ', array_slice($headers, 0, 6));
        $more       = count($headers) > 6 ? ' and more' : '';
        $summary    = "Table with {$colCount} columns ({$headerList}{$more}) and {$rowCount} data rows.";

        // Include first data row as a sample
        if (!empty($table['rows'][0])) {
            $sample = implode(' | ', array_slice($table['rows'][0], 0, 5));
            $summary .= " Sample row: {$sample}.";
        }

        return $summary;
    }

    /**
     * Generate a richer semantic description of a table using the LLM.
     * Falls back to tableSummary() on AI failure.
     */
    public function describeTable(array $table): string
    {
        $markdown = mb_substr($table['raw'], 0, self::DESCRIBE_SNIPPET_CHARS);
        $prompt   = "Describe the following data table in 1-2 sentences. "
            . "Explain what kind of records it contains, what the columns represent, "
            . "and what a person might search for to find it. "
            . "Be concise and factual.\n\nTable:\n{$markdown}";

        $result = $this->aiService->process($prompt, ['model_role' => 'fast', 'max_tokens' => 150]);

        if (!($result['success'] ?? false) || empty(trim($result['response'] ?? ''))) {
            return $this->tableSummary($table);
        }

        return trim($result['response']);
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    /**
     * Store parsed tables as child rag_documents (parent_id = $parentId).
     * Each table becomes a separate searchable chunk.
     * Returns count of rows inserted.
     */
    public function storeTableChunks(int $parentId, string $parentTitle, array $tables): int
    {
        $count = 0;
        foreach ($tables as $i => $table) {
            try {
                $title       = trim($parentTitle) !== ''
                    ? "{$parentTitle} — Table " . ($i + 1)
                    : "Table " . ($i + 1);
                $description = $this->describeTable($table);

                // Content = raw markdown + description (for full-text + embedding)
                $content = $table['raw'] . "\n\n" . $description;

                $source_id = "table:{$parentId}:" . ($i + 1);

                DB::connection('pgsql_rag')->insert("
                    INSERT INTO rag_documents
                        (document_type, title, content, parent_id, source_id, source_type,
                         has_visual_content, metadata, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'table_chunk', TRUE, ?::jsonb, NOW(), NOW())
                ", [
                    'table',
                    mb_substr($title, 0, 500),
                    $content,
                    $parentId,
                    $source_id,
                    json_encode([
                        'column_count' => $table['column_count'],
                        'row_count'    => $table['row_count'],
                        'headers'      => $table['headers'],
                    ]),
                ]);

                $count++;
            } catch (\Exception $e) {
                Log::warning('TableExtraction: Failed to store table chunk', [
                    'parent_id' => $parentId,
                    'table_idx' => $i,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /**
     * Delete previously extracted table chunks for a document.
     * Called before re-extraction to avoid duplicates.
     */
    public function deleteChunksForDocument(int $parentId): int
    {
        return DB::connection('pgsql_rag')->delete(
            "DELETE FROM rag_documents WHERE parent_id = ? AND source_type = 'table_chunk'",
            [$parentId]
        );
    }

    /**
     * Count table chunks for a document.
     */
    public function countChunksForDocument(int $parentId): int
    {
        $row = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) AS cnt FROM rag_documents WHERE parent_id = ? AND source_type = 'table_chunk'",
            [$parentId]
        );
        return (int) ($row->cnt ?? 0);
    }
}
