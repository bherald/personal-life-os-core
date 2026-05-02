<?php

namespace App\Services;

class OllamaBoundedPayloadService
{
    public function __construct(
        private readonly OllamaPipelineProfileService $profiles
    ) {}

    public function buildPayload(string $taskClass, string $text, array $context = []): array
    {
        $profile = $this->profiles->getTaskProfile($taskClass);
        $pages = $this->splitPages($text);
        $chunks = $this->chunkPages(
            $pages,
            (int) ($profile['chunk_chars'] ?? 12000),
            (int) ($profile['max_pages_per_chunk'] ?? 1)
        );

        return [
            'task_class' => $taskClass,
            'profile' => [
                'route' => $profile['route'],
                'model_role' => $profile['model_role'],
                'output_schema' => $profile['output_schema'],
                'stages' => $profile['stages'],
                'human_gate_required' => $profile['human_gate_required'],
            ],
            'context' => $context,
            'chunk_count' => count($chunks),
            'chunks' => $chunks,
        ];
    }

    private function splitPages(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $pages = preg_split('/\f+/', $text) ?: [];
        $pages = array_values(array_filter(array_map(static fn (string $page): string => trim($page), $pages), static fn (string $page): bool => $page !== ''));

        if ($pages !== []) {
            return $pages;
        }

        return [$text];
    }

    private function chunkPages(array $pages, int $maxChars, int $maxPagesPerChunk): array
    {
        if ($pages === []) {
            return [];
        }

        $chunks = [];
        $currentPages = [];
        $currentChars = 0;
        $chunkStartPage = 1;

        foreach ($pages as $index => $pageText) {
            $pageNumber = $index + 1;
            $pageLength = mb_strlen($pageText);
            $wouldExceedPages = count($currentPages) >= $maxPagesPerChunk;
            $wouldExceedChars = $currentPages !== [] && ($currentChars + $pageLength + 2) > $maxChars;

            if ($wouldExceedPages || $wouldExceedChars) {
                $chunks[] = $this->formatChunk($chunks, $currentPages, $chunkStartPage);
                $currentPages = [];
                $currentChars = 0;
                $chunkStartPage = $pageNumber;
            }

            if ($pageLength > $maxChars) {
                if ($currentPages !== []) {
                    $chunks[] = $this->formatChunk($chunks, $currentPages, $chunkStartPage);
                    $currentPages = [];
                    $currentChars = 0;
                    $chunkStartPage = $pageNumber;
                }

                foreach ($this->chunkLongPage($pageText, $maxChars) as $partIndex => $part) {
                    $chunks[] = [
                        'chunk_index' => count($chunks) + 1,
                        'page_start' => $pageNumber,
                        'page_end' => $pageNumber,
                        'char_count' => mb_strlen($part),
                        'text' => $part,
                        'page_fragment' => $partIndex + 1,
                    ];
                }

                $chunkStartPage = $pageNumber + 1;
                continue;
            }

            $currentPages[] = [
                'page_number' => $pageNumber,
                'text' => $pageText,
            ];
            $currentChars += $pageLength;
        }

        if ($currentPages !== []) {
            $chunks[] = $this->formatChunk($chunks, $currentPages, $chunkStartPage);
        }

        return $chunks;
    }

    private function formatChunk(array $existingChunks, array $pages, int $chunkStartPage): array
    {
        $text = implode("\n\n", array_map(
            static fn (array $page): string => $page['text'],
            $pages
        ));

        return [
            'chunk_index' => count($existingChunks) + 1,
            'page_start' => $chunkStartPage,
            'page_end' => $pages[array_key_last($pages)]['page_number'],
            'char_count' => mb_strlen($text),
            'text' => $text,
        ];
    }

    private function chunkLongPage(string $text, int $maxChars): array
    {
        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunks[] = mb_substr($text, $offset, $maxChars);
            $offset += $maxChars;
        }

        return $chunks;
    }
}
