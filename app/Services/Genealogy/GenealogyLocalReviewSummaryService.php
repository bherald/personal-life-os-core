<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use App\Services\OllamaBoundedPayloadService;

class GenealogyLocalReviewSummaryService
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly OllamaBoundedPayloadService $payloads
    ) {}

    public function cleanup(string $text, array $context = []): string
    {
        $payload = $this->payloads->buildPayload('review_summary_cleanup', $text, $context);
        if (($payload['chunk_count'] ?? 0) === 0) {
            return trim($text);
        }

        $response = $this->aiService->process(
            $this->buildPrompt($payload['chunks'][0]['text'], $context),
            [
                'model_role' => 'fast',
                'temperature' => 0.0,
                'max_tokens' => 220,
                'use_cache' => false,
                'dedup' => false,
                'factual_mode' => true,
                'task_type' => 'genealogy_local_review_summary_cleanup',
            ]
        );

        if (! ($response['success'] ?? true)) {
            return trim($text);
        }

        $cleaned = trim((string) ($response['response'] ?? ''));
        if ($cleaned === '') {
            return trim($text);
        }

        return $cleaned;
    }

    private function buildPrompt(string $text, array $context): string
    {
        $personName = (string) ($context['person_name'] ?? 'the person');

        return <<<PROMPT
Rewrite this genealogy review content into short human-readable review text for {$personName}.

Rules:
1. Use plain text only
2. Keep it concise
3. Remove raw JSON/tool payload wording
4. Preserve meaningful names, sources, dates, and "none generated" style outcomes
5. Prefer 2-4 short lines

Content:
{$text}
PROMPT;
    }
}
