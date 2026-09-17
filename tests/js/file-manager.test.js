import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/file-manager.js', import.meta.url), 'utf8');

function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}

function harness(t) {
    const window = new EventTarget();
    const document = new EventTarget();
    const prompts = [];
    const downloads = [];
    const links = new Set();
    window.confirm = message => { prompts.push(message); return false; };
    document.body = { append: link => links.add(link) };
    document.createElement = tag => {
        assert.equal(tag, 'a');
        const link = {
            click() {
                assert.equal(links.has(link), true);
                downloads.push(link.href);
            },
            remove() { links.delete(link); },
        };
        return link;
    };
    runInNewContext(source, { window, document, CustomEvent });
    const manager = window.fileManager('workspace-under-test');
    manager.$wire = { pendingChanges: false };
    manager.init();
    t.after(() => manager.destroy());

    function navigate() {
        const event = new Event('livewire:navigate', { cancelable: true });
        document.dispatchEvent(event);
        return event;
    }

    function unload() {
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        return event;
    }

    return { manager, window, document, prompts, downloads, links, navigate, unload };
}

test('selecting a different file waits for the current buffer to be safely staged', async t => {
    const { manager, window } = harness(t);
    manager.dirty = true;
    const staged = deferred();
    const calls = [];
    window.addEventListener('file-editor-save', event => {
        assert.equal(event.detail.workspaceId, 'workspace-under-test');
        staged.promise.then(event.detail.resolve);
    });
    manager.$wire.selectFile = async path => { calls.push(path); };

    const switching = manager.run('selectFile', 'assets/theme.css');
    assert.equal(manager.busy, true);
    assert.deepEqual(calls, []);

    staged.resolve(true);
    await switching;
    assert.deepEqual(calls, ['assets/theme.css']);
    assert.equal(manager.busy, false);
    assert.equal(manager.leaving, false);
});

test('publication stages edits before its server action and allows the resulting navigation', async t => {
    const { manager, window, prompts, navigate, unload } = harness(t);
    const order = [];
    manager.dirty = true;
    manager.$wire.pendingChanges = true;
    window.addEventListener('file-editor-save', event => {
        order.push('stage');
        event.detail.resolve(true);
    });
    manager.$wire.publish = async () => {
        order.push('publish');
        assert.equal(navigate().defaultPrevented, false);
        assert.equal(unload().defaultPrevented, false);
        return true;
    };

    await manager.run('publish');

    assert.deepEqual(order, ['stage', 'publish']);
    assert.deepEqual(prompts, []);
    assert.equal(manager.busy, false);
});

test('failed staging prevents selection, publication and downloading without losing the dirty state', async t => {
    const { manager, window, downloads } = harness(t);
    const calls = [];
    manager.dirty = true;
    window.addEventListener('file-editor-save', event => event.detail.resolve(false));
    manager.$wire.selectFile = async () => calls.push('select');
    manager.$wire.publish = async () => calls.push('publish');

    await manager.run('selectFile', 'other.txt');
    await manager.run('publish');
    await manager.download('/admin/file-workspaces/draft/download');

    assert.deepEqual(calls, []);
    assert.deepEqual(downloads, []);
    assert.equal(manager.dirty, true);
    assert.equal(manager.leaving, false);
    assert.equal(manager.busy, false);
});

test('download includes the latest buffer and removes its temporary link', async t => {
    const { manager, window, downloads, links } = harness(t);
    manager.dirty = true;
    const staged = deferred();
    window.addEventListener('file-editor-save', event => staged.promise.then(event.detail.resolve));
    const download = manager.download('/admin/file-workspaces/draft/download');
    assert.deepEqual(downloads, []);

    staged.resolve(true);
    await download;

    assert.deepEqual(downloads, ['/admin/file-workspaces/draft/download']);
    assert.equal(links.size, 0);
    assert.equal(manager.busy, false);
});

