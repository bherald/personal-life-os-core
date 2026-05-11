<?php

namespace App\Services\Genealogy;

class GenealogyDocumentTextQualityGateService
{
    /**
     * Gate OCR/HTR/text before local fact extraction. The goal is not to prove a
     * document; it is to stop label-only or junk OCR from becoming proposals.
     */
    public function assess(string $text, array $context = []): array
    {
        $normalized = $this->normalize($text);
        $metrics = $this->metrics($normalized);
        $reasons = [];

        $mediaType = strtolower(trim((string) ($context['media_type'] ?? $context['document_type'] ?? 'document')));
        $title = strtolower(trim((string) ($context['title'] ?? $context['source_name'] ?? '')));
        $sourceMethod = strtolower(trim((string) ($context['source_method'] ?? '')));
        $isCertificate = str_contains($mediaType, 'certificate')
            || str_contains($title, 'certificate')
            || str_contains($title, 'vital record');
        $isTrustedTextLayer = in_array($sourceMethod, ['tika', 'pdftotext', 'text', 'html', 'markdown', 'csv', 'office'], true);

        if ($metrics['non_space_chars'] < 24) {
            $reasons[] = 'too_short';
        }

        if ($metrics['word_count'] >= 6 && $metrics['alnum_ratio'] < 0.48) {
            $reasons[] = 'low_alnum_ratio';
        }

        if ($metrics['word_count'] >= 10 && $metrics['readable_word_ratio'] < 0.38) {
            $reasons[] = 'low_readable_word_ratio';
        }

        if ($metrics['junk_ratio'] > 0.18) {
            $reasons[] = 'high_junk_ratio';
        }

        if ($isCertificate && $metrics['certificate_label_count'] >= 2 && $metrics['value_anchor_count'] < 2) {
            $reasons[] = 'certificate_labels_without_readable_values';
        }

        if ($isCertificate && $metrics['certificate_label_count'] > $metrics['value_anchor_count'] * 4 && $metrics['value_anchor_count'] < 3) {
            $reasons[] = 'certificate_needs_field_level_review';
        }

        $score = $this->score($metrics, $isCertificate);
        $blockingReasons = array_diff($reasons, $isTrustedTextLayer && ! $isCertificate ? ['too_short'] : []);
        $allow = $blockingReasons === [] && ($isTrustedTextLayer && ! $isCertificate || $score >= ($isCertificate ? 0.52 : 0.35));

        $label = 'usable';
        if (! $allow) {
            $label = $isCertificate && in_array('certificate_labels_without_readable_values', $reasons, true)
                ? 'manual_review'
                : 'noisy';
        }

        return [
            'allow_fact_extraction' => $allow,
            'label' => $label,
            'score' => round($score, 3),
            'reasons' => $reasons,
            'metrics' => $metrics,
        ];
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace('/\R+/', "\n", trim($text)) ?? '';

        return trim($text);
    }

    private function metrics(string $text): array
    {
        $nonSpace = preg_replace('/\s+/', '', $text) ?? '';
        $nonSpaceChars = strlen($nonSpace);
        $alnumChars = $this->countPattern('/[A-Za-z0-9]/', $nonSpace);
        $junkChars = $this->countPattern('/[^A-Za-z0-9\s.,;:()\'"\/&\-#\[\]]/', $text);

        $words = array_values(array_filter(preg_split('/\s+/', $text) ?: []));
        $readableWords = array_values(array_filter($words, fn (string $word): bool => $this->isReadableWord($word)));
        $nameLikeCount = $this->countNameLikeAnchors($text);
        $dateLikeCount = $this->countDateLikeAnchors($text);
        $genealogyTermCount = $this->countGenealogyTerms($text);
        $certificateLabelCount = $this->countCertificateLabels($text);

        return [
            'chars' => strlen($text),
            'non_space_chars' => $nonSpaceChars,
            'word_count' => count($words),
            'readable_word_count' => count($readableWords),
            'readable_word_ratio' => count($words) > 0 ? round(count($readableWords) / count($words), 3) : 0.0,
            'alnum_ratio' => $nonSpaceChars > 0 ? round($alnumChars / $nonSpaceChars, 3) : 0.0,
            'junk_ratio' => $nonSpaceChars > 0 ? round($junkChars / $nonSpaceChars, 3) : 0.0,
            'name_like_count' => $nameLikeCount,
            'date_like_count' => $dateLikeCount,
            'genealogy_term_count' => $genealogyTermCount,
            'certificate_label_count' => $certificateLabelCount,
            'value_anchor_count' => $nameLikeCount + $dateLikeCount,
        ];
    }

