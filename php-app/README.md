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
    (`AuthService`), account profile/password/deletion management (issue
    #18) and data export (`AccountExportService`, issue #21), plus their
    exceptions.
  - `Household/` — `HouseholdService` (creation, membership, invites —
    issue #5; settings, notes, and pets — issue #7; the shopping list —
    issue #24; staples — issue #66; roles and permissions — issue #17;
    the calendar — issue #13; contacts — issue #16)
    plus its exceptions; `TaskService`/`RecurrenceCalculator`
    (task/chore tracking — issue #12); `HomeImprovementService` (projects
    and maintenance — issue #11); `HouseholdMeetingService` (meetings —
    issue #8).
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
| GET    | `/me`                     | —                                                  | Returns the current user if authenticated, `401` otherwise. Includes `pending_email` (see "Account management" below) — `null` unless an email change is awaiting verification. |
| POST   | `/account/update`         | `{"username"?, "email"?}`                          | Requires auth. Either field may be omitted to leave it alone; a value equal to the caller's current one is a no-op. `username`: same 3-32 char rules as `/register`, `409` if taken. `email`: same valid-format rule, but doesn't take effect immediately — stashed as `pending_email` and emailed a verification link (same flow as `/register`'s), `409` if another account already has it. `400` on validation failure. Returns `{"user"}` (with the new `pending_email` if an email change was requested), `502` if the verification email fails to send (the username half, if any, is not rolled back). See "Account management" below. |
| POST   | `/account/change-password` | `{"current_password", "new_password"}`            | Requires auth. `401` if `current_password` is wrong; `400` if `new_password` fails the same 8-72 char rule as `/register`. Deletes every one of the account's sessions, current one included, same as `/reset-password` — the frontend sends the user back to the login page. |
| POST   | `/account/delete`         | `{"password"}`                                     | Requires auth. `401` if `password` is wrong. Deletes the account; see "Account management" below for what that cascades into. |
| GET    | `/account/export`         | —                                                  | Requires auth. Everything the caller themselves has access to, as one JSON document — see "Data export" below for exactly what's included. Returns `{"export": {...}}`. |
| GET    | `/chat/models`            | —                                                  | Requires auth. Lists the model keys `POST /chat` accepts (`{"models": [string], "default_model": string}`) — see `ModelCatalog`. |
| POST   | `/chat`                   | `{"messages": [{"role","content"}, ...], "model"?}` | Requires auth. Runs `messages` through Fireworks (default model if `model` omitted), including any tool-calling round trips (see `Tools`). `400` if `messages` is missing/empty or `model` isn't a known key, `503` if `FIREWORKS_API_KEY` isn't configured, `402` if the Fireworks account balance is exhausted, `502` on any other upstream failure. Every attempt — success or failure — is recorded to the ledger (`Chat/README` below). Returns `{"reply", "messages", "usage", "cost_usd", "model"}`; `messages` is the full updated conversation, suitable for passing back in as the next request's `messages` to continue the thread. |
| GET    | `/chat/usage`             | —                                                  | Requires auth. The current user's own lifetime LLM usage: `{"usage": {"requestCount", "totalUsageUsd", "totalTokens", "lastUsedAt"}}`. |
| POST   | `/households`             | `{"name"}`                                        | Requires auth. Creates a household (1-100 chars) and makes the caller its `owner`. `400` on validation failure. Returns `{"household"}`. |
| GET    | `/households`             | —                                                  | Requires auth. Every household the caller belongs to, with their own role in each: `{"households": [{"id","name","created_at","role"}]}`. |
| GET    | `/households/members`     | query param `household_id`                        | Requires auth; `403` if the caller isn't a member of that household. Returns `{"members": [{"user_id","username","email","role","joined_at"}]}`. |
| POST   | `/households/invite`      | `{"household_id", "username_or_email"}`            | Requires auth; `403` if the caller isn't a member. Looks up the target by username, then email; if neither matches but the input is itself a valid email address, invites that address instead — the invite doubles as a registration link (see "Household invites" below). `404` if no account matches and the input isn't a valid email either, `409` if already a member/already has a pending invite (existing-user or email invite alike), `409` if inviting yourself, `502` if the invitation email can't be sent (rolled back so you can retry). |
| GET    | `/households/invites`     | —                                                  | Requires auth. The caller's own pending invites: `{"invites": [{"id","household_id","household_name","invited_by_user_id","invited_by_username","created_at"}]}`. |
| POST   | `/households/invites/respond` | `{"invite_id", "action": "accept"\|"decline"}` | Requires auth. `404` if there's no such pending invite addressed to the caller. Accepting adds them as a `member`. |
| POST   | `/households/members/remove` | `{"household_id", "user_id"}`                   | Requires auth; `404` if the caller isn't a member of that household, or `user_id` isn't either. `403` unless the caller is removing themselves (leaving) or is the household's `owner` removing someone else — see "Household roles and permissions" below. |
| POST   | `/households/settings`    | `{"household_id", "name"}`                        | Requires auth; `403` if the caller isn't a member — any member, not just the owner, see "Household roles and permissions" below. Renames the household (1-100 chars, `400` otherwise) — see "Household settings, notes, and pets" below. Returns `{"household"}`. |
| POST   | `/households/delete`      | `{"household_id"}`                                | Requires auth; `403` if the caller isn't a member, or is a member but not the `owner`. Deletes the household outright — every household-scoped table's own `household_id` foreign key cascades from it (see `database/README.md`'s schema overview), so there's nothing else to clean up. See "Household roles and permissions" below. |
| GET    | `/households/notes`       | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every `public` note in the household plus the caller's own `private` ones — never another member's private notes. Returns `{"notes": [{"id","household_id","author_user_id","author_username","visibility","body","created_at","updated_at"}]}`. |
| POST   | `/households/notes`       | `{"household_id", "visibility": "private"\|"public", "body"}` | Requires auth; `403` if the caller isn't a member. `body`: 1-20,000 chars, `400` otherwise. Returns `{"note"}`. |
| POST   | `/households/notes/update` | `{"note_id", "visibility", "body"}`               | Requires auth. `404` if no such note; `403` unless the caller is the note's own author (public notes included — see below). |
| POST   | `/households/notes/delete` | `{"note_id"}`                                     | Requires auth. Same `404`/`403` rules as `/households/notes/update`. |
| GET    | `/households/contacts`    | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every contact in the household — no privacy tiers, same as pets. Returns `{"contacts": [{"id","household_id","name","category","phones","emails","address","notes","created_by_user_id","created_at","updated_at"}]}` — `phones`/`emails` are each `[{"label": "home"\|"mobile"\|"work", "phone"\|"email": string}, ...]`, any number including zero. |
| POST   | `/households/contacts`    | `{"household_id", "name", "category"?, "phones"?: [{"label", "phone"}], "emails"?: [{"label", "email"}], "address"?, "notes"?}` | Requires auth; `403` if the caller isn't a member. `name`: 1-150 chars; `category` (free text, like the shopping list's): ≤50 chars; each `phones[].label`/`emails[].label` must be `"home"`, `"mobile"`, or `"work"`; each `phone`: 1-50 chars; each `email`: 1-255 chars and a valid address; `address` (multi-line, e.g. street/city/state/zip on separate lines): ≤500 chars; `notes`: ≤2000 chars. `400` on any validation failure. Returns `{"contact"}`. |
| POST   | `/households/contacts/update` | `{"contact_id", "name", "category"?, "phones"?, "emails"?, "address"?, "notes"?}` | Requires auth. `404` if no such contact; `403` if the caller isn't a member of that contact's household. Any member may update it — see below. Same validation as create; `phones`/`emails`, if given, wholesale-replace the contact's existing set rather than merging with it. |
| POST   | `/households/contacts/delete` | `{"contact_id"}`                              | Requires auth. Same `404`/`403` rules as `/households/contacts/update`. |
| GET    | `/households/pets`        | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every pet in the household — no privacy tiers, unlike notes. Returns `{"pets": [{"id","household_id","name","species","breed","birthday","notes","vet_contact_id","created_by_user_id","created_at","updated_at"}]}`. |
| POST   | `/households/pets`        | `{"household_id", "name", "species"?, "breed"?, "birthday"?, "notes"?, "vet_contact_id"?}` | Requires auth; `403` if the caller isn't a member. `name`: 1-100 chars; `birthday`: `YYYY-MM-DD` if given; `notes`: ≤2000 chars; `vet_contact_id`, if given, must be a contact (see `/households/contacts` above) in the same household. `400` on any validation failure. Returns `{"pet"}`. |
| POST   | `/households/pets/update` | `{"pet_id", "name", "species"?, "breed"?, "birthday"?, "notes"?, "vet_contact_id"?}` | Requires auth. `404` if no such pet; `403` if the caller isn't a member of that pet's household. Any member may update it — see below. |
| POST   | `/households/pets/delete` | `{"pet_id"}`                                      | Requires auth. Same `404`/`403` rules as `/households/pets/update`. |
| GET    | `/households/shopping-list` | query param `household_id`                       | Requires auth; `403` if the caller isn't a member. Returns `{"needed": [...], "recently_purchased": [...]}` — items nobody's bought yet (oldest-added first) and the most recent 25 bought (newest first), same joined row shape for both: `{"id","household_id","name","quantity","category","added_by_user_id","purchased_at","purchased_by_user_id","created_at"}`. |
| POST   | `/households/shopping-list` | `{"household_id", "name", "quantity"?, "category"?}` | Requires auth; `403` if the caller isn't a member. `name`: 1-150 chars; `quantity`/`category` (free text, e.g. `"2 lbs"`/`"Produce"` — no fixed list): ≤50 chars each. `400` on any validation failure. Returns `{"item"}`. |
| POST   | `/households/shopping-list/purchase` | `{"item_id"}`                        | Requires auth. `404` if no such item; `403` if the caller isn't a member of its household. Any member may mark any item purchased — a shared household resource, not a per-user one, same as pets. Returns `{"item"}`. |
| POST   | `/households/shopping-list/unpurchase` | `{"item_id"}`                      | Requires auth. Same `404`/`403` rules as `/purchase`. The undo side of it — puts the item back to needed (clearing `purchased_at`/`purchased_by_user_id`), for a misclick rather than delete-and-re-add. Returns `{"item"}`. |
| POST   | `/households/shopping-list/delete` | `{"item_id"}`                            | Requires auth. Same `404`/`403` rules as `/purchase`. |
| GET    | `/households/staples`     | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every staple in the household, flagged-as-needing-restock first then alphabetical. Returns `{"staples": [{"id","household_id","name","category","needs_restock","flagged_by_user_id","flagged_at","created_at"}]}`. |
| POST   | `/households/staples`     | `{"household_id", "name", "category"?}`            | Requires auth; `403` if the caller isn't a member. `name`: 1-150 chars; `category` (free text, like the shopping list's): ≤50 chars. `400` on any validation failure. Returns `{"item"}`. |
| POST   | `/households/staples/flag` | `{"item_id"}`                                     | Requires auth. `404` if no such staple; `403` if the caller isn't a member of its household. Any member may flag any staple — a shared household resource, same as pets/the shopping list. Returns `{"item"}`. |
| POST   | `/households/staples/unflag` | `{"item_id"}`                                    | Requires auth. Same `404`/`403` rules as `/flag`. Clears `needs_restock`/`flagged_by_user_id`/`flagged_at`. Returns `{"item"}`. |
| POST   | `/households/staples/delete` | `{"item_id"}`                                    | Requires auth. Same `404`/`403` rules as `/flag`. |
| POST   | `/households/staples/add-to-shopping-list` | `{"household_id"}`                | Requires auth; `403` if the caller isn't a member. Creates a shopping-list item for every currently-flagged staple in the household and clears their flags. Returns `{"items": [...]}` (the newly-created shopping items, possibly empty). |
| GET    | `/households/calendar`    | query params `household_id`, `from`, `to`           | Requires auth; `403` if the caller isn't a member. `from`/`to` are any `strtotime()`-parseable bound (`400` if either fails to parse) — every event overlapping that range. No single-event-by-id GET route exists at all; this list endpoint (with its own server-side redaction — see "Household calendar" below) is the only read path. Returns `{"events": [...]}`, each either a full row (`{"id","household_id","created_by_user_id","created_by_username","title","description","starts_at","ends_at","location","responsible_user_id","responsible_username","visibility","created_at","updated_at"}`) or, for a `busy` event of another member's, a redacted `{"id","household_id","starts_at","ends_at","visibility"}` with no other keys present. |
| POST   | `/households/calendar`    | `{"household_id", "title", "description"?, "starts_at", "ends_at", "location"?, "responsible_user_id"?, "visibility": "private"\|"busy"\|"public"}` | Requires auth; `403` if the caller isn't a member. `title`: 1-150 chars; `description`/`location`: ≤2000/≤255 chars; `starts_at`/`ends_at`: any `strtotime()`-parseable value, normalized to `Y-m-d H:i:s`, `400` if either fails to parse or `ends_at` isn't after `starts_at`; `responsible_user_id`, if given, must be a member of the household. `400` on any validation failure. Returns `{"event"}` (unredacted, the caller's own). |
| POST   | `/households/calendar/update` | `{"event_id", "title", "description"?, "starts_at", "ends_at", "location"?, "responsible_user_id"?, "visibility"}` | Requires auth. `404` if no such event; `403` if the caller isn't a member of its household, or is but didn't create the event — only the creator may edit it, same as notes. Same validation as create. Returns `{"event"}`. |
| POST   | `/households/calendar/delete` | `{"event_id"}`                                | Requires auth. Same `404`/`403` rules as update. |
| GET    | `/households/tasks`       | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. One row per task in the household (per assignee, for an `"everyone"`-mode task's concurrent copies) — the single soonest-due *pending* instance, not every instance cron may have generated (see "Task/chore tracking" below). Returns `{"tasks": [{"id","task_id","household_id","title","description","assignment_mode","priority","assigned_to_user_id","assigned_to_username","assignees","recurrence_frequency","recurrence_interval","due_at","status","completed_at","completed_by_user_id","notes","created_at","completion_count","last_completed_at"}]}` — `id` is the *instance's* id (what every other `/households/tasks/*` route below takes as `instance_id`), `task_id` its parent definition's; `assigned_to_user_id`/`assigned_to_username` are *this instance's own* assignee (only ever set for one of an `'everyone'`-mode task's per-assignee copies, see "Task/chore tracking" below), `assignees` is the full `[{"id","username"}, ...]` list for the parent task regardless of mode; `due_at` is `null` for an open-ended task (see "Open-ended tasks" below), ordered ahead of every dated instance, highest `priority` first. |
| GET    | `/households/tasks/finished` | query param `household_id`                      | Requires auth; `403` if the caller isn't a member. Every instance resolved *today* in the household, completed or skipped alike, newest first — the household Tasks tab's "Show finished today" list, the counterpart to `GET /households/tasks` above (which drops a resolved instance the moment it's no longer pending). Same joined row shape, plus `completed_by_username` (who resolved it — set for both `"done"` and `"skipped"`). |
| POST   | `/households/tasks`       | `{"household_id", "title", "description"?, "notes"?, "assigned_to_user_ids"?: [int], "assignment_mode"?: "anyone"\|"everyone", "recurrence_frequency"?, "recurrence_interval"?, "due_at"?, "priority"?: "low"\|"medium"\|"high"\|"critical", "source_type"?: "home_improvement_project"\|"maintenance"\|"meeting", "source_id"?}` | Requires auth; `403` if the caller isn't a member. `title`: 1-150 chars; `notes` (≤2000 chars, like `description`) seeds the created instance's own notes — general notes on this occurrence, not tied to completing/skipping it (see "Notes on a task" below); every id in `assigned_to_user_ids` must be a member of the household; `assignment_mode` defaults to `"anyone"` and must be `"everyone"` only with at least one assignee (`400` otherwise); `recurrence_frequency` (`daily`\|`weekly`\|`monthly`\|`annual`) pairs with `recurrence_interval` (default `1`) — omit both for a one-off task. `due_at`, if given, must be `YYYY-MM-DD` (`400` otherwise); omitted for a *recurring* task it defaults to today (still needs a real anchor date), omitted for a *one-off* task it's left `null` — an open-ended task with no deadline (see "Open-ended tasks" below). `priority` only really matters for an open-ended task (defaults to `"medium"` there if not given) — stored as given otherwise, `400` if not one of the four values. `source_type`/`source_id` tag this task as a home improvement project's own task (`source_type = "home_improvement_project"`, `source_id` a real project in this same household — `404` otherwise), a maintenance item (`source_type = "maintenance"`, `source_id` omitted, and `recurrence_frequency` required — `400` otherwise, issue #11's own follow-up), or a meeting's own action item (`source_type = "meeting"`, `source_id` a real meeting in this same household — `404` otherwise, issue #8); omit both for an ordinary task. See "Home improvement projects and maintenance" and "Household meetings" below. `400` on any other validation failure. Creates the definition *and* its first instance(s) in one call — one shared instance for `"anyone"` mode, one per assignee for `"everyone"` mode (all sharing the same initial `notes`, if given). Returns `{"tasks": [...]}` (an *array*, since `"everyone"` mode can create more than one instance — each in the same joined shape as the list above). |
| POST   | `/households/tasks/update` | `{"instance_id", "title", "description"?, "notes"?, "assigned_to_user_ids"?: [int], "assignment_mode"?: "anyone"\|"everyone", "recurrence_frequency"?, "recurrence_interval"?, "due_at"?, "priority"?: "low"\|"medium"\|"high"\|"critical"}` | Requires auth. `404` if no such instance; `403` if the caller isn't a member of its household. Updates the parent definition's title/description/assignees/mode/priority/recurrence *and* moves this specific instance's own due date (or clears it, per the same `due_at` rules as create above) — see "Task/chore tracking" below for why editing doesn't touch the definition's `start_date`, any other instance, or retroactively create/delete instances for an assignee added/removed by this call. `notes` sets this instance's own notes directly — unlike `/complete`'s `notes` below, omitting or blanking it here *clears* it (an explicit edit, not a "didn't say anything this time" default). Any member may update any task. Returns `{"task"}` (single row, unlike the create route above). |
| POST   | `/households/tasks/delete` | `{"instance_id"}`                                | Requires auth. Same `404`/`403` rules as update. For a recurring task, removes just this instance outright, with no record left behind — use `/households/tasks/skip` below instead if it's worth keeping a reason on file. For a one-off task, deletes the instance and then, only once that leaves the definition with zero remaining instances, the definition too — covers both a single-assignee one-off (its one instance) and an `"everyone"`-mode one-off (each assignee's own copy needs deleting first) without leaving an orphaned definition behind. |
| POST   | `/households/tasks/complete` | `{"instance_id", "notes"?}`                    | Requires auth. Same `404`/`403` rules as update. Marks this instance `done` (`notes`: ≤2000 chars) — nothing else happens here; a recurring task's *next* occurrence is a separate row already generated (or waiting to be) by the daily cron script, not something completing this one creates on the spot. Unlike `/update`, an omitted `notes` here *preserves* whatever note the instance already had rather than clearing it — completing is usually just a click, and shouldn't silently erase a note written while it was still pending; giving one explicitly still overwrites. In `"everyone"` mode this only completes *this assignee's own copy* — the others' instances are untouched, unlike `"anyone"` mode where any one of them completing the single shared instance finishes it for all. |
| POST   | `/households/tasks/uncomplete` | `{"instance_id"}`                            | Requires auth. Same `404`/`403` rules as update, plus `400` if the instance isn't currently `"done"`. The undo side of `/complete` above — puts the instance back to `"pending"` and clears `completed_at`/`completed_by_user_id` (`notes` untouched). Backs the web UI's 5-second "Undo" toast after clicking Complete; not a general "reopen a finished task" route, and doesn't work on a `"skipped"` instance (see "Un-completing an instance" below). |
| POST   | `/households/tasks/skip` | `{"instance_id", "notes"}`                    | Requires auth. Same `404`/`403` rules as update, plus `400` if the instance's task isn't recurring (skip a one-off with `/households/tasks/delete` instead) or `notes` is empty/whitespace-only after trimming (required here, unlike `/complete`'s optional one — ≤2000 chars). Marks this instance `skipped` with the given note — "this occurrence isn't happening, and here's why" ("didn't walk the dog — there was a tornado"), distinct from completing it (it happened) or deleting it (no record at all). Same per-assignee semantics as `/complete` in `"everyone"` mode. |
| GET    | `/tasks/mine`             | —                                                  | Requires auth. Every pending task instance that's this user's own to act on across *every* household they belong to (the "My Tasks" view), not scoped to one household — either a shared `"anyone"`-mode instance for a task they're one of the assignees on, or their own personal `"everyone"`-mode copy. Same response shape as `/households/tasks` (plus `household_name`). Completing one of these still goes through `/households/tasks/complete` above. |
| GET    | `/households/projects`    | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every home improvement project in the household, active statuses first. Returns `{"projects": [{"id","household_id","title","description","status","estimated_cost","actual_cost","target_date","created_by_user_id","created_at","updated_at"}]}`. |
| GET    | `/households/projects/detail` | query param `project_id`                       | Requires auth. `404` if no such project; `403` if the caller isn't a member of its household. Returns `{"project", "tasks": [...]}` — `tasks` is that project's own linked tasks (`source_type = "home_improvement_project"`, `source_id` this project), same joined shape and soonest-due-pending-only collapsing as `GET /households/tasks`. |
| POST   | `/households/projects`    | `{"household_id", "title", "description"?, "status"?, "estimated_cost"?, "target_date"?}` | Requires auth; `403` if the caller isn't a member. `title`: 1-150 chars; `status` defaults to `"idea"`, must be one of `"idea"`/`"planned"`/`"in_progress"`/`"completed"`/`"abandoned"`; `estimated_cost`, if given, a non-negative number; `target_date`, if given, `YYYY-MM-DD`. `400` on any validation failure. Returns `{"project"}`. |
| POST   | `/households/projects/update` | `{"project_id", "title", "description"?, "status"?, "estimated_cost"?, "actual_cost"?, "target_date"?}` | Requires auth. `404` if no such project; `403` if the caller isn't a member. Same validation as create, plus `actual_cost` (absent from create — nothing to report before a project exists). Full replace, same as `/households/tasks/update` — an omitted field really does clear it. Returns `{"project"}`. |
| POST   | `/households/projects/delete` | `{"project_id"}`                                | Requires auth. Same `404`/`403` rules as update. Deletes the project only — its linked tasks are *not* cascade-deleted, they remain as ordinary tasks (see "Home improvement projects and maintenance" below). |
| GET    | `/households/maintenance` | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every recurring task tagged `source_type = "maintenance"` in the household, same joined shape and soonest-due-pending-only collapsing as `GET /households/tasks` — a second, filtered view onto rows that already appear there too, not a separate list of separate tasks. |
| GET    | `/households/meetings`    | query param `household_id`                         | Requires auth; `403` if the caller isn't a member. Every meeting in the household, most recent first. Returns `{"meetings": [{"id","household_id","occurred_at","notes","created_by_user_id","created_at","updated_at","attendees": [{"id","username"}, ...]}]}`. |
| GET    | `/households/meetings/detail` | query param `meeting_id`                       | Requires auth. `404` if no such meeting; `403` if the caller isn't a member of its household. Returns `{"meeting", "tasks": [...]}` — `tasks` is that meeting's own linked tasks (`source_type = "meeting"`, `source_id` this meeting), same joined shape and soonest-due-pending-only collapsing as `GET /households/tasks`. |
| POST   | `/households/meetings`    | `{"household_id", "occurred_at", "attendee_user_ids"?: [int], "notes"?}` | Requires auth; `403` if the caller isn't a member. `occurred_at`: any `strtotime()`-parseable date/time (`400` if it fails to parse), normalized to `Y-m-d H:i:s`; every id in `attendee_user_ids` must be a member of the household (`400` otherwise); `notes`: ≤20,000 chars, like a note's own `body`. `400` on any other validation failure. Returns `{"meeting"}`. |
| POST   | `/households/meetings/update` | `{"meeting_id", "occurred_at", "attendee_user_ids"?, "notes"?}` | Requires auth. `404` if no such meeting; `403` if the caller isn't a member of its household. Any member may update it — see "Household meetings" below. Same validation as create; `attendee_user_ids`, if given, wholesale-replaces the meeting's existing attendee list rather than merging with it. |
| POST   | `/households/meetings/delete` | `{"meeting_id"}`                               | Requires auth. Same `404`/`403` rules as `/households/meetings/update`. Does not delete the meeting's own linked tasks — see "Household meetings" below. |

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

## Account management

`AuthService::updateProfile()`/`changePassword()`/`deleteAccount()`
(issue #18) — distinct from `/forgot-password`/`/reset-password` above
(an unauthenticated, "I don't remember my password" flow) and from any one
household's own `/households/settings` (issue #7): these act on the
caller's own account, authenticated, not a household.

**Username/email update** (`POST /account/update`) — a username change
applies immediately, no re-verification needed (it's a login identifier,
not a contact address). An email change does not: the new address is
stashed in `users.pending_email` (migration `0026`) rather than
overwriting `email` outright, and a verification link is emailed to *that*
new address, reusing the exact same `email_verifications` token flow
`/register` already uses. `AuthService::verifyEmail()` (used by both
registration and this) checks `pending_email` on the token's account: set,
it promotes `pending_email` to `email` and clears it; unset, it just marks
the existing `email` verified (the plain registration case). Until that
link is clicked, the caller keeps logging in and receiving mail at their
current, already-verified address — a typo'd new address can't lock
anyone out.

**Change password** (`POST /account/change-password`) — the logged-in
counterpart to `/reset-password`: proves identity with the current
password instead of an emailed token, but ends the same way, deleting
every one of the account's sessions (current one included) for the same
reason `resetPassword()` already documents — a password change is as much
a signal of possible compromise as a forced reset. The web UI sends the
user back to the login page afterward.

**Account deletion** (`POST /account/delete`) — a real, user-initiated
deletion, confirmed by the account's own password (distinct from
`AuthService::cancelRegistration()`'s internal rollback-on-failed-
verification-email path, which isn't user-facing). No bespoke cleanup
code: every table that references `users.id` already has its own
`ON DELETE CASCADE`/`SET NULL` from the migration that added it, so
deleting the row is enough. Concretely, deleting an account:

- Deletes every session, email/password-reset token, and chat-usage record
  of theirs directly (`ON DELETE CASCADE`).
- Deletes every household **they created** (`households.created_by_user_id`
  is `CASCADE`) — which in turn deletes *all* of that household's own data
  (members, notes, pets, tasks, shopping items, staples, home improvement
  projects, invites — every `household_id` foreign key cascades too), for
  every member, not just the deleted account. This is the same
  no-ownership-transfer reality "Household invites" below already lives
  with (an owner can already leave a household unchallenged); deleting
  your account is a stricter version of leaving every household at once,
  and the web UI's delete-account form says so before letting the request
  through.
- Removes their membership (and anything else `_by_user_id`-shaped they
  authored) from every household they *don't* own, without touching that
  household itself.
- Nulls out a handful of "who happened to act on this shared row" columns
  that use `ON DELETE SET NULL` instead of `CASCADE` (e.g.
  `household_task_instances.completed_by_user_id`,
  `household_shopping_items.purchased_by_user_id`,
  `household_staple_items.flagged_by_user_id`) — the row itself survives,
  just with no record of who acted on it.

No display-name concept was added alongside this — username stays the one
identity a household sees, per the issue's own open question, until a real
need for a separate display name shows up.

## Data export

`GET /account/export` (issue #21, `AccountExportService`) — a "get your
data back" dump: your own account profile, LLM chat usage, and for every
household you belong to, its notes, pets, shopping list, staples, home
improvement projects, and current tasks. Settled explicitly, per the
issue's own open questions:

- **Per-user only, no household-wide/owner-only export.** The export is
  always scoped to what the requesting user themselves can already see —
  never a bulk dump of an entire household including other members' own
  private data. Concretely, this falls out of the implementation rather
  than needing its own privacy logic: `AccountExportService` calls each
  tracker's own already-privacy-respecting list method (e.g.
  `HouseholdService::listNotes()`, which already filters to public notes
  plus the caller's own private ones for the live UI) instead of querying
  tables directly, so the same guarantee the UI already has applies here
  automatically — there's no second, separate privacy check to get wrong.
- **JSON only.** No per-tracker CSV — revisit if someone actually wants,
  say, just their shopping history in a spreadsheet; nothing here rules it
  out later.
- **Synchronous, single request.** No queueing/emailing a file once ready
  — every household in this app so far is small enough that a single
  request comfortably returns the whole thing. Revisit if that stops being
  true.
- **Current state, not a full historical record.** Tasks are exported via
  the same `listTasks()`/`listFinishedToday()` the Tasks tab itself calls
  — the soonest-due pending instance per task, plus whatever resolved
  today — not every completion a recurring task has ever logged. A true
  full history is issue #19's (household activity log) territory; this
  export should draw on that once it exists rather than growing its own
  separate deep-history query in the meantime.

The web UI triggers a plain client-side JSON file download from the
response — no server-side file generation or storage involved.

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
"Household roles and permissions" below for the full matrix this is part
of.

## Household roles and permissions

`household_members.role` (`owner`/`member`, from issue #5) gated nothing
beyond membership itself until issue #17 settled which actions actually
need more than "is this person a member at all":

- **Owner-only**: removing a *different* member (`HouseholdService::
  removeMember()`), and deleting the household outright (`deleteHousehold()`,
  `POST /households/delete`) — a new capability this issue adds, not just
  a permission check on an existing one. Both go through a shared
  `requireOwner(int $householdId, int $userId, string $message)` guard,
  alongside the existing `requireMember()`, throwing a single
  `NotHouseholdOwnerException` (403) with an action-specific message
  rather than each action inventing its own exception.
- **Any member**: inviting someone else, leaving the household yourself,
  editing household settings (issue #7), and every tracker's own
  create/edit/delete (notes, pets, tasks, the shopping list, staples, home
  improvement projects). These were already implemented this way before
  #17 — this issue is a decision that they *stay* that way, not a change:
  a household is a small, trusted, collaborative group, and restricting
  everyday actions to the owner alone would just be friction with no
  concrete need behind it yet. Revisit per-action if a real need shows up,
  rather than restricting pre-emptively.

Open questions from issue #17, settled for v1:

- **Ownership is singular and non-transferable.** Whoever created the
  household is its one `owner` for as long as it exists; there's no
  transfer/shared-ownership flow. An owner can still leave (or delete
  their whole account, see "Account management" above) unchallenged, same
  as any member — no "last owner" special case blocks it. A real
  ownership-transfer story is its own future issue if households ever
  outlive their original creator's involvement in practice.
- **Owner/member is enough — no third tier.** No "admin" role short of
  full ownership exists; add one only once a concrete need for a
  middle tier shows up, rather than speculatively.
- **No per-tracker permission overrides.** Every tracker uses the same
  household-level owner/member split (in practice, "any member" for
  everything of theirs) rather than its own bespoke permission model —
  e.g. a future budget tracker (#9) doesn't get its own "who can edit this"
  concept independent of the matrix above unless a real need for one
  surfaces.

Whatever household-scoped feature comes next should point back to this
matrix rather than deciding its own permission model from scratch — "any
member" unless there's a specific, stated reason it needs to be
owner-only.

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
  model as settings). `vet_contact_id` (migration `0031`) is a nullable FK
  into `household_contacts` (issue #16) — see "Household contacts" below.

## Household contacts

A general-purpose address book (issue #16, `household_contacts`), split off
from pets rather than a `vet_name`/`vet_phone`/`vet_address` field bolted
onto `household_pets` (which is what issue #7 originally proposed) — the
vet, a doctor, a plumber, the insurance agent, whatever else a household
needs to keep track of, in one shared table. Same "no privacy tiers, any
member can add/edit/remove" permission model as pets — a shared reference
resource, not privacy-bearing content.

`category` is deliberately freeform text (≤50 chars), not an `ENUM` — the
same "open-ended list, not a small fixed set worth hardcoding" reasoning
the shopping list's and staples' own `category` columns already use, one
of two open questions the issue itself raised (a fixed dropdown vs.
freeform text); a household's contact categories are too varied to
usefully enumerate up front. `address` is free text with no format
validation (street number/city/state/zip formats vary too much to usefully
constrain), rendered as a multi-line `<textarea>` in the web UI rather
than a single-line input, and stored at up to 500 chars to comfortably
hold several lines.

**Multiple phones/emails, each labeled** (issue #16 follow-up, migration
`0033`): a contact isn't limited to one phone number or one email —
`household_contact_phones`/`household_contact_emails` are child tables
(`contact_id` FK, `ON DELETE CASCADE`), each row an entry labeled `home`,
`mobile`, or `work` (`ENUM`, a genuinely closed, well-known set — unlike
`category`'s own open-ended one). `HouseholdService::createContact()`/
`updateContact()` take `$phones`/`$emails` as plain arrays and replace a
contact's set of each *wholesale* on every save
(`HouseholdContactRepository::replacePhones()`/`replaceEmails()`,
delete-then-reinsert) rather than diffing against what's already there —
the same "edited as a whole via the UI, not incrementally" approach a
task's assignee list (issue #12) already uses, and for the same reason: a
short list is simpler to just replace outright than to reconcile. Each
email, if given, is validated as a real address (`FILTER_VALIDATE_EMAIL`);
phone numbers are free text with no format validation, since formats vary
by country/convention too much to usefully constrain.

The original single nullable `phone`/`email` columns this table shipped
with are gone — migration `0033` backfills any existing value into the new
tables (as a `home`-labeled entry) before dropping the old columns, the
same backfill-then-drop shape migration `0010` already used when
`household_tasks.assigned_to_user_id` became a many-to-many table.

**Pets link to a vet contact** (`household_pets.vet_contact_id`, migration
`0031`) — the issue's other open question (block pets on this landing
first, or ship pets with simple inline vet fields now and migrate later)
was settled by neither: `0007` shipped pets with no vet field at all,
deliberately deferring it rather than adding fields that would need
migrating away later — see that migration's own comment. `vet_contact_id`
is nullable, `ON DELETE SET NULL` like `household_tasks.
assigned_to_user_id`, since deleting a contact shouldn't delete the pet
that pointed to it. `HouseholdService::
validateVetContactId()` enforces it must, if given, reference a contact
already in the *same* household — the same "must belong to this household"
check calendar events already use for `responsible_user_id`. This is
meant to be a reusable pattern, not a one-off: a future home improvement
contractor contact or finances bank/insurance contact could point into
this same table the same way, rather than each tracker growing its own
contact fields — not building those now, just noting the shape already
supports it.

## Household shopping list

A single shared checklist per household (issue #24), `household_shopping_items`
— same "shared resource, no privacy tiers, any member can act on any row"
permission model as pets, not a per-user list. `name` is required;
`quantity`/`category` are both optional and free text (e.g. `"2 lbs"`,
`"Produce"`) rather than numeric/enum — a fixed category list would need
maintaining as households' own grocery habits vary, so v1 keeps it simple.

Marking an item purchased (`POST .../purchase`) just sets `purchased_at`/
`purchased_by_user_id` — it's the same row, not a move to a different table,
so un-purchasing (`POST .../unpurchase`, a misclick's undo, same idea as the
task Complete button's own Undo toast) is a plain clear-those-two-columns
update rather than needing to reconstruct anything. `GET .../shopping-list`
returns both the still-needed items (oldest-added first, so the list reads
like a running errand list) and the 25 most-recently-purchased (newest
first, a "did we already get that" glance-back, not a full purchase
history — nothing prunes older purchased rows the way cron prunes old task
instances, so this cap is the only thing keeping that query bounded).

One thing explicitly deferred past v1 (an open question on issue #24
itself): no link to a future #9 (spending) transaction when an item is
purchased — that stays entirely separate for now. The other open question,
a recurring/favorites concept for quick re-adding a staple item, shipped
separately as its own feature (issue #66) — see "Household staples list"
below.

## Household staples list

A standing checklist of "things we always keep stocked" (issue #66) —
`household_staple_items`, same "shared resource, no privacy tiers"
permission model as pets/the shopping list above, and a deliberately
separate table from `household_shopping_items` rather than a flag on it: a
staple is a definition that gets checked repeatedly (is this running low?),
while a shopping-list item is a one-off "need to buy this" that disappears
once purchased.

`needs_restock` is the actual checklist state — false normally, flipped to
true when a member checks the pantry/fridge and finds an item running low
or out (`POST .../staples/flag`, recording who/when in
`flagged_by_user_id`/`flagged_at`), and back to false either manually
(`POST .../staples/unflag` — "never mind, we still have some") or
automatically once it's been copied onto the real shopping list.

`POST /households/staples/add-to-shopping-list` is the actual point of the
feature: it takes every currently-flagged staple in the household, creates
a matching `household_shopping_items` row for each (via
`HouseholdShoppingItemRepository::create()` — the exact same path
`createShoppingItem()` above uses), and clears their flags so they aren't
copied over again next time. Checking the pantry and flagging what's low,
then clicking this once, is the whole intended workflow.

## Household calendar

Single-occurrence household events (issue #13), `household_calendar_events`
— unlike pets/the shopping list/staples, this table carries privacy-bearing
content, so it follows notes' permission model instead: any member can
create an event, but only its own creator can edit or delete it
(`requireOwnCalendarEvent()`, `HouseholdService`).

**Three visibility tiers**, a superset of notes' plain `private`/`public`
split — `busy` sits in between:

- **`private`** — visible only to its creator. Excluded entirely at the SQL
  level for every other member (`HouseholdCalendarEventRepository::
  listVisibleTo()`'s `WHERE` clause), the same defense-in-depth
  `HouseholdNoteRepository::listVisibleTo()` already uses for private
  notes — never even fetched into PHP for anyone else, let alone returned.
- **`busy`** — every other member can see that the time slot is blocked,
  but none of the event's details. Unlike `private`, a `busy` event of
  another member's *is* fetched (it has to be, to show the blocked slot at
  all) but then redacted down to just `{"id","household_id","starts_at",
  "ends_at","visibility"}` — no title, description, location, responsible
  party, or who created it — by `HouseholdService::redactCalendarEvent()`
  before the response ever leaves the server. This redaction is what makes
  `busy` different from a plain filter: the row exists in the response, just
  stripped, so the calendar UI can still render "busy" blocks on a week
  view for slots the caller doesn't own.
- **`public`** — every member sees the full event, same as a public note.

Redaction happens exclusively in `HouseholdService`, never left to the
frontend to enforce — the same guarantee "Household settings, notes, and
pets" above already documents for notes. There is deliberately **no
single-event-by-id GET route at all** — `GET /households/calendar`'s
date-range list (with per-row redaction already applied) is the only read
path that exists, which by construction rules out "fetch a specific busy
or private event directly by its id" as a leak vector, rather than needing
a separate check on a route that doesn't exist to leak through in the
first place.

**Settled explicitly for v1**, per the issue's own open questions:

- **No recurrence.** Every event is a single occurrence — no "repeat
  weekly" concept. Revisit if a real need shows up; the task/chore system
  already owns recurring commitments (see "Task/chore tracking" below),
  and a calendar-specific recurrence model is easy to add later without
  reworking what's here.
- **One responsible party, not a multi-attendee list.**
  `responsible_user_id` (nullable, `ON DELETE SET NULL` like
  `household_tasks.assigned_to_user_id` — removing that member shouldn't
  delete the event) is the person accountable for the event, which may
  differ from whoever created it. No RSVP/multi-attendee model exists yet.
- **Plain wall-clock `DATETIME`, no timezone conversion.** `starts_at`/
  `ends_at` are a single household-local time shared by every member —
  the same no-per-user-timezone assumption every other date/time column in
  this schema already makes (e.g. `household_task_instances.due_at`). The
  frontend's `datetime-local` inputs are read and written as plain local
  strings, with no UTC round-trip anywhere in the path.
- **A shared top-level create/edit form, not per-row inline editing.**
  Every other tracker in this app edits a row in place
  (`renderXEditForm(li, item, householdId)` in `web-static/js/main.js`);
  the calendar form's much higher field count (title, description, start,
  end, location, responsible party, visibility) made a single shared
  form clearer than seven inline fields opening inside a list row, and
  the issue's own proposed shape already called for "a create/edit form"
  (singular). Submitting it branches on whether an event is currently
  being edited (`editingCalendarEventId` in `main.js`) to `POST` to either
  `/households/calendar` or `/households/calendar/update`.

The Calendar tab shows one week at a time (Monday-to-Monday,
`startOfWeek()` in `main.js`), with Previous/Next buttons walking
`currentCalendarRangeStart` forward/backward 7 days and re-fetching; it
resets to the current week each time a household is (re)opened.

## Task/chore tracking

One-off tasks and recurring chores (issue #12), assignable to any number of
household members (or left unassigned), with an `assignment_mode` deciding
what 2+ assignees means — see "Multiple assignees" below. Tasks are a shared
household resource, not per-user content, same permission model as pets: any
member can create/edit/delete/complete any task, regardless of who created or
is assigned it (in `"everyone"` mode this means, e.g., any member can
complete a *different* assignee's own instance copy on their behalf).

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
leaves a real backlog of individually-completable rows sitting in the
database (one per missed occurrence) instead of one stuck, increasingly-
overdue row -- completing the oldest one doesn't silently skip the others.

`GET /households/tasks` (the household Tasks tab) only ever *shows* the
single soonest-due pending instance per task (per assignee, for an
`"everyone"`-mode task's several concurrent copies) rather than every
instance cron has generated -- `HouseholdTaskInstanceRepository::
listForHousehold()`'s own docblock calls this "the root task the instances
are generated from". A fallen-behind chore is still addressable one
occurrence at a time this way, it just doesn't clutter the tab with, e.g.,
a whole week of a daily task's already-generated future occurrences at
once -- completing the shown instance reveals whichever one was next
behind it on the following load. `GET /tasks/mine` (My Tasks) is unchanged
and still shows every pending instance assigned to the caller.

**Multiple assignees** (issue #12's own follow-up, migration `0010`):
`household_task_assignees` is a plain many-to-many join table (task + user)
rather than the single nullable `household_tasks.assigned_to_user_id`
column the feature originally shipped with. `household_tasks.assignment_mode`
decides what 2+ assignees means:
  - `"anyone"` (the default, and the only meaningful mode for 0/1 assignees):
    one shared instance per occurrence (`assigned_to_user_id` null on the
    instance) — whoever completes it first completes it for every assignee.
  - `"everyone"`: one instance row *per assignee* for the same occurrence
    (`assigned_to_user_id` set to that assignee's own id), each completed
    independently — reuses every existing per-instance complete/list/delete
    code path as-is rather than needing a separate per-person
    completion-tracking table. Requires at least one assignee (`400`
    otherwise, since there'd be nothing to generate a copy for).
An assignee list is edited as a whole (`HouseholdTaskRepository::
replaceAssignees()`), the same way a task's other fields are — there's no
separate add/remove-one-assignee route, and editing one doesn't retroactively
create or delete instances for the change (see "Editing" below).

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
just generating one. For each occurrence date, `"anyone"`-mode definitions
get one shared instance and `"everyone"`-mode ones get one per current
assignee (`HouseholdTaskRepository::listAssigneeIds()`) — so adding or
removing an assignee on an `"everyone"`-mode task only changes what the
*next* cron run generates, not any instance already created. Idempotent —
the `(task_id, due_at, assigned_to_user_id)` unique constraint plus an
exists-check before every insert means running it twice in a row, or after
missing a day, never double-books an occurrence (see the `0010` migration's
own comment for why the unique constraint alone isn't quite enough for a
shared instance's null `assigned_to_user_id`). Its second half purges old
instances past `RETENTION_DAYS`
(90) — resolved ones, completed *or skipped* (pure history by that point,
see "Skipping an occurrence" below) and pending ones nobody ever completed
(so an abandoned chore doesn't clutter the list forever) — and then
deletes any one-off definition left with zero instances after that (a
backstop; a one-off's definition and instance are normally deleted
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

**Un-completing an instance** (`POST /households/tasks/uncomplete`) is the
undo side of the above: puts a `done` instance back to `pending`, clearing
`completed_at`/`completed_by_user_id`. Backs the web UI's 5-second "Undo"
toast after clicking Complete (a misclick recovery, not a general "reopen a
finished task" feature) — see `showUndoToast()`/`completeTaskWithUndo()` in
`web-static/js/main.js`. Only valid from `done`: a skipped instance can't be
un-skipped this way, since a skip's required note would otherwise be
silently discarded with no chance to see it first, and a skip already takes
deliberate effort (typing a reason) that makes a misclick far less likely.

**Skipping an occurrence** (`POST /households/tasks/skip`, issue #12's own
follow-up): a third way to resolve a recurring task's pending instance,
alongside completing it (it happened) and deleting it (no record left at
all) — marks it `skipped` with a required note explaining why ("didn't
walk the dog — there was a tornado"). Recurring-only: a one-off task has no
*next* occurrence for a skip to make way for, so there's nothing skipping
would mean beyond what delete already does — `TaskService::skipInstance()`
rejects it with `400` (delete instead). Like completing, skipping doesn't
touch the task's schedule — the next occurrence is whatever cron already
generated (or will generate) on its own cadence, completely unaffected by
the skip. A skipped instance disappears from the pending lists the same
way a completed one does, and gets swept up by the same retention purge
(see above) — see "Viewing finished tasks" below for where it (and a
completion) actually surfaces.

**Viewing finished tasks** (`GET /households/tasks/finished`, issue #12's
own follow-up): a completed or skipped instance drops off `GET
/households/tasks`/`GET /tasks/mine` the moment it's no longer pending,
same as always — this route is a separate, household-wide window into
what was actually resolved *today*, either way, so that history isn't
simply invisible once acted on. The household Tasks tab has a "Show
finished today" toggle for it; it isn't fetched until the first time
that's clicked, and re-fetches every time the pending list itself
reloads while it's showing (completing/skipping/deleting/editing a task,
or reopening the toggle), so it never goes stale while visible.

**Highlighting what's due today**: a `task-due-today` CSS class on a
task's list item, in both the household Tasks tab and My Tasks — a
lighter, at-a-glance visual cue than the "OVERDUE" text marker an
actually-late task already gets, since due today isn't a problem yet.
Pure frontend (`isDueToday()` in `web-static/js/main.js`); no API change.
An actually-overdue task gets its own red `task-overdue` CSS class
(`isTaskOverdue()`) alongside the existing "OVERDUE" text marker, on the
household Tasks tab, My Tasks, and the dashboard's "Overdue" section
alike — same pure-frontend, no-API-change shape as `task-due-today`.

**Editing** (`POST /households/tasks/update`) updates the parent
definition's title/description/assignees/mode/recurrence *and* moves the
specific instance being edited to a new due date — but doesn't touch the
definition's `start_date`, any other instance, or retroactively create/delete
instances for an assignee just added/removed (that only takes effect on the
*next* cron-generated occurrence; the instance being edited keeps whichever
specific assignee, if any, it already had). `start_date` is only ever
read once, the moment a task has zero instances (shouldn't normally happen);
otherwise cron always advances from whatever the latest instance's due date
actually is, so a manual edit's new date naturally becomes the anchor the
*next* generated occurrence advances from.

**Notes on a task** (issue #12's own follow-up): `household_task_instances.
notes` isn't only a completion/skip explanation — `POST /households/tasks`
and `POST /households/tasks/update` can set it directly too, for anything
worth jotting down about an occurrence while it's still pending ("need to
buy dish soap first"). It lives on the *instance*, same as `due_at`, so
editing it only affects the specific occurrence being edited, not a
recurring task's other instances. The three write paths treat an omitted
`notes` differently on purpose: create leaves it unset, `/update` clears it
(an explicit edit — blank really does mean blank), and `/complete`
preserves whatever was already there (a plain "mark done" click shouldn't
silently erase a note); `/skip`'s `notes` is required and always
overwrites, since the skip reason is what matters most from that point on.

**"My Tasks" (`GET /tasks/mine`)**: a cross-household view — every pending
instance that's the caller's own to act on, across every household they
belong to, ordered by due date the same way as a single household's list:
either a shared `"anyone"`-mode instance for a task they're one of the
assignees on, or their own personal `"everyone"`-mode copy.
`HouseholdTaskInstanceRepository::listAssignedToUser()`'s query joins
`household_task_assignees` (the definition's assignee list) back to
`household_members` (matching both the household and the assignee) so a
task doesn't keep showing up here after its assignee has since left that
household — removing a member doesn't clear their rows out of
`household_task_assignees`, so without that join a stale assignment would
otherwise linger forever.

**Open-ended tasks** (issue #12's own follow-up, migration `0011`): a
one-off task's `due_at` is no longer forced to a real date -- leaving it
blank on `POST /households/tasks` (or clearing it via
`POST /households/tasks/update`) makes the instance open-ended (`due_at`
`null`, no deadline at all), for something like "put the new latch on the
back gate" that's real but has no actual due date. A *recurring* task
still always needs a real anchor date (defaults to today if omitted,
unchanged) since `RecurrenceCalculator` has to advance from somewhere.
`priority` (`"low"`/`"medium"`/`"high"`/`"critical"`) lets an open-ended
task be triaged; it defaults to `"medium"` when the task is open-ended and
none was given, so every open-ended task always has one to sort by. Both
`GET /households/tasks` and `GET /tasks/mine` order every open-ended
instance ahead of every dated one, highest priority first ("bubble to the
top... in reverse-priority order") — dated instances keep their existing
ascending-due-date order beneath them, unaffected by priority.
`bin/generate_task_instances.php` never has to reason about a `null`
`due_at`: it only ever touches recurring definitions (which always have a
real date), and `purgeExpiredPendingOlderThan()`'s
`due_at < CURDATE() - INTERVAL ...` comparison is itself `null` (never
true) against a `null` `due_at` in SQL, so an open-ended task's instance is
correctly never swept up as "expired" just for having sat around a long
time, with no extra code needed for that. The My Tasks tab has a "Show
open-ended tasks" checkbox (`web-static/js/main.js`) that filters the
already-fetched list client-side — it re-renders from the last response
rather than re-fetching, so toggling it never fights with a concurrent
complete.

**Household dashboard** (issue #20): a "Dashboard" tab, now the default tab
shown on opening a household (previously Members), splitting the same
pending instances `GET /households/tasks` already returns into "Due today"
and "Overdue" lists — no new API route. `web-static/js/main.js`'s
`loadDashboard()` reuses the existing `isDueToday()`/`isTaskOverdue()`
checks that already drive the Tasks tab's own highlight/`OVERDUE` label, so
the dashboard can never disagree with what that tab shows for the same
task; an open-ended task (no `due_at`) never matches either bucket, same as
there. Each row gets a Complete action that reloads via `loadTasks()`,
which — as its last step — also reloads the dashboard, so either tab stays
in sync regardless of where a task was completed from.

Issue #20 also calls for today's calendar events (issue #13) and overdue
maintenance (issue #11) to appear here; neither tracker existed yet at the
time this dashboard shipped, so those sections were left out rather than
stubbed in, and it still pulls from tasks alone — wiring in today's
calendar events is a natural follow-up now that #13 exists, but hasn't
been done yet. For the same reason it stays a distinct
tab rather than becoming the post-login landing page (app.html's household
list) — the issue's own suggestion is to promote it "once enough trackers
exist to make it worth showing."

`source_type`/`source_id` (issue #12's own consolidation recommendation)
let another tracker link its own tasks into this same system rather than
growing a bespoke task table of its own — see "Home improvement projects
and maintenance" and "Household meetings" below for the two trackers that
use it.

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

## Home improvement projects and maintenance

Issue #11, using #12's own consolidation recommendation: two related but
distinct concerns, sharing the task/chore system above rather than each
growing a bespoke table of their own. The Home Improvement tab presents
them as their own Projects/Maintenance sub-tabs (`web-static/js/main.js`'s
`activateHiTab()`, the same tab-panel pattern `activateTab()`/
`activateTopTab()` already use one level up) rather than stacking both
sections in one panel; pure frontend, no API shape change from that split.

**Home improvement projects** (`home_improvement_projects`, migration
`0015`) *are* their own entity — a project's status (`"idea"` →
`"planned"` → `"in_progress"` → `"completed"`/`"abandoned"`),
estimated-vs-actual cost, and target date aren't just "a task." A
project's own tasks, though, are plain `household_tasks` (issue #12),
created via the ordinary `POST /households/tasks` route with
`source_type = "home_improvement_project"` and `source_id` set to that
project's id — reusing #12's assignment/status/completion-history model
as-is rather than a bespoke `home_improvement_project_tasks` table.
`GET /households/projects/detail` returns a project alongside its own
task list (`HouseholdTaskInstanceRepository::listForSource()`); adding a
task to a project, completing it, editing it, deleting it, all go through
the same `/households/tasks/*` routes as any other task — the Home
Improvement tab's "Add task" form on a project's detail view is just a
thin wrapper that pre-fills `source_type`/`source_id`, same as any other
task creation.

Deleting a project (`POST /households/projects/delete`) deliberately
doesn't cascade-delete its tasks — they simply keep existing as ordinary
tasks, their `source_type`/`source_id` now pointing at nothing in
particular. This mirrors `source_type`/`source_id` having no FK to begin
with (see `0008`'s own migration comment) — the polymorphic reference was
always meant to be informational, not something the database enforces. A
member who wants those tasks gone too can delete them the normal way.

**Maintenance** (the recurring counterpart) needs no table of its own at
all — a maintenance item *is* a recurring `household_task`, tagged
`source_type = "maintenance"` (`source_id` always `null`, since it
doesn't source from a project or anything else — the tag alone is what
identifies one; `TaskService::validateSource()` rejects a `"maintenance"`
task that isn't recurring, or that has a `source_id`). `GET
/households/maintenance` is the Home Improvement tab's Maintenance
schedule, a second, filtered view over the same rows `GET
/households/tasks` and the dashboard's due-today/overdue sections already
show — completing/skipping/editing a maintenance item from either place
affects the exact same instance. This also means overdue maintenance
already surfaces on the household dashboard (issue #20) for free, without
that dashboard needing a dedicated "overdue maintenance" section of its
own — a maintenance item overdue is just an overdue task like any other.

**Not yet wired up**: actual project/maintenance cost is a plain hand-
entered field (`estimated_cost`/`actual_cost`), not linked to a future
financial tracker's (issue #9) transactions; no reminders/notifications
beyond the existing visible overdue indicator; no before/after photos.
See issue #11's own "Open questions" for the reasoning.

## Household meetings

A running log of household meetings (issue #8, `household_meetings`) —
when a meeting happened, who was there, and notes on decisions made and
issues discussed. Same "no privacy tiers, any member can add/edit/remove"
permission model as pets/contacts — a meeting log is shared household
information, not one member's private content, unlike notes/calendar
events.

`occurred_at` accepts any `strtotime()`-parseable date/time (the same
lenient parsing calendar events use for `starts_at`/`ends_at`), since a
meeting is often logged after the fact rather than in the moment.
`notes` is a single freeform field (≤20,000 chars, like a note's own
`body`) rather than a separate structured "issues discussed" list — one
of the issue's own open questions, settled the same way every other
freeform notes column in this schema already is.

**Attendees** (`household_meeting_attendees`) are a plain
household-member checkbox list, replaced wholesale on every save
(`HouseholdMeetingRepository::replaceAttendees()`) rather than diffed —
the same "edited as a whole via the UI, not incrementally" approach a
task's assignee list (issue #12) already uses.

**A meeting's action items are plain `household_tasks`**, not their own
table — created via the ordinary `POST /households/tasks` route with
`source_type = "meeting"` and `source_id` set to that meeting's id,
reusing issue #12's full assignment/recurrence/completion-history model
as-is (a meeting's action items can be delegated to a specific member,
one-off or recurring, just like any other task) — the exact same
`source_type`/`source_id` pattern issue #11's home improvement projects
already established, and the same split of responsibility:
`HouseholdMeetingService` owns the meeting entity itself,
`GET /households/meetings/detail` returns it alongside its own task list
(`HouseholdTaskInstanceRepository::listForSource()`), and adding/
completing/editing/deleting a meeting's task goes through the same
`/households/tasks/*` routes as any other task — the Meetings tab's "Add
task" form on a meeting's detail view is just a thin wrapper that
pre-fills `source_type`/`source_id`.

Deleting a meeting (`POST /households/meetings/delete`) deliberately
doesn't cascade-delete its tasks — they simply keep existing as ordinary
tasks, their `source_type`/`source_id` now pointing at nothing in
particular, the same behavior settled on for a deleted home improvement
project's own tasks (see above). A member who wants those tasks gone too
can delete them the normal way.

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
