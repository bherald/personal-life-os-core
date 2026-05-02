<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AIResponseSanitizer - Post-process AI responses to enforce INTJ communication style
 *
 * Since different LLMs (Llama 3.1, Mistral, Claude, etc.) have varying instruction-following
 * capabilities, this service strips forbidden patterns from responses AFTER generation.
 *
 * This is a fallback mechanism - prompts should still include FORBIDDEN rules,
 * but this catches violations from models that don't follow instructions perfectly.
 *
 * Easily extensible for model-specific rules in the future.
 */
class AIResponseSanitizer
{
    /**
     * Forbidden patterns that should be removed from AI responses
     * Patterns are case-insensitive regex
     */
    private array $forbiddenPatterns = [
        // Medical/professional disclaimers
        '/\b(please\s+)?(consult|speak\s+with|talk\s+to|see|visit)\s+(a\s+|your\s+)?(doctor|physician|healthcare\s+provider|healthcare\s+professional|medical\s+professional|specialist|nutritionist|dietitian)/i',
        '/\bbefore\s+(starting|beginning|taking|using|adding)\s+any\s+(new\s+)?(supplements?|medications?|treatments?|regimen)/i',
        '/\bit\'?s\s+(important|essential|crucial|recommended|advisable)\s+to\s+(note|remember|keep\s+in\s+mind|consult)/i',
        '/\bI\s+(would\s+)?(strongly\s+)?(recommend|suggest|advise)\s+(that\s+you\s+)?(consult|speak|talk)/i',
        '/\balways\s+consult/i',
        '/\bseek\s+(medical\s+|professional\s+)?advice/i',

        // Filler phrases at start
        '/^(based\s+on\s+(my|the|your)\s+(search|question|knowledge|research|findings|analysis|information)[,.]?\s*)/i',
        '/^(according\s+to\s+(my|the)\s+(search|knowledge|research|findings|analysis)[,.]?\s*)/i',
        '/^(from\s+what\s+I\s+(found|gathered|learned)[,.]?\s*)/i',
        '/^I\'?ve\s+searched\s+(online|the\s+web)\s+and\s+(found|here\'?s?)\s+[^.]+\.\s*/i',
        '/^I\'?ve\s+gathered\s+(some\s+)?information\s+[^.]+\.\s*/i',
        '/^Here\s+(are|is)\s+some\s+(key\s+)?(information|points?|facts?)\s*[:.]/i',

        // Tool announcement filler (LLM announces tool use unnecessarily)
        '/^(I\'?ll|I\s+will|You\s+can|Let\s+me)\s+use\s+(the\s+)?[\*`]*[\w_]+[\*`]*\s+(tool\s+)?to\s+[^.]+\.\s*/i',
        '/^(Searching|Looking|Querying)\s+(online|the\s+web|for)\s+[^.]+\.\.\.\s*/i',
        '/^Let\s+me\s+(search|look|check|find)\s+[^.]+\.\s*/i',
        '/^After\s+searching,?\s*/i',

        // External prompt-injection artifacts that should never survive in model output
        '/\b(ignore|disregard|forget)\s+(all\s+)?(previous|prior|above)\s+(instructions?|rules?|prompts?)\b/i',
        '/\b(you\s+are\s+now|act\s+as|pretend\s+to\s+be|your\s+new\s+(role|instructions?))\b/i',
        '/\bsystem\s*prompt\s*[:=]\s*\S.*$/im',
        '/\b(call|execute|run|invoke)\s+(tool|function|command)\s*[:=\(].*$/im',
        '/\[\s*INST\s*\].*?\[\s*\/INST\s*\]/is',
        '/\[\s*\/?INST\s*\]/i',
        '/<\|\s*im_start\s*\|>.*?<\|\s*im_end\s*\|>/is',
        '/<\|\s*im_(start|end)\s*\|>/i',

        // Trailing questions and filler (end of response)
        '/[.!]\s*(do\s+you\s+have\s+any\s+(other\s+)?questions\??)\s*$/i',
        '/[.!]\s*(is\s+there\s+anything\s+else\s+(you\'?d\s+like\s+to\s+know|I\s+can\s+help\s+with)\??)\s*$/i',
        '/[.!]\s*(would\s+you\s+like\s+(me\s+to\s+|more\s+)?[^?]+\??)\s*$/i',
        '/[.!]\s*(let\s+me\s+know\s+if\s+you\s+(have|need|want)\s+[^.!]+[.!]?)\s*$/i',
        '/[.!]\s*(please\s+let\s+me\s+know[^.!]*[.!]?)\s*$/i',
        '/[.!]\s*(feel\s+free\s+to\s+ask[^.!]*[.!]?)\s*$/i',
        '/\s*I\s+hope\s+this\s+(information\s+)?(helps|is\s+helpful)[.!]?\s*/i',
        '/\s*Do\s+you\s+have\s+any\s+(specific\s+)?questions?\s*(about\s+[^?]+)?\??\s*$/i',

        // AI-generated source lists (we have UI for this, AI should not list sources in text)
        // Match "Sources:" followed by numbered list items
        '/\n*Sources?:\s*\n(\[\d+\][^\n]*\n?)+/is',
        '/\n*References?:\s*\n(\[\d+\][^\n]*\n?)+/is',
        '/\n*Citations?:\s*\n(\[\d+\][^\n]*\n?)+/is',
        // Match "Sources:" followed by any list (bullet, numbered, or plain)
        '/\n+Sources?:\s*\n+(?:[-*•]\s*[^\n]+\n*)+/is',
        '/\n+Sources?:\s*\n+(?:\d+[\.\)]\s*[^\n]+\n*)+/is',
        // Match trailing "Sources:" section at end of response (any format)
        '/\n+Sources?:\s*\n.*$/is',
        // Catch when response is ONLY a sources list (starts with Sources:)
        '/^Sources?:\s*\n(\[\d+\][^\n]*\n?)+$/is',

        // Hallucinated external sources (not our RAG sources)
        // Note: DO NOT remove all URLs - RAG provides legitimate media_url sources
        '/\b(Stack\s*Overflow|stackoverflow\.com)\b/i',
        '/\b(github\.com\/[^\s)>\]]+)\b/i', // Only remove github.com paths, not mentions

        // "Important to note" variations
        '/\bit\'?s\s+(worth\s+)?(noting|mentioning)\s+that\b/i',
        '/\bkeep\s+in\s+mind\s+that\b/i',
        '/\bplease\s+note:?\s+[^.!?]*[.!?]?\s*$/i', // "Please note: ..." at end of response
        '/\bone\s+important\s+(thing|point|note)\b/i',

        // Meta-commentary about the response or sources
        '/\b(consult|see|check|refer\s+to)\s+(the\s+)?(provided|above|following)?\s*(knowledge\s+base|sources?|results?|documents?)/i',
        '/\bfor\s+more\s+(information|details?),?\s+(please\s+)?(see|check|refer|consult)/i',

        // Unnecessary hedging
        '/\bI\'?m\s+not\s+a\s+(doctor|medical\s+professional|healthcare\s+provider)\b/i',
        '/\bthis\s+is\s+not\s+medical\s+advice\b/i',
        '/\bthis\s+should\s+not\s+replace\s+(professional\s+)?medical\s+advice\b/i',
    ];

