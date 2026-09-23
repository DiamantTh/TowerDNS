<script lang="ts">
    import App from '../../themes/default/src/app/App.svelte';
    import type { ThemeBootstrap, UserBootstrap } from '../../themes/default/src/lib/bootstrap';

    type Language = 'en-GB' | 'de-DE';
    type PageKind = 'dashboard' | 'providerAccounts' | 'accountMembers';
    type Props = { view?: PageKind; language?: Language };
    let { view = 'dashboard', language = 'en-GB' }: Props = $props();

    const catalog: Record<Language, Record<string, string>> = {
        'en-GB': {
            'navigation.skip-to-content': 'Skip to content', 'navigation.menu': 'Open navigation', 'navigation.zones': 'DNS zones', 'navigation.accounts': 'Accounts', 'navigation.users': 'Users', 'navigation.roles': 'Roles', 'navigation.settings': 'Settings', 'navigation.schema': 'Schema', 'navigation.providers': 'Providers',
            'theme.label': 'Theme', 'theme.dark': 'Dark', 'theme.light': 'Light', 'auth.logout': 'Log out', 'common.open': 'Open', 'common.delete': 'Delete', 'common.remove': 'Remove', 'common.save': 'Save', 'common.edit': 'Edit',
            'page.dashboard.title': 'Dashboard', 'dashboard.welcome': 'Welcome back, {name}', 'dashboard.zones.title': 'DNS zones', 'dashboard.zones.text': 'Manage account-scoped DNS zones.', 'dashboard.accounts.title': 'Accounts', 'dashboard.accounts.text': 'Review personal and organization access.', 'dashboard.security.title': 'Security', 'dashboard.security.text': 'Manage your sign-in settings.',
            'providers.account-connections': 'Provider accounts', 'providers.connection-create': 'Add provider account', 'providers.credentials-replace': 'Replace credentials', 'providers.credentials-replace-hint': 'Leave existing credentials unchanged until the replacement is submitted.', 'providers.confirm-deactivate': 'Deactivate {name}?', 'providers.connection-deactivate': 'Deactivate', 'providers.none-configured': 'No provider accounts configured.',
            'field.name': 'Name', 'field.type': 'Type', 'status.active': 'Active', 'status.inactive': 'Inactive',
            'accounts.members': 'Members', 'accounts.member-add': 'Invite member', 'accounts.invitation-email-placeholder': 'name@example.test', 'accounts.user-id-placeholder': 'User ID', 'accounts.invitations': 'Pending invitations', 'accounts.invitation-revoke': 'Revoke invitation', 'field.email': 'Email address', 'field.role': 'Role', 'account.new-owner': 'New owner', 'account.transfer-ownership': 'Transfer ownership', 'account.confirm-ownership-transfer': 'Transfer ownership?', 'account.confirm-member-remove': 'Remove member?', 'account.invitation.status.pending': 'Pending', 'profile.role.owner': 'Owner', 'profile.role.dns_manager': 'DNS manager', 'profile.role.viewer': 'Viewer',
        },
        'de-DE': {
            'navigation.skip-to-content': 'Zum Inhalt springen', 'navigation.menu': 'Navigation öffnen', 'navigation.zones': 'DNS-Zonen', 'navigation.accounts': 'Konten', 'navigation.users': 'Benutzer', 'navigation.roles': 'Rollen', 'navigation.settings': 'Einstellungen', 'navigation.schema': 'Schema', 'navigation.providers': 'Provider',
            'theme.label': 'Theme', 'theme.dark': 'Dunkel', 'theme.light': 'Hell', 'auth.logout': 'Abmelden', 'common.open': 'Öffnen', 'common.delete': 'Löschen', 'common.remove': 'Entfernen', 'common.save': 'Speichern', 'common.edit': 'Bearbeiten',
            'page.dashboard.title': 'Übersicht', 'dashboard.welcome': 'Willkommen zurück, {name}', 'dashboard.zones.title': 'DNS-Zonen', 'dashboard.zones.text': 'DNS-Zonen im gewählten Konto verwalten.', 'dashboard.accounts.title': 'Konten', 'dashboard.accounts.text': 'Persönliche und Organisationszugriffe prüfen.', 'dashboard.security.title': 'Sicherheit', 'dashboard.security.text': 'Anmeldeeinstellungen verwalten.',
            'providers.account-connections': 'Provider-Konten', 'providers.connection-create': 'Provider-Konto hinzufügen', 'providers.credentials-replace': 'Zugangsdaten ersetzen', 'providers.credentials-replace-hint': 'Bestehende Zugangsdaten bleiben bis zum Absenden unverändert.', 'providers.confirm-deactivate': '{name} deaktivieren?', 'providers.connection-deactivate': 'Deaktivieren', 'providers.none-configured': 'Keine Provider-Konten konfiguriert.',
            'field.name': 'Name', 'field.type': 'Typ', 'status.active': 'Aktiv', 'status.inactive': 'Inaktiv',
            'accounts.members': 'Mitglieder', 'accounts.member-add': 'Mitglied einladen', 'accounts.invitation-email-placeholder': 'name@beispiel.test', 'accounts.user-id-placeholder': 'Benutzer-ID', 'accounts.invitations': 'Offene Einladungen', 'accounts.invitation-revoke': 'Einladung zurückziehen', 'field.email': 'E-Mail-Adresse', 'field.role': 'Rolle', 'account.new-owner': 'Neuer Eigentümer', 'account.transfer-ownership': 'Ownership übertragen', 'account.confirm-ownership-transfer': 'Ownership übertragen?', 'account.confirm-member-remove': 'Mitglied entfernen?', 'account.invitation.status.pending': 'Offen', 'profile.role.owner': 'Owner', 'profile.role.dns_manager': 'DNS-Manager', 'profile.role.viewer': 'Leser',
        },
    };
    const themes: ThemeBootstrap[] = [{ name: 'cerberus', displayName: 'Cerberus', description: 'Synthetic fixture theme', skeletonTheme: 'cerberus' }];
    const user = $derived<UserBootstrap>({
        id: 'storybook-admin', email: 'admin@example.test', displayName: 'Taylor Example', theme: 'cerberus', language, locale: language, timezone: 'Europe/Berlin',
        roles: [{ id: 'administrator', name: 'Administrator', isBuiltIn: true, permissions: ['user.manage', 'role.manage', 'system.settings.manage', 'system.schema.manage', 'provider.config.manage'] }],
    });
    const base = $derived({ user, csrfToken: 'storybook-fixture-only-not-a-valid-token' });
    const bootstrapData = $derived(view === 'dashboard' ? base : view === 'providerAccounts' ? {
        ...base, account: { id: 107, name: 'Example Hosting' }, allowedTypes: ['desec'], providerDefinitions: { desec: { label: 'deSEC', user_managed: true, credentials: { token: { input: 'token', label: 'API token', required: true, secret: true } } } },
        providers: [{ id: 21, name: 'Primary DNS', providerType: 'desec', isActive: true }], error: null, success: null,
    } : {
        ...base, account: { id: 107, name: 'Example Hosting' }, roles: [{ value: 'owner' }, { value: 'dns_manager' }, { value: 'viewer' }],
        invitations: [{ id: 'invite-1', email: 'alice@example.test', role: 'dns_manager', status: 'pending', expiresAt: '2026-10-01 12:00:00' }],
        members: [{ userId: 'storybook-admin', displayName: 'Taylor Example', email: 'admin@example.test', role: 'owner' }, { userId: 'alice', displayName: 'Alice Example', email: 'alice@example.test', role: 'dns_manager' }], error: null, success: null,
    });
    const bootPage = $derived(view === 'providerAccounts' ? 'provider_accounts/list' : view === 'accountMembers' ? 'accounts/members' : 'dashboard');
    const boot = $derived({ page: bootPage, props: bootstrapData, themes, theme: themes[0], debug: false, i18n: { locale: language, messages: catalog[language] } });
</script>

<App {boot} />
