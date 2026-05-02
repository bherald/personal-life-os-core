<?php

namespace App\Services;

use App\DTOs\TrustEnvelope;
use App\Services\AIService;
use App\Services\SenderProfileService;
use App\Services\TrustBoundaryFormatterService;
use App\Engine\MCPRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Email Classification Service
 *
 * AI-powered email categorization, priority detection, and tagging.
 * Integrates with SenderProfileService for reputation-based classification.
 * Uses direct SQL queries for maximum performance and security.
 */
class EmailClassificationService
{
    private AIService $aiService;
    private MCPRouter $mcpRouter;
    private ?SenderProfileService $senderProfileService = null;
    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    public function __construct(
        AIService $aiService,
        MCPRouter $mcpRouter,
        ?SenderProfileService $senderProfileService = null
    ) {
        $this->aiService = $aiService;
        $this->mcpRouter = $mcpRouter;
        $this->senderProfileService = $senderProfileService ?? new SenderProfileService();
    }

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    /**
     * Classify an email using AI with sender profile integration
     */
    public function classifyEmail(array $email): array
    {
        $from = $email['from'] ?? '';

        // Check sender profile for overrides
        $senderOverrides = $this->senderProfileService->getClassificationOverrides($from);

        // If sender has high spam score, skip AI and classify as spam
        if ($senderOverrides['spam_score'] >= 0.8) {
            Log::info('Email classified as spam based on sender profile', [
                'from' => $from,
                'spam_score' => $senderOverrides['spam_score'],
            ]);

            $classification = [
                'category' => 'spam',
                'priority' => 'low',
                'tags' => ['spam', 'blocked-sender'],
                'summary' => 'Blocked sender with high spam score',
                'confidence' => 0.95,
                'reasoning' => 'Sender profile spam_score >= 0.8',
                'source' => 'sender_profile',
            ];

            return $this->saveAndReturnClassification($email, $classification, $senderOverrides);
        }

        // If sender has category override and high trust, use it directly
        if ($senderOverrides['category'] && $senderOverrides['trust_score'] >= 0.85) {
            Log::info('Using sender profile category override', [
                'from' => $from,
                'category' => $senderOverrides['category'],
                'trust_score' => $senderOverrides['trust_score'],
            ]);

            $classification = [
                'category' => $senderOverrides['category'],
                'priority' => $senderOverrides['priority'] ?? 'normal',
                'tags' => $senderOverrides['typical_tags'],
                'summary' => 'Classified by trusted sender profile',
                'confidence' => $senderOverrides['trust_score'],
                'reasoning' => 'Trusted sender with category override',
                'source' => 'sender_profile',
            ];

            return $this->saveAndReturnClassification($email, $classification, $senderOverrides);
        }

        $prompt = $this->buildClassificationPrompt($email);

        try {
            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => 500,
                'system_prompt' => 'You are an email classification AI. Analyze emails and provide structured categorization.',
            ]);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'AI classification failed');
            }

            $classification = $this->parseClassificationResponse($result['response']);
            $classification['source'] = 'ai';

            // Apply sender profile overrides if they exist (lower trust threshold for hints)
            if ($senderOverrides['category'] && $senderOverrides['trust_score'] >= 0.6) {
                // Use sender category if AI confidence is low
                if ($classification['confidence'] < 0.7) {
                    $classification['category'] = $senderOverrides['category'];
                    $classification['reasoning'] .= ' (Adjusted by sender profile)';
                }
            }

            // Merge typical tags from sender profile
            if (!empty($senderOverrides['typical_tags'])) {
                $classification['tags'] = array_unique(array_merge(
                    $classification['tags'],
                    $senderOverrides['typical_tags']
                ));
            }

            // Record interaction with sender
            $this->senderProfileService->recordInteraction($from, [
                'category' => $classification['category'],
            ]);

            return $this->saveAndReturnClassification($email, $classification, $senderOverrides);

        } catch (\Exception $e) {
            Log::error('Email classification failed', [
                'error' => $e->getMessage(),
                'email' => $email,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'classification' => $this->getFallbackClassification(),
            ];
        }
    }

    /**
     * Build classification prompt
     */
    private function buildClassificationPrompt(array $email): string
    {
        $from = $email['from'] ?? 'unknown';
        $subject = $email['subject'] ?? '';
        $body = $email['body'] ?? '';
        $bodyPreview = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'email_body',
            contentType: 'text/plain',
            origin: (string) $from,
            payload: (string) $body,
            maxChars: 500,
        ));

        return <<<PROMPT
