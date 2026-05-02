<?php

namespace App\Services\AgentLoop;

/**
 * Framework C1 — Compact Run Memory Service.
 *
 * A small, in-process, structured per-run memory slice. Lives in PHP during an
 * agent run, is injected into the system prompt on every loop iteration so the
 * LLM can see it without us resending the full transcript, and is updated as
 * tool results arrive. Purpose: replace prompt accumulation with compact
 * factual run state.
 *
 * Standard schema (per WS3 of docs/plos-benefit-harvest-implementation-plan.md):
 *   - goal               — the task the agent was given
 *   - constraints        — profile + permission + policy notes that matter
 *   - decisions          — things already concluded this run
 *   - open_questions     — known unknowns the agent is still chasing
 *   - verification_state — tool-confirmed vs speculative claims
 *
 * Storage: in-memory, keyed by sessionId. No new DB tables. Cleared via
 * clear($sessionId) at end of run (success OR failure).
 *
 * Rendering is token-frugal. If the slice is effectively empty, the rendered
 * fragment is the empty string so the caller can skip it cleanly.
 */
class RunMemoryService
{
    /**
     * Per-session in-memory slice store.
     *
     * Shape:
     *   [
     *     sessionId => [
     *       'goal'               => string,
     *       'constraints'        => string[],
     *       'decisions'          => array<int, ['decision'=>string,'evidence'=>array]>,
     *       'open_questions'     => array<int, ['question'=>string,'resolution'=>?string,'status'=>'open'|'resolved']>,
     *       'verification_state' => array<string, 'confirmed'|'rejected'|'speculative'>,
     *       'started_at'         => float (microtime),
     *     ]
     *   ]
     */
    private static array $store = [];

    /**
     * Hard cap on the rendered fragment size (bytes).
     * Intended to keep system prompt growth bounded; callers may render short.
     */
    public const MAX_FRAGMENT_BYTES = 2048;

    /**
     * Start a run memory slice for a session.
     */
    public function start(string $sessionId, string $goal, array $constraints): void
    {
        if ($sessionId === '') {
            return;
        }

        self::$store[$sessionId] = [
            'goal' => $this->trimLine($goal, 500),
            'constraints' => array_values(array_unique(array_filter(array_map(
                fn ($c) => is_string($c) ? $this->trimLine($c, 200) : null,
                $constraints
            )))),
            'decisions' => [],
            'open_questions' => [],
            'verification_state' => [],
            'started_at' => microtime(true),
        ];
    }

    /**
     * Record a decision reached during the run. Dedupes on decision text.
     *
     * @param  array  $evidence  Optional small array of evidence refs (strings/ids).
     */
    public function recordDecision(string $sessionId, string $decision, array $evidence = []): void
    {
        if (! $this->has($sessionId)) {
            return;
        }
        $decision = $this->trimLine($decision, 300);
        if ($decision === '') {
            return;
        }

        // Dedupe: skip if an identical decision text is already recorded
        foreach (self::$store[$sessionId]['decisions'] as $d) {
            if ($d['decision'] === $decision) {
                return;
            }
        }

        self::$store[$sessionId]['decisions'][] = [
            'decision' => $decision,
            'evidence' => array_values(array_filter(array_map(
                fn ($e) => is_scalar($e) ? $this->trimLine((string) $e, 120) : null,
                $evidence
            ))),
        ];
    }

    /**
     * Record a known unknown the agent is still chasing. Dedupes on question text.
     */
    public function recordOpenQuestion(string $sessionId, string $question): void
    {
        if (! $this->has($sessionId)) {
            return;
        }
        $question = $this->trimLine($question, 300);
        if ($question === '') {
            return;
        }

        foreach (self::$store[$sessionId]['open_questions'] as $q) {
            if ($q['question'] === $question) {
                return;
            }
        }

        self::$store[$sessionId]['open_questions'][] = [
            'question' => $question,
            'resolution' => null,
            'status' => 'open',
        ];
    }

    /**
     * Resolve a previously recorded open question.
     *
     * If the question is not yet recorded, it's added in a resolved state so
     * the audit trail is still captured.
     */
    public function resolveOpenQuestion(string $sessionId, string $question, string $resolution): void
    {
        if (! $this->has($sessionId)) {
            return;
        }
        $question = $this->trimLine($question, 300);
        $resolution = $this->trimLine($resolution, 300);
        if ($question === '' || $resolution === '') {
            return;
        }

        foreach (self::$store[$sessionId]['open_questions'] as &$q) {
            if ($q['question'] === $question) {
                $q['resolution'] = $resolution;
                $q['status'] = 'resolved';
                return;
            }
        }
        unset($q);

        self::$store[$sessionId]['open_questions'][] = [
            'question' => $question,
            'resolution' => $resolution,
            'status' => 'resolved',
        ];
    }

