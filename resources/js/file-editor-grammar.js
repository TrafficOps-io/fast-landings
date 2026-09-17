export function tplGrammar(htmlLanguage, language) {
    // The directive vocabulary and authoring rules come from the same language
    // model as the VS Code extension; this adapter only supplies Monarch tokens.
    const empty = language.buildProject([{ uri: 'template.tpl', text: '' }]);
    const names = language.getCompletions(empty, 'template.tpl', 0).map(value => value.label.slice(1));
    const directive = new RegExp(`^\\s*@@(?:${names.join('|')})\\b`);
    const expression = [
        [/&/, 'keyword'], [/\b[A-Za-z][A-Za-z0-9_]*\b/, 'variable'],
        [/\./, 'delimiter'], [/[ \t\r]+/, ''], [/./, ''],
    ];
    const runtimeStart = /(?<![\\{])\{(?=(?:query|headers|body)\.)/;
    const runtimeEscape = [/\\\{(?:query|headers|body)\.[A-Za-z0-9_.*-]+\}/, 'string.escape'];
    const runtimeExpression = [
        [/\}/, 'delimiter', '@pop'], [/\n/, '', '@pop'],
        [/\b(?:query|headers|body)\b/, 'keyword'],
        [/[A-Za-z0-9_][A-Za-z0-9_-]*/, 'variable'], [/[.*]/, 'delimiter'], [/./, ''],
    ];
    const phpAttribute = state => htmlLanguage.tokenizer.phpInSimpleState
        ? [[/<\?(?:php\b|=)/, { token: '@rematch', switchTo: `@phpInSimpleState.${state}` }]] : [];
    const tokenizer = {
        ...htmlLanguage.tokenizer,
        root: [
            // Monarch expands @name attributes and treats @@ as one literal @.
            [/^\s*@@@@/, 'string.escape'],
            [/^\s*@@previewData\s*$/, 'keyword', '@tplPreviewData'],
            [directive, 'keyword', '@tplDirective'],
            [/\{\{/, 'delimiter', '@tplExpression'],
            runtimeEscape,
            [runtimeStart, 'delimiter', '@tplRuntime'],
            ...htmlLanguage.tokenizer.root.slice(0, -1),
            [/[^<{\\]+/, ''], [/[<{\\]/, ''],
        ],
        otherTag: [
            [/\{\{/, 'delimiter', '@tplExpression'],
            runtimeEscape,
            [runtimeStart, 'delimiter', '@tplRuntime'],
            [/"/, 'attribute.value', '@tplDoubleAttribute'],
            [/'/, 'attribute.value', '@tplSingleAttribute'],
            ...htmlLanguage.tokenizer.otherTag.filter(rule => !String(rule[0]).startsWith('/"') && !String(rule[0]).startsWith("/'")),
        ],
        tplDoubleAttribute: [...phpAttribute('tplDoubleAttribute'), [/\{\{/, 'delimiter', '@tplExpression'], runtimeEscape, [runtimeStart, 'delimiter', '@tplRuntime'], [/"/, 'attribute.value', '@pop'], [/[^"{\\<]+/, 'attribute.value'], [/./, 'attribute.value']],
        tplSingleAttribute: [...phpAttribute('tplSingleAttribute'), [/\{\{/, 'delimiter', '@tplExpression'], runtimeEscape, [runtimeStart, 'delimiter', '@tplRuntime'], [/'/, 'attribute.value', '@pop'], [/[^'{\\<]+/, 'attribute.value'], [/./, 'attribute.value']],
        tplDirective: [
            [/\n/, '', '@pop'], [/"/, 'string', '@tplDoubleString'], [/'/, 'string', '@tplSingleString'],
            [/\b[A-Z][A-Za-z0-9_]*(?:\[\])?/, 'type'], [/\b(?:true|false|in)\b/, 'keyword'],
            [/[A-Za-z][A-Za-z0-9_]*(?=\s*=)/, 'attribute.name'],
            [/[-+]?\d+(?:\.\d+)?/, 'number'], [/[A-Za-z][A-Za-z0-9_]*/, 'variable'],
            [/[=():,.\[\]]/, 'delimiter'], [/[ \t\r]+/, ''], [/./, ''],
        ],
        tplDoubleString: [[/\n/, '', '@popall'], runtimeEscape, [runtimeStart, 'delimiter', '@tplRuntime'], [/\\./, 'string.escape'], [/"/, 'string', '@pop'], [/[^"{\\\n]+/, 'string'], [/./, 'string']],
        tplSingleString: [[/\n/, '', '@popall'], runtimeEscape, [runtimeStart, 'delimiter', '@tplRuntime'], [/\\./, 'string.escape'], [/'/, 'string', '@pop'], [/[^'{\\\n]+/, 'string'], [/./, 'string']],
        tplExpression: [[/\}\}/, 'delimiter', '@pop'], [/\n/, '', '@pop'], ...expression],
        tplRuntime: runtimeExpression,
        tplPreviewData: [
            [/^\s*@@endpreviewData\s*$/, 'keyword', '@pop'],
            [/"(?:[^"\\]|\\.)*"?/, 'string'],
            [/\b(?:true|false|null)\b/, 'keyword'],
            [/-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][-+]?\d+)?/, 'number'],
            [/[{}\[\]:,]/, 'delimiter'], [/\s+/, ''], [/./, 'invalid'],
        ],
    };
    for (const [state, embedded] of [['scriptEmbedded', 'javascript'], ['styleEmbedded', 'css']]) {
        tokenizer[state] = [
            [/\{\{/, { token: 'delimiter', next: `@tplEmbeddedExpression.${embedded}`, nextEmbedded: '@pop' }],
            [runtimeStart, { token: 'delimiter', next: `@tplEmbeddedRuntime.${embedded}`, nextEmbedded: '@pop' }],
            ...htmlLanguage.tokenizer[state], [/[^<{]+/, ''], [/[<{]/, ''],
        ];
    }
    tokenizer.tplEmbeddedExpression = [
        [/\}\}/, { token: 'delimiter', next: '@pop', nextEmbedded: '$S2' }],
        [/\n/, { token: '', next: '@pop', nextEmbedded: '$S2' }], ...expression,
    ];
    tokenizer.tplEmbeddedRuntime = [
        [/\}/, { token: 'delimiter', next: '@pop', nextEmbedded: '$S2' }],
        [/\n/, { token: '', next: '@pop', nextEmbedded: '$S2' }], ...runtimeExpression.slice(2),
    ];
    return { ...htmlLanguage, ignoreCase: false, includeLF: true, tokenPostfix: '.tpl', tokenizer };
}
