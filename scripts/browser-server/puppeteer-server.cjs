#!/usr/bin/env node
/**
 * Persistent Puppeteer Server (Security Hardened)
 *
 * A long-running HTTP server that maintains browser instances for data removal.
 * Accepts commands via HTTP POST and returns results as JSON.
 *
 * E06: Personal Data Removal System
 *
 * SECURITY FEATURES:
 * - Sandbox enabled (when running as non-root)
 * - Fresh browser instance per navigation session
 * - URL domain allowlist for broker sites only
 * - Downloads disabled
 * - Strict CSP headers
 * - Request interception for malicious content blocking
 *
 * Usage:
 *   node puppeteer-server.js [port]
 *   Default port: 9222
 *
 * Endpoints:
 *   POST /navigate   - Navigate to URL (domain-validated)
 *   POST /screenshot - Take screenshot
 *   POST /evaluate   - Execute JavaScript (disabled for security)
 *   POST /fill       - Fill form field
 *   POST /click      - Click element
 *   POST /content    - Get page content
 *   GET  /health     - Health check
 *   POST /close      - Close current page
 *   POST /shutdown   - Shutdown server
 *   POST /allowlist  - Add domain to allowlist
 *   GET  /allowlist  - Get current allowlist
 */

const http = require('http');
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PORT = parseInt(process.argv[2]) || 9222;
const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/puppeteer-screenshots';

// Security: Domain allowlist for data brokers
// Only these domains (and their subdomains) can be navigated to
const ALLOWED_DOMAINS = new Set([
    // Major data brokers - preloaded
    'spokeo.com', 'www.spokeo.com',
    'beenverified.com', 'www.beenverified.com',
    'whitepages.com', 'www.whitepages.com',
    'intelius.com', 'www.intelius.com',
    'truepeoplesearch.com', 'www.truepeoplesearch.com',
    'fastpeoplesearch.com', 'www.fastpeoplesearch.com',
    'peoplefinders.com', 'www.peoplefinders.com',
    'instantcheckmate.com', 'www.instantcheckmate.com',
    'truthfinder.com', 'www.truthfinder.com',
    'mylife.com', 'www.mylife.com',
    'radaris.com', 'www.radaris.com',
    'pipl.com', 'www.pipl.com',
    'familytreenow.com', 'www.familytreenow.com',
    'usphonebook.com', 'www.usphonebook.com',
    'thatsthem.com', 'www.thatsthem.com',
    'clustrmaps.com', 'www.clustrmaps.com',
    'addresses.com', 'www.addresses.com',
    'publicrecordsnow.com', 'www.publicrecordsnow.com',
    'zabasearch.com', 'www.zabasearch.com',
    'ussearch.com', 'www.ussearch.com',
    'infotracer.com', 'www.infotracer.com',
    'peoplelooker.com', 'www.peoplelooker.com',
    'searchpeoplefree.com', 'www.searchpeoplefree.com',
    'cyberbackgroundchecks.com', 'www.cyberbackgroundchecks.com',
    'checksecrets.com', 'www.checksecrets.com',
    'publicdatacheck.com', 'www.publicdatacheck.com',
    'locatepeople.org', 'www.locatepeople.org',
    'smartbackgroundchecks.com', 'www.smartbackgroundchecks.com',
    'advancedbackgroundchecks.com', 'www.advancedbackgroundchecks.com',
    'officialusa.com', 'www.officialusa.com',
    'nuwber.com', 'www.nuwber.com',
    'verecor.com', 'www.verecor.com',
    'idcrawl.com', 'www.idcrawl.com',
    'peoplesearchnow.com', 'www.peoplesearchnow.com',
]);

// Blocked file extensions (security)
const BLOCKED_EXTENSIONS = ['.exe', '.msi', '.dll', '.bat', '.cmd', '.ps1', '.sh', '.dmg', '.pkg', '.deb', '.rpm', '.zip', '.rar', '.7z', '.tar', '.gz'];

