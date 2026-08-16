const storageKey = 'towerdns-color-mode';
const stored = localStorage.getItem(storageKey);
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
const dark = stored === 'dark' || (stored !== 'light' && prefersDark);

document.documentElement.classList.toggle('dark', dark);
