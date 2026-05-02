<?php

namespace App\Services\AgentMetrics;

class AwoScoringService
{
    public function score(array $qualityGate, array $decisionEnvelope): array
    {
        $hardFailReasons = $this->hardFailReasons($qualityGate);
        $hardFail = ($decisionEnvelope['hard_fail_confirmed'] ?? false) === true
            || $hardFailReasons !== [];
        $operatorDecision = (string) ($decisionEnvelope['operator_decision'] ?? 'unknown');

        if ($hardFail) {
            return $this->result(0, false, true, $hardFailReasons, [
                'operator_decision' => $operatorDecision,
                'quality_gate_score' => $this->qualityGateScore($qualityGate),
                'decision_credit' => 0,
                'safety_credit' => 0,
            ]);
        }

        $qualityGateScore = $this->qualityGateScore($qualityGate);
        $decisionCredit = $this->decisionCredit($operatorDecision);
        $safetyCredit = $this->safetyCredit($decisionEnvelope);
        $reworkPenalty = ($decisionEnvelope['rework_required'] ?? false) === true ? 20 : 0;
        $pendingPenalty = $operatorDecision === 'pending' ? 30 : 0;

        $score = max(0, min(100, (int) round(
            ($qualityGateScore * 0.55) + $decisionCredit + $safetyCredit - $reworkPenalty - $pendingPenalty
        )));

        return $this->result($score, $this->isApprovalWorthy($score, $operatorDecision), false, [], [
            'operator_decision' => $operatorDecision,
            'quality_gate_score' => $qualityGateScore,
            'decision_credit' => $decisionCredit,
            'safety_credit' => $safetyCredit,
            'rework_penalty' => $reworkPenalty,
            'pending_penalty' => $pendingPenalty,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(int $score, bool $approvalWorthy, bool $hardFail, array $hardFailReasons, array $dimensions): array
    {
        return [
            'version' => 1,
            'score' => $score,
            'approval_worthy' => $approvalWorthy,
            'hard_fail' => $hardFail,
            'hard_fail_reasons' => array_values(array_unique($hardFailReasons)),
            'dimensions' => $dimensions,
        ];
    }

    private function qualityGateScore(array $qualityGate): int
    {
        $score = $qualityGate['approval_worthy_score'] ?? 0;

        return max(0, min(100, is_numeric($score) ? (int) round((float) $score) : 0));
    }

    /**
     * @return array<int, string>
     */
    private function hardFailReasons(array $qualityGate): array
    {
        $reasons = $qualityGate['hard_fail_reasons'] ?? [];

        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, 'is_string'));
    }

    private function decisionCredit(string $operatorDecision): int
    {
        return match ($operatorDecision) {
            'approved' => 35,
            'approved_with_notes' => 30,
            'rejected' => 5,
            default => 0,
        };
    }

    private function safetyCredit(array $decisionEnvelope): int
    {
        $credit = 0;

        if (($decisionEnvelope['privacy_review_status'] ?? null) === 'passed') {
            $credit += 5;
        }
        if (in_array($decisionEnvelope['public_export_status'] ?? null, ['not_applicable', 'ready', 'private_only'], true)) {
            $credit += 5;
        }
        if (in_array($decisionEnvelope['living_person_status'] ?? null, ['not_applicable', 'passed'], true)) {
            $credit += 5;
        }
        if (in_array($decisionEnvelope['provider_boundary_status'] ?? null, ['automated_public', 'not_applicable', 'private_opt_in'], true)) {
            $credit += 5;
        }

        return $credit;
    }

    private function isApprovalWorthy(int $score, string $operatorDecision): bool
    {
        return $score >= 70 && in_array($operatorDecision, ['approved', 'approved_with_notes'], true);
    }
}
