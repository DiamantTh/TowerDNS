<script lang="ts">
    import Page from '../../components/Page.svelte';
    import Field from '../../components/Field.svelte';
    import { pageUrl } from '../../lib/bootstrap';
    import { useI18n } from '../../lib/i18n';
    import type { PageBoot } from '../types';

    let { boot }: { boot: PageBoot } = $props();
    const t = useI18n();
    const p = $derived(boot.props);
    const back = $derived(p.zoneId === null
        ? pageUrl(boot.urls, 'account', { id: p.accountId })
        : pageUrl(boot.urls, 'records', { account: p.accountId, zone: p.zoneId }));
    const columns = ['created_at', 'action', 'target_type', 'target_id', 'actor_user_id', 'effective_user_id',
        'account_id', 'zone_id', 'provider_account_id', 'impersonation_session_id'];
    function pageLink(page: number) {
        return '?' + new URLSearchParams({ ...p.filters, page: String(page) }).toString();
    }
</script>

<Page title={t('audit.title')}>
    <nav class="page-actions"><a class="button" href={back}>← {p.zoneId === null ? t('navigation.accounts') : t('dns.back-records')}</a></nav>
    <form class="box form-grid" method="get">
        <Field label={t('audit.action')}><input class="input" name="action" maxlength="128" value={p.filters?.action ?? ''}></Field>
        <Field label={t('audit.target_type')}><input class="input" name="target" maxlength="64" value={p.filters?.target ?? ''}></Field>
        <Field label={t('audit.provider_account_id')}><input class="input" type="number" min="1" name="provider" value={p.filters?.provider ?? ''}></Field>
        <Field label={t('audit.since')}><input class="input" type="date" name="since" value={p.filters?.since ?? ''}></Field>
        <button class="button is-primary">{t('audit.filter')}</button>
    </form>
    {#each (p.events ?? []) as event (event.id)}
        <article class="box">
            <h2 class="subtitle">{event.action}</h2>
            <p><time>{event.created_at}</time></p>
            <details>
                <summary>{t('audit.details')}</summary>
                <dl>
                    {#each columns.slice(2) as column}
                        {#if event[column] != null}
                            <dt>{t(`audit.${column}`)}</dt>
                            <dd class="audit-value">{event[column]}</dd>
                        {/if}
                    {/each}
                </dl>
            </details>
        </article>
    {:else}
        <p class="empty">{t('audit.empty')}</p>
    {/each}
    <nav class="page-actions" aria-label={t('audit.pagination')}>
        {#if p.auditPage > 1}<a class="button" href={pageLink(p.auditPage - 1)}>{t('audit.previous')}</a>{/if}
        {#if p.hasMore}<a class="button" href={pageLink(p.auditPage + 1)}>{t('audit.next')}</a>{/if}
    </nav>
</Page>

<style>
    .audit-value { overflow-wrap: anywhere; }
</style>
