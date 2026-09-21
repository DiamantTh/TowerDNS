<script lang="ts">
    import DataTable from './DataTable.svelte';
    import Field from './Field.svelte';
    import { useI18n } from '../lib/i18n';

    type JsonObject = Record<string, unknown>;
    type Migration = { version: string; description: string };
    type SchemaStatus = {
        metadataInitialized: boolean;
        schemaCurrent: boolean;
        pending: Migration[];
        schemaIssues: string[];
        dataIssues: string[];
    };
    type AdminPageData = JsonObject & {
        status?: SchemaStatus;
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
</script>

{#if page === 'settings/schema'}
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
        {#if data.status && (data.status.schemaIssues.length || data.status.dataIssues.length)}
            <h2 class="subtitle">{t('schema.validation-issues')}</h2>
            <ul>{#each data.status.schemaIssues as issue}<li>{issue}</li>{/each}</ul>
            <ul>{#each data.status.dataIssues as issue}<li>{issue}</li>{/each}</ul>
        {/if}
        {#if data.status?.pending.length}
            <h2 class="subtitle">{t('schema.pending')}</h2>
            <ul>{#each data.status.pending as migration}<li><code>{migration.version}</code> — {migration.description}</li>{/each}</ul>
            <form method="post" class="mt-4">
                <input type="hidden" name="csrf_token" value={csrf}>
                <button class="button is-primary">{t('schema.apply')}</button>
            </form>
        {:else}
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
        <form class="mt-4" method="post">
            <input type="hidden" name="csrf_token" value={csrf}>
            {#if data.target}
                <Field label={t('field.display-name')}><input class="input" name="display_name" value={data.target.displayName ?? ''} /></Field>
                <input type="hidden" name="action" value="display_name">
                <button class="button is-primary">{t('common.save')}</button>
            {:else if page === 'admin/switch'}
                <Field label={t('field.target-user-id')}><input class="input" name="effective_user_id" /></Field>
                <Field label={t('field.reason')}><input class="input" name="reason" /></Field>
                <button class="button is-danger">{t('common.switch')}</button>
            {/if}
        </form>
    </section>
{/if}
