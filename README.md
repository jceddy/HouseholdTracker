# HouseholdTracker

A web-based household tracker.

## Repository structure

This repository is organized into three independent projects, the same
layout used by [MoodSwings-Web](https://github.com/jceddy/MoodSwings-Web):

- [`php-app/`](php-app/) — The PHP REST API implementing the application's
  server-side logic (user accounts, and whatever household-tracking
  features get built on top of them).
- [`database/`](database/) — The MySQL schema and related database assets.
- [`web-static/`](web-static/) — Static web content (HTML/CSS/JS/images)
  served to the browser.

See each project's own README for setup and details.

## Branching & environments

Two long-lived branches, each deploying to its own domain:

- **`development`** — the integration branch. Feature/fix PRs merge here.
  Every merge auto-deploys to the dev domain via
  `.github/workflows/deploy-dev.yml`, so the dev site always reflects the
  latest merged work.
- **`main`** — production. Deploys to the live domain via
  `.github/workflows/deploy.yml`. `main` only moves forward via a periodic
  `development` -> `main` pull request, promoting a batch of
  already-merged, already-dev-tested changes to production on a
  controlled schedule rather than on every individual merge.

The two deploy workflows are otherwise identical (same build/artifact
steps) and read entirely separate `DEV_`-prefixed secrets/variables (see
"Development environment setup" below) so configuring, or misconfiguring,
the dev environment can never touch production's already-live credentials.

`development` isn't created by this initial scaffold — create it (from
`main`, once this structure is merged) the first time there's a dev
environment ready to deploy to.

## Versioning

