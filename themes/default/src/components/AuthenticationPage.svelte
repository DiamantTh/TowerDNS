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
    let email = $state('');
    let usePassword = $state(false);
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
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');

    async function finishAssertion(credential: PublicKeyCredential): Promise<void> {
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
        if (!result.ok) throw new Error(typeof body.error === 'string' ? body.error : t('auth.error.login-failed'));
        window.location.href = typeof body.redirect === 'string' ? body.redirect : url('dashboard');
    }

    async function loginWithPasskey(hardwareHint = false): Promise<void> {
        busy = true;
        passkeyError = '';
        try {
            let serialized: SerializedRequestOptions;
            if (page === 'login_webauthn') {
                if (!data.optionsJson) throw new Error(t('auth.error.login-failed'));
                serialized = JSON.parse(data.optionsJson) as SerializedRequestOptions;
            } else {
                if (!email.trim()) throw new Error(t('auth.error.email-required'));
                const begin = await fetch('/login/webauthn/begin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ email: email.trim(), csrf_token: data.csrfToken ?? '' }),
                });
                const body = await begin.json() as SerializedRequestOptions & { error?: unknown };
                if (!begin.ok) throw new Error(typeof body.error === 'string' ? body.error : t('auth.error.login-failed'));
                serialized = body;
            }

            const options: PublicKeyCredentialRequestOptions = {
                ...serialized,
                challenge: toBuffer(serialized.challenge),
                allowCredentials: serialized.allowCredentials?.map((credential) => ({
                    ...credential,
                    id: toBuffer(credential.id),
                })),
                ...(hardwareHint ? { hints: ['security-key'] } : {}),
            };
            const credential = await navigator.credentials.get({ publicKey: options }) as PublicKeyCredential | null;
            if (!credential) throw new Error(t('passkey.error.no-authenticator-response'));
            await finishAssertion(credential);
        } catch (cause) {
            passkeyError = cause instanceof Error ? cause.message : t('auth.error.login-failed');
            busy = false;
        }
    }

    function submitPassword(event: SubmitEvent): void {
        if (page === 'login' && !usePassword) {
            event.preventDefault();
            void loginWithPasskey(false);
        }
    }
</script>

{#if page === 'login_webauthn'}
    <section class="auth-card box">
        <a class="wordmark" href={url('dashboard')}>TowerDNS</a>
        <h1 class="title is-4">{t('auth.login-with-passkey')}</h1>
        {#if data.error || passkeyError}<Notice kind="danger" text={data.error || passkeyError} />{/if}
        <button type="button" class:loading={busy} class="button is-primary is-fullwidth" onclick={() => loginWithPasskey(true)}>{t('auth.use-security-key-recommended')}</button>
        <details class="mt-4"><summary>{t('auth.more-authentication-options')}</summary>
            <button type="button" class="button is-fullwidth mt-3" onclick={() => loginWithPasskey(false)}>{t('auth.use-passkey')}</button>
            {#if (data as AuthenticationPageData & { totpAvailable?: boolean }).totpAvailable}
                <a class="button is-fullwidth mt-3" href="/login/totp">{t('auth.use-totp')}</a>
            {/if}
        </details>
        <div class="auth-links"><a href={url('login')}>{t('auth.other-login-method')}</a></div>
    </section>
{:else}
    <section class="auth-card box">
        <a class="wordmark" href={url('dashboard')}>TowerDNS</a>
        <h1 class="title is-4">
            {page === 'login' ? t('auth.welcome-back') : page === 'forgot_password' ? t('auth.forgot-password') : page === 'reset_password' ? t('auth.new-password') : t('auth.two-factor')}
        </h1>
        {#if data.error || passkeyError}<Notice kind="danger" text={data.error || passkeyError} />{/if}
        {#if data.sent}
            <Notice kind="success" text={t('auth.reset-email-sent')} />
        {:else if page === 'login'}
            {#if !usePassword}
                <Field label={t('field.email')}><input class="input" name="email" type="email" autocomplete="username" bind:value={email} required></Field>
                <button type="button" class:loading={busy} class="button is-primary is-fullwidth mt-4" onclick={() => loginWithPasskey(true)}>{t('auth.use-security-key-recommended')}</button>
                <details class="mt-4"><summary>{t('auth.more-authentication-options')}</summary>
                    <button type="button" class="button is-fullwidth mt-3" onclick={() => loginWithPasskey(false)}>{t('auth.use-passkey')}</button>
                    <button type="button" class="button is-fullwidth mt-3" onclick={() => usePassword = true}>{t('auth.use-password-alternative')}</button>
                </details>
                <div class="auth-links"><a href="/password/forgot">{t('auth.forgot-password-question')}</a></div>
            {:else}
                <form id="password-login-form" method="post" action={url('login')} onsubmit={submitPassword}>
                    <input type="hidden" name="csrf_token" value={data.csrfToken ?? ''}>
                    <Field label={t('field.email')}><input class="input" name="email" type="email" autocomplete="username" bind:value={email} required></Field>
                    <Field label={t('field.password')}><input class="input" name="password" type="password" autocomplete="current-password" required></Field>
                    <button class="button is-primary is-fullwidth mt-4">{t('auth.login')}</button>
                    <button type="button" class="button is-ghost is-fullwidth mt-2" onclick={() => usePassword = false}>{t('auth.back-to-passkey')}</button>
                </form>
                <div class="auth-links"><a href="/password/forgot">{t('auth.forgot-password-question')}</a></div>
            {/if}
        {:else}
            <form method="post" action={page === 'forgot_password' ? '/password/forgot' : page === 'reset_password' ? '/password/reset' : '/login/totp'}>
                <input type="hidden" name="csrf_token" value={data.csrfToken ?? ''}>
                {#if data.token}<input type="hidden" name="token" value={data.token}>{/if}
                {#if page === 'forgot_password'}
                    <Field label={t('field.email')}><input class="input" name="email" type="email" autocomplete="email" required></Field>
                {/if}
                {#if page === 'reset_password'}
                    <Field label={t('field.new-password')}><input class="input" name="password" type="password" oninput={strength} required><Strength value={score} /></Field>
                    <Field label={t('field.repeat')}><input class="input" name="password_confirm" type="password" required></Field>
                {/if}
                {#if page === 'mfa_totp'}
                    <Field label={t('field.authenticator-code')}><input class="input code-input" name="code" inputmode="numeric" pattern={'[0-9]{6,8}'} required></Field>
                {/if}
                <button class="button is-primary is-fullwidth">
                    {page === 'forgot_password' ? t('auth.send-reset-link') : page === 'reset_password' ? t('common.save') : t('common.confirm')}
                </button>
            </form>
            <div class="auth-links"><a href={url('login')}>{t('auth.back-to-login')}</a></div>
        {/if}
    </section>
{/if}
