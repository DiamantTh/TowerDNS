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

## C. Runnable Storybook UX preview

The component workshop is the runnable comparison environment. It renders the
real TowerDNS Svelte components, including `AppShell`, dashboard, zone list,
RRset page, provider-account management, and account-member management with
synthetic, secrets-free bootstrap data.

```sh
npm ci
npm run storybook
```

The toolbar selects the normal Skeleton theme, language, viewport and one of
the Storybook-only UX palettes: **02 Forest Light**, **03 Amber Light**,
**05 Slate Hybrid**, **06 OLED Ocean Dark**, or **Nordic Hybrid**. The palette
token story lists the exact HEX values. The variants deliberately run over the
same real components so that tables, long RDATA values, forms, statuses and
responsive layouts can be compared directly.

`npm run storybook:check` creates an ignored `storybook-static/` directory.
It is suitable for a local or access-controlled development preview with only
the committed fixtures. It must not be copied into `httpdocs/`, and it does
not contain production credentials, live DNS data or application
configuration.

## D. Productive application

Only the normal Svelte bundle under `httpdocs/assets/` is part of the running
TowerDNS application. Storybook palettes are workshop-only token overrides:
they do not alter stored user themes, locale, active-account selection,
dashboard settings or any server-side authorization.
