<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Skill Version Service
 *
 * Tracks every version of SKILL.md files in the skill_versions table.
 * Provides rollback capability and version comparison.
 *
 * Key responsibilities:
 * - Detect when a SKILL.md has changed (content hash comparison)
 * - Snapshot full content + parsed frontmatter for every version
 * - Mark active version per skill
 * - Rollback to any previous version by restoring SKILL.md file
 * - Provide diff/history for audit trail
 */
class SkillVersionService
{
    /**
     * Track a skill's current state. If content changed, creates new version record.
     *
     * Called by SkillLoaderService on every loadSkill() to ensure DB stays in sync.
     *
     * @return array Version info: ['version_id' => int, 'version' => string, 'is_new' => bool]
     */
    public function trackVersion(string $skillName, array $frontmatter, string $body, string $fullContent): array
    {
        $contentHash = hash('sha256', $fullContent);
        $version = $frontmatter['version'] ?? '1.0.0';

        // Check if this exact content already tracked
        $existing = DB::select("
            SELECT id, version, is_active
            FROM skill_versions
            WHERE skill_name = ? AND content_hash = ?
            LIMIT 1
        ", [$skillName, $contentHash]);

        if (!empty($existing)) {
            $record = $existing[0];
            // Ensure it's marked active (might have been rolled back and re-deployed)
            if (!$record->is_active) {
                $this->activateVersion($skillName, $record->id);
            }
            return [
                'version_id' => $record->id,
                'version' => $record->version,
                'is_new' => false,
            ];
        }

        // New content detected — create version record
        $changeSummary = $this->detectChanges($skillName, $frontmatter, $body);

        // Deactivate all previous versions for this skill
        DB::update("
            UPDATE skill_versions SET is_active = 0 WHERE skill_name = ?
        ", [$skillName]);

        // Insert new version
        DB::insert("
            INSERT INTO skill_versions
            (skill_name, version, content_hash, frontmatter, body_text, full_content,
             change_summary, changed_by, is_active, tools_count, tool_phases_count, permissions)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'system', 1, ?, ?, ?)
        ", [
            $skillName,
            $version,
            $contentHash,
            json_encode($frontmatter),
            $body,
            $fullContent,
            $changeSummary,
            count($frontmatter['tools'] ?? []),
            count($frontmatter['tool_phases'] ?? []),
            json_encode($frontmatter['permissions'] ?? []),
        ]);

        $versionId = (int) DB::getPdo()->lastInsertId();

        Log::info("SkillVersionService: New version tracked", [
            'skill' => $skillName,
            'version' => $version,
            'version_id' => $versionId,
            'change_summary' => $changeSummary,
        ]);

        return [
            'version_id' => $versionId,
            'version' => $version,
            'is_new' => true,
        ];
    }

