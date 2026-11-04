<script lang="ts">
    import AccountRecoveryPage from '../../components/AccountRecoveryPage.svelte';
    import StepUpPage from '../../components/StepUpPage.svelte';
    import type { PageBoot } from '../types';
    let { boot }: { boot: PageBoot } = $props();
    const p = $derived(boot.props);
</script>
{#if boot.page === 'account/recovery'}
    <AccountRecoveryPage data={p}/>
{:else}
    <StepUpPage data={p as {csrfToken:string;completed:boolean;returnUrl:string|null;totpAvailable:boolean;passkeyAvailable:boolean;passwordAvailable:boolean;factorHint?:string|null;recoveryRequired?:boolean;error:string|null}}/>
{/if}
