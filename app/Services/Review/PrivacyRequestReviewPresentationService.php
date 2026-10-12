<?php

namespace App\Services\Review;

class PrivacyRequestReviewPresentationService
{
    /** @var list<string> */
    private const PERSONAL_FIELDS = [
        'name', 'email', 'phone', 'address', 'city', 'state', 'zip', 'dob', 'aliases',
    ];

    /** @param array<string, mixed> $item @param array<string, mixed> $registeredType */
    public function project(array $item, array $registeredType): ?array
    {
        $id = $this->positiveInt($item['id'] ?? null);
        if ($id === null || ($item['unified_id'] ?? null) !== "privacy:{$id}") {
            return null;
        }

        $available = $this->availableFields($item);
        $requiredRaw = $this->fieldList($item['required_fields'] ?? null, false);
        $required = array_values(array_intersect($requiredRaw, self::PERSONAL_FIELDS));
        $optional = array_values(array_intersect(
            $this->fieldList($item['optional_fields'] ?? null, false),
            $available
        ));
        $selected = $this->fieldList($item['fields_to_submit'] ?? null, true);
        if ($selected === []) {
            // Start new authorizations at the least-data broker requirement.
            // Operators can deliberately add an optional category in detail.
            $selected = $required;
        }
        $selected = array_values(array_intersect($selected, $available));
        foreach ($required as $field) {
            if (in_array($field, $available, true) && ! in_array($field, $selected, true)) {
                $selected[] = $field;
            }
        }

        $actions = [];
        foreach (['approve', 'reject'] as $action) {
            if (($registeredType["{$action}_method"] ?? null) === $action) {
                $actions[] = $action;
            }
        }

        $profileUrl = $this->safeUrl($item['profile_url'] ?? null);
        $profileRequired = in_array('profile_url', $requiredRaw, true);
        $blockers = [];
        if ($this->safeText($item['subject_name'] ?? null, 240) === '') {
            $blockers[] = 'subject_missing';
        }
        if ($this->safeText($item['broker_name'] ?? null, 240) === '') {
            $blockers[] = 'broker_missing';
        }
        if (array_diff($required, $available) !== []) {
            $blockers[] = 'required_personal_data_missing';
        }
        if (! filter_var($item['method_target_available'] ?? false, FILTER_VALIDATE_BOOL)) {
            $blockers[] = 'removal_destination_missing';
        }
        if ($profileRequired && $profileUrl === null) {
            $blockers[] = 'profile_url_required';
        }
        if (! in_array('approve', $actions, true)) {
            $blockers[] = 'approval_route_unavailable';
        }

        $warnings = [];
        if (filter_var($item['requires_captcha'] ?? false, FILTER_VALIDATE_BOOL)) {
            $warnings[] = 'captcha_or_manual_step_expected';
        }
        if (filter_var($item['requires_auth'] ?? false, FILTER_VALIDATE_BOOL)) {
            $warnings[] = 'broker_authentication_expected';
        }
        $hasAssessment = is_scalar($item['summary'] ?? null)
            && trim((string) $item['summary']) !== '';

        return [
            'item' => [
                'id' => $id,
                'unified_id' => "privacy:{$id}",
                'review_type' => 'privacy',
                'source' => 'privacy',
                'category' => 'privacy',
                'title' => $this->safeText($item['title'] ?? 'Privacy removal request', 300),
                'summary' => $hasAssessment
                    ? 'An automated scan marked this broker listing for operator review.'
                    : '',
                'confidence' => is_numeric($item['confidence'] ?? null)
                    ? max(0.0, min(1.0, (float) $item['confidence']))
                    : null,
                'created_at' => $item['created_at'] ?? null,
                'subject_name' => $this->safeText($item['subject_name'] ?? null, 240),
                'broker_name' => $this->safeText($item['broker_name'] ?? null, 240),
                'broker_url' => $this->safeUrl($item['broker_url'] ?? null),
                'profile_url' => $profileUrl,
                'removal_method' => $this->safeCode($item['removal_method'] ?? null),
                'automation_tier' => is_numeric($item['automation_tier'] ?? null)
                    ? (int) $item['automation_tier']
                    : null,
                'actions' => $actions,
                'batch_enabled' => false,
            ],
            'privacy_review' => [
                'schema' => 'plos.privacy_request_review_context.v1',
                'operator_only' => true,
                'batch_enabled' => false,
                'approval_ready' => $blockers === [],
                'blockers' => $blockers,
                'warnings' => $warnings,
                'available_fields' => $available,
                'required_fields' => $required,
                'optional_fields' => $optional,
                'selected_fields' => $selected,
                'profile_url_required' => $profileRequired,
                'approval_effect' => 'Authorizes only the selected personal-data categories for a later controlled removal submission.',
                'approval_non_effect' => 'Approval does not send an email, submit a form, or contact the broker now.',
                'rejection_effect' => 'Makes this request ineligible for submission. It does not contact the broker.',
                'confirmation_label' => 'I reviewed the broker target and authorize the selected personal-data categories for this removal request.',
            ],
        ];
    }

    /** @return list<string> */
    private function availableFields(array $item): array
    {
        $available = [];
        foreach (self::PERSONAL_FIELDS as $field) {
            if (filter_var($item["has_{$field}"] ?? false, FILTER_VALIDATE_BOOL)) {
                $available[] = $field;
            }
        }

        return $available;
    }

    /** @return list<string> */
    private function fieldList(mixed $value, bool $personalOnly): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        $fields = array_values(array_unique(array_filter(array_map(
            static fn (mixed $field): string => strtolower(trim(is_scalar($field) ? (string) $field : '')),
            $value
        ))));

        return $personalOnly ? array_values(array_intersect($fields, self::PERSONAL_FIELDS)) : $fields;
    }

    private function safeUrl(mixed $value): ?string
    {
        $url = trim(is_scalar($value) ? (string) $value : '');
        if ($url === '' || mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)
            || parse_url($url, PHP_URL_HOST) === null
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            return null;
        }

        return preg_replace('/[?#].*$/', '', $url) ?: null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function safeCode(mixed $value): string
    {
        $code = strtolower($this->safeText($value, 80));

        return preg_match('/^[a-z0-9_-]+$/', $code) === 1 ? $code : '';
    }

    private function safeText(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value));

        return mb_substr($text, 0, $limit);
    }
}