Analyze this email and provide classification:

From: {$from}
Subject: {$subject}
Body Preview:
{$bodyPreview}

Respond with JSON ONLY:
{
  "category": "work|personal|spam|newsletter|receipts|social|important|other",
  "priority": "low|normal|high|urgent",
  "tags": ["tag1", "tag2"],
  "summary": "Brief 1-sentence summary",
  "confidence": 0.95,
  "reasoning": "Why you classified it this way"
}

Categories:
- work: Work-related emails, meetings, projects
- personal: Personal correspondence
- spam: Unwanted/promotional emails
- newsletter: Newsletters, updates
- receipts: Order confirmations, receipts
- social: Social media notifications
- important: Requires immediate attention
- other: Doesn't fit other categories

Priority:
- urgent: Needs immediate action
- high: Important, act soon
- normal: Regular importance
- low: Can wait

Tags: 1-3 relevant keywords
PROMPT;
    }

    /**
     * Parse AI classification response
     */
    private function parseClassificationResponse(string $response): array
    {
        // Extract JSON from response
        if (preg_match('/\{[^}]+\}/', $response, $matches)) {
            $json = $matches[0];
            $data = json_decode($json, true);

            if ($data) {
                return [
                    'category' => $data['category'] ?? 'other',
                    'priority' => $data['priority'] ?? 'normal',
                    'tags' => $data['tags'] ?? [],
                    'summary' => $data['summary'] ?? '',
                    'confidence' => $data['confidence'] ?? 0.5,
                    'reasoning' => $data['reasoning'] ?? '',
                ];
            }
        }

        return $this->getFallbackClassification();
    }

    /**
     * Fallback classification when AI fails
     */
    private function getFallbackClassification(): array
    {
        return [
            'category' => 'other',
            'priority' => 'normal',
            'tags' => [],
            'summary' => 'Classification failed',
            'confidence' => 0.0,
            'reasoning' => 'AI classification unavailable',
            'source' => 'fallback',
        ];
    }

    /**
     * Save classification to database and return result
     *
     * @param array $email Email data
     * @param array $classification Classification result
     * @param array $senderOverrides Sender profile overrides
     * @return array Result with success, classification, record_id, sentiment
     */
    private function saveAndReturnClassification(array $email, array $classification, array $senderOverrides): array
    {
        // Save to database using raw SQL
        $metadata = array_merge($email, [
            'sender_profile_id' => $senderOverrides['profile_id'] ?? null,
            'sender_trust_score' => $senderOverrides['trust_score'] ?? null,
            'classification_source' => $classification['source'] ?? 'unknown',
        ]);

        $sql = "INSERT INTO email_classifications (message_id, folder, category, priority, tags, summary, confidence, metadata, classified_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        DB::insert($sql, [
            $email['message_id'] ?? 'unknown',
            $email['folder'] ?? 'INBOX',
            $classification['category'],
            $classification['priority'],
            json_encode($classification['tags']),
            $classification['summary'],
            $classification['confidence'],
            json_encode($metadata),
            now(),
            now(),
            now(),
        ]);
        $recordId = DB::getPdo()->lastInsertId();

        return [
            'success' => true,
            'classification' => $classification,
            'record_id' => $recordId,
            'sentiment' => null,
            'sender_profile' => $senderOverrides['profile_id'] ? [
                'id' => $senderOverrides['profile_id'],
                'trust_score' => $senderOverrides['trust_score'],
                'category_override' => $senderOverrides['category'],
            ] : null,
        ];
    }

    /**
     * Search and classify emails from Thunderbird
     */
    public function searchAndClassify(string $query, ?string $folder = null, int $limit = 10): array
    {
        try {
            // Search emails using MCP
            $searchResult = $this->mcpRouter->callTool('thunderbird', 'searchMessages', [
                'query' => $query,
                'folder' => $folder ?? 'INBOX',
            ]);

            if (!$searchResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Email search failed',
                ];
            }

            $emails = $searchResult['result'] ?? [];
            $classified = [];

            // Classify each email
            foreach (array_slice($emails, 0, $limit) as $email) {
                $result = $this->classifyEmail($email);
                $classified[] = [
                    'email' => $email,
                    'classification' => $result['classification'],
                ];
            }

            return [
                'success' => true,
                'count' => count($classified),
                'results' => $classified,
            ];

        } catch (\Exception $e) {
            Log::error('Search and classify failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get classification statistics using direct SQL
     */
    public function getStats(): array
    {
        $totalResult = DB::select('SELECT COUNT(*) as count FROM email_classifications');
        $total = $totalResult[0]->count ?? 0;

        $byCategory = DB::select('
            SELECT category, COUNT(*) as count
            FROM email_classifications
            GROUP BY category
            ORDER BY count DESC
        ');

        $byPriority = DB::select('
            SELECT priority, COUNT(*) as count
            FROM email_classifications
            GROUP BY priority
            ORDER BY count DESC
        ');

        $recent = DB::select('
            SELECT category, priority, summary, classified_at
            FROM email_classifications
            ORDER BY classified_at DESC
            LIMIT 10
        ');

        // Convert to associative arrays using native PHP
        $byCategoryArray = [];
        foreach ($byCategory as $item) {
            $byCategoryArray[$item->category] = $item->count;
        }

        $byPriorityArray = [];
        foreach ($byPriority as $item) {
            $byPriorityArray[$item->priority] = $item->count;
        }

        $recentArray = array_map(function($c) {
            return [
                'category' => $c->category,
                'priority' => $c->priority,
                'summary' => $c->summary,
                'date' => $c->classified_at,
            ];
        }, $recent);

        return [
            'total_classified' => $total,
            'by_category' => $byCategoryArray,
            'by_priority' => $byPriorityArray,
            'recent' => $recentArray,
        ];
    }

    /**
     * Generate AI reply draft for email using direct SQL
     */
    public function generateReplyDraft(string $messageId, array $email, array $options = []): int
    {
        $templateId = $options['template_id'] ?? null;
        $tone = $options['tone'] ?? 'professional';

        // Build reply generation prompt
        $prompt = $this->buildReplyPrompt($email, $templateId, $tone);

        try {
            // Generate reply using AI
            $result = $this->aiService->process($prompt, [
                'temperature' => 0.7,
                'max_tokens' => 1000,
            ]);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'AI reply generation failed');
            }

            // Parse response
            $replyData = $this->parseReplyResponse($result['response']);

            // Store draft using raw SQL
            $sql = "INSERT INTO email_reply_drafts (original_message_id, `to`, subject, body, ai_suggestions, status, ai_confidence, template_id, metadata, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            DB::insert($sql, [
                $messageId,
                $email['from'] ?? '',
                'Re: ' . ($email['subject'] ?? ''),
                $replyData['body'],
                $replyData['suggestions'] ?? null,
                'draft',
                $replyData['confidence'] ?? 0.8,
                $templateId,
                json_encode([
                    'tone' => $tone,
                    'generated_at' => now(),
                ]),
                now(),
                now(),
            ]);
            $draftId = DB::getPdo()->lastInsertId();

            Log::info("Generated reply draft", [
                'draft_id' => $draftId,
                'message_id' => $messageId,
            ]);

            return $draftId;

        } catch (Exception $e) {
            Log::error('Reply generation failed', [
                'error' => $e->getMessage(),
                'message_id' => $messageId,
            ]);
            throw $e;
        }
    }

    /**
     * Build reply generation prompt
     */
    private function buildReplyPrompt(array $email, ?int $templateId, string $tone): string
    {
        $from = $email['from'] ?? 'unknown';
        $subject = $email['subject'] ?? '';
        $body = $email['body'] ?? $email['snippet'] ?? '';
        $bodyPreview = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'email_body',
            contentType: 'text/plain',
            origin: (string) $from,
            payload: (string) $body,
            maxChars: 1500,
        ));

        $toneInstructions = [
            'professional' => 'Professional and courteous',
            'casual' => 'Friendly and conversational',
            'formal' => 'Formal and respectful',
            'friendly' => 'Warm and personable',
        ];

        $toneDesc = $toneInstructions[$tone] ?? 'Professional';

        $prompt = <<<PROMPT
