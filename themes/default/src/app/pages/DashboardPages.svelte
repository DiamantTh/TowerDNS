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

{#if boot.page==='dashboard' && user}<Page title={t('page.dashboard.title')} subtitle={t('dashboard.welcome',{name:user.displayName||user.email})}><div class="dashboard-grid"><Feature title={t('dashboard.zones.title')} text={t('dashboard.zones.text')} href={url("zones")}/><Feature title={t('dashboard.accounts.title')} text={t('dashboard.accounts.text')} href={url("accounts")}/><Feature title={t('dashboard.security.title')} text={t('dashboard.security.text')} href={url("profile")}/></div></Page>
{/if}
