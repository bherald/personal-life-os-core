'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { Readable } = require('node:stream');
const test = require('node:test');
const {
    DEFAULT_MAX_CONTENT_WINDOW_CHARS,
    contentWindow,
    isDomainAllowed,
    isIpLiteralOrLocalhost,
    isPublicUnicastAddress,
    navigationScopeForTarget,
    normalizeDomain,
    parseJsonBody,
    requestPolicyError,
    requireAutomationToken,
    resolvePublicNavigationTarget,
    safeOutputName,
} = require('./security-policy.cjs');

const token = 'a'.repeat(64);

function request(headers = {}) {
    return { headers };
}

test('requires a strong automation token', () => {
    assert.throws(() => requireAutomationToken({}), /at least 32/);
    assert.throws(() => requireAutomationToken({ BROWSER_AUTOMATION_TOKEN: 'short' }), /at least 32/);
    assert.equal(requireAutomationToken({ BROWSER_AUTOMATION_TOKEN: token }), token);
});

test('accepts authenticated loopback requests without a browser origin', () => {
    assert.equal(requestPolicyError(request({
        host: '127.0.0.1:9223',
        authorization: `Bearer ${token}`,
    }), 9223, token), null);
});

test('rejects missing auth, DNS rebinding hosts, and external origins', () => {
    assert.equal(requestPolicyError(request({ host: '127.0.0.1:9223' }), 9223, token)?.status, 401);
    assert.equal(requestPolicyError(request({
        host: 'attacker.example:9223',
        authorization: `Bearer ${token}`,
    }), 9223, token)?.status, 403);
    assert.equal(requestPolicyError(request({
        host: 'localhost:9223',
        origin: 'https://attacker.example',
        authorization: `Bearer ${token}`,
    }), 9223, token)?.status, 403);
});

test('permits authenticated loopback browser origins', () => {
    assert.equal(requestPolicyError(request({
        host: 'localhost:9223',
        origin: 'http://localhost:5173',
        authorization: `Bearer ${token}`,
    }), 9223, token), null);
});

test('normalizes artifact names without path traversal', () => {
    assert.equal(safeOutputName('../../private report', 'capture'), 'private-report');
    assert.equal(safeOutputName('valid_name-1', 'capture'), 'valid_name-1');
    assert.equal(safeOutputName('...', 'capture'), 'capture');
});

test('validates navigation schemes, credentials, domains, and subdomains', () => {
    const domains = new Set(['example.com']);

    assert.equal(isDomainAllowed('https://example.com/path', domains), true);
    assert.equal(isDomainAllowed('https://www.example.com/path', domains), true);
    assert.equal(isDomainAllowed('https://notexample.com/path', domains), false);
    assert.equal(isDomainAllowed('file:///etc/passwd', domains), false);
    assert.equal(isDomainAllowed('https://user:secret@example.com/', domains), false);
});

test('blocks browser-canonical IP literals and localhost before allowlist handling', () => {
    const blocked = [
        '127.0.0.1',
        '2130706433',
        '0x7f000001',
        '0177.0.0.1',
        '127.1',
        '127.0.1',
        '[::1]',
        '%31%32%37.0.0.1',
        '１２７.０.０.１',
        '127。0。0。1',
        'localhost',
        'api.localhost',
    ];

    for (const hostname of blocked) {
        assert.equal(isIpLiteralOrLocalhost(hostname), true, hostname);
        assert.equal(normalizeDomain(hostname), null, hostname);
        assert.equal(navigationScopeForTarget(`http://${hostname}/`, new Set(), true), null, hostname);
    }

    assert.equal(isIpLiteralOrLocalhost('127.0.0.1.evil.example'), false);
    assert.equal(isIpLiteralOrLocalhost('example.com'), false);
    assert.equal(normalizeDomain('example.com'), 'example.com');
});

