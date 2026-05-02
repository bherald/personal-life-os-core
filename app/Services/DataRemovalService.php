<?php

namespace App\Services;

use App\Services\CircuitBreaker;
use App\Services\RetryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Data Removal Service
 *
 * Main orchestrator for the Personal Data Removal System (E06).
 * Coordinates discovery, removal requests, follow-ups, and verification.
 *
 * Uses raw SQL with parameterized statements per project requirements.
 */
class DataRemovalService
{
    private CircuitBreaker $circuitBreaker;
    private RetryService $retryService;

    // Configuration defaults (overridable via .env)
    private int $followupIntervalDays;
    private int $recheckIntervalDays;
    private int $maxFollowups;
    private float $aiConfidenceThreshold;
    private float $autoSubmitThreshold;
    private int $throttleMs;
    private int $maxRequestsPerDay;

    public function __construct(CircuitBreaker $circuitBreaker, RetryService $retryService)
    {
        $this->circuitBreaker = $circuitBreaker;
        $this->retryService = $retryService;

        // Load configuration
        $this->followupIntervalDays = (int) config('data_removal.followup_interval_days', 7);
        $this->recheckIntervalDays = (int) config('data_removal.recheck_interval_days', 30);
        $this->maxFollowups = (int) config('data_removal.max_followups', 3);
        $this->aiConfidenceThreshold = (float) config('data_removal.ai_confidence_threshold', 75);
        $this->autoSubmitThreshold = (float) config('data_removal.auto_submit_threshold', 90);
        $this->throttleMs = (int) config('data_removal.throttle_ms', 3000);
        $this->maxRequestsPerDay = (int) config('data_removal.max_requests_per_day', 50);
    }

    // ========================================
    // SUBJECT MANAGEMENT
    // ========================================

    /**
     * Get all data subjects
     */
    public function getSubjects(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM data_subjects";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name ASC";

        return DB::select($sql);
    }

    /**
     * Get a single data subject by ID
     */
    public function getSubject(int $id): ?object
    {
        $sql = "SELECT * FROM data_subjects WHERE id = ? LIMIT 1";
        $results = DB::select($sql, [$id]);
        return $results[0] ?? null;
    }

