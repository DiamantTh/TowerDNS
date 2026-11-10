export async function loadPage(page: string) {
    if (page === 'audit/index') return (await import('./pages/AuditPage.svelte')).default;
    if (page === 'account/recovery' || page === 'security/step_up') return (await import('./pages/SecurityPages.svelte')).default;
    if (page === 'dashboard') return (await import('./pages/DashboardPages.svelte')).default;
    if (['zones/list', 'zones/records', 'zones/record_edit', 'zones/dnssec'].includes(page)) return (await import('./pages/DnsPages.svelte')).default;
    if (['profile/index', 'profile/password', 'profile/totp', 'profile/webauthn', 'profile/api_keys'].includes(page)) return (await import('./pages/ProfilePages.svelte')).default;
    if (['invitations/accept', 'invitations/register'].includes(page)) return (await import('./pages/InvitationPages.svelte')).default;
    if (page === 'provider_accounts/list' || page === 'credentials') return (await import('./pages/ProviderPages.svelte')).default;
    if (page === 'settings') return (await import('./pages/SettingsPages.svelte')).default;
    return (await import('./pages/ManagementPages.svelte')).default;
}
