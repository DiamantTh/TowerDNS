import type { DnsZoneBootstrap, DnsRrsetBootstrap } from '../../lib/bootstrap';

const compare = (a: string, b: string) => a.toLowerCase().localeCompare(b.toLowerCase(), 'en', { numeric: true }) || a.localeCompare(b, 'en');
export function visibleZones(zones: DnsZoneBootstrap[], query: string, provider: string): DnsZoneBootstrap[] {
    const search = query.trim().toLowerCase();
    return zones.filter(zone => zone.canonicalName.toLowerCase().includes(search) && (!provider || String(zone.providerAccountId) === provider))
        .slice().sort((a, b) => compare(a.canonicalName, b.canonicalName) || a.id - b.id);
}
export function visibleRrsets(rrsets: DnsRrsetBootstrap[], query: string, type: string): DnsRrsetBootstrap[] {
    const search = query.trim().toLowerCase();
    return rrsets.filter(rrset => (!type || rrset.type.presentation === type) && [rrset.ownerName || '@', ...rrset.rdata].some(value => value.toLowerCase().includes(search)))
        .slice().sort((a, b) => compare(a.ownerName, b.ownerName) || a.type.code - b.type.code);
}
