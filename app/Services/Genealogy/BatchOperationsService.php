<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Batch Operations Service
 *
 * Handles bulk operations on genealogy entities for efficient
 * mass updates, tagging, and deletions.
 *
 * @see docs/genealogy-module-review.md Priority 3.5
 */
class BatchOperationsService
{
    /**
     * Batch update multiple persons with the same field values
     *
     * @param int $treeId Tree ID
     * @param array $personIds Array of person IDs to update
     * @param array $updates Key-value pairs of fields to update
     * @return array Result with success count and errors
     */
    public function batchUpdatePersons(int $treeId, array $personIds, array $updates): array
    {
        $result = [
            'success' => true,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Validate person IDs
        if (empty($personIds)) {
            $result['success'] = false;
            $result['errors'][] = 'No person IDs provided';
            return $result;
        }

        // Validate updates
        if (empty($updates)) {
            $result['success'] = false;
            $result['errors'][] = 'No updates provided';
            return $result;
        }

        // Define allowed fields for batch update
        $allowedFields = [
            'surname', 'given_name', 'nickname', 'sex',
            'birth_date', 'birth_place', 'death_date', 'death_place',
            'occupation', 'religion', 'nationality',
            'notes', 'living',
        ];

        // Filter to only allowed fields
        $filteredUpdates = array_intersect_key($updates, array_flip($allowedFields));
        if (empty($filteredUpdates)) {
            $result['success'] = false;
            $result['errors'][] = 'No valid fields to update. Allowed fields: ' . implode(', ', $allowedFields);
            return $result;
        }

        try {
            DB::beginTransaction();

            // Build SET clause
            $setClauses = [];
            $params = [];
            foreach ($filteredUpdates as $field => $value) {
                $setClauses[] = "{$field} = ?";
                $params[] = $value;
            }
            $setClauses[] = "updated_at = NOW()";

            // Build WHERE clause with placeholders
            $placeholders = implode(',', array_fill(0, count($personIds), '?'));
            $params = array_merge($params, $personIds, [$treeId]);

            $sql = "UPDATE genealogy_persons
                    SET " . implode(', ', $setClauses) . "
                    WHERE id IN ({$placeholders}) AND tree_id = ?";

            $updated = DB::update($sql, $params);

            DB::commit();

            $result['updated'] = $updated;
            $result['failed'] = count($personIds) - $updated;

            if ($result['failed'] > 0) {
                $result['errors'][] = "{$result['failed']} person(s) not found in tree or already updated";
            }

            Log::info('Batch update persons completed', [
                'tree_id' => $treeId,
                'requested' => count($personIds),
                'updated' => $updated,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error('Batch update persons failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Batch delete multiple persons from a tree
     *
     * @param int $treeId Tree ID
     * @param array $personIds Array of person IDs to delete
     * @param bool $cascade If true, also delete related records (events, facts, etc.)
     * @return array Result with success count and errors
     */
    public function batchDeletePersons(int $treeId, array $personIds, bool $cascade = true): array
    {
        $result = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
            'details' => [
                'persons' => 0,
                'events' => 0,
                'facts' => 0,
                'media_links' => 0,
                'citations' => 0,
                'children_records' => 0,
            ],
        ];

        if (empty($personIds)) {
            $result['success'] = false;
            $result['errors'][] = 'No person IDs provided';
            return $result;
        }

        try {
            DB::beginTransaction();

            $placeholders = implode(',', array_fill(0, count($personIds), '?'));
            $params = array_merge($personIds, [$treeId]);

            // Verify persons exist in tree
            $existingPersons = DB::select(
                "SELECT id FROM genealogy_persons WHERE id IN ({$placeholders}) AND tree_id = ?",
                $params
            );
            $existingIds = array_column($existingPersons, 'id');

            if (empty($existingIds)) {
                DB::rollBack();
                $result['success'] = false;
                $result['errors'][] = 'No matching persons found in tree';
                return $result;
            }

            $placeholders = implode(',', array_fill(0, count($existingIds), '?'));

            if ($cascade) {
                // Delete related events
                $eventsDeleted = DB::delete(
                    "DELETE FROM genealogy_events WHERE person_id IN ({$placeholders})",
                    $existingIds
                );
                $result['details']['events'] = $eventsDeleted;

                // genealogy_person_facts table does not exist — skip
                $result['details']['facts'] = 0;

                // Delete media links (not the media themselves)
                $mediaLinksDeleted = DB::delete(
                    "DELETE FROM genealogy_person_media WHERE person_id IN ({$placeholders})",
                    $existingIds
                );
                $result['details']['media_links'] = $mediaLinksDeleted;

                // Delete citations
                $citationsDeleted = DB::delete(
                    "DELETE FROM genealogy_citations WHERE person_id IN ({$placeholders})",
                    $existingIds
                );
                $result['details']['citations'] = $citationsDeleted;

                // Delete children records (where person is a child)
                $childrenDeleted = DB::delete(
                    "DELETE FROM genealogy_children WHERE person_id IN ({$placeholders})",
                    $existingIds
                );
                $result['details']['children_records'] = $childrenDeleted;

                // Update families where person is husband/wife (set to NULL)
                DB::update(
                    "UPDATE genealogy_families SET husband_id = NULL WHERE husband_id IN ({$placeholders})",
                    $existingIds
                );
                DB::update(
                    "UPDATE genealogy_families SET wife_id = NULL WHERE wife_id IN ({$placeholders})",
                    $existingIds
                );

                // Delete shared note references for these persons
                DB::delete(
                    "DELETE FROM genealogy_shared_note_refs WHERE record_type = 'person' AND record_id IN ({$placeholders})",
                    $existingIds
                );

                // Delete research hints
                DB::delete(
                    "DELETE FROM genealogy_research_hints WHERE person_id IN ({$placeholders})",
                    $existingIds
                );

                // Delete external links
                DB::delete(
                    "DELETE FROM genealogy_person_external_links WHERE person_id IN ({$placeholders})",
                    $existingIds
                );
            }

            // Delete the persons
            $personsDeleted = DB::delete(
                "DELETE FROM genealogy_persons WHERE id IN ({$placeholders})",
                $existingIds
            );
            $result['details']['persons'] = $personsDeleted;

            // Update tree person count
            DB::update(
                "UPDATE genealogy_trees SET person_count = person_count - ? WHERE id = ?",
                [$personsDeleted, $treeId]
            );

            DB::commit();

            $result['deleted'] = $personsDeleted;
            $result['failed'] = count($personIds) - $personsDeleted;

            Log::info('Batch delete persons completed', [
                'tree_id' => $treeId,
                'requested' => count($personIds),
                'deleted' => $personsDeleted,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error('Batch delete persons failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Batch tag/categorize multiple media items
     *
     * @param int $treeId Tree ID
     * @param array $mediaIds Array of media IDs to tag
     * @param array $tags Tags to apply (e.g., ['category' => 'portrait', 'year' => '1950'])
     * @return array Result with success count and errors
     */
    public function batchTagMedia(int $treeId, array $mediaIds, array $tags): array
    {
        $result = [
            'success' => true,
            'tagged' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (empty($mediaIds)) {
            $result['success'] = false;
            $result['errors'][] = 'No media IDs provided';
            return $result;
        }

        if (empty($tags)) {
            $result['success'] = false;
            $result['errors'][] = 'No tags provided';
            return $result;
        }

        // Allowed tag fields
        $allowedFields = [
            'title', 'description', 'category', 'date_taken',
            'location', 'photographer', 'copyright', 'notes',
        ];

        $filteredTags = array_intersect_key($tags, array_flip($allowedFields));
        if (empty($filteredTags)) {
            $result['success'] = false;
            $result['errors'][] = 'No valid tag fields. Allowed: ' . implode(', ', $allowedFields);
            return $result;
        }

        try {
            DB::beginTransaction();

            // Build SET clause
            $setClauses = [];
            $params = [];
            foreach ($filteredTags as $field => $value) {
                $setClauses[] = "{$field} = ?";
                $params[] = $value;
            }
            $setClauses[] = "updated_at = NOW()";

            $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
            $params = array_merge($params, $mediaIds, [$treeId]);

            $sql = "UPDATE genealogy_media
                    SET " . implode(', ', $setClauses) . "
                    WHERE id IN ({$placeholders}) AND tree_id = ?";

            $updated = DB::update($sql, $params);

            DB::commit();

            $result['tagged'] = $updated;
            $result['failed'] = count($mediaIds) - $updated;

            Log::info('Batch tag media completed', [
                'tree_id' => $treeId,
                'requested' => count($mediaIds),
                'tagged' => $updated,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error('Batch tag media failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Batch link multiple media items to a person
     *
     * @param int $treeId Tree ID
     * @param int $personId Person ID to link media to
     * @param array $mediaIds Array of media IDs to link
     * @return array Result with success count and errors
     */
    public function batchLinkMediaToPerson(int $treeId, int $personId, array $mediaIds): array
    {
        $result = [
            'success' => true,
            'linked' => 0,
            'already_linked' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (empty($mediaIds)) {
            $result['success'] = false;
            $result['errors'][] = 'No media IDs provided';
            return $result;
        }

        try {
            // Verify person exists in tree
            $person = DB::selectOne(
                "SELECT id FROM genealogy_persons WHERE id = ? AND tree_id = ?",
                [$personId, $treeId]
            );

            if (!$person) {
                $result['success'] = false;
                $result['errors'][] = 'Person not found in tree';
                return $result;
            }

            // Get existing links
            $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
            $existingLinks = DB::select(
                "SELECT media_id FROM genealogy_person_media
                 WHERE person_id = ? AND media_id IN ({$placeholders})",
                array_merge([$personId], $mediaIds)
            );
            $existingMediaIds = array_column($existingLinks, 'media_id');
            $result['already_linked'] = count($existingMediaIds);

            // Filter to only new links
            $newMediaIds = array_diff($mediaIds, $existingMediaIds);

            if (empty($newMediaIds)) {
                $result['errors'][] = 'All media items already linked to person';
                return $result;
            }

            // Verify media exists in tree
            $placeholders = implode(',', array_fill(0, count($newMediaIds), '?'));
            $validMedia = DB::select(
                "SELECT id FROM genealogy_media WHERE id IN ({$placeholders}) AND tree_id = ?",
                array_merge($newMediaIds, [$treeId])
            );
            $validMediaIds = array_column($validMedia, 'id');

            // Create new links
            $linked = 0;
            foreach ($validMediaIds as $mediaId) {
                try {
                    DB::insert(
                        "INSERT INTO genealogy_person_media (person_id, media_id, created_at) VALUES (?, ?, NOW())",
                        [$personId, $mediaId]
                    );
                    $linked++;
                } catch (Exception $e) {
                    // Skip duplicates silently
                }
            }

            $result['linked'] = $linked;
            $result['failed'] = count($newMediaIds) - $linked;

            Log::info('Batch link media to person completed', [
                'tree_id' => $treeId,
                'person_id' => $personId,
                'linked' => $linked,
            ]);

        } catch (Exception $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error('Batch link media failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Batch delete multiple media items
     *
     * @param int $treeId Tree ID
     * @param array $mediaIds Array of media IDs to delete
     * @param bool $deleteFiles If true, also delete the actual media files
     * @return array Result with success count and errors
     */
    public function batchDeleteMedia(int $treeId, array $mediaIds, bool $deleteFiles = false): array
    {
        $result = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
            'details' => [
                'media_records' => 0,
                'person_links' => 0,
                'face_regions' => 0,
                'files_deleted' => 0,
            ],
        ];

        if (empty($mediaIds)) {
            $result['success'] = false;
            $result['errors'][] = 'No media IDs provided';
            return $result;
        }

        try {
            DB::beginTransaction();

            $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
            $params = array_merge($mediaIds, [$treeId]);

            // Get media records for potential file deletion
            $mediaRecords = [];
            if ($deleteFiles) {
                $mediaRecords = DB::select(
                    "SELECT id, original_path FROM genealogy_media WHERE id IN ({$placeholders}) AND tree_id = ?",
                    $params
                );
            }

            // Delete person-media links
            $linksDeleted = DB::delete(
                "DELETE FROM genealogy_person_media WHERE media_id IN ({$placeholders})",
                $mediaIds
            );
            $result['details']['person_links'] = $linksDeleted;

            // genealogy_face_regions table does not exist — face data lives in file_registry_faces
            $result['details']['face_regions'] = 0;

            // Delete media records
            $mediaDeleted = DB::delete(
                "DELETE FROM genealogy_media WHERE id IN ({$placeholders}) AND tree_id = ?",
                $params
            );
            $result['details']['media_records'] = $mediaDeleted;

            // Update tree media count
            DB::update(
                "UPDATE genealogy_trees SET media_count = media_count - ? WHERE id = ?",
                [$mediaDeleted, $treeId]
            );

            DB::commit();

            // Delete physical files if requested
            if ($deleteFiles && !empty($mediaRecords)) {
                foreach ($mediaRecords as $media) {
                    if (!empty($media->original_path) && file_exists($media->original_path)) {
                        if (unlink($media->original_path)) {
                            $result['details']['files_deleted']++;
                        }
                    }
                }
            }

            $result['deleted'] = $mediaDeleted;
            $result['failed'] = count($mediaIds) - $mediaDeleted;

            Log::info('Batch delete media completed', [
                'tree_id' => $treeId,
                'requested' => count($mediaIds),
                'deleted' => $mediaDeleted,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error('Batch delete media failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Batch assign persons to a specific surname group or category
     *
     * @param int $treeId Tree ID
     * @param string $surname Surname to match
     * @param array $updates Fields to update for all matching persons
     * @return array Result with success count
     */
    public function batchUpdateBySurname(int $treeId, string $surname, array $updates): array
    {
        // Get all person IDs with this surname
        $persons = DB::select(
            "SELECT id FROM genealogy_persons WHERE tree_id = ? AND LOWER(surname) = LOWER(?)",
            [$treeId, $surname]
        );

        if (empty($persons)) {
            return [
                'success' => false,
                'updated' => 0,
                'errors' => ["No persons found with surname: {$surname}"],
            ];
        }

        $personIds = array_column($persons, 'id');
        return $this->batchUpdatePersons($treeId, $personIds, $updates);
    }
}
