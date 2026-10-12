<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Custody\TaskCustodyService;
use Illuminate\Support\Facades\DB;

/** Per-video receipts; discovery and queue admission are never delivery proof. */
class YouTubeJoplinDeliveryService
{
    private const CANONICAL_PLACEHOLDER_SECTION = '/^## Key Points[\t ]*\R[\t ]*\R\*Key points will be extracted\*[\t ]*\R[\t ]*\R(?=^---[\t ]*\r?$)/m';

    public function __construct(private JoplinFilesService $reader) {}

    /** Existing generated-output contract; this is not semantic claim verification. */
    public function validGeneratedKeyPoints(array $points): bool
    {
        if (count($points) < 3 || count($points) > 8) {
            return false;
        }
        $seen = [];
        foreach ($points as $point) {
            if (! is_string($point) || trim($point) === ''
                || preg_match('/[\r\n]|will be extracted|to be generated|pending|placeholder/i', $point)) {
                return false;
            }
            $normalized = mb_strtolower(trim($point));
            if (in_array($normalized, $seen, true)) {
                return false;
            }
            $seen[] = $normalized;
        }

        return true;
    }

    /** Unique existing source bytes only; never infer another video/source identity. */
    public function placeholderSource(array $note): ?array
    {
        $noteId = $note['id'] ?? '';
        $videoId = $this->reader->youtubeVideoId($note);
        if (($note['type'] ?? null) !== 1 || ! is_string($noteId) || ! preg_match('/^[a-f0-9]{32}$/D', $noteId)
            || $videoId === null || ! is_string($note['content'] ?? null) || ! is_string($note['raw'] ?? null)) {
            return null;
        }
        if ($this->sourceWriteHeld($noteId)) {
            throw new InferenceAdmissionException('YouTube source write outcome is unknown; processing held');
        }
        if (preg_match_all(self::CANONICAL_PLACEHOLDER_SECTION, $note['content']) !== 1
            || preg_match_all('/^#{1,6}[\t ]+key points[\t ]*\r?$/mi', $note['content']) !== 1) {
            return null;
        }
        $matches = [];
        foreach (DB::table('youtube_transcripts')->where('video_id', $videoId)->get() as $source) {
            if ($source->video_id === $videoId && is_string($source->content) && trim($source->content) !== ''
                && str_contains($note['content'], $source->content)) {
                $matches[] = ['video_id' => $videoId, 'note_id' => $noteId, 'source_id' => (int) $source->id,
                    'source_hash' => hash('sha256', $source->content), 'wire_hash' => hash('sha256', $note['raw']),
                    'content' => $source->content];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function pendingItems(string $playlistId, array $items): array
    {
        $receipts = DB::table('youtube_joplin_deliveries')->where('playlist_id', $playlistId)
            ->get()->keyBy('video_id');
        $heldVideos = array_column($this->heldSourceWrites(), 'video_id');
        $pending = array_values(array_filter($items, fn (array $item): bool => empty($receipts[$item['video_id']]->verified_at)
            && ! in_array($item['video_id'], $heldVideos, true)));
        // Oldest attempt first prevents a repeatedly failing first batch from starving others.
        usort($pending, fn (array $a, array $b): int => [($receipts[$a['video_id']]->last_attempt_at ?? ''), $a['added_to_playlist_at'], $a['video_id']]
            <=> [($receipts[$b['video_id']]->last_attempt_at ?? ''), $b['added_to_playlist_at'], $b['video_id']]);

        return $pending;
    }

    public function attempted(string $playlistId, array $item): void
    {
        DB::table('youtube_joplin_deliveries')->insertOrIgnore([
            'playlist_id' => $playlistId, 'video_id' => $item['video_id'],
            'added_at' => $item['added_to_playlist_at'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('youtube_joplin_deliveries')->where('playlist_id', $playlistId)
            ->where('video_id', $item['video_id'])->update(['last_attempt_at' => now(), 'updated_at' => now()]);
    }

    public function verify(string $playlistId, array $videos): array
    {
        $report = ['verified' => 0, 'retry' => 0, 'reasons' => []];
        foreach ($videos as $video) {
            $this->attempted($playlistId, $video);
            $noteId = $video['joplin_note_id'] ?? null;
            try {
                $note = $noteId ? $this->reader->getNote($noteId, true) : null;
                $reason = $this->incompleteReason($note, $video);
            } catch (\Throwable) {
                $note = null;
                $reason = 'read_back_unavailable';
            }
            $values = ['note_id' => $noteId, 'last_error' => $reason, 'updated_at' => now()];
            if ($reason === null) {
                $values += ['verified_at' => now(), 'source_hash' => hash('sha256', $note['title']."\n".$note['content'])];
                $report['verified']++;
            } else {
                $values += ['verified_at' => null, 'source_hash' => null];
                $report['retry']++;
                $report['reasons'][$video['video_id']] = $reason;
            }
            DB::table('youtube_joplin_deliveries')->where('playlist_id', $playlistId)
                ->where('video_id', $video['video_id'])->update($values);
        }

        return $report;
    }

    /** One exact stored source; edited transcripts require explicit append-only review. */
    public function retainMissingOriginal(string $videoId, string $noteId, int $sourceId, bool $apply = false, ?string $expectedWireHash = null, ?string $expectedSourceHash = null, array $keyPoints = [], bool $preserveEditedTranscript = false, bool $replacePlaceholder = false): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId) || ! preg_match('/^[a-f0-9]{32}$/D', $noteId) || $sourceId < 1) {
            throw new \RuntimeException('Invalid exact YouTube source identity');
        }
        if ($this->sourceWriteHeld($noteId)) {
            return ['status' => 'hold', 'reason_code' => 'source_write_outcome_unknown', 'video_id' => $videoId, 'note_id' => $noteId];
        }
        $writer = app(JoplinWriteService::class);
        $locks = app(JoplinLockHandler::class);
        $lock = null;
        if ($apply) {
            $writer->assertReviewTargetCompatible();
            if (DB::table('joplin_queue_jobs')->whereIn('status', ['pending', 'processing'])->exists()) {
                throw new \RuntimeException('Source retention requires an idle Joplin queue');
            }
            if (! is_string($expectedWireHash) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedWireHash)) {
                throw new \RuntimeException('Apply requires the reviewed wire hash');
            }
            if (! is_string($expectedSourceHash) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSourceHash)) {
                throw new \RuntimeException('Apply requires the reviewed source hash');
            }
            $lock = $locks->acquireSyncLock();
        }
        try {
            $source = DB::table('youtube_transcripts')->where('id', $sourceId)->where('video_id', $videoId)->first();
            if (! $source || $source->video_id !== $videoId || ! is_string($source->content) || trim($source->content) === '') {
                throw new \RuntimeException('Exact stored transcript is missing');
            }
            if ($apply && ! hash_equals($expectedSourceHash, hash('sha256', $source->content))) {
                throw new \RuntimeException('Stored transcript changed since reviewed preview');
            }
            $snapshot = $writer->reviewSnapshot($noteId.'.md');
            if (! $snapshot || ! is_string($snapshot['etag'] ?? null) || $snapshot['etag'] === '') {
                throw new \RuntimeException('Fresh source retention snapshot requires an ETag');
            }
            if ($apply && ! hash_equals($expectedWireHash, $snapshot['hash'])) {
                throw new \RuntimeException('Source note changed since reviewed preview');
            }
            $fields = $writer->reviewNoteExactFields($snapshot['body'], $noteId);
            if ($this->reader->youtubeVideoId(['title' => $fields['title'], 'content' => $fields['content'], 'metadata' => []]) !== $videoId) {
                throw new \RuntimeException('Source retention note/video identity mismatch');
            }
            $report = ['status' => 'preview', 'dry_run' => ! $apply, 'video_id' => $videoId, 'note_id' => $noteId,
                'source_id' => $sourceId, 'source_hash' => hash('sha256', $source->content),
                'source_chars' => strlen($source->content), 'wire_hash' => $snapshot['hash']];
            $retained = str_contains(preg_replace('/\s+/u', ' ', $fields['content']), preg_replace('/\s+/u', ' ', trim($source->content)));
            $pointsPresent = preg_match('/^#{1,6}\s+key points\s*$/mi', $fields['content']) === 1;
            $validPoints = $this->validGeneratedKeyPoints($keyPoints);
            $appendPoints = ! $pointsPresent && $validPoints;
            $replacement = null;
            if ($replacePlaceholder) {
                // Only the complete canonical section can authorize replacement;
                // incidental quoted markers or operator-edited sections stay intact.
                $canonical = self::CANONICAL_PLACEHOLDER_SECTION;
                if (! $validPoints || ! str_contains($fields['content'], $source->content)
                    || preg_match_all('/^#{1,6}[\t ]+key points[\t ]*\r?$/mi', $fields['content']) !== 1
                    || preg_match_all($canonical, $fields['content']) !== 1) {
                    return array_replace($report, ['status' => 'hold', 'reason_code' => 'placeholder_source_binding_unqualified']);
                }
                $newline = str_contains($snapshot['body'], "\r\n") ? "\r\n" : "\n";
                $replacement = '## Key Points'.$newline.$newline
                    .implode($newline.$newline, array_map(fn (string $point): string => '- '.trim($point), $keyPoints)).$newline.$newline;
            }
            $report['key_points_disposition'] = $pointsPresent ? 'existing_section_preserved'
                : ($appendPoints ? 'validated_append' : 'required_key_points_missing');
            if ($replacement !== null) {
                $report['key_points_disposition'] = 'validated_placeholder_replace';
            }
            if ($retained && ! $appendPoints && $replacement === null) {
                return array_replace($report, ['status' => 'already_retained', 'reason_code' => 'original_already_present']);
            }
            // A changed or edited transcript is operator-owned, even when substantially shorter.
            $editedTranscript = preg_match('/^#{1,6}\s+[^\r\n]*transcript[^\r\n]*$/mi', $fields['content']) === 1;
            if (! $retained && $editedTranscript && ! $preserveEditedTranscript) {
                return array_replace($report, ['status' => 'hold', 'reason_code' => 'existing_transcript_requires_review']);
            }
            $newline = str_contains($snapshot['body'], "\r\n") ? "\r\n" : "\n";
            $addition = '';
            if ($appendPoints) {
                $addition .= $newline.'---'.$newline.$newline.'## Key Points'.$newline.$newline
                    .implode($newline.$newline, array_map(fn (string $point): string => '- '.trim($point), $keyPoints)).$newline.$newline;
            }
            if (! $retained) {
                $heading = $editedTranscript ? 'Archived Original Transcript' : 'Full Transcript';
                $addition .= $newline.'---'.$newline.$newline.'## '.$heading.$newline.$newline.$source->content
                    .$newline.$newline.'---'.$newline.$newline
                    .'Stored source: https://www.youtube.com/watch?v='.$videoId.'; youtube_transcripts.id='.$sourceId
                    .'; SHA-256='.$report['source_hash'].$newline.$newline;
            }
            // Retain every original body byte; advance only the ordinary sync timestamps.
            $revision = now()->utc()->format('Y-m-d\TH:i:s.v\Z');
            $footer = preg_replace_callback('/^(updated_time|user_updated_time):[^\r\n]*/m',
                fn (array $match): string => $match[1].': '.$revision,
                substr($snapshot['body'], $fields['footer_offset']));
            $prefix = substr($snapshot['body'], 0, $fields['footer_offset']);
            if ($replacement !== null) {
                $bodyOffset = strpos($snapshot['body'], $newline.$newline) + strlen($newline.$newline);
                $prefix = substr($prefix, 0, $bodyOffset)
                    .preg_replace_callback($canonical, fn (): string => $replacement, $fields['content'], 1)
                    .substr($prefix, $bodyOffset + strlen($fields['content']));
            }
            $wire = $prefix.$addition.$footer;
            $report['desired_wire_hash'] = hash('sha256', $wire);
            $reasonCode = $replacement !== null ? 'placeholder_key_points_replace'
                : ($retained ? 'missing_key_points_append' : 'missing_original_append');
            if (! $apply) {
                return $report + ['reason_code' => $reasonCode];
            }
            $raw = DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'youtube_knowledge')->value('config_value');
            $policy = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
            $relative = $policy['rewrite']['backup_relative'] ?? null;
            if (! is_string($relative) || ! preg_match('~^[A-Za-z0-9/_-]+$~D', $relative)) {
                throw new \RuntimeException('Source retention private backup policy is unavailable');
            }
            $directory = storage_path('app/private/'.$relative.'/'.$report['desired_wire_hash'].'/'.$videoId);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Cannot create private source retention backup');
            }
            chmod($directory, 0700);
            foreach ([$noteId.'.md' => $snapshot['body'], 'source.txt' => $source->content, 'source-record.json' => json_encode($source, JSON_THROW_ON_ERROR),
                'receipt.json' => json_encode($report + ['source_method' => $source->source_method, 'source_fetched_at' => $source->fetched_at], JSON_THROW_ON_ERROR)] as $name => $bytes) {
                $path = $directory.'/'.$name;
                if (is_file($path)) {
                    if (! hash_equals(hash_file('sha256', $path), hash('sha256', $bytes))) {
                        throw new \RuntimeException('Private source retention backup conflict');
                    }
                } else {
                    $handle = fopen($path, 'x+b');
                    if ($handle === false) {
                        throw new \RuntimeException('Cannot create private source retention backup file');
                    }
                    try {
                        chmod($path, 0600);
                        if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                            throw new \RuntimeException('Cannot durably retain source backup');
                        }
                    } finally {
                        fclose($handle);
                    }
                }
            }
            $directoryHandle = fopen($directory, 'r');
            if ($directoryHandle === false) {
                throw new \RuntimeException('Cannot open private backup directory for durability');
            }
            try {
                if (! fsync($directoryHandle)) {
                    throw new \RuntimeException('Cannot durably retain backup directory');
                }
            } finally {
                fclose($directoryHandle);
            }
            // Durable intent precedes any possible remote dispatch. Age/death never releases it.
            $owner = bin2hex(random_bytes(32));
            $envelope = $report + ['schema' => 'plos.youtube_source_write.v1', 'phase' => 'dispatch_intent'];
            $encoded = json_encode($envelope, JSON_THROW_ON_ERROR);
            $custodyId = DB::table('task_custody_records')->insertGetId([
                'surface' => TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE, 'surface_ref' => $noteId,
                'owner_token' => $owner, 'result_envelope' => $encoded, 'progress_note' => 'source_write_outcome_unknown',
                'acquired_at' => now(), 'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $intent = DB::table('task_custody_records')->where('id', $custodyId)->where('owner_token', $owner)->whereNull('released_at')->first();
            $published = $intent ? json_decode($intent->result_envelope, true, flags: JSON_THROW_ON_ERROR) : null;
            $expected = $envelope;
            // MySQL JSON normalizes object key order; preserve exact scalar types/values.
            if (is_array($published)) {
                ksort($published);
            }
            ksort($expected);
            if ($published !== $expected) {
                throw new \RuntimeException('Exact source write intent publication failed; dispatch held');
            }
            $encoded = $intent->result_envelope;
            try {
                $verified = $writer->reviewPut($noteId.'.md', $wire, $snapshot);
                if (! hash_equals($report['desired_wire_hash'], $verified['hash'] ?? '')) {
                    throw new \RuntimeException('Source retention exact read-back failed');
                }
                $released = DB::table('task_custody_records')->where('id', $custodyId)
                    ->where('surface', TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE)->where('surface_ref', $noteId)
                    ->where('owner_token', $owner)->whereRaw('CAST(result_envelope AS CHAR) = ?', [$encoded])->whereNull('released_at')
                    ->update(['released_at' => now(), 'outcome' => 'success', 'progress_note' => 'source_write_verified',
                        'result_envelope' => json_encode(array_replace($envelope, ['phase' => 'verified', 'verified_wire_hash' => $verified['hash']]), JSON_THROW_ON_ERROR),
                        'notification_state' => 'suppressed', 'updated_at' => now()]);
                if ($released !== 1) {
                    throw new \RuntimeException('Source retention terminal owner publication failed');
                }
            } catch (\Throwable $failure) {
                if (InferenceAdmissionException::isAdmissionFailure($failure)) {
                    throw $failure;
                }

                return array_replace($report, ['status' => 'hold', 'reason_code' => 'source_write_outcome_unknown']);
            }

            return array_replace($report, ['status' => 'retained', 'reason_code' => $reasonCode]);
        } finally {
            if ($lock !== null) {
                $locks->releaseLock($lock);
            }
        }
    }

