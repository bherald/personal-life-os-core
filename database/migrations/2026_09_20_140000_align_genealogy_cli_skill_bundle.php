<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This exact source shipped in d12ecb76 after the original registration
        // migration. Reconcile its version record without adopting other skills.
        DB::transaction(function (): void {
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('genealogy-researcher');
            if ($skill === null || ! hash_equals(
                '752a700bcf1dc5ec749fd8436d9507aba083821f5e990ca87a019618d453bb41',
                hash('sha256', $skill['full_content'])
            )) {
                throw new RuntimeException('The reviewed genealogy-researcher 1.2.2 source is required for CLI skill registration.');
            }
            app(SkillVersionService::class)->trackVersion('genealogy-researcher', $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
    }

    public function down(): void
    {
        // Preserve complete history. A release rollback restores its source and
        // uses normal framework skill version tracking; never delete evidence.
    }
};
