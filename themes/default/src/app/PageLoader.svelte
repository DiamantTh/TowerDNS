<script lang="ts">
    import Notice from '../components/Notice.svelte';
    import { useI18n } from '../lib/i18n';
    import type { PageBoot } from './types';
    import { loadPage } from './page-loader';
    let { boot }: { boot: PageBoot } = $props();
    const t = useI18n();
    const page = $derived(loadPage(boot.page));
</script>
{#await page then Page}
    <Page {boot}/>
{:catch}
    <Notice kind="danger" text={t('http.error.operation-failed')}/>
{/await}
