<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create sender_profiles table for email smart categorization
 *
 * Builds sender reputation profiles for auto-classification based on history.
 * Tracks domain patterns, email address patterns, and trust scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE sender_profiles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                domain VARCHAR(255) NOT NULL COMMENT 'Email domain (e.g., amazon.com)',
                email_pattern VARCHAR(255) NULL COMMENT 'Regex pattern for email addresses (e.g., noreply@.*)',
                display_name_pattern VARCHAR(255) NULL COMMENT 'Regex pattern for display names',
                category VARCHAR(50) NOT NULL DEFAULT 'other' COMMENT 'Auto-assigned category',
                subcategory VARCHAR(50) NULL COMMENT 'More specific categorization',
                trust_score DECIMAL(3,2) DEFAULT 0.50 COMMENT 'Trust score 0.00-1.00 (1.00 = fully trusted)',
                spam_score DECIMAL(3,2) DEFAULT 0.00 COMMENT 'Spam likelihood 0.00-1.00',
                interaction_count INT UNSIGNED DEFAULT 0 COMMENT 'Total emails from this sender',
                reply_count INT UNSIGNED DEFAULT 0 COMMENT 'Emails we replied to',
                open_count INT UNSIGNED DEFAULT 0 COMMENT 'Emails we opened/read',
                last_interaction_at TIMESTAMP NULL COMMENT 'Last email received from sender',
                typical_priority VARCHAR(20) DEFAULT 'normal' COMMENT 'Most common priority for this sender',
                typical_tags JSON NULL COMMENT 'Common tags associated with this sender',
                notes TEXT NULL COMMENT 'Human-added notes about sender',
                is_newsletter TINYINT(1) DEFAULT 0 COMMENT 'Known newsletter sender',
                is_transactional TINYINT(1) DEFAULT 0 COMMENT 'Known transactional sender (receipts, confirmations)',
                is_marketing TINYINT(1) DEFAULT 0 COMMENT 'Known marketing/promotional sender',
                is_personal TINYINT(1) DEFAULT 0 COMMENT 'Known personal contact',
                auto_category_override VARCHAR(50) NULL COMMENT 'Force this category regardless of AI classification',
                auto_priority_override VARCHAR(20) NULL COMMENT 'Force this priority regardless of AI classification',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_domain_pattern (domain, email_pattern),
                KEY idx_category (category),
                KEY idx_trust_score (trust_score),
                KEY idx_spam_score (spam_score),
                KEY idx_last_interaction (last_interaction_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed common sender profiles
        DB::insert("
            INSERT INTO sender_profiles
                (domain, email_pattern, category, subcategory, trust_score, is_transactional, is_newsletter, auto_category_override)
            VALUES
                ('amazon.com', 'auto-confirm@.*', 'receipts', 'orders', 0.95, 1, 0, 'receipts'),
                ('amazon.com', 'ship-confirm@.*', 'receipts', 'shipping', 0.95, 1, 0, 'receipts'),
                ('amazon.com', '.*@amazon.com', 'receipts', 'general', 0.85, 1, 0, NULL),
                ('paypal.com', 'service@.*', 'receipts', 'payments', 0.90, 1, 0, 'receipts'),
                ('google.com', 'noreply@.*', 'work', 'notifications', 0.85, 1, 0, NULL),
                ('github.com', 'notifications@.*', 'work', 'code', 0.90, 1, 0, 'work'),
                ('linkedin.com', '.*@linkedin.com', 'social', 'professional', 0.60, 0, 1, 'newsletter'),
                ('facebook.com', 'notification@.*', 'social', 'social', 0.50, 0, 1, 'social'),
                ('twitter.com', 'notify@.*', 'social', 'social', 0.50, 0, 1, 'social'),
                ('substack.com', '.*@substack.com', 'newsletter', 'subscriptions', 0.70, 0, 1, 'newsletter')
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS sender_profiles");
    }
};
