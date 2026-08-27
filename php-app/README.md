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
  - `Household/` — `HouseholdService` (creation, membership, invites — issue
    #5) plus its exceptions.
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

Auth-requiring routes use the `session_token` cookie set by `/login`/`/me`
(`401` if missing/invalid) — see `requireAuth()` in `public/index.php`.
Whatever household-scoped tracker routes come next belong below
`/households/members/remove` in `public/index.php`, each guarded by the
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
