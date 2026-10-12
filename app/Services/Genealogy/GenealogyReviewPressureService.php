<?php

namespace App\Services\Genealogy;

class GenealogyReviewPressureService
{
    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $flow
     * @return array<string,mixed>
     */
    public function evaluate(array $policy, int $pending, ?int $maxPending, array $flow = []): array
    {
        $enabled = (bool) ($policy['enabled'] ?? false);
        $reserveSlots = max(0, (int) ($policy['reserve_slots'] ?? 0));
        $windowDays = max(1, min(30, (int) ($policy['window_days'] ?? 7)));
        $created = max(0, (int) ($flow['created'] ?? 0));
        $cleared = max(0, (int) ($flow['cleared'] ?? 0));
        $oldestPendingDays = isset($flow['oldest_pending_days']) && is_numeric($flow['oldest_pending_days'])
            ? max(0, (int) $flow['oldest_pending_days'])
            : null;
        $reserveThreshold = $maxPending !== null ? max(0, $maxPending - $reserveSlots) : null;
        $reserveReached = $enabled
            && $reserveThreshold !== null
            && $pending >= $reserveThreshold;
        $maxInflowRatio = max(1.0, (float) ($policy['max_inflow_to_clearance_ratio'] ?? 1.25));
        $minimumCreatedForRatio = max(1, (int) ($policy['minimum_created_for_ratio_gate'] ?? 4));
        $inflowOutpacesClearance = $enabled
            && $created >= $minimumCreatedForRatio
            && $created > max(1, $cleared) * $maxInflowRatio;

        $reasons = [];
        if ($reserveReached) {
            $reasons[] = 'review_reserve_reached';
        }
        if ($inflowOutpacesClearance) {
            $reasons[] = 'packet_inflow_outpaces_clearance';
        }

        $refillAllowed = ! $enabled || $reasons === [];

        return [
            'enabled' => $enabled,
            'window_days' => $windowDays,
            'pending_packets' => $pending,
            'max_pending_total' => $maxPending,
            'reserve_slots' => $reserveSlots,
            'reserve_threshold' => $reserveThreshold,
            'created_in_window' => $created,
            'cleared_in_window' => $cleared,
            'inflow_to_clearance_ratio' => $cleared > 0 ? round($created / $cleared, 2) : ($created > 0 ? null : 0.0),
            'oldest_pending_days' => $oldestPendingDays,
            'state' => $refillAllowed ? 'capacity_available' : 'operator_review_pressure',
            'refill_allowed' => $refillAllowed,
            'reasons' => $reasons,
            'next_operator_action' => $pending > 0 ? [
                'action' => 'review_face_photo_repair_packet',
                'target_source' => 'ops:review-backlog-report',
                'command_hint' => 'php artisan ops:review-backlog-report --json --compact --next-target --focus=face-photo-repair-packet',
                'identity_writeback' => false,
            ] : null,
        ];
    }
}
