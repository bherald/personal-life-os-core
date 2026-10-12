<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use RuntimeException;

class PlosWorkboardService
{
    public function __construct(private readonly ?string $sourcePath = null) {}

    /**
     * @return array{version:int,source:string,updated_at:?string,source_hash:string,counts:array<string,int>,items:array<int,array<string,mixed>>}
     */
    public function payload(): array
    {
        $path = $this->path();
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('PLOS workboard source is unreadable.');
        }

        $items = $this->parseUnifiedWorkList($contents);
        $counts = [];
        foreach ($items as $item) {
            $group = (string) $item['status_group'];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }
        ksort($counts);

        $modifiedAt = filemtime($path);

        return [
            'version' => 1,
            'source' => 'docs/TODO.md#unified-work-list',
            'updated_at' => $modifiedAt !== false
                ? Carbon::createFromTimestamp($modifiedAt, (string) config('app.timezone', 'UTC'))->toIso8601String()
                : null,
            'source_hash' => hash('sha256', $contents),
            'counts' => $counts,
            'items' => $items,
        ];
    }

    /**
     * @return array<int,array{priority:int,id:string,status:string,status_group:string,area:string,summary:string}>
     */
    public function parseUnifiedWorkList(string $contents): array
    {
        $start = strpos($contents, '## Unified Work List');
        if ($start === false) {
            throw new RuntimeException('PLOS workboard source has no Unified Work List section.');
        }

        $section = substr($contents, $start + strlen('## Unified Work List'));
        if (preg_match('/^##\s+/m', $section, $match, PREG_OFFSET_CAPTURE) === 1) {
            $section = substr($section, 0, (int) $match[0][1]);
        }

        $items = [];
        foreach (preg_split('/\R/', $section) ?: [] as $line) {
            $trimmed = trim($line);
            if (! str_starts_with($trimmed, '|') || str_contains($trimmed, '---')) {
                continue;
            }

            $columns = array_map('trim', explode('|', trim($trimmed, '|')));
            if (count($columns) < 6 || ! ctype_digit($columns[0])) {
                continue;
            }

            [$priority, $id, $status, $area, $nextAction] = array_slice($columns, 0, 5);
            $items[] = [
                'priority' => (int) $priority,
                'id' => strip_tags($id),
                'status' => strip_tags($status),
                'status_group' => $this->statusGroup($status),
                'area' => strip_tags($area),
                'summary' => $this->summary($id, $nextAction),
            ];
        }

        if ($items === []) {
            throw new RuntimeException('PLOS Unified Work List contains no readable items.');
        }

        return $items;
    }

    private function path(): string
    {
        $path = $this->sourcePath ?? base_path('docs/TODO.md');
        if (! is_readable($path)) {
            throw new RuntimeException('PLOS workboard source is unavailable.');
        }

        return $path;
    }

    private function statusGroup(string $status): string
    {
        $normalized = strtolower($status);
        $tokens = array_values(array_filter(array_map(
            static fn (string $token): string => trim($token),
            preg_split('/[\/,|]+/', $normalized) ?: [],
        )));

        return match (true) {
            array_intersect($tokens, ['done', 'complete', 'completed']) !== [] => 'done',
            in_array('paused', $tokens, true) => 'paused',
            array_intersect($tokens, ['operator', 'gated']) !== [] => 'operator',
            in_array('parked', $tokens, true) => 'parked',
            array_intersect($tokens, ['maintenance', 'watch']) !== [] => 'watch',
            default => 'active',
        };
    }

    private function summary(string $id, string $nextAction): string
    {
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($nextAction)));
        $plain = str_replace('`', '', $plain);
        $max = $id === 'TODO-015-NEXT' ? 430 : 120;
        if (mb_strlen($plain) <= $max) {
            return $plain;
        }

        return rtrim(mb_substr($plain, 0, $max - 3), " \t\n\r\0\x0B,.;:").'...';
    }
}
