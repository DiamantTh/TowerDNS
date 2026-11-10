<script lang="ts">
    import { visibleRrsets } from '../app/pages/dns-workspace';
    import { tick } from 'svelte';
    import type { DnsRrsetBootstrap } from '../lib/bootstrap';
    import Field from './Field.svelte';
    import Page from './Page.svelte';
    import { useI18n } from '../lib/i18n';
    import { pageUrl, type DnsRecordsPageProps, type PageUrlTemplates } from '../lib/bootstrap';

    let { page, urls }: { page: DnsRecordsPageProps; urls?: PageUrlTemplates } = $props();
    const t = useI18n();
    let query = $state('');
    let typeFilter = $state('');
    let owner = $state('@');
    let typeChoice = $state('A');
    let customType = $state('');
    let ttl = $state(300);
    let rdata = $state('');
    let editing = $state(false);
    let editor: HTMLTextAreaElement;
    const rrsets = $derived(visibleRrsets(page.rrsets, query, typeFilter));
    const types = $derived([...new Set(page.rrsets.map(rrset => rrset.type.presentation))].sort());
    const reset = () => { owner = '@'; typeChoice = 'A'; customType = ''; ttl = 300; rdata = ''; editing = false; };
    const edit = async (rrset: DnsRrsetBootstrap) => {
        if (!page.canReplaceRrsets) return;
        owner = rrset.ownerName || '@';
        typeChoice = page.recordTypes.includes(rrset.type.presentation) ? rrset.type.presentation : 'custom';
        customType = rrset.type.presentation; ttl = rrset.ttl; rdata = rrset.rdata.join('\n'); editing = true;
        await tick(); editor?.focus(); editor?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    };

    const url = (name: string, parameters: Record<string, string | number>) => pageUrl(urls, name, parameters);

    const confirmDelete = (event: SubmitEvent, rrset: DnsRrsetBootstrap): void => {
        if (!confirm(t('dns.confirm-delete-rrset', { zone: page.managedZoneName, owner: rrset.ownerName || '@', type: rrset.type.presentation }))) event.preventDefault();
    };
</script>

