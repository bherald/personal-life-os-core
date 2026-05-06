<?php

namespace App\Services;

use App\Services\Genealogy\GenealogyReviewPacketFocusService;
use App\Services\Genealogy\GenealogyReviewPacketOutcomeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReviewTypeRegistryService - DB-driven pluggable review type system
 *
 * Loads review type definitions from review_type_registry table.
 * Dynamically builds SQL queries, handles multi-DB connections,
 * and routes approve/reject to appropriate handlers.
 *
 * Pattern matches AgentToolRegistryService for consistency.
 */
class ReviewTypeRegistryService
{
    private ?array $typeCache = null;

    private ?\App\Services\Genealogy\GenealogyLocalReviewSummaryService $genealogyReviewSummary = null;

    private ?GenealogyReviewPacketFocusService $genealogyReviewPacketFocus = null;

    private ?GenealogyReviewPacketOutcomeService $genealogyReviewPacketOutcome = null;

    private const CACHE_KEY = 'review_type_registry';

    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Get all enabled review types
     *
     * @return array<string, array> Keyed by type name
     */
    public function getTypes(): array
    {
        if ($this->typeCache !== null) {
            return $this->typeCache;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            $this->typeCache = $cached;

            return $this->typeCache;
        }

        try {
            $rows = DB::select('
                SELECT * FROM review_type_registry
                WHERE enabled = 1
                ORDER BY display_order ASC
            ');
        } catch (\Throwable $e) {
            Log::warning('ReviewTypeRegistry: Table not found, returning empty', ['error' => $e->getMessage()]);

            return [];
        }

        $types = [];
        foreach ($rows as $row) {
            $types[$row->name] = $this->rowToType($row);
        }

        $this->typeCache = $types;
        Cache::put(self::CACHE_KEY, $types, self::CACHE_TTL);

        return $types;
    }

    /**
     * Get a single review type by name
     */
    public function getType(string $name): ?array
    {
        $types = $this->getTypes();

        return $types[$name] ?? null;
    }

    /**
     * Get types grouped by category for UI tabs
     */
    public function getTypesByCategory(): array
    {
        $types = $this->getTypes();
        $grouped = [];

        foreach ($types as $name => $type) {
            $category = $type['category'];
            if (! isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][$name] = $type;
        }

        return $grouped;
    }

    /**
     * Get stats for all review types
     *
     * @return array<string, array{total: int, high_priority?: int}>
     */
    public function getAllStats(): array
    {
        $stats = [];
        $totalAll = 0;

        foreach ($this->getTypes() as $name => $type) {
            try {
                $connection = $this->getConnection($type['source_connection']);
                $result = $connection->selectOne($type['count_sql']);

                $stats[$name] = [
                    'total' => (int) ($result->total ?? 0),
                    'high_priority' => (int) ($result->high_priority ?? 0),
                    'expiring_soon' => (int) ($result->expiring_soon ?? 0),
                    'label' => $type['label'],
                    'icon' => $type['icon'],
                    'color' => $type['color'],
                    'category' => $type['category'],
                ];

                $totalAll += $stats[$name]['total'];
            } catch (\Throwable $e) {
                Log::warning("ReviewTypeRegistry: Failed to get stats for {$name}", ['error' => $e->getMessage()]);
                $stats[$name] = [
                    'total' => 0,
                    'error' => $e->getMessage(),
                    'label' => $type['label'],
                    'icon' => $type['icon'],
                    'color' => $type['color'],
                    'category' => $type['category'],
                ];
            }
        }

        $stats['_total'] = $totalAll;

        return $stats;
    }

    /**
     * Fetch pending items for a specific type.
     *
     * @param  float|null  $minConfidence  When set, items whose confidence is
     *                                     below this threshold are filtered out. NULL-confidence items
     *                                     (system alerts, review types without scoring) always pass through
     *                                     so operator-facing alerts don't get silenced alongside low-score
     *                                     research findings.
     */
    public function fetchItems(string $typeName, int $limit = 25, int $offset = 0, ?float $minConfidence = null): array
    {
        $type = $this->getType($typeName);
        if (! $type) {
            return ['items' => [], 'total' => 0, 'has_more' => false, 'error' => "Unknown type: {$typeName}"];
        }

        try {
            $connection = $this->getConnection($type['source_connection']);
            $rows = $connection->select($type['fetch_sql']);
            $allItems = $this->mapItems($rows, $type);
            $allItems = $this->applyConfidenceThreshold($allItems, $minConfidence);
            $total = count($allItems);
            $items = array_slice($allItems, $offset, $limit);

            return [
                'items' => array_values($items),
                'total' => $total,
                'has_more' => ($offset + $limit) < $total,
                'type' => $type,
            ];
        } catch (\Throwable $e) {
            Log::error("ReviewTypeRegistry: Fetch failed for {$typeName}", [
                'error' => $e->getMessage(),
                'sql' => $type['fetch_sql'],
            ]);

            return ['items' => [], 'total' => 0, 'has_more' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch items for all types (for unified view) with pagination
     */
    public function fetchAllItems(?string $category = null, int $limit = 25, int $offset = 0, ?float $minConfidence = null): array
    {
        $allItems = [];
        $types = $this->getTypes();

        foreach ($types as $name => $type) {
            if ($category && $type['category'] !== $category) {
                continue;
            }

            // Fetch all from each type (SQL already has its own LIMIT)
            $result = $this->fetchItems($name, 500, 0, $minConfidence);
            if (! empty($result['items'])) {
                $allItems = array_merge($allItems, $result['items']);
            }
        }

        // Sort by priority (high first) then created_at (oldest first)
        usort($allItems, function ($a, $b) {
            $priorityA = $a['priority'] ?? 0;
            $priorityB = $b['priority'] ?? 0;
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA; // Higher priority first
            }

            return ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''); // Older first
        });

        $total = count($allItems);
        $paged = array_slice($allItems, $offset, $limit);

        return [
            'items' => array_values($paged),
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Approve an item by unified ID
     */
    public function approveItem(string $unifiedId, ?string $notes = null): array
    {
        [$typeName, $id] = $this->parseUnifiedId($unifiedId);
        $type = $this->getType($typeName);

        if (! $type) {
            return ['success' => false, 'error' => "Unknown type: {$typeName}"];
        }

        // Custom service handler takes precedence
        if ($type['service_class'] && $type['approve_method']) {
            return $this->callServiceMethod(
                $type['service_class'],
                $type['approve_method'],
                $id,
                $notes
            );
        }

        // Default SQL-based approval
        if ($type['approve_sql']) {
            try {
                $connection = $this->getConnection($type['source_connection']);
                $sql = $this->resolveActionSql($type['approve_sql'], $type, $id);
                $affected = $connection->update($sql, [$id]);

                return ['success' => $affected > 0, 'message' => 'Approved'];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'No approval handler configured'];
    }

    /**
     * Reject an item by unified ID
     */
    public function rejectItem(string $unifiedId, ?string $reason = null, ?string $reasonCode = null): array
    {
        [$typeName, $id] = $this->parseUnifiedId($unifiedId);
        $type = $this->getType($typeName);

        if (! $type) {
            return ['success' => false, 'error' => "Unknown type: {$typeName}"];
        }

        // Custom service handler
        if ($type['service_class'] && $type['reject_method']) {
            return $this->callServiceMethod(
                $type['service_class'],
                $type['reject_method'],
                $id,
                $reason,
                $this->decisionMeta($reasonCode)
            );
        }

        // Default SQL-based rejection
        if ($type['reject_sql']) {
            try {
                $connection = $this->getConnection($type['source_connection']);
                $sql = $this->resolveActionSql($type['reject_sql'], $type, $id);
                // Some reject_sql templates include a reviewer_notes placeholder before the id.
                // Detect by counting '?' placeholders: 2 means (reason, id); 1 means (id) only.
                $placeholderCount = substr_count($sql, '?');
                $params = $placeholderCount >= 2 ? [$reason, $id] : [$id];
                $affected = $connection->update($sql, $params);

                return ['success' => $affected > 0, 'message' => 'Rejected'];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'No rejection handler configured'];
    }

    /**
     * Request clarification for an item by unified ID.
     */
    public function clarifyItem(string $unifiedId, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->callServiceActionItem($unifiedId, 'clarify', $notes, 'clarification', $reasonCode);
    }

    /**
     * Defer an item by unified ID.
     */
    public function deferItem(string $unifiedId, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->callServiceActionItem($unifiedId, 'defer', $notes, 'defer', $reasonCode);
    }

    /**
     * Ignore an item by unified ID (sets 'ignored' status to prevent re-discovery)
     *
     * Uses ignore_sql from the registry so each table's correct status column
     * is referenced (e.g. research_facts uses review_status, not status).
     */
    public function ignoreItem(string $unifiedId): array
    {
        [$typeName, $id] = $this->parseUnifiedId($unifiedId);
        $type = $this->getType($typeName);

        if (! $type) {
            return ['success' => false, 'error' => "Unknown type: {$typeName}"];
        }

        // Use ignore_sql from registry (same pattern as approve/reject)
        if (! empty($type['ignore_sql'])) {
            try {
                $connection = $this->getConnection($type['source_connection']);
                $sql = $this->resolveActionSql($type['ignore_sql'], $type, $id);
                $affected = $connection->update($sql, [$id]);

                return ['success' => $affected > 0, 'message' => 'Ignored'];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Fallback for types without ignore_sql configured
        try {
            $connection = $this->getConnection($type['source_connection']);
            $table = $type['source_table'];
            $idColumn = $this->resolveIdColumn($type, $id);

            $affected = $connection->update(
                "UPDATE {$table} SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE {$idColumn} = ?",
                [$id]
            );

            return ['success' => $affected > 0, 'message' => 'Ignored'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function callServiceActionItem(
        string $unifiedId,
        string $method,
        ?string $notes,
        string $label,
        ?string $reasonCode = null
    ): array {
        [$typeName, $id] = $this->parseUnifiedId($unifiedId);
        $type = $this->getType($typeName);

        if (! $type) {
            return ['success' => false, 'error' => "Unknown type: {$typeName}"];
        }

        if ($type['service_class']) {
            return $this->callServiceMethod(
                $type['service_class'],
                $method,
                $id,
                $notes,
                $this->decisionMeta($reasonCode)
            );
        }

        return ['success' => false, 'error' => "No {$label} handler configured"];
    }

    /**
     * Determine which column to use for WHERE clause based on unified_id_template.
     * If the template uses {{token}}, we need WHERE token = ?, otherwise WHERE id = ?.
     */
    private function getIdColumnForType(array $type): string
    {
        $template = $type['field_mapping']['unified_id_template'] ?? '';
        if (preg_match('/\{\{(\w+)\}\}/', $template, $m)) {
            return $m[1]; // e.g., 'token' for agent, 'id' for others
        }

        return 'id';
    }

    /**
     * Resolve the correct id column, falling back to 'id' when the value is numeric
     * (indicates the unified_id was built with row id due to empty template field).
     */
    private function resolveIdColumn(array $type, string $id): string
    {
        $column = $this->getIdColumnForType($type);
        // If template says 'token' but id is numeric, the token was empty and we fell back to row id
        if ($column !== 'id' && ctype_digit($id)) {
            return 'id';
        }

        return $column;
    }

    /**
     * Rewrite approve/reject SQL to use 'id' column when the unified_id value is numeric
     * (fallback for rows with empty token fields).
     */
    private function resolveActionSql(string $sql, array $type, string $id): string
    {
        $templateColumn = $this->getIdColumnForType($type);
        if ($templateColumn !== 'id' && ctype_digit($id)) {
            return str_replace("WHERE {$templateColumn} = ?", 'WHERE id = ?', $sql);
        }

        return $sql;
    }

    /**
     * Batch approve multiple items
     */
    public function batchApprove(array $unifiedIds, ?string $notes = null): array
    {
        $results = [];
        foreach ($unifiedIds as $id) {
            $batchDisabled = $this->batchDisabledResult((string) $id);
            if ($batchDisabled !== null) {
                $results[$id] = $batchDisabled;

                continue;
            }

            $results[$id] = $this->approveItem($id, $notes);
        }

        return $results;
    }

    /**
     * Batch reject multiple items
     */
    public function batchReject(array $unifiedIds, ?string $reason = null): array
    {
        $results = [];
        foreach ($unifiedIds as $id) {
            $batchDisabled = $this->batchDisabledResult((string) $id);
            if ($batchDisabled !== null) {
                $results[$id] = $batchDisabled;

                continue;
            }

            $results[$id] = $this->rejectItem($id, $reason);
        }

        return $results;
    }

    private function batchDisabledResult(string $unifiedId): ?array
    {
        try {
            [$typeName] = $this->parseUnifiedId($unifiedId);
        } catch (\Throwable) {
            return null;
        }

        $type = $this->getType($typeName);
        if (! $type || (bool) ($type['batch_enabled'] ?? true)) {
            return null;
        }

        return [
            'success' => false,
            'error' => "Batch actions are disabled for {$typeName}.",
        ];
    }

    /**
     * Register a new review type dynamically
     */
    public function registerType(array $definition): array
    {
        $required = ['name', 'label', 'source_table', 'count_sql', 'fetch_sql', 'field_mapping'];
        foreach ($required as $field) {
            if (empty($definition[$field])) {
                return ['success' => false, 'error' => "Missing required field: {$field}"];
            }
        }

        try {
            DB::insert('
                INSERT INTO review_type_registry
                (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql,
                 approve_sql, reject_sql, field_mapping, vue_renderer, vue_detail_component, actions,
                 requires_image, image_field, batch_enabled, service_class, approve_method, reject_method,
                 color, display_order, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                $definition['name'],
                $definition['label'],
                $definition['icon'] ?? null,
                $definition['category'] ?? $definition['name'],
                $definition['source_table'],
                $definition['source_connection'] ?? 'mysql',
                $definition['count_sql'],
                $definition['fetch_sql'],
                $definition['approve_sql'] ?? null,
                $definition['reject_sql'] ?? null,
                is_array($definition['field_mapping']) ? json_encode($definition['field_mapping']) : $definition['field_mapping'],
                $definition['vue_renderer'] ?? null,
                $definition['vue_detail_component'] ?? null,
                is_array($definition['actions'] ?? null) ? json_encode($definition['actions']) : ($definition['actions'] ?? null),
                $definition['requires_image'] ?? 0,
                $definition['image_field'] ?? null,
                $definition['batch_enabled'] ?? 1,
                $definition['service_class'] ?? null,
                $definition['approve_method'] ?? null,
                $definition['reject_method'] ?? null,
                $definition['color'] ?? null,
                $definition['display_order'] ?? 100,
                $definition['enabled'] ?? 1,
            ]);

            $this->clearCache();

            return ['success' => true, 'name' => $definition['name']];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear registry cache
     */
    public function clearCache(): void
    {
        $this->typeCache = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Apply the same display decoration used by the review hub item list.
     */
    public function decorateItemForDisplay(string $typeName, array $item): array
    {
        if ($typeName === 'genealogy_finding') {
            return $this->decorateGenealogyFindingItem($item);
        }

        $type = $this->getType($typeName);
        if (! $type) {
            return $item;
        }

        return $item;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function rowToType(object $row): array
    {
        return [
            'name' => $row->name,
            'label' => $row->label,
            'icon' => $row->icon,
            'category' => $row->category,
            'source_table' => $row->source_table,
            'source_connection' => $row->source_connection,
            'count_sql' => $row->count_sql,
            'fetch_sql' => $row->fetch_sql,
            'approve_sql' => $row->approve_sql,
            'reject_sql' => $row->reject_sql,
            'ignore_sql' => $row->ignore_sql ?? null,
            'field_mapping' => json_decode($row->field_mapping, true) ?? [],
            'ui_schema' => json_decode($row->ui_schema ?? '{}', true),
            'vue_renderer' => $row->vue_renderer,
            'vue_detail_component' => $row->vue_detail_component,
            'actions' => json_decode($row->actions ?? '[]', true),
            'requires_image' => (bool) $row->requires_image,
            'image_field' => $row->image_field,
            'batch_enabled' => (bool) $row->batch_enabled,
            'service_class' => $row->service_class,
            'approve_method' => $row->approve_method,
            'reject_method' => $row->reject_method,
            'color' => $row->color,
            'display_order' => (int) $row->display_order,
        ];
    }

    private function getConnection(string $connectionName)
    {
        return $connectionName === 'pgsql_rag'
            ? DB::connection('pgsql_rag')
            : DB::connection();
    }

    /**
     * Drop rows whose confidence is below the threshold. NULL-confidence
     * rows (system alerts, non-scored review types) always pass through.
     * Pass null or <= 0 to disable filtering entirely.
     *
     * @param  array  $items  already mapped via mapItems()
     */
    private function applyConfidenceThreshold(array $items, ?float $minConfidence): array
    {
        if ($minConfidence === null || $minConfidence <= 0.0) {
            return $items;
        }

        return array_values(array_filter($items, static function (array $item) use ($minConfidence): bool {
            $confidence = $item['confidence'] ?? null;
            if ($confidence === null || $confidence === '') {
                return true;
            }

            return (float) $confidence >= $minConfidence;
        }));
    }

    private function mapItems(array $rows, array $type): array
    {
        $mapping = $type['field_mapping'];
        $items = [];

        foreach ($rows as $row) {
            $rowArray = (array) $row;
            // Override category when data indicates a different domain
            // (e.g., research_facts from genealogy missions → genealogy tab)
            $effectiveCategory = $type['category'];
            if (isset($rowArray['domain_category']) && $rowArray['domain_category'] !== $type['category']) {
                $effectiveCategory = $rowArray['domain_category'];
            }

            $item = [
                'source' => $type['name'],
                'category' => $effectiveCategory,
                'ui_schema' => $type['ui_schema'],
                'vue_renderer' => $type['vue_renderer'],
                'requires_image' => $type['requires_image'],
                'actions' => $type['actions'],
                'color' => $type['color'],
                'batch_enabled' => $type['batch_enabled'],
            ];

            // Apply field mapping
            foreach ($mapping as $unified => $source) {
                // Template substitution: "prefix:{{field}}"
                if (is_string($source) && preg_match('/\{\{(\w+)\}\}/', $source, $m)) {
                    $value = $rowArray[$m[1]] ?? '';
                    $item[$unified] = str_replace("{{{$m[1]}}}", $value, $source);
                }
                // Expression fields (title_expr, summary_expr) - use as-is from query
                elseif (str_ends_with($unified, '_expr')) {
                    $baseField = str_replace('_expr', '', $unified);
                    $item[$baseField] = $rowArray[$source] ?? null;
                }
                // JSON fields
                elseif (str_ends_with($unified, '_json')) {
                    $baseField = str_replace('_json', '', $unified);
                    $rawValue = $rowArray[$source] ?? null;
                    $item[$baseField] = is_string($rawValue) ? json_decode($rawValue, true) : $rawValue;
                }
                // Direct field mapping
                else {
                    $item[$unified] = $rowArray[$source] ?? null;
                }
            }

            // Build unified_id from template
            if (isset($mapping['unified_id_template'])) {
                $template = $mapping['unified_id_template'];
                preg_match_all('/\{\{(\w+)\}\}/', $template, $matches);
                foreach ($matches[1] as $field) {
                    // Fall back to row id if the template field is empty
                    $value = ! empty($rowArray[$field]) ? $rowArray[$field] : ($rowArray['id'] ?? '');
                    $template = str_replace("{{{$field}}}", $value, $template);
                }
                $item['unified_id'] = $template;
            }

            // Build title from expression or direct mapping
            if (! isset($item['title']) && isset($rowArray['title'])) {
                $item['title'] = $rowArray['title'];
            }

            // Build summary from expression or direct mapping
            if (! isset($item['summary']) && isset($rowArray['summary'])) {
                $item['summary'] = $rowArray['summary'];
            }

            if ($type['name'] === 'genealogy_finding') {
                $item = $this->decorateGenealogyFindingItem($item);
            }

            if ($type['name'] === 'genealogy_review_packet') {
                $item = $this->decorateGenealogyReviewPacketItem($item);
            }

            // Add image URL if applicable
            if ($type['requires_image'] && $type['image_field']) {
                $item['image_url'] = $this->buildImageUrl($rowArray, $type);
            }

            // Calculate priority from confidence if not set
            if (! isset($item['priority']) && isset($item['confidence'])) {
                $item['priority'] = $this->confidenceToPriority($item['confidence']);
            }

            $items[] = $item;
        }

        return $items;
    }

    private function decorateGenealogyReviewPacketItem(array $item): array
    {
        $details = $item['details'] ?? null;
        if (! is_array($details)) {
            return $item;
        }

        $personId = $this->extractGenealogyReviewPacketPersonId($details);
        if ($personId !== null) {
            $item['person_id'] = $personId;
        }

        if (isset($details['packet_status']) && is_scalar($details['packet_status'])) {
            $item['packet_status'] = (string) $details['packet_status'];
        }

        $claims = is_array($details['claims'] ?? null) ? $details['claims'] : [];
        $item['claim_count'] = count($claims);

        $sourceLocators = is_array($details['source_locators'] ?? null) ? $details['source_locators'] : [];
        $sources = is_array($details['sources'] ?? null) ? $details['sources'] : [];
        $item['source_count'] = count($sourceLocators !== [] ? $sourceLocators : $sources);
        $item['review_focus'] = $this->genealogyReviewPacketFocus()->fromPersistedDetails($details);
        $item['packet_outcome'] = $this->genealogyReviewPacketOutcome()->fromDetails(
            $details,
            isset($item['status']) && is_scalar($item['status']) ? (string) $item['status'] : null
        );

        if (isset($details['source_locator']) && is_scalar($details['source_locator'])) {
            $item['source_locator'] = (string) $details['source_locator'];
        } elseif (isset($item['review_focus']['source_locator']) && is_scalar($item['review_focus']['source_locator'])) {
            $item['source_locator'] = (string) $item['review_focus']['source_locator'];
        }

        return $item;
    }

    private function extractGenealogyReviewPacketPersonId(array $details): ?int
    {
        $identity = $details['identity'] ?? null;
        if (is_array($identity)) {
            foreach (['person_id', 'target_person_id'] as $key) {
                $personId = $this->positiveIntOrNull($identity[$key] ?? null);
                if ($personId !== null) {
                    return $personId;
                }
            }
        }

        $claims = is_array($details['claims'] ?? null) ? $details['claims'] : [];
        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $personId = $this->positiveIntOrNull($claim['person_id'] ?? null);
            if ($personId !== null) {
                return $personId;
            }
        }

        return null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function genealogyReviewPacketFocus(): GenealogyReviewPacketFocusService
    {
        return $this->genealogyReviewPacketFocus ??= new GenealogyReviewPacketFocusService;
    }

    private function genealogyReviewPacketOutcome(): GenealogyReviewPacketOutcomeService
    {
        return $this->genealogyReviewPacketOutcome ??= new GenealogyReviewPacketOutcomeService;
    }

    private function decorateGenealogyFindingItem(array $item): array
    {
        $item = $this->enrichGenealogyFindingReviewLink($item);

        $details = $item['details'] ?? null;
        if (! is_array($details)) {
            return $item;
        }

        if (! empty($details['person_name']) && empty($item['person_name'])) {
            $item['person_name'] = $details['person_name'];
        }

        $proposals = is_array($details['proposals'] ?? null) ? $details['proposals'] : [];
        if ($proposals === []) {
            return $item;
        }

        $lines = [];
        foreach (array_slice($proposals, 0, 3) as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            if (! empty($item['person_name']) && empty($proposal['person_name'])) {
                $proposal['person_name'] = $item['person_name'];
            }

            $line = $this->summarizeGenealogyProposal($proposal);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($lines !== []) {
            if (count($proposals) > count($lines)) {
                $lines[] = 'Additional proposed changes: '.(count($proposals) - count($lines)).' more';
            }

            $lines = array_map(fn (string $line): string => $this->cleanupGenealogyReviewLine($line, $item), $lines);
            $item['summary'] = implode("\n\n", $lines);
            $item['details_human'] = implode("\n\n", $lines);
            $item['proposal_count'] = count($proposals);
        }

        return $item;
    }

    private function summarizeGenealogyProposal(array $proposal): ?string
    {
        $type = (string) ($proposal['change_type'] ?? $proposal['relationship_type'] ?? 'update');
        $field = trim((string) ($proposal['field_name'] ?? ''));
        $evidence = $this->sanitizeGenealogyEvidenceText((string) ($proposal['evidence_summary'] ?? ''));
        $sourceLine = $this->buildGenealogySourceLine($proposal);

        if ($type === 'notes_append') {
            $text = trim((string) ($proposal['evidence_summary'] ?? $proposal['proposed_value'] ?? ''));
            if ($text === '') {
                return null;
            }

            if ($this->looksLikeStructuredSearchCoverage($text)) {
                return $this->summarizeStructuredSearchCoverage($proposal, $text);
            }

            $parts = ['Finding: '.mb_substr($text, 0, 220)];
            if ($sourceLine !== null) {
                $parts[] = $sourceLine;
            }

            return implode("\n", $parts);
        }

        $value = trim((string) ($proposal['proposed_value'] ?? $proposal['proposed_name'] ?? ''));
        if ($value === '') {
            return null;
        }

        $headline = $field !== ''
            ? 'Proposed '.$this->humanizeGenealogyFieldLabel($field).': '.mb_substr($value, 0, 160)
            : $this->humanizeGenealogyChangeLabel($type).': '.mb_substr($value, 0, 160);

        $parts = [$headline];
        if ($evidence !== '') {
            $parts[] = 'Evidence: '.mb_substr($evidence, 0, 220);
        }
        if ($sourceLine !== null) {
            $parts[] = $sourceLine;
        }

        return implode("\n", $parts);
    }

    private function cleanupGenealogyReviewLine(string $line, array $item): string
    {
        $line = trim($line);
        if (! $this->looksNoisyGenealogyReviewLine($line)) {
            return $line;
        }

        return $this->genealogyReviewSummary()->cleanup($line, [
            'person_name' => $item['person_name'] ?? null,
        ]);
    }

    private function looksNoisyGenealogyReviewLine(string $line): bool
    {
        return str_contains($line, '{')
            || str_contains($line, 'notes_append:')
            || str_contains($line, '[structured data]')
            || preg_match('/\bstructured_data\b/i', $line) === 1
            || preg_match('/\braw\b.*\bjson\b/i', $line) === 1;
    }

    private function genealogyReviewSummary(): \App\Services\Genealogy\GenealogyLocalReviewSummaryService
    {
        if ($this->genealogyReviewSummary === null) {
            $this->genealogyReviewSummary = app(\App\Services\Genealogy\GenealogyLocalReviewSummaryService::class);
        }

        return $this->genealogyReviewSummary;
    }

    private function sanitizeGenealogyEvidenceText(string $text): string
    {
        $text = trim($text);
        if ($text === '' || $this->looksLikeStructuredSearchCoverage($text)) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function buildGenealogySourceLine(array $proposal): ?string
    {
        $sources = array_values(array_filter(
            is_array($proposal['evidence_sources'] ?? null) ? $proposal['evidence_sources'] : [],
            fn ($item) => is_string($item) && trim($item) !== '' && trim($item) !== 'update_search_coverage'
        ));

        if ($sources === []) {
            return null;
        }

        $sourceList = implode(', ', array_slice($sources, 0, 3));
        if (count($sources) > 3) {
            $sourceList .= ', ...';
        }

        return 'Sources: '.$sourceList;
    }

    private function looksLikeStructuredSearchCoverage(string $text): bool
    {
        return str_contains($text, '"success"')
            || str_contains($text, '\"success\"')
            || str_contains($text, '"query"')
            || str_contains($text, '\"query\"')
            || str_contains($text, '"sources_searched"')
            || str_contains($text, '\"sources_searched\"')
            || (str_contains($text, 'Search for ') && str_contains($text, 'result(s)'));
    }

    private function summarizeStructuredSearchCoverage(array $proposal, string $text): string
    {
        $personName = $this->extractSearchCoverageQuery($proposal, $text);
        $sources = $this->extractSearchCoverageSources($text);
        $hintCount = $this->extractSearchCoverageHintCount($text);

        $parts = [];
        $parts[] = $personName !== ''
            ? "Search coverage updated for {$personName}"
            : 'Search coverage updated';

        if ($sources !== []) {
            $sourceList = implode(', ', array_slice($sources, 0, 3));
            if (count($sources) > 3) {
                $sourceList .= ', ...';
            }
            $parts[] = "Sources checked: {$sourceList}";
        }

        if ($hintCount !== null) {
            $parts[] = $hintCount === 0 ? 'Record hints: none generated' : "Record hints: {$hintCount}";
        }

        return mb_substr(implode("\n", $parts), 0, 220);
    }

    private function enrichGenealogyFindingReviewLink(array $item): array
    {
        $unifiedId = trim((string) ($item['unified_id'] ?? ''));

        if ($unifiedId !== '') {
            $item['review_url'] = $this->buildReviewQuickViewUrl($unifiedId);
            $item['review_link_text'] = 'Open full review page';
        }

        $sourceUrls = $this->harvestGenealogySourceUrls($item);
        if ($sourceUrls !== []) {
            $item['source_urls'] = $sourceUrls;
        }

        if (! is_array($item['ui_schema'] ?? null)) {
            return $item;
        }

        $card = is_array($item['ui_schema']['card'] ?? null) ? $item['ui_schema']['card'] : [];
        $body = is_array($card['body'] ?? null) ? $card['body'] : [];

        $hasReviewLink = false;
        $hasSourceList = false;
        foreach ($body as $field) {
            if (($field['type'] ?? null) === 'link' && ($field['source'] ?? null) === 'review_url') {
                $hasReviewLink = true;
            }
            if (($field['type'] ?? null) === 'link_list' && ($field['source'] ?? null) === 'source_urls') {
                $hasSourceList = true;
            }
        }

        if (! $hasReviewLink && ! empty($item['review_url'])) {
            $body[] = [
                'type' => 'link',
                'label' => 'Review',
                'source' => 'review_url',
                'text_source' => 'review_link_text',
                'class' => 'text-xs mt-1',
            ];
        }

        if (! $hasSourceList && $sourceUrls !== []) {
            $body[] = [
                'type' => 'link_list',
                'label' => 'Open source',
                'source' => 'source_urls',
                'class' => 'text-xs mt-1',
            ];
        }

        $item['ui_schema']['card'] = $card;
        $item['ui_schema']['card']['body'] = $body;

        return $item;
    }

    private function harvestGenealogySourceUrls(array $item): array
    {
        $details = is_array($item['details'] ?? null) ? $item['details'] : [];
        $proposals = is_array($details['proposals'] ?? null) ? $details['proposals'] : [];
        if ($proposals === []) {
            return [];
        }

        $urls = [];
        $seen = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            foreach ($this->extractGenealogyHttpUrls($proposal) as $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function extractGenealogyHttpUrls(array $proposal): array
    {
        $candidates = [];
        foreach (['proposed_value', 'source_url', 'url', 'evidence_summary'] as $key) {
            $value = $proposal[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $candidates[] = $value;
            }
        }
        if (is_array($proposal['evidence_sources'] ?? null)) {
            foreach ($proposal['evidence_sources'] as $src) {
                if (is_string($src) && $src !== '') {
                    $candidates[] = $src;
                }
            }
        }

        $urls = [];
        foreach ($candidates as $candidate) {
            if (preg_match_all('~https?://[^\s<>"\')]+~', $candidate, $matches) !== false) {
                foreach ($matches[0] as $url) {
                    $url = rtrim($url, '.,;:)]}');
                    if ($url !== '') {
                        $urls[] = $url;
                    }
                }
            }
        }

        return $urls;
    }

    private function buildReviewQuickViewUrl(string $unifiedId): string
    {
        $baseUrl = rtrim((string) config('app.public_url', config('app.url')), '/');

        return "{$baseUrl}/api/research-hub/quick-view/{$unifiedId}";
    }

    private function humanizeGenealogyFieldLabel(string $field): string
    {
        return trim(strtolower(str_replace('_', ' ', $field)));
    }

    private function humanizeGenealogyChangeLabel(string $type): string
    {
        return match ($type) {
            'event_add' => 'Proposed event detail',
            'residence_add' => 'Proposed residence',
            'source_add' => 'Proposed source link',
            'source_create' => 'Proposed source record',
            'media_link' => 'Proposed media link',
            'media_metadata_update' => 'Proposed media metadata update',
            'external_record_link' => 'Proposed external record link',
            'family_event_update' => 'Proposed family event update',
            'spouse' => 'Possible spouse relationship',
            'parent' => 'Possible parent relationship',
            'child' => 'Possible child relationship',
            'sibling' => 'Possible sibling relationship',
            default => 'Proposed update',
        };
    }

    private function extractSearchCoverageQuery(array $proposal, string $text): string
    {
        $personName = trim((string) ($proposal['person_name'] ?? ''));
        if ($personName !== '') {
            return $personName;
        }

        if (preg_match('/"query"\s*:\s*"([^"]+)"/', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/query"\s*:\s*\\"([^"]+)\\"/', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/Search for\s+([^;]+);/i', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    private function extractSearchCoverageSources(string $text): array
    {
        if (preg_match('/sources_searched"\s*:\s*\[(.*?)\]/', $text, $matches) === 1
            || preg_match('/sources_searched\\\"\s*:\s*\[(.*?)\]/', $text, $matches) === 1) {
            preg_match_all('/"([^"]+)"|\\"([^"]+)\\"/', $matches[1], $sourceMatches);
            $sources = array_values(array_filter(array_map(static function ($plain, $escaped) {
                return trim((string) ($plain !== '' ? $plain : $escaped));
            }, $sourceMatches[1] ?? [], $sourceMatches[2] ?? [])));

            return array_values(array_unique($sources));
        }

        if (preg_match('/sources\s+([^;]+)/i', $text, $matches) === 1) {
            $parts = array_map('trim', explode(',', $matches[1]));

            return array_values(array_filter(array_unique($parts)));
        }

        return [];
    }

    private function extractSearchCoverageHintCount(string $text): ?int
    {
        if (preg_match('/"hints_generated"\s*:\s*(\d+)/', $text, $matches) === 1
            || preg_match('/hints_generated\\\"\s*:\s*(\d+)/', $text, $matches) === 1
            || preg_match('/Record hints generated:\s*(\d+)/i', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function buildImageUrl(array $row, array $type): ?string
    {
        $imageField = $type['image_field'];
        $value = $row[$imageField] ?? null;
        if (! $value) {
            return null;
        }

        // For face matches, build face crop URL using queue ID
        if ($type['name'] === 'face') {
            $mediaId = $row['media_id'] ?? null;
            if ($mediaId) {
                // Use face-match-crop endpoint which queries genealogy_face_match_queue
                return "/api/media/face-match-crop/{$row['id']}";
            }

            // Fallback to thumbnail of full image
            return '/api/media/thumbnail?'.http_build_query(['path' => $value, 'size' => 'medium']);
        }

        // Default: build thumbnail URL
        return '/api/media/thumbnail?'.http_build_query(['path' => $value, 'size' => 'medium']);
    }

    private function confidenceToPriority(?float $confidence): string
    {
        if ($confidence === null) {
            return 'medium';
        }
        if ($confidence >= 0.8) {
            return 'high';
        }
        if ($confidence >= 0.5) {
            return 'medium';
        }

        return 'low';
    }

    private function parseUnifiedId(string $unifiedId): array
    {
        $parts = explode(':', $unifiedId, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid unified ID format: {$unifiedId}");
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function callServiceMethod(string $class, string $method, $id, ?string $notesOrReason, array $meta = []): array
    {
        try {
            $service = app($class);

            if (! method_exists($service, $method)) {
                return ['success' => false, 'error' => "Method {$method} not found on {$class}"];
            }

            // Reflect on method signature to build correct arguments:
            // - 1-param methods: pass ($id) only
            // - 2-param with array type: pass ($id, [notes/reason wrapped])
            // - 2-param with string/null type: pass ($id, $notesOrReason)
            // - 3-param handlers may accept packet-level decision metadata.
            $ref = new \ReflectionMethod($service, $method);
            $params = $ref->getParameters();
            $paramCount = count($params);

            // Cast $id to int if the first parameter is typed as int
            if ($paramCount > 0) {
                $firstType = $params[0]->getType();
                if ($firstType instanceof \ReflectionNamedType && $firstType->getName() === 'int') {
                    $id = (int) $id;
                }
            }

            if ($paramCount === 0) {
                $result = $service->$method();
            } elseif ($paramCount === 1) {
                $result = $service->$method($id);
            } else {
                $secondParam = $params[1];
                $secondType = $secondParam->getType();
                $typeName = $secondType instanceof \ReflectionNamedType ? $secondType->getName() : null;

                $secondArg = $typeName === 'array'
                    ? ($notesOrReason ? ['notes' => $notesOrReason] : [])
                    : $notesOrReason;

                if ($paramCount >= 3 && $meta !== [] && $this->thirdParameterAcceptsDecisionMeta($params[2])) {
                    $thirdParam = $params[2];
                    $thirdType = $thirdParam->getType();
                    $thirdTypeName = $thirdType instanceof \ReflectionNamedType ? $thirdType->getName() : null;
                    $thirdArg = $thirdTypeName === 'array' ? $meta : ($meta['reason_code'] ?? null);
                    $result = $service->$method($id, $secondArg, $thirdArg);
                } elseif ($typeName === 'array') {
                    $result = $service->$method($id, $secondArg);
                } else {
                    $result = $service->$method($id, $secondArg);
                }
            }

            if (is_array($result)) {
                return $result;
            }

            return ['success' => (bool) $result, 'result' => $result];
        } catch (\Throwable $e) {
            Log::error('ReviewTypeRegistry: Service call failed', [
                'class' => $class,
                'method' => $method,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, string>
     */
    private function decisionMeta(?string $reasonCode): array
    {
        $reasonCode = trim((string) $reasonCode);
        if ($reasonCode === '') {
            return [];
        }

        // Target decision services own reason-code normalization because their
        // vocabularies are review-type specific.
        return ['reason_code' => $reasonCode];
    }

    private function thirdParameterAcceptsDecisionMeta(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (! $type instanceof \ReflectionNamedType) {
            return true;
        }

        return in_array($type->getName(), ['array', 'string', 'mixed'], true);
    }
}
