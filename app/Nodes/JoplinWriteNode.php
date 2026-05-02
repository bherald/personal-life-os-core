<?php

namespace App\Nodes;

use App\Services\JoplinWriteService;
use Exception;

/**
 * Joplin Write Node
 *
 * Writes workflow outputs to Joplin notes.
 * Supports creating new notes, updating existing notes, and appending content.
 */
class JoplinWriteNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $service = app(JoplinWriteService::class);

            $action = $this->getConfigValue('action', 'create'); // create, update, append
            $title = $this->getConfigValue('title', '');
            $content = $this->getConfigValue('content', '');
            $notebookId = $this->getConfigValue('notebook_id', null);

            // Support dynamic content from previous nodes
            if (isset($input['formatted_text'])) {
                $content = $input['formatted_text'];
            } elseif (isset($input['data']) && is_string($input['data'])) {
                $content = $input['data'];
            }

            // Dynamic title replacement
            $title = $this->replacePlaceholders($title, $input);
            $content = $this->replacePlaceholders($content, $input);

            switch ($action) {
                case 'create':
                    return $this->createNote($service, $title, $content, $notebookId);

                case 'update':
                    $noteId = $this->getConfigValue('note_id', '');
                    return $this->updateNote($service, $noteId, $title, $content);

                case 'append':
                    $noteId = $this->getConfigValue('note_id', '');
                    $separator = $this->getConfigValue('separator', "\n\n---\n\n");
                    return $this->appendToNote($service, $noteId, $content, $separator);

                case 'create_notebook':
                    $parentId = $this->getConfigValue('parent_id', null);
                    return $this->createNotebook($service, $title, $parentId);

                default:
                    throw new Exception("Unknown action: $action");
            }

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function createNote(JoplinWriteService $service, string $title, string $content, ?string $notebookId): array
    {
        if (empty($title)) {
            return $this->standardOutput(null, [], 'Title is required for creating notes');
        }

        $result = $service->createNote($title, $content, $notebookId);

        if (!$result['success']) {
            return $this->standardOutput(null, [], $result['error'] ?? 'Failed to create note');
        }

        return $this->standardOutput([
            'note_id' => $result['note_id'],
            'title' => $result['title'],
            'created' => true,
            'path' => $result['path'],
        ], [
            'action' => 'create',
            'created_time' => $result['created_time'],
        ]);
    }

    private function updateNote(JoplinWriteService $service, string $noteId, string $title, string $content): array
    {
        if (empty($noteId)) {
            return $this->standardOutput(null, [], 'Note ID is required for updates');
        }

        $updates = [];
        if (!empty($title)) {
            $updates['title'] = $title;
        }
        if (!empty($content)) {
            $updates['content'] = $content;
        }

        $result = $service->updateNote($noteId, $updates, false);

        if (!$result['success']) {
            return $this->standardOutput(null, [], $result['error'] ?? 'Failed to update note');
        }

        return $this->standardOutput([
            'note_id' => $noteId,
            'updated' => true,
            'title' => $result['title'],
        ], [
            'action' => 'update',
            'updated_time' => $result['updated_time'],
        ]);
    }

    private function appendToNote(JoplinWriteService $service, string $noteId, string $content, string $separator): array
    {
        if (empty($noteId)) {
            return $this->standardOutput(null, [], 'Note ID is required for append');
        }

        if (empty($content)) {
            return $this->standardOutput(null, [], 'Content is required for append');
        }

        $result = $service->appendToNote($noteId, $content, $separator);

        if (!$result['success']) {
            return $this->standardOutput(null, [], $result['error'] ?? 'Failed to append to note');
        }

        return $this->standardOutput([
            'note_id' => $noteId,
            'appended' => true,
            'title' => $result['title'],
        ], [
            'action' => 'append',
            'updated_time' => $result['updated_time'],
        ]);
    }

    private function createNotebook(JoplinWriteService $service, string $title, ?string $parentId): array
    {
        if (empty($title)) {
            return $this->standardOutput(null, [], 'Title is required for creating notebooks');
        }

        $result = $service->createNotebook($title, $parentId);

        if (!$result['success']) {
            return $this->standardOutput(null, [], $result['error'] ?? 'Failed to create notebook');
        }

        return $this->standardOutput([
            'notebook_id' => $result['notebook_id'],
            'title' => $result['title'],
            'created' => true,
        ], [
            'action' => 'create_notebook',
        ]);
    }

    /**
     * Replace placeholders in text with input values
     */
    private function replacePlaceholders(string $text, array $input): string
    {
        // Replace {date} with current date
        $text = str_replace('{date}', now()->format('Y-m-d'), $text);
        $text = str_replace('{datetime}', now()->format('Y-m-d H:i:s'), $text);
        $text = str_replace('{today}', now()->format('F j, Y'), $text);

        // Replace {workflow_*} placeholders
        if (isset($input['workflow_name'])) {
            $text = str_replace('{workflow_name}', $input['workflow_name'], $text);
        }

        return $text;
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'joplin_write',
            'name' => 'Joplin Write',
            'description' => 'Write workflow outputs to Joplin notes (create, update, append)',
            'category' => 'Integration',
            'icon' => '📝',
            'config' => [
                'action' => [
                    'type' => 'select',
                    'label' => 'Action',
                    'description' => 'What to do with the note',
                    'required' => true,
                    'options' => [
                        'create' => 'Create New Note',
                        'update' => 'Update Existing Note',
                        'append' => 'Append to Note',
                        'create_notebook' => 'Create Notebook',
                    ],
                    'default' => 'create',
                ],
                'title' => [
                    'type' => 'string',
                    'label' => 'Note Title',
                    'description' => 'Title of the note (supports {date}, {today} placeholders)',
                    'required' => true,
                    'default' => 'Workflow Output - {today}',
                ],
                'content' => [
                    'type' => 'text',
                    'label' => 'Content',
                    'description' => 'Note content (markdown). Leave empty to use previous node output.',
                    'required' => false,
                    'default' => '',
                ],
                'note_id' => [
                    'type' => 'string',
                    'label' => 'Note ID',
                    'description' => 'Required for update/append actions',
                    'required' => false,
                    'show_if' => ['action' => ['update', 'append']],
                ],
                'notebook_id' => [
                    'type' => 'string',
                    'label' => 'Notebook ID',
                    'description' => 'Parent notebook (optional)',
                    'required' => false,
                    'show_if' => ['action' => 'create'],
                ],
                'separator' => [
                    'type' => 'string',
                    'label' => 'Separator',
                    'description' => 'Content separator for append action',
                    'required' => false,
                    'default' => "\n\n---\n\n",
                    'show_if' => ['action' => 'append'],
                ],
            ],
            'outputs' => [
                'note_id' => 'ID of the created/updated note',
                'title' => 'Note title',
                'created' => 'Boolean: was note created',
                'updated' => 'Boolean: was note updated',
                'appended' => 'Boolean: was content appended',
            ],
        ];
    }
}
