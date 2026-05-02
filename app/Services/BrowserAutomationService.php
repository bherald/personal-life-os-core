<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\DB;

/**
 * Browser Automation Service (Security Hardened)
 *
 * Provides a unified interface for browser automation using persistent
 * Puppeteer or Playwright servers. Falls back gracefully between engines.
 *
 * SECURITY FEATURES:
 * - Domain allowlist enforcement (syncs from database)
 * - Fresh session per navigation (prevents state leakage)
 * - Download blocking
 * - Request interception for malicious content
 * - Sandbox enabled (when not running as root)
 *
 * Engine Priority:
 * 1. Puppeteer (port 9222) - Fast, lightweight, security-hardened
 * 2. Playwright (port 9223) - Better anti-detection
 * 3. HTTP - Simple requests without JavaScript
 *
 * E06: Personal Data Removal System
 */
class BrowserAutomationService
{
    private const PUPPETEER_PORT = 9222;
    private const PLAYWRIGHT_PORT = 9223;
    private const PUPPETEER_SCRIPT = 'scripts/browser-server/puppeteer-server.cjs';
    private const PLAYWRIGHT_SCRIPT = 'scripts/browser-server/playwright-server.cjs';

    private int $timeout;
    private string $screenshotPath;
    private string $userAgent;

    private ?string $activeEngine = null;
    private bool $allowlistSynced = false;

    public function __construct()
    {
        $this->timeout = (int) config('data_removal.browser_automation_timeout', 30);
        $this->screenshotPath = storage_path('app/data-removal/screenshots');
        $this->userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        if (!file_exists($this->screenshotPath)) {
            mkdir($this->screenshotPath, 0755, true);
        }
    }

