import test from 'node:test';
import assert from 'node:assert/strict';
import { compile } from 'monaco-editor/editor/standalone/common/monarch/monarchCompile.js';
import { MonarchTokenizer } from 'monaco-editor/editor/standalone/common/monarch/monarchLexer.js';
import language from '../../resources/js/vendor/trafficops-template-language.cjs';
import { tplGrammar } from '../../resources/js/file-editor-grammar.js';

// The adapter adds TPL states to HTML. These host rules keep this regression
// focused on the DSL while using Monaco's real compiler and tokenizer in Node.
const host = { tokenizer: {
    root: [[/</, 'delimiter'], [/[^<]+/, '']], otherTag: [],
    scriptEmbedded: [[/<\/script/, { token: '@rematch', next: '@pop', nextEmbedded: '@pop' }]],
    styleEmbedded: [[/<\/style/, { token: '@rematch', next: '@pop', nextEmbedded: '@pop' }]],
} };

function tokenize(lines) {
    const lexer = compile('fast-landings-tpl', tplGrammar(host, language));
    const tokenizer = new MonarchTokenizer({}, {}, 'fast-landings-tpl', lexer, {
        getValue: () => 20000, onDidChangeConfiguration: () => ({ dispose() {} }),
    });
    let state = tokenizer.getInitialState();
    try {
        return lines.map(line => {
            const result = tokenizer.tokenize(line, true, state);
            state = result.endState;
            return result.tokens.map(token => token.type);
        });
    } finally { tokenizer.dispose(); }
}

test('Monarch treats DSL @ directives and escaped @@ as literals, including previewData delimiters', () => {
    const tokens = tokenize(['@template "Example"', '@@param escaped String', '@previewData',
        '{"title": "Preview", "enabled": true}', '@endpreviewData', '@layout', '{{title}}', '@endlayout']);
    assert.equal(tokens[0][0], 'keyword.tpl');
    assert.equal(tokens[1][0], 'string.escape.tpl');
    assert.equal(tokens[2][0], 'keyword.tpl');
    assert.ok(tokens[3].includes('string.tpl'));
    assert.ok(tokens[3].includes('keyword.tpl'));
    assert.equal(tokens[4][0], 'keyword.tpl');
    assert.equal(tokens[5][0], 'keyword.tpl');
    assert.ok(tokens[6].includes('variable.tpl'));
    assert.equal(tokens[7][0], 'keyword.tpl');
});

test('incomplete declarations and interpolations recover at the next line', () => {
    const tokens = tokenize(['@param title String label="unfinished', '@layout   ', '{{title   ', '@endlayout']);
    assert.equal(tokens[0][0], 'keyword.tpl');
    assert.equal(tokens[1][0], 'keyword.tpl');
    assert.ok(tokens[2].includes('variable.tpl'));
    assert.equal(tokens[3][0], 'keyword.tpl');
});

test('runtime validation and single-brace macros receive tokens without treating ordinary braces as macros', () => {
    const tokens = tokenize(['@validation body fallback="/error"', '@param name String required min=4', '@endvalidation',
        'Hello {body.name}', '{headers.*}', '{unrelated}', '\\{body.name}', '@param title String = "Hello {body.name}"']);
    assert.equal(tokens[0][0], 'keyword.tpl');
    assert.equal(tokens[2][0], 'keyword.tpl');
    assert.ok(tokens[3].includes('keyword.tpl'));
    assert.ok(tokens[3].includes('variable.tpl'));
    assert.ok(tokens[4].includes('keyword.tpl'));
    assert.ok(!tokens[5].includes('variable.tpl'));
    assert.ok(!tokens[6].includes('variable.tpl'));
    assert.ok(tokens[7].includes('delimiter.tpl'));
});
