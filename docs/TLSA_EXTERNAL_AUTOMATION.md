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

Mutation responses include `change_result`. `unchanged` means the requested
delta was already present in the provider readback. `applied_and_read_back`
means the operation and subsequent readback matched. If the provider write
returned a transport error but a fresh readback proves the requested state,
TowerDNS returns `applied_and_read_back_after_transport_error`. This confirms
provider state only, not public DNS publication. If readback cannot establish
the result, the operation fails; read the current state before retrying.

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

The sample rejects combining additions and removals in one request. Run a
prepublication invocation first and a separate retirement invocation only
after the operator or external orchestrator has confirmed publication,
activation, and the required cache-overlap interval. The example does not
sleep for TTLs, activate certificates, or infer that a deploy hook ran early
enough; those are external orchestration responsibilities.

The generator's response marks its output as unverified. Provider readback
confirms only the provider API's view. An explicit read-only authoritative
publication check is available:

```text
GET /api/v1/accounts/{account}/zones/{zone}/tlsa?owner=_443._tcp&verify=authoritative
Authorization: Bearer <tlsa:read key>
```

The authenticated UI offers the same check in the TLSA section. TowerDNS
discovers NS targets for the managed zone through the host resolver, resolves
their public addresses, and sends bounded direct UDP queries (with TCP fallback
for truncated responses) to up to eight public addresses. Private/reserved
addresses are excluded, and the check is limited to five attempts per minute.
Results contain the queried NS/address, authoritative-answer bit, DNS RCODE,
observed TLSA values, minimum answer TTL, and per-server check time. `all_match`
means each queried endpoint answered authoritatively with the provider
readback values. Disagreement, different records, timeout, incomplete checks,
and discovery failures have separate states. TTL is observational, not a
propagation guarantee.

NS and address discovery uses PHP's configured host resolver and is marked
`system_resolver_unvalidated`: resolver lookup time is governed by the host's
resolver configuration and cannot be strictly bounded by TowerDNS's direct
query deadline. The authoritative check itself does not validate DNSSEC.

### Optional DNSSEC validation

To delegate DNSSEC validation, configure an operator-trusted, DNSSEC-validating
DNS-over-HTTPS endpoint in `configs/config.local.toml`:

```toml
[dnssec]
validating_resolver_https = "https://resolver.example/dns-query"
```

TowerDNS sends bounded DNS wire queries over HTTPS with certificate validation,
redirects disabled, and DNSSEC OK set. It checks the TLSA answer and the zone's
DS state. `secure` relies on the AD bit from this explicitly configured
validating resolver; `bogus` is returned only for DNSSEC-related EDE codes.
`insecure` requires an authenticated denial of DS. Ambiguous resolver responses,
including a bare SERVFAIL, remain `indeterminate`. Without configuration the
result is `not_checked`. This is resolver-delegated validation, not local
DNSSEC signature verification: the resolver operator and its HTTPS identity
are the trust boundary. The endpoint is administrator-controlled, never
accepted from a request. Responses include the resolver host and timestamp.

`verify=dnssec` checks this status through the existing zone-scoped TLSA API.
`verify=all` combines it with the direct authoritative publication check. The
DNSSEC diagnostic is rate-limited separately and can be disabled by leaving
the endpoint empty. DNSKEY/RRSIG/DS chain processing is performed by the
configured validating resolver; TowerDNS does not claim to have independently
recomputed signatures or NSEC/NSEC3 proofs.

For SMTP, `verify=smtp-dane` resolves MX records and queries each bounded MX
target's `_25._tcp` TLSA RRset through that resolver. It retains MX preference
and TTL, distinguishes a null MX and missing TLSA data, and reports DNSSEC
status per answer. It does not resolve/connect to MX A/AAAA addresses, perform
STARTTLS, inspect server certificates, or authenticate SMTP DANE (RFC 7672).
The MX list is capped at four targets. `dane` and `smtp_tls` remain
`not_tested`.
Re-run after changes and TTL intervals. Anycast or network-specific DNS views
may still differ. One TLS handshake may show only one of several RSA/ECDSA
certificates.

### Certificate-to-public-record diagnostic

The read-only diagnostic endpoint accepts a public certificate bundle and
compares its TLSA associations with the provider readback and each result from
the authoritative publication check:

```text
POST /api/v1/accounts/{account}/zones/{zone}/tlsa/diagnostic
Authorization: Bearer <tlsa:read key>
Content-Type: application/json

{"owner":"_443._tcp","certificates":"-----BEGIN CERTIFICATE-----\\n...\\n-----END CERTIFICATE-----"}
```

The authenticated UI provides the same comparison. A PEM bundle can contain
several end-entity certificates and CA certificates; TLSA usage 1/3 is compared
with end-entity certificates, while usage 0/2 is compared with CA certificates.
The response keeps association matching, SAN/validity/key-usage details, and
system PKIX chain results separate. `matched_supplied_certificate` only means
that the supplied public certificate data matches a published association. It
does not mean a server presented that certificate, that every dual-certificate
configuration was enumerated, or that DANE authentication succeeded. No
private key is needed or accepted. Input and result counts are bounded and the
endpoint is rate limited.

## Verification status

Keep these claims separate in automation and operational records:

1. TLSA RDATA was generated and syntactically accepted.
2. The provider accepted the mutation and returned a readback.
3. Authoritative DNS servers publish the expected RRset.
4. A configured trusted validating resolver reports DNSSEC status; the
   authoritative-only check does not establish this stage.
5. The actual service certificate satisfies DANE (not performed by TowerDNS's
   DNS-only diagnostics; for SMTP, see RFC 7672).

TowerDNS reports ordinary API operations as `provider_only`. The optional
authoritative check reports stage 3 as observed from the specific NS addresses
queried. The optional resolver check reports stage 4 within the resolver trust
boundary; neither check establishes stage 5. Certificate inspection reports
SAN match, validity, key usage and a separate OpenSSL system-trust result.
TLSA association matching against user-supplied certificates is not evidence
that a server presented them, and is not a DANE authentication result.

The API limiter uses TowerDNS's configured PSR-16 filesystem cache. Its fixed
window counter is a best-effort application limit; requests arriving at the
same time in multiple PHP workers can briefly exceed the configured count.
Deployments that require strict volumetric protection should also rate-limit
the API path at their trusted reverse proxy.
