<?php

namespace App\Nodes;

use App\Services\EmailService;
use Exception;

/**
 * Email Node (EA2)
 *
 * Workflow node for email operations:
 * - Send emails immediately or queue for approval
 * - Classify emails using AI
 * - Search emails for context
 *
 * Supports variable substitution in to, subject, and body fields.
 * Example: {{contact.email}}, {{subject.name}}, {{data.result}}
 */
class EmailNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $emailService = app(EmailService::class);

            $action = $this->getConfigValue('action', 'queue');

            switch ($action) {
                case 'send':
                    return $this->executeSend($emailService, $input);

                case 'queue':
                    return $this->executeQueue($emailService, $input);

                case 'classify':
                    return $this->executeClassify($emailService, $input);

                case 'search':
                    return $this->executeSearch($emailService, $input);

                case 'status':
                    return $this->executeStatus($emailService);

                default:
                    return $this->standardOutput(null, [], "Unknown action: {$action}");
            }

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    /**
     * Send email immediately (requires Thunderbird)
     */
    private function executeSend(EmailService $emailService, array $input): array
    {
        if (!$emailService->isAvailable()) {
            return $this->standardOutput(null, [], 'Thunderbird MCP not available - email cannot be sent');
        }

        $to = $this->substituteVariables($this->getConfigValue('to', ''), $input);
        $subject = $this->substituteVariables($this->getConfigValue('subject', ''), $input);
        $body = $this->substituteVariables($this->getConfigValue('body', ''), $input);
        $from = $this->getConfigValue('from', null);

        if (empty($to) || empty($subject) || empty($body)) {
            return $this->standardOutput(null, [], 'Missing required fields: to, subject, or body');
        }

        $result = $emailService->sendEmail($to, $subject, $body, $from);

        return $this->standardOutput([
            'action' => 'send',
            'success' => $result['success'],
            'to' => $to,
            'subject' => $subject,
            'result' => $result['result'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * Queue email for human approval
     */
    private function executeQueue(EmailService $emailService, array $input): array
    {
        $to = $this->substituteVariables($this->getConfigValue('to', ''), $input);
        $subject = $this->substituteVariables($this->getConfigValue('subject', ''), $input);
        $body = $this->substituteVariables($this->getConfigValue('body', ''), $input);
        $from = $this->getConfigValue('from', null);
        $priority = $this->getConfigValue('priority', 'normal');
        $source = $this->getConfigValue('source', 'workflow');

        if (empty($to) || empty($subject) || empty($body)) {
            return $this->standardOutput(null, [], 'Missing required fields: to, subject, or body');
        }

        $email = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'from' => $from,
        ];

        $options = [
            'priority' => $priority,
            'workflow_execution_id' => $input['_execution_id'] ?? null,
        ];

        // Handle related entity linking
        $relatedType = $this->getConfigValue('related_type', null);
        $relatedId = $this->getConfigValue('related_id', null);

        if ($relatedType && $relatedId) {
            $options['related_type'] = $relatedType;
            $options['related_id'] = $this->substituteVariables($relatedId, $input);
        }

        $draftId = $emailService->queueDraft($email, $source, $options);

        return $this->standardOutput([
            'action' => 'queue',
            'success' => true,
            'draft_id' => $draftId,
            'to' => $to,
            'subject' => $subject,
            'priority' => $priority,
            'message' => "Email queued for approval (draft #{$draftId})",
        ]);
    }

    /**
     * Classify emails using AI
     */
    private function executeClassify(EmailService $emailService, array $input): array
    {
        $folder = $this->getConfigValue('folder', 'Inbox');
        $limit = (int) $this->getConfigValue('limit', 10);

        if (!$emailService->isAvailable()) {
            return $this->standardOutput(null, [], 'Thunderbird MCP not available - cannot access emails');
        }

        $result = $emailService->classifyRecentEmails($folder, $limit);

        return $this->standardOutput([
            'action' => 'classify',
            'folder' => $folder,
            'total' => $result['total'],
            'classified' => $result['classified'],
            'results' => $result['results'],
        ]);
    }

    /**
     * Search emails
     */
    private function executeSearch(EmailService $emailService, array $input): array
    {
        $query = $this->substituteVariables($this->getConfigValue('query', ''), $input);
        $folder = $this->getConfigValue('folder', null);

        if (empty($query)) {
            return $this->standardOutput(null, [], 'Search query is required');
        }

        if (!$emailService->isAvailable()) {
            return $this->standardOutput(null, [], 'Thunderbird MCP not available - cannot search emails');
        }

        $results = $emailService->searchEmails($query, $folder);

        return $this->standardOutput([
            'action' => 'search',
            'query' => $query,
            'folder' => $folder,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Get email service status
     */
    private function executeStatus(EmailService $emailService): array
    {
        $status = $emailService->getStatus();

        return $this->standardOutput([
            'action' => 'status',
            'available' => $status['available'],
            'thunderbird' => $status['thunderbird'],
            'draft_queue' => $status['draft_queue'],
        ]);
    }

    /**
     * Substitute variables in template strings
     *
     * Supports: {{variable}}, {{object.property}}, {{array.0}}
     */
    private function substituteVariables(string $template, array $input): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) use ($input) {
            $path = trim($matches[1]);
            $parts = explode('.', $path);

            $value = $input;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } elseif (is_object($value) && isset($value->$part)) {
                    $value = $value->$part;
                } else {
                    return $matches[0]; // Keep original if not found
                }
            }

            return is_scalar($value) ? (string) $value : json_encode($value);
        }, $template);
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'email',
            'name' => 'Email',
            'description' => 'Send emails, queue for approval, classify, or search',
            'category' => 'Communication',
            'icon' => '&#9993;',
            'config' => [
                'action' => [
                    'type' => 'select',
                    'label' => 'Action',
                    'description' => 'What to do with email',
                    'required' => true,
                    'default' => 'queue',
                    'options' => [
                        'queue' => 'Queue for Approval',
                        'send' => 'Send Immediately',
                        'classify' => 'Classify Recent Emails',
                        'search' => 'Search Emails',
                        'status' => 'Get Service Status',
                    ],
                ],
                'to' => [
                    'type' => 'text',
                    'label' => 'To',
                    'description' => 'Recipient email address (supports {{variables}})',
                    'required' => false,
                ],
                'subject' => [
                    'type' => 'text',
                    'label' => 'Subject',
                    'description' => 'Email subject (supports {{variables}})',
                    'required' => false,
                ],
                'body' => [
                    'type' => 'textarea',
                    'label' => 'Body',
                    'description' => 'Email body (supports {{variables}})',
                    'required' => false,
                ],
                'from' => [
                    'type' => 'text',
                    'label' => 'From',
                    'description' => 'Sender mailbox (leave empty for default)',
                    'required' => false,
                ],
                'priority' => [
                    'type' => 'select',
                    'label' => 'Priority',
                    'description' => 'Email priority for queue ordering',
                    'required' => false,
                    'default' => 'normal',
                    'options' => [
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ],
                ],
                'source' => [
                    'type' => 'select',
                    'label' => 'Source',
                    'description' => 'Source identifier for tracking',
                    'required' => false,
                    'default' => 'workflow',
                    'options' => [
                        'workflow' => 'Workflow',
                        'data_removal' => 'Data Removal',
                        'ai_reply' => 'AI Reply',
                    ],
                ],
                'folder' => [
                    'type' => 'text',
                    'label' => 'Folder',
                    'description' => 'Email folder for classify/search (default: Inbox)',
                    'required' => false,
                    'default' => 'Inbox',
                ],
                'query' => [
                    'type' => 'text',
                    'label' => 'Search Query',
                    'description' => 'Search query for search action (supports {{variables}})',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'number',
                    'label' => 'Limit',
                    'description' => 'Maximum emails to classify',
                    'required' => false,
                    'default' => 10,
                ],
                'related_type' => [
                    'type' => 'text',
                    'label' => 'Related Type',
                    'description' => 'Type of related entity (e.g., data_removal_requests)',
                    'required' => false,
                ],
                'related_id' => [
                    'type' => 'text',
                    'label' => 'Related ID',
                    'description' => 'ID of related entity (supports {{variables}})',
                    'required' => false,
                ],
            ],
            'outputs' => [
                'action' => 'The action performed',
                'success' => 'Whether the action succeeded',
                'draft_id' => 'Draft ID if queued',
                'to' => 'Recipient email',
                'subject' => 'Email subject',
                'message' => 'Status message',
                'results' => 'Search/classify results',
                'count' => 'Number of results',
            ],
        ];
    }
}
