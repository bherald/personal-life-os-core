<?php

namespace App\Console\Commands;

use App\Services\JoplinWriteService;
use App\Services\JoplinFilesService;
use Illuminate\Console\Command;

/**
 * Joplin Write Command
 *
 * CLI tool for testing Joplin write operations.
 * Supports creating, updating, appending, and deleting notes.
 */
class JoplinWriteCommand extends Command
{
    protected $signature = 'joplin:write
                            {action : Action to perform (create, update, append, delete, notebook, conflicts, test)}
                            {--title= : Note title}
                            {--content= : Note content}
                            {--id= : Note ID for update/append/delete}
                            {--notebook= : Notebook ID}
                            {--separator=\n\n--- : Separator for append}
                            {--parent= : Parent ID for notebook}';

    protected $description = 'Test Joplin write operations via WebDAV';

    protected JoplinWriteService $writeService;
    protected JoplinFilesService $readService;

    public function __construct(JoplinWriteService $writeService, JoplinFilesService $readService)
    {
        parent::__construct();
        $this->writeService = $writeService;
        $this->readService = $readService;
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        try {
            switch ($action) {
                case 'create':
                    return $this->createNote();

                case 'update':
                    return $this->updateNote();

                case 'append':
                    return $this->appendToNote();

                case 'delete':
                    return $this->deleteNote();

                case 'notebook':
                    return $this->createNotebook();

                case 'conflicts':
                    return $this->detectConflicts();

                case 'test':
                    return $this->runTests();

                default:
                    $this->error("Unknown action: $action");
                    $this->info('Available actions: create, update, append, delete, notebook, conflicts, test');
                    return 1;
            }

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Create a new note
     */
    protected function createNote(): int
    {
        $title = $this->option('title');
        $content = $this->option('content') ?? 'Test note created via CLI';
        $notebookId = $this->option('notebook');

        if (!$title) {
            $title = 'CLI Test Note - ' . now()->format('Y-m-d H:i:s');
        }

        $this->info('Creating note...');
        $result = $this->writeService->createNote($title, $content, $notebookId);

        if ($result['success']) {
            $this->info('✓ Note created successfully');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Note ID', $result['note_id']],
                    ['Title', $result['title']],
                    ['Parent ID', $result['parent_id'] ?? 'None'],
                    ['Created Time', $result['created_time']],
                    ['Path', $result['path']],
                ]
            );
            return 0;
        } else {
            $this->error('✗ Failed to create note: ' . $result['error']);
            return 1;
        }
    }

