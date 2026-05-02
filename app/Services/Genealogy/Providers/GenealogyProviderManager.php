<?php

namespace App\Services\Genealogy\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Genealogy Provider Manager
 *
 * Orchestrates all external genealogy providers.
 * Provides a unified interface for:
 * - Provider registration and discovery
 * - Multi-provider search aggregation
 * - OAuth2 flow management
 * - Rate limiting and caching coordination
 *
 * Usage:
 *   $manager = new GenealogyProviderManager();
 *   $manager->setTreeContext($treeId);
 *
 *   // Get specific provider
 *   $provider = $manager->getProvider('wikitree');
 *
 *   // Search across all configured providers
 *   $results = $manager->searchAllProviders(['surname' => 'Smith']);
 *
 *   // Get provider status
 *   $status = $manager->getProvidersStatus();
 */
class GenealogyProviderManager
{
    protected array $providers = [];
    protected ?int $treeId = null;

    /**
     * Available provider classes — loaded from DB, with hardcoded fallback.
     * Table: genealogy_research_providers
     */
    protected array $providerClasses = [];

    /**
     * Hardcoded fallback when DB table doesn't exist or is empty
     */
    protected const PROVIDER_CLASSES_FALLBACK = [
        'wikitree'     => WikiTreeProvider::class,
        'findagrave'   => FindAGraveProvider::class,
        'billiongraves' => BillionGravesProvider::class,
        'ellis_island' => EllisIslandProvider::class,
        'blm_glo'      => BLMGLOProvider::class,
    ];

    private const UNSUPPORTED_PROVIDER_IDS = [
        'familysearch',
        'ancestry_dna',
    ];

    public function __construct()
    {
        $this->loadProviderClasses();
    }

