<?php

namespace App\Services\UnifiedCli;

use RuntimeException;
use Throwable;

/** Retain HTR transport first; execution completion never grants text acceptance. */
final class CliHtrResult
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliArtifactStore $artifacts,
    ) {}

    public function retain(string $sourceHash, string $stdout, string $stderr, ?int $exitCode, bool $interrupted, array $expected, bool $custodyLost = false): array
    {
        $interrupted = $interrupted || $custodyLost;
        $session = $this->store->session($this->sessionId);
        if (! in_array($sourceHash, array_column($session['attachments'] ?? [], 'sha256'), true)) {
            throw new RuntimeException('HTR result must belong to an original artifact in this session.');
        }
        $transport = $this->artifacts->captureBytes($stdout);
        $diagnostics = $this->artifacts->captureBytes($stderr);
        $captureId = bin2hex(random_bytes(16));
        $this->store->recordEvent($this->sessionId, 'htr_transport', ['capture_id' => $captureId, 'source_sha256' => $sourceHash,
            'stdout' => $transport, 'stderr' => $diagnostics, 'exit_code' => $exitCode, 'interrupted' => $interrupted, 'custody_lost' => $custodyLost]);

        $segments = [];
        $pending = null;
        $final = null;
        $error = null;
        $expectedValid = false;
        $framed = array_key_exists('frame_selection', $expected);
        $frameMetadata = null;
        $frameCursor = 0;
        $stripCursor = 0;
        $allowedExpected = ['model', 'device', 'max_new_tokens', 'eos_token_ids', 'binding_sha256', 'compute_instance', 'frame_selection', 'source_frame_count'];
        $safeExpected = array_intersect_key($expected, array_flip($allowedExpected));
        try {
            if (array_diff(array_keys($expected), $allowedExpected) !== []
                || ! is_string($expected['model'] ?? null) || ! str_starts_with($expected['model'], '/') || str_contains($expected['model'], "\0")
                || ! in_array($expected['device'] ?? null, ['cpu', 'cuda'], true)
                || ! is_int($expected['max_new_tokens'] ?? null) || $expected['max_new_tokens'] < 1 || $expected['max_new_tokens'] > 4096
                || ! is_array($expected['eos_token_ids'] ?? null) || ! array_is_list($expected['eos_token_ids'])
                || $expected['eos_token_ids'] === []) {
                throw new RuntimeException('invalid_expected_htr_contract');
            }
            foreach ($expected['eos_token_ids'] as $token) {
                if (! is_int($token) || $token < 0) {
                    throw new RuntimeException('invalid_expected_htr_contract');
                }
            }
            if (isset($expected['binding_sha256']) && (! is_string($expected['binding_sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $expected['binding_sha256']))) {
                throw new RuntimeException('invalid_expected_htr_contract');
            }
            if (isset($expected['compute_instance']) && (! is_string($expected['compute_instance']) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $expected['compute_instance']))) {
                throw new RuntimeException('invalid_expected_htr_contract');
            }
            if ($framed && $expected['frame_selection'] !== 'all') {
                $selection = $expected['frame_selection'];
                if (! is_array($selection) || ! array_is_list($selection) || $selection === []) {
                    throw new RuntimeException('invalid_expected_htr_frames');
                }
                $seen = [];
                foreach ($selection as $number) {
                    if (! is_int($number) || $number < 1 || in_array($number, $seen, true)) {
                        throw new RuntimeException('invalid_expected_htr_frames');
                    }
                    $seen[] = $number;
                }
            }
            if (($framed && (! is_int($expected['source_frame_count'] ?? null) || $expected['source_frame_count'] < 1))
                || (! $framed && array_key_exists('source_frame_count', $expected))) {
                throw new RuntimeException('invalid_expected_htr_frames');
            }
            $expectedValid = true;
            $lines = explode("\n", $stdout);
            $tail = array_pop($lines);
            foreach ($lines as $index => $line) {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($record) || array_is_list($record) || $final !== null) {
                    throw new RuntimeException('invalid_htr_record_order');
                }
                $schema = $record['schema'] ?? null;
                if ($schema === 'plos.cli.htr-identity.v1' && $index === 0) {
                    // Diagnostic data only. Worker claims cannot grant host/runtime authority.
                    continue;
                }
                if ($framed && $schema === 'plos.cli.htr-frames.v1' && $frameMetadata === null && $segments === [] && $pending === null) {
                    $frameMetadata = $this->validateFrames($record, $expected);

                    continue;
                }
                if ($schema === ($framed ? 'plos.cli.htr-segment.v2' : 'plos.cli.htr-segment.v1')) {
                    $stage = $record['stage'] ?? null;
                    $positionKeys = $framed ? ['frame_number', 'frame_strip_index', 'top', 'bottom'] : [];
                    $segment = array_intersect_key($record, array_flip(['index', 'text', 'token_ids', 'complete', 'width', 'height', ...$positionKeys]));
                    if (count($segment) !== 6 + count($positionKeys) || ! is_int($segment['index']) || $segment['index'] !== count($segments)
                        || ! is_int($segment['width']) || $segment['width'] < 1 || ! is_int($segment['height']) || $segment['height'] < 1
                        || ! is_array($segment['token_ids']) || ! array_is_list($segment['token_ids']) || $segment['token_ids'] === []
                        || ! is_bool($segment['complete'])) {
                        throw new RuntimeException('invalid_htr_segment');
                    }
                    foreach ($segment['token_ids'] as $token) {
                        if (! is_int($token) || $token < 0) {
                            throw new RuntimeException('invalid_htr_token');
                        }
                    }
                    if (count($segment['token_ids']) > $expected['max_new_tokens'] + 1) {
                        throw new RuntimeException('htr_output_exceeds_selected_limit');
                    }
                    if ($framed) {
                        $frame = $frameMetadata['frames'][$frameCursor] ?? null;
                        if ($frame === null) {
                            throw new RuntimeException('missing_or_exhausted_htr_frame_plan');
                        }
                        $top = $stripCursor * $this->stripHeight($frame['width'], $frame['height']);
                        $bottom = min($top + $this->stripHeight($frame['width'], $frame['height']), $frame['height']);
                        foreach (['frame_number' => $frame['frame_number'], 'frame_strip_index' => $stripCursor,
                            'top' => $top, 'bottom' => $bottom, 'width' => $frame['width'], 'height' => $bottom - $top] as $key => $value) {
                            if ($segment[$key] !== $value) {
                                throw new RuntimeException('htr_frame_coverage_mismatch');
                            }
                        }
                    }
                    if ($stage === 'generated' && $pending === null && $segment['text'] === null && $segment['complete'] === false) {
                        $pending = $segment;

                        continue;
                    }
                    if ($stage !== 'decoded' || $pending === null || ! is_string($segment['text'])) {
                        throw new RuntimeException('invalid_htr_segment_order');
                    }
                    foreach (['index', 'token_ids', 'width', 'height', ...$positionKeys] as $key) {
                        if ($segment[$key] !== $pending[$key]) {
                            throw new RuntimeException('htr_segment_identity_changed');
                        }
                    }
                    $eos = in_array($segment['token_ids'][array_key_last($segment['token_ids'])], $expected['eos_token_ids'], true);
                    if ($segment['complete'] !== $eos) {
                        throw new RuntimeException('htr_eos_contract_mismatch');
                    }
                    $segments[] = $segment;
                    $pending = null;
                    if ($framed && ++$stripCursor === $frameMetadata['frames'][$frameCursor]['strip_count']) {
                        $frameCursor++;
                        $stripCursor = 0;
                    }

                    continue;
                }
                if ($schema === ($framed ? 'plos.cli.htr-result.v2' : 'plos.cli.htr-result.v1') || isset($record['error'])) {
                    $final = $record;

                    continue;
                }
                throw new RuntimeException('unknown_htr_record');
            }
            if ($tail !== '' || $final === null) {
                throw new RuntimeException('incomplete_htr_stream');
            }
            if (isset($final['error'])) {
                throw new RuntimeException('htr_worker_error');
            }
            if ($pending !== null || $segments === [] || ($final['complete'] ?? null) !== true
                || ($final['fallback_used'] ?? null) !== false || ($final['model'] ?? null) !== $expected['model']
                || ($final['device'] ?? null) !== $expected['device'] || ($final['max_new_tokens'] ?? null) !== $expected['max_new_tokens']
                || ($final['processed_strips'] ?? null) !== count($segments) || ($final['total_strips'] ?? null) !== count($segments)
                || ($final['line_count'] ?? null) !== count($segments) || ($final['lines'] ?? null) !== array_column($segments, 'text')
                || ($final['text'] ?? null) !== $this->text($segments, $framed)
                || ! is_array($final['segments'] ?? null) || ! array_is_list($final['segments']) || count($final['segments']) !== count($segments)) {
                throw new RuntimeException('htr_final_contract_mismatch');
            }
            foreach ($segments as $index => $segment) {
                if (! $segment['complete'] || ! is_array($final['segments'][$index])) {
                    throw new RuntimeException('incomplete_htr_segment');
                }
                foreach ($segment as $key => $value) {
                    if (! array_key_exists($key, $final['segments'][$index]) || $final['segments'][$index][$key] !== $value) {
                        throw new RuntimeException('htr_final_segment_changed');
                    }
                }
            }
            if ($framed) {
                if ($frameMetadata === null || $frameCursor !== count($frameMetadata['frames']) || $stripCursor !== 0
                    || $frameMetadata['total_strips'] !== count($segments)) {
                    throw new RuntimeException('incomplete_htr_frame_coverage');
                }
                foreach (['source_frame_count', 'frame_selection', 'selected_frames'] as $key) {
                    if (($final[$key] ?? null) !== $frameMetadata[$key]) {
                        throw new RuntimeException('htr_final_frames_changed');
                    }
                }
                $this->validateFrames($final, $expected);
                foreach ($frameMetadata['frames'] as $index => $frame) {
                    foreach ($frame as $key => $value) {
                        if (($final['frames'][$index][$key] ?? null) !== $value) {
                            throw new RuntimeException('htr_final_frames_changed');
                        }
                    }
                }
                $texts = $this->frameTexts($segments);
                if (! is_array($final['frame_texts'] ?? null) || ! array_is_list($final['frame_texts']) || count($final['frame_texts']) !== count($texts)) {
                    throw new RuntimeException('htr_final_frame_text_changed');
                }
                foreach ($texts as $index => $frame) {
                    foreach ($frame as $key => $value) {
                        if (($final['frame_texts'][$index][$key] ?? null) !== $value) {
                            throw new RuntimeException('htr_final_frame_text_changed');
                        }
                    }
                }
            }
            if ($exitCode !== 0 || $interrupted) {
                throw new RuntimeException('htr_process_not_completed');
            }
        } catch (Throwable $failure) {
            $error = $failure instanceof \JsonException ? 'invalid_htr_json' : $failure->getMessage();
        }

        $complete = $error === null;
        $text = $complete ? $final['text'] : $this->text($segments, $framed);
        $output = $this->artifacts->derived($sourceHash, $text, 'PLOS HTR '.($complete ? 'complete' : 'partial').' decoded output');
        $output += ['name' => $sourceHash.'.htr.txt', 'execution_complete' => $complete,
            'quality_status' => 'unreviewed', 'review_required' => true];
        if ($frameMetadata !== null) {
            $output += ['source_frame_count' => $frameMetadata['source_frame_count'],
                'selected_frames' => $frameMetadata['selected_frames'],
                'decoded_frames' => array_values(array_unique(array_column($segments, 'frame_number')))];
        }
        $receipt = ['capture_id' => $captureId, 'source_sha256' => $sourceHash, 'method' => 'htr', 'success' => $complete,
            'execution_complete' => $complete, 'quality_status' => 'unreviewed', 'review_required' => true,
            'exit_code' => $exitCode, 'interrupted' => $interrupted, 'custody_lost' => $custodyLost, 'error' => $error,
            'expected_runtime' => $expectedValid ? $safeExpected : [], 'runtime_admission_checked' => false,
            'frame_plan' => $frameMetadata,
            'decoded_segments' => count($segments), 'pending_generated_segment' => $pending,
            'output' => $output, 'transport' => $transport, 'diagnostics' => $diagnostics];
        $this->store->recordEvent($this->sessionId, 'media_extraction', $receipt);

        return $receipt;
    }

    private function validateFrames(array $record, array $expected): array
    {
        $count = $record['source_frame_count'] ?? null;
        $selected = $record['selected_frames'] ?? null;
        $frames = $record['frames'] ?? null;
        if (! is_int($count) || $count < 1 || ! is_array($selected) || ! array_is_list($selected) || $selected === []
            || ! is_array($frames) || ! array_is_list($frames) || count($frames) !== count($selected)
            || ($record['frame_selection'] ?? null) !== $expected['frame_selection']
            || (isset($expected['source_frame_count']) && $count !== $expected['source_frame_count'])
            || ($expected['frame_selection'] === 'all' ? count($selected) !== $count : $selected !== $expected['frame_selection'])) {
            throw new RuntimeException('invalid_htr_frame_plan');
        }
        $total = 0;
        $metadata = [];
        foreach ($selected as $index => $number) {
            $frame = $frames[$index];
            if (! is_int($number) || $number < 1 || $number > $count || ($expected['frame_selection'] === 'all' && $number !== $index + 1)
                || ! is_array($frame) || ($frame['frame_number'] ?? null) !== $number
                || ! is_int($frame['width'] ?? null) || $frame['width'] < 1 || ! is_int($frame['height'] ?? null) || $frame['height'] < 1) {
                throw new RuntimeException('invalid_htr_frame_plan');
            }
            $strips = intdiv($frame['height'] - 1, $this->stripHeight($frame['width'], $frame['height'])) + 1;
            if (($frame['strip_count'] ?? null) !== $strips) {
                throw new RuntimeException('invalid_htr_frame_strip_count');
            }
            $total += $strips;
            $metadata[] = array_intersect_key($frame, array_flip(['frame_number', 'width', 'height', 'strip_count']));
        }
        if (! is_int($total) || ($record['total_strips'] ?? null) !== $total) {
            throw new RuntimeException('invalid_htr_frame_strip_count');
        }

        return ['source_frame_count' => $count, 'frame_selection' => $record['frame_selection'],
            'selected_frames' => $selected, 'frames' => $metadata, 'total_strips' => $total];
    }

    private function stripHeight(int $width, int $height): int
    {
        return $height > $width * 2 && $height > 200 ? max(80, intdiv($height, max(1, intdiv($height, 120)))) : $height;
    }

    private function frameTexts(array $segments): array
    {
        $frames = [];
        foreach ($segments as $segment) {
            $frames[$segment['frame_number']][] = $segment['text'];
        }
        $result = [];
        foreach ($frames as $number => $lines) {
            $result[] = ['frame_number' => $number, 'text' => implode("\n", $lines)];
        }

        return $result;
    }

    private function text(array $segments, bool $framed): string
    {
        return $framed ? implode("\f", array_column($this->frameTexts($segments), 'text')) : implode("\n", array_column($segments, 'text'));
    }
}