    /**
     * Update an existing note
     */
    protected function updateNote(): int
    {
        $noteId = $this->option('id');

        if (!$noteId) {
            $this->error('Note ID is required (--id)');
            return 1;
        }

        $updates = [];
        if ($title = $this->option('title')) {
            $updates['title'] = $title;
        }
        if ($content = $this->option('content')) {
            $updates['content'] = $content;
        }
        if ($notebookId = $this->option('notebook')) {
            $updates['parent_id'] = $notebookId;
        }

        if (empty($updates)) {
            $this->error('No updates specified (use --title, --content, or --notebook)');
            return 1;
        }

        $this->info('Updating note...');
        $result = $this->writeService->updateNote($noteId, $updates, false);

        if ($result['success']) {
            $this->info('✓ Note updated successfully');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Note ID', $result['note_id']],
                    ['Title', $result['title']],
                    ['Updated Time', $result['updated_time']],
                ]
            );
            return 0;
        } else {
            $this->error('✗ Failed to update note: ' . $result['error']);
            return 1;
        }
    }

    /**
     * Append content to a note
     */
    protected function appendToNote(): int
    {
        $noteId = $this->option('id');
        $content = $this->option('content');
        $separator = $this->option('separator');

        if (!$noteId) {
            $this->error('Note ID is required (--id)');
            return 1;
        }

        if (!$content) {
            $this->error('Content is required (--content)');
            return 1;
        }

        $this->info('Appending to note...');
        $result = $this->writeService->appendToNote($noteId, $content, $separator);

        if ($result['success']) {
            $this->info('✓ Content appended successfully');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Note ID', $result['note_id']],
                    ['Title', $result['title']],
                    ['Updated Time', $result['updated_time']],
                ]
            );
            return 0;
        } else {
            $this->error('✗ Failed to append content: ' . $result['error']);
            return 1;
        }
    }

    /**
     * Delete a note
     */
    protected function deleteNote(): int
    {
        $noteId = $this->option('id');

        if (!$noteId) {
            $this->error('Note ID is required (--id)');
            return 1;
        }

        if (!$this->confirm("Are you sure you want to delete note $noteId?")) {
            $this->info('Deletion cancelled');
            return 0;
        }

        $this->info('Deleting note...');
        $result = $this->writeService->deleteNote($noteId);

        if ($result['success']) {
            $this->info('✓ Note deleted successfully');
            return 0;
        } else {
            $this->error('✗ Failed to delete note: ' . $result['error']);
            return 1;
        }
    }

    /**
     * Create a new notebook
     */
    protected function createNotebook(): int
    {
        $title = $this->option('title');
        $parentId = $this->option('parent');

        if (!$title) {
            $title = 'CLI Test Notebook - ' . now()->format('Y-m-d H:i:s');
        }

        $this->info('Creating notebook...');
        $result = $this->writeService->createNotebook($title, $parentId);

        if ($result['success']) {
            $this->info('✓ Notebook created successfully');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Notebook ID', $result['notebook_id']],
                    ['Title', $result['title']],
                    ['Parent ID', $result['parent_id'] ?? 'None'],
                ]
            );
            return 0;
        } else {
            $this->error('✗ Failed to create notebook: ' . $result['error']);
            return 1;
        }
    }

    /**
     * Detect sync conflicts
     */
    protected function detectConflicts(): int
    {
        $this->info('Detecting sync conflicts...');
        $this->info('This requires a list of local note states (ID => updated_time)');
        $this->info('Use this via API: POST /api/joplin/sync/detect-conflicts');

        // For CLI demo, just show status
        $status = $this->writeService->getStatus();
        $this->table(
            ['Capability', 'Available'],
            [
                ['Create Notes', $status['capabilities']['create_notes'] ? '✓' : '✗'],
                ['Update Notes', $status['capabilities']['update_notes'] ? '✓' : '✗'],
                ['Append Notes', $status['capabilities']['append_notes'] ? '✓' : '✗'],
                ['Create Notebooks', $status['capabilities']['create_notebooks'] ? '✓' : '✗'],
                ['Delete Notes', $status['capabilities']['delete_notes'] ? '✓' : '✗'],
                ['Conflict Detection', $status['capabilities']['conflict_detection'] ? '✓' : '✗'],
            ]
        );

        return 0;
    }

    /**
     * Run comprehensive tests
     */
    protected function runTests(): int
    {
        $this->info('=== Running Joplin Write Tests ===');
        $this->newLine();

        $noteId = null;
        $notebookId = null;

        // Test 1: Create notebook
        $this->info('[1/5] Testing notebook creation...');
        $result = $this->writeService->createNotebook('CLI Test Notebook', null);
        if ($result['success']) {
            $notebookId = $result['notebook_id'];
            $this->info('  ✓ Notebook created: ' . $notebookId);
        } else {
            $this->error('  ✗ Failed: ' . $result['error']);
            return 1;
        }

        // Test 2: Create note
        $this->info('[2/5] Testing note creation...');
        $result = $this->writeService->createNote(
            'CLI Test Note',
            'This is a test note created by the CLI command.',
            $notebookId
        );
        if ($result['success']) {
            $noteId = $result['note_id'];
            $this->info('  ✓ Note created: ' . $noteId);
        } else {
            $this->error('  ✗ Failed: ' . $result['error']);
            return 1;
        }

        // Test 3: Update note
        $this->info('[3/5] Testing note update...');
        $result = $this->writeService->updateNote($noteId, [
            'title' => 'CLI Test Note (Updated)',
            'content' => 'This note has been updated.',
        ], false);
        if ($result['success']) {
            $this->info('  ✓ Note updated successfully');
        } else {
            $this->error('  ✗ Failed: ' . $result['error']);
            return 1;
        }

        // Test 4: Append to note
        $this->info('[4/5] Testing note append...');
        $result = $this->writeService->appendToNote(
            $noteId,
            'This content was appended via CLI.',
            "\n\n---\n\n"
        );
        if ($result['success']) {
            $this->info('  ✓ Content appended successfully');
        } else {
            $this->error('  ✗ Failed: ' . $result['error']);
            return 1;
        }

        // Test 5: Verify note exists
        $this->info('[5/5] Verifying note in Joplin...');
        $note = $this->readService->getNote($noteId);
        if ($note) {
            $this->info('  ✓ Note verified in Joplin');
            $this->newLine();
            $this->table(
                ['Field', 'Value'],
                [
                    ['Note ID', $note['id']],
                    ['Title', $note['title']],
                    ['Type', $note['type'] == 1 ? 'Note' : 'Notebook'],
                    ['Created', $note['created_time']],
                    ['Updated', $note['updated_time']],
                ]
            );
        } else {
            $this->error('  ✗ Note not found in Joplin');
            return 1;
        }

        $this->newLine();
        $this->info('=== All Tests Passed ✓ ===');
        $this->info("Test note ID: $noteId");
        $this->info("Test notebook ID: $notebookId");
        $this->newLine();
        $this->comment('You can delete the test note with:');
        $this->comment("  php artisan joplin:write delete --id=$noteId");

        return 0;
    }
}
