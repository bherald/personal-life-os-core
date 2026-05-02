<?php

namespace App\Services;

use App\Services\AIService;
use App\Services\BrowserAutomationService;
use App\Services\CircuitBreaker;
use App\Services\RetryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Broker Scraper Service
 *
 * Handles browser automation and scraping for data broker sites.
 * Supports multiple engines with automatic fallback:
 * 1. Puppeteer (persistent HTTP server) - Fast, lightweight
 * 2. Playwright (persistent HTTP server) - Better anti-detection
 * 3. HTTP/cURL - Simple requests without JavaScript
 *
 * Includes CAPTCHA detection and solving capabilities.
 *
 * E06: Personal Data Removal System
 */
class BrokerScraperService
{
    private BrowserAutomationService $browserService;
    private AIService $aiService;
    private CircuitBreaker $circuitBreaker;
    private RetryService $retryService;

    private int $timeout;
    private string $userAgent;
    private string $screenshotPath;

    public function __construct(
        BrowserAutomationService $browserService,
        AIService $aiService,
        CircuitBreaker $circuitBreaker,
        RetryService $retryService
    ) {
        $this->browserService = $browserService;
        $this->aiService = $aiService;
        $this->circuitBreaker = $circuitBreaker;
        $this->retryService = $retryService;

        $this->timeout = (int) config('data_removal.scraper_timeout', 30);
        $this->userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->screenshotPath = storage_path('app/data-removal/screenshots');

        // Ensure screenshot directory exists
        if (!file_exists($this->screenshotPath)) {
            mkdir($this->screenshotPath, 0755, true);
        }
    }

    // ========================================
    // ENGINE SELECTION
    // ========================================

    /**
     * Select the appropriate scraping engine based on broker requirements
     *
     * @param object $broker The data broker record
     * @return string Engine type: 'puppeteer', 'playwright', or 'http'
     */
    public function selectEngine(object $broker): string
    {
        // If JavaScript is required, try browser engines
        if ($broker->uses_javascript) {
            // Try Puppeteer first (faster startup)
            if ($this->isPuppeteerAvailable()) {
                return 'puppeteer';
            }

            // Fall back to Playwright (better anti-detection)
            if ($this->isPlaywrightAvailable()) {
                return 'playwright';
            }
        }

        // Fall back to HTTP for simple sites or when browsers unavailable
        return 'http';
    }

    /**
     * Check if Puppeteer server is available
     */
    public function isPuppeteerAvailable(): bool
    {
        return $this->browserService->isPuppeteerAvailable();
    }

    /**
     * Check if Playwright server is available
     */
    public function isPlaywrightAvailable(): bool
    {
        return $this->browserService->isPlaywrightAvailable();
    }

    /**
     * Start browser servers if needed
     */
    public function ensureBrowserAvailable(): bool
    {
        return $this->browserService->getAvailableEngine() !== null;
    }

    // ========================================
    // SCRAPING METHODS
    // ========================================

    /**
     * Search a broker for a subject's data
     *
     * @param object $broker The data broker record
     * @param object $subject The data subject record
     * @return array Search results with found data
     */
    public function searchBroker(object $broker, object $subject): array
    {
        $engine = $this->selectEngine($broker);

        Log::info("BrokerScraperService: Searching {$broker->name} using {$engine}", [
            'broker_id' => $broker->id,
            'subject_id' => $subject->id,
        ]);

        try {
            return $this->circuitBreaker->call(
                "broker_scraper_{$broker->domain}",
                fn() => match ($engine) {
                    'puppeteer' => $this->searchWithBrowser($broker, $subject, 'puppeteer'),
                    'playwright' => $this->searchWithBrowser($broker, $subject, 'playwright'),
                    default => $this->searchWithHttp($broker, $subject),
                }
            );
        } catch (\Exception $e) {
            Log::error("BrokerScraperService: Search failed for {$broker->name}", [
                'error' => $e->getMessage(),
                'engine' => $engine,
            ]);

            return [
                'success' => false,
                'found' => false,
                'error' => $e->getMessage(),
                'engine' => $engine,
            ];
        }
    }