<div class="dns-records-view">
<Page title={page.managedZoneName} error={page.error} success={page.success}>
    <nav class="page-actions" aria-label={t('dns.context')}>
        <span>{t('dns.account', { id: page.accountId })}</span>
        <a class="button" href={url('accountZones', { account: page.accountId })}>← {t('navigation.zones')}</a>
        <a class="button" href={url('dnssec', { account: page.accountId, zone: page.managedZoneId })}>DNSSEC</a>
        {#if page.canReadAudit}<a class="button" href={`/accounts/${page.accountId}/zones/${page.managedZoneId}/audit`}>{t('audit.title')}</a>{/if}
    </nav>

    <section class="box">
        <h2 class="subtitle">{t(editing ? 'dns.edit-rrset' : 'rrset.save')}</h2>
        <p class="muted">{t('rrset.save-hint')}</p>
        <form method="post" action={url('rrsetReplace', { account: page.accountId, zone: page.managedZoneId })}>
            <input type="hidden" name="csrf_token" value={page.csrfToken}>
            <div class="form-grid">
                <Field label={t('rrset.column.owner')}><input class="input" name="name" bind:value={owner} readonly={editing} required disabled={!page.canReplaceRrsets} /></Field>
                <Field label={t('rrset.column.type')}><select class="input" bind:value={typeChoice} disabled={editing || !page.canReplaceRrsets}>
                    {#each page.recordTypes as type}<option value={type}>{type}</option>{/each}<option value="custom">{t('dns.custom-type')}</option>
                </select><input type="hidden" name="type" value={typeChoice === 'custom' ? customType : typeChoice}>
                {#if typeChoice === 'custom'}<input class="input" aria-label={t('dns.custom-type')} bind:value={customType} placeholder="TYPE65400" required readonly={editing} disabled={!page.canReplaceRrsets}>{/if}</Field>
                <Field label={t('rrset.column.ttl')}><input class="input" name="ttl" type="number" min="30" max="604800" bind:value={ttl} required disabled={!page.canReplaceRrsets} /></Field>
            </div>
            <Field label={t('rrset.column.rdata')}><textarea class="input" name="rdata" rows="4" bind:this={editor} bind:value={rdata} aria-describedby="rdata-help" required disabled={!page.canReplaceRrsets}></textarea></Field>
            <p id="rdata-help" class="help">{t('dns.rdata-help')}</p>
            {#if editing}<button type="button" class="button" onclick={reset}>{t('dns.new-rrset')}</button>{/if}
            <button class="button is-primary" disabled={!page.canReplaceRrsets} aria-describedby={!page.canReplaceRrsets ? 'rrset-replace-unavailable' : undefined}>{t('rrset.save')}</button>
            {#if !page.canReplaceRrsets}<p class="help" id="rrset-replace-unavailable" role="status">{t('rrset.action-unavailable')}</p>{/if}
        </form>
    </section>

    <section class="box form-grid" aria-label={t('dns.filters')}>
        <Field label={t('common.search')}><input class="input" type="search" bind:value={query} placeholder={t('dns.record-search')}></Field>
        <Field label={t('rrset.column.type')}><select class="input" bind:value={typeFilter}><option value="">{t('common.all')}</option>{#each types as type}<option value={type}>{type}</option>{/each}</select></Field>
        <p role="status">{t('dns.results', { shown: rrsets.length, total: page.rrsets.length })}</p>
    </section>
    <!-- svelte-ignore a11y_no_noninteractive_tabindex -- This named region is a keyboard scroll target for the wide data table. -->
    <div class="table-container" role="region" aria-label={t('rrset.table-caption')} tabindex="0">
        <table class="table">
            <caption class="sr-only">{t('rrset.table-caption')}</caption>
            <thead>
                <tr>
                    <th scope="col">{t('rrset.column.owner')}</th>
                    <th scope="col">{t('rrset.column.type')}</th>
                    <th scope="col">{t('rrset.column.ttl')}</th>
                    <th scope="col">{t('rrset.column.rdata')}</th>
                    <th scope="col">{t('rrset.column.actions')}</th>
                </tr>
            </thead>
            <tbody>
                {#each rrsets as rrset, index}
                    <tr>
                        <td><code>{rrset.ownerName || '@'}</code></td>
                        <td>{rrset.type.presentation}</td>
                        <td>{rrset.ttl}</td>
                        <td class="rdata-values">
                            {#each rrset.rdata as value}<code class="rdata-value">{value}</code>{/each}
                        </td>
                        <td class="actions">
                            <button type="button" class="button is-small" disabled={!page.canReplaceRrsets} aria-label={t('dns.edit-label', { owner: rrset.ownerName || '@', type: rrset.type.presentation })} onclick={() => edit(rrset)}>{t('common.edit')}</button>
                            <form method="post" action={url('rrsetDelete', { account: page.accountId, zone: page.managedZoneId, owner: rrset.ownerName, type: rrset.type.presentation })} onsubmit={(event) => confirmDelete(event, rrset)}>
                                <input type="hidden" name="csrf_token" value={page.csrfToken}>
                                <button class="button is-small is-danger" disabled={!page.canDeleteRrsets} aria-describedby={!page.canDeleteRrsets ? `rrset-delete-unavailable-${index}` : undefined}>{t('rrset.delete')}</button>
                                {#if !page.canDeleteRrsets}<span class="sr-only" id={`rrset-delete-unavailable-${index}`}>{t('rrset.action-unavailable')}</span>{/if}
                            </form>
                        </td>
                    </tr>
                {:else}
                    <tr><td colspan="5">{t(page.rrsets.length ? 'dns.no-matches' : 'rrset.empty')}</td></tr>
                {/each}
            </tbody>
        </table>
    </div>
</Page>
</div>

<style>
    .rdata-values { width: 50%; max-width: 34rem; }
    .rdata-value { overflow-wrap: anywhere; word-break: break-word; white-space: pre-wrap; }
    .actions .button { margin-bottom: .5rem; }
</style>
