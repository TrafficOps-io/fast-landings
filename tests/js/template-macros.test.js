import test from 'node:test';
import assert from 'node:assert/strict';
import { runtimeMacroRange, insertRuntimeMacro, templateMacroInput } from '../../resources/js/template-macros.js';

test('inserting inside a runtime macro replaces its complete token and preserves surrounding text', () => {
    const value = 'Спасибо, {body.na-old}!';
    const caret = value.indexOf('-old');
    const range = runtimeMacroRange(value, caret);
    assert.equal(range.query, 'body.na');
    assert.deepEqual(insertRuntimeMacro(value, range, '{body.name}'), { value: 'Спасибо, {body.name}!', cursor: 20 });
    assert.equal(runtimeMacroRange('{body.*}', 7).fragment, true);
    assert.equal(insertRuntimeMacro('{body.name', runtimeMacroRange('{body.name', 8), '{body.name}').value, '{body.name}');
    for (const text of ['{{body.', '\\{body.', '{"name":', 'ordinary']) {
        assert.equal(runtimeMacroRange(text, text.length).fragment, false);
    }
});

test('plain input insertion notifies Livewire and supports keyboard navigation, filtering and escape', () => {
    const state = templateMacroInput([
        { token: '{query.subid}', label: 'String · required' },
        { token: '{body.name}', label: 'String · required' },
    ]);
    let dispatched = 0;
    let cursor;
    let focused = 0;
    state.$refs = {
        macroInput: { value: 'Hello {bo}', selectionStart: 9, selectionEnd: 9,
            dispatchEvent(event) { assert.equal(event.type, 'input'); dispatched++; }, focus() { focused++; },
            setSelectionRange(from, to) { assert.equal(from, to); cursor = from; } },
        macroList: { querySelector() { return null; } },
    };
    state.$nextTick = callback => callback();
    state.captureMacros();
    assert.equal(state.filteredMacros.length, 1);
    assert.equal(state.macroOpen, true);
    const key = key => ({ key, preventDefault() {}, stopPropagation() {} });
    assert.equal(state.macroKeydown(key('ArrowDown')), true);
    assert.equal(state.macroKeydown(key('Enter')), true);
    assert.equal(state.$refs.macroInput.value, 'Hello {body.name}');
    assert.equal(cursor, 17);
    assert.equal(dispatched, 1);
    assert.equal(state.macroOpen, false);
    state.macroOpen = true;
    state.macroKeydown(key('Escape'));
    assert.equal(state.macroOpen, false);
    assert.equal(focused, 2);
});
