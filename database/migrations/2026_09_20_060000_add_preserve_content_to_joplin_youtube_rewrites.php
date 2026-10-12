<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joplin_youtube_knowledge_rewrites', function (Blueprint $table): void {
            $table->string('operation_mode', 32)->default('rewrite')->after('video_id')->index();
        });

        $row = DB::table('system_configs')
            ->where('section', 'joplin')
            ->where('config_key', 'youtube_knowledge')
            ->first();
        if (! $row) {
            return;
        }
        $policy = json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR);
        $policy['rewrite']['allowed_operations'] = ['rewrite', 'preserve_content'];
        DB::table('system_configs')
            ->where('section', 'joplin')
            ->where('config_key', 'youtube_knowledge')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'description' => 'Table-owned Joplin YouTube layout, guarded content rewriting and source-preserving exact-video consolidation.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Retain operation receipts and the preserve-content policy on rollback.
    }
};