    private function isReadableWord(string $word): bool
    {
        $clean = trim($word, " \t\n\r\0\x0B.,;:()[]{}'\"/-");
        if ($clean === '' || strlen($clean) > 28) {
            return false;
        }

        if (preg_match('/^\d{1,4}$/', $clean) === 1) {
            return true;
        }

        if (preg_match('/^[A-Za-z][A-Za-z\'-]*$/', $clean) !== 1) {
            return false;
        }

        return strlen($clean) <= 3 || preg_match('/[aeiouy]/i', $clean) === 1;
    }

    private function countNameLikeAnchors(string $text): int
    {
        $matches = [];
        preg_match_all('/\b(?!COMMONWEALTH\b|DEPARTMENT\b|BUREAU\b|CERTIFICATE\b|PENNSYLVANIA\b|UNITED\b|STATES\b)([A-Z][a-z]{2,}|[A-Z]{2,})\s+(?:[A-Z]\.\s+)?(?!COUNTY\b|HEALTH\b|STATISTICS\b|DEATH\b|BIRTH\b|RESIDENCE\b)([A-Z][a-z]{2,}|[A-Z]{2,})\b/', $text, $matches);

        $stopWords = [
            'A', 'AN', 'AND', 'BIRTH', 'BOROUGH', 'BUREAU', 'CERTIFICATE', 'CITY',
            'COMMONWEALTH', 'COUNTY', 'CTS', 'DATE', 'DEATH', 'DECEASED',
            'DEPARTMENT', 'DIST', 'FIC', 'FILE', 'HEALTH', 'HVS', 'NO', 'OF',
            'PLACE', 'PENNSYLVANIA', 'PRIMARY', 'REGISTERED', 'REGISTEREED',
            'RESIDENCE', 'SPA', 'STATE', 'STATISTICS', 'THE', 'TOWNSHIP',
            'UNITED', 'USUAL', 'VITAL',
        ];

        $count = 0;
        foreach (($matches[0] ?? []) as $match) {
            $tokens = preg_split('/\s+/', trim((string) $match)) ?: [];
            $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== '' && $token !== '.'));
            if (count($tokens) < 2) {
                continue;
            }

            $upperTokens = array_map(static fn (string $token): string => strtoupper(trim($token, ' .,')), $tokens);
            if (array_intersect($upperTokens, $stopWords) !== []) {
                continue;
            }

            $hasTitleCaseToken = false;
            foreach ($tokens as $token) {
                if (preg_match('/^[A-Z][a-z]{2,}/', $token) === 1) {
                    $hasTitleCaseToken = true;
                    break;
                }
            }

            if (! $hasTitleCaseToken) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function countDateLikeAnchors(string $text): int
    {
        $count = 0;
        $count += $this->countPattern('/\b(?:Jan|Feb|Mar|Apr|May|Jun|June|Jul|July|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},?\s+(?:18|19|20)\d{2}\b/i', $text);
        $count += $this->countPattern('/\b(?:18|19|20)\d{2}\b/', $text);
        $count += $this->countPattern('/\b\d{1,2}[\/\-]\d{1,2}[\/\-](?:18|19|20)?\d{2}\b/', $text);

        return $count;
    }

    private function countGenealogyTerms(string $text): int
    {
        return $this->countPattern('/\b(?:born|birth|died|death|buried|burial|cemetery|married|marriage|spouse|wife|husband|father|mother|son|daughter|residence|resident|county|obituary|funeral|interment)\b/i', $text);
    }

    private function countCertificateLabels(string $text): int
    {
        return $this->countPattern('/\b(?:certificate of death|certificate|place of death|usual residence|deceased|date of death|date of birth|birthplace|father|mother|informant|burial|cemetery|registered)\b/i', $text);
    }

    private function countPattern(string $pattern, string $text): int
    {
        $matches = [];
        $count = preg_match_all($pattern, $text, $matches);

        return $count === false ? 0 : $count;
    }

    private function score(array $metrics, bool $isCertificate): float
    {
        $score = 0.0;
        $score += min(0.20, ($metrics['word_count'] / 80) * 0.20);
        $score += min(0.20, $metrics['readable_word_ratio'] * 0.20);
        $score += min(0.20, $metrics['alnum_ratio'] * 0.20);
        $score += min(0.15, ($metrics['genealogy_term_count'] / 5) * 0.15);
        $score += min(0.15, ($metrics['date_like_count'] / 2) * 0.15);
        $score += min(0.15, ($metrics['name_like_count'] / 2) * 0.15);

        if ($isCertificate) {
            $score += min(0.10, ($metrics['certificate_label_count'] / 4) * 0.10);
        }

        $score -= min(0.25, $metrics['junk_ratio'] * 0.9);

        return max(0.0, min(1.0, $score));
    }
}
