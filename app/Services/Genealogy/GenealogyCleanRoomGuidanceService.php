<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Str;

class GenealogyCleanRoomGuidanceService
{
    public const VERSION = 'gramps-clean-room-2026-06-03';

    /**
     * Build PLOS-owned Genea guidance inspired by public Gramps/Gramps Web behavior.
     * No upstream source, prompts, fixtures, UI copy, or implementation details are reused.
     */
    public function buildPromptBlock(): string
    {
        return implode("\n", [
            '',
            'Gramps clean-room Genea discipline for this task:',
            '- Treat Gramps and Gramps Web as reference behavior only; do not copy upstream code, prompts, UI text, fixtures, or workflow files.',
            '- Produce evidence packets, not bare conclusions. Every proposed fact or relationship needs a subject, claim, source label, citation state, identity bridge, confidence, and review action.',
            '- Keep a hypothesis ledger. Use states such as confirmed, plausible, contradicted, wrong_subject, unresolved, and negative_search instead of collapsing weak leads into facts.',
            '- Treat source_search_all, LOC, and NARA same-name results without a source-text identity bridge as leads only. Put them in HYPOTHESIS_LEDGER or AGENT_ACTIVITY_TRACE as reject, lead, or negative_search; do not emit EVIDENCE_PACKETS with review_action=review_packet for those hits.',
            '- Make agent activity visible. Summarize searches, opened records, API/public-source checks, rejected same-name hits, downloads, proposed changes, and blocked steps.',
            '- Keep long-running work reviewable. If work is incomplete, say what ran, what remains, what blocked it, and the next bounded action.',
            '- Review before writeback. Drafts, source-backed proposals, person creation, canonical facts, and writeback must stay inside existing bounded tools and review/apply gates.',
            '- Negative evidence counts. Record searched-but-not-found sources when they reduce a hypothesis or explain why a task is deferred.',
            '- Do not request broad internet access. If another public source, API, scraper, archive catalog, or authenticated site would help, report it as a source/tool gap for separate vetting.',
            '- Do not use face clusters, image similarity, private notes, or unsourced AI summaries as identity proof.',
            '',
            'Use these JSON final markers when possible. If there is nothing to report, use none.',
            'EVIDENCE_PACKETS: [{"claim":"...","subject":"...","source_label":"...","citation_state":"direct|inferred|negative|conflict","identity_bridge":"...","confidence":"low|medium|high","review_action":"none|review_packet|writeback_candidate"}]',
            'HYPOTHESIS_LEDGER: [{"hypothesis":"...","state":"confirmed|plausible|contradicted|wrong_subject|unresolved|negative_search","basis":"...","next_action":"..."}]',
            'AGENT_ACTIVITY_TRACE: [{"action":"search|open|download|reject|propose|writeback_blocked","target":"...","result":"...","reason":"..."}]',
            'SOURCE_TOOL_GAPS: [{"source_or_tool":"...","purpose":"...","access_type":"public|api|scrape|authenticated|operator_manual","risk":"low|medium|high","vetting_needed":"..."}]',
            'REVIEW_GATES: {"review_first":true,"writeback_blocked_or_bounded":true,"private_payload_avoided":true,"face_evidence_avoided":true}',
        ])."\n";
    }

    /**
     * @return array{
     *     version:string,
     *     evidence_packets:list<array<string,mixed>>,
     *     hypothesis_ledger:list<array<string,mixed>>,
     *     agent_activity_trace:list<array<string,mixed>>,
     *     source_tool_gaps:list<array<string,mixed>>,
     *     review_gates:array<string,mixed>,
     *     marker_warnings:list<string>
     * }
     */
    public function parseFinalMarkers(string $response): array
    {
        $warnings = [];

        $evidencePackets = $this->decodeListMarker($response, 'EVIDENCE_PACKETS', $warnings);
        $hypothesisLedger = $this->decodeListMarker($response, 'HYPOTHESIS_LEDGER', $warnings);
        $activityTrace = $this->decodeListMarker($response, 'AGENT_ACTIVITY_TRACE', $warnings);
        $sourceToolGaps = $this->decodeListMarker($response, 'SOURCE_TOOL_GAPS', $warnings);
        $reviewGates = $this->decodeMapMarker($response, 'REVIEW_GATES', $warnings);

        return [
            'version' => self::VERSION,
            'evidence_packets' => $this->limitRows($evidencePackets, 12),
            'hypothesis_ledger' => $this->limitRows($hypothesisLedger, 12),
            'agent_activity_trace' => $this->limitRows($activityTrace, 20),
            'source_tool_gaps' => $this->limitRows($sourceToolGaps, 12),
            'review_gates' => $this->limitMap($reviewGates, 12),
            'marker_warnings' => $warnings,
        ];
    }

    public function summary(): array
    {
        return [
            'version' => self::VERSION,
            'source_posture' => 'clean_room_reference_only',
            'requires_evidence_packets' => true,
            'requires_hypothesis_ledger' => true,
            'requires_activity_trace' => true,
            'requires_source_tool_gap_reporting' => true,
            'writeback_posture' => 'review_first_bounded_tools_only',
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array<string,mixed>>
     */
    private function decodeListMarker(string $response, string $label, array &$warnings): array
    {
        $value = $this->extractMarker($response, $label);
        if ($this->isNone($value)) {
            return [];
        }

        $decoded = $this->decodeJson($label, $value, $warnings);
        if (! is_array($decoded)) {
            return [];
        }

        if (! array_is_list($decoded)) {
            $warnings[] = "{$label} was JSON but not a list.";

            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $row): array => is_array($row) ? $this->normalizeRow($row) : [], $decoded),
            static fn (array $row): bool => $row !== []
        ));
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function decodeMapMarker(string $response, string $label, array &$warnings): array
    {
        $value = $this->extractMarker($response, $label);
        if ($this->isNone($value)) {
            return [];
        }

        $decoded = $this->decodeJson($label, $value, $warnings);
        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            $warnings[] = "{$label} was JSON but not an object.";

            return [];
        }

        return $this->normalizeRow($decoded);
    }

    private function extractMarker(string $response, string $label): ?string
    {
        if (preg_match('/^'.preg_quote($label, '/').':\s*(.+)$/mi', $response, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function isNone(?string $value): bool
    {
        return $value === null || $value === '' || strtolower($value) === 'none';
    }

    /**
     * @param  list<string>  $warnings
     */
    private function decodeJson(string $label, ?string $value, array &$warnings): mixed
    {
        if ($this->isNone($value)) {
            return null;
        }

        try {
            return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $warnings[] = "{$label} JSON invalid: ".$exception->getMessage();

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $key = $this->compact((string) $key, 80);
            if ($key === '') {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $normalized[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $this->compact(json_encode($value, JSON_UNESCAPED_SLASHES) ?: '', 500);

                continue;
            }

            $normalized[$key] = $this->compact((string) $value, 500);
        }

        return $normalized;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function limitRows(array $rows, int $limit): array
    {
        return array_slice($rows, 0, max(0, $limit));
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function limitMap(array $row, int $limit): array
    {
        return array_slice($row, 0, max(0, $limit), true);
    }

    private function compact(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', Str::ascii($text)) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'...' : $text;
    }
}
