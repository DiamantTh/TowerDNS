<script lang="ts">
    import Page from '../../components/Page.svelte';
    import Field from '../../components/Field.svelte';
    import Notice from '../../components/Notice.svelte';
    import Strength from '../../components/Strength.svelte';
    import Setting from '../../components/Setting.svelte';
    import DataTable from '../../components/DataTable.svelte';
    import type { PageBoot } from '../types';
    import { pageUrl, type UserBootstrap } from '../../lib/bootstrap';
    import { useI18n } from '../../lib/i18n';
    type Data = Record<string, any>;
    let { boot }: { boot: PageBoot } = $props();
    const p = $derived(boot.props);
    const user = $derived((p.user ?? p.currentUser ?? null) as UserBootstrap | null);
    const csrf = $derived(p.csrfToken ?? '');
    const t = useI18n();
    const url = (name: string, parameters: Record<string, string | number> = {}) => pageUrl(boot.urls, name, parameters);
    const list = <T=Data>(v: T[]|null|undefined): T[] => v ?? [];
    const enc = (v: unknown) => encodeURIComponent(String(v ?? ''));
    const ask = (e: Event, text: string) => { if (!confirm(text)) e.preventDefault(); };
    let score = $state<number|null>(null);
    const strength = (e: Event) => { const value = (e.currentTarget as HTMLInputElement).value; void import('zxcvbn').then(({default: zxcvbn}) => score = zxcvbn(value).score); };
    let passkeyError = $state(''), busy = $state(false), keyName = $state('');
    const tp = (key: string, count: number, parameters: Record<string, string|number> = {}) => { const message = boot.i18n?.messages[key]; const text = Array.isArray(message) ? message[message.length === 3 ? (count === 0 ? 0 : count === 1 ? 1 : 2) : count === 1 ? 0 : 1] : message ?? key; return text.replace(/\{([a-zA-Z0-9_]+)\}/g, (_, name) => String(({ ...parameters, count } as Record<string, string|number>)[name] ?? `{${name}}`)); };
    const dateLabel = (value: string|null|undefined) => value ? new Intl.DateTimeFormat(user?.locale ?? boot.i18n?.locale ?? 'en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: user?.timezone ?? 'UTC' }).format(new Date(value.replace(' ', 'T'))) : t('profile.never');
    const toBuf=(s:string)=>{const b=s.replace(/-/g,'+').replace(/_/g,'/');return Uint8Array.from(atob(b+'='.repeat((4-b.length%4)%4)),c=>c.charCodeAt(0)).buffer};
    const toB64=(b:ArrayBuffer)=>btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
    async function registerPasskey(method: 'security_key'|'passkey' = 'passkey'){
        if(!keyName.trim()){passkeyError=t('passkey.error.name-required');return}
        busy=true;passkeyError='';
        try{
            const begin=await fetch('/profile/webauthn/register/begin',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`name=${enc(keyName)}&method=${enc(method)}&csrf_token=${enc(csrf)}`});
            const o=await begin.json();
            if(begin.status===428){location.href=o.redirect??'/security/step-up';return}
            if(!begin.ok)throw Error(o.error??t('passkey.error.start-failed'));
            o.challenge=toBuf(o.challenge);o.user={...o.user,id:toBuf(o.user.id)};o.excludeCredentials=list(o.excludeCredentials).map(c=>({...c,id:toBuf(c.id)}));
            const c=await navigator.credentials.create({publicKey:o}) as PublicKeyCredential|null;
            if(!c)throw Error(t('passkey.error.no-authenticator-response'));
            const r=c.response as AuthenticatorAttestationResponse;
            const done=await fetch('/profile/webauthn/register/finish',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:csrf,id:toB64(c.rawId),rawId:toB64(c.rawId),type:c.type,authenticatorAttachment:c.authenticatorAttachment,response:{clientDataJSON:toB64(r.clientDataJSON),attestationObject:toB64(r.attestationObject),transports:r.getTransports?.()??[]}})});
            const d=await done.json();
            if(done.status===428){location.href=d.redirect??'/security/step-up';return}
            if(!done.ok)throw Error(d.error??t('passkey.error.registration-failed'));
            location.reload();
        }catch(e){passkeyError=e instanceof Error?e.message:t('passkey.error.registration-failed');busy=false;}
    }

