<script lang="ts">
    import AppShell from '../components/AppShell.svelte';
    import Notice from '../components/Notice.svelte';
    import { provideI18n, useI18n } from '../lib/i18n';
    import type { UserBootstrap } from '../lib/bootstrap';
    import type { PageBoot } from './types';
    import PageLoader from './PageLoader.svelte';
    let { boot }: { boot: PageBoot } = $props();
    provideI18n(() => boot.i18n?.messages ?? {});
    const t = useI18n();
    const p = $derived(boot.props);
    const user = $derived((p.user ?? p.currentUser ?? null) as UserBootstrap | null);
    const csrf = $derived(p.csrfToken ?? '');
    let dark = $state(document.documentElement.classList.contains('dark'));
    let menu = $state(false);
    const permission = (value: string) => (user?.roles ?? []).some(role => (role.permissions ?? []).includes(value));
    function setDark(value: boolean) {
        dark = value;
        document.documentElement.classList.toggle('dark', value);
        localStorage.setItem('towerdns-color-mode', value ? 'dark' : 'light');
    }
</script>
<AppShell {user} csrfToken={csrf} {dark} {menu} can={permission} urls={boot.urls} accountLabel={typeof p.account?.name === 'string' ? p.account.name : null} onToggleTheme={setDark} onToggleMenu={(open) => menu = open}>
    <main id="main-content">
        {#if boot.debug}<Notice kind="warning" text={t('notice.debug-enabled')}/>{/if}
        <PageLoader {boot}/>
    </main>
</AppShell>
