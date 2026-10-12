<?php

use App\Services\SkillLoaderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

return new class extends Migration
{
    public function definition(): array
    {
        $content = file_get_contents(SkillLoaderService::configuredSkillsBasePath().'/typesafe-ai/SKILL.md');
        $parts = preg_split('/^---\s*$/m', $content, 3);
        $frontmatter = Yaml::parse($parts[1]);

        return ['skill_name' => 'typesafe-ai', 'version' => '0.5.7',
            'content_hash' => hash('sha256', $content), 'frontmatter' => json_encode($frontmatter, JSON_THROW_ON_ERROR),
            'body_text' => trim($parts[2]), 'full_content' => $content,
            'change_summary' => 'Official TypeSafe guidance skill; no model, tool, permission or route activation.',
            'changed_by' => 'migration', 'is_active' => true,
            'tools_count' => 0, 'tool_phases_count' => 0,
            'permissions' => json_encode([], JSON_THROW_ON_ERROR)];
    }

    public function up(): void
    {
        DB::table('skill_versions')->insertOrIgnore($this->definition() + ['created_at' => now()]);
        // No default selection, registry permission, target, adapter or credential changes.
    }

    public function down(): void
    {
        $definition = $this->definition();
        DB::table('skill_versions')->where('skill_name', $definition['skill_name'])
            ->where('version', $definition['version'])->where('content_hash', $definition['content_hash'])
            ->update(['is_active' => false]);
        // Retain version history and every unrelated or customized skill.
    }
};
