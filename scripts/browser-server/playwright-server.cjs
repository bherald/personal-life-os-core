#!/usr/bin/env node
/**
 * Persistent Playwright Server
 *
 * A long-running HTTP server that maintains a persistent browser instance.
 * Accepts commands via HTTP POST and returns results as JSON.
 * Uses Playwright for better anti-bot detection bypass.
 *
 * E06: Personal Data Removal System
 *
 * Usage:
 *   node playwright-server.js [port]
 *   Default port: 9223
 *
 * Endpoints:
 *   POST /navigate   - Navigate to URL
 *   POST /screenshot - Take screenshot
 *   POST /evaluate   - Execute JavaScript
 *   POST /fill       - Fill form field
 *   POST /click      - Click element
 *   POST /content    - Get page content
 *   POST /snapshot   - Get capped accessibility snapshot (untrusted content)
 *   GET  /health     - Health check
 *   POST /close      - Close current page
 *   POST /shutdown   - Shutdown server
 *   GET/POST /allowlist - Inspect or add allowed navigation domains
 *
 * Every endpoint requires installation-derived bearer authentication and
 * loopback Host/Origin validation. Arbitrary page evaluation is off by default.
 */

const http = require('http');
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const {
    DEFAULT_MAX_CONTENT_WINDOW_CHARS,
    contentWindow,
    isDomainAllowed,
    navigationScopeForTarget,
    normalizeDomain,
    parseJsonBody,
    requestPolicyError,
    requireAutomationToken,
    resolvePublicNavigationTarget,
    safeOutputName,
    sendJson,
} = require('./security-policy.cjs');

const PORT = parseInt(process.argv[2]) || 9223;
const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/playwright-screenshots';
const AUTH_TOKEN = requireAutomationToken();
const ALLOW_EVALUATE = process.env.BROWSER_AUTOMATION_ALLOW_EVALUATE === 'true';
const MAX_SNAPSHOT_CHARS = 200000;
const ALLOWED_DOMAINS = new Set();
const BLOCKED_EXTENSIONS = ['.exe', '.msi', '.dll', '.bat', '.cmd', '.ps1', '.sh', '.dmg', '.pkg', '.deb', '.rpm', '.zip', '.rar', '.7z', '.tar', '.gz'];

// Ensure screenshot directory exists
if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

let browser = null;
let context = null;
let page = null;
let lastActivity = Date.now();
let activeNavigationScope = null;
let blockedNavigationUrl = null;
let blockedNavigationError = null;

// Auto-restart browser if idle for too long (30 minutes)
const IDLE_TIMEOUT = 30 * 60 * 1000;

/**
 * Initialize or get browser instance
 */
async function getBrowser() {
    if (!browser || !browser.isConnected()) {
        console.log('[Playwright] Launching browser...');
        const isRoot = process.getuid && process.getuid() === 0;
        const args = [
            '--disable-dev-shm-usage',
            '--disable-blink-features=AutomationControlled',
        ];
        if (isRoot) {
            args.push('--no-sandbox', '--disable-setuid-sandbox');
        }

        const executablePath = process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined;
        browser = await chromium.launch({
            headless: true,
            args,
            executablePath,
            chromiumSandbox: !isRoot,
        });
        console.log('[Playwright] Browser launched');
    }
    return browser;
}

/**
 * Get or create browser context
 */
async function getContext(forceNew = false) {
    const b = await getBrowser();

    if (forceNew && context) {
        await context.close().catch(() => {});
        context = null;
        page = null;
    }

    if (!context) {
        console.log('[Playwright] Creating browser context...');
        context = await b.newContext({
            viewport: { width: 1920, height: 1080 },
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            locale: 'en-US',
            timezoneId: 'America/New_York',
            acceptDownloads: false,
            serviceWorkers: 'block',
        });

        // Add stealth scripts to avoid detection
        await context.addInitScript(() => {
            // Override webdriver property
            Object.defineProperty(navigator, 'webdriver', {
                get: () => undefined,
            });

            // Override plugins
            Object.defineProperty(navigator, 'plugins', {
                get: () => [1, 2, 3, 4, 5],
            });

            // Override languages
            Object.defineProperty(navigator, 'languages', {
                get: () => ['en-US', 'en'],
            });
        });

        console.log('[Playwright] Context created');
    }

    return context;
}

/**
 * Get or create page
 */
