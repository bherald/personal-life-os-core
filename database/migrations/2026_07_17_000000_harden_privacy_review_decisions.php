<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        DB::table('review_type_registry')->where('name', 'privacy')->update([
            'label' => 'Privacy Requests',
            'category' => 'privacy',
            'source_table' => 'removal_requests',
            'source_connection' => 'mysql',
            'count_sql' => "SELECT COUNT(*) as total FROM removal_requests WHERE requires_review = 1 AND status = 'pending'",
            'fetch_sql' => <<<'SQL'
SELECT r.id,
       CONCAT('Remove from ', COALESCE(b.name, 'Unknown broker')) AS title,
       r.ai_notes AS summary,
       r.ai_confidence / 100 AS confidence,
       r.created_at,
       r.profile_url,
       r.fields_to_submit,
       r.automation_tier,
       b.name AS broker_name,
       CONCAT('https://', b.domain) AS broker_url,
       b.removal_method,
       b.required_fields,
       b.optional_fields,
       b.requires_captcha,
       b.requires_auth,
       b.uses_javascript,
       CASE
           WHEN b.removal_method IN ('web_form', 'api') THEN b.removal_url IS NOT NULL AND b.removal_url <> ''
           WHEN b.removal_method = 'email' THEN b.removal_email IS NOT NULL AND b.removal_email <> ''
           ELSE 0
       END AS method_target_available,
       s.name AS subject_name,
       s.name IS NOT NULL AND s.name <> '' AS has_name,
       s.email IS NOT NULL AND s.email <> '' AS has_email,
       s.phone IS NOT NULL AND s.phone <> '' AS has_phone,
       s.address_line1 IS NOT NULL AND s.address_line1 <> '' AS has_address,
       s.city IS NOT NULL AND s.city <> '' AS has_city,
       s.state IS NOT NULL AND s.state <> '' AS has_state,
       s.zip IS NOT NULL AND s.zip <> '' AS has_zip,
       s.date_of_birth IS NOT NULL AS has_dob,
       s.aliases IS NOT NULL AND JSON_LENGTH(s.aliases) > 0 AS has_aliases
FROM removal_requests r
JOIN data_brokers b ON b.id = r.broker_id
JOIN data_subjects s ON s.id = r.subject_id
WHERE r.requires_review = 1 AND r.status = 'pending'
ORDER BY r.created_at ASC
LIMIT 100
SQL,
            'approve_sql' => null,
            'reject_sql' => null,
            'field_mapping' => json_encode([
                'unified_id_template' => 'privacy:{{id}}',
                'id' => 'id',
                'title' => 'title',
                'summary' => 'summary',
                'confidence' => 'confidence',
                'created_at' => 'created_at',
                'profile_url' => 'profile_url',
                'fields_to_submit_json' => 'fields_to_submit',
                'automation_tier' => 'automation_tier',
                'broker_name' => 'broker_name',
                'broker_url' => 'broker_url',
                'removal_method' => 'removal_method',
                'required_fields_json' => 'required_fields',
                'optional_fields_json' => 'optional_fields',
                'requires_captcha' => 'requires_captcha',
                'requires_auth' => 'requires_auth',
                'uses_javascript' => 'uses_javascript',
                'method_target_available' => 'method_target_available',
                'subject_name' => 'subject_name',
                'has_name' => 'has_name',
                'has_email' => 'has_email',
                'has_phone' => 'has_phone',
                'has_address' => 'has_address',
                'has_city' => 'has_city',
                'has_state' => 'has_state',
                'has_zip' => 'has_zip',
                'has_dob' => 'has_dob',
                'has_aliases' => 'has_aliases',
            ], JSON_UNESCAPED_SLASHES),
            'ui_schema' => json_encode([], JSON_UNESCAPED_SLASHES),
            'vue_renderer' => 'PrivacyRequestRenderer',
            'vue_detail_component' => 'PrivacyRequestDetail',
            'actions' => json_encode(['approve', 'reject']),
            'requires_image' => false,
            'image_field' => null,
            'batch_enabled' => false,
            'service_class' => 'App\\Services\\DataRemoval\\PrivacyRequestReviewDecisionService',
            'approve_method' => 'approve',
            'reject_method' => 'reject',
            'color' => 'ops-peach',
            'display_order' => 50,
            'enabled' => true,
            'updated_at' => now(),
        ]);

        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (Schema::hasTable('review_type_registry')) {
            DB::table('review_type_registry')->where('name', 'privacy')->update([
                'fetch_sql' => "SELECT r.id, r.profile_url, r.status, r.ai_confidence, r.ai_notes as summary, r.review_notes, r.created_at, b.name as broker_name, CONCAT('https://', b.domain) as broker_url, s.name as subject_name FROM removal_requests r LEFT JOIN data_brokers b ON b.id = r.broker_id LEFT JOIN data_subjects s ON s.id = r.subject_id WHERE r.requires_review = 1 AND r.status = 'pending' ORDER BY r.ai_confidence DESC, r.created_at ASC LIMIT 100",
                'approve_sql' => "UPDATE removal_requests SET status = 'approved', review_notes = CONCAT(COALESCE(review_notes, ''), ' [Approved via Research Hub]'), updated_at = NOW() WHERE id = ?",
                'reject_sql' => "UPDATE removal_requests SET status = 'rejected', review_notes = CONCAT(COALESCE(review_notes, ''), ' [Rejected via Research Hub]'), updated_at = NOW() WHERE id = ?",
                'service_class' => null,
                'approve_method' => null,
                'reject_method' => null,
                'vue_detail_component' => null,
                'batch_enabled' => true,
                'updated_at' => now(),
            ]);
        }

        Cache::forget('review_type_registry');
    }
};
