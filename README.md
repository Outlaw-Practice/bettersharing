# BetterSharing

A Nextcloud 35 app that replaces the sharing sidebar with a guided share dialog, firm-owned presets, and a tabbed sidebar (Sharing, Presets, Activity, History). Every share it makes is an ordinary Nextcloud share, so disabling the app leaves all access in place.

The design, decisions, and the reasons behind them are in [docs/PLAN.md](docs/PLAN.md).

## Configuration

Firms are Nextcloud groups. List them in the app setting `firm_groups`:

```bash
occ config:app:set bettersharing firm_groups --value='["firm-a","firm-b"]'
```

Each user must belong to exactly one firm group; anyone else sees a message instead of the sharing tools. Firm administrators are the Nextcloud group administrators (subadmins) of their firm group, plus instance admins. Before production use, complete the prerequisites in the plan: outbound mail, single-firm groups, and core firm isolation. Qualify each new upstream release through the platform test copy before deployment.

## Development

Qualification runs against the local signed-artifact Nextcloud 35 test copy at http://127.0.0.1:8191. Mailpit captures messages locally at http://127.0.0.1:8125. Provision the disposable browser identities with `tests/browser-fixture.php` and deliver the same generated password privately to its container file and the ignored mode-600 `dev/seed.env`; the platform qualification launcher handles setup and cleanup. Do not reuse real user credentials. Nothing here touches a production server.

The following Docker/setup commands are legacy Nextcloud 34 recipes, retained for reference; they are not the current qualification path:

```bash
docker compose -f dev/compose.yaml up -d
```

```bash
dev/setup.sh
```

The legacy `dev/setup.sh` installs Nextcloud 34.0.4 at http://127.0.0.1:8180; it is not the Nextcloud 35 qualification route. Platform qualification uses the signed-artifact PHP-FPM/nginx/PostgreSQL test copy with disposable firm users and local Mailpit. Generated test credentials remain private and are not committed.

```bash
npm install
```

```bash
npm run build
```

| Tests | Command |
| --- | --- |
| PHP (against the real Nextcloud 35 test copy) | Platform qualification launcher; `dev/test.sh` targets the retired legacy container |
| JavaScript unit tests | `npm test` |
| Browser tests (Playwright; signed-artifact copy on localhost:8191 with disposable fixtures) | `npm run e2e` |

PHP dependencies install with `docker run --rm -v "$PWD":/app -w /app composer:2 install`.

## Packaging

```bash
dev/package.sh
```

This writes `build/bettersharing-<version>.tar.gz`. The platform's committed input manifest records its exact digest; the signed-release builder places it in `custom_apps/bettersharing`. Do not copy it into the old snap or modify installed read-only release code.
