import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, realpath, rm, symlink, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { isPublicAddress, previewOrigin, publicAsset, publicDocument, previewResources, releaseFile } from '../../scripts/preview-resources.mjs';

function request(url, options = {}) {
    return {
        url: () => url,
        method: () => options.method ?? 'GET',
        resourceType: () => options.type ?? 'image',
        isNavigationRequest: () => options.navigation ?? false,
        frame: () => options.frame,
        redirectedFrom: () => options.previous ?? null,
    };
}

async function route(handler, requested) {
    let result;
    await handler({
        request: () => requested,
        abort: async () => { result = { aborted: true }; },
        fulfill: async response => { result = response; },
    });
    return result;
}

test('preview resources reject private, loopback, metadata and mapped addresses', async () => {
    for (const address of ['127.0.0.1', '10.0.0.1', '169.254.169.254', '192.168.1.1', '0.0.0.0', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1']) {
        assert.equal(isPublicAddress(address), false, address);
        await assert.rejects(publicAsset(new URL('https://example.com/image.png'), async () => [{ address, family: address.includes(':') ? 6 : 4 }]), /Private asset URL/);
    }
    assert.equal(isPublicAddress('8.8.8.8'), true);
    assert.equal(isPublicAddress('2606:4700:4700::1111'), true);
    await assert.rejects(publicAsset(new URL('https://example.com'), async () => [
        { address: '8.8.8.8', family: 4 }, { address: '127.0.0.1', family: 4 },
    ]), /Private asset URL/);
});

test('preview resources reject credentials, non-HTTP schemes and custom ports before DNS', async () => {
    for (const url of ['file:///etc/passwd', 'ftp://example.com/a', 'https://user:pass@example.com', 'http://example.com:8090']) {
        await assert.rejects(publicAsset(new URL(url), async () => assert.fail('URL must be rejected before DNS')), /Unsupported asset URL/);
    }
});

test('public documents resolve relative redirects and keep the final asset base', async () => {
    const fetched = [];
    const response = { status: 200, headers: { 'content-type': 'text/html' }, body: Buffer.from('<img src="photo.png">') };
    const document = await publicDocument('https://example.com/demo#heading', async url => {
        fetched.push(url.href);
        return fetched.length === 1 ? { status: 302, headers: { location: './v2/index.html#heading' } } : response;
    });
    assert.deepEqual(fetched, ['https://example.com/demo', 'https://example.com/v2/index.html']);
    assert.equal(new URL('photo.png', document.url).href, 'https://example.com/v2/photo.png');
    assert.equal(document.response, response);
});

test('every public document redirect is checked for SSRF', async () => {
    let fetched = 0;
    await assert.rejects(publicDocument('https://example.com/demo', async url => {
        if (++fetched === 1) return { status: 302, headers: { location: 'http://127.0.0.1/admin' } };
        return publicAsset(url, async () => [{ address: '127.0.0.1', family: 4 }]);
    }), /Private asset URL/);
    assert.equal(fetched, 2);
});

test('public document redirects and failures are bounded', async () => {
    await assert.rejects(publicDocument('https://example.com/a', async () => ({ status: 302, headers: { location: '/a' } })), /redirect loop/);
    let fetched = 0;
    await assert.rejects(publicDocument('https://example.com/a', async () => ({ status: 302, headers: { location: `/next-${++fetched}` } })), /too many redirects/);
    assert.equal(fetched, 6);
    await assert.rejects(publicDocument('https://example.com/a', async () => ({ status: 404, headers: {} })), /Unable to load/);
});

test('public mode fulfills only the initial main-frame document and permitted assets', async () => {
    const mainFrame = {};
    const response = { status: 200, headers: { 'content-type': 'text/html' }, body: Buffer.from('Preview') };
    const document = { url: new URL('https://example.com/demo/'), response };
    const fetched = [];
    const handler = previewResources({ document, mainFrame, fetch: async url => { fetched.push(url.href); return response; } });
    assert.deepEqual(await route(handler, request(document.url.href, { navigation: true, frame: {} })), { aborted: true });
    assert.equal(await route(handler, request(document.url.href, { navigation: true, frame: mainFrame })), response);
    assert.deepEqual(await route(handler, request(document.url.href, { navigation: true, frame: mainFrame })), { aborted: true });
    assert.deepEqual(await route(handler, request('https://example.com/redirect', { navigation: true, frame: mainFrame })), { aborted: true });
    for (const type of ['fetch', 'xhr', 'document', 'eventsource', 'ping']) {
        assert.deepEqual(await route(handler, request('https://example.com/api', { type })), { aborted: true });
    }
    assert.deepEqual(await route(handler, request('https://example.com/api', { method: 'POST' })), { aborted: true });
    for (const type of ['image', 'stylesheet', 'font', 'script']) {
        assert.equal(await route(handler, request(`https://example.com/${type}`, { type })), response);
    }
    assert.equal(fetched.length, 4);
});

test('asset redirects cannot access local networks or exceed request limits', async () => {
    const handler = previewResources({ fetch: url => publicAsset(url, async () => [{ address: '127.0.0.1', family: 4 }]) });
    assert.deepEqual(await route(handler, request('http://127.0.0.1/image')), { aborted: true });

    let fetched = 0;
    const boundedHandler = previewResources({ fetch: async () => { fetched++; return { status: 200 }; } });
    let redirected = request('https://example.com/image');
    for (let i = 0; i < 6; i++) redirected = request('https://example.com/image', { previous: redirected });
    assert.deepEqual(await route(boundedHandler, redirected), { aborted: true });
    assert.equal(fetched, 0);
    for (let i = 0; i < 199; i++) await route(boundedHandler, request('https://example.com/image'));
    assert.deepEqual(await route(boundedHandler, request('https://example.com/last-image')), { aborted: true });
    assert.equal(fetched, 199);
});

test('local release mode preserves custom entrypoints and assets, rejecting traversal and escaped symlinks', async t => {
    const temporary = await mkdtemp(path.join(os.tmpdir(), 'landing-preview-'));
    t.after(() => rm(temporary, { recursive: true, force: true }));
    const directory = path.join(temporary, 'release');
    await mkdir(directory);
    const root = await realpath(directory);
    await writeFile(path.join(root, 'custom.html'), '<h1>Preview</h1>');
    await writeFile(path.join(root, 'style.css'), 'body { color: red; }');
    await writeFile(path.join(root, 'index.php'), '<?php echo "Private source";');
    await assert.rejects(releaseFile(root, '/', 'index.php'), /Server source/);
    await assert.rejects(releaseFile(root, '/index.php'), /Server source/);
    await writeFile(path.join(temporary, 'private.txt'), 'secret');
    await symlink(path.join(temporary, 'private.txt'), path.join(root, 'escaped.txt'));
    assert.equal(await releaseFile(root, '/', 'custom.html'), path.join(root, 'custom.html'));
    for (const pathname of ['/../private.txt', '/%2e%2e/private.txt', '/escaped.txt', '/%00', '/%5cprivate.txt']) {
        await assert.rejects(releaseFile(root, pathname));
    }
    const handler = previewResources({ root, entrypoint: 'custom.html', fetch: async () => assert.fail('Local files must not use HTTP') });
    assert.deepEqual(await route(handler, request(`${previewOrigin}/`, { navigation: true })), { path: path.join(root, 'custom.html') });
    assert.deepEqual(await route(handler, request(`${previewOrigin}/style.css`, { type: 'stylesheet' })), { path: path.join(root, 'style.css') });
    assert.equal((await route(handler, request(`${previewOrigin}/missing.png`))).status, 404);
    assert.deepEqual(await route(handler, request('https://example.com/away', { navigation: true })), { aborted: true });
});
