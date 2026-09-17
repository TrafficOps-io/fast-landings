import { lookup } from 'node:dns/promises';
import http from 'node:http';
import https from 'node:https';
import { realpath, stat } from 'node:fs/promises';
import path from 'node:path';
import ipaddr from 'ipaddr.js';

export const previewOrigin = 'https://landing-preview.invalid';
const maxRedirects = 5;

export function isPublicAddress(address) {
    try {
        return ipaddr.process(address).range() === 'unicast';
    } catch {
        return false;
    }
}

export async function releaseFile(root, pathname, entrypoint = 'index.html') {
    const decoded = decodeURIComponent(pathname);
    if (decoded.includes('\0') || decoded.includes('\\')) throw new Error('Invalid asset path.');
    const segments = decoded.split('/').filter(Boolean);
    if (segments.some(segment => segment === '.' || segment === '..')) throw new Error('Invalid asset path.');
    const relative = segments.join('/');
    const candidate = path.resolve(root, relative === '' ? entrypoint : relative + (decoded.endsWith('/') ? '/index.html' : ''));
    // This renderer has no PHP runtime. Never send server sources to Chromium,
    // including through a root entrypoint or a script/image request.
    if (/(?:^|\/)\.|\.(?:php\d*|phtml|phar|inc|tpl)(?:\.|$)/i.test(path.relative(root, candidate))) {
        throw new Error('Server source requires the website PHP runtime.');
    }
    const file = await realpath(candidate);
    if (!file.startsWith(root + path.sep) || !(await stat(file)).isFile()) throw new Error('Asset is outside the release.');
    return file;
}

// Fetch only public HTTP resources. Pin the checked address to the socket to
// prevent DNS rebinding; callers must check every redirect through this handler.
export async function publicAsset(url, resolve = lookup) {
    const hostname = url.hostname.replace(/^\[|\]$/g, '');
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password
        || (url.port && !['80', '443'].includes(url.port))) throw new Error('Unsupported asset URL.');
    const addresses = await resolve(hostname, { all: true });
    if (!addresses.length || addresses.some(({ address }) => !isPublicAddress(address))) throw new Error('Private asset URL.');
    const target = addresses[0];
    return new Promise((resolve, reject) => {
        const request = (url.protocol === 'https:' ? https : http).get(url, {
            agent: false,
            lookup: (_hostname, options, callback) => callback(null, options.all ? [target] : target.address, target.family),
            headers: { 'User-Agent': 'FastLandings-Preview/1.0', Accept: '*/*', 'Accept-Encoding': 'identity' },
        }, response => {
            const chunks = [];
            let size = 0;
            response.on('data', chunk => {
                size += chunk.length;
                if (size > 15 * 1024 * 1024) request.destroy(new Error('Asset is too large.'));
                else chunks.push(chunk);
            });
            response.on('error', reject);
            response.on('end', () => {
                const headers = {};
                for (const name of ['content-type', 'content-encoding', 'location', 'access-control-allow-origin']) {
                    if (response.headers[name]) headers[name] = response.headers[name];
                }
                resolve({ status: response.statusCode, headers, body: Buffer.concat(chunks) });
            });
        });
        const deadline = setTimeout(() => request.destroy(new Error('Asset timed out.')), 8000);
        request.on('close', () => clearTimeout(deadline));
        request.on('error', reject);
    });
}

// Resolve the document before opening the page so its real final URL remains
// the base for relative assets, while Chromium never follows a navigation.
export async function publicDocument(url, fetch = publicAsset) {
    let current = new URL(url);
    const visited = new Set();
    for (let redirects = 0; redirects <= maxRedirects; redirects++) {
        current.hash = '';
        if (visited.has(current.href)) throw new Error('Preview URL has a redirect loop.');
        visited.add(current.href);
        const response = await fetch(current);
        if ([301, 302, 303, 307, 308].includes(response.status) && response.headers.location) {
            current = new URL(response.headers.location, current);
            continue;
        }
        if (response.status < 200 || response.status >= 300) throw new Error('Unable to load the public preview.');
        return { url: current, response };
    }
    throw new Error('Preview URL has too many redirects.');
}

export function previewResources({ root, entrypoint = 'index.html', document, mainFrame, fetch = publicAsset }) {
    let resourceCount = 0;
    let documentPending = true;
    return async route => {
        try {
            const request = route.request();
            const url = new URL(request.url());
            if (++resourceCount > 200 || request.method() !== 'GET') return await route.abort();
            let redirects = 0;
            for (let previous = request.redirectedFrom(); previous; previous = previous.redirectedFrom()) {
                if (++redirects > maxRedirects) return await route.abort();
            }
            if (root && url.origin === previewOrigin) {
                let file;
                try {
                    file = await releaseFile(root, url.pathname, entrypoint);
                } catch {
                    return await route.fulfill({ status: 404, body: 'Not found' });
                }
                return await route.fulfill({ path: file });
            }
            if (request.isNavigationRequest()) {
                if (!document || !documentPending || request.frame() !== mainFrame || url.href !== document.url.href) {
                    return await route.abort();
                }
                documentPending = false;
                return await route.fulfill(document.response);
            }
            // Do not submit forms, analytics, or background API requests.
            if (!['image', 'stylesheet', 'font', 'script'].includes(request.resourceType())) return await route.abort();
            await route.fulfill(await fetch(url));
        } catch {
            await route.abort().catch(() => {});
        }
    };
}
