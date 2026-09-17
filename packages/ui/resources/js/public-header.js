// Delegation also covers headers replaced during client-side navigation.
document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    document.querySelectorAll('.ui-public-header details[open]').forEach((menu) => {
        if (!menu.contains(event.target) || event.target.closest('a')) menu.open = false;
    });
});

document.addEventListener('toggle', (event) => {
    const menu = event.target;
    if (!(menu instanceof HTMLDetailsElement) || !menu.open || !menu.closest('.ui-public-header')) return;
    document.querySelectorAll('.ui-public-header details[open]').forEach((other) => {
        if (other !== menu) other.open = false;
    });
}, true);

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('.ui-public-header details[open]').forEach((menu) => {
        menu.open = false;
        menu.querySelector('summary')?.focus();
    });
});