    /**
     * Load provider classes from genealogy_research_providers table.
     * Falls back to hardcoded constants if table doesn't exist.
     */
    private function loadProviderClasses(): void
    {
        $myHeritageEnabled = $this->myHeritageAutomationEnabled();
        $cacheKey = 'genealogy_provider_classes:myheritage:'.($myHeritageEnabled ? 'on' : 'off');
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if ($cached !== null) {
            $this->providerClasses = $cached;
            return;
        }

        try {
            $rows = DB::select("
                SELECT provider_id, provider_class, is_active
                FROM genealogy_research_providers
                WHERE provider_class IS NOT NULL
                  AND (is_active = 1 OR (provider_id = 'myheritage' AND ? = 1))
                ORDER BY priority ASC
            ", [$myHeritageEnabled ? 1 : 0]);

            if (empty($rows)) {
                $this->providerClasses = $this->fallbackProviderClasses();
                return;
            }

            $classes = [];
            foreach ($rows as $row) {
                if (in_array($row->provider_id, self::UNSUPPORTED_PROVIDER_IDS, true)) {
                    continue;
                }

                if ($row->provider_id === 'myheritage' && ! $myHeritageEnabled) {
                    continue;
                }

                if (class_exists($row->provider_class)) {
                    $classes[$row->provider_id] = $row->provider_class;
                } else {
                    Log::warning("GenealogyProviderManager: Class not found for provider '{$row->provider_id}': {$row->provider_class}");
                }
            }

            $this->providerClasses = !empty($classes) ? $classes : $this->fallbackProviderClasses();
            \Illuminate\Support\Facades\Cache::put($cacheKey, $this->providerClasses, 300);

        } catch (\Exception $e) {
            // Table doesn't exist yet or other DB error
            Log::info('GenealogyProviderManager: Using hardcoded providers', ['reason' => $e->getMessage()]);
            $this->providerClasses = $this->fallbackProviderClasses();
        }
    }

    private function fallbackProviderClasses(): array
    {
        $providers = self::PROVIDER_CLASSES_FALLBACK;

        if ($this->myHeritageAutomationEnabled()) {
            $providers['myheritage'] = MyHeritageProvider::class;
        }

        return $providers;
    }

    private function myHeritageAutomationEnabled(): bool
    {
        return (bool) config('services.myheritage.personal_automation_enabled', false);
    }

    /**
     * Get all research providers from DB (including non-class-based ones)
     * Returns raw provider data for display/management purposes
     */
    public function getAllResearchProviders(): array
    {
        try {
            return DB::select("
                SELECT provider_id, provider_name, provider_type, base_url, auth_type,
                       capabilities, is_active, is_authenticated, priority, signup_url, notes,
                       last_used_at, last_error
                FROM genealogy_research_providers
                ORDER BY priority ASC
            ");
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Set tree context for all providers
     */
    public function setTreeContext(int $treeId): self
    {
        $this->treeId = $treeId;

        // Update context for already-loaded providers
        foreach ($this->providers as $provider) {
            if (method_exists($provider, 'setTreeContext')) {
                $provider->setTreeContext($treeId);
            }
        }

        return $this;
    }

    /**
     * Get a specific provider instance
     */
    public function getProvider(string $providerId): ?GenealogyProviderInterface
    {
        if (!isset($this->providerClasses[$providerId])) {
            Log::warning("GenealogyProviderManager: Unknown provider '{$providerId}'");
            return null;
        }

        if (!isset($this->providers[$providerId])) {
            $class = $this->providerClasses[$providerId];
            $provider = new $class();

            if ($this->treeId && method_exists($provider, 'setTreeContext')) {
                $provider->setTreeContext($this->treeId);
            }

            $this->providers[$providerId] = $provider;
        }

        return $this->providers[$providerId];
    }

    /**
     * Get all registered provider IDs
     */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->providerClasses);
    }

    /**
     * Get status of all providers
     */
    public function getProvidersStatus(): array
    {
        $status = [];

        foreach ($this->providerClasses as $id => $class) {
            $provider = $this->getProvider($id);

            $status[$id] = [
                'id' => $id,
                'name' => $provider->getProviderName(),
                'auth_type' => $provider->getAuthType(),
                'configured' => $provider->isConfigured(),
                'authenticated' => $provider->isAuthenticated(),
                'capabilities' => $provider->getCapabilities(),
            ];

            // Add provider-specific status if available
            if (method_exists($provider, 'getStatus')) {
                $status[$id]['provider_status'] = $provider->getStatus();
            }
        }

        return $status;
    }

    /**
     * Get only providers that are ready to use
     */
    public function getActiveProviders(): array
    {
        $active = [];

        foreach ($this->providerClasses as $id => $class) {
            $provider = $this->getProvider($id);

            if ($provider->isConfigured()) {
                $active[$id] = $provider;
            }
        }

        return $active;
    }

    /**
     * Get OAuth2 authorization URL for a provider
     */
    public function getAuthorizationUrl(string $providerId, ?string $state = null): ?string
    {
        $provider = $this->getProvider($providerId);

        if (!$provider) {
            return null;
        }

        if ($provider->getAuthType() !== 'oauth2') {
            Log::warning("Provider '{$providerId}' does not use OAuth2");
            return null;
        }

        return $provider->getAuthorizationUrl($state);
    }

    /**
     * Handle OAuth2 callback for a provider
     */
    public function handleOAuthCallback(string $providerId, string $code, ?string $state = null): bool
    {
        $provider = $this->getProvider($providerId);

        if (!$provider) {
            return false;
        }

        return $provider->handleOAuthCallback($code, $state);
    }

    /**
     * Search across all active providers
     */
    public function searchAllProviders(array $criteria, array $options = []): array
    {
        $results = [
            'query' => $criteria,
            'providers_searched' => 0,
            'total_results' => 0,
            'results' => [],
            'errors' => [],
        ];

        $providers = $options['providers'] ?? null;

        foreach ($this->getActiveProviders() as $id => $provider) {
            // Skip if specific providers requested and this isn't one
            if ($providers && !in_array($id, $providers)) {
                continue;
            }

            // Check if provider supports the search type
            $capabilities = $provider->getCapabilities();
            if (!($capabilities['search_persons'] ?? false) && !($capabilities['search_records'] ?? false)) {
                continue;
            }

            try {
                $providerResults = $provider->searchPersons($criteria, $options);

                $results['providers_searched']++;

                if ($providerResults['success'] ?? false) {
                    $results['results'][$id] = $providerResults['results'] ?? [];
                    $results['total_results'] += count($providerResults['results'] ?? []);
                } else {
                    $results['errors'][$id] = $providerResults['error'] ?? 'Unknown error';
                }

            } catch (\Exception $e) {
                $results['errors'][$id] = $e->getMessage();
                Log::error("GenealogyProviderManager: Error searching {$id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Search for records across all providers
     */
    public function searchRecordsAllProviders(array $criteria, array $options = []): array
    {
        $results = [
            'query' => $criteria,
            'providers_searched' => 0,
            'total_results' => 0,
            'results' => [],
            'errors' => [],
        ];

        foreach ($this->getActiveProviders() as $id => $provider) {
            $capabilities = $provider->getCapabilities();
            if (!($capabilities['search_records'] ?? false)) {
                continue;
            }

            try {
                $providerResults = $provider->searchRecords($criteria, $options);

                $results['providers_searched']++;

                if ($providerResults['success'] ?? false) {
                    $results['results'][$id] = $providerResults['results'] ?? [];
                    $results['total_results'] += count($providerResults['results'] ?? []);
                } else {
                    $results['errors'][$id] = $providerResults['error'] ?? 'Unknown error';
                }

            } catch (\Exception $e) {
                $results['errors'][$id] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Search for a specific person across providers
     */
    public function searchForPerson(array $personData, array $options = []): array
    {
        $criteria = [
            'given_name' => $personData['given_name'] ?? null,
            'surname' => $personData['surname'] ?? null,
            'birth_date' => $personData['birth_date'] ?? null,
            'birth_year' => $personData['birth_year'] ?? null,
            'birth_place' => $personData['birth_place'] ?? null,
            'death_date' => $personData['death_date'] ?? null,
            'death_year' => $personData['death_year'] ?? null,
            'death_place' => $personData['death_place'] ?? null,
        ];

        // Remove empty values
        $criteria = array_filter($criteria, fn($v) => $v !== null && $v !== '');

        return $this->searchAllProviders($criteria, $options);
    }

    /**
     * Get stored tokens for a tree (RAW SQL)
     */
    public function getStoredTokens(): array
    {
        if (!$this->treeId) {
            return [];
        }

        return DB::select("
            SELECT provider_id, external_user_id, external_username,
                   is_active, last_used_at, token_expires_at
            FROM genealogy_provider_tokens
            WHERE tree_id = ?
        ", [$this->treeId]);
    }

    /**
     * Remove provider authentication
     */
    public function disconnectProvider(string $providerId): bool
    {
        if (!$this->treeId) {
            return false;
        }

        return DB::delete("
            DELETE FROM genealogy_provider_tokens
            WHERE tree_id = ? AND provider_id = ?
        ", [$this->treeId, $providerId]) > 0;
    }

    /**
     * Get provider activity logs (RAW SQL)
     */
    public function getProviderLogs(string $providerId = null, int $limit = 100): array
    {
        $sql = "
            SELECT provider_id, action, success, error_message,
                   response_time_ms, rate_limit_remaining, created_at
            FROM genealogy_provider_logs
        ";
        $params = [];

        if ($providerId) {
            $sql .= " WHERE provider_id = ?";
            $params[] = $providerId;
        }

        if ($this->treeId) {
            $sql .= ($providerId ? " AND" : " WHERE") . " (tree_id = ? OR tree_id IS NULL)";
            $params[] = $this->treeId;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        return DB::select($sql, $params);
    }

    // -------------------------------------------------------------------------
    // WikiTree agent-callable methods
    // -------------------------------------------------------------------------

    /**
     * Search WikiTree profiles by name/dates — callable by agent tools.
     *
     * @param array $params Keys: given_name, surname, birth_year, birth_place, limit
     */
    public function searchWikiTree(array $params): array
    {
        /** @var WikiTreeProvider $provider */
        $provider = $this->getProvider('wikitree');
        if (!$provider) {
            return ['success' => false, 'error' => 'WikiTree provider not available', 'results' => []];
        }

        $criteria = array_filter([
            'given_name'  => $params['given_name']  ?? null,
            'surname'     => $params['surname']      ?? null,
            'birth_year'  => $params['birth_year']   ?? null,
            'birth_place' => $params['birth_place']  ?? null,
            'death_year'  => $params['death_year']   ?? null,
        ], fn($v) => $v !== null && $v !== '');

        return $provider->searchPersons($criteria, ['limit' => $params['limit'] ?? 20]);
    }

    /**
     * Get WikiTree ancestor tree up to N generations — callable by agent tools.
     *
     * @param array $params Keys: wikitree_id (e.g. "Smith-1"), depth (1-5, default 3)
     */
    public function getWikiTreeAncestors(array $params): array
    {
        /** @var WikiTreeProvider $provider */
        $provider = $this->getProvider('wikitree');
        if (!$provider) {
            return ['success' => false, 'error' => 'WikiTree provider not available', 'ancestors' => []];
        }

        $personId = $params['wikitree_id'] ?? $params['person_id'] ?? null;
        if (!$personId) {
            return ['success' => false, 'error' => 'wikitree_id is required', 'ancestors' => []];
        }

        return $provider->getAncestors((string)$personId, (int)($params['depth'] ?? 3));
    }

    /**
     * Get WikiTree profile and immediate family — callable by agent tools.
     *
     * @param array $params Keys: wikitree_id
     */
    public function getWikiTreePerson(array $params): array
    {
        /** @var WikiTreeProvider $provider */
        $provider = $this->getProvider('wikitree');
        if (!$provider) {
            return ['success' => false, 'error' => 'WikiTree provider not available'];
        }

        $personId = $params['wikitree_id'] ?? $params['person_id'] ?? null;
        if (!$personId) {
            return ['success' => false, 'error' => 'wikitree_id is required'];
        }

        $person = $provider->getPerson((string)$personId);
        if (!$person) {
            return ['success' => false, 'error' => 'Person not found on WikiTree: ' . $personId];
        }

        $family = $provider->getPersonFamily((string)$personId);

        return [
            'success' => true,
            'source'  => 'WikiTree',
            'person'  => $person,
            'family'  => $family,
        ];
    }

    /**
     * Register a custom provider class
     */
    public function registerProvider(string $id, string $className): self
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Provider class {$className} not found");
        }

        if (!in_array(GenealogyProviderInterface::class, class_implements($className))) {
            throw new \InvalidArgumentException("Provider class must implement GenealogyProviderInterface");
        }

        $this->providerClasses[$id] = $className;

        return $this;
    }

    /**
     * Get provider by capability
     */
    public function getProvidersByCapability(string $capability): array
    {
        $matching = [];

        foreach ($this->providerClasses as $id => $class) {
            $provider = $this->getProvider($id);
            $capabilities = $provider->getCapabilities();

            if ($capabilities[$capability] ?? false) {
                $matching[$id] = $provider;
            }
        }

        return $matching;
    }
}
