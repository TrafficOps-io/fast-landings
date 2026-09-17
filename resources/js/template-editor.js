import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { runtimeMacroPicker, insertRuntimeMacro, runtimeMacroRange } from './template-macros.js';

const markdownActions = {
    bold: ['**', '**', 'bold text'], italic: ['*', '*', 'italic text'],
    heading: ['## ', '', 'Heading'], bulletList: ['- ', '', 'List item'],
    orderedList: ['1. ', '', 'List item'], blockquote: ['> ', '', 'Quote'],
    codeBlock: ['```\n', '\n```', 'code'],
};

export function previewImageUrl(src, mediaUrl) {
    return /^_media\/[A-Za-z0-9]+\.(?:jpe?g|png|gif|webp|avif)$/.test(src)
        ? mediaUrl.replace('__FILE__', src.slice(7)) : src;
}

export function validEditorUrl(value, image = false) {
    try {
        const url = new URL(value);
        return (image ? ['http:', 'https:'] : ['http:', 'https:', 'mailto:']).includes(url.protocol)
            && !url.username && !url.password;
    } catch {
        return false;
    }
}

export function markdownInsert(value, from, to, before, after = '', placeholder = '') {
    const text = value.slice(from, to) || placeholder;
    if (['## ', '- ', '1. ', '> ', '```\n'].includes(before) && from > 0 && value[from - 1] !== '\n') before = '\n' + before;
    return {
        value: value.slice(0, from) + before + text + after + value.slice(to),
        from: from + before.length, to: from + before.length + text.length,
    };
}