The three sub-projects deploy together as one site (see "Deployment"
below), so they share a single product version rather than each having
their own — tracked in the [`VERSION`](VERSION) file at the repo root.
Follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`).

Starting at `0.1.0` rather than `1.0.0` follows SemVer's own convention for
initial development: the public API/data model can still change in
backward-incompatible ways at any time before `1.0.0`.

`VERSION` is bumped by hand as part of whatever PR the version change
belongs to.

**Hard requirement: any change that includes a database migration must
also bump `VERSION`.** The deployed app compares `VERSION` against a
version value stored in the database (`schema_version`, see
`database/README.md`) on every request, and shows a maintenance page on
any mismatch — see `MaintenanceGate` in `php-app/README.md`. This exists
because production's GitHub Actions runner has no direct access to the
database (it can only reach it indirectly, by asking the already-deployed
app to apply pending migrations itself — see "Deployment" below); without
this check, a deploy that shipped code depending on a not-yet-applied
migration would run silently against a stale schema for however long that
request takes, instead of visibly blocking traffic until the migration
catches up.

## Deployment

`.github/workflows/deploy.yml` deploys to Bluehost over FTP on every push
to `main` (production); `.github/workflows/deploy-dev.yml` does the same
on every push to `development` (dev), reading its own separate set of
secrets — see "Branching & environments" above. Both merge `web-static/`
and `php-app/` into a single site: static files serve from the domain
root, and the PHP app is reachable under `/app` (e.g. `/app/health`) via
`php-app/public/.htaccess`'s rewrite rule.

Every `<script src="...">`/`<link href="...">` referencing a `.js`/`.css`
file gets `?v=<short commit SHA>` appended during the build, so browsers
that already cached an old version of a script reliably fetch the new one
instead of silently keeping the stale cached copy.

Deploys aren't atomic: the FTP action uploads changed files one at a time,
so for the (typically brief) duration of a deploy, different requests can
hit a mix of old and new files.

Once the migration and health-check steps below both succeed, the deploy
is considered complete.

### One-time setup

1. In cPanel, create (or reuse) an FTP account for deploys and note its
   host/username/password.
2. Get SMTP credentials for sending the registration verification email
   and password reset email. A transactional email service (e.g.
   SendGrid, Mailgun, Postmark) is recommended over Bluehost's own mail
   server — shared-hosting IPs have no sending reputation of their own,
   which can mean mail gets silently filtered by providers like Gmail
   even with correct SPF/DKIM.
3. In your GitHub repo, go to **Settings → Secrets and variables →
   Actions** and add these **secrets**:
   - `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` — from step 1.
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` —
     credentials for the MySQL database the deployed app should use.
   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`,
     `SMTP_ENCRYPTION` (`tls` or `ssl`), `SMTP_FROM_ADDRESS`,
     `SMTP_FROM_NAME` — from step 2, used to send verification and
     password reset emails.
   - `MIGRATION_DEPLOY_KEY` — lets each deploy apply its own pending
     `database/migrations/*.sql` files automatically (any sufficiently
     random string, e.g. `openssl rand -hex 32`). Shared with
     `deploy-dev.yml` (dev). See "Auto-applying migrations on deploy" in
     `database/README.md`; optional in the sense that skipping it just
     falls back to applying migrations by hand (step 5 below).
   - `FIREWORKS_API_KEY` — from your [Fireworks AI account](https://fireworks.ai),
     lets `POST /chat` run LLM requests. Unlike `MIGRATION_DEPLOY_KEY`/
     `SMTP_*` above, dev gets its **own** key (`DEV_FIREWORKS_API_KEY`,
     see "Development environment setup" below) rather than sharing this
     one — it's a cost-metered third-party API key, so dev's own
     usage/spend should never draw against production's Fireworks balance.
     Optional: without it, `/chat` just returns `503`. See "LLM usage
     (Fireworks AI)" in `php-app/README.md`.
4. Optionally add these **variables** (same Settings page, "Variables"
   tab):
   - `FTP_SERVER_DIR` — remote path to deploy into. Defaults to
     `/public_html/` if unset.
   - `APP_URL` — your live site's base URL including the `/app` path
     (e.g. `https://example.com/app`), used to build the verification/
     reset links sent in email.
   - `SITE_URL` — your live site's base URL (e.g. `https://example.com`),
     domain root only, no `/app`. If set, the workflow curls
     `$SITE_URL/app/health` after each deploy as a smoke test (and, if
     `MIGRATION_DEPLOY_KEY` is also set, `POST`s to `$SITE_URL/app/migrate`
     just before that to apply any pending migration). If unset, the app
     derives it from `APP_URL` instead, so this is optional but
     recommended.
5. Create the database itself. A brand-new database still needs its
   initial schema applied by hand — this repo's GitHub Actions runner
   cannot reach Bluehost's MySQL directly, so run each file in
   `database/migrations/` (in filename order) yourself via phpMyAdmin's
   SQL tab in cPanel (or Bluehost's Remote MySQL feature if you prefer a
   local client). See [`database/README.md`](database/README.md) for
   details. Every *later* migration, once the app is deployed and
   `MIGRATION_DEPLOY_KEY` is set, applies itself automatically on the
   deploy that introduces it.
6. In cPanel's **Cron Jobs** page, add a daily entry running
   `bin/generate_task_instances.php` — see "Cron setup" in
   `php-app/README.md`'s "Task/chore tracking" section for the exact
   command and why this can't be part of the deploy workflow itself
   (cPanel cron entries aren't something a GitHub Actions run can create).

Once secrets are set and `main` has the schema-backed database ready, a
push to `main` deploys automatically, applying any pending migration along
the way.

### Development environment setup

Same steps as above, aimed at your dev domain/database instead, using the
`DEV_`-prefixed name of each secret/variable so FTP/DB credentials stay
entirely separate from production's — except SMTP, which is intentionally
shared: both `deploy.yml` and `deploy-dev.yml` read the same plain
`SMTP_*` secrets, since it's just a transactional-email sender rather than
something meaningfully different per environment.

1. A separate FTP account (or the same one, if it can already reach your
   dev domain's document root) for `DEV_FTP_SERVER`, `DEV_FTP_USERNAME`,
   `DEV_FTP_PASSWORD`.
2. Add the **secrets**: `DEV_FTP_SERVER`/`DEV_FTP_USERNAME`/
   `DEV_FTP_PASSWORD`, `DEV_DB_HOST`/`DEV_DB_PORT`/`DEV_DB_NAME`/
   `DEV_DB_USER`/`DEV_DB_PASSWORD`. No `DEV_SMTP_*` secrets are needed —
   `deploy-dev.yml` reuses production's own `SMTP_*` secrets from step 3
   above, so if those are already set, dev's email sending already works.
   No separate `DEV_MIGRATION_DEPLOY_KEY` is needed either — `deploy-dev.yml`
   reuses the same `MIGRATION_DEPLOY_KEY` secret. `DEV_FIREWORKS_API_KEY`
   **is** its own separate key, though (see step 3 above) — a fresh one
   from the same or a different Fireworks account, so dev usage/spend
   stays independent of production's.
3. Add the **variables**: `DEV_FTP_SERVER_DIR`, `DEV_APP_URL` (your dev
   domain's `/app` URL), `DEV_SITE_URL` (your dev domain's base URL).
4. Create a **separate** database for the dev domain (do not point it at
   the production database) and apply `database/migrations/` to it the
   same way as production's step 5 — the two environments' data should
   stay fully independent, so testing on dev never risks live data.

Once these are set, a push to `development` (i.e. any feature PR merging
into it) deploys automatically to the dev domain, applying any pending
migration along the way.

## Repository variables/secrets checklist

Everything from the two setup sections above, in one place — see there for
what each one does.

**Production secrets:** `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`,
`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `SMTP_HOST`,
`SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`,
`SMTP_FROM_ADDRESS`, `SMTP_FROM_NAME`, `MIGRATION_DEPLOY_KEY` (shared with
dev), `FIREWORKS_API_KEY` (own key, not shared with dev — see below).

**Production variables:** `FTP_SERVER_DIR`, `APP_URL`, `SITE_URL`.

**Development secrets (own set, `DEV_`-prefixed):** `DEV_FTP_SERVER`,
`DEV_FTP_USERNAME`, `DEV_FTP_PASSWORD`, `DEV_DB_HOST`, `DEV_DB_PORT`,
`DEV_DB_NAME`, `DEV_DB_USER`, `DEV_DB_PASSWORD`, `DEV_FIREWORKS_API_KEY`.
(SMTP and `MIGRATION_DEPLOY_KEY` are shared with production — no `DEV_`
versions of those; `FIREWORKS_API_KEY` is the one exception that does get
its own `DEV_` version, since it's a cost-metered third-party API key —
see "Development environment setup" above.)

**Development variables:** `DEV_FTP_SERVER_DIR`, `DEV_APP_URL`,
`DEV_SITE_URL`.

**A database** for each environment, with `database/migrations/` applied
(see `database/README.md`).

## Credits

- **Developer:** [jceddy](https://github.com/jceddy)
