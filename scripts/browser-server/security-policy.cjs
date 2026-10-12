'use strict';

const crypto = require('crypto');
const dns = require('node:dns');
const net = require('node:net');
const ipaddr = require('ipaddr.js');

const DEFAULT_MAX_BODY_BYTES = 1024 * 1024;
const DEFAULT_MAX_CONTENT_WINDOW_CHARS = 200000;
const DEFAULT_DNS_LOOKUP_TIMEOUT_MS = 3000;

function requireAutomationToken(env = process.env) {
    const token = String(env.BROWSER_AUTOMATION_TOKEN || '');
    if (token.length < 32) {
        throw new Error('BROWSER_AUTOMATION_TOKEN must contain at least 32 characters');
    }

    return token;
}

function requestPolicyError(req, port, token) {
    const host = String(req.headers.host || '').toLowerCase();
    const allowedHosts = new Set([
        `127.0.0.1:${port}`,
        `localhost:${port}`,
    ]);

    if (!allowedHosts.has(host)) {
        return { status: 403, error: 'Host is not allowed' };
    }

    const origin = String(req.headers.origin || '');
    if (origin !== '') {
        try {
            const parsed = new URL(origin);
            if (!['127.0.0.1', 'localhost'].includes(parsed.hostname.toLowerCase())) {
                return { status: 403, error: 'Origin is not allowed' };
            }
        } catch {
            return { status: 403, error: 'Origin is not allowed' };
        }
    }

    const authorization = String(req.headers.authorization || '');
    const prefix = 'Bearer ';
    if (!authorization.startsWith(prefix)) {
        return { status: 401, error: 'Authentication required' };
    }

    const supplied = Buffer.from(authorization.slice(prefix.length));
    const expected = Buffer.from(token);
    if (supplied.length !== expected.length || !crypto.timingSafeEqual(supplied, expected)) {
        return { status: 401, error: 'Authentication required' };
    }

    return null;
}

function parseJsonBody(req, maxBytes = DEFAULT_MAX_BODY_BYTES) {
    return new Promise((resolve, reject) => {
        let body = '';
        let bytes = 0;
        let settled = false;

        req.on('data', chunk => {
            if (settled) return;

            bytes += Buffer.byteLength(chunk);
            if (bytes > maxBytes) {
                settled = true;
                reject(Object.assign(new Error('Request body too large'), { statusCode: 413 }));
                req.destroy();
                return;
            }

            body += chunk;
        });
        req.on('end', () => {
            if (settled) return;

            try {
                settled = true;
                resolve(body ? JSON.parse(body) : {});
            } catch {
                settled = true;
                reject(Object.assign(new Error('Invalid JSON'), { statusCode: 400 }));
            }
        });
        req.on('error', error => {
            if (settled) return;
            settled = true;
            reject(error);
        });
    });
}

function sendJson(res, status, data) {
    res.writeHead(status, {
        'Content-Type': 'application/json',
        'Cache-Control': 'no-store',
        'X-Content-Type-Options': 'nosniff',
        'Content-Security-Policy': "default-src 'none'; frame-ancestors 'none'",
    });
    res.end(JSON.stringify(data));
}

function safeOutputName(value, fallback) {
    const name = String(value || fallback || '')
        .trim()
        .replace(/[^A-Za-z0-9._-]+/g, '-')
        .replace(/^[.-]+/, '')
        .slice(0, 120);

    return name || fallback;
}

function normalizeDomain(value) {
    const domain = String(value || '').trim().toLowerCase();
    if (isIpLiteralOrLocalhost(domain)
        || !/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/.test(domain)
        || domain.includes('..')) {
        return null;
    }

    return domain;
}

function isIpLiteralOrLocalhost(value) {
    const raw = String(value || '').trim();
    if (raw === '') return false;

    const direct = raw.replace(/^\[|\]$/g, '').replace(/\.$/, '').toLowerCase();
    if (net.isIP(direct) !== 0 || direct === 'localhost' || direct.endsWith('.localhost')) {
        return true;
    }

    try {
        const canonical = new URL(`http://${raw}/`).hostname
            .replace(/^\[|\]$/g, '')
            .replace(/\.$/, '')
            .toLowerCase();

        return net.isIP(canonical) !== 0
            || canonical === 'localhost'
            || canonical.endsWith('.localhost');
    } catch {
        return false;
    }
}

function isDomainAllowed(targetUrl, allowedDomains) {
    try {
        const parsed = new URL(targetUrl);
        if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) {
            return false;
        }

        const hostname = parsed.hostname.toLowerCase();
        if (isIpLiteralOrLocalhost(hostname)) {
            return false;
        }
        for (const allowed of allowedDomains) {
            if (hostname === allowed || hostname.endsWith(`.${allowed}`)) {
                return true;
            }
        }
    } catch {
        return false;
    }

    return false;
}

