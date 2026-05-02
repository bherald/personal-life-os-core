<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Carbon;

class GenealogyReviewPacketDecisionLogService
{
    public function append(array $details, string $action, ?string $actor = null, ?string $notes = null, array $meta = []): array
    {
        $log = array_values(array_filter((array) ($details['decision_log'] ?? []), 'is_array'));
        $log[] = [
            'action' => $action,
            'actor' => $actor,
            'notes' => $notes,
            'meta' => $meta,
            'created_at' => Carbon::now()->toIso8601String(),
        ];

        $details['decision_log'] = $log;

        return $details;
    }
}
