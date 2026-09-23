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

The project allowlist permits only the version-pinned `esbuild@0.25.12`
postinstall script. That script selects and validates esbuild's optional
platform binary; it is required for the reviewed frontend toolchain install.
Review further install scripts individually with `npm install-scripts ls`;
do not approve all scripts as a group.

It binds to `127.0.0.1:6006`. Use the Storybook toolbar to switch the Skeleton
theme, UX palette, light/dark mode, fixture language, and preview viewport.
The stories are under **TowerDNS / Shared**, **TowerDNS / DNS**,
**TowerDNS / Layout**, and **TowerDNS / UX previews**. They include real
AppShell, dashboard, DNS, provider-account, account/member, user, role,
profile, settings and login views. All server bootstrap values are synthetic
and credentials are omitted.

The **UX palette** toolbar changes Storybook-only tokens, independently of the
Skeleton-theme toolbar. It provides Forest Light, Amber Light, Slate Hybrid,
OLED Ocean Dark and Nordic Hybrid. Hybrid variants intentionally retain a
dark navigation surface with lighter working panels; they are not aliases for
the regular dark-mode control. The palette-token story shows the exact HEX
values used by each direction.

To run a production-style Storybook compile check without touching the normal
web assets:

```sh
npm run storybook:check
```

That command writes only to the ignored `storybook-static/` directory. It can
be served as an access-controlled, static development preview when needed,
but must never be placed in the public TowerDNS `httpdocs/` runtime. The
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

### Preview navigation and actions

The Storybook preview has no Mezzio backend. Links to represented views
(dashboard, DNS zones and RRsets, provider accounts, accounts and members,
users, roles, profile, system settings, and login) open the matching story in
the Storybook manager. The root link opens the dashboard except from the login
story, where it returns to that login preview. Unrepresented internal views
(such as account detail, profile subpages, provider-wide credentials and
schema) show an accessible notice rather than a 404. Mapped links also remain
inside Storybook when opened in another tab/window. Form submissions,
programmatic form submits, TowerDNS-route fetches, and all state-changing
`fetch` requests are blocked with a visible demo notice. The preview does not
emulate server authorization or mutate fixture data. These guards are imported
only by `.storybook/preview.ts`; productive routes and actions keep their
normal behaviour.

The Storybook viewport selector includes phone widths, a 640 CSS-pixel view
representing a 1280px desktop at 200% zoom, tablet breakpoints, and desktop /
ultrawide sizes. The productive shell switches to its keyboard-operable
collapsed navigation below 1100px; wide DNS tables keep horizontal scrolling
inside their own table container rather than widening the document.
