<?php

namespace App\Services\Genealogy;

use App\Services\Genealogy\Support\TemporalProximityChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creation-time gate for genealogy proposals (defense layer 1 of 3).
 *
 * The original Mary Billington / E. Billington defect (Civil War
 * pension proposed for someone who died 1718) showed that
 * surname-only agent searches with no temporal filter produce
 * GPS-violating proposals every cycle. By the time the operator sees
 * one in review, the agent has already polluted the queue.
 *
 * Three layers of defense in the genealogy stack:
 *   1. CREATION-TIME (this service) — filters proposals BEFORE they
 *      land in genealogy_proposed_changes
 *   2. APPLY-TIME (PersonService::runSourceAddBackstop) — refuses
 *      to apply a proposal that violates gates even if it slipped
 *      past creation
 *   3. DISPLAY-TIME (ReviewContextEnrichmentService::detect…) —
 *      surfaces the violation in the operator UI as a red warning
 *
 * All three share TemporalProximityChecker so they can't drift apart.
 *
 * Operator-emitted proposals (no agent_id) bypass all gates per the
 * same convention runSourceAddBackstop uses — operator's manual
 * additions are trusted as deliberate.
 */
class ProposalValidatorService
{
    private const MIN_EVIDENCE_SUMMARY_LENGTH = 20;

    /**
     * Validate a proposal before it's inserted into
     * genealogy_proposed_changes. Returns:
     *   - {ok: true} when the proposal passes every gate
     *   - {ok: false, reason: string, gate: string, severity?: string}
     *     when any gate fires
     *
     * Operator-emitted proposals (agentId === null or '') are ALWAYS
     * accepted — manual additions are trusted as deliberate.
     *
     * @param  string  $proposedValue  raw string (not JSON-decoded)
     * @param  array<int,string>  $evidenceSources
     * @param  string|null  $agentId  null/empty = operator-emitted
     * @return array{ok: bool, reason?: string, gate?: string, severity?: string}
     */
    public function validate(
        int $personId,
        string $changeType,
        ?string $fieldName,
        string $proposedValue,
        string $evidenceSummary,
        array $evidenceSources,
        ?string $agentId
    ): array {
        if ($agentId === null || $agentId === '') {
            return ['ok' => true];
        }

        // Gate 1: evidence_summary minimum length. Agents must
        // explain WHY they're proposing — bare URL with no context
        // is uncorroborated.
        if (mb_strlen(trim($evidenceSummary)) < self::MIN_EVIDENCE_SUMMARY_LENGTH) {
            return [
                'ok' => false,
                'gate' => 'evidence_summary_min_length',
                'reason' => sprintf(
                    'evidence_summary is %d chars; minimum %d required for an agent-emitted proposal',
                    mb_strlen(trim($evidenceSummary)),
                    self::MIN_EVIDENCE_SUMMARY_LENGTH
                ),
            ];
        }

        // Gate 2: evidence_sources non-empty. Agent must cite at
        // least one source identifier.
        $cleanSources = array_values(array_filter(array_map('trim', $evidenceSources), fn ($s) => $s !== ''));
        if ($cleanSources === []) {
            return [
                'ok' => false,
                'gate' => 'evidence_sources_required',
                'reason' => 'evidence_sources is empty; agent-emitted proposals must cite at least one source',
            ];
        }

        // Gate 3: temporal proximity (GPS Element 3 — analysis &
        // correlation of evidence). Person's lifetime must overlap
        // with the source's referenced years within the
        // birth-50/death+100 margin. Skipped for change_types where
        // year-references aren't expected (notes_append free-text).
        if (! in_array($changeType, ['notes_append', 'media_link', 'media_metadata_update'], true)) {
            $temporal = $this->checkTemporal($personId, $proposedValue, $evidenceSummary);
            if ($temporal !== null) {
                return [
                    'ok' => false,
                    'gate' => 'temporal_proximity',
                    'severity' => $temporal['severity'],
                    'reason' => sprintf(
                        "Source year %d is %d years outside person's lifetime (%s) — likely wrong person",
                        $temporal['worst_year'],
                        $temporal['gap_years'],
                        ($temporal['person_birth'] ?? '?').'–'.($temporal['person_death'] ?? '?')
                    ),
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * Convenience wrapper that logs the rejection reason to
     * agent_episodes (or laravel.log fallback) so the operator can
     * audit what's being filtered.
     */
    public function validateAndLog(
        int $personId,
        string $changeType,
        ?string $fieldName,
        string $proposedValue,
        string $evidenceSummary,
        array $evidenceSources,
        ?string $agentId
    ): array {
        $result = $this->validate(
            $personId,
            $changeType,
            $fieldName,
            $proposedValue,
            $evidenceSummary,
            $evidenceSources,
            $agentId
        );
        if ($result['ok'] === false) {
            Log::warning('ProposalValidator: REJECTED at creation time', [
                'person_id' => $personId,
                'agent_id' => $agentId,
                'change_type' => $changeType,
                'field_name' => $fieldName,
                'gate' => $result['gate'] ?? 'unknown',
                'reason' => $result['reason'] ?? 'unknown',
                'severity' => $result['severity'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * @return array{worst_year: int, person_birth: int|null, person_death: int|null, gap_years: int, matched_years: array<int,int>, severity: string}|null
     */
    private function checkTemporal(int $personId, string $proposedValue, string $evidenceSummary): ?array
    {
        try {
            $row = DB::selectOne(
                'SELECT birth_date, death_date FROM genealogy_persons WHERE id = ?',
                [$personId]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (! $row) {
            return null;
        }

        return TemporalProximityChecker::check(
            TemporalProximityChecker::extractYear($row->birth_date ?? null),
            TemporalProximityChecker::extractYear($row->death_date ?? null),
            $evidenceSummary.' '.$proposedValue
        );
    }
}