    /**
     * Model-specific additional patterns
     * Some models have unique quirks that need extra handling
     */
    private array $modelPatterns = [
        'llama' => [
            // Llama often adds "References:" with hallucinated sources
            '/\n\n?(?:References?|Sources?|Citations?):\s*\n.*$/is',
            // Llama sometimes outputs JSON fragments
            '/^\s*\{[^}]*"(output|answer|response)":/i',
        ],
        'mistral' => [
            // Mistral-specific patterns can be added here
        ],
        'claude' => [
            // Claude typically follows instructions well, minimal patterns needed
        ],
    ];

    /**
     * Sanitize AI response by removing forbidden patterns
     *
     * @param string $response The raw AI response
     * @param string|null $model Optional model identifier for model-specific rules
     * @return string Sanitized response
     */
    public function sanitize(string $response, ?string $model = null): string
    {
        $original = $response;
        $modified = false;

        // Apply common forbidden patterns
        foreach ($this->forbiddenPatterns as $pattern) {
            $newResponse = preg_replace($pattern, '', $response);
            if ($newResponse !== null && $newResponse !== $response) {
                $response = $newResponse;
                $modified = true;
            }
        }

        // Apply model-specific patterns
        if ($model !== null) {
            $modelKey = $this->detectModelFamily($model);
            if (isset($this->modelPatterns[$modelKey])) {
                foreach ($this->modelPatterns[$modelKey] as $pattern) {
                    $newResponse = preg_replace($pattern, '', $response);
                    if ($newResponse !== null && $newResponse !== $response) {
                        $response = $newResponse;
                        $modified = true;
                    }
                }
            }
        }

        // Clean up whitespace artifacts
        $response = $this->cleanWhitespace($response);

        // Log if we made modifications
        if ($modified) {
            Log::info('AIResponseSanitizer: Removed forbidden patterns from response', [
                'model' => $model,
                'original_length' => strlen($original),
                'sanitized_length' => strlen($response),
                'removed_chars' => strlen($original) - strlen($response),
            ]);
        }

        return $response;
    }