test('IP literals stay blocked even when an existing allowlist contains them', () => {
    const allowed = new Set(['127.0.0.1', 'example.com']);

    assert.equal(isDomainAllowed('http://127.0.0.1/admin', allowed), false);
    assert.equal(isDomainAllowed('http://2130706433/admin', allowed), false);
    assert.equal(isDomainAllowed('https://example.com/public', allowed), true);
});

test('classifies only public unicast IP addresses as navigation-safe', () => {
    for (const address of [
        '0.0.0.0',
        '10.0.0.1',
        '100.64.0.1',
        '127.0.0.1',
        '169.254.169.254',
        '192.0.2.1',
        '224.0.0.1',
        '::',
        '::1',
        'fc00::1',
        'fe80::1',
        'ff00::1',
        '2001:db8::1',
        '::ffff:127.0.0.1',
    ]) {
        assert.equal(isPublicUnicastAddress(address), false, address);
    }

    assert.equal(isPublicUnicastAddress('8.8.8.8'), true);
    assert.equal(isPublicUnicastAddress('2606:4700:4700::1111'), true);
});

test('requires every resolved IPv4 and IPv6 address to be public unicast', async () => {
    const optionsSeen = [];
    const publicResult = await resolvePublicNavigationTarget(
        'https://example.com/path',
        async (hostname, options) => {
            assert.equal(hostname, 'example.com');
            optionsSeen.push(options);
            return [
                { address: '93.184.216.34', family: 4 },
                { address: '2606:2800:220:1:248:1893:25c8:1946', family: 6 },
            ];
        },
    );
    assert.deepEqual(publicResult, {
        allowed: true,
        reason: 'public_unicast',
        addressCount: 2,
    });
    assert.deepEqual(optionsSeen, [{ all: true, order: 'verbatim' }]);

    const mixedResult = await resolvePublicNavigationTarget(
        'https://example.com/path',
        async () => [
            { address: '93.184.216.34', family: 4 },
            { address: '169.254.169.254', family: 4 },
        ],
    );
    assert.deepEqual(mixedResult, {
        allowed: false,
        reason: 'dns_non_public_address',
        addressCount: 2,
    });
});

test('DNS target policy fails closed on lookup errors, empty answers, and timeouts', async () => {
    assert.equal((await resolvePublicNavigationTarget(
        'https://missing.example',
        async () => { throw new Error('lookup failed'); },
    )).reason, 'dns_lookup_failed');
    assert.equal((await resolvePublicNavigationTarget(
        'https://empty.example',
        async () => [],
    )).reason, 'dns_no_addresses');
    assert.equal((await resolvePublicNavigationTarget(
        'https://slow.example',
        async () => new Promise(() => {}),
        5,
    )).reason, 'dns_timeout');
    assert.equal((await resolvePublicNavigationTarget(
        'http://127.0.0.1/',
        async () => [{ address: '8.8.8.8', family: 4 }],
    )).reason, 'ip_literal_or_localhost');
});

test('scopes a navigation to its matching domain family instead of the global allowlist', () => {
    const domains = new Set(['example.com', 'another.example']);
    const scope = navigationScopeForTarget('https://www.example.com/start', domains);

    assert.deepEqual(Array.from(scope).sort(), ['example.com', 'www.example.com']);
    assert.equal(isDomainAllowed('https://account.example.com/next', scope), true);
    assert.equal(isDomainAllowed('https://another.example/escape', scope), false);
    assert.equal(navigationScopeForTarget('https://blocked.example/start', domains), null);
});

test('trusted navigation bypass admits only the initial origin family', () => {
    const scope = navigationScopeForTarget('https://research.example/start', new Set(), true);

    assert.deepEqual(Array.from(scope), ['research.example']);
    assert.equal(isDomainAllowed('https://research.example/next', scope), true);
    assert.equal(isDomainAllowed('https://attacker.example/redirect', scope), false);
    assert.equal(navigationScopeForTarget('file:///etc/passwd', new Set(), true), null);
    assert.equal(navigationScopeForTarget('https://user:secret@example.com', new Set(), true), null);
});

