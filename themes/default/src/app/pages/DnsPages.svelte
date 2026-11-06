<script lang="ts">
    import Page from '../../components/Page.svelte';
    import Field from '../../components/Field.svelte';
    import Notice from '../../components/Notice.svelte';
    import DnsRecordsPage from '../../components/DnsRecordsPage.svelte';
    import DnsZonesPage from '../../components/DnsZonesPage.svelte';
    import type { PageBoot } from '../types';
    import { pageUrl, type DnsRecordsPageProps, type DnsZonesPageProps } from '../../lib/bootstrap';
    import { useI18n } from '../../lib/i18n';
    let { boot }: { boot: PageBoot } = $props();
    const p = $derived(boot.props);
    const csrf = $derived(p.csrfToken ?? '');
    const t = useI18n();
    const url = (name: string) => pageUrl(boot.urls, name, { account: p.accountId, zone: p.managedZoneId });
    const enc = (v: unknown) => encodeURIComponent(String(v ?? ''));
</script>

{#if boot.page==='zones/list'}<DnsZonesPage page={p as DnsZonesPageProps} urls={boot.urls} />
{:else if boot.page==='zones/records'}<DnsRecordsPage page={p as DnsRecordsPageProps} urls={boot.urls} />
{:else if boot.page==='zones/record_edit'}<Page title={t('page.record-edit.title')} error={p.error} narrow><a class="button" href={url('records')}>← {t('dns.back-records')}</a>{#if p.record}<section class="box"><form method="post" action={`/accounts/${enc(p.accountId)}/zones/${enc(p.managedZoneId)}/records/${enc(p.record.id)}/update`}><input type="hidden" name="csrf_token" value={csrf}><div class="form-grid"><Field label={t('field.name')}><input class="input" name="name" value={p.record.name} required></Field><Field label="TTL"><input class="input" name="ttl" type="number" value={p.record.ttl} required></Field><Field label={t('field.content')}><input class="input" name="content" value={p.record.content} required></Field></div><button class="button is-primary">{t('common.save')}</button></form></section>{/if}</Page>
{:else if boot.page==='zones/dnssec'}<Page title={`${t('page.dnssec.title')} · ${p.managedZoneName ?? p.managedZoneId}`} error={p.error} success={p.success} narrow><nav class="page-actions" aria-label={t('dns.context')}><a class="button" href={url('accountZones')}>← {t('navigation.zones')}</a><a class="button" href={url('records')}>{t('dns.back-records')}</a><span>{t('dns.account', { id: p.accountId })}</span></nav><section class="box">{#if p.profile}<p>{t('dnssec.status')}: <strong>{t(`dnssec.state.${p.profile.state??'unknown'}`)}</strong></p>{#if p.profile.features?.manual_actions}<h2 class="subtitle mt-4">{t('dnssec.actions')}</h2><form class="buttons mt-4" method="post" onsubmit={(event) => { if (event.submitter instanceof HTMLButtonElement && event.submitter.value === 'disable' && !confirm(t('dns.confirm-disable', { zone: p.managedZoneName ?? p.managedZoneId }))) event.preventDefault(); }}><input type="hidden" name="csrf_token" value={csrf}><button class="button is-primary" name="action" value="enable">{t('common.enable')}</button><button class="button is-danger" name="action" value="disable">{t('common.disable')}</button></form>{:else}<Notice kind="info" text={t('dnssec.actions-unavailable')}/>{/if}{:else}<Notice kind="info" text={t('dnssec.unavailable')}/>{/if}</section></Page>
{/if}
