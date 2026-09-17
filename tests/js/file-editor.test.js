import test from 'node:test';
import assert from 'node:assert/strict';
import { fileEditor } from '../../resources/js/file-editor.js';
import { TPL_LANGUAGE, fileLanguage, fileUri, tplProject, includeCompletions, language } from '../../resources/js/file-editor-language.js';

test('TPL detection covers fragments, alternate extensions and declared HTML templates', () => {
    for (const path of ['template.tpl', 'blocks/article.tpl.html', 'success.tpl.php', 'fragment.tpl.txt']) assert.equal(fileLanguage(path), TPL_LANGUAGE);
    assert.equal(fileLanguage('index.html', '\uFEFF\n@template "Article"'), TPL_LANGUAGE);
    assert.equal(fileLanguage('index.html', '<!doctype html>'), 'html');
    assert.equal(fileLanguage('site.js'), 'javascript');
    assert.equal(fileLanguage('settings.JSON'), 'json');
    assert.equal(fileLanguage('unknown.dat'), 'plaintext');
});

test('shared TPL model completes included record fields and ignores unrelated fragments', () => {
    const sources = {
        'template.tpl': '@template "Test"\n@include "types.tpl"\n@param card Card\n@include "blocks/card.tpl"',
        'types.tpl': '@type Card\n@param title String\n@param text Wysiwyg\n@endtype',
        'blocks/card.tpl': '@block cardView(card: Card)\n<h2>{{card.}}</h2>\n@endblock',
        'unused.tpl': '@param accidental String',
    };
    const current = sources['blocks/card.tpl'];
    const project = tplProject(sources, 'blocks/card.tpl', current, 'workspace');
    const uri = fileUri('workspace', 'blocks/card.tpl');
    const items = language.getCompletions(project, uri, current.indexOf('card.}}') + 5);
    assert.deepEqual(items.map(item => item.label), ['title', 'text']);
    assert.equal(project.params.has('accidental'), false);
    assert.equal(project.documents.size, 3);
    const changed = tplProject(sources, 'types.tpl', sources['types.tpl'].replace('title', 'headline'), 'workspace');
    assert.equal(changed.types.get('Card').fields[0].name, 'headline');
});

test('include suggestions replace the entire quoted path and exclude binary/self files', () => {
    const line = '@include "blocks/ca-old.tpl"';
    const column = line.indexOf('-old');
    const suggestions = includeCompletions([
        { path: 'blocks/card.tpl', editable: true },
        { path: 'blocks/cat.png', editable: false },
        { path: 'blocks/catalog.tpl', editable: true },
    ], 'blocks/catalog.tpl', line, column);
    assert.equal(suggestions.length, 1);
    assert.equal(line.slice(0, suggestions[0].startColumn - 1) + suggestions[0].insertText + line.slice(suggestions[0].endColumn - 1), '@include "blocks/card.tpl"');
    assert.equal(includeCompletions([], '', '<h1>', 4), null);
});

test('companion website pages complete shared settings and omit unused fragments', () => {
    const sources = {
        'index.tpl.html': '@template "Website"\n@param heading String = "Hello"\n@layout\n<h1>{{heading}}</h1>\n@endlayout',
        'success.tpl.php': '@layout\n<h1>{{heading}}</h1>\n@endlayout',
        'unused.tpl': '@param accidental String',
    };
    const project = tplProject(sources, 'success.tpl.php', sources['success.tpl.php'], 'website');
    assert.equal(project.params.has('heading'), true);
    assert.equal(project.params.has('accidental'), false);
    assert.equal(project.documents.size, 2);
});

test('project URI preserves Unicode, spaces and reserved filename characters', () => {
    assert.equal(fileUri('draft#1', 'blocks/заголовок +.tpl'), 'inmemory://fast-landings/draft%231/blocks/%D0%B7%D0%B0%D0%B3%D0%BE%D0%BB%D0%BE%D0%B2%D0%BE%D0%BA%20%2B.tpl');
});

test('ordinary landing files support request macros and page-scoped completion', () => {
    assert.equal(fileLanguage('index.php', '@validation query\n@param subid String required\n@endvalidation'), TPL_LANGUAGE);
    assert.equal(fileLanguage('success.html', '<h1>Hello {body.name}</h1>'), TPL_LANGUAGE);
    const sources = {
        'index.tpl.php': '@template "Request"\n@validation query\n@param subid String\n@endvalidation\n@layout\n{query.}\n@endlayout',
        'success.tpl.php': '@validation body\n@param name String\n@endvalidation\n@layout\n{body.}\n@endlayout',
    };
    const text = sources['success.tpl.php'];
    const project = tplProject(sources, 'success.tpl.php', text, 'request');
    const suggestions = language.getCompletions(project, fileUri('request', 'success.tpl.php'), text.indexOf('{body.') + 6);
    assert.ok(suggestions.some(item => item.label === 'name'));
    assert.equal(project.params.has('name'), false);
});

