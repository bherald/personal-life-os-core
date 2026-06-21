<?php

namespace App\Services\Genealogy;

use App\Support\JsonColumn;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class GenealogyReviewPacketMaterializationService
{
    public function __construct(
        private readonly GenealogyReviewPacketValidatorService $validator = new GenealogyReviewPacketValidatorService,
        private readonly GenealogyReviewPacketAdapterService $adapter = new GenealogyReviewPacketAdapterService,
    ) {}

    public function materialize(array $packet, array $context = []): array
    {
        $validation = $this->validator->validate($packet);
        if (! $validation['valid']) {
            return [
                'success' => false,
                'error' => 'packet_validation_failed',
                'validation' => $validation,
            ];
        }

        $payload = $this->adapter->toReviewPayload($packet, $context);
        $dedupKey = (string) ($payload['details']['dedup_key'] ?? '');
        $packetKey = (string) ($payload['details']['packet_key'] ?? '');
        $existing = $this->findExistingPending($payload['agent_id'], $payload['review_type'], $dedupKey, $packetKey, $payload['title']);
        if ($existing !== null) {
            return [
                'success' => true,
                'materialized_existing' => true,
                'review_queue_id' => (int) $existing->id,
                'token' => (string) $existing->token,
                'payload' => $payload,
                'validation' => $validation,
            ];
        }

        $token = (string) ($context['token'] ?? Str::random(40));

        try {
            $insert = [
                'agent_id' => $payload['agent_id'],
                'review_type' => $payload['review_type'],
                'title' => $payload['title'],
                'summary' => $payload['summary'],
                'details' => json_encode($payload['details']),
                'confidence' => $payload['confidence'],
                'priority' => $payload['priority'],
                'status' => 'pending',
                'token' => $token,
                'expires_at' => $payload['expires_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('agent_review_queue', 'finding_type')) {
                $insert['finding_type'] = $payload['finding_type'] ?? null;
            }

            $id = DB::table('agent_review_queue')->insertGetId($insert);
        } catch (Throwable $e) {
            $existing = $this->findExistingPending($payload['agent_id'], $payload['review_type'], $dedupKey, $packetKey, $payload['title']);
            if ($existing !== null) {
                return [
                    'success' => true,
                    'materialized_existing' => true,
                    'review_queue_id' => (int) $existing->id,
                    'token' => (string) $existing->token,
                    'payload' => $payload,
                    'validation' => $validation,
                ];
            }

            return [
                'success' => false,
                'error' => 'review_queue_insert_failed',
                'message' => $e->getMessage(),
                'validation' => $validation,
            ];
        }

        return [
            'success' => true,
            'materialized_existing' => false,
            'review_queue_id' => (int) $id,
            'token' => $token,
            'payload' => $payload,
            'validation' => $validation,
        ];
    }

    private function findExistingPending(string $agentId, string $reviewType, string $dedupKey, string $packetKey, string $title): ?object
    {
        if ($dedupKey !== '') {
            $query = $this->pendingReviewPacketQuery($agentId, $reviewType);
            JsonColumn::whereScalarEquals($query, 'details', '$.dedup_key', $dedupKey);
            $existing = $query->orderBy('id')->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($packetKey !== '') {
            $query = $this->pendingReviewPacketQuery($agentId, $reviewType);
            JsonColumn::whereScalarEquals($query, 'details', '$.packet_key', $packetKey);
            $existing = $query->orderBy('id')->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($dedupKey === '' && $packetKey === '') {
            return $this->pendingReviewPacketQuery($agentId, $reviewType)
                ->where('title', $title)
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    private function pendingReviewPacketQuery(string $agentId, string $reviewType): Builder
    {
        return DB::table('agent_review_queue')
            ->select(['id', 'token'])
            ->where('agent_id', $agentId)
            ->where('review_type', $reviewType)
            ->where('status', 'pending');
    }
}
