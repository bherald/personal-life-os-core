<?php

namespace App\Services\Genealogy;

class GenealogyIntakeSelectedPacketComposer
{
    public function __construct(
        private readonly GenealogyIntakeWorkspacePacketSelector $selector,
        private readonly GenealogyIntakeBindingSignalsService $bindingSignals
    ) {}

    /**
     * Compose a normalized selected-packet payload from a run snapshot and workspace.
     * Pure, deterministic, non-mutating. Returns null if no match found.
     */
    public function compose(array $run, array $workspace, ?string $packetKey = null, ?string $packetLabel = null): ?array
    {
        $bundle = $this->selector->selectPacket($run, $workspace, $packetKey, $packetLabel);
        if ($bundle === null) {
            return null;
        }

        $packet = (array) ($bundle['packet'] ?? []);
        $queueEntry = $bundle['queue_entry'];
        $draftEntry = $bundle['draft_entry'];
        $presentation = $bundle['presentation'];
        $action = $bundle['action'];

        return [
            'packet_key' => (string) ($packet['packet_key'] ?? ($packetKey ?? '')),
            'packet_label' => (string) ($packet['packet_label'] ?? ''),
            'packet' => $packet,
            'preview_state' => $packet['preview_state'] ?? null,
            'review_decision' => $packet['review_decision'] ?? null,
            'execution' => $packet['reference_copy_execution'] ?? null,
            'queue_entry' => $queueEntry,
            'draft_entry' => $draftEntry,
            'presentation' => $presentation,
            'action' => $action,
            'stage' => self::deriveStage($queueEntry),
            'binding_signals' => $this->bindingSignals->build($packet),
            'source_preference' => self::deriveSourcePreference($queueEntry, $draftEntry),
        ];
    }

    private static function deriveStage(?array $queueEntry): ?array
    {
        if ($queueEntry === null) {
            return null;
        }

        return [
            'status' => (string) ($queueEntry['status'] ?? ''),
            'reason' => (string) ($queueEntry['reason'] ?? ''),
        ];
    }

    private static function deriveSourcePreference(?array $queueEntry, ?array $draftEntry): array
    {
        $hasQueuePresentation = isset($queueEntry['presentation']);
        $hasDraftPresentation = isset($draftEntry['presentation']);
        $hasQueueAction = isset($queueEntry['action']);
        $hasDraftAction = isset($draftEntry['action']);

        return [
            'presentation_source' => $hasQueuePresentation ? 'queue' : ($hasDraftPresentation ? 'draft' : 'none'),
            'action_source' => $hasQueueAction ? 'queue' : ($hasDraftAction ? 'draft' : 'none'),
        ];
    }
}