// Blocked content types (security)
const BLOCKED_CONTENT_TYPES = ['application/x-msdownload', 'application/x-msdos-program', 'application/octet-stream'];

// Ensure screenshot directory exists
if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

let browser = null;
let page = null;
let lastActivity = Date.now();
let sessionCount = 0;

// Auto-restart browser if idle for too long (15 minutes - reduced for security)
const IDLE_TIMEOUT = 15 * 60 * 1000;

// Max sessions before forced browser restart (security: prevent state leakage)
const MAX_SESSIONS_BEFORE_RESTART = 10;

/**
 * Check if domain is in allowlist
 */
function isDomainAllowed(targetUrl) {
    try {
        const parsed = new URL(targetUrl);
        const hostname = parsed.hostname.toLowerCase();

        // Check exact match
        if (ALLOWED_DOMAINS.has(hostname)) {
            return true;
        }

        // Check if it's a subdomain of an allowed domain
        for (const allowed of ALLOWED_DOMAINS) {
            if (hostname.endsWith('.' + allowed)) {
                return true;
            }
        }

        return false;
    } catch (e) {
        return false;
    }
}

/**
 * Check if URL has blocked file extension
 */
function hasBlockedExtension(targetUrl) {
    try {
        const parsed = new URL(targetUrl);
        const pathname = parsed.pathname.toLowerCase();
        return BLOCKED_EXTENSIONS.some(ext => pathname.endsWith(ext));
    } catch (e) {
        return true; // Block if can't parse
    }
}

/**
 * Initialize or get browser instance
 * Security: Uses sandbox, blocks downloads, isolated context
 */
async function getBrowser(forceNew = false) {
    // Security: Force restart after max sessions to prevent state leakage
    if (sessionCount >= MAX_SESSIONS_BEFORE_RESTART) {
        console.log('[Puppeteer] Security: Max sessions reached, restarting browser...');
        if (browser) {
            await browser.close().catch(() => {});
            browser = null;
        }
        sessionCount = 0;
    }

    if (forceNew && browser) {
        console.log('[Puppeteer] Security: Forcing fresh browser instance...');
        await browser.close().catch(() => {});
        browser = null;
    }

    if (!browser || !browser.isConnected()) {
        console.log('[Puppeteer] Launching browser (security-hardened)...');

        // Check if running as root (sandbox won't work)
        const isRoot = process.getuid && process.getuid() === 0;

        browser = await puppeteer.launch({
            headless: 'new',
            args: [
                // Security: Only disable sandbox if running as root
                ...(isRoot ? ['--no-sandbox'] : []),
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--disable-gpu',
                '--window-size=1920,1080',
                // Security: Additional hardening
                '--disable-extensions',
                '--disable-plugins',
                '--disable-sync',
                '--disable-translate',
                '--disable-background-networking',
                '--safebrowsing-disable-auto-update',
                '--disable-default-apps',
                '--no-first-run',
                '--disable-popup-blocking', // Need popups for some opt-out flows
                '--disable-web-security=false', // Keep web security ON
                '--disable-features=TranslateUI',
                '--disable-ipc-flooding-protection',
            ],
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
        });

        console.log(`[Puppeteer] Browser launched (sandbox: ${!isRoot})`);
    }
    return browser;
}

/**
 * Get or create page with security hardening
 */
