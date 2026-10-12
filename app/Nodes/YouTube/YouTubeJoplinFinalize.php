<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Nodes\PushoverNotify;
use App\Services\YouTubeJoplinDeliveryService;
use RuntimeException;

class YouTubeJoplinFinalize extends BaseNode
{
    public function execute(array $input): array
    {
        $data = $input['data'] ?? $input;
        $playlistId = $data['playlist_id'] ?? null;
        if (! is_string($playlistId) || $playlistId === '') {
            throw new RuntimeException('Nightly YouTube pipeline lost its playlist identity');
        }
        $videos = array_column($data['videos'] ?? [], null, 'video_id');
        $candidates = $data['delivery_candidates'] ?? array_values($videos);
        $selected = array_map(fn (array $candidate): array => $videos[$candidate['video_id']] ?? $candidate, $candidates);
        $delivery = app(YouTubeJoplinDeliveryService::class)->verify($playlistId, $selected);
        $organization = $data['organization_stats'] ?? [];
        $complete = $delivery['retry'] === 0 && empty($input['error'])
            && ($organization['status'] ?? null) === 'verified'
            && empty($organization['failed_operations']) && empty($organization['managed_index_conflict_count'])
            && empty($data['key_points_stats']['failed']);
        $data['pipeline_status'] = $complete ? 'complete' : 'needs_attention';
        $data['delivery_verified'] = $delivery['verified'];
        $data['delivery_retry'] = $delivery['retry'];
        $data['delivery_remaining'] = max(0, (int) ($data['delivery_backlog'] ?? count($selected)) - $delivery['verified']);
        $data['delivery_stats'] = $delivery;
        $data['organization_conflicts'] = $organization['managed_index_conflict_count'] ?? 0;
        $output = $this->standardOutput($data);
        if (! $complete) {
            throw new RuntimeException('Nightly YouTube pipeline needs attention; unfinished videos remain eligible for retry');
        }
        if ($this->resolveBooleanConfig('notify', true)) {
            $notice = app(PushoverNotify::class, ['config' => $this->config])->execute($output);
            if (! empty($notice['error'])) {
                throw new RuntimeException('Nightly YouTube final notification failed');
            }
        }

        return $output;
    }
}
