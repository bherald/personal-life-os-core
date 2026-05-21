<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use App\Services\OllamaBoundedPayloadService;

class GenealogyPacketSynthesisService
{
    private ?GenealogyLessonPromptContextService $lessonPromptContext = null;

    public function __construct(
        private readonly AIService $aiService,
        private readonly OllamaBoundedPayloadService $payloads
    ) {}

    private function lessonContext(): GenealogyLessonPromptContextService
    {
        return $this->lessonPromptContext ??= app(GenealogyLessonPromptContextService::class);
    }

    public function synthesizeFromPageSummaries(array $pages, array $context = []): array
    {
        $text = $this->buildPacketText($pages);
        $payload = $this->payloads->buildPayload('multipage_synthesis', $text, $context);

        if (($payload['chunk_count'] ?? 0) === 0) {
            return [];
        }

        $response = $this->aiService->process(
            $this->buildPrompt($payload['chunks'], $context),
            [
                'model_role' => 'quality',
                'temperature' => 0.0,
                'max_tokens' => 900,
                'use_cache' => false,
                'dedup' => false,
                'factual_mode' => true,
                'task_type' => 'genealogy_packet_synthesis',
            ]
        );

        if (! ($response['success'] ?? true)) {
            return [];
        }

        return $this->parseResponse((string) ($response['response'] ?? ''));
    }

    private function buildPacketText(array $pages): string
    {
        $lines = [];
        foreach ($pages as $page) {
            $pageNumber = (int) ($page['page_number'] ?? 0);
            $summary = trim((string) ($page['summary'] ?? ''));
            if ($pageNumber <= 0 || $summary === '') {
                continue;
            }

            $lines[] = "Page {$pageNumber}: {$summary}";
        }

        return implode("\f", $lines);
    }

    private function buildPrompt(array $chunks, array $context): string
    {
        $title = (string) ($context['title'] ?? 'Genealogy packet');
        $chunkText = implode("\n\n", array_map(
            static fn (array $chunk): string => "Pages {$chunk['page_start']}-{$chunk['page_end']}:\n".$chunk['text'],
            $chunks
        ));
        $lessonContext = $this->lessonContext()->build($context, $this->lessonSearchTerms($chunks, $context), 4);

        return <<<PROMPT
Synthesize these page-level genealogy packet summaries into one bounded packet summary.

Packet title: {$title}
{$lessonContext}

Return ONLY valid JSON:
{
  "packet_summary": "short summary",
  "page_anchors": [
    "page 1 ...",
    "page 2 ..."
  ],
  "unresolved_questions": [
    "short question"
  ]
}

Rules:
1. Preserve page references explicitly
2. Keep unresolved ambiguity as questions instead of forcing conclusions
3. Plain factual wording only

Packet content:
{$chunkText}
PROMPT;
    }

    /**
     * @return list<string>
     */
    private function lessonSearchTerms(array $chunks, array $context): array
    {
        $terms = [
            $context['title'] ?? null,
            $context['media_type'] ?? null,
            'packet synthesis',
            'multi-page packet',
            'source packet',
            'unresolved questions',
            'page anchors',
            'proposal readiness',
        ];

        foreach (array_slice((array) ($context['ft_candidates'] ?? []), 0, 5) as $candidate) {
            $candidate = (array) $candidate;
            $terms[] = $candidate['display_name'] ?? null;
            $terms[] = $candidate['name'] ?? null;
        }

        foreach (array_slice($chunks, 0, 3) as $chunk) {
            foreach (preg_split('/[^A-Za-z0-9]+/', (string) ($chunk['text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (strlen($word) >= 5) {
                    $terms[] = $word;
                }
            }
        }

        return array_values(array_filter(
            array_map(static fn (mixed $term): string => trim((string) $term), $terms),
            static fn (string $term): bool => $term !== ''
        ));
    }

    private function parseResponse(string $response): array
    {
        $decoded = json_decode(trim($response), true);
        if (! is_array($decoded) && preg_match('/\{[\s\S]*\}/', $response, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_merge([
            'packet_summary' => '',
            'page_anchors' => [],
            'unresolved_questions' => [],
        ], $decoded);
    }
}
