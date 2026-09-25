<script lang="ts">
    import type { Snippet } from 'svelte';
    import { Switch } from '@skeletonlabs/skeleton-svelte';
    import { useI18n } from '../lib/i18n';
    import { pageUrl, type PageUrlTemplates, type UserBootstrap } from '../lib/bootstrap';

    type Props = {
        user: UserBootstrap | null;
        csrfToken: string;
        dark: boolean;
        menu: boolean;
        can: (permission: string) => boolean;
        onToggleTheme: (dark: boolean) => void;
        onToggleMenu: (open: boolean) => void;
        accountLabel?: string | null;
        accountHref?: string;
        urls?: PageUrlTemplates;
        children: Snippet;
    };

    let {
        user,
        csrfToken,
        dark,
        menu,
        can,
        onToggleTheme,
        onToggleMenu,
        accountLabel = null,
        accountHref,
        urls,
        children,
    }: Props = $props();
    const t = useI18n();
    const url = (name: string) => pageUrl(urls, name);
</script>

<a class="skip-link" href="#main-content">{t('navigation.skip-to-content')}</a>
{#if user}
    <nav class="navbar app-shell__nav" aria-label="TowerDNS">
        <div class="navbar-brand">
            <a class="navbar-item brand" href={url('dashboard')}>TowerDNS</a>
            <button type="button" class="navbar-burger" aria-label={t('navigation.menu')} aria-controls="towerdns-primary-navigation" aria-expanded={menu} onclick={() => onToggleMenu(!menu)}>
                <span></span><span></span><span></span>
            </button>
        </div>
        <div id="towerdns-primary-navigation" class:open={menu} class="navbar-menu">
            <div class="navbar-start">
                <a class="navbar-item" href={url('zones')}>{t('navigation.zones')}</a>
                <a class="navbar-item" href={url('accounts')}>{t('navigation.accounts')}</a>
                {#if can('user.manage') || can('role.manage') || can('system.settings.manage') || can('system.schema.manage') || can('provider.config.manage') || can('system.impersonation.execute')}<a class="navbar-item" href={url('admin')}>{t('navigation.admin')}</a>{/if}
                {#if can('user.manage')}<a class="navbar-item" href={url('users')}>{t('navigation.users')}</a>{/if}
                {#if can('role.manage')}<a class="navbar-item" href={url('roles')}>{t('navigation.roles')}</a>{/if}
                {#if can('system.settings.manage')}<a class="navbar-item" href={url('settings')}>{t('navigation.settings')}</a>{/if}
                {#if can('system.schema.manage')}<a class="navbar-item" href={url('schema')}>{t('navigation.schema')}</a>{/if}
                {#if can('provider.config.manage')}<a class="navbar-item" href="/credentials">{t('navigation.providers')}</a>{/if}
            </div>
            <div class="navbar-end">
                <div class="navbar-item">
                    <Switch checked={dark} onCheckedChange={(event) => onToggleTheme(event.checked)} class="theme-switch" label={t('theme.label')}>
                        <Switch.Label>{dark ? t('theme.dark') : t('theme.light')}</Switch.Label>
                        <Switch.Control class="theme-switch__control preset-filled-primary-500">
                            <Switch.Thumb class="theme-switch__thumb">{dark ? '☾' : '☀'}</Switch.Thumb>
                        </Switch.Control>
                        <Switch.HiddenInput />
                    </Switch>
                </div>
                <a class="navbar-item" href={url('profile')}>{user.displayName || user.email}</a>
                <form class="navbar-item" method="post" action="/logout">
                    <input type="hidden" name="csrf_token" value={csrfToken}>
                    <button class="button is-small">{t('auth.logout')}</button>
                </form>
            </div>
        </div>
    </nav>
    {#if accountLabel}
        <div class="app-context" aria-label={t('navigation.accounts')}>
            <span class="app-context__label">{t('navigation.accounts')}</span>
            <a href={accountHref ?? url('accounts')}>{accountLabel}</a>
        </div>
    {/if}
{/if}

{@render children()}
