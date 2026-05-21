<?php

namespace App\Services\Genealogy\SourceAudit;

use App\Services\Genealogy\GenealogyReviewPacketAdapterService;
use App\Services\Genealogy\GenealogyReviewPacketMaterializationService;
use App\Services\Genealogy\GenealogyTreeRootResolver;
use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use RuntimeException;
use ZipArchive;

class SourceAuditWorkbookService
{
    public const SCHEMA_VERSION = 'source_audit_workbook.v1';

    private const FORMATS = ['manifest', 'csv_zip', 'package', 'docx', 'odt'];

    private const PRIVACY_MODES = ['private_local', 'public_redacted', 'audit_ids_only'];

    private const SHARD_MODES = ['none', 'surname_initial'];

    private const BRANCH_MODES = ['descendants', 'ancestors', 'family'];

    private const MAX_PRELABEL_RESERVATIONS = 500;

    private const CSV_FILES = [
        'persons_all.csv',
        'families_all.csv',
        'sources_inventory.csv',
        'media_inventory.csv',
        'audit_claims.csv',
        'prelabel_queue.csv',
        'review_notes.csv',
        'issue_index.csv',
        'intake_log.csv',
    ];

    public function __construct(private readonly GenealogyTreeRootResolver $rootResolver) {}

    public function generate(
        int $treeId,
        string $format = 'manifest',
        string $privacyMode = 'private_local',
        bool $dryRun = true,
        bool $confirm = false,
        string $actor = 'source-audit-workbook',
        ?string $layoutProfile = null,
        bool $includeSources = true,
        bool $includeMedia = true,
        bool $includeIssues = true,
        int $prelabelCount = 0,
        string $shardMode = 'none',
        ?int $branchPersonId = null,
        string $branchMode = 'descendants'
    ): array {
        $format = $this->normalizeFormat($format);
        $privacyMode = $this->normalizePrivacyMode($privacyMode);
        $prelabelCount = $this->normalizePrelabelCount($prelabelCount);
        $shardMode = $this->normalizeShardMode($shardMode);
        $branchMode = $this->normalizeBranchMode($branchMode);

        if ($treeId <= 0) {
            return $this->error('tree_id must be a positive integer.');
        }

        if (! $dryRun && ! $confirm) {
            return $this->error('confirm=true is required when dry_run=false.');
        }

        $tree = DB::table('genealogy_trees')->where('id', $treeId)->first();
        if (! $tree) {
            return $this->error("Tree not found: {$treeId}");
        }

        $runId = $this->buildRunId($treeId);
        $generatedAt = now()->toIso8601String();
        $branchFilter = $this->buildBranchFilter($treeId, $branchPersonId, $branchMode);
        $dataset = $this->buildDataset(
            treeId: $treeId,
            privacyMode: $privacyMode,
            includeSources: $includeSources,
            includeMedia: $includeMedia,
            includeIssues: $includeIssues,
            branchFilter: $branchFilter,
            prelabelCount: $prelabelCount,
            runId: $runId
        );

        $outputPlan = $this->outputPlan($treeId, $tree, $runId, $format, $shardMode, $branchFilter, $prelabelCount);
        $manifest = $this->buildManifest(
            tree: $tree,
            treeId: $treeId,
            runId: $runId,
            generatedAt: $generatedAt,
            privacyMode: $privacyMode,
            format: $format,
            actor: $actor,
            layoutProfile: $layoutProfile ?: 'dense_audit_v1',
            dataset: $dataset,
            outputPlan: $outputPlan,
            dryRun: $dryRun
        );

        if ($dryRun) {
            return [
                'tool' => 'source_audit_workbook',
                'success' => true,
                'dry_run' => true,
                'applied' => false,
                'tree_id' => $treeId,
                'tree_name' => $tree->name ?? null,
                'format' => $format,
                'privacy_mode' => $privacyMode,
                'prelabel_count' => $prelabelCount,
                'shard_mode' => $shardMode,
                'branch_filter' => $branchFilter ? $this->branchFilterSummary($branchFilter) : null,
                'run_id' => $runId,
                'row_counts' => $manifest['row_counts'],
                'counts' => $manifest['row_counts'],
                'output_plan' => $outputPlan,
                'manifest' => $manifest,
                'timestamp' => $generatedAt,
            ];
        }

        $written = $this->writePackage($format, $dataset, $manifest, $outputPlan);

        return [
            'tool' => 'source_audit_workbook',
            'success' => true,
            'dry_run' => false,
            'applied' => true,
            'tree_id' => $treeId,
            'tree_name' => $tree->name ?? null,
            'format' => $format,
            'privacy_mode' => $privacyMode,
            'prelabel_count' => $prelabelCount,
            'shard_mode' => $shardMode,
            'branch_filter' => $branchFilter ? $this->branchFilterSummary($branchFilter) : null,
            'run_id' => $runId,
            'row_counts' => $manifest['row_counts'],
            'counts' => $manifest['row_counts'],
            'output_dir' => $outputPlan['output_dir'],
            'files' => $written,
            'manifest' => $this->manifestWithWrittenFiles($manifest, $written),
            'timestamp' => $generatedAt,
        ];
    }

    public function buildDataset(
        int $treeId,
        string $privacyMode = 'private_local',
        bool $includeSources = true,
        bool $includeMedia = true,
        bool $includeIssues = true,
        ?array $branchFilter = null,
        int $prelabelCount = 0,
        string $runId = ''
    ): array {
        $privacyMode = $this->normalizePrivacyMode($privacyMode);
        $prelabelCount = $this->normalizePrelabelCount($prelabelCount);
        $personQuery = DB::table('genealogy_persons')
            ->where('tree_id', $treeId);
        if ($branchFilter !== null) {
            $scopedPersonIds = array_values(array_unique(array_map('intval', $branchFilter['person_ids'] ?? [])));
            $scopedPersonIds === []
                ? $personQuery->whereRaw('1 = 0')
                : $personQuery->whereIn('id', $scopedPersonIds);
        }
        $persons = $personQuery
            ->orderBy('surname')
            ->orderBy('given_name')
            ->orderBy('id')
            ->get();

        $personIds = $persons->pluck('id')->map(fn ($id) => (int) $id)->all();
        $personMap = [];
        foreach ($persons as $person) {
            $personMap[(int) $person->id] = $person;
        }

        $familyQuery = DB::table('genealogy_families')
            ->where('tree_id', $treeId);
        if ($branchFilter !== null) {
            $scopedFamilyIds = array_values(array_unique(array_map('intval', $branchFilter['family_ids'] ?? [])));
            $scopedFamilyIds === []
                ? $familyQuery->whereRaw('1 = 0')
                : $familyQuery->whereIn('id', $scopedFamilyIds);
        }
        $families = $familyQuery
            ->orderBy('id')
            ->get();

        $familyIds = $families->pluck('id')->map(fn ($id) => (int) $id)->all();
        $familyDisplayIds = [];
        foreach ($families as $family) {
            $familyDisplayIds[(int) $family->id] = $this->recordDisplayId($family->gedcom_id ?? null, 'F', (int) $family->id);
        }

        $childRows = $this->rowsForIds('genealogy_children', 'family_id', $familyIds, ['family_id', 'person_id', 'birth_order']);
        $childrenByFamily = [];
        $parentFamilyByPerson = [];
        foreach ($childRows as $row) {
            $familyId = (int) $row->family_id;
            $personId = (int) $row->person_id;
            $childrenByFamily[$familyId][] = $row;
            $parentFamilyByPerson[$personId] = $familyId;
        }

        $spouseFamiliesByPerson = [];
        foreach ($families as $family) {
            foreach ([(int) ($family->husband_id ?? 0), (int) ($family->wife_id ?? 0)] as $personId) {
                if ($personId > 0) {
                    $spouseFamiliesByPerson[$personId][] = (int) $family->id;
                }
            }
        }

        $variantsByPerson = [];
        if ($personIds !== [] && Schema::hasTable('genealogy_name_variants')) {
            foreach ($this->rowsForIds('genealogy_name_variants', 'person_id', $personIds, ['person_id', 'name_type', 'full_name', 'given_names', 'surname']) as $variant) {
                $variantsByPerson[(int) $variant->person_id][] = $this->compactNameVariant($variant);
            }
        }

        $personSourceCounts = $this->countRowsForIds('genealogy_person_sources', 'person_id', $personIds);
        $personMediaCounts = $this->countRowsForIds('genealogy_person_media', 'person_id', $personIds);
        $personCitationCounts = $this->countRowsForIds('genealogy_citations', 'person_id', $personIds);
        $familySourceCounts = $this->countRowsForIds('genealogy_family_sources', 'family_id', $familyIds);
        $familyMediaCounts = $this->countRowsForIds('genealogy_family_media', 'family_id', $familyIds);
        $familyCitationCounts = $this->countRowsForIds('genealogy_citations', 'family_id', $familyIds);

        $issueCountsByPerson = $includeIssues ? $this->pendingIssueCountsByPerson($treeId) : [];
        $issueCountsByFamily = $includeIssues ? $this->pendingIssueCountsByFamily($treeId) : [];

        $personRows = [];
        foreach ($persons as $person) {
            $personRows[] = $this->personAuditRow(
                $treeId,
                $person,
                $privacyMode,
                $parentFamilyByPerson,
                $spouseFamiliesByPerson,
                $familyDisplayIds,
                $variantsByPerson,
                $personSourceCounts,
                $personMediaCounts,
                $personCitationCounts,
                $issueCountsByPerson
            );
        }
        $this->sortPersonAuditRows($personRows);

        $familyRows = [];
        foreach ($families as $family) {
            $familyRows[] = $this->familyAuditRow(
                $treeId,
                $family,
                $privacyMode,
                $personMap,
                $childrenByFamily,
                $familyDisplayIds,
                $familySourceCounts,
                $familyMediaCounts,
                $familyCitationCounts,
                $issueCountsByFamily
            );
        }
        $this->sortFamilyAuditRows($familyRows);

        return [
            'persons_all.csv' => $personRows,
            'families_all.csv' => $familyRows,
            'sources_inventory.csv' => $includeSources ? $this->sourceRows($treeId, $privacyMode, $branchFilter) : [],
            'media_inventory.csv' => $includeMedia ? $this->mediaRows($treeId, $privacyMode, $branchFilter) : [],
            'audit_claims.csv' => $includeSources ? $this->claimRows($treeId, $privacyMode, $branchFilter) : [],
            'prelabel_queue.csv' => $this->prelabelRows($treeId, $prelabelCount, $runId, $branchFilter),
            'review_notes.csv' => $includeIssues ? $this->reviewNoteRows($treeId, $privacyMode, $branchFilter) : [],
            'issue_index.csv' => $includeIssues ? $this->issueRows($treeId, $privacyMode, $branchFilter) : [],
            'intake_log.csv' => [],
        ];
    }