    /** Reuse the exact preview/apply gate; conflicting edited sections stay held. */
    public function reconcileReusedVideo(array $video, string $noteId): array
    {
        $source = DB::table('youtube_transcripts')->where('video_id', $video['video_id'] ?? '')
            ->where('language', $video['transcript_language'] ?? 'en')->first();
        $original = $video['transcript_original'] ?? $video['transcript_full_text'] ?? null;
        if (! $source || ! is_string($original) || $original === '' || ! is_string($source->content)
            || ! hash_equals(hash('sha256', $source->content), hash('sha256', $original))) {
            return ['status' => 'hold', 'reason_code' => 'exact_stored_source_unavailable'];
        }
        $points = ($video['transcript_formatted'] ?? false) === true && is_array($video['key_points'] ?? null)
            ? $video['key_points'] : [];
        $preview = $this->retainMissingOriginal($video['video_id'], $noteId, (int) $source->id, keyPoints: $points);
        if ($preview['status'] !== 'preview') {
            return $preview;
        }

        return $this->retainMissingOriginal($video['video_id'], $noteId, (int) $source->id, true,
            $preview['wire_hash'], $preview['source_hash'], $points);
    }

    /** Explicit exact read-back reconciliation; never dispatches or regenerates a note. */
    public function reconcileSourceWrite(int $custodyId, string $videoId, string $noteId, int $sourceId, bool $apply = false, ?string $expectedCustodyHash = null): array
    {
        $row = DB::table('task_custody_records')->where('id', $custodyId)
            ->where('surface', TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE)->where('surface_ref', $noteId)
            ->whereNull('released_at')->first();
        $envelope = json_decode($row->result_envelope ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (($envelope['schema'] ?? null) !== 'plos.youtube_source_write.v1'
            || ($envelope['video_id'] ?? null) !== $videoId || ($envelope['note_id'] ?? null) !== $noteId
            || ($envelope['source_id'] ?? null) !== $sourceId || ($envelope['phase'] ?? null) !== 'dispatch_intent') {
            throw new \RuntimeException('Exact source write custody binding is unavailable');
        }
        $custodyHash = hash('sha256', $row->owner_token."\n".$row->result_envelope);
        if ($apply && (! is_string($expectedCustodyHash) || ! hash_equals($custodyHash, $expectedCustodyHash))) {
            throw new \RuntimeException('Source write custody changed since preview');
        }
        $writer = app(JoplinWriteService::class);
        $lock = null;
        if ($apply) {
            $writer->assertReviewTargetCompatible();
            if (DB::table('joplin_queue_jobs')->whereIn('status', ['pending', 'processing'])->exists()) {
                throw new \RuntimeException('Source reconciliation requires an idle Joplin queue');
            }
            $lock = app(JoplinLockHandler::class)->acquireSyncLock();
        }
        try {
            $source = DB::table('youtube_transcripts')->where('id', $sourceId)->where('video_id', $videoId)->first();
            $snapshot = $writer->reviewSnapshot($noteId.'.md');
            $verified = $source && is_string($source->content) && $snapshot
                && hash_equals($envelope['source_hash'], hash('sha256', $source->content))
                && hash_equals($envelope['desired_wire_hash'], $snapshot['hash']);
            $report = ['status' => 'hold', 'reason_code' => 'source_write_outcome_unknown', 'dry_run' => ! $apply,
                'custody_id' => $custodyId, 'custody_hash' => $custodyHash, 'video_id' => $videoId, 'note_id' => $noteId, 'source_id' => $sourceId];
            if (! $verified) {
                return $report;
            }
            if (! $apply) {
                return array_replace($report, ['status' => 'preview_verified']);
            }
            $released = DB::table('task_custody_records')->where('id', $row->id)->where('surface', $row->surface)
                ->where('surface_ref', $row->surface_ref)->where('owner_token', $row->owner_token)
                ->whereRaw('CAST(result_envelope AS CHAR) = ?', [$row->result_envelope])->whereNull('released_at')
                ->update(['released_at' => now(), 'outcome' => 'success', 'progress_note' => 'source_write_reconciled',
                    'result_envelope' => json_encode(array_replace($envelope, ['phase' => 'reconciled', 'verified_wire_hash' => $snapshot['hash']]), JSON_THROW_ON_ERROR),
                    'notification_state' => 'suppressed', 'updated_at' => now()]);
            if ($released !== 1) {
                throw new \RuntimeException('Exact source write reconciliation owner changed');
            }

            return array_replace($report, ['status' => 'reconciled', 'reason_code' => 'exact_write_readback_verified']);
        } finally {
            if ($lock !== null) {
                app(JoplinLockHandler::class)->releaseLock($lock);
            }
        }
    }

    /** Unknown outcomes remain held regardless of process death, expiry or note read-back. */
    public function heldSourceWrites(): array
    {
        return DB::table('task_custody_records')->where('surface', TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE)
            ->whereNull('released_at')->get()->map(function (object $row): array {
                $envelope = json_decode($row->result_envelope, true, flags: JSON_THROW_ON_ERROR);
                if (($envelope['schema'] ?? null) !== 'plos.youtube_source_write.v1'
                    || ($envelope['note_id'] ?? null) !== $row->surface_ref
                    || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $envelope['video_id'] ?? '')) {
                    throw new \RuntimeException('Source write custody is unqualified; automatic processing held');
                }

                return $envelope;
            })->all();
    }

