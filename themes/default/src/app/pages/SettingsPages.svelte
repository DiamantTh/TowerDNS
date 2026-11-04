<script lang="ts">
    import SystemSettingsPage from '../../components/SystemSettingsPage.svelte';
    import type { PageBoot } from '../types';
    let { boot }: { boot: PageBoot } = $props();
    const p = $derived(boot.props);
    const csrf = $derived(p.csrfToken ?? '');
</script>

{#if boot.page==='settings'}<SystemSettingsPage fields={p.fields} themes={boot.themes} csrfToken={csrf} error={p.error} success={p.success}/>
{/if}
