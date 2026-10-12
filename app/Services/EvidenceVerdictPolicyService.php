<?php

namespace App\Services;

class EvidenceVerdictPolicyService
{
    /**
     * Convert deterministic evidence counts into the PLOS five-class verdict.
     *
     * @return array{verdict:string,confidence:float}
     */
    public function determine(int $supporting, int $contradicting, int $neutral, ?float $factuality): array
    {
        $total = $supporting + $contradicting + $neutral;

        if ($total === 0) {
            return ['verdict' => 'inconclusive', 'confidence' => 0.0];
        }

        $evidenceRatio = ($supporting + $contradicting) / $total;

        if ($supporting + $contradicting < 2 || $evidenceRatio < 0.3) {
            return [
                'verdict' => 'inconclusive',
                'confidence' => round(min(0.4, max(0.1, $evidenceRatio * 0.4)), 3),
            ];
        }

        if ($factuality !== null) {
            if ($factuality >= 0.85 && $supporting >= 2) {
                return [
                    'verdict' => 'true',
                    'confidence' => round(min(0.95, 0.5 + ($factuality * 0.4) + ($supporting * 0.05)), 3),
                ];
            }
            if ($factuality >= 0.65 && $supporting > $contradicting) {
                return [
                    'verdict' => 'mostly_true',
                    'confidence' => round(min(0.85, 0.4 + ($factuality * 0.4) + ($supporting * 0.05)), 3),
                ];
            }
            if ($factuality <= 0.15 && $contradicting >= 2) {
                return [
                    'verdict' => 'false',
                    'confidence' => round(min(0.95, 0.5 + ((1 - $factuality) * 0.4) + ($contradicting * 0.05)), 3),
                ];
            }
            if ($factuality <= 0.35 && $contradicting > $supporting) {
                return [
                    'verdict' => 'mostly_false',
                    'confidence' => round(min(0.85, 0.4 + ((1 - $factuality) * 0.4) + ($contradicting * 0.05)), 3),
                ];
            }
        }

        if ($supporting > 0 && $contradicting > 0) {
            return [
                'verdict' => 'half_true',
                'confidence' => round(max(0.2, min(0.6, $evidenceRatio * 0.5)), 3),
            ];
        }

        if ($supporting > $contradicting) {
            return [
                'verdict' => 'mostly_true',
                'confidence' => round(0.4 + ($evidenceRatio * 0.3), 3),
            ];
        }
        if ($contradicting > $supporting) {
            return [
                'verdict' => 'mostly_false',
                'confidence' => round(0.4 + ($evidenceRatio * 0.3), 3),
            ];
        }

        return [
            'verdict' => 'half_true',
            'confidence' => round(max(0.2, $evidenceRatio * 0.4), 3),
        ];
    }
}
