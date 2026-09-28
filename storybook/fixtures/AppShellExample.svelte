<script lang="ts">
    import AppShell from '../../themes/default/src/components/AppShell.svelte';
    import { provideI18n } from '../../themes/default/src/lib/i18n';
    import type { UserBootstrap } from '../../themes/default/src/lib/bootstrap';
    import { fixtureAccounts, fixtureCsrfToken, fixtureUsers } from './towerdns-installation';

    type Language = 'en-GB' | 'de-DE';
    type Props = { language?: Language; dark?: boolean; accountLabel?: string | null; permissionSet?: string[]; restricted?: boolean };
    let { language = 'en-GB', dark = false, accountLabel = fixtureAccounts.northwind.name, permissionSet = ['user.manage', 'role.manage', 'system.settings.manage', 'system.schema.manage', 'provider.config.manage'], restricted = false }: Props = $props();
    let menu = $state(false);
    let activeDark = $state(false);
    $effect(() => { activeDark = dark; });

    const messages: Record<Language, Record<string, string>> = {
        'en-GB': {
            'navigation.skip-to-content': 'Skip to content', 'navigation.menu': 'Open navigation', 'navigation.zones': 'DNS zones',
            'navigation.accounts': 'Accounts', 'navigation.users': 'Users', 'navigation.roles': 'Roles', 'navigation.settings': 'Settings',
            'navigation.schema': 'Schema', 'navigation.providers': 'Providers', 'theme.label': 'Theme', 'theme.dark': 'Dark', 'theme.light': 'Light', 'auth.logout': 'Log out',
        },
        'de-DE': {
            'navigation.skip-to-content': 'Zum Inhalt springen', 'navigation.menu': 'Navigation öffnen', 'navigation.zones': 'DNS-Zonen',
            'navigation.accounts': 'Konten', 'navigation.users': 'Benutzer', 'navigation.roles': 'Rollen', 'navigation.settings': 'Einstellungen',
            'navigation.schema': 'Schema', 'navigation.providers': 'Provider', 'theme.label': 'Theme', 'theme.dark': 'Dunkel', 'theme.light': 'Hell', 'auth.logout': 'Abmelden',
        },
    };
    const user = $derived<UserBootstrap>({ ...(restricted ? fixtureUsers.restricted : fixtureUsers.superadmin), language, locale: language });
    provideI18n(() => messages[language]);
</script>

<AppShell {user} csrfToken={fixtureCsrfToken} dark={activeDark} {menu} accountLabel={accountLabel} can={(permission) => permissionSet.includes(permission)} onToggleTheme={(value) => activeDark = value} onToggleMenu={(value) => menu = value}>
    <main id="main-content" class="page" style="max-width: 76rem; margin: 0 auto;">
        <header class="page-header"><h1 class="title">DNS workspace</h1><p class="subtitle muted">Synthetic Storybook content; no live account data is used.</p></header>
        <section class="box"><h2 class="subtitle">Account context</h2><p>This area represents the selected resource scope.</p></section>
    </main>
</AppShell>
