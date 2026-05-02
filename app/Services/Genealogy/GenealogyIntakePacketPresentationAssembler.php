<?php

namespace App\Services\Genealogy;

class GenealogyIntakePacketPresentationAssembler
{
    public function __construct(
        private readonly GenealogyIntakePacketStatusPresenter $presenter,
        private readonly GenealogyIntakeBindingSignalsService $bindingSignals = new GenealogyIntakeBindingSignalsService
    ) {}

    /**
     * Enrich a proposal queue with operator-friendly presentation blocks.
     *
     * @param  array  $run  Saved run snapshot (must have 'packets')
     * @param  array  $queue  Output from GenealogyIntakeProposalQueueService::buildQueue()
     * @return array Same queue structure with 'presentation' added to each packet entry
     */
    public function enrichQueue(array $run, array $queue): array
    {
        $lookup = self::buildPacketLookup($run);

        $enriched = $queue;
        foreach (['ready_packets', 'blocked_packets', 'pending_packets'] as $bucket) {
            $entries = (array) ($enriched[$bucket] ?? []);
            foreach ($entries as $i => $entry) {
                $packet = self::findPacket($lookup, $entry);
                $stage = [
                    'status' => (string) ($entry['status'] ?? 'pending'),
                    'reason' => (string) ($entry['reason'] ?? 'unknown_decision'),
                    'ready_for_proposal' => (bool) ($entry['proposal_ready'] ?? false),
                ];
                $entries[$i]['presentation'] = $this->presenter->presentQueueStage($packet, $stage);
                $entries[$i]['binding_signals'] = $this->bindingSignals->build($packet);
            }
            $enriched[$bucket] = $entries;
        }

        return $enriched;
    }

    /**
     * Enrich a draft plan with operator-friendly presentation blocks.
     *
     * @param  array  $run  Saved run snapshot (must have 'packets')
     * @param  array  $plan  Output from GenealogyIntakeProposalDraftService::plan()
     * @return array Same plan structure with 'presentation' added to each packet entry
     */
    public function enrichDraftPlan(array $run, array $plan): array
    {
        $lookup = self::buildPacketLookup($run);

        $enriched = $plan;
        foreach (['ready_packets', 'blocked_packets', 'pending_packets'] as $bucket) {
            $entries = (array) ($enriched[$bucket] ?? []);
            foreach ($entries as $i => $entry) {
                $packet = self::findPacket($lookup, $entry);
                $entries[$i]['presentation'] = $this->presenter->presentDraftStage($packet, $entry);
                $entries[$i]['binding_signals'] = $this->bindingSignals->build($packet);
            }
            $enriched[$bucket] = $entries;
        }

        return $enriched;
    }

    /**
     * Build a lookup index from run packets: keyed by packet_key and packet_label.
     */
    private static function buildPacketLookup(array $run): array
    {
        $byKey = [];
        $byLabel = [];

        foreach (array_values((array) ($run['packets'] ?? [])) as $packet) {
            $key = (string) ($packet['packet_key'] ?? '');
            $label = (string) ($packet['packet_label'] ?? '');

            if ($key !== '' && ! isset($byKey[$key])) {
                $byKey[$key] = $packet;
            }
            if ($label !== '' && ! isset($byLabel[$label])) {
                $byLabel[$label] = $packet;
            }
        }

        return ['by_key' => $byKey, 'by_label' => $byLabel];
    }

    /**
     * Find the matching packet from the lookup, or build a minimal fallback.
     */
    private static function findPacket(array $lookup, array $entry): array
    {
        $key = (string) ($entry['packet_key'] ?? '');
        $label = (string) ($entry['packet_label'] ?? '');

        if ($key !== '' && isset($lookup['by_key'][$key])) {
            return $lookup['by_key'][$key];
        }

        if ($label !== '' && isset($lookup['by_label'][$label])) {
            return $lookup['by_label'][$label];
        }

        // Fallback: minimal packet so the presenter still works
        return array_filter([
            'packet_key' => $key !== '' ? $key : null,
            'packet_label' => $label !== '' ? $label : 'unknown',
        ], fn ($v) => $v !== null);
    }
}
