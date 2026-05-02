<?php

namespace App\Services\Genealogy;

class GenealogyStagedPacketPreviewService
{
    private const PRIMARY_FACT_ROLES = [
        'principal',
        'subject',
        'self',
        'head',
        'decedent',
        'bride',
        'groom',
        'infant',
    ];

    private const PERSON_SCALAR_FACT_FIELDS = [
        'given_name',
        'surname',
        'suffix',
        'nickname',
        'sex',
        'birth_year',
        'death_year',
    ];

    public function __construct(
        private readonly GenealogyPacketIntakeOrchestratorService $packetIntake,
        private readonly GenealogyIntakePacketRegistryService $packetRegistry,
        private readonly GenealogyIntakePageTextExtractionService $pageTextExtraction,
        private readonly GenealogyPacketTextSignalService $textSignals = new GenealogyPacketTextSignalService,
        private readonly ?GenealogyLocalDocumentWorkerService $localWorker = null
    ) {}

    public function previewPacket(array $packet, array $context = []): array
    {
        $documents = array_values((array) ($packet['documents'] ?? []));
        $registration = $this->packetRegistry->planPacketRegistration($packet, $context);
        if ($documents === []) {
            return [
                'packet_label' => $packet['packet_label'] ?? 'Untitled packet',
                'packet_key' => $packet['packet_key'] ?? null,
                'document_count' => 0,
                'page_count' => 0,
                'registration' => $registration,
                'media_summary' => $this->buildMediaSummary($registration),
                'preview' => [
                    'status' => 'empty_packet',
                    'proposal_ready' => false,
                    'packet_summary' => '',
                    'page_anchors' => [],
                    'person_candidates' => [],
                    'questions' => [],
                ],
            ];
        }

        $registeredPreview = $this->buildRegisteredPages($registration);
        $preview = $this->packetIntake->orchestratePacket($registeredPreview['pages'], [
            'title' => $packet['packet_label'] ?? 'Genealogy packet',
            'packet_type' => $packet['packet_type'] ?? 'packet',
        ] + $context);

        if ($registeredPreview['structured_facts'] !== []) {
            $preview['structured_facts'] = $registeredPreview['structured_facts'];
        }

        return [
            'packet_label' => $packet['packet_label'] ?? 'Untitled packet',
            'packet_key' => $packet['packet_key'] ?? null,
            'document_count' => count($documents),
            'page_count' => count($registeredPreview['pages']),
            'registration' => $registration,
            'media_summary' => $this->buildMediaSummary($registration),
            'preview' => $preview,
        ];
    }

    public function selectPacket(array $stagedScope, ?string $packetLabel = null): ?array
    {
        $packets = array_values((array) ($stagedScope['packets'] ?? []));
        if ($packets === []) {
            return null;
        }

        if ($packetLabel === null || trim($packetLabel) === '') {
            return $packets[0];
        }

        $needle = mb_strtolower(trim($packetLabel));
        foreach ($packets as $packet) {
            $label = mb_strtolower((string) ($packet['packet_label'] ?? ''));
            if ($label === $needle) {
                return $packet;
            }
        }

        return null;
    }

    private function buildRegisteredPages(array $registration): array
    {
        $pages = [];
        $structuredFacts = [];
        $packetPageNumber = 1;

        foreach (array_values((array) ($registration['documents'] ?? [])) as $document) {
            $document = (array) $document;
            $documentPages = array_values((array) ($document['pages'] ?? []));
            $extracted = $this->pageTextExtraction->extractDocumentText($document);

            $rawText = trim((string) ($extracted['raw_text'] ?? ''));
            $structured = $rawText !== ''
                ? $this->extractStructuredFacts($document, $rawText)
                : [];
            $parsedPersons = $this->buildParsedPersons($rawText, $structured);
            $structuredFacts = array_merge(
                $structuredFacts,
                $this->flattenStructuredFacts($structured, $documentPages)
            );

            foreach ($documentPages as $page) {
                $page = (array) $page;
                $pages[] = [
                    'page_number' => $packetPageNumber++,
                    'summary' => $this->buildRegisteredPageSummary($document, $page, $extracted),
                    'persons' => $parsedPersons,
                ];
            }
        }

        return [
            'pages' => $pages,
            'structured_facts' => $this->deduplicateStructuredFacts($structuredFacts),
        ];
    }