    /**
     * Search using persistent browser server (Puppeteer or Playwright)
     */
    private function searchWithBrowser(object $broker, object $subject, string $engine): array
    {
        // Build search URL
        $searchUrl = $this->buildSearchUrl($broker, $subject);

        // Navigate to the page
        $navResult = $this->browserService->navigate($searchUrl, $engine);

        if (!($navResult['success'] ?? false)) {
            // Try falling back to other browser or HTTP
            if ($engine === 'puppeteer' && $this->isPlaywrightAvailable()) {
                Log::info("BrokerScraperService: Puppeteer failed, trying Playwright");
                return $this->searchWithBrowser($broker, $subject, 'playwright');
            }

            return [
                'success' => false,
                'found' => false,
                'error' => $navResult['error'] ?? 'Navigation failed',
                'engine' => $engine,
            ];
        }

        // Take screenshot
        $screenshotName = "search_{$broker->id}_{$subject->id}_" . time();
        $this->browserService->screenshot($screenshotName);

        // Get page content
        $contentResult = $this->browserService->getContent('text');
        $pageContent = $contentResult['content'] ?? '';

        // Check for CAPTCHA
        if ($this->detectCaptcha($pageContent)) {
            return [
                'success' => false,
                'found' => false,
                'captcha_detected' => true,
                'error' => 'CAPTCHA detected',
                'engine' => $engine,
            ];
        }

        // Analyze content for subject data
        $found = $this->analyzeForSubjectData($pageContent, $subject);

        return [
            'success' => true,
            'found' => $found['found'],
            'data_found' => $found['data'],
            'profile_url' => $found['profile_url'] ?? null,
            'screenshot' => $screenshotName,
            'engine' => $engine,
        ];
    }