test('leaving warns once for either unsaved text or unpublished draft changes', t => {
    const { manager, document, prompts, navigate, unload } = harness(t);
    assert.equal(navigate().defaultPrevented, false);
    assert.equal(unload().defaultPrevented, false);
    assert.equal(prompts.length, 0);

    for (const [dirty, pendingChanges] of [[true, false], [false, true], [true, true]]) {
        manager.dirty = dirty;
        manager.$wire.pendingChanges = pendingChanges;
        const count = prompts.length;
        assert.equal(navigate().defaultPrevented, true);
        assert.equal(prompts.length, count + 1);
        assert.equal(unload().defaultPrevented, true);
        assert.equal(prompts.length, count + 1);
    }

    const alreadyCancelled = new Event('livewire:navigate', { cancelable: true });
    alreadyCancelled.preventDefault();
    const count = prompts.length;
    document.dispatchEvent(alreadyCancelled);
    assert.equal(prompts.length, count);
});

test('explicit discard never saves unwanted text or asks again during its redirect', async t => {
    const { manager, window, prompts, navigate, unload } = harness(t);
    manager.dirty = true;
    manager.$wire.pendingChanges = true;
    let saveRequests = 0;
    window.addEventListener('file-editor-save', event => {
        saveRequests++;
        event.detail.resolve(false);
    });
    let discarded = false;
    manager.$wire.discard = async () => {
        discarded = true;
        assert.equal(navigate().defaultPrevented, false);
        assert.equal(unload().defaultPrevented, false);
        return true;
    };

    await manager.run('discard');

    assert.equal(discarded, true);
    assert.equal(saveRequests, 0);
    assert.deepEqual(prompts, []);
});

test('busy operations reject competing actions and keep the editor locked until the server finishes', async t => {
    const { manager, window, downloads } = harness(t);
    const saving = deferred();
    const switching = deferred();
    const calls = [];
    const locks = [];
    manager.dirty = true;
    window.addEventListener('file-editor-save', event => saving.promise.then(event.detail.resolve));
    window.addEventListener('file-editor-busy', event => {
        assert.equal(event.detail.workspaceId, 'workspace-under-test');
        locks.push(event.detail.busy);
    });
    manager.$wire.selectFile = async () => { calls.push('select'); await switching.promise; };
    manager.$wire.publish = async () => calls.push('publish');

    const first = manager.run('selectFile', 'assets/theme.css');
    await manager.run('publish');
    await manager.download('/download');
    assert.deepEqual(calls, []);
    assert.deepEqual(downloads, []);
    assert.deepEqual(locks, [true]);

    saving.resolve(true);
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls, ['select']);
    assert.equal(manager.busy, true);
    assert.deepEqual(locks, [true]);

    switching.resolve();
    await first;
    assert.equal(manager.busy, false);
    assert.deepEqual(locks, [true, false]);
});

test('failed publication unlocks the editor and restores protection for unsaved changes', async t => {
    const { manager, window, navigate } = harness(t);
    const locks = [];
    manager.$wire.pendingChanges = true;
    window.addEventListener('file-editor-busy', event => locks.push(event.detail.busy));
    manager.$wire.publish = async () => false;

    await manager.run('publish');

    assert.equal(manager.busy, false);
    assert.equal(manager.leaving, false);
    assert.equal(navigate().defaultPrevented, true);
    assert.deepEqual(locks, [true, false]);

    manager.$wire.publish = async () => { throw new Error('Network error'); };
    await manager.run('publish');
    assert.equal(manager.busy, false);
    assert.equal(manager.leaving, false);
    assert.match(manager.error, /try again/i);
    assert.equal(navigate().defaultPrevented, true);
    assert.deepEqual(locks, [true, false, true, false]);
});

test('destroy removes navigation guards so a departed editor cannot block another page', t => {
    const { manager, prompts, navigate, unload } = harness(t);
    manager.dirty = true;
    manager.$wire.pendingChanges = true;
    manager.destroy();

    assert.equal(navigate().defaultPrevented, false);
    assert.equal(unload().defaultPrevented, false);
    assert.deepEqual(prompts, []);
});
