<?php

namespace App\Services\Genealogy;

use App\DTOs\TrustEnvelope;
use App\Services\AIService;
use App\Services\OllamaBoundedPayloadService;
use App\Services\TrustBoundaryFormatterService;
use Illuminate\Support\Facades\Log;

class GenealogyLocalDocumentWorkerService
{
    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    public function __construct(
        private readonly AIService $aiService,
        private readonly OllamaBoundedPayloadService $payloads
    ) {}

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    public function extractStructuredFactsFromText(string $text, array $context = []): array
    {
        $payload = $this->payloads->buildPayload('fact_extraction', $text, $context);
        if (($payload['chunk_count'] ?? 0) === 0) {
            return [];
        }

        $chunkResults = [];

        foreach ($payload['chunks'] as $chunk) {
            $response = $this->aiService->process(
                $this->buildExtractionPrompt($chunk['text'], $context),
                [
                    'model_role' => 'fast',
                    'temperature' => 0.0,
                    'max_tokens' => 1200,
                    'use_cache' => false,
                    'dedup' => false,
                    'factual_mode' => true,
                    'task_type' => 'genealogy_local_fact_extraction',
                ]
            );

            if (! ($response['success'] ?? true)) {
                Log::warning('GenealogyLocalDocumentWorkerService: chunk extraction failed', [
                    'context' => $context,
                    'error' => $response['error'] ?? 'unknown',
                ]);
                continue;
            }

            $parsed = $this->parseExtractionResponse((string) ($response['response'] ?? ''));
            if ($parsed === []) {
                continue;
            }

            $chunkResults[] = $parsed;
        }

        if ($chunkResults === []) {
            return [];
        }

        return $this->mergeChunkResults($chunkResults, $context);
    }

    private function buildExtractionPrompt(string $chunkText, array $context): string
    {
        $documentType = (string) ($context['media_type'] ?? 'document');
        $title = (string) ($context['title'] ?? 'Untitled genealogy document');
        $formattedChunkText = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'genealogy_document',
            contentType: 'text/plain',
            origin: $title,
            payload: $chunkText,
        ));

        return <<<PROMPT
You are a professional genealogist extracting structured facts from a {$documentType} transcript.

Document title: {$title}

Transcript chunk:
{$formattedChunkText}

Return ONLY valid JSON in this exact format:
{
  "confidence": 0.85,
  "document_type": "{$documentType}",
  "document_year": 1920,
  "persons": [
    {
      "name": "John William Doe",
      "given_name": "John William",
      "surname": "Doe",
      "birth_year": 1875,
      "role": "head",
      "confidence": 0.90,
      "facts": [
        {"field": "birth_place", "value": "Pennsylvania", "confidence": 0.85},
        {"field": "occupation", "value": "farmer", "confidence": 0.90}
      ],
      "relationships": [
        {"type": "spouse", "name": "Mary Doe"},
        {"type": "child", "name": "Robert Doe", "birth_year": 1902}
      ]
    }
  ],
  "notes_remainder": "Remaining genealogical context from this chunk."
}

Rules:
1. facts.field must be one of: birth_date, birth_year, birth_place, death_date, death_year, death_place, burial_place, marriage_date, marriage_place, occupation, residence, immigration_date, immigration_place, military_branch, military_rank, enlistment_date, discharge_date, nationality, religion, other
2. If unclear, omit rather than guess
3. Return JSON only
PROMPT;
    }

    private function parseExtractionResponse(string $response): array
    {
        $json = preg_replace('/^```(?:json)?\s*/m', '', trim($response));
        $json = preg_replace('/\s*```$/m', '', $json);

        $data = json_decode(trim($json), true);
        if (! is_array($data)) {
            if (preg_match('/\{[\s\S]*\}/', $response, $matches) === 1) {
                $data = json_decode($matches[0], true);
            }
        }

        if (! is_array($data)) {
            return [];
        }

        return array_merge([
            'confidence' => 0.0,
            'document_type' => 'document',
            'document_year' => null,
            'persons' => [],
            'notes_remainder' => '',
        ], $data);
    }

    private function mergeChunkResults(array $chunkResults, array $context): array
    {
        $persons = [];
        $notes = [];
        $confidences = [];
        $documentYear = null;
        $documentType = (string) ($context['media_type'] ?? ($chunkResults[0]['document_type'] ?? 'document'));

        foreach ($chunkResults as $result) {
            $confidences[] = (float) ($result['confidence'] ?? 0);
            $documentYear ??= $result['document_year'] ?? null;

            foreach (($result['persons'] ?? []) as $person) {
                $persons[] = $person;
            }

            if (! empty($result['notes_remainder'])) {
                $notes[] = trim((string) $result['notes_remainder']);
            }
        }

        return [
            'confidence' => $confidences === [] ? 0.0 : round(array_sum($confidences) / count($confidences), 2),
            'document_type' => $documentType,
            'document_year' => $documentYear,
            'persons' => $persons,
            'notes_remainder' => implode(' ', array_values(array_unique(array_filter($notes)))),
        ];
    }
}
