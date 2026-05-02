<?php

namespace App\Services\Genealogy;

class GenealogyIntakePacketRowQueryService
{
    private const PRIORITY_ORDER_DESC = ['high' => 0, 'medium' => 1, 'low' => 2, '' => 3];

    private const STATUS_ORDER = ['blocked' => 0, 'pending' => 1, 'ready' => 2, 'unknown' => 3];

    /**
     * Filter and sort packet row summaries.
     * Pure, deterministic, non-mutating.
     *
     * @param  array  $rows     Array of packet row summaries
     * @param  array  $options  Filter/sort options
     */
    public function query(array $rows, array $options = []): array
    {
        // Tag each row with its original index for stable sort
        $indexed = [];
        foreach ($rows as $i => $row) {
            $indexed[] = ['_idx' => $i, 'row' => $row];
        }

        $filtered = self::applyFilters($indexed, $options);
        $sorted = self::applySort($filtered, $options);

        return array_values(array_map(fn ($item) => $item['row'], $sorted));
    }

    private static function applyFilters(array $indexed, array $options): array
    {
        if (isset($options['stage_status'])) {
            $allowed = (array) $options['stage_status'];
            $indexed = array_filter($indexed, fn ($item) => in_array($item['row']['stage_status'] ?? 'unknown', $allowed, true));
        }

        if (isset($options['action_priority'])) {
            $allowed = (array) $options['action_priority'];
            $indexed = array_filter($indexed, fn ($item) => in_array($item['row']['action_priority'] ?? null, $allowed, true));
        }

        if (isset($options['proposal_ready'])) {
            $val = (bool) $options['proposal_ready'];
            $indexed = array_filter($indexed, fn ($item) => (bool) ($item['row']['proposal_ready'] ?? false) === $val);
        }

        if (isset($options['has_questions'])) {
            $val = (bool) $options['has_questions'];
            $indexed = array_filter($indexed, fn ($item) => (($item['row']['question_count'] ?? 0) > 0) === $val);
        }

        if (isset($options['search'])) {
            $search = mb_strtolower(trim((string) $options['search']));
            if ($search !== '') {
                $indexed = array_filter($indexed, function ($item) use ($search) {
                    $fields = [
                        mb_strtolower((string) ($item['row']['packet_label'] ?? '')),
                        mb_strtolower((string) ($item['row']['headline'] ?? '')),
                        mb_strtolower((string) ($item['row']['action_label'] ?? '')),
                    ];
                    foreach ($fields as $field) {
                        if (str_contains($field, $search)) {
                            return true;
                        }
                    }

                    return false;
                });
            }
        }

        return array_values($indexed);
    }

    private static function applySort(array $indexed, array $options): array
    {
        $sort = (string) ($options['sort'] ?? '');
        if ($sort === '') {
            return $indexed;
        }

        usort($indexed, function ($a, $b) use ($sort) {
            $cmp = match ($sort) {
                'packet_label_asc' => strcasecmp(
                    (string) ($a['row']['packet_label'] ?? ''),
                    (string) ($b['row']['packet_label'] ?? '')
                ),
                'packet_label_desc' => strcasecmp(
                    (string) ($b['row']['packet_label'] ?? ''),
                    (string) ($a['row']['packet_label'] ?? '')
                ),
                'action_priority_desc' => self::comparePriority($a['row'], $b['row'], false),
                'action_priority_asc' => self::comparePriority($a['row'], $b['row'], true),
                'stage_status' => self::compareStatus($a['row'], $b['row']),
                'question_count_desc' => ($b['row']['question_count'] ?? 0) <=> ($a['row']['question_count'] ?? 0),
                'document_count_desc' => ($b['row']['document_count'] ?? 0) <=> ($a['row']['document_count'] ?? 0),
                default => 0,
            };

            return $cmp !== 0 ? $cmp : $a['_idx'] <=> $b['_idx'];
        });

        return $indexed;
    }

    private static function comparePriority(array $a, array $b, bool $ascending): int
    {
        $map = self::PRIORITY_ORDER_DESC;
        $aVal = $map[(string) ($a['action_priority'] ?? '')] ?? 3;
        $bVal = $map[(string) ($b['action_priority'] ?? '')] ?? 3;

        return $ascending ? ($bVal <=> $aVal) : ($aVal <=> $bVal);
    }

    private static function compareStatus(array $a, array $b): int
    {
        $map = self::STATUS_ORDER;
        $aVal = $map[(string) ($a['stage_status'] ?? 'unknown')] ?? 3;
        $bVal = $map[(string) ($b['stage_status'] ?? 'unknown')] ?? 3;

        return $aVal <=> $bVal;
    }
}
