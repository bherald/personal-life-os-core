<?php

namespace App\Services\Ops;

class AiModelUpdateReviewPresentationService
{
    private const DECISIONS = [
        'add_candidate',
        'retain',
        'promote_for_eval',
        'retire',
        'reconfigure_codex_role',
    ];

    private const PRIORITIES = ['high', 'medium', 'low'];

    /** @return array<string, mixed> */
    public function project(array $details): array
    {
        $actions = [];
        $blockers = [];
        foreach (array_slice((array) ($details['actions'] ?? []), 0, 20) as $index => $action) {
            if (! is_array($action)) {
                $blockers[] = 'invalid_action_'.($index + 1);

                continue;
            }

            $projected = $this->projectAction($action);
            if ($projected['resource_id'] === '' || $projected['benefit'] === '') {
                $blockers[] = 'incomplete_action_'.($index + 1);
            }
            if ($projected['source_urls'] === []) {
                $blockers[] = 'action_without_public_source_'.($index + 1);
            }
            $actions[] = $projected;
        }

        if (($details['schema'] ?? null) !== 'plos.llm_portfolio_review.v1') {
            $blockers[] = 'unsupported_packet_schema';
        }
        if ($actions === []) {
            $blockers[] = 'no_review_actions';
        }

        $decisionCounts = array_fill_keys(self::DECISIONS, 0);
        foreach ($actions as $action) {
            $decision = $action['decision'];
            $decisionCounts[$decision] = ($decisionCounts[$decision] ?? 0) + 1;
        }
        $decisionCounts = array_filter($decisionCounts, static fn (int $count): bool => $count > 0);
        $highImpactCount = array_sum(array_intersect_key($decisionCounts, array_flip([
            'promote_for_eval',
            'retire',
            'reconfigure_codex_role',
        ])));

        $execution = is_array($details['execution_contract'] ?? null) ? $details['execution_contract'] : [];

        return [
            'schema' => 'plos.ai_model_update_review_ui.v1',
            'review_ready' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'summary' => [
                'action_count' => count($actions),
                'retain_count' => (int) ($decisionCounts['retain'] ?? 0),
                'candidate_count' => (int) ($decisionCounts['add_candidate'] ?? 0),
                'high_impact_count' => $highImpactCount,
                'decision_counts' => $decisionCounts,
            ],
            'actions' => $actions,
            'approval_effect' => [
                'records_operator_acceptance' => true,
                'creates_bounded_execution_authority' => (bool) ($execution['approval_creates_execution_authority'] ?? true),
                'automatic_install_or_route_change' => false,
                'automatic_model_promotion' => false,
                'automatic_retirement' => false,
                'prompt_compression' => false,
                'precompaction' => false,
                'face_or_photo_identity_writeback' => false,
            ],
            'approval_label' => $this->approvalLabel($decisionCounts),
            'confirmation_label' => $highImpactCount > 0
                ? 'I reviewed every action and authorize only the bounded validation and execution plan shown.'
                : 'I reviewed every action and approve only the retain and bounded evaluation scope shown.',
            'validation_warnings' => [
                'discarded_source_url_count' => max(0, (int) data_get($details, 'validation_warnings.discarded_source_url_count', 0)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function projectAction(array $action): array
    {
        $fit = is_array($action['fit'] ?? null) ? $action['fit'] : [];
        $decision = $this->enum($action['decision'] ?? null, self::DECISIONS, 'add_candidate');

        return [
            'resource_type' => $this->safeCode($action['resource_type'] ?? null),
            'resource_id' => $this->safeText($action['resource_id'] ?? null, 180),
            'decision' => $decision,
            'priority' => $this->enum($action['priority'] ?? null, self::PRIORITIES, 'medium'),
            'benefit' => $this->safeText($action['benefit'] ?? null, 1000),
            'exact_model_revision' => $this->safeText($action['exact_model_revision'] ?? null, 500),
            'license' => $this->safeText($action['license'] ?? null, 500),
            'evidence_grade' => $this->enum($action['evidence_grade'] ?? null, ['A', 'B', 'C', 'D'], 'D'),
            'feedback_signal' => $this->safeCode($action['feedback_signal'] ?? null),
            'execution_mode' => $this->safeCode($action['execution_mode'] ?? null),
            'capability_lanes' => $this->safeTextList($action['capability_lanes'] ?? [], 30, 100),
            'target_instance_ids' => $this->safeTextList($fit['target_instance_ids'] ?? [], 20, 120),
            'fit' => [
                'overall' => $this->safeCode($fit['overall_fit'] ?? null),
                'hardware' => $this->safeCode($fit['hardware_fit'] ?? null),
                'context' => $this->safeCode($fit['context_fit'] ?? null),
                'capability' => $this->safeCode($fit['capability_fit'] ?? null),
                'privacy' => $this->safeCode($fit['privacy_fit'] ?? null),
                'failure_domain_effect' => $this->safeText($fit['failure_domain_effect'] ?? null, 700),
            ],
            'evidence' => $this->safeTextList($action['evidence'] ?? [], 12, 800),
            'risks' => $this->safeTextList($action['risks'] ?? [], 12, 800),
            'required_validation' => $this->safeTextList($action['required_validation'] ?? [], 12, 1000),
            'source_urls' => $this->publicSourceUrls($action['source_urls'] ?? []),
        ];
    }

    /** @return list<string> */
    private function safeTextList(mixed $values, int $limit, int $length): array
    {
        $safe = [];
        foreach (array_slice(is_array($values) ? $values : [], 0, $limit) as $value) {
            $text = $this->safeText($value, $length);
            if ($text !== '') {
                $safe[] = $text;
            }
        }

        return array_values(array_unique($safe));
    }

    /** @return list<string> */
    private function publicSourceUrls(mixed $values): array
    {
        $urls = [];
        foreach (array_slice(is_array($values) ? $values : [], 0, 20) as $value) {
            if (! is_string($value)) {
                continue;
            }
            $url = trim($value);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = parse_url($url, PHP_URL_HOST);
            $user = parse_url($url, PHP_URL_USER);
            $pass = parse_url($url, PHP_URL_PASS);
            if (! in_array($scheme, ['http', 'https'], true)
                || ! is_string($host)
                || $host === ''
                || $user !== null
                || $pass !== null
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || $this->hasSensitiveQueryKey($url)) {
                continue;
            }
            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    private function hasSensitiveQueryKey(string $url): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (array_keys($query) as $key) {
            if (in_array(strtolower((string) $key), [
                'access_token', 'api_key', 'apikey', 'key', 'password', 'secret', 'signature', 'token',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function safeText(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = trim((string) $value);
        $text = (string) preg_replace([
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i',
            '/\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=\-]{8,}\b/i',
            '/\/(?:home|Users|root)\/[^\s,"\')\]}]+/',
            '/[A-Za-z]:\\\\[^\s,"\')\]}]+/',
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
        ], [
            'Bearer [redacted]',
            '[redacted token]',
            '[redacted path]',
            '[redacted path]',
            '',
        ], $text);

        return mb_substr($text, 0, $limit);
    }

    private function safeCode(mixed $value): string
    {
        $code = strtolower($this->safeText($value, 120));

        return preg_match('/^[a-z0-9_.:\/-]+$/', $code) === 1 ? $code : '';
    }

    private function enum(mixed $value, array $allowed, string $fallback): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return in_array($text, $allowed, true) ? $text : $fallback;
    }

    private function approvalLabel(array $decisionCounts): string
    {
        $parts = [];
        if (($decisionCounts['retain'] ?? 0) > 0) {
            $parts[] = $decisionCounts['retain'].' retain';
        }
        if (($decisionCounts['add_candidate'] ?? 0) > 0) {
            $parts[] = $decisionCounts['add_candidate'].' candidate';
        }
        $other = array_sum(array_intersect_key($decisionCounts, array_flip([
            'promote_for_eval',
            'retire',
            'reconfigure_codex_role',
        ])));
        if ($other > 0) {
            $parts[] = $other.' controlled change';
        }

        return 'Approve '.($parts !== [] ? implode(' + ', $parts) : 'review');
    }
}
