import type {
    DnsProviderAccountBootstrap,
    DnsRrsetBootstrap,
    DnsZoneBootstrap,
    RoleBootstrap,
    UserBootstrap,
} from '../../themes/default/src/lib/bootstrap';

/**
 * One coherent, deliberately fake TowerDNS installation for Storybook.
 *
 * Every identifier, host, address, token and credential in this module is a
 * fixture. Nothing here is loaded by the PHP application or sent to a DNS
 * provider. Keep the data relationship-oriented so page stories can exercise
 * the same people, accounts, provider connections and zones.
 */
export const fixtureCsrfToken = 'storybook-fixture-only-not-a-valid-token';

const recordType = (presentation: string, code: number): DnsRrsetBootstrap['type'] => ({
    presentation,
    code,
    isKnown: true,
});

export const fixtureRoles = {
    superadmin: {
        id: 'fixture-superadmin', name: 'System administrator', isBuiltIn: true,
        permissions: ['user.manage', 'role.manage', 'system.settings.manage', 'system.schema.manage', 'provider.config.manage', 'system.impersonation.execute'],
    },
    dnsManager: {
        id: 'fixture-dns-manager', name: 'DNS manager', isBuiltIn: true,
        permissions: ['zone.read', 'zone.write', 'rrset.write', 'dnssec.manage'],
    },
    readOnly: {
        id: 'fixture-read-only', name: 'Zone observer', isBuiltIn: false,
        permissions: ['zone.read'],
    },
    billingViewer: {
        id: 'fixture-billing-viewer', name: 'Account observer', isBuiltIn: false,
        permissions: ['account.read'],
    },
} satisfies Record<string, RoleBootstrap>;

export const fixtureUsers = {
    superadmin: {
        id: 'usr-fixture-superadmin', email: 'taylor.admin@example.test', displayName: 'Taylor Example', theme: 'cerberus', language: 'en-GB', locale: 'en-GB', timezone: 'Europe/Berlin', active: true,
        firstName: 'Taylor', lastName: 'Example', createdAt: '2025-02-04 10:00:00', lastLoginAt: '2026-09-28 08:45:00', roles: [fixtureRoles.superadmin],
    },
    dnsManager: {
        id: 'usr-fixture-dns-manager', email: 'avery.dns@example.test', displayName: 'Avery DNS', theme: 'modern', language: 'en-GB', locale: 'en-GB', timezone: 'Europe/London', active: true,
        firstName: 'Avery', lastName: 'DNS', createdAt: '2025-04-14 09:10:00', lastLoginAt: '2026-09-27 18:33:00', roles: [fixtureRoles.dnsManager],
    },
    restricted: {
        id: 'usr-fixture-restricted', email: 'sam.read-only@example.test', displayName: 'Sam Observer', theme: 'default', language: 'de-DE', locale: 'de-DE', timezone: 'Europe/Berlin', active: true,
        firstName: 'Sam', lastName: 'Observer', createdAt: '2025-06-08 16:20:00', lastLoginAt: '2026-09-21 11:02:00', roles: [fixtureRoles.readOnly],
    },
    disabled: {
        id: 'usr-fixture-disabled', email: 'deactivated-user-with-a-very-long-address@example.test', displayName: 'Deactivated User with a Long Display Name', theme: 'system', language: 'en-GB', locale: 'en-GB', timezone: 'UTC', active: false,
        firstName: 'Deactivated', lastName: 'Example', createdAt: '2024-10-12 13:00:00', lastLoginAt: '2025-12-01 12:00:00', roles: [fixtureRoles.readOnly],
    },
    multiRole: {
        id: 'usr-fixture-multi-role', email: 'jordan.multi-role@example.test', displayName: 'Jordan Multi Role', theme: 'terminus', language: 'en-GB', locale: 'en-GB', timezone: 'America/New_York', active: true,
        firstName: 'Jordan', lastName: 'Multi Role', createdAt: '2025-07-17 07:30:00', lastLoginAt: '2026-09-27 22:11:00', roles: [fixtureRoles.dnsManager, fixtureRoles.billingViewer],
    },
} satisfies Record<string, UserBootstrap>;

export const fixtureManyUsers = [
    ...Object.values(fixtureUsers),
    ...Array.from({ length: 32 }, (_, index): UserBootstrap => ({
        id: `usr-fixture-bulk-${String(index + 1).padStart(2, '0')}`,
        email: `dns-operator-${String(index + 1).padStart(2, '0')}@northwind.example.test`,
        displayName: `DNS Operator ${index + 1}`,
        theme: 'default', language: 'en-GB', locale: 'en-GB', timezone: 'UTC', active: index % 11 !== 0,
        roles: [index % 4 === 0 ? fixtureRoles.readOnly : fixtureRoles.dnsManager],
    })),
];

export const fixtureAccounts = {
    taylorPersonal: { id: 101, name: 'Taylor Example', kind: 'personal', isActive: true },
    northwind: { id: 107, name: 'Northwind Example Hosting', kind: 'organization', isActive: true },
    research: { id: 108, name: 'Research and Documentation Lab', kind: 'organization', isActive: true },
    archived: { id: 109, name: 'Archived Example Organisation', kind: 'organization', isActive: false },
} as const;

