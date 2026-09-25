type StoryLink = { matches: (url: URL) => boolean; storyId: string };

const storyLinks: StoryLink[] = [
    { matches: (url) => url.pathname === '/' || url.pathname === '/dashboard', storyId: 'towerdns-ux-previews-application-pages--dashboard' },
    { matches: (url) => url.pathname === '/zones' || /^\/accounts\/\d+\/zones\/?$/.test(url.pathname), storyId: 'towerdns-dns-zone-list--provider-and-zones' },
    { matches: (url) => /^\/accounts\/\d+\/zones\/\d+\/?$/.test(url.pathname) || /^\/accounts\/\d+\/zones\/\d+\/rrsets\/?$/.test(url.pathname), storyId: 'towerdns-dns-record-sets-page--multiple-rrsets' },
    { matches: (url) => /^\/accounts\/\d+\/providers\/?$/.test(url.pathname), storyId: 'towerdns-ux-previews-application-pages--provider-accounts' },
    { matches: (url) => /^\/accounts\/\d+\/members\/?$/.test(url.pathname), storyId: 'towerdns-ux-previews-application-pages--account-members' },
    { matches: (url) => url.pathname === '/accounts', storyId: 'towerdns-ux-previews-application-pages--accounts' },
    { matches: (url) => url.pathname === '/users', storyId: 'towerdns-ux-previews-application-pages--users' },
    { matches: (url) => url.pathname === '/roles', storyId: 'towerdns-ux-previews-application-pages--roles' },
    { matches: (url) => url.pathname === '/profile', storyId: 'towerdns-ux-previews-application-pages--profile' },
    { matches: (url) => url.pathname === '/settings', storyId: 'towerdns-ux-previews-application-pages--system-settings' },
    { matches: (url) => url.pathname === '/login', storyId: 'towerdns-ux-previews-application-pages--login' },
];

const backendPath = /^\/(?:accounts|zones|users|roles|settings|credentials|profile|login|logout|password)(?:\/|$)/;
let installed = false;

function previewNotice(message: string): void {
    let notice = document.querySelector<HTMLElement>('[data-tower-preview-notice]');

    if (!notice) {
        notice = document.createElement('div');
        notice.dataset.towerPreviewNotice = 'true';
        notice.className = 'notification is-info storybook-preview-notice';
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        notice.tabIndex = -1;
        document.body.append(notice);
    }

    notice.textContent = message;
    notice.focus({ preventScroll: true });
}

function translatedNotice(): (kind: 'navigation' | 'action', path?: string) => string {
    const german = document.documentElement.lang.toLowerCase().startsWith('de');

    return (kind, path = '') => kind === 'action'
        ? german
            ? 'Storybook-Demo: Diese Aktion wurde nicht gesendet und verändert keine Daten.'
            : 'Storybook demo: this action was not submitted and no data was changed.'
        : german
            ? `Diese Ansicht (${path}) benötigt TowerDNS mit Mezzio und wird in dieser Vorschau nicht dargestellt.`
            : `This view (${path}) requires TowerDNS with Mezzio and is not represented in this preview.`;
}

/** Installs preview-only navigation and write guards. This module is imported only by .storybook/preview.ts. */
export function installStorybookPreviewGuards(): void {
    if (installed) return;
    installed = true;

    document.addEventListener('click', (event) => {
        const anchor = (event.target as Element | null)?.closest<HTMLAnchorElement>('a[href]');
        if (!anchor) return;

        const target = new URL(anchor.href, window.location.href);
        if (target.origin !== window.location.origin || (target.pathname === window.location.pathname && target.search === window.location.search)) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const currentStoryId = new URLSearchParams(window.location.search).get('id');
        const route = target.pathname === '/' && currentStoryId?.endsWith('--login')
            ? { matches: () => true, storyId: 'towerdns-ux-previews-application-pages--login' }
            : storyLinks.find((link) => link.matches(target));
        if (route) {
            const storybookUrl = new URL('./', window.location.href);
            storybookUrl.searchParams.set('path', `/story/${route.storyId}`);
            const openSeparately = anchor.target === '_blank' || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
            if (openSeparately) {
                window.open(storybookUrl.toString(), '_blank', 'noopener,noreferrer');
            } else {
                window.top?.location.assign(storybookUrl.toString());
            }
            return;
        }

        previewNotice(translatedNotice()('navigation', target.pathname));
    }, true);

    document.addEventListener('submit', (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
        previewNotice(translatedNotice()('action'));
    }, true);

    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function submitInPreview(): void {
        if (this.ownerDocument === document) {
            previewNotice(translatedNotice()('action'));
            return;
        }

        nativeSubmit.call(this);
    };

    const nativeFetch = window.fetch.bind(window);
    window.fetch = (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
        const rawUrl = input instanceof Request ? input.url : String(input);
        const target = new URL(rawUrl, window.location.href);
        const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const isWrite = !['GET', 'HEAD', 'OPTIONS'].includes(method);

        if (isWrite || (target.origin === window.location.origin && backendPath.test(target.pathname))) {
            previewNotice(translatedNotice()('action'));
            return Promise.resolve(Response.json({ error: 'Unavailable in the Storybook preview.' }, { status: 501 }));
        }

        return nativeFetch(input, init);
    };
}
