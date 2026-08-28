# php-app

Plain PHP REST API for HouseholdTracker, using PDO to talk to the MySQL
database defined in [`../database`](../database).

## Setup

```sh
composer install
cp .env.example .env   # then edit with your local MySQL credentials
```

Apply the database migrations (see [`../database`](../database) for
details):

```sh
composer migrate
```

then start the built-in dev server:

```sh
php -S localhost:8000 -t public
```

Visit `http://localhost:8000/health` to verify the app can connect to the
database.

## Layout

- `public/` — Web server document root / front controller.
- `src/` — Application source (PSR-4 autoloaded under `HouseholdTracker\`).
  - `Auth/` — Registration, login, session, and password reset logic
    (`AuthService`) plus its exceptions.
  - `Household/` — `HouseholdService` (creation, membership, invites —
    issue #5; settings, notes, and pets — issue #7) plus its exceptions;
    `TaskService`/`RecurrenceCalculator` (task/chore tracking — issue #12).
  - `Repository/` — Thin PDO data-access classes, one per table.
  - `Chat/` — LLM scaffolding (Fireworks AI) — `FireworksClient`,
    `ModelCatalog`/`CostCalculator` (per-model pricing), `ChatAgent` (the
    tool-calling loop), `Tools` (the OpenAI-style function-calling
    registry — currently empty; see "LLM usage (Fireworks AI)" below).
  - `Ledger/` — `Ledger`, per-user LLM usage/cost tracking.
  - `Database/` — `Connection` (a lazily-created PDO singleton) and
    `MigrationRunner`.
  - `Mail/` — `Mailer`, a thin PHPMailer/SMTP wrapper.
  - `Maintenance/` — `MaintenanceGate`, the deployed-`VERSION`-vs-
    `schema_version` check (see "Maintenance mode" below).
  - `Config.php` — Reads `.env` (falling back to real environment
    variables), the single source every other class reads settings from.
  - `SiteUrl.php` — Derives the static frontend's own domain root from
    `SITE_URL`/`APP_URL`.
- `bin/migrate.php` — Applies pending database migrations from
  `../database/migrations/` (see that project's README).
- `bin/generate_task_instances.php` — Daily-cron task/chore maintenance
  script — see "Task/chore tracking" below.
- `tests/` — PHPUnit tests.

## API

All responses are JSON with a `status` field (`ok` or `error`), except
`/verify-email` — that one's opened directly from an emailed link by a
human rather than called by our own JS, so it renders an HTML page
instead. Every route except `/health` and `/migrate` can also return `503`
with `{"status": "maintenance", "message"}` (or, for `/verify-email`, an
HTML maintenance page) — see "Maintenance mode" below.

| Method | Path                    | Body                                            | Notes |
| ------ | ----------------------- | ------------------------------------------------ | ----- |
| GET    | `/health`                | —                                                 | Checks DB connectivity. Exempt from maintenance mode. |
| POST   | `/migrate`                | —                                                 | Requires an `X-Migration-Key` header matching the `MIGRATION_DEPLOY_KEY` secret; `403` if missing/wrong or unconfigured. Applies pending `database/migrations/*.sql` files. Returns `{"applied": [string]}`. See `database/README.md`. |
| POST   | `/register`               | `{"username", "email", "password"}`               | Creates an unverified user and emails a verification link. Username: 3-32 chars (letters/numbers/`_`/`-`); email: valid format; password: 8-72 chars. `409` on duplicate username/email, `400` on validation failure, `502` if the verification email can't be sent (registration is rolled back so you can retry). |
| GET    | `/verify-email`           | query param `token`                               | HTML page (not JSON). On success, auto-redirects to `/` after 5 seconds. `400` with a link to `resend-verification.html` if the token is invalid/expired. |
| POST   | `/resend-verification`    | `{"email"}`                                       | Issues a fresh verification link and emails it. Always returns the same generic `200` regardless of account state, so it can't be used to discover which addresses are registered. Rate-limited to once per 60 seconds per account; `400` on invalid email, `502` if sending fails. |
| POST   | `/forgot-password`        | `{"email"}`                                       | Issues a password reset link (valid 1 hour) and emails it. Same enumeration-resistant `200` and rate limit as `/resend-verification`. The emailed link points at the static `reset-password.html` page, not a GET route — see "Password reset" below. |
| POST   | `/reset-password`         | `{"token", "password"}`                           | Consumes a single-use reset token and sets the new password (8-72 chars). Also deletes every one of the account's sessions. `400` if the token is invalid/expired/used or the password fails validation. |
| POST   | `/login`                  | `{"username", "password"}`                        | `401` on bad credentials, `403` if the email isn't verified yet. |
| POST   | `/logout`                 | —                                                  | Invalidates the current session only. |
| GET    | `/me`                     | —                                                  | Returns the current user if authenticated, `401` otherwise. |
| GET    | `/chat/models`            | —                                                  | Requires auth. Lists the model keys `POST /chat` accepts (`{"models": [string], "default_model": string}`) — see `ModelCatalog`. |
| POST   | `/chat`                   | `{"messages": [{"role","content"}, ...], "model"?}` | Requires auth. Runs `messages` through Fireworks (default model if `model` omitted), including any tool-calling round trips (see `Tools`). `400` if `messages` is missing/empty or `model` isn't a known key, `503` if `FIREWORKS_API_KEY` isn't configured, `402` if the Fireworks account balance is exhausted, `502` on any other upstream failure. Every attempt — success or failure — is recorded to the ledger (`Chat/README` below). Returns `{"reply", "messages", "usage", "cost_usd", "model"}`; `messages` is the full updated conversation, suitable for passing back in as the next request's `messages` to continue the thread. |
| GET    | `/chat/usage`             | —                                                  | Requires auth. The current user's own lifetime LLM usage: `{"usage": {"requestCount", "totalUsageUsd", "totalTokens", "lastUsedAt"}}`. |
| POST   | `/households`             | `{"name"}`                                        | Requires auth. Creates a household (1-100 chars) and makes the caller its `owner`. `400` on validation failure. Returns `{"household"}`. |
| GET    | `/households`             | —                                                  | Requires auth. Every household the caller belongs to, with their own role in each: `{"households": [{"id","name","created_at","role"}]}`. |
| GET    | `/households/members`     | query param `household_id`                        | Requires auth; `403` if the caller isn't a member of that household. Returns `{"members": [{"user_id","username","email","role","joined_at"}]}`. |
| POST   | `/households/invite`      | `{"household_id", "username_or_email"}`            | Requires auth; `403` if the caller isn't a member. Looks up the target by username, then email; if neither matches but the input is itself a valid email address, invites that address instead — the invite doubles as a registration link (see "Household invites" below). `404` if no account matches and the input isn't a valid email either, `409` if already a member/already has a pending invite (existing-user or email invite alike), `409` if inviting yourself, `502` if the invitation email can't be sent (rolled back so you can retry). |
| GET    | `/households/invites`     | —                                                  | Requires auth. The caller's own pending invites: `{"invites": [{"id","household_id","household_name","invited_by_user_id","invited_by_username","created_at"}]}`. |
| POST   | `/households/invites/respond` | `{"invite_id", "action": "accept"\|"decline"}` | Requires auth. `404` if there's no such pending invite addressed to the caller. Accepting adds them as a `member`. |
| POST   | `/households/members/remove` | `{"household_id", "user_id"}`                   | Requires auth; `404` if the caller isn't a member of that household, or `user_id` isn't either. `403` unless the caller is removing themselves (leaving) or is the household's `owner` removing someone else. |
| POST   | `/households/settings`    | `{"household_id", "name"}`                        | Requires auth; `403` if the caller isn't a member. Renames the household (1-100 chars, `400` otherwise) — see "Household settings, notes, and pets" below. Returns `{"household"}`. |
| GET    | `/households/notes`       | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every `public` note in the household plus the caller's own `private` ones — never another member's private notes. Returns `{"notes": [{"id","household_id","author_user_id","author_username","visibility","body","created_at","updated_at"}]}`. |
| POST   | `/households/notes`       | `{"household_id", "visibility": "private"\|"public", "body"}` | Requires auth; `403` if the caller isn't a member. `body`: 1-20,000 chars, `400` otherwise. Returns `{"note"}`. |
| POST   | `/households/notes/update` | `{"note_id", "visibility", "body"}`               | Requires auth. `404` if no such note; `403` unless the caller is the note's own author (public notes included — see below). |
| POST   | `/households/notes/delete` | `{"note_id"}`                                     | Requires auth. Same `404`/`403` rules as `/households/notes/update`. |
| GET    | `/households/pets`        | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every pet in the household — no privacy tiers, unlike notes. Returns `{"pets": [{"id","household_id","name","species","breed","birthday","notes","created_by_user_id","created_at","updated_at"}]}`. |
| POST   | `/households/pets`        | `{"household_id", "name", "species"?, "breed"?, "birthday"?, "notes"?}` | Requires auth; `403` if the caller isn't a member. `name`: 1-100 chars; `birthday`: `YYYY-MM-DD` if given; `notes`: ≤2000 chars. `400` on any validation failure. Returns `{"pet"}`. |
| POST   | `/households/pets/update` | `{"pet_id", "name", "species"?, "breed"?, "birthday"?, "notes"?}` | Requires auth. `404` if no such pet; `403` if the caller isn't a member of that pet's household. Any member may update it — see below. |
| POST   | `/households/pets/delete` | `{"pet_id"}`                                      | Requires auth. Same `404`/`403` rules as `/households/pets/update`. |
| GET    | `/households/tasks`       | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every *pending* task instance in the household (see "Task/chore tracking" below for why only pending). Returns `{"tasks": [{"id","task_id","household_id","title","description","assigned_to_user_id","assigned_to_username","recurrence_frequency","recurrence_interval","due_at","status","completed_at","completed_by_user_id","notes","created_at","completion_count","last_completed_at"}]}` — `id` is the *instance's* id (what every other `/households/tasks/*` route below takes as `instance_id`), `task_id` its parent definition's. |
| POST   | `/households/tasks`       | `{"household_id", "title", "description"?, "assigned_to_user_id"?, "recurrence_frequency"?, "recurrence_interval"?, "due_at"?}` | Requires auth; `403` if the caller isn't a member. `title`: 1-150 chars; `assigned_to_user_id`, if given, must be a member of the household; `recurrence_frequency` (`daily`\|`weekly`\|`monthly`\|`annual`) pairs with `recurrence_interval` (default `1`) — omit both for a one-off task. `due_at` defaults to today if omitted. `400` on any validation failure. Creates the definition *and* its first instance in one call. Returns `{"task"}` (same joined shape as the list above). |
| POST   | `/households/tasks/update` | `{"instance_id", "title", "description"?, "assigned_to_user_id"?, "recurrence_frequency"?, "recurrence_interval"?, "due_at"?}` | Requires auth. `404` if no such instance; `403` if the caller isn't a member of its household. Updates the parent definition's title/description/assignee/recurrence *and* moves this specific instance's own due date — see "Task/chore tracking" below for why editing doesn't touch the definition's `start_date` or any other instance. Any member may update any task. |
| POST   | `/households/tasks/delete` | `{"instance_id"}`                                | Requires auth. Same `404`/`403` rules as update. For a recurring task, deletes just this instance (skip this occurrence). For a one-off task (which only ever has one instance), deletes the whole definition too, rather than leaving an orphan behind. |
| POST   | `/households/tasks/complete` | `{"instance_id", "notes"?}`                    | Requires auth. Same `404`/`403` rules as update. Marks this instance `done` (`notes`: ≤2000 chars) — nothing else happens here; a recurring task's *next* occurrence is a separate row already generated (or waiting to be) by the daily cron script, not something completing this one creates on the spot. |
| GET    | `/tasks/mine`             | —                                                  | Requires auth. Every pending task instance assigned to the caller across *every* household they belong to (the "My Tasks" view), not scoped to one household — same response shape as `/households/tasks` (plus `household_name`, no `assigned_to_username` since it's always the caller). Completing one of these still goes through `/households/tasks/complete` above. |

Auth-requiring routes use the `session_token` cookie set by `/login`/`/me`
(`401` if missing/invalid) — see `requireAuth()` in `public/index.php`.
Whatever household-scoped tracker routes come next belong below
`/tasks/mine` in `public/index.php`, each guarded by the
same `requireAuth($auth)` call plus a household-membership check the way
`/households/members` already is.

## Maintenance mode

Every route except `/health`, `/migrate`, and `/verify-email` (which
checks the gate itself and renders an HTML maintenance page instead) is
preceded by a `MaintenanceGate::activeMessage()` check: if the deployed
`VERSION` file doesn't match the `schema_version` row in the database, the
request gets a `503` with `{"status": "maintenance", "message"}` instead
of running against a schema a migration hasn't been applied to yet. See
"Versioning" in the top-level README and "Adding a new migration" in
`database/README.md`.

## Password reset

`/forgot-password` emails a link to the *static* `reset-password.html`
page (not a GET API route) so that corporate email-security scanners that
pre-fetch links in inbound mail can't silently burn the single-use token
before the real user opens it — the token is only submitted (and
consumed) when the visitor actually chooses a new password, via
`POST /reset-password`. `/verify-email`, in contrast, safely consumes its
token on a bare GET, since a verification link being opened twice (once
by a scanner, once by the human) is harmless either way.

## Household invites

A user may belong to any number of households — `household_members` is a
plain join table (household + user + role), not a column on `users`, so
nothing forces "one household per user." Inviting first looks for an
already-registered account by username then email, the same order
`AuthService::register()`'s own duplicate checks already use.

**Inviting an email with no account yet** (issue #33): if neither lookup
matches but the input is a valid email address, `household_invites` gets a
row with `invited_email` set and no `invited_user_id` yet, and a distinct
invitation email goes out (`Mailer::sendHouseholdInviteEmail()`) linking to
`register.html` (optionally prefilling the email field via `?email=...` —
convenience only, no security-bearing token in the link). No separate
invite-specific token is needed: `AuthService`'s existing registration flow
already proves the recipient controls that mailbox, via `email_verifications`.
So the moment a *new* account verifies that exact email
(`HouseholdService::linkPendingInvitesForEmail()`, called from the
`/verify-email` route right after `AuthService::verifyEmail()` succeeds),
every pending `invited_email`-only invite addressed to it gets its
`invited_user_id` set and `invited_email` cleared — becoming an ordinary
existing-user invite with no separate acceptance path of its own; it just
shows up through the same `GET /households/invites`/
`POST /households/invites/respond` flow as any other invite, and isn't
auto-accepted. A failed invitation-email send rolls the invite row back
(`HouseholdService::cancelInvite()`), the same pattern `/register` already
uses for a failed verification email.

Any member can invite someone else or remove themselves (leave); removing
a *different* member requires being the household's `owner` — see
`HouseholdService::removeMember()`. There's no ownership-transfer story
yet (tracked in issue #17's own broader roles/permissions work), so for
now an owner can also leave their own household unchallenged, same as any
member.

## Household settings, notes, and pets

Three small pieces of household-scoped data (issue #7), each requiring the
caller to already be a member of the household in question:

- **Settings** — v1 is just the household's own name; there's no dedicated
  `household_settings` table yet, `HouseholdService::updateSettings()`
  updates the `households.name` column directly (added a separate table
  only once a second setting actually needs one). Any member can rename
  the household, not just the `owner`.
- **Notes** (`household_notes`) — two visibility tiers, `private` (only the
  author) and `public` (every member). `GET /households/notes` filters at
  the SQL level (`visibility = 'public' OR author_user_id = :caller_id`),
  so a private note is never returned to anyone but its author, including
  via a direct note id. A note, public or private, can only be edited or
  deleted by its own author — resolving one of issue #7's own open
  questions the same way for both tiers rather than letting any member
  edit a public one.
- **Pets** (`household_pets`) — unlike notes, no privacy tiers: every
  member sees the full pet list, and any member can add, edit, or remove a
  pet (a shared household resource, not a per-user one, same permission
  model as settings). `vet_contact_id` is deliberately not a column yet —
  issue #16 (household contacts) hasn't shipped, so there's nothing for it
  to reference; add it via a follow-up migration once #16 lands rather
  than shipping a nullable FK to a table that doesn't exist.

## Task/chore tracking

One-off tasks and recurring chores (issue #12), assignable to any household
member (or left unassigned). Tasks are a shared household resource, not
per-user content, same permission model as pets: any member can create/edit/
delete/complete any task, not just its creator or assignee.

**Definition + instances** (`household_tasks` + `household_task_instances`,
issue #12's own follow-up — see the `0009` migration's comment for the full
reasoning): `household_tasks` is a pure *definition*, a recurring rule or a
one-off, with no due date or status of its own — those live on
`household_task_instances`, one row per concrete occurrence. The first
version of this feature kept a single mutable row per task (completing it
advanced its one `next_due_at` in place), which meant an unaddressed
recurring chore just sat there, increasingly overdue, forever — nothing ever
created a *new* occurrence on its own. With instances as their own rows, a
daily cron script can proactively populate the next several days of
occurrences for every recurring definition, so falling behind on a chore
shows up in the household Tasks tab as a real backlog of
individually-completable rows (one per missed occurrence) instead of one
stuck, increasingly-overdue row.

**Recurrence**: `recurrence_frequency` (`daily`/`weekly`/`monthly`/`annual`)
plus a `recurrence_interval` multiplier covers "every N days/weeks/months/
years" without a separate `custom` bucket — "every 15 days" is just `daily`
with `recurrence_interval = 15`.

**`bin/generate_task_instances.php`** (run once a day via cron — see "Cron
setup" below): for every recurring definition, advances from its latest
existing instance (via `RecurrenceCalculator::advance()` — calendar-correct
for `monthly`/`annual`, handling month-end dates and leap years rather than
a naive `+30 days`) and inserts new pending instances up to `LOOKAHEAD_DAYS`
(7) ahead, looping to catch up on any gap since the last run rather than
just generating one. Idempotent — the `(task_id, due_at)` unique constraint
means running it twice in a row, or after missing a day, never double-books
an occurrence. Its second half purges old instances past `RETENTION_DAYS`
(90) — completed ones (pure history by that point) and pending ones nobody
ever completed (so an abandoned chore doesn't clutter the list forever) —
and then deletes any one-off definition left with zero instances after that
(a backstop; a one-off's definition and instance are normally deleted
together, see `/households/tasks/delete` above).

**Completing an instance** (`POST /households/tasks/complete`) just marks
that one row `done` — nothing else happens on the spot. A recurring task's
*next* occurrence is a separate row, already generated (or waiting to be, on
the next cron run) rather than something completion creates synchronously;
this was an explicit open question in issue #12, resolved this way (rather
than advancing from whenever it actually got done) so a chore's schedule
stays anchored to its original cadence — trash day stays Monday — instead of
drifting later after an occasional late completion. One documented
consequence: `RecurrenceCalculator` clamps from whatever the *latest*
instance's due date currently is, not a remembered original day-of-month, so
a "31st of every month" task that's clamped to Feb 28 once stays on the 28th
from then on rather than springing back to the 31st in a later longer month
— see the class's own docblock.

**Editing** (`POST /households/tasks/update`) updates the parent
definition's title/description/assignee/recurrence *and* moves the specific
instance being edited to a new due date — but doesn't touch the
definition's `start_date` or any other instance. `start_date` is only ever
read once, the moment a task has zero instances (shouldn't normally happen);
otherwise cron always advances from whatever the latest instance's due date
actually is, so a manual edit's new date naturally becomes the anchor the
*next* generated occurrence advances from.

**"My Tasks" (`GET /tasks/mine`)**: a cross-household view — every pending
instance assigned to the caller, across every household they belong to,
ordered by due date the same way as a single household's list.
`HouseholdTaskInstanceRepository::listAssignedToUser()`'s query joins back
to `household_members` (matching both the household and the assignee) so a
task doesn't keep showing up here after its assignee has since left that
household — removing a member doesn't clear `assigned_to_user_id` on their
existing tasks, so without that join a stale assignment would otherwise
linger forever.

**Not yet wired up**: `source_type`/`source_id` are reserved, unenforced
columns for a future meeting (issue #8) or home-improvement project (issue
#11) to link its own tasks into this same system, per issue #12's
consolidation recommendation — nothing sets them yet.

### Cron setup

Bluehost's shared hosting runs cron jobs directly on the same box the app is
deployed to (unlike the deploy pipeline's own migration step, which needs an
HTTP round trip since the GitHub Actions runner can't reach the database
directly) — so this is a plain CLI script, invoked with no network
indirection, set up once per environment via cPanel's own **Cron Jobs**
page:

- **Command**: `php /home/<cpanel-user>/<site-directory>/bin/generate_task_instances.php >> /home/<cpanel-user>/logs/task-instances.log 2>&1`
  (exact paths depend on the domain's document root — the same one
  `DEV_FTP_SERVER_DIR`/production's server-dir point at; `bin/` deploys as a
  sibling of `app/`, `src/`, `vendor/`, denied to web requests via its own
  `.htaccess`, the same as those).
- **Schedule**: once daily (e.g. `0 6 * * *` for 6am server time) is enough
  given `LOOKAHEAD_DAYS`/`RETENTION_DAYS` above; running it more often is
  harmless (idempotent) but pointless.
- Set this up separately for the dev and production domains, same as the
  `MIGRATION_DEPLOY_KEY` one-time setup in `database/README.md` — cPanel
  cron entries aren't part of the repo or the deploy workflow, so a fresh
  environment needs this configured by hand once.

## LLM usage (Fireworks AI)

`POST /chat` runs a conversation through [Fireworks AI](https://fireworks.ai)'s
OpenAI-compatible chat completions API, including a tool-calling loop
(`ChatAgent`) — the scaffold for letting an LLM call into whatever
household-tracking domain logic this app ends up with (e.g. "what's on
this week's chore list?"). `src/Chat/Tools.php` is currently an empty
registry (`definitions()` returns `[]`), so `/chat` runs as a plain chat
model until real tools are added there — see that file's own docblock for
the pattern (mirrors `Tools::call()`/`Tools::definitions()` in the
[MeadBotAPI](https://github.com/jceddy/MeadBotAPI) project this scaffold
was adapted from).

**Setup:** get an API key from your [Fireworks AI account](https://fireworks.ai)
and set it as `FIREWORKS_API_KEY` (locally, in `.env`; deployed, as a
repository secret — see "Repository variables/secrets checklist" in the
top-level README). Without it, `/chat` returns `503`.

**Model catalog:** `src/Chat/ModelCatalog.php` ships with a single
placeholder model (`'default'` → `llama-v3p1-8b-instruct`, at made-up
pricing) — replace it with the actual Fireworks-hosted model(s) this app
should offer and their current published per-1M-token rates from
[fireworks.ai/pricing](https://fireworks.ai/pricing) before relying on
this in production. Each model's pricing is a list of dated tiers (see
the class's own docblock) so a future rate change can be pre-populated
ahead of time and takes effect automatically, without a same-day deploy.

**Usage tracking:** every `/chat` request — success or failure — is
recorded to the `chat_usage` table (`Ledger::recordChatUsage()`,
`database/migrations/0004_add_chat_usage.sql`) with its token counts and
computed USD cost, tied to the authenticated caller. Recording is
best-effort and never fails the request itself. `GET /chat/usage` lets a
user see their own lifetime totals; there's no cross-user/admin view yet
(HouseholdTracker has no admin-role concept to gate one behind) — add one
alongside whatever role system this app eventually needs.

**Cost:** Fireworks bills per API call regardless of whether the overall
`/chat` request ultimately succeeds (e.g. a later call in a multi-tool-call
round trip fails, or the iteration cap in `ChatAgent::MAX_TOOL_ITERATIONS`
is hit) — `ChatUsageException` always carries whatever usage was
accumulated before the failure, and that's what gets recorded/billed to
the ledger even on an error response.
