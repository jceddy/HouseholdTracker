<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use HouseholdTracker\Auth\AuthService;
use HouseholdTracker\Auth\DuplicateEmailException;
use HouseholdTracker\Auth\DuplicateUsernameException;
use HouseholdTracker\Auth\EmailNotVerifiedException;
use HouseholdTracker\Auth\InvalidCredentialsException;
use HouseholdTracker\Auth\InvalidPasswordResetTokenException;
use HouseholdTracker\Auth\InvalidVerificationTokenException;
use HouseholdTracker\Chat\ChatAgent;
use HouseholdTracker\Chat\ChatUsageException;
use HouseholdTracker\Chat\FireworksClient;
use HouseholdTracker\Chat\ModelCatalog;
use HouseholdTracker\Config;
use HouseholdTracker\Database\Connection;
use HouseholdTracker\Database\MigrationRunner;
use HouseholdTracker\Household\AlreadyMemberException;
use HouseholdTracker\Household\CannotInviteSelfException;
use HouseholdTracker\Household\HouseholdService;
use HouseholdTracker\Household\InviteNotFoundException;
use HouseholdTracker\Household\NoteNotFoundException;
use HouseholdTracker\Household\NotAHouseholdMemberException;
use HouseholdTracker\Household\NotAuthorizedToModifyNoteException;
use HouseholdTracker\Household\NotAuthorizedToRemoveMemberException;
use HouseholdTracker\Household\PetNotFoundException;
use HouseholdTracker\Household\TaskNotFoundException;
use HouseholdTracker\Household\TaskService;
use HouseholdTracker\Household\UserNotFoundException;
use HouseholdTracker\Ledger\Ledger;
use HouseholdTracker\Mail\Mailer;
use HouseholdTracker\Maintenance\MaintenanceGate;
use HouseholdTracker\Repository\EmailVerificationRepository;
use HouseholdTracker\Repository\HouseholdInviteRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdNoteRepository;
use HouseholdTracker\Repository\HouseholdPetRepository;
use HouseholdTracker\Repository\HouseholdRepository;
use HouseholdTracker\Repository\HouseholdTaskInstanceRepository;
use HouseholdTracker\Repository\HouseholdTaskRepository;
use HouseholdTracker\Repository\PasswordResetRepository;
use HouseholdTracker\Repository\SessionRepository;
use HouseholdTracker\Repository\UserRepository;
use HouseholdTracker\SiteUrl;

header('Content-Type: application/json');

// Without this, an uncaught Throwable from any route falls through to
// PHP's own fatal error output -- plain text/HTML, not JSON. This turns
// that into a proper JSON 500 and logs the real exception server-side.
set_exception_handler(static function (Throwable $e): void {
    error_log('Unhandled exception: ' . $e);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
});

// __route is set by public/.htaccess when the app is deployed under a
// subfolder (e.g. /app on shared hosting), so routing works regardless of
// where the front controller is mounted.
$path = isset($_GET['__route'])
    ? '/' . ltrim($_GET['__route'], '/')
    : (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requestBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

/**
 * Only used by /verify-email: unlike every other route, that one is meant
 * to be opened directly from an emailed link by a human, not called by our
 * own JS, so it renders a page instead of JSON.
 */
function respondHtml(int $status, string $title, string $heading, string $message, ?string $redirectTo = null, ?string $linkTo = null, string $linkText = 'Back to login'): never
{
    header('Content-Type: text/html; charset=utf-8', true);
    http_response_code($status);

    $redirectMeta = $redirectTo !== null
        ? sprintf('<meta http-equiv="refresh" content="5;url=%s">', htmlspecialchars($redirectTo, ENT_QUOTES))
        : '';
    $link = $redirectTo !== null
        ? sprintf('<p><a href="%s">Continue to login</a></p>', htmlspecialchars($redirectTo, ENT_QUOTES))
        : sprintf('<p><a href="%s">%s</a></p>', htmlspecialchars($linkTo ?? '/', ENT_QUOTES), htmlspecialchars($linkText, ENT_QUOTES));

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
        . '<link rel="stylesheet" href="/css/style.css">'
        . $redirectMeta
        . '</head><body><main>'
        . '<h1>' . htmlspecialchars($heading, ENT_QUOTES) . '</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . $link
        . '</main></body></html>';
    exit;
}

function publicUser(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
    ];
}

