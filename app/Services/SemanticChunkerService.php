<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Traits\RecursionAware;
use Exception;

/**
 * Semantic Chunking Service for RAG
 *
 * Splits text into semantically coherent chunks by detecting topic shifts
 * using sentence embeddings. Instead of fixed-size chunks, this approach:
 *
 * 1. Splits text into sentences
 * 2. Generates embeddings for each sentence
 * 3. Calculates cosine distances between adjacent sentence embeddings
 * 4. Identifies breakpoints where distance exceeds a threshold (topic shifts)
 * 5. Groups sentences between breakpoints into coherent chunks
 *
 * Benefits:
 * - Chunks maintain semantic coherence (single topics)
 * - Reduces mid-sentence/mid-thought splits
 * - Improves retrieval accuracy by 70%+ vs fixed-size chunking
 *
 * @see https://api.python.langchain.com/en/latest/text_splitter/langchain_experimental.text_splitter.SemanticChunker.html
 */
class SemanticChunkerService
{
    use RecursionAware;

    private AIService $aiService;

    /**
     * Common abbreviations that should NOT trigger sentence splits
     */
    private array $abbreviations = [
        'Dr', 'Mr', 'Mrs', 'Ms', 'Jr', 'Sr', 'Prof', 'Rev',
        'Gen', 'Col', 'Lt', 'Sgt', 'Capt', 'Cmdr', 'Adm',
        'Gov', 'Sen', 'Rep', 'Hon', 'Pres',
        'St', 'Ave', 'Blvd', 'Rd', 'Hwy',
        'Inc', 'Ltd', 'Corp', 'Co', 'LLC',
        'vs', 'etc', 'al', 'eg', 'ie', 'cf',
        'Jan', 'Feb', 'Mar', 'Apr', 'Jun', 'Jul', 'Aug', 'Sep', 'Sept', 'Oct', 'Nov', 'Dec',
        'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
        'Fig', 'No', 'Vol', 'pp', 'Ph', 'approx', 'Est',
    ];

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Chunk text using semantic similarity analysis
     *
     * @param string $text Text to chunk
     * @param array $options Chunking options:
     *   - breakpoint_percentile: int (default 90) - Threshold percentile for splits (higher = fewer chunks)
     *   - breakpoint_method: string (default 'percentile') - Method: percentile, standard_deviation, interquartile, gradient
     *   - min_chunk_size: int (default 100) - Minimum characters per chunk
     *   - max_chunk_size: int (default 2000) - Maximum characters per chunk (will split if exceeded)
     *   - overlap_percent: int (default 15) - Overlap percentage between chunks (10-20% recommended)
     *   - min_sentences_per_chunk: int (default 2) - Minimum sentences before allowing a split
     * @return array Array of chunk strings
     */
    public function chunk(string $text, array $options = []): array
    {
        // RLM: Try recursive semantic chunking
        $rlm = $this->tryRecursive('semantic_chunker', 'quality_gate_retry', ['text' => $text, 'options' => $options], function ($ctx) {
            return $this->chunk($ctx['text'] ?? $ctx['data'], $ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $startTime = microtime(true);

        // Default options targeting 256-512 tokens (~1000-2000 chars)
        $breakpointPercentile = $options['breakpoint_percentile'] ?? 90;
        $breakpointMethod = $options['breakpoint_method'] ?? 'percentile';
        $minChunkSize = $options['min_chunk_size'] ?? 100;
        $maxChunkSize = $options['max_chunk_size'] ?? 2000;
        $overlapPercent = $options['overlap_percent'] ?? 15;
        $minSentencesPerChunk = $options['min_sentences_per_chunk'] ?? 2;

        // Split into sentences
        $sentences = $this->splitSentences($text);

        if (count($sentences) <= 1) {
            Log::debug('SemanticChunker: Text has 1 or fewer sentences, returning as single chunk');
            return [trim($text)];
        }

        // Generate embeddings for all sentences
        $embeddings = $this->embedBatch($sentences);

        if (empty($embeddings) || count($embeddings) !== count($sentences)) {
            Log::warning('SemanticChunker: Embedding generation failed, falling back to fixed-size chunking');
            return $this->fallbackFixedSizeChunk($text, $maxChunkSize, $overlapPercent);
        }

        // Calculate cosine distances between adjacent sentences
        $distances = [];
        for ($i = 0; $i < count($embeddings) - 1; $i++) {
            $distances[] = 1 - $this->cosineSimilarity($embeddings[$i], $embeddings[$i + 1]);
        }

        if (empty($distances)) {
            return [trim($text)];
        }

        // Find threshold based on selected method
        $threshold = $this->calculateThreshold($distances, $breakpointMethod, $breakpointPercentile);

        // Find breakpoints (topic shifts)
        $breakpoints = [];
        $sentenceCount = 0;
        for ($i = 0; $i < count($distances); $i++) {
            $sentenceCount++;
            // Only allow breakpoint if we have enough sentences accumulated
            if ($distances[$i] > $threshold && $sentenceCount >= $minSentencesPerChunk) {
                $breakpoints[] = $i;
                $sentenceCount = 0;
            }
        }

        // Build chunks from sentences using breakpoints
        $chunks = [];
        $startIdx = 0;

        foreach ($breakpoints as $breakIdx) {
            $chunkSentences = array_slice($sentences, $startIdx, $breakIdx - $startIdx + 1);
            $chunkText = implode(' ', $chunkSentences);

            // Apply size constraints
            if (strlen($chunkText) >= $minChunkSize) {
                if (strlen($chunkText) > $maxChunkSize) {
                    // Split oversized chunks
                    $subChunks = $this->splitOversizedChunk($chunkText, $maxChunkSize, $overlapPercent);
                    $chunks = array_merge($chunks, $subChunks);
                } else {
                    $chunks[] = trim($chunkText);
                }
            }
            $startIdx = $breakIdx + 1;
        }

        // Add remaining sentences as final chunk
        if ($startIdx < count($sentences)) {
            $chunkSentences = array_slice($sentences, $startIdx);
            $chunkText = implode(' ', $chunkSentences);

            if (strlen($chunkText) >= $minChunkSize) {
                if (strlen($chunkText) > $maxChunkSize) {
                    $subChunks = $this->splitOversizedChunk($chunkText, $maxChunkSize, $overlapPercent);
                    $chunks = array_merge($chunks, $subChunks);
                } else {
                    $chunks[] = trim($chunkText);
                }
            } elseif (!empty($chunks)) {
                // Append to previous chunk if too small
                $chunks[count($chunks) - 1] .= ' ' . trim($chunkText);
            } else {
                $chunks[] = trim($chunkText);
            }
        }

        // Apply overlap between chunks
        if ($overlapPercent > 0 && count($chunks) > 1) {
            $chunks = $this->applyOverlap($chunks, $overlapPercent);
        }

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        Log::info('SemanticChunker: Chunking completed', [
            'input_length' => strlen($text),
            'sentences' => count($sentences),
            'breakpoints' => count($breakpoints),
            'chunks' => count($chunks),
            'threshold' => round($threshold, 4),
            'method' => $breakpointMethod,
            'duration_ms' => $durationMs,
        ]);

        return $chunks;
    }

    /**
     * Split text into sentences with proper handling of abbreviations
     *
     * @param string $text Input text
     * @return array Array of sentences
     */
    public function splitSentences(string $text): array
    {
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (empty($text)) {
            return [];
        }

        // Protect abbreviations by replacing periods with placeholder
        $placeholder = '<<<ABBR>>>';
        $protected = $text;

        foreach ($this->abbreviations as $abbr) {
            // Match abbreviation followed by period (case insensitive)
            $protected = preg_replace(
                '/\b(' . preg_quote($abbr, '/') . ')\./i',
                '$1' . $placeholder,
                $protected
            );
        }

        // Protect decimal numbers (e.g., 3.14)
        $protected = preg_replace('/(\d)\.(\d)/', '$1' . $placeholder . '$2', $protected);

        // Protect ellipsis
        $protected = str_replace('...', '<<<ELLIPSIS>>>', $protected);

        // Split on sentence-ending punctuation
        $sentences = preg_split(
            '/(?<=[.!?])\s+(?=[A-Z0-9])/',
            $protected,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        // Restore protected sequences
        $sentences = array_map(function ($sentence) use ($placeholder) {
            $sentence = str_replace($placeholder, '.', $sentence);
            $sentence = str_replace('<<<ELLIPSIS>>>', '...', $sentence);
            return trim($sentence);
        }, $sentences);

        // Filter empty sentences
        return array_values(array_filter($sentences, fn($s) => strlen(trim($s)) > 0));
    }

    /**
     * Generate embeddings for a batch of sentences
     *
     * @param array $sentences Array of sentence strings
     * @return array Array of embedding vectors (768-dim each for nomic-embed-text)
     */
    private function embedBatch(array $sentences): array
    {
        $embeddings = [];

        foreach ($sentences as $sentence) {
            $result = $this->aiService->generateEmbedding($sentence);

            if (!$result['success'] || empty($result['embedding'])) {
                Log::warning('SemanticChunker: Failed to embed sentence', [
                    'sentence_preview' => substr($sentence, 0, 50),
                    'error' => $result['error'] ?? 'unknown',
                ]);
                return []; // Return empty on any failure for consistency
            }

            $embeddings[] = $result['embedding'];
        }

        return $embeddings;
    }

    /**
     * Calculate cosine similarity between two vectors
     *
     * @param array $vec1 First vector
     * @param array $vec2 Second vector
     * @return float Similarity score between -1 and 1 (1 = identical)
     */
    public function cosineSimilarity(array $vec1, array $vec2): float
    {
        return \App\Support\VectorMath::cosineSimilarity($vec1, $vec2);
    }

    /**
     * Calculate the percentile value from an array
     *
     * @param array $values Array of numeric values
     * @param int $p Percentile (0-100)
     * @return float Percentile value
     */
    public function percentile(array $values, int $p): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $count = count($values);

        $k = ($p / 100) * ($count - 1);
        $f = floor($k);
        $c = ceil($k);

        if ($f == $c) {
            return $values[(int)$f];
        }

        return $values[(int)$f] + ($k - $f) * ($values[(int)$c] - $values[(int)$f]);
    }

    /**
     * Calculate threshold for breakpoint detection
     *
     * @param array $distances Array of cosine distances
     * @param string $method Method: percentile, standard_deviation, interquartile, gradient
     * @param int $percentile Percentile value (for percentile method)
     * @return float Threshold value
     */
    private function calculateThreshold(array $distances, string $method, int $percentile): float
    {
        if (empty($distances)) {
            return 0.0;
        }

        return match ($method) {
            'percentile' => $this->percentile($distances, $percentile),
            'standard_deviation' => $this->standardDeviationThreshold($distances),
            'interquartile' => $this->interquartileThreshold($distances),
            'gradient' => $this->gradientThreshold($distances),
            default => $this->percentile($distances, $percentile),
        };
    }

    /**
     * Calculate threshold using standard deviation method
     * Threshold = mean + (1.5 * std_dev)
     */
    private function standardDeviationThreshold(array $values): float
    {
        $count = count($values);
        $mean = array_sum($values) / $count;

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        $stdDev = sqrt($variance / $count);

        return $mean + (1.5 * $stdDev);
    }

    /**
     * Calculate threshold using interquartile range method
     * Threshold = Q3 + (1.5 * IQR)
     */
    private function interquartileThreshold(array $values): float
    {
        $q1 = $this->percentile($values, 25);
        $q3 = $this->percentile($values, 75);
        $iqr = $q3 - $q1;

        return $q3 + (1.5 * $iqr);
    }

    /**
     * Calculate threshold using gradient method
     * Finds the point of maximum rate of change in sorted distances
     */
    private function gradientThreshold(array $values): float
    {
        if (count($values) < 3) {
            return $this->percentile($values, 90);
        }

        $sorted = $values;
        sort($sorted);

        // Calculate gradients (differences between consecutive values)
        $gradients = [];
        for ($i = 1; $i < count($sorted); $i++) {
            $gradients[] = $sorted[$i] - $sorted[$i - 1];
        }

        // Find index of maximum gradient
        $maxGradientIdx = array_search(max($gradients), $gradients);

        // Threshold is the value just before the steepest increase
        return $sorted[$maxGradientIdx + 1] ?? $sorted[count($sorted) - 1];
    }

    /**
     * Split an oversized chunk into smaller pieces
     */
    private function splitOversizedChunk(string $text, int $maxSize, int $overlapPercent): array
    {
        $chunks = [];
        $overlapSize = (int)($maxSize * $overlapPercent / 100);
        $stepSize = $maxSize - $overlapSize;
        $length = strlen($text);
        $pos = 0;

        while ($pos < $length) {
            $chunk = substr($text, $pos, $maxSize);

            // Try to break at word boundary
            if ($pos + $maxSize < $length) {
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace !== false && $lastSpace > $maxSize * 0.7) {
                    $chunk = substr($chunk, 0, $lastSpace);
                }
            }

            $chunks[] = trim($chunk);
            $pos += max($stepSize, strlen($chunk) - $overlapSize);
        }

        return $chunks;
    }

    /**
     * Apply overlap between consecutive chunks
     */
    private function applyOverlap(array $chunks, int $overlapPercent): array
    {
        if (count($chunks) <= 1) {
            return $chunks;
        }

        $overlappedChunks = [];

        for ($i = 0; $i < count($chunks); $i++) {
            $chunk = $chunks[$i];

            // Add overlap from previous chunk at the beginning
            if ($i > 0) {
                $prevChunk = $chunks[$i - 1];
                $overlapSize = (int)(strlen($prevChunk) * $overlapPercent / 100);
                $overlap = substr($prevChunk, -$overlapSize);

                // Find word boundary for overlap
                $firstSpace = strpos($overlap, ' ');
                if ($firstSpace !== false) {
                    $overlap = substr($overlap, $firstSpace + 1);
                }

                if (!empty($overlap)) {
                    $chunk = $overlap . ' ' . $chunk;
                }
            }

            $overlappedChunks[] = trim($chunk);
        }

        return $overlappedChunks;
    }

    /**
     * Fallback to fixed-size chunking when semantic chunking fails
     */
    private function fallbackFixedSizeChunk(string $text, int $maxSize, int $overlapPercent): array
    {
        Log::info('SemanticChunker: Using fixed-size fallback chunking');

        $sentences = $this->splitSentences($text);
        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            if (strlen($currentChunk) + strlen($sentence) + 1 > $maxSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $sentence;
            } else {
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        // Apply overlap
        if ($overlapPercent > 0 && count($chunks) > 1) {
            $chunks = $this->applyOverlap($chunks, $overlapPercent);
        }

        return $chunks;
    }

    /**
     * Get detailed chunking statistics for debugging
     *
     * @param string $text Input text
     * @param array $options Chunking options
     * @return array Statistics including distances, threshold, breakpoints
     */
    public function analyzeChunking(string $text, array $options = []): array
    {
        $breakpointPercentile = $options['breakpoint_percentile'] ?? 90;
        $breakpointMethod = $options['breakpoint_method'] ?? 'percentile';

        $sentences = $this->splitSentences($text);
        $embeddings = $this->embedBatch($sentences);

        if (empty($embeddings)) {
            return [
                'error' => 'Failed to generate embeddings',
                'sentences' => count($sentences),
            ];
        }

        $distances = [];
        for ($i = 0; $i < count($embeddings) - 1; $i++) {
            $distances[] = [
                'index' => $i,
                'sentence_a' => substr($sentences[$i], 0, 50) . '...',
                'sentence_b' => substr($sentences[$i + 1], 0, 50) . '...',
                'distance' => 1 - $this->cosineSimilarity($embeddings[$i], $embeddings[$i + 1]),
            ];
        }

        $distanceValues = array_column($distances, 'distance');
        $threshold = $this->calculateThreshold($distanceValues, $breakpointMethod, $breakpointPercentile);

        $breakpoints = array_filter($distances, fn($d) => $d['distance'] > $threshold);

        return [
            'sentences' => count($sentences),
            'distances' => $distances,
            'threshold' => $threshold,
            'method' => $breakpointMethod,
            'percentile' => $breakpointPercentile,
            'breakpoint_count' => count($breakpoints),
            'breakpoint_indices' => array_column($breakpoints, 'index'),
            'stats' => [
                'min_distance' => min($distanceValues),
                'max_distance' => max($distanceValues),
                'mean_distance' => array_sum($distanceValues) / count($distanceValues),
                'p50' => $this->percentile($distanceValues, 50),
                'p75' => $this->percentile($distanceValues, 75),
                'p90' => $this->percentile($distanceValues, 90),
                'p95' => $this->percentile($distanceValues, 95),
            ],
        ];
    }

    // ========================================================================
    // SENTENCE WINDOW RETRIEVAL SUPPORT
    // ========================================================================

    /**
     * Chunk text with sentence-level positions for window retrieval
     *
     * Returns chunks with sentence_positions metadata that can be used
     * to expand context windows during retrieval.
     *
     * @param string $text Text to chunk
     * @param array $options Same as chunk() plus:
     *   - embed_sentences: bool (default false) - Generate embeddings for each sentence
     * @return array ['chunks' => [...], 'sentence_positions' => [...], 'sentence_embeddings' => [...]]
     */
    public function chunkWithSentencePositions(string $text, array $options = []): array
    {
        $embedSentences = $options['embed_sentences'] ?? false;

        // First, get sentences with their positions in original text
        $sentencesWithPositions = $this->splitSentencesWithPositions($text);

        if (empty($sentencesWithPositions)) {
            return [
                'chunks' => [trim($text)],
                'sentence_positions' => [],
                'sentence_embeddings' => [],
            ];
        }

        // Generate sentence embeddings if requested
        $sentenceEmbeddings = [];
        if ($embedSentences) {
            $sentences = array_column($sentencesWithPositions, 'text');
            $sentenceEmbeddings = $this->embedBatch($sentences);
        }

        // Run normal chunking
        $chunks = $this->chunk($text, $options);

        // Map sentence positions to chunks
        $chunkSentencePositions = [];
        foreach ($chunks as $chunkIndex => $chunkText) {
            $positions = [];
            foreach ($sentencesWithPositions as $sentenceIndex => $sentence) {
                // Check if this sentence is in this chunk
                if (strpos($chunkText, $sentence['text']) !== false) {
                    $positions[] = [
                        'sentence_index' => $sentenceIndex,
                        'char_start' => $sentence['start'],
                        'char_end' => $sentence['end'],
                        'text' => $sentence['text'],
                    ];
                }
            }
            $chunkSentencePositions[$chunkIndex] = $positions;
        }

        return [
            'chunks' => $chunks,
            'sentence_positions' => $chunkSentencePositions,
            'sentence_embeddings' => $sentenceEmbeddings,
            'sentences' => $sentencesWithPositions,
        ];
    }

    /**
     * Split text into sentences with character positions
     *
     * @param string $text Input text
     * @return array Array of {text, start, end}
     */
    public function splitSentencesWithPositions(string $text): array
    {
        $sentences = $this->splitSentences($text);
        $result = [];
        $searchStart = 0;

        foreach ($sentences as $index => $sentence) {
            $pos = strpos($text, $sentence, $searchStart);
            if ($pos !== false) {
                $result[] = [
                    'index' => $index,
                    'text' => $sentence,
                    'start' => $pos,
                    'end' => $pos + strlen($sentence),
                ];
                $searchStart = $pos + strlen($sentence);
            }
        }

        return $result;
    }

    /**
     * Get context window around a sentence
     *
     * @param string $text Original full text
     * @param int $sentenceIndex Target sentence index
     * @param int $windowSize Number of sentences before/after to include
     * @return array ['text' => expanded text, 'start_sentence' => int, 'end_sentence' => int]
     */
    public function getSentenceWindow(string $text, int $sentenceIndex, int $windowSize = 2): array
    {
        $sentences = $this->splitSentences($text);
        $totalSentences = count($sentences);

        if ($sentenceIndex < 0 || $sentenceIndex >= $totalSentences) {
            return ['text' => '', 'start_sentence' => 0, 'end_sentence' => 0];
        }

        $startIndex = max(0, $sentenceIndex - $windowSize);
        $endIndex = min($totalSentences - 1, $sentenceIndex + $windowSize);

        $windowSentences = array_slice($sentences, $startIndex, $endIndex - $startIndex + 1);

        return [
            'text' => implode(' ', $windowSentences),
            'start_sentence' => $startIndex,
            'end_sentence' => $endIndex,
            'target_sentence' => $sentences[$sentenceIndex],
        ];
    }

    /**
     * Generate embeddings for sentences and prepare for storage
     *
     * Returns data suitable for inserting into rag_sentence_embeddings table.
     *
     * @param int $documentId RAG document ID
     * @param string $content Document content
     * @return array Array of sentence embedding records
     */
    public function generateSentenceEmbeddings(int $documentId, string $content): array
    {
        $sentencesWithPositions = $this->splitSentencesWithPositions($content);

        if (empty($sentencesWithPositions)) {
            return [];
        }

        $records = [];

        foreach ($sentencesWithPositions as $sentence) {
            $result = $this->aiService->generateEmbedding($sentence['text']);

            if ($result['success'] && !empty($result['embedding'])) {
                $records[] = [
                    'document_id' => $documentId,
                    'sentence_index' => $sentence['index'],
                    'sentence_text' => $sentence['text'],
                    'char_start' => $sentence['start'],
                    'char_end' => $sentence['end'],
                    'embedding' => $result['embedding'],
                ];
            }
        }

        Log::info('SemanticChunker: Generated sentence embeddings', [
            'document_id' => $documentId,
            'total_sentences' => count($sentencesWithPositions),
            'embedded_sentences' => count($records),
        ]);

        return $records;
    }

    /**
     * Extract atomic propositions from a chunk of text.
     *
     * Proposition-based indexing extracts factual statements from text,
     * each expressing a single fact about a single entity. Particularly
     * effective for genealogy records where multiple facts are embedded
     * in narrative text.
     *
     * Uses heuristic extraction (fast, no LLM) by default, with LLM
     * fallback for complex text.
     *
     * @param string $text Chunk text to decompose
     * @param array $options [use_llm: bool, min_confidence: float]
     * @return array [{proposition: string, subject: string|null, predicate: string|null, object: string|null, confidence: float}]
     */
    public function extractPropositions(string $text, array $options = []): array
    {
        $useLlm = $options['use_llm'] ?? false;
        $minConfidence = $options['min_confidence'] ?? 0.5;

        // Step 1: Heuristic extraction — fast, no LLM cost
        $propositions = $this->heuristicPropositionExtraction($text);

        // Step 2: LLM extraction for complex text (if enabled and heuristic found few)
        if ($useLlm && count($propositions) < 2 && strlen($text) > 100) {
            $llmPropositions = $this->llmPropositionExtraction($text);
            $propositions = array_merge($propositions, $llmPropositions);
        }

        // Deduplicate by normalized proposition text
        $seen = [];
        $unique = [];
        foreach ($propositions as $p) {
            $key = strtolower(trim($p['proposition']));
            if (!isset($seen[$key]) && $p['confidence'] >= $minConfidence) {
                $seen[$key] = true;
                $unique[] = $p;
            }
        }

        return $unique;
    }

    /**
     * Heuristic proposition extraction — pattern-based, no LLM.
     */
    private function heuristicPropositionExtraction(string $text): array
    {
        $propositions = [];

        // Birth pattern: "X was born on/in DATE in/at PLACE"
        if (preg_match_all('/(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s+was\s+born\s+(?:on\s+)?([^,.]+(?:,\s*\d{4})?)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $propositions[] = [
                    'proposition' => trim($m[0]),
                    'subject' => trim($m[1]),
                    'predicate' => 'born',
                    'object' => trim($m[2]),
                    'confidence' => 0.90,
                ];
            }
        }

        // Death pattern
        if (preg_match_all('/(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s+died\s+(?:on\s+)?([^,.]+(?:,\s*\d{4})?)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $propositions[] = [
                    'proposition' => trim($m[0]),
                    'subject' => trim($m[1]),
                    'predicate' => 'died',
                    'object' => trim($m[2]),
                    'confidence' => 0.90,
                ];
            }
        }

        // Marriage pattern
        if (preg_match_all('/(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s+married\s+(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)(?:\s+(?:on|in)\s+([^,.]+))?/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $propositions[] = [
                    'proposition' => trim($m[0]),
                    'subject' => trim($m[1]),
                    'predicate' => 'married',
                    'object' => trim($m[2]),
                    'confidence' => 0.88,
                ];
            }
        }

        // Residence/location pattern
        if (preg_match_all('/(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s+(?:lived|resided|settled)\s+(?:in|at)\s+([^,.]+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $propositions[] = [
                    'proposition' => trim($m[0]),
                    'subject' => trim($m[1]),
                    'predicate' => 'resided',
                    'object' => trim($m[2]),
                    'confidence' => 0.85,
                ];
            }
        }