window.templateEditor = function (config) {
    // ProseMirror must not be wrapped in Alpine's reactive Proxy.
    let editor;
    let alive = true;
    let unwatch;
    let unwatchMacros = [];
    let selection = { from: 0, to: 0 };
    const imageUrl = (src) => previewImageUrl(src, config.mediaUrl);

    return {
        ...runtimeMacroPicker(config.macros ?? []),
        get filteredMacros() { return this.matchingMacros(); },
        value: '', preview: false, loadingPreview: false, uploading: false,
        insert: '', url: '', alt: '', error: '', revision: 0,

        init() {
            const component = this;
            this.value = this.$wire.$get(`values.${config.path}`) ?? '';
            if (config.type === 'wysiwyg') {
                const EditorImage = Image.extend({
                    addNodeView() {
                        return ({ node }) => {
                            const dom = document.createElement('img');
                            const update = (next) => {
                                if (next.type.name !== 'image') return false;
                                dom.src = imageUrl(next.attrs.src);
                                dom.alt = next.attrs.alt || '';
                                dom.title = next.attrs.title || '';
                                return true;
                            };
                            update(node);
                            return { dom, update };
                        };
                    },
                });
                editor = new Editor({
                    element: this.$refs.editor,
                    extensions: [StarterKit.configure({
                        heading: { levels: [1, 2, 3, 4, 5, 6] },
                        link: { openOnClick: false, HTMLAttributes: { target: null } },
                    }), EditorImage.configure({ allowBase64: false })],
                    content: this.value,
                    editorProps: {
                        handleKeyDown: (_view, event) => component.macroKeydown(event),
                        attributes: {
                            id: config.id, role: 'textbox', 'aria-multiline': 'true',
                            'aria-labelledby': `${config.id}-label`,
                            'aria-describedby': `${config.id}-help ${config.id}-error`,
                            'aria-required': String(config.required),
                        },
                    },
                    onUpdate: () => {
                        this.value = editor.isEmpty ? '' : editor.getHTML();
                        this.sync();
                        this.captureMacros();
                    },
                    onSelectionUpdate: () => this.captureMacros(),
                    onTransaction: () => { this.revision++; },
                });
                const updateAria = () => {
                    const dom = editor.view.dom;
                    dom.setAttribute('aria-autocomplete', 'list');
                    dom.setAttribute('aria-controls', this.$id('runtime-macros'));
                    dom.setAttribute('aria-expanded', String(this.macroOpen));
                    if (this.macroOpen && this.macroActive >= 0) dom.setAttribute('aria-activedescendant', this.$id('runtime-macro', this.macroActive));
                    else dom.removeAttribute('aria-activedescendant');
                };
                unwatchMacros = [this.$watch('macroOpen', updateAria), this.$watch('macroActive', updateAria)];
                updateAria();
            }
            unwatch = this.$wire.$watch(`values.${config.path}`, (value) => {
                value = value ?? '';
                if (value === this.value) return;
                this.value = value;
                if (editor && editor.getHTML() !== value) editor.commands.setContent(value, { emitUpdate: false });
                this.preview = false;
            });
        },

        destroy() {
            alive = false;
            unwatch?.();
            unwatchMacros.forEach(stop => stop?.());
            editor?.destroy();
        },

        sync() {
            // Update Livewire's client state immediately; the next action sends it.
            this.$wire.$set(`values.${config.path}`, this.value, false);
        },

        active(command) {
            this.revision;
            if (!editor) return false;
            return editor.isActive(command === 'heading' ? 'heading' : command);
        },

        rememberSelection() {
            if (this.$refs.source) selection = { from: this.$refs.source.selectionStart, to: this.$refs.source.selectionEnd };
        },

        captureMacros() {
            if (editor) {
                const { $from, $to } = editor.state.selection;
                this.macroRange = { start: $from.pos, end: $to.pos, query: '', fragment: false };
                if (!$from.sameParent($to) || !$from.parent.isTextblock) { this.macroOpen = false; return; }
                const text = $from.parent.textBetween(0, $from.parent.content.size, '\n', '\ufffc');
                const range = runtimeMacroRange(text, $from.parentOffset, $to.parentOffset);
                this.macroRange = { ...range, start: $from.start() + range.start, end: $from.start() + range.end };
                this.macroSearch = range.query;
                this.macroOpen = range.fragment;
                this.macroActive = -1;
            } else {
                this.rememberSelection();
                this.suggestMacros(this.value, selection.from, selection.to);
            }
        },

        browseMacros() {
            const wasOpen = this.macroOpen;
            this.captureMacros();
            this.macroOpen = !wasOpen;
            this.macroSearch = '';
            if (this.macroOpen) this.$nextTick(() => this.$refs.macroSearch.focus());
        },

        restoreMacroFocus() {
            if (editor) editor.commands.focus();
            else this.$refs.source.focus();
        },

        selectMacro(option) {
            if (!option) return;
            if (editor) {
                editor.chain().focus().insertContentAt({ from: this.macroRange.start, to: this.macroRange.end },
                    { type: 'text', text: option.token }).run();
            } else {
                const change = insertRuntimeMacro(this.value, this.macroRange, option.token);
                this.value = change.value;
                selection = { from: change.cursor, to: change.cursor };
                this.sync();
                this.$nextTick(() => {
                    this.$refs.source.focus();
                    this.$refs.source.setSelectionRange(change.cursor, change.cursor);
                });
            }
            this.macroOpen = false;
        },

        replaceMarkdown(before, after, placeholder) {
            const change = markdownInsert(this.value, selection.from, selection.to, before, after, placeholder);
            this.value = change.value;
            selection = { from: change.from, to: change.to };
            this.sync();
            this.$nextTick(() => {
                this.$refs.source.focus();
                this.$refs.source.setSelectionRange(selection.from, selection.to);
            });
        },

        format(command) {
            if (!editor) {
                this.replaceMarkdown(...markdownActions[command]);
                return;
            }
            const chain = editor.chain().focus();
            const commands = {
                bold: () => chain.toggleBold(), italic: () => chain.toggleItalic(),
                heading: () => chain.toggleHeading({ level: 2 }),
                bulletList: () => chain.toggleBulletList(), orderedList: () => chain.toggleOrderedList(),
                blockquote: () => chain.toggleBlockquote(), codeBlock: () => chain.toggleCodeBlock(),
                undo: () => chain.undo(), redo: () => chain.redo(),
            };
            commands[command]?.().run();
        },

        openInsert(kind) {
            this.rememberSelection();
            this.insert = kind;
            this.error = '';
            this.url = kind === 'link' && editor ? (editor.getAttributes('link').href || '') : '';
            this.alt = '';
            this.$nextTick(() => this.$refs.url.focus());
        },

        insertUrl() {
            const url = this.url.trim();
            if (!validEditorUrl(url, this.insert === 'image')) {
                this.error = this.insert === 'image' ? 'Enter an HTTP(S) image URL.' : 'Enter an HTTP(S) or mailto link.';
                return;
            }
            this.error = '';
            if (this.insert === 'image') this.insertImage(url);
            else if (editor) editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
            else this.replaceMarkdown('[', `](<${url.replace(/[<>\s]/g, encodeURIComponent)}>)`, 'link text');
            this.insert = '';
        },

        insertImage(src) {
            if (editor) editor.chain().focus().setImage({ src, alt: this.alt }).run();
            else {
                const alt = this.alt.replace(/[\\\[\]]/g, '\\$&').replace(/\r?\n/g, ' ');
                // Images replace the selection instead of treating it as alt text.
                this.value = this.value.slice(0, selection.from) + this.value.slice(selection.to);
                selection.to = selection.from;
                this.replaceMarkdown(`![${alt}](<${src.replace(/[<>\s]/g, encodeURIComponent)}>)`, '', '');
            }
        },

        async uploadImage(event) {
            const file = event.target.files?.[0];
            if (!file || this.uploading) return;
            const key = `image_${crypto.randomUUID().replaceAll('-', '')}`;
            this.uploading = true;
            this.error = '';
            // Match the existing form-wide upload guard through the final storage step.
            this.$dispatch('livewire-upload-start');
            try {
                await new Promise((resolve, reject) => this.$wire.$upload(`editorUploads.${key}`, file, resolve, reject));
                const result = await this.$wire.uploadEditorImage(config.path, key);
                if (!alive) return;
                this.insertImage(result.src);
                this.insert = '';
            } catch {
                if (alive) this.error = 'Image upload failed. Check the image format and size, then try again.';
            } finally {
                if (alive) {
                    this.uploading = false;
                    event.target.value = '';
                    this.$dispatch('livewire-upload-finish');
                }
            }
        },

        async togglePreview() {
            if (this.preview) { this.preview = false; return; }
            this.loadingPreview = true;
            this.error = '';
            this.sync();
            try {
                const html = await this.$wire.previewMarkdown(config.path);
                if (!alive) return;
                // The server uses the exact same HTML sanitizer as static publication.
                const fragment = document.createElement('template');
                fragment.innerHTML = html;
                fragment.content.querySelectorAll('img').forEach((img) => img.setAttribute('src', imageUrl(img.getAttribute('src'))));
                this.$refs.preview.replaceChildren(fragment.content);
                this.preview = true;
            } catch {
                if (alive) this.error = 'Unable to load preview. Check the content and try again.';
            } finally {
                if (alive) this.loadingPreview = false;
            }
        },
    };
};
