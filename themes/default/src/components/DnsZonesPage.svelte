<script lang="ts">
    import { visibleZones } from '../app/pages/dns-workspace';
    import Field from './Field.svelte';
    import Page from './Page.svelte';
    import { useI18n } from '../lib/i18n';
    import { pageUrl, type DnsZonesPageProps, type PageUrlTemplates } from '../lib/bootstrap';

    let { page, urls }: { page: DnsZonesPageProps; urls?: PageUrlTemplates } = $props();
    const t = useI18n();
    let query = $state('');
    let providerFilter = $state('');
    $effect(() => { providerFilter = page.providerFilter ?? ''; });
    const zones = $derived(visibleZones(page.managedZones, query, providerFilter));
    const providers = $derived([...new Set([...Object.keys(page.providerNames), ...page.managedZones.map(zone => String(zone.providerAccountId))])].sort((a,b) => (page.providerNames[a] ?? a).localeCompare(page.providerNames[b] ?? b)));

    const url = (name: string, parameters: Record<string, string | number>) => pageUrl(urls, name, parameters);

    const confirmDelete = (event: SubmitEvent, name: string): void => {
        if (!confirm(t('zones.confirm-delete', { name }))) event.preventDefault();
    };
</script>

<Page title={t('page.zones.title')} error={page.error}>
    <section class="box zones-workspace">
        {#if page.canCreateZones && page.providerAccounts.length}
            <div class="zones-toolbar">
                <div>
                    <h2 class="subtitle">{t('zones.create')}</h2>
                    <p class="muted">{t('zones.provider')}</p>
                </div>
                <form class="inline-form" method="post" action={url('accountZones', { account: page.accountId })}>
                    <input type="hidden" name="csrf_token" value={page.csrfToken}>
                    <Field label={t('zones.provider')}>
                        <select class="input" name="provider_account_id" required>
                            {#each page.providerAccounts as provider}<option value={provider.id} selected={String(provider.id) === page.providerFilter}>{provider.name}</option>{/each}
                        </select>
                    </Field>
                    <Field label={t('field.name')}>
                        <input class="input" name="zone_name" placeholder="example.com" required>
                    </Field>
                    <button class="button is-primary">{t('zones.create')}</button>
                </form>
            </div>
        {:else}
            <p class="empty">{t(page.canCreateZones ? 'zones.provider-required' : 'rrset.action-unavailable')}</p>{#if page.canManageProviders}<a class="button" href={url('accountProviders', { id: page.accountId })}>{t('navigation.providers')}</a>{/if}
        {/if}
    </section>

    <section class="box form-grid" aria-label={t('dns.filters')}>
        <Field label={t('common.search')}><input class="input" type="search" bind:value={query} placeholder={t('dns.zone-search')}></Field>
        <Field label={t('zones.provider')}><select class="input" bind:value={providerFilter}><option value="">{t('common.all')}</option>{#each providers as id}<option value={id}>{page.providerNames[id] ?? t('zones.provider-connection-unavailable')}</option>{/each}</select></Field>
        <p role="status">{t('dns.results', { shown: zones.length, total: page.managedZones.length })}</p>
    </section>
    <section class="resource-grid zones-list" aria-label={t('page.zones.title')}>
        {#each zones as zone (zone.id)}
            {@const providerName = page.providerNames[String(zone.providerAccountId)]}
            <article class="resource-card zone-card">
                <div class="zone-card__summary">
                    <a class="resource-title" href={url('records', { account: page.accountId, zone: zone.id })}>{zone.canonicalName}</a>
                    {#if providerName}<span class="status">{t('zones.provider')}: {providerName}</span>{:else}<span class="status">{t('zones.provider-connection-unavailable')}</span>{/if}
                </div>
                {#if page.canDeleteZones}<form method="post" action={url('zoneDelete', { account: page.accountId, zone: zone.id })} onsubmit={(event) => confirmDelete(event, zone.canonicalName)}>
                    <input type="hidden" name="csrf_token" value={page.csrfToken}>
                    <button class="button is-small is-danger">{t('common.delete')}</button>
                </form>{/if}
            </article>
        {:else}
            <p class="empty">{t(page.managedZones.length ? 'dns.no-matches' : 'zones.empty')}</p>
        {/each}
    </section>
</Page>
