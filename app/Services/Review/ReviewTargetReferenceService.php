<?php

namespace App\Services\Review;

final class ReviewTargetReferenceService
{
    public function forReviewRow(object|array $row, ?string $reviewType = null, ?string $findingType = null): string
    {
        $reviewType ??= $this->nullableString($this->rowValue($row, 'review_type')) ?? 'unknown';
        $findingType ??= $this->nullableString($this->rowValue($row, 'finding_type'));

        $basis = implode('|', [
            $reviewType,
            $findingType ?? 'none',
            $this->nullableString($this->rowValue($row, 'id')) ?? '',
            $this->nullableString($this->rowValue($row, 'token')) ?? '',
            $this->nullableString($this->rowValue($row, 'created_at')) ?? '',
        ]);
        $key = (string) config('app.key', '');
        $digest = $key !== ''
            ? hash_hmac('sha256', $basis, $key)
            : hash('sha256', $basis);

        return $reviewType.':target-'.substr($digest, 0, 12);
    }

    private function rowValue(object|array $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
