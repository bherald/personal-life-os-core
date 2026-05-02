<?php

namespace App\Nodes;

use App\Services\AIService;
use Exception;

class AIFormatter extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $preformatted = $this->extractPreformattedText($input);
            if ($preformatted !== null && $this->resolveBooleanConfig('prefer_preformatted', true)) {
                return $this->standardOutput([
                    'formatted_text' => $preformatted,
                    'original_data' => $input,
                ], [
                    'ai_provider' => 'preformatted',
                    'ai_duration_ms' => 0,
                    'response_format' => $this->getConfigValue('response_format', 'text'),
                    'preformatted_passthrough' => true,
                ]);
            }

            $prompt = $this->getConfigValue('prompt');

            if (! $prompt) {
                throw new Exception('Prompt configuration is required');
            }

            // Inject dynamic date into prompt
            $prompt = $this->injectDynamicValues($prompt);

            // Detect input format and build context
            $context = $this->buildContext($input);

            // Build full prompt with response format
            $responseFormat = $this->getConfigValue('response_format', 'text');
            $fullPrompt = $this->buildFullPrompt($prompt, $context, $responseFormat);

            // Use AIService with guaranteed fallback chain (Ollama → Claude CLI → alert)
            $aiService = app(AIService::class);
            $result = $aiService->process($fullPrompt, $this->config);

            if ($result['success']) {
                return $this->standardOutput([
                    'formatted_text' => $result['response'],
                    'original_data' => $input,
                ], [
                    'ai_provider' => $result['provider'],
                    'ai_duration_ms' => $result['duration_ms'],
                    'response_format' => $responseFormat,
                ]);
            }

            // AIService exhausted all providers and sent alert
            // Return structured error - don't pass raw JSON to Pushover
            throw new Exception('AI formatting failed after all fallbacks: '.$result['error']);
        } catch (\Throwable $e) {
            return $this->standardOutput(null, ['error' => true], $e->getMessage());
        }
    }

    /**
     * Inject dynamic values into prompt (date, time, etc.)
     */
    private function injectDynamicValues(string $prompt): string
    {
        $now = now(); // Laravel helper for current date/time with timezone

        $replacements = [
            '{TODAY}' => $now->format('F j, Y'),           // e.g., "October 28, 2025"
            '{TODAY_SHORT}' => $now->format('M j, Y'),     // e.g., "Oct 28, 2025"
            '{DATE}' => $now->format('Y-m-d'),             // e.g., "2025-10-28"
            '{YESTERDAY}' => $now->subDay()->format('M j'), // e.g., "Oct 27"
            '{MONTH}' => $now->format('F'),                // e.g., "October"
            '{YEAR}' => $now->format('Y'),                 // e.g., "2025"
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $prompt);
    }

    private function buildContext(array $input): string
    {
        // If input has 'data' key, use that
        if (isset($input['data'])) {
            return $this->formatData($input['data']);
        }

        // Otherwise use entire input
        return $this->formatData($input);
    }

    private function formatData($data): string
    {
        if (is_string($data)) {
            return $data;
        }

        if (is_array($data)) {
            // NEW: If data has formatted_text key, prefer that over JSON encoding
            // This allows BiasRatingEnrich to pass both enriched articles and formatted text
            if (isset($data['formatted_text']) && is_string($data['formatted_text'])) {
                return $data['formatted_text'];
            }

            if (isset($data['preformatted_weather']) && is_string($data['preformatted_weather'])) {
                return $data['preformatted_weather'];
            }

            return json_encode($data, JSON_PRETTY_PRINT);
        }

        return (string) $data;
    }

    private function extractPreformattedText(array $input): ?string
    {
        foreach ([
            $input['data']['preformatted_weather'] ?? null,
            $input['preformatted_weather'] ?? null,
            $input['data']['formatted_text'] ?? null,
            $input['formatted_text'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function buildFullPrompt(string $basePrompt, string $context, string $responseFormat): string
    {
        $prompt = $basePrompt."\n\n";
        $prompt .= "Context/Data:\n".$context."\n\n";

        // Check if Pushover HTML formatting is enabled
        $pushoverFormat = $this->getConfigValue('pushover_format', null);

        if ($pushoverFormat === 'html') {
            $prompt .= $this->getPushoverHtmlInstructions($responseFormat);
        } elseif ($pushoverFormat === 'monospace') {
            $prompt .= $this->getPushoverMonospaceInstructions($responseFormat);
        } else {
            $prompt .= 'Response format: '.$responseFormat;
        }

        return $prompt;
    }

    /**
     * Get Pushover HTML formatting instructions
     */
    private function getPushoverHtmlInstructions(string $baseFormat): string
    {
        return 'Response format: '.$baseFormat.'

IMPORTANT: Format your response using Pushover HTML tags for beautiful mobile display:

HTML Tags Available:
- <b>text</b> for BOLD (use for headers, key points, emphasis)
- <i>text</i> for italic (use for subtle emphasis, quotes)
- <u>text</u> for underline (use sparingly)
- <font color="#RRGGBB">text</font> for COLORED text (see color guide below)
- <a href="URL">text</a> for CLICKABLE LINKS (always include source URLs!)

Color Palette (use consistently):
- #2E86DE = Blue (info, cool temps, general emphasis)
- #EE5A6F = Red (warnings, hot temps, urgent items)
- #26DE81 = Green (success, positive news, good weather)
- #FED330 = Yellow/Gold (caution, moderate priority)
- #45AAF2 = Light Blue (secondary info, links)
- #A55EEA = Purple (special items, highlights)
- #778CA3 = Gray (metadata, timestamps, sources)

Formatting Best Practices:
1. Use <b> for section headers and key information
2. Add colors to make content scannable (temps, categories, priority indicators)
3. ALWAYS include <a> links to sources for verification
4. Keep line breaks for readability (newlines work in HTML mode)
5. Use colors meaningfully, not decoratively
6. Bold + color combination works well for headers: <b><font color="#2E86DE">Header</font></b>

Example Output Structure:
<b><font color="#2E86DE">Section Header</font></b>
Key point in <b>bold</b> with normal text following
Temperature: <font color="#EE5A6F">85°F</font> (hot) / <font color="#2E86DE">45°F</font> (cold)
Source: <a href="https://example.com">Article Title</a>

Remember: This will display beautifully on mobile with proper colors and formatting!';
    }

    /**
     * Get Pushover monospace formatting instructions
     */
    private function getPushoverMonospaceInstructions(string $baseFormat): string
    {
        return 'Response format: '.$baseFormat.'

IMPORTANT: Format your response for MONOSPACE display (fixed-width font):

Monospace is perfect for:
- Technical data (IPs, hashes, code snippets)
- Tables and aligned columns
- Log-style output
- Structured data with consistent spacing

Formatting Guidelines:
1. Use consistent spacing for alignment
2. ASCII art boxes work well: ┌─────┐
3. Keep lines to reasonable length (~60-70 chars)
4. Use blank lines to separate sections
5. Consider that all characters have equal width

Example Output:
┌─────────────────────────────┐
│ SECURITY ALERT              │
└─────────────────────────────┘

IOC Type    Value
----------- -------------------------
IP Address  192.168.1.100
Hash (MD5)  d41d8cd98f00b204e9800998ecf8427e

Status: VERIFIED
Source: ThreatDB

Remember: Monospace provides clear, technical readability!';
    }
}