function setSessionCookie(string $token, DateTimeImmutable $expiresAt): void
{
    setcookie(AuthService::COOKIE_NAME, $token, [
        'expires' => $expiresAt->getTimestamp(),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearSessionCookie(): void
{
    setcookie(AuthService::COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Reads the session cookie, responding 401 if there's no valid session;
 * otherwise refreshes the cookie's expiry (matching /me's behavior) and
 * returns the current user.
 */
function requireAuth(AuthService $auth): array
{
    $token = $_COOKIE[AuthService::COOKIE_NAME] ?? null;
    $result = $token !== null ? $auth->currentUser($token) : null;

    if ($result === null) {
        respond(401, ['status' => 'error', 'message' => 'Not authenticated']);
    }

    setSessionCookie($token, $result['expiresAt']);

    return $result['user'];
}

/**
 * @throws \Throwable if the email fails to send
 */
function sendVerificationEmail(array $user, string $token): void
{
    $verificationUrl = rtrim(Config::get('APP_URL', ''), '/') . '/verify-email?token=' . urlencode($token);

    (new Mailer())->sendVerificationEmail($user['email'], $user['username'], $verificationUrl);
}

/**
 * Unlike sendVerificationEmail(), this links to a static frontend page
 * rather than a token-consuming GET route: corporate email-security
 * scanners that pre-fetch links in inbound mail would otherwise silently
 * burn the single-use reset token before the real user ever opens it. The
 * static page reads ?token= on load but only submits (and consumes) it
 * when the user actually chooses a new password, via POST /reset-password.
 *
 * @throws \Throwable if the email fails to send
 */
function sendPasswordResetEmail(array $user, string $token): void
{
    $resetUrl = SiteUrl::root() . '/reset-password.html?token=' . urlencode($token);

    (new Mailer())->sendPasswordResetEmail($user['email'], $user['username'], $resetUrl);
}

/**
 * Writes to a fixed, non-web-accessible file (src/ already has a
 * deny-all .htaccess) rather than PHP's ambient error_log destination.
 */
function logMailError(string $message): void
{
    $config = sprintf(
        'host=%s port=%s encryption=%s',
        Config::get('SMTP_HOST', '') ?: '(empty)',
        Config::get('SMTP_PORT', '587'),
        Config::get('SMTP_ENCRYPTION', 'tls') ?: '(none)'
    );
    $line = '[' . date('Y-m-d H:i:s') . "] {$message} [{$config}]\n";
    error_log($line, 3, dirname(__DIR__) . '/src/mail-errors.log');
}

if ($path === '/health' && $method === 'GET') {
    try {
        Connection::get()->query('SELECT 1');
        respond(200, ['status' => 'ok']);
    } catch (\Throwable $e) {
        respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

/**
 * Applies pending database/migrations/*.sql files (see MigrationRunner),
 * called by the deploy workflows (.github/workflows/deploy*.yml) right
 * after each deploy's file upload -- production has no shell access of
 * its own to run bin/migrate.php directly (see "Applying migrations" in
 * database/README.md). Gated on MIGRATION_DEPLOY_KEY (an X-Migration-Key
 * header, compared with hash_equals() to resist timing attacks) rather
 * than any user session, since this runs from a CI job with no logged-in
 * user at all; an unset/empty key fails closed.
 */
if ($path === '/migrate' && $method === 'POST') {
    $expectedKey = Config::get('MIGRATION_DEPLOY_KEY', '') ?? '';
    $providedKey = $_SERVER['HTTP_X_MIGRATION_KEY'] ?? '';

    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        respond(403, ['status' => 'error', 'message' => 'Invalid or missing migration key']);
    }

    try {
        $applied = MigrationRunner::applyPending(Connection::get());
        respond(200, ['status' => 'ok', 'applied' => $applied]);
    } catch (\Throwable $e) {
        respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// /health and /migrate are exempt above because the deploy workflows'
// post-deploy smoke test and migration step both need to run regardless
// of whether the deployed VERSION and schema_version currently agree --
// /migrate's entire purpose is to resolve that exact mismatch. /verify-email
// is exempt here too, but not skipped: unlike every other route it renders
// an HTML page for a human clicking an emailed link rather than JSON, so
// its own route block below checks the gate itself.
if ($path !== '/health' && $path !== '/migrate' && $path !== '/verify-email') {
    $maintenanceMessage = MaintenanceGate::activeMessage();
    if ($maintenanceMessage !== null) {
        header('Retry-After: 120');
        respond(503, ['status' => 'maintenance', 'message' => $maintenanceMessage]);
    }
}

$auth = new AuthService(
    new UserRepository(),
    new SessionRepository(),
    new EmailVerificationRepository(),
    new PasswordResetRepository()
);

// Constructed here (rather than down in the household routes block below) so
// /verify-email can also reach it, to link a newly-verified email's pending
// household invites (issue #33) -- see HouseholdService::linkPendingInvitesForEmail().
$households = new HouseholdService(
    new HouseholdRepository(),
    new HouseholdMemberRepository(),
    new HouseholdInviteRepository(),
    new UserRepository(),
    new HouseholdNoteRepository(),
    new HouseholdPetRepository()
);

$tasks = new TaskService(
    new HouseholdMemberRepository(),
    new HouseholdTaskRepository(),
    new HouseholdTaskInstanceRepository()
);

if ($path === '/register' && $method === 'POST') {
    $body = requestBody();

    try {
        $result = $auth->register(
            (string) ($body['username'] ?? ''),
            (string) ($body['email'] ?? ''),
            (string) ($body['password'] ?? '')
        );
    } catch (DuplicateUsernameException | DuplicateEmailException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }

    try {
        sendVerificationEmail($result['user'], $result['verificationToken']);
    } catch (\Throwable $e) {
        logMailError('Failed to send registration verification email: ' . $e->getMessage());
        $auth->cancelRegistration((int) $result['user']['id']);
        respond(502, [
            'status' => 'error',
            'message' => 'Could not send the verification email. Please try registering again.',
        ]);
    }

    respond(201, [
        'status' => 'ok',
        'message' => 'Check your email to verify your account before logging in.',
        'user' => publicUser($result['user']),
    ]);
}

if ($path === '/resend-verification' && $method === 'POST') {
    $body = requestBody();
    $email = (string) ($body['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        respond(400, ['status' => 'error', 'message' => 'A valid email address is required.']);
    }

    $result = $auth->resendVerificationEmail($email);

    if ($result !== null) {
        try {
            sendVerificationEmail($result['user'], $result['verificationToken']);
        } catch (\Throwable $e) {
            logMailError('Failed to send resend-verification email: ' . $e->getMessage());
            respond(502, [
                'status' => 'error',
                'message' => 'Could not send the verification email. Please try again shortly.',
            ]);
        }
    }

    // Always the same response, whether or not an email was actually sent, so
    // this endpoint can't be used to discover which addresses are registered.
    respond(200, [
        'status' => 'ok',
        'message' => 'If an account with that email exists and needs verification, a new email has been sent.',
    ]);
}

if ($path === '/verify-email' && $method === 'GET') {
    $maintenanceMessage = MaintenanceGate::activeMessage();
    if ($maintenanceMessage !== null) {
        respondHtml(503, 'Maintenance - HouseholdTracker', 'Under maintenance', $maintenanceMessage);
    }

    $token = (string) ($_GET['token'] ?? '');

    try {
        $user = $auth->verifyEmail($token);
        $households->linkPendingInvitesForEmail((int) $user['id'], $user['email']);
        respondHtml(
            200,
            'Email verified - HouseholdTracker',
            'Email verified',
            "Thanks, {$user['username']}! Your account is verified and you can now log in. Redirecting you shortly...",
            '/'
        );
    } catch (InvalidVerificationTokenException $e) {
        respondHtml(
            400,
            'Verification failed - HouseholdTracker',
            'Verification failed',
            $e->getMessage(),
            null,
            '/resend-verification.html',
            'Get a new verification email'
        );
    }
}

if ($path === '/forgot-password' && $method === 'POST') {
    $body = requestBody();
    $email = (string) ($body['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        respond(400, ['status' => 'error', 'message' => 'A valid email address is required.']);
    }

    $result = $auth->requestPasswordReset($email);

    if ($result !== null) {
        try {
            sendPasswordResetEmail($result['user'], $result['resetToken']);
        } catch (\Throwable $e) {
            logMailError('Failed to send password reset email: ' . $e->getMessage());
            respond(502, [
                'status' => 'error',
                'message' => 'Could not send the password reset email. Please try again shortly.',
            ]);
        }
    }

    // Always the same response, whether or not an email was actually sent, so
    // this endpoint can't be used to discover which addresses are registered.
    respond(200, [
        'status' => 'ok',
        'message' => 'If an account with that email exists, a password reset link has been sent.',
    ]);
}

if ($path === '/reset-password' && $method === 'POST') {
    $body = requestBody();

    try {
        $user = $auth->resetPassword((string) ($body['token'] ?? ''), (string) ($body['password'] ?? ''));
        respond(200, [
            'status' => 'ok',
            'message' => 'Your password has been reset. You can now log in with your new password.',
            'user' => publicUser($user),
        ]);
    } catch (InvalidPasswordResetTokenException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/login' && $method === 'POST') {
    $body = requestBody();

    try {
        $result = $auth->login(
            (string) ($body['username'] ?? ''),
            (string) ($body['password'] ?? ''),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        setSessionCookie($result['token'], $result['expiresAt']);
        respond(200, ['status' => 'ok', 'user' => publicUser($result['user'])]);
    } catch (InvalidCredentialsException $e) {
        respond(401, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (EmailNotVerifiedException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/logout' && $method === 'POST') {
    $token = $_COOKIE[AuthService::COOKIE_NAME] ?? null;

    if ($token !== null) {
        $auth->logout($token);
    }

    clearSessionCookie();
    respond(200, ['status' => 'ok']);
}

if ($path === '/me' && $method === 'GET') {
    $token = $_COOKIE[AuthService::COOKIE_NAME] ?? null;
    $result = $token !== null ? $auth->currentUser($token) : null;

    if ($result === null) {
        respond(401, ['status' => 'error', 'message' => 'Not authenticated']);
    }

    setSessionCookie($token, $result['expiresAt']);
    respond(200, ['status' => 'ok', 'user' => $result['user']]);
}

// LLM usage (Fireworks AI) -- see "LLM usage (Fireworks AI)" in
// php-app/README.md. A scaffold for whatever household-tracking features
// end up calling an LLM: HouseholdTracker\Chat\Tools is currently an
// empty tool registry (ChatAgent runs as a plain chat model until real
// tools are added there), and HouseholdTracker\Chat\ModelCatalog ships
// with one placeholder model to replace with a real Fireworks model id
// and its published pricing before relying on this in production.

if ($path === '/chat/models' && $method === 'GET') {
    requireAuth($auth);
    respond(200, ['status' => 'ok', 'models' => ModelCatalog::keys(), 'default_model' => ModelCatalog::DEFAULT_KEY]);
}

if ($path === '/chat/usage' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'usage' => (new Ledger())->usageForUser((int) $currentUser['id'])]);
}

if ($path === '/chat' && $method === 'POST') {
    $currentUser = requireAuth($auth);

    $fireworksApiKey = Config::get('FIREWORKS_API_KEY', '') ?? '';
    if ($fireworksApiKey === '') {
        respond(503, ['status' => 'error', 'message' => 'LLM chat is not configured on this server.']);
    }

    $body = requestBody();
    $messages = $body['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        respond(400, ['status' => 'error', 'message' => 'messages (a non-empty array) is required.']);
    }

    $modelKey = (string) ($body['model'] ?? ModelCatalog::DEFAULT_KEY);
    if (!ModelCatalog::has($modelKey)) {
        respond(400, ['status' => 'error', 'message' => "Unknown model \"{$modelKey}\". See GET /chat/models."]);
    }

    $agent = new ChatAgent(new FireworksClient($fireworksApiKey, ModelCatalog::fireworksModel($modelKey)));
    $ledger = new Ledger();

    try {
        $result = $agent->run($messages);
    } catch (ChatUsageException $e) {
        $costUsd = ModelCatalog::pricing($modelKey)->costUsd($e->usage);
        $ledger->recordChatUsage((int) $currentUser['id'], $e->usage, $costUsd, $modelKey, success: false, errorMessage: $e->getMessage());

        $status = $e->insufficientBalance ? 402 : 502;
        respond($status, ['status' => 'error', 'message' => $e->getMessage()]);
    }

    $costUsd = ModelCatalog::pricing($modelKey)->costUsd($result['usage']);
    $ledger->recordChatUsage((int) $currentUser['id'], $result['usage'], $costUsd, $modelKey, success: true, errorMessage: null);

    respond(200, [
        'status' => 'ok',
        'reply' => $result['reply'],
        'messages' => $result['messages'],
        'usage' => $result['usage'],
        'cost_usd' => $costUsd,
        'model' => $modelKey,
    ]);
}

// Households (issue #5): membership and invites. Every household-scoped
// tracker that follows (chores, finances, calendar, ...) builds on top of
// this -- its own migration in ../database/migrations, its own
// Repository/Service pair under src/, and its own routes guarded by
// requireAuth($auth) plus a household-membership check the same way every
// route below already is.

if ($path === '/households' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $household = $households->createHousehold((int) $currentUser['id'], (string) ($body['name'] ?? ''));
        respond(201, ['status' => 'ok', 'household' => $household]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'households' => $households->listHouseholdsForUser((int) $currentUser['id'])]);
}

if ($path === '/households/members' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $householdId = (int) ($_GET['household_id'] ?? 0);

    try {
        respond(200, ['status' => 'ok', 'members' => $households->listMembers((int) $currentUser['id'], $householdId)]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/invite' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $householdId = (int) ($body['household_id'] ?? 0);

    try {
        $result = $households->inviteMember($householdId, (int) $currentUser['id'], (string) ($body['username_or_email'] ?? ''));
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (UserNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (CannotInviteSelfException | AlreadyMemberException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }

    if ($result['type'] === 'new_email') {
        $registerUrl = SiteUrl::root() . '/register.html?email=' . urlencode($result['invitedEmail']);

        try {
            (new Mailer())->sendHouseholdInviteEmail(
                $result['invitedEmail'],
                $result['household']['name'],
                $currentUser['username'],
                $registerUrl
            );
        } catch (\Throwable $e) {
            logMailError('Failed to send household invite email: ' . $e->getMessage());
            $households->cancelInvite((int) $result['invite']['id']);
            respond(502, [
                'status' => 'error',
                'message' => 'Could not send the invitation email. Please try again shortly.',
            ]);
        }

        respond(201, [
            'status' => 'ok',
            'message' => "Invitation email sent to {$result['invitedEmail']}.",
            'invited_email' => $result['invitedEmail'],
        ]);
    }

    respond(201, [
        'status' => 'ok',
        'message' => 'Invite sent.',
        'invited_user' => ['id' => (int) $result['invitedUser']['id'], 'username' => $result['invitedUser']['username']],
    ]);
}

if ($path === '/households/invites' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'invites' => $households->listInvitesForUser((int) $currentUser['id'])]);
}

if ($path === '/households/invites/respond' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $households->respondToInvite((int) $currentUser['id'], (int) ($body['invite_id'] ?? 0), (string) ($body['action'] ?? ''));
        respond(200, ['status' => 'ok']);
    } catch (InviteNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/members/remove' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $households->removeMember((int) $currentUser['id'], (int) ($body['household_id'] ?? 0), (int) ($body['user_id'] ?? 0));
        respond(200, ['status' => 'ok']);
    } catch (NotAHouseholdMemberException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToRemoveMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/settings' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $household = $households->updateSettings(
            (int) $currentUser['id'],
            (int) ($body['household_id'] ?? 0),
            (string) ($body['name'] ?? '')
        );
        respond(200, ['status' => 'ok', 'household' => $household]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/notes' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $householdId = (int) ($_GET['household_id'] ?? 0);

    try {
        respond(200, ['status' => 'ok', 'notes' => $households->listNotes((int) $currentUser['id'], $householdId)]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/notes' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $note = $households->createNote(
            (int) $currentUser['id'],
            (int) ($body['household_id'] ?? 0),
            (string) ($body['visibility'] ?? ''),
            (string) ($body['body'] ?? '')
        );
        respond(201, ['status' => 'ok', 'note' => $note]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/notes/update' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $note = $households->updateNote(
            (int) $currentUser['id'],
            (int) ($body['note_id'] ?? 0),
            (string) ($body['visibility'] ?? ''),
            (string) ($body['body'] ?? '')
        );
        respond(200, ['status' => 'ok', 'note' => $note]);
    } catch (NoteNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToModifyNoteException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/notes/delete' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $households->deleteNote((int) $currentUser['id'], (int) ($body['note_id'] ?? 0));
        respond(200, ['status' => 'ok']);
    } catch (NoteNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToModifyNoteException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/pets' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $householdId = (int) ($_GET['household_id'] ?? 0);

    try {
        respond(200, ['status' => 'ok', 'pets' => $households->listPets((int) $currentUser['id'], $householdId)]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/pets' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $pet = $households->createPet(
            (int) $currentUser['id'],
            (int) ($body['household_id'] ?? 0),
            (string) ($body['name'] ?? ''),
            isset($body['species']) ? (string) $body['species'] : null,
            isset($body['breed']) ? (string) $body['breed'] : null,
            isset($body['birthday']) ? (string) $body['birthday'] : null,
            isset($body['notes']) ? (string) $body['notes'] : null
        );
        respond(201, ['status' => 'ok', 'pet' => $pet]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/pets/update' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $pet = $households->updatePet(
            (int) $currentUser['id'],
            (int) ($body['pet_id'] ?? 0),
            (string) ($body['name'] ?? ''),
            isset($body['species']) ? (string) $body['species'] : null,
            isset($body['breed']) ? (string) $body['breed'] : null,
            isset($body['birthday']) ? (string) $body['birthday'] : null,
            isset($body['notes']) ? (string) $body['notes'] : null
        );
        respond(200, ['status' => 'ok', 'pet' => $pet]);
    } catch (PetNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/pets/delete' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $households->deletePet((int) $currentUser['id'], (int) ($body['pet_id'] ?? 0));
        respond(200, ['status' => 'ok']);
    } catch (PetNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/tasks' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $householdId = (int) ($_GET['household_id'] ?? 0);

    try {
        respond(200, ['status' => 'ok', 'tasks' => $tasks->listTasks((int) $currentUser['id'], $householdId)]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/tasks/finished' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $householdId = (int) ($_GET['household_id'] ?? 0);

    try {
        respond(200, ['status' => 'ok', 'tasks' => $tasks->listFinishedToday((int) $currentUser['id'], $householdId)]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/tasks' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $createdInstances = $tasks->createTask(
            (int) $currentUser['id'],
            (int) ($body['household_id'] ?? 0),
            (string) ($body['title'] ?? ''),
            isset($body['description']) ? (string) $body['description'] : null,
            isset($body['assigned_to_user_ids']) && is_array($body['assigned_to_user_ids']) ? array_map('intval', $body['assigned_to_user_ids']) : [],
            isset($body['assignment_mode']) && $body['assignment_mode'] !== '' ? (string) $body['assignment_mode'] : null,
            isset($body['recurrence_frequency']) && $body['recurrence_frequency'] !== '' ? (string) $body['recurrence_frequency'] : null,
            isset($body['recurrence_interval']) && $body['recurrence_interval'] !== '' ? (int) $body['recurrence_interval'] : null,
            isset($body['due_at']) && $body['due_at'] !== '' ? (string) $body['due_at'] : null,
            isset($body['priority']) && $body['priority'] !== '' ? (string) $body['priority'] : null,
            isset($body['notes']) ? (string) $body['notes'] : null
        );
        respond(201, ['status' => 'ok', 'tasks' => $createdInstances]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/tasks/update' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $task = $tasks->updateTask(
            (int) $currentUser['id'],
            (int) ($body['instance_id'] ?? 0),
            (string) ($body['title'] ?? ''),
            isset($body['description']) ? (string) $body['description'] : null,
            isset($body['assigned_to_user_ids']) && is_array($body['assigned_to_user_ids']) ? array_map('intval', $body['assigned_to_user_ids']) : [],
            isset($body['assignment_mode']) && $body['assignment_mode'] !== '' ? (string) $body['assignment_mode'] : null,
            isset($body['recurrence_frequency']) && $body['recurrence_frequency'] !== '' ? (string) $body['recurrence_frequency'] : null,
            isset($body['recurrence_interval']) && $body['recurrence_interval'] !== '' ? (int) $body['recurrence_interval'] : null,
            isset($body['due_at']) && $body['due_at'] !== '' ? (string) $body['due_at'] : null,
            isset($body['priority']) && $body['priority'] !== '' ? (string) $body['priority'] : null,
            isset($body['notes']) ? (string) $body['notes'] : null
        );
        respond(200, ['status' => 'ok', 'task' => $task]);
    } catch (TaskNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/tasks/delete' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $tasks->deleteInstance((int) $currentUser['id'], (int) ($body['instance_id'] ?? 0));
        respond(200, ['status' => 'ok']);
    } catch (TaskNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/tasks/complete' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $task = $tasks->completeInstance(
            (int) $currentUser['id'],
            (int) ($body['instance_id'] ?? 0),
            isset($body['notes']) ? (string) $body['notes'] : null
        );
        respond(200, ['status' => 'ok', 'task' => $task]);
    } catch (TaskNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/households/tasks/skip' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $task = $tasks->skipInstance(
            (int) $currentUser['id'],
            (int) ($body['instance_id'] ?? 0),
            (string) ($body['notes'] ?? '')
        );
        respond(200, ['status' => 'ok', 'task' => $task]);
    } catch (TaskNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAHouseholdMemberException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/tasks/mine' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'tasks' => $tasks->listMyTasks((int) $currentUser['id'])]);
}

// Further household-tracking domain routes (finances, calendar, whatever
// this app actually ends up tracking) go here.

respond(404, ['status' => 'error', 'message' => 'Not found']);
