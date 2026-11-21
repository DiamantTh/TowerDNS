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
    let manualTlsaVersion = $state('new');
    let tlsaCertificates = $state('');
    let tlsaPort = $state(443);
    let tlsaProtocol = $state('tcp');
    let tlsaUsage = $state(3);
    let tlsaSelector = $state(1);
    let tlsaMatching = $state(1);
    let tlsaBusy = $state(false);
    let tlsaError = $state('');
    let tlsaPreview = $state<string[]>([]);
    let tlsaHost = $state('');
    let tlsaTtl = $state(300);
    let tlsaToken: string | undefined;
    let tlsaState = $state<{ records: string[]; ttl: number | null; conditional: boolean; capabilities?: { conditional_replace: boolean; append_record: boolean; remove_record: boolean; atomic_multi_record: boolean; consistency: string } } | null>(null);
    let tlsaPublication = $state<{ owner: string; status: string; checked_at: string; discovery_source: string; expected: string[]; provider_readback: string[]; servers: { nameserver: string; address: string | null; status: string; rcode: string | null; authoritative: boolean; records: string[]; minimum_ttl: number | null }[]; scope: string; dnssec: string; dane: string } | null>(null);
    let tlsaDnssec = $state<{ owner: string; dnssec: string; dnssec_validation: { status: string; checked_at: string; method: string; resolver: string | null; reason: string | null; scope: string } } | null>(null);
    let smtpDane = $state<{ status: string; checked_at: string; method: string; mx_dnssec?: string; mx: { preference: number; host: string; ttl: number; tlsa_records: string[]; tlsa_status?: string; dnssec: string }[]; dane: string; smtp_tls?: string; scope?: string } | null>(null);
    let certificateDiagnostic = $state<{ owner: string; hostname: string; checked_at: string; publication: { status: string; servers: { nameserver: string; status: string; records: string[]; certificate_matches?: { record: string; association: string; certificate_indexes: number[]; pkix: string; dane_authentication: string }[] }[] }; dnssec_validation: { status: string }; dane_authentication: string; server_certificate_seen: boolean } | null>(null);
    let tlsaVersion = '';
    let tlsaChangeToken = '';
    let tlsaPreviewOwner = $state('');
    let tlsaMessage = $state('');
    let tlsaGeneratedFrom = $state('');
    let certificateChoices = $state<{ index: number; subject: string; key_type: string; is_ca: boolean; validity: string; hostname: string; pkix_chain: string }[]>([]);
    let selectedCertificates = $state<number[]>([]);
    const tlsaInputIdentity = $derived(JSON.stringify([tlsaCertificates, tlsaUsage, tlsaSelector, tlsaMatching, selectedCertificates]));
    const tlsaOwner = $derived(`_${tlsaPort}._${tlsaProtocol}${tlsaHost.trim() ? '.' + tlsaHost.trim() : ''}`);
    const tlsaEndpoint = (name: string) => `/accounts/${page.accountId}/zones/${page.managedZoneId}/tlsa?owner=${encodeURIComponent(name)}`;
    let editor: HTMLTextAreaElement;
    const rrsets = $derived(visibleRrsets(page.rrsets, query, typeFilter));
    const types = $derived([...new Set(page.rrsets.map(rrset => rrset.type.presentation))].sort());
    const reset = () => { owner = '@'; typeChoice = 'A'; customType = ''; ttl = 300; rdata = ''; editing = false; manualTlsaVersion = 'new'; };
    const edit = async (rrset: DnsRrsetBootstrap) => {
        if (!page.canReplaceRrsets) return;
        owner = rrset.ownerName || '@';
        typeChoice = page.recordTypes.includes(rrset.type.presentation) ? rrset.type.presentation : 'custom';
        manualTlsaVersion = rrset.tlsaVersion ?? 'new';
        customType = rrset.type.presentation; ttl = rrset.ttl; rdata = rrset.rdata.join('\n'); editing = true;
        await tick(); editor?.focus(); editor?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    };

    const url = (name: string, parameters: Record<string, string | number>) => pageUrl(urls, name, parameters);

    const confirmDelete = (event: SubmitEvent, rrset: DnsRrsetBootstrap): void => {
        if (!confirm(t('dns.confirm-delete-rrset', { zone: page.managedZoneName, owner: rrset.ownerName || '@', type: rrset.type.presentation }))) event.preventDefault();
    };

    async function loadTlsa() {
        tlsaState = null;
        tlsaPreviewOwner = tlsaOwner;
        const response = await fetch(tlsaEndpoint(tlsaPreviewOwner));
        if (!response.ok) throw new Error('read');
        const data = await response.json();
        tlsaState = data;
        tlsaVersion = response.headers.get('ETag') ?? '';
        tlsaChangeToken = data.csrf_token ?? '';
    }

    async function inspectOrGenerate(inspect: boolean) {
        tlsaBusy = true;
        tlsaError = '';
        tlsaMessage = '';
        tlsaPreview = [];
        const inputIdentity = tlsaInputIdentity;
        try {
            const body = new URLSearchParams({ csrf_token: tlsaToken ?? page.tlsaPreviewToken ?? '', certificates: tlsaCertificates,
                usage: String(tlsaUsage), selector: String(tlsaSelector), matching: String(tlsaMatching) });
            if (inspect) {
                body.set('inspect', '1');
                body.set('hostname', [tlsaHost.trim().replace(/\.$/, ''), page.managedZoneName].filter(Boolean).join('.'));
            }
            else if (certificateChoices.length) body.set('indexes', selectedCertificates.join(','));
            const response = await fetch('/tlsa/generate', { method: 'POST', body });
            const data = await response.json();
            tlsaToken = data.csrf_token ?? tlsaToken;
            if (!response.ok) throw new Error('invalid');
            if (inspect) {
                certificateChoices = data.certificates;
                selectedCertificates = [];
            } else {
                tlsaGeneratedFrom = inputIdentity;
                tlsaPreview = data.records;
                await loadTlsa();
            }
        } catch {
            tlsaError = t('tlsa.generator.error');
        } finally {
            tlsaBusy = false;
        }
    }

    async function readCurrentTlsa() {
        tlsaBusy = true;
        tlsaError = '';
        tlsaPreview = [];
        try {
            await loadTlsa();
        } catch {
            tlsaError = t('tlsa.generator.error');
        } finally {
            tlsaBusy = false;
        }
    }

    async function verifyPublicTlsa() {
        tlsaBusy = true;
        tlsaError = '';
        tlsaPublication = null;
        try {
            const response = await fetch(tlsaEndpoint(tlsaOwner) + '&verify=authoritative');
            const data = await response.json();
            if (!response.ok) throw new Error('read');
            tlsaPublication = data;
        } catch {
            tlsaError = t('tlsa.publication.error');
        } finally {
            tlsaBusy = false;
        }
    }

    async function verifyDnssec() {
        tlsaBusy = true;
        tlsaError = '';
        try {
            const response = await fetch(tlsaEndpoint(tlsaOwner) + '&verify=dnssec');
            const data = await response.json();
            if (!response.ok) throw new Error('dnssec');
            tlsaDnssec = data;
        } catch {
            tlsaError = t('tlsa.diagnostic.error');
        } finally {
            tlsaBusy = false;
        }
    }

    async function verifySmtpDane() {
        tlsaBusy = true;
        tlsaError = '';
        try {
            const response = await fetch(tlsaEndpoint(tlsaOwner) + '&verify=smtp-dane');
            const data = await response.json();
            if (!response.ok) throw new Error('smtp-dane');
            smtpDane = data.smtp_dane;
        } catch {
            tlsaError = t('tlsa.diagnostic.error');
        } finally {
            tlsaBusy = false;
        }
    }

    async function compareCertificatesWithPublicTlsa() {
        tlsaBusy = true;
        tlsaError = '';
        try {
            const response = await fetch(`/accounts/${page.accountId}/zones/${page.managedZoneId}/tlsa/diagnostic`, {
                method: 'POST',
                body: new URLSearchParams({ csrf_token: tlsaToken ?? page.tlsaPreviewToken ?? '', owner: tlsaOwner, certificates: tlsaCertificates }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error('diagnostic');
            tlsaToken = data.csrf_token ?? tlsaToken;
            certificateDiagnostic = data;
        } catch {
            tlsaError = t('tlsa.diagnostic.error');
        } finally {
            tlsaBusy = false;
        }
    }

    async function importTlsa(event: Event) {
        const files = Array.from((event.currentTarget as HTMLInputElement).files ?? []);
        certificateChoices = [];
        selectedCertificates = [];
        tlsaPreview = [];
        certificateDiagnostic = null;
        if (files.length > 32 || files.reduce((size, file) => size + file.size, 0) > 750000) {
            tlsaError = t('tlsa.generator.error');
            return;
        }
        try {
            const parts = [];
            for (const file of files) {
                const bytes = new Uint8Array(await file.arrayBuffer());
                const content = new TextDecoder().decode(bytes).trim();
                if (content.startsWith('-----BEGIN')) {
                    parts.push(content);
                    continue;
                }
                let binary = '';
                for (const byte of bytes) binary += String.fromCharCode(byte);
                parts.push('-----BEGIN CERTIFICATE-----\n' + btoa(binary) + '\n-----END CERTIFICATE-----');
            }
            tlsaCertificates = parts.join('\n');
        } catch {
            tlsaError = t('tlsa.generator.error');
        }
    }

    async function saveTlsa(add: string[], remove: string[]) {
        const canAdd = !!tlsaState?.capabilities?.append_record || !!tlsaState?.conditional;
        const canRemove = !!tlsaState?.capabilities?.remove_record || !!tlsaState?.conditional;
        if (!tlsaState || tlsaPreviewOwner !== tlsaOwner || (add.length > 0 && (!canAdd || !page.canReplaceRrsets)) || (remove.length > 0 && (!canRemove || !page.canDeleteRrsets))) return;
        if (remove.length && !confirm(t('tlsa.generator.confirm-remove'))) return;
        tlsaBusy = true;
        tlsaError = '';
        tlsaMessage = '';
        try {
            const response = await fetch(tlsaEndpoint(tlsaPreviewOwner), {
                method: 'POST',
                headers: { 'If-Match': tlsaVersion },
                body: new URLSearchParams({ csrf_token: tlsaChangeToken, change: JSON.stringify({ add, remove, ttl: tlsaTtl }) }),
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                const providerConflict = response.status === 409 && error.error === 'provider_state_conflict';
                tlsaError = t(response.status === 412 || providerConflict ? 'tlsa.generator.conflict' : response.status === 409 ? 'tlsa.generator.provider-unsupported' : 'tlsa.generator.write-error');
                if (response.status === 412 || providerConflict) await loadTlsa();
                return;
            }
            tlsaMessage = t('tlsa.generator.saved');
            tlsaPreview = [];
            await loadTlsa();
        } catch {
            tlsaError = t('tlsa.generator.write-error');
        } finally {
            tlsaBusy = false;
        }
    }
</script>

<div class="dns-records-view">
<Page title={page.managedZoneName} error={page.error} success={page.success}>
    <nav class="page-actions" aria-label={t('dns.context')}>
        <span>{t('dns.account', { id: page.accountId })}</span>
        <a class="button" href={url('accountZones', { account: page.accountId })}>← {t('navigation.zones')}</a>
        <a class="button" href={url('dnssec', { account: page.accountId, zone: page.managedZoneId })}>DNSSEC</a>
        {#if page.canReadAudit}<a class="button" href={`/accounts/${page.accountId}/zones/${page.managedZoneId}/audit`}>{t('audit.title')}</a>{/if}
    </nav>

    <details class="box">
        <summary class="subtitle">{t('tlsa.generator.title')}</summary>
        <p class="muted">{t('tlsa.generator.help')}</p>
        <div class="form-grid">
            <Field label={t('tlsa.generator.host')}><input class="input" bind:value={tlsaHost} placeholder={page.managedZoneName}></Field>
            <Field label={t('rrset.column.ttl')}><input class="input" type="number" min="30" max="604800" bind:value={tlsaTtl} disabled={tlsaState?.ttl != null}></Field>
            <Field label={t('tlsa.generator.port')}><input class="input" type="number" min="1" max="65535" bind:value={tlsaPort}></Field>
            <Field label={t('tlsa.generator.protocol')}><select class="input" bind:value={tlsaProtocol}><option value="tcp">TCP</option><option value="udp">UDP</option></select></Field>
            <Field label={t('tlsa.generator.usage')}><select class="input" bind:value={tlsaUsage}>{#each [0,1,2,3] as n}<option value={n}>{n} · {["PKIX-TA", "PKIX-EE", "DANE-TA", "DANE-EE"][n]}</option>{/each}</select></Field>
            <Field label={t('tlsa.generator.selector')}><select class="input" bind:value={tlsaSelector}><option value={0}>{t('tlsa.generator.certificate')}</option><option value={1}>SPKI</option></select></Field>
            <Field label={t('tlsa.generator.matching')}><select class="input" bind:value={tlsaMatching}><option value={0}>{t('tlsa.generator.full')}</option><option value={1}>SHA-256</option><option value={2}>SHA-512</option></select></Field>
        </div>
        <Field label={t('tlsa.generator.certificates')}><input class="input" type="file" accept=".cer,.crt,.der,.pem" multiple onchange={importTlsa}></Field>
        <Field label={t('tlsa.generator.certificates')}><textarea class="input" rows="8" bind:value={tlsaCertificates} oninput={() => { certificateChoices = []; selectedCertificates = []; tlsaPreview = []; certificateDiagnostic = null; }} placeholder="-----BEGIN CERTIFICATE-----"></textarea></Field>
        <button type="button" class="button" disabled={tlsaBusy || !tlsaCertificates.trim()} onclick={() => inspectOrGenerate(true)}>{t('tlsa.generator.inspect')}</button>
        {#each certificateChoices as cert}
            <label class="block"><input type="checkbox" bind:group={selectedCertificates} value={cert.index} disabled={tlsaBusy}>
                #{cert.index + 1} · {cert.subject} · {cert.key_type} · {t(cert.is_ca ? 'tlsa.generator.ca' : 'tlsa.generator.ee')} · {t(`tlsa.validity.${cert.validity}`)}
                {#if !cert.is_ca}
                    <span class="muted">· {t(`tlsa.hostname.${cert.hostname}`)} · {t(`tlsa.chain.${cert.pkix_chain}`)}</span>
                {/if}
            </label>
        {/each}
        <button type="button" class="button" disabled={tlsaBusy || !tlsaCertificates.trim() || (certificateChoices.length > 0 && selectedCertificates.length === 0)} onclick={() => inspectOrGenerate(false)}>{t('tlsa.generator.generate')}</button>
        <button type="button" class="button" disabled={tlsaBusy} onclick={readCurrentTlsa}>{t('tlsa.generator.read')}</button>
        <button type="button" class="button" disabled={tlsaBusy || !tlsaState || tlsaPreviewOwner !== tlsaOwner} onclick={verifyPublicTlsa}>{t('tlsa.publication.check')}</button>
        <button type="button" class="button" disabled={tlsaBusy || !tlsaState || tlsaPreviewOwner !== tlsaOwner} onclick={verifyDnssec}>{t('tlsa.dnssec.check')}</button>
        <button type="button" class="button" disabled={tlsaBusy || !tlsaState || !tlsaCertificates.trim()} onclick={compareCertificatesWithPublicTlsa}>{t('tlsa.diagnostic.compare-certificates')}</button>
        {#if tlsaPort === 25 && tlsaProtocol === 'tcp'}<button type="button" class="button" disabled={tlsaBusy || !tlsaState} onclick={verifySmtpDane}>{t('tlsa.smtp-dane.check')}</button>{/if}
        {#if tlsaError}<p role="alert" class="has-text-danger">{tlsaError}</p>{/if}
        {#if tlsaMessage}<p role="status">{tlsaMessage}</p>{/if}
        {#if tlsaState && tlsaPreviewOwner === tlsaOwner}
            <h3 class="subtitle">{tlsaPreviewOwner}</h3>
            {#if tlsaState.ttl !== null}<p>TTL: {tlsaState.ttl}</p>{/if}
            {#if !tlsaState.conditional && (tlsaState.capabilities?.append_record || tlsaState.capabilities?.remove_record)}<p role="status">{t('tlsa.generator.targeted-no-cas')}</p>{/if}
            {#if !tlsaState.conditional && !tlsaState.capabilities?.append_record && !tlsaState.capabilities?.remove_record}<p role="status">{t('tlsa.generator.unsupported')}</p>{/if}
            {#each tlsaState.records as record}
                <p><code class="rdata-value">{record}</code>
                    {#if (tlsaState.conditional || tlsaState.capabilities?.remove_record) && page.canDeleteRrsets}<button type="button" class="button is-small" disabled={tlsaBusy} onclick={() => saveTlsa([], [record])}>{t('tlsa.generator.remove')}</button>{/if}
                </p>
            {/each}
            {#if tlsaPreview.length && tlsaGeneratedFrom === tlsaInputIdentity}
                <section aria-live="polite">
                    <h3 class="subtitle">{t('tlsa.generator.preview')}</h3>
                    {#each tlsaPreview as record}<p><code class="rdata-value">{record}</code>{#if tlsaState.records.includes(record)} · {t('tlsa.generator.present')}{/if}</p>{/each}
                    <button type="button" class="button is-primary" disabled={!page.canReplaceRrsets || (!tlsaState.conditional && !tlsaState.capabilities?.append_record) || tlsaBusy} onclick={() => saveTlsa(tlsaPreview, [])}>{t('tlsa.generator.apply')}</button>
                </section>
            {/if}
        {/if}
        {#if tlsaPublication && tlsaPublication.owner === tlsaOwner}
            <section class="box" aria-live="polite">
                <h3 class="subtitle">{t('tlsa.publication.title')}: {t(`tlsa.publication.${tlsaPublication.status}`)}</h3>
                <p>{t('tlsa.publication.checked-at', { date: tlsaPublication.checked_at })}</p>
                <p class="muted">{t('tlsa.publication.scope')}</p>
                <p>{t('tlsa.publication.dnssec')}: {t(`tlsa.publication.${tlsaPublication.dnssec}`)} · {t('tlsa.publication.dane')}: {t(`tlsa.publication.${tlsaPublication.dane}`)}</p>
                <details>
                    <summary>{t('tlsa.publication.details')}</summary>
                    <p>{t('tlsa.publication.discovery-source')}: {t(`tlsa.publication.discovery.${tlsaPublication.discovery_source}`)}</p>
                    {#each tlsaPublication.servers as server}
                        <article class="box">
                            <strong>{server.nameserver}</strong>{#if server.address} · <code>{server.address}</code>{/if}
                            <p>{t(`tlsa.publication.${server.status}`)} · {server.rcode ?? '—'} · AA={server.authoritative ? '1' : '0'} · TTL={server.minimum_ttl ?? '—'}</p>
                            {#each server.records as record}<p><code class="rdata-value">{record}</code></p>{/each}
                        </article>
                    {/each}
                </details>
            </section>
        {/if}
        {#if tlsaDnssec && tlsaDnssec.owner === tlsaOwner}
            <section class="box" aria-live="polite">
                <h3 class="subtitle">{t('tlsa.dnssec.title')}: {t(`tlsa.dnssec.${tlsaDnssec.dnssec}`)}</h3>
                <p>{t('tlsa.dnssec.checked-at', { date: tlsaDnssec.dnssec_validation.checked_at })}</p>
                <p>{t(`tlsa.dnssec.method.${tlsaDnssec.dnssec_validation.method}`)}</p>
                <p class="muted">{t('tlsa.dnssec.scope')}</p>
                {#if tlsaDnssec.dnssec_validation.reason}<p>{t(`tlsa.dnssec.reason.${tlsaDnssec.dnssec_validation.reason}`)}</p>{/if}
            </section>
        {/if}
        {#if smtpDane && tlsaPort === 25 && tlsaProtocol === 'tcp'}
            <section class="box" aria-live="polite">
                <h3 class="subtitle">{t('tlsa.smtp-dane.title')}: {t(`tlsa.dnssec.${smtpDane.status}`)}</h3>
                <p>{t('tlsa.dnssec.checked-at', { date: smtpDane.checked_at })}</p>
                <p class="muted">{t('tlsa.smtp-dane.scope')}</p>
                {#each smtpDane.mx as mx}
                    <article class="box"><strong>MX {mx.preference}: {mx.host || '.'}</strong><p>TTL {mx.ttl} · {t(`tlsa.dnssec.${mx.dnssec}`)} · {t(`tlsa.smtp-dane.${mx.tlsa_status ?? 'lookup_failed'}`)}</p>
                        {#each mx.tlsa_records ?? [] as record}<p><code class="rdata-value">{record}</code></p>{/each}
                    </article>
                {/each}
            </section>
        {/if}
        {#if certificateDiagnostic && certificateDiagnostic.owner === tlsaOwner}
            <section class="box" aria-live="polite">
                <h3 class="subtitle">{t('tlsa.diagnostic.title')}</h3>
                <p>{t('tlsa.publication.checked-at', { date: certificateDiagnostic.checked_at })}</p>
                <p>{t('tlsa.dnssec.title')}: {t(`tlsa.dnssec.${certificateDiagnostic.dnssec_validation.status}`)}</p>
                <p>{t('tlsa.diagnostic.live-service-not-tested')}</p>
                {#each certificateDiagnostic.publication.servers as server}
                    <article class="box"><strong>{server.nameserver}</strong><p>{t(`tlsa.publication.${server.status}`)}</p>
                        {#each server.certificate_matches ?? [] as match}
                            <p><code class="rdata-value">{match.record}</code> · {t(`tlsa.diagnostic.${match.association}`)} · {t(`tlsa.diagnostic.pkix.${match.pkix}`)}</p>
                        {/each}
                    </article>
                {/each}
            </section>
        {/if}
    </details>

    <section class="box">
        <h2 class="subtitle">{t(editing ? 'dns.edit-rrset' : 'rrset.save')}</h2>
        <p class="muted">{t('rrset.save-hint')}</p>
        {#if typeChoice === 'TLSA'}<p role="status">{t('rrset.tlsa-manual-warning')}</p>{/if}
        <form method="post" action={url('rrsetReplace', { account: page.accountId, zone: page.managedZoneId })}>
            <input type="hidden" name="csrf_token" value={page.csrfToken}>
            <input type="hidden" name="tlsa_version" value={manualTlsaVersion}>
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
                                <input type="hidden" name="tlsa_version" value={rrset.tlsaVersion ?? ''}>
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
