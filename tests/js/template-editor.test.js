import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = {};
const { markdownInsert, previewImageUrl, validEditorUrl } = await import('../../resources/js/template-editor.js');

test('formatting preserves surrounding source and places the cursor inside the markup', () => {
    assert.deepEqual(markdownInsert('First selected last', 6, 14, '**', '**', 'bold'), {
        value: 'First **selected** last', from: 8, to: 16,
    });
    assert.deepEqual(markdownInsert('First', 5, 5, '## ', '', 'Heading'), {
        value: 'First\n## Heading', from: 9, to: 16,
    });
});

test('only managed image paths are mapped to private preview URLs', () => {
    const base = 'https://panel.test/admin/template-media/__FILE__?release=ABC';
    assert.equal(previewImageUrl('_media/ABC123.png', base), 'https://panel.test/admin/template-media/ABC123.png?release=ABC');
    assert.equal(previewImageUrl('https://example.test/image.png', base), 'https://example.test/image.png');
    assert.equal(previewImageUrl('_media/../../secret.png', base), '_media/../../secret.png');
});

test('image and link dialogs reject executable schemes and credential URLs', () => {
    for (const value of ['javascript:alert(1)', 'data:image/svg+xml,test', '//example.test/image', 'https://user:secret@example.test/image']) {
        assert.equal(validEditorUrl(value), false);
        assert.equal(validEditorUrl(value, true), false);
    }
    assert.equal(validEditorUrl('mailto:reader@example.test'), true);
    assert.equal(validEditorUrl('mailto:reader@example.test', true), false);
    assert.equal(validEditorUrl('https://example.test/photo.jpg', true), true);
});

test('Markdown image insertion replaces selected text and escapes alt text', () => {
    const state = window.templateEditor({ type: 'markdown', mediaUrl: '' });
    state.value = 'before selected after';
    state.alt = '[image]';
    state.$refs = { source: { selectionStart: 7, selectionEnd: 15, focus() {}, setSelectionRange() {} } };
    state.$wire = { $set() {} };
    state.$nextTick = (callback) => callback();
    state.rememberSelection();
    state.insertImage('https://example.test/a(b).png');
    assert.equal(state.value, 'before ![\\[image\\]](<https://example.test/a(b).png>) after');
});

test('Markdown settings autocomplete replaces a runtime fragment and synchronizes the stored value', () => {
    const state = window.templateEditor({ type: 'markdown', mediaUrl: '', macros: [{ token: '{body.name}', label: 'String' }] });
    state.value = 'Thanks **{body.na}**!';
    let stored;
    state.$refs = { source: { selectionStart: 16, selectionEnd: 16, focus() {}, setSelectionRange() {} } };
    state.$wire = { $set(_path, value) { stored = value; } };
    state.$nextTick = callback => callback();
    state.captureMacros();
    assert.equal(state.macroOpen, true);
    assert.equal(state.filteredMacros.length, 1);
    state.selectMacro(state.filteredMacros[0]);
    assert.equal(stored, 'Thanks **{body.name}**!');
    assert.equal(state.macroOpen, false);
});
