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
 *   GET  /health     - Health check
 *   POST /close      - Close current page
 *   POST /shutdown   - Shutdown server
 */

const http = require('http');
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.argv[2]) || 9223;
const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/playwright-screenshots';

// Ensure screenshot directory exists
if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

let browser = null;
let context = null;
let page = null;
let lastActivity = Date.now();

// Auto-restart browser if idle for too long (30 minutes)
const IDLE_TIMEOUT = 30 * 60 * 1000;

/**
 * Initialize or get browser instance
 */
async function getBrowser() {
    if (!browser || !browser.isConnected()) {
        console.log('[Playwright] Launching browser...');
        browser = await chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-blink-features=AutomationControlled',
            ],
        });
        console.log('[Playwright] Browser launched');
    }
    return browser;
}

/**
 * Get or create browser context
 */
async function getContext() {
    const b = await getBrowser();

    if (!context) {
        console.log('[Playwright] Creating browser context...');
        context = await b.newContext({
            viewport: { width: 1920, height: 1080 },
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            locale: 'en-US',
            timezoneId: 'America/New_York',
            geolocation: { longitude: -74.006, latitude: 40.7128 },
            permissions: ['geolocation'],
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
async function getPage() {
    const ctx = await getContext();

    if (!page || page.isClosed()) {
        console.log('[Playwright] Creating new page...');
        page = await ctx.newPage();
        console.log('[Playwright] Page created');
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
            return sendJson(res, 200, {
                status: 'ok',
                browser: browser?.isConnected() ? 'connected' : 'disconnected',
                page: page && !page.isClosed() ? 'active' : 'none',
                uptime: process.uptime(),
                lastActivity: new Date(lastActivity).toISOString(),
                engine: 'playwright',
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

        // Navigate to URL
        if (url === '/navigate' && method === 'POST') {
            const { url: targetUrl, waitUntil = 'networkidle', timeout = 30000 } = body;

            if (!targetUrl) {
                return sendJson(res, 400, { error: 'url is required' });
            }

            const p = await getPage();
            console.log(`[Playwright] Navigating to: ${targetUrl}`);

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
            });
        }

        // Take screenshot
        if (url === '/screenshot' && method === 'POST') {
            const { name, selector, fullPage = false } = body;

            const p = await getPage();
            const filename = name || `screenshot-${Date.now()}`;
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
            return sendJson(res, 200, { success: true });
        }

        // Get cookies
        if (url === '/cookies' && method === 'GET') {
            const ctx = await getContext();
            const cookies = await ctx.cookies();
            return sendJson(res, 200, { success: true, cookies });
        }

        // Set cookies
        if (url === '/cookies' && method === 'POST') {
            const { cookies } = body;
            if (!cookies || !Array.isArray(cookies)) {
                return sendJson(res, 400, { error: 'cookies array is required' });
            }

            const ctx = await getContext();
            await ctx.addCookies(cookies);
            return sendJson(res, 200, { success: true });
        }

        // Not found
        return sendJson(res, 404, { error: 'Not found' });

    } catch (error) {
        console.error('[Playwright] Error:', error.message);
        return sendJson(res, 500, {
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
