# TowerDNS component workshop

The optional component workshop uses Storybook with the existing Svelte 5 and
Vite toolchain. It renders TowerDNS's real Svelte components and imports the
normal Skeleton theme and application stylesheet; it is not a replacement UI
or a second application runtime.

## Run it

Install the development dependencies with `npm ci`, then start the workshop:

```sh
npm run storybook
```

It binds to `127.0.0.1:6006`. Use the Storybook toolbar to switch the Skeleton
theme, light/dark mode, fixture language, and preview viewport. The current
stories are under **TowerDNS / Shared** (data table, feature card, notice) and
**TowerDNS / DNS** (the existing record-set page).

To run a production-style Storybook compile check without touching the normal
web assets:

```sh
npm run storybook:check
```

That command writes only to the ignored `storybook-static/` directory. The
normal `npm run build` continues to write the application bundle to
`httpdocs/assets/`.

## Scope and safety

All Storybook configuration and fixture data live in `.storybook/` and
`storybook/`. They are not imported by the PHP entry point, `SvelteRenderer`,
or the normal Vite application entries. Storybook packages are development
dependencies only; neither Composer nor PHP runtime startup requires Node.js,
Storybook, or its files.

The DNS page story mounts the real `App.svelte` with a small server-bootstrap
fixture. Names use `example.test`, addresses use documentation-only IPv4/IPv6
ranges, and the CSRF value is deliberately invalid. No provider credentials
are included and the stories do not call the backend. A story that hides an
action only demonstrates a visual state: authorization remains a server-side
responsibility and is not tested by the workshop.

The fixtures are deliberately limited to component states that the current
components can render. They do not imply that every story has a distinct
production workflow or that a provider operation is functional.
