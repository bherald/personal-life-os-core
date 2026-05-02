<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sender Profile Service
 *
 * Manages sender reputation profiles for email auto-classification.
 * Tracks sender history, trust scores, and provides category overrides.
 * Uses direct SQL queries to keep sender-profile reads predictable.
 */
class SenderProfileService
{
    private const DEFAULT_TRUST_SCORE = 0.50;

    private const DEFAULT_SPAM_SCORE = 0.00;

    /**
     * Get sender profile for an email address
     *
     * @param  string  $email  Full email address
     * @return object|null Profile if found
     */
    public function getProfileForEmail(string $email): ?object
    {
        $domain = $this->extractDomain($email);
        if (! $domain) {
            return null;
        }

        // First try exact email pattern match
        $profiles = DB::select(
            'SELECT * FROM sender_profiles WHERE domain = ? ORDER BY email_pattern IS NULL, id',
            [$domain]
        );

        foreach ($profiles as $profile) {
            if ($profile->email_pattern === null) {
                // Domain-only match (fallback)
                return $profile;
            }

            // Check regex pattern (with timeout guard against ReDoS)
            if (@preg_match('/'.$profile->email_pattern.'/i', $email) === 1) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * Get classification overrides for a sender
     *
     * @param  string  $email  Sender email address
     * @return array ['category' => string|null, 'priority' => string|null, 'trust_score' => float]
     */
    public function getClassificationOverrides(string $email): array
    {
        $profile = $this->getProfileForEmail($email);

        if (! $profile) {
            return [
                'category' => null,
                'priority' => null,
                'trust_score' => self::DEFAULT_TRUST_SCORE,
                'spam_score' => self::DEFAULT_SPAM_SCORE,
                'typical_tags' => [],
                'is_newsletter' => false,
                'is_transactional' => false,
                'is_marketing' => false,
                'profile_id' => null,
            ];
        }

        return [
            'category' => $profile->auto_category_override,
            'priority' => $profile->auto_priority_override,
            'trust_score' => (float) $profile->trust_score,
            'spam_score' => (float) $profile->spam_score,
            'typical_tags' => json_decode($profile->typical_tags ?? '[]', true),
            'is_newsletter' => (bool) $profile->is_newsletter,
            'is_transactional' => (bool) $profile->is_transactional,
            'is_marketing' => (bool) $profile->is_marketing,
            'profile_id' => $profile->id,
        ];
    }

    /**
     * Record an interaction with a sender (email received)
     *
     * @param  string  $email  Sender email address
     * @param  array  $options  ['opened' => bool, 'replied' => bool, 'category' => string, 'tags' => array]
     */
    public function recordInteraction(string $email, array $options = []): void
    {
        $domain = $this->extractDomain($email);
        if (! $domain) {
            return;
        }

        $profile = $this->getProfileForEmail($email);

        if ($profile) {
            // Update existing profile
            $updates = ['interaction_count = interaction_count + 1', 'last_interaction_at = ?'];
            $params = [now()];

            if (! empty($options['opened'])) {
                $updates[] = 'open_count = open_count + 1';
            }

            if (! empty($options['replied'])) {
                $updates[] = 'reply_count = reply_count + 1';
                // Increase trust score when we reply (indicates engagement)
                $newTrust = min(1.0, $profile->trust_score + 0.02);
                $updates[] = 'trust_score = ?';
                $params[] = $newTrust;
            }

            $params[] = $profile->id;

            DB::update(
                'UPDATE sender_profiles SET '.implode(', ', $updates).', updated_at = NOW() WHERE id = ?',
                $params
            );
        } else {
            // Create new profile for unknown sender
            $this->createProfile($email, [
                'category' => $options['category'] ?? 'other',
                'trust_score' => self::DEFAULT_TRUST_SCORE,
            ]);
        }
    }

    /**
     * Create a new sender profile
     *
     * @param  string  $email  Email address or pattern
     * @param  array  $data  Profile data
     * @return int Profile ID
     */
    public function createProfile(string $email, array $data = []): int
    {
        $domain = $this->extractDomain($email);
        if (! $domain) {
            throw new \InvalidArgumentException("Invalid email address: $email");
        }

        // Determine if this is a pattern or specific address
        $emailPattern = null;
        if (strpos($email, '*') !== false || strpos($email, '@') !== false) {
            // If it's a full email, don't set pattern (domain-level)
            // If it has wildcards, it's a pattern
            if (strpos($email, '*') !== false) {
                $emailPattern = str_replace('*', '.*', preg_quote($email, '/'));
            }
        }

        DB::insert(
            'INSERT INTO sender_profiles (domain, email_pattern, category, subcategory, trust_score, spam_score,
             is_newsletter, is_transactional, is_marketing, is_personal, interaction_count, last_interaction_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $domain,
                $emailPattern,
                $data['category'] ?? 'other',
                $data['subcategory'] ?? null,
                $data['trust_score'] ?? self::DEFAULT_TRUST_SCORE,
                $data['spam_score'] ?? self::DEFAULT_SPAM_SCORE,
                $data['is_newsletter'] ?? 0,
                $data['is_transactional'] ?? 0,
                $data['is_marketing'] ?? 0,
                $data['is_personal'] ?? 0,
                1,
                now(),
                $data['notes'] ?? null,
            ]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update sender trust score
     *
     * @param  int  $profileId  Profile ID
     * @param  float  $delta  Amount to adjust (-1.0 to 1.0)
     * @param  string|null  $reason  Reason for adjustment
     */
    public function adjustTrustScore(int $profileId, float $delta, ?string $reason = null): void
    {
        $profile = DB::selectOne('SELECT trust_score FROM sender_profiles WHERE id = ?', [$profileId]);
        if (! $profile) {
            return;
        }

        $newScore = max(0.0, min(1.0, $profile->trust_score + $delta));

        DB::update(
            'UPDATE sender_profiles SET trust_score = ?, updated_at = NOW() WHERE id = ?',
            [$newScore, $profileId]
        );

        if ($reason) {
            Log::info('Adjusted sender trust score', [
                'profile_id' => $profileId,
                'old_score' => $profile->trust_score,
                'new_score' => $newScore,
                'delta' => $delta,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Mark sender as spam
     *
     * @param  string  $email  Sender email address
     */
    public function markAsSpam(string $email): void
    {
        $profile = $this->getProfileForEmail($email);

        if ($profile) {
            DB::update(
                "UPDATE sender_profiles SET spam_score = 1.0, trust_score = 0.0,
                 auto_category_override = 'spam', updated_at = NOW() WHERE id = ?",
                [$profile->id]
            );
        } else {
            $this->createProfile($email, [
                'category' => 'spam',
                'trust_score' => 0.0,
                'spam_score' => 1.0,
            ]);
        }
    }

    /**
     * Mark sender as trusted
     *
     * @param  string  $email  Sender email address
     * @param  string|null  $category  Category to assign
     */
    public function markAsTrusted(string $email, ?string $category = null): void
    {
        $profile = $this->getProfileForEmail($email);

        if ($profile) {
            $updates = ['trust_score = 1.0', 'spam_score = 0.0', 'updated_at = NOW()'];
            $params = [];

            if ($category) {
                $updates[] = 'auto_category_override = ?';
                $params[] = $category;
            }

            $params[] = $profile->id;

            DB::update(
                'UPDATE sender_profiles SET '.implode(', ', $updates).' WHERE id = ?',
                $params
            );
        } else {
            $this->createProfile($email, [
                'category' => $category ?? 'other',
                'trust_score' => 1.0,
                'spam_score' => 0.0,
            ]);
        }
    }

    /**
     * Get profiles by category
     *
     * @param  string  $category  Category name
     * @param  int  $limit  Max results
     * @return array Profiles
     */
    public function getProfilesByCategory(string $category, int $limit = 50): array
    {
        return DB::select(
            'SELECT * FROM sender_profiles WHERE category = ? ORDER BY interaction_count DESC LIMIT ?',
            [$category, $limit]
        );
    }

    /**
     * Get high-risk senders (spam score > 0.7)
     *
     * @param  int  $limit  Max results
     * @return array Profiles
     */
    public function getHighRiskSenders(int $limit = 50): array
    {
        return DB::select(
            'SELECT * FROM sender_profiles WHERE spam_score > 0.7 ORDER BY spam_score DESC LIMIT ?',
            [$limit]
        );
    }

    /**
     * Get statistics about sender profiles
     *
     * @return array Stats
     */
    public function getStats(): array
    {
        $total = DB::scalar('SELECT COUNT(*) FROM sender_profiles');
        $byCategory = DB::select(
            'SELECT category, COUNT(*) as count FROM sender_profiles GROUP BY category ORDER BY count DESC'
        );
        $avgTrust = DB::scalar('SELECT AVG(trust_score) FROM sender_profiles');
        $highRisk = DB::scalar('SELECT COUNT(*) FROM sender_profiles WHERE spam_score > 0.7');
        $newsletters = DB::scalar('SELECT COUNT(*) FROM sender_profiles WHERE is_newsletter = 1');
        $transactional = DB::scalar('SELECT COUNT(*) FROM sender_profiles WHERE is_transactional = 1');

        return [
            'total_profiles' => $total,
            'by_category' => array_column($byCategory, 'count', 'category'),
            'average_trust_score' => round($avgTrust, 2),
            'high_risk_count' => $highRisk,
            'newsletter_count' => $newsletters,
            'transactional_count' => $transactional,
        ];
    }

    /**
     * Extract domain from email address
     *
     * @param  string  $email  Email address
     * @return string|null Domain
     */
    private function extractDomain(string $email): ?string
    {
        // Handle "Name <email@domain.com>" format
        if (preg_match('/<([^>]+)>/', $email, $matches)) {
            $email = $matches[1];
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return null;
        }

        return strtolower(trim($parts[1]));
    }
}