    public function createReviewPacketFromWorkbookRow(
        int $treeId,
        ?string $tag = null,
        ?string $recordType = null,
        ?int $recordId = null,
        bool $dryRun = true,
        bool $confirm = false,
        string $actor = 'source-audit-workbook-review-packet'
    ): array {
        if ($treeId <= 0) {
            return $this->error('tree_id must be a positive integer.');
        }

        if (! $dryRun && ! $confirm) {
            return $this->error('confirm=true is required when dry_run=false.');
        }

        $resolved = null;
        $resolver = app(SourceAuditWorkbookTagResolver::class);
        $tag = trim((string) ($tag ?? ''));
        if ($tag !== '') {
            $resolved = $resolver->resolveTag($treeId, $tag);
        } elseif ($recordType !== null && $recordId !== null) {
            $resolved = $resolver->resolveRecord($treeId, $recordType, $recordId);
        } else {
            return $this->error('A workbook tag or record_type plus record_id is required.');
        }

        if (! ($resolved['success'] ?? false)) {
            return $this->error((string) ($resolved['error'] ?? 'workbook_row_not_found'));
        }

        $packet = $this->reviewPacketForWorkbookTarget($treeId, $resolved);
        if (! ($packet['success'] ?? false)) {
            return $packet;
        }

        $payload = (array) ($packet['packet'] ?? []);
        if ($dryRun) {
            return [
                'tool' => 'source_audit_workbook_review_packet',
                'success' => true,
                'dry_run' => true,
                'applied' => false,
                'tree_id' => $treeId,
                'target' => $resolved,
                'packet' => $payload,
                'materialization' => null,
            ];
        }

        $materialization = app(GenealogyReviewPacketMaterializationService::class)->materialize($payload, [
            'agent_id' => $actor,
            'priority' => 1,
        ]);

        $targetRef = null;
        $researchHubUrl = null;
        $queueId = (int) ($materialization['review_queue_id'] ?? 0);
        if (($materialization['success'] ?? false) && $queueId > 0) {
            $row = DB::table('agent_review_queue')->where('id', $queueId)->first();
            if ($row) {
                $targetRef = app(ReviewTargetReferenceService::class)
                    ->forReviewRow($row, GenealogyReviewPacketAdapterService::REVIEW_TYPE);
                $researchHubUrl = '/research-hub?category=genealogy&unified_id='.rawurlencode($targetRef);
            }
        }

        return [
            'tool' => 'source_audit_workbook_review_packet',
            'success' => (bool) ($materialization['success'] ?? false),
            'dry_run' => false,
            'applied' => (bool) ($materialization['success'] ?? false),
            'tree_id' => $treeId,
            'target' => $resolved,
            'packet' => $payload,
            'materialization' => $materialization,
            'target_ref' => $targetRef,
            'research_hub_url' => $researchHubUrl,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $dataset
     */
    public function csvStrings(array $dataset): array
    {
        $csv = [];
        foreach (self::CSV_FILES as $fileName) {
            $csv[$fileName] = $this->toCsv($dataset[$fileName] ?? [], $this->headersFor($fileName));
        }

        return $csv;
    }

    private function prelabelRows(int $treeId, int $prelabelCount, string $runId, ?array $branchFilter): array
    {
        if ($prelabelCount <= 0) {
            return [];
        }

        $batchStamp = $this->runStamp($runId !== '' ? $runId : $this->buildRunId($treeId));
        $branchPersonId = (int) ($branchFilter['person_id'] ?? 0);

        $rows = [];
        for ($index = 1; $index <= $prelabelCount; $index++) {
            $sequence = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            $rows[] = [
                'prelabel_id' => "FT{$treeId}-PRE-{$batchStamp}-{$sequence}",
                'pre_scan_document_id' => "FT{$treeId}-DOC-{$batchStamp}-{$sequence}",
                'person_audit_id' => $branchPersonId > 0 ? $this->auditId($treeId, 'P', $branchPersonId) : '',
                'family_audit_id' => '',
                'source_audit_id' => '',
                'expected_fact_type' => '',
                'expected_date_place' => '',
                'paper_location' => '',
                'scan_batch_id' => $runId,
                'reviewer_action' => 'scan_or_defer',
                'reject_defer_reason' => '',
            ];
        }

        return $rows;
    }

    private function buildBranchFilter(int $treeId, ?int $personId, string $branchMode): ?array
    {
        if ($personId === null || $personId <= 0) {
            return null;
        }

        $person = DB::table('genealogy_persons')
            ->where('tree_id', $treeId)
            ->where('id', $personId)
            ->first();
        if (! $person) {
            throw new RuntimeException("Branch root person not found in tree {$treeId}: {$personId}");
        }

        $families = DB::table('genealogy_families')
            ->where('tree_id', $treeId)
            ->select(['id', 'husband_id', 'wife_id'])
            ->get();
        $familyIds = $families->pluck('id')->map(fn ($id) => (int) $id)->all();
        $childRows = $this->rowsForIds('genealogy_children', 'family_id', $familyIds, ['family_id', 'person_id', 'birth_order']);

        $childrenByFamily = [];
        $parentFamilyByPerson = [];
        foreach ($childRows as $row) {
            $familyId = (int) $row->family_id;
            $childId = (int) $row->person_id;
            $childrenByFamily[$familyId][] = $childId;
            $parentFamilyByPerson[$childId] = $familyId;
        }

        $familyById = [];
        $spouseFamiliesByPerson = [];
        foreach ($families as $family) {
            $familyId = (int) $family->id;
            $familyById[$familyId] = [
                'id' => $familyId,
                'husband_id' => (int) ($family->husband_id ?? 0),
                'wife_id' => (int) ($family->wife_id ?? 0),
            ];
            foreach ([$familyById[$familyId]['husband_id'], $familyById[$familyId]['wife_id']] as $spouseId) {
                if ($spouseId > 0) {
                    $spouseFamiliesByPerson[$spouseId][] = $familyId;
                }
            }
        }

        [$personIds, $scopedFamilyIds] = match ($branchMode) {
            'ancestors' => $this->ancestorScope($personId, $familyById, $parentFamilyByPerson),
            'family' => $this->nuclearFamilyScope($personId, $familyById, $childrenByFamily, $parentFamilyByPerson, $spouseFamiliesByPerson),
            default => $this->descendantScope($personId, $familyById, $childrenByFamily, $spouseFamiliesByPerson),
        };

        return [
            'person_id' => $personId,
            'mode' => $branchMode,
            'label' => trim($this->formatPersonNameWithYears($person).' '.$branchMode),
            'person_ids' => $personIds,
            'family_ids' => $scopedFamilyIds,
        ];
    }

    private function descendantScope(int $rootPersonId, array $familyById, array $childrenByFamily, array $spouseFamiliesByPerson): array
    {
        $personIds = [$rootPersonId => true];
        $familyIds = [];
        $queue = [$rootPersonId];
        $visited = [];

        while ($queue !== []) {
            $personId = array_shift($queue);
            if (isset($visited[$personId])) {
                continue;
            }
            $visited[$personId] = true;

            foreach ($spouseFamiliesByPerson[$personId] ?? [] as $familyId) {
                $family = $familyById[$familyId] ?? null;
                if (! $family) {
                    continue;
                }
                $familyIds[$familyId] = true;
                foreach ([$family['husband_id'], $family['wife_id']] as $parentId) {
                    if ($parentId > 0) {
                        $personIds[$parentId] = true;
                    }
                }
                foreach ($childrenByFamily[$familyId] ?? [] as $childId) {
                    if (! isset($personIds[$childId])) {
                        $queue[] = $childId;
                    }
                    $personIds[$childId] = true;
                }
            }
        }

        return [$this->sortedIntKeys($personIds), $this->sortedIntKeys($familyIds)];
    }

    private function ancestorScope(int $rootPersonId, array $familyById, array $parentFamilyByPerson): array
    {
        $personIds = [$rootPersonId => true];
        $familyIds = [];
        $queue = [$rootPersonId];
        $visited = [];

        while ($queue !== []) {
            $personId = array_shift($queue);
            if (isset($visited[$personId])) {
                continue;
            }
            $visited[$personId] = true;

            $familyId = $parentFamilyByPerson[$personId] ?? 0;
            $family = $familyById[$familyId] ?? null;
            if (! $family) {
                continue;
            }
            $familyIds[$familyId] = true;
            foreach ([$family['husband_id'], $family['wife_id']] as $parentId) {
                if ($parentId <= 0) {
                    continue;
                }
                if (! isset($personIds[$parentId])) {
                    $queue[] = $parentId;
                }
                $personIds[$parentId] = true;
            }
        }

        return [$this->sortedIntKeys($personIds), $this->sortedIntKeys($familyIds)];
    }

    private function nuclearFamilyScope(int $rootPersonId, array $familyById, array $childrenByFamily, array $parentFamilyByPerson, array $spouseFamiliesByPerson): array
    {
        $personIds = [$rootPersonId => true];
        $familyIds = [];
        $candidateFamilyIds = array_merge(
            [$parentFamilyByPerson[$rootPersonId] ?? 0],
            $spouseFamiliesByPerson[$rootPersonId] ?? []
        );

        foreach ($candidateFamilyIds as $familyId) {
            $familyId = (int) $familyId;
            $family = $familyById[$familyId] ?? null;
            if (! $family) {
                continue;
            }
            $familyIds[$familyId] = true;
            foreach ([$family['husband_id'], $family['wife_id']] as $parentId) {
                if ($parentId > 0) {
                    $personIds[$parentId] = true;
                }
            }
            foreach ($childrenByFamily[$familyId] ?? [] as $childId) {
                $personIds[$childId] = true;
            }
        }

        return [$this->sortedIntKeys($personIds), $this->sortedIntKeys($familyIds)];
    }

    private function sourceIdsForScope(array $personIds, array $familyIds): array
    {
        $ids = [];
        if ($personIds !== [] && Schema::hasTable('genealogy_person_sources') && Schema::hasColumn('genealogy_person_sources', 'person_id')) {
            $ids = array_merge($ids, DB::table('genealogy_person_sources')->whereIn('person_id', $personIds)->pluck('source_id')->all());
        }
        if ($familyIds !== [] && Schema::hasTable('genealogy_family_sources') && Schema::hasColumn('genealogy_family_sources', 'family_id')) {
            $ids = array_merge($ids, DB::table('genealogy_family_sources')->whereIn('family_id', $familyIds)->pluck('source_id')->all());
        }
        if (Schema::hasTable('genealogy_citations')) {
            if ($personIds !== []) {
                $ids = array_merge($ids, DB::table('genealogy_citations')->whereIn('person_id', $personIds)->whereNotNull('source_id')->pluck('source_id')->all());
            }
            if ($familyIds !== []) {
                $ids = array_merge($ids, DB::table('genealogy_citations')->whereIn('family_id', $familyIds)->whereNotNull('source_id')->pluck('source_id')->all());
            }
        }

        return $this->sortedUniqueInts($ids);
    }

    private function mediaIdsForScope(array $personIds, array $familyIds): array
    {
        $ids = [];
        if ($personIds !== [] && Schema::hasTable('genealogy_person_media') && Schema::hasColumn('genealogy_person_media', 'person_id')) {
            $ids = array_merge($ids, DB::table('genealogy_person_media')->whereIn('person_id', $personIds)->pluck('media_id')->all());
        }
        if ($familyIds !== [] && Schema::hasTable('genealogy_family_media') && Schema::hasColumn('genealogy_family_media', 'family_id')) {
            $ids = array_merge($ids, DB::table('genealogy_family_media')->whereIn('family_id', $familyIds)->pluck('media_id')->all());
        }
        if (Schema::hasTable('genealogy_citations')) {
            if ($personIds !== []) {
                $ids = array_merge($ids, DB::table('genealogy_citations')->whereIn('person_id', $personIds)->whereNotNull('media_id')->pluck('media_id')->all());
            }
            if ($familyIds !== []) {
                $ids = array_merge($ids, DB::table('genealogy_citations')->whereIn('family_id', $familyIds)->whereNotNull('media_id')->pluck('media_id')->all());
            }
        }

        return $this->sortedUniqueInts($ids);
    }

    private function scopedPersonIds(?array $branchFilter): array
    {
        return $this->sortedUniqueInts((array) ($branchFilter['person_ids'] ?? []));
    }

    private function scopedFamilyIds(?array $branchFilter): array
    {
        return $this->sortedUniqueInts((array) ($branchFilter['family_ids'] ?? []));
    }

    private function sortedIntKeys(array $map): array
    {
        return $this->sortedUniqueInts(array_keys($map));
    }

    private function sortedUniqueInts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    private function branchFilterSummary(array $branchFilter): array
    {
        return [
            'person_id' => (int) ($branchFilter['person_id'] ?? 0),
            'mode' => (string) ($branchFilter['mode'] ?? ''),
            'label' => (string) ($branchFilter['label'] ?? ''),
            'person_count' => count($branchFilter['person_ids'] ?? []),
            'family_count' => count($branchFilter['family_ids'] ?? []),
        ];
    }

    private function personAuditRow(
        int $treeId,
        object $person,
        string $privacyMode,
        array $parentFamilyByPerson,
        array $spouseFamiliesByPerson,
        array $familyDisplayIds,
        array $variantsByPerson,
        array $personSourceCounts,
        array $personMediaCounts,
        array $personCitationCounts,
        array $issueCountsByPerson
    ): array {
        $personId = (int) $person->id;
        $private = $this->personIsPrivate($person, $privacyMode);
        $auditOnly = $privacyMode === 'audit_ids_only';

        $displayName = $this->formatPersonNameWithYears($person);
        $sortName = trim((string) ($person->surname ?? '').', '.(string) ($person->given_name ?? ''));
        $surnameGroup = $this->surnameGroupLabel($person->surname ?? null);

        return [
            'person_audit_id' => $this->auditId($treeId, 'P', $personId),
            'person_id' => $personId,
            'person_print_id' => $auditOnly || $private ? '' : $this->recordDisplayId($person->gedcom_id ?? null, 'I', $personId),
            'person_url' => $auditOnly || $private ? '' : url('/genealogy?person='.$personId),
            'document_tag' => $auditOnly || $private ? '' : $this->documentTag($person->gedcom_id ?? null, 'I', $personId),
            'gedcom_id' => (string) ($person->gedcom_id ?? ''),
            'sort_name' => $auditOnly ? '' : ($private ? '[private]' : $sortName),
            'person_sort_group' => $auditOnly ? '' : ($private ? 'PRIVATE' : $surnameGroup),
            'person_sort_key' => $auditOnly ? '' : ($private ? '[private]' : $this->personSortKey($person)),
            'person_sort_year' => $this->extractYear($person->birth_date ?? null),
            'display_name' => $auditOnly ? '' : ($private ? '[private person]' : $displayName),
            'name_variants' => $auditOnly || $private ? '' : implode('; ', $variantsByPerson[$personId] ?? []),
            'sex' => $auditOnly || $private ? '' : (string) ($person->sex ?? ''),
            'birth' => $auditOnly || $private ? '' : $this->datePlace($person->birth_date ?? null, $person->birth_place ?? null),
            'death' => $auditOnly || $private ? '' : $this->datePlace($person->death_date ?? null, $person->death_place ?? null),
            'living' => (int) ($person->living ?? 0),
            'parent_family_id' => isset($parentFamilyByPerson[$personId]) ? $this->auditId($treeId, 'F', (int) $parentFamilyByPerson[$personId]) : '',
            'parent_family_print_id' => isset($parentFamilyByPerson[$personId]) ? ($familyDisplayIds[(int) $parentFamilyByPerson[$personId]] ?? 'F'.(int) $parentFamilyByPerson[$personId]) : '',
            'spouse_family_ids' => $this->auditIdList($treeId, 'F', $spouseFamiliesByPerson[$personId] ?? []),
            'spouse_family_print_ids' => $this->displayIdList($familyDisplayIds, $spouseFamiliesByPerson[$personId] ?? [], 'F'),
            'source_count' => $personSourceCounts[$personId] ?? 0,
            'media_count' => $personMediaCounts[$personId] ?? 0,
            'citation_count' => $personCitationCounts[$personId] ?? 0,
            'open_issue_count' => $issueCountsByPerson[$personId] ?? 0,
            'review_status' => $this->reviewStatus($issueCountsByPerson[$personId] ?? 0, $personSourceCounts[$personId] ?? 0),
            'doc_notes' => '',
        ];
    }

    private function familyAuditRow(
        int $treeId,
        object $family,
        string $privacyMode,
        array $personMap,
        array $childrenByFamily,
        array $familyDisplayIds,
        array $familySourceCounts,
        array $familyMediaCounts,
        array $familyCitationCounts,
        array $issueCountsByFamily
    ): array {
        $familyId = (int) $family->id;
        $husband = $personMap[(int) ($family->husband_id ?? 0)] ?? null;
        $wife = $personMap[(int) ($family->wife_id ?? 0)] ?? null;
        $children = $childrenByFamily[$familyId] ?? [];
        usort($children, static function (object $left, object $right): int {
            return ((int) ($left->birth_order ?? 9999) <=> (int) ($right->birth_order ?? 9999))
                ?: ((int) $left->person_id <=> (int) $right->person_id);
        });

        $childIds = [];
        $childPrintIds = [];
        foreach ($children as $child) {
            $childId = (int) $child->person_id;
            $childPerson = $personMap[$childId] ?? null;
            $childIds[] = $this->auditId($treeId, 'P', $childId);
            $childPrintIds[] = $childPerson
                ? $this->recordDisplayId($childPerson->gedcom_id ?? null, 'I', $childId)
                : 'I'.$childId;
        }

        $private = ($husband && $this->personIsPrivate($husband, $privacyMode))
            || ($wife && $this->personIsPrivate($wife, $privacyMode));
        $auditOnly = $privacyMode === 'audit_ids_only';
        $familySort = $this->familySortParts($husband, $wife, $family);

        return [
            'family_audit_id' => $this->auditId($treeId, 'F', $familyId),
            'family_id' => $familyId,
            'family_print_id' => $auditOnly || $private ? '' : ($familyDisplayIds[$familyId] ?? $this->recordDisplayId($family->gedcom_id ?? null, 'F', $familyId)),
            'document_tag' => $auditOnly || $private ? '' : $this->documentTag($family->gedcom_id ?? null, 'F', $familyId),
            'gedcom_id' => (string) ($family->gedcom_id ?? ''),
            'family_sort_group' => $auditOnly ? '' : ($private ? 'PRIVATE' : $familySort['group']),
            'family_sort_key' => $auditOnly ? '' : ($private ? '[private]' : $familySort['key']),
            'family_sort_year' => $this->extractYear($family->marriage_date ?? null),
            'husband_id' => $family->husband_id ? $this->auditId($treeId, 'P', (int) $family->husband_id) : '',
            'husband_url' => $auditOnly || $private || ! $husband ? '' : url('/genealogy?person='.(int) $family->husband_id),
            'husband_name' => $auditOnly || $private ? '' : ($husband ? $this->formatPersonNameWithYears($husband) : ''),
            'wife_id' => $family->wife_id ? $this->auditId($treeId, 'P', (int) $family->wife_id) : '',
            'wife_url' => $auditOnly || $private || ! $wife ? '' : url('/genealogy?person='.(int) $family->wife_id),
            'wife_name' => $auditOnly || $private ? '' : ($wife ? $this->formatPersonNameWithYears($wife) : ''),
            'marriage' => $auditOnly || $private ? '' : $this->datePlace($family->marriage_date ?? null, $family->marriage_place ?? null),
            'divorce' => $auditOnly || $private ? '' : $this->datePlace($family->divorce_date ?? null, $family->divorce_place ?? null),
            'child_count' => count($children),
            'child_ids' => implode('; ', $childIds),
            'child_print_ids' => implode('; ', $childPrintIds),
            'source_count' => $familySourceCounts[$familyId] ?? 0,
            'media_count' => $familyMediaCounts[$familyId] ?? 0,
            'citation_count' => $familyCitationCounts[$familyId] ?? 0,
            'open_issue_count' => $issueCountsByFamily[$familyId] ?? 0,
            'review_status' => $this->reviewStatus($issueCountsByFamily[$familyId] ?? 0, $familySourceCounts[$familyId] ?? 0),
            'doc_notes' => '',
        ];
    }

    private function sortPersonAuditRows(array &$rows): void
    {
        usort($rows, function (array $left, array $right): int {
            return $this->compareWorkbookSortValues([
                $this->unknownGroupRank($left['person_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($left['person_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($left['person_sort_key'] ?? $left['sort_name'] ?? ''),
                $this->sortYear($left['person_sort_year'] ?? $left['birth'] ?? ''),
                (int) ($left['person_id'] ?? 0),
            ], [
                $this->unknownGroupRank($right['person_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($right['person_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($right['person_sort_key'] ?? $right['sort_name'] ?? ''),
                $this->sortYear($right['person_sort_year'] ?? $right['birth'] ?? ''),
                (int) ($right['person_id'] ?? 0),
            ]);
        });
    }

    private function sortFamilyAuditRows(array &$rows): void
    {
        usort($rows, function (array $left, array $right): int {
            return $this->compareWorkbookSortValues([
                $this->unknownGroupRank($left['family_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($left['family_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($left['family_sort_key'] ?? ''),
                $this->sortYear($left['family_sort_year'] ?? $left['marriage'] ?? ''),
                $this->naturalIdSortNumber($left['family_print_id'] ?? ''),
                (int) ($left['family_id'] ?? 0),
            ], [
                $this->unknownGroupRank($right['family_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($right['family_sort_group'] ?? ''),
                $this->normalizeWorkbookSortText($right['family_sort_key'] ?? ''),
                $this->sortYear($right['family_sort_year'] ?? $right['marriage'] ?? ''),
                $this->naturalIdSortNumber($right['family_print_id'] ?? ''),
                (int) ($right['family_id'] ?? 0),
            ]);
        });
    }

    /**
     * @return array{group: string, key: string}
     */
    private function familySortParts(?object $husband, ?object $wife, object $family): array
    {
        $primary = $this->personHasSurname($husband) ? $husband : ($this->personHasSurname($wife) ? $wife : ($husband ?? $wife));
        $secondary = $primary === $husband ? $wife : $husband;

        return [
            'group' => $this->surnameGroupLabel($primary?->surname ?? null),
            'key' => trim(implode(' ', array_filter([
                (string) ($primary?->surname ?? ''),
                (string) ($primary?->given_name ?? ''),
                (string) ($secondary?->surname ?? ''),
                (string) ($secondary?->given_name ?? ''),
                $this->extractYear($family->marriage_date ?? null),
                (string) ($family->gedcom_id ?? ''),
            ], static fn (string $part): bool => trim($part) !== ''))),
        ];
    }

    private function personSortKey(object $person): string
    {
        return trim(implode(' ', array_filter([
            (string) ($person->surname ?? ''),
            (string) ($person->given_name ?? ''),
            $this->extractYear($person->birth_date ?? null),
            (string) ($person->gedcom_id ?? ''),
        ], static fn (string $part): bool => trim($part) !== '')));
    }

    private function personHasSurname(?object $person): bool
    {
        return $person !== null && trim((string) ($person->surname ?? '')) !== '';
    }

    private function surnameGroupLabel(mixed $surname): string
    {
        $surname = trim((string) ($surname ?? ''));
        if ($surname === '') {
            return 'UNKNOWN / NO SURNAME';
        }

        return Str::upper($surname);
    }

    private function unknownGroupRank(mixed $group): int
    {
        $group = $this->normalizeWorkbookSortText($group);

        return $group === '' || $group === 'UNKNOWN / NO SURNAME' ? 1 : 0;
    }

    private function sortYear(mixed $value): int
    {
        $year = $this->extractYear($value);

        return $year === '' ? 9999 : (int) $year;
    }

    private function naturalIdSortNumber(mixed $value): int
    {
        return preg_match('/(\d+)/', (string) $value, $matches) === 1 ? (int) $matches[1] : PHP_INT_MAX;
    }

    private function normalizeWorkbookSortText(mixed $value): string
    {
        $text = Str::upper(trim((string) $value));
        $text = preg_replace('/\s+/', ' ', $text);

        return $text === null ? '' : $text;
    }

    private function compareWorkbookSortValues(array $left, array $right): int
    {
        $count = max(count($left), count($right));
        for ($index = 0; $index < $count; $index++) {
            $leftValue = $left[$index] ?? '';
            $rightValue = $right[$index] ?? '';

            if (is_int($leftValue) && is_int($rightValue)) {
                $comparison = $leftValue <=> $rightValue;
            } else {
                $comparison = strnatcasecmp((string) $leftValue, (string) $rightValue);
            }

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function sourceRows(int $treeId, string $privacyMode, ?array $branchFilter = null): array
    {
        $query = DB::table('genealogy_sources')
            ->where('tree_id', $treeId);
        if ($branchFilter !== null) {
            $sourceIds = $this->sourceIdsForScope(
                $this->scopedPersonIds($branchFilter),
                $this->scopedFamilyIds($branchFilter)
            );
            $sourceIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('id', $sourceIds);
        }
        $sources = $query
            ->orderBy('title')
            ->orderBy('id')
            ->get();
        $sourceIds = $sources->pluck('id')->map(fn ($id) => (int) $id)->all();
        $personLinks = $this->countRowsForIds('genealogy_person_sources', 'source_id', $sourceIds);
        $familyLinks = $this->countRowsForIds('genealogy_family_sources', 'source_id', $sourceIds);
        $citationCounts = $this->countRowsForIds('genealogy_citations', 'source_id', $sourceIds);
        $mediaCounts = $this->citationMediaCountsBySource($sourceIds);
        $auditOnly = $privacyMode === 'audit_ids_only';

        $rows = [];
        foreach ($sources as $source) {
            $sourceId = (int) $source->id;
            $rows[] = [
                'source_audit_id' => $this->auditId($treeId, 'S', $sourceId),
                'source_id' => $sourceId,
                'gedcom_id' => (string) ($source->gedcom_id ?? ''),
                'title' => $auditOnly ? '' : $this->cleanText($source->title ?? ''),
                'repository' => $auditOnly ? '' : $this->cleanText($source->repository ?? ''),
                'url' => $auditOnly ? '' : (string) ($source->url ?? ''),
                'source_quality' => (string) ($source->source_quality ?? $source->source_category ?? ''),
                'information_quality' => (string) ($source->information_quality ?? ''),
                'person_link_count' => $personLinks[$sourceId] ?? 0,
                'family_link_count' => $familyLinks[$sourceId] ?? 0,
                'citation_count' => $citationCounts[$sourceId] ?? 0,
                'media_count' => $mediaCounts[$sourceId] ?? 0,
                'rag_indexed_at' => (string) ($source->rag_indexed_at ?? ''),
                'doc_notes' => '',
            ];
        }

        return $rows;
    }

    private function mediaRows(int $treeId, string $privacyMode, ?array $branchFilter = null): array
    {
        $query = DB::table('genealogy_media')
            ->where('tree_id', $treeId);
        if ($branchFilter !== null) {
            $mediaIds = $this->mediaIdsForScope(
                $this->scopedPersonIds($branchFilter),
                $this->scopedFamilyIds($branchFilter)
            );
            $mediaIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('id', $mediaIds);
        }
        $media = $query
            ->orderBy('title')
            ->orderBy('id')
            ->get();
        $mediaIds = $media->pluck('id')->map(fn ($id) => (int) $id)->all();
        $personLinks = $this->countRowsForIds('genealogy_person_media', 'media_id', $mediaIds);
        $familyLinks = $this->countRowsForIds('genealogy_family_media', 'media_id', $mediaIds);
        $citationCounts = $this->countRowsForIds('genealogy_citations', 'media_id', $mediaIds);
        $sourceByMedia = $this->sourceIdsByMedia($mediaIds);
        $auditOnly = $privacyMode === 'audit_ids_only';

        $rows = [];
        foreach ($media as $item) {
            $mediaId = (int) $item->id;
            $hasText = trim((string) ($item->transcription_text ?? '')) !== ''
                || trim((string) ($item->transcription ?? '')) !== '';

            $rows[] = [
                'media_audit_id' => $this->auditId($treeId, 'M', $mediaId),
                'media_id' => $mediaId,
                'gedcom_id' => (string) ($item->gedcom_id ?? ''),
                'title' => $auditOnly ? '' : $this->cleanText($item->title ?? $item->local_filename ?? ''),
                'media_type' => (string) ($item->media_type ?? ''),
                'file_format' => (string) ($item->file_format ?? ''),
                'mime_type' => (string) ($item->mime_type ?? ''),
                'file_size' => (int) ($item->file_size ?? 0),
                'path' => $auditOnly ? '' : $this->cleanText($item->nextcloud_path ?? $item->original_path ?? ''),
                'source_ids' => $this->auditIdList($treeId, 'S', $sourceByMedia[$mediaId] ?? []),
                'person_link_count' => $personLinks[$mediaId] ?? 0,
                'family_link_count' => $familyLinks[$mediaId] ?? 0,
                'citation_count' => $citationCounts[$mediaId] ?? 0,
                'ocr_status' => (string) ($item->analysis_status ?? ''),
                'htr_or_transcription' => $hasText ? 'has_text' : '',
                'rag_indexed_at' => (string) ($item->rag_indexed_at ?? ''),
                'doc_notes' => '',
            ];
        }

        return $rows;
    }

    private function claimRows(int $treeId, string $privacyMode, ?array $branchFilter = null): array
    {
        $auditOnly = $privacyMode === 'audit_ids_only';
        $query = DB::table('genealogy_citations as c')
            ->join('genealogy_sources as s', 's.id', '=', 'c.source_id')
            ->where('s.tree_id', $treeId)
            ->select([
                'c.id',
                'c.source_id',
                'c.person_id',
                'c.family_id',
                'c.media_id',
                'c.fact_type',
                'c.page',
                'c.quality',
                'c.evidence_type',
                'c.information_type',
                'c.text',
            ]);
        if ($branchFilter !== null) {
            $personIds = $this->scopedPersonIds($branchFilter);
            $familyIds = $this->scopedFamilyIds($branchFilter);
            $query->where(function ($scope) use ($personIds, $familyIds): void {
                $hasScope = false;
                if ($personIds !== []) {
                    $scope->whereIn('c.person_id', $personIds);
                    $hasScope = true;
                }
                if ($familyIds !== []) {
                    $hasScope
                        ? $scope->orWhereIn('c.family_id', $familyIds)
                        : $scope->whereIn('c.family_id', $familyIds);
                    $hasScope = true;
                }
                if (! $hasScope) {
                    $scope->whereRaw('1 = 0');
                }
            });
        }
        $rows = $query
            ->orderBy('c.person_id')
            ->orderBy('c.family_id')
            ->orderBy('c.source_id')
            ->orderBy('c.id')
            ->get();

        $claims = [];
        foreach ($rows as $row) {
            $claims[] = [
                'claim_audit_id' => 'FT'.$treeId.'-CLM-'.str_pad((string) $row->id, 8, '0', STR_PAD_LEFT),
                'citation_id' => (int) $row->id,
                'source_id' => $this->auditId($treeId, 'S', (int) $row->source_id),
                'media_id' => $row->media_id ? $this->auditId($treeId, 'M', (int) $row->media_id) : '',
                'person_id' => $row->person_id ? $this->auditId($treeId, 'P', (int) $row->person_id) : '',
                'family_id' => $row->family_id ? $this->auditId($treeId, 'F', (int) $row->family_id) : '',
                'fact_type' => (string) ($row->fact_type ?? ''),
                'page' => $auditOnly ? '' : (string) ($row->page ?? ''),
                'quality' => (string) ($row->quality ?? ''),
                'evidence_type' => (string) ($row->evidence_type ?? ''),
                'information_type' => (string) ($row->information_type ?? ''),
                'claim_excerpt' => $auditOnly ? '' : Str::limit($this->cleanText($row->text ?? ''), 240, '...'),
                'match_status' => '',
                'next_action' => '',
                'note_id' => '',
            ];
        }

        return $claims;
    }

    private function issueRows(int $treeId, string $privacyMode, ?array $branchFilter = null): array
    {
        $rows = [];
        if (Schema::hasTable('genealogy_proposed_changes') && Schema::hasColumn('genealogy_proposed_changes', 'tree_id')) {
            $query = DB::table('genealogy_proposed_changes')
                ->where('tree_id', $treeId)
                ->orderBy('id')
                ->limit(1000);
            if ($branchFilter !== null && Schema::hasColumn('genealogy_proposed_changes', 'person_id')) {
                $personIds = $this->scopedPersonIds($branchFilter);
                $personIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('person_id', $personIds);
            }
            if (Schema::hasColumn('genealogy_proposed_changes', 'status')) {
                $query->whereIn('status', ['pending', 'pending_review', 'approved']);
            }

            foreach ($query->get() as $change) {
                $personId = (int) ($change->person_id ?? 0);
                $rows[] = [
                    'issue_id' => 'FT'.$treeId.'-ISS-PC-'.str_pad((string) $change->id, 8, '0', STR_PAD_LEFT),
                    'severity' => 'review',
                    'scope' => 'person',
                    'affected_id' => $personId > 0 ? $this->auditId($treeId, 'P', $personId) : '',
                    'issue_class' => 'proposed_change',
                    'description' => $privacyMode === 'audit_ids_only' ? '' : trim((string) ($change->change_type ?? '').' '.(string) ($change->field_name ?? '')),
                    'suggested_fix' => 'Review proposal in PLOS before applying.',
                    'state' => (string) ($change->status ?? ''),
                    'doc_notes' => '',
                ];
            }
        }

        if (Schema::hasTable('genealogy_proposed_relationships') && Schema::hasColumn('genealogy_proposed_relationships', 'tree_id')) {
            $query = DB::table('genealogy_proposed_relationships')
                ->where('tree_id', $treeId)
                ->orderBy('id')
                ->limit(1000);
            if ($branchFilter !== null) {
                $personIds = $this->scopedPersonIds($branchFilter);
                $familyIds = $this->scopedFamilyIds($branchFilter);
                $query->where(function ($scope) use ($personIds, $familyIds): void {
                    $hasScope = false;
                    if ($personIds !== [] && Schema::hasColumn('genealogy_proposed_relationships', 'person_id')) {
                        $scope->whereIn('person_id', $personIds);
                        $hasScope = true;
                    }
                    if ($familyIds !== [] && Schema::hasColumn('genealogy_proposed_relationships', 'applied_family_id')) {
                        $hasScope
                            ? $scope->orWhereIn('applied_family_id', $familyIds)
                            : $scope->whereIn('applied_family_id', $familyIds);
                        $hasScope = true;
                    }
                    if (! $hasScope) {
                        $scope->whereRaw('1 = 0');
                    }
                });
            }
            if (Schema::hasColumn('genealogy_proposed_relationships', 'status')) {
                $query->whereIn('status', ['pending', 'pending_review', 'approved']);
            }

            foreach ($query->get() as $relationship) {
                $personId = (int) ($relationship->person_id ?? 0);
                $rows[] = [
                    'issue_id' => 'FT'.$treeId.'-ISS-PR-'.str_pad((string) $relationship->id, 8, '0', STR_PAD_LEFT),
                    'severity' => 'review',
                    'scope' => 'relationship',
                    'affected_id' => $personId > 0 ? $this->auditId($treeId, 'P', $personId) : '',
                    'issue_class' => 'proposed_relationship',
                    'description' => $privacyMode === 'audit_ids_only' ? '' : (string) ($relationship->relationship_type ?? ''),
                    'suggested_fix' => 'Review relationship proposal in PLOS before applying.',
                    'state' => (string) ($relationship->status ?? ''),
                    'doc_notes' => '',
                ];
            }
        }

        return $rows;
    }

    private function reviewNoteRows(int $treeId, string $privacyMode, ?array $branchFilter = null): array
    {
        $notes = [];
        if (! Schema::hasTable('genealogy_research_tasks') || ! Schema::hasColumn('genealogy_research_tasks', 'tree_id')) {
            return $notes;
        }

        $query = DB::table('genealogy_research_tasks')
            ->where('tree_id', $treeId)
            ->whereIn('status', ['open', 'pending', 'in_progress'])
            ->orderBy('id')
            ->limit(1000);
        if ($branchFilter !== null) {
            $personIds = $this->scopedPersonIds($branchFilter);
            $familyIds = $this->scopedFamilyIds($branchFilter);
            $query->where(function ($scope) use ($personIds, $familyIds): void {
                $hasScope = false;
                if ($personIds !== [] && Schema::hasColumn('genealogy_research_tasks', 'person_id')) {
                    $scope->whereIn('person_id', $personIds);
                    $hasScope = true;
                }
                if ($familyIds !== [] && Schema::hasColumn('genealogy_research_tasks', 'family_id')) {
                    $hasScope
                        ? $scope->orWhereIn('family_id', $familyIds)
                        : $scope->whereIn('family_id', $familyIds);
                    $hasScope = true;
                }
                if (! $hasScope) {
                    $scope->whereRaw('1 = 0');
                }
            });
        }

        $tasks = $query->get();

        foreach ($tasks as $task) {
            $notes[] = [
                'note_id' => 'FT'.$treeId.'-NOTE-'.str_pad((string) $task->id, 8, '0', STR_PAD_LEFT),
                'parent_type' => 'research_task',
                'parent_id' => (int) $task->id,
                'note_type' => (string) ($task->task_type ?? 'research'),
                'note_text' => $privacyMode === 'audit_ids_only' ? '' : $this->cleanText($task->description ?? $task->title ?? ''),
                'state' => (string) ($task->status ?? ''),
                'linked_claim_id' => '',
            ];
        }

        return $notes;
    }

    private function buildManifest(
        object $tree,
        int $treeId,
        string $runId,
        string $generatedAt,
        string $privacyMode,
        string $format,
        string $actor,
        string $layoutProfile,
        array $dataset,
        array $outputPlan,
        bool $dryRun
    ): array {
        $rowCounts = [];
        foreach (self::CSV_FILES as $fileName) {
            $rowCounts[$fileName] = count($dataset[$fileName] ?? []);
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'tree_id' => $treeId,
            'tree_name' => (string) ($tree->name ?? ''),
            'tree_description' => $this->cleanText($tree->description ?? ''),
            'privacy_mode' => $privacyMode,
            'format' => $format,
            'layout_profile' => $layoutProfile,
            'prelabel_count' => (int) ($outputPlan['prelabel_count'] ?? 0),
            'shard_mode' => (string) ($outputPlan['shard_mode'] ?? 'none'),
            'branch_filter' => $outputPlan['branch_filter'] ?? null,
            'generated_at' => $generatedAt,
            'generated_by' => $actor,
            'dry_run' => $dryRun,
            'row_counts' => $rowCounts,
            'counts' => $rowCounts,
            'output_plan' => $outputPlan,
            'csv_files' => self::CSV_FILES,
            'warnings' => $this->manifestWarnings($dataset, $privacyMode, $format),
            'files' => [],
        ];
    }

    private function writePackage(string $format, array $dataset, array $manifest, array $outputPlan): array
    {
        $outputDir = (string) $outputPlan['output_dir'];
        $treeId = (int) ($outputPlan['tree_id'] ?? $manifest['tree_id'] ?? 0);
        $shardMode = (string) ($outputPlan['shard_mode'] ?? 'none');
        File::ensureDirectoryExists($outputDir, 0755, true);

        $csvStrings = $this->csvStrings($dataset);
        $written = [];
        $fileStem = (string) ($outputPlan['file_stem'] ?? 'source-audit');

        if ($format === 'manifest') {
            $manifestName = $fileStem.'-manifest.json';
            $manifestPath = $outputDir.'/'.$manifestName;
            $this->atomicWrite($manifestPath, $this->jsonEncode($manifest));
            $written[] = $this->fileInfo($manifestName, $manifestPath, $treeId);

            return $written;
        }

        if (in_array($format, ['docx', 'odt'], true)) {
            $documentDatasets = $shardMode === 'none'
                ? [['key' => 'all', 'label' => 'All', 'dataset' => $dataset]]
                : $this->shardDatasets($dataset, $shardMode);
            foreach ($documentDatasets as $documentDataset) {
                $suffix = $shardMode === 'none' ? '' : '-'.$documentDataset['key'];
                $documentName = $fileStem.'-workbook'.$suffix.'.'.$format;
                $documentPath = $outputDir.'/'.$documentName;
                $documentManifest = $manifest;
                $documentManifest['shard'] = [
                    'mode' => $shardMode,
                    'key' => $documentDataset['key'],
                    'label' => $documentDataset['label'],
                ];
                $this->writeWorkbookDocument($documentPath, $documentDataset['dataset'], $documentManifest, $format);
                $written[] = $this->fileInfo($documentName, $documentPath, $treeId);
            }

            $manifestWithFiles = $this->manifestWithWrittenFiles($manifest, $written);
            $manifestName = $fileStem.'-manifest.json';
            $manifestPath = $outputDir.'/'.$manifestName;
            $this->atomicWrite($manifestPath, $this->jsonEncode($manifestWithFiles));
            $written[] = $this->fileInfo($manifestName, $manifestPath, $treeId);

            return $written;
        }

        $csvDir = $outputDir.'/csv';
        File::ensureDirectoryExists($csvDir, 0755, true);
        foreach ($csvStrings as $fileName => $contents) {
            $path = $csvDir.'/'.$fileName;
            $this->atomicWrite($path, $contents);
            $written[] = $this->fileInfo('csv/'.$fileName, $path, $treeId);
        }

        if ($shardMode !== 'none') {
            foreach ($this->shardDatasets($dataset, $shardMode) as $shard) {
                $shardStrings = $this->csvStrings($shard['dataset']);
                foreach ($shardStrings as $fileName => $contents) {
                    $relativePath = 'shards/'.$shard['key'].'/'.$fileName;
                    $path = $csvDir.'/'.$relativePath;
                    File::ensureDirectoryExists(dirname($path), 0755, true);
                    $this->atomicWrite($path, $contents);
                    $written[] = $this->fileInfo('csv/'.$relativePath, $path, $treeId);
                    $csvStrings[$relativePath] = $contents;
                }
            }
        }

        $zipName = $fileStem.'-sheets.zip';
        $zipPath = $outputDir.'/'.$zipName;
        $this->writeZip($zipPath, $csvStrings);
        $written[] = $this->fileInfo($zipName, $zipPath, $treeId);

        $manifestWithFiles = $this->manifestWithWrittenFiles($manifest, $written);
        $manifestName = $fileStem.'-manifest.json';
        $manifestPath = $outputDir.'/'.$manifestName;
        $this->atomicWrite($manifestPath, $this->jsonEncode($manifestWithFiles));
        $written[] = $this->fileInfo($manifestName, $manifestPath, $treeId);

        return $written;
    }

    private function manifestWithWrittenFiles(array $manifest, array $written): array
    {
        $manifest['files'] = $written;

        return $manifest;
    }

    private function shardDatasets(array $dataset, string $shardMode): array
    {
        if ($shardMode === 'none') {
            return [['key' => 'all', 'label' => 'All', 'dataset' => $dataset]];
        }

        $personRowsByShard = [];
        foreach ($dataset['persons_all.csv'] ?? [] as $person) {
            $key = $this->surnameInitialShardKey($person);
            $personRowsByShard[$key][] = $person;
        }
        ksort($personRowsByShard);

        $shards = [];
        foreach ($personRowsByShard as $key => $personRows) {
            $personAuditIds = array_values(array_filter(array_map(
                static fn (array $row): string => (string) ($row['person_audit_id'] ?? ''),
                $personRows
            )));
            $personAuditIdSet = array_fill_keys($personAuditIds, true);

            $familyRows = array_values(array_filter(
                $dataset['families_all.csv'] ?? [],
                function (array $family) use ($personAuditIdSet): bool {
                    foreach ([
                        (string) ($family['husband_id'] ?? ''),
                        (string) ($family['wife_id'] ?? ''),
                    ] as $personId) {
                        if ($personId !== '' && isset($personAuditIdSet[$personId])) {
                            return true;
                        }
                    }
                    foreach ($this->splitAuditIds($family['child_ids'] ?? '') as $childId) {
                        if (isset($personAuditIdSet[$childId])) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
            $familyAuditIds = array_values(array_filter(array_map(
                static fn (array $row): string => (string) ($row['family_audit_id'] ?? ''),
                $familyRows
            )));
            $familyAuditIdSet = array_fill_keys($familyAuditIds, true);

            $claimRows = array_values(array_filter(
                $dataset['audit_claims.csv'] ?? [],
                static fn (array $claim): bool => isset($personAuditIdSet[(string) ($claim['person_id'] ?? '')])
                    || isset($familyAuditIdSet[(string) ($claim['family_id'] ?? '')])
            ));
            $sourceAuditIdSet = array_fill_keys(array_values(array_filter(array_map(
                static fn (array $claim): string => (string) ($claim['source_id'] ?? ''),
                $claimRows
            ))), true);
            $mediaAuditIdSet = array_fill_keys(array_values(array_filter(array_map(
                static fn (array $claim): string => (string) ($claim['media_id'] ?? ''),
                $claimRows
            ))), true);

            $shardDataset = $dataset;
            $shardDataset['persons_all.csv'] = $personRows;
            $shardDataset['families_all.csv'] = $familyRows;
            $shardDataset['audit_claims.csv'] = $claimRows;
            $shardDataset['sources_inventory.csv'] = array_values(array_filter(
                $dataset['sources_inventory.csv'] ?? [],
                static fn (array $source): bool => isset($sourceAuditIdSet[(string) ($source['source_audit_id'] ?? '')])
            ));
            $shardDataset['media_inventory.csv'] = array_values(array_filter(
                $dataset['media_inventory.csv'] ?? [],
                static fn (array $media): bool => isset($mediaAuditIdSet[(string) ($media['media_audit_id'] ?? '')])
            ));
            $shardDataset['prelabel_queue.csv'] = array_values(array_filter(
                $dataset['prelabel_queue.csv'] ?? [],
                static fn (array $row): bool => isset($personAuditIdSet[(string) ($row['person_audit_id'] ?? '')])
            ));
            $shardDataset['issue_index.csv'] = array_values(array_filter(
                $dataset['issue_index.csv'] ?? [],
                static fn (array $row): bool => isset($personAuditIdSet[(string) ($row['affected_id'] ?? '')])
                    || isset($familyAuditIdSet[(string) ($row['affected_id'] ?? '')])
            ));

            $shards[] = [
                'key' => $key,
                'label' => $key === 'other' ? 'Other/Unknown' : strtoupper($key),
                'dataset' => $shardDataset,
            ];
        }

        return $shards;
    }

    private function surnameInitialShardKey(array $person): string
    {
        $sortName = trim((string) ($person['sort_name'] ?? ''));
        $displayName = trim((string) ($person['display_name'] ?? ''));
        $candidate = $sortName !== '' && ! str_starts_with($sortName, '[') ? $sortName : $displayName;
        if (preg_match('/[A-Za-z]/', $candidate, $match) !== 1) {
            return 'other';
        }

        return strtolower($match[0]);
    }

    private function outputPlan(int $treeId, object $tree, string $runId, string $format, string $shardMode, ?array $branchFilter, int $prelabelCount): array
    {
        $treeRoot = rtrim($this->rootResolver->mediaRoot($treeId), '/');
        $dayPath = now()->format('Y/m/d');
        $outputDir = $treeRoot.'/reports/source-audit/'.$dayPath.'/'.$runId;

        return [
            'tree_id' => $treeId,
            'tree_root' => $treeRoot,
            'output_dir' => $outputDir,
            'file_stem' => 'ft'.$treeId.'-source-audit',
            'format' => $format,
            'shard_mode' => $shardMode,
            'branch_filter' => $branchFilter ? $this->branchFilterSummary($branchFilter) : null,
            'prelabel_count' => $prelabelCount,
            'will_write_manifest' => true,
            'will_write_csv_zip' => in_array($format, ['csv_zip', 'package'], true),
            'will_write_workbook' => in_array($format, ['docx', 'odt'], true),
        ];
    }

    private function toCsv(array $rows, array $headers): string
    {
        $handle = fopen('php://temp', 'r+');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to open temporary CSV stream.');
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $this->csvCell($row[$header] ?? '');
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }

    private function headersFor(string $fileName): array
    {
        return match ($fileName) {
            'persons_all.csv' => ['person_audit_id', 'person_id', 'person_print_id', 'person_url', 'document_tag', 'gedcom_id', 'sort_name', 'display_name', 'name_variants', 'sex', 'birth', 'death', 'living', 'parent_family_id', 'parent_family_print_id', 'spouse_family_ids', 'spouse_family_print_ids', 'source_count', 'media_count', 'citation_count', 'open_issue_count', 'review_status', 'doc_notes'],
            'families_all.csv' => ['family_audit_id', 'family_id', 'family_print_id', 'document_tag', 'gedcom_id', 'husband_id', 'husband_url', 'husband_name', 'wife_id', 'wife_url', 'wife_name', 'marriage', 'divorce', 'child_count', 'child_ids', 'child_print_ids', 'source_count', 'media_count', 'citation_count', 'open_issue_count', 'review_status', 'doc_notes'],
            'sources_inventory.csv' => ['source_audit_id', 'source_id', 'gedcom_id', 'title', 'repository', 'url', 'source_quality', 'information_quality', 'person_link_count', 'family_link_count', 'citation_count', 'media_count', 'rag_indexed_at', 'doc_notes'],
            'media_inventory.csv' => ['media_audit_id', 'media_id', 'gedcom_id', 'title', 'media_type', 'file_format', 'mime_type', 'file_size', 'path', 'source_ids', 'person_link_count', 'family_link_count', 'citation_count', 'ocr_status', 'htr_or_transcription', 'rag_indexed_at', 'doc_notes'],
            'audit_claims.csv' => ['claim_audit_id', 'citation_id', 'source_id', 'media_id', 'person_id', 'family_id', 'fact_type', 'page', 'quality', 'evidence_type', 'information_type', 'claim_excerpt', 'match_status', 'next_action', 'note_id'],
            'prelabel_queue.csv' => ['prelabel_id', 'pre_scan_document_id', 'person_audit_id', 'family_audit_id', 'source_audit_id', 'expected_fact_type', 'expected_date_place', 'paper_location', 'scan_batch_id', 'reviewer_action', 'reject_defer_reason'],
            'review_notes.csv' => ['note_id', 'parent_type', 'parent_id', 'note_type', 'note_text', 'state', 'linked_claim_id'],
            'issue_index.csv' => ['issue_id', 'severity', 'scope', 'affected_id', 'issue_class', 'description', 'suggested_fix', 'state', 'doc_notes'],
            'intake_log.csv' => ['intake_id', 'received_date', 'provider', 'source_ids', 'media_count', 'duplicate_rate', 'completeness_pct', 'prelabel_model_version', 'imported_by', 'validation_pass', 'notes'],
            default => [],
        };
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        File::put($tmp, $contents, true);
        rename($tmp, $path);
    }

    private function writeWorkbookDocument(string $path, array $dataset, array $manifest, string $format): void
    {
        if (! class_exists(PhpWord::class)) {
            throw new RuntimeException('PHPWord is not available.');
        }

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(6);
        $phpWord->addTableStyle('SourceAuditDense', [
            'borderColor' => 'B8C0CC',
            'borderSize' => 4,
            'cellMargin' => 25,
        ], [
            'bgColor' => 'E9EEF5',
        ]);

        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'marginTop' => Converter::inchToTwip(0.35),
            'marginBottom' => Converter::inchToTwip(0.35),
            'marginLeft' => Converter::inchToTwip(0.35),
            'marginRight' => Converter::inchToTwip(0.35),
        ]);

        $section->addText(
            'Family Tree Source Audit Workbook',
            ['bold' => true, 'size' => 12],
            ['spaceAfter' => 20, 'spaceBefore' => 0, 'lineHeight' => 1.0]
        );
        $section->addText(
            implode(' | ', array_filter([
                'Tree ID '.(string) ($manifest['tree_id'] ?? ''),
                $this->cleanText($manifest['tree_name'] ?? 'Family Tree'),
                'Privacy '.$this->cleanText($manifest['privacy_mode'] ?? ''),
                isset($manifest['shard']['label']) ? 'Shard '.$this->cleanText($manifest['shard']['label']) : '',
                'Generated '.$this->cleanText($manifest['generated_at'] ?? ''),
            ])),
            ['size' => 6],
            ['spaceAfter' => 20, 'spaceBefore' => 0, 'lineHeight' => 1.0]
        );
        $treeDescription = $this->cleanText($manifest['tree_description'] ?? '');
        if ($treeDescription !== '') {
            $section->addText($treeDescription, ['size' => 6], ['spaceAfter' => 60, 'spaceBefore' => 0, 'lineHeight' => 1.0]);
        }

        $this->addWorkbookFamilyUnits($section, $dataset);
        if (($dataset['prelabel_queue.csv'] ?? []) !== []) {
            $section->addPageBreak();
            $this->addWorkbookTable($section, 'Pre-Scan Document ID Reservations', $dataset['prelabel_queue.csv'] ?? [], [
                'pre_scan_document_id' => 'Doc ID',
                'person_audit_id' => 'Person',
                'family_audit_id' => 'Family',
                'source_audit_id' => 'Source',
                'expected_fact_type' => 'Fact',
                'expected_date_place' => 'Date/Place',
                'paper_location' => 'Paper Location',
                'reviewer_action' => 'Action',
                'reject_defer_reason' => 'Reason',
            ]);
        }
        $section->addPageBreak();
        $this->addWorkbookTable($section, 'All Persons', $dataset['persons_all.csv'] ?? [], [
            'person_print_id' => 'ID',
            'document_tag' => 'Doc Tag',
            'person_url' => 'Link',
            'display_name' => 'Name',
            'name_variants' => 'Names',
            'birth' => 'Birth',
            'death' => 'Death',
            'parent_family_print_id' => 'Parents',
            'spouse_family_print_ids' => 'Spouse Families',
            'source_count' => 'Src',
            'media_count' => 'Med',
            'citation_count' => 'Cit',
            'doc_notes' => 'Notes',
        ], 'person_sort_group');

        $writerType = $format === 'odt' ? 'ODText' : 'Word2007';
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        $previousOutputEscaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);
        try {
            IOFactory::createWriter($phpWord, $writerType)->save($tmp);
        } finally {
            Settings::setOutputEscapingEnabled($previousOutputEscaping);
        }
        rename($tmp, $path);
    }

    private function addWorkbookFamilyUnits(object $section, array $dataset): void
    {
        $personNamesByPrintId = [];
        foreach ($dataset['persons_all.csv'] ?? [] as $person) {
            $printId = (string) ($person['person_print_id'] ?? '');
            $name = trim((string) ($person['display_name'] ?? ''));
            if ($printId !== '' && $name !== '') {
                $personNamesByPrintId[$printId] = $name;
            }
        }

        $rows = [];
        foreach ($dataset['families_all.csv'] ?? [] as $family) {
            $childIds = $this->splitAuditIds($family['child_print_ids'] ?? '');
            $children = array_map(
                fn (string $childId): string => trim($childId.' '.($personNamesByPrintId[$childId] ?? '')),
                $childIds
            );

            $rows[] = [
                'family_print_id' => $family['family_print_id'] ?? '',
                'document_tag' => $family['document_tag'] ?? '',
                'family_sort_group' => $family['family_sort_group'] ?? '',
                'couple' => trim((string) ($family['husband_name'] ?? '').' + '.(string) ($family['wife_name'] ?? ''), ' +'),
                'marriage' => $family['marriage'] ?? '',
                'children' => implode('; ', $children),
                'evidence' => 'S '.$family['source_count'].' / M '.$family['media_count'].' / C '.$family['citation_count'],
                'doc_notes' => '',
            ];
        }

        $this->addWorkbookTable($section, 'Family Units', $rows, [
            'family_print_id' => 'Family',
            'document_tag' => 'Doc Tag',
            'couple' => 'Couple',
            'marriage' => 'Marriage',
            'children' => 'Children',
            'evidence' => 'Evidence',
            'doc_notes' => 'Notes',
        ], 'family_sort_group');
    }

    private function reviewPacketForWorkbookTarget(int $treeId, array $target): array
    {
        $recordType = (string) ($target['record_type'] ?? '');
        $recordId = (int) ($target['record_id'] ?? 0);
        $tag = (string) ($target['tag'] ?? '');
        $label = trim((string) ($target['label'] ?? ''));
        $personId = isset($target['person_id']) ? (int) $target['person_id'] : null;
        $familyId = isset($target['family_id']) ? (int) $target['family_id'] : null;

        if (! in_array($recordType, ['person', 'family'], true) || $recordId <= 0 || $tag === '') {
            return $this->error('workbook_target_invalid');
        }

        if ($recordType === 'family' && (! $personId || $personId <= 0)) {
            return $this->error('family_tag_has_no_person_context');
        }

        $sourceLocator = sprintf('source-audit-workbook:tree:%d:%s:%d', $treeId, $recordType, $recordId);
        $targetLabel = $label !== '' ? $label : ucfirst($recordType).' '.$recordId;
        $claimText = sprintf('Review source-audit workbook row %s for %s.', $tag, $targetLabel);
        $packetKey = sprintf('source-audit-workbook:%d:%s:%d', $treeId, $recordType, $recordId);

        $packet = [
            'packet_key' => $packetKey,
            'packet_label' => sprintf('Source audit row %s - %s', $tag, $targetLabel),
            'summary' => $claimText,
            'tree_id' => $treeId,
            'target_record_type' => $recordType,
            'target_record_id' => $recordId,
            'target_person_id' => $personId,
            'person_id' => $personId,
            'target_family_id' => $familyId,
            'source_locator' => $sourceLocator,
            'source_locators' => [$sourceLocator],
            'sources' => [[
                'locator' => $sourceLocator,
                'type' => 'source_audit_workbook_row',
                'workbook_tag' => $tag,
                'record_type' => $recordType,
                'record_id' => $recordId,
            ]],
            'claims' => [[
                'claim_text' => $claimText,
                'field_name' => $recordType === 'family' ? 'family_source_audit_review' : 'person_source_audit_review',
                'change_type' => 'review_packet_create',
                'person_id' => $personId,
                'source_ref' => $sourceLocator,
            ]],
            'identity' => [
                'person_id' => $personId,
                'target_person_id' => $personId,
                'family_id' => $familyId,
                'target_family_id' => $familyId,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'record_label' => $targetLabel,
                'resolved' => true,
                'status' => 'resolved',
            ],
            'privacy' => [
                'status' => 'cleared',
                'cleared' => true,
                'scope' => 'private_local_operator_review',
            ],
            'sprint' => [
                'boundary_label' => 'source_audit_workbook',
                'workbook_tag' => $tag,
            ],
            'workbook_target' => $target,
        ];

        return [
            'success' => true,
            'packet' => $packet,
        ];
    }

    private function addWorkbookTable(object $section, string $title, array $rows, array $columns, ?string $groupColumn = null): void
    {
        $compactParagraph = ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1.0];
        $groupParagraph = ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1.0, 'alignment' => 'right'];
        $section->addText($title, ['bold' => true, 'size' => 9], ['spaceBefore' => 40, 'spaceAfter' => 20, 'lineHeight' => 1.0]);
        if ($rows === []) {
            $section->addText('No rows.', ['italic' => true, 'size' => 6], $compactParagraph);

            return;
        }

        $table = $section->addTable('SourceAuditDense');
        $table->addRow(180);
        foreach ($columns as $label) {
            $table->addCell(1200, ['bgColor' => 'E9EEF5'])->addText((string) $label, ['bold' => true, 'size' => 5], $compactParagraph);
        }

        $currentGroup = null;
        foreach ($rows as $row) {
            if ($groupColumn !== null) {
                $groupLabel = trim((string) ($row[$groupColumn] ?? ''));
                if ($groupLabel !== '' && $groupLabel !== $currentGroup) {
                    $currentGroup = $groupLabel;
                    $table->addRow(160, ['cantSplit' => true]);
                    $columnKeys = array_keys($columns);
                    $lastIndex = count($columnKeys) - 1;
                    foreach ($columnKeys as $index => $columnKey) {
                        $table->addCell($columnKey === 'doc_notes' ? 1800 : 1200, [
                            'bgColor' => 'E7ECE2',
                            'valign' => 'center',
                        ])->addText($index === $lastIndex ? $groupLabel : ' ', ['bold' => true, 'size' => 5], $groupParagraph);
                    }
                }
            }
            $table->addRow(180, ['cantSplit' => true]);
            foreach ($columns as $key => $label) {
                $text = $this->cleanText($row[$key] ?? '');
                $table->addCell($key === 'doc_notes' ? 1800 : 1200, ['valign' => 'top'])
                    ->addText($text === '' ? ' ' : $text, ['size' => 5], $compactParagraph);
            }
        }
    }

    private function splitAuditIds(mixed $value): array
    {
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(';', $text)),
            static fn (string $id): bool => $id !== ''
        ));
    }

    private function writeZip(string $path, array $files): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is not available.');
        }

        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create source-audit zip.');
        }

        ksort($files);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        rename($tmp, $path);
    }

    public function resolveReportDownloadPath(int $treeId, string $path): string
    {
        $treeRoot = realpath(rtrim($this->rootResolver->mediaRoot($treeId), '/'));
        if ($treeRoot === false) {
            throw new RuntimeException("Tree root is not available for tree {$treeId}.");
        }

        $candidate = str_starts_with($path, '/') ? $path : $treeRoot.'/'.ltrim($path, '/');
        $realPath = realpath($candidate);
        $reportsRoot = realpath($treeRoot.'/reports/source-audit');
        if ($realPath === false || $reportsRoot === false || ! is_file($realPath)) {
            throw new RuntimeException('Source-audit report file was not found.');
        }

        $reportsPrefix = rtrim($reportsRoot, '/').'/';
        if ($realPath !== $reportsRoot && ! str_starts_with($realPath, $reportsPrefix)) {
            throw new RuntimeException('Report download path is outside the source-audit report folder.');
        }

        return $realPath;
    }

    private function fileInfo(string $relativePath, string $path, int $treeId = 0): array
    {
        $info = [
            'relative_path' => $relativePath,
            'path' => $path,
            'size_bytes' => File::size($path),
            'sha256' => hash_file('sha256', $path),
        ];
        if ($treeId > 0) {
            $info['download_url'] = url('/api/genealogy/trees/'.$treeId.'/reports/source-audit-workbook/download')
                .'?path='.rawurlencode($path);
        }

        return $info;
    }

    private function rowsForIds(string $table, string $column, array $ids, array $columns): array
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        $selectedColumns = array_values(array_filter(
            $columns,
            static fn (string $candidate): bool => Schema::hasColumn($table, $candidate)
        ));
        if ($selectedColumns === [] || ! in_array($column, $selectedColumns, true)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->select($selectedColumns)
            ->get()
            ->all();
    }

    private function countRowsForIds(string $table, string $column, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        $counts = [];
        foreach (DB::table($table)
            ->whereIn($column, $ids)
            ->select($column, DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy($column)
            ->get() as $row) {
            $counts[(int) $row->{$column}] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    private function pendingIssueCountsByPerson(int $treeId): array
    {
        if (! Schema::hasTable('genealogy_proposed_changes')
            || ! Schema::hasColumn('genealogy_proposed_changes', 'tree_id')
            || ! Schema::hasColumn('genealogy_proposed_changes', 'person_id')) {
            return [];
        }

        $counts = [];
        $query = DB::table('genealogy_proposed_changes')
            ->where('tree_id', $treeId)
            ->whereNotNull('person_id')
            ->select('person_id', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('person_id');
        if (Schema::hasColumn('genealogy_proposed_changes', 'status')) {
            $query->whereIn('status', ['pending', 'pending_review', 'approved']);
        }

        foreach ($query->get() as $row) {
            $counts[(int) $row->person_id] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    private function pendingIssueCountsByFamily(int $treeId): array
    {
        if (! Schema::hasTable('genealogy_proposed_relationships')
            || ! Schema::hasColumn('genealogy_proposed_relationships', 'tree_id')
            || ! Schema::hasColumn('genealogy_proposed_relationships', 'applied_family_id')) {
            return [];
        }

        $counts = [];
        $query = DB::table('genealogy_proposed_relationships')
            ->where('tree_id', $treeId)
            ->whereNotNull('applied_family_id')
            ->select('applied_family_id', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('applied_family_id');
        if (Schema::hasColumn('genealogy_proposed_relationships', 'status')) {
            $query->whereIn('status', ['pending', 'pending_review', 'approved']);
        }

        foreach ($query->get() as $row) {
            $counts[(int) $row->applied_family_id] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    private function citationMediaCountsBySource(array $sourceIds): array
    {
        if ($sourceIds === [] || ! Schema::hasTable('genealogy_citations')) {
            return [];
        }

        $counts = [];
        foreach (DB::table('genealogy_citations')
            ->whereIn('source_id', $sourceIds)
            ->whereNotNull('media_id')
            ->select('source_id', DB::raw('COUNT(DISTINCT media_id) as aggregate_count'))
            ->groupBy('source_id')
            ->get() as $row) {
            $counts[(int) $row->source_id] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    private function sourceIdsByMedia(array $mediaIds): array
    {
        if ($mediaIds === [] || ! Schema::hasTable('genealogy_citations')) {
            return [];
        }

        $ids = [];
        foreach (DB::table('genealogy_citations')
            ->whereIn('media_id', $mediaIds)
            ->whereNotNull('source_id')
            ->select('media_id', 'source_id')
            ->distinct()
            ->get() as $row) {
            $ids[(int) $row->media_id][] = (int) $row->source_id;
        }

        return $ids;
    }

    private function personIsPrivate(object $person, string $privacyMode): bool
    {
        if ($privacyMode === 'private_local') {
            return false;
        }

        return (int) ($person->living ?? 0) === 1 || (string) ($person->privacy_override ?? '') === 'private';
    }

    private function formatPersonName(object $person): string
    {
        return trim(implode(' ', array_filter([
            (string) ($person->given_name ?? ''),
            (string) ($person->surname ?? ''),
            (string) ($person->suffix ?? ''),
        ], static fn (string $part): bool => trim($part) !== '')));
    }

    private function formatPersonNameWithYears(object $person): string
    {
        $name = $this->formatPersonName($person);
        $years = $this->lifeYears($person);

        return trim($name.($years !== '' ? ' '.$years : ''));
    }

    private function lifeYears(object $person): string
    {
        $birthYear = $this->extractYear($person->birth_date ?? null);
        $deathYear = $this->extractYear($person->death_date ?? null);
        if ($birthYear === '' && $deathYear === '') {
            return '';
        }

        return $birthYear.'-'.$deathYear;
    }

    private function extractYear(mixed $date): string
    {
        $date = (string) ($date ?? '');
        if (preg_match('/\b(1[0-9]{3}|20[0-9]{2}|[0-9]{3})\b/', $date, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    private function compactNameVariant(object $variant): string
    {
        $fullName = trim((string) ($variant->full_name ?? ''));
        if ($fullName === '') {
            $fullName = trim((string) ($variant->given_names ?? '').' '.(string) ($variant->surname ?? ''));
        }

        $type = trim((string) ($variant->name_type ?? ''));

        return $fullName !== '' ? ($type !== '' ? "{$fullName} ({$type})" : $fullName) : '';
    }

    private function datePlace(mixed $date, mixed $place): string
    {
        $date = trim((string) ($date ?? ''));
        $place = trim($this->cleanText($place ?? ''));

        return trim($date.($date !== '' && $place !== '' ? ' - ' : '').$place);
    }

    private function auditId(int $treeId, string $prefix, int $id): string
    {
        return 'FT'.$treeId.'-'.$prefix.str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    }

    private function documentTag(mixed $gedcomId, string $fallbackPrefix, int $id): string
    {
        $tag = $this->recordDisplayId($gedcomId, $fallbackPrefix, $id);

        return $tag !== '' ? '#'.$tag.'#' : '';
    }

    private function recordDisplayId(mixed $gedcomId, string $fallbackPrefix, int $id): string
    {
        $tag = trim((string) ($gedcomId ?? ''));
        $tag = trim($tag, "#@ \t\n\r\0\x0B");
        if (preg_match('/^([A-Za-z])0*([0-9]{1,6})$/', $tag, $matches) === 1
            && Str::upper($matches[1]) === Str::upper($fallbackPrefix)
        ) {
            return Str::upper($fallbackPrefix).((int) $matches[2]);
        }

        return Str::upper($fallbackPrefix).$id;
    }

    private function displayIdList(array $displayIdsById, array $ids, string $fallbackPrefix): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return implode('; ', array_map(
            fn (int $id): string => (string) ($displayIdsById[$id] ?? $fallbackPrefix.$id),
            $ids
        ));
    }

    private function auditIdList(int $treeId, string $prefix, array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return implode('; ', array_map(fn (int $id): string => $this->auditId($treeId, $prefix, $id), $ids));
    }

    private function reviewStatus(int $issueCount, int $sourceCount): string
    {
        if ($issueCount > 0) {
            return 'review';
        }

        return $sourceCount > 0 ? 'sourced' : 'needs_source';
    }

    private function cleanText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text;
    }

    private function csvCell(mixed $value): string
    {
        $text = $this->cleanText($value);
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
            return "'".$text;
        }

        return $text;
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if ($format === 'csv' || $format === 'zip') {
            $format = 'csv_zip';
        }
        if (in_array($format, ['word', 'word_doc', 'doc'], true)) {
            $format = 'docx';
        }
        if (in_array($format, ['openoffice', 'odf'], true)) {
            $format = 'odt';
        }
        if (! in_array($format, self::FORMATS, true)) {
            throw new RuntimeException('Unsupported source-audit format: '.$format);
        }

        return $format;
    }

    private function normalizePrivacyMode(string $privacyMode): string
    {
        $privacyMode = strtolower(trim($privacyMode));
        if ($privacyMode === 'public_export') {
            $privacyMode = 'public_redacted';
        }
        if (! in_array($privacyMode, self::PRIVACY_MODES, true)) {
            throw new RuntimeException('Unsupported source-audit privacy mode: '.$privacyMode);
        }

        return $privacyMode;
    }

    private function normalizePrelabelCount(int $prelabelCount): int
    {
        if ($prelabelCount < 0) {
            throw new RuntimeException('prelabel_count must be zero or greater.');
        }
        if ($prelabelCount > self::MAX_PRELABEL_RESERVATIONS) {
            throw new RuntimeException('prelabel_count may not exceed '.self::MAX_PRELABEL_RESERVATIONS.'.');
        }

        return $prelabelCount;
    }

    private function normalizeShardMode(string $shardMode): string
    {
        $shardMode = strtolower(trim($shardMode));
        if (in_array($shardMode, ['', 'off', 'false', 'no'], true)) {
            $shardMode = 'none';
        }
        if (in_array($shardMode, ['surname', 'initial', 'surname-initial'], true)) {
            $shardMode = 'surname_initial';
        }
        if (! in_array($shardMode, self::SHARD_MODES, true)) {
            throw new RuntimeException('Unsupported source-audit shard mode: '.$shardMode);
        }

        return $shardMode;
    }

    private function normalizeBranchMode(string $branchMode): string
    {
        $branchMode = strtolower(trim($branchMode));
        if ($branchMode === '') {
            $branchMode = 'descendants';
        }
        if (! in_array($branchMode, self::BRANCH_MODES, true)) {
            throw new RuntimeException('Unsupported source-audit branch mode: '.$branchMode);
        }

        return $branchMode;
    }

    private function buildRunId(int $treeId): string
    {
        return 'FT'.$treeId.'-SOURCE-AUDIT-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(6));
    }

    private function runStamp(string $runId): string
    {
        if (preg_match('/SOURCE-AUDIT-([0-9]{8}-[0-9]{6})/', $runId, $matches) === 1) {
            return $matches[1];
        }

        return now()->format('Ymd-His');
    }

    private function jsonEncode(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode source-audit manifest.');
        }

        return $json."\n";
    }

    private function manifestWarnings(array $dataset, string $privacyMode, string $format): array
    {
        $warnings = [];
        if ($privacyMode === 'private_local') {
            $warnings[] = 'private_local includes living/private tree details; do not commit generated outputs to the public repo.';
        }
        if ($format === 'manifest') {
            $warnings[] = 'manifest format does not include the CSV audit sheets.';
        }
        if (count($dataset['persons_all.csv'] ?? []) > 2000) {
            $warnings[] = 'large_tree: prefer CSV package first; ODT/DOCX rendering may need sharding.';
        }

        return $warnings;
    }

    private function error(string $message): array
    {
        return [
            'tool' => 'source_audit_workbook',
            'success' => false,
            'error' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
