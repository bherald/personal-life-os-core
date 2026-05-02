<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Support\Facades\Log;

/**
 * JoplinYouTubeOrganizer
 *
 * Organizes YouTube Watch Later folder in Joplin:
 * - Identifies and removes duplicate notes (by video ID or normalized title)
 * - Categorizes videos by keywords
 * - Creates subfolders for each category
 * - Moves notes to appropriate subfolders
 *
 * Independent PLOS interoperability adapter for an operator-managed sync target;
 * it does not use upstream Joplin application or server source code.
 *
 * Uses curl_multi for parallel batch fetching for performance.
 */
class JoplinYouTubeOrganizer
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected string $joplinPath = '/Joplin-data/';

    protected string $watchLaterFolderId = '';

    protected array $categories = [];

    protected $output = null;

    protected ?string $localPath = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.nextcloud.url') ?? '', '/');
        $this->username = config('services.nextcloud.username') ?? '';
        $this->password = config('services.nextcloud.password') ?? '';
        $this->joplinPath = JoplinPaths::syncPath(true);
        $this->watchLaterFolderId = (string) config('services.joplin.youtube_watch_later_folder_id', '');
        $this->categories = (array) config('joplin_youtube.categories', []);

        // Filesystem-first: use local path when available (~1000x faster than WebDAV)
        $this->localPath = JoplinPaths::localRoot();
    }

    /**
     * Set console output for progress reporting
     */
    public function setOutput($output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Log a message to console and/or log file
     */
    protected function log(string $message, string $level = 'info'): void
    {
        if ($this->output) {
            $this->output->writeln($message);
        }
        Log::$level('[JoplinYouTubeOrganizer] '.strip_tags($message));
    }

    protected function getLocalFilePath(string $path): ?string
    {
        return JoplinPaths::localFile($this->localPath, $this->joplinPath, $path);
    }

    private function hasConfiguredWatchLaterFolder(): bool
    {
        return preg_match('/^[a-f0-9]{32}$/i', $this->watchLaterFolderId) === 1;
    }

    private function unconfiguredStats(bool $dryRun, bool $includeConsolidationFields = false): array
    {
        $stats = [
            'total_notes' => 0,
            'duplicates_found' => 0,
            'duplicates_deleted' => 0,
            'categories_created' => 0,
            'notes_moved' => 0,
            'failed_operations' => 1,
            'dry_run' => $dryRun,
            'error' => 'JOPLIN_WATCH_LATER_FOLDER_ID is not configured',
        ];

        if ($includeConsolidationFields) {
            $stats += [
                'extra_folders_found' => 0,
                'extra_folders_deleted' => 0,
                'ai_categorized' => 0,
                'per_source' => [],
            ];
        }

        return $stats;
    }

    /**
     * Run the full organization process
     */
    public function organize(bool $dryRun = false): array
    {
        if (! $this->hasConfiguredWatchLaterFolder()) {
            $this->log('JOPLIN_WATCH_LATER_FOLDER_ID is not configured. Set it to the 32-character Joplin notebook ID before organizing Watch Later notes.', 'warning');

            return $this->unconfiguredStats($dryRun);
        }

        $stats = [
            'total_notes' => 0,
            'duplicates_found' => 0,
            'duplicates_deleted' => 0,
            'categories_created' => 0,
            'notes_moved' => 0,
            'failed_operations' => 0,
            'dry_run' => $dryRun,
        ];

        $this->log('=== YouTube Watch Later Organization ===');
        $this->log("Target folder ID: {$this->watchLaterFolderId}");
        if ($dryRun) {
            $this->log('<comment>DRY RUN - no changes will be made</comment>');
        }

        // Step 1: Get all files
        $this->log("\nStep 1: Fetching all Joplin files...");
        $files = $this->listDirectory($this->joplinPath);
        $this->log('Found '.count($files).' total Joplin files');

        // Step 2: Find notes in Watch Later folder
        $this->log("\nStep 2: Finding notes in YouTube - Watch Later folder...");
        $allContents = $this->getFileContentsBatch($files, 50);
        $this->log('  Fetched '.count($allContents).' files');

        $watchLaterNotes = [];
        $existingSubfolders = [];

        foreach ($allContents as $filename => $content) {
            $note = $this->parseNote($content);
            $note['filename'] = $filename;

            if ($note['type'] == 2 && $note['parent_id'] === $this->watchLaterFolderId) {
                $existingSubfolders[$note['id']] = $note['title'];
                $this->log("  Found existing subfolder: {$note['title']}");
            } elseif ($note['type'] == 1 && $note['parent_id'] === $this->watchLaterFolderId) {
                $watchLaterNotes[] = $note;
            }
        }

        $stats['total_notes'] = count($watchLaterNotes);
        $this->log("\nFound {$stats['total_notes']} notes in YouTube - Watch Later folder");
        $this->log('Found '.count($existingSubfolders).' existing subfolders');

        // Step 3: Identify duplicates
        $this->log("\nStep 3: Identifying duplicates...");
        [$uniqueNotes, $duplicates] = $this->findDuplicates($watchLaterNotes);
        $stats['duplicates_found'] = count($duplicates);
        $this->log("Found {$stats['duplicates_found']} duplicates, keeping ".count($uniqueNotes).' unique notes');

        // Step 4: Delete duplicates
        if (count($duplicates) > 0 && ! $dryRun) {
            $this->log("\nStep 4: Deleting duplicates...");
            foreach ($duplicates as $note) {
                $success = $this->deleteFile($this->joplinPath.$note['filename']);
                if ($success) {
                    $stats['duplicates_deleted']++;
                } else {
                    $stats['failed_operations']++;
                    $this->log("  <error>FAILED:</error> {$note['title']}");
                }
            }
            $this->log("Deleted {$stats['duplicates_deleted']} duplicates");
        } elseif ($dryRun && count($duplicates) > 0) {
            $this->log("\nStep 4: Would delete {$stats['duplicates_found']} duplicates (dry run)");
        }

        // Step 5: Categorize notes
        $this->log("\nStep 5: Categorizing notes...");
        $categorizedNotes = $this->categorizeNotes($uniqueNotes);
        $this->log('Categories found:');
        foreach ($categorizedNotes as $category => $notes) {
            $this->log("  {$category}: ".count($notes).' notes');
        }

        // Step 6: Create subfolders
        $this->log("\nStep 6: Creating subfolders...");
        $categoryToFolderId = [];

        foreach ($categorizedNotes as $category => $notes) {
            $existingId = array_search($category, $existingSubfolders);
            if ($existingId !== false) {
                $categoryToFolderId[$category] = $existingId;
                $this->log("  Using existing: {$category}");
            } elseif (! $dryRun) {
                $newId = $this->createNotebook($category, $this->watchLaterFolderId);
                if ($newId) {
                    $categoryToFolderId[$category] = $newId;
                    $stats['categories_created']++;
                    $this->log("  <info>Created:</info> {$category}");
                } else {
                    $stats['failed_operations']++;
                    $this->log("  <error>FAILED:</error> {$category}");
                }
            } else {
                $this->log("  Would create: {$category} (dry run)");
            }
        }

        // Step 7: Move notes to subfolders
        $this->log("\nStep 7: Moving notes to subfolders...");
        foreach ($categorizedNotes as $category => $notes) {
            if (! isset($categoryToFolderId[$category]) && ! $dryRun) {
                continue;
            }

            $targetFolderId = $categoryToFolderId[$category] ?? 'DRY_RUN';

            foreach ($notes as $note) {
                if ($dryRun) {
                    $this->log('  Would move: '.substr($note['title'], 0, 40)."... -> {$category}");

                    continue;
                }

                $updatedContent = $this->updateNoteParent($note, $targetFolderId);
                $success = $this->putFileContent($this->joplinPath.$note['filename'], $updatedContent);

                if ($success) {
                    $stats['notes_moved']++;
                } else {
                    $stats['failed_operations']++;
                    $this->log("  <error>FAILED:</error> {$note['title']}");
                }
            }
        }

        $this->log("\n=== Summary ===");
        $this->log("Total notes processed: {$stats['total_notes']}");
        $this->log("Duplicates removed: {$stats['duplicates_deleted']}");
        $this->log("Notes moved: {$stats['notes_moved']}");
        $this->log("Failed operations: {$stats['failed_operations']}");
        $this->log("Categories created: {$stats['categories_created']}");

        return $stats;
    }

    /**
     * Find duplicates by video ID or normalized title
     */
    protected function findDuplicates(array $notes): array
    {
        $videoIds = [];
        $titleMap = [];
        $duplicates = [];
        $uniqueNotes = [];

        foreach ($notes as $note) {
            $videoId = $this->extractVideoId($note);
            $normalizedTitle = $this->normalizeTitle($note['title']);
            $isDuplicate = false;

            if ($videoId) {
                if (isset($videoIds[$videoId])) {
                    $isDuplicate = true;
                } else {
                    $videoIds[$videoId] = $note;
                }
            }

            if (! $isDuplicate && $normalizedTitle) {
                if (isset($titleMap[$normalizedTitle])) {
                    $isDuplicate = true;
                } else {
                    $titleMap[$normalizedTitle] = $note;
                }
            }

            if ($isDuplicate) {
                $duplicates[] = $note;
            } else {
                $uniqueNotes[] = $note;
            }
        }

        return [$uniqueNotes, $duplicates];
    }

    /**
     * Categorize notes by keyword matching
     */
    protected function categorizeNotes(array $notes): array
    {
        $categorized = [];

        foreach ($notes as $note) {
            $category = $this->categorizeNote($note);
            if (! isset($categorized[$category])) {
                $categorized[$category] = [];
            }
            $categorized[$category][] = $note;
        }

        return $categorized;
    }

    /**
     * Categorize a single note
     */
    protected function categorizeNote(array $note): string
    {
        $text = strtolower($note['title'].' '.$note['content']);
        $scores = [];

        foreach ($this->categories as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$category] = $score;
            }
        }

        if (empty($scores)) {
            return 'Uncategorized';
        }

        arsort($scores);

        return array_key_first($scores);
    }

    /**
     * Extract YouTube video ID from note
     */
    protected function extractVideoId(array $note): ?string
    {
        $text = $note['title'].' '.$note['content'];

        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Normalize title for duplicate detection
     */
    protected function normalizeTitle(string $title): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/\s+/', ' ', $title);
        $title = preg_replace('/[^\w\s]/', '', $title);

        return $title;
    }

    /**
     * List files in Joplin directory (filesystem-first, WebDAV fallback)
     */
    public function listDirectory(string $path): array
    {
        // Filesystem-first: direct directory scan
        if ($this->localPath && $path === $this->joplinPath) {
            $files = [];
            foreach (scandir($this->localPath) as $file) {
                if (preg_match('/^[a-f0-9]{32}\.md$/', $file)) {
                    $files[] = $file;
                }
            }

            return $files;
        }

        // WebDAV fallback
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Depth: 1']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return [];
        }

        preg_match_all('/<d:href>([^<]+)<\/d:href>/', $response, $matches);

        $files = [];
        foreach ($matches[1] as $href) {
            $basename = basename($href);
            if ($basename && $basename !== basename($path) && preg_match('/^[a-f0-9]{32}\.md$/', $basename)) {
                $files[] = $basename;
            }
        }

        return $files;
    }

    /**
     * Batch fetch file contents (filesystem-first, WebDAV fallback)
     */
    public function getFileContentsBatch(array $filenames, int $batchSize = 50): array
    {
        // Filesystem-first: direct file reads
        if ($this->localPath) {
            $results = [];
            foreach ($filenames as $filename) {
                $path = $this->localPath.'/'.$filename;
                if (file_exists($path)) {
                    $results[$filename] = file_get_contents($path);
                }
            }

            return $results;
        }

        // WebDAV fallback with curl_multi
        $results = [];
        $batches = array_chunk($filenames, $batchSize);

        foreach ($batches as $batch) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($batch as $filename) {
                $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$this->joplinPath.$filename;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_multi_add_handle($mh, $ch);
                $handles[$filename] = $ch;
            }

            $running = null;
            do {
                $multiStatus = curl_multi_exec($mh, $running);
                if ($multiStatus !== CURLM_OK) {
                    $this->log("WebDAV batch fetch failed: curl_multi_exec status {$multiStatus}", 'warning');
                    break;
                }

                if ($running > 0) {
                    $selectResult = curl_multi_select($mh, 1.0);
                    if ($selectResult === -1) {
                        usleep(100_000);
                    }
                }
            } while ($running > 0);

            foreach ($handles as $filename => $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);

                if ($error !== '') {
                    $this->log("WebDAV fetch failed for {$filename}: {$error}", 'warning');
                } elseif ($httpCode === 200) {
                    $results[$filename] = curl_multi_getcontent($ch);
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        return $results;
    }

    /**
     * Parse Joplin note content
     */
    public function parseNote(string $content): array
    {
        $lines = explode("\n", $content);

        $data = [
            'title' => '',
            'id' => '',
            'parent_id' => '',
            'type' => 0,
            'content' => '',
            'raw' => $content,
        ];

        if (count($lines) > 0) {
            $data['title'] = trim($lines[0]);
        }

        foreach ($lines as $line) {
            if (preg_match('/^id:\s*(.*)$/', $line, $m)) {
                $data['id'] = $m[1];
            } elseif (preg_match('/^parent_id:\s*(.*)$/', $line, $m)) {
                $data['parent_id'] = $m[1];
            } elseif (preg_match('/^type_:\s*(.*)$/', $line, $m)) {
                $data['type'] = (int) $m[1];
            }
        }

        $metaStart = -1;
        for ($i = 1; $i < count($lines); $i++) {
            if (preg_match('/^(id|parent_id|created_time|updated_time|type_|is_conflict):\s*/', $lines[$i])) {
                $metaStart = $i;
                break;
            }
        }

        if ($metaStart > 1) {
            $contentLines = array_slice($lines, 1, $metaStart - 1);
            $data['content'] = trim(implode("\n", $contentLines));
        }

        return $data;
    }

    /**
     * Put file content via WebDAV (writes require www-data ownership, no filesystem shortcut)
     */
    public function putFileContent(string $path, string $content): bool
    {
        $localFile = $this->getLocalFilePath($path);
        if ($localFile) {
            $directory = dirname($localFile);
            if (is_dir($directory) && is_writable($directory) && (! file_exists($localFile) || is_writable($localFile))) {
                $written = @file_put_contents($localFile, $content, LOCK_EX);
                if ($written !== false) {
                    return true;
                }

                $this->log("Filesystem PUT fallback to WebDAV for {$path}", 'warning');
            }
        }

        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            $this->log("WebDAV PUT failed for {$path}: {$error}", 'warning');

            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Delete file via WebDAV
     */
    protected function deleteFile(string $path): bool
    {
        $localFile = $this->getLocalFilePath($path);
        if ($localFile && file_exists($localFile) && is_writable($localFile)) {
            if (@unlink($localFile)) {
                return true;
            }

            $this->log("Filesystem DELETE fallback to WebDAV for {$path}", 'warning');
        }

        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            $this->log("WebDAV DELETE failed for {$path}: {$error}", 'warning');

            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Create a new Joplin notebook
     */
    protected function createNotebook(string $title, string $parentId): ?string
    {
        $id = bin2hex(random_bytes(16));
        $now = date('Y-m-d\TH:i:s.v\Z');

        $content = "{$title}\n\nid: {$id}\nparent_id: {$parentId}\ncreated_time: {$now}\nupdated_time: {$now}\nuser_created_time: {$now}\nuser_updated_time: {$now}\nencryption_cipher_text: \nencryption_applied: 0\nis_shared: 0\nshare_id: \nmaster_key_id: \nicon: \ntype_: 2";

        $path = $this->joplinPath.$id.'.md';
        $success = $this->putFileContent($path, $content);

        return $success ? $id : null;
    }

    /**
     * Update note's parent_id
     */
    protected function updateNoteParent(array $note, string $newParentId): string
    {
        $content = $note['raw'];
        $now = date('Y-m-d\TH:i:s.v\Z');

        $content = preg_replace('/^parent_id:\s*.*$/m', "parent_id: {$newParentId}", $content);
        $content = preg_replace('/^updated_time:\s*.*$/m', "updated_time: {$now}", $content);

        return $content;
    }

    /**
     * Consolidate ALL "YouTube - Watch Later" notebooks into the primary one.
     * Finds duplicate notebooks, collects all notes, dedup, categorize (keyword + optional AI), move, cleanup.
     */
    public function organizeAll(bool $dryRun = false, bool $useAI = false): array
    {
        if (! $this->hasConfiguredWatchLaterFolder()) {
            $this->log('JOPLIN_WATCH_LATER_FOLDER_ID is not configured. Set it to the 32-character Joplin notebook ID before consolidating Watch Later notes.', 'warning');

            return $this->unconfiguredStats($dryRun, true);
        }

        $stats = [
            'total_notes' => 0,
            'extra_folders_found' => 0,
            'extra_folders_deleted' => 0,
            'duplicates_found' => 0,
            'duplicates_deleted' => 0,
            'categories_created' => 0,
            'notes_moved' => 0,
            'ai_categorized' => 0,
            'failed_operations' => 0,
            'dry_run' => $dryRun,
            'per_source' => [],
        ];

        $this->log('=== YouTube Watch Later Consolidation ===');
        $this->log("Primary folder ID: {$this->watchLaterFolderId}");
        if ($dryRun) {
            $this->log('<comment>DRY RUN - no changes will be made</comment>');
        }

        // Step 1: Fetch all Joplin files
        $this->log("\nStep 1: Fetching all Joplin files...");
        $files = $this->listDirectory($this->joplinPath);
        $this->log('Found '.count($files).' total Joplin files');

        // Step 2: Parse all files and identify Watch Later notebooks
        $this->log("\nStep 2: Parsing files and finding Watch Later notebooks...");
        $allContents = $this->getFileContentsBatch($files, 50);
        $this->log('  Fetched '.count($allContents).' files');

        $watchLaterFolderIds = [];
        $allParsedNotes = [];
        $existingSubfolders = [];

        foreach ($allContents as $filename => $content) {
            $note = $this->parseNote($content);
            $note['filename'] = $filename;
            $allParsedNotes[$filename] = $note;

            // Find all notebooks named "YouTube - Watch Later"
            if ($note['type'] == 2 && stripos($note['title'], 'YouTube - Watch Later') !== false) {
                $watchLaterFolderIds[$note['id']] = $note;
                if ($note['id'] === $this->watchLaterFolderId) {
                    $this->log("  Primary: {$note['title']} (ID: {$note['id']})");
                } else {
                    $this->log("  Extra:   {$note['title']} (ID: {$note['id']})");
                }
            }

            // Track existing subfolders of primary notebook
            if ($note['type'] == 2 && $note['parent_id'] === $this->watchLaterFolderId) {
                $existingSubfolders[$note['id']] = $note['title'];
                $this->log("  Subfolder: {$note['title']}");
            }
        }

        $extraFolderIds = array_diff(array_keys($watchLaterFolderIds), [$this->watchLaterFolderId]);
        $stats['extra_folders_found'] = count($extraFolderIds);
        $this->log("\nFound ".count($watchLaterFolderIds).' Watch Later notebooks ('.count($extraFolderIds).' extras)');

        // Step 3: Collect notes from ALL Watch Later notebooks (primary + extras)
        $this->log("\nStep 3: Collecting notes from all Watch Later notebooks...");
        $allWatchLaterNotes = [];
        $allTargetFolderIds = array_merge([$this->watchLaterFolderId], $extraFolderIds);

        foreach ($allParsedNotes as $filename => $note) {
            if ($note['type'] == 1 && in_array($note['parent_id'], $allTargetFolderIds)) {
                $allWatchLaterNotes[] = $note;
                $source = $note['parent_id'] === $this->watchLaterFolderId ? 'primary' : 'extra:'.substr($note['parent_id'], 0, 8);
                if (! isset($stats['per_source'][$source])) {
                    $stats['per_source'][$source] = 0;
                }
                $stats['per_source'][$source]++;
            }
        }

        $stats['total_notes'] = count($allWatchLaterNotes);
        $this->log("Collected {$stats['total_notes']} total notes");
        foreach ($stats['per_source'] as $source => $count) {
            $this->log("  {$source}: {$count} notes");
        }

        // Step 4: Dedup across ALL collected notes
        $this->log("\nStep 4: Identifying duplicates across all folders...");
        [$uniqueNotes, $duplicates] = $this->findDuplicates($allWatchLaterNotes);
        $stats['duplicates_found'] = count($duplicates);
        $this->log("Found {$stats['duplicates_found']} duplicates, keeping ".count($uniqueNotes).' unique notes');

        // Track filenames of notes that were moved or deleted, so step 9 can detect truly empty folders
        $processedFilenames = [];

        // Step 5: Delete duplicates
        if (count($duplicates) > 0) {
            $this->log("\nStep 5: ".($dryRun ? 'Would delete' : 'Deleting').' duplicates...');
            foreach ($duplicates as $note) {
                if ($dryRun) {
                    $this->log('  Would delete: '.substr($note['title'], 0, 50));
                    $processedFilenames[$note['filename']] = true;

                    continue;
                }
                $success = $this->deleteFile($this->joplinPath.$note['filename']);
                if ($success) {
                    $stats['duplicates_deleted']++;
                    $processedFilenames[$note['filename']] = true;
                } else {
                    $stats['failed_operations']++;
                    $this->log("  <error>FAILED:</error> {$note['title']}");
                }
            }
            if (! $dryRun) {
                $this->log("Deleted {$stats['duplicates_deleted']} duplicates");
            }
        }

        // Step 6: Categorize notes (keyword + optional AI)
        $this->log("\nStep 6: Categorizing notes...");
        $categorizedNotes = $this->categorizeNotes($uniqueNotes);

        // AI fallback for uncategorized notes
        if ($useAI && isset($categorizedNotes['Uncategorized']) && count($categorizedNotes['Uncategorized']) > 0) {
            $uncategorized = $categorizedNotes['Uncategorized'];
            unset($categorizedNotes['Uncategorized']);
            $this->log('  Running AI categorization on '.count($uncategorized).' uncategorized notes...');

            foreach ($uncategorized as $note) {
                $aiCategory = $this->categorizeNoteWithAI($note);
                $stats['ai_categorized']++;
                $this->log('  AI: '.substr($note['title'], 0, 40)."... -> {$aiCategory}");
                if (! isset($categorizedNotes[$aiCategory])) {
                    $categorizedNotes[$aiCategory] = [];
                }
                $categorizedNotes[$aiCategory][] = $note;
            }
        }

        $this->log('Categories:');
        foreach ($categorizedNotes as $category => $notes) {
            $this->log("  {$category}: ".count($notes).' notes');
        }

        // Step 7: Create subfolders and move notes
        $this->log("\nStep 7: Creating subfolders and moving notes...");
        $categoryToFolderId = [];

        foreach ($categorizedNotes as $category => $notes) {
            $existingId = array_search($category, $existingSubfolders);
            if ($existingId !== false) {
                $categoryToFolderId[$category] = $existingId;
                $this->log("  Using existing: {$category}");
            } elseif (! $dryRun) {
                $newId = $this->createNotebook($category, $this->watchLaterFolderId);
                if ($newId) {
                    $categoryToFolderId[$category] = $newId;
                    $stats['categories_created']++;
                    $this->log("  <info>Created:</info> {$category}");
                } else {
                    $stats['failed_operations']++;
                    $this->log("  <error>FAILED:</error> {$category}");
                }
            } else {
                $this->log("  Would create: {$category} (dry run)");
            }
        }

        // Step 8: Move notes
        $this->log("\nStep 8: Moving notes to subfolders...");
        foreach ($categorizedNotes as $category => $notes) {
            if (! isset($categoryToFolderId[$category]) && ! $dryRun) {
                continue;
            }

            $targetFolderId = $categoryToFolderId[$category] ?? 'DRY_RUN';

            foreach ($notes as $note) {
                // Skip notes already in the correct subfolder
                if (isset($categoryToFolderId[$category]) && $note['parent_id'] === $categoryToFolderId[$category]) {
                    continue;
                }

                if ($dryRun) {
                    $this->log('  Would move: '.substr($note['title'], 0, 40)."... -> {$category}");
                    $processedFilenames[$note['filename']] = true;

                    continue;
                }

                $updatedContent = $this->updateNoteParent($note, $targetFolderId);
                $success = $this->putFileContent($this->joplinPath.$note['filename'], $updatedContent);

                if ($success) {
                    $stats['notes_moved']++;
                    $processedFilenames[$note['filename']] = true;
                } else {
                    $stats['failed_operations']++;
                    $this->log("  <error>FAILED:</error> {$note['title']}");
                }
            }
        }

        // Step 9: Delete empty extra Watch Later folders
        $this->log("\nStep 9: Cleaning up empty extra folders...");
        foreach ($extraFolderIds as $extraId) {
            // Check if any notes still reference this folder that weren't moved/deleted
            $hasNotes = false;
            foreach ($allParsedNotes as $note) {
                if ($note['type'] == 1 && $note['parent_id'] === $extraId && ! isset($processedFilenames[$note['filename']])) {
                    $hasNotes = true;
                    break;
                }
            }

            $extraNote = $watchLaterFolderIds[$extraId];
            if ($dryRun) {
                $this->log("  Would delete folder: {$extraNote['title']} (ID: {$extraId})".($hasNotes ? ' [still has notes - skipped in live run]' : ''));
            } elseif (! $hasNotes) {
                $success = $this->deleteFile($this->joplinPath.$extraNote['filename']);
                if ($success) {
                    $stats['extra_folders_deleted']++;
                    $this->log("  <info>Deleted:</info> {$extraNote['title']}");
                } else {
                    $stats['failed_operations']++;
                    $this->log("  <error>FAILED:</error> {$extraNote['title']}");
                }
            } else {
                $this->log("  Skipped (still has notes): {$extraNote['title']}");
            }
        }

        $this->log("\n=== Consolidation Summary ===");
        $this->log("Total notes processed: {$stats['total_notes']}");
        $this->log("Extra folders found: {$stats['extra_folders_found']}");
        $this->log("Extra folders deleted: {$stats['extra_folders_deleted']}");
        $this->log("Duplicates removed: {$stats['duplicates_deleted']}");
        $this->log("AI categorized: {$stats['ai_categorized']}");
        $this->log("Notes moved: {$stats['notes_moved']}");
        $this->log("Categories created: {$stats['categories_created']}");
        $this->log("Failed operations: {$stats['failed_operations']}");

        return $stats;
    }

    /**
     * Categorize a note using AI when keyword matching fails
     */
    protected function categorizeNoteWithAI(array $note): string
    {
        try {
            $aiService = app(AIService::class);
            $categoryNames = implode(', ', array_keys($this->categories));
            $textSample = substr($note['title']."\n".$note['content'], 0, 500);

            $prompt = "Classify this YouTube video into one of these categories: {$categoryNames}. "
                ."Return ONLY the category name, nothing else.\n\n"
                ."Video: {$textSample}";

            $result = $aiService->process($prompt, [
                'max_tokens' => 50,
                'temperature' => 0,
            ]);

            $aiCategory = trim($result['response'] ?? '');

            // Validate the AI returned an actual category name
            if (array_key_exists($aiCategory, $this->categories)) {
                return $aiCategory;
            }

            // Fuzzy match: AI might return slightly different casing
            foreach (array_keys($this->categories) as $cat) {
                if (strcasecmp($aiCategory, $cat) === 0) {
                    return $cat;
                }
            }

            return 'Uncategorized';
        } catch (\Exception $e) {
            Log::warning('[JoplinYouTubeOrganizer] AI categorization failed', [
                'note_title' => $note['title'],
                'error' => $e->getMessage(),
            ]);

            return 'Uncategorized';
        }
    }

    /**
     * Get the Joplin data path
     */
    public function getJoplinPath(): string
    {
        return $this->joplinPath;
    }

    /**
     * Get the watch later folder ID
     */
    public function getWatchLaterFolderId(): string
    {
        return $this->watchLaterFolderId;
    }

    /**
     * Get categories configuration
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /**
     * Set custom categories
     */
    public function setCategories(array $categories): self
    {
        $this->categories = $categories;

        return $this;
    }

    /**
     * Set watch later folder ID
     */
    public function setWatchLaterFolderId(string $id): self
    {
        $this->watchLaterFolderId = $id;

        return $this;
    }
}