    /**
     * Create a new data subject
     */
    public function createSubject(array $data): int
    {
        $sql = "INSERT INTO data_subjects
                (name, email, phone, address_line1, address_line2, city, state, zip,
                 date_of_birth, aliases, is_active, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        DB::insert($sql, [
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['address_line1'] ?? null,
            $data['address_line2'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['zip'] ?? null,
            $data['date_of_birth'] ?? null,
            isset($data['aliases']) ? json_encode($data['aliases']) : null,
            $data['is_active'] ?? true,
            $data['notes'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a data subject
     */
    public function updateSubject(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        foreach (['name', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'zip', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (array_key_exists('date_of_birth', $data)) {
            $fields[] = "date_of_birth = ?";
            $values[] = $data['date_of_birth'];
        }

        if (array_key_exists('aliases', $data)) {
            $fields[] = "aliases = ?";
            $values[] = json_encode($data['aliases']);
        }

        if (array_key_exists('is_active', $data)) {
            $fields[] = "is_active = ?";
            $values[] = $data['is_active'] ? 1 : 0;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $id;

        $sql = "UPDATE data_subjects SET " . implode(', ', $fields) . " WHERE id = ?";
        return DB::update($sql, $values) > 0;
    }

    /**
     * Delete a data subject
     */
    public function deleteSubject(int $id): bool
    {
        // First delete related requests
        // removal_activity_log cascade deleted (D2: table removed)
        DB::delete("DELETE FROM removal_requests WHERE subject_id = ?", [$id]);

        $sql = "DELETE FROM data_subjects WHERE id = ?";
        return DB::delete($sql, [$id]) > 0;
    }

    // ========================================
    // BROKER MANAGEMENT
    // ========================================

    /**
     * Get all data brokers
     */
    public function getBrokers(bool $activeOnly = true, ?string $category = null, ?int $tier = null): array
    {
        $sql = "SELECT * FROM data_brokers WHERE 1=1";
        $params = [];

        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }

        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        if ($tier !== null) {
            $sql .= " AND automation_tier = ?";
            $params[] = $tier;
        }

        $sql .= " ORDER BY name ASC";

        return DB::select($sql, $params);
    }

    /**
     * Get a single broker by ID
     */
    public function getBroker(int $id): ?object
    {
        $sql = "SELECT * FROM data_brokers WHERE id = ? LIMIT 1";
        $results = DB::select($sql, [$id]);
        return $results[0] ?? null;
    }

    /**
     * Get broker by domain (partial match)
     */
    public function getBrokerByDomain(string $domain): ?object
    {
        // Clean domain
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);

        // Try exact match first
        $sql = "SELECT * FROM data_brokers WHERE LOWER(domain) = ? LIMIT 1";
        $results = DB::select($sql, [$domain]);

        if (!empty($results)) {
            return $results[0];
        }

        // Try partial match (domain contains or is contained by)
        $sql = "SELECT * FROM data_brokers
                WHERE LOWER(domain) LIKE ? OR ? LIKE CONCAT('%', LOWER(domain), '%')
                ORDER BY LENGTH(domain) DESC
                LIMIT 1";
        $results = DB::select($sql, ["%{$domain}%", $domain]);

        return $results[0] ?? null;
    }

    /**
     * Create a new broker
     */
    public function createBroker(array $data): int
    {
        $sql = "INSERT INTO data_brokers
                (name, domain, category, removal_method, removal_url, removal_email,
                 required_fields, optional_fields,
                 automation_tier, requires_captcha, requires_auth, uses_javascript,
                 rate_limit_seconds, discovered_by, discovery_notes, is_active,
                 created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        // Default: minimal data principle - only name required
        $requiredFields = $data['required_fields'] ?? ['name'];
        $optionalFields = $data['optional_fields'] ?? ['city', 'state'];

        DB::insert($sql, [
            $data['name'],
            $data['domain'],
            $data['category'] ?? 'people_search',
            $data['removal_method'] ?? 'unknown',
            $data['removal_url'] ?? null,
            $data['removal_email'] ?? null,
            json_encode($requiredFields),
            json_encode($optionalFields),
            $data['automation_tier'] ?? 3,
            $data['requires_captcha'] ?? false,
            $data['requires_auth'] ?? false,
            $data['uses_javascript'] ?? true,
            $data['rate_limit_seconds'] ?? 60,
            $data['discovered_by'] ?? 'manual',
            $data['discovery_notes'] ?? null,
            $data['is_active'] ?? true,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a broker
     */
    public function updateBroker(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = [
            'name', 'domain', 'category', 'removal_method', 'removal_url',
            'removal_email', 'automation_tier', 'requires_captcha', 'requires_auth',
            'uses_javascript', 'rate_limit_seconds', 'discovery_notes', 'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        // Handle JSON fields separately
        if (array_key_exists('required_fields', $data)) {
            $fields[] = "required_fields = ?";
            $values[] = is_array($data['required_fields']) ? json_encode($data['required_fields']) : $data['required_fields'];
        }
        if (array_key_exists('optional_fields', $data)) {
            $fields[] = "optional_fields = ?";
            $values[] = is_array($data['optional_fields']) ? json_encode($data['optional_fields']) : $data['optional_fields'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $id;

        $sql = "UPDATE data_brokers SET " . implode(', ', $fields) . " WHERE id = ?";
        return DB::update($sql, $values) > 0;
    }

    /**
     * Delete a broker
     */
    public function deleteBroker(int $id): bool
    {
        // First delete related requests
        // removal_activity_log cascade deleted (D2: table removed)
        DB::delete("DELETE FROM removal_requests WHERE broker_id = ?", [$id]);

        $sql = "DELETE FROM data_brokers WHERE id = ?";
        return DB::delete($sql, [$id]) > 0;
    }

    /**
     * Update broker metrics after a removal attempt
     */
    public function updateBrokerMetrics(int $brokerId, bool $success, int $removalDays = 0): void
    {
        $sql = "UPDATE data_brokers SET
                    total_attempts = total_attempts + 1,
                    total_successes = total_successes + ?,
                    success_rate = ROUND((total_successes + ?) / (total_attempts + 1) * 100, 2),
                    avg_removal_days = CASE
                        WHEN total_successes > 0 THEN
                            ROUND((avg_removal_days * total_successes + ?) / (total_successes + ?), 0)
                        ELSE ?
                    END,
                    updated_at = NOW()
                WHERE id = ?";

        $successInt = $success ? 1 : 0;
        DB::update($sql, [$successInt, $successInt, $removalDays, $successInt, $removalDays, $brokerId]);
    }

    // ========================================
    // REMOVAL REQUEST MANAGEMENT
    // ========================================

    /**
     * Get removal requests with optional filtering
     */
    public function getRequests(array $filters = []): array
    {
        $sql = "SELECT r.*, s.name as subject_name, s.email as subject_email, s.phone as subject_phone,
                       s.address_line1 as subject_address, s.city as subject_city, s.state as subject_state,
                       s.zip as subject_zip, s.date_of_birth as subject_dob, s.aliases as subject_aliases,
                       b.name as broker_name, b.domain as broker_domain, b.removal_url as broker_removal_url,
                       b.required_fields as broker_required_fields, b.optional_fields as broker_optional_fields
                FROM removal_requests r
                JOIN data_subjects s ON r.subject_id = s.id
                JOIN data_brokers b ON r.broker_id = b.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['subject_id'])) {
            $sql .= " AND r.subject_id = ?";
            $params[] = $filters['subject_id'];
        }

        if (!empty($filters['broker_id'])) {
            $sql .= " AND r.broker_id = ?";
            $params[] = $filters['broker_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['tier'])) {
            $sql .= " AND r.automation_tier = ?";
            $params[] = $filters['tier'];
        }

        if (!empty($filters['requires_review'])) {
            $sql .= " AND r.requires_review = 1";
        }

        if (!empty($filters['broker_domain'])) {
            // Match domain exactly or as subdomain (e.g., www.example.com matches example.com)
            $domain = $filters['broker_domain'];
            // Remove common prefixes for matching
            $cleanDomain = preg_replace('/^(www\.|m\.|mobile\.)/i', '', $domain);
            $sql .= " AND (b.domain = ? OR b.domain = ? OR b.domain LIKE ?)";
            $params[] = $domain;
            $params[] = $cleanDomain;
            $params[] = '%.' . $cleanDomain;
        }

        $sql .= " ORDER BY r.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int) $filters['limit'];
        }

        return DB::select($sql, $params);
    }

    /**
     * Get a single request by ID
     */
    public function getRequest(int $id): ?object
    {
        $sql = "SELECT r.*, s.name as subject_name, s.email as subject_email, s.phone as subject_phone,
                       s.address_line1 as subject_address, s.city as subject_city, s.state as subject_state,
                       s.zip as subject_zip, s.date_of_birth as subject_dob, s.aliases as subject_aliases,
                       b.name as broker_name, b.domain as broker_domain
                FROM removal_requests r
                JOIN data_subjects s ON r.subject_id = s.id
                JOIN data_brokers b ON r.broker_id = b.id
                WHERE r.id = ? LIMIT 1";
        $results = DB::select($sql, [$id]);
        return $results[0] ?? null;
    }

    /**
     * Create a new removal request
     */
    public function createRequest(int $subjectId, int $brokerId, array $data = []): int
    {
        // Get broker's automation tier
        $broker = $this->getBroker($brokerId);
        $tier = $broker->automation_tier ?? 3;

        $sql = "INSERT INTO removal_requests
                (subject_id, broker_id, status, automation_tier, data_found, profile_url,
                 ai_confidence, ai_notes, requires_review, first_discovered_at,
                 max_followups, created_at, updated_at)
                VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())";

        $requiresReview = $tier >= 2 || ($data['ai_confidence'] ?? 0) < $this->aiConfidenceThreshold;

        DB::insert($sql, [
            $subjectId,
            $brokerId,
            $tier,
            isset($data['data_found']) ? json_encode($data['data_found']) : null,
            $data['profile_url'] ?? null,
            $data['ai_confidence'] ?? null,
            $data['ai_notes'] ?? null,
            $requiresReview ? 1 : 0,
            $this->maxFollowups,
        ]);

        $requestId = (int) DB::getPdo()->lastInsertId();

        // Log the discovery
        $this->logActivity($requestId, 'discovered', 'Data found on broker', [
            'subject_id' => $subjectId,
            'broker_id' => $brokerId,
            'tier' => $tier,
        ]);

        return $requestId;
    }

    /**
     * Update request status
     */
    public function updateRequestStatus(int $id, string $status, ?string $error = null): bool
    {
        $sql = "UPDATE removal_requests SET status = ?, last_error = ?, updated_at = NOW()";
        $params = [$status, $error];

        // Update timestamps based on status
        switch ($status) {
            case 'submitted':
                $sql .= ", submitted_at = NOW()";
                break;
            case 'confirmed':
                $sql .= ", confirmed_at = NOW()";
                break;
            case 'verified_removed':
                $sql .= ", verified_removed_at = NOW()";
                break;
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;

        $result = DB::update($sql, $params) > 0;

        if ($result) {
            $this->logActivity($id, $status === 'failed' ? 'failed' : 'verified', "Status changed to: {$status}", [
                'new_status' => $status,
                'error' => $error,
            ]);
        }

        return $result;
    }

    /**
     * Get requests needing follow-up
     */
    public function getRequestsNeedingFollowup(): array
    {
        $sql = "SELECT r.*, s.name as subject_name, s.email as subject_email, s.phone as subject_phone,
                       s.address_line1 as subject_address, s.city as subject_city, s.state as subject_state,
                       s.zip as subject_zip, s.date_of_birth as subject_dob, s.aliases as subject_aliases,
                       b.name as broker_name, b.domain as broker_domain
                FROM removal_requests r
                JOIN data_subjects s ON r.subject_id = s.id
                JOIN data_brokers b ON r.broker_id = b.id
                WHERE r.status IN ('submitted', 'awaiting_confirmation')
                AND r.next_followup_at <= NOW()
                AND r.followup_count < r.max_followups
                ORDER BY r.next_followup_at ASC";

        return DB::select($sql);
    }

    /**
     * Get requests needing verification/recheck
     */
    public function getRequestsNeedingRecheck(): array
    {
        $sql = "SELECT r.*, s.name as subject_name, s.email as subject_email, s.phone as subject_phone,
                       s.address_line1 as subject_address, s.city as subject_city, s.state as subject_state,
                       s.zip as subject_zip, s.date_of_birth as subject_dob, s.aliases as subject_aliases,
                       b.name as broker_name, b.domain as broker_domain
                FROM removal_requests r
                JOIN data_subjects s ON r.subject_id = s.id
                JOIN data_brokers b ON r.broker_id = b.id
                WHERE r.status = 'verified_removed'
                AND r.recheck_at <= NOW()
                ORDER BY r.recheck_at ASC";

        return DB::select($sql);
    }

    /**
     * Schedule follow-up for a request
     */
    public function scheduleFollowup(int $id): void
    {
        $followupAt = Carbon::now()->addDays($this->followupIntervalDays);

        $sql = "UPDATE removal_requests SET
                    next_followup_at = ?,
                    followup_count = followup_count + 1,
                    updated_at = NOW()
                WHERE id = ?";

        DB::update($sql, [$followupAt, $id]);
    }

    /**
     * Schedule recheck for a request
     */
    public function scheduleRecheck(int $id): void
    {
        $recheckAt = Carbon::now()->addDays($this->recheckIntervalDays);

        $sql = "UPDATE removal_requests SET
                    recheck_at = ?,
                    updated_at = NOW()
                WHERE id = ?";

        DB::update($sql, [$recheckAt, $id]);
    }

    /**
     * Mark request as requiring review
     */
    public function markForReview(int $id, ?string $notes = null): void
    {
        $sql = "UPDATE removal_requests SET
                    requires_review = 1,
                    ai_notes = CONCAT(COALESCE(ai_notes, ''), '\n', ?),
                    updated_at = NOW()
                WHERE id = ?";

        DB::update($sql, [$notes ?? 'Marked for manual review', $id]);

        $this->logActivity($id, 'ai_decision', 'Marked for manual review', ['notes' => $notes]);
    }

    /**
     * Complete human review of a request
     */
    public function completeReview(int $id, string $reviewedBy, string $action, ?string $notes = null): bool
    {
        $sql = "UPDATE removal_requests SET
                    requires_review = 0,
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_notes = ?,
                    updated_at = NOW()
                WHERE id = ?";

        $result = DB::update($sql, [$reviewedBy, $notes, $id]) > 0;

        if ($result) {
            $this->logActivity($id, 'manual_action', "Review completed: {$action}", [
                'reviewed_by' => $reviewedBy,
                'action' => $action,
                'notes' => $notes,
            ]);
        }

        return $result;
    }

    // ========================================
    // ACTIVITY LOGGING
    // ========================================

    /**
     * Log an activity for a request
     */
    public function logActivity(int $requestId, string $type, ?string $description = null, ?array $metadata = null, string $triggeredBy = 'workflow'): int
    {
        // Activity logging table removed (D2). Log to laravel.log instead.
        Log::info('DataRemoval: Activity', [
            'request_id' => $requestId,
            'type' => $type,
            'description' => $description,
            'triggered_by' => $triggeredBy,
        ]);
        return 0;
    }

    /**
     * Get activity log for a request (table removed per D2)
     */
    public function getActivityLog(int $requestId): array
    {
        return [];
    }

    // ========================================
    // STATISTICS & DASHBOARD
    // ========================================

    /**
     * Get overall statistics
     */
    public function getStats(): array
    {
        // Subject stats
        $subjectStats = DB::select("SELECT
            COUNT(*) as total_subjects,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_subjects
            FROM data_subjects")[0];

        // Broker stats
        $brokerStats = DB::select("SELECT
            COUNT(*) as total_brokers,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_brokers,
            SUM(CASE WHEN automation_tier = 1 THEN 1 ELSE 0 END) as tier1_brokers,
            SUM(CASE WHEN automation_tier = 2 THEN 1 ELSE 0 END) as tier2_brokers,
            SUM(CASE WHEN automation_tier = 3 THEN 1 ELSE 0 END) as tier3_brokers
            FROM data_brokers")[0];

        // Request stats
        $requestStats = DB::select("SELECT
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'awaiting_confirmation' THEN 1 ELSE 0 END) as awaiting_confirmation,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN status = 'verified_removed' THEN 1 ELSE 0 END) as verified_removed,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'reappeared' THEN 1 ELSE 0 END) as reappeared,
            SUM(CASE WHEN requires_review = 1 THEN 1 ELSE 0 END) as pending_review
            FROM removal_requests")[0];

        // Calculate success rate
        $totalCompleted = $requestStats->verified_removed + $requestStats->failed;
        $successRate = $totalCompleted > 0
            ? round(($requestStats->verified_removed / $totalCompleted) * 100, 1)
            : 0;

        // Activity log table removed per D2
        $recentActivity = [];

        return [
            'subjects' => [
                'total' => (int) $subjectStats->total_subjects,
                'active' => (int) $subjectStats->active_subjects,
            ],
            'brokers' => [
                'total' => (int) $brokerStats->total_brokers,
                'active' => (int) $brokerStats->active_brokers,
                'by_tier' => [
                    'tier1' => (int) $brokerStats->tier1_brokers,
                    'tier2' => (int) $brokerStats->tier2_brokers,
                    'tier3' => (int) $brokerStats->tier3_brokers,
                ],
            ],
            'requests' => [
                'total' => (int) $requestStats->total_requests,
                'by_status' => [
                    'pending' => (int) $requestStats->pending,
                    'submitted' => (int) $requestStats->submitted,
                    'awaiting_confirmation' => (int) $requestStats->awaiting_confirmation,
                    'confirmed' => (int) $requestStats->confirmed,
                    'verified_removed' => (int) $requestStats->verified_removed,
                    'failed' => (int) $requestStats->failed,
                    'reappeared' => (int) $requestStats->reappeared,
                ],
                'pending_review' => (int) $requestStats->pending_review,
                'success_rate' => $successRate,
            ],
            'recent_activity' => $recentActivity,
        ];
    }

    /**
     * Get dashboard data
     */
    public function getDashboardData(): array
    {
        $stats = $this->getStats();

        // Get recent requests
        $recentRequests = $this->getRequests(['limit' => 10]);

        // Get pending review items
        $pendingReview = $this->getRequests(['requires_review' => true, 'limit' => 5]);

        // Get upcoming follow-ups
        $upcomingFollowups = DB::select("SELECT r.*, s.name as subject_name, s.email as subject_email,
            s.phone as subject_phone, s.address_line1 as subject_address, s.city as subject_city,
            s.state as subject_state, s.zip as subject_zip, s.date_of_birth as subject_dob,
            s.aliases as subject_aliases, b.name as broker_name
            FROM removal_requests r
            JOIN data_subjects s ON r.subject_id = s.id
            JOIN data_brokers b ON r.broker_id = b.id
            WHERE r.next_followup_at IS NOT NULL
            AND r.followup_count < r.max_followups
            ORDER BY r.next_followup_at ASC
            LIMIT 5");

        return [
            'stats' => $stats,
            'recent_requests' => $recentRequests,
            'pending_review' => $pendingReview,
            'upcoming_followups' => $upcomingFollowups,
        ];
    }

    // ========================================
    // TIER ROUTING
    // ========================================

    /**
     * Route a request to the appropriate tier
     */
    public function routeToTier(int $requestId): int
    {
        $request = $this->getRequest($requestId);
        if (!$request) {
            throw new \Exception("Request not found: {$requestId}");
        }

        $broker = $this->getBroker($request->broker_id);
        $tier = $broker->automation_tier;

        // Adjust tier based on AI confidence
        if ($request->ai_confidence !== null) {
            if ($request->ai_confidence >= $this->autoSubmitThreshold && $tier > 1) {
                $tier = 1; // Promote to auto if high confidence
            } elseif ($request->ai_confidence < $this->aiConfidenceThreshold && $tier < 3) {
                $tier = 3; // Demote to manual if low confidence
            }
        }

        // Update the request's tier if changed
        if ($tier !== $request->automation_tier) {
            DB::update("UPDATE removal_requests SET automation_tier = ?, updated_at = NOW() WHERE id = ?", [$tier, $requestId]);
            $this->logActivity($requestId, 'ai_decision', "Tier adjusted to {$tier}", [
                'original_tier' => $request->automation_tier,
                'new_tier' => $tier,
                'ai_confidence' => $request->ai_confidence,
            ]);
        }

        return $tier;
    }

    /**
     * Check if today's request limit has been reached
     */
    public function isRateLimited(): bool
    {
        $sql = "SELECT COUNT(*) as count FROM removal_requests
                WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        $result = DB::select($sql)[0];
        return $result->count >= $this->maxRequestsPerDay;
    }

    /**
     * Get throttle delay for a broker
     */
    public function getThrottleDelay(int $brokerId): int
    {
        $broker = $this->getBroker($brokerId);
        return max($this->throttleMs, ($broker->rate_limit_seconds ?? 60) * 1000);
    }

    // ========================================
    // WORKFLOW NODE SUPPORT METHODS
    // ========================================

    /**
     * Get request by subject and broker combination
     */
    public function getRequestBySubjectAndBroker(int $subjectId, int $brokerId): ?object
    {
        $sql = "SELECT * FROM removal_requests
                WHERE subject_id = ? AND broker_id = ?
                LIMIT 1";

        $result = DB::select($sql, [$subjectId, $brokerId]);
        return $result[0] ?? null;
    }

    /**
     * Get requests ready for submission
     */
    public function getRequestsForSubmission(int $limit = 10, ?int $tierFilter = null, bool $skipNeedsReview = true): array
    {
        $sql = "SELECT r.*, s.name as subject_name, s.email as subject_email, s.phone as subject_phone,
                       s.address_line1 as subject_address, s.city as subject_city, s.state as subject_state,
                       s.zip as subject_zip, s.date_of_birth as subject_dob, s.aliases as subject_aliases,
                       b.name as broker_name, b.domain as broker_domain
                FROM removal_requests r
                JOIN data_subjects s ON r.subject_id = s.id
                JOIN data_brokers b ON r.broker_id = b.id
                WHERE r.status = 'pending'";

        $params = [];

        if ($tierFilter !== null) {
            $sql .= " AND r.automation_tier = ?";
            $params[] = $tierFilter;
        }

        if ($skipNeedsReview) {
            $sql .= " AND r.requires_review = 0";
        }

        $sql .= " ORDER BY r.automation_tier ASC, r.created_at ASC LIMIT ?";
        $params[] = $limit;

        return DB::select($sql, $params);
    }

    /**
     * Get requests ready for verification
     */
    public function getRequestsForVerification(int $daysAfterSubmission = 7, int $limit = 10): array
    {
        $sql = "SELECT r.*, s.name as subject_name, s.email as subject_email, s.phone as subject_phone,
                       s.address_line1 as subject_address, s.city as subject_city, s.state as subject_state,
                       s.zip as subject_zip, s.date_of_birth as subject_dob, s.aliases as subject_aliases,
                       b.name as broker_name, b.domain as broker_domain
                FROM removal_requests r
                JOIN data_subjects s ON r.subject_id = s.id
                JOIN data_brokers b ON r.broker_id = b.id
                WHERE r.status IN ('submitted', 'confirmed')
                AND r.submitted_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND (r.recheck_at IS NULL OR r.recheck_at <= NOW())
                ORDER BY r.submitted_at ASC
                LIMIT ?";

        return DB::select($sql, [$daysAfterSubmission, $limit]);
    }

    /**
     * Get requests due for follow-up
     */
    public function getRequestsForFollowup(int $limit = 10): array
    {
        $sql = "SELECT r.*, s.name as subject_name, s.email as subject_email, s.phone as subject_phone,
                       s.address_line1 as subject_address, s.city as subject_city, s.state as subject_state,
                       s.zip as subject_zip, s.date_of_birth as subject_dob, s.aliases as subject_aliases,
                       b.name as broker_name, b.domain as broker_domain
                FROM removal_requests r
                JOIN data_subjects s ON r.subject_id = s.id
                JOIN data_brokers b ON r.broker_id = b.id
                WHERE r.status IN ('submitted', 'awaiting_confirmation')
                AND r.next_followup_at <= NOW()
                AND r.followup_count < r.max_followups
                ORDER BY r.next_followup_at ASC
                LIMIT ?";

        return DB::select($sql, [$limit]);
    }

    /**
     * Update request with arbitrary fields
     */
    public function updateRequest(int $id, array $data): bool
    {
        $allowedFields = [
            'status', 'submission_method', 'confirmation_code', 'confirmation_email_id',
            'data_found', 'profile_url', 'screenshot_path', 'first_discovered_at',
            'submitted_at', 'confirmed_at', 'verified_removed_at', 'next_followup_at',
            'recheck_at', 'followup_count', 'max_followups', 'last_error', 'error_count',
            'ai_confidence', 'ai_notes', 'requires_review', 'reviewed_by', 'reviewed_at',
            'review_notes', 'automation_tier', 'fields_to_submit', 'fields_submitted'
        ];

        $setClauses = [];
        $params = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $setClauses[] = "{$field} = ?";
                $params[] = is_array($value) ? json_encode($value) : $value;
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $setClauses[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE removal_requests SET " . implode(', ', $setClauses) . " WHERE id = ?";

        return DB::update($sql, $params) > 0;
    }

    /**
     * Get the fields that should be submitted for a request
     * Priority: user selection > broker required + optional fields > minimal (name only)
     */
    public function getFieldsToSubmit(int $requestId): array
    {
        $request = $this->getRequest($requestId);
        if (!$request) {
            return ['name'];
        }

        // If user has explicitly selected fields, use those
        if (!empty($request->fields_to_submit)) {
            $userFields = json_decode($request->fields_to_submit, true);
            if (is_array($userFields) && !empty($userFields)) {
                return $userFields;
            }
        }

        // Otherwise, use broker's required + optional fields
        $broker = $this->getBroker($request->broker_id);
        if ($broker) {
            $required = json_decode($broker->required_fields ?? '["name"]', true) ?? ['name'];
            $optional = json_decode($broker->optional_fields ?? '[]', true) ?? [];
            return array_unique(array_merge($required, $optional));
        }

        // Fallback: minimal data principle
        return ['name'];
    }

    /**
     * Get available fields for a subject (fields that have data)
     */
    public function getAvailableSubjectFields(int $subjectId): array
    {
        $subject = $this->getSubject($subjectId);
        if (!$subject) {
            return [];
        }

        $available = [];
        $fieldMap = [
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address_line1',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip',
            'dob' => 'date_of_birth',
            'aliases' => 'aliases',
        ];

        foreach ($fieldMap as $fieldName => $dbColumn) {
            if (!empty($subject->$dbColumn)) {
                $available[] = $fieldName;
            }
        }

        return $available;
    }

    /**
     * Get subject data filtered to only specified fields
     */
    public function getFilteredSubjectData(object $subject, array $fields): array
    {
        $fieldMap = [
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address_line1',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip',
            'dob' => 'date_of_birth',
            'aliases' => 'aliases',
        ];

        $filtered = [];
        foreach ($fields as $field) {
            if (isset($fieldMap[$field]) && !empty($subject->{$fieldMap[$field]})) {
                $filtered[$field] = $subject->{$fieldMap[$field]};
            }
        }

        return $filtered;
    }

    /**
     * Set user-selected fields for a request
     */
    public function setRequestFieldsToSubmit(int $requestId, array $fields): bool
    {
        return $this->updateRequest($requestId, [
            'fields_to_submit' => $fields,
        ]);
    }
}
