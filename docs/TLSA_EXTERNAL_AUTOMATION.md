# TLSA automation with TowerDNS

TowerDNS can generate and manage TLSA records for external certificate
automation. It does not issue, install, or renew certificates. The API accepts
public PEM certificate material or already calculated TLSA RDATA; never send a
private key.

## API key

Create a key from **Profile → API keys** over HTTPS. Use the narrowest scope:

- `tlsa:append` can read and add records for the selected zone and service
  owner. It cannot remove values.
- `tlsa:write` can also remove exact values from that owner. Use it only for an
  automation that owns the corresponding records.
- `tlsa:read` is read-only and may be scoped to a zone or a single owner.

The current user must retain the matching DNS read/update/delete permissions.
The key is shown once and expires after 90 days. Multiple keys may overlap:
create and test the replacement first, update the external secret store, then
revoke the previous key. The profile lists expiry and last use. TowerDNS returns
401 for invalid, expired, or revoked keys. API requests are rate limited; 429
includes `Retry-After`.

## API sequence

The endpoint is:

```text
GET  /api/v1/accounts/{account}/zones/{zone}/tlsa?owner=_443._tcp
POST /api/v1/accounts/{account}/zones/{zone}/tlsa?owner=_443._tcp
```

Send `Authorization: Bearer …`. GET returns the current canonical record list,
TTL, provider capabilities and an ETag. POST requires that ETag in `If-Match`.
For example, add certificate-derived records without replacing the RRset:

```json
{
  "certificates": "-----BEGIN CERTIFICATE-----\n...public certificate...\n-----END CERTIFICATE-----",
  "usage": 3,
  "selector": 1,
  "matching": 1
}
```

Or add/remove exact TLSA RDATA values using `add` and `remove` arrays. An add is
idempotent. A repeated remove of an absent value is also harmless. The API
canonicalizes and compares values; it does not automatically remove a value
because it was absent from a new certificate bundle. HTTP 412 means the
TowerDNS snapshot is stale. HTTP 409 means the provider reported a state
conflict or does not offer the requested mutation. Re-read, compare the new
state with the intended change, and only then submit a new request with the new
ETag. Do not retry a stale full RRset from a saved local copy.

Provider capability fields describe the available provider operation, not a
universal guarantee. Providers with targeted record APIs preserve siblings but
may not provide compare-and-swap against direct external edits. A timeout after
a write is an unknown result; TowerDNS reads back the provider and does not
blindly replay a complete RRset replacement. Inspect a fresh GET before
deciding what to do next.

## acme.sh and staged rollover

An ordinary acme.sh deploy hook is commonly called after renewal and may run
while installing the new certificate. Publishing a TLSA value from that hook
alone is therefore **not** proof of safe prepublication. RFC 7671 recommends
publishing a new association at least two TTLs before deploying the matching
key. If the key has already been switched, publishing its association after
the fact cannot repair the earlier validation window.

Use an external orchestration with separate stages:

1. Obtain the renewed **public** certificate bundle from the ACME client.
2. Before installing it, use TowerDNS with an `tlsa:append` key to add the
   new associations. Keep all currently served RSA, ECDSA, and other
   certificate associations in the RRset.
3. Read back the provider state and independently check publication at the
   authoritative nameservers. Wait the required TTL/propagation interval.
4. Install/activate the new certificate using the ACME client and verify the
   service from the relevant network path.
5. Only after that verification and the required overlap, use a deliberately
   authorized `tlsa:write` key to remove the exact old RDATA values that this
   automation owns. Do not remove other algorithms' or automation's values.

The sample [`examples/tlsa-api.sh`](examples/tlsa-api.sh) demonstrates GET,
ETag use, certificate-based addition, and exact-value removal. It reads its
token from the environment, never prints it, and does not automatically retry
409/412. It requires `curl` and `jq`. Store the token in the ACME deployment
environment or a secret manager; do not put it in a checked-in hook. Configure
the API URL, account, zone, service owner, and either a public certificate
path (`TLSA_CERT_FILE`) or exact old RDATA as JSON (`TLSA_REMOVE_JSON`).

For example, an external orchestration step may call it with
`TLSA_CERT_FILE="$PUBLIC_CERT_BUNDLE"`, `TLSA_OWNER="_443._tcp.example.org"`,
the already configured `TOWERDNS_API_URL`, and a secret-store supplied
`TOWERDNS_API_TOKEN`. acme.sh deploy hooks can invoke external scripts, but a
post-issuance hook must not be treated as the prepublication stage unless the
surrounding deployment explicitly postpones certificate activation until the
publication and TTL wait have completed.

The generator's response marks its output as unverified. Provider readback
confirms only the provider API's view. TowerDNS currently does not independently
query every authoritative server, validate the public DNSSEC chain, or perform
a TLS/DANE service handshake. A resolver's AD bit is not treated as an
independent DNSSEC proof. Use an external DNSSEC/DANE validator and record which
nameservers and time it checked. One TLS handshake may show only one of several
RSA/ECDSA certificates.

## Verification status

Keep these claims separate in automation and operational records:

1. TLSA RDATA was generated and syntactically accepted.
2. The provider accepted the mutation and returned a readback.
3. Authoritative DNS servers publish the expected RRset.
4. The DNSSEC chain validates to a trusted root.
5. The actual service certificate satisfies DANE (for SMTP, see RFC 7672).

TowerDNS reports the API operation as `provider_only`; it currently establishes
stage 2, not stages 3–5. Certificate-chain inspection in the generator is
informational and is not a DANE authentication result.

The API limiter uses TowerDNS's configured PSR-16 filesystem cache. Its fixed
window counter is a best-effort application limit; requests arriving at the
same time in multiple PHP workers can briefly exceed the configured count.
Deployments that require strict volumetric protection should also rate-limit
the API path at their trusted reverse proxy.
