import type { DnsZonesPageProps } from '../../themes/default/src/lib/bootstrap';
import { fixtureAccounts, fixtureCsrfToken, fixtureManagedZones, fixtureProviderNames, northwindProviderAccounts } from './towerdns-installation';

export const exampleDnsZonesPage: DnsZonesPageProps = {
    accountId: fixtureAccounts.northwind.id,
    csrfToken: fixtureCsrfToken,
    error: null,
    providerAccounts: northwindProviderAccounts,
    providerNames: fixtureProviderNames,
    managedZones: fixtureManagedZones,
};
