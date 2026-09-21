# Provider capability matrix

This matrix describes the adapter code currently present in `modules/`. It is
not a statement that every provider API path has been validated against a live
account. Provider API behavior is covered with isolated test responses where
available; no production provider is contacted by the test suite.

`Zone CRUD` means list/read/create/delete. No current adapter implements zone
metadata updates through TowerDNS: the shared provider contract has no zone
update operation, so adapters must advertise `zone.update` as unsupported.
Record CRUD is additionally limited by each provider's accepted record types
and API-specific constraints. The common record validator applies owner-name,
TTL (30–604800 seconds), and RDATA checks before provider calls. RRset views and
replacement use the adapter's RRset implementation; this is not equivalent to
every provider supporting every DNS record type or atomic changes.

| Adapter | Zones | Record CRUD | Record comments | Specific limits |
| --- | --- | --- | --- | --- |
| deSEC | List/read/create/delete | Yes | No | deSEC-managed domains and supported DNS types; DNSSEC is provider-managed |
| Cloudflare | List/read/create/delete | Yes | Yes | Provider-managed DNSSEC with enable/disable actions; no key listing/rollover |
| PowerDNS | List/read/create/delete | Yes | Yes | Native authoritative API; RRset writes adapt to server `EXTEND` support |
| INWX | List/read/create/delete | Yes | No | Domain-name zone identifiers; provider API controls accepted types |
| OVHcloud | List/read only | Yes | No | Record writes currently limited to A, AAAA, CAA, CNAME, MX, NS, PTR, SRV, TLSA, and TXT; zones must already exist in OVH |
| ClouDNS | List/read/create/delete | Yes | No | DNSSEC management is not implemented in this adapter |
| netcup CCP DNS | List/read only | Yes | No | Zones must be explicitly present in the configured allow-list; no zone create/delete through this adapter |
| Google Cloud DNS | List/read/create/delete | Yes | No | DNSSEC status read only; no action/key/DS management exposed |

## Test coverage

Dedicated mocked API/provider tests exist for deSEC, Cloudflare, PowerDNS,
INWX, OVHcloud, ClouDNS, and netcup. Google Cloud DNS currently has no
adapter-specific provider test suite; the existing factory test only checks
that the module can be instantiated. Its capability row above describes the
source implementation, not equivalent test-backed confidence. Validate the
Google adapter against a disposable Cloud DNS project before relying on it for
managed production zones.

## DNSSEC

| Adapter | Status | Automatic/provider-managed | Enable/disable | Key list | Rollover | DS data |
| --- | --- | --- | --- | --- | --- | --- |
| deSEC | Yes | Yes | No | No | No | Yes |
| Cloudflare | Yes | Yes | Yes | No | No | Yes, when returned by the API |
| PowerDNS | Yes | No | Yes | Yes | Yes | Yes |
| INWX | Yes | No | Yes | Yes | No | Yes |
| OVHcloud | Yes | No | No | No | No | No |
| ClouDNS | No | No | No | No | No | No |
| netcup CCP DNS | No | No | No | No | No | No |
| Google Cloud DNS | Yes | No | No | No | No | No |

Google Cloud DNS currently exposes DNSSEC status only; its provider-level
state is not represented as TowerDNS-managed automatic signing.

The DNSSEC screen only renders action controls when the adapter declares
`dnssec.action.execute`. An unsupported status read is shown as unavailable
rather than as a failed DNSSEC operation. Authorization and account-scope
checks remain in the application service and are independent of these provider
capabilities.

## Source of truth and maintenance

The `capabilityMap()` in each adapter is authoritative. When adapter methods or
provider API coverage changes, update this matrix and the relevant mocked
provider tests together. A capability flag means the operation is implemented
by TowerDNS's adapter; it does not promise feature parity with the provider's
full control panel.