    private function buildRegisteredPageSummary(array $document, array $page, array $extracted): string
    {
        $type = (string) ($document['document_type'] ?? $page['document_type'] ?? 'document');
        $sourceName = (string) ($document['source_name'] ?? $page['source_name'] ?? 'document');
        $sourcePath = (string) ($document['source_path'] ?? $page['source_path'] ?? '');
        $copyPath = (string) ($document['reference_copy_path'] ?? $page['reference_copy_path'] ?? '');
        $duplicateScope = (string) ($document['duplicate_scope'] ?? $page['duplicate_scope'] ?? 'unknown');
        $copyStatus = (string) (($document['copy_plan']['status'] ?? null) ?? ($page['copy_status'] ?? 'ready'));
        $anchorLabel = (string) ($page['anchor_label'] ?? $sourceName);
        $pageNumber = (int) ($page['page_number'] ?? 1);
        $pageCount = (int) ($document['page_count'] ?? 1);
        $textSummary = trim((string) ($extracted['summary_base'] ?? ''));
        $sourceMethod = trim((string) ($extracted['source_method'] ?? ''));

        if ($textSummary !== '') {
            $lead = $pageCount > 1
                ? sprintf(
                    'Document-level extracted text for %s; anchor %s; source page %d of %d; method %s; excerpt %s',
                    $sourceName,
                    $anchorLabel,
                    $pageNumber,
                    $pageCount,
                    $sourceMethod !== '' ? $sourceMethod : 'unknown',
                    $textSummary
                )
                : sprintf(
                    'Extracted text for %s; anchor %s; method %s; excerpt %s',
                    $sourceName,
                    $anchorLabel,
                    $sourceMethod !== '' ? $sourceMethod : 'unknown',
                    $textSummary
                );

            return trim($lead);
        }

        $segments = [
            sprintf('%s evidence %s', ucfirst($type), $sourceName),
            $pageCount > 1 ? sprintf('source page %d of %d', $pageNumber, $pageCount) : 'single-page source',
            sprintf('anchor %s', $anchorLabel),
            sprintf('source path %s', $sourcePath !== '' ? $sourcePath : 'missing'),
            sprintf('FT copy %s', $copyPath !== '' ? $copyPath : 'pending'),
            sprintf('duplicate scope %s', $duplicateScope),
            sprintf('copy plan %s', $copyStatus),
            sprintf('extraction %s', $sourceMethod !== '' ? $sourceMethod : 'placeholder'),
        ];

        return implode('; ', $segments);
    }

    private function buildMediaSummary(array $registration): array
    {
        $typeCounts = [];
        $pageCount = 0;

        foreach (array_values((array) ($registration['documents'] ?? [])) as $document) {
            $type = (string) ($document['document_type'] ?? 'document');
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            $pageCount += (int) ($document['page_count'] ?? 0);
        }

        ksort($typeCounts);

        return [
            'document_type_counts' => $typeCounts,
            'document_types' => array_keys($typeCounts),
            'is_mixed_media' => count($typeCounts) > 1,
            'page_count' => $pageCount,
        ];
    }

    private function extractStructuredFacts(array $document, string $rawText): array
    {
        $result = $this->localWorker()->extractStructuredFactsFromText($rawText, [
            'media_type' => (string) ($document['document_type'] ?? 'document'),
            'title' => (string) ($document['source_name'] ?? $document['name'] ?? 'Genealogy document'),
        ]);

        return is_array($result) ? $result : [];
    }

    private function buildParsedPersons(string $rawText, array $structured): array
    {
        $structuredPersons = array_values(array_filter(array_map(
            fn ($person): ?array => $this->normalizeStructuredPerson((array) $person),
            (array) ($structured['persons'] ?? [])
        )));

        if ($structuredPersons !== []) {
            return $structuredPersons;
        }

        return $rawText !== ''
            ? $this->textSignals->toParsedPersons($this->textSignals->extractSignals($rawText))
            : [];
    }

