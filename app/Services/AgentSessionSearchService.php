<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AgentSessionSearchService
{
    public const SCHEMA = 'plos.agent_session_search.v1';

    private const TRUST_BOUNDARY = 'historical_agent_trace_not_fact';

    private const DEFAULT_SOURCES = [
        'session_messages',
        'agent_episodes',
        'agent_episode_summaries',
        'agent_messages',
        'agent_execution_log',
        'scheduled_job_runs',
    ];

    /**
     * Agent tool: search bounded historical agent/session/job trace excerpts.
     */
    public function search(array $params): array
    {
        $query = $this->normalizeQuery((string) ($params['query'] ?? ''));
        if ($query === '') {
            return [
                'success' => false,
                'schema' => self::SCHEMA,
                'error' => 'query parameter is required',
                'result_text' => 'Error: query parameter is required for agent session search.',
            ];
        }

        $limit = $this->clampInt($params['limit'] ?? 8, 1, 20);
        $hours = $this->clampInt($params['hours'] ?? 168, 1, 2160);
        $contextChars = $this->clampInt($params['context_chars'] ?? 90, 40, 240);
        $agentId = $this->optionalString($params['agent_id'] ?? $params['_agent_id'] ?? null, 100);
        $sessionId = $this->optionalString($params['session_id'] ?? $params['_session_id'] ?? null, 100);
        $sources = $this->normalizeSources($params['sources'] ?? null);
        $terms = $this->searchTerms($query);
        $patterns = array_map(fn (string $term): string => '%'.$this->escapeLike($term).'%', $terms);
        $cutoff = now()->subHours($hours)->format('Y-m-d H:i:s');

        $results = [];
        foreach ($sources as $source) {
            try {
                $results = array_merge(
                    $results,
                    $this->collectSource($source, $patterns, $terms, $cutoff, $limit, $contextChars, $agentId, $sessionId)
                );
            } catch (\Throwable $e) {
                Log::debug('AgentSessionSearchService: source search failed', [
                    'source' => $source,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        usort($results, function (array $a, array $b): int {
            $timeCompare = strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return strcmp((string) ($b['source_type'] ?? ''), (string) ($a['source_type'] ?? ''));
        });

        $totalMatches = count($results);
        $results = array_slice($results, 0, $limit);

        return [
            'success' => true,
            'schema' => self::SCHEMA,
            'trust_boundary' => self::TRUST_BOUNDARY,
            'usage_note' => 'Historical agent traces are leads only. Verify facts with primary tools/sources before acting.',
            'query_hash' => hash('sha256', $query),
            'query_preview' => $this->redact($query),
            'window_hours' => $hours,
            'limit' => $limit,
            'sources' => $sources,
            'result_count' => count($results),
            'total_matches_before_limit' => $totalMatches,
            'results' => $results,
            'result_text' => $this->formatResultText($query, $results, $totalMatches, $hours),
        ];
    }

    private function collectSource(
        string $source,
        array $patterns,
        array $terms,
        string $cutoff,
        int $limit,
        int $contextChars,
        ?string $agentId,
        ?string $sessionId
    ): array {
        return match ($source) {
            'session_messages' => $this->searchSessionMessages($patterns, $terms, $cutoff, $limit, $contextChars, $agentId, $sessionId),
            'agent_episodes' => $this->searchAgentEpisodes($patterns, $terms, $cutoff, $limit, $contextChars, $agentId, $sessionId),
            'agent_episode_summaries' => $this->searchEpisodeSummaries($patterns, $terms, $cutoff, $limit, $contextChars, $agentId, $sessionId),
            'agent_messages' => $this->searchAgentMessages($patterns, $terms, $cutoff, $limit, $contextChars, $agentId),
            'agent_execution_log' => $this->searchExecutionLog($patterns, $terms, $cutoff, $limit, $contextChars, $agentId, $sessionId),
            'scheduled_job_runs' => $this->searchScheduledJobRuns($patterns, $terms, $cutoff, $limit, $contextChars, $agentId),
            default => [],
        };
    }

    private function searchSessionMessages(array $patterns, array $terms, string $cutoff, int $limit, int $contextChars, ?string $agentId, ?string $sessionId): array
    {
        if (! Schema::hasTable('agent_sessions')) {
            return [];
        }

        $query = DB::table('agent_sessions as s')
            ->select('s.id', 's.session_id', 's.agent_name', 's.messages', 's.status', 's.created_at', 's.updated_at', 's.last_activity_at')
            ->whereRaw('(s.last_activity_at >= ? OR s.updated_at >= ? OR s.created_at >= ?)', [$cutoff, $cutoff, $cutoff])
            ->orderByDesc(DB::raw('COALESCE(s.last_activity_at, s.updated_at, s.created_at)'))
            ->limit($limit * 3);

        $this->applyOptionalAgentFilter($query, 's.agent_name', $agentId);
        $this->applyOptionalSessionFilter($query, 's.session_id', $sessionId);
        $this->applyLikeSearch($query, ['s.messages'], $patterns);

        $results = [];
        foreach ($query->get() as $row) {
            $messages = json_decode((string) ($row->messages ?? ''), true);
            if (! is_array($messages)) {
                $this->appendResult($results, 'session_messages', $row->id, $row->session_id, $row->agent_name, $row->last_activity_at ?? $row->updated_at ?? $row->created_at, 'messages_json', (string) $row->messages, $terms, $contextChars, [
                    'session_status' => $row->status ?? null,
                ]);

                continue;
            }

            foreach ($messages as $index => $message) {
                if (! is_array($message)) {
                    continue;
                }

                $content = (string) ($message['content'] ?? '');
                if ($content === '' || ! $this->containsAnyTerm($content, $terms)) {
                    continue;
                }

                $role = (string) ($message['role'] ?? 'unknown');
                $this->appendResult($results, 'session_messages', $row->id, $row->session_id, $row->agent_name, (string) ($message['timestamp'] ?? $row->last_activity_at ?? $row->updated_at ?? $row->created_at), "messages.{$index}.content:{$role}", $content, $terms, $contextChars, [
                    'message_role' => $role,
                    'session_status' => $row->status ?? null,
                ]);
            }
        }

        return $results;
    }

    private function searchAgentEpisodes(array $patterns, array $terms, string $cutoff, int $limit, int $contextChars, ?string $agentId, ?string $sessionId): array
    {
        if (! Schema::hasTable('agent_episodes')) {
            return [];
        }

        $query = DB::table('agent_episodes as e')
            ->select('e.id', 'e.agent_id', 'e.session_id', 'e.event_type', 'e.summary', 'e.details', 'e.created_at')
            ->where('e.created_at', '>=', $cutoff)
            ->orderByDesc('e.created_at')
            ->limit($limit * 3);

        $this->applyOptionalAgentFilter($query, 'e.agent_id', $agentId);
        $this->applyOptionalSessionFilter($query, 'e.session_id', $sessionId);
        $this->applyLikeSearch($query, ['e.summary', 'e.details'], $patterns);

        $results = [];
        foreach ($query->get() as $row) {
            $text = $this->containsAnyTerm((string) $row->summary, $terms)
                ? (string) $row->summary
                : (string) ($row->details ?? '');

            $this->appendResult($results, 'agent_episode', $row->id, $row->session_id, $row->agent_id, $row->created_at, $this->containsAnyTerm((string) $row->summary, $terms) ? 'summary' : 'details', $text, $terms, $contextChars, [
                'event_type' => $row->event_type ?? null,
            ]);
        }

        return $results;
    }

    private function searchEpisodeSummaries(array $patterns, array $terms, string $cutoff, int $limit, int $contextChars, ?string $agentId, ?string $sessionId): array
    {
        if (! Schema::hasTable('agent_episode_summaries')) {
            return [];
        }

        $query = DB::table('agent_episode_summaries as aes')
            ->select('aes.id', 'aes.agent_id', 'aes.session_id', 'aes.task', 'aes.summary', 'aes.notes', 'aes.outcome', 'aes.importance', 'aes.created_at')
            ->where('aes.created_at', '>=', $cutoff)
            ->where(function (Builder $builder): void {
                $builder->whereNull('aes.is_archived')->orWhere('aes.is_archived', 0);
            })
            ->orderByDesc('aes.created_at')
            ->limit($limit * 3);

        $this->applyOptionalAgentFilter($query, 'aes.agent_id', $agentId);
        $this->applyOptionalSessionFilter($query, 'aes.session_id', $sessionId);
        $this->applyLikeSearch($query, ['aes.task', 'aes.summary', 'aes.notes'], $patterns);

        $results = [];
        foreach ($query->get() as $row) {
            $field = 'summary';
            $text = (string) $row->summary;
            if (! $this->containsAnyTerm($text, $terms) && $this->containsAnyTerm((string) $row->task, $terms)) {
                $field = 'task';
                $text = (string) $row->task;
            } elseif (! $this->containsAnyTerm($text, $terms) && $this->containsAnyTerm((string) $row->notes, $terms)) {
                $field = 'notes';
                $text = (string) $row->notes;
            }

            $this->appendResult($results, 'agent_episode_summary', $row->id, $row->session_id, $row->agent_id, $row->created_at, $field, $text, $terms, $contextChars, [
                'outcome' => $row->outcome ?? null,
                'importance' => $row->importance ?? null,
            ]);
        }

        return $results;
    }

    private function searchAgentMessages(array $patterns, array $terms, string $cutoff, int $limit, int $contextChars, ?string $agentId): array
    {
        if (! Schema::hasTable('agent_messages')) {
            return [];
        }

        $query = DB::table('agent_messages as m')
            ->select('m.id', 'm.from_agent', 'm.to_agent', 'm.message_type', 'm.subject', 'm.body', 'm.metadata', 'm.priority', 'm.created_at')
            ->where('m.created_at', '>=', $cutoff)
            ->orderByDesc('m.created_at')
            ->limit($limit * 3);

        if ($agentId !== null) {
            $query->where(function (Builder $builder) use ($agentId): void {
                $builder->where('m.from_agent', $agentId)
                    ->orWhere('m.to_agent', $agentId)
                    ->orWhere('m.to_agent', '*');
            });
        }
        $this->applyLikeSearch($query, ['m.subject', 'm.body', 'm.metadata'], $patterns);

        $results = [];
        foreach ($query->get() as $row) {
            $field = $this->containsAnyTerm((string) $row->subject, $terms) ? 'subject' : 'body';
            $text = $field === 'subject' ? (string) $row->subject : (string) $row->body;
            if (! $this->containsAnyTerm($text, $terms) && $this->containsAnyTerm((string) $row->metadata, $terms)) {
                $field = 'metadata';
                $text = (string) $row->metadata;
            }

            $this->appendResult($results, 'agent_message', $row->id, null, $row->to_agent === '*' ? $row->from_agent : $row->to_agent, $row->created_at, $field, $text, $terms, $contextChars, [
                'from_agent' => $row->from_agent ?? null,
                'to_agent' => $row->to_agent ?? null,
                'message_type' => $row->message_type ?? null,
                'priority' => $row->priority ?? null,
            ]);
        }

        return $results;
    }

    private function searchExecutionLog(array $patterns, array $terms, string $cutoff, int $limit, int $contextChars, ?string $agentId, ?string $sessionId): array
    {
        if (! Schema::hasTable('agent_execution_log')) {
            return [];
        }

        $query = DB::table('agent_execution_log as l')
            ->select('l.id', 'l.session_id', 'l.agent_name', 'l.action_type', 'l.action_detail', 'l.risk_level', 'l.context', 'l.outcome', 'l.input_summary', 'l.output_summary', 'l.created_at')
            ->where('l.created_at', '>=', $cutoff)
            ->orderByDesc('l.created_at')
            ->limit($limit * 3);

        $this->applyOptionalAgentFilter($query, 'l.agent_name', $agentId);
        $this->applyOptionalSessionFilter($query, 'l.session_id', $sessionId);
        $this->applyLikeSearch($query, ['l.action_detail', 'l.input_summary', 'l.output_summary', 'l.context'], $patterns);

        $results = [];
        foreach ($query->get() as $row) {
            $field = 'output_summary';
            $text = (string) ($row->output_summary ?? '');
            foreach (['action_detail', 'input_summary', 'context'] as $candidate) {
                if ($this->containsAnyTerm((string) ($row->{$candidate} ?? ''), $terms)) {
                    $field = $candidate;
                    $text = (string) $row->{$candidate};
                    break;
                }
            }

            $this->appendResult($results, 'agent_execution_log', $row->id, $row->session_id, $row->agent_name, $row->created_at, $field, $text, $terms, $contextChars, [
                'action_type' => $row->action_type ?? null,
                'outcome' => $row->outcome ?? null,
                'risk_level' => $row->risk_level ?? null,
            ]);
        }

        return $results;
    }

    private function searchScheduledJobRuns(array $patterns, array $terms, string $cutoff, int $limit, int $contextChars, ?string $agentId): array
    {
        if (! Schema::hasTable('scheduled_job_runs') || ! Schema::hasTable('scheduled_jobs')) {
            return [];
        }

        $query = DB::table('scheduled_job_runs as r')
            ->leftJoin('scheduled_jobs as j', 'j.id', '=', 'r.scheduled_job_id')
            ->select('r.id', 'r.status', 'r.output', 'r.started_at', 'r.completed_at', 'j.name as job_name', 'j.command as job_command')
            ->where('r.started_at', '>=', $cutoff)
            ->orderByDesc('r.started_at')
            ->limit($limit * 3);

        if ($agentId !== null) {
            $agentPatterns = $this->agentNamePatterns($agentId);
            $query->where(function (Builder $builder) use ($agentPatterns): void {
                foreach ($agentPatterns as $pattern) {
                    $builder->orWhereRaw("j.name LIKE ? ESCAPE '\\\\'", [$pattern])
                        ->orWhereRaw("j.command LIKE ? ESCAPE '\\\\'", [$pattern])
                        ->orWhereRaw("r.output LIKE ? ESCAPE '\\\\'", [$pattern]);
                }
            });
        }
        $this->applyLikeSearch($query, ['r.output', 'j.name', 'j.command'], $patterns);

        $results = [];
        foreach ($query->get() as $row) {
            $field = $this->containsAnyTerm((string) $row->job_name, $terms) ? 'job_name' : 'output';
            $text = $field === 'job_name' ? (string) $row->job_name : (string) $row->output;
            if (! $this->containsAnyTerm($text, $terms) && $this->containsAnyTerm((string) $row->job_command, $terms)) {
                $field = 'job_command';
                $text = (string) $row->job_command;
            }

            $this->appendResult($results, 'scheduled_job_run', $row->id, null, null, $row->completed_at ?? $row->started_at, $field, $text, $terms, $contextChars, [
                'job_name' => $row->job_name ?? null,
                'status' => $row->status ?? null,
            ]);
        }

        return $results;
    }

    private function appendResult(array &$results, string $sourceType, mixed $sourceId, ?string $sessionId, ?string $agentId, mixed $timestamp, string $field, string $text, array $terms, int $contextChars, array $metadata = []): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $excerpt = $this->buildExcerpt($text, $terms, $contextChars);
        $results[] = [
            'trust_boundary' => self::TRUST_BOUNDARY,
            'source_type' => $sourceType,
            'source_label' => $this->sourceLabel($sourceType),
            'source_id' => (string) $sourceId,
            'session_id' => $sessionId,
            'agent_id' => $agentId,
            'timestamp' => $this->timestampString($timestamp),
            'field' => $field,
            'excerpt' => $excerpt['excerpt'],
            'context_before' => $excerpt['context_before'],
            'context_after' => $excerpt['context_after'],
            'matched_terms' => $excerpt['matched_terms'],
            'metadata' => $this->compactMetadata($metadata),
        ];
    }

    private function applyLikeSearch(Builder $query, array $columns, array $patterns): void
    {
        $query->where(function (Builder $builder) use ($columns, $patterns): void {
            foreach ($columns as $column) {
                foreach ($patterns as $pattern) {
                    $builder->orWhereRaw("{$column} LIKE ? ESCAPE '\\\\'", [$pattern]);
                }
            }
        });
    }

    private function applyOptionalAgentFilter(Builder $query, string $column, ?string $agentId): void
    {
        if ($agentId !== null) {
            $query->where($column, $agentId);
        }
    }

    private function applyOptionalSessionFilter(Builder $query, string $column, ?string $sessionId): void
    {
        if ($sessionId !== null) {
            $query->where($column, $sessionId);
        }
    }

    private function buildExcerpt(string $text, array $terms, int $contextChars): array
    {
        $clean = $this->compactWhitespace($this->redact($text));
        $match = $this->firstMatch($clean, $terms);
        $start = $match['position'] ?? 0;
        $length = max(1, (int) ($match['length'] ?? 1));
        $beforeStart = max(0, $start - $contextChars);
        $afterStart = min(mb_strlen($clean), $start + $length);
        $before = mb_substr($clean, $beforeStart, $start - $beforeStart);
        $hit = mb_substr($clean, $start, $length);
        $after = mb_substr($clean, $afterStart, $contextChars);

        $excerpt = ($beforeStart > 0 ? '... ' : '').$before.$hit.$after;
        if ($afterStart + $contextChars < mb_strlen($clean)) {
            $excerpt .= ' ...';
        }

        return [
            'excerpt' => trim($excerpt),
            'context_before' => trim($before),
            'context_after' => trim($after),
            'matched_terms' => $match['term'] !== null ? [$this->redact((string) $match['term'])] : [],
        ];
    }

    private function firstMatch(string $text, array $terms): array
    {
        $best = null;
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $position = mb_stripos($text, $term);
            if ($position === false) {
                continue;
            }

            if ($best === null || $position < $best['position']) {
                $best = [
                    'position' => $position,
                    'length' => mb_strlen($term),
                    'term' => $term,
                ];
            }
        }

        return $best ?? ['position' => 0, 'length' => min(32, mb_strlen($text)), 'term' => null];
    }

    private function containsAnyTerm(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && mb_stripos($text, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    private function searchTerms(string $query): array
    {
        $terms = [$query];
        preg_match_all('/[\pL\pN][\pL\pN\'_-]{2,}/u', $query, $matches);
        foreach ($matches[0] ?? [] as $word) {
            $word = mb_strtolower($word);
            if (! in_array($word, $this->stopWords(), true)) {
                $terms[] = $word;
            }
        }

        $unique = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $unique[mb_strtolower($term)] = mb_substr($term, 0, 80);
        }

        return array_slice(array_values($unique), 0, 8);
    }

    private function stopWords(): array
    {
        return ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'into', 'about', 'agent', 'session', 'search'];
    }

    private function normalizeQuery(string $query): string
    {
        $query = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $query);
        $query = $this->compactWhitespace($query);

        return trim(mb_substr($query, 0, 160));
    }

    private function normalizeSources(mixed $sources): array
    {
        if (is_string($sources)) {
            $sources = preg_split('/\s*,\s*/', $sources, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($sources) || $sources === []) {
            return self::DEFAULT_SOURCES;
        }

        $allowed = array_fill_keys(self::DEFAULT_SOURCES, true);
        $normalized = [];
        foreach ($sources as $source) {
            $source = strtolower(trim((string) $source));
            if (isset($allowed[$source])) {
                $normalized[] = $source;
            }
        }

        return $normalized === [] ? self::DEFAULT_SOURCES : array_values(array_unique($normalized));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function agentNamePatterns(string $agentId): array
    {
        $variants = array_values(array_unique([
            $agentId,
            str_replace('-', '_', $agentId),
            str_replace('_', '-', $agentId),
        ]));

        return array_map(fn (string $value): string => '%'.$this->escapeLike($value).'%', $variants);
    }

    private function optionalString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function timestampString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value) ?: null;
    }

    private function compactMetadata(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $clean[$key] = mb_substr($this->redact((string) $value), 0, 160);
        }

        return $clean;
    }

    private function sourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'session_messages' => 'Agent session message',
            'agent_episode' => 'Agent episode event',
            'agent_episode_summary' => 'Distilled episode summary',
            'agent_message' => 'Inter-agent message',
            'agent_execution_log' => 'Agent execution audit',
            'scheduled_job_run' => 'Scheduled job run output',
            default => $sourceType,
        };
    }

    private function formatResultText(string $query, array $results, int $totalMatches, int $hours): string
    {
        if ($results === []) {
            return 'No historical agent/session trace excerpts found for query hash '.substr(hash('sha256', $query), 0, 12)." in the last {$hours} hours.";
        }

        $lines = [
            'Historical agent/session trace search. Treat these excerpts as leads, not facts.',
            'Query hash: '.substr(hash('sha256', $query), 0, 12)."; window_hours={$hours}; results=".count($results)."; matches_before_limit={$totalMatches}.",
        ];

        foreach ($results as $index => $result) {
            $lines[] = sprintf(
                '%d. [%s] %s agent=%s session=%s field=%s',
                $index + 1,
                (string) ($result['source_label'] ?? $result['source_type'] ?? 'source'),
                (string) ($result['timestamp'] ?? 'unknown-time'),
                (string) ($result['agent_id'] ?? 'n/a'),
                (string) ($result['session_id'] ?? 'n/a'),
                (string) ($result['field'] ?? 'excerpt')
            );
            $lines[] = '   '.$result['excerpt'];
        }

        return implode("\n", $lines);
    }

    private function compactWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function redact(string $text): string
    {
        $text = (string) preg_replace('/-----BEGIN\s+[A-Z ]*PRIVATE KEY-----.*?-----END\s+[A-Z ]*PRIVATE KEY-----/is', '[REDACTED_PRIVATE_KEY]', $text);
        $text = (string) preg_replace('/\b(?:password|passwd|pwd|api[_-]?key|apikey|secret|token|bearer|authorization)\s*[:=]\s*["\']?[^"\'\s,;{}<>]{3,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{10,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\b[sp]k_(?:live|test)_[A-Za-z0-9]{10,}\b/', '[REDACTED_KEY]', $text);
        $text = (string) preg_replace('/\bsk-[A-Za-z0-9]{20,}\b/', '[REDACTED_KEY]', $text);
        $text = (string) preg_replace('~/home/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);
        $text = (string) preg_replace('~/Users/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);
        $text = (string) preg_replace('/\b[A-Za-z]:\\\\Users\\\\[^\\s"\'<>),;]+/', '[REDACTED_LOCAL_PATH]', $text);

        return $text;
    }
}