    public function sourceWriteHeld(string $noteId): bool
    {
        try {
            return DB::table('task_custody_records')->where('surface', TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE)
                ->where('surface_ref', $noteId)->whereNull('released_at')->exists();
        } catch (\Throwable $failure) {
            if (InferenceAdmissionException::isAdmissionFailure($failure)) {
                throw $failure;
            }
            throw new InferenceAdmissionException('YouTube source write custody is unavailable; processing held', previous: $failure);
        }
    }

    private function incompleteReason(?array $note, array $video): ?string
    {
        if (is_string($video['joplin_note_id'] ?? null) && $this->sourceWriteHeld($video['joplin_note_id'])) {
            return 'source_write_outcome_unknown';
        }
        if (! $note || $note['type'] !== 1 || $this->reader->youtubeVideoId($note) !== $video['video_id']) {
            return 'delivery_not_verified';
        }
        if (($video['delivery_repair']['status'] ?? null) === 'hold') {
            $reason = $video['delivery_repair']['reason_code'] ?? null;

            return in_array($reason, ['exact_stored_source_unavailable', 'existing_transcript_requires_review'], true)
                ? $reason : 'reused_note_repair_held';
        }
        $body = $note['content'];
        if (! preg_match('/^## Key Points\s*\R(.*?)(?=^---\s*$|^## )/ms', $body, $points)
            || ! preg_match('/^-\s+\S/m', $points[1])
            || preg_match('/will be extracted|to be generated|pending|placeholder/i', $points[1])) {
            return 'key_points_incomplete';
        }
        $original = $video['transcript_original'] ?? $video['transcript_full_text'] ?? null;
        if (is_string($original) && $original !== '') {
            // An explicitly reviewed archived original can coexist with an edited
            // transcript. Compare retained evidence without replacing either body.
            $actual = preg_replace('/\s+/u', ' ', $body);
            $expected = preg_replace('/\s+/u', ' ', trim($original));

            return is_string($actual) && is_string($expected) && str_contains($actual, $expected)
                ? null : 'original_transcript_not_retained';
        }
        if (! preg_match('/^## (?:Full Transcript|Archived Original Transcript)\s*\R(.*?)(?=^---\s*$|^## Metadata|\z)/ms', $body, $text)) {
            return 'transcript_missing';
        }
        $transcript = trim(preg_replace('/^\*\*Transcript Stats:\*\*\R(?:- .*\R)*/m', '', $text[1]));

        if ($transcript === '' || str_contains(strtolower($transcript), 'transcript not available')) {
            return 'transcript_missing';
        }

        return null;
    }
}
