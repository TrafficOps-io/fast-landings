window.fileManager = function (workspaceId) {
    let beforeUnload;
    let beforeNavigate;
    return {
        busy: false,
        dirty: false,
        leaving: false,
        error: '',
        search: '',

        init() {
            beforeUnload = (event) => {
                if (!this.leaving && (this.dirty || this.$wire.pendingChanges)) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            beforeNavigate = (event) => {
                if (!this.leaving && (this.dirty || this.$wire.pendingChanges) && !event.defaultPrevented
                    && !window.confirm('Leave the file editor? Unpublished changes will be discarded.')) {
                    event.preventDefault();
                }
            };
            window.addEventListener('beforeunload', beforeUnload);
            document.addEventListener('livewire:navigate', beforeNavigate);
        },

        destroy() {
            window.removeEventListener('beforeunload', beforeUnload);
            document.removeEventListener('livewire:navigate', beforeNavigate);
        },

        async stage() {
            if (!this.dirty) return true;
            return new Promise((resolve) => window.dispatchEvent(new CustomEvent('file-editor-save', {
                detail: { workspaceId, resolve },
            })));
        },

        async run(method, ...args) {
            if (this.busy) return;
            this.busy = true;
            window.dispatchEvent(new CustomEvent('file-editor-busy', { detail: { workspaceId, busy: true } }));
            this.error = '';
            try {
                if (method !== 'discard' && !await this.stage()) return;
                this.leaving = method === 'publish' || method === 'discard';
                const result = await this.$wire[method](...args);
                if (result !== true) this.leaving = false;
            } catch {
                this.leaving = false;
                this.error = 'The file operation failed. Your editor content is still available; please try again.';
            } finally {
                this.busy = false;
                window.dispatchEvent(new CustomEvent('file-editor-busy', { detail: { workspaceId, busy: false } }));
            }
        },

        async download(url) {
            if (this.busy) return;
            this.busy = true;
            window.dispatchEvent(new CustomEvent('file-editor-busy', { detail: { workspaceId, busy: true } }));
            try {
                if (await this.stage()) {
                    // A real download link avoids interfering with Livewire navigation.
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = '';
                    document.body.append(link);
                    link.click();
                    link.remove();
                }
            } finally {
                this.busy = false;
                window.dispatchEvent(new CustomEvent('file-editor-busy', { detail: { workspaceId, busy: false } }));
            }
        },
    };
};
