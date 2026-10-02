import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build, preview } from 'vite';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const outDir = await mkdtemp(join(tmpdir(), 'fast-landings-editor-'));
let server, browser, page;
const errors = [], consoleErrors = [];
try {
  await build({ root, configFile: false, logLevel: 'error', build: { outDir, emptyOutDir: true,
    commonjsOptions: { include: [/node_modules/, /resources\/js\/vendor\//] },
    rolldownOptions: { input: join(root, 'tests/browser/editor-smoke.html') } } });
  server = await preview({ root, configFile: false, build: { outDir }, preview: { host: '127.0.0.1', port: 0 } });
  browser = await chromium.launch({ headless: true, ...(process.platform === 'darwin' ? { executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' } : {}) });
  page = await browser.newPage();
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/tests/browser/editor-smoke.html`);
  await page.waitForFunction(() => window.instance?.editor.getModel());
  assert.equal(await page.evaluate(() => window.instance.editor.getModel().getLanguageId()), 'fast-landings-tpl');
  assert.ok(await page.evaluate(() => window.monaco.editor.tokenize('@param title String', 'fast-landings-tpl')[0].some(token => token.type.includes('keyword'))));
  await page.evaluate(() => { window.instance.editor.focus(); window.instance.editor.trigger('smoke', 'actions.find', null); });
  await page.locator('.find-widget.visible').waitFor();
  await page.keyboard.press('Escape');
  for (const [language, content] of [['html', '<h1>Browser test</h1>'], ['css', 'h1 { color: red; }'], ['javascript', 'const value = 42;']]) {
    await page.evaluate(({ language, content }) => { const model = window.instance.editor.getModel(); window.monaco.editor.setModelLanguage(model, language); model.setValue(content); }, { language, content });
    assert.equal(await page.evaluate(() => window.instance.editor.getValue()), content);
  }
  await page.evaluate(() => { const previous = window.instance.editor.getModel(); const model = window.monaco.editor.createModel('{"broken": }', 'json', window.monaco.Uri.parse('inmemory://fast-landings/smoke/test.json')); window.instance.editor.setModel(model); previous.dispose(); });
  await page.waitForFunction(() => window.monaco.editor.getModelMarkers({ owner: 'json', resource: window.instance.editor.getModel().uri }).length > 0);
  await page.evaluate(() => window.instance.editor.getModel().setValue('{"ok": true}'));
  await page.waitForFunction(() => window.monaco.editor.getModelMarkers({ owner: 'json', resource: window.instance.editor.getModel().uri }).length === 0);
  assert.deepEqual(errors, []);
  console.log('editor-smoke: PASS (TPL tokens, find, language switching, JSON worker diagnostics)');
} catch (error) {
  console.error(JSON.stringify({ errors, consoleErrors, state: page && await page.evaluate(() => ({ language: window.instance?.editor.getModel()?.getLanguageId(), markers: window.monaco?.editor.getModelMarkers({}), content: window.instance?.editor.getValue() })).catch(() => null) }));
  throw error;
} finally {
  await browser?.close();
  if (server) await new Promise(resolve => server.httpServer.close(resolve));
  await rm(outDir, { recursive: true, force: true });
}
