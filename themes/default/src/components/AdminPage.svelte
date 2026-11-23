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
        zones?: (JsonObject & { managed_zone_id?: number; canonical_name?: string; enabled?: boolean | number; smtp_dane_enabled?: boolean | number; interval_minutes?: number; status?: string; severity?: string; method?: string; checked_at?: string; changed_at?: string; stale?: boolean; active_status?: string; acknowledged_at?: string; muted_until?: string; recipient?: string; tlsa_owner?: string; tlsa_expected?: string; locale?: string; minimum?: string; confirmation_runs?: number })[];
        history?: (JsonObject & { check_type?: string; previous_status?: string | null; status?: string; severity?: string; occurred_at?: string })[];
        deliveries?: JsonObject[];
        monitoringReadOnly?: boolean;
        monitoringCanConfigure?: boolean;
        monitoringAction?: string;
        monitoringBackHref?: string;
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
            case 'authoritative_missing': return t('monitoring.status.authoritative_missing');
            case 'authoritative_divergent': return t('monitoring.status.authoritative_divergent');
            case 'authoritative_unknown': return t('monitoring.status.authoritative_unknown');
            case 'api_key_expiring': return t('monitoring.status.api_key_expiring');
            case 'recovery': return t('monitoring.status.recovery');
            default: return t('monitoring.status.indeterminate');
        }
    }
    function deliveryStatusLabel(status: unknown): string {
        switch (status) {
            case 'pending': return t('monitoring.delivery.pending');
            case 'sending': return t('monitoring.delivery.sending');
            case 'sent': return t('monitoring.delivery.sent');
            case 'failed': return t('monitoring.delivery.failed');
            case 'cancelled': return t('monitoring.delivery.cancelled');
            default: return t('monitoring.delivery.unknown');
        }
    }
</script>

{#if page === 'admin/monitoring'}
    <section class="box">
        {#if data.monitoringBackHref}<a class="button" href={data.monitoringBackHref}>{t('dns.back-records')}</a>{/if}
        <p class="muted">{t('monitoring.description')}</p>
        {#each data.zones ?? [] as zone}
            <article class="resource-card">
                <div>
                    <strong>{zone.canonical_name}</strong>
                    {#if zone.status}
                        <span class="status">{monitoringStatusLabel(zone.status)}</span>
                        {#if zone.stale}<small>{t('monitoring.stale')}</small>{/if}
                        {#if zone.active_status}<small>{t('monitoring.active')} {monitoringStatusLabel(zone.active_status)}</small>{/if}
                        {#if zone.acknowledged_at}<small>{t('monitoring.acknowledged')} {zone.acknowledged_at} UTC</small>{/if}
                        {#if zone.muted_until}<small>{t('monitoring.muted-until')} {zone.muted_until} UTC</small>{/if}
                        <small>{t('monitoring.last-checked')}: {zone.checked_at ?? t('monitoring.never')}</small>
                        <small>{t('monitoring.method')}: {zone.method ?? t('monitoring.not-checked')}</small>
                    {:else}
                        <small>{t('monitoring.not-checked')}</small>
                    {/if}
                </div>
                {#if !data.monitoringReadOnly || data.monitoringCanConfigure}
                <form method="post" action={data.monitoringAction ?? "/admin/monitoring"} class="form-grid">
                    <input type="hidden" name="csrf_token" value={csrf}>
                    <input type="hidden" name="managed_zone_id" value={zone.managed_zone_id}>
                    <label class="checkbox"><input type="checkbox" name="enabled" value="1" checked={zone.enabled === true || zone.enabled === 1}> {t('monitoring.enabled')}</label>
                    <label class="checkbox"><input type="checkbox" name="smtp_dane_enabled" value="1" checked={zone.smtp_dane_enabled === true || zone.smtp_dane_enabled === 1}> {t('monitoring.smtp-dane')}</label>
                    <Field label={t('monitoring.interval')}><input class="input" type="number" min="5" max="1440" name="interval_minutes" value={zone.interval_minutes ?? 60} required></Field>
                    <button class="button is-primary">{t('common.save')}</button>
                    {#if !data.monitoringReadOnly}<a class="button" href={'/admin/monitoring?zone=' + zone.managed_zone_id}>{t('monitoring.history')}</a>{/if}
                </form>
                {#if !data.monitoringReadOnly && zone.managed_zone_id === data.selectedZoneId}
                    <form method="post" action="/admin/monitoring" class="form-grid">
                        <input type="hidden" name="operation" value="alerts">
                        <input type="hidden" name="csrf_token" value={csrf}>
                        <input type="hidden" name="managed_zone_id" value={zone.managed_zone_id}>
                        <input type="hidden" name="interval_minutes" value={zone.interval_minutes ?? 60}>
                        <Field label={t('monitoring.tlsa-owner')}><input class="input" name="tlsa_owner" maxlength="200" placeholder="_443._tcp" value={zone.tlsa_owner ?? ''}></Field>
                        <Field label={t('monitoring.tlsa-expected')}><textarea class="input" name="tlsa_expected" maxlength="8192" value={zone.tlsa_expected ?? ''}></textarea></Field>
                        <Field label={t('monitoring.recipient')}><input class="input" type="email" maxlength="254" name="recipient" value={zone.recipient ?? ''}></Field>
                        <Field label={t('monitoring.language')}><select name="locale" value={zone.locale ?? 'en-GB'}><option value="en-GB">English</option><option value="de-DE">Deutsch</option></select></Field>
                        <Field label={t('monitoring.minimum')}><select name="minimum" value={zone.minimum ?? 'warning'}><option value="warning">{t('monitoring.warning')}</option><option value="critical">{t('monitoring.critical')}</option></select></Field>
                        <Field label={t('monitoring.confirmation')}><input class="input" type="number" name="confirmation_runs" min="2" max="5" value={zone.confirmation_runs ?? 2} required></Field>
                        <Field label={t('monitoring.mute')}><input class="input" type="number" name="mute_hours" min="0" max="168" value="0" required></Field>
                        <label class="checkbox"><input type="checkbox" name="acknowledge" value="1"> {t('monitoring.acknowledge')}</label>
                        <button class="button is-primary">{t('common.save')}</button>
                    </form>
                {/if}
                {/if}
            </article>
        {:else}
            <p class="empty">{t('monitoring.no-zones')}</p>
        {/each}
    </section>
    {#if data.selectedZoneId && (data.history?.length ?? 0) > 0}
        <section class="box">
            <h2 class="subtitle">{t('monitoring.history')}</h2>
            <DataTable rows={(data.history ?? []).map(row => ({...row, previous_status: row.previous_status ? monitoringStatusLabel(row.previous_status) : null, status: row.status ? monitoringStatusLabel(row.status) : null}))} cols={['previous_status','status','severity','occurred_at']} labels={[t('monitoring.previous-state'),t('field.status'),t('monitoring.severity'),t('monitoring.checked-at')]} />
        </section>
    {/if}
    {#if (data.deliveries?.length ?? 0) > 0}
        <section class="box"><h2 class="subtitle">{t('monitoring.deliveries')}</h2>
            <DataTable rows={(data.deliveries ?? []).map(row => ({ ...row, status: deliveryStatusLabel(row.status), kind: monitoringStatusLabel(String(row.kind)) }))} cols={['kind','status','attempts','occurred_at']} labels={[t('monitoring.history'),t('field.status'),t('monitoring.attempts'),t('monitoring.checked-at')]} />
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
