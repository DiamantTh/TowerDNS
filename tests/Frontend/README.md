# DNS workspace regression checks

Run the pure search/filter/sort checks with Node.js 22.15 or newer:

```sh
node --experimental-strip-types tests/Frontend/dns-workspace.test.mjs
```

The browser test requires local Firefox, PHP and Python 3, and uses ports
28570 and 28571. Prepare and explicitly activate the current frontend first:

```sh
npm run frontend:check
php scripts/activate-frontend.php
python3 tests/Frontend/dns-browser-test.py
```

The browser fixture uses synthetic account/zone/RRset data. It exercises DE/EN,
multiple providers, search and filtering, RRset editing, contextual delete
confirmation, long TXT values, a narrow viewport, read-only controls and DNSSEC
navigation. It never performs provider writes. Service tests separately cover
provider capabilities, scoped mutations and authorization.

Account/provider and monitoring regressions use the same prerequisites, with separate ports 28572 and 28573:

```sh
python3 tests/Frontend/account-provider-browser-test.py
```

They verify metadata defaults, cleared secrets on provider changes, duplicate
submit protection, account context, read-only connections and narrow layouts
in both languages. The monitoring fixture also covers notification settings, keyboard focus, a delivery error, and read-only rendering. State-changing submissions are intercepted in the fixture. The scripts use Firefox Marionette directly. On a host that blocks the test server or headless Firefox inside the coding sandbox, run the same commands in the normal desktop session with Firefox security settings intact.
