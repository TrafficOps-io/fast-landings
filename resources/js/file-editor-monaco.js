// Monaco 0.57 exposes public subpaths relative to esm/vs and bundles editor contributions in editor.main.
import * as monaco from 'monaco-editor/editor/editor.main.js';
import { jsonDefaults } from 'monaco-editor/languages/features/json/register.js';
import { language as phpLanguage } from 'monaco-editor/languages/definitions/php/php.js';
import EditorWorker from 'monaco-editor/editor/editor.worker.js?worker';
import HtmlWorker from 'monaco-editor/languages/features/html/html.worker.js?worker';
import CssWorker from 'monaco-editor/languages/features/css/css.worker.js?worker';
import JsonWorker from 'monaco-editor/languages/features/json/json.worker.js?worker';
import TypescriptWorker from 'monaco-editor/languages/features/typescript/ts.worker.js?worker';
import tplConfiguration from './vendor/trafficops-language-configuration.json';
import { TPL_LANGUAGE, fileLanguage, fileUri, tplProject, includeCompletions, language } from './file-editor-language';
import { tplGrammar } from './file-editor-grammar';
import '../css/file-editor.css';

// Workers are bundled by Vite and served from the application's own origin.
self.MonacoEnvironment = {
    getWorker(_moduleId, label) {
        if (label === 'html') return new HtmlWorker();
        if (['css', 'scss', 'less'].includes(label)) return new CssWorker();
        if (label === 'json') return new JsonWorker();
        if (['javascript', 'typescript'].includes(label)) return new TypescriptWorker();
        return new EditorWorker();
    },
};
jsonDefaults.setDiagnosticsOptions({ validate: true, enableSchemaRequest: false });

const sessions = new WeakMap();
const rangeAt = (model, start, end) => {
    const from = model.getPositionAt(start), to = model.getPositionAt(end);
    return new monaco.Range(from.lineNumber, from.column, to.lineNumber, to.column);
};
const kinds = Object.fromEntries(Object.entries({ type: 'Struct', field: 'Field', variable: 'Variable',
    function: 'Function', keyword: 'Keyword', property: 'Property', file: 'File', snippet: 'Snippet' })
    .map(([key, value]) => [key, monaco.languages.CompletionItemKind[value]]));

function projectFor(model) {
    const session = sessions.get(model);
    if (!session) return null;
    if (session.version !== model.getVersionId()) {
        session.project = tplProject(session.config.sources ?? {}, session.config.path, model.getValue(), session.config.workspaceId);
        session.version = model.getVersionId();
    }
    return session.project;
}


monaco.languages.register({ id: TPL_LANGUAGE, extensions: ['.tpl', '.tpl.html', '.tpl.php', '.tpl.txt'], aliases: ['Fast Landings TPL'] });
monaco.languages.setMonarchTokensProvider(TPL_LANGUAGE, tplGrammar(phpLanguage, language));
monaco.languages.setLanguageConfiguration(TPL_LANGUAGE, {
    ...tplConfiguration,
    wordPattern: new RegExp(tplConfiguration.wordPattern),
    surroundingPairs: tplConfiguration.surroundingPairs.map(([open, close]) => ({ open, close })),
    indentationRules: Object.fromEntries(Object.entries(tplConfiguration.indentationRules).map(([key, value]) => [key, new RegExp(value)])),
    folding: { markers: Object.fromEntries(Object.entries(tplConfiguration.folding.markers).map(([key, value]) => [key, new RegExp(value)])) },
    onEnterRules: tplConfiguration.onEnterRules.map(rule => ({
        beforeText: new RegExp(rule.beforeText), ...(rule.afterText ? { afterText: new RegExp(rule.afterText) } : {}),
        action: { indentAction: rule.action.indent === 'indentOutdent' ? monaco.languages.IndentAction.IndentOutdent : monaco.languages.IndentAction.Indent },
    })),
});

