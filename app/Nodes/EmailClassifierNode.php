<?php

namespace App\Nodes;

use App\Services\EmailClassificationService;

/**
 * Email Classifier Node
 *
 * Classifies emails using AI (category, priority, tags, summary)
 */
class EmailClassifierNode extends BaseNode
{
    public function execute(array $context): array
    {
        $query = $this->getConfig('query', '');
        $folder = $this->getConfig('folder', 'INBOX');
        $limit = (int) $this->getConfig('limit', 10);

        if (empty($query)) {
            return $this->error('Query is required');
        }

        try {
            $service = app(EmailClassificationService::class);
            $result = $service->searchAndClassify($query, $folder, $limit);

            if (!$result['success']) {
                return $this->error($result['error'] ?? 'Classification failed');
            }

            return $this->success([
                'classified_count' => $result['count'],
                'results' => $result['results'],
                'message' => "Classified {$result['count']} emails",
            ]);

        } catch (\Exception $e) {
            return $this->error('Email classification failed: ' . $e->getMessage());
        }
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'email_classifier',
            'name' => 'Email Classifier',
            'description' => 'Classify emails using AI (category, priority, tags)',
            'category' => 'Email',
            'icon' => '📧',
            'config' => [
                'query' => [
                    'type' => 'string',
                    'label' => 'Search Query',
                    'description' => 'Search term to find emails',
                    'required' => true,
                    'default' => '',
                ],
                'folder' => [
                    'type' => 'string',
                    'label' => 'Folder',
                    'description' => 'Email folder to search (default: INBOX)',
                    'required' => false,
                    'default' => 'INBOX',
                ],
                'limit' => [
                    'type' => 'integer',
                    'label' => 'Limit',
                    'description' => 'Maximum number of emails to classify',
                    'required' => false,
                    'default' => 10,
                ],
            ],
            'outputs' => [
                'classified_count' => 'Number of emails classified',
                'results' => 'Array of classified emails with categories, priorities, tags',
                'message' => 'Status message',
            ],
        ];
    }
}
