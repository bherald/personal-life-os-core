<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to review and manage pending face match approvals.
 *
 * Lists pending fuzzy matches (nicknames, typos, SOUNDEX) that require
 * human review before linking faces to persons in the genealogy system.
 *
 * Sprint 2: Face Match Approval Queue
 */
class GenealogyReviewFaceMatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'genealogy:review-face-matches
                            {--tree-id=4 : Tree ID to review (default: 4)}
                            {--status=pending : Filter by status (pending, approved, rejected, all)}
                            {--limit=50 : Number of matches to display}
                            {--approve= : Approve queue entry by ID}
                            {--reject= : Reject queue entry by ID}
                            {--approve-all-type= : Auto-approve all pending matches of a type (typo, nickname, soundex)}
                            {--confirm : Explicitly confirm a non-interactive approve or reject mutation}
                            {--confirm-bulk : Explicitly confirm a non-interactive bulk approval mutation}
                            {--stats : Show queue statistics only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Review and manage pending face match approvals for genealogy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $treeId = (int) $this->option('tree-id');
        $status = $this->option('status');
        $limit = (int) $this->option('limit');
        $approveId = $this->option('approve');
        $rejectId = $this->option('reject');
        $approveAllType = $this->option('approve-all-type');
        $confirmed = (bool) $this->option('confirm');
        $confirmBulk = (bool) $this->option('confirm-bulk');
        $showStats = $this->option('stats');

        // Handle approve action
        if ($approveId) {
            return $this->approveMatch((int) $approveId, $confirmed);
        }

        // Handle reject action
        if ($rejectId) {
            return $this->rejectMatch((int) $rejectId, $confirmed);
        }

        // Handle bulk approve by type
        if ($approveAllType) {
            return $this->approveAllByType($treeId, $approveAllType, $confirmBulk);
        }

        // Show stats
        if ($showStats) {
            return $this->showStats($treeId);
        }

        // Default: List pending matches
        return $this->listMatches($treeId, $status, $limit);
    }

    /**
     * List pending face matches.
     */
    private function listMatches(int $treeId, string $status, int $limit): int
    {
        $validStatuses = ['pending', 'approved', 'rejected', 'ignored', 'all'];
        if (! in_array($status, $validStatuses, true)) {
            $this->error("Invalid status: {$status}");
            $this->info('Valid statuses: '.implode(', ', $validStatuses));

            return Command::FAILURE;
        }

        $this->info('Face Match Approval Queue');
        $this->info('=========================');
        $this->info("Tree ID: {$treeId}");
        $this->info("Status filter: {$status}");
        $this->newLine();

        $statusFilter = $status === 'all' ? '' : 'AND q.status = ?';
        $statusParams = $status === 'all' ? [] : [$status];

        $sql = "SELECT
                    q.id,
                    q.face_name,
                    q.match_type,
                    q.confidence_score,
                    q.status,
                    q.created_at,
                    p.given_name,
                    p.surname,
                    p.id as person_id,
                    m.local_filename as file_name,
                    m.nextcloud_path
                FROM genealogy_face_match_queue q
                LEFT JOIN genealogy_persons p ON q.suggested_person_id = p.id
                LEFT JOIN genealogy_media m ON q.media_id = m.id
                WHERE q.tree_id = ? {$statusFilter}
                ORDER BY q.confidence_score DESC, q.created_at ASC
                LIMIT ?";

        $matches = DB::select($sql, array_merge([$treeId], $statusParams, [$limit]));

        if (empty($matches)) {
            $this->info("No {$status} matches found for tree {$treeId}.");

            return Command::SUCCESS;
        }

        $tableData = [];
        foreach ($matches as $match) {
            $suggestedPerson = $match->given_name
                ? "{$match->given_name} {$match->surname} (#{$match->person_id})"
                : '(no suggestion)';

            $tableData[] = [
                $match->id,
                $match->face_name,
                $suggestedPerson,
                $match->match_type,
                number_format($match->confidence_score, 0).'%',
                $match->status,
                $match->file_name ?? 'N/A',
            ];
        }

        $this->table(
            ['ID', 'Face Name', 'Suggested Person', 'Match Type', 'Confidence', 'Status', 'File'],
            $tableData
        );

        $this->newLine();
        $this->info("Showing {$limit} matches. Use --limit=N to see more.");
        $this->newLine();
        $this->info('Actions:');
        $this->info('  Approve: php artisan genealogy:review-face-matches --approve=ID --confirm');
        $this->info('  Reject:  php artisan genealogy:review-face-matches --reject=ID --confirm');
        $this->info('  Bulk:    php artisan genealogy:review-face-matches --approve-all-type=typo --confirm-bulk');

        return Command::SUCCESS;
    }

    /**
     * Show queue statistics.
     */
    private function showStats(int $treeId): int
    {
        $this->info('Face Match Queue Statistics');
        $this->info('===========================');
        $this->info("Tree ID: {$treeId}");
        $this->newLine();

        // Status counts
        $statusCounts = DB::select(
            'SELECT status, COUNT(*) as count
             FROM genealogy_face_match_queue
             WHERE tree_id = ?
             GROUP BY status',
            [$treeId]
        );

        $statusTable = [];
        $total = 0;
        foreach ($statusCounts as $row) {
            $statusTable[] = [ucfirst($row->status), $row->count];
            $total += $row->count;
        }
        $statusTable[] = ['TOTAL', $total];

        $this->info('By Status:');
        $this->table(['Status', 'Count'], $statusTable);

        // Match type counts (pending only)
        $typeCounts = DB::select(
            "SELECT match_type, COUNT(*) as count, AVG(confidence_score) as avg_confidence
             FROM genealogy_face_match_queue
             WHERE tree_id = ? AND status = 'pending'
             GROUP BY match_type
             ORDER BY count DESC",
            [$treeId]
        );

        if (! empty($typeCounts)) {
            $this->newLine();
            $this->info('Pending by Match Type:');
            $typeTable = [];
            foreach ($typeCounts as $row) {
                $typeTable[] = [
                    $row->match_type,
                    $row->count,
                    number_format($row->avg_confidence, 1).'%',
                ];
            }
            $this->table(['Match Type', 'Count', 'Avg Confidence'], $typeTable);
        }

        // Recent activity
        $recentApproved = DB::selectOne(
            "SELECT COUNT(*) as count FROM genealogy_face_match_queue
             WHERE tree_id = ? AND status = 'approved' AND reviewed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$treeId]
        );

        $recentRejected = DB::selectOne(
            "SELECT COUNT(*) as count FROM genealogy_face_match_queue
             WHERE tree_id = ? AND status = 'rejected' AND reviewed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$treeId]
        );

        $this->newLine();
        $this->info('Last 24 Hours:');
        $this->info("  Approved: {$recentApproved->count}");
        $this->info("  Rejected: {$recentRejected->count}");

        return Command::SUCCESS;
    }

    /**
     * Approve a face match and link the person to media.
     */
    private function approveMatch(int $queueId, bool $confirmed): int
    {
        $match = DB::selectOne(
            'SELECT q.*, m.id as media_id
             FROM genealogy_face_match_queue q
             JOIN genealogy_media m ON q.media_id = m.id
             WHERE q.id = ?',
            [$queueId]
        );

        if (! $match) {
            $this->error("Queue entry #{$queueId} not found.");

            return Command::FAILURE;
        }

        if ($match->status !== 'pending') {
            $this->warn("Queue entry #{$queueId} is already {$match->status}.");

            return Command::SUCCESS;
        }

        if (! $match->suggested_person_id) {
            $this->error("Queue entry #{$queueId} has no suggested person. Cannot approve.");
            $this->info("Use --reject={$queueId} to reject, or manually link via API.");

            return Command::FAILURE;
        }

        // Get person info for display
        $person = DB::selectOne(
            'SELECT given_name, surname FROM genealogy_persons WHERE id = ?',
            [$match->suggested_person_id]
        );

        $this->info('Approving match:');
        $this->info("  Face Name: {$match->face_name}");
        $this->info("  Person: {$person->given_name} {$person->surname} (#{$match->suggested_person_id})");
        $this->info("  Match Type: {$match->match_type}");
        $this->info("  Confidence: {$match->confidence_score}%");

        if (! $this->confirmMutation('Confirm approval?', $confirmed)) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        try {
            DB::beginTransaction();

            // Decode face region from JSON
            $faceRegion = $match->face_region ? json_decode($match->face_region, true) : [];

            // Create person_media link
            $existing = DB::selectOne(
                'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                [$match->suggested_person_id, $match->media_id]
            );

            if (! $existing) {
                DB::insert(
                    'INSERT INTO genealogy_person_media
                     (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h,
                      face_confirmed, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                    [
                        $match->suggested_person_id,
                        $match->media_id,
                        $faceRegion['x'] ?? null,
                        $faceRegion['y'] ?? null,
                        $faceRegion['w'] ?? null,
                        $faceRegion['h'] ?? null,
                    ]
                );
            }

            // Update queue status
            DB::update(
                "UPDATE genealogy_face_match_queue
                 SET status = 'approved', reviewed_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [$queueId]
            );

            DB::commit();

            $this->info('Approved! Person linked to media.');

            Log::info('Face match approved via CLI', [
                'queue_id' => $queueId,
                'person_id' => $match->suggested_person_id,
                'media_id' => $match->media_id,
                'face_name' => $match->face_name,
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Failed to approve: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Reject a face match.
     */
    private function rejectMatch(int $queueId, bool $confirmed): int
    {
        $match = DB::selectOne(
            'SELECT * FROM genealogy_face_match_queue WHERE id = ?',
            [$queueId]
        );

        if (! $match) {
            $this->error("Queue entry #{$queueId} not found.");

            return Command::FAILURE;
        }

        if ($match->status !== 'pending') {
            $this->warn("Queue entry #{$queueId} is already {$match->status}.");

            return Command::SUCCESS;
        }

        $this->info('Rejecting match:');
        $this->info("  Face Name: {$match->face_name}");
        $this->info("  Match Type: {$match->match_type}");

        if (! $this->confirmMutation('Confirm rejection?', $confirmed)) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        DB::update(
            "UPDATE genealogy_face_match_queue
             SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$queueId]
        );

        $this->info('Rejected!');

        Log::info('Face match rejected via CLI', [
            'queue_id' => $queueId,
            'face_name' => $match->face_name,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Approve all pending matches of a specific type.
     */
    private function approveAllByType(int $treeId, string $matchType, bool $confirmBulk): int
    {
        $validTypes = ['typo', 'nickname', 'soundex', 'no_match'];
        if (! in_array($matchType, $validTypes, true)) {
            $this->error("Invalid match type: {$matchType}");
            $this->info('Valid types: '.implode(', ', $validTypes));

            return Command::FAILURE;
        }

        // Get count and show sample
        $count = DB::selectOne(
            "SELECT COUNT(*) as count FROM genealogy_face_match_queue
             WHERE tree_id = ? AND status = 'pending' AND match_type = ?",
            [$treeId, $matchType]
        );

        if ($count->count === 0) {
            $this->info("No pending {$matchType} matches found.");

            return Command::SUCCESS;
        }

        // Show sample matches
        $samples = DB::select(
            "SELECT q.face_name, q.confidence_score, p.given_name, p.surname
             FROM genealogy_face_match_queue q
             LEFT JOIN genealogy_persons p ON q.suggested_person_id = p.id
             WHERE q.tree_id = ? AND q.status = 'pending' AND q.match_type = ?
             ORDER BY q.confidence_score DESC
             LIMIT 5",
            [$treeId, $matchType]
        );

        $this->warn("About to approve {$count->count} {$matchType} matches.");
        $this->newLine();
        $this->info('Sample matches:');
        foreach ($samples as $sample) {
            $person = $sample->given_name
                ? "{$sample->given_name} {$sample->surname}"
                : '(no suggestion)';
            $this->info("  - {$sample->face_name} => {$person} ({$sample->confidence_score}%)");
        }

        if ($count->count > 5) {
            $this->info('  ... and '.($count->count - 5).' more');
        }

        $this->newLine();

        // Only allow bulk approval for matches with suggestions
        if ($matchType === 'no_match') {
            $this->error("Cannot bulk approve 'no_match' entries - they have no suggested person.");

            return Command::FAILURE;
        }

        if (! $confirmBulk) {
            $this->error('Bulk approval requires --confirm-bulk.');

            return Command::FAILURE;
        }

        if (! $this->confirmMutation("Approve all {$count->count} {$matchType} matches?", true)) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->info('Processing...');

        // Process in batches
        $approved = 0;
        $failed = 0;

        $matches = DB::select(
            "SELECT q.id, q.media_id, q.suggested_person_id, q.face_region
             FROM genealogy_face_match_queue q
             WHERE q.tree_id = ? AND q.status = 'pending' AND q.match_type = ?
               AND q.suggested_person_id IS NOT NULL",
            [$treeId, $matchType]
        );

        foreach ($matches as $match) {
            try {
                $faceRegion = $match->face_region ? json_decode($match->face_region, true) : [];

                // Check if link already exists
                $existing = DB::selectOne(
                    'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                    [$match->suggested_person_id, $match->media_id]
                );

                if (! $existing) {
                    DB::insert(
                        'INSERT INTO genealogy_person_media
                         (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h,
                          face_confirmed, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                        [
                            $match->suggested_person_id,
                            $match->media_id,
                            $faceRegion['x'] ?? null,
                            $faceRegion['y'] ?? null,
                            $faceRegion['w'] ?? null,
                            $faceRegion['h'] ?? null,
                        ]
                    );
                }

                DB::update(
                    "UPDATE genealogy_face_match_queue
                     SET status = 'approved', reviewed_at = NOW(), updated_at = NOW()
                     WHERE id = ?",
                    [$match->id]
                );

                $approved++;

            } catch (\Exception $e) {
                $failed++;
                Log::error('Failed to approve queue entry', [
                    'queue_id' => $match->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('Bulk approval complete!');
        $this->info("  Approved: {$approved}");
        if ($failed > 0) {
            $this->warn("  Failed: {$failed}");
        }

        Log::info('Bulk face match approval via CLI', [
            'tree_id' => $treeId,
            'match_type' => $matchType,
            'approved' => $approved,
            'failed' => $failed,
        ]);

        return Command::SUCCESS;
    }

    private function confirmMutation(string $question, bool $confirmed): bool
    {
        if ($this->input->isInteractive()) {
            return (bool) $this->confirm($question, false);
        }

        if ($confirmed) {
            return true;
        }

        $this->error('Non-interactive face-review mutations require --confirm or --confirm-bulk.');

        return false;
    }
}