async function getPage(freshSession = false) {
    const ctx = await getContext(freshSession);

    if (!page || page.isClosed()) {
        console.log('[Playwright] Creating new page...');
        page = await ctx.newPage();
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Fetch.enable', {
            patterns: [{ urlPattern: '*', resourceType: 'Document', requestStage: 'Request' }],
        });
        cdp.on('Fetch.requestPaused', async event => {
            try {
                if (!activeNavigationScope || !isDomainAllowed(event.request.url, activeNavigationScope)) {
                    blockedNavigationUrl = event.request.url;
                    blockedNavigationError = 'Top-level navigation left the operation domain scope';
                    console.log(`[Playwright] Security: Blocked document request outside operation scope - ${event.request.url}`);
                    await cdp.send('Fetch.failRequest', {
                        requestId: event.requestId,
                        errorReason: 'BlockedByClient',
                    });
                    return;
                }

                const dnsPolicy = await resolvePublicNavigationTarget(event.request.url);
                if (!dnsPolicy.allowed) {
                    blockedNavigationUrl = event.request.url;
                    blockedNavigationError = 'Top-level hostname did not resolve exclusively to public addresses';
                    console.log(`[Playwright] Security: Blocked document request by DNS policy - ${dnsPolicy.reason}`);
                    await cdp.send('Fetch.failRequest', {
                        requestId: event.requestId,
                        errorReason: 'BlockedByClient',
                    });
                    return;
                }

                await cdp.send('Fetch.continueRequest', { requestId: event.requestId });
            } catch (error) {
                console.log(`[Playwright] Security: Document request policy failed closed - ${error.message}`);
                await cdp.send('Fetch.failRequest', {
                    requestId: event.requestId,
                    errorReason: 'BlockedByClient',
                }).catch(() => {});
            }
        });
        page.on('download', download => download.cancel().catch(() => {}));
        page.on('popup', popup => {
            console.log(`[Playwright] Security: Blocked popup - ${popup.url()}`);
            popup.close().catch(() => {});
        });
        console.log('[Playwright] Page created');
    }

    lastActivity = Date.now();
    return page;
}

/**
 * Handle requests
 */