</script>

{#if boot.page==='profile/index' && user}
<Page title={t('page.profile.title')} error={p.error} success={p.success} narrow>
 <section class="box"><h2 class="subtitle">{t('profile.identity.title')}</h2><form method="post" action={url("profile")}><input type="hidden" name="csrf_token" value={csrf}><div class="form-grid"><Field label={t('field.display-name')}><input class="input" name="display_name" value={user.displayName??''} maxlength="64"></Field><Field label={t('profile.first-name')}><input class="input" name="first_name" value={user.firstName??''} maxlength="100"></Field><Field label={t('profile.last-name')}><input class="input" name="last_name" value={user.lastName??''} maxlength="100"></Field><Field label={t('profile.alternate-email')}><input class="input" type="email" name="alternate_email" value={user.alternateEmail??''}></Field><Field label={t('profile.phone')}><input class="input" name="phone" value={user.phone??''}></Field><Field label={t('profile.mobile')}><input class="input" name="mobile" value={user.mobile??''}></Field><Field label={t('profile.street')}><input class="input" name="street" value={user.street??''}></Field><Field label={t('profile.street2')}><input class="input" name="street2" value={user.street2??''}></Field><Field label={t('profile.postal-code')}><input class="input" name="postal_code" value={user.postalCode??''}></Field><Field label={t('profile.city')}><input class="input" name="city" value={user.city??''}></Field><Field label={t('profile.region')}><input class="input" name="region" value={user.region??''}></Field><Field label={t('profile.country')}><input class="input" name="country" value={user.country??''} maxlength="2"></Field></div><Field label={t('profile.language')}><select class="input" name="language">{#each list<string>(p.supportedLanguages) as language}<option value={language} selected={user.language===language}>{t(`profile.locale.${language}`)}</option>{/each}</select></Field><Field label={t('profile.locale')}><select class="input" name="locale">{#each list<string>(p.supportedLocales) as locale}<option value={locale} selected={user.locale===locale}>{t(`profile.locale.${locale}`)}</option>{/each}</select></Field><Field label={t('profile.timezone')}><select class="input" name="timezone">{#each list<string>(p.supportedTimezones) as timezone}<option value={timezone} selected={user.timezone===timezone}>{timezone}</option>{/each}</select></Field><Field label={t('field.theme')}><select class="input" name="theme"><option value="system" selected={user.theme==='system'}>{t('theme.system')}</option>{#each boot.themes as theme}<option value={theme.name} selected={user.theme===theme.name}>{theme.displayName}</option>{/each}</select></Field><button class="button is-primary">{t('common.save')}</button></form><dl><dt>{t('field.email')}</dt><dd>{user.email}</dd><dt>{t('profile.user-id')}</dt><dd><code>{user.id}</code></dd><dt>{t('profile.created-at')}</dt><dd>{dateLabel(user.createdAt)}</dd><dt>{t('profile.last-login')}</dt><dd>{dateLabel(user.lastLoginAt)}</dd></dl></section>
 <section class="box"><h2 class="subtitle">{t('profile.accounts.title')}</h2>{#if p.personalAccount}<p><a href={url("account", { id: p.personalAccount.id })}>{t('profile.account.personal')}</a> · {t('profile.role.owner')}{#if p.activeAccountId===p.personalAccount.id} · {t('profile.account.active')}{/if}</p>{/if}{#each list(p.accountMemberships) as account}<p><a href={url("account", { id: account.id })}>{account.name}</a> · {t('profile.account.organization')} · {t(`profile.role.${account.role}`)}{#if p.activeAccountId===account.id} · {t('profile.account.active')}{/if}</p>{:else}<p>{t('profile.account.none')}</p>{/each}</section>
 <section class="box"><h2 class="subtitle">{t('profile.security.title')}</h2><p class="muted">{t('profile.security.description')}</p><div class="settings-list"><Setting title={t('profile.passkeys.title')} text={tp('profile.security.passkeys',p.webAuthnKeyCount??0)} href="/profile/webauthn"/><Setting title="TOTP" text={tp('profile.security.totp',p.totpCount??0)} href="/profile/totp"/><Setting title={t('profile.password.title')} text={p.passwordConfigured?t('profile.security.password-configured'):t('profile.security.password-absent')} href="/profile/password"/><Setting title={t('profile.api-keys.title')} text={t('profile.api-keys.text')} href="/profile/api-keys"/></div></section>
</Page>
{:else if boot.page==='profile/password'}<Page title={t('profile.password.title')} error={p.error} success={p.success} narrow><p><a href={url('profile')}>← {t('profile.security.back')}</a></p><section class="box">{#if !p.passwordConfigured}<Notice kind="info" text={t('profile.password.passkey-only')}/>{/if}<form method="post"><input type="hidden" name="csrf_token" value={csrf}><input type="hidden" name="action" value="change"><Field label={t('field.new-password')}><input class="input" type="password" name="new_password" oninput={strength} required autocomplete="new-password"><Strength value={score}/></Field><Field label={t('field.repeat')}><input class="input" type="password" name="new_password_confirm" autocomplete="new-password" required></Field><button class="button is-primary">{p.passwordConfigured?t('profile.password.change'):t('profile.password.enable')}</button></form>{#if p.passwordConfigured && (p.webAuthnKeyCount??0)>0}<hr><form method="post" onsubmit={(e)=>ask(e,t('profile.password.confirm-disable'))}><input type="hidden" name="csrf_token" value={csrf}><input type="hidden" name="action" value="disable"><button class="button is-danger">{t('profile.password.disable')}</button></form>{:else if p.passwordConfigured}<Notice kind="info" text={t('profile.password.disable-requires-passkey')}/>{/if}</section></Page>
{:else if boot.page==='profile/totp'}<Page title={t('auth.two-factor')} error={p.error} success={p.success} narrow>
 <p><a href={url('profile')}>← {t('profile.security.back')}</a></p>
 <Notice kind="warning" text={t('profile.totp.notice')}/>
 {#if (p.totpCount??0)===2}<Notice kind="warning" text={t('profile.totp.remove-two-warning')}/>{/if}
 {#if (p.totpCount??0)===1 && (p.webAuthnKeyCount??0)>0}<Notice kind="info" text={t('profile.totp.remove-last-with-fido2')}/>{/if}
 {#if (p.totpCount??0)===1 && (p.webAuthnKeyCount??0)===0}<Notice kind="warning" text={t('profile.totp.remove-last-recovery')}/>{/if}
 <section class="box"><h2 class="subtitle">{t('profile.totp.credentials-title',{count:p.totpCount??0,limit:p.totpLimit??5})}</h2>
 <DataTable rows={list(p.totpCredentials)} cols={['label','created_at','last_used_at']} labels={[t('field.name'),t('table.registered'),t('table.last-used')]} {csrf} action={(r)=>(p.totpCount??0)===1&&(p.webAuthnKeyCount??0)===0?null:`/profile/totp/${enc(r.id)}/delete`} actionText={t('common.remove')} confirmMessage={(r)=>(p.totpCount??0)===2?t('profile.totp.remove-two-confirm',{label:String(r.label??'')}):null}/>
 {#if p.secret}
  <hr><h2 class="subtitle">{t('profile.totp.setup-title')}</h2><p>{t('profile.totp.pending-label',{label:p.pendingLabel??''})}</p>
  <p class="mt-4"><a class="button is-fullwidth" href={p.provisioningUri}>{t('profile.totp.open-authenticator')}</a></p>
  <Field label={t('profile.totp.secret')}><input class="input" readonly value={p.secretFormatted}></Field>
  <form method="post"><input type="hidden" name="csrf_token" value={csrf}><input type="hidden" name="action" value="enable"><Field label={t('profile.totp.current-code')}><input class="input code-input" name="code" inputmode="numeric" pattern={'[0-9]{8}'} autocomplete="one-time-code" required></Field><button class="button is-primary">{t('common.confirm')}</button></form>
 {:else if (p.totpCount??0) < (p.totpLimit??5)}
  <hr><form method="get" action="/profile/totp"><input type="hidden" name="setup" value="1"><Field label={t('profile.totp.label')}><input class="input" name="label" maxlength="100" value=""></Field><button class="button is-primary">{t('profile.totp.add')}</button></form>
 {:else}<Notice kind="info" text={t('totp.error.limit-reached')}/>{/if}
 </section>
</Page>
{:else if boot.page==='profile/webauthn'}<Page title={t('profile.passkeys.title')} error={p.error} success={p.success} narrow>
 <p><a href={url('profile')}>← {t('profile.security.back')}</a></p>
 {#if (p.credentialCount??0)===2}<Notice kind="warning" text={t('profile.passkeys.remove-two-warning')}/>{/if}
 {#if (p.credentialCount??0)===1}<Notice kind="info" text={t('profile.passkeys.remove-last-recovery')}/>{/if}
 <section class="box"><h2 class="subtitle">{t('profile.passkeys.hardware-title')}</h2><p class="muted">{t('profile.passkeys.hardware-description')}</p><p class="help">{t('profile.passkeys.limit',{count:p.credentialCount??0,limit:p.credentialLimit??10})}</p><div class="inline-form mt-4"><input class="input" bind:value={keyName} maxlength="64" placeholder={t('profile.passkeys.name')} disabled={(p.credentialCount??0)>=(p.credentialLimit??10)}><button class:loading={busy} class="button is-primary" disabled={busy||(p.credentialCount??0)>=(p.credentialLimit??10)} onclick={()=>registerPasskey('security_key')}>{t('profile.passkeys.register-security-key')}</button></div>
  <details class="mt-4"><summary>{t('profile.passkeys.other-options')}</summary><p class="muted mt-3">{t('profile.passkeys.synced-description')}</p><button class="button mt-3" disabled={busy||(p.credentialCount??0)>=(p.credentialLimit??10)} onclick={()=>registerPasskey('passkey')}>{t('profile.passkeys.register-passkey')}</button></details>
  {#if passkeyError}<Notice kind="danger" text={passkeyError}/>{/if}
 </section>
 <section class="box"><h2 class="subtitle">{t('profile.passkeys.credentials-title')}</h2><DataTable rows={list(p.keys)} cols={['name','attachment','created_at','last_used_at']} labels={[t('field.name'),t('profile.passkeys.attachment'),t('table.registered'),t('table.last-used')]} detailCols={['aaguid','transports','backup_eligible','backup_state']} detailLabels={[t('profile.passkeys.aaguid'),t('profile.passkeys.transports'),t('profile.passkeys.backup-eligible'),t('profile.passkeys.backup-state')]} {csrf} action={(r)=>(p.credentialCount??0)<=1?null:`/profile/webauthn/${r.credential_id_url}/delete`} actionText={t('common.remove')} confirmMessage={(r)=>(p.credentialCount??0)===2?t('profile.passkeys.remove-two-confirm',{name:String(r.name??'')}):null}/></section>
</Page>
{:else if boot.page==='profile/api_keys'}<Page title={t('profile.api-keys.title')} error={p.error} success={p.success} narrow><p><a href={url('profile')}>← {t('profile.security.back')}</a></p><Notice kind="info" text={t('profile.api-keys.unavailable')}/><DataTable rows={list(p.keys)} cols={['name','created_at','last_used','statusLabel']} labels={[t('field.name'),t('table.created'),t('table.last-used'),t('table.status')]} {csrf} action={(r)=>r.is_active===true?`/profile/api-keys/${r.id}/revoke`:null} actionText={t('profile.api-keys.revoke')}/></Page>
{/if}