    /**
     * Sync domain allowlist from database to Puppeteer server
     * Called automatically on first navigation
     */
    public function syncAllowlistFromDatabase(): bool
    {
        if ($this->allowlistSynced) {
            return true;
        }

        if (!$this->isPuppeteerAvailable()) {
            return false;
        }

        try {
            // Get all active broker domains from database (raw SQL)
            $brokers = DB::select("
                SELECT LOWER(domain) as domain
                FROM data_brokers
                WHERE is_active = 1
            ");

            $synced = 0;
            foreach ($brokers as $broker) {
                try {
                    Http::connectTimeout(1)->timeout(2)->post('http://127.0.0.1:' . self::PUPPETEER_PORT . '/allowlist', [
                        'domain' => $broker->domain,
                    ]);
                    $synced++;
                } catch (\Exception $e) {
                    // Continue with next
                }
            }

            Log::info('BrowserAutomation: Synced allowlist from database', [
                'domains_synced' => $synced,
                'total_brokers' => count($brokers),
            ]);

            $this->allowlistSynced = true;
            return true;

        } catch (\Exception $e) {
            Log::warning('BrowserAutomation: Failed to sync allowlist', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Add a single domain to the Puppeteer allowlist
     */
    public function addToAllowlist(string $domain): bool
    {
        if (!$this->isPuppeteerAvailable()) {
            return false;
        }

        try {
            $response = Http::connectTimeout(1)->timeout(2)->post('http://127.0.0.1:' . self::PUPPETEER_PORT . '/allowlist', [
                'domain' => strtolower(trim($domain)),
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('BrowserAutomation: Failed to add domain to allowlist', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get current Puppeteer allowlist
     */
    public function getAllowlist(): array
    {
        if (!$this->isPuppeteerAvailable()) {
            return [];
        }

        try {
            $response = Http::connectTimeout(1)->timeout(2)->get('http://127.0.0.1:' . self::PUPPETEER_PORT . '/allowlist');
            if ($response->successful()) {
                return $response->json('domains') ?? [];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return [];
    }

    /**
     * Get security status from Puppeteer server
     */
    public function getSecurityStatus(): array
    {
        if (!$this->isPuppeteerAvailable()) {
            return ['available' => false];
        }

        try {
            $response = Http::connectTimeout(1)->timeout(2)->get('http://127.0.0.1:' . self::PUPPETEER_PORT . '/health');
            if ($response->successful()) {
                $health = $response->json();
                return [
                    'available' => true,
                    'security' => $health['security'] ?? [],
                ];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return ['available' => false];
    }

    // ========================================
    // ENGINE MANAGEMENT
    // ========================================

    /**
     * Check if Puppeteer server is running
     */
    public function isPuppeteerAvailable(): bool
    {
        return $this->checkServerHealth(self::PUPPETEER_PORT);
    }

    /**
     * Check if Playwright server is running
     */
    public function isPlaywrightAvailable(): bool
    {
        return $this->checkServerHealth(self::PLAYWRIGHT_PORT);
    }

    /**
     * Check server health via HTTP
     */
    private function checkServerHealth(int $port): bool
    {
        try {
            $response = Http::connectTimeout(1)->timeout(2)->get("http://127.0.0.1:{$port}/health");
            return $response->successful() && ($response->json('status') === 'ok');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Start Puppeteer server if not running
     */
    public function startPuppeteer(): bool
    {
        if ($this->isPuppeteerAvailable()) {
            Log::debug('BrowserAutomation: Puppeteer already running');
            return true;
        }

        $script = base_path(self::PUPPETEER_SCRIPT);
        if (!file_exists($script)) {
            Log::error('BrowserAutomation: Puppeteer script not found', ['path' => $script]);
            return false;
        }

        $env = [
            'PUPPETEER_EXECUTABLE_PATH' => $this->getChromePath(),
            'SCREENSHOT_DIR' => $this->screenshotPath,
            'NODE_ENV' => 'production',
        ];

        Log::info('BrowserAutomation: Starting Puppeteer server', ['script' => $script, 'port' => self::PUPPETEER_PORT]);

        Process::forever()
            ->path(base_path())
            ->env($env)
            ->quietly()
            ->start(['node', $script, (string) self::PUPPETEER_PORT]);

        // Wait for startup
        for ($i = 0; $i < 10; $i++) {
            usleep(500000); // 500ms
            if ($this->isPuppeteerAvailable()) {
                Log::info('BrowserAutomation: Puppeteer server started');
                return true;
            }
        }

        Log::error('BrowserAutomation: Failed to start Puppeteer server');
        return false;
    }

    /**
     * Start Playwright server if not running
     */
    public function startPlaywright(): bool
    {
        if ($this->isPlaywrightAvailable()) {
            Log::debug('BrowserAutomation: Playwright already running');
            return true;
        }

        $script = base_path(self::PLAYWRIGHT_SCRIPT);
        if (!file_exists($script)) {
            Log::error('BrowserAutomation: Playwright script not found', ['path' => $script]);
            return false;
        }

        $env = [
            'SCREENSHOT_DIR' => $this->screenshotPath,
            'NODE_ENV' => 'production',
        ];

        Log::info('BrowserAutomation: Starting Playwright server', ['script' => $script, 'port' => self::PLAYWRIGHT_PORT]);

        Process::forever()
            ->path(base_path())
            ->env($env)
            ->quietly()
            ->start(['node', $script, (string) self::PLAYWRIGHT_PORT]);

        // Wait for startup
        for ($i = 0; $i < 10; $i++) {
            usleep(500000); // 500ms
            if ($this->isPlaywrightAvailable()) {
                Log::info('BrowserAutomation: Playwright server started');
                return true;
            }
        }

        Log::error('BrowserAutomation: Failed to start Playwright server');
        return false;
    }

    /**
     * Get Chrome executable path
     */
    private function getChromePath(): string
    {
        // Check for Puppeteer's Chrome
        $homeDir = $this->resolveRuntimeEnvValue('HOME') ?? '';
        $puppeteerChrome = $homeDir !== ''
            ? glob($homeDir . '/.cache/puppeteer/chrome/*/chrome-linux64/chrome')
            : [];
        if (!empty($puppeteerChrome)) {
            // Sort and get latest version
            rsort($puppeteerChrome);
            return $puppeteerChrome[0];
        }

        // Fall back to system Chrome
        $systemPaths = [
            '/usr/bin/google-chrome',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
        ];

        foreach ($systemPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return 'chrome';
    }

    private function resolveRuntimeEnvValue(?string $key): ?string
    {
        if (!$key) {
            return null;
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Get the best available engine
     */
    public function getAvailableEngine(): ?string
    {
        // Try Puppeteer first (faster)
        if ($this->isPuppeteerAvailable() || $this->startPuppeteer()) {
            return 'puppeteer';
        }

        // Fall back to Playwright
        if ($this->isPlaywrightAvailable() || $this->startPlaywright()) {
            return 'playwright';
        }

        return null;
    }

    /**
     * Get base URL for engine
     */
    private function getEngineUrl(string $engine): string
    {
        return match ($engine) {
            'puppeteer' => 'http://127.0.0.1:' . self::PUPPETEER_PORT,
            'playwright' => 'http://127.0.0.1:' . self::PLAYWRIGHT_PORT,
            default => throw new \InvalidArgumentException("Unknown engine: {$engine}"),
        };
    }

    /**
     * Make request to browser server
     */
    private function request(string $engine, string $endpoint, array $data = []): array
    {
        $url = $this->getEngineUrl($engine) . $endpoint;

        try {
            $response = Http::connectTimeout(5)->timeout($this->timeout)
                ->post($url, $data);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            return [
                'success' => false,
                'error' => "HTTP {$response->status()}: " . $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('BrowserAutomation: Request failed', [
                'engine' => $engine,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ========================================
    // BROWSER OPERATIONS
    // ========================================

    /**
     * Navigate to URL (Security Hardened)
     *
     * @param string $url Target URL
     * @param string|null $engine Specific engine or null for auto-select
     * @param bool $freshSession Use fresh browser session (recommended for security)
     * @param bool $bypassAllowlist Bypass domain allowlist (use with caution)
     * @return array Navigation result
     */
    public function navigate(string $url, ?string $engine = null, bool $freshSession = true, bool $bypassAllowlist = false): array
    {
        $engine = $engine ?? $this->getAvailableEngine();

        if (!$engine) {
            return [
                'success' => false,
                'error' => 'No browser engine available',
            ];
        }

        // Security: Sync allowlist from database on first use
        if ($engine === 'puppeteer' && !$this->allowlistSynced) {
            $this->syncAllowlistFromDatabase();
        }

        Log::info('BrowserAutomation: Navigating', [
            'url' => $url,
            'engine' => $engine,
            'freshSession' => $freshSession,
            'bypassAllowlist' => $bypassAllowlist,
        ]);

        $result = $this->request($engine, '/navigate', [
            'url' => $url,
            'waitUntil' => $engine === 'playwright' ? 'networkidle' : 'networkidle2',
            'timeout' => $this->timeout * 1000,
            'freshSession' => $freshSession,
            'bypassAllowlist' => $bypassAllowlist,
        ]);

        $result['engine'] = $engine;
        $this->activeEngine = $engine;

        return $result;
    }

    /**
     * Navigate to a trusted/internal URL (bypasses allowlist)
     * Use only for known-safe URLs like research sites
     */
    public function navigateTrusted(string $url, ?string $engine = null): array
    {
        return $this->navigate($url, $engine, true, true);
    }

    /**
     * Get page content
     *
     * @param string $type Content type: 'html' or 'text'
     * @return array Content result
     */
    public function getContent(string $type = 'text'): array
    {
        if (!$this->activeEngine) {
            return ['success' => false, 'error' => 'No active page'];
        }

        return $this->request($this->activeEngine, '/content', ['type' => $type]);
    }

    /**
     * Take screenshot
     *
     * @param string|null $name Screenshot name
     * @param string|null $selector Element selector (optional)
     * @param bool $fullPage Full page screenshot
     * @return array Screenshot result with path and base64
     */
    public function screenshot(?string $name = null, ?string $selector = null, bool $fullPage = false): array
    {
        if (!$this->activeEngine) {
            return ['success' => false, 'error' => 'No active page'];
        }

        return $this->request($this->activeEngine, '/screenshot', [
            'name' => $name ?? 'screenshot-' . time(),
            'selector' => $selector,
            'fullPage' => $fullPage,
        ]);
    }

    /**
     * Execute JavaScript
     *
     * @param string $script JavaScript code
     * @return array Evaluation result
     */
    public function evaluate(string $script): array
    {
        if (!$this->activeEngine) {
            return ['success' => false, 'error' => 'No active page'];
        }

        return $this->request($this->activeEngine, '/evaluate', ['script' => $script]);
    }

    /**
     * Fill form field
     *
     * @param string $selector CSS selector
     * @param string $value Value to fill
     * @return array Fill result
     */
    public function fill(string $selector, string $value): array
    {
        if (!$this->activeEngine) {
            return ['success' => false, 'error' => 'No active page'];
        }

        return $this->request($this->activeEngine, '/fill', [
            'selector' => $selector,
            'value' => $value,
        ]);
    }

    /**
     * Click element
     *
     * @param string $selector CSS selector
     * @return array Click result
     */
    public function click(string $selector): array
    {
        if (!$this->activeEngine) {
            return ['success' => false, 'error' => 'No active page'];
        }

        return $this->request($this->activeEngine, '/click', [
            'selector' => $selector,
        ]);
    }

    /**
     * Select dropdown option
     *
     * @param string $selector CSS selector
     * @param string $value Option value
     * @return array Select result
     */
    public function select(string $selector, string $value): array
    {
        if (!$this->activeEngine) {
            return ['success' => false, 'error' => 'No active page'];
        }

        return $this->request($this->activeEngine, '/select', [
            'selector' => $selector,
            'value' => $value,
        ]);
    }

    /**
     * Wait for selector
     *
     * @param string $selector CSS selector
     * @param int $timeout Timeout in milliseconds
     * @return array Wait result
     */
    public function waitFor(string $selector, int $timeout = 10000): array
    {
        if (!$this->activeEngine) {
            return ['success' => false, 'error' => 'No active page'];
        }

        return $this->request($this->activeEngine, '/wait', [
            'selector' => $selector,
            'timeout' => $timeout,
        ]);
    }

    /**
     * Close current page
     *
     * @return array Close result
     */
    public function closePage(): array
    {
        if (!$this->activeEngine) {
            return ['success' => true];
        }

        $result = $this->request($this->activeEngine, '/close');
        $this->activeEngine = null;

        return $result;
    }

    /**
     * Get current engine
     */
    public function getActiveEngine(): ?string
    {
        return $this->activeEngine;
    }

    /**
     * Shutdown all browser servers
     */
    public function shutdownAll(): void
    {
        if ($this->isPuppeteerAvailable()) {
            try {
                Http::connectTimeout(1)->timeout(2)->post('http://127.0.0.1:' . self::PUPPETEER_PORT . '/shutdown');
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->isPlaywrightAvailable()) {
            try {
                Http::connectTimeout(1)->timeout(2)->post('http://127.0.0.1:' . self::PLAYWRIGHT_PORT . '/shutdown');
            } catch (\Exception $e) {
                // Ignore
            }
        }
    }

    /**
     * Get status of all engines
     */
    public function getStatus(): array
    {
        $status = [
            'puppeteer' => [
                'available' => $this->isPuppeteerAvailable(),
                'port' => self::PUPPETEER_PORT,
            ],
            'playwright' => [
                'available' => $this->isPlaywrightAvailable(),
                'port' => self::PLAYWRIGHT_PORT,
            ],
            'active_engine' => $this->activeEngine,
        ];

        // Get detailed health if available
        foreach (['puppeteer', 'playwright'] as $engine) {
            if ($status[$engine]['available']) {
                try {
                    $port = $engine === 'puppeteer' ? self::PUPPETEER_PORT : self::PLAYWRIGHT_PORT;
                    $health = Http::connectTimeout(1)->timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
                    $status[$engine]['health'] = $health;
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        return $status;
    }
}
