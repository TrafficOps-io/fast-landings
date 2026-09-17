const MAX_BYTES = 10 * 1024 * 1024;
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

// The preview and exported canvas share coordinates in output pixels.
export function imagePlacement(source, target, zoom = 1, x = 0, y = 0) {
    const scale = Math.max(target.width / source.width, target.height / source.height) * zoom;
    const width = source.width * scale;
    const height = source.height * scale;
    return { width, height, left: (target.width - width) / 2 + x * target.width,
        top: (target.height - height) / 2 + y * target.height, scale };
}

export function fitZoom(source, target) {
    return Math.min(target.width / source.width, target.height / source.height)
        / Math.max(target.width / source.width, target.height / source.height);
}

export function imagePreset(source, sizes) {
    const ratio = source.width / source.height;
    return sizes.reduce((best, size, index) =>
        Math.abs(Math.log(ratio / (size.width / size.height))) < Math.abs(Math.log(ratio / (sizes[best].width / sizes[best].height))) ? index : best, 0);
}

export function imageEditor(config) {
    let drag = null;
    let dragDepth = 0;
    let generation = 0;
    return {
        items: [], index: 0, mediaId: null, loading: false, saving: false, dragging: false, progress: 0, error: '',
        get current() { return this.items[this.index] ?? null; },
        get target() { return config.resolveTarget ? config.resolveTarget(this.current) : config.sizes[this.current?.preset ?? 0]; },
        get placement() {
            return this.current ? imagePlacement(this.current, this.target, this.current.zoom, this.current.x, this.current.y) : null;
        },
        get imageStyle() {
            const p = this.placement;
            if (!p) return '';
            return `width:${p.width / this.target.width * 100}%;height:${p.height / this.target.height * 100}%;left:${p.left / this.target.width * 100}%;top:${p.top / this.target.height * 100}%`;
        },
        get outputLabel() { return `${this.target.width} × ${this.target.height} px${this.target.ratio ? ` · ${this.target.ratio}` : ''}`; },
        get lowResolution() { return this.placement?.scale > 1.01; },
        get ready() { return Boolean(this.current?.image) && !this.loading && !this.saving; },
        async selectFiles(event) {
            const files = Array.from(event.target.files ?? []);
            event.target.value = '';
            await this.acceptFiles(files);
        },
        dragEnter(event) {
            if (this.saving || this.loading || !Array.from(event.dataTransfer?.types ?? []).includes('Files')) return;
            dragDepth++; this.dragging = true;
        },
        dragOver(event) {
            if (event.dataTransfer) event.dataTransfer.dropEffect = this.saving || this.loading ? 'none' : 'copy';
        },
        dragLeave() { dragDepth = Math.max(0, dragDepth - 1); this.dragging = dragDepth > 0; },
        async dropFiles(event) {
            dragDepth = 0; this.dragging = false;
            await this.acceptFiles(Array.from(event.dataTransfer?.files ?? []));
        },
        async acceptFiles(files) {
            if (this.saving || this.loading) return;
            if (!files.length) return;
            const remaining = Number(this.$root.dataset.remaining);
            if (files.length > remaining) { this.error = config.messages.tooMany.replace(':count', remaining); return; }
            if (files.some(file => !config.mimeTypes.includes(file.type) || file.size > (config.maxBytes ?? MAX_BYTES))) {
                this.error = config.messages.invalidFile; return;
            }
            await this.open(files.map(file => ({ url: URL.createObjectURL(file), name: file.name, temporary: true, originalFile: file })), null);
        },
        async edit(url, name, id) { await this.open([{ url, name, temporary: false }], id); },
        async open(sources, id) {
            this.release();
            const version = ++generation;
            this.error = ''; this.index = 0; this.mediaId = id; this.loading = true;
            this.items = sources.map(source => ({ ...source, image: null, width: 0, height: 0,
                preset: 0, zoom: 1, x: 0, y: 0, background: '#ffffff', transparent: config.transparent ?? false }));
            this.$refs.dialog.showModal();
            try {
                for (const item of this.items) {
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.src = item.url;
                    await img.decode();
                    if (version !== generation) return;
                    item.image = img; item.width = img.naturalWidth; item.height = img.naturalHeight;
                    item.preset = config.sizes?.length ? imagePreset(item, config.sizes) : 0;
                }
            } catch { if (version === generation) this.error = config.messages.loadFailed; }
            finally { if (version === generation) this.loading = false; }
        },
        setPreset(value) { this.current.preset = Number(value); this.reset(); },
        reset() { this.current.zoom = 1; this.current.x = 0; this.current.y = 0; },
        fit() { this.reset(); this.current.zoom = fitZoom(this.current, this.target); },
        zoomBy(amount) { if (this.ready) this.current.zoom = clamp(Number(this.current.zoom) + amount, 0.1, 4); },
        startDrag(event) {
            if (!this.ready || event.button !== 0) return;
            event.preventDefault();
            event.currentTarget.setPointerCapture(event.pointerId);
            drag = { id: event.pointerId, clientX: event.clientX, clientY: event.clientY, x: this.current.x, y: this.current.y };
        },
        moveDrag(event) {
            if (!drag || drag.id !== event.pointerId || !this.ready) return;
            const rect = event.currentTarget.getBoundingClientRect();
            this.current.x = clamp(drag.x + (event.clientX - drag.clientX) / rect.width, -1, 1);
            this.current.y = clamp(drag.y + (event.clientY - drag.clientY) / rect.height, -1, 1);
        },
        stopDrag() { drag = null; },
        nudge(x, y) {
            if (!this.ready) return;
            this.current.x = clamp(this.current.x + x, -1, 1);
            this.current.y = clamp(this.current.y + y, -1, 1);
        },
        async exportItem(item) {
            const target = config.resolveTarget ? config.resolveTarget(item) : config.sizes[item.preset];
            const canvas = document.createElement('canvas');
            canvas.width = target.width; canvas.height = target.height;
            const context = canvas.getContext('2d');
            if (!item.transparent) {
                context.fillStyle = item.background;
                context.fillRect(0, 0, canvas.width, canvas.height);
            }
            context.imageSmoothingEnabled = true; context.imageSmoothingQuality = 'high';
            const p = imagePlacement(item, target, item.zoom, item.x, item.y);
            context.drawImage(item.image, p.left, p.top, p.width, p.height);
            const type = config.outputType ?? (config.multiple ? 'image/jpeg' : 'image/png');
            const blob = await new Promise(resolve => canvas.toBlob(resolve, type, 0.92));
            if (!blob || blob.size > (config.maxBytes ?? MAX_BYTES)) throw new Error(config.messages.exportFailed);
            const name = item.name.replace(/\.[^.]+$/, '') + '-cropped.' + (type === 'image/jpeg' ? 'jpg' : 'png');
            return new File([blob], name, { type });
        },
        async save() {
            if (!this.ready || this.items.some(item => !item.image)) return;
            this.saving = true; this.progress = 0; this.error = '';
            try {
                const files = [];
                for (const item of this.items) files.push(await this.exportItem(item));
                if (config.saveFiles) {
                    await config.saveFiles.call(this, files);
                } else {
                    await new Promise((resolve, reject) => this.$wire.uploadMultiple(config.uploadProperty ?? 'croppedUpload', files, resolve,
                        () => reject(new Error(config.messages.uploadFailed)),
                        event => { this.progress = event.detail.progress; },
                        () => reject(new Error(config.messages.uploadFailed)), false));
                    const saved = await this.$wire[config.saveMethod ?? 'saveCroppedMedia'](...(config.saveArgs ?? [config.path, config.locale]), this.mediaId);
                    if (!saved) throw new Error(config.messages.saveFailed);
                }
                this.saving = false;
                this.close();
            } catch (error) {
                this.error = error.message || config.messages.uploadFailed;
            } finally { this.saving = false; }
        },
        close() {
            if (this.saving) return;
            ++generation;
            this.$refs.dialog.close(); this.release(); this.loading = false; this.error = '';
        },
        release() { this.items.forEach(item => { if (item.temporary) URL.revokeObjectURL(item.url); }); this.items = []; drag = null; dragDepth = 0; this.dragging = false; },
        destroy() { ++generation; this.release(); },
    };
}
