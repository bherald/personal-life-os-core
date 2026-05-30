<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AgentContextReconcileSignalService
{
    public const TABLE = 'agent_context_reconcile_events';

    /**
     * @param  array<int, array<string, mixed>>  $signals
     */
    public function recordSignals(array $signals, array $options = []): int
    {
        if (! (bool) config('agents.retrieved_context_fencing.reconcile_events_enabled', true)
            || ! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $maxSignals = max(0, (int) config('agents.retrieved_context_fencing.max_reconcile_events_per_run', 25));
        if ($maxSignals === 0) {
            return 0;
        }

        $recorded = 0;
        $now = CarbonImmutable::now()->toDateTimeString();

        foreach (array_slice($signals, 0, $maxSignals) as $signal) {
            $normalized = $this->normalizeSignal($signal, $options, $now);
            if ($normalized === null) {
                continue;
            }

            try {
                $existing = DB::table(self::TABLE)
                    ->select(['id', 'event_count'])
                    ->where('event_key', $normalized['event_key'])
                    ->first();

                if ($existing === null) {
                    DB::table(self::TABLE)->insert($normalized);
                } else {
                    DB::table(self::TABLE)
                        ->where('id', (int) $existing->id)
                        ->update(array_merge(
                            $normalized,
                            [
                                'event_count' => ((int) ($existing->event_count ?? 0)) + 1,
                                'first_seen_at' => DB::raw('first_seen_at'),
                                'created_at' => DB::raw('created_at'),
                                'resolved_at' => null,
                            ]
                        ));
                }

                $recorded++;
            } catch (\Throwable $e) {
                Log::debug('AgentContextReconcileSignal: record failed', [
                    'source_system' => $normalized['source_system'] ?? null,
                    'reason' => $normalized['reason'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $recorded;
    }

    /**
     * @return array<string, mixed>
     */
    public function recentSignals(int $hours = 72, int $limit = 500): array
    {
        $hours = max(1, $hours);
        $limit = max(1, min(5000, $limit));

        if (! Schema::hasTable(self::TABLE)) {
            return [
                'available' => false,
                'event_hours' => $hours,
                'open_events' => 0,
                'events' => [],
                'reason_counts' => [],
                'source_state_counts' => [],
                'sample_hashes' => [],
            ];
        }

        $since = CarbonImmutable::now()->subHours($hours)->toDateTimeString();
        $rows = DB::table(self::TABLE)
            ->select([
                'id',
                'event_key',
                'source_system',
                'source_state',
                'reason',
                'rag_document_id',
                'source_id_hash',
                'title_hash',
                'agent_id',
                'event_count',
                'first_seen_at',
                'last_seen_at',
            ])
            ->whereNull('resolved_at')
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();

        $events = [];
        $reasonCounts = [];
        $sourceStateCounts = [];
        $samples = [];

        foreach ($rows as $row) {
            $reason = (string) ($row->reason ?? 'unknown');
            $sourceState = (string) ($row->source_state ?? 'unknown');
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            $sourceStateCounts[$sourceState] = ($sourceStateCounts[$sourceState] ?? 0) + 1;

            $event = [
                'id' => (int) $row->id,
                'event_key' => (string) $row->event_key,
                'event_ref' => substr((string) $row->event_key, 0, 12),
                'source_system' => (string) $row->source_system,
                'source_state' => $sourceState,
                'reason' => $reason,
                'rag_document_id' => $row->rag_document_id === null ? null : (int) $row->rag_document_id,
                'source_id_hash' => $row->source_id_hash === null ? null : (string) $row->source_id_hash,
                'source_ref' => $row->source_id_hash === null ? null : substr((string) $row->source_id_hash, 0, 12),
                'title_ref' => $row->title_hash === null ? null : substr((string) $row->title_hash, 0, 12),
                'agent_id' => $row->agent_id === null ? null : (string) $row->agent_id,
                'event_count' => (int) ($row->event_count ?? 0),
                'first_seen_at' => $row->first_seen_at === null ? null : (string) $row->first_seen_at,
                'last_seen_at' => $row->last_seen_at === null ? null : (string) $row->last_seen_at,
            ];

            $events[] = $event;

            if (count($samples) < 10) {
                $samples[] = [
                    'event_ref' => $event['event_ref'],
                    'source_system' => $event['source_system'],
                    'source_state' => $event['source_state'],
                    'reason' => $event['reason'],
                    'source_ref' => $event['source_ref'],
                    'title_ref' => $event['title_ref'],
                    'event_count' => $event['event_count'],
                ];
            }
        }

        arsort($reasonCounts);
        arsort($sourceStateCounts);

        return [
            'available' => true,
            'event_hours' => $hours,
            'open_events' => count($events),
            'events' => $events,
            'reason_counts' => $reasonCounts,
            'source_state_counts' => $sourceStateCounts,
            'sample_hashes' => $samples,
        ];
    }

    /**
     * @param  array<int, int>  $ragDocumentIds
     */
    public function markResolvedByRagDocumentIds(array $ragDocumentIds): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ragDocumentIds),
            fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return 0;
        }

        $resolved = 0;
        $now = CarbonImmutable::now()->toDateTimeString();

        foreach (array_chunk($ids, 500) as $chunk) {
            $resolved += DB::table(self::TABLE)
                ->whereIn('rag_document_id', $chunk)
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function normalizeSignal(array $signal, array $options, string $now): ?array
    {
        $sourceSystem = $this->boundedString($signal['source_system'] ?? null, 50);
        if (! in_array($sourceSystem, ['joplin', 'joplin_attachment'], true)) {
            return null;
        }

        $sourceState = $this->boundedString($signal['source_state'] ?? $signal['state'] ?? null, 40) ?? 'unknown';
        $reason = $this->boundedString($signal['reason'] ?? $signal['source_reason'] ?? null, 120) ?? 'unknown';
        $ragDocumentId = $this->nullableInt($signal['rag_document_id'] ?? null);
        $sourceId = $this->boundedString($signal['source_id'] ?? null, 255);
        $sourceIdHash = $sourceId === null ? null : hash('sha256', $sourceId);

        if ($ragDocumentId === null && $sourceIdHash === null) {
            return null;
        }

        $titleHash = $this->boundedString($signal['title_hash'] ?? null, 64);
        $agentId = $this->boundedString($options['agent_id'] ?? null, 100);
        $task = $this->boundedString($options['task'] ?? null, 4096);
        $session = $this->boundedString($options['session_id'] ?? $options['run_memory_session_id'] ?? null, 255);

        $eventKey = hash('sha256', implode('|', [
            'retrieved_context_fence',
            $sourceSystem,
            $sourceState,
            $reason,
            $ragDocumentId ?? 'no-rag-id',
            $sourceIdHash ?? 'no-source-id',
        ]));

        return [
            'event_key' => $eventKey,
            'source_system' => $sourceSystem,
            'source_state' => $sourceState,
            'reason' => $reason,
            'rag_document_id' => $ragDocumentId,
            'source_id_hash' => $sourceIdHash,
            'title_hash' => $titleHash,
            'agent_id' => $agentId,
            'session_hash' => $session === null ? null : hash('sha256', $session),
            'task_hash' => $task === null ? null : hash('sha256', $task),
            'event_count' => 1,
            'metadata' => json_encode([
                'origin' => 'retrieved_context_fence',
                'schema' => 'hwr-015-context-reconcile-event-v1',
            ], JSON_UNESCAPED_SLASHES),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function boundedString(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
