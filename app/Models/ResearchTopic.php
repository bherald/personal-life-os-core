<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchTopic extends Model
{
    /**
     * The connection name for the model.
     * Using PostgreSQL for better text handling and relational integrity
     */
    protected $connection = 'pgsql_rag';

    protected $table = 'research_topics';

    protected $fillable = [
        'description',
        'topic_content',
        'frequency',
        'last_ran_at',
        'is_active',
        'rag_category',
        // Advanced research settings
        'search_depth',
        'max_sources',
        'max_results_per_source',
        'date_filter_days',
        'preferred_categories',
        'excluded_domains',
        'require_recent_only',
    ];

    protected $casts = [
        'last_ran_at' => 'datetime',
        'is_active' => 'boolean',
        'search_depth' => 'integer',
        'max_sources' => 'integer',
        'max_results_per_source' => 'integer',
        'date_filter_days' => 'integer',
        'preferred_categories' => 'array',
        'excluded_domains' => 'array',
        'require_recent_only' => 'boolean',
    ];

    /**
     * Frequency options
     */
    const FREQUENCY_DAILY = 'daily';
    const FREQUENCY_WEEKLY = 'weekly';
    const FREQUENCY_MONTHLY = 'monthly';
    const FREQUENCY_QUARTERLY = 'quarterly';
    const FREQUENCY_BIANNUALLY = 'biannually';

    const FREQUENCIES = [
        self::FREQUENCY_DAILY => 'Daily',
        self::FREQUENCY_WEEKLY => 'Weekly',
        self::FREQUENCY_MONTHLY => 'Monthly',
        self::FREQUENCY_QUARTERLY => 'Quarterly',
        self::FREQUENCY_BIANNUALLY => 'Bi-annually',
    ];

    /**
     * Get research results for this topic
     */
    public function results(): HasMany
    {
        return $this->hasMany(ResearchResult::class);
    }

    /**
     * Get pending research results for this topic
     */
    public function pendingResults(): HasMany
    {
        return $this->hasMany(ResearchResult::class)->where('status', 'pending');
    }

    /**
     * Check if this topic is due for research based on frequency and last_ran_at
     */
    public function isDueForResearch(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!$this->last_ran_at) {
            return true; // Never ran, so it's due
        }

        $now = now();
        $lastRan = $this->last_ran_at;

        return match ($this->frequency) {
            self::FREQUENCY_DAILY => $lastRan->diffInDays($now) >= 1,
            self::FREQUENCY_WEEKLY => $lastRan->diffInWeeks($now) >= 1,
            self::FREQUENCY_MONTHLY => $lastRan->diffInMonths($now) >= 1,
            self::FREQUENCY_QUARTERLY => $lastRan->diffInMonths($now) >= 3,
            self::FREQUENCY_BIANNUALLY => $lastRan->diffInMonths($now) >= 6,
            default => false,
        };
    }

    /**
     * Get RAG category for this topic (auto-generate if not set)
     */
    public function getRagCategoryName(): string
    {
        if ($this->rag_category) {
            return $this->rag_category;
        }

        // Auto-generate from description
        return 'research_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->description));
    }

    /**
     * Scope: Active topics only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Topics due for research
     */
    public function scopeDueForResearch($query)
    {
        return $query->active()->where(function ($q) {
            $q->whereNull('last_ran_at')
              ->orWhere(function ($subQ) {
                  // Daily: last ran more than 1 day ago
                  $subQ->where('frequency', self::FREQUENCY_DAILY)
                       ->where('last_ran_at', '<', now()->subDay());
              })
              ->orWhere(function ($subQ) {
                  // Weekly: last ran more than 1 week ago
                  $subQ->where('frequency', self::FREQUENCY_WEEKLY)
                       ->where('last_ran_at', '<', now()->subWeek());
              })
              ->orWhere(function ($subQ) {
                  // Monthly: last ran more than 1 month ago
                  $subQ->where('frequency', self::FREQUENCY_MONTHLY)
                       ->where('last_ran_at', '<', now()->subMonth());
              })
              ->orWhere(function ($subQ) {
                  // Quarterly: last ran more than 3 months ago
                  $subQ->where('frequency', self::FREQUENCY_QUARTERLY)
                       ->where('last_ran_at', '<', now()->subMonths(3));
              })
              ->orWhere(function ($subQ) {
                  // Bi-annually: last ran more than 6 months ago
                  $subQ->where('frequency', self::FREQUENCY_BIANNUALLY)
                       ->where('last_ran_at', '<', now()->subMonths(6));
              });
        });
    }
}
