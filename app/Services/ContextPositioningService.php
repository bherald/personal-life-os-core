<?php

namespace App\Services;

/**
 * Place the strongest retrieved evidence at the prompt boundaries.
 *
 * The ordering algorithm is adapted from Haystack's LostInTheMiddleRanker:
 * https://github.com/deepset-ai/haystack/blob/acc9e58a66d038622cafe40fe863691f812a66e6/haystack/components/rankers/lost_in_the_middle.py
 *
 * SPDX-FileCopyrightText: 2022-present deepset GmbH <info@deepset.ai>
 * SPDX-License-Identifier: Apache-2.0
 *
 * PLOS modifications: operate on native RAG result arrays, retain retrieval
 * rank, use stable document identities, and expose prompt context positions.
 */
class ContextPositioningService
{
    /**
     * @param  array<int,array<string,mixed>>  $rankedResults
     * @return array<int,array<string,mixed>>
     */
    public function position(array $rankedResults): array
    {
        $deduplicated = [];
        $seen = [];

        foreach (array_values($rankedResults) as $index => $result) {
            $identity = $this->identity($result);
            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $result['retrieval_rank'] = $result['retrieval_rank'] ?? ($index + 1);
            $deduplicated[] = $result;
        }

        if (count($deduplicated) <= 1) {
            if ($deduplicated !== []) {
                $deduplicated[0]['context_position'] = 1;
            }

            return $deduplicated;
        }

        $indices = [0];
        for ($index = 1, $count = count($deduplicated); $index < $count; $index++) {
            $insertionIndex = intdiv(count($indices), 2) + (count($indices) % 2);
            array_splice($indices, $insertionIndex, 0, [$index]);
        }

        $positioned = [];
        foreach ($indices as $position => $index) {
            $result = $deduplicated[$index];
            $result['context_position'] = $position + 1;
            $positioned[] = $result;
        }

        return $positioned;
    }

    /** @param array<string,mixed> $result */
    private function identity(array $result): string
    {
        $document = $result['document'] ?? null;
        $id = is_object($document) ? ($document->id ?? null) : ($document['id'] ?? null);

        if (is_numeric($id) && (int) $id > 0) {
            return 'id:'.(int) $id;
        }

        $url = is_object($document)
            ? ($document->media_url ?? $document->url ?? null)
            : ($document['media_url'] ?? $document['url'] ?? null);
        $content = is_object($document)
            ? ($document->content ?? '')
            : ($document['content'] ?? '');

        return 'external:'.hash('sha256', (string) $url."\0".(string) $content);
    }
}
