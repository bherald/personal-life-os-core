<?php

use App\Services\SkillLoaderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

return new class extends Migration
{
    public function definition(): array
    {
        $content = file_get_contents(SkillLoaderService::configuredSkillsBasePath().'/os-tool-review/SKILL.md');
        $parts = preg_split('/^---\s*$/m', $content, 3);
        $frontmatter = Yaml::parse($parts[1]);

        return ['skill_name' => 'os-tool-review', 'version' => $frontmatter['version'],
            'content_hash' => hash('sha256', $content), 'frontmatter' => json_encode($frontmatter, JSON_THROW_ON_ERROR),
            'body_text' => trim($parts[2]), 'full_content' => $content,
            'change_summary' => 'Opt-in read-only discovery and recurring tool review through existing framework services.',
            'changed_by' => 'migration', 'is_active' => true,
            'tools_count' => count($frontmatter['tools']), 'tool_phases_count' => 0,
            'permissions' => json_encode($frontmatter['permissions'], JSON_THROW_ON_ERROR)];
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
