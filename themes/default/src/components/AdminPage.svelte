<script lang="ts">
    import DataTable from './DataTable.svelte';
    import Field from './Field.svelte';
    import Notice from './Notice.svelte';
    import { useI18n } from '../lib/i18n';

    type JsonObject = Record<string, unknown>;
    type Migration = { version: string; description: string };
    type SchemaStatus = {
        metadataInitialized: boolean;
        canBaseline: boolean;
        schemaCurrent: boolean;
        pending: Migration[];
        schemaIssues: string[];
        dataIssues: string[];
    };
    type AdminPageData = JsonObject & {
        status?: SchemaStatus;
        zones?: (JsonObject & { managed_zone_id?: number; canonical_name?: string; enabled?: boolean | number; smtp_dane_enabled?: boolean | number; interval_minutes?: number; status?: string; severity?: string; method?: string; checked_at?: string; changed_at?: string })[];
        history?: (JsonObject & { check_type?: string; previous_status?: string | null; status?: string; severity?: string; occurred_at?: string })[];
        selectedZoneId?: number | null;
        users?: JsonObject[];
        roles?: JsonObject[];
        accounts?: JsonObject[];
        providers?: JsonObject[];
        target?: JsonObject & { displayName?: string | null };
        role?: JsonObject;
        account?: JsonObject;
        profile?: JsonObject;
    };

    let { page, data, csrf }: { page: string; data: AdminPageData; csrf: string } = $props();
    const t = useI18n();

    function monitoringStatusLabel(status: string): string {
        switch (status) {
            case 'secure_records_published': return t('monitoring.status.secure_records_published');
            case 'secure_partial_tlsa': return t('monitoring.status.secure_partial_tlsa');
            case 'published_without_dnssec': return t('monitoring.status.published_without_dnssec');
            case 'secure_no_tlsa': return t('monitoring.status.secure_no_tlsa');
            case 'secure_no_mx': return t('monitoring.status.secure_no_mx');
            case 'dnssec_insecure': return t('monitoring.status.dnssec_insecure');
            case 'dnssec_bogus': return t('monitoring.status.dnssec_bogus');
            case 'check_execution_failed': return t('monitoring.status.check_execution_failed');
            case 'not_checked': return t('monitoring.status.not_checked');
            default: return t('monitoring.status.indeterminate');
        }
    }
</script>

