<script lang="ts">
    import Field from './Field.svelte';
    import Notice from './Notice.svelte';
    import Page from './Page.svelte';
    import { useI18n } from '../lib/i18n';

    type RecoveryPageData = {
        csrfToken?: string;
        ticket?: string;
        recoverySession?: boolean;
        expired?: boolean;
        restricted?: boolean;
        error?: string | null;
    };

    let { data }: { data: RecoveryPageData } = $props();
    const t = useI18n();
    let label = $state('FIDO2 security key');
    let busy = $state(false);
    let registered = $state(false);
    let error = $state('');

    const toBuffer = (value: string): ArrayBuffer => {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64 + '='.repeat((4 - base64.length % 4) % 4)), (c) => c.charCodeAt(0)).buffer;
    };
    const toBase64Url = (buffer: ArrayBuffer): string => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');

    async function enroll(method: 'security_key' | 'passkey'): Promise<void> {
        if (!label.trim()) {
            error = t('passkey.error.name-required');
            return;
        }
        busy = true;
        error = '';
        try {
            const begin = await fetch('/account/recovery/webauthn/register/begin', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ name: label.trim(), method, csrf_token: data.csrfToken ?? '' }),
            });
            const payload = await begin.json() as Record<string, any>;
            if (!begin.ok) throw new Error(typeof payload.error === 'string' ? payload.error : t('recovery.enrollment-failed'));
            const options = payload as unknown as PublicKeyCredentialCreationOptions;
            const serialized = options as unknown as Record<string, any>;
            serialized.challenge = toBuffer(serialized.challenge);
            serialized.user = { ...serialized.user, id: toBuffer(serialized.user.id) };
            serialized.excludeCredentials = (serialized.excludeCredentials ?? []).map((credential: Record<string, any>) => ({ ...credential, id: toBuffer(credential.id) }));
            const credential = await navigator.credentials.create({ publicKey: options }) as PublicKeyCredential | null;
            if (!credential) throw new Error(t('passkey.error.no-authenticator-response'));
            const response = credential.response as AuthenticatorAttestationResponse;
            const finish = await fetch('/account/recovery/webauthn/register/finish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: data.csrfToken,
                    id: toBase64Url(credential.rawId),
                    rawId: toBase64Url(credential.rawId),
                    type: credential.type,
                    authenticatorAttachment: credential.authenticatorAttachment,
                    response: {
                        clientDataJSON: toBase64Url(response.clientDataJSON),
                        attestationObject: toBase64Url(response.attestationObject),
                        transports: response.getTransports?.() ?? [],
                    },
                }),
            });
            const result = await finish.json() as { error?: string };
            if (!finish.ok) throw new Error(result.error ?? t('recovery.enrollment-failed'));
            registered = true;
        } catch (cause) {
            error = cause instanceof Error ? cause.message : t('recovery.enrollment-failed');
        } finally {
            busy = false;
        }
    }

    async function finishRecovery(): Promise<void> {
        busy = true;
        error = '';
        try {
            const response = await fetch('/account/recovery/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: data.csrfToken ?? '' }),
            });
            const result = await response.json() as { error?: string; redirect?: string };
            if (!response.ok) throw new Error(result.error ?? t('recovery.completion-failed'));
            location.href = result.redirect ?? '/login?recovery=complete';
        } catch (cause) {
            error = cause instanceof Error ? cause.message : t('recovery.completion-failed');
            busy = false;
        }
    }

    async function abortRecovery(): Promise<void> {
        if (!confirm(t('recovery.abort-confirm'))) return;
        busy = true;
        try {
            const response = await fetch('/account/recovery/abort', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: data.csrfToken ?? '' }),
            });
            const result = await response.json() as { redirect?: string };
            if (response.ok) location.href = result.redirect ?? '/login';
            else throw new Error(t('recovery.session-expired'));
        } catch (cause) {
            error = cause instanceof Error ? cause.message : t('recovery.session-expired');
            busy = false;
        }
    }
</script>

<Page title={t('recovery.title')} error={data.error ?? error} narrow>
    {#if data.expired}<Notice kind="warning" text={t('recovery.ticket-expired')} />{/if}
    {#if data.restricted}<Notice kind="warning" text={t('recovery.restricted-session')} />{/if}
    {#if data.recoverySession}
        <section class="box">
            <h2 class="subtitle">{t('recovery.register-title')}</h2>
            <p>{t('recovery.register-help')}</p>
            <p class="muted">{t('recovery.old-factors-warning')}</p>
            <Field label={t('field.name')}><input class="input" bind:value={label} maxlength="64" required></Field>
            <div class="buttons mt-4">
                <button class="button is-primary" class:loading={busy} disabled={busy} onclick={() => enroll('security_key')}>{t('recovery.hardware-key')}</button>
                <button class="button" class:loading={busy} disabled={busy} onclick={() => enroll('passkey')}>{t('recovery.other-passkey')}</button>
            </div>
            {#if registered}
                <Notice kind="success" text={t('recovery.credential-verified')} />
                <button class="button is-primary mt-3" disabled={busy} onclick={finishRecovery}>{t('recovery.complete')}</button>
            {/if}
            <button class="button is-ghost mt-3" disabled={busy} onclick={abortRecovery}>{t('recovery.abort')}</button>
        </section>
    {:else if data.ticket}
        <section class="box">
            <p>{t('recovery.ticket-help')}</p>
            <p class="muted">{t('recovery.password-not-required')}</p>
            <form method="post" action="/account/recovery">
                <input type="hidden" name="csrf_token" value={data.csrfToken ?? ''}>
                <input type="hidden" name="ticket" value={data.ticket}>
                <button class="button is-primary">{t('recovery.start')}</button>
            </form>
        </section>
    {:else}
        <section class="box"><p>{t('recovery.ticket-invalid')}</p><a class="button" href="/login">{t('auth.back-to-login')}</a></section>
    {/if}
</Page>
