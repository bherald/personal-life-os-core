<?php

namespace App\Services\Genealogy;

use App\Services\AIService;

class GenealogyLocalSourceQualityService
{
    public function __construct(
        private readonly AIService $aiService
    ) {}

    public function assess(array $extraction, array $media = []): array
    {
        $preflight = $this->preflightAssessment($extraction);
        if ($preflight !== null) {
            return $preflight;
        }

        $response = $this->aiService->process(
            $this->buildPrompt($extraction, $media),
            [
                'model_role' => 'standard',
                'temperature' => 0.0,
                'max_tokens' => 400,
                'use_cache' => false,
                'dedup' => false,
                'factual_mode' => true,
                'task_type' => 'genealogy_local_source_quality',
            ]
        );

        if (! ($response['success'] ?? true)) {
            return [
                'label' => 'weak',
                'confidence' => 'low',
                'rationale' => $response['error'] ?? 'quality_assessment_failed',
                'allow_proposals' => false,
            ];
        }

        return $this->parseResponse((string) ($response['response'] ?? ''));
    }

    private function preflightAssessment(array $extraction): ?array
    {
        $textQuality = (array) ($extraction['_text_quality'] ?? []);
        if ($textQuality !== [] && ! ($textQuality['allow_fact_extraction'] ?? false)) {
            return [
                'label' => ($textQuality['label'] ?? 'noisy') === 'manual_review' ? 'weak' : 'noisy',
                'confidence' => 'high',
                'rationale' => 'text_quality_gate:'.implode(',', (array) ($textQuality['reasons'] ?? [])),
                'allow_proposals' => false,
            ];
        }

        $notes = strtolower((string) ($extraction['notes_remainder'] ?? ''));
        if (str_contains($notes, 'field_level_review_needed')) {
            return [
                'label' => 'weak',
                'confidence' => 'high',
                'rationale' => 'field_level_review_needed',
                'allow_proposals' => false,
            ];
        }

        return null;
    }

    private function buildPrompt(array $extraction, array $media): string
    {
        $mediaType = (string) ($media['media_type'] ?? ($extraction['document_type'] ?? 'document'));
        $title = (string) ($media['title'] ?? 'Genealogy document');
        $personsJson = json_encode($extraction['persons'] ?? [], JSON_UNESCAPED_SLASHES);
        $notes = (string) ($extraction['notes_remainder'] ?? '');

        return <<<PROMPT
Assess whether this extracted genealogy evidence is strong enough for proposal generation.

Return ONLY valid JSON:
{
  "label": "usable",
  "confidence": "high",
  "rationale": "short reason"
}

Allowed labels: usable, weak, noisy, collateral_only

Guidance:
- usable: concrete person/event evidence with meaningful genealogical value
- weak: fragmentary or too uncertain for proposals
- noisy: mostly junk, OCR garbage, or non-genealogical content
- collateral_only: useful context about relatives or cluster, but not strong enough for direct person proposals

Document:
- type: {$mediaType}
- title: {$title}

Extracted persons:
{$personsJson}

Notes:
{$notes}

Prefer weak or collateral_only over false usable.
PROMPT;
    }

    private function parseResponse(string $response): array
    {
        $decoded = json_decode(trim($response), true);
        if (! is_array($decoded) && preg_match('/\{[\s\S]*\}/', $response, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded)) {
            return [
                'label' => 'weak',
                'confidence' => 'low',
                'rationale' => 'invalid_json',
                'allow_proposals' => false,
            ];
        }

        $label = strtolower((string) ($decoded['label'] ?? 'weak'));

        return [
            'label' => $label,
            'confidence' => strtolower((string) ($decoded['confidence'] ?? 'low')),
            'rationale' => (string) ($decoded['rationale'] ?? ''),
            'allow_proposals' => $label === 'usable',
        ];
    }
}
