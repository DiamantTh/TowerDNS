# TowerDNS UI examples

## A. Original implementation screenshots

These six images are screenshots of the current Svelte application, rendered
with the repository's `SvelteRenderer` and built frontend assets. The example
rows, zone names, addresses, users, and provider-connection labels are
synthetic fixtures created only to make the existing views legible. They are
not a live TowerDNS database, real DNS data, real provider accounts, or claims
about provider activity. No credentials or tokens are present. These are
examples of implemented screens, not mockups of planned functionality. They
are retained as the **before state**; the separate redesign concepts are in
[`proposals/`](proposals/README.md).

The views cover the dashboard, zone list, one zone's RRsets, provider-account
management, account administration, and user administration. The UI continues
to use server-selected Mezzio pages; the Svelte application renders the
selected page and receives only that page's bootstrap data.

| Screenshot | View |
| --- | --- |
| `dashboard.png` | Dashboard with navigation and the available high-level sections |
| `zones.png` | Account-scoped DNS zones with provider labels and create-zone form |
| `zone-records.png` | One zone's RRset form and records |
| `provider-accounts.png` | Account provider connections and credential-management controls |
| `accounts.png` | Personal and organization accounts, member roles, and status controls |
| `users.png` | User search and user-management examples |

Provider coverage and known operation limits are documented in
[`../PROVIDER_CAPABILITIES.md`](../PROVIDER_CAPABILITIES.md).

## B. Older static UX proposals

Local files in `proposals/` are earlier, static reference material. They are
kept separate from application source and are not evidence of implemented
features. They may contain deliberately synthetic metrics or layout ideas.

## C. Productive application

Only the normal Svelte bundle under `httpdocs/assets/` is part of the running
TowerDNS application. A future, temporary UX development tool may use the
real components with synthetic, secrets-free states, but is not part of the
application or this repository at present. It must not alter stored user
themes, locale, active-account selection, dashboard settings, or any
server-side authorization.
