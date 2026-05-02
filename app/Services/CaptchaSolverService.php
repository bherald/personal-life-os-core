<?php

namespace App\Services;

use App\Services\AIService;
use App\Engine\MCPRouter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * CAPTCHA Solver Service
 *
 * Centralized service for detecting and solving CAPTCHAs.
 * Supports multiple solving methods:
 * - AI Vision (local LLMs for simple image CAPTCHAs)
 * - 2Captcha (for reCAPTCHA, hCaptcha, Turnstile)
 * - Manual queue (for unsolvable CAPTCHAs)
 *
 * E06: Personal Data Removal System
 */
class CaptchaSolverService
{
    private AIService $aiService;
    private MCPRouter $mcpRouter;

    private array $captchaPatterns = [
        'recaptcha' => ['g-recaptcha', 'grecaptcha', 'recaptcha-token', 'recaptcha/api'],
        'hcaptcha' => ['hcaptcha', 'h-captcha'],
        'turnstile' => ['cf-turnstile', 'challenges.cloudflare'],
        'funcaptcha' => ['funcaptcha', 'arkoselabs'],
        'image' => ['captcha', 'verify-image', 'security-code'],
    ];

    public function __construct(AIService $aiService, MCPRouter $mcpRouter)
    {
        $this->aiService = $aiService;
        $this->mcpRouter = $mcpRouter;
    }

    // ========================================
    // DETECTION
    // ========================================