/** Account and zone grants are display fixtures, never an authorization source. */
export const fixtureMemberships = [
    { userId: fixtureUsers.superadmin.id, accountId: fixtureAccounts.taylorPersonal.id, role: 'owner' },
    { userId: fixtureUsers.superadmin.id, accountId: fixtureAccounts.northwind.id, role: 'owner' },
    { userId: fixtureUsers.superadmin.id, accountId: fixtureAccounts.research.id, role: 'owner' },
    { userId: fixtureUsers.dnsManager.id, accountId: fixtureAccounts.northwind.id, role: 'dns_manager' },
    { userId: fixtureUsers.multiRole.id, accountId: fixtureAccounts.northwind.id, role: 'dns_manager' },
    { userId: fixtureUsers.multiRole.id, accountId: fixtureAccounts.research.id, role: 'viewer' },
    { userId: fixtureUsers.restricted.id, accountId: fixtureAccounts.research.id, role: 'viewer' },
] as const;

export const fixtureZoneGrants = [
    { userId: fixtureUsers.restricted.id, accountId: fixtureAccounts.northwind.id, zoneId: 844, role: 'viewer' },
] as const;

export const fixtureProviderAccounts = [
    { id: 21, name: 'deSEC · Northwind primary', providerType: 'desec', isActive: true, status: 'active' },
    { id: 22, name: 'PowerDNS · research authoritative', providerType: 'powerdns', isActive: true, status: 'active' },
    { id: 23, name: 'Cloudflare · marketing edge', providerType: 'cloudflare', isActive: true, status: 'error' },
    { id: 24, name: 'INWX · legacy registrar', providerType: 'inwx', isActive: false, status: 'unavailable' },
] as const;

export const fixtureProviderNames: Record<string, string> = Object.fromEntries(
    fixtureProviderAccounts.map((provider) => [String(provider.id), provider.name]),
);

export const fixtureManagedZones: DnsZoneBootstrap[] = [
    { id: 841, canonicalName: 'northwind.example.test', providerAccountId: 21 },
    { id: 842, canonicalName: 'research.example.test', providerAccountId: 22 },
    { id: 843, canonicalName: 'xn--bcher-kva.northwind.example.test', providerAccountId: 21 },
    { id: 844, canonicalName: 'read-only.example.test', providerAccountId: 23 },
    { id: 845, canonicalName: 'very-long-delegated-service-name-for-responsive-table-testing.example.test', providerAccountId: 22 },
];

export const fixtureManyManagedZones: DnsZoneBootstrap[] = [
    ...fixtureManagedZones,
    ...Array.from({ length: 28 }, (_, index) => ({
        id: 900 + index,
        canonicalName: `delegated-${String(index + 1).padStart(2, '0')}.northwind.example.test`,
        providerAccountId: index % 2 === 0 ? 21 : 22,
    })),
];

export const fixtureRrsets: DnsRrsetBootstrap[] = [
    { ownerName: 'northwind.example.test', type: recordType('A', 1), ttl: 300, rdata: ['203.0.113.42', '203.0.113.43'] },
    { ownerName: 'northwind.example.test', type: recordType('AAAA', 28), ttl: 900, rdata: ['2001:db8:100::42'] },
    { ownerName: 'www.northwind.example.test', type: recordType('CNAME', 5), ttl: 3600, rdata: ['northwind.example.test.'] },
    { ownerName: 'northwind.example.test', type: recordType('MX', 15), ttl: 3600, rdata: ['10 mail.northwind.example.test.', '20 backup-mail.example.test.'] },
    { ownerName: 'northwind.example.test', type: recordType('TXT', 16), ttl: 300, rdata: ['v=spf1 include:_spf.example.test -all'] },
    { ownerName: '_dmarc.northwind.example.test', type: recordType('TXT', 16), ttl: 3600, rdata: [`v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@northwind.example.test; ${'fo=1; '.repeat(24)}`] },
    { ownerName: '_sip._tcp.northwind.example.test', type: recordType('SRV', 33), ttl: 1800, rdata: ['10 5 5061 sip-01.northwind.example.test.', '10 10 5061 sip-02.northwind.example.test.'] },
    { ownerName: 'northwind.example.test', type: recordType('CAA', 257), ttl: 86400, rdata: ['0 issue "letsencrypt.org"', '0 iodef "mailto:security@northwind.example.test"'] },
    { ownerName: 'delegated.northwind.example.test', type: recordType('NS', 2), ttl: 86400, rdata: ['ns1.example.test.', 'ns2.example.test.'] },
];

export const fixtureLongTxtRrsets: DnsRrsetBootstrap[] = fixtureRrsets.filter((rrset) => rrset.type.presentation === 'TXT');
export const fixtureManyRrsets: DnsRrsetBootstrap[] = Array.from({ length: 36 }, (_, index) => ({
    ownerName: `service-${String(index + 1).padStart(2, '0')}.northwind.example.test`,
    type: index % 2 === 0 ? recordType('A', 1) : recordType('AAAA', 28),
    ttl: [60, 300, 900, 3600][index % 4],
    rdata: index % 2 === 0 ? [`203.0.113.${(index % 200) + 10}`] : [`2001:db8:100:${index.toString(16)}::${index + 1}`],
}));

export const northwindProviderAccounts: DnsProviderAccountBootstrap[] = fixtureProviderAccounts
    .filter((provider) => provider.isActive)
    .map(({ id, name }) => ({ id, name }));
