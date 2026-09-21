import type { DnsZonesPageProps } from '../../themes/default/src/lib/bootstrap';

export const exampleDnsZonesPage: DnsZonesPageProps = {
    accountId: 107,
    csrfToken: 'storybook-fixture-only-not-a-valid-token',
    error: null,
    providerAccounts: [{ id: 21, name: 'Synthetic DNS provider' }],
    providerNames: { '21': 'Synthetic DNS provider' },
    managedZones: [
        { id: 841, canonicalName: 'example.test', providerAccountId: 21 },
        { id: 842, canonicalName: 'long-example-name.internal.example.test', providerAccountId: 21 },
    ],
};