{#if page === 'admin/monitoring'}
    <section class="box">
        <p class="muted">{t('monitoring.description')}</p>
        {#each data.zones ?? [] as zone}
            <article class="resource-card">
                <div>
                    <strong>{zone.canonical_name}</strong>
                    {#if zone.status}
                        <span class="status">{monitoringStatusLabel(zone.status)}</span>
                        <small>{t('monitoring.last-checked')}: {zone.checked_at ?? t('monitoring.never')}</small>
                        <small>{t('monitoring.method')}: {zone.method ?? t('monitoring.not-checked')}</small>
                    {:else}
                        <small>{t('monitoring.not-checked')}</small>
                    {/if}
                </div>
                <form method="post" action="/admin/monitoring" class="form-grid">
                    <input type="hidden" name="csrf_token" value={csrf}>
                    <input type="hidden" name="managed_zone_id" value={zone.managed_zone_id}>
                    <label class="checkbox"><input type="checkbox" name="enabled" value="1" checked={zone.enabled === true || zone.enabled === 1}> {t('monitoring.enabled')}</label>
                    <label class="checkbox"><input type="checkbox" name="smtp_dane_enabled" value="1" checked={zone.smtp_dane_enabled === true || zone.smtp_dane_enabled === 1}> {t('monitoring.smtp-dane')}</label>
                    <Field label={t('monitoring.interval')}><input class="input" type="number" min="5" max="1440" name="interval_minutes" value={zone.interval_minutes ?? 60} required></Field>
                    <button class="button is-primary">{t('common.save')}</button>
                    <a class="button" href={'/admin/monitoring?zone=' + zone.managed_zone_id}>{t('monitoring.history')}</a>
                </form>
            </article>
        {:else}
            <p class="empty">{t('monitoring.no-zones')}</p>
        {/each}
    </section>
    {#if data.selectedZoneId && (data.history?.length ?? 0) > 0}
        <section class="box">
            <h2 class="subtitle">{t('monitoring.history')}</h2>
            <DataTable rows={data.history ?? []} cols={['previous_status','status','severity','occurred_at']} labels={[t('monitoring.previous-state'),t('field.status'),t('monitoring.severity'),t('monitoring.checked-at')]} />
        </section>
    {/if}
{:else if page === 'settings/schema'}
    <section class="box">
        <p class="muted">{t('schema.backup-hint')}</p>
        <dl>
            <dt>{t('schema.metadata.initialized')}</dt>
            <dd>{data.status?.metadataInitialized ? t('common.yes') : t('schema.metadata.not-initialized')}</dd>
            <dt>{t('schema.current')}</dt>
            <dd>{data.status?.schemaCurrent ? t('common.yes') : t('common.no')}</dd>
            <dt>{t('schema.pending')}</dt>
            <dd>{data.status?.pending.length ?? 0}</dd>
        </dl>
        {#if data.status?.dataIssues.length}
            <Notice kind="danger" text={t('schema.state.data-invalid')} />
        {:else if data.status?.schemaIssues.length}
            <Notice kind="danger" text={t('schema.state.invalid')} />
        {:else if data.status?.canBaseline}
            <Notice kind="info" text={t('schema.state.baseline')} />
        {:else if data.status?.pending.length}
            <Notice kind="warning" text={t('schema.state.pending')} />
        {:else if data.status?.metadataInitialized && data.status?.schemaCurrent}
            <Notice kind="success" text={t('schema.state.current')} />
        {:else}
            <Notice kind="danger" text={t('schema.state.metadata-missing')} />
        {/if}
        {#if data.status && (data.status.schemaIssues.length || data.status.dataIssues.length)}
            <h2 class="subtitle">{t('schema.validation-issues')}</h2>
            <ul>{#each data.status.schemaIssues as issue}<li>{issue}</li>{/each}</ul>
            <ul>{#each data.status.dataIssues as issue}<li>{issue}</li>{/each}</ul>
        {/if}
        {#if data.status?.pending.length || data.status?.canBaseline}
            <h2 class="subtitle">{t('schema.pending')}</h2>
            <ul>{#each data.status.pending as migration}<li><code>{migration.version}</code> — {migration.description}</li>{/each}</ul>
            <form method="post" class="mt-4" onsubmit={(event) => { if (!confirm(t('schema.confirm-apply'))) event.preventDefault(); }}>
                <input type="hidden" name="csrf_token" value={csrf}>
                <div class="settings-option"><input id="schema-backup-confirmed" type="checkbox" name="backup_confirmed" value="1" required><label for="schema-backup-confirmed">{t('schema.backup-confirm')}</label></div>
                <button class="button is-primary">{t('schema.apply')}</button>
            </form>
        {:else if data.status?.metadataInitialized && data.status?.schemaCurrent && !(data.status?.schemaIssues.length || data.status?.dataIssues.length)}
            <p class="empty">{t('schema.no-pending')}</p>
        {/if}
    </section>
{:else}
    <section class="box">
        <p class="muted">{t('admin.modern-view')}</p>
        {#if data.users}
            <DataTable rows={data.users} cols={['email', 'displayName']} labels={[t('field.email'), t('field.display-name')]} />
        {:else if data.roles}
            <DataTable rows={data.roles} cols={['name', 'isBuiltIn']} labels={[t('field.name'), t('roles.built-in')]} />
        {:else if data.accounts}
            <DataTable rows={data.accounts} cols={['name', 'slug', 'isActive']} labels={[t('field.name'), t('field.slug'), t('status.active')]} />
        {:else if data.providers}
            <DataTable rows={data.providers} cols={['name', 'providerType', 'isActive']} labels={[t('field.name'), t('field.type'), t('status.active')]} />
        {:else}
            <pre class="metadata">{JSON.stringify(data.target ?? data.role ?? data.account ?? data.profile ?? {}, null, 2)}</pre>
        {/if}
        <form class="mt-4" method="post" action={page === 'admin/switch' ? '/admin/switch/start' : undefined}>
            <input type="hidden" name="csrf_token" value={csrf}>
            {#if data.target}
                <Field label={t('field.display-name')}><input class="input" name="display_name" value={data.target.displayName ?? ''} /></Field>
                <input type="hidden" name="action" value="display_name">
                <button class="button is-primary">{t('common.save')}</button>
            {:else if page === 'admin/switch'}
                <Field label={t('field.target-user-id')}><input class="input" name="effective_user_id" required /></Field>
                <Field label={t('field.reason')}><input class="input" name="reason" required /></Field>
                <button class="button is-danger">{t('common.switch')}</button>
            {/if}
        </form>
    </section>
{/if}
