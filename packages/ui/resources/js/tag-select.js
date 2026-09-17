export function tagSelectState(state, options = [], { splitOnSpace = false, kind = 'tag' } = {}) {
    return {
        ...state,
        options, splitOnSpace, kind, query: '', open: false, active: -1,
        get selected() { return this.getSelected(); },
        has(option) { return this.selected.some(item => item.name === option.name && item.kind === option.kind); },
        get filtered() {
            const query = this.query.trim().toLocaleLowerCase();
            return this.options.filter(option => !this.has(option) && `${option.name} ${option.label ?? ''}`.toLocaleLowerCase().includes(query));
        },
        get canCreate() {
            const name = this.query.trim();
            return name && !this.options.some(option => option.name === name) && !this.selected.some(option => option.name === name);
        },
        get choices() { return [...this.filtered, ...(this.canCreate ? [{ name: this.query.trim(), kind: this.kind, create: true }] : [])]; },
        add(option) {
            if (!option || this.has(option)) return;
            this.select(option);
            this.query = ''; this.active = -1; this.open = true;
        },
        commit() {
            const names = this.query.split(this.splitOnSpace ? /[\s,]+/u : /[,\r\n]+/u).map(name => name.trim()).filter(Boolean);
            for (const name of names) {
                this.add(this.options.find(option => option.name === name) ?? { name, kind: this.kind });
            }
            this.query = ''; this.active = -1;
        },
        input() {
            this.open = true; this.active = -1;
            if ((this.splitOnSpace ? /[\s,]/u : /[,\r\n]/u).test(this.query)) this.commit();
        },
        move(direction) {
            this.open = true;
            const length = this.choices.length;
            this.active = !length ? -1 : (this.active < 0 ? (direction > 0 ? 0 : length - 1) : (this.active + direction + length) % length);
            this.$nextTick(() => this.$refs.list?.querySelector(`[data-option-index="${this.active}"]`)?.scrollIntoView({ block: 'nearest' }));
        },
        keydown(event) {
            if (event.isComposing) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault(); this.move(event.key === 'ArrowDown' ? 1 : -1);
            } else if (event.key === 'Enter' || event.key === ',' || (this.splitOnSpace && event.key === ' ')) {
                event.preventDefault();
                if (event.key === 'Enter' && this.open && this.active >= 0) this.add(this.choices[this.active]);
                else this.commit();
            } else if (event.key === 'Escape') {
                event.preventDefault(); this.open = false; this.active = -1;
            }
        },
    };
}

export function tagSelect(values) {
    return tagSelectState({
        values,
        getSelected() { return [...new Set(this.values ?? [])].map(name => ({ name, kind: 'tag' })); },
        select(option) { this.values = [...this.selected.map(item => item.name), option.name]; },
        remove(option) { this.values = (this.values ?? []).filter(name => name !== option.name); },
    });
}