async function mountedEditor(saveFile, config = {}) {
    globalThis.window = new EventTarget();
    globalThis.document = new EventTarget();
    window.confirm = () => false;
    let content = 'original';
    let onChange;
    let disposed = false;
    let readOnly = false;
    const events = [];
    const state = fileEditor({ path: 'template.tpl', content, workspaceId: 'draft', ...config }, async () => ({
        createFileEditor: () => ({ editor: {
            getValue: () => content,
            updateOptions: options => { readOnly = options.readOnly; },
            onDidChangeModelContent: callback => { onChange = callback; return { dispose() {} }; },
        }, dispose() { disposed = true; } }),
    }));
    state.$refs = { editor: {} };
    state.$dispatch = (name, detail) => events.push({ name, detail });
    state.$wire = { saveFile };
    await state.init();
    return { state, events, disposed: () => disposed, readOnly: () => readOnly, change(value) { content = value; onChange(); } };
}

test('staging failures preserve the buffer and block the parent action', async () => {
    const mounted = await mountedEditor(async () => false);
    mounted.change('changed');
    assert.equal(await mounted.state.save(), false);
    assert.equal(mounted.state.dirty, true);
    assert.equal(mounted.state.saving, false);
    const result = await new Promise(resolve => window.dispatchEvent(new CustomEvent('file-editor-save', { detail: { resolve } })));
    assert.equal(result, false);
    mounted.state.destroy();
    assert.equal(mounted.disposed(), true);
});

test('concurrent save requests share one write and preserve edits made while saving', async () => {
    let finish;
    let calls = 0;
    const mounted = await mountedEditor(() => { calls++; return new Promise(resolve => { finish = resolve; }); });
    mounted.change('first edit');
    const first = mounted.state.save();
    const second = mounted.state.save();
    mounted.change('typed during save');
    finish(true);
    assert.equal(await first, false);
    assert.equal(await second, false);
    assert.equal(calls, 1);
    assert.equal(mounted.state.dirty, true);
    const next = mounted.state.save();
    finish(true);
    assert.equal(await next, true);
    assert.equal(mounted.state.dirty, false);
    mounted.state.destroy();
});

test('navigation guard only prompts for an unsaved buffer and listeners are removed on unmount', async () => {
    const mounted = await mountedEditor(async () => true);
    assert.equal(document.dispatchEvent(new Event('livewire:navigate', { cancelable: true })), true);
    mounted.change('unsaved');
    assert.equal(document.dispatchEvent(new Event('livewire:navigate', { cancelable: true })), false);
    const unload = new Event('beforeunload', { cancelable: true });
    Object.defineProperty(unload, 'returnValue', { writable: true, value: true });
    assert.equal(window.dispatchEvent(unload), false);
    await mounted.state.save();
    assert.equal(document.dispatchEvent(new Event('livewire:navigate', { cancelable: true })), true);
    mounted.change('another edit');
    mounted.state.destroy();
    assert.equal(document.dispatchEvent(new Event('livewire:navigate', { cancelable: true })), true);
});

test('manager actions lock the editor and its unified navigation guard can own confirmation', async () => {
    const mounted = await mountedEditor(async () => true, { guardNavigation: false });
    mounted.change('unsaved');
    assert.equal(document.dispatchEvent(new Event('livewire:navigate', { cancelable: true })), true);
    window.dispatchEvent(new CustomEvent('file-editor-busy', { detail: { workspaceId: 'other', busy: true } }));
    assert.equal(mounted.readOnly(), false);
    window.dispatchEvent(new CustomEvent('file-editor-busy', { detail: { workspaceId: 'draft', busy: true } }));
    assert.equal(mounted.readOnly(), true);
    window.dispatchEvent(new CustomEvent('file-editor-busy', { detail: { workspaceId: 'draft', busy: false } }));
    assert.equal(mounted.readOnly(), false);
    mounted.state.destroy();
});

test('leaving a file while its lazy module loads does not mount a detached editor', async () => {
    globalThis.window = new EventTarget();
    globalThis.document = new EventTarget();
    let finish;
    let created = false;
    const state = fileEditor({ content: '', workspaceId: 'draft' }, () => new Promise(resolve => { finish = resolve; }));
    const loading = state.init();
    state.destroy();
    finish({ createFileEditor() { created = true; } });
    await loading;
    assert.equal(created, false);
});
