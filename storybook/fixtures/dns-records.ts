import type { DnsRecordsPageProps, DnsRrsetBootstrap } from '../../themes/default/src/lib/bootstrap';

export type FixtureLanguage = 'en-GB' | 'de-DE';

export const dnsRecordsMessages: Record<FixtureLanguage, Record<string, string>> = {
    'en-GB': {
        'navigation.zones': 'DNS zones',
        'rrset.save': 'Save RRset',
        'rrset.save-hint': 'Add or replace the values for one DNS owner and record type.',
        'rrset.column.owner': 'Owner name',
        'rrset.column.type': 'Type',
        'rrset.column.ttl': 'TTL',
        'rrset.column.rdata': 'Record data',
        'rrset.column.actions': 'Actions',
        'rrset.table-caption': 'DNS resource record sets',
        'rrset.empty': 'This zone has no record sets yet.',
        'rrset.delete': 'Delete RRset',
        'rrset.confirm-delete': 'Delete this RRset?',
        'rrset.action-unavailable': 'This action is unavailable for the current account or provider.',
    },
    'de-DE': {
        'navigation.zones': 'DNS-Zonen',
        'rrset.save': 'RRset speichern',
        'rrset.save-hint': 'Werte eines DNS-Namens und Record-Typs hinzufügen oder ersetzen.',
        'rrset.column.owner': 'Owner-Name',
        'rrset.column.type': 'Typ',
        'rrset.column.ttl': 'TTL',
        'rrset.column.rdata': 'Record-Daten',
        'rrset.column.actions': 'Aktionen',
        'rrset.table-caption': 'DNS-Resource-Record-Sets',
        'rrset.empty': 'Diese Zone enthält noch keine RRsets.',
        'rrset.delete': 'RRset löschen',
        'rrset.confirm-delete': 'Dieses RRset löschen?',
        'rrset.action-unavailable': 'Diese Aktion ist für das aktuelle Konto oder den Provider nicht verfügbar.',
    },
};

const recordType = (presentation: string, code: number): DnsRrsetBootstrap['type'] => ({
    presentation,
    code,
    isKnown: true,
});

export const exampleRrsets: DnsRrsetBootstrap[] = [
    { ownerName: 'example.test', type: recordType('A', 1), ttl: 300, rdata: ['203.0.113.42', '203.0.113.43'] },
    { ownerName: 'mail.example.test', type: recordType('AAAA', 28), ttl: 900, rdata: ['2001:db8::25'] },
    { ownerName: 'example.test', type: recordType('MX', 15), ttl: 3600, rdata: ['10 mail.example.test.'] },
];

export function createDnsRecordsPage(options: {
    error?: string | null;
    language?: FixtureLanguage;
    canReplaceRrsets?: boolean;
    canDeleteRrsets?: boolean;
    rrsets?: DnsRrsetBootstrap[];
} = {}): DnsRecordsPageProps {
    return {
        accountId: 107,
        managedZoneId: 841,
        managedZoneName: 'example.test',
        csrfToken: 'storybook-fixture-only-not-a-valid-token',
        error: options.error ?? null,
        success: null,
        canReplaceRrsets: options.canReplaceRrsets ?? true,
        canDeleteRrsets: options.canDeleteRrsets ?? true,
        rrsets: options.rrsets ?? exampleRrsets,
    };
}

export const longTxtRrsets: DnsRrsetBootstrap[] = [{
    ownerName: '_policy.example.test',
    type: recordType('TXT', 16),
    ttl: 3600,
    rdata: [
        `v=spf1 ${'include:mail.example.test '.repeat(8)}~all`,
        `verification=${'synthetic-value-'.repeat(12)}`,
    ],
}];