    /**
     * Get version history for a skill
     */
    public function getHistory(string $skillName, int $limit = 20): array
    {
        return DB::select("
            SELECT id, version, content_hash, change_summary, changed_by,
                   is_active, tools_count, tool_phases_count, created_at
            FROM skill_versions
            WHERE skill_name = ?
            ORDER BY id DESC
            LIMIT ?
        ", [$skillName, $limit]);
    }

    /**
     * Get the currently active version for a skill
     */
    public function getActiveVersion(string $skillName): ?object
    {
        $result = DB::select("
            SELECT id, version, content_hash, frontmatter, body_text, full_content,
                   change_summary, changed_by, tools_count, tool_phases_count, created_at
            FROM skill_versions
            WHERE skill_name = ? AND is_active = 1
            LIMIT 1
        ", [$skillName]);

        return $result[0] ?? null;
    }

    /**
     * Get a specific version by ID
     */
    public function getVersion(int $versionId): ?object
    {
        $result = DB::select("
            SELECT id, skill_name, version, content_hash, frontmatter, body_text,
                   full_content, change_summary, changed_by, is_active,
                   tools_count, tool_phases_count, created_at
            FROM skill_versions
            WHERE id = ?
            LIMIT 1
        ", [$versionId]);

        return $result[0] ?? null;
    }

    /**
     * Rollback a skill to a specific version.
     * Restores the SKILL.md file from the stored snapshot.
     *
     * @return bool True if rollback succeeded
     */
    public function rollback(string $skillName, int $versionId): bool
    {
        $version = $this->getVersion($versionId);

        if (!$version || $version->skill_name !== $skillName) {
            Log::error("SkillVersionService: Rollback failed — version not found or skill mismatch", [
                'skill' => $skillName,
                'version_id' => $versionId,
            ]);
            return false;
        }

        $skillFile = SkillLoaderService::configuredSkillsBasePath().'/'.$skillName.'/SKILL.md';

        if (!is_dir(dirname($skillFile))) {
            Log::error("SkillVersionService: Rollback failed — skill directory missing", [
                'skill' => $skillName,
            ]);
            return false;
        }

        // Write the stored content back to disk
        $written = file_put_contents($skillFile, $version->full_content);

        if ($written === false) {
            Log::error("SkillVersionService: Rollback failed — could not write file", [
                'skill' => $skillName,
                'path' => $skillFile,
            ]);
            return false;
        }

        // Activate this version, deactivate others
        $this->activateVersion($skillName, $versionId);

        Log::info("SkillVersionService: Rolled back skill", [
            'skill' => $skillName,
            'to_version' => $version->version,
            'version_id' => $versionId,
        ]);

        return true;
    }

    /**
     * Compare two versions and return structured diff
     */
    public function compareVersions(int $versionIdA, int $versionIdB): ?array
    {
        $a = $this->getVersion($versionIdA);
        $b = $this->getVersion($versionIdB);

        if (!$a || !$b) {
            return null;
        }

        $fmA = json_decode($a->frontmatter, true) ?? [];
        $fmB = json_decode($b->frontmatter, true) ?? [];

        $toolsA = $fmA['tools'] ?? [];
        $toolsB = $fmB['tools'] ?? [];

        return [
            'skill' => $a->skill_name,
            'version_a' => ['id' => $a->id, 'version' => $a->version, 'created_at' => $a->created_at],
            'version_b' => ['id' => $b->id, 'version' => $b->version, 'created_at' => $b->created_at],
            'tools_added' => array_values(array_diff($toolsB, $toolsA)),
            'tools_removed' => array_values(array_diff($toolsA, $toolsB)),
            'tools_a_count' => count($toolsA),
            'tools_b_count' => count($toolsB),
            'version_changed' => $a->version !== $b->version,
            'body_changed' => $a->body_text !== $b->body_text,
            'body_size_a' => strlen($a->body_text),
            'body_size_b' => strlen($b->body_text),
        ];
    }

    /**
     * Snapshot all current skills (bootstrap — run once to populate table from existing SKILL.md files)
     *
     * @return int Number of skills snapshotted
     */
    public function snapshotAll(): int
    {
        $loader = app(SkillLoaderService::class);
        $skills = $loader->getSkillIndex();
        $count = 0;

        foreach ($skills as $skill) {
            $skillName = $skill['name'];
            $fullContent = file_get_contents($skill['path']);
            $parsed = $loader->loadSkill($skillName);

            if (!$parsed) {
                continue;
            }

            $result = $this->trackVersion(
                $skillName,
                $parsed['frontmatter'],
                $parsed['body'],
                $fullContent
            );

            $count++;
        }

        return $count;
    }

    /**
     * Get summary stats across all skills
     */
    public function getStats(): array
    {
        $totalVersions = DB::select("SELECT COUNT(*) as cnt FROM skill_versions")[0]->cnt;
        $uniqueSkills = DB::select("SELECT COUNT(DISTINCT skill_name) as cnt FROM skill_versions")[0]->cnt;
        $activeVersions = DB::select("SELECT COUNT(*) as cnt FROM skill_versions WHERE is_active = 1")[0]->cnt;

        $perSkill = DB::select("
            SELECT skill_name,
                   COUNT(*) as version_count,
                   MAX(version) as latest_version,
                   MAX(created_at) as last_updated
            FROM skill_versions
            GROUP BY skill_name
            ORDER BY skill_name
        ");

        return [
            'total_versions' => (int) $totalVersions,
            'unique_skills' => (int) $uniqueSkills,
            'active_versions' => (int) $activeVersions,
            'per_skill' => $perSkill,
        ];
    }

    /**
     * Activate a specific version and deactivate all others for the skill
     */
    private function activateVersion(string $skillName, int $versionId): void
    {
        DB::update("UPDATE skill_versions SET is_active = 0 WHERE skill_name = ?", [$skillName]);
        DB::update("UPDATE skill_versions SET is_active = 1 WHERE id = ?", [$versionId]);
    }

    /**
     * Detect what changed compared to the previous active version
     */
    private function detectChanges(string $skillName, array $newFrontmatter, string $newBody): ?string
    {
        $previous = $this->getActiveVersion($skillName);

        if (!$previous) {
            return 'Initial version';
        }

        $changes = [];
        $oldFm = json_decode($previous->frontmatter, true) ?? [];

        // Version bump
        $oldVersion = $oldFm['version'] ?? '1.0.0';
        $newVersion = $newFrontmatter['version'] ?? '1.0.0';
        if ($oldVersion !== $newVersion) {
            $changes[] = "version {$oldVersion} -> {$newVersion}";
        }

        // Tool changes
        $oldTools = $oldFm['tools'] ?? [];
        $newTools = $newFrontmatter['tools'] ?? [];
        $added = array_diff($newTools, $oldTools);
        $removed = array_diff($oldTools, $newTools);
        if (!empty($added)) {
            $changes[] = 'added tools: ' . implode(', ', $added);
        }
        if (!empty($removed)) {
            $changes[] = 'removed tools: ' . implode(', ', $removed);
        }

        // Permission changes
        $oldPerms = $oldFm['permissions'] ?? [];
        $newPerms = $newFrontmatter['permissions'] ?? [];
        if ($oldPerms !== $newPerms) {
            $changes[] = 'permissions changed';
        }

        // Mode change
        if (($oldFm['workflow_mode'] ?? null) !== ($newFrontmatter['workflow_mode'] ?? null)) {
            $changes[] = 'workflow_mode changed to ' . ($newFrontmatter['workflow_mode'] ?? 'unset');
        }

        // Body changes
        if ($previous->body_text !== $newBody) {
            $oldLen = strlen($previous->body_text);
            $newLen = strlen($newBody);
            $diff = $newLen - $oldLen;
            $sign = $diff >= 0 ? '+' : '';
            $changes[] = "instructions updated ({$sign}{$diff} chars)";
        }

        return !empty($changes) ? implode('; ', $changes) : 'Content changed (minor)';
    }
}
