<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Manual, manifest-scoped publisher with explicit, receipt-backed owned pruning. */
class JoplinReviewMirrorService
{
    public function __construct(private JoplinWriteService $writer, private JoplinFilesService $reader, private JoplinQueueService $queue, private JoplinLockHandler $locks) {}

    public function run(string $bundlePath, bool $apply = false, int $limit = 0, bool $prune = false, ?string $previousBundlePath = null, array $pruneSourcePaths = []): array
    {
        $policy = json_decode((string) DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'review_mirror')->value('config_value'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['max_files', 'max_file_bytes', 'max_bundle_bytes', 'lock_seconds', 'batch_size', 'http_concurrency', 'request_timeout_seconds'] as $key) {
            if (! is_int($policy[$key] ?? null) || $policy[$key] <= 0) {
                throw new RuntimeException('Invalid review policy: '.$key);
            }
        }
        // At most two requests per item in each of three phases; keep each group within the Joplin protocol lease.
        if (ceil(2 * $policy['batch_size'] / $policy['http_concurrency']) * 3 * $policy['request_timeout_seconds'] >= JoplinLockHandler::LOCK_TTL_MS / 1000) {
            throw new RuntimeException('Review batch exceeds the Joplin sync-lock lease');
        }
        foreach (['mirror_key', 'parent_title', 'notebook_title'] as $key) {
            if (! is_string($policy[$key] ?? null) || trim($policy[$key]) === '') {
                throw new RuntimeException('Missing review policy: '.$key);
            }
        }
        if (! is_bool($policy['allow_owned_prune'] ?? null)
            || ! is_string($policy['prune_backup_relative'] ?? null)
            || ! preg_match('~^[A-Za-z0-9/_-]+$~D', $policy['prune_backup_relative'])) {
            throw new RuntimeException('Invalid review prune policy');
        }
        if ($prune && ! $policy['allow_owned_prune']) {
            throw new RuntimeException('Owned review pruning is disabled by policy');
        }
        if ($limit < 0 || ($prune && (! $apply || $limit !== 0)) || ($apply && $pruneSourcePaths !== [] && ! $prune) || ! is_file($bundlePath) || filesize($bundlePath) > $policy['max_bundle_bytes']) {
            throw new RuntimeException('Review bundle outside admission limits');
        }
        $bundle = json_decode(file_get_contents($bundlePath), true, 512, JSON_THROW_ON_ERROR);
        $items = $this->validateBundle($bundle, $policy);
        $previousItems = [];
        if ($previousBundlePath !== null) {
            if (! is_file($previousBundlePath) || filesize($previousBundlePath) > $policy['max_bundle_bytes']) {
                throw new RuntimeException('Previous review bundle outside admission limits');
            }
            $previousBundle = json_decode(file_get_contents($previousBundlePath), true, 512, JSON_THROW_ON_ERROR);
            $previousItems = collect($this->validateBundle($previousBundle, $policy))->keyBy('key')->all();
        }
        $key = $policy['mirror_key'];
        $sourceKeys = array_fill_keys(array_column($items, 'key'), true);
        $known = DB::table('joplin_review_items')->where('mirror_key', $key)->get();
        $stale = $known->filter(fn ($row) => ! isset($sourceKeys[$row->source_key]))->values();
        $allStaleCount = $stale->count();
        if ($pruneSourcePaths !== []) {
            if (count($pruneSourcePaths) !== count(array_unique($pruneSourcePaths)) || count(array_filter($pruneSourcePaths, fn ($path) => is_string($path) && $path !== '')) !== count($pruneSourcePaths)) {
                throw new RuntimeException('Invalid or duplicate review prune source path');
            }
            $requested = array_fill_keys($pruneSourcePaths, true);
            $stale = $stale->filter(fn ($row) => isset($requested[$row->source_path]))->values();
            if ($stale->count() !== count($pruneSourcePaths)) {
                throw new RuntimeException('Review prune source path is not an unlisted owned mapping');
            }
        }
        $report = [
            'status' => $apply ? 'applying' : 'preview', 'mirror' => $key, 'files' => count($items),
            'new' => count($items) - $known->whereIn('source_key', array_keys($sourceKeys))->count(),
            'updated' => 0, 'unchanged' => 0, 'deleted' => 0,
            'recovered_misroutes' => 0,
            'removal_candidates' => $stale->count(), 'unlisted_mappings' => $allStaleCount, 'prune_requested' => $prune,
            'read_only_workflow' => true, 'editing_restriction_enforced' => false,
        ];
        if (! $apply) {
            $report['known_mappings'] = $known->count();
            $report['removal_conflicts'] = $this->previewPruneConflicts($stale, $policy);

            return $report;
        }
        $guard = Cache::lock('joplin-review-mirror:'.$key, $policy['lock_seconds']);
        if (! $guard->get()) {
            throw new RuntimeException('Another review mirror writer holds the lease');
        }
        $started = microtime(true);
        try {
            $this->assertIdle();
            $this->writer->assertReviewTargetCompatible();
            $notebook = $this->notebook($policy);
            // Reserve every ID before rendering any link; stable keys survive generated filename renumbering.
            foreach ($items as $item) {
                DB::table('joplin_review_items')->insertOrIgnore([
                    'mirror_key' => $key, 'source_key' => $item['key'], 'source_path' => $item['path'],
                    'note_id' => bin2hex(random_bytes(16)), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $rows = DB::table('joplin_review_items')->where('mirror_key', $key)->get()->keyBy('source_key');
            $replace = [];
            foreach ($items as $item) {
                $replace['{{PLOS_NOTE:'.$item['key'].'}}'] = ':/'.$rows[$item['key']]->note_id;
                $replace['{{PLOS_RESOURCE:'.$item['key'].'}}'] = ':/'.$this->resourceId($key, $item);
            }
            $previousReplace = [];
            foreach ($previousItems as $previousItem) {
                $previousRow = $rows[$previousItem['key']] ?? null;
                if (! $previousRow) {
                    throw new RuntimeException('Previous review identity has no owned mapping: '.$previousItem['path']);
                }
                $previousReplace['{{PLOS_NOTE:'.$previousItem['key'].'}}'] = ':/'.$previousRow->note_id;
                $previousReplace['{{PLOS_RESOURCE:'.$previousItem['key'].'}}'] = ':/'.($previousRow->resource_id ?: $this->resourceId($key, $previousItem));
            }
            // Main navigation is published last so it never advertises an incomplete new batch as complete.
            usort($items, fn ($a, $b) => ($a['path'] === 'START-HERE.html') <=> ($b['path'] === 'START-HERE.html'));
            $selected = $limit ? array_slice($items, 0, $limit) : $items;
            $resourceKeys = array_fill_keys(array_column(array_filter($selected, fn ($item) => $item['attach_original']), 'key'), true);
            foreach ($selected as $item) {
                preg_match_all('/\{\{PLOS_RESOURCE:([a-f0-9]{64})\}\}/', $item['body'], $matches);
                foreach ($matches[1] as $resourceKey) {
                    $resourceKeys[$resourceKey] = true;
                }
            }
            $resources = [];
            foreach ($items as $item) {
                if (isset($resourceKeys[$item['key']]) && $rows[$item['key']]->resource_id !== $this->resourceId($key, $item)) {
                    $resources[] = ['id' => $this->resourceId($key, $item), 'filename' => basename($item['path']), 'mime' => $item['mime'], 'bytes' => $item['bytes'], 'key' => $item['key']];
                }
            }
            foreach (array_chunk($resources, $policy['batch_size']) as $chunk) {
                $this->bounded($started, $policy);
                $this->withSyncLock(fn () => $this->writer->reviewResources($chunk, $policy['http_concurrency'], $policy['request_timeout_seconds']));
                foreach ($chunk as $resource) {
                    DB::table('joplin_review_items')->where('id', $rows[$resource['key']]->id)->update(['resource_id' => $resource['id']]);
                }
            }
            $pending = [];
            foreach ($selected as $item) {
                $row = $rows[$item['key']];
                $coreBody = strtr($item['body'], $replace);
                $body = $this->publishedBody($coreBody, $item['attach_original'] ? $this->resourceId($key, $item) : null);
                $desired = hash('sha256', $item['title']."\n".$body."\n".$notebook);
                if ($row->source_hash === $desired && $row->remote_hash && ! $row->pending_hash) {
                    $report['unchanged']++;

                    continue;
                }
                $previousBody = null;
                if (isset($previousItems[$item['key']])) {
                    $previousItem = $previousItems[$item['key']];
                    $previousCore = strtr($previousItem['body'], $previousReplace);
                    $previousBody = $this->publishedBody(
                        $previousCore,
                        $previousItem['attach_original'] ? ($row->resource_id ?: $this->resourceId($key, $previousItem)) : null,
                    );
                }
                $pending[] = compact('row', 'item', 'coreBody', 'body', 'desired', 'previousBody');
            }
            // Publish the main index in its own final batch after every detail note was acknowledged.
            $index = array_values(array_filter($pending, fn ($p) => $p['item']['path'] === 'START-HERE.html'));
            $details = array_values(array_filter($pending, fn ($p) => $p['item']['path'] !== 'START-HERE.html'));
            $chunks = array_chunk($details, $policy['batch_size']);
            if ($index) {
                $chunks[] = $index;
            }
            foreach ($chunks as $chunk) {
                $this->bounded($started, $policy);
                $this->withSyncLock(function () use ($chunk, $notebook, &$report, $policy, $key) {
                    $remotes = $this->writer->reviewSnapshots(array_map(fn ($p) => $p['row']->note_id.'.md', $chunk), $policy['http_concurrency'], $policy['request_timeout_seconds']);
                    $updates = [];
                    $acks = [];
                    foreach ($chunk as $p) {
                        ['row' => $row, 'item' => $item, 'coreBody' => $coreBody, 'body' => $body, 'desired' => $desired, 'previousBody' => $previousBody] = $p;
                        $path = $row->note_id.'.md';
                        $remote = $remotes[$path];
                        if ($remote && $row->pending_hash && hash_equals($row->pending_hash, $remote['hash'])) {
                            $row->remote_hash = $row->pending_hash;
                            DB::table('joplin_review_items')->where('id', $row->id)->update(['remote_hash' => $row->remote_hash, 'pending_hash' => null]);
                        }
                        if ($remote && $remote['hash'] !== $row->remote_hash) {
                            $previousBody ??= $this->publishedBody($coreBody, $row->resource_id);
                            $exact = $this->writer->reviewNoteSemanticallyMatches($remote['body'], $item['title'], $previousBody, $notebook);
                            $currentParent = $this->writer->reviewNoteParent($remote['body']);
                            $ownedMisroute = ! $exact
                                && $this->writer->reviewNoteSemanticallyMatches($remote['body'], $item['title'], $previousBody, null)
                                && $currentParent
                                && DB::table('joplin_youtube_knowledge_notes')->where('parent_key', $currentParent)->exists();
                            if (! $exact && ! $ownedMisroute) {
                                throw new RuntimeException('Review note conflict or deletion: '.$item['path'].'; preserved for operator review');
                            }
                            if ($ownedMisroute) {
                                $report['recovered_misroutes']++;
                            }
                            $row->remote_hash = $remote['hash'];
                            DB::table('joplin_review_items')->where('id', $row->id)->update(['remote_hash' => $row->remote_hash, 'updated_at' => now()]);
                        } elseif (! $remote && $row->remote_hash) {
                            throw new RuntimeException('Review note conflict or deletion: '.$item['path'].'; preserved for operator review');
                        }
                        if ($remote && $row->source_hash === $desired) {
                            $report['unchanged']++;

                            continue;
                        }
                        $wire = $this->writer->reviewNoteWire($row->note_id, $item['title'], $body, $notebook, Carbon::parse($row->created_at)->utc()->format('Y-m-d\TH:i:s.v\Z'));
                        $updates[$path] = ['body' => $wire, 'previous' => $remote];
                        $acks[$path] = $p;
                    }
                    // Persist every pending digest before a partial batch can reach the server.
                    foreach ($updates as $path => $update) {
                        DB::table('joplin_review_items')->where('id', $acks[$path]['row']->id)->update(['pending_hash' => hash('sha256', $update['body'])]);
                    }
                    $verified = $this->writer->reviewPuts($updates, $policy['http_concurrency'], $policy['request_timeout_seconds']);
                    foreach ($verified as $path => $result) {
                        $p = $acks[$path];
                        DB::table('joplin_review_items')->where('id', $p['row']->id)->update([
                            'remote_hash' => $result['hash'], 'pending_hash' => null, 'source_hash' => $p['desired'],
                            'source_path' => $p['item']['path'],
                            'resource_id' => $p['item']['attach_original'] ? $this->resourceId($key, $p['item']) : null,
                            'updated_at' => now(),
                        ]);
                        $report['updated']++;
                    }
                });
            }
            $report['status'] = count($selected) === count($items) ? 'verified' : 'pilot';
            $report['notebook_id'] = $notebook;
            $report['verified_files'] = $report['updated'] + $report['unchanged'];
            $report['retained_unlisted_mappings'] = $rows->count() - count($items);
            if ($prune) {
                $pruned = $this->pruneOwned($stale, $notebook, $key, hash_file('sha256', $bundlePath), $policy);
                $report['deleted'] = $pruned['deleted'];
                $report['deleted_resources'] = $pruned['deleted_resources'];
                $report['prune_backup'] = $pruned['backup'];
                $report['retained_unlisted_mappings'] = $allStaleCount - $pruned['deleted'];
            }
            if ($report['status'] === 'verified') {
                DB::table('joplin_review_mirrors')->where('mirror_key', $key)->update(['manifest_hash' => hash_file('sha256', $bundlePath), 'updated_at' => now()]);
            }
            $report['verification'] = 'Changed items read back from WebDAV; unchanged items retain their last verified receipt';
            $report['queue'] = $this->queue->getStatistics();

            return $report;
        } finally {
            $guard->release();
        }
    }

    private function previewPruneConflicts($rows, array $policy): int
    {
        $conflicts = 0;
        $byPath = $rows->keyBy(fn ($row) => $row->note_id.'.md');
        foreach (array_chunk(array_keys($byPath->all()), $policy['batch_size'] * 4) as $paths) {
            $remotes = $this->writer->reviewSnapshots($paths, $policy['http_concurrency'], $policy['request_timeout_seconds']);
            foreach ($remotes as $path => $remote) {
                $row = $byPath[$path];
                if (! $remote || ! $row->remote_hash || ! hash_equals($row->remote_hash, $remote['hash'])) {
                    $conflicts++;
                }
            }
        }

        return $conflicts;
    }

    private function pruneOwned($rows, string $notebook, string $key, string $manifestHash, array $policy): array
    {
        $root = storage_path('app/private/'.$policy['prune_backup_relative'].'/'.$key.'/'.$manifestHash);
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Cannot create private review-prune backup');
        }
        chmod($root, 0700);
        $plans = [];
        $allPaths = [];
        foreach ($rows as $row) {
            $allPaths[] = $row->note_id.'.md';
            if ($row->resource_id) {
                $allPaths[] = '.resource/'.$row->resource_id;
                $allPaths[] = $row->resource_id.'.md';
            }
        }
        $snapshots = [];
        foreach (array_chunk($allPaths, $policy['batch_size'] * 4) as $paths) {
            $snapshots += $this->writer->reviewSnapshots($paths, $policy['http_concurrency'], $policy['request_timeout_seconds']);
        }
        foreach ($rows as $row) {
            $dir = $root.'/'.$row->source_key.'-'.$row->note_id;
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException('Cannot create review-prune item backup');
            }
            $note = $snapshots[$row->note_id.'.md'] ?? null;
            $savedNote = $dir.'/note.md';
            if (! $note && is_file($savedNote) && hash_file('sha256', $savedNote) === $row->remote_hash) {
                $note = ['body' => file_get_contents($savedNote), 'hash' => $row->remote_hash, 'etag' => null, 'deleted' => true];
            }
            if (! $note || ! $row->remote_hash || ! hash_equals($row->remote_hash, $note['hash'])) {
                throw new RuntimeException('Review prune conflict or missing owned note: '.$row->source_path);
            }
            if (! str_contains($note['body'], "\nid: {$row->note_id}\n")
                || ! str_contains($note['body'], "\nparent_id: {$notebook}\n")
                || ! str_ends_with($note['body'], 'type_: 1')) {
                throw new RuntimeException('Review prune ownership check failed: '.$row->source_path);
            }
            $resource = $metadata = null;
            if ($row->resource_id) {
                $resource = $snapshots['.resource/'.$row->resource_id] ?? null;
                $metadata = $snapshots[$row->resource_id.'.md'] ?? null;
                if ((! $resource && ! is_file($dir.'/resource.bin')) || (! $metadata && ! is_file($dir.'/resource.md'))) {
                    throw new RuntimeException('Review prune resource receipt missing: '.$row->source_path);
                }
                if ($metadata && (! str_contains($metadata['body'], "\nid: {$row->resource_id}\n") || ! str_ends_with($metadata['body'], 'type_: 4'))) {
                    throw new RuntimeException('Review prune resource ownership check failed: '.$row->source_path);
                }
            }
            $this->privateBackup($savedNote, $note['body']);
            if ($resource) {
                $this->privateBackup($dir.'/resource.bin', $resource['body']);
            }
            if ($metadata) {
                $this->privateBackup($dir.'/resource.md', $metadata['body']);
            }
            $this->privateBackup($dir.'/receipt.json', json_encode([
                'mirror' => $key, 'source_key' => $row->source_key, 'source_path' => $row->source_path,
                'note_id' => $row->note_id, 'note_hash' => $row->remote_hash, 'resource_id' => $row->resource_id,
                'prepared_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $plans[] = compact('row', 'note', 'resource', 'metadata');
        }

        $noteDeletes = $resourceDeletes = [];
        foreach ($plans as $plan) {
            if (empty($plan['note']['deleted'])) {
                $noteDeletes[$plan['row']->note_id.'.md'] = $plan['note'];
            }
            if ($plan['resource']) {
                $resourceDeletes['.resource/'.$plan['row']->resource_id] = $plan['resource'];
            }
            if ($plan['metadata']) {
                $resourceDeletes[$plan['row']->resource_id.'.md'] = $plan['metadata'];
            }
        }
        foreach (array_chunk($noteDeletes, $policy['batch_size'] * 4, true) as $chunk) {
            $this->writer->reviewDeletes($chunk, $policy['http_concurrency'], $policy['request_timeout_seconds']);
        }
        foreach (array_chunk($resourceDeletes, $policy['batch_size'] * 4, true) as $chunk) {
            $this->writer->reviewDeletes($chunk, $policy['http_concurrency'], $policy['request_timeout_seconds']);
        }
        DB::transaction(fn () => DB::table('joplin_review_items')->whereIn('id', array_map(fn ($plan) => $plan['row']->id, $plans))->delete());

        return ['deleted' => count($plans), 'deleted_resources' => count(array_filter($plans, fn ($plan) => $plan['resource'] !== null)), 'backup' => $root];
    }

    private function privateBackup(string $path, string $body): void
    {
        if (is_file($path)) {
            if (! hash_equals(hash_file('sha256', $path), hash('sha256', $body))) {
                throw new RuntimeException('Review-prune backup conflict');
            }

            return;
        }
        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write review-prune backup');
        }
        chmod($path, 0600);
    }

    private function publishedBody(string $coreBody, ?string $resourceId): string
    {
        $body = $coreBody."\n\n---\n";
        if ($resourceId) {
            $body .= '[Original review file](:/'.$resourceId.")\n\n";
        }

        return $body."Generated review copy. Give decisions in the PLOS CLI; local edits are preserved as conflicts.\n";
    }

    public function validateBundle(array $bundle, array $policy): array
    {
        $items = $bundle['items'] ?? [];
        if (($bundle['schema'] ?? '') !== 'plos.review-mirror.v1' || ! is_array($items) || ! $items || count($items) > $policy['max_files']) {
            throw new RuntimeException('Invalid or oversized review manifest');
        }
        $seen = $paths = [];
        foreach ($items as &$item) {
            if (! is_array($item) || ! preg_match('/^[a-f0-9]{64}$/D', $item['key'] ?? '') || isset($seen[$item['key']])) {
                throw new RuntimeException('Invalid or duplicate review identity');
            }
            if (! preg_match('~^(START-HERE\.html|review/[A-Za-z0-9_.-]+)$~D', $item['path'] ?? '') || isset($paths[$item['path']])) {
                throw new RuntimeException('Invalid or duplicate review path');
            }
            $ext = pathinfo($item['path'], PATHINFO_EXTENSION);
            if (! in_array($ext, $policy['allowed_extensions'], true)) {
                throw new RuntimeException('Unsupported review file: '.$item['path']);
            }
            $item['bytes'] = base64_decode($item['data'] ?? '', true);
            if ($item['bytes'] === false || strlen($item['bytes']) > $policy['max_file_bytes'] || hash('sha256', $item['bytes']) !== ($item['sha256'] ?? '')) {
                throw new RuntimeException('Review file size/identity mismatch');
            }
            if (! is_string($item['title'] ?? null) || preg_match('/[\r\n]/', $item['title']) || ! is_string($item['body'] ?? null)) {
                throw new RuntimeException('Invalid review title/body');
            }
            $item['attach_original'] = $item['attach_original'] ?? true;
            if (! is_bool($item['attach_original'])) {
                throw new RuntimeException('Invalid review attachment policy');
            }
            $mimes = ['html' => 'text/html', 'json' => 'application/json', 'png' => 'image/png', 'css' => 'text/css'];
            $item['mime'] = $mimes[$ext];
            $seen[$item['key']] = true;
            $paths[$item['path']] = true;
        }
        unset($item);
        if (! isset($paths['START-HERE.html'])) {
            throw new RuntimeException('Review index missing');
        }
        foreach ($items as $item) {
            preg_match_all('/\{\{PLOS_(?:NOTE|RESOURCE):([a-f0-9]{64})\}\}/', $item['body'], $matches);
            foreach ($matches[1] as $target) {
                if (! isset($seen[$target])) {
                    throw new RuntimeException('Review link target missing');
                }
            }
        }

        return $items;
    }

    private function resourceId(string $key, array $item): string
    {
        return substr(hash('sha256', $key.':'.$item['key'].':'.$item['sha256']), 0, 32);
    }

    private function assertIdle(): void
    {
        $q = $this->queue->getStatistics();
        $locks = $this->locks->getLockStatus();
        if (($q['pending'] ?? 0) || ($q['processing'] ?? 0) || ($q['failed'] ?? 0) || ($locks['sync_locks'] ?? 0) || ($locks['exclusive_locks'] ?? 0)) {
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

    private function bounded(float $started, array $policy): void
    {
        if (microtime(true) - $started > $policy['lock_seconds'] / 2) {
            throw new RuntimeException('Review run reached its lease budget; resume the same bundle');
        }
    }

    private function notebook(array $policy): string
    {
        $key = $policy['mirror_key'];
        $state = DB::table('joplin_review_mirrors')->where('mirror_key', $key)->first();
        if (! $state) {
            // Discover titles at runtime, verify full parent records rather than trusting cached summaries.
            Cache::forget('joplin_notebooks_list');
            $matches = array_values(array_filter($this->reader->getNotebooks(), fn ($n) => $n['title'] === $policy['parent_title']));
            if (count($matches) !== 1) {
                throw new RuntimeException('Review parent notebook missing or ambiguous');
            }
            $parent = $matches[0]['id'];
            foreach ($this->reader->getNotebooks() as $candidate) {
                if ($candidate['title'] === $policy['notebook_title']) {
                    $full = $this->reader->getNote($candidate['id']);
                    if (($full['parent_id'] ?? '') === $parent) {
                        throw new RuntimeException('An unowned Review Mirror notebook already exists; reconcile before adopting');
                    }
                }
            }
            DB::table('joplin_review_mirrors')->insert(['mirror_key' => $key, 'parent_id' => $parent, 'notebook_id' => bin2hex(random_bytes(16)), 'created_at' => now(), 'updated_at' => now()]);
            $state = DB::table('joplin_review_mirrors')->where('mirror_key', $key)->first();
        }
        $this->withSyncLock(function () use ($state, $policy) {
            $parent = $this->writer->reviewSnapshot($state->parent_id.'.md');
            if (! $parent || ! str_ends_with($parent['body'], 'type_: 2')) {
                throw new RuntimeException('Review parent is no longer a notebook');
            }
            $existing = $this->writer->reviewSnapshot($state->notebook_id.'.md');
            if (! $existing) {
                $this->writer->reviewPut($state->notebook_id.'.md', $this->writer->reviewNotebookWire($state->notebook_id, $policy['notebook_title'], $state->parent_id), null);
                Cache::forget('joplin_notebooks_list');
            } elseif (! str_contains($existing['body'], "\nparent_id: ".$state->parent_id."\n") || ! str_ends_with($existing['body'], 'type_: 2')) {
                throw new RuntimeException('Review notebook moved or changed type; preserve and reconcile');
            }
        });

        return $state->notebook_id;
    }
}
