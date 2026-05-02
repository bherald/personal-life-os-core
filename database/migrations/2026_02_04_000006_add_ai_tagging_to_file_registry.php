<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI Auto-Tagging columns for file_registry
     *
     * Stores AI-generated tags, descriptions, document classification,
     * and OCR text for files. Enables semantic search and auto-organization.
     */
    public function up(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            // AI-generated tags with confidence scores
            // Format: [{"tag": "receipt", "confidence": 0.95}, {"tag": "financial", "confidence": 0.87}]
            $table->json('ai_tags')->nullable()->after('tags')
                  ->comment('AI-detected tags with confidence scores');

            // AI-generated description of file contents
            $table->text('ai_description')->nullable()->after('ai_tags')
                  ->comment('AI-generated description of file contents');

            // Document type classification
            $table->string('ai_document_type', 50)->nullable()->after('ai_description')
                  ->comment('AI classification: invoice, receipt, letter, photo, contract, etc.');

            // OCR/extracted text from images and scanned documents
            $table->text('ai_detected_text')->nullable()->after('ai_document_type')
                  ->comment('OCR results and text extracted from images/documents');

            // Tracking timestamps and version
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_detected_text')
                  ->comment('When AI analysis was last performed');

            $table->string('ai_analysis_version', 20)->nullable()->after('ai_analyzed_at')
                  ->comment('Model/pipeline version used for analysis');

            // Indexes for search
            $table->index('ai_document_type');
            $table->index('ai_analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            $table->dropIndex(['ai_document_type']);
            $table->dropIndex(['ai_analyzed_at']);

            $table->dropColumn([
                'ai_tags',
                'ai_description',
                'ai_document_type',
                'ai_detected_text',
                'ai_analyzed_at',
                'ai_analysis_version',
            ]);
        });
    }
};
