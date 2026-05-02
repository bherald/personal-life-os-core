<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Privacy Service
 *
 * Handles privacy settings, collaborators, invitations, and activity logging.
 * Extracted from GenealogyService as part of Priority 2.1 refactoring.
 *
 * @see docs/genealogy-module-review.md Priority 2.1
 */
class PrivacyService
{
    /**
     * Get tree privacy settings
     */
    public function getTreePrivacySettings(int $treeId): ?array
    {
        $tree = DB::selectOne("
            SELECT id, name, owner_id, privacy, living_privacy,
                   living_years_threshold, default_media_privacy, allow_public_search
            FROM genealogy_trees
            WHERE id = ?
        ", [$treeId]);

        if (!$tree) {
            return null;
        }

        return [
            'id' => $tree->id,
            'name' => $tree->name,
            'owner_id' => $tree->owner_id,
            'privacy' => $tree->privacy ?? 'private',
            'living_privacy' => $tree->living_privacy ?? 'hide_details',
            'living_years_threshold' => $tree->living_years_threshold ?? 100,
            'default_media_privacy' => $tree->default_media_privacy ?? 'shared',
            'allow_public_search' => (bool)($tree->allow_public_search ?? false),
        ];
    }

    /**
     * Update tree privacy settings
     */
    public function updateTreePrivacySettings(int $treeId, array $settings): bool
    {
        $updates = [];
        $params = [];

        if (isset($settings['privacy'])) {
            $updates[] = 'privacy = ?';
            $params[] = $settings['privacy'];
        }
        if (isset($settings['living_privacy'])) {
            $updates[] = 'living_privacy = ?';
            $params[] = $settings['living_privacy'];
        }
        if (isset($settings['living_years_threshold'])) {
            $updates[] = 'living_years_threshold = ?';
            $params[] = (int)$settings['living_years_threshold'];
        }
        if (isset($settings['default_media_privacy'])) {
            $updates[] = 'default_media_privacy = ?';
            $params[] = $settings['default_media_privacy'];
        }
        if (isset($settings['allow_public_search'])) {
            $updates[] = 'allow_public_search = ?';
            $params[] = $settings['allow_public_search'] ? 1 : 0;
        }
        if (isset($settings['owner_id'])) {
            $updates[] = 'owner_id = ?';
            $params[] = $settings['owner_id'];
        }

        if (empty($updates)) {
            return false;
        }

        $params[] = $treeId;
        $sql = "UPDATE genealogy_trees SET " . implode(', ', $updates) . " WHERE id = ?";

        DB::update($sql, $params);

        // Log the activity
        $this->logActivity($treeId, null, 'update_privacy_settings', 'tree', $treeId, null, $settings);

        return true;
    }

    /**
     * Check if a person is considered living (for privacy filtering)
     */
    public function isPersonLiving(int $personId): bool
    {
        $person = DB::selectOne("
            SELECT p.living, p.birth_date, p.death_date, t.living_years_threshold
            FROM genealogy_persons p
            LEFT JOIN genealogy_trees t ON p.tree_id = t.id
            WHERE p.id = ?
        ", [$personId]);

        if (!$person) {
            return false;
        }

        // If living status is explicitly set, use it
        if ($person->living !== null) {
            return (bool)$person->living;
        }

        // If death date exists, person is deceased
        if ($person->death_date && $person->death_date !== '') {
            return false;
        }

        // If birth date exists, check against threshold
        if ($person->birth_date) {
            $threshold = $person->living_years_threshold ?? 100;
            $birthYear = $this->extractYear($person->birth_date);
            if ($birthYear && (date('Y') - $birthYear > $threshold)) {
                return false; // Assumed deceased
            }
        }

        // Default to living if no death date and within threshold
        return true;
    }

    /**
     * Extract year from date string
     */
    private function extractYear(?string $date): ?int
    {
        if (!$date) {
            return null;
        }

        // Try to match 4-digit year at beginning or end
        if (preg_match('/^(\d{4})/', $date, $matches)) {
            return (int)$matches[1];
        }
        if (preg_match('/(\d{4})$/', $date, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Auto-detect and update living status for all persons in a tree
     */
    public function autoDetectLivingPersons(int $treeId): array
    {
        $tree = DB::selectOne("
            SELECT living_years_threshold FROM genealogy_trees WHERE id = ?
        ", [$treeId]);

        $threshold = $tree->living_years_threshold ?? 100;
        $thresholdYear = date('Y') - $threshold;

        // Mark as living: no death date and born within threshold
        $markedLiving = DB::update("
            UPDATE genealogy_persons
            SET living = 1
            WHERE tree_id = ?
              AND living IS NULL
              AND (death_date IS NULL OR death_date = '')
              AND (
                  birth_date IS NULL OR birth_date = ''
                  OR CAST(SUBSTRING(birth_date, -4) AS UNSIGNED) >= ?
              )
        ", [$treeId, $thresholdYear]);

        // Mark as deceased: has death date OR born before threshold
        $markedDeceased = DB::update("
            UPDATE genealogy_persons
            SET living = 0
            WHERE tree_id = ?
              AND living IS NULL
              AND (
                  (death_date IS NOT NULL AND death_date != '')
                  OR (
                      birth_date IS NOT NULL AND birth_date != ''
                      AND CAST(SUBSTRING(birth_date, -4) AS UNSIGNED) < ?
                  )
              )
        ", [$treeId, $thresholdYear]);

        $this->logActivity($treeId, null, 'auto_detect_living', 'tree', $treeId, null, [
            'marked_living' => $markedLiving,
            'marked_deceased' => $markedDeceased,
        ]);

        return [
            'marked_living' => $markedLiving,
            'marked_deceased' => $markedDeceased,
        ];
    }

    /**
     * Update privacy override for a person
     */
    public function updatePersonPrivacy(int $personId, string $privacyOverride): bool
    {
        if (!in_array($privacyOverride, ['default', 'public', 'private'])) {
            return false;
        }

        $person = DB::selectOne("SELECT tree_id FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            return false;
        }

        DB::update("
            UPDATE genealogy_persons
            SET privacy_override = ?
            WHERE id = ?
        ", [$privacyOverride, $personId]);

        $this->logActivity($person->tree_id, null, 'update_person_privacy', 'person', $personId, null, [
            'privacy_override' => $privacyOverride,
        ]);

        return true;
    }

    /**
     * Update media privacy settings
     */
    public function updateMediaPrivacy(int $mediaId, ?string $privacy, bool $isSensitive = false): bool
    {
        $media = DB::selectOne("SELECT tree_id FROM genealogy_media WHERE id = ?", [$mediaId]);
        if (!$media) {
            return false;
        }

        if ($privacy !== null && !in_array($privacy, ['private', 'shared', 'public'])) {
            return false;
        }

        DB::update("
            UPDATE genealogy_media
            SET privacy = ?, is_sensitive = ?
            WHERE id = ?
        ", [$privacy, $isSensitive ? 1 : 0, $mediaId]);

        $this->logActivity($media->tree_id, null, 'update_media_privacy', 'media', $mediaId, null, [
            'privacy' => $privacy,
            'is_sensitive' => $isSensitive,
        ]);

        return true;
    }

    /**
     * Get tree collaborators
     */
    public function getTreeCollaborators(int $treeId): array
    {
        $collaborators = DB::select("
            SELECT c.*, u.name as user_name, u.email as user_email,
                   i.name as invited_by_name
            FROM genealogy_tree_collaborators c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN users i ON c.invited_by = i.id
            WHERE c.tree_id = ?
            ORDER BY c.role, c.created_at
        ", [$treeId]);

        return array_map(fn($c) => [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'user_name' => $c->user_name,
            'user_email' => $c->user_email,
            'role' => $c->role,
            'can_export' => (bool)$c->can_export,
            'can_delete' => (bool)$c->can_delete,
            'can_manage_media' => (bool)$c->can_manage_media,
            'invited_by' => $c->invited_by,
            'invited_by_name' => $c->invited_by_name,
            'invited_at' => $c->invited_at,
            'accepted_at' => $c->accepted_at,
        ], $collaborators);
    }

    /**
     * Add a collaborator to a tree
     */
    public function addCollaborator(int $treeId, int $userId, string $role, ?int $invitedBy = null, array $permissions = []): bool
    {
        if (!in_array($role, ['viewer', 'contributor', 'editor', 'admin'])) {
            return false;
        }

        // Check if already a collaborator
        $existing = DB::selectOne("
            SELECT id FROM genealogy_tree_collaborators WHERE tree_id = ? AND user_id = ?
        ", [$treeId, $userId]);

        if ($existing) {
            return false; // Already exists
        }

        DB::insert("
            INSERT INTO genealogy_tree_collaborators
            (tree_id, user_id, role, can_export, can_delete, can_manage_media, invited_by, accepted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $treeId,
            $userId,
            $role,
            $permissions['can_export'] ?? ($role === 'admin' || $role === 'editor'),
            $permissions['can_delete'] ?? ($role === 'admin'),
            $permissions['can_manage_media'] ?? true,
            $invitedBy,
        ]);

        $this->logActivity($treeId, $invitedBy, 'add_collaborator', 'collaborator', $userId, null, [
            'role' => $role,
        ]);

        return true;
    }

    /**
     * Update collaborator role/permissions
     */
    public function updateCollaborator(int $collaboratorId, array $updates): bool
    {
        $collab = DB::selectOne("
            SELECT tree_id, user_id FROM genealogy_tree_collaborators WHERE id = ?
        ", [$collaboratorId]);

        if (!$collab) {
            return false;
        }

        $sets = [];
        $params = [];

        if (isset($updates['role']) && in_array($updates['role'], ['viewer', 'contributor', 'editor', 'admin'])) {
            $sets[] = 'role = ?';
            $params[] = $updates['role'];
        }
        if (isset($updates['can_export'])) {
            $sets[] = 'can_export = ?';
            $params[] = $updates['can_export'] ? 1 : 0;
        }
        if (isset($updates['can_delete'])) {
            $sets[] = 'can_delete = ?';
            $params[] = $updates['can_delete'] ? 1 : 0;
        }
        if (isset($updates['can_manage_media'])) {
            $sets[] = 'can_manage_media = ?';
            $params[] = $updates['can_manage_media'] ? 1 : 0;
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $collaboratorId;
        DB::update("UPDATE genealogy_tree_collaborators SET " . implode(', ', $sets) . " WHERE id = ?", $params);

        $this->logActivity($collab->tree_id, null, 'update_collaborator', 'collaborator', $collab->user_id, null, $updates);

        return true;
    }

    /**
     * Remove a collaborator from a tree
     */
    public function removeCollaborator(int $collaboratorId): bool
    {
        $collab = DB::selectOne("
            SELECT tree_id, user_id FROM genealogy_tree_collaborators WHERE id = ?
        ", [$collaboratorId]);

        if (!$collab) {
            return false;
        }

        DB::delete("DELETE FROM genealogy_tree_collaborators WHERE id = ?", [$collaboratorId]);

        $this->logActivity($collab->tree_id, null, 'remove_collaborator', 'collaborator', $collab->user_id);

        return true;
    }

    /**
     * Create an invitation to collaborate on a tree
     */
    public function createInvitation(int $treeId, string $email, string $role, int $invitedBy): ?array
    {
        if (!in_array($role, ['viewer', 'contributor', 'editor', 'admin'])) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        DB::insert("
            INSERT INTO genealogy_tree_invitations (tree_id, email, role, token, invited_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$treeId, $email, $role, $token, $invitedBy, $expiresAt]);

        $id = DB::getPdo()->lastInsertId();

        $this->logActivity($treeId, $invitedBy, 'create_invitation', 'invitation', $id, null, [
            'email' => $email,
            'role' => $role,
        ]);

        return [
            'id' => $id,
            'token' => $token,
            'email' => $email,
            'role' => $role,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Get pending invitations for a tree
     */
    public function getPendingInvitations(int $treeId): array
    {
        $invitations = DB::select("
            SELECT i.*, u.name as invited_by_name
            FROM genealogy_tree_invitations i
            LEFT JOIN users u ON i.invited_by = u.id
            WHERE i.tree_id = ? AND i.expires_at > NOW()
            ORDER BY i.created_at DESC
        ", [$treeId]);

        return array_map(fn($i) => [
            'id' => $i->id,
            'email' => $i->email,
            'role' => $i->role,
            'invited_by' => $i->invited_by,
            'invited_by_name' => $i->invited_by_name,
            'expires_at' => $i->expires_at,
            'created_at' => $i->created_at,
        ], $invitations);
    }

    /**
     * Accept an invitation
     */
    public function acceptInvitation(string $token, int $userId): ?array
    {
        $invitation = DB::selectOne("
            SELECT * FROM genealogy_tree_invitations
            WHERE token = ? AND expires_at > NOW()
        ", [$token]);

        if (!$invitation) {
            return null;
        }

        // Add as collaborator
        $this->addCollaborator($invitation->tree_id, $userId, $invitation->role, $invitation->invited_by);

        // Delete invitation
        DB::delete("DELETE FROM genealogy_tree_invitations WHERE id = ?", [$invitation->id]);

        return [
            'tree_id' => $invitation->tree_id,
            'role' => $invitation->role,
        ];
    }

    /**
     * Cancel/delete an invitation
     */
    public function cancelInvitation(int $invitationId): bool
    {
        $invitation = DB::selectOne("
            SELECT tree_id FROM genealogy_tree_invitations WHERE id = ?
        ", [$invitationId]);

        if (!$invitation) {
            return false;
        }

        DB::delete("DELETE FROM genealogy_tree_invitations WHERE id = ?", [$invitationId]);

        $this->logActivity($invitation->tree_id, null, 'cancel_invitation', 'invitation', $invitationId);

        return true;
    }

    /**
     * Check user's role/permissions on a tree
     */
    public function getUserTreePermissions(int $treeId, int $userId): ?array
    {
        // Check if owner
        $tree = DB::selectOne("
            SELECT owner_id, privacy FROM genealogy_trees WHERE id = ?
        ", [$treeId]);

        if ($tree && $tree->owner_id === $userId) {
            return [
                'role' => 'owner',
                'can_view' => true,
                'can_edit' => true,
                'can_export' => true,
                'can_delete' => true,
                'can_manage_media' => true,
                'can_manage_collaborators' => true,
            ];
        }

        // Check collaborator role
        $collab = DB::selectOne("
            SELECT * FROM genealogy_tree_collaborators
            WHERE tree_id = ? AND user_id = ?
        ", [$treeId, $userId]);

        if (!$collab) {
            // Check if tree is public
            if ($tree && $tree->privacy === 'public') {
                return [
                    'role' => 'public',
                    'can_view' => true,
                    'can_edit' => false,
                    'can_export' => false,
                    'can_delete' => false,
                    'can_manage_media' => false,
                    'can_manage_collaborators' => false,
                ];
            }
            return null; // No access
        }

        return [
            'role' => $collab->role,
            'can_view' => true,
            'can_edit' => in_array($collab->role, ['contributor', 'editor', 'admin']),
            'can_export' => (bool)$collab->can_export,
            'can_delete' => (bool)$collab->can_delete,
            'can_manage_media' => (bool)$collab->can_manage_media,
            'can_manage_collaborators' => $collab->role === 'admin',
        ];
    }

    /**
     * Log activity for audit trail
     */
    public function logActivity(
        int $treeId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $ipAddress = request()->ip() ?? null;
        $userAgent = substr(request()->userAgent() ?? '', 0, 500);

        DB::insert("
            INSERT INTO genealogy_activity_log
            (tree_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            $treeId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $ipAddress,
            $userAgent,
        ]);
    }

    /**
     * Get activity log for a tree
     */
    public function getActivityLog(int $treeId, int $limit = 50, int $offset = 0): array
    {
        $activities = DB::select("
            SELECT a.*, u.name as user_name
            FROM genealogy_activity_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tree_id = ?
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ", [$treeId, $limit, $offset]);

        return array_map(fn($a) => [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'user_name' => $a->user_name,
            'action' => $a->action,
            'entity_type' => $a->entity_type,
            'entity_id' => $a->entity_id,
            'old_values' => $a->old_values ? json_decode($a->old_values, true) : null,
            'new_values' => $a->new_values ? json_decode($a->new_values, true) : null,
            'ip_address' => $a->ip_address,
            'created_at' => $a->created_at,
        ], $activities);
    }

    /**
     * Apply privacy filtering to person data based on tree settings
     */
    public function applyPrivacyFilter(array $personData, int $treeId, ?int $viewingUserId = null): array
    {
        $tree = $this->getTreePrivacySettings($treeId);
        if (!$tree) {
            return $personData;
        }

        // Check if viewer has full access
        if ($viewingUserId) {
            $permissions = $this->getUserTreePermissions($treeId, $viewingUserId);
            if ($permissions && in_array($permissions['role'], ['owner', 'admin', 'editor'])) {
                return $personData; // Full access
            }
        }

        // Check if person is living and needs privacy protection
        $isLiving = $personData['living'] ?? false;
        $privacyOverride = $personData['privacy_override'] ?? 'default';

        // Handle privacy override
        if ($privacyOverride === 'public') {
            return $personData;
        }
        if ($privacyOverride === 'private') {
            return $this->redactPersonData($personData, 'hide_all');
        }

        // Apply tree's living privacy settings
        if ($isLiving) {
            return $this->redactPersonData($personData, $tree['living_privacy']);
        }

        return $personData;
    }

    /**
     * Redact person data based on privacy level
     */
    private function redactPersonData(array $data, string $level): array
    {
        if ($level === 'show_all') {
            return $data;
        }

        if ($level === 'hide_all') {
            return [
                'id' => $data['id'] ?? null,
                'tree_id' => $data['tree_id'] ?? null,
                'given_name' => 'Living',
                'surname' => $data['surname'] ?? null,
                'sex' => $data['sex'] ?? null,
                'living' => true,
                'privacy_restricted' => true,
            ];
        }

        // hide_details - show name but hide dates/places
        $filtered = $data;
        $filtered['birth_date'] = null;
        $filtered['birth_place'] = null;
        $filtered['death_date'] = null;
        $filtered['death_place'] = null;
        $filtered['burial_date'] = null;
        $filtered['burial_place'] = null;
        $filtered['ssn'] = null;
        $filtered['notes'] = null;
        $filtered['privacy_restricted'] = true;

        return $filtered;
    }

    /**
     * Get statistics about living/deceased persons in a tree
     */
    public function getLivingStatistics(int $treeId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN living = 1 THEN 1 ELSE 0 END) as living_explicit,
                SUM(CASE WHEN living = 0 THEN 1 ELSE 0 END) as deceased_explicit,
                SUM(CASE WHEN living IS NULL THEN 1 ELSE 0 END) as unknown,
                SUM(CASE WHEN death_date IS NOT NULL AND death_date != '' THEN 1 ELSE 0 END) as has_death_date,
                SUM(CASE WHEN privacy_override = 'public' THEN 1 ELSE 0 END) as privacy_public,
                SUM(CASE WHEN privacy_override = 'private' THEN 1 ELSE 0 END) as privacy_private
            FROM genealogy_persons
            WHERE tree_id = ?
        ", [$treeId]);

        return [
            'total' => (int)$stats->total,
            'living_explicit' => (int)$stats->living_explicit,
            'deceased_explicit' => (int)$stats->deceased_explicit,
            'unknown_status' => (int)$stats->unknown,
            'has_death_date' => (int)$stats->has_death_date,
            'privacy_public' => (int)$stats->privacy_public,
            'privacy_private' => (int)$stats->privacy_private,
        ];
    }
}
