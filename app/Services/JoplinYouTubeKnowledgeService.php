<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class JoplinYouTubeKnowledgeService
{
    public function __construct(
        private JoplinFilesService $reader,
        private JoplinWriteService $writer,
        private JoplinQueueService $queue,
        private JoplinLockHandler $locks,
    ) {}

    public function run(bool $apply = false, int $limit = 0, bool $deduplicate = false): array
    {
        if ($deduplicate && $limit !== 0) {
            throw new RuntimeException('Duplicate cleanup requires a full run without --limit');
        }
        $report = $this->organize($apply, $limit);
        if ($deduplicate) {
            $cleanup = $this->deduplicate($apply);
            if ($apply && $cleanup['duplicates_removed'] > 0) {
                $refreshed = $this->organize(true, 0);
                foreach (['applied_moves', 'verified_moves', 'managed_notes_updated'] as $count) {
                    $refreshed[$count] += $report[$count];
                }
                $report = $refreshed;
            }
            $report['duplicate_cleanup'] = $cleanup;
        }

        return $report;
    }

    private function organize(bool $apply, int $limit): array
    {
        if ($limit < 0) {
            throw new RuntimeException('YouTube Knowledge limit cannot be negative');
        }
        $policy = $this->policy();
        if ($apply) {
            $this->assertIdle();
        }

        [$notes, $notebooks] = $this->inventory();
        $managedIds = DB::table('joplin_youtube_knowledge_notes')->pluck('note_id')->flip()->all();
        $reviewMirrorIds = DB::table('joplin_review_items')->pluck('note_id')->flip()->all();
        $topLevel = $this->uniqueNotebooksByTitle(
            $notebooks,
            '',
            array_merge([$policy['root_title'], $policy['inbox_title']], array_keys($policy['topics'])),
        );
        $inbox = $topLevel[$policy['inbox_title']] ?? null;
        if (! $inbox) {
            throw new RuntimeException('Configured top-level Joplin Inbox is missing');
        }

        $sourceRoots = [];
        foreach (array_keys($policy['topics']) as $title) {
            if (isset($topLevel[$title])) {
                $sourceRoots[$topLevel[$title]['id']] = $title;
            }
        }

        $root = $topLevel[$policy['root_title']] ?? null;
        $targetTitles = array_merge(array_keys($policy['topics']), [$policy['review_topic']]);
        $children = $root ? $this->uniqueNotebooksByTitle($notebooks, $root['id'], $targetTitles) : [];
        if ($apply) {
            [$root, $children] = $this->ensureTree($root, $children, $policy, $targetTitles);
        } else {
            $root ??= ['id' => 'DRY_RUN_ROOT', 'title' => $policy['root_title'], 'parent_id' => ''];
            foreach ($targetTitles as $title) {
                $children[$title] ??= ['id' => 'DRY_RUN_'.md5($title), 'title' => $title, 'parent_id' => $root['id']];
            }
        }

        $childTopics = [];
        foreach ($children as $title => $child) {
            $childTopics[$child['id']] = $title;
        }

        $heldNotes = array_fill_keys(array_column(app(YouTubeJoplinDeliveryService::class)->heldSourceWrites(), 'note_id'), true);
        $youtube = [];
        $moves = [];
        foreach ($notes as $note) {
            if (isset($heldNotes[$note['id']]) || isset($managedIds[$note['id']]) || isset($reviewMirrorIds[$note['id']])
                || str_contains($note['content'], '<!-- PLOS:YOUTUBE-KNOWLEDGE:')) {
                continue;
            }
            $videoId = $this->videoId($note);
            if (! $videoId) {
                if (isset($sourceRoots[$note['parent_id']])) {
                    $moves[$note['id']] = ['note' => $note, 'target_id' => $inbox['id'], 'kind' => 'ordinary_to_inbox'];
                }

                continue;
            }

            $topic = $sourceRoots[$note['parent_id']] ?? $childTopics[$note['parent_id']] ?? $this->categorize($note, $policy);
            if (! isset($children[$topic])) {
                $topic = $policy['review_topic'];
            }
            $analysis = $this->analyze($note, $videoId, $topic, $notebooks, $policy);
            $youtube[$note['id']] = $analysis;
            if ($note['parent_id'] !== $children[$topic]['id']) {
                $moves[$note['id']] = ['note' => $note, 'target_id' => $children[$topic]['id'], 'kind' => 'youtube_to_topic'];
            }
        }

        $byVideo = [];
        foreach ($youtube as $noteId => $item) {
            $byVideo[$item['video_id']][] = $noteId;
        }
        foreach ($byVideo as $noteIds) {
            if (count($noteIds) < 2) {
                continue;
            }
            foreach ($noteIds as $noteId) {
                $youtube[$noteId]['issues'][] = 'exact_video_duplicate';
                $youtube[$noteId]['completeness_state'] = 'needs_review';
                $youtube[$noteId]['review_state'] = 'needs_review';
            }
        }

        $report = $this->report($apply, $limit, $youtube, $moves, $sourceRoots);
        if (! $apply) {
            return $report;
        }

        $guard = Cache::lock('joplin-youtube-knowledge', 1800);
        if (! $guard->get()) {
            throw new RuntimeException('Another YouTube Knowledge writer holds the lease');
        }
        try {
            $selected = $limit > 0 ? array_slice($moves, 0, $limit, true) : $moves;
            foreach (array_chunk($selected, $policy['batch_size'], true) as $chunk) {
                $this->withSyncLock(function () use ($chunk, &$report): void {
                    foreach ($chunk as $move) {
                        $this->writer->reviewMoveNote(
                            $move['note']['id'], $move['target_id'], $move['note']['updated_time'],
                        );
                        $report['applied_moves']++;
                    }
                });
            }

            $this->recordInventory($youtube);
            if ($limit === 0 || count($selected) === count($moves)) {
                $indexes = $this->refreshIndexes($youtube, $root['id'], $children, $policy, $notes);
                $report['managed_notes_updated'] = $indexes['updated'];
                $report['managed_index_conflicts'] = $indexes['conflicts'];
                $report['managed_index_conflict_count'] = count($indexes['conflicts']);
                $report['status'] = $indexes['conflicts'] ? 'completed_with_conflicts' : 'verified';
                $report['verified_moves'] = $this->verifyMoves($moves);
            } else {
                $report['status'] = 'pilot';
            }
            $report['queue'] = $this->queue->getStatistics();

            return $report;
        } finally {
            $guard->release();
        }
    }

    /** Plan exact duplicates, then delegate all destructive work to the existing guarded workflow. */
    private function deduplicate(bool $apply): array
    {
        $policy = $this->policy();
        $rewrite = $policy['rewrite'] ?? [];
        if (($rewrite['enabled'] ?? false) !== true
            || ! in_array('preserve_content', $rewrite['allowed_operations'] ?? [], true)) {
            throw new RuntimeException('Source-preserving duplicate cleanup is disabled by policy');
        }
        [$notes, $notebooks, $tagged] = $this->inventory();
        $managed = DB::table('joplin_youtube_knowledge_notes')->pluck('note_id')->flip()->all()
            + DB::table('joplin_review_items')->pluck('note_id')->flip()->all();
        $roots = $this->uniqueNotebooksByTitle($notebooks, '', [$policy['root_title']]);
        $root = $roots[$policy['root_title']] ?? null;
        $children = $root ? $this->uniqueNotebooksByTitle($notebooks, $root['id'], array_merge(array_keys($policy['topics']), [$policy['review_topic']])) : [];
        $topics = array_column($children, 'title', 'id');
        $heldVideos = array_fill_keys(array_column(app(YouTubeJoplinDeliveryService::class)->heldSourceWrites(), 'video_id'), true);
        $groups = [];
        foreach ($notes as $note) {
            if (! isset($managed[$note['id']]) && ($video = $this->reader->youtubeVideoId($note)) !== null) {
                $groups[$video][] = $note;
            }
        }
        ksort($groups);
        $items = $blocked = [];
        $candidates = 0;
        foreach ($groups as $videoId => $group) {
            if (isset($heldVideos[$videoId])) {
                $blocked[] = ['video_id' => $videoId, 'note_ids' => array_column($group, 'id'), 'reason' => 'source_write_outcome_unknown'];

                continue;
            }
            if (count($group) < 2) {
                continue;
            }
            usort($group, fn (array $a, array $b): int => [$a['created_time'], $a['id']] <=> [$b['created_time'], $b['id']]);
            $canonical = $group[0];
            $topic = $topics[$canonical['parent_id']] ?? null;
            $reason = $topic === null ? 'source_outside_knowledge_topics' : null;
            $equivalent = null;
            $hashes = $parents = $wireHashes = [];
            foreach ($group as $note) {
                if (isset($tagged[$note['id']])) {
                    $reason = 'tagged_source_requires_review';
                    break;
                }
                $snapshot = $this->writer->reviewSnapshot($note['id'].'.md');
                if ($snapshot === null) {
                    throw new RuntimeException('Duplicate source disappeared during planning');
                }
                $exact = $this->writer->reviewNoteExactFields($snapshot['body'], $note['id']);
                if ($exact['updated_time'] !== $note['updated_time'] || $exact['parent_id'] !== $note['parent_id'] || $exact['title'] !== $note['title']) {
                    throw new RuntimeException('Duplicate source changed during planning');
                }
                // Keep unknown properties and ordering; ignore only protocol identity/timestamps.
                $metadata = preg_replace('/^(?:id|parent_id|created_time|updated_time|user_created_time|user_updated_time):[^\r\n]*(?:\r?\n|$)/m', '', substr($snapshot['body'], $exact['footer_offset']));
                $content = $note['title']."\n".$this->duplicateBody($exact['content'])."\n".$metadata;
                $signature = hash('sha256', $content);
                if ($equivalent !== null && ! hash_equals($equivalent, $signature)) {
                    $reason = 'different_content_or_metadata';
                    break;
                }
                $equivalent = $signature;
                $hashes[$note['id']] = hash('sha256', $note['title']."\n".$note['content']);
                $parents[$note['id']] = $note['parent_id'];
                $wireHashes[$note['id']] = $snapshot['hash'];
            }
            if ($reason !== null) {
                $blocked[] = ['video_id' => $videoId, 'note_ids' => array_column($group, 'id'), 'reason' => $reason];

                continue;
            }
            if (strlen($canonical['content']) > ($rewrite['max_body_bytes'] ?? 0) || count($items) >= ($rewrite['max_items'] ?? 0)) {
                $blocked[] = ['video_id' => $videoId, 'note_ids' => array_column($group, 'id'), 'reason' => 'rewrite_policy_limit'];

                continue;
            }
            $analysis = $this->analyze($canonical, $videoId, $topic, $notebooks, $policy);
            $items[] = [
                'operation' => 'preserve_content', 'video_id' => $videoId,
                'classification' => $analysis['format_class'] === 'general' ? 'other' : $analysis['format_class'],
                'target_topic' => $topic, 'title' => $canonical['title'], 'body' => $canonical['content'],
                'canonical_note_id' => $canonical['id'], 'source_note_ids' => array_column($group, 'id'),
                'source_hashes' => $hashes, 'source_parent_ids' => $parents,
                'source_wire_hashes' => $wireHashes, 'require_untagged' => true,
            ];
            $candidates += count($group) - 1;
        }
        $report = [
            'status' => $apply ? 'no_op' : 'preview', 'candidate_groups' => count($items),
            'duplicate_notes_to_remove' => $candidates, 'duplicates_removed' => 0,
            'needs_review' => count($blocked), 'blocked_groups' => $blocked,
        ];
        if (! $apply || $items === []) {
            return $report;
        }
        $bundle = json_encode(['schema' => 'plos.joplin-youtube-rewrite.v1', 'items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (strlen($bundle) > ($rewrite['max_bundle_bytes'] ?? 0)
            || ! preg_match('~^[A-Za-z0-9/_-]+$~D', $rewrite['backup_relative'] ?? '')) {
            throw new RuntimeException('Weekly duplicate bundle exceeds rewrite policy');
        }
        $directory = storage_path('app/private/'.$rewrite['backup_relative'].'/weekly-bundles');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private weekly duplicate bundle directory');
        }
        chmod($directory, 0700);
        $path = $directory.'/'.hash('sha256', $bundle).'.json';
        if (is_file($path) && ! hash_equals(hash_file('sha256', $path), hash('sha256', $bundle))) {
            throw new RuntimeException('Weekly duplicate bundle changed');
        }
        if (! is_file($path) && file_put_contents($path, $bundle, LOCK_EX) === false) {
            throw new RuntimeException('Cannot save private weekly duplicate bundle');
        }
        chmod($path, 0600);
        $service = app(JoplinYouTubeRewriteService::class);
        $preview = $service->run($path, false);
        if (($preview['canonical_updates'] ?? -1) !== 0) {
            throw new RuntimeException('Automatic duplicate cleanup would change canonical content');
        }
        $result = $service->run($path, true);
        $report['status'] = $result['status'];
        $report['duplicates_removed'] = $result['duplicates_removed'];
        $report['backed_up_notes'] = $result['backed_up_notes'];
        $report['bundle_hash'] = $result['bundle_hash'];

        return $report;
    }

    /** Only this machine-generated header timestamp is irrelevant to source equivalence. */
    private function duplicateBody(string $body): string
    {
        if (! str_ends_with(rtrim($body, "\n"), '*Auto-generated by PLOS YouTube Integration*')) {
            return $body;
        }
        $boundary = strpos($body, "\n---\n");
        if ($boundary === false) {
            return $body;
        }
        $header = preg_replace('/^\*\*Processed:\*\* [0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/m', '**Processed:** {generated timestamp}', substr($body, 0, $boundary));

        return $header.substr($body, $boundary);
    }

    private function policy(): array
    {
        $raw = DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'youtube_knowledge')->value('config_value');
        $policy = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        foreach (['root_title', 'inbox_title', 'review_topic', 'edited_label'] as $key) {
            if (! is_string($policy[$key] ?? null) || trim($policy[$key]) === '') {
                throw new RuntimeException('Invalid YouTube Knowledge policy: '.$key);
            }
        }
        if (! is_array($policy['topics'] ?? null) || ! $policy['topics']
            || ! is_int($policy['minimum_transcript_words'] ?? null) || $policy['minimum_transcript_words'] < 1
            || ! is_int($policy['batch_size'] ?? null) || $policy['batch_size'] < 1 || $policy['batch_size'] > 100) {
            throw new RuntimeException('Invalid YouTube Knowledge policy');
        }

        return $policy;
    }

    private function inventory(): array
    {
        $notes = $notebooks = $tagged = [];
        foreach ($this->reader->listNotes(true) as $filename) {
            $item = $this->reader->getNote(substr($filename, 0, -3), true);
            if (! $item || $item['id'] !== substr($filename, 0, -3) || ! preg_match('/^[a-f0-9]{32}$/D', $item['id'])) {
                throw new RuntimeException('Incomplete YouTube Knowledge inventory; no changes applied');
            }
            if ($item['type'] === 1) {
                $notes[$item['id']] = $item;
            } elseif ($item['type'] === 2) {
                $notebooks[$item['id']] = $item;
            } elseif ($item['type'] === 6 && isset($item['metadata']['note_id'])) {
                $tagged[$item['metadata']['note_id']] = true;
            }
        }

        return [$notes, $notebooks, $tagged];
    }

    private function uniqueNotebooksByTitle(array $notebooks, string $parentId, ?array $wanted = null): array
    {
        $out = [];
        foreach ($notebooks as $notebook) {
            if ($notebook['parent_id'] !== $parentId || ($wanted !== null && ! in_array($notebook['title'], $wanted, true))) {
                continue;
            }
            if (isset($out[$notebook['title']])) {
                throw new RuntimeException('Ambiguous Joplin notebook: '.$notebook['title']);
            }
            $out[$notebook['title']] = $notebook;
        }

        return $out;
    }

    private function ensureTree(?array $root, array $children, array $policy, array $targetTitles): array
    {
        $this->withSyncLock(function () use (&$root, &$children, $policy, $targetTitles): void {
            if (! $root) {
                $created = $this->writer->createNotebook($policy['root_title']);
                if (! ($created['success'] ?? false)) {
                    throw new RuntimeException('Cannot create YouTube Knowledge notebook');
                }
                $root = ['id' => $created['notebook_id'], 'title' => $policy['root_title'], 'parent_id' => ''];
            }
            foreach ($targetTitles as $title) {
                if (isset($children[$title])) {
                    continue;
                }
                $created = $this->writer->createNotebook($title, $root['id']);
                if (! ($created['success'] ?? false)) {
                    throw new RuntimeException('Cannot create YouTube Knowledge topic: '.$title);
                }
                $children[$title] = ['id' => $created['notebook_id'], 'title' => $title, 'parent_id' => $root['id']];
            }
        });

        return [$root, $children];
    }

    private function videoId(array $note): ?string
    {
        return preg_match('/(?:youtube\.com\/watch\?[^\s)]*v=|youtu\.be\/|youtube\.com\/embed\/)([A-Za-z0-9_-]{11})/', $note['title'].' '.$note['content'], $match)
            ? $match[1]
            : null;
    }

    private function categorize(array $note, array $policy): string
    {
        $text = strtolower($note['title'].' '.$note['content']);
        $scores = [];
        foreach ($policy['topics'] as $topic => $terms) {
            $scores[$topic] = count(array_filter($terms, fn ($term) => str_contains($text, strtolower((string) $term))));
        }
        arsort($scores);

        return reset($scores) > 0 ? (string) array_key_first($scores) : $policy['review_topic'];
    }

    private function analyze(array $note, string $videoId, string $topic, array $notebooks, array $policy): array
    {
        $content = $note['content'];
        $transcript = '';
        if (preg_match('/## Full Transcript.*?(?:- Language:[^\r\n]*\R\R)?(.*?)(?=\R\R---\R\R## Metadata|\z)/si', $content, $match)) {
            $transcript = trim($match[1]);
        }
        $words = str_word_count(strip_tags($transcript));
        $issues = [];
        if ($transcript === '' || str_contains(strtolower($transcript), 'transcript not available')) {
            $issues[] = 'missing_transcript';
        } elseif ($words < $policy['minimum_transcript_words']) {
            $issues[] = 'short_transcript';
        }
        if (! str_contains($content, '## Key Points')) {
            $issues[] = 'missing_key_points';
        } elseif (preg_match('/## Key Points.*?(?:to be generated|pending|placeholder)/si', $content)) {
            $issues[] = 'placeholder_key_points';
        }
        $lower = strtolower($note['title'].' '.$content);
        $format = 'general';
        if ($topic === 'Food & Recipes' && $this->containsAny($lower, $policy['food_recipe_terms'] ?? [])) {
            $format = 'food_recipe';
        } elseif ($this->containsAny($lower, $policy['nonfood_formula_terms'] ?? [])) {
            $format = 'nonfood_formula';
        }
        $parent = $notebooks[$note['parent_id']]['title'] ?? null;

        return [
            'note_id' => $note['id'], 'title' => $note['title'], 'video_id' => $videoId,
            'url' => 'https://www.youtube.com/watch?v='.$videoId, 'topic' => $topic,
            'format_class' => $format, 'transcript_words' => $words, 'issues' => $issues,
            'completeness_state' => $issues ? 'needs_review' : 'complete',
            'review_state' => $issues ? 'needs_review' : 'ready',
            'source_hash' => hash('sha256', $note['title']."\n".$note['content']),
            'provenance' => [
                'source_url' => 'https://www.youtube.com/watch?v='.$videoId,
                'source_notebook_id' => $note['parent_id'], 'source_notebook_title' => $parent,
                'source_updated_time' => $note['updated_time'], 'original_content_hash' => hash('sha256', $note['content']),
                'creator' => null, 'creator_supporting_links_reviewed' => false, 'user_comments_ingested' => false,
            ],
        ];
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, strtolower((string) $term))) {
                return true;
            }
        }

        return false;
    }

    private function report(bool $apply, int $limit, array $youtube, array $moves, array $sourceRoots): array
    {
        $kinds = array_count_values(array_column($moves, 'kind'));
        $notesByTopic = array_count_values(array_column($youtube, 'topic'));
        $duplicatesByTopic = [];
        foreach ($youtube as $item) {
            if (in_array('exact_video_duplicate', $item['issues'], true)) {
                $duplicatesByTopic[$item['topic']] = ($duplicatesByTopic[$item['topic']] ?? 0) + 1;
            }
        }
        ksort($notesByTopic);
        ksort($duplicatesByTopic);

        return [
            'status' => $apply ? 'applying' : 'preview', 'dry_run' => ! $apply, 'limit' => $limit,
            'source_topic_roots' => count($sourceRoots), 'youtube_notes' => count($youtube),
            'youtube_moves' => $kinds['youtube_to_topic'] ?? 0,
            'ordinary_notes_to_inbox' => $kinds['ordinary_to_inbox'] ?? 0,
            'planned_moves' => count($moves), 'applied_moves' => 0, 'verified_moves' => 0,
            'needs_review' => count(array_filter($youtube, fn ($item) => $item['review_state'] === 'needs_review')),
            'complete' => count(array_filter($youtube, fn ($item) => $item['completeness_state'] === 'complete')),
            'food_recipe_candidates' => count(array_filter($youtube, fn ($item) => $item['format_class'] === 'food_recipe')),
            'nonfood_formula_candidates' => count(array_filter($youtube, fn ($item) => $item['format_class'] === 'nonfood_formula')),
            'exact_duplicate_notes' => count(array_filter($youtube, fn ($item) => in_array('exact_video_duplicate', $item['issues'], true))),
            'notes_by_topic' => $notesByTopic,
            'exact_duplicate_notes_by_topic' => $duplicatesByTopic,
            'managed_notes_updated' => 0, 'failed_operations' => 0,
            'managed_index_conflict_count' => 0, 'managed_index_conflicts' => [],
        ];
    }

    private function recordInventory(array $youtube): void
    {
        DB::transaction(function () use ($youtube): void {
            DB::table('joplin_youtube_knowledge_items')->update(['present' => false, 'updated_at' => now()]);
            foreach ($youtube as $item) {
                DB::table('joplin_youtube_knowledge_items')->upsert([[
                    'note_id' => $item['note_id'], 'video_id' => $item['video_id'], 'topic' => $item['topic'],
                    'format_class' => $item['format_class'], 'completeness_state' => $item['completeness_state'],
                    'review_state' => $item['review_state'], 'source_hash' => $item['source_hash'],
                    'transcript_words' => $item['transcript_words'], 'issues' => json_encode($item['issues']),
                    'provenance' => json_encode($item['provenance'], JSON_UNESCAPED_SLASHES), 'present' => true,
                    'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]], ['note_id'], [
                    'video_id', 'topic', 'format_class', 'completeness_state', 'review_state', 'source_hash',
                    'transcript_words', 'issues', 'provenance', 'present', 'last_seen_at', 'updated_at',
                ]);
            }
        });
    }

    private function refreshIndexes(array $youtube, string $rootId, array $children, array $policy, array $allNotes): array
    {
        $existing = [];
        foreach ($allNotes as $note) {
            $existing[$note['parent_id'].'|'.$note['title']] = $note;
        }
        $groups = [];
        foreach ($youtube as $item) {
            $groups[$item['topic']][] = $item;
        }
        $indexIds = [];
        $updated = 0;
        $conflicts = [];
        $this->withSyncLock(function () use ($groups, $children, $policy, $rootId, $existing, &$indexIds, &$updated, &$conflicts, $youtube): void {
            foreach (array_merge(array_keys($policy['topics']), [$policy['review_topic']]) as $topic) {
                $items = $groups[$topic] ?? [];
                usort($items, fn ($left, $right) => strcasecmp($left['title'], $right['title']));
                $body = "<!-- PLOS:YOUTUBE-KNOWLEDGE:topic -->\n"
                    ."# {$topic}\n\nSource index maintained by PLOS. Each source stays separate; follow the note and YouTube links for attribution.\n\n";
                foreach ($items as $item) {
                    $flags = $item['issues'] ? ' — review: '.implode(', ', $item['issues']) : ' — complete audit';
                    $body .= '- ['.$this->markdown($item['title']).'](:/'.$item['note_id'].') · [YouTube]('.$item['url'].')'.$flags."\n";
                }
                if ($topic === 'Food & Recipes') {
                    $body .= "\nFood recipes remain separate. Later cleanup uses US cups/tbsp/tsp/oz/lb and °F, retains source metric values in parentheses, and never guesses missing amounts.\n";
                }
                $key = 'topic:'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $topic));
                [$noteId, $changed, $conflict] = $this->upsertManagedNote($key, $topic.' — Source Index', $body, $children[$topic]['id'], $existing);
                if ($conflict !== null) {
                    $conflicts[] = $conflict;
                }
                $indexIds[$topic] = $noteId;
                $updated += (int) $changed;
            }

            $review = array_filter($youtube, fn ($item) => $item['review_state'] === 'needs_review');
            $body = "<!-- PLOS:YOUTUBE-KNOWLEDGE:root -->\n# YouTube Knowledge\n\n"
                .'PLOS audited '.count($youtube).' source notes; '.count($review)." need content review. Creator materials may be retained with attribution; user comments are excluded. Rewritten transcripts replace the old transcript text and retain its hash, source link, creator, retrieval date, and the label \"{$policy['edited_label']}\".\n\n## Topic indexes\n\n";
            foreach ($indexIds as $topic => $noteId) {
                $body .= '- ['.$this->markdown($topic).'](:/'.$noteId.')'."\n";
            }
            [$noteId, $changed, $conflict] = $this->upsertManagedNote('root:index', '00 — YouTube Knowledge', $body, $rootId, $existing);
            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }
            $updated += (int) $changed;
        });

        return ['updated' => $updated, 'conflicts' => $conflicts];
    }

    private function upsertManagedNote(string $key, string $title, string $body, string $parentId, array $existing): array
    {
        $row = DB::table('joplin_youtube_knowledge_notes')->where('note_key', $key)->first();
        $desired = hash('sha256', $title."\n".$body."\n".$parentId);
        if (! $row) {
            if (isset($existing[$parentId.'|'.$title])) {
                throw new RuntimeException('Unowned YouTube Knowledge index already exists: '.$title);
            }
            $result = $this->writer->createNote($title, $body, $parentId, [], true);
            if (! ($result['success'] ?? false) || ($result['queued'] ?? false)) {
                throw new RuntimeException('Cannot create YouTube Knowledge index: '.$title);
            }
            DB::table('joplin_youtube_knowledge_notes')->insert([
                'note_key' => $key, 'note_id' => $result['note_id'], 'parent_key' => $parentId,
                'title' => $title, 'remote_hash' => $desired, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$result['note_id'], true, null];
        }
        $snapshot = $this->writer->reviewSnapshot($row->note_id.'.md');
        if ($snapshot === null) {
            throw new RuntimeException('Owned YouTube Knowledge index is missing: '.$title);
        }
        $current = $this->writer->reviewNoteExactFields($snapshot['body'], $row->note_id);
        $currentHash = hash('sha256', $current['title']."\n".$current['content']."\n".$current['parent_id']);
        if (! $row->remote_hash || ! hash_equals($row->remote_hash, $currentHash)) {
            return [$row->note_id, false, [
                'note_key' => $key, 'note_id' => $row->note_id, 'title' => $title,
                'reason' => 'operator_edit_preserved',
            ]];
        }
        if (hash_equals($desired, $currentHash)) {
            return [$row->note_id, false, null];
        }
        $result = $this->writer->updateNote($row->note_id, [
            'title' => $title, 'content' => $body, 'parent_id' => $parentId,
            'expected_updated_time' => $current['updated_time'],
        ], true, true);
        if (! ($result['success'] ?? false) || ($result['queued'] ?? false)) {
            throw new RuntimeException('Cannot update YouTube Knowledge index: '.$title);
        }
        DB::table('joplin_youtube_knowledge_notes')->where('note_key', $key)->update([
            'parent_key' => $parentId, 'title' => $title, 'remote_hash' => $desired, 'updated_at' => now(),
        ]);

        return [$row->note_id, true, null];
    }

    private function verifyMoves(array $moves): int
    {
        $verified = 0;
        foreach ($moves as $move) {
            $note = $this->reader->getNote($move['note']['id']);
            if (! $note || $note['parent_id'] !== $move['target_id']) {
                throw new RuntimeException('YouTube Knowledge move read-back failed: '.$move['note']['id']);
            }
            $verified++;
        }

        return $verified;
    }

    private function assertIdle(): void
    {
        $queue = $this->queue->getStatistics();
        $locks = $this->locks->getLockStatus();
        if (($queue['pending'] ?? 0) || ($queue['processing'] ?? 0) || ($queue['failed'] ?? 0)
            || ($locks['sync_locks'] ?? 0) || ($locks['exclusive_locks'] ?? 0)) {
            throw new RuntimeException('Joplin queue or sync lock busy; retry after normal sync completes');
        }
    }

    private function withSyncLock(callable $action): mixed
    {
        $this->assertIdle();
        $lock = $this->locks->acquireSyncLock();
        try {
            return $action();
        } finally {
            $this->locks->releaseLock($lock);
        }
    }

    private function markdown(string $value): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], trim($value));
    }
}