    /**
     * Detect if page contains a CAPTCHA and identify its type
     *
     * @param string $html Page HTML content
     * @return array ['detected' => bool, 'type' => string|null, 'details' => array]
     */
    public function detect(string $html): array
    {
        $htmlLower = strtolower($html);
        $detected = false;
        $type = null;
        $details = [];

        foreach ($this->captchaPatterns as $captchaType => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($htmlLower, $pattern)) {
                    $detected = true;
                    $type = $captchaType;
                    $details['matched_pattern'] = $pattern;
                    break 2;
                }
            }
        }

        // Try to extract sitekey for reCAPTCHA/hCaptcha
        if ($detected && in_array($type, ['recaptcha', 'hcaptcha'])) {
            $details['sitekey'] = $this->extractSiteKey($html, $type);
        }

        // Check for Cloudflare challenge page
        if (!$detected && $this->isCloudflareChallenge($htmlLower)) {
            $detected = true;
            $type = 'cloudflare_challenge';
        }

        return [
            'detected' => $detected,
            'type' => $type,
            'details' => $details,
        ];
    }

    /**
     * Extract site key from HTML for reCAPTCHA/hCaptcha
     */
    private function extractSiteKey(string $html, string $type): ?string
    {
        $patterns = match ($type) {
            'recaptcha' => [
                '/data-sitekey=["\']([^"\']+)["\']/',
                '/grecaptcha\.render\([^,]+,\s*\{[^}]*sitekey["\']?\s*:\s*["\']([^"\']+)["\']/',
            ],
            'hcaptcha' => [
                '/data-sitekey=["\']([^"\']+)["\']/',
                '/hcaptcha\.render\([^,]+,\s*\{[^}]*sitekey["\']?\s*:\s*["\']([^"\']+)["\']/',
            ],
            default => [],
        };

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Check if page is a Cloudflare challenge
     */
    private function isCloudflareChallenge(string $htmlLower): bool
    {
        $cfPatterns = [
            'checking your browser',
            'ray id:',
            'cf-browser-verification',
            'cloudflare',
            'just a moment',
        ];

        $matchCount = 0;
        foreach ($cfPatterns as $pattern) {
            if (str_contains($htmlLower, $pattern)) {
                $matchCount++;
            }
        }

        return $matchCount >= 2;
    }

    // ========================================
    // SOLVING
    // ========================================

    /**
     * Attempt to solve a CAPTCHA
     *
     * @param string $type CAPTCHA type
     * @param array $params Solving parameters (sitekey, pageUrl, imageData, etc.)
     * @return array ['success' => bool, 'solution' => string|null, 'method' => string]
     */
    public function solve(string $type, array $params): array
    {
        Log::info("CaptchaSolverService: Attempting to solve {$type} CAPTCHA");

        return match ($type) {
            'image' => $this->solveImageCaptcha($params),
            'recaptcha' => $this->solveReCaptcha($params),
            'hcaptcha' => $this->solveHCaptcha($params),
            'turnstile' => $this->solveTurnstile($params),
            default => $this->queueForManualSolving($type, $params),
        };
    }

    /**
     * Solve simple image CAPTCHA using AI vision
     */
    private function solveImageCaptcha(array $params): array
    {
        if (empty($params['image_data']) && empty($params['image_url'])) {
            return [
                'success' => false,
                'solution' => null,
                'method' => 'ai_vision',
                'error' => 'No image data provided',
            ];
        }

        try {
            // Get image data
            $imageData = $params['image_data'] ?? null;
            if (!$imageData && !empty($params['image_url'])) {
                $response = Http::connectTimeout(5)->timeout(30)->get($params['image_url']);
                if ($response->successful()) {
                    $imageData = $response->body();
                }
            }

            if (!$imageData) {
                return [
                    'success' => false,
                    'solution' => null,
                    'method' => 'ai_vision',
                    'error' => 'Failed to fetch image',
                ];
            }

            // Use AI vision to solve
            $prompt = "This is a CAPTCHA image. Read and type out ONLY the characters or numbers shown. Respond with just the characters, nothing else.";
            $result = $this->aiService->processImage($imageData, $prompt);

            if ($result['success'] && !empty($result['response'])) {
                // Clean up the response
                $solution = trim($result['response']);
                $solution = preg_replace('/[^a-zA-Z0-9]/', '', $solution);

                if (strlen($solution) >= 3 && strlen($solution) <= 10) {
                    Log::info("CaptchaSolverService: Image CAPTCHA solved via AI vision", [
                        'provider' => $result['provider'] ?? 'unknown',
                    ]);
                    return [
                        'success' => true,
                        'solution' => $solution,
                        'method' => 'ai_vision',
                    ];
                }
            }

            return [
                'success' => false,
                'solution' => null,
                'method' => 'ai_vision',
                'error' => 'AI could not solve CAPTCHA',
            ];

        } catch (\Exception $e) {
            Log::error("CaptchaSolverService: AI vision solve failed", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'solution' => null,
                'method' => 'ai_vision',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Solve reCAPTCHA using 2Captcha service
     */
    private function solveReCaptcha(array $params): array
    {
        $apiKey = config('services.twocaptcha.api_key');

        if (empty($apiKey)) {
            return $this->queueForManualSolving('recaptcha', $params);
        }

        if (empty($params['sitekey']) || empty($params['page_url'])) {
            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => 'Missing sitekey or page URL',
            ];
        }

        try {
            // Determine reCAPTCHA version
            $method = $params['version'] === 'v3' ? 'userrecaptcha' : 'userrecaptcha';
            $submitParams = [
                'key' => $apiKey,
                'method' => $method,
                'googlekey' => $params['sitekey'],
                'pageurl' => $params['page_url'],
                'json' => 1,
            ];

            if ($params['version'] === 'v3') {
                $submitParams['version'] = 'v3';
                $submitParams['action'] = $params['action'] ?? 'verify';
                $submitParams['min_score'] = $params['min_score'] ?? 0.3;
            }

            // Submit CAPTCHA
            $submitResponse = Http::connectTimeout(5)->timeout(30)->get('https://2captcha.com/in.php', $submitParams);
            $submitData = $submitResponse->json();

            if (($submitData['status'] ?? 0) !== 1) {
                return [
                    'success' => false,
                    'solution' => null,
                    'method' => '2captcha',
                    'error' => $submitData['request'] ?? 'Submit failed',
                ];
            }

            $requestId = $submitData['request'];

            // Poll for result (max 3 minutes)
            for ($i = 0; $i < 36; $i++) {
                sleep(5);

                $resultResponse = Http::connectTimeout(5)->timeout(30)->get('https://2captcha.com/res.php', [
                    'key' => $apiKey,
                    'action' => 'get',
                    'id' => $requestId,
                    'json' => 1,
                ]);

                $resultData = $resultResponse->json();

                if (($resultData['status'] ?? 0) === 1) {
                    Log::info("CaptchaSolverService: reCAPTCHA solved via 2Captcha");
                    return [
                        'success' => true,
                        'solution' => $resultData['request'],
                        'method' => '2captcha',
                    ];
                }

                if (($resultData['request'] ?? '') === 'ERROR_CAPTCHA_UNSOLVABLE') {
                    return [
                        'success' => false,
                        'solution' => null,
                        'method' => '2captcha',
                        'error' => 'CAPTCHA unsolvable',
                    ];
                }
            }

            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => 'Timeout waiting for solution',
            ];

        } catch (\Exception $e) {
            Log::error("CaptchaSolverService: 2Captcha solve failed", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Solve hCaptcha using 2Captcha service
     */
    private function solveHCaptcha(array $params): array
    {
        $apiKey = config('services.twocaptcha.api_key');

        if (empty($apiKey)) {
            return $this->queueForManualSolving('hcaptcha', $params);
        }

        if (empty($params['sitekey']) || empty($params['page_url'])) {
            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => 'Missing sitekey or page URL',
            ];
        }

        try {
            $submitResponse = Http::connectTimeout(5)->timeout(30)->get('https://2captcha.com/in.php', [
                'key' => $apiKey,
                'method' => 'hcaptcha',
                'sitekey' => $params['sitekey'],
                'pageurl' => $params['page_url'],
                'json' => 1,
            ]);

            $submitData = $submitResponse->json();

            if (($submitData['status'] ?? 0) !== 1) {
                return [
                    'success' => false,
                    'solution' => null,
                    'method' => '2captcha',
                    'error' => $submitData['request'] ?? 'Submit failed',
                ];
            }

            $requestId = $submitData['request'];

            // Poll for result
            for ($i = 0; $i < 36; $i++) {
                sleep(5);

                $resultResponse = Http::connectTimeout(5)->timeout(30)->get('https://2captcha.com/res.php', [
                    'key' => $apiKey,
                    'action' => 'get',
                    'id' => $requestId,
                    'json' => 1,
                ]);

                $resultData = $resultResponse->json();

                if (($resultData['status'] ?? 0) === 1) {
                    Log::info("CaptchaSolverService: hCaptcha solved via 2Captcha");
                    return [
                        'success' => true,
                        'solution' => $resultData['request'],
                        'method' => '2captcha',
                    ];
                }

                if (($resultData['request'] ?? '') === 'ERROR_CAPTCHA_UNSOLVABLE') {
                    break;
                }
            }

            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => 'Timeout or unsolvable',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Solve Cloudflare Turnstile using 2Captcha
     */
    private function solveTurnstile(array $params): array
    {
        $apiKey = config('services.twocaptcha.api_key');

        if (empty($apiKey)) {
            return $this->queueForManualSolving('turnstile', $params);
        }

        if (empty($params['sitekey']) || empty($params['page_url'])) {
            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => 'Missing sitekey or page URL',
            ];
        }

        try {
            $submitResponse = Http::connectTimeout(5)->timeout(30)->get('https://2captcha.com/in.php', [
                'key' => $apiKey,
                'method' => 'turnstile',
                'sitekey' => $params['sitekey'],
                'pageurl' => $params['page_url'],
                'json' => 1,
            ]);

            $submitData = $submitResponse->json();

            if (($submitData['status'] ?? 0) !== 1) {
                return [
                    'success' => false,
                    'solution' => null,
                    'method' => '2captcha',
                    'error' => $submitData['request'] ?? 'Submit failed',
                ];
            }

            $requestId = $submitData['request'];

            for ($i = 0; $i < 24; $i++) {
                sleep(5);

                $resultResponse = Http::connectTimeout(5)->timeout(30)->get('https://2captcha.com/res.php', [
                    'key' => $apiKey,
                    'action' => 'get',
                    'id' => $requestId,
                    'json' => 1,
                ]);

                $resultData = $resultResponse->json();

                if (($resultData['status'] ?? 0) === 1) {
                    Log::info("CaptchaSolverService: Turnstile solved via 2Captcha");
                    return [
                        'success' => true,
                        'solution' => $resultData['request'],
                        'method' => '2captcha',
                    ];
                }
            }

            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => 'Timeout',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'solution' => null,
                'method' => '2captcha',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Queue CAPTCHA for manual solving
     */
    // D2: data_removal_captcha_queue table dropped (2026-03-16). Methods stubbed.
    private function queueForManualSolving(string $type, array $params): array
    {
        return ['success' => false, 'solution' => null, 'method' => 'manual_queue', 'error' => 'Captcha queue disabled (D2)'];
    }

    public function getPendingQueue(int $limit = 10): array
    {
        return [];
    }

    public function submitManualSolution(int $queueId, string $solution): bool
    {
        return false;
    }

    public function getQueuedSolution(int $queueId): ?string
    {
        return null;
    }
}
