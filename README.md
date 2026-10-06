# BetterSharing

A Nextcloud 34 app that replaces the sharing sidebar with a guided share dialog, firm-owned presets, and a tabbed sidebar (Sharing, Presets, Activity, History). Every share it makes is an ordinary Nextcloud share, so disabling the app leaves all access in place.

The design, decisions, and the reasons behind them are in [docs/PLAN.md](docs/PLAN.md).

## Configuration

Firms are Nextcloud groups. List them in the app setting `firm_groups`:

```bash
occ config:app:set bettersharing firm_groups --value='["firm-a","firm-b"]'
```

Each user must belong to exactly one firm group; anyone else sees a message instead of the sharing tools. Firm administrators are the Nextcloud group administrators (subadmins) of their firm group, plus instance admins. Before production use, complete the prerequisites in the plan: outbound mail, single-firm groups, core firm isolation, and pinning the Nextcloud major version.

## Development

Everything runs against a local test instance in Docker. Nothing here touches a production server.

```bash
docker compose -f dev/compose.yaml up -d
```

```bash
dev/setup.sh
```

`dev/setup.sh` installs Nextcloud 34.0.4 at http://127.0.0.1:8180 with two test firms (users `alice` and `bob` in firm A, `carol` in firm B) and Mailpit at http://127.0.0.1:8125. Generated test passwords are in `dev/seed.env`, which is not committed.

```bash
npm install
```

```bash
npm run build
```

| Tests | Command |
| --- | --- |
| PHP (against the real test instance) | `dev/test.sh` |
| JavaScript unit tests | `npm test` |
| Browser tests (Playwright) | `npm run e2e` |

PHP dependencies install with `docker run --rm -v "$PWD":/app -w /app composer:2 install`.

## Packaging

```bash
dev/package.sh
```

This writes `build/bettersharing-<version>.tar.gz`, which unpacks into a Nextcloud apps directory (on the N5 snap: `/var/snap/nextcloud/current/nextcloud/extra-apps`). Enable with `occ app:enable bettersharing`.
