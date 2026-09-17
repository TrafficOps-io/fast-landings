import { chromium } from 'playwright';
import { realpath } from 'node:fs/promises';
import { previewOrigin, releaseFile, publicDocument, previewResources } from './preview-resources.mjs';

const [source, destination, third] = process.argv.slice(2);
const remote = source === '--url';
const output = remote ? third : destination;
const entrypoint = third || 'index.html';
if (!source || !destination || !output) {
    throw new Error('Usage: capture-landing.mjs RELEASE_DIRECTORY OUTPUT [ENTRYPOINT] | --url URL OUTPUT');
}
const root = remote ? null : await realpath(source);
const document = remote ? await publicDocument(destination) : null;
if (root) await releaseFile(root, '/', entrypoint);

let browser;
try {
    browser = await chromium.launch({
        executablePath: process.env.FAST_LANDINGS_CHROMIUM_PATH || undefined,
        headless: true,
        // Every resource is fulfilled by our file/public-asset handlers. The
        // dead proxy also blocks direct-IP traffic outside request routing.
        proxy: { server: 'http://127.0.0.1:9' },
        args: ['--proxy-bypass-list=<-loopback>', '--disable-quic', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp'],
    });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        deviceScaleFactor: 1,
        colorScheme: 'light',
        reducedMotion: 'reduce',
        serviceWorkers: 'block',
        acceptDownloads: false,
    });
    await context.routeWebSocket(/.*/, socket => socket.close());
    const page = await context.newPage();
    await context.route('**/*', previewResources({ root, entrypoint, document, mainFrame: page.mainFrame() }));
    context.on('page', popup => { if (popup !== page) popup.close().catch(() => {}); });
    page.on('dialog', dialog => dialog.dismiss());
    const response = await page.goto(document?.url.href ?? previewOrigin + '/', { waitUntil: 'domcontentloaded', timeout: 15000 });
    if (!response?.ok()) throw new Error('Unable to render the preview.');
    await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
    await page.evaluate(async () => {
        await Promise.race([document.fonts.ready, new Promise(resolve => setTimeout(resolve, 2000))]);
    });
    await page.screenshot({ path: output, type: 'png', animations: 'disabled', timeout: 10000 });
} finally {
    await browser?.close();
}
