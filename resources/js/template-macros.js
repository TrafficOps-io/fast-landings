// Match only runtime macro fragments, leaving template expressions and escaped
// braces alone. Replacing a fragment also consumes its suffix after the caret.
export function runtimeMacroRange(value, start, end = start) {
    const match = value.slice(0, start).match(/(?<![\\{])\{(?:[a-zA-Z0-9_.*-]*)$/);
    if (!match) return { start, end, query: '', fragment: false };
    const tail = value.slice(end).match(/^[a-zA-Z0-9_.*-]*\}?/);
    return { start: match.index, end: end + (tail?.[0].length ?? 0), query: match[0].slice(1), fragment: true };
}

export function insertRuntimeMacro(value, range, token) {
    return { value: value.slice(0, range.start) + token + value.slice(range.end), cursor: range.start + token.length };
}

export function runtimeMacroPicker(options = []) {
    return {
        macroOptions: options, macroOpen: false, macroSearch: '', macroActive: -1,
        macroRange: { start: 0, end: 0, query: '', fragment: false },
        matchingMacros() {
            const query = this.macroSearch.toLocaleLowerCase();
            return this.macroOptions.filter(option => `${option.token} ${option.label} ${option.source ?? ''}`.toLocaleLowerCase().includes(query));
        },
        suggestMacros(value, from, to = from) {
            this.macroRange = runtimeMacroRange(value, from, to);
            this.macroSearch = this.macroRange.query;
            this.macroOpen = this.macroRange.fragment;
            this.macroActive = -1;
        },
        macroKeydown(event) {
            if (event.isComposing || !this.macroOpen) return false;
            if (event.key === 'Escape') {
                this.macroOpen = false;
                this.restoreMacroFocus?.();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                const count = this.filteredMacros.length;
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                this.macroActive = !count ? -1 : this.macroActive < 0
                    ? (direction > 0 ? 0 : count - 1) : (this.macroActive + direction + count) % count;
                this.$nextTick(() => this.$refs.macroList?.querySelector(`[data-macro-index="${this.macroActive}"]`)?.scrollIntoView({ block: 'nearest' }));
            } else if (event.key === 'Enter' && this.macroActive >= 0) {
                this.selectMacro(this.filteredMacros[this.macroActive]);
            } else if (event.key === 'Tab') {
                this.macroOpen = false;
                return false;
            } else return false;
            event.preventDefault();
            event.stopPropagation();
            return true;
        },
    };
}

export function templateMacroInput(options = []) {
    return {
        ...runtimeMacroPicker(options),
        get filteredMacros() { return this.matchingMacros(); },
        captureMacros() {
            const input = this.$refs.macroInput;
            this.suggestMacros(input.value, input.selectionStart ?? input.value.length, input.selectionEnd ?? input.value.length);
        },
        restoreMacroFocus() {
            this.$refs.macroInput.focus();
        },
        browseMacros() {
            const wasOpen = this.macroOpen;
            this.captureMacros();
            this.macroOpen = !wasOpen;
            this.macroSearch = '';
            if (this.macroOpen) this.$nextTick(() => this.$refs.macroSearch.focus());
        },
        selectMacro(option) {
            if (!option) return;
            const input = this.$refs.macroInput;
            const change = insertRuntimeMacro(input.value, this.macroRange, option.token);
            input.value = change.value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            this.macroOpen = false;
            this.$nextTick(() => { input.focus(); input.setSelectionRange(change.cursor, change.cursor); });
        },
    };
}
