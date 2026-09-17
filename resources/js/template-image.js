import { imageEditor } from '@trafficops/fast-landings-ui/image-editor';

// Ratio-only crops retain as many source pixels as possible, without enlarging.
export function imageTarget(source, config) {
    if (config.sizes?.length) return config.sizes[source?.preset ?? 0];
    const width = source?.width || 1;
    const height = source?.height || 1;
    const ratio = config.aspectRatio || width / height;
    const targetWidth = Math.min(source?.outputWidth || Math.min(width, height * ratio), 4096, 4096 * ratio);
    return { width: Math.max(1, Math.round(targetWidth)), height: Math.max(1, Math.round(targetWidth / ratio)) };
}

export function templateImageEditor(config) {
    const editor = imageEditor({
        ...config,
        transparent: true,
        resolveTarget: source => imageTarget(source, config),
        messages: {
            tooMany: 'Choose one image at a time.',
            invalidFile: `Choose a JPEG, PNG, GIF, WebP or AVIF image up to ${config.maxBytes / 1024 / 1024} MB.`,
            loadFailed: 'Unable to open this image. For external URLs, the server must allow cross-origin image editing.',
            exportFailed: 'Unable to export this image within the upload limit. Try a smaller image.',
            uploadFailed: 'Upload failed. Please try again.',
        },
        async saveFiles(files) { await this.uploadImage(files[0]); },
    });
    const saveCrop = editor.save;
    const destroyEditor = editor.destroy;
    return Object.defineProperties(editor, Object.getOwnPropertyDescriptors({
        url: config.value || '', preview: config.preview || '', previewFailed: false,
        selectedPreview: '', selectedName: '',
        init() {
            this.$watch('url', value => { this.$wire.$set(config.valuePath, value, false); this.previewFailed = false; });
        },
        get previewUrl() { return this.selectedPreview || (config.hasUpload ? this.preview : '') || (/^https?:\/\//i.test(this.url) ? this.url : this.url === config.value ? this.preview : ''); },
        setOutputWidth(value) {
            const width = Number(value);
            if (this.current && Number.isFinite(width) && width >= 1) this.current.outputWidth = Math.round(width);
        },
        async editCurrent() { await this.edit(this.previewUrl, this.selectedName || this.url.split('/').pop() || 'image.png', null); },
        async uploadImage(file) {
            await new Promise((resolve, reject) => this.$wire.upload(config.uploadPath, file, resolve,
                () => reject(new Error('Upload failed. Please try again.')),
                event => { this.progress = event.detail.progress; },
                () => reject(new Error('Upload cancelled.'))));
            if (!await this.$wire.imageUploaded(config.path)) throw new Error('Unable to save this image. Check the template requirements.');
            if (this.selectedPreview) URL.revokeObjectURL(this.selectedPreview);
            this.selectedPreview = URL.createObjectURL(file);
            this.selectedName = file.name;
            this.previewFailed = false;
        },
        async save() {
            if (!this.ready) return;
            this.$dispatch('template-image-upload-start');
            try { await saveCrop.call(this); }
            finally { this.$dispatch('template-image-upload-finish'); }
        },
        async useOriginal() {
            if (!this.ready || !this.current.originalFile || config.aspectRatio || config.sizes?.length) return;
            this.saving = true; this.error = ''; this.progress = 0;
            this.$dispatch('template-image-upload-start');
            try {
                await this.uploadImage(this.current.originalFile);
                this.saving = false; this.close();
            } catch { this.error = 'Upload failed. Please try again.'; }
            finally { this.saving = false; this.$dispatch('template-image-upload-finish'); }
        },
        async clearImage() {
            this.saving = true;
            try { await this.$wire.clearImage(config.path); }
            finally { this.saving = false; }
        },
        destroy() {
            destroyEditor.call(this);
            if (this.selectedPreview) URL.revokeObjectURL(this.selectedPreview);
        },
    }));
}