monaco.languages.registerCompletionItemProvider([TPL_LANGUAGE, 'html', 'php'], {
    triggerCharacters: ['@', '.', '{', '&', ' ', ':', '(', '"', "'", '/', '['],
    provideCompletionItems(model, position) {
        const session = sessions.get(model), project = projectFor(model);
        if (!project) return { suggestions: [] };
        const document = project.documents.get(model.uri.toString()), offset = model.getOffsetAt(position);
        if (document?.phpSpans.some(span => span.start <= offset && (offset < span.end || (!span.closed && offset === span.end)))
            || document?.previewBlocks.some(block => block.bodyStart <= offset && (offset < block.bodyEnd || (!block.closed && offset === document.text.length)))) return { suggestions: [] };
        const includes = includeCompletions(session.config.files ?? [], session.config.path, model.getLineContent(position.lineNumber), position.column - 1);
        if (includes) return { suggestions: includes.map(value => ({ ...value, kind: monaco.languages.CompletionItemKind.File,
            range: new monaco.Range(position.lineNumber, value.startColumn, position.lineNumber, value.endColumn) })) };
        return { suggestions: language.getCompletions(project, model.uri.toString(), offset).map(value => ({
            label: value.label, kind: kinds[value.kind] ?? monaco.languages.CompletionItemKind.Text,
            detail: value.detail, documentation: value.documentation ? { value: value.documentation } : undefined,
            insertText: value.insertText ?? value.label,
            insertTextRules: value.snippet ? monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet : undefined,
            range: value.range ? rangeAt(model, value.range.start, value.range.end) : monaco.Range.fromPositions(position),
        })) };
    },
});
monaco.languages.registerHoverProvider([TPL_LANGUAGE, 'html', 'php'], {
    provideHover(model, position) {
        const project = projectFor(model);
        const value = project && language.getHover(project, model.uri.toString(), model.getOffsetAt(position));
        return value ? { contents: [{ value: value.contents }], range: rangeAt(model, value.range.start, value.range.end) } : null;
    },
});
monaco.languages.registerSignatureHelpProvider(TPL_LANGUAGE, {
    signatureHelpTriggerCharacters: ['(', ','],
    provideSignatureHelp(model, position) {
        const project = projectFor(model);
        const value = project && language.getSignatureHelp(project, model.uri.toString(), model.getOffsetAt(position));
        return value ? { value: { signatures: [{ label: value.label, parameters: value.parameters }], activeSignature: 0,
            activeParameter: Math.max(0, Math.min(value.activeParameter, value.parameters.length - 1)) }, dispose() {} } : null;
    },
});
monaco.languages.registerDocumentSymbolProvider(TPL_LANGUAGE, {
    provideDocumentSymbols(model) {
        const project = projectFor(model);
        if (!project) return [];
        const symbolKinds = { type: 'Struct', field: 'Field', variable: 'Variable', block: 'Function', layout: 'Module', section: 'Namespace', validation: 'Namespace' };
        const convert = value => ({ name: value.name, detail: value.detail ?? '', kind: monaco.languages.SymbolKind[symbolKinds[value.kind] ?? 'Variable'],
            tags: [], range: rangeAt(model, value.start, value.end), selectionRange: rangeAt(model, value.selectionStart, value.selectionEnd),
            children: (value.children ?? []).map(convert) });
        return language.getSymbols(project, model.uri.toString()).map(convert);
    },
});

export function createFileEditor(container, config, save) {
    const model = monaco.editor.createModel(config.content ?? '', fileLanguage(config.path, config.content), monaco.Uri.parse(fileUri(config.workspaceId, config.path)));
    sessions.set(model, { config, version: -1, project: null });
    const dark = () => document.documentElement.dataset.theme === 'dark' || document.documentElement.classList.contains('dark');
    const editor = monaco.editor.create(container, {
        model, theme: dark() ? 'vs-dark' : 'vs', readOnly: Boolean(config.readOnly),
        automaticLayout: true, minimap: { enabled: false }, fontSize: 13, lineHeight: 21,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
        scrollBeyondLastLine: false, padding: { top: 16, bottom: 16 }, tabSize: 4,
        renderWhitespace: 'selection', wordWrap: 'on', fixedOverflowWidgets: true,
        quickSuggestions: { other: true, comments: false, strings: true },
        suggest: { insertMode: 'replace' }, ariaLabel: config.path,
    });
    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, save);
    const observer = new MutationObserver(() => monaco.editor.setTheme(dark() ? 'vs-dark' : 'vs'));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
    return { editor, dispose() { observer.disconnect(); sessions.delete(model); editor.dispose(); model.dispose(); } };
}
