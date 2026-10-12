<?php

namespace App\Services\DataRemoval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrivacyRequestReviewDecisionService
{
    /** @var list<string> */
    private const PERSONAL_FIELDS = [
        'name', 'email', 'phone', 'address', 'city', 'state', 'zip', 'dob', 'aliases',
    ];

    /** @var list<string> */
    private const REJECTION_REASONS = [
        'wrong_target', 'no_personal_data', 'insufficient_evidence', 'do_not_submit', 'duplicate', 'other',
    ];

    /** @return array<string, mixed> */
    public function approve(int $id, ?string $notes = null, array $meta = []): array
    {
        $row = $this->pendingRequest($id);
        if ($row === null) {
            return $this->error('Pending privacy request requiring review was not found.', 'not_pending');
        }

        $fieldSelection = $meta['fields_to_submit'] ?? null;
        if ($this->containsUnsupportedField($fieldSelection)) {
            return $this->error('The approval contains an unsupported personal-data field.', 'unsupported_field');
        }

        $selected = $this->normalizeFields($fieldSelection);
        if ($selected === []) {
            return $this->error('Select at least one available personal-data field before approval.', 'fields_required');
        }

        $available = $this->availableFields($row);
        $unavailable = array_values(array_diff($selected, $available));
        if ($unavailable !== []) {
            return $this->error(
                'Selected fields are not available for this subject: '.implode(', ', $unavailable).'.',
                'field_unavailable'
            );
        }

        $required = array_values(array_intersect(
            $this->decodeFieldList($row->required_fields ?? null),
            self::PERSONAL_FIELDS
        ));
        $missingRequired = array_values(array_diff($required, $selected));
        if ($missingRequired !== []) {
            return $this->error(
                'Required fields must remain selected: '.implode(', ', $missingRequired).'.',
                'required_field_missing'
            );
        }

        $rawProfileUrl = $meta['profile_url'] ?? ($row->profile_url ?? null);
        $profileUrl = $this->normalizeProfileUrl($rawProfileUrl);
        if (trim(is_scalar($rawProfileUrl) ? (string) $rawProfileUrl : '') !== '' && $profileUrl === null) {
            return $this->error('The broker profile URL must be a public HTTP(S) URL without credentials.', 'profile_url_invalid');
        }
        if ($this->brokerRequiresProfileUrl($row) && $profileUrl === null) {
            return $this->error('This broker requires a public profile URL before approval.', 'profile_url_required');
        }
        if (! $this->methodTargetAvailable($row)) {
            return $this->error('The broker removal destination is missing or unsupported.', 'method_target_missing');
        }

        $updated = DB::update(
            "UPDATE removal_requests
             SET status = 'pending', requires_review = 0, fields_to_submit = ?, profile_url = ?,
                 reviewed_by = 'review_hub_operator', reviewed_at = NOW(), review_notes = ?, updated_at = NOW()
             WHERE id = ? AND status = 'pending' AND requires_review = 1",
            [json_encode($selected, JSON_THROW_ON_ERROR), $profileUrl, $this->safeNotes($notes), $id]
        );

        if ($updated !== 1) {
            return $this->error('Privacy request changed before approval completed.', 'concurrent_change');
        }

        Log::info('Privacy request authorized for later removal submission', [
            'request_id' => $id,
            'field_categories' => $selected,
            'external_submission_performed' => false,
        ]);

        return [
            'success' => true,
            'message' => 'Privacy request authorized for later removal submission; no form or email was sent now.',
            'review_state' => 'approved_for_submission',
            'authorized_field_categories' => $selected,
            'external_submission_performed' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function reject(int $id, ?string $reason = null, array $meta = []): array
    {
        $reasonCode = strtolower(trim((string) ($meta['reason_code'] ?? '')));
        if (! in_array($reasonCode, self::REJECTION_REASONS, true)) {
            return $this->error('Choose a valid rejection reason before rejecting this request.', 'reason_required');
        }

        $note = trim(implode(': ', array_filter([$reasonCode, $this->safeNotes($reason)])));
        $updated = DB::update(
            "UPDATE removal_requests
             SET status = 'ignored', requires_review = 0,
                 reviewed_by = 'review_hub_operator', reviewed_at = NOW(), review_notes = ?, updated_at = NOW()
             WHERE id = ? AND status = 'pending' AND requires_review = 1",
            [$note, $id]
        );

        if ($updated !== 1) {
            return $this->error('Pending privacy request requiring review was not found.', 'not_pending');
        }

        Log::info('Privacy request rejected by operator review', [
            'request_id' => $id,
            'reason_code' => $reasonCode,
            'external_submission_performed' => false,
        ]);

        return [
            'success' => true,
            'message' => 'Privacy request rejected and made ineligible for submission; no external action occurred.',
            'review_state' => 'rejected',
            'external_submission_performed' => false,
        ];
    }

    private function pendingRequest(int $id): ?object
    {
        return DB::selectOne(
            "SELECT r.id, r.status, r.requires_review, r.profile_url,
                    b.removal_method, b.removal_url, b.removal_email, b.required_fields,
                    s.name AS subject_name, s.email AS subject_email, s.phone AS subject_phone,
                    s.address_line1 AS subject_address, s.city AS subject_city,
                    s.state AS subject_state, s.zip AS subject_zip,
                    s.date_of_birth AS subject_dob, s.aliases AS subject_aliases
             FROM removal_requests r
             JOIN data_brokers b ON b.id = r.broker_id
             JOIN data_subjects s ON s.id = r.subject_id
             WHERE r.id = ? AND r.status = 'pending' AND r.requires_review = 1",
            [$id]
        );
    }

    /** @return list<string> */
    private function normalizeFields(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fields = [];
        foreach ($value as $field) {
            $field = strtolower(trim(is_scalar($field) ? (string) $field : ''));
            if (in_array($field, self::PERSONAL_FIELDS, true)) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    private function containsUnsupportedField(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $field) {
            if (! is_scalar($field)
                || ! in_array(strtolower(trim((string) $field)), self::PERSONAL_FIELDS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function decodeFieldList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return $this->normalizeFields(is_array($value) ? $value : []);
    }

    /** @return list<string> */
    private function availableFields(object $row): array
    {
        $mapping = [
            'name' => 'subject_name',
            'email' => 'subject_email',
            'phone' => 'subject_phone',
            'address' => 'subject_address',
            'city' => 'subject_city',
            'state' => 'subject_state',
            'zip' => 'subject_zip',
            'dob' => 'subject_dob',
            'aliases' => 'subject_aliases',
        ];

        $available = [];
        foreach ($mapping as $field => $column) {
            $value = $row->{$column} ?? null;
            if ($field === 'aliases' && is_string($value)) {
                $value = json_decode($value, true);
            }
            if ((is_array($value) && $value !== []) || (! is_array($value) && trim((string) $value) !== '')) {
                $available[] = $field;
            }
        }

        return $available;
    }

    private function brokerRequiresProfileUrl(object $row): bool
    {
        return in_array('profile_url', $this->rawFieldList($row->required_fields ?? null), true);
    }

    /** @return list<string> */
    private function rawFieldList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $field): string => strtolower(trim(is_scalar($field) ? (string) $field : '')),
            $value
        ))));
    }

    private function methodTargetAvailable(object $row): bool
    {
        return match (strtolower((string) ($row->removal_method ?? ''))) {
            'web_form', 'api' => $this->normalizeProfileUrl($row->removal_url ?? null) !== null,
            'email' => filter_var($row->removal_email ?? null, FILTER_VALIDATE_EMAIL) !== false,
            default => false,
        };
    }

    private function normalizeProfileUrl(mixed $value): ?string
    {
        $url = trim(is_scalar($value) ? (string) $value : '');
        if ($url === '' || mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || $host === 'localhost'
            || str_ends_with($host, '.local')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $url;
    }

    private function safeNotes(?string $value): ?string
    {
        $notes = trim((string) $value);

        return $notes === '' ? null : mb_substr($notes, 0, 2000);
    }

    /** @return array{success:false,error:string,error_code:string} */
    private function error(string $message, string $code): array
    {
        return ['success' => false, 'error' => $message, 'error_code' => $code];
    }
}
