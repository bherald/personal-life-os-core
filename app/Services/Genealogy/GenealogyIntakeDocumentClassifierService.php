<?php

namespace App\Services\Genealogy;

class GenealogyIntakeDocumentClassifierService
{
    public const FT_SELF_CONTAINED = 'ft_self_contained';

    public const EXTERNAL_UNIQUE_NEEDS_FT_COPY = 'external_unique_needs_ft_copy';

    public const EXTERNAL_COPY_PRESENT = 'external_copy_present';

    public const ALREADY_INGESTED_REUSE_CANDIDATE = 'already_ingested_reuse_candidate';

    public const CONFLICT_NEEDS_REVIEW = 'conflict_needs_review';

    public const MISSING_SOURCE_PATH = 'missing_source_path';

    /**
     * @return self::FT_SELF_CONTAINED|self::EXTERNAL_UNIQUE_NEEDS_FT_COPY|self::EXTERNAL_COPY_PRESENT|self::ALREADY_INGESTED_REUSE_CANDIDATE|self::CONFLICT_NEEDS_REVIEW|self::MISSING_SOURCE_PATH
     */
    public function classify(array $document): string
    {
        return (string) ($this->classifyDetailed($document)['primary_classification'] ?? self::EXTERNAL_UNIQUE_NEEDS_FT_COPY);
    }

    /**
     * @return array{
     *   primary_classification: string,
     *   is_ft_local_source: bool,
     *   is_ft_intake_copy: bool,
     *   is_already_ingested: bool,
     *   is_reuse_candidate: bool,
     *   copy_requirement: string,
     *   self_contained_reason: ?string,
     *   reuse_reason: ?string
     * }
     */
    public function classifyDetailed(array $document): array
    {
        $copyPlan = (array) ($document['copy_plan'] ?? []);
        $copyStatus = (string) ($copyPlan['status'] ?? '');
        $duplicateScope = (string) ($document['duplicate_scope'] ?? '');
        $alreadyIngested = (bool) ($document['already_ingested'] ?? false);
        $isFtLocal = $duplicateScope === 'ft_self_contained';
        $isFtIntakeCopy = $isFtLocal || str_contains((string) ($document['reference_copy_path'] ?? ''), '/FT/');

        $primaryClassification = match (true) {
            $copyStatus === 'conflict' => self::CONFLICT_NEEDS_REVIEW,
            $copyStatus === 'missing_source_path' => self::MISSING_SOURCE_PATH,
            $duplicateScope === 'ft_self_contained' => self::FT_SELF_CONTAINED,
            $alreadyIngested => self::ALREADY_INGESTED_REUSE_CANDIDATE,
            $copyStatus === 'already_in_place' => self::EXTERNAL_COPY_PRESENT,
            default => self::EXTERNAL_UNIQUE_NEEDS_FT_COPY,
        };

        $copyRequirement = match ($primaryClassification) {
            self::FT_SELF_CONTAINED, self::EXTERNAL_COPY_PRESENT, self::ALREADY_INGESTED_REUSE_CANDIDATE => 'none',
            self::CONFLICT_NEEDS_REVIEW, self::MISSING_SOURCE_PATH => 'blocked',
            default => 'required',
        };

        return [
            'primary_classification' => $primaryClassification,
            'is_ft_local_source' => $isFtLocal,
            'is_ft_intake_copy' => $isFtIntakeCopy,
            'is_already_ingested' => $alreadyIngested,
            'is_reuse_candidate' => $alreadyIngested || $copyStatus === 'already_in_place',
            'copy_requirement' => $copyRequirement,
            'self_contained_reason' => $isFtLocal
                ? 'Source already lives under FT and should remain self-contained evidence.'
                : null,
            'reuse_reason' => $alreadyIngested
                ? 'A matching genealogy media record already exists for this document.'
                : ($copyStatus === 'already_in_place'
                    ? 'A matching FT reference copy is already present for this document.'
                    : null),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function summarizeDocuments(array $documents): array
    {
        $summary = [
            self::FT_SELF_CONTAINED => 0,
            self::EXTERNAL_UNIQUE_NEEDS_FT_COPY => 0,
            self::EXTERNAL_COPY_PRESENT => 0,
            self::ALREADY_INGESTED_REUSE_CANDIDATE => 0,
            self::CONFLICT_NEEDS_REVIEW => 0,
            self::MISSING_SOURCE_PATH => 0,
        ];

        foreach ($documents as $document) {
            $classification = $this->classify((array) $document);
            $summary[$classification] = ($summary[$classification] ?? 0) + 1;
        }

        return $summary;
    }
}
