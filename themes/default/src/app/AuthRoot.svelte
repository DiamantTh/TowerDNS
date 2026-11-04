<script lang="ts">
    import AuthenticationPage from '../components/AuthenticationPage.svelte';
    import Notice from '../components/Notice.svelte';
    import { provideI18n, useI18n } from '../lib/i18n';
    import type { AuthenticationPageName } from '../lib/bootstrap';
    import type { PageBoot } from './types';
    let { boot }: { boot: PageBoot } = $props();
    provideI18n(() => boot.i18n?.messages ?? {});
    const t = useI18n();
</script>
<a class="skip-link" href="#main-content">{t('navigation.skip-to-content')}</a>
<main class="auth-page" id="main-content">
    {#if boot.debug}<Notice kind="warning" text={t('notice.debug-enabled')}/>{/if}
    <AuthenticationPage page={boot.page as AuthenticationPageName} data={boot.props} urls={boot.urls}/>
</main>
