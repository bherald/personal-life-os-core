<?php

namespace App\Services\Genealogy;

class GenealogyIntakeWorkspaceService
{
    public function __construct(
        private readonly GenealogyIntakeRunSummaryService $summaryService,
        private readonly GenealogyIntakeProposalQueueService $queueService,
        private readonly GenealogyIntakeProposalDraftService $draftService,
        private readonly GenealogyIntakePacketPresentationAssembler $assembler,
        private readonly GenealogyIntakePacketActionService $actionService,
        private readonly GenealogyIntakeNextPacketRecommendationService $recommendationService,
        private readonly GenealogyIntakeProposalPreviewService $previewService
    ) {}

    /**
     * Build a complete operator-ready workspace payload from a saved intake run snapshot.
     * Pure, side-effect free, deterministic, non-mutating.
     */
    public function buildWorkspace(array $run): array
    {
        $runKey = (string) ($run['run_key'] ?? '');

        $summary = $this->summaryService->summarizeRun($run);

        $queue = $this->queueService->buildQueue($run);
        $enrichedQueue = $this->assembler->enrichQueue($run, $queue);
        $enrichedQueue = $this->attachQueueActions($enrichedQueue);

        $plan = $this->draftService->plan($run);
        $enrichedPlan = $this->assembler->enrichDraftPlan($run, $plan);
        $enrichedPlan = $this->attachDraftActions($enrichedPlan);

        $workspace = [
            'run_key' => $runKey,
            'summary' => $summary,
            'queue' => $enrichedQueue,
            'draft_plan' => $enrichedPlan,
        ];

        $workspace['recommendations'] = $this->recommendationService->recommend($run, $workspace, $enrichedPlan);
        $workspace['proposal_previews'] = $this->buildProposalPreviews($run, $enrichedPlan);

        return $workspace;
    }

    private function attachQueueActions(array $queue): array
    {
        foreach (['ready_packets', 'blocked_packets', 'pending_packets'] as $bucket) {
            $entries = (array) ($queue[$bucket] ?? []);
            foreach ($entries as $i => $entry) {
                $entries[$i]['action'] = $this->actionService->recommendFromQueueEntry($entry);
            }
            $queue[$bucket] = $entries;
        }

        return $queue;
    }

    private function buildProposalPreviews(array $run, array $enrichedPlan): array
    {
        $previews = [];

        foreach ((array) ($enrichedPlan['ready_packets'] ?? []) as $entry) {
            $draftInput = (array) ($entry['draft_input'] ?? []);
            $packet = self::findPacketInRun($run, (string) ($entry['packet_key'] ?? ''), (string) ($entry['packet_label'] ?? ''));

            $previews[] = [
                'packet_key' => (string) ($entry['packet_key'] ?? ''),
                'packet_label' => (string) ($entry['packet_label'] ?? 'unknown'),
                'approval_apply_state' => $packet['approval_apply_state'] ?? null,
                'preview' => $this->previewService->preview($draftInput),
            ];
        }

        return [
            'ready_packets' => $previews,
            'count' => count($previews),
        ];
    }

    private function attachDraftActions(array $plan): array
    {
        foreach (['ready_packets', 'blocked_packets', 'pending_packets'] as $bucket) {
            $entries = (array) ($plan[$bucket] ?? []);
            foreach ($entries as $i => $entry) {
                $entries[$i]['action'] = $this->actionService->recommendFromDraftEntry($entry);
            }
            $plan[$bucket] = $entries;
        }

        return $plan;
    }

    private static function findPacketInRun(array $run, string $packetKey, string $packetLabel): array
    {
        foreach ((array) ($run['packets'] ?? []) as $packet) {
            $existingKey = (string) ($packet['packet_key'] ?? '');
            $existingLabel = (string) ($packet['packet_label'] ?? '');

            if ($packetKey !== '' && $existingKey === $packetKey) {
                return (array) $packet;
            }

            if ($packetLabel !== '' && $existingLabel === $packetLabel) {
                return (array) $packet;
            }
        }

        return [];
    }
}
