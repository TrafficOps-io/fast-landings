// Inlined in <head> by Astro and Blade so the saved theme is applied before paint.
(() => {
    const storageKey = document.currentScript?.dataset.uiThemeKey || 'trafficops-theme';
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const readPreference = () => {
        try {
            const value = localStorage.getItem(storageKey);
            return value === 'light' || value === 'dark' ? value : null;
        } catch {
            return null;
        }
    };
    let preference = readPreference();
    const syncButtons = () => {
        document.querySelectorAll('[data-ui-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'));
        });
    };
    const apply = () => {
        const theme = preference || (media.matches ? 'dark' : 'light');
        root.dataset.theme = theme;
        root.classList.toggle('dark', theme === 'dark');
        root.style.colorScheme = theme;
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#1d1a19' : '#fbf7f2');
        syncButtons();
    };
    // Flux captures this adapter when Alpine starts. Keep its workspace preference
    // in sync with the public toggle instead of letting it fall back to system.
    if (storageKey === 'flux.appearance') {
        window.Flux = window.Flux || {};
        window.Flux.applyAppearance = (value) => {
            preference = value === 'light' || value === 'dark' ? value : null;
            try {
                if (preference) localStorage.setItem(storageKey, preference);
                else localStorage.removeItem(storageKey);
            } catch { /* Optional preference. */ }
            apply();
        };
    }
    apply();
    document.addEventListener('DOMContentLoaded', syncButtons, { once: true });
    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element) || !event.target.closest('[data-ui-theme-toggle]')) return;
        preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
        if (storageKey === 'flux.appearance' && window.Flux) window.Flux.appearance = preference;
        try { localStorage.setItem(storageKey, preference); } catch { /* Optional preference. */ }
        apply();
    });
    media.addEventListener('change', () => { if (!preference) apply(); });
    window.addEventListener('storage', (event) => {
        if (event.key !== storageKey && event.key !== null) return;
        preference = readPreference();
        if (storageKey === 'flux.appearance' && window.Flux) window.Flux.appearance = preference || 'system';
        apply();
    });
    document.addEventListener('livewire:navigated', () => {
        preference = readPreference();
        apply();
    });
})();
