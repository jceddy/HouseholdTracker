# web-static

Static web content (HTML/CSS/JS) for HouseholdTracker.

Served directly by the web server, or proxied alongside the
[`php-app`](../php-app) backend (static assets served from this directory,
API requests routed to the PHP app under `/app`).

## Pages

- `index.html` (`/`) — Login form. If the visitor already has an active
  session (checked via `GET /app/me`), they're redirected straight to
  `/app.html`. Links to `register.html`, `forgot-password.html`, and
  `resend-verification.html`.
- `register.html` — Registration form. On success, shows a message to
  check email for the verification link (login is blocked until
  verified).
- `resend-verification.html` — Takes an email address and calls
  `POST /app/resend-verification`; always shows the same generic success
  message regardless of whether the address is registered, already
  verified, or rate-limited.
- `forgot-password.html` — Takes an email address and calls
  `POST /app/forgot-password`; same enumeration-resistant response as
  above.
- `reset-password.html` — Reached via the link emailed by
  `forgot-password.html`. Reads `?token=` from the URL on load but
  doesn't submit it until the visitor actually chooses a new password
  (see "Password reset" in `php-app/README.md`).
- `maintenance.html` — Shown during a maintenance window; not linked from
  anywhere, reached only via `apiRequest()`'s redirect.
- `app.html` (`/app.html`) — Redirects to `/` if there's no active
  session; otherwise the logged-in landing page. This is the placeholder
  for the actual household-tracking UI — everything past login/logout
  belongs here.

## Version indicator

Every page has a `<footer>` with an `#app-version` span, populated by a
snippet in `js/app.js` that fetches `/VERSION` — a plain static text file
deployed alongside `index.html` — and renders it as e.g. "v0.1.0". Fetched
with `cache: 'no-store'` so a page loaded shortly after a deploy can't
keep showing a stale, browser-cached version string. See "Versioning" in
the top-level README.

## Dark mode

Three modes, chosen via a `<select id="theme-select">` in every page's own
`<footer>` (`system`/`light`/`dark`, defaulting to `system`). The color
switch itself is CSS-only, in `css/style.css`: a `:root` block defines
light-mode custom properties that every other rule reads from, overridden
by a `prefers-color-scheme: dark` media query (unless `data-theme="light"`
is set) and again by a `:root[data-theme="dark"]` rule (forcing dark
regardless of the OS). Each page duplicates a tiny inline `<script>` at
the top of its own `<head>` that reads `themePreference` from
`localStorage` and sets `document.documentElement.dataset.theme`
synchronously, before `js/app.js` (at the bottom of `<body>`) ever loads
— so an explicit preference never flashes the wrong theme first.

## Maintenance mode

`apiRequest()` (`js/app.js`) — the single fetch wrapper every API-calling
function funnels through — checks every response for `status: 503` with
`body.status === 'maintenance'` (see "Maintenance mode" in
`php-app/README.md`). On a match, it stashes the server's message and the
current page's path in `sessionStorage`, redirects to `/maintenance.html`,
and returns a Promise that never resolves. `maintenance.html` reads that
stashed state in `js/maintenance.js` (falling back to a hardcoded message
and `/` if visited directly), wires a retry button, and polls a raw
`fetch('/app/me')` (bypassing `apiRequest()`, so the poll itself can't
re-trigger the redirect logic) every 15 seconds — once that stops
returning `503`, it redirects back to the stashed return path
automatically.