Generate a reply email to this message.

ORIGINAL EMAIL:
From: $from
Subject: $subject
Body:
$bodyPreview

TONE: $toneDesc

REQUIREMENTS:
1. Address the sender's main points
2. Be clear and concise
3. Maintain appropriate tone
4. Include a professional closing
5. Use proper grammar and spelling

RESPONSE FORMAT:
Respond with valid JSON only (no markdown):
{
  "body": "The complete email reply text",
  "confidence": 0.85,
  "suggestions": ["Optional suggestion 1", "Optional suggestion 2"],
  "reasoning": "Brief explanation of approach"
}
PROMPT;

        // If template specified, add template content
        if ($templateId) {
            $template = DB::selectOne(
                'SELECT * FROM email_templates WHERE id = ? AND is_active = 1 LIMIT 1',
                [$templateId]
            );

            if ($template) {
                $prompt .= "\n\nUSE THIS TEMPLATE AS A STARTING POINT:\n";
                $prompt .= "Subject: {$template->subject}\n";
                $prompt .= "Body: {$template->body}\n";
            }
        }

        return $prompt;
    }

    /**
     * Parse AI reply response
     */
    private function parseReplyResponse(string $response): array
    {
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $json = $matches[0];
            $data = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'body' => $data['body'] ?? $response,
                    'confidence' => (float) ($data['confidence'] ?? 0.7),
                    'suggestions' => json_encode($data['suggestions'] ?? []),
                    'reasoning' => $data['reasoning'] ?? '',
                ];
            }
        }

        // Fallback: use response as-is
        return [
            'body' => $response,
            'confidence' => 0.6,
            'suggestions' => json_encode([]),
            'reasoning' => 'Direct response',
        ];
    }

    /**
     * Apply email rules using direct SQL
     */
    public function applyRules(string $messageId, array $email, array $classification): void
    {
        // email_rules table dropped (D1 decision). Stub — no rules applied.
    }

    /**
     * Check if rule matches email
     */
    private function ruleMatches(object $rule, array $email, array $classification): bool
    {
        $field = null;

        switch ($rule->rule_type) {
            case 'from':
                $field = $email['from'] ?? '';
                break;
            case 'to':
                $field = $email['to'] ?? '';
                break;
            case 'subject':
                $field = $email['subject'] ?? '';
                break;
            case 'body':
                $field = $email['body'] ?? $email['snippet'] ?? '';
                break;
            case 'sender_domain':
                $from = $email['from'] ?? '';
                preg_match('/@(.+)>?$/', $from, $matches);
                $field = $matches[1] ?? '';
                break;
            case 'category':
                $field = $classification['category'];
                break;
            case 'priority':
                $field = $classification['priority'];
                break;
            default:
                return false;
        }

        return $this->matchesOperator($field, $rule->operator, $rule->value);
    }

    /**
     * Check if field matches operator and value
     */
    private function matchesOperator(string $field, string $operator, string $value): bool
    {
        $field = strtolower($field);
        $value = strtolower($value);

        switch ($operator) {
            case 'contains':
                return str_contains($field, $value);
            case 'equals':
                return $field === $value;
            case 'starts_with':
                return str_starts_with($field, $value);
            case 'ends_with':
                return str_ends_with($field, $value);
            case 'regex':
                return @preg_match("/$value/i", $field) === 1;
            default:
                return false;
        }
    }

    /**
     * Execute rule action
     */
    private function executeRuleAction(object $rule, string $messageId, array $email): void
    {
        $actionParams = json_decode($rule->action_params, true) ?? [];

        switch ($rule->action) {
            case 'classify':
                $category = $actionParams['category'] ?? 'other';
                DB::update(
                    'UPDATE email_classifications SET category = ?, updated_at = ? WHERE message_id = ?',
                    [$category, now(), $messageId]
                );
                Log::info("Rule action: Updated category to $category", ['message_id' => $messageId]);
                break;

            case 'tag':
                $tags = $actionParams['tags'] ?? [];
                $existing = DB::selectOne(
                    'SELECT tags FROM email_classifications WHERE message_id = ?',
                    [$messageId]
                );
                $existingTags = json_decode($existing->tags ?? '[]', true);
                $newTags = array_unique(array_merge($existingTags, $tags));
                DB::update(
                    'UPDATE email_classifications SET tags = ?, updated_at = ? WHERE message_id = ?',
                    [json_encode($newTags), now(), $messageId]
                );
                Log::info("Rule action: Added tags", ['message_id' => $messageId, 'tags' => $tags]);
                break;

            case 'priority':
                $priority = $actionParams['priority'] ?? 'normal';
                DB::update(
                    'UPDATE email_classifications SET priority = ?, updated_at = ? WHERE message_id = ?',
                    [$priority, now(), $messageId]
                );
                Log::info("Rule action: Updated priority to $priority", ['message_id' => $messageId]);
                break;

            case 'generate_reply':
                $this->generateReplyDraft($messageId, $email, $actionParams);
                break;
        }
    }
}
