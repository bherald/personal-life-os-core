<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyHealthAuditService
{
    private const REPORT_VERSION = 1;

    private const DEFAULT_SECTIONS = ['links', 'dates', 'conflicts', 'media', 'rag', 'citations', 'duplicates', 'export'];

    private const PERFORMANCE_BUDGET = [
        'hard_sample_limit' => 200,
        'recommended_interactive_sample_limit' => 50,
        'recommended_review_packet_sample_limit' => 25,
        'max_interactive_sections' => 4,
        'max_interactive_query_groups' => 24,
        'interactive_target_seconds' => 15,
        'scheduled_target_seconds' => 120,
    ];

    private const SECTION_QUERY_GROUPS = [
        'links' => 8,
        'dates' => 3,
        'conflicts' => 3,
        'media' => 8,
        'rag' => 3,
        'citations' => 2,
        'duplicates' => 2,
        'export' => 3,
    ];

    private GenealogyTreeRootResolver $rootResolver;

    private GenealogyExportPrivacyPolicyService $privacyPolicyService;

    public function __construct(
        GenealogyTreeRootResolver $rootResolver,
        ?GenealogyExportPrivacyPolicyService $privacyPolicyService = null
    ) {
        $this->rootResolver = $rootResolver;
        $this->privacyPolicyService = $privacyPolicyService ?: app(GenealogyExportPrivacyPolicyService::class);
    }

    public function collect(int $treeId, ?string $root, int $limit, array $sections = [], bool $dryRun = false): array
    {
        $limit = max(1, min(200, $limit));
        $sections = $this->normalizeSections($sections);
        $root = $this->rootResolver->mediaRoot($treeId, $root, inferFromMedia: ! $dryRun);

        $payload = [
            'version' => self::REPORT_VERSION,
            'command' => 'genealogy:health-audit',
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'tree_id' => $treeId,
            'root' => $root,
            'root_hash' => substr(sha1($root), 0, 16),
            'limit' => $limit,
            'sections' => $sections,
            'performance_budget' => $this->performanceBudget($treeId, $sections, $limit, $dryRun),
            'status' => $dryRun ? 'dry_run' : 'observe_ok',
            'summary' => $this->blankSummary(),
            'issue_schema' => $this->issueSchema(),
            'issues' => [],
            'next_actions' => [],
            'posture' => $this->posture(pathsIncluded: true),
        ];

        if ($dryRun) {
            $payload['summary']['query_would_run'] = true;
            $payload['next_actions'] = $this->dryRunActions();

            return $payload;
        }

        if (! Schema::hasTable('genealogy_persons')) {
            $payload['status'] = 'schema_missing';
            $payload['missing_tables'] = ['genealogy_persons'];
            $payload['next_actions'] = [[
                'code' => 'schema_missing',
                'label' => 'genealogy_persons is unavailable in this environment.',
                'write_required' => false,
            ]];

            return $payload;
        }

        $payload['summary'] = $this->summary($treeId);
        $issues = [];

        if (in_array('links', $sections, true)) {
            array_push($issues, ...$this->linkIssues($treeId, $limit));
        }

        if (in_array('dates', $sections, true)) {
            array_push($issues, ...$this->dateIssues($treeId, $limit));
        }

        if (in_array('conflicts', $sections, true)) {
            array_push($issues, ...$this->conflictIssues($treeId, $limit));
        }

        if (in_array('media', $sections, true)) {
            array_push($issues, ...$this->mediaIssues($treeId, $limit));
        }

        if (in_array('rag', $sections, true)) {
            array_push($issues, ...$this->ragIssues($treeId, $limit));
        }

        if (in_array('citations', $sections, true)) {
            array_push($issues, ...$this->citationIssues($treeId, $limit));
        }

        if (in_array('duplicates', $sections, true)) {
            array_push($issues, ...$this->duplicateIssues($treeId, $limit));
        }

        if (in_array('export', $sections, true)) {
            array_push($issues, ...$this->exportIssues($treeId, $root, $limit));
        }

        $payload['issues'] = array_map(
            fn (array $issue): array => $this->attachIssueIdentity($treeId, $issue),
            array_values(array_filter($issues))
        );
        $payload['summary']['issue_count'] = count($payload['issues']);
        $payload['summary']['issue_rows'] = array_sum(array_map(static fn (array $issue): int => (int) ($issue['count'] ?? 0), $payload['issues']));
        $payload['summary']['severity_counts'] = $this->severityCounts($payload['issues']);
        $payload['next_actions'] = $this->nextActions($payload['issues']);

        return $payload;
    }

    public function compactPayload(array $payload): array
    {
        unset($payload['root']);

        $payload['compact'] = true;
        $payload['posture'] = $this->posture(pathsIncluded: false);
        $payload['issues'] = array_map(static function (array $issue): array {
            $issue['sample_count'] = count($issue['samples'] ?? []);
            unset($issue['samples']);

            return $issue;
        }, $payload['issues'] ?? []);
        if (isset($payload['performance_budget']['section_budgets'])) {
            $payload['performance_budget']['section_budget_count'] = count($payload['performance_budget']['section_budgets']);
            unset($payload['performance_budget']['section_budgets']);
        }

        return $payload;
    }

    public function issueSchema(): array
    {
        return [
            'version' => self::REPORT_VERSION,
            'required_fields' => [
                'issue_id',
                'code',
                'title',
                'section',
                'severity',
                'confidence',
                'entity_type',
                'entity_id',
                'review_target',
                'provenance',
                'count',
                'safe_auto_fix',
                'auto_fix_policy',
                'suggested_fix',
                'samples',
            ],
            'sections' => self::DEFAULT_SECTIONS,
            'severity_values' => ['critical', 'high', 'medium', 'low', 'info'],
            'confidence_values' => ['strong', 'medium', 'weak', 'missing'],
            'sample_policy' => 'Samples are evidence pointers for review packets; they are not write instructions.',
            'write_policy' => 'Default safe_auto_fix=false. Writes must use proposal/review tools unless a separate deterministic repair tool explicitly supports dry-run and rollback.',
            'auto_fix_policy_fields' => [
                'mode',
                'default_action',
                'dry_run_required',
                'confirmation_required',
                'allowed_tool',
                'safe_auto_fix',
                'rollback_required',
            ],
        ];
    }

    public function reviewPackets(array $payload, int $issueLimit = 20): array
    {
        $issueLimit = max(1, min(100, $issueLimit));
        $treeId = (int) ($payload['tree_id'] ?? 0);
        $packets = [];

        foreach (array_slice($payload['issues'] ?? [], 0, $issueLimit) as $issue) {
            $samples = array_slice($issue['samples'] ?? [], 0, (int) ($payload['limit'] ?? 25));
            $code = (string) ($issue['code'] ?? 'unknown_issue');
            $issueId = (string) ($issue['issue_id'] ?? $this->issueId($treeId, (string) ($issue['section'] ?? 'unknown'), $code));
            $packets[] = [
                'packet_id' => substr(sha1($issueId), 0, 16),
                'schema_version' => self::REPORT_VERSION,
                'tree_id' => $treeId,
                'issue_id' => $issueId,
                'review_target' => $issue['review_target'] ?? null,
                'issue' => [
                    'issue_id' => $issueId,
                    'code' => $code,
                    'title' => $issue['title'] ?? null,
                    'section' => $issue['section'] ?? null,
                    'severity' => $issue['severity'] ?? null,
                    'confidence' => $issue['confidence'] ?? null,
                    'entity_type' => $issue['entity_type'] ?? 'genealogy_tree',
                    'entity_id' => $issue['entity_id'] ?? $treeId,
                    'review_target' => $issue['review_target'] ?? null,
                    'provenance' => $issue['provenance'] ?? null,
                    'count' => (int) ($issue['count'] ?? 0),
                    'safe_auto_fix' => (bool) ($issue['safe_auto_fix'] ?? false),
                    'auto_fix_policy' => $issue['auto_fix_policy'] ?? $this->autoFixPolicyForIssue($code),
                    'suggested_fix' => $issue['suggested_fix'] ?? null,
                ],
                'samples' => $samples,
                'sample_count' => count($samples),
                'review_policy' => [
                    'status' => 'needs_review',
                    'writes_allowed' => false,
                    'default_action' => 'review_evidence_then_route_to_proposal_or_deterministic_repair_tool',
                    'auto_fix_policy' => (bool) ($issue['safe_auto_fix'] ?? false)
                        ? 'only_with_dedicated_dry_run_tool_and_confirmation'
                        : 'proposal_or_manual_review_required',
                ],
                'recommended_tools' => $this->reviewPacketToolsForIssue($code),
            ];
        }

        return $packets;
    }

    public function toText(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $lines = [
            'Genealogy health audit',
            'Status: '.($payload['status'] ?? 'unknown').' | Tree: '.($payload['tree_id'] ?? 'unknown').' | Read-only: yes',
            'Records: people='.($summary['persons'] ?? 0)
                .' families='.($summary['families'] ?? 0)
                .' children='.($summary['children'] ?? 0)
                .' media='.($summary['media'] ?? 0),
            'Issues: '.($summary['issue_count'] ?? 0).' categories, '.($summary['issue_rows'] ?? 0).' affected rows',
            'Posture: no writes, no deletes, no link changes, no privacy/export release.',
        ];

        foreach ($payload['issues'] ?? [] as $issue) {
            $lines[] = sprintf(
                '  - %s [%s/%s]: %d — %s',
                $issue['code'] ?? 'issue',
                $issue['severity'] ?? 'info',
                $issue['confidence'] ?? 'unknown',
                (int) ($issue['count'] ?? 0),
                $issue['title'] ?? ''
            );
        }

        if (! empty($payload['next_actions'])) {
            $lines[] = 'Next actions:';
            foreach ($payload['next_actions'] as $action) {
                $lines[] = '  - '.($action['code'] ?? 'action').': '.($action['label'] ?? '');
            }
        }

        return implode(PHP_EOL, $lines);
    }

    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $lines = [
            '# Genealogy Health Audit',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Tree: `'.($payload['tree_id'] ?? 'unknown').'`',
            '- Read-only: `true`',
            '- Mutation allowed: `false`',
            '',
            '## Summary',
            '',
            '| Metric | Value |',
            '|---|---:|',
            '| People | '.($summary['persons'] ?? 0).' |',
            '| Families | '.($summary['families'] ?? 0).' |',
            '| Children links | '.($summary['children'] ?? 0).' |',
            '| Media | '.($summary['media'] ?? 0).' |',
            '| Issue categories | '.($summary['issue_count'] ?? 0).' |',
            '| Affected rows | '.($summary['issue_rows'] ?? 0).' |',
            '',
            '## Issues',
            '',
        ];

        foreach ($payload['issues'] ?? [] as $issue) {
            $lines[] = sprintf(
                '- `%s` `%s/%s` %d: %s',
                $issue['code'] ?? 'issue',
                $issue['severity'] ?? 'info',
                $issue['confidence'] ?? 'unknown',
                (int) ($issue['count'] ?? 0),
                $issue['title'] ?? ''
            );
        }

        $lines[] = '';
        $lines[] = 'Posture: no writes, no deletes, no link changes, no privacy/export release.';

        return implode(PHP_EOL, $lines);
    }

    private function linkIssues(int $treeId, int $limit): array
    {
        $issues = [];

        if ($this->hasTables(['genealogy_families', 'genealogy_persons'])) {
            $sql = '
                SELECT f.id AS family_id, f.husband_id, hp.tree_id AS husband_tree_id, f.wife_id, wp.tree_id AS wife_tree_id
                FROM genealogy_families f
                LEFT JOIN genealogy_persons hp ON hp.id = f.husband_id
                LEFT JOIN genealogy_persons wp ON wp.id = f.wife_id
                WHERE f.tree_id = ?
                  AND (
                    (f.husband_id IS NOT NULL AND (hp.id IS NULL OR hp.tree_id <> f.tree_id))
                    OR (f.wife_id IS NOT NULL AND (wp.id IS NULL OR wp.tree_id <> f.tree_id))
                  )
            ';
            $issues[] = $this->issueFromSql(
                'broken_family_spouse_links',
                'Family spouse links point to missing people or people outside this tree.',
                'links',
                'high',
                'strong',
                $sql,
                [$treeId],
                $limit,
                'Review each family spouse pointer and replace or clear it through the proposal/review path.'
            );

            $issues[] = $this->issueFromSql(
                'self_spouse_family_links',
                'A family has the same person recorded as both spouses.',
                'links',
                'high',
                'strong',
                '
                    SELECT id AS family_id, husband_id, wife_id
                    FROM genealogy_families
                    WHERE tree_id = ?
                      AND husband_id IS NOT NULL
                      AND husband_id = wife_id
                ',
                [$treeId],
                $limit,
                'Review the family and replace the incorrect spouse pointer through the proposal/review path.'
            );
        }

        if ($this->hasTables(['genealogy_children', 'genealogy_families', 'genealogy_persons'])) {
            $sql = '
                SELECT c.id AS child_link_id, c.family_id, c.person_id, f.tree_id AS family_tree_id, p.tree_id AS person_tree_id
                FROM genealogy_children c
                LEFT JOIN genealogy_families f ON f.id = c.family_id
                LEFT JOIN genealogy_persons p ON p.id = c.person_id
                WHERE COALESCE(f.tree_id, p.tree_id) = ?
                  AND (f.id IS NULL OR p.id IS NULL OR p.tree_id <> f.tree_id)
            ';
            $issues[] = $this->issueFromSql(
                'broken_child_links',
                'Child rows point to missing families, missing people, or people outside the family tree.',
                'links',
                'high',
                'strong',
                $sql,
                [$treeId],
                $limit,
                'Review child rows and repair parent-family membership with provenance.'
            );

            $issues[] = $this->issueFromSql(
                'self_child_links',
                'A person is linked as a child in a family where they are also a parent/spouse.',
                'links',
                'high',
                'strong',
                '
                    SELECT c.id AS child_link_id,
                           c.family_id,
                           c.person_id,
                           f.husband_id,
                           f.wife_id
                    FROM genealogy_children c
                    JOIN genealogy_families f ON f.id = c.family_id
                    WHERE f.tree_id = ?
                      AND (c.person_id = f.husband_id OR c.person_id = f.wife_id)
                ',
                [$treeId],
                $limit,
                'Review the parent-family link and remove or move the child row through the proposal/review path.'
            );

            $issues[] = $this->issueFromSql(
                'duplicate_child_family_links',
                'The same person appears more than once as a child in the same family.',
                'links',
                'medium',
                'strong',
                '
                    SELECT MIN(c.id) AS first_child_link_id,
                           c.family_id,
                           c.person_id,
                           COUNT(*) AS duplicate_count,
                           GROUP_CONCAT(c.id) AS child_link_ids
                    FROM genealogy_children c
                    JOIN genealogy_families f ON f.id = c.family_id
                    WHERE f.tree_id = ?
                    GROUP BY c.family_id, c.person_id
                    HAVING COUNT(*) > 1
                ',
                [$treeId],
                $limit,
                'Keep one supported child-family link and remove duplicate rows through reviewed remediation.'
            );
        }

        if ($this->hasTables(['genealogy_person_media', 'genealogy_persons', 'genealogy_media'])) {
            $sql = '
                SELECT pm.id AS link_id, pm.person_id, pm.media_id, p.tree_id AS person_tree_id, m.tree_id AS media_tree_id
                FROM genealogy_person_media pm
                LEFT JOIN genealogy_persons p ON p.id = pm.person_id
                LEFT JOIN genealogy_media m ON m.id = pm.media_id
                WHERE COALESCE(p.tree_id, m.tree_id) = ?
                  AND (p.id IS NULL OR m.id IS NULL OR p.tree_id <> m.tree_id)
            ';
            $issues[] = $this->issueFromSql(
                'broken_person_media_links',
                'Person-media links point to missing records or cross-tree media.',
                'links',
                'high',
                'strong',
                $sql,
                [$treeId],
                $limit,
                'Repoint links to the retained FT-local media row or remove invalid links after review.'
            );
        }

        if ($this->hasTables(['genealogy_family_media', 'genealogy_families', 'genealogy_media'])) {
            $sql = '
                SELECT fm.id AS link_id, fm.family_id, fm.media_id, f.tree_id AS family_tree_id, m.tree_id AS media_tree_id
                FROM genealogy_family_media fm
                LEFT JOIN genealogy_families f ON f.id = fm.family_id
                LEFT JOIN genealogy_media m ON m.id = fm.media_id
                WHERE COALESCE(f.tree_id, m.tree_id) = ?
                  AND (f.id IS NULL OR m.id IS NULL OR f.tree_id <> m.tree_id)
            ';
            $issues[] = $this->issueFromSql(
                'broken_family_media_links',
                'Family-media links point to missing records or cross-tree media.',
                'links',
                'high',
                'strong',
                $sql,
                [$treeId],
                $limit,
                'Repoint links to the retained FT-local media row or remove invalid links after review.'
            );
        }

        if ($this->hasTables(['genealogy_persons', 'genealogy_media']) && Schema::hasColumn('genealogy_persons', 'primary_photo_id')) {
            $sql = '
                SELECT p.id AS person_id, p.given_name, p.surname, p.primary_photo_id, m.tree_id AS media_tree_id
                FROM genealogy_persons p
                LEFT JOIN genealogy_media m ON m.id = p.primary_photo_id
                WHERE p.tree_id = ?
                  AND p.primary_photo_id IS NOT NULL
                  AND (m.id IS NULL OR m.tree_id <> p.tree_id)
            ';
            $issues[] = $this->issueFromSql(
                'broken_primary_photo_links',
                'Primary photo pointers reference missing media or media outside this tree.',
                'links',
                'medium',
                'strong',
                $sql,
                [$treeId],
                $limit,
                'Choose a valid in-tree primary image or clear the pointer through review.'
            );
        }

        if ($this->hasTables(['genealogy_persons', 'genealogy_families', 'genealogy_children'])) {
            $sourceGapReviewedFilter = $this->hasTables(['agent_semantic_memory'])
                ? "
                  AND NOT EXISTS (
                      SELECT 1
                      FROM agent_semantic_memory asm
                      WHERE asm.entity_type = 'genealogy_person'
                        AND asm.entity_id = p.id
                        AND asm.fact_type = 'source_gap_decision'
                        AND COALESCE(asm.consensus_status, 'agreed') <> 'disputed'
                  )
                "
                : '';
            $sql = "
                SELECT p.id AS person_id, p.given_name, p.surname, p.birth_date, p.death_date
                FROM genealogy_persons p
                WHERE p.tree_id = ?
                  AND NOT EXISTS (SELECT 1 FROM genealogy_children c WHERE c.person_id = p.id)
                  AND NOT EXISTS (SELECT 1 FROM genealogy_families f WHERE f.husband_id = p.id OR f.wife_id = p.id)
                  {$sourceGapReviewedFilter}
            ";
            $issues[] = $this->issueFromSql(
                'disconnected_people',
                'People have no parent-family or spouse-family connection and no accepted source-gap review memory.',
                'links',
                'low',
                'medium',
                $sql,
                [$treeId],
                $limit,
                'Treat as orphan/disconnected candidates; accepted source-gap and branch-anchor reviews are suppressed.'
            );
        }

        $issues[] = $this->nonFtPersonCandidateIssue($treeId, $limit);

        return $issues;
    }

    private function dateIssues(int $treeId, int $limit): array
    {
        $people = DB::select('
            SELECT id, given_name, surname, birth_date, death_date, living
            FROM genealogy_persons
            WHERE tree_id = ?
        ', [$treeId]);

        $currentYear = (int) now()->format('Y');
        $over100 = [];
        $deathBeforeBirth = [];
        $longLifespan = [];

        foreach ($people as $person) {
            $birthYear = $this->yearFromDate($person->birth_date ?? null);
            $deathYear = $this->yearFromDate($person->death_date ?? null);
            $sample = [
                'person_id' => (int) $person->id,
                'name' => $this->personName($person),
                'birth_date' => $person->birth_date,
                'death_date' => $person->death_date,
            ];

            if ($birthYear !== null && $deathYear !== null && $deathYear < $birthYear) {
                $deathBeforeBirth[] = $sample + ['birth_year' => $birthYear, 'death_year' => $deathYear];
            }

            if ($birthYear !== null && $deathYear !== null && ($deathYear - $birthYear) > 125) {
                $longLifespan[] = $sample + ['age_years' => $deathYear - $birthYear];
            }

            if ($birthYear !== null && $birthYear <= ($currentYear - 101) && empty($person->death_date) && (int) ($person->living ?? 0) === 1) {
                $over100[] = $sample + ['age_floor_years' => $currentYear - $birthYear];
            }
        }

        $issues = [
            $this->issueFromRows(
                'death_before_birth',
                'Death year appears earlier than birth year.',
                'dates',
                'high',
                'medium',
                $deathBeforeBirth,
                $limit,
                'Review raw dates and sources before changing canonical birth/death fields.'
            ),
            $this->issueFromRows(
                'lifespan_over_125',
                'Recorded lifespan exceeds 125 years.',
                'dates',
                'medium',
                'medium',
                $longLifespan,
                $limit,
                'Check whether one date belongs to another person or is an OCR/import error.'
            ),
            $this->issueFromRows(
                'living_person_over_100',
                'Person is marked living, has a birth year more than 100 years ago, and has no death date.',
                'dates',
                'medium',
                'medium',
                $over100,
                $limit,
                'Queue as a deceased-candidate review; mark deceased only when local policy allows or evidence supports it.'
            ),
        ];

        if ($this->hasTables(['genealogy_children', 'genealogy_families', 'genealogy_persons'])) {
            $issues[] = $this->parentAgeIssue($treeId, $limit);
        }

        return $issues;
    }

    private function conflictIssues(int $treeId, int $limit): array
    {
        $issues = [];

        if ($this->hasTables(['genealogy_source_conflicts', 'genealogy_persons'])) {
            $issues[] = $this->issueFromSql(
                'unresolved_source_conflicts',
                'Previously detected source conflicts are still unresolved.',
                'conflicts',
                'medium',
                'strong',
                '
                    SELECT sc.id AS conflict_id,
                           sc.person_id,
                           p.given_name,
                           p.surname,
                           sc.field_name,
                           sc.source_a_id,
                           sc.source_a_value,
                           sc.source_b_id,
                           sc.source_b_value,
                           sc.conflict_severity,
                           sc.resolution_status,
                           sc.created_at
                    FROM genealogy_source_conflicts sc
                    JOIN genealogy_persons p ON p.id = sc.person_id AND p.tree_id = sc.tree_id
                    WHERE sc.tree_id = ?
                      AND sc.resolution_status = \'unresolved\'
                ',
                [$treeId],
                $limit,
                'Resolve or document each conflicting source claim before raising confidence or applying a canonical fact update.'
            );
        }

        if ($this->hasTables(['genealogy_proposed_changes', 'genealogy_persons'])) {
            $issues[] = $this->issueFromSql(
                'conflicting_fact_proposals',
                'Multiple pending/approved fact proposals disagree for the same person field.',
                'conflicts',
                'medium',
                'strong',
                "
                    SELECT pc.person_id,
                           p.given_name,
                           p.surname,
                           pc.field_name,
                           COUNT(DISTINCT TRIM(COALESCE(pc.proposed_value, ''))) AS proposed_value_count,
                           GROUP_CONCAT(pc.id) AS proposal_ids,
                           GROUP_CONCAT(DISTINCT pc.status) AS statuses
                    FROM genealogy_proposed_changes pc
                    JOIN genealogy_persons p ON p.id = pc.person_id AND p.tree_id = pc.tree_id
                    WHERE pc.tree_id = ?
                      AND pc.change_type = 'fact_update'
                      AND pc.status IN ('pending', 'approved')
                      AND pc.field_name IN (
                        'given_name', 'surname', 'nickname',
                        'birth_date', 'birth_place',
                        'death_date', 'death_place',
                        'burial_date', 'burial_place',
                        'occupation', 'education', 'religion', 'nationality',
                        'cause_of_death', 'physical_description', 'notes'
                      )
                    GROUP BY pc.person_id, p.given_name, p.surname, pc.field_name
                    HAVING COUNT(DISTINCT TRIM(COALESCE(pc.proposed_value, ''))) > 1
                ",
                [$treeId],
                $limit,
                'Queue these for evidence review; do not apply either fact until the conflict is resolved or one proposal is rejected.'
            );
        }

        if ($this->hasTables(['genealogy_proposed_relationships', 'genealogy_persons'])) {
            $issues[] = $this->issueFromSql(
                'conflicting_relationship_proposals',
                'Multiple pending/approved relationship proposals disagree for the same anchor person and relationship type.',
                'conflicts',
                'medium',
                'strong',
                "
                    SELECT pr.person_id,
                           p.given_name,
                           p.surname,
                           pr.relationship_type,
                           COUNT(DISTINCT COALESCE(CAST(pr.related_person_id AS CHAR), pr.proposed_name, '')) AS target_count,
                           GROUP_CONCAT(pr.id) AS proposal_ids,
                           GROUP_CONCAT(DISTINCT pr.status) AS statuses
                    FROM genealogy_proposed_relationships pr
                    JOIN genealogy_persons p ON p.id = pr.person_id AND p.tree_id = pr.tree_id
                    WHERE pr.tree_id = ?
                      AND pr.status IN ('pending', 'approved')
                    GROUP BY pr.person_id, p.given_name, p.surname, pr.relationship_type
                    HAVING COUNT(DISTINCT COALESCE(CAST(pr.related_person_id AS CHAR), pr.proposed_name, '')) > 1
                ",
                [$treeId],
                $limit,
                'Resolve identity and source conflicts before applying relationship links or creating a new person.'
            );
        }

        return $issues;
    }

    private function mediaIssues(int $treeId, int $limit): array
    {
        if (! Schema::hasTable('genealogy_media')) {
            return [];
        }

        $issues = [];

        $issues[] = $this->issueFromSql(
            'missing_media_files',
            'Media rows do not currently have a verified local file.',
            'media',
            'high',
            'strong',
            '
                SELECT id AS media_id, media_type, title, local_filename, file_exists
                FROM genealogy_media
                WHERE tree_id = ? AND COALESCE(file_exists, 0) <> 1
            ',
            [$treeId],
            $limit,
            'Recover/copy the file into the FT folder, replace with a duplicate retained file, or drop the stale reference.'
        );

        if ($this->hasTables(['genealogy_person_media', 'genealogy_family_media'])) {
            $citationFilter = $this->hasTables(['genealogy_citations'])
                ? 'AND NOT EXISTS (SELECT 1 FROM genealogy_citations c WHERE c.media_id = m.id)'
                : '';

            $issues[] = $this->issueFromSql(
                'unlinked_media',
                'Media rows are not linked to any person, family, or citation.',
                'media',
                'medium',
                'strong',
                "
                    SELECT m.id AS media_id, m.media_type, m.title, m.local_filename
                    FROM genealogy_media m
                    LEFT JOIN genealogy_person_media pm ON pm.media_id = m.id
                    LEFT JOIN genealogy_family_media fm ON fm.media_id = m.id
                    WHERE m.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL
                      {$citationFilter}
                ",
                [$treeId],
                $limit,
                'Process metadata/text and create person/family link proposals only when identity evidence is sufficient.'
            );

            if ($this->hasTables(['genealogy_citations'])) {
                $mediaTriagedFilter = $this->hasTables(['agent_semantic_memory'])
                    ? "
                        AND NOT EXISTS (
                            SELECT 1
                            FROM agent_semantic_memory asm
                            WHERE asm.entity_type = 'genealogy_media'
                              AND asm.entity_id = m.id
                              AND asm.fact_type = 'media_triage_review'
                              AND COALESCE(asm.consensus_status, 'agreed') <> 'disputed'
                        )
                    "
                    : '';

                $issues[] = $this->issueFromSql(
                    'citation_only_media',
                    'Media rows are attached only to source-level citations, not direct person/family links or subject citations.',
                    'media',
                    'info',
                    'strong',
                    "
                        SELECT m.id AS media_id,
                               m.media_type,
                               m.title,
                               m.local_filename,
                               COUNT(DISTINCT c.id) AS citation_count,
                               COUNT(DISTINCT c.source_id) AS source_count,
                               SUM(CASE WHEN c.person_id IS NOT NULL OR c.family_id IS NOT NULL THEN 1 ELSE 0 END) AS subject_citation_count
                        FROM genealogy_media m
                        JOIN genealogy_citations c ON c.media_id = m.id
                        LEFT JOIN genealogy_person_media pm ON pm.media_id = m.id
                        LEFT JOIN genealogy_family_media fm ON fm.media_id = m.id
                        WHERE m.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL
                          {$mediaTriagedFilter}
                        GROUP BY m.id, m.media_type, m.title, m.local_filename
                        HAVING subject_citation_count = 0
                    ",
                    [$treeId],
                    $limit,
                    'Review source-only captures for subject citations or accepted source-context triage; accepted media triage reviews are suppressed.'
                );
            }
        }

        $issues[] = $this->nonFtMediaCandidateIssue($treeId, $limit);

        $issues[] = $this->issueFromSql(
            'external_only_media',
            'Media has an external URL but no verified local copy.',
            'media',
            'medium',
            'strong',
            "
                SELECT id AS media_id, media_type, title, local_filename, nextcloud_path, original_path
                FROM genealogy_media
                WHERE tree_id = ?
                  AND COALESCE(file_exists, 0) <> 1
                  AND (
                    nextcloud_path LIKE 'http://%' OR nextcloud_path LIKE 'https://%'
                    OR original_path LIKE 'http://%' OR original_path LIKE 'https://%'
                  )
            ",
            [$treeId],
            $limit,
            'Download/capture permitted assets into the owning FT folder before using them as evidence.'
        );

        return $issues;
    }

    private function nonFtPersonCandidateIssue(int $treeId, int $limit): ?array
    {
        if (! $this->hasTables(['agent_semantic_memory', 'genealogy_persons'])) {
            return null;
        }

        $names = $this->nonFtNameMemoryRows($treeId);
        if ($names === []) {
            return null;
        }

        $nicknameSelect = Schema::hasColumn('genealogy_persons', 'nickname') ? 'nickname' : 'NULL AS nickname';
        $people = DB::select(
            "SELECT id AS person_id, given_name, surname, {$nicknameSelect}, birth_date, death_date
             FROM genealogy_persons
             WHERE tree_id = ?",
            [$treeId]
        );

        $matches = [];
        foreach ($people as $person) {
            $personText = $this->normalizeMatchText(trim(implode(' ', array_filter([
                $person->given_name ?? null,
                $person->nickname ?? null,
                $person->surname ?? null,
            ]))));

            foreach ($names as $name) {
                if ($personText === '' || ! $this->containsNormalizedName($personText, $name['match_key'])) {
                    continue;
                }

                $matches[] = [
                    'person_id' => (int) $person->person_id,
                    'name' => $this->personName($person),
                    'birth_date' => $person->birth_date ?? null,
                    'death_date' => $person->death_date ?? null,
                    'matched_non_ft_name' => $name['name'],
                    'memory_id' => $name['memory_id'],
                    'memory_confidence' => $name['confidence'],
                    'basis' => 'operator_confirmed_non_ft_name_memory',
                ];

                break;
            }
        }

        return $this->issueFromRows(
            'non_ft_person_name_memory_hits',
            'In-tree people match operator-confirmed non-FT name memory.',
            'links',
            'medium',
            'medium',
            $matches,
            $limit,
            'Review identity and relationship context before deleting or moving any person record; matching non-FT memory is a cleanup lead, not proof.'
        );
    }

    private function nonFtMediaCandidateIssue(int $treeId, int $limit): ?array
    {
        if (! $this->hasTables(['agent_semantic_memory', 'genealogy_media', 'genealogy_person_media', 'genealogy_family_media'])) {
            return null;
        }

        $names = $this->nonFtNameMemoryRows($treeId);
        if ($names === []) {
            return null;
        }

        $citationJoin = $this->hasTables(['genealogy_citations'])
            ? 'LEFT JOIN genealogy_citations gc ON gc.media_id = gm.id'
            : '';
        $citationWhere = $this->hasTables(['genealogy_citations'])
            ? 'AND gc.id IS NULL'
            : '';

        $mediaRows = DB::select(
            "
                SELECT gm.id AS media_id,
                       gm.media_type,
                       gm.title,
                       gm.local_filename,
                       gm.description,
                       gm.ai_description,
                       gm.transcription_text,
                       gm.transcription,
                       gm.nextcloud_path,
                       gm.original_path,
                       gm.file_exists
                FROM genealogy_media gm
                LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
                LEFT JOIN genealogy_family_media gfm ON gfm.media_id = gm.id
                {$citationJoin}
                WHERE gm.tree_id = ?
                  AND gpm.id IS NULL
                  AND gfm.id IS NULL
                  {$citationWhere}
                ORDER BY gm.updated_at DESC, gm.id DESC
                LIMIT 5000
            ",
            [$treeId]
        );

        if ($mediaRows === []) {
            return null;
        }

        $mediaIds = array_map(static fn (object $row): int => (int) $row->media_id, $mediaRows);
        $faceHints = $this->nonFtMediaFaceHints($treeId, $mediaIds);
        $matches = [];

        foreach ($mediaRows as $media) {
            $mediaId = (int) $media->media_id;
            $mediaText = $this->normalizeMatchText(implode(' ', array_filter([
                $media->title ?? null,
                $media->local_filename ?? null,
                $media->description ?? null,
                $media->ai_description ?? null,
                $media->transcription_text ?? null,
                $media->transcription ?? null,
                $media->nextcloud_path ?? null,
                $media->original_path ?? null,
                $faceHints[$mediaId] ?? null,
            ])));

            foreach ($names as $name) {
                if ($mediaText === '' || ! $this->containsNormalizedName($mediaText, $name['match_key'])) {
                    continue;
                }

                $matches[] = [
                    'media_id' => $mediaId,
                    'media_type' => $media->media_type ?? null,
                    'title' => $media->title ?? null,
                    'local_filename' => $media->local_filename ?? null,
                    'file_exists' => isset($media->file_exists) ? (int) $media->file_exists : null,
                    'matched_non_ft_name' => $name['name'],
                    'memory_id' => $name['memory_id'],
                    'memory_confidence' => $name['confidence'],
                    'basis' => isset($faceHints[$mediaId])
                        ? 'operator_non_ft_memory_matched_media_text_or_face_metadata'
                        : 'operator_non_ft_memory_matched_media_text',
                ];

                break;
            }
        }

        return $this->issueFromRows(
            'non_ft_media_name_memory_hits',
            'Unlinked, uncited media matches operator-confirmed non-FT name memory.',
            'media',
            'medium',
            'medium',
            $matches,
            $limit,
            'Review the media packet; quarantine/delete only after confirming it has no FT subject, citation role, or export requirement.'
        );
    }

    /**
     * @return array<int, array{memory_id: int, name: string, match_key: string, confidence: float|null}>
     */
    private function nonFtNameMemoryRows(int $treeId): array
    {
        $rows = DB::select(
            "SELECT id, fact_value, confidence
             FROM agent_semantic_memory
             WHERE entity_type = 'genealogy_tree'
               AND entity_id = ?
               AND fact_type = 'non_ft_name'
             ORDER BY confidence DESC, updated_at DESC, id DESC
             LIMIT 500",
            [$treeId]
        );

        $names = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->fact_value ?? ''));
            $matchKey = $this->normalizeMatchText($name);
            if ($name === '' || $matchKey === '' || isset($seen[$matchKey])) {
                continue;
            }

            $seen[$matchKey] = true;
            $names[] = [
                'memory_id' => (int) $row->id,
                'name' => $name,
                'match_key' => $matchKey,
                'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
            ];
        }

        return $names;
    }

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, string>
     */
    private function nonFtMediaFaceHints(int $treeId, array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_filter($mediaIds, static fn (int $id): bool => $id > 0)));
        if ($mediaIds === []) {
            return [];
        }

        $hints = [];
        if ($this->hasTables(['genealogy_face_match_queue'])) {
            foreach (array_chunk($mediaIds, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $rows = DB::select(
                    "SELECT media_id, GROUP_CONCAT(DISTINCT face_name) AS names
                     FROM genealogy_face_match_queue
                     WHERE tree_id = ?
                       AND media_id IN ({$placeholders})
                       AND NULLIF(face_name, '') IS NOT NULL
                     GROUP BY media_id",
                    array_merge([$treeId], $chunk)
                );

                foreach ($rows as $row) {
                    $mediaId = (int) $row->media_id;
                    $hints[$mediaId] = trim(($hints[$mediaId] ?? '').' '.(string) ($row->names ?? ''));
                }
            }
        }

        if ($this->hasTables(['file_registry', 'file_registry_faces'])) {
            foreach (array_chunk($mediaIds, 250) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $rows = DB::select(
                    "SELECT gm.id AS media_id,
                            GROUP_CONCAT(DISTINCT ff.person_name) AS names
                     FROM genealogy_media gm
                     JOIN file_registry fr
                       ON fr.current_path = gm.nextcloud_path
                       OR fr.current_path = gm.original_path
                       OR fr.original_path = gm.nextcloud_path
                       OR fr.original_path = gm.original_path
                     JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                     WHERE gm.tree_id = ?
                       AND gm.id IN ({$placeholders})
                       AND COALESCE(ff.hidden, 0) = 0
                       AND NULLIF(ff.person_name, '') IS NOT NULL
                     GROUP BY gm.id",
                    array_merge([$treeId], $chunk)
                );

                foreach ($rows as $row) {
                    $mediaId = (int) $row->media_id;
                    $hints[$mediaId] = trim(($hints[$mediaId] ?? '').' '.(string) ($row->names ?? ''));
                }
            }
        }

        return array_filter($hints, static fn (string $hint): bool => trim($hint) !== '');
    }

    private function normalizeMatchText(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value)) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function containsNormalizedName(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        return str_contains(" {$haystack} ", " {$needle} ");
    }

    private function ragIssues(int $treeId, int $limit): array
    {
        $issues = [];

        if (Schema::hasColumn('genealogy_persons', 'rag_indexed_at')) {
            $issues[] = $this->issueFromSql(
                'rag_missing_persons',
                'People are not marked as indexed into person RAG.',
                'rag',
                'medium',
                'strong',
                '
                    SELECT id AS person_id, given_name, surname, updated_at, rag_indexed_at
                    FROM genealogy_persons
                    WHERE tree_id = ? AND rag_indexed_at IS NULL
                ',
                [$treeId],
                $limit,
                'Run genealogy:rag-index for this tree so all private FT people are searchable locally.'
            );

            $issues[] = $this->issueFromSql(
                'rag_stale_persons',
                'People changed after their last RAG index timestamp.',
                'rag',
                'low',
                'strong',
                '
                    SELECT id AS person_id, given_name, surname, updated_at, rag_indexed_at
                    FROM genealogy_persons
                    WHERE tree_id = ? AND rag_indexed_at IS NOT NULL AND updated_at IS NOT NULL AND updated_at > rag_indexed_at
                ',
                [$treeId],
                $limit,
                'Run incremental genealogy:rag-index or allow the scheduled job to refresh stale records.'
            );
        }

        if (Schema::hasTable('genealogy_media') && Schema::hasColumn('genealogy_media', 'rag_indexed_at')) {
            $readableMediaWhere = "
                (
                    NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL
                    OR NULLIF(TRIM(COALESCE(local_filename, '')), '') IS NOT NULL
                    OR NULLIF(TRIM(COALESCE(description, '')), '') IS NOT NULL
                    OR NULLIF(TRIM(COALESCE(ai_description, '')), '') IS NOT NULL
                    OR NULLIF(TRIM(COALESCE(transcription_text, '')), '') IS NOT NULL
                    OR NULLIF(TRIM(COALESCE(transcription, '')), '') IS NOT NULL
                )
            ";

            $issues[] = $this->issueFromSql(
                'rag_missing_media',
                'Readable media metadata/text is not marked as indexed into media RAG.',
                'rag',
                'medium',
                'strong',
                "
                    SELECT id AS media_id, media_type, title, local_filename, updated_at, rag_indexed_at
                    FROM genealogy_media
                    WHERE tree_id = ?
                      AND rag_indexed_at IS NULL
                      AND {$readableMediaWhere}
                ",
                [$treeId],
                $limit,
                'Run genealogy:media-rag-index so media evidence and rejected/non-FT names are searchable.'
            );

            $issues[] = $this->issueFromSql(
                'rag_stale_media',
                'Readable media metadata/text changed after the last media RAG index timestamp.',
                'rag',
                'low',
                'strong',
                "
                    SELECT id AS media_id, media_type, title, local_filename, updated_at, rag_indexed_at
                    FROM genealogy_media
                    WHERE tree_id = ?
                      AND rag_indexed_at IS NOT NULL
                      AND updated_at IS NOT NULL
                      AND updated_at > rag_indexed_at
                      AND {$readableMediaWhere}
                ",
                [$treeId],
                $limit,
                'Run bounded genealogy:media-rag-index batches or allow the scheduled media RAG job to refresh stale records.'
            );
        }

        return $issues;
    }

    private function citationIssues(int $treeId, int $limit): array
    {
        if (! $this->hasTables(['genealogy_person_sources'])) {
            return [];
        }

        $sourceGapReviewedFilter = $this->hasTables(['agent_semantic_memory'])
            ? "
                      AND NOT EXISTS (
                          SELECT 1
                          FROM agent_semantic_memory asm
                          WHERE asm.entity_type = 'genealogy_person'
                            AND asm.entity_id = p.id
                            AND asm.fact_type = 'source_gap_decision'
                            AND COALESCE(asm.consensus_status, 'agreed') <> 'disputed'
                      )
            "
            : '';

        return [
            $this->issueFromSql(
                'people_without_person_sources',
                'People have no direct person-source citation rows and no accepted source-gap review memory.',
                'citations',
                'low',
                'strong',
                "
                    SELECT p.id AS person_id, p.given_name, p.surname, p.birth_date, p.death_date
                    FROM genealogy_persons p
                    LEFT JOIN genealogy_person_sources ps ON ps.person_id = p.id
                    WHERE p.tree_id = ? AND ps.id IS NULL
                    {$sourceGapReviewedFilter}
                ",
                [$treeId],
                $limit,
                'Rank these for source research; accepted source-gap decisions are suppressed so reviewed dead ends do not dominate active triage.'
            ),
        ];
    }

    private function duplicateIssues(int $treeId, int $limit): array
    {
        $issues = [];
        $duplicateReviewedFilter = $this->hasTables(['agent_semantic_memory'])
            ? "
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM agent_semantic_memory asm
                    WHERE asm.entity_type = 'genealogy_person'
                      AND asm.entity_id = duplicate_rows.first_person_id
                      AND asm.fact_type = 'review_rejected'
                      AND asm.fact_key LIKE 'tree_%_duplicate_pair_%'
                      AND COALESCE(asm.consensus_status, 'agreed') <> 'disputed'
                )
            "
            : '';

        $issues[] = $this->issueFromSql(
            'duplicate_people_exact_key',
            'People share the same normalized name and birth date key with no accepted false-positive review.',
            'duplicates',
            'medium',
            'medium',
            "
                SELECT duplicate_rows.given_key,
                       duplicate_rows.surname_key,
                       duplicate_rows.birth_key,
                       duplicate_rows.duplicate_count,
                       duplicate_rows.person_ids
                FROM (
                    SELECT
                        LOWER(TRIM(COALESCE(given_name, ''))) AS given_key,
                        LOWER(TRIM(COALESCE(surname, ''))) AS surname_key,
                        COALESCE(birth_date, '') AS birth_key,
                        COUNT(*) AS duplicate_count,
                        GROUP_CONCAT(id) AS person_ids,
                        MIN(id) AS first_person_id
                    FROM genealogy_persons
                    WHERE tree_id = ?
                    GROUP BY LOWER(TRIM(COALESCE(given_name, ''))), LOWER(TRIM(COALESCE(surname, ''))), COALESCE(birth_date, '')
                    HAVING COUNT(*) > 1 AND (given_key <> '' OR surname_key <> '')
                ) duplicate_rows
                {$duplicateReviewedFilter}
            ",
            [$treeId],
            $limit,
            'Review identity evidence before merging; accepted false-positive duplicate reviews are suppressed.'
        );

        if (Schema::hasTable('genealogy_families')) {
            $issues[] = $this->issueFromSql(
                'duplicate_families_exact_key',
                'Families share the same spouse pair and marriage date key.',
                'duplicates',
                'medium',
                'medium',
                "
                    SELECT
                        COALESCE(husband_id, 0) AS husband_key,
                        COALESCE(wife_id, 0) AS wife_key,
                        COALESCE(marriage_date, '') AS marriage_key,
                        COUNT(*) AS duplicate_count,
                        GROUP_CONCAT(id) AS family_ids
                    FROM genealogy_families
                    WHERE tree_id = ?
                    GROUP BY COALESCE(husband_id, 0), COALESCE(wife_id, 0), COALESCE(marriage_date, '')
                    HAVING COUNT(*) > 1 AND (husband_key <> 0 OR wife_key <> 0)
                ",
                [$treeId],
                $limit,
                'Use a family remediation preview before merging spouse/child/source/media links.'
            );
        }

        return $issues;
    }

    private function exportIssues(int $treeId, string $root, int $limit): array
    {
        if (! Schema::hasTable('genealogy_media')) {
            return [];
        }

        $rootLike = rtrim($root, '/').'/%';
        $issues = [];

        $issues[] = $this->issueFromSql(
            'media_paths_not_self_contained',
            'Media paths appear outside the inferred FT folder, use old drive paths, or are still external.',
            'export',
            'medium',
            'medium',
            "
                SELECT id AS media_id, media_type, title, local_filename, nextcloud_path, original_path
                FROM genealogy_media
                WHERE tree_id = ?
                  AND (
                    nextcloud_path IS NULL
                    OR nextcloud_path = ''
                    OR nextcloud_path LIKE 'Z:%'
                    OR nextcloud_path LIKE 'http://%'
                    OR nextcloud_path LIKE 'https://%'
                    OR (
                        nextcloud_path NOT LIKE 'Z:%'
                        AND nextcloud_path NOT LIKE 'http://%'
                        AND nextcloud_path NOT LIKE 'https://%'
                        AND nextcloud_path NOT LIKE ?
                    )
                  )
            ",
            [$treeId, $rootLike],
            $limit,
            'Copy/download into the owning FT folder and repoint records before export.'
        );

        if (Schema::hasTable('genealogy_persons')) {
            $personRows = $this->exportReadinessPersons($treeId);

            $livingIssues = [];
            $privateIssues = [];
            foreach ($personRows as $person) {
                if (! $this->privacyPolicyService->includePersonInExport($person, false)) {
                    $livingIssues[] = $this->rowsToArrays([$person])[0];
                }

                if ($this->privacyPolicyService->hasPrivatePrivacyOverride($person)) {
                    $privateIssues[] = $this->rowsToArrays([$person])[0];
                }
            }

            if ($livingIssues !== []) {
                $issues[] = $this->issueFromRows(
                    'public_export_living_persons_redacted',
                    'Living persons will be reduced to privacy stubs in default public export output.',
                    'export',
                    'info',
                    'medium',
                    $livingIssues,
                    $limit,
                    'Run explicit include_living/export policy approval before releasing living-person details.'
                );
            }

            if ($privateIssues !== []) {
                $issues[] = $this->issueFromRows(
                    'public_export_private_override_persons',
                    'Persons with explicit private privacy overrides require privacy review before public export.',
                    'export',
                    'medium',
                    'medium',
                    $privateIssues,
                    $limit,
                    'Resolve privacy override risk (public/private visibility decision) before release.'
                );
            }
        }

        return array_values(array_filter($issues, static fn ($issue): bool => $issue !== null));
    }

    private function exportReadinessPersons(int $treeId): array
    {
        $hasTreeTable = Schema::hasTable('genealogy_trees');
        $treeThreshold = $hasTreeTable && Schema::hasColumn('genealogy_trees', 'living_years_threshold');
        $personThreshold = Schema::hasColumn('genealogy_persons', 'living_years_threshold');
        $privacyOverride = Schema::hasColumn('genealogy_persons', 'privacy_override');

        $select = [
            'p.id AS id',
            'p.given_name',
            'p.surname',
            'p.birth_date',
            'p.death_date',
            'p.living',
        ];

        if ($privacyOverride) {
            $select[] = 'p.privacy_override';
        } else {
            $select[] = 'NULL AS privacy_override';
        }

        if ($personThreshold && $treeThreshold) {
            $livingThresholdExpr = 'COALESCE(p.living_years_threshold, t.living_years_threshold, 100)';
        } elseif ($personThreshold) {
            $livingThresholdExpr = 'COALESCE(p.living_years_threshold, 100)';
        } elseif ($treeThreshold) {
            $livingThresholdExpr = 'COALESCE(t.living_years_threshold, 100)';
        } else {
            $livingThresholdExpr = '100';
        }

        $select[] = $livingThresholdExpr.' AS living_years_threshold';

        $sql = 'SELECT '.implode(', ', $select);
        if (str_contains($sql, 't.')) {
            $sql .= ' FROM genealogy_persons p LEFT JOIN genealogy_trees t ON t.id = p.tree_id';
        } else {
            $sql .= ' FROM genealogy_persons p';
        }

        $sql .= ' WHERE p.tree_id = ?';

        return DB::select($sql, [$treeId]);
    }

    private function parentAgeIssue(int $treeId, int $limit): ?array
    {
        $rows = DB::select('
            SELECT
                c.id AS child_link_id,
                child.id AS child_id,
                child.given_name AS child_given_name,
                child.surname AS child_surname,
                child.birth_date AS child_birth_date,
                father.id AS father_id,
                father.birth_date AS father_birth_date,
                mother.id AS mother_id,
                mother.birth_date AS mother_birth_date
            FROM genealogy_children c
            JOIN genealogy_families f ON f.id = c.family_id
            JOIN genealogy_persons child ON child.id = c.person_id
            LEFT JOIN genealogy_persons father ON father.id = f.husband_id
            LEFT JOIN genealogy_persons mother ON mother.id = f.wife_id
            WHERE f.tree_id = ?
        ', [$treeId]);

        $conflicts = [];
        foreach ($rows as $row) {
            $childYear = $this->yearFromDate($row->child_birth_date ?? null);
            if ($childYear === null) {
                continue;
            }

            $fatherYear = $this->yearFromDate($row->father_birth_date ?? null);
            if ($fatherYear !== null) {
                $age = $childYear - $fatherYear;
                if ($age < 12 || $age > 90) {
                    $conflicts[] = [
                        'child_link_id' => (int) $row->child_link_id,
                        'child_id' => (int) $row->child_id,
                        'child_name' => trim(($row->child_given_name ?? '').' '.($row->child_surname ?? '')),
                        'parent_id' => (int) $row->father_id,
                        'parent_role' => 'father',
                        'parent_age_at_child_birth' => $age,
                    ];
                }
            }

            $motherYear = $this->yearFromDate($row->mother_birth_date ?? null);
            if ($motherYear !== null) {
                $age = $childYear - $motherYear;
                if ($age < 12 || $age > 55) {
                    $conflicts[] = [
                        'child_link_id' => (int) $row->child_link_id,
                        'child_id' => (int) $row->child_id,
                        'child_name' => trim(($row->child_given_name ?? '').' '.($row->child_surname ?? '')),
                        'parent_id' => (int) $row->mother_id,
                        'parent_role' => 'mother',
                        'parent_age_at_child_birth' => $age,
                    ];
                }
            }
        }

        return $this->issueFromRows(
            'implausible_parent_age',
            'Parent age at child birth is outside conservative genealogy review bounds.',
            'dates',
            'medium',
            'medium',
            $conflicts,
            $limit,
            'Review parent/child identity and dates before changing relationship links.'
        );
    }

    private function summary(int $treeId): array
    {
        $summary = $this->blankSummary();
        $summary['persons'] = $this->countTable('genealogy_persons', 'tree_id = ?', [$treeId]);
        $summary['families'] = $this->countTable('genealogy_families', 'tree_id = ?', [$treeId]);
        $summary['media'] = $this->countTable('genealogy_media', 'tree_id = ?', [$treeId]);
        $summary['sources'] = $this->countTable('genealogy_sources', 'tree_id = ?', [$treeId]);

        if ($this->hasTables(['genealogy_children', 'genealogy_families'])) {
            $summary['children'] = $this->countSql(
                'SELECT c.id FROM genealogy_children c JOIN genealogy_families f ON f.id = c.family_id WHERE f.tree_id = ?',
                [$treeId]
            );
        }

        return $summary;
    }

    private function issueFromSql(string $code, string $title, string $section, string $severity, string $confidence, string $sql, array $params, int $limit, string $suggestedFix): ?array
    {
        $count = $this->countSql($sql, $params);
        if ($count === 0) {
            return null;
        }

        $samples = DB::select($sql.' LIMIT ?', [...$params, $limit]);

        return $this->issue($code, $title, $section, $severity, $confidence, $count, $this->rowsToArrays($samples), $suggestedFix);
    }

    private function issueFromRows(string $code, string $title, string $section, string $severity, string $confidence, array $rows, int $limit, string $suggestedFix): ?array
    {
        if ($rows === []) {
            return null;
        }

        return $this->issue($code, $title, $section, $severity, $confidence, count($rows), array_slice(array_values($rows), 0, $limit), $suggestedFix);
    }

    private function issue(string $code, string $title, string $section, string $severity, string $confidence, int $count, array $samples, string $suggestedFix): array
    {
        $autoFixPolicy = $this->autoFixPolicyForIssue($code);

        return [
            'code' => $code,
            'title' => $title,
            'section' => $section,
            'severity' => $severity,
            'confidence' => $confidence,
            'count' => $count,
            'safe_auto_fix' => (bool) ($autoFixPolicy['safe_auto_fix'] ?? false),
            'auto_fix_policy' => $autoFixPolicy,
            'suggested_fix' => $suggestedFix,
            'samples' => $samples,
        ];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function attachIssueIdentity(int $treeId, array $issue): array
    {
        $code = (string) ($issue['code'] ?? 'unknown_issue');
        $section = (string) ($issue['section'] ?? 'unknown');
        $issueId = $this->issueId($treeId, $section, $code);

        $issue['issue_id'] = $issueId;
        $issue['entity_type'] = $issue['entity_type'] ?? 'genealogy_tree';
        $issue['entity_id'] = $issue['entity_id'] ?? $treeId;
        $issue['review_target'] = $issue['review_target'] ?? [
            'target_ref' => $issueId,
            'target_type' => 'genealogy_health_issue',
            'target_id' => $issueId,
            'tree_id' => $treeId,
            'section' => $section,
            'code' => $code,
        ];
        $issue['provenance'] = $issue['provenance'] ?? [
            'tool' => 'genealogy:health-audit',
            'schema_version' => self::REPORT_VERSION,
            'identity_scope' => 'tree_section_code',
        ];

        return $issue;
    }

    private function issueId(int $treeId, string $section, string $code): string
    {
        $sectionKey = preg_replace('/[^a-z0-9_:-]+/', '_', strtolower(trim($section))) ?: 'unknown';
        $codeKey = preg_replace('/[^a-z0-9_:-]+/', '_', strtolower(trim($code))) ?: 'unknown_issue';

        return "genealogy_health_issue:tree:{$treeId}:{$sectionKey}:{$codeKey}";
    }

    private function countTable(string $table, ?string $where = null, array $params = []): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS aggregate FROM {$table}".($where ? " WHERE {$where}" : '');

        return (int) (DB::selectOne($sql, $params)->aggregate ?? 0);
    }

    private function countSql(string $sql, array $params = []): int
    {
        return (int) (DB::selectOne("SELECT COUNT(*) AS aggregate FROM ({$sql}) audit_rows", $params)->aggregate ?? 0);
    }

    private function hasTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function severityCounts(array $issues): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $severity = (string) ($issue['severity'] ?? 'info');
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return $counts;
    }

    private function performanceBudget(int $treeId, array $sections, int $limit, bool $dryRun): array
    {
        $sectionBudgets = [];
        $queryGroups = 0;
        foreach ($sections as $section) {
            $groups = self::SECTION_QUERY_GROUPS[$section] ?? 1;
            $queryGroups += $groups;
            $sectionBudgets[$section] = [
                'budgeted_query_groups' => $groups,
                'sample_limit_applies' => true,
                'tree_scoped' => true,
            ];
        }

        $warnings = [];
        if (count($sections) > self::PERFORMANCE_BUDGET['max_interactive_sections']) {
            $warnings[] = 'wide_section_run_use_compact_or_split_sections_for_interactive_agents';
        }
        if ($limit > self::PERFORMANCE_BUDGET['recommended_interactive_sample_limit']) {
            $warnings[] = 'high_sample_limit_use_scheduled_job_or_review_packet_for_repeated_runs';
        }
        if ($queryGroups > self::PERFORMANCE_BUDGET['max_interactive_query_groups']) {
            $warnings[] = 'wide_query_group_run_use_bounded_tree_scoped_sections';
        }

        return [
            'status' => $warnings === [] ? 'within_budget' : 'budget_notice',
            'tree_scope' => [
                'tree_id' => $treeId,
                'required' => true,
                'all_trees_mode' => 'iterate_one_tree_at_a_time',
            ],
            'sections' => [
                'selected' => $sections,
                'count' => count($sections),
                'default_sections' => self::DEFAULT_SECTIONS,
                'recommended_interactive_max' => self::PERFORMANCE_BUDGET['max_interactive_sections'],
            ],
            'sample_limit' => [
                'effective' => $limit,
                'hard_max' => self::PERFORMANCE_BUDGET['hard_sample_limit'],
                'recommended_interactive' => self::PERFORMANCE_BUDGET['recommended_interactive_sample_limit'],
                'recommended_review_packet' => self::PERFORMANCE_BUDGET['recommended_review_packet_sample_limit'],
            ],
            'query_groups' => [
                'selected' => $queryGroups,
                'recommended_interactive_max' => self::PERFORMANCE_BUDGET['max_interactive_query_groups'],
            ],
            'runtime_targets' => [
                'interactive_seconds' => self::PERFORMANCE_BUDGET['interactive_target_seconds'],
                'scheduled_seconds' => self::PERFORMANCE_BUDGET['scheduled_target_seconds'],
            ],
            'section_budgets' => $sectionBudgets,
            'limited_detail' => [
                'samples_are_capped' => true,
                'compact_mode_removes_samples_and_paths' => true,
                'dry_run_skips_row_queries' => $dryRun,
            ],
            'warnings' => $warnings,
        ];
    }

    private function autoFixPolicyForIssue(string $code): array
    {
        $policy = match ($code) {
            'public_export_living_persons_redacted', 'public_export_private_override_persons' => [
                'mode' => 'export_release_review_required',
                'default_action' => 'approve public visibility policy before generating public release artifacts.',
                'allowed_tool' => 'genealogy.export_readiness',
                'safe_auto_fix' => false,
            ],
            'rag_missing_persons', 'rag_stale_persons', 'rag_missing_media', 'rag_stale_media' => [
                'mode' => 'bounded_batch',
                'default_action' => 'run_existing_index_batch_or_wait_for_schedule',
                'allowed_tool' => str_contains($code, 'media') ? 'genealogy.media_rag_batch' : 'genealogy.rag_index_batch',
                'safe_auto_fix' => false,
            ],
            'missing_media_files', 'external_only_media', 'media_paths_not_self_contained' => [
                'mode' => 'capture_or_path_repair_review',
                'default_action' => 'review_source_and_copy_or_download_into_ft_folder_before_repointing',
                'allowed_tool' => 'genealogy.export_readiness',
                'safe_auto_fix' => false,
            ],
            'unlinked_media', 'citation_only_media', 'non_ft_media_name_memory_hits' => [
                'mode' => 'media_review_first',
                'default_action' => 'review_media_packet_then_attach_quarantine_or_keep_as_source_context',
                'allowed_tool' => 'genealogy.media_triage_batch',
                'safe_auto_fix' => false,
            ],
            'broken_person_media_links', 'broken_primary_photo_links' => [
                'mode' => 'deterministic_repair_candidate',
                'default_action' => 'dry_run_media_link_integrity_then_confirm_repair_if_same_tree_and_evidence_backed',
                'allowed_tool' => 'genealogy.media_link_integrity',
                'safe_auto_fix' => false,
            ],
            'duplicate_people_exact_key', 'duplicate_families_exact_key' => [
                'mode' => 'merge_review_required',
                'default_action' => 'review_identity_evidence_before_merge_or_relationship_remediation',
                'allowed_tool' => 'genealogy.duplicate_candidates',
                'safe_auto_fix' => false,
            ],
            'conflicting_fact_proposals', 'unresolved_source_conflicts' => [
                'mode' => 'conflict_resolution_required',
                'default_action' => 'resolve_conflict_or_reject_weaker_proposal_before_apply',
                'allowed_tool' => 'genealogy.proposal_queue',
                'safe_auto_fix' => false,
            ],
            'conflicting_relationship_proposals', 'broken_family_spouse_links', 'broken_child_links', 'self_spouse_family_links', 'self_child_links', 'duplicate_child_family_links', 'implausible_parent_age' => [
                'mode' => 'relationship_review_required',
                'default_action' => 'review_relationship_context_and_create_source_backed_proposal_or_remediation_preview',
                'allowed_tool' => 'genealogy.relationship_audit',
                'safe_auto_fix' => false,
            ],
            'living_person_over_100', 'death_before_birth', 'lifespan_over_125' => [
                'mode' => 'fact_review_required',
                'default_action' => 'review dates_and_sources_then_create_or_apply_source_backed_fact_proposal',
                'allowed_tool' => 'genealogy.person_fact_extract',
                'safe_auto_fix' => false,
            ],
            'non_ft_person_name_memory_hits', 'disconnected_people' => [
                'mode' => 'person_cleanup_review_required',
                'default_action' => 'review branch_context_sources_and_media_before_delete_or_move_decision',
                'allowed_tool' => 'genealogy.person_profile',
                'safe_auto_fix' => false,
            ],
            default => [
                'mode' => 'proposal_or_manual_review_required',
                'default_action' => 'review_evidence_then_use_proposal_or_dedicated_repair_tool',
                'allowed_tool' => 'genealogy.health_review_packet',
                'safe_auto_fix' => false,
            ],
        };

        return $policy + [
            'dry_run_required' => true,
            'confirmation_required' => true,
            'rollback_required' => true,
        ];
    }

    private function nextActions(array $issues): array
    {
        if ($issues === []) {
            return [[
                'code' => 'no_immediate_health_issues',
                'label' => 'No issue category was detected by this read-only audit slice.',
                'write_required' => false,
            ]];
        }

        $actions = [];
        foreach ([
            'broken_family_spouse_links',
            'broken_child_links',
            'unresolved_source_conflicts',
            'conflicting_fact_proposals',
            'conflicting_relationship_proposals',
            'missing_media_files',
            'living_person_over_100',
            'public_export_living_persons_redacted',
            'public_export_private_override_persons',
            'rag_missing_persons',
        ] as $code) {
            $issue = collect($issues)->firstWhere('code', $code);
            if ($issue) {
                $actions[] = [
                    'code' => 'review_'.$code,
                    'label' => $issue['suggested_fix'],
                    'write_required' => false,
                ];
            }
        }

        return $actions;
    }

    /**
     * @return list<array{tool: string, reason: string}>
     */
    private function reviewPacketToolsForIssue(string $code): array
    {
        return match ($code) {
            'missing_media_files', 'external_only_media', 'media_paths_not_self_contained' => [[
                'tool' => 'genealogy.export_readiness',
                'reason' => 'Verify self-contained media/export state before copying, downloading, or dropping references.',
            ]],
            'public_export_living_persons_redacted', 'public_export_private_override_persons' => [[
                'tool' => 'genealogy.export_readiness',
                'reason' => 'Apply explicit visibility policy for public release before export distribution.',
            ]],
            'unlinked_media', 'citation_only_media', 'non_ft_media_name_memory_hits' => [[
                'tool' => 'genealogy.media_triage_batch',
                'reason' => 'Get compact media triage buckets before link, quarantine, or keep-as-source decisions.',
            ]],
            'conflicting_fact_proposals', 'unresolved_source_conflicts' => [[
                'tool' => 'genealogy.proposal_queue',
                'reason' => 'Review pending/approved fact proposals before applying or rejecting changes.',
            ]],
            'conflicting_relationship_proposals', 'broken_child_links', 'broken_family_spouse_links' => [[
                'tool' => 'genealogy.relationship_audit',
                'reason' => 'Inspect relationship integrity context before proposing link repairs.',
            ]],
            'rag_missing_persons', 'rag_stale_persons', 'rag_missing_media', 'rag_stale_media' => [[
                'tool' => str_contains($code, 'media') ? 'genealogy.media_rag_batch' : 'genealogy.rag_index_batch',
                'reason' => 'Confirm RAG backlog and use bounded dry-run-first index batches or scheduled jobs.',
            ]],
            default => [[
                'tool' => 'genealogy.health_audit',
                'reason' => 'Re-run the relevant section with samples before making a review decision.',
            ]],
        };
    }

    private function dryRunActions(): array
    {
        return [[
            'code' => 'run_observe',
            'label' => 'Run without --dry-run to query read-only genealogy health counts and issue samples.',
            'write_required' => false,
        ]];
    }

    private function posture(bool $pathsIncluded): array
    {
        return [
            'paths_included' => $pathsIncluded,
            'downloads_enabled' => false,
            'storage_writes_enabled' => false,
            'genealogy_links_enabled' => false,
            'canonical_record_writes_enabled' => false,
            'review_decisions_enabled' => false,
            'privacy_export_release_enabled' => false,
        ];
    }

    private function blankSummary(): array
    {
        return [
            'query_would_run' => false,
            'persons' => 0,
            'families' => 0,
            'children' => 0,
            'media' => 0,
            'sources' => 0,
            'issue_count' => 0,
            'issue_rows' => 0,
            'severity_counts' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0],
        ];
    }

    private function normalizeSections(array $sections): array
    {
        $sections = array_values(array_filter(array_map(
            static fn ($section): string => trim(strtolower((string) $section)),
            $sections
        )));

        if ($sections === []) {
            return self::DEFAULT_SECTIONS;
        }

        $valid = array_values(array_intersect($sections, self::DEFAULT_SECTIONS));

        return $valid === [] ? self::DEFAULT_SECTIONS : $valid;
    }

    private function rowsToArrays(array $rows): array
    {
        return array_map(static fn (object $row): array => array_map(
            static fn ($value) => is_bool($value) ? (int) $value : $value,
            (array) $row
        ), $rows);
    }

    private function yearFromDate(?string $date): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        if (preg_match('/\b(1[0-9]{3}|20[0-9]{2})\b/', $date, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function personName(object $person): string
    {
        return trim(($person->given_name ?? '').' '.($person->surname ?? '')) ?: 'Unknown';
    }
}
