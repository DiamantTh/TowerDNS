# Project history and code provenance

TowerDNS is developed as an independent, provider-neutral DNS management
application. The earlier `desec-manager` project was never completed and is not
a functional, architectural, or compatibility specification for TowerDNS.
TowerDNS does not currently provide an import path for its database or stored
resources.

One implementation-specific provenance note is retained in
[`DeSECApiClient`](../modules/deSEC/src/DeSECApiClient.php): its HTTP-client
implementation was adapted from the former project's `App\\DeSEC\\DeSECClient`
and then changed to fit TowerDNS's module boundary, exception type, and
injectable HTTP client. That fact does not imply that the former project's
architecture or unfinished features were ported.

TowerDNS's licensing terms are defined by the repository's
[`LICENSE`](../LICENSE) and file-level SPDX identifiers, not by its historical
relationship to another project.
