<?php

namespace App\Contracts;

/**
 * Interface for review types that need custom approval logic beyond simple SQL updates.
 *
 * Implementations are registered in the review_type_registry table via service_class + approve_method columns.
 * The agent engine dispatches to these dynamically when a review item is approved/rejected.
 */
interface ReviewApprovalHandler
{
    /**
     * Handle approval of a review item.
     *
     * @param int $itemId The domain-specific item ID (e.g., proposal_id, fact_id)
     * @param array $details Decoded details JSON from the review queue item
     * @return array Result with at minimum ['success' => bool]
     */
    public function onApprove(int $itemId, array $details): array;
}