    /**
     * Detect model family from model identifier
     */
    private function detectModelFamily(string $model): string
    {
        $lower = strtolower($model);

        if (str_contains($lower, 'llama') || str_contains($lower, 'llava')) {
            return 'llama';
        }

        if (str_contains($lower, 'mistral') || str_contains($lower, 'mixtral')) {
            return 'mistral';
        }

        if (str_contains($lower, 'claude')) {
            return 'claude';
        }

        // Default to llama patterns since that's our primary local model
        return 'llama';
    }

    /**
     * Clean up whitespace artifacts after pattern removal
     */
    private function cleanWhitespace(string $text): string
    {
        // Remove multiple consecutive newlines (more than 2)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Remove leading/trailing whitespace
        $text = trim($text);

        // Remove trailing punctuation artifacts (multiple periods, etc.)
        $text = preg_replace('/[.]{2,}$/', '.', $text);

        // Remove orphaned punctuation at start of lines
        $text = preg_replace('/^\s*[,;:]\s*/m', '', $text);

        // Clean up orphaned conjunctions after pattern removal
        // "However, that more..." -> remove the orphaned "However, "
        $text = preg_replace('/\b(However|But|Although|Though|Nevertheless),?\s+that\s+/i', '', $text);

        // Remove double spaces
        $text = preg_replace('/  +/', ' ', $text);

        return $text;
    }

    /**
     * Add a custom forbidden pattern
     *
     * @param string $pattern Regex pattern (should include delimiters and flags)
     */
    public function addForbiddenPattern(string $pattern): void
    {
        $this->forbiddenPatterns[] = $pattern;
    }

    /**
     * Add model-specific patterns
     *
     * @param string $modelFamily Model family key (llama, mistral, claude, etc.)
     * @param array $patterns Array of regex patterns
     */
    public function addModelPatterns(string $modelFamily, array $patterns): void
    {
        if (!isset($this->modelPatterns[$modelFamily])) {
            $this->modelPatterns[$modelFamily] = [];
        }

        $this->modelPatterns[$modelFamily] = array_merge(
            $this->modelPatterns[$modelFamily],
            $patterns
        );
    }

    /**
     * Check if a response contains forbidden patterns (for testing/debugging)
     *
     * @param string $response The response to check
     * @return array List of matched forbidden patterns
     */
    public function detectViolations(string $response): array
    {
        $violations = [];

        foreach ($this->forbiddenPatterns as $pattern) {
            if (preg_match($pattern, $response, $matches)) {
                $violations[] = [
                    'pattern' => $pattern,
                    'match' => $matches[0],
                ];
            }
        }

        return $violations;
    }
}
