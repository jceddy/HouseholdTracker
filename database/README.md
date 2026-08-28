# database

MySQL schema for HouseholdTracker, managed as an ordered set of migrations
in [`migrations/`](migrations/) rather than a single schema dump — new
schema changes ship as small incremental files applied on top of whatever's
already there, instead of re-running the whole thing.

## Applying migrations

**Local development / tests:** point `php-app`'s `.env` (or `DB_*`
environment variables) at the target database, then run:

```sh
cd php-app
composer migrate
```

This applies whichever migrations in `migrations/` haven't been run yet,
tracked in a `schema_migrations` table, and is safe to re-run at any time —
already-applied migrations are skipped.

**Production (Bluehost, no shell access):** applies automatically as part
of every deploy. Neither `deploy.yml` nor `deploy-dev.yml`'s own GitHub
Actions runner can reach Bluehost's MySQL directly, so instead each
workflow's "Run database migrations" step, right after the file upload,
sends a `POST /migrate` request (`X-Migration-Key` header) to the
already-deployed app itself — which *can* reach its own database — and
`MigrationRunner::applyPending()` runs there exactly as `composer migrate`
would locally (see "Auto-applying migrations on deploy" below). This step
is skipped, falling back to the manual path below, if the
`MIGRATION_DEPLOY_KEY` secret isn't set yet or the relevant
`SITE_URL`/`DEV_SITE_URL` variable is unset.

**Manual fallback (or initial setup, before the app's first deploy ever
runs):** apply migration files yourself via phpMyAdmin's SQL tab, in
filename order — each one is a plain `.sql` file you can paste and run
directly.

## Adding a new migration

Add a new file named `NNNN_description.sql` (next sequential 4-digit
number) containing only the incremental change (e.g. an `ALTER TABLE`),
not a full schema dump. Migrations are immutable once merged — never edit
one that's already shipped; add a new one instead. Keep each migration to
plain DDL statements (no stored procedures/triggers), and if a migration
seeds data, make sure none of the values contain a literal semicolon —
`bin/migrate.php` splits files on `;`.

**Every migration that changes the schema must also bump the root
[`VERSION`](../VERSION) file, and must end with an `UPDATE schema_version
SET version = 'X.Y.Z' WHERE id = 1;` matching that same bump** (see
`MaintenanceGate` in `php-app/README.md`). This must be the file's **last
statement** — MySQL/InnoDB DDL isn't transactional, so a partial/failed run
needs to leave `schema_version` still reporting the *old* version (keeping
the app in maintenance mode) rather than falsely reporting the new one
against a half-migrated schema.

## Auto-applying migrations on deploy

`php-app/src/Database/MigrationRunner.php` holds the actual
apply-pending-migrations logic (scan `database/migrations/*.sql`, skip
whatever's already recorded in `schema_migrations`, run the rest in
filename order, record each as it applies) — shared by two callers:

- **`bin/migrate.php`** (`composer migrate`) — the local/CI-less path.
- **`POST /migrate`** (`php-app/public/index.php`) — an HTTP-triggered
  path for production/dev, since neither has shell access of its own.
  Gated on an `X-Migration-Key` header matching the `MIGRATION_DEPLOY_KEY`
  secret (compared with `hash_equals()`), since this runs from a CI job
  with no logged-in user session. Exempted from `MaintenanceGate`
  (alongside `/health`), since `/migrate`'s entire purpose is to resolve a
  deployed-`VERSION`-vs-`schema_version` mismatch.

**One-time setup:** add a `MIGRATION_DEPLOY_KEY` repository secret (any
sufficiently random string — e.g. `openssl rand -hex 32`) in **Settings →
Secrets and variables → Actions**. Both `deploy.yml` and `deploy-dev.yml`
read the same one. Until this secret is set, the "Run database migrations"
step in both workflows is skipped entirely, and the manual phpMyAdmin path
above remains the only way migrations reach the deployed database.

## Layout

- `migrations/` — Ordered `.sql` files, one per schema change, applied in
  filename order.

## Schema overview

- **Accounts** (`0001`): `users`, `sessions`, `email_verifications`.
- **Password reset** (`0002`): `password_resets` — a single-use, expiring,
  hashed token tied to a user, consumed by
  `PasswordResetRepository::consumeValid()`'s select-then-delete.
- **Application/system** (`0003`): `schema_version` — see "Adding a new
  migration" above.
- **LLM usage** (`0004`): `chat_usage` — one row per `POST /chat` request
  (see "LLM usage (Fireworks AI)" in `php-app/README.md`), tied to the
  authenticated user who made it, recording token counts and computed USD
  cost whether the request succeeded or failed.
- **Households** (`0005`, issue #5): `households`, `household_members`
  (a user's membership + role in a household — a plain join table, so a
  user isn't limited to one household), and `household_invites` (pending
  invites; see "Household invites" in `php-app/README.md`).
- **Email invites** (`0006`, issue #33): widens `household_invites` —
  `invited_user_id` becomes nullable and a new `invited_email` column is
  added, so an invite can target an email address with no account yet
  instead of only an already-registered user.
- **Household settings, notes, and pets** (`0007`, issue #7):
  `household_notes` (private/public visibility) and `household_pets`. No
  new table for settings — v1 is just the household's own `name`, already
  a column from `0005`. `household_pets.vet_contact_id` is deliberately
  not included yet; see the migration's own comment and "Household
  settings, notes, and pets" in `php-app/README.md`.
- **Task/chore tracking** (`0008`, issue #12): `household_tasks`
  (one-off and recurring, assignable to a member) and
  `household_task_completions` (append-only history). `source_type`/
  `source_id` are reserved, unenforced columns for a future tracker (e.g.
  issues #8/#11) to link its own tasks into this same system instead of
  growing a bespoke table.
- **Task/chore instances** (`0009`, issue #12 follow-up): splits
  `household_tasks` into a pure definition (drops `status`/`next_due_at`,
  renames `next_due_at` to `start_date`, now `NOT NULL`) plus a new
  `household_task_instances` table, one row per concrete occurrence,
  replacing `household_task_completions` (dropped — each instance already
  carries its own completion state). A daily cron script populates
  upcoming instances from recurring definitions and purges old
  completed/expired ones — see "Task/chore tracking" in
  `php-app/README.md` for why, and the migration's own comment for how it
  backfills every existing task's *current* state (its individual
  historical completion events aren't individually replayed, only its
  latest one).

Whatever household-scoped tracker tables come next (finances, calendar,
whatever the application actually ends up tracking) belong here too, as
their own numbered migrations starting at `0010`.