    /**
     * Update verification state for a claim.
     *
     * @param  string  $state  One of: 'confirmed', 'rejected', 'speculative'
     */
    public function updateVerificationState(string $sessionId, string $claim, string $state): void
    {
        if (! $this->has($sessionId)) {
            return;
        }
        $claim = $this->trimLine($claim, 240);
        if ($claim === '') {
            return;
        }
        $allowed = ['confirmed', 'rejected', 'speculative'];
        if (! in_array($state, $allowed, true)) {
            return;
        }

        self::$store[$sessionId]['verification_state'][$claim] = $state;
    }

    /**
     * Render a compact system-prompt fragment for the current slice.
     *
     * Returns '' if the slice is empty / not started, so the caller can skip
     * it cleanly without emitting a bare heading.
     */
    public function renderSystemPromptFragment(string $sessionId): string
    {
        if (! $this->has($sessionId)) {
            return '';
        }

        $slice = self::$store[$sessionId];

        $hasContent = ($slice['goal'] !== '')
            || ! empty($slice['constraints'])
            || ! empty($slice['decisions'])
            || ! empty($slice['open_questions'])
            || ! empty($slice['verification_state']);

        if (! $hasContent) {
            return '';
        }

        $lines = ['## Run Memory (compact state)'];

        if ($slice['goal'] !== '') {
            $lines[] = 'Goal: ' . $slice['goal'];
        }

        if (! empty($slice['constraints'])) {
            $lines[] = 'Constraints:';
            foreach (array_slice($slice['constraints'], 0, 8) as $c) {
                $lines[] = '- ' . $c;
            }
        }

        if (! empty($slice['decisions'])) {
            $lines[] = 'Decisions:';
            foreach (array_slice($slice['decisions'], -8) as $d) {
                $suffix = '';
                if (! empty($d['evidence'])) {
                    $suffix = ' [' . implode(',', array_slice($d['evidence'], 0, 3)) . ']';
                }
                $lines[] = '- ' . $d['decision'] . $suffix;
            }
        }

        $open = array_values(array_filter(
            $slice['open_questions'],
            fn ($q) => ($q['status'] ?? 'open') === 'open'
        ));
        $resolved = array_values(array_filter(
            $slice['open_questions'],
            fn ($q) => ($q['status'] ?? 'open') === 'resolved'
        ));

        if (! empty($open)) {
            $lines[] = 'Open questions:';
            foreach (array_slice($open, 0, 8) as $q) {
                $lines[] = '- ' . $q['question'];
            }
        }

        if (! empty($resolved)) {
            $lines[] = 'Resolved:';
            foreach (array_slice($resolved, -5) as $q) {
                $lines[] = '- ' . $q['question'] . ' -> ' . ($q['resolution'] ?? '');
            }
        }

        if (! empty($slice['verification_state'])) {
            $confirmed = [];
            $rejected = [];
            $speculative = [];
            foreach ($slice['verification_state'] as $claim => $state) {
                if ($state === 'confirmed') {
                    $confirmed[] = $claim;
                } elseif ($state === 'rejected') {
                    $rejected[] = $claim;
                } else {
                    $speculative[] = $claim;
                }
            }
            $lines[] = 'Verification:';
            if (! empty($confirmed)) {
                $lines[] = '- confirmed: ' . implode('; ', array_slice($confirmed, 0, 6));
            }
            if (! empty($rejected)) {
                $lines[] = '- rejected: ' . implode('; ', array_slice($rejected, 0, 4));
            }
            if (! empty($speculative)) {
                $lines[] = '- speculative: ' . implode('; ', array_slice($speculative, 0, 4));
            }
        }

        $rendered = implode("\n", $lines);

        // Hard byte cap — if the fragment ever exceeds MAX_FRAGMENT_BYTES (should be rare
        // given per-section caps), truncate and tag so the LLM knows state was elided.
        if (strlen($rendered) > self::MAX_FRAGMENT_BYTES) {
            $rendered = substr($rendered, 0, self::MAX_FRAGMENT_BYTES - 30)
                . "\n[run memory truncated]";
        }

        return $rendered;
    }

    /**
     * Return the current slice for debugging / telemetry.
     */
    public function snapshot(string $sessionId): array
    {
        if (! $this->has($sessionId)) {
            return [];
        }

        return self::$store[$sessionId];
    }

    /**
     * Clear the slice for a session. Call at end of run (success OR failure).
     */
    public function clear(string $sessionId): void
    {
        unset(self::$store[$sessionId]);
    }

    /**
     * Clear all slices — testing convenience.
     */
    public static function flushAll(): void
    {
        self::$store = [];
    }

    private function has(string $sessionId): bool
    {
        return $sessionId !== '' && isset(self::$store[$sessionId]);
    }

    private function trimLine(string $s, int $max): string
    {
        // Collapse whitespace/newlines — run memory must stay on single lines so
        // the rendered fragment is predictable.
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? '';
        if (mb_strlen($s) > $max) {
            $s = mb_substr($s, 0, $max - 1) . '…';
        }
        return $s;
    }
}