        // Date pattern: "in YEAR" or "on DATE" with context
        if (preg_match_all('/(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s+(?:was|became|served|enlisted|immigrated|arrived|departed)\s+([^.]+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $propositions[] = [
                    'proposition' => trim($m[0]),
                    'subject' => trim($m[1]),
                    'predicate' => 'event',
                    'object' => trim($m[2]),
                    'confidence' => 0.80,
                ];
            }
        }

        // Parent/child pattern
        if (preg_match_all('/(?:son|daughter|child)\s+of\s+(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s+and\s+(\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $propositions[] = [
                    'proposition' => trim($m[0]),
                    'subject' => null,
                    'predicate' => 'child_of',
                    'object' => trim($m[1]) . ' and ' . trim($m[2]),
                    'confidence' => 0.85,
                ];
            }
        }

        return $propositions;
    }

    /**
     * LLM-based proposition extraction for complex text.
     */
    private function llmPropositionExtraction(string $text): array
    {
        try {
            $prompt = "Extract atomic factual propositions from this text. Each proposition should state ONE fact about ONE entity.\n\n"
                . "Text: {$text}\n\n"
                . "Return a JSON array of objects with keys: proposition (string), subject (string), predicate (string), object (string).\n"
                . "Return ONLY the JSON array, no other text.";

            $result = $this->aiService->generateText($prompt, [
                'max_tokens' => 1000,
                'model_role' => 'fast',
            ]);

            $response = $result['response'] ?? '';
            if (preg_match('/\[.*\]/s', $response, $m)) {
                $parsed = json_decode($m[0], true);
                if (is_array($parsed)) {
                    return array_map(fn($p) => array_merge($p, ['confidence' => 0.75]), $parsed);
                }
            }
        } catch (\Exception $e) {
            Log::warning('SemanticChunker: LLM proposition extraction failed', ['error' => $e->getMessage()]);
        }

        return [];
    }
}
