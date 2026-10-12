<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('genealogy-analyst');
            if ($skill === null) {
                throw new RuntimeException('Cannot align deployed genealogy-analyst skill version.');
            }

            app(SkillVersionService::class)->trackVersion('genealogy-analyst',
                $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
    }

    public function down(): void
    {
        // Retain skill version history; rollback uses the skill version workflow.
    }
};
