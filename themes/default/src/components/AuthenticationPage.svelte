<script lang="ts">
    import Field from './Field.svelte';
    import Notice from './Notice.svelte';
    import Strength from './Strength.svelte';
    import { useI18n } from '../lib/i18n';
    import { pageUrl, type PageUrlTemplates, type AuthenticationPageData, type AuthenticationPageName } from '../lib/bootstrap';

    type SerializedCredentialDescriptor = { id: string; type: PublicKeyCredentialType; transports?: AuthenticatorTransport[] };
    type SerializedRequestOptions = Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'> & {
        challenge: string;
        allowCredentials?: SerializedCredentialDescriptor[];
    };
    type FinishResponse = { error?: unknown; redirect?: unknown };

    let { page, data, urls }: { page: AuthenticationPageName; data: AuthenticationPageData; urls?: PageUrlTemplates } = $props();
    const url = (name: string) => pageUrl(urls, name);
    let score = $state<number | null>(null);
    let busy = $state(false);
    let passkeyError = $state('');
    const t = useI18n();

    const strength = (event: Event): void => {
        const password = (event.currentTarget as HTMLInputElement).value;
        void import('zxcvbn').then(({ default: zxcvbn }) => score = zxcvbn(password).score);
    };

    const toBuffer = (value: string): ArrayBuffer => {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64 + '='.repeat((4 - base64.length % 4) % 4)), (character) => character.charCodeAt(0)).buffer;
    };

    const toBase64Url = (value: ArrayBuffer): string => btoa(String.fromCharCode(...new Uint8Array(value)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=/g, '');

    async function loginWithPasskey(): Promise<void> {
        busy = true;
        passkeyError = '';

        try {
            if (!data.optionsJson) throw new Error(t('auth.error.login-failed'));

            const serialized = JSON.parse(data.optionsJson) as SerializedRequestOptions;
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
            const result = await fetch('/login/webauthn/finish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
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
            const body = await result.json() as FinishResponse;
            if (!result.ok) {
                throw new Error(typeof body.error === 'string' ? body.error : t('auth.error.login-failed'));
            }

            window.location.href = typeof body.redirect === 'string' ? body.redirect : url('dashboard');
        } catch (cause) {
            passkeyError = cause instanceof Error ? cause.message : t('auth.error.login-failed');
            busy = false;
        }
    }
</script>

{#if page === 'login_webauthn'}
    <section class="auth-card box">
        <a class="wordmark" href={url('dashboard')}>TowerDNS</a>
        <h1 class="title is-4">{t('auth.login-with-passkey')}</h1>
        {#if data.error || passkeyError}<Notice kind="danger" text={data.error || passkeyError} />{/if}
        <button class:loading={busy} class="button is-primary is-fullwidth" onclick={loginWithPasskey}>{t('auth.use-passkey')}</button>
        <div class="auth-links"><a href={url('login')}>{t('auth.other-login-method')}</a></div>
    </section>
{:else}
    <section class="auth-card box">
        <a class="wordmark" href={url('dashboard')}>TowerDNS</a>
        <h1 class="title is-4">
            {page === 'login' ? t('auth.welcome-back') : page === 'forgot_password' ? t('auth.forgot-password') : page === 'reset_password' ? t('auth.new-password') : t('auth.two-factor')}
        </h1>
        {#if data.error}<Notice kind="danger" text={data.error} />{/if}
        {#if data.sent}
            <Notice kind="success" text={t('auth.reset-email-sent')} />
        {:else}
            <form method="post" action={page === 'login' ? url('login') : page === 'forgot_password' ? '/password/forgot' : page === 'reset_password' ? '/password/reset' : '/login/totp'}>
                <input type="hidden" name="csrf_token" value={data.csrfToken ?? ''}>
                {#if data.token}<input type="hidden" name="token" value={data.token}>{/if}
                {#if page === 'login' || page === 'forgot_password'}
                    <Field label={t('field.email')}><input class="input" name="email" type="email" autocomplete="email" required></Field>
                {/if}
                {#if page === 'login'}
                    <Field label={t('field.password')}><input class="input" name="password" type="password" autocomplete="current-password" required></Field>
                {/if}
                {#if page === 'reset_password'}
                    <Field label={t('field.new-password')}><input class="input" name="password" type="password" oninput={strength} required><Strength value={score} /></Field>
                    <Field label={t('field.repeat')}><input class="input" name="password_confirm" type="password" required></Field>
                {/if}
                {#if page === 'mfa_totp'}
                    <Field label={t('field.authenticator-code')}><input class="input code-input" name="code" inputmode="numeric" pattern={'[0-9]{6,8}'} required></Field>
                {/if}
                <button class="button is-primary is-fullwidth">
                    {page === 'login' ? t('auth.login') : page === 'forgot_password' ? t('auth.send-reset-link') : page === 'reset_password' ? t('common.save') : t('common.confirm')}
                </button>
            </form>
        {/if}
        <div class="auth-links">
            <a href={page === 'login' ? '/password/forgot' : url('login')}>
                {page === 'login' ? t('auth.forgot-password-question') : t('auth.back-to-login')}
            </a>
        </div>
    </section>
{/if}
