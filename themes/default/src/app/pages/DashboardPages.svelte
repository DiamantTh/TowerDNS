<script lang="ts">
    import Page from '../../components/Page.svelte';
    import Feature from '../../components/Feature.svelte';
    import type { PageBoot } from '../types';
    import { pageUrl, type UserBootstrap } from '../../lib/bootstrap';
    import { useI18n } from '../../lib/i18n';
    let { boot }: { boot: PageBoot } = $props();
    const p = $derived(boot.props);
    const user = $derived((p.user ?? p.currentUser ?? null) as UserBootstrap | null);
    const t = useI18n();
    const url = (name: string, parameters: Record<string, string | number> = {}) => pageUrl(boot.urls, name, parameters);
</script>

{#if boot.page === 'dashboard' && user}
    <Page title={t('page.dashboard.title')} subtitle={t('dashboard.welcome', { name: user.displayName || user.email })}>
        <section class="box">
            {#if p.account}
                <h2 class="subtitle">{t('dns.active-account')}: {p.account.name}</h2>
                <div class="buttons">
                    {#if p.zoneCount != null}<a class="button" href={url('accountZones', { account: p.account.id })}>{t('navigation.zones')} ({p.zoneCount})</a>{/if}
                    {#if p.providerCount != null}<a class="button" href={url('accountProviders', { id: p.account.id })}>{t('navigation.providers')} ({p.providerCount})</a>{/if}
                </div>
            {:else}
                <p>{t('dashboard.no-active-account')}</p>
            {/if}
        </section>
        <div class="dashboard-grid">
            <Feature title={t('dashboard.accounts.title')} text={t('dashboard.accounts.text')} href={url('accounts')}/>
            <Feature title={t('dashboard.security.title')} text={t('dashboard.security.text')} href={url('profile')}/>
        </div>
        <div class="page-actions">
            {#if p.showInvitations}<a class="button" href="/profile/invitations">{t('invitations.incoming')}</a>{/if}
            {#if p.offerPasskey}<a class="button" href="/profile/webauthn">{t('dashboard.add-passkey')}</a>{/if}
        </div>
    </Page>
{/if}
