<script lang="ts">
    import Field from './Field.svelte';
    import Notice from './Notice.svelte';
    import Page from './Page.svelte';
    import type { ThemeBootstrap } from '../lib/bootstrap';
    import { useI18n } from '../lib/i18n';

    type SettingsFields = {
        app_name?: string;
        app_hostname?: string;
        app_force_https?: boolean;
        app_debug?: boolean;
        theme_name?: string;
        pwd_min_length?: number;
        pwd_min_score?: number;
        hibp_enabled?: boolean;
        hibp_fail_open?: boolean;
        hibp_timeout?: number;
        mailer_configured?: boolean;
        mailer_editable?: boolean;
        mailer_enabled?: boolean;
        smtp_host?: string;
        smtp_port?: number;
        smtp_encryption?: string;
        smtp_username?: string;
        mailer_from_address?: string;
    };

    type Props = {
        fields?: SettingsFields;
        themes: ThemeBootstrap[];
        csrfToken: string;
        error?: string | null;
        success?: string | null;
    };

    let { fields = {}, themes, csrfToken, error = null, success = null }: Props = $props();
    const t = useI18n();
</script>

<Page title={t('page.settings.title')} {error} {success} narrow>
    <form method="post" class="settings-form">
        <input type="hidden" name="csrf_token" value={csrfToken}>

        <section class="box settings-section" aria-labelledby="settings-application-title">
            <header class="settings-section__header">
                <h2 class="subtitle" id="settings-application-title">{t('settings.application.title')}</h2>
                <p class="muted">{t('settings.application.description')}</p>
            </header>
            <div class="form-grid">
                <Field label={t('settings.application-name')}><input class="input" name="app_name" value={fields.app_name ?? ''}></Field>
                <Field label={t('settings.hostname')}><input class="input" name="app_hostname" value={fields.app_hostname ?? ''}></Field>
                <Field label={t('field.theme')}><select class="input" name="theme_name">{#each themes as theme}<option value={theme.name} selected={theme.name === fields.theme_name}>{theme.displayName}</option>{/each}</select></Field>
            </div>
            <div class="settings-options">
                <label class="checkbox"><input type="checkbox" name="app_force_https" value="1" checked={fields.app_force_https}> {t('settings.force-https')}</label>
                <p class="help">{t('settings.force-https.help')}</p>
            </div>
        </section>

        <section class="box settings-section" aria-labelledby="settings-security-title">
            <header class="settings-section__header">
                <h2 class="subtitle" id="settings-security-title">{t('settings.security.title')}</h2>
                <p class="muted">{t('settings.security.description')}</p>
            </header>
            <div class="form-grid">
                <Field label={t('settings.password-min-length')}><input class="input" type="number" min="8" max="128" name="pwd_min_length" value={fields.pwd_min_length ?? 16}></Field>
                <Field label={t('settings.password-min-score')}><input class="input" type="number" min="0" max="4" name="pwd_min_score" value={fields.pwd_min_score ?? 2}></Field>
                <Field label={t('settings.hibp.timeout')}><input class="input" type="number" min="1" max="10" step="0.5" name="hibp_timeout" value={fields.hibp_timeout ?? 3}></Field>
            </div>
            <div class="settings-options">
                <label class="checkbox"><input type="checkbox" name="hibp_enabled" value="1" checked={fields.hibp_enabled}> {t('settings.hibp.enabled')}</label>
                <label class="checkbox"><input type="checkbox" name="hibp_fail_open" value="1" checked={fields.hibp_fail_open}> {t('settings.hibp.fail-open')}</label>
                <p class="help">{t('settings.hibp.help')}</p>
            </div>
        </section>

        <section class="box settings-section" aria-labelledby="settings-mail-title">
            <header class="settings-section__header">
                <h2 class="subtitle" id="settings-mail-title">{t('settings.mail.title')}</h2>
                <p class="muted">{t('settings.mail.description')}</p>
            </header>
            {#if fields.mailer_editable !== false}
                <div class="settings-options">
                    <label class="checkbox"><input type="checkbox" name="mailer_enabled" value="1" checked={fields.mailer_enabled}> {t('settings.mailer.enabled')}</label>
                </div>
                <div class="form-grid">
                    <Field label={t('settings.smtp-host')}><input class="input" name="smtp_host" value={fields.smtp_host ?? ''} autocomplete="off"></Field>
                    <Field label={t('settings.smtp-port')}><input class="input" type="number" min="1" max="65535" name="smtp_port" value={fields.smtp_port ?? 587}></Field>
                    <Field label={t('settings.smtp-encryption')}><select class="input" name="smtp_encryption"><option value="starttls" selected={fields.smtp_encryption === 'starttls'}>STARTTLS</option><option value="tls" selected={fields.smtp_encryption === 'tls'}>TLS</option><option value="none" selected={fields.smtp_encryption === 'none'}>{t('settings.smtp-encryption.none')}</option></select></Field>
                    <Field label={t('settings.smtp-username')}><input class="input" name="smtp_username" value={fields.smtp_username ?? ''} autocomplete="username"></Field>
                    <Field label={t('settings.smtp-password')}><input class="input" type="password" name="smtp_password" value="" autocomplete="new-password"><p class="help">{fields.mailer_configured ? t('settings.smtp-password.configured') : t('settings.smtp-password.help')}</p></Field>
                    <Field label={t('settings.sender')}><input class="input" type="email" name="mailer_from_address" value={fields.mailer_from_address ?? ''}></Field>
                </div>
            {:else}
                <Notice kind="info" text={t('settings.mail.legacy-transport')} />
            {/if}
        </section>

        <section class="box settings-section settings-section--advanced" aria-labelledby="settings-operations-title">
            <header class="settings-section__header">
                <h2 class="subtitle" id="settings-operations-title">{t('settings.operations.title')}</h2>
                <p class="muted">{t('settings.operations.description')}</p>
            </header>
            <div class="settings-options">
                <Notice kind="warning" text={t('settings.debug.warning')} />
                <label class="checkbox"><input type="checkbox" name="app_debug" value="1" checked={fields.app_debug}> {t('settings.debug')}</label>
            </div>
        </section>

        <button class="button is-primary">{t('common.save')}</button>
    </form>
</Page>
