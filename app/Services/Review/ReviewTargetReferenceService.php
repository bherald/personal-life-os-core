<?php

namespace App\Services\Review;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * @param  list<string>|null  $allowedReviewTypes
     * @return array{review_type: string, target_ref: string}|null
     */
    public function normalize(mixed $value, ?array $allowedReviewTypes = null): ?array
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^([a-z_]+):target-([a-f0-9]{12})$/i', $text, $matches) !== 1) {
            return null;
        }

        $reviewType = strtolower($matches[1]);
        if (is_array($allowedReviewTypes) && ! in_array($reviewType, $allowedReviewTypes, true)) {
            return null;
        }

        return [
            'review_type' => $reviewType,
            'target_ref' => $reviewType.':target-'.strtolower($matches[2]),
        ];
    }

    /**
     * Resolve a sanitized target ref to a current pending review row.
     *
     * This intentionally performs bounded comparison against generated refs
     * instead of decoding row ids or tokens from the target string.
     *
     * @param  list<string>|null  $allowedReviewTypes
     */
    public function pendingReviewRowForTargetRef(
        mixed $targetRef,
        ?array $allowedReviewTypes = null,
        int $limit = 500,
    ): ?object {
        $normalized = $this->normalize($targetRef, $allowedReviewTypes);
        if ($normalized === null || ! Schema::hasTable('agent_review_queue')) {
            return null;
        }

        $rows = DB::table('agent_review_queue')
            ->where('status', 'pending')
            ->where('review_type', $normalized['review_type'])
            ->where(function ($query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->get();

        foreach ($rows as $row) {
            if ($this->forReviewRow($row, $normalized['review_type']) === $normalized['target_ref']) {
                return $row;
            }
        }

        return null;
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
