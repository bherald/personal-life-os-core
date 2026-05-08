<?php

namespace App\Services\Genealogy;

class GenealogyReviewPacketSourceLabelService
{
    public function safeLabel(mixed $value, ?string $fallback = null): ?string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $label = trim((string) $value);
        if ($label === '') {
            return $fallback;
        }

        if ($this->looksUnsafe($label)) {
            return $fallback;
        }

        return $label;
    }

    private function looksUnsafe(string $label): bool
    {
        if (strlen($label) > 96) {
            return true;
        }

        if (str_contains($label, '<') || str_contains($label, '>') || preg_match('/&(?:lt|gt|#x?[0-9a-f]+);/i', $label) === 1) {
            return true;
        }

        if (str_contains($label, '://') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $label) === 1) {
            return true;
        }

        if (str_starts_with($label, '/') || str_starts_with($label, '\\') || str_starts_with($label, '~/') || str_starts_with($label, './') || str_starts_with($label, '../')) {
            return true;
        }

        if (str_contains($label, '/') || str_contains($label, '\\')) {
            return true;
        }

        if (preg_match('/(?:token|secret|bearer|oauth|api[_-]?key|password|passwd)=/i', $label) === 1) {
            return true;
        }

        if (preg_match('/\b[a-f0-9]{32,}\b/i', $label) === 1) {
            return true;
        }

        if (preg_match('/^[A-Za-z0-9_-]{32,}$/', $label) === 1) {
            return true;
        }

        return false;
    }
}
