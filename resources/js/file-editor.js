let editorModule;

export function fileEditor(config, loadEditor = () => (editorModule ??= import('./file-editor-monaco.js'))) {
    // Monaco objects must remain outside Alpine's reactive proxy.
    let instance;
    let alive = true;
    let baseline = config.content ?? '';
    let pendingSave;
    let stopChange;
    let onSave;
    let onBeforeUnload;
    let onNavigate;
    let onBusy;
    let busy = false;

    return {
        dirty: false,
        saving: false,
        loading: true,
        error: '',

        async init() {
            onSave = event => {
                if (event.detail?.workspaceId && event.detail.workspaceId !== config.workspaceId) return;
                if (typeof event.detail?.resolve === 'function') this.save().then(event.detail.resolve);
            };
            onBusy = event => {
                if (event.detail?.workspaceId && event.detail.workspaceId !== config.workspaceId) return;
                busy = Boolean(event.detail?.busy);
                instance?.editor.updateOptions({ readOnly: busy || Boolean(config.readOnly) });
            };
            onBeforeUnload = event => {
                if (!this.dirty || config.guardNavigation === false) return;
                event.preventDefault();
                event.returnValue = '';
            };
            onNavigate = event => {
                if (config.guardNavigation !== false && this.dirty && !window.confirm(config.messages?.unsaved ?? 'Leave this page and discard unsaved file changes?')) event.preventDefault();
            };
            window.addEventListener('file-editor-save', onSave);
            window.addEventListener('file-editor-busy', onBusy);
            window.addEventListener('beforeunload', onBeforeUnload);
            document.addEventListener('livewire:navigate', onNavigate);
            try {
                const { createFileEditor } = await loadEditor();
                if (!alive) return;
                instance = createFileEditor(this.$refs.editor, config, () => this.save());
                if (busy) instance.editor.updateOptions({ readOnly: true });
                stopChange = instance.editor.onDidChangeModelContent(() => this.changed());
                this.loading = false;
                this.changed();
            } catch (error) {
                if (!alive) return;
                this.loading = false;
                this.error = config.messages?.loadingError ?? 'The code editor could not load. Reload the page to try again.';
                console.error('File editor could not load', error);
            }
        },

        changed() {
            this.dirty = Boolean(instance && instance.editor.getValue() !== baseline);
            this.$dispatch('file-editor-dirty', { dirty: this.dirty, path: config.path, workspaceId: config.workspaceId });
        },

        async save() {
            if (pendingSave) return pendingSave;
            if (this.loading || this.error && !instance) return false;
            if (!this.dirty || config.readOnly) return true;
            const content = instance.editor.getValue();
            this.saving = true;
            this.error = '';
            pendingSave = (async () => {
                try {
                    const saved = await this.$wire.saveFile(content);
                    if (saved !== true || !alive) return false;
                    baseline = content;
                    this.changed();
                    // A keystroke during the request must not be lost by a following navigation.
                    return !this.dirty;
                } catch {
                    if (alive) this.error = config.messages?.saveError ?? 'The file could not be saved. Your changes are still in the editor.';
                    return false;
                } finally {
                    pendingSave = null;
                    if (alive) this.saving = false;
                }
            })();
            return pendingSave;
        },

        destroy() {
            alive = false;
            window.removeEventListener('file-editor-save', onSave);
            window.removeEventListener('file-editor-busy', onBusy);
            window.removeEventListener('beforeunload', onBeforeUnload);
            document.removeEventListener('livewire:navigate', onNavigate);
            stopChange?.dispose();
            instance?.dispose();
        },
    };
}
