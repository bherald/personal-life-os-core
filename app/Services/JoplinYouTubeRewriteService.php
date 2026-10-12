<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class JoplinYouTubeRewriteService
{
    public function __construct(
        private JoplinFilesService $reader,
        private JoplinWriteService $writer,
        private JoplinQueueService $queue,
        private JoplinLockHandler $locks,
    ) {}

    public function run(string $bundlePath, bool $apply = false, int $limit = 0): array
    {
        if ($limit < 0) {
            throw new RuntimeException('YouTube rewrite limit cannot be negative');
        }
        $policy = $this->policy();
        $rewrite = $policy['rewrite'];
        if (! $rewrite['enabled']) {
            throw new RuntimeException('YouTube rewriting is disabled by table policy');
        }
        if (! is_file($bundlePath) || filesize($bundlePath) > $rewrite['max_bundle_bytes']) {
            throw new RuntimeException('YouTube rewrite bundle outside admission limits');
        }
        $bundleHash = hash_file('sha256', $bundlePath);
        $items = $this->validateBundle(
            json_decode((string) file_get_contents($bundlePath), true, 512, JSON_THROW_ON_ERROR),
            $policy,
        );
        $topics = $this->topicNotebooks($policy);
        $plans = $this->plans($items, $topics, $bundleHash);
        $selected = $limit > 0 ? array_slice($plans, 0, $limit) : $plans;
        $report = [
            'status' => $apply ? 'applying' : 'preview',
            'dry_run' => ! $apply,
            'bundle_hash' => $bundleHash,
            'items' => count($plans),
            'selected_items' => count($selected),
            'canonical_updates' => count(array_filter($selected, fn (array $plan): bool => $plan['update_canonical'])),
            'duplicate_notes_to_remove' => array_sum(array_map(fn (array $plan): int => count($plan['duplicates']), $selected)),
            'already_verified' => count(array_filter($selected, fn (array $plan): bool => $plan['already_verified'])),
            'classifications' => array_count_values(array_column($selected, 'classification')),
            'operations' => array_count_values(array_column($selected, 'operation')),
            'updated' => 0,
            'duplicates_removed' => 0,
            'backed_up_notes' => 0,
            'failed_operations' => 0,
        ];
        if (! $apply) {
            return $report;
        }

        $this->writer->assertReviewTargetCompatible();
        $this->assertIdle();
        $guard = Cache::lock('joplin-youtube-rewrite', 1800);
        if (! $guard->get()) {
            throw new RuntimeException('Another YouTube rewrite writer holds the lease');
        }
        try {
            foreach (array_chunk($selected, $rewrite['batch_size']) as $chunk) {
                foreach ($chunk as $plan) {
                    $this->withSyncLock(function () use ($plan, $bundleHash, $rewrite, &$report): void {
                        if ($plan['already_verified']) {
                            return;
                        }
                        $backup = $this->backupPlan($plan, $bundleHash, $rewrite['backup_relative']);
                        $report['backed_up_notes'] += $backup['written'];
                        if ($plan['update_canonical']) {
                            $current = $this->reader->getNote($plan['canonical_note_id']);
                            if (! $current) {
                                throw new RuntimeException('Canonical rewrite note disappeared: '.$plan['video_id']);
                            }
                            $result = $this->writer->updateNote($plan['canonical_note_id'], [
                                'title' => $plan['title'],
                                'content' => $plan['body'],
                                'parent_id' => $plan['target_id'],
                                'expected_updated_time' => $current['updated_time'],
                            ], true, true);
                            if (! ($result['success'] ?? false) || ($result['queued'] ?? false)) {
                                throw new RuntimeException('Canonical rewrite failed: '.$plan['video_id']);
                            }
                            $report['updated']++;
                        }
                        $verified = $this->writer->reviewSnapshot($plan['canonical_note_id'].'.md');
                        if (! $verified || ! $this->writer->reviewNoteSemanticallyMatches(
                            $verified['body'],
                            $plan['title'],
                            $plan['body'],
                            $plan['target_id'],
                        )) {
                            throw new RuntimeException('Canonical rewrite read-back failed: '.$plan['video_id']);
                        }
                        if ($plan['require_untagged'] ?? false) {
                            $this->assertUntagged($plan['source_note_ids']);
                        }
                        $this->recordRewrite($plan, $bundleHash, $backup['relative'], 'applying');
                        foreach ($plan['duplicates'] as $duplicate) {
                            $snapshot = $this->writer->reviewSnapshot($duplicate['note_id'].'.md');
                            if (! $snapshot) {
                                continue;
                            }
                            if (! hash_equals($duplicate['wire_hash'], $snapshot['hash'])) {
                                throw new RuntimeException('Duplicate changed after backup: '.$plan['video_id']);
                            }
                            $this->writer->reviewDelete($duplicate['note_id'].'.md', $snapshot);
                            $report['duplicates_removed']++;
                        }
                        $this->recordRewrite($plan, $bundleHash, $backup['relative'], 'verified');
                    });
                }
            }
            $report['status'] = count($selected) === count($plans) ? 'verified' : 'pilot';
            $report['queue'] = $this->queue->getStatistics();

            return $report;
        } finally {
            $guard->release();
        }
    }

    private function policy(): array
    {
        $raw = DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'youtube_knowledge')->value('config_value');
        $policy = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $rewrite = $policy['rewrite'] ?? null;
        if (! is_array($rewrite)
            || ! is_bool($rewrite['enabled'] ?? null)
            || ! is_int($rewrite['max_items'] ?? null) || $rewrite['max_items'] < 1
            || ! is_int($rewrite['max_bundle_bytes'] ?? null) || $rewrite['max_bundle_bytes'] < 1
            || ! is_int($rewrite['max_body_bytes'] ?? null) || $rewrite['max_body_bytes'] < 1
            || ! is_int($rewrite['batch_size'] ?? null) || $rewrite['batch_size'] < 1
            || ! is_string($rewrite['backup_relative'] ?? null) || ! preg_match('~^[A-Za-z0-9/_-]+$~D', $rewrite['backup_relative'])
            || ! is_array($rewrite['allowed_classes'] ?? null) || $rewrite['allowed_classes'] === []
            || ! is_array($rewrite['allowed_operations'] ?? null) || $rewrite['allowed_operations'] === []
            || ! is_string($rewrite['food_topic'] ?? null)
            || ! is_array($rewrite['forbidden_headings'] ?? null)) {
            throw new RuntimeException('Invalid YouTube rewrite policy');
        }

        return $policy;
    }

    private function validateBundle(array $bundle, array $policy): array
    {
        $items = $bundle['items'] ?? null;
        $rewrite = $policy['rewrite'];
        if (($bundle['schema'] ?? null) !== 'plos.joplin-youtube-rewrite.v1'
            || ! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > $rewrite['max_items']) {
            throw new RuntimeException('Invalid YouTube rewrite bundle');
        }
        $topics = array_merge(array_keys($policy['topics'] ?? []), [$policy['review_topic'] ?? '']);
        $seenVideos = $seenNotes = [];
        foreach ($items as &$item) {
            $item['operation'] ??= 'rewrite';
            if (! is_array($item)
                || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $item['video_id'] ?? '')
                || isset($seenVideos[$item['video_id']])
                || ! in_array($item['operation'], $rewrite['allowed_operations'], true)
                || ! in_array($item['classification'] ?? null, $rewrite['allowed_classes'], true)
                || ! in_array($item['target_topic'] ?? null, $topics, true)
                || ! is_string($item['title'] ?? null) || trim($item['title']) === '' || mb_strlen($item['title']) > 255
                || ! is_string($item['body'] ?? null) || trim($item['body']) === '' || strlen($item['body']) > $rewrite['max_body_bytes']
                || ! is_array($item['source_note_ids'] ?? null) || ! array_is_list($item['source_note_ids']) || $item['source_note_ids'] === []
                || ! is_array($item['source_hashes'] ?? null)
                || ! is_array($item['source_parent_ids'] ?? null)
                || ! in_array($item['canonical_note_id'] ?? null, $item['source_note_ids'], true)) {
                throw new RuntimeException('Invalid YouTube rewrite item');
            }
            if ($item['classification'] === 'food_recipe' && $item['target_topic'] !== $rewrite['food_topic']) {
                throw new RuntimeException('Food recipe classification/topic mismatch: '.$item['video_id']);
            }
            if ($item['classification'] === 'nonfood_formula' && $item['target_topic'] === $rewrite['food_topic']) {
                throw new RuntimeException('Non-food formula cannot use the food topic: '.$item['video_id']);
            }
            if ($item['operation'] === 'rewrite' && (! str_contains($item['body'], $policy['edited_label'])
                || ! str_contains($item['body'], 'https://www.youtube.com/watch?v='.$item['video_id']))) {
                throw new RuntimeException('Rewrite provenance missing: '.$item['video_id']);
            }
            if ($item['operation'] === 'rewrite') {
                foreach ($rewrite['forbidden_headings'] as $heading) {
                    if (preg_match('/^#{1,6}\s+'.preg_quote((string) $heading, '/').'\s*$/mi', $item['body'])) {
                        throw new RuntimeException('Rewrite retains a transcript section: '.$item['video_id']);
                    }
                }
            }
            if (isset($item['source_wire_hashes']) && (! is_array($item['source_wire_hashes'])
                || array_diff(array_keys($item['source_wire_hashes']), $item['source_note_ids'])
                || array_diff($item['source_note_ids'], array_keys($item['source_wire_hashes'])))) {
                throw new RuntimeException('Rewrite source wire receipt set mismatch');
            }
            if (isset($item['require_untagged']) && ! is_bool($item['require_untagged'])) {
                throw new RuntimeException('Invalid rewrite tag admission');
            }
            foreach ($item['source_note_ids'] as $noteId) {
                if (isset($item['source_wire_hashes']) && ! preg_match('/^[a-f0-9]{64}$/D', $item['source_wire_hashes'][$noteId] ?? '')) {
                    throw new RuntimeException('Invalid rewrite source wire hash');
                }
                if (! is_string($noteId) || ! preg_match('/^[a-f0-9]{32}$/D', $noteId) || isset($seenNotes[$noteId])
                    || ! preg_match('/^[a-f0-9]{64}$/D', $item['source_hashes'][$noteId] ?? '')
                    || ! preg_match('/^[a-f0-9]{32}$/D', $item['source_parent_ids'][$noteId] ?? '')) {
                    throw new RuntimeException('Invalid or repeated rewrite source identity');
                }
                $seenNotes[$noteId] = true;
            }
            if (array_diff(array_keys($item['source_hashes']), $item['source_note_ids'])
                || array_diff(array_keys($item['source_parent_ids']), $item['source_note_ids'])) {
                throw new RuntimeException('Rewrite source receipt set mismatch');
            }
            $seenVideos[$item['video_id']] = true;
            $item['title'] = trim($item['title']);
            $item['body'] = rtrim(str_replace(["\r\n", "\r"], "\n", $item['body']))."\n";
        }
        unset($item);

        return $items;
    }

    private function topicNotebooks(array $policy): array
    {
        $notebooks = [];
        foreach ($this->reader->listNotes() as $filename) {
            $note = $this->reader->getNote(substr($filename, 0, -3));
            if ($note && $note['type'] === 2) {
                $notebooks[] = $note;
            }
        }
        $roots = array_values(array_filter($notebooks, fn (array $note): bool => $note['parent_id'] === '' && $note['title'] === $policy['root_title']));
        if (count($roots) !== 1) {
            throw new RuntimeException('YouTube Knowledge root is missing or ambiguous');
        }
        $wanted = array_merge(array_keys($policy['topics']), [$policy['review_topic']]);
        $topics = [];
        foreach ($notebooks as $note) {
            if ($note['parent_id'] !== $roots[0]['id'] || ! in_array($note['title'], $wanted, true)) {
                continue;
            }
            if (isset($topics[$note['title']])) {
                throw new RuntimeException('YouTube Knowledge topic is ambiguous: '.$note['title']);
            }
            $topics[$note['title']] = $note['id'];
        }
        if (count($topics) !== count($wanted)) {
            throw new RuntimeException('YouTube Knowledge topic set is incomplete');
        }

        return $topics;
    }

    private function plans(array $items, array $topics, string $bundleHash): array
    {
        $plans = [];
        foreach ($items as $item) {
            $row = DB::table('joplin_youtube_knowledge_rewrites')->where('video_id', $item['video_id'])->first();
            $current = [];
            $canonicalIsDesired = false;
            $desiredHash = hash('sha256', $item['title']."\n".$item['body']."\n".$topics[$item['target_topic']]);
            foreach ($item['source_note_ids'] as $noteId) {
                $note = $this->reader->getNote($noteId, isset($item['source_wire_hashes']));
                if (! $note) {
                    if (! $row || $noteId === $item['canonical_note_id']) {
                        throw new RuntimeException('Rewrite source note is missing: '.$item['video_id']);
                    }

                    continue;
                }
                if (isset($item['source_wire_hashes'])) {
                    $sourceSnapshot = $this->writer->reviewSnapshot($noteId.'.md');
                    if (! $sourceSnapshot || ! hash_equals($item['source_wire_hashes'][$noteId], $sourceSnapshot['hash'])) {
                        throw new RuntimeException('Rewrite source wire changed after export: '.$item['video_id']);
                    }
                }
                $sourceHash = hash('sha256', $note['title']."\n".$note['content']);
                if (! in_array($item['video_id'], $this->videoIds($note['title'].' '.$note['content']), true)) {
                    throw new RuntimeException('Rewrite source video identity mismatch: '.$item['video_id']);
                }
                $isDesiredCanonical = false;
                if ($noteId === $item['canonical_note_id']) {
                    $snapshot = $this->writer->reviewSnapshot($noteId.'.md');
                    $isDesiredCanonical = $snapshot && $this->writer->reviewNoteSemanticallyMatches(
                        $snapshot['body'],
                        $item['title'],
                        $item['body'],
                        $topics[$item['target_topic']],
                    );
                    if ($item['operation'] === 'preserve_content' && ! $isDesiredCanonical
                        && $this->normalizeBody($note['content']) !== $item['body']) {
                        throw new RuntimeException('Preserve-content bundle changed canonical body: '.$item['video_id']);
                    }
                    $canonicalIsDesired = $isDesiredCanonical;
                }
                if (! $isDesiredCanonical && (! hash_equals($item['source_hashes'][$noteId], $sourceHash)
                    || ! hash_equals($item['source_parent_ids'][$noteId], $note['parent_id']))) {
                    throw new RuntimeException('Rewrite source changed after export: '.$item['video_id']);
                }
                $current[$noteId] = $note;
            }
            $canonical = $current[$item['canonical_note_id']] ?? null;
            if (! $canonical) {
                throw new RuntimeException('Canonical rewrite note is missing: '.$item['video_id']);
            }
            $alreadyVerified = $canonicalIsDesired && $row && $row->status === 'verified'
                && ($row->operation_mode ?? 'rewrite') === $item['operation']
                && hash_equals($row->desired_hash, $desiredHash) && hash_equals($row->bundle_hash, $bundleHash);
            $duplicates = [];
            foreach (array_diff($item['source_note_ids'], [$item['canonical_note_id']]) as $noteId) {
                if (! isset($current[$noteId])) {
                    continue;
                }
                $snapshot = $this->writer->reviewSnapshot($noteId.'.md');
                if (! $snapshot) {
                    throw new RuntimeException('Duplicate rewrite snapshot is missing: '.$item['video_id']);
                }
                $duplicates[] = ['note_id' => $noteId, 'wire_hash' => $item['source_wire_hashes'][$noteId] ?? $snapshot['hash']];
            }
            $plans[] = $item + [
                'target_id' => $topics[$item['target_topic']],
                'desired_hash' => $desiredHash,
                'update_canonical' => ! $canonicalIsDesired,
                'already_verified' => $alreadyVerified && $duplicates === [],
                'duplicates' => $duplicates,
                'current_notes' => $current,
            ];
        }

        return $plans;
    }

    private function backupPlan(array $plan, string $bundleHash, string $relativeRoot): array
    {
        $relative = $relativeRoot.'/'.$bundleHash.'/'.$plan['video_id'];
        $root = storage_path('app/private/'.$relative);
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Cannot create private YouTube rewrite backup');
        }
        chmod($root, 0700);
        $written = 0;
        foreach ($plan['current_notes'] as $noteId => $note) {
            $wirePath = $root.'/'.$noteId.'.md';
            $receiptPath = $root.'/'.$noteId.'.json';
            if (is_file($wirePath) || is_file($receiptPath)) {
                $this->verifyExistingBackup(
                    $wirePath,
                    $receiptPath,
                    $noteId,
                    $bundleHash,
                    $plan['source_hashes'][$noteId],
                );

                continue;
            }
            $snapshot = $this->writer->reviewSnapshot($noteId.'.md');
            if (! $snapshot) {
                throw new RuntimeException('Cannot snapshot YouTube rewrite source');
            }
            if (isset($plan['source_wire_hashes']) && ! hash_equals($plan['source_wire_hashes'][$noteId], $snapshot['hash'])) {
                throw new RuntimeException('Rewrite source wire changed before backup');
            }
            $this->privateBackup($wirePath, $snapshot['body']);
            $this->privateBackup($receiptPath, json_encode([
                'note_id' => $noteId,
                'title' => $note['title'],
                'parent_id' => $note['parent_id'],
                'visible_hash' => hash('sha256', $note['title']."\n".$note['content']),
                'wire_hash' => $snapshot['hash'],
                'bundle_hash' => $bundleHash,
                'captured_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $written++;
        }

        return ['relative' => $relative, 'written' => $written];
    }

    private function recordRewrite(array $plan, string $bundleHash, string $backupRelative, string $status): void
    {
        DB::table('joplin_youtube_knowledge_rewrites')->upsert([[
            'video_id' => $plan['video_id'],
            'operation_mode' => $plan['operation'],
            'canonical_note_id' => $plan['canonical_note_id'],
            'source_note_ids' => json_encode($plan['source_note_ids']),
            'source_hashes' => json_encode($plan['source_hashes']),
            'classification' => $plan['classification'],
            'target_topic' => $plan['target_topic'],
            'desired_hash' => $plan['desired_hash'],
            'bundle_hash' => $bundleHash,
            'backup_relative' => $backupRelative,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['video_id'], [
            'operation_mode', 'canonical_note_id', 'source_note_ids', 'source_hashes', 'classification', 'target_topic',
            'desired_hash', 'bundle_hash', 'backup_relative', 'status', 'updated_at',
        ]);
    }

    private function verifyExistingBackup(
        string $wirePath,
        string $receiptPath,
        string $noteId,
        string $bundleHash,
        string $sourceHash,
    ): void {
        if (! is_file($wirePath) || ! is_file($receiptPath)) {
            throw new RuntimeException('YouTube rewrite backup is incomplete');
        }
        $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
        if (($receipt['note_id'] ?? null) !== $noteId
            || ($receipt['bundle_hash'] ?? null) !== $bundleHash
            || ($receipt['visible_hash'] ?? null) !== $sourceHash
            || ($receipt['wire_hash'] ?? null) !== hash_file('sha256', $wirePath)) {
            throw new RuntimeException('YouTube rewrite backup receipt mismatch');
        }
    }

    private function privateBackup(string $path, string $body): void
    {
        if (is_file($path)) {
            if (! hash_equals(hash_file('sha256', $path), hash('sha256', $body))) {
                throw new RuntimeException('YouTube rewrite backup conflict');
            }

            return;
        }
        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write YouTube rewrite backup');
        }
        chmod($path, 0600);
    }

    /** @return list<string> */
    private function videoIds(string $text): array
    {
        preg_match_all('/(?:youtube\.com\/watch\?[^\s)]*v=|youtu\.be\/|youtube\.com\/embed\/)([A-Za-z0-9_-]{11})/', $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function normalizeBody(string $body): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $body))."\n";
    }

    /** Recheck separate tag relationships after backups and immediately before deletion. */
    private function assertUntagged(array $noteIds): void
    {
        foreach ($this->reader->listNotes(true) as $file) {
            $note = $this->reader->getNote(substr($file, 0, -3), true);
            if ($note === null) {
                throw new RuntimeException('Incomplete Joplin tag inventory before duplicate deletion');
            }
            if ($note['type'] === 6 && in_array($note['metadata']['note_id'] ?? null, $noteIds, true)) {
                throw new RuntimeException('Duplicate source gained a tag; preserved for review');
            }
        }
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
}
