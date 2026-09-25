export type ThemeBootstrap = {
    name: string;
    displayName: string;
    description: string;
    skeletonTheme: string;
};

export type RoleBootstrap = {
    id: string;
    name: string;
    isBuiltIn: boolean;
    permissions: string[];
};

/** Fields exposed for shared navigation and locale-aware display. */
export type UserBootstrap = {
    id: string;
    email: string;
    displayName: string | null;
    theme: string;
    language: string;
    locale: string;
    timezone: string;
    roles: RoleBootstrap[];
    active?: boolean;
    firstName?: string | null;
    lastName?: string | null;
    alternateEmail?: string | null;
    phone?: string | null;
    mobile?: string | null;
    street?: string | null;
    street2?: string | null;
    postalCode?: string | null;
    city?: string | null;
    region?: string | null;
    country?: string | null;
    createdAt?: string | null;
    lastLoginAt?: string | null;
};

export type AuthenticationPageName = 'login' | 'forgot_password' | 'reset_password' | 'mfa_totp' | 'login_webauthn';

/** Values rendered by Login, password-reset, TOTP and WebAuthn handlers. */
export type AuthenticationPageData = {
    csrfToken?: string;
    error?: string | null;
    sent?: boolean;
    token?: string | null;
    optionsJson?: string;
};

/** JSON representation of DNSRecordType produced for the RRset page. */
export type DNSRecordTypeBootstrap = {
    presentation: string;
    code: number;
    isKnown: boolean;
};

/** Display-only RRset fields deliberately passed to the DNS records page. */
export type DnsRrsetBootstrap = {
    ownerName: string;
    type: DNSRecordTypeBootstrap;
    ttl: number;
    rdata: string[];
};

/** Props assembled by RecordListHandler for the account-scoped RRset view. */
export type DnsRecordsPageProps = {
    accountId: number;
    managedZoneId: number;
    managedZoneName: string;
    rrsets: DnsRrsetBootstrap[];
    csrfToken: string;
    error: string | null;
    success: string | null;
    canReplaceRrsets: boolean;
    canDeleteRrsets: boolean;
};

/** Display-only zone and provider fields emitted by ZoneListHandler. */
export type DnsZoneBootstrap = {
    id: number;
    canonicalName: string;
    providerAccountId: number;
};

export type DnsProviderAccountBootstrap = {
    id: number;
    name: string;
};

/** Props assembled by ZoneListHandler for the account-scoped zone view. */
export type DnsZonesPageProps = {
    accountId: number;
    managedZones: DnsZoneBootstrap[];
    providerAccounts: DnsProviderAccountBootstrap[];
    providerNames: Record<string, string>;
    csrfToken: string;
    error: string | null;
};

export function isAuthenticationPage(page: string): page is AuthenticationPageName {
    return ['login', 'forgot_password', 'reset_password', 'mfa_totp', 'login_webauthn'].includes(page);
}
