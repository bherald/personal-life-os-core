<?php

namespace App\Console\Commands;

use App\Services\AIService;
use App\Services\JoplinFilesService;
use App\Services\JoplinWriteService;
use Illuminate\Console\Command;

class JoplinAISort extends Command
{
    protected $signature = 'joplin:ai-sort
                            {--notebook= : Notebook name to sort}
                            {--dry-run : Show what would be done without making changes}
                            {--limit=100 : Maximum notes to process}';

    protected $description = 'Sort any Joplin notebook\'s notes into subfolders using AI classification';

    public function handle(JoplinFilesService $filesService, JoplinWriteService $writeService, AIService $aiService): int
    {
        $notebookName = $this->option('notebook');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if (!$notebookName) {
            $this->error('--notebook is required');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info("Finding notebook: {$notebookName}");

        // Find target notebook
        $notebooks = $filesService->getNotebooks();
        $targetNotebook = null;
        foreach ($notebooks as $nb) {
            if (strcasecmp($nb['title'], $notebookName) === 0) {
                $targetNotebook = $nb;
                break;
            }
        }

        if (!$targetNotebook) {
            $this->error("Notebook not found: {$notebookName}");
            $this->line('Available notebooks:');
            foreach ($notebooks as $nb) {
                $this->line("  - {$nb['title']}");
            }
            return self::FAILURE;
        }

        $this->info("Found notebook: {$targetNotebook['title']} (ID: {$targetNotebook['id']})");

        // Get existing subfolders as categories
        $subfolders = [];
        foreach ($notebooks as $nb) {
            $note = $filesService->getNote($nb['id']);
            if ($note && $note['parent_id'] === $targetNotebook['id']) {
                $subfolders[$nb['id']] = $nb['title'];
            }
        }

        if (empty($subfolders)) {
            $this->warn('No existing subfolders found. AI will suggest new categories.');
        } else {
            $this->info('Existing subfolders (used as categories):');
            foreach ($subfolders as $id => $title) {
                $this->line("  - {$title}");
            }
        }

        // Get unsorted notes (direct children of the notebook, type 1 only)
        $notes = $filesService->getNotesInNotebook($targetNotebook['id']);
        $this->info("\nFound " . count($notes) . " unsorted notes in root of notebook");

        if (empty($notes)) {
            $this->info('No unsorted notes to process.');
            return self::SUCCESS;
        }

        $notes = array_slice($notes, 0, $limit);
        $this->info("Processing " . count($notes) . " notes (limit: {$limit})");
        $this->newLine();

        $categoryNames = !empty($subfolders) ? implode(', ', array_values($subfolders)) : null;
        $moved = 0;
        $created = 0;
        $failed = 0;
        $newFolderIds = []; // track newly created subfolder IDs

        foreach ($notes as $i => $note) {
            $noteDetail = $filesService->getNote($note['id']);
            if (!$noteDetail) {
                $this->line("  <error>Could not read note:</error> {$note['title']}");
                $failed++;
                continue;
            }

            $textSample = substr($noteDetail['title'] . "\n" . ($noteDetail['content'] ?? ''), 0, 500);

            // Build AI prompt
            if ($categoryNames) {
                $prompt = "Classify this note into one of these existing categories: {$categoryNames}. "
                    . "If none fit well, suggest a short new category name (2-3 words). "
                    . "Return ONLY the category name, nothing else.\n\n"
                    . "Note: {$textSample}";
            } else {
                $prompt = "Suggest a short category name (2-3 words) for organizing this note into a folder. "
                    . "Return ONLY the category name, nothing else.\n\n"
                    . "Note: {$textSample}";
            }

            try {
                $result = $aiService->process($prompt, [
                    'max_tokens' => 50,
                    'temperature' => 0,
                ]);

                $category = trim($result['response'] ?? '');
                if (empty($category)) {
                    $this->line("  <comment>Skip (empty AI response):</comment> " . substr($note['title'], 0, 50));
                    $failed++;
                    continue;
                }
            } catch (\Exception $e) {
                $this->line("  <error>AI error:</error> " . substr($note['title'], 0, 50) . " - " . $e->getMessage());
                $failed++;
                continue;
            }

            // Find or create the subfolder
            $targetFolderId = array_search($category, $subfolders);

            // Case-insensitive fallback
            if ($targetFolderId === false) {
                foreach ($subfolders as $fid => $fname) {
                    if (strcasecmp($fname, $category) === 0) {
                        $targetFolderId = $fid;
                        $category = $fname; // normalize to existing name
                        break;
                    }
                }
            }

            $prefix = '[' . ($i + 1) . '/' . count($notes) . ']';

            if ($targetFolderId === false) {
                // New category - create subfolder
                if ($dryRun) {
                    $this->line("  {$prefix} <info>NEW</info> " . substr($note['title'], 0, 40) . "... -> {$category}");
                    $subfolders['dry_' . $i] = $category;
                } else {
                    $result = $writeService->createNotebook($category, $targetNotebook['id']);
                    if ($result['success'] ?? false) {
                        $newId = $result['notebook_id'];
                        $subfolders[$newId] = $category;
                        $targetFolderId = $newId;
                        $newFolderIds[] = $newId;
                        $created++;
                        $this->line("  {$prefix} <info>NEW</info> " . substr($note['title'], 0, 40) . "... -> {$category} (created)");
                    } else {
                        $this->line("  {$prefix} <error>Failed to create folder:</error> {$category}");
                        $failed++;
                        continue;
                    }
                }
            } else {
                $this->line("  {$prefix} " . substr($note['title'], 0, 40) . "... -> {$category}");
            }

            // Move note
            if (!$dryRun && $targetFolderId !== false) {
                $updateResult = $writeService->updateNote($note['id'], [
                    'parent_id' => $targetFolderId,
                ], false);

                if ($updateResult['success'] ?? false) {
                    $moved++;
                } else {
                    $this->line("    <error>Move failed:</error> " . ($updateResult['error'] ?? 'unknown'));
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info('Sort complete!');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Notes Processed', count($notes)],
                ['Notes Moved', $dryRun ? '(dry run)' : $moved],
                ['New Categories Created', $dryRun ? '(dry run)' : $created],
                ['Failed', $failed],
                ['Dry Run', $dryRun ? 'Yes' : 'No'],
            ]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
