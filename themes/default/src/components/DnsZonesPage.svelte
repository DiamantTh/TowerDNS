<script lang="ts">
    import Field from './Field.svelte';
    import Page from './Page.svelte';
    import { useI18n } from '../lib/i18n';
    import type { DnsZonesPageProps } from '../lib/bootstrap';

    let { page }: { page: DnsZonesPageProps } = $props();
    const t = useI18n();
    const enc = (value: string | number): string => encodeURIComponent(String(value));

    const confirmDelete = (event: SubmitEvent, name: string): void => {
        if (!confirm(t('zones.confirm-delete', { name }))) event.preventDefault();
    };
</script>

<Page title={t('page.zones.title')} error={page.error}>
    <section class="box zones-workspace">
        {#if page.providerAccounts.length}
            <div class="zones-toolbar">
                <div>
                    <h2 class="subtitle">{t('zones.create')}</h2>
                    <p class="muted">{t('zones.provider')}</p>
                </div>
                <form class="inline-form" method="post" action={`/accounts/${enc(page.accountId)}/zones`}>
                    <input type="hidden" name="csrf_token" value={page.csrfToken}>
                    <Field label={t('zones.provider')}>
                        <select class="input" name="provider_account_id" required>
                            {#each page.providerAccounts as provider}<option value={provider.id}>{provider.name}</option>{/each}
                        </select>
                    </Field>
                    <Field label={t('field.name')}>
                        <input class="input" name="zone_name" placeholder="example.com" required>
                    </Field>
                    <button class="button is-primary">{t('zones.create')}</button>
                </form>
            </div>
        {:else}
            <p class="empty">{t('zones.provider-required')}</p>
        {/if}
    </section>

    <section class="resource-grid zones-list" aria-label={t('page.zones.title')}>
        {#each page.managedZones as zone}
            {@const providerName = page.providerNames[String(zone.providerAccountId)]}
            <article class="resource-card zone-card">
                <div class="zone-card__summary">
                    <a class="resource-title" href={`/accounts/${enc(page.accountId)}/zones/${enc(zone.id)}`}>{zone.canonicalName}</a>
                    {#if providerName}<span class="status">{t('zones.provider')}: {providerName}</span>{:else}<span class="status">{t('zones.provider-connection-unavailable')}</span>{/if}
                </div>
                <form method="post" action={`/accounts/${enc(page.accountId)}/zones/${enc(zone.id)}/delete`} onsubmit={(event) => confirmDelete(event, zone.canonicalName)}>
                    <input type="hidden" name="csrf_token" value={page.csrfToken}>
                    <button class="button is-small is-danger">{t('common.delete')}</button>
                </form>
            </article>
        {:else}
            <p class="empty">{t('zones.empty')}</p>
        {/each}
    </section>
</Page>
