<script lang="ts">
    import Page from './Page.svelte';
    import Field from './Field.svelte';
    import Notice from './Notice.svelte';
    import { useI18n } from '../lib/i18n';

    type StepUpPageData = {
        csrfToken: string;
        completed: boolean;
        returnUrl: string | null;
        totpAvailable: boolean;
        passkeyAvailable: boolean;
        passwordAvailable: boolean;
        factorHint?: string | null;
        recoveryRequired?: boolean;
        error: string | null;
    };
    type SerializedCredentialDescriptor = { id: string; type: PublicKeyCredentialType; transports?: AuthenticatorTransport[] };
    type SerializedRequestOptions = Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'> & {
        challenge: string;
        allowCredentials?: SerializedCredentialDescriptor[];
    };

    let { data }: { data: StepUpPageData } = $props();
    const t = useI18n();
    let busy = $state(false);
    let error = $state('');

    const toBuffer = (value: string): ArrayBuffer => {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64 + '='.repeat((4 - base64.length % 4) % 4)), (character) => character.charCodeAt(0)).buffer;
    };

    const toBase64Url = (value: ArrayBuffer): string => btoa(String.fromCharCode(...new Uint8Array(value)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=/g, '');

    async function verifyPasskey(): Promise<void> {
        busy = true;
        error = '';
        try {
            const begin = await fetch('/security/step-up/webauthn/begin', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: data.csrfToken }),
            });
            const serialized = await begin.json() as SerializedRequestOptions & { error?: unknown };
            if (!begin.ok) throw new Error(typeof serialized.error === 'string' ? serialized.error : t('security.step-up.failed'));

            const options: PublicKeyCredentialRequestOptions = {
                ...serialized,
                challenge: toBuffer(serialized.challenge),
                allowCredentials: serialized.allowCredentials?.map((credential) => ({
                    ...credential,
                    id: toBuffer(credential.id),
                })),
            };
            const credential = await navigator.credentials.get({ publicKey: options }) as PublicKeyCredential | null;
            if (!credential) throw new Error(t('passkey.error.no-authenticator-response'));

            const response = credential.response as AuthenticatorAssertionResponse;
            const finish = await fetch('/security/step-up/webauthn/finish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: data.csrfToken,
                    id: toBase64Url(credential.rawId),
                    rawId: toBase64Url(credential.rawId),
                    type: credential.type,
                    response: {
                        clientDataJSON: toBase64Url(response.clientDataJSON),
                        authenticatorData: toBase64Url(response.authenticatorData),
                        signature: toBase64Url(response.signature),
                        userHandle: response.userHandle ? toBase64Url(response.userHandle) : null,
                    },
                }),
            });
            const result = await finish.json() as { error?: unknown; redirect?: unknown };
            if (!finish.ok) throw new Error(typeof result.error === 'string' ? result.error : t('security.step-up.failed'));
            window.location.href = typeof result.redirect === 'string' ? result.redirect : '/security/step-up?verified=1';
        } catch (cause) {
            error = cause instanceof Error ? cause.message : t('security.step-up.failed');
            busy = false;
        }
    }
</script>

<Page title={data.completed ? t('security.step-up.complete-title') : t('security.step-up.title')} subtitle={data.completed ? t('security.step-up.complete-text') : t('security.step-up.description')} error={error || data.error} success={data.completed ? t('security.step-up.verified') : null} narrow>
    {#if data.completed && data.returnUrl}
        <section class="box">
            <p>{t('security.step-up.resubmit-hint')}</p>
            <a class="button is-primary mt-4" href={data.returnUrl}>{t('security.step-up.return')}</a>
        </section>
    {:else if !data.totpAvailable && !data.passkeyAvailable && !data.passwordAvailable}
        <section class="box">
            <Notice kind="warning" text={t(data.recoveryRequired ? 'security.step-up.recovery-required' : 'security.step-up.no-factor')} />
            {#if !data.recoveryRequired}
                <p>{t('security.step-up.enrollment-hint')}</p>
                <div class="buttons mt-4">
                    <a class="button" href="/profile/totp">TOTP</a>
                    <a class="button" href="/profile/webauthn">{t('profile.passkeys.title')}</a>
                </div>
            {/if}
        </section>
    {:else}
        <section class="box">
            {#if data.factorHint}<Notice kind="warning" text={t(data.factorHint)} />{/if}
            {#if data.totpAvailable}
                <form method="post" action="/security/step-up/totp">
                    <input type="hidden" name="csrf_token" value={data.csrfToken}>
                    <Field label={t('field.authenticator-code')}>
                        <input class="input code-input" name="code" inputmode="numeric" pattern={'[0-9]{6,8}'} autocomplete="one-time-code" required>
                    </Field>
                    <button class="button is-primary">{t('security.step-up.confirm-totp')}</button>
                </form>
            {/if}
            {#if data.passkeyAvailable}
                <button class="button" class:loading={busy} disabled={busy} onclick={verifyPasskey}>{t('security.step-up.confirm-passkey')}</button>
            {/if}
            {#if data.passwordAvailable}
                <form method="post" action="/security/step-up/password" class="mt-4">
                    <input type="hidden" name="csrf_token" value={data.csrfToken}>
                    <Field label={t('field.password')}><input class="input" type="password" name="password" autocomplete="current-password" required></Field>
                    <button class="button">{t('security.step-up.confirm-password')}</button>
                </form>
            {/if}
        </section>
    {/if}
</Page>
