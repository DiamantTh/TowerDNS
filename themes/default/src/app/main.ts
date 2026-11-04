import { mount } from 'svelte';
import { isAuthenticationPage } from '../lib/bootstrap';
import type { PageBoot } from './types';
import '../styles/app.css';

const target = document.getElementById('towerdns-app');
const source = document.getElementById('towerdns-page');

if (target && source?.textContent) {
    const boot = JSON.parse(source.textContent) as PageBoot;
    const authenticated = Boolean(boot.props.user ?? boot.props.currentUser) && !boot.props.recoverySession;
    const root = isAuthenticationPage(boot.page)
        ? import('./AuthRoot.svelte')
        : authenticated ? import('./App.svelte') : import('./PublicRoot.svelte');
    void root.then(({ default: Component }) => mount(Component, { target, props: { boot } })).catch(() => {
        const message = boot.i18n?.messages['http.error.operation-failed'];
        target.textContent = (Array.isArray(message) ? message[0] : message) ?? 'The page could not be loaded.';
    });
}