    private function normalizeStructuredPerson(array $person): ?array
    {
        $name = trim((string) ($person['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $facts = array_values(array_filter(array_map(
            fn ($fact): ?array => $this->normalizeFact((array) $fact),
            array_merge(
                $this->scalarFactsFromPerson($person),
                array_values((array) ($person['facts'] ?? []))
            )
        )));

        $relationships = array_values(array_filter(array_map(function ($relationship): ?array {
            $relationship = (array) $relationship;
            $type = trim((string) ($relationship['type'] ?? ''));
            $relatedName = trim((string) ($relationship['name'] ?? ''));
            if ($type === '' || $relatedName === '') {
                return null;
            }

            return array_filter([
                'type' => $type,
                'name' => $relatedName,
                'birth_year' => isset($relationship['birth_year']) ? (string) $relationship['birth_year'] : null,
            ], static fn ($value) => $value !== null && $value !== '');
        }, (array) ($person['relationships'] ?? []))));

        return [
            'name' => $name,
            'role' => trim((string) ($person['role'] ?? '')),
            'facts' => $facts,
            'relationships' => $relationships,
        ];
    }

    private function scalarFactsFromPerson(array $person): array
    {
        $facts = [];

        foreach (self::PERSON_SCALAR_FACT_FIELDS as $field) {
            $value = trim((string) ($person[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $fact = [
                'field' => $field,
                'value' => $value,
            ];

            if (isset($person['confidence']) && is_numeric($person['confidence'])) {
                $fact['confidence'] = (float) $person['confidence'];
            }

            $facts[] = $fact;
        }

        return $facts;
    }

    private function flattenStructuredFacts(array $structured, array $documentPages): array
    {
        $anchors = array_values(array_filter(array_map(
            static fn ($page): string => trim((string) (($page['anchor_label'] ?? null) ?? ($page['source_name'] ?? ''))),
            $documentPages
        )));

        $facts = [];
        foreach ($this->selectPrimaryFactPersons((array) ($structured['persons'] ?? [])) as $person) {
            foreach (array_merge(
                $this->scalarFactsFromPerson((array) $person),
                array_values((array) ($person['facts'] ?? []))
            ) as $fact) {
                $normalized = $this->normalizeFact((array) $fact);
                if ($normalized === null) {
                    continue;
                }

                if ($anchors !== []) {
                    $normalized['page_anchors'] = $anchors;
                }

                $facts[] = $normalized;
            }
        }

        return $facts;
    }

    private function selectPrimaryFactPersons(array $persons): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn ($person): array => (array) $person,
            $persons
        ), static fn (array $person): bool => trim((string) ($person['name'] ?? '')) !== ''));

        if (count($normalized) <= 1) {
            return $normalized;
        }

        $preferred = array_values(array_filter($normalized, function (array $person): bool {
            $role = strtolower(trim((string) ($person['role'] ?? '')));

            return in_array($role, self::PRIMARY_FACT_ROLES, true);
        }));

        return $preferred !== [] ? $preferred : [];
    }

    private function normalizeFact(array $fact): ?array
    {
        $field = trim((string) ($fact['field'] ?? ''));
        $value = trim((string) ($fact['value'] ?? ''));
        if ($field === '' || $value === '') {
            return null;
        }

        $normalized = [
            'field' => $field,
            'value' => $value,
        ];

        if (isset($fact['confidence']) && is_numeric($fact['confidence'])) {
            $normalized['confidence'] = (float) $fact['confidence'];
        }

        return $normalized;
    }

    private function deduplicateStructuredFacts(array $facts): array
    {
        $deduplicated = [];

        foreach ($facts as $fact) {
            $fact = (array) $fact;
            $anchors = array_values(array_filter(array_map(
                static fn ($anchor): string => trim((string) $anchor),
                (array) ($fact['page_anchors'] ?? [])
            )));

            $key = implode('|', [
                trim((string) ($fact['field'] ?? '')),
                trim((string) ($fact['value'] ?? '')),
                implode(',', $anchors),
            ]);

            if ($key === '||' || isset($deduplicated[$key])) {
                continue;
            }

            if ($anchors !== []) {
                $fact['page_anchors'] = $anchors;
            }

            $deduplicated[$key] = $fact;
        }

        return array_values($deduplicated);
    }

    private function localWorker(): GenealogyLocalDocumentWorkerService
    {
        return $this->localWorker ?? app(GenealogyLocalDocumentWorkerService::class);
    }
}
