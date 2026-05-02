<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE email_rate_limits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                mailbox VARCHAR(255) NOT NULL COMMENT 'Email address/mailbox identifier',
                daily_count INT UNSIGNED DEFAULT 0 COMMENT 'Emails sent today',
                hourly_count INT UNSIGNED DEFAULT 0 COMMENT 'Emails sent this hour',
                daily_reset_at TIMESTAMP NULL COMMENT 'When daily counter resets',
                hourly_reset_at TIMESTAMP NULL COMMENT 'When hourly counter resets',
                last_sent_at TIMESTAMP NULL COMMENT 'Last email sent timestamp',
                domain_throttles JSON COMMENT 'Per-domain delay settings {domain: {delay_ms: int, reason: string, until: timestamp}}',
                cooldown_until TIMESTAMP NULL COMMENT 'Cooldown period end time (no sends allowed until then)',
                cooldown_reason VARCHAR(255) NULL COMMENT 'Why cooldown was triggered',
                metadata JSON COMMENT 'Additional tracking data',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_mailbox (mailbox),
                KEY idx_cooldown_until (cooldown_until),
                KEY idx_daily_reset (daily_reset_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Domain throttle configuration table for persistent domain rules
        DB::statement("
            CREATE TABLE email_domain_throttles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                domain VARCHAR(255) NOT NULL COMMENT 'Email domain (e.g., gmail.com)',
                delay_ms INT UNSIGNED DEFAULT 0 COMMENT 'Delay in milliseconds between sends',
                max_per_hour INT UNSIGNED NULL COMMENT 'Max sends per hour to this domain',
                max_per_day INT UNSIGNED NULL COMMENT 'Max sends per day to this domain',
                reason VARCHAR(255) NULL COMMENT 'Reason for throttle (bounce_rate, manual, etc)',
                is_active TINYINT(1) DEFAULT 1,
                expires_at TIMESTAMP NULL COMMENT 'When throttle expires (NULL = permanent)',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_domain (domain),
                KEY idx_active_expires (is_active, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert default throttles for known problematic domains
        DB::insert("
            INSERT INTO email_domain_throttles (domain, delay_ms, max_per_hour, reason) VALUES
            ('gmail.com', 2000, 15, 'High volume provider - default throttle'),
            ('yahoo.com', 3000, 10, 'Strict spam filters'),
            ('outlook.com', 2000, 15, 'Microsoft anti-spam'),
            ('hotmail.com', 2000, 15, 'Microsoft anti-spam'),
            ('aol.com', 3000, 10, 'Strict filters'),
            ('icloud.com', 2000, 15, 'Apple anti-spam')
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS email_domain_throttles");
        DB::statement("DROP TABLE IF EXISTS email_rate_limits");
    }
};
