<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('detected_bills')) {
            return;
        }

        DB::statement("
            CREATE TABLE detected_bills (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                payee VARCHAR(255) NOT NULL,
                amount VARCHAR(50),
                due_date DATE,
                bill_type VARCHAR(50) NOT NULL DEFAULT 'other',
                confidence DECIMAL(3,2),
                email_date TIMESTAMP NULL,
                email_id INT UNSIGNED NULL,
                status ENUM('pending', 'paid', 'overdue', 'dismissed') NOT NULL DEFAULT 'pending',
                paid_at TIMESTAMP NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_bills_due_date (due_date),
                INDEX idx_bills_status (status),
                INDEX idx_bills_payee (payee)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('detected_bills');
    }
};
