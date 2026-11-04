<script lang="ts">
    import Page from '../../components/Page.svelte';
    import Field from '../../components/Field.svelte';
    import type { PageBoot } from '../types';
    import { useI18n } from '../../lib/i18n';
    let { boot }: { boot: PageBoot } = $props();
    const p = $derived(boot.props);
    const csrf = $derived(p.csrfToken ?? '');
    const t = useI18n();
</script>

{#if boot.page==='invitations/accept'}<Page title={t('accounts.invitation-title')} error={p.error} narrow><section class="box"><p>{t('accounts.invitation-for',{email:p.invitation?.email??''})}</p><p>{t(`profile.role.${p.invitation?.role??'viewer'}`)}</p><p>{t('accounts.invitation-expires',{date:p.invitation?.expiresAt??''})}</p><form class="buttons" method="post"><input type="hidden" name="csrf_token" value={csrf}><button class="button is-primary" name="action" value="accept">{t('accounts.invitation-accept')}</button><button class="button" name="action" value="decline">{t('accounts.invitation-decline')}</button></form></section></Page>
{:else if boot.page==='invitations/register'}<Page title={t('accounts.invitation-register-title')} error={p.error} narrow><section class="box"><p>{t('accounts.invitation-register-intro',{email:p.invitation?.email??''})}</p><p>{t('accounts.invitation-expires',{date:p.invitation?.expiresAt??''})}</p><form class="form-grid" method="post"><input type="hidden" name="csrf_token" value={csrf}><input type="hidden" name="action" value="register"><Field label={t('field.email')}><input class="input" type="email" name="email" value={p.email??p.invitation?.email??''} readonly required></Field><Field label={t('field.password')}><input class="input" type="password" name="password" minlength="16" required autocomplete="new-password"></Field><Field label={t('accounts.invitation-password-confirm')}><input class="input" type="password" name="password_confirm" minlength="16" required autocomplete="new-password"></Field><button class="button is-primary">{t('accounts.invitation-register')}</button></form></section></Page>
{/if}