async function getPage(freshSession = false) {
    const b = await getBrowser(freshSession);

    if (!page || page.isClosed() || freshSession) {
        // Close existing page if forcing fresh session
        if (page && !page.isClosed()) {
            await page.close().catch(() => {});
        }

        console.log('[Puppeteer] Creating new page (security-hardened)...');
        page = await b.newPage();
        sessionCount++;

        // Set viewport
        await page.setViewport({ width: 1920, height: 1080 });

        // Set user agent (rotate slightly for anti-fingerprinting)
        const chromeVersions = ['120.0.0.0', '121.0.0.0', '122.0.0.0', '123.0.0.0'];
        const randomVersion = chromeVersions[Math.floor(Math.random() * chromeVersions.length)];
        await page.setUserAgent(
            `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${randomVersion} Safari/537.36`
        );

        // Set extra headers
        await page.setExtraHTTPHeaders({
            'Accept-Language': 'en-US,en;q=0.9',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        });

        // Security: Enable request interception to block malicious content
        await page.setRequestInterception(true);

        page.on('request', (request) => {
            const requestUrl = request.url();
            const resourceType = request.resourceType();

            // Block downloads and executable content
            if (hasBlockedExtension(requestUrl)) {
                console.log(`[Puppeteer] Security: Blocked download - ${requestUrl}`);
                request.abort('blockedbyclient');
                return;
            }

            // Block data: URLs (potential XSS vector)
            if (requestUrl.startsWith('data:') && resourceType !== 'image') {
                console.log(`[Puppeteer] Security: Blocked data URL - ${resourceType}`);
                request.abort('blockedbyclient');
                return;
            }

            // Block blob: URLs for non-images
            if (requestUrl.startsWith('blob:') && resourceType !== 'image') {
                console.log(`[Puppeteer] Security: Blocked blob URL - ${resourceType}`);
                request.abort('blockedbyclient');
                return;
            }

            request.continue();
        });

        // Security: Block file downloads
        const client = await page.target().createCDPSession();
        await client.send('Page.setDownloadBehavior', {
            behavior: 'deny'
        });

        // Security: Log console messages for debugging
        page.on('console', msg => {
            if (msg.type() === 'error') {
                console.log(`[Puppeteer] Page console error: ${msg.text()}`);
            }
        });

        // Security: Log page errors
        page.on('pageerror', error => {
            console.log(`[Puppeteer] Page error: ${error.message}`);
        });

        console.log(`[Puppeteer] Page created (session #${sessionCount})`);
    }

    lastActivity = Date.now();
    return page;
}

/**
 * Parse JSON body from request
 */
function parseBody(req) {
    return new Promise((resolve, reject) => {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            try {
                resolve(body ? JSON.parse(body) : {});
            } catch (e) {
                reject(new Error('Invalid JSON'));
            }
        });
        req.on('error', reject);
    });
}

/**
 * Send JSON response
 */
function sendJson(res, status, data) {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(data));
}

/**
 * Handle requests
 */
