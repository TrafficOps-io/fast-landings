import test from 'node:test';
import assert from 'node:assert/strict';
import { imageTarget, templateImageEditor } from '../../resources/js/template-image.js';

const config = { sizes: [], maxBytes: 10 * 1024 * 1024, path: 'comments.2.avatar', uploadPath: 'uploads.comments.2.avatar' };

test('ratio crops preserve source resolution, resize proportionally and bound canvas dimensions', () => {
    assert.deepEqual(imageTarget({ width: 2000, height: 1000 }, { aspectRatio: 1 }), { width: 1000, height: 1000 });
    assert.deepEqual(imageTarget({ width: 1000, height: 2000 }, { aspectRatio: 16 / 9 }), { width: 1000, height: 563 });
    assert.deepEqual(imageTarget({ width: 2000, height: 1000, outputWidth: 800 }, {}), { width: 800, height: 400 });
    assert.deepEqual(imageTarget({ width: 10000, height: 5000 }, {}), { width: 4096, height: 2048 });
    assert.deepEqual(imageTarget({ width: 1000, height: 1000, outputWidth: 4096 }, { aspectRatio: 0.01 }), { width: 41, height: 4096 });
    assert.deepEqual(imageTarget({ preset: 1 }, { sizes: [{ width: 100, height: 100 }, { width: 1200, height: 630 }] }), { width: 1200, height: 630 });
});

test('shared reactive getters follow source, zoom and selected output size', () => {
    const editor = templateImageEditor({ ...config, sizes: [{ width: 100, height: 100 }, { width: 200, height: 100 }] });
    editor.items = [{ image: {}, width: 400, height: 200, preset: 0, zoom: 1, x: 0, y: 0 }];
    assert.equal(editor.target.width, 100);
    assert.equal(editor.placement.left, -50);
    editor.setPreset(1);
    assert.equal(editor.target.width, 200);
    assert.equal(editor.placement.left, 0);
    assert.equal(editor.outputLabel, '200 × 100 px');
    assert.equal(editor.ready, true);
});

test('nested upload validates before replacing preview and balances busy events on failure and success', async () => {
    const editor = templateImageEditor(config);
    const file = new Blob(['image'], { type: 'image/png' });
    file.name = 'crop.png';
    const events = [];
    editor.$dispatch = event => events.push(event);
    editor.$refs = { dialog: { close() {} } };
    editor.items = [{ image: {}, width: 100, height: 100, zoom: 1, x: 0, y: 0 }];
    editor.exportItem = async () => file;
    editor.$wire = {
        upload(path, uploaded, done) { assert.equal(path, config.uploadPath); assert.equal(uploaded, file); done(); },
        async imageUploaded(path) { assert.equal(path, config.path); return false; },
    };
    await editor.save();
    assert.match(editor.error, /Unable to save/);
    assert.equal(editor.selectedPreview, '');
    assert.equal(editor.items.length, 1);
    assert.equal(editor.saving, false);
    editor.$wire.imageUploaded = async () => true;
    await editor.save();
    assert.match(editor.selectedPreview, /^blob:/);
    assert.equal(editor.items.length, 0);
    assert.deepEqual(events, ['template-image-upload-start', 'template-image-upload-finish', 'template-image-upload-start', 'template-image-upload-finish']);
    editor.destroy();
});

test('unconstrained original upload preserves animation and is unavailable for constrained fields', async () => {
    for (const options of [{}, { aspectRatio: 1 }, { sizes: [{ width: 200, height: 200 }] }]) {
        const editor = templateImageEditor({ ...config, ...options });
        const original = { name: 'animated.gif' };
        editor.items = [{ image: {}, originalFile: original }];
        editor.$dispatch = () => {};
        editor.$refs = { dialog: { close() {} } };
        let uploaded;
        editor.uploadImage = async file => { uploaded = file; };
        await editor.useOriginal();
        assert.equal(uploaded, Object.keys(options).length ? undefined : original);
    }
});

test('pending uploaded previews take precedence over previous URL values after a form remount', () => {
    const editor = templateImageEditor({ ...config, hasUpload: true, value: 'https://example.test/old.png', preview: '/temporary/new.png' });
    assert.equal(editor.previewUrl, '/temporary/new.png');
});