async function handleRequest(req, res) {
    const url = req.url;
    const method = req.method;

    try {
        const policyError = requestPolicyError(req, PORT, AUTH_TOKEN);
        if (policyError) {
            return sendJson(res, policyError.status, { error: policyError.error });
        }

        // Health check
        if (url === '/health' && method === 'GET') {
            return sendJson(res, 200, {
                status: 'ok',
                browser: browser?.isConnected() ? 'connected' : 'disconnected',
                page: page && !page.isClosed() ? 'active' : 'none',
                uptime: process.uptime(),
                lastActivity: new Date(lastActivity).toISOString(),
                engine: 'playwright',
                security: {
                    sandboxEnabled: !(process.getuid && process.getuid() === 0),
                    authRequired: true,
                    hostProtection: true,
                    originProtection: true,
                    domainAllowlist: ALLOWED_DOMAINS.size,
                    freshContextPerNavigation: true,
                    downloadsAccepted: false,
                    serviceWorkersBlocked: true,
                    arbitraryEvaluateEnabled: ALLOW_EVALUATE,
                    topLevelNavigationAllowlist: true,
                    documentRequestsScoped: true,
                    ipLiteralAndLocalhostTargetsBlocked: true,
                    topLevelDnsPublicAddressesRequired: true,
                    dnsResolutionFailClosed: true,
                    popupsBlocked: true,
                    credentialApiEnabled: false,
                    contentWindowing: true,
                    maxContentWindowChars: DEFAULT_MAX_CONTENT_WINDOW_CHARS,
                    maxRequestBodyBytes: 1024 * 1024,
                    maxSnapshotChars: MAX_SNAPSHOT_CHARS,
                },
            });
        }

        // Shutdown
        if (url === '/shutdown' && method === 'POST') {
            sendJson(res, 200, { status: 'shutting_down' });
            setTimeout(async () => {
                if (browser) await browser.close();
                process.exit(0);
            }, 100);
            return;
        }

        const body = await parseJsonBody(req);

        // Navigate to URL
        if (url === '/navigate' && method === 'POST') {
            const { url: targetUrl, waitUntil = 'networkidle', timeout = 30000, bypassAllowlist = false, freshSession = true } = body;

            if (!targetUrl) {
                return sendJson(res, 400, { error: 'url is required' });
            }

            const navigationScope = navigationScopeForTarget(targetUrl, ALLOWED_DOMAINS, bypassAllowlist);
            if (!navigationScope) {
                return sendJson(res, 403, { error: 'Domain not in allowlist' });
            }

            const dnsPolicy = await resolvePublicNavigationTarget(targetUrl);
            if (!dnsPolicy.allowed) {
                return sendJson(res, 403, {
                    error: 'Target hostname did not resolve exclusively to public addresses',
                    reason: dnsPolicy.reason,
                });
            }

            let parsedTarget;
            try {
                parsedTarget = new URL(targetUrl);
            } catch {
                return sendJson(res, 400, { error: 'Invalid URL' });
            }
            if (!['http:', 'https:'].includes(parsedTarget.protocol) || parsedTarget.username || parsedTarget.password) {
                return sendJson(res, 403, { error: 'URL scheme or credentials are not allowed' });
            }
            if (BLOCKED_EXTENSIONS.some(extension => parsedTarget.pathname.toLowerCase().endsWith(extension))) {
                return sendJson(res, 403, { error: 'Blocked file type' });
            }

            activeNavigationScope = navigationScope;
            blockedNavigationUrl = null;
            blockedNavigationError = null;
            const p = await getPage(freshSession);
            console.log(`[Playwright] Navigating to: ${targetUrl} (fresh session: ${freshSession})`);

            try {
                await p.goto(targetUrl, {
                    waitUntil,
                    timeout
                });
            } catch (error) {
                if (blockedNavigationUrl) {
                    return sendJson(res, 403, {
                        error: blockedNavigationError,
                        blockedUrl: blockedNavigationUrl,
                    });
                }
                throw error;
            }

            const pageUrl = p.url();
            if (!isDomainAllowed(pageUrl, navigationScope)) {
                await p.goto('about:blank').catch(() => {});
                return sendJson(res, 403, {
                    error: 'Final navigation URL left the operation domain scope',
                    blockedUrl: pageUrl,
                });
            }
            const title = await p.title();

            return sendJson(res, 200, {
                success: true,
                url: pageUrl,
                title,
            });
        }

        // Take screenshot
        if (url === '/screenshot' && method === 'POST') {
            const { name, selector, fullPage = false } = body;

            const p = await getPage();
            const filename = safeOutputName(name, `screenshot-${Date.now()}`);
            const filepath = path.join(SCREENSHOT_DIR, `${filename}.png`);

            if (selector) {
                const element = await p.$(selector);
                if (element) {
                    await element.screenshot({ path: filepath });
                } else {
                    return sendJson(res, 404, { error: `Element not found: ${selector}` });
                }
            } else {
                await p.screenshot({ path: filepath, fullPage });
            }

            console.log(`[Playwright] Screenshot saved: ${filepath}`);

            const base64 = fs.readFileSync(filepath).toString('base64');

            return sendJson(res, 200, {
                success: true,
                path: filepath,
                base64,
            });
        }

        // Evaluate JavaScript
        if (url === '/evaluate' && method === 'POST') {
            if (!ALLOW_EVALUATE) {
                return sendJson(res, 403, { error: 'Arbitrary page evaluation is disabled' });
            }

            const { script } = body;

            if (!script) {
                return sendJson(res, 400, { error: 'script is required' });
            }

            const p = await getPage();
            const result = await p.evaluate(script);

            return sendJson(res, 200, {
                success: true,
                result,
            });
        }

        // Get page content
        if (url === '/content' && method === 'POST') {
            const { type = 'html', offset = 0, limit = DEFAULT_MAX_CONTENT_WINDOW_CHARS } = body;

            const p = await getPage();
            let content;

            if (type === 'text') {
                content = await p.evaluate(() => document.body.innerText);
            } else {
                content = await p.content();
            }

            return sendJson(res, 200, {
                success: true,
                ...contentWindow(content, offset, limit),
                untrustedPageContent: true,
                url: p.url(),
            });
        }

        // Accessibility snapshots are untrusted page content, not instructions.
        if (url === '/snapshot' && method === 'POST') {
            const { selector = 'body' } = body;
            const p = await getPage();
            const snapshot = await p.locator(selector).ariaSnapshot({ timeout: 5000 });
            const truncated = snapshot.length > MAX_SNAPSHOT_CHARS;

            return sendJson(res, 200, {
                success: true,
                snapshot: snapshot.slice(0, MAX_SNAPSHOT_CHARS),
                truncated,
                untrustedPageContent: true,
                url: p.url(),
            });
        }

        if (url === '/allowlist' && method === 'GET') {
            return sendJson(res, 200, {
                success: true,
                domains: Array.from(ALLOWED_DOMAINS).sort(),
                count: ALLOWED_DOMAINS.size,
            });
        }

        if (url === '/allowlist' && method === 'POST') {
            const domain = normalizeDomain(body.domain);
            if (!domain) {
                return sendJson(res, 400, { error: 'Invalid domain format' });
            }

            ALLOWED_DOMAINS.add(domain);
            return sendJson(res, 200, {
                success: true,
                domain,
                totalDomains: ALLOWED_DOMAINS.size,
            });
        }

        // Fill form field
        if (url === '/fill' && method === 'POST') {
            const { selector, value } = body;

            if (!selector || value === undefined) {
                return sendJson(res, 400, { error: 'selector and value are required' });
            }

            const p = await getPage();

            try {
                await p.fill(selector, value, { timeout: 5000 });
                return sendJson(res, 200, { success: true });
            } catch (e) {
                return sendJson(res, 404, { error: `Element not found or not fillable: ${selector}` });
            }
        }

        // Click element
        if (url === '/click' && method === 'POST') {
            const { selector, timeout = 5000 } = body;

            if (!selector) {
                return sendJson(res, 400, { error: 'selector is required' });
            }

            const p = await getPage();

            try {
                await p.click(selector, { timeout });
                return sendJson(res, 200, { success: true });
            } catch (e) {
                return sendJson(res, 404, { error: `Element not found or not clickable: ${selector}` });
            }
        }

        // Select dropdown
        if (url === '/select' && method === 'POST') {
            const { selector, value } = body;

            if (!selector || !value) {
                return sendJson(res, 400, { error: 'selector and value are required' });
            }

            const p = await getPage();

            try {
                await p.selectOption(selector, value, { timeout: 5000 });
                return sendJson(res, 200, { success: true });
            } catch (e) {
                return sendJson(res, 404, { error: `Select element not found: ${selector}` });
            }
        }

        // Wait for selector
        if (url === '/wait' && method === 'POST') {
            const { selector, timeout = 10000, state = 'visible' } = body;

            if (!selector) {
                return sendJson(res, 400, { error: 'selector is required' });
            }

            const p = await getPage();

            try {
                await p.waitForSelector(selector, { timeout, state });
                return sendJson(res, 200, { success: true, found: true });
            } catch (e) {
                return sendJson(res, 200, { success: true, found: false });
            }
        }

        // Close current page (but keep browser)
        if (url === '/close' && method === 'POST') {
            if (page && !page.isClosed()) {
                await page.close();
                page = null;
            }
            activeNavigationScope = null;
            blockedNavigationUrl = null;
            return sendJson(res, 200, { success: true });
        }

        // Not found
        return sendJson(res, 404, { error: 'Not found' });

    } catch (error) {
        console.error('[Playwright] Error:', error.message);
        return sendJson(res, error.statusCode || 500, {
            error: error.message,
            stack: process.env.NODE_ENV === 'development' ? error.stack : undefined,
        });
    }
}

// Create server
const server = http.createServer(handleRequest);

// Graceful shutdown
async function shutdown() {
    console.log('[Playwright] Shutting down...');
    if (context) await context.close().catch(() => {});
    if (browser) await browser.close().catch(() => {});
    server.close();
    process.exit(0);
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

// Idle check
setInterval(async () => {
    if (Date.now() - lastActivity > IDLE_TIMEOUT) {
        console.log('[Playwright] Idle timeout, closing browser...');
        if (page && !page.isClosed()) {
            await page.close().catch(() => {});
            page = null;
        }
        activeNavigationScope = null;
        blockedNavigationUrl = null;
        if (context) {
            await context.close().catch(() => {});
            context = null;
        }
        if (browser) {
            await browser.close().catch(() => {});
            browser = null;
        }
    }
}, 60000);

// Start server
server.listen(PORT, '127.0.0.1', () => {
    console.log(`[Playwright] Server listening on http://127.0.0.1:${PORT}`);
    console.log(`[Playwright] Screenshot directory: ${SCREENSHOT_DIR}`);
});