    /**
     * Search using HTTP/cURL (for simple sites)
     */
    private function searchWithHttp(object $broker, object $subject): array
    {
        $searchUrl = $this->buildSearchUrl($broker, $subject);

        $response = $this->retryService->retryHttp(function () use ($searchUrl) {
            return Http::connectTimeout(5)->timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ])
                ->get($searchUrl);
        });

        if (!$response->successful()) {
            return [
                'success' => false,
                'found' => false,
                'error' => "HTTP {$response->status()}",
                'engine' => 'http',
            ];
        }

        $pageContent = $response->body();

        // Check for CAPTCHA
        if ($this->detectCaptcha($pageContent)) {
            return [
                'success' => false,
                'found' => false,
                'captcha_detected' => true,
                'error' => 'CAPTCHA detected',
                'engine' => 'http',
            ];
        }

        // Analyze content for subject data
        $found = $this->analyzeForSubjectData($pageContent, $subject);

        return [
            'success' => true,
            'found' => $found['found'],
            'data_found' => $found['data'],
            'profile_url' => $found['profile_url'] ?? null,
            'engine' => 'http',
        ];
    }

    /**
     * Build search URL for a broker
     */
    private function buildSearchUrl(object $broker, object $subject): string
    {
        // Common search URL patterns
        $domain = $broker->domain;
        $name = urlencode($subject->name);

        // Try to build search URL based on known patterns
        $patterns = [
            'spokeo.com' => "https://www.spokeo.com/search?q={$name}",
            'whitepages.com' => "https://www.whitepages.com/name/{$name}",
            'beenverified.com' => "https://www.beenverified.com/people/{$name}",
            'truepeoplesearch.com' => "https://www.truepeoplesearch.com/results?name={$name}",
            'fastpeoplesearch.com' => "https://www.fastpeoplesearch.com/name/{$name}",
        ];

        // Add location if available
        $location = '';
        if (!empty($subject->city) && !empty($subject->state)) {
            $location = urlencode("{$subject->city}, {$subject->state}");
        } elseif (!empty($subject->state)) {
            $location = urlencode($subject->state);
        }

        foreach ($patterns as $pattern => $url) {
            if (str_contains($domain, $pattern)) {
                if ($location) {
                    $url .= "&location={$location}";
                }
                return $url;
            }
        }

        // Generic fallback - try common search page
        return "https://www.{$domain}/search?q={$name}";
    }

    // ========================================
    // CONTENT ANALYSIS
    // ========================================

    /**
     * Analyze page content for subject data
     */
    private function analyzeForSubjectData(string $content, object $subject): array
    {
        $found = false;
        $dataFound = [];
        $profileUrl = null;

        // Normalize content
        $content = strtolower($content);
        $nameParts = explode(' ', strtolower($subject->name));

        // Check for name match
        $nameMatches = 0;
        foreach ($nameParts as $part) {
            if (strlen($part) > 2 && str_contains($content, $part)) {
                $nameMatches++;
            }
        }

        if ($nameMatches >= 2 || (count($nameParts) === 1 && $nameMatches === 1)) {
            $found = true;
            $dataFound['name'] = true;
        }

        // Check for address
        if (!empty($subject->city) && str_contains($content, strtolower($subject->city))) {
            $dataFound['city'] = true;
            $found = true;
        }

        if (!empty($subject->state) && str_contains($content, strtolower($subject->state))) {
            $dataFound['state'] = true;
        }

        // Check for phone (last 4 digits as basic check)
        if (!empty($subject->phone)) {
            $last4 = substr(preg_replace('/\D/', '', $subject->phone), -4);
            if (str_contains($content, $last4)) {
                $dataFound['phone'] = true;
                $found = true;
            }
        }

        // Check for email domain
        if (!empty($subject->email)) {
            $emailParts = explode('@', strtolower($subject->email));
            if (count($emailParts) === 2 && str_contains($content, $emailParts[0])) {
                $dataFound['email'] = true;
                $found = true;
            }
        }

        // Try to extract profile URL from content
        if ($found) {
            // Look for common profile URL patterns
            if (preg_match('/href="([^"]*profile[^"]*)"/', $content, $matches)) {
                $profileUrl = $matches[1];
            }
        }

        return [
            'found' => $found,
            'data' => $dataFound,
            'profile_url' => $profileUrl,
        ];
    }

    // ========================================
    // CAPTCHA HANDLING
    // ========================================

    /**
     * Detect if page contains a CAPTCHA
     */
    public function detectCaptcha(string $html): bool
    {
        $captchaPatterns = [
            'g-recaptcha',
            'recaptcha',
            'hcaptcha',
            'captcha',
            'cf-turnstile',
            'challenge-form',
            'verify you are human',
            'are you a robot',
            'prove you\'re human',
            'security check',
            'bot detection',
        ];

        $htmlLower = strtolower($html);

        foreach ($captchaPatterns as $pattern) {
            if (str_contains($htmlLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attempt to solve CAPTCHA using AI vision
     *
     * @param string $imagePath Path to CAPTCHA image
     * @return string|null Solved CAPTCHA text or null if failed
     */
    public function solveCaptchaWithVision(string $imagePath): ?string
    {
        try {
            if (!file_exists($imagePath)) {
                return null;
            }

            $imageData = file_get_contents($imagePath);

            $prompt = "This is a CAPTCHA image. Please read and type out the characters or numbers shown. Only respond with the exact characters, no explanation.";

            $result = $this->aiService->processImage($imageData, $prompt);

            if ($result['success'] && !empty($result['response'])) {
                // Clean up the response
                $solution = trim($result['response']);
                $solution = preg_replace('/[^a-zA-Z0-9]/', '', $solution);

                Log::info("BrokerScraperService: CAPTCHA solved with AI vision", [
                    'solution_length' => strlen($solution),
                    'provider' => $result['provider'] ?? 'unknown',
                ]);

                return $solution;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("BrokerScraperService: AI vision CAPTCHA solve failed", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Attempt to solve CAPTCHA using 2Captcha service
     *
     * @param string $siteKey The reCAPTCHA site key
     * @param string $pageUrl The page URL
     * @return string|null Solved token or null if failed
     */
    public function solveCaptchaWith2Captcha(string $siteKey, string $pageUrl): ?string
    {
        $apiKey = config('services.twocaptcha.api_key');

        if (empty($apiKey)) {
            Log::warning("BrokerScraperService: 2Captcha API key not configured");
            return null;
        }

        try {
            // Submit CAPTCHA
            $submitResponse = Http::connectTimeout(5)->timeout(30)->get('https://2captcha.com/in.php', [
                'key' => $apiKey,
                'method' => 'userrecaptcha',
                'googlekey' => $siteKey,
                'pageurl' => $pageUrl,
                'json' => 1,
            ]);

            $submitData = $submitResponse->json();

            if (($submitData['status'] ?? 0) !== 1) {
                return null;
            }

            $requestId = $submitData['request'];

            // Poll for result (max 2 minutes)
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
                    Log::info("BrokerScraperService: CAPTCHA solved with 2Captcha");
                    return $resultData['request'];
                }

                if (($resultData['request'] ?? '') === 'ERROR_CAPTCHA_UNSOLVABLE') {
                    return null;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error("BrokerScraperService: 2Captcha solve failed", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ========================================
    // FORM SUBMISSION
    // ========================================

    /**
     * Submit a removal request form
     *
     * @param object $broker The data broker
     * @param object $subject The data subject
     * @param array|null $allowedFields Fields allowed to be submitted (privacy filter)
     * @param string|null $engine Preferred engine (optional)
     * @return array Submission result with fields_submitted
     */
    public function submitRemovalForm(object $broker, object $subject, ?array $allowedFields = null, ?string $engine = null): array
    {
        if (empty($broker->removal_url)) {
            return [
                'success' => false,
                'error' => 'No removal URL configured for this broker',
            ];
        }

        if ($engine === null) {
            $engine = $this->selectEngine($broker);
        }

        // Determine which fields to submit based on privacy controls
        if ($allowedFields === null) {
            // Default: use broker's required + optional fields
            $required = json_decode($broker->required_fields ?? '["name"]', true) ?? ['name'];
            $optional = json_decode($broker->optional_fields ?? '[]', true) ?? [];
            $allowedFields = array_unique(array_merge($required, $optional));
        }

        Log::info("BrokerScraperService: Submitting removal form for {$broker->name}", [
            'broker_id' => $broker->id,
            'subject_id' => $subject->id,
            'engine' => $engine,
            'allowed_fields' => $allowedFields,
        ]);

        try {
            $result = match ($engine) {
                'puppeteer', 'playwright' => $this->submitFormWithBrowser($broker, $subject, $engine, $allowedFields),
                default => $this->submitFormWithHttp($broker, $subject, $allowedFields),
            };

            // Track which fields were actually submitted
            $result['fields_submitted'] = $allowedFields;
            return $result;
        } catch (\Exception $e) {
            Log::error("BrokerScraperService: Form submission failed for {$broker->name}", [
                'error' => $e->getMessage(),
                'engine' => $engine,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'engine' => $engine,
            ];
        }
    }

    /**
     * Submit removal form using browser automation
     * Only submits fields that are in the allowedFields list (privacy protection)
     */
    private function submitFormWithBrowser(object $broker, object $subject, string $engine, array $allowedFields = ['name']): array
    {
        // Navigate to removal URL
        $navResult = $this->browserService->navigate($broker->removal_url, $engine);

        if (!($navResult['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $navResult['error'] ?? 'Navigation failed',
                'engine' => $engine,
            ];
        }

        // Take pre-submission screenshot
        $screenshotName = "removal_pre_{$broker->id}_{$subject->id}_" . time();
        $this->browserService->screenshot($screenshotName);

        // Check for CAPTCHA
        $contentResult = $this->browserService->getContent('html');
        $pageContent = $contentResult['content'] ?? '';

        if ($this->detectCaptcha($pageContent)) {
            return [
                'success' => false,
                'needs_captcha' => true,
                'captcha_type' => $this->identifyCaptchaType($pageContent),
                'engine' => $engine,
            ];
        }

        // Only fill fields that are explicitly allowed (privacy protection)
        // Each field is only submitted if it's in the allowedFields array
        if (in_array('name', $allowedFields)) {
            $this->tryFillFormField('input[name*="name"], input[name*="fullname"]', $subject->name);
        }
        if (in_array('email', $allowedFields) && !empty($subject->email)) {
            $this->tryFillFormField('input[name*="email"], input[type="email"]', $subject->email);
        }
        if (in_array('phone', $allowedFields) && !empty($subject->phone)) {
            $this->tryFillFormField('input[name*="phone"]', $subject->phone);
        }
        if (in_array('address', $allowedFields) && !empty($subject->address_line1)) {
            $this->tryFillFormField('input[name*="address"], input[name*="street"]', $subject->address_line1);
        }
        if (in_array('city', $allowedFields) && !empty($subject->city)) {
            $this->tryFillFormField('input[name*="city"]', $subject->city);
        }
        if (in_array('state', $allowedFields) && !empty($subject->state)) {
            $this->tryFillFormField('input[name*="state"]', $subject->state);
        }
        if (in_array('zip', $allowedFields) && !empty($subject->zip)) {
            $this->tryFillFormField('input[name*="zip"], input[name*="postal"]', $subject->zip);
        }
        if (in_array('dob', $allowedFields) && !empty($subject->date_of_birth)) {
            $this->tryFillFormField('input[name*="birth"], input[name*="dob"]', $subject->date_of_birth);
        }

        // Try to find and click submit button
        $submitClicked = $this->tryClickSubmit();

        if ($submitClicked) {
            // Wait for submission
            usleep(3000000);

            // Take post-submission screenshot
            $postScreenshot = "removal_post_{$broker->id}_{$subject->id}_" . time();
            $this->browserService->screenshot($postScreenshot);

            // Look for confirmation
            $postResult = $this->browserService->getContent('text');
            $postContent = $postResult['content'] ?? '';

            $confirmation = $this->extractConfirmation($postContent);

            return [
                'success' => true,
                'confirmation_code' => $confirmation,
                'screenshots' => [$screenshotName, $postScreenshot],
                'engine' => $engine,
            ];
        }

        return [
            'success' => false,
            'error' => 'Could not find submit button',
            'requires_manual' => true,
            'removal_url' => $broker->removal_url,
            'engine' => $engine,
        ];
    }

    /**
     * Submit removal form using HTTP POST
     * Only submits fields that are in the allowedFields list (privacy protection)
     */
    private function submitFormWithHttp(object $broker, object $subject, array $allowedFields = ['name']): array
    {
        // HTTP form submission is broker-specific
        // Most brokers use JavaScript, so this is a fallback
        return [
            'success' => false,
            'requires_manual' => true,
            'removal_url' => $broker->removal_url,
            'message' => 'HTTP form submission requires manual action for this broker.',
            'engine' => 'http',
            'allowed_fields' => $allowedFields,
        ];
    }

    /**
     * Try to fill a form field using browser automation
     */
    private function tryFillFormField(string $selector, string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        try {
            $result = $this->browserService->fill($selector, $value);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            // Field not found or not fillable
            return false;
        }
    }

    /**
     * Try to click submit button using common selectors
     */
    private function tryClickSubmit(): bool
    {
        $submitSelectors = [
            'button[type="submit"]',
            'input[type="submit"]',
            'button:has-text("Submit")',
            'button:has-text("Remove")',
            'button:has-text("Opt Out")',
            'button:has-text("Delete")',
            '.submit-btn',
            '#submit',
        ];

        foreach ($submitSelectors as $selector) {
            try {
                $result = $this->browserService->click($selector);
                if ($result['success'] ?? false) {
                    return true;
                }
            } catch (\Exception $e) {
                // Selector not found, try next
            }
        }

        return false;
    }

    /**
     * Identify the type of CAPTCHA on the page
     */
    private function identifyCaptchaType(string $html): string
    {
        $htmlLower = strtolower($html);

        if (str_contains($htmlLower, 'g-recaptcha') || str_contains($htmlLower, 'recaptcha')) {
            return 'recaptcha';
        }
        if (str_contains($htmlLower, 'hcaptcha')) {
            return 'hcaptcha';
        }
        if (str_contains($htmlLower, 'cf-turnstile')) {
            return 'turnstile';
        }
        if (str_contains($htmlLower, 'captcha')) {
            return 'image_captcha';
        }

        return 'unknown';
    }

    /**
     * Extract confirmation code from page content
     */
    private function extractConfirmation(string $content): ?string
    {
        // Look for common confirmation patterns
        $patterns = [
            '/confirmation\s*(?:number|code|id)?[:\s]*([A-Z0-9-]+)/i',
            '/reference\s*(?:number|code|id)?[:\s]*([A-Z0-9-]+)/i',
            '/request\s*(?:number|code|id)?[:\s]*([A-Z0-9-]+)/i',
            '/ticket\s*(?:number|code|id)?[:\s]*([A-Z0-9-]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return $matches[1];
            }
        }

        // Check for success message
        if (preg_match('/request.*(?:received|submitted|confirmed)/i', $content)) {
            return 'SUBMITTED_' . date('YmdHis');
        }

        return null;
    }

    // ========================================
    // RATE LIMITING
    // ========================================

    /**
     * Check if we can scan this broker (rate limiting)
     *
     * @param object $broker The data broker
     * @return bool True if we can proceed, false if rate limited
     */
    public function checkRateLimit(object $broker): bool
    {
        $cacheKey = "broker_rate_limit_{$broker->id}";
        $maxScansPerHour = $broker->max_scans_per_hour ?? 10;

        $scanCount = cache()->get($cacheKey, 0);

        if ($scanCount >= $maxScansPerHour) {
            Log::info("BrokerScraperService: Rate limited for {$broker->domain}", [
                'current' => $scanCount,
                'max' => $maxScansPerHour,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Record that a scan was performed (for rate limiting)
     *
     * @param object $broker The data broker
     */
    public function recordScan(object $broker): void
    {
        $cacheKey = "broker_rate_limit_{$broker->id}";
        $scanCount = cache()->get($cacheKey, 0);

        cache()->put($cacheKey, $scanCount + 1, now()->addHour());

        Log::debug("BrokerScraperService: Recorded scan for {$broker->domain}", [
            'new_count' => $scanCount + 1,
        ]);
    }

    /**
     * Scan a broker for subject data (alias for searchBroker with engine selection)
     *
     * @param object $broker The data broker
     * @param object $subject The data subject
     * @param string|null $engine Preferred engine (optional, will auto-select if null)
     * @return array Scan results
     */
    public function scanBroker(object $broker, object $subject, ?string $engine = null): array
    {
        // Use the provided engine or auto-select
        if ($engine === null) {
            $engine = $this->selectEngine($broker);
        }

        Log::info("BrokerScraperService: Scanning {$broker->name} using {$engine}", [
            'broker_id' => $broker->id,
            'subject_id' => $subject->id,
        ]);

        try {
            return $this->circuitBreaker->call(
                "broker_scraper_{$broker->domain}",
                fn() => match ($engine) {
                    'puppeteer', 'playwright' => $this->searchWithBrowser($broker, $subject, $engine),
                    default => $this->searchWithHttp($broker, $subject),
                }
            );
        } catch (\Exception $e) {
            Log::error("BrokerScraperService: Scan failed for {$broker->name}", [
                'error' => $e->getMessage(),
                'engine' => $engine,
            ]);

            return [
                'success' => false,
                'found' => false,
                'error' => $e->getMessage(),
                'engine' => $engine,
            ];
        }
    }

    // ========================================
    // SCREENSHOTS
    // ========================================

    /**
     * Take a screenshot of a URL
     *
     * @param string $url URL to screenshot
     * @param string $name Screenshot name
     * @return string|null Path to screenshot or null if failed
     */
    public function takeScreenshot(string $url, string $name): ?string
    {
        $engine = $this->browserService->getAvailableEngine();

        if (!$engine) {
            return null;
        }

        try {
            $navResult = $this->browserService->navigate($url, $engine);

            if (!($navResult['success'] ?? false)) {
                return null;
            }

            $result = $this->browserService->screenshot($name);

            return $result['path'] ?? null;
        } catch (\Exception $e) {
            Log::error("BrokerScraperService: Screenshot failed", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get browser automation status
     */
    public function getBrowserStatus(): array
    {
        return $this->browserService->getStatus();
    }

    /**
     * Shutdown browser servers
     */
    public function shutdownBrowsers(): void
    {
        $this->browserService->shutdownAll();
    }

    // ========================================
    // SECURITY MANAGEMENT
    // ========================================

    /**
     * Add domain to Puppeteer allowlist
     */
    public function addDomainToAllowlist(string $domain): bool
    {
        return $this->browserService->addToAllowlist($domain);
    }

    /**
     * Get Puppeteer security status
     */
    public function getSecurityStatus(): array
    {
        return $this->browserService->getSecurityStatus();
    }

    /**
     * Sync allowlist from database
     */
    public function syncAllowlist(): bool
    {
        return $this->browserService->syncAllowlistFromDatabase();
    }

    /**
     * Get current Puppeteer allowlist
     */
    public function getAllowlist(): array
    {
        return $this->browserService->getAllowlist();
    }
}
