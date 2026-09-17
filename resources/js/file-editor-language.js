import language from './vendor/trafficops-template-language.cjs';

export const TPL_LANGUAGE = 'fast-landings-tpl';

export function fileLanguage(path, content = '') {
    if (/\.tpl(?:\.(?:html|php|txt))?$/i.test(path) || /^\s*@template\b/.test(content.replace(/^\uFEFF/, ''))) return TPL_LANGUAGE;
    if (/\.(?:html?|php|txt)$/i.test(path) && (/^\s*@validation\b/m.test(content)
        || /(?<![\\{])\{(?:query|headers|body)\.[A-Za-z0-9_*][A-Za-z0-9_.*-]*\}(?!\})/.test(content))) return TPL_LANGUAGE;
    const extension = path.split('.').pop().toLowerCase();
    return ({ html: 'html', htm: 'html', css: 'css', scss: 'scss', less: 'less',
        js: 'javascript', mjs: 'javascript', cjs: 'javascript', jsx: 'javascript',
        ts: 'typescript', tsx: 'typescript', json: 'json', map: 'json',
        svg: 'xml', xml: 'xml', md: 'markdown', markdown: 'markdown',
        yaml: 'yaml', yml: 'yaml', php: 'php' })[extension] || 'plaintext';
}

export function fileUri(workspaceId, path) {
    return `inmemory://fast-landings/${encodeURIComponent(workspaceId)}/${path.split('/').map(encodeURIComponent).join('/')}`;
}

// Includes are resolved from the package root, just as in the VS Code project loader.
// Unreferenced fragments must not leak their declarations into another template.
export function tplProject(sources, currentPath, content, workspaceId) {
    const all = { ...sources, [currentPath]: content };
    const walk = (root) => {
        const paths = new Set();
        const visit = (path) => {
            if (paths.has(path) || !Object.hasOwn(all, path) || typeof all[path] !== 'string') return;
            paths.add(path);
            const document = language.parseDocument(path, all[path]);
            for (const include of document.includes) {
                const target = include.path.replace(/\\/g, '/').replace(/^\.\//, '');
                if (!target.startsWith('/') && !target.split('/').includes('..')) visit(target);
            }
        };
        visit(root);
        return paths;
    };
    let paths = walk(currentPath);
    const entries = Object.keys(all).filter(path => /^\s*@template\b/.test(all[path].replace(/^\uFEFF/, '')));
    const pages = Object.keys(all).filter(path => /\.tpl\.(?:html|php)$/i.test(path));
    for (const entry of entries) {
        // Website pages share declarations at generation time. Keep completion
        // scope consistent while still excluding unused .tpl source fragments.
        const included = new Set([...walk(entry), ...pages.flatMap(page => [...walk(page)])]);
        if (included.has(currentPath)) { paths = included; break; }
    }
    return language.buildProject([...paths].map(path => ({ uri: fileUri(workspaceId, path), text: all[path] })));
}

export function includeCompletions(files, currentPath, line, column) {
    const prefix = line.slice(0, column);
    const match = /^\s*@include\s+(["'])([^"']*)$/.exec(prefix);
    if (!match) return null;
    const suffix = line.slice(column).match(/^[^"']*/)[0];
    return files.filter(file => file.path !== currentPath && file.editable !== false
        && /\.(?:tpl(?:\.(?:html|php|txt))?|html?|txt)$/i.test(file.path)
        && file.path.toLowerCase().startsWith(match[2].toLowerCase()))
        .map(file => ({ label: file.path, insertText: file.path,
            startColumn: column - match[2].length + 1, endColumn: column + suffix.length + 1 }));
}

export { language };
