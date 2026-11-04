<script lang="ts">
    import Notice from '../components/Notice.svelte';
    import { provideI18n, useI18n } from '../lib/i18n';
    import type { PageBoot } from './types';
    import PageLoader from './PageLoader.svelte';
    let { boot }: { boot: PageBoot } = $props();
    provideI18n(() => boot.i18n?.messages ?? {});
    const t = useI18n();
</script>
<a class="skip-link" href="#main-content">{t('navigation.skip-to-content')}</a>
<main class="auth-page" id="main-content">
    {#if boot.debug}<Notice kind="warning" text={t('notice.debug-enabled')}/>{/if}
    <PageLoader {boot}/>
</main>