function navigationScopeForTarget(targetUrl, allowedDomains, allowUnlistedInitial = false) {
    let parsed;
    try {
        parsed = new URL(targetUrl);
    } catch {
        return null;
    }

    if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) {
        return null;
    }

    const hostname = parsed.hostname.toLowerCase();
    if (isIpLiteralOrLocalhost(hostname)) {
        return null;
    }
    const matchingDomains = Array.from(allowedDomains)
        .filter(domain => hostname === domain || hostname.endsWith(`.${domain}`));

    if (matchingDomains.length === 0 && !allowUnlistedInitial) {
        return null;
    }

    const scope = new Set(matchingDomains.length > 0 ? matchingDomains : [hostname]);
    scope.add(hostname);
    if (hostname.startsWith('www.') && hostname.length > 4) {
        scope.add(hostname.slice(4));
    }

    return scope;
}

function isPublicUnicastAddress(value) {
    try {
        return ipaddr.process(String(value || '')).range() === 'unicast';
    } catch {
        return false;
    }
}

async function resolvePublicNavigationTarget(
    targetUrl,
    lookup = dns.promises.lookup,
    timeoutMs = DEFAULT_DNS_LOOKUP_TIMEOUT_MS,
) {
    let parsed;
    try {
        parsed = new URL(targetUrl);
    } catch {
        return { allowed: false, reason: 'invalid_url', addressCount: 0 };
    }

    if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) {
        return { allowed: false, reason: 'invalid_url_policy', addressCount: 0 };
    }

    const hostname = parsed.hostname.toLowerCase();
    if (isIpLiteralOrLocalhost(hostname)) {
        return { allowed: false, reason: 'ip_literal_or_localhost', addressCount: 0 };
    }

    const safeTimeoutMs = Number.isSafeInteger(timeoutMs) && timeoutMs > 0
        ? Math.min(timeoutMs, DEFAULT_DNS_LOOKUP_TIMEOUT_MS)
        : DEFAULT_DNS_LOOKUP_TIMEOUT_MS;
    let timeout;

    try {
        const timeoutPromise = new Promise((resolve) => {
            timeout = setTimeout(
                () => resolve({ timedOut: true }),
                safeTimeoutMs,
            );
        });
        const lookupPromise = Promise.resolve()
            .then(() => lookup(hostname, { all: true, order: 'verbatim' }))
            .then(addresses => ({ addresses }))
            .catch(() => ({ failed: true }));
        const result = await Promise.race([lookupPromise, timeoutPromise]);

        if (result.timedOut) {
            return { allowed: false, reason: 'dns_timeout', addressCount: 0 };
        }
        if (result.failed) {
            return { allowed: false, reason: 'dns_lookup_failed', addressCount: 0 };
        }

        const addresses = Array.isArray(result.addresses) ? result.addresses : [];
        if (addresses.length === 0) {
            return { allowed: false, reason: 'dns_no_addresses', addressCount: 0 };
        }

        const allPublic = addresses.every(entry => isPublicUnicastAddress(entry?.address));
        return {
            allowed: allPublic,
            reason: allPublic ? 'public_unicast' : 'dns_non_public_address',
            addressCount: addresses.length,
        };
    } finally {
        if (timeout) clearTimeout(timeout);
    }
}

function contentWindow(content, offset = 0, limit = DEFAULT_MAX_CONTENT_WINDOW_CHARS) {
    const text = String(content ?? '');
    const safeOffset = Number.isSafeInteger(offset) && offset >= 0 ? offset : 0;
    const requestedLimit = Number.isSafeInteger(limit) && limit > 0
        ? limit
        : DEFAULT_MAX_CONTENT_WINDOW_CHARS;
    const safeLimit = Math.min(requestedLimit, DEFAULT_MAX_CONTENT_WINDOW_CHARS);
    const window = text.slice(safeOffset, safeOffset + safeLimit);
    const endOffset = safeOffset + window.length;
    const complete = endOffset >= text.length;

    return {
        content: window,
        offset: safeOffset,
        limit: safeLimit,
        nextOffset: complete ? null : endOffset,
        complete,
        truncated: !complete,
        totalChars: text.length,
    };
}

module.exports = {
    DEFAULT_DNS_LOOKUP_TIMEOUT_MS,
    DEFAULT_MAX_BODY_BYTES,
    DEFAULT_MAX_CONTENT_WINDOW_CHARS,
    contentWindow,
    parseJsonBody,
    isDomainAllowed,
    isIpLiteralOrLocalhost,
    isPublicUnicastAddress,
    navigationScopeForTarget,
    normalizeDomain,
    requestPolicyError,
    requireAutomationToken,
    resolvePublicNavigationTarget,
    safeOutputName,
    sendJson,
};
