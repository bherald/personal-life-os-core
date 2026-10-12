<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;

/** Freeze original terminal media before admission; serialize only supported inputs. */
final class CliInputAdapter
{
    public function __construct(private readonly CliArtifactStore $artifacts, private readonly string $workspace,
        private readonly ?Closure $validateSkillSelection = null) {}

    public function capture(array $session, array $input, ?Closure $checkpoint = null): array
    {
        $turn = $session['turns'][array_key_last($session['turns'])]['id'];
        $bound = ['inline' => [], 'queued' => $session['pending_attachments'] ?? []];
        foreach ($input as $index => $item) {
            $type = $item['type'] ?? null;
            if (! in_array($type, ['localImage', 'localAudio', 'image', 'audio'], true)) {
                continue;
            }
            if (str_starts_with($type, 'local')) {
                if (! is_string($item['path'] ?? null)) {
                    throw new RuntimeException('Local media requires an original file path.');
                }
                $path = str_starts_with($item['path'], '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $item['path'])
                    ? $item['path'] : $this->workspace.DIRECTORY_SEPARATOR.$item['path'];
                $artifact = $this->artifacts->importFile($path, [$this->workspace]);
            } else {
                if (! is_string($item['url'] ?? null)
                    || ! preg_match('~^data:([a-z0-9.+-]+/[a-z0-9.+-]+);base64,([A-Za-z0-9+/]*={0,2})$~D', $item['url'], $match)) {
                    throw new RuntimeException('Embedded media requires a complete base64 data URL. Remote URLs are not fetched implicitly.');
                }
                $bytes = base64_decode($match[2], true);
                if ($bytes === false || base64_encode($bytes) !== $match[2]) {
                    throw new RuntimeException('Embedded media encoding is incomplete or invalid.');
                }
                $artifact = $this->artifacts->captureBytes($bytes);
                $artifact['declared_mime'] = $match[1];
            }
            $bound['inline'][$index] = $artifact;
            $session['attachments'][] = $artifact;
            $session['turn_attachments'][$turn] = $bound;
            if ($checkpoint !== null) {
                $session = $checkpoint($session);
            }
        }
        $session['turn_attachments'][$turn] = $bound;

        return $session;
    }

    public function parts(array $session, array $input, array $route): array
    {
        $turn = $session['turns'][array_key_last($session['turns'])]['id'];
        $bound = $session['turn_attachments'][$turn] ?? ['inline' => [], 'queued' => []];
        $parts = [];
        foreach ($input as $index => $item) {
            // Native skill metadata has already selected the verified PLOS
            // bundle. Its full instructions are supplied by the shared skill
            // path; the original selector remains in the persisted turn.
            if (($item['type'] ?? null) === 'skill') {
                if ($this->validateSkillSelection === null) {
                    throw new RuntimeException('Native skill input requires its verified PLOS bundle adapter.');
                }
                ($this->validateSkillSelection)($session, $item);

                continue;
            }
            if (($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $parts[] = ['type' => 'text', 'text' => $item['text']];

                continue;
            }
            $kind = match ($item['type'] ?? null) {
                'localImage', 'image' => 'image', 'localAudio', 'audio' => 'audio',
                default => throw new RuntimeException('This structured input requires its own admitted adapter. Original input is saved.'),
            };
            $artifact = $bound['inline'][$index] ?? null;
            if (! is_array($artifact)) {
                throw new RuntimeException('Original inline media was not captured for this turn.');
            }
            $parts[] = $this->media($artifact, $kind, $route, $item['detail'] ?? null);
        }
        foreach ($bound['queued'] as $artifact) {
            $bytes = $this->artifacts->read($artifact['sha256']);
            $mime = $artifact['mime_type'];
            $parts[] = ['type' => 'text', 'text' => 'PLOS attachment metadata: '.json_encode(array_intersect_key($artifact,
                array_flip(['name', 'sha256', 'bytes', 'mime_type', 'kind', 'parent_sha256', 'operation', 'genealogy',
                    'execution_complete', 'quality_status', 'review_required', 'source_frame_count', 'selected_frames', 'decoded_frames'])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"];
            if (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml', 'application/javascript', 'application/x-empty', 'inode/x-empty'], true)) {
                if (! mb_check_encoding($bytes, 'UTF-8')) {
                    throw new RuntimeException('Original text is not UTF-8; an explicitly selected derived encoding is required. No replacement bytes were sent.');
                }
                $parts[] = ['type' => 'text', 'text' => $bytes];
            } elseif (str_starts_with($mime, 'image/')) {
                $parts[] = $this->media($artifact, 'image', $route);
            } elseif (str_starts_with($mime, 'audio/')) {
                $parts[] = $this->media($artifact, 'audio', $route);
            } else {
                throw new RuntimeException('This attachment requires a document/media adapter. Original bytes are retained; no extraction or omission was performed.');
            }
        }

        return $parts === [] && in_array('skill', array_column($input, 'type'), true) ? [['type' => 'text', 'text' => '']] : $parts;
    }

    private function media(array $artifact, string $kind, array $route, ?string $detail = null): array
    {
        if (! in_array($kind, $route['input_modalities'] ?? ['text'], true)) {
            throw new RuntimeException('The selected runtime does not admit '.$kind.' input. Original media is retained without conversion or fallback.');
        }
        $bytes = $this->artifacts->read($artifact['sha256']);
        $mime = $artifact['mime_type'];
        $canonicalMime = fn ($value) => match ($value) {
            'audio/x-wav' => 'audio/wav', 'audio/mp3' => 'audio/mpeg', 'image/jpg' => 'image/jpeg', default => $value,
        };
        if (isset($artifact['declared_mime']) && $canonicalMime($artifact['declared_mime']) !== $canonicalMime($mime)) {
            throw new RuntimeException('Embedded media type differs from its original bytes.');
        }
        if ($kind === 'image' && in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            if ($detail !== null && ! in_array($detail, ['auto', 'low', 'high'], true)) {
                throw new RuntimeException('Selected image detail needs an admitted provider adapter.');
            }
            $image = ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes)];
            if ($detail !== null) {
                $image['detail'] = $detail;
            }

            return ['type' => 'image_url', 'image_url' => $image];
        }
        if ($kind === 'audio' && in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/mpeg'], true)) {
            return ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($bytes),
                'format' => $mime === 'audio/mpeg' ? 'mp3' : 'wav']];
        }
        throw new RuntimeException('Selected media format requires an explicit adapter. Original bytes remain intact.');
    }
}
