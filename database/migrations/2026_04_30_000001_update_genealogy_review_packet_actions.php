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

        $updates = [];

        if (Schema::hasColumn('review_type_registry', 'actions')) {
            $updates['actions'] = json_encode(['approve', 'reject', 'clarify', 'defer', 'ignore']);
        }

        if (Schema::hasColumn('review_type_registry', 'batch_enabled')) {
            $updates['batch_enabled'] = false;
        }

        if (Schema::hasColumn('review_type_registry', 'approve_method')) {
            $updates['approve_method'] = 'approve';
        }

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();

        DB::table('review_type_registry')
            ->where('name', 'genealogy_review_packet')
            ->update($updates);

        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $updates = [];

        if (Schema::hasColumn('review_type_registry', 'actions')) {
            $updates['actions'] = json_encode(['approve', 'reject', 'ignore']);
        }

        if (Schema::hasColumn('review_type_registry', 'batch_enabled')) {
            $updates['batch_enabled'] = true;
        }

        if (Schema::hasColumn('review_type_registry', 'approve_method')) {
            $updates['approve_method'] = 'markReviewed';
        }

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();

        DB::table('review_type_registry')
            ->where('name', 'genealogy_review_packet')
            ->update($updates);

        Cache::forget('review_type_registry');
    }
};
