<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchResult extends Model
{
    /**
     * The connection name for the model.
     * Using PostgreSQL for better text handling and relational integrity
     */
    protected $connection = 'pgsql_rag';

    protected $table = 'research_results';

    protected $fillable = [
        'research_topic_id',
        'ai_output',
        'status',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_SKIPPED = 'skipped';

    const STATUSES = [
        self::STATUS_PENDING => 'Pending Review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_SKIPPED => 'Skipped',
    ];

    /**
     * Get the research topic this result belongs to
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(ResearchTopic::class, 'research_topic_id');
    }

    /**
     * Scope: Pending results only
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Mark as approved and set reviewed timestamp
     */
    public function markApproved(): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Mark as skipped and set reviewed timestamp
     */
    public function markSkipped(): bool
    {
        return $this->update([
            'status' => self::STATUS_SKIPPED,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Check if this result is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