test('returns lossless bounded content windows with explicit continuation offsets', () => {
    const content = 'x'.repeat(DEFAULT_MAX_CONTENT_WINDOW_CHARS + 25);
    const first = contentWindow(content);
    const second = contentWindow(content, first.nextOffset, DEFAULT_MAX_CONTENT_WINDOW_CHARS);

    assert.equal(first.content.length, DEFAULT_MAX_CONTENT_WINDOW_CHARS);
    assert.equal(first.complete, false);
    assert.equal(first.truncated, true);
    assert.equal(first.totalChars, content.length);
    assert.equal(second.content.length, 25);
    assert.equal(second.complete, true);
    assert.equal(second.nextOffset, null);
    assert.equal(first.content + second.content, content);
});

test('normalizes only hostname-shaped allowlist entries', () => {
    assert.equal(normalizeDomain(' Example.COM '), 'example.com');
    assert.equal(normalizeDomain('https://example.com'), null);
    assert.equal(normalizeDomain('../example.com'), null);
    assert.equal(normalizeDomain('example..com'), null);
});

test('parses bounded JSON and rejects oversized request bodies', async () => {
    const valid = Readable.from(['{"ok":true}']);
    assert.deepEqual(await parseJsonBody(valid, 32), { ok: true });

    const oversized = Readable.from(['{"payload":"too-large"}']);
    await assert.rejects(
        parseJsonBody(oversized, 8),
        error => error.statusCode === 413 && /too large/.test(error.message),
    );
});

test('both browser sidecars enforce the shared control-plane policy', () => {
    for (const filename of ['puppeteer-server.cjs', 'playwright-server.cjs']) {
        const source = fs.readFileSync(path.join(__dirname, filename), 'utf8');
        assert.match(source, /requireAutomationToken\(\)/);
        assert.match(source, /requestPolicyError\(req, PORT, AUTH_TOKEN\)/);
        assert.match(source, /parseJsonBody\(req\)/);
        assert.match(source, /safeOutputName\(name,/);
    }

    const playwright = fs.readFileSync(path.join(__dirname, 'playwright-server.cjs'), 'utf8');
    assert.match(playwright, /acceptDownloads: false/);
    assert.match(playwright, /serviceWorkers: 'block'/);
    assert.match(playwright, /getPage\(freshSession\)/);
    assert.match(playwright, /ariaSnapshot/);
    assert.match(playwright, /untrustedPageContent: true/);
    assert.match(playwright, /Fetch\.enable/);
    assert.match(playwright, /resourceType: 'Document'/);
    assert.match(playwright, /Fetch\.failRequest/);
    assert.match(playwright, /chromiumSandbox: !isRoot/);

    const puppeteer = fs.readFileSync(path.join(__dirname, 'puppeteer-server.cjs'), 'utf8');
    assert.match(puppeteer, /resourceType === 'document'/);
    assert.match(puppeteer, /\.\.\.\(isRoot \? \['--no-sandbox', '--disable-setuid-sandbox'\] : \[\]\)/);

    for (const filename of ['puppeteer-server.cjs', 'playwright-server.cjs']) {
        const source = fs.readFileSync(path.join(__dirname, filename), 'utf8');
        assert.match(source, /navigationScopeForTarget/);
        assert.match(source, /topLevelNavigationAllowlist: true/);
        assert.match(source, /documentRequestsScoped: true/);
        assert.match(source, /ipLiteralAndLocalhostTargetsBlocked: true/);
        assert.match(source, /topLevelDnsPublicAddressesRequired: true/);
        assert.match(source, /resolvePublicNavigationTarget/);
        assert.match(source, /credentialApiEnabled: false/);
        assert.match(source, /contentWindowing: true/);
        assert.doesNotMatch(source, /url === '\/cookies'/);
    }
});
