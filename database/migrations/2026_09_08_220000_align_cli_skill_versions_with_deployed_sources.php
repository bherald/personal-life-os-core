<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Use normal framework version tracking; never rewrite source files,
        // old version content, tool registrations or operator configuration.
        DB::transaction(function (): void {
            $loader = app(SkillLoaderService::class);
            $versions = app(SkillVersionService::class);
            foreach (['data-removal-ops', 'factcheck-ops'] as $name) {
                $skill = $loader->loadSkillSnapshot($name);
                if ($skill === null) {
                    throw new RuntimeException('Cannot align deployed skill version: '.$name);
                }
                $versions->trackVersion($name, $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
        });
    }

    public function down(): void
    {
        // Preserve the framework's complete version history. A skill rollback
        // uses skill:versions --rollback with the selected prior version; normal
        // skill loading also tracks the source of a restored application release.
    }
};
