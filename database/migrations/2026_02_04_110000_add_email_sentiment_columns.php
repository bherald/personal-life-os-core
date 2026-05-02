<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add sentiment analysis columns to email_messages table
     *
     * Stores AI-analyzed sentiment data for emails:
     * - sentiment_score: -1.0 (negative) to 1.0 (positive)
     * - sentiment_label: categorical classification
     * - sentiment_confidence: model confidence in prediction
     * - key_phrases: extracted key phrases as JSON
     * - sentiment_analyzed_at: when analysis was performed
     */
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->decimal('sentiment_score', 4, 3)->nullable()->after('received_at');
            $table->enum('sentiment_label', ['positive', 'neutral', 'negative', 'urgent'])->nullable()->after('sentiment_score');
            $table->decimal('sentiment_confidence', 4, 3)->nullable()->after('sentiment_label');
            $table->json('key_phrases')->nullable()->after('sentiment_confidence');
            $table->timestamp('sentiment_analyzed_at')->nullable()->after('key_phrases');

            $table->index('sentiment_label');
            $table->index('sentiment_score');
            $table->index('sentiment_analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['sentiment_label']);
            $table->dropIndex(['sentiment_score']);
            $table->dropIndex(['sentiment_analyzed_at']);

            $table->dropColumn([
                'sentiment_score',
                'sentiment_label',
                'sentiment_confidence',
                'key_phrases',
                'sentiment_analyzed_at',
            ]);
        });
    }
};