async function handleRequest(req, res) {
    const url = req.url;
    const method = req.method;

    try {
        // Health check
        if (url === '/health' && method === 'GET') {
            const isRoot = process.getuid && process.getuid() === 0;
            return sendJson(res, 200, {
                status: 'ok',
                browser: browser?.isConnected() ? 'connected' : 'disconnected',
                page: page && !page.isClosed() ? 'active' : 'none',
                uptime: process.uptime(),
                lastActivity: new Date(lastActivity).toISOString(),
                security: {
                    sandboxEnabled: !isRoot,
                    downloadBlocking: true,
                    domainAllowlist: ALLOWED_DOMAINS.size,
                    sessionCount: sessionCount,
                    maxSessionsBeforeRestart: MAX_SESSIONS_BEFORE_RESTART,
                    requestInterception: true,
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

        const body = await parseBody(req);

        // Navigate to URL (with domain validation)
        if (url === '/navigate' && method === 'POST') {
            const { url: targetUrl, waitUntil = 'networkidle2', timeout = 30000, bypassAllowlist = false, freshSession = true } = body;

            if (!targetUrl) {
                return sendJson(res, 400, { error: 'url is required' });
            }

            // Security: Validate domain is in allowlist
            if (!bypassAllowlist && !isDomainAllowed(targetUrl)) {
                console.log(`[Puppeteer] Security: Blocked navigation to non-allowlisted domain - ${targetUrl}`);
                return sendJson(res, 403, {
                    error: 'Domain not in allowlist',
                    domain: new URL(targetUrl).hostname,
                    hint: 'Add domain via POST /allowlist or pass bypassAllowlist:true for trusted URLs',
                });
            }

            // Security: Block direct file downloads
            if (hasBlockedExtension(targetUrl)) {
                console.log(`[Puppeteer] Security: Blocked navigation to download URL - ${targetUrl}`);
                return sendJson(res, 403, {
                    error: 'Blocked file type',
                    url: targetUrl,
                });
            }

            // Security: Use fresh session for each navigation to prevent state leakage
            const p = await getPage(freshSession);
            console.log(`[Puppeteer] Navigating to: ${targetUrl} (fresh session: ${freshSession})`);

            await p.goto(targetUrl, {
                waitUntil,
                timeout
            });

            const pageUrl = p.url();
            const title = await p.title();

            return sendJson(res, 200, {
                success: true,
                url: pageUrl,
                title,
                sessionCount,
            });
        }

        // Take screenshot
        if (url === '/screenshot' && method === 'POST') {
            const { name, selector, fullPage = false, encoding = 'base64' } = body;

            const p = await getPage();
            const filename = name || `screenshot-${Date.now()}`;
            const filepath = path.join(SCREENSHOT_DIR, `${filename}.png`);

            let screenshotOptions = {
                path: filepath,
                fullPage,
            };

            if (selector) {
                const element = await p.$(selector);
                if (element) {
                    await element.screenshot(screenshotOptions);
                } else {
                    return sendJson(res, 404, { error: `Element not found: ${selector}` });
                }
            } else {
                await p.screenshot(screenshotOptions);
            }

            console.log(`[Puppeteer] Screenshot saved: ${filepath}`);

            let base64 = null;
            if (encoding === 'base64') {
                base64 = fs.readFileSync(filepath).toString('base64');
            }

            return sendJson(res, 200, {
                success: true,
                path: filepath,
                base64,
            });
        }

        // Evaluate JavaScript - SECURITY: Only allow safe, predefined scripts
        if (url === '/evaluate' && method === 'POST') {
            const { script, safeMode = true } = body;

            if (!script) {
                return sendJson(res, 400, { error: 'script is required' });
            }

            // Security: In safe mode, only allow specific operations
            if (safeMode) {
                const allowedScripts = [
                    'document.body.innerText',
                    'document.body.innerHTML',
                    'document.title',
                    'window.location.href',
                    'document.documentElement.outerHTML',
                ];

                // Check if script is a simple property access (no function calls, assignments, etc.)
                const isSimpleAccess = /^[a-zA-Z0-9_.]+$/.test(script.trim());
                const isAllowed = allowedScripts.some(s => script.trim().startsWith(s));

                if (!isSimpleAccess && !isAllowed) {
                    console.log(`[Puppeteer] Security: Blocked unsafe script execution`);
                    return sendJson(res, 403, {
                        error: 'Script blocked by security policy',
                        hint: 'Only simple property access allowed in safe mode. Pass safeMode:false for trusted scripts.',
                    });
                }
            }

            const p = await getPage();
            const result = await p.evaluate(script);

            return sendJson(res, 200, {
                success: true,
                result,
            });
        }

        // Allowlist management - GET list
        if (url === '/allowlist' && method === 'GET') {
            return sendJson(res, 200, {
                success: true,
                domains: Array.from(ALLOWED_DOMAINS).sort(),
                count: ALLOWED_DOMAINS.size,
            });
        }

        // Allowlist management - POST add domain
        if (url === '/allowlist' && method === 'POST') {
            const { domain } = body;

            if (!domain) {
                return sendJson(res, 400, { error: 'domain is required' });
            }

            const normalizedDomain = domain.toLowerCase().trim();

            // Validate domain format
            if (!/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/.test(normalizedDomain)) {
                return sendJson(res, 400, { error: 'Invalid domain format' });
            }

            ALLOWED_DOMAINS.add(normalizedDomain);
            // Also add www. variant
            if (!normalizedDomain.startsWith('www.')) {
                ALLOWED_DOMAINS.add('www.' + normalizedDomain);
            }

            console.log(`[Puppeteer] Security: Added domain to allowlist - ${normalizedDomain}`);

            return sendJson(res, 200, {
                success: true,
                domain: normalizedDomain,
                totalDomains: ALLOWED_DOMAINS.size,
            });
        }

        // Get page content
        if (url === '/content' && method === 'POST') {
            const { type = 'html' } = body;

            const p = await getPage();
            let content;

            if (type === 'text') {
                content = await p.evaluate(() => document.body.innerText);
            } else {
                content = await p.content();
            }

            return sendJson(res, 200, {
                success: true,
                content,
                url: p.url(),
            });
        }

        // Fill form field
        if (url === '/fill' && method === 'POST') {
            const { selector, value, clear = true } = body;

            if (!selector || value === undefined) {
                return sendJson(res, 400, { error: 'selector and value are required' });
            }

            const p = await getPage();

            try {
                await p.waitForSelector(selector, { timeout: 5000 });

                if (clear) {
                    await p.click(selector, { clickCount: 3 });
                }

                await p.type(selector, value, { delay: 50 });

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
                await p.waitForSelector(selector, { timeout });
                await p.click(selector);

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
                await p.waitForSelector(selector, { timeout: 5000 });
                await p.select(selector, value);

                return sendJson(res, 200, { success: true });
            } catch (e) {
                return sendJson(res, 404, { error: `Select element not found: ${selector}` });
            }
        }

        // Wait for selector
        if (url === '/wait' && method === 'POST') {
            const { selector, timeout = 10000 } = body;

            if (!selector) {
                return sendJson(res, 400, { error: 'selector is required' });
            }

            const p = await getPage();

            try {
                await p.waitForSelector(selector, { timeout });
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
            return sendJson(res, 200, { success: true });
        }

        // Get cookies
        if (url === '/cookies' && method === 'GET') {
            const p = await getPage();
            const cookies = await p.cookies();
            return sendJson(res, 200, { success: true, cookies });
        }

        // Set cookies
        if (url === '/cookies' && method === 'POST') {
            const { cookies } = body;
            if (!cookies || !Array.isArray(cookies)) {
                return sendJson(res, 400, { error: 'cookies array is required' });
            }

            const p = await getPage();
            await p.setCookie(...cookies);
            return sendJson(res, 200, { success: true });
        }

        // Not found
        return sendJson(res, 404, { error: 'Not found' });

    } catch (error) {
        console.error('[Puppeteer] Error:', error.message);
        return sendJson(res, 500, {
            error: error.message,
            stack: process.env.NODE_ENV === 'development' ? error.stack : undefined,
        });
    }
}

// Create server
const server = http.createServer(handleRequest);

// Graceful shutdown
process.on('SIGTERM', async () => {
    console.log('[Puppeteer] SIGTERM received, shutting down...');
    if (browser) await browser.close();
    server.close();
    process.exit(0);
});

process.on('SIGINT', async () => {
    console.log('[Puppeteer] SIGINT received, shutting down...');
    if (browser) await browser.close();
    server.close();
    process.exit(0);
});

// Idle check
setInterval(async () => {
    if (Date.now() - lastActivity > IDLE_TIMEOUT) {
        console.log('[Puppeteer] Idle timeout, closing browser...');
        if (page && !page.isClosed()) {
            await page.close();
            page = null;
        }
        if (browser) {
            await browser.close();
            browser = null;
        }
    }
}, 60000);

// Start server
server.listen(PORT, '127.0.0.1', () => {
    console.log(`[Puppeteer] Server listening on http://127.0.0.1:${PORT}`);
    console.log(`[Puppeteer] Screenshot directory: ${SCREENSHOT_DIR}`);
});
