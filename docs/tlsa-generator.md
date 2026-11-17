# TLSA generation (RFC 6698 / RFC 7671)

TowerDNS handles DNS RRsets; certificate issuance and renewal remain external concerns.
The generator never calls `exec()`, opens TLS sockets, or searches for certificates on remote servers.

## Current implementation

- `TowerDNS\\Application\\DNS\\TlsaGenerator` is the single generation engine used by the web preview and CLI.
- Supports certificate usages 0 (PKIX-TA), 1 (PKIX-EE), 2 (DANE-TA), 3 (DANE-EE); selectors 0 (complete DER certificate), 1 (DER SubjectPublicKeyInfo); and matching types 0 (exact DER), 1 (SHA-256), 2 (SHA-512).
- PEM certificate bundles and PEM SubjectPublicKeyInfo (`PUBLIC KEY`) blocks are supported. The browser import accepts DER certificate files as well; the CLI wraps DER files as certificates.
- ASN.1 processing is independent of whether the public key uses RSA, ECDSA, or EdDSA. No private key material is required, transferred, or stored.
- Multiple input blocks produce multiple TLSA RDATA values and duplicate values are removed.
- The generator only produces DNS values; it does **not** certify that the chosen CA/EE usage matches the server's actual certificate chain.

### Web workflow

On a zone's record page, use the TLSA generator below the RRset editor.
Choose the usage, selector and matching type, paste/upload *public* certificates,
then add the generated values to the existing editor. The editor merges existing
TLSA RDATA values for the currently selected owner and the new generated values.
Review the service owner name (`_25._tcp.mail`, for example), TTL, all associations,
provider capabilities and DNSSEC configuration before saving.

The preview POST route requires a logged-in session, matching zone-scoped
`record.update` permission and CSRF token. It does not write DNS; the existing
RRset replacement flow performs that step and applies the normal permission,
provider and audit checks.

### CLI

```console
bin/towerdns tlsa:generate --file=/path/to/rsa.pem --file=/path/to/ecdsa.pem --usage=3 --selector=1 --matching=1
```

Output is one canonical TLSA RDATA value per line. Use `--format=json` if a
script needs structured output. The CLI processes local public certificate files
only; external scripts/acme.sh hooks may run this command after renewal.

## Operational limitations

- A remote TLS handshake cannot guarantee discovery of every certificate when
  RSA/ECDSA/EdDSA selection, SNI, protocol version, multiple A/AAAA targets or
  load balancing influence what a server presents. No remote probe is currently offered.
- For usage 3 / selector 1, a certificate renewal **without** a public-key change
  keeps the TLSA association unchanged. A key change needs a safe overlapping
  publication/activation/removal sequence (RFC 7671 and RFC 7672 for SMTP).
- The browser only prepares/edits a full RRset. External synchronisation must not
  blindly overwrite TLSA records owned by another integration.
- TowerDNS's existing API-key page intentionally **does not issue usable API
  credentials**. The authenticated preview endpoint is **not** a public automation
  API. A future API requires its own token authentication, scoped permissions,
  ownership/concurrency handling, audit logging and safe RRset updates.
- DNSSEC chain validation, TLS service scanning, ACME deployment and KeyHelp hooks
  are not provided by this feature.
