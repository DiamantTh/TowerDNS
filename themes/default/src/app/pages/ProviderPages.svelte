<script lang="ts">
    import Page from '../../components/Page.svelte';
    import Field from '../../components/Field.svelte';
    import ProviderCredentialFields from '../../components/ProviderCredentialFields.svelte';
    import type { PageBoot } from '../types';
    import { pageUrl } from '../../lib/bootstrap';
    import { useI18n } from '../../lib/i18n';
    type Data = Record<string, any>;
    type CredentialField = { input: string; label: string; required: boolean; secret: boolean; default?: string; type?: string };
    type ProviderDefinition = { label: string; user_managed: boolean; credentials: Record<string, CredentialField> };
    let { boot }: { boot: PageBoot } = $props();
    const p = $derived(boot.props);
    const csrf = $derived(p.csrfToken ?? '');
    const t = useI18n();
    const url = (name: string, parameters: Record<string, string | number> = {}) => pageUrl(boot.urls, name, parameters);
    const list = <T=Data>(v: T[]|null|undefined): T[] => v ?? [];
    const enc = (v: unknown) => encodeURIComponent(String(v ?? ''));
    const ask = (e: Event, text: string) => { if (!confirm(text)) e.preventDefault(); };
    let selectedProviderType = $state('');
    const providerDefinitions = (v: unknown): [string, ProviderDefinition][] => Object.entries((v ?? {}) as Record<string, ProviderDefinition>);
    const selectedProviderDefinition = $derived(providerDefinitions(p.providerDefinitions).find(([id]) => id === selectedProviderType)?.[1] ?? null);
    const credentialFields = (v: ProviderDefinition): CredentialField[] => Object.values(v.credentials ?? {});
    $effect(() => { const allowed = list<string>(p.allowedTypes); if (!allowed.includes(selectedProviderType)) selectedProviderType = allowed[0] ?? ''; });
</script>

{#if boot.page==='provider_accounts/list'}<Page title={t('providers.account-connections')} error={p.error} success={p.success}><p class="muted mb-4">{p.account?.name}</p>{#if list(p.allowedTypes).length}<section class="box"><h2 class="subtitle">{t('providers.connection-create')}</h2><form method="post" action={url("accountProviders", { id: p.account.id })}><input type="hidden" name="csrf_token" value={csrf}><div class="form-grid"><Field label={t('field.name')}><input class="input" name="name" maxlength="128" required></Field><Field label={t('field.type')}><select class="input" name="provider_type" bind:value={selectedProviderType} required>{#each list<string>(p.allowedTypes) as providerType}<option value={providerType}>{providerDefinitions(p.providerDefinitions).find(([id])=>id===providerType)?.[1].label??providerType}</option>{/each}</select></Field></div>{#if selectedProviderDefinition}<ProviderCredentialFields fields={credentialFields(selectedProviderDefinition)}/>{/if}<button class="button is-primary mt-4">{t('providers.connection-create')}</button></form></section>{/if}<section class="box"><h2 class="subtitle">{t('providers.account-connections')}</h2>{#each list(p.providers) as provider}{@const definition=providerDefinitions(p.providerDefinitions).find(([id])=>id===provider.providerType)?.[1]}<article class="resource-card provider-card"><div class="provider-card__summary"><strong>{provider.name}</strong><span class="status">{definition?.label??provider.providerType} · {provider.isActive?t('status.active'):t('status.inactive')}</span></div><div class="provider-card__actions">{#if definition}<details><summary>{t('providers.credentials-replace')}</summary><p class="muted">{t('providers.credentials-replace-hint')}</p><form method="post" action={`/accounts/${enc(p.account.id)}/providers/${enc(provider.id)}/replace`}><input type="hidden" name="csrf_token" value={csrf}><ProviderCredentialFields fields={credentialFields(definition)}/><button class="button mt-3">{t('providers.credentials-replace')}</button></form></details>{/if}{#if provider.isActive}<form method="post" action={`/accounts/${enc(p.account.id)}/providers/${enc(provider.id)}/deactivate`} onsubmit={(e)=>ask(e,t('providers.confirm-deactivate',{name:provider.name}))}><input type="hidden" name="csrf_token" value={csrf}><button class="button is-small is-danger">{t('providers.connection-deactivate')}</button></form>{/if}</div></article>{:else}<p class="empty">{t('providers.none-configured')}</p>{/each}</section></Page>
{:else if boot.page==='credentials'}<Page title={t('page.credentials.title')} error={p.error} success={p.success}>{#each providerDefinitions(p.providerDefinitions) as [providerId,provider]}<section class="box"><h2 class="subtitle">{provider.label} {p.configured?.[providerId]?`· ${t('status.configured')}`:''}</h2><form method="post"><input type="hidden" name="csrf_token" value={csrf}><input type="hidden" name="provider" value={providerId}><div class="form-grid">{#each credentialFields(provider) as field}<Field label={field.label}>{#if field.type==='checkbox'}<input class="checkbox" type="checkbox" name={field.input} value="1" checked={p.values?.[field.input]==='1'}>{:else}<input class="input" type={field.secret?'password':'text'} name={field.input} value={p.values?.[field.input]??field.default??''} required={field.required}>{/if}</Field>{/each}</div><button class="button is-primary">{t('common.save')}</button></form></section>{/each}</Page>
{/if}
