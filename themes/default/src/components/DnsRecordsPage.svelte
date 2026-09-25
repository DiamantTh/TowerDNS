<script lang="ts">
    import Field from './Field.svelte';
    import Page from './Page.svelte';
    import { useI18n } from '../lib/i18n';
    import { pageUrl, type DnsRecordsPageProps, type PageUrlTemplates } from '../lib/bootstrap';

    let { page, urls }: { page: DnsRecordsPageProps; urls?: PageUrlTemplates } = $props();
    const t = useI18n();
    const url = (name: string, parameters: Record<string, string | number>) => pageUrl(urls, name, parameters);

    const confirmDelete = (event: SubmitEvent): void => {
        if (!confirm(t('rrset.confirm-delete'))) event.preventDefault();
    };
</script>

<div class="dns-records-view">
<Page title={page.managedZoneName} error={page.error} success={page.success}>
    <div class="page-actions">
        <a class="button" href={url('accountZones', { account: page.accountId })}>← {t('navigation.zones')}</a>
        <a class="button" href={url('dnssec', { account: page.accountId, zone: page.managedZoneId })}>DNSSEC</a>
    </div>

    <section class="box">
        <h2 class="subtitle">{t('rrset.save')}</h2>
        <p class="muted">{t('rrset.save-hint')}</p>
        <form method="post" action={url('rrsetReplace', { account: page.accountId, zone: page.managedZoneId })}>
            <input type="hidden" name="csrf_token" value={page.csrfToken}>
            <div class="form-grid">
                <Field label={t('rrset.column.owner')}><input class="input" name="name" value="@" required disabled={!page.canReplaceRrsets} /></Field>
                <Field label={t('rrset.column.type')}><input class="input" name="type" required disabled={!page.canReplaceRrsets} /></Field>
                <Field label={t('rrset.column.ttl')}><input class="input" name="ttl" type="number" min="30" max="604800" value="300" required disabled={!page.canReplaceRrsets} /></Field>
            </div>
            <Field label={t('rrset.column.rdata')}><textarea class="input" name="rdata" rows="4" required disabled={!page.canReplaceRrsets}></textarea></Field>
            <button class="button is-primary" disabled={!page.canReplaceRrsets} aria-describedby={!page.canReplaceRrsets ? 'rrset-replace-unavailable' : undefined}>{t('rrset.save')}</button>
            {#if !page.canReplaceRrsets}<p class="help" id="rrset-replace-unavailable" role="status">{t('rrset.action-unavailable')}</p>{/if}
        </form>
    </section>

    <div class="table-container">
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
                {#each page.rrsets as rrset, index}
                    <tr>
                        <td><code>{rrset.ownerName || '@'}</code></td>
                        <td>{rrset.type.presentation}</td>
                        <td>{rrset.ttl}</td>
                        <td class="rdata-values">
                            {#each rrset.rdata as value}<code class="rdata-value">{value}</code>{/each}
                        </td>
                        <td class="actions">
                            <form method="post" action={url('rrsetDelete', { account: page.accountId, zone: page.managedZoneId, owner: rrset.ownerName, type: rrset.type.presentation })} onsubmit={confirmDelete}>
                                <input type="hidden" name="csrf_token" value={page.csrfToken}>
                                <button class="button is-small is-danger" disabled={!page.canDeleteRrsets} aria-describedby={!page.canDeleteRrsets ? `rrset-delete-unavailable-${index}` : undefined}>{t('rrset.delete')}</button>
                                {#if !page.canDeleteRrsets}<span class="sr-only" id={`rrset-delete-unavailable-${index}`}>{t('rrset.action-unavailable')}</span>{/if}
                            </form>
                        </td>
                    </tr>
                {:else}
                    <tr><td colspan="5">{t('rrset.empty')}</td></tr>
                {/each}
            </tbody>
        </table>
    </div>
</Page>
</div>
