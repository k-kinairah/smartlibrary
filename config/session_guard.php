<?php
// Live account check for signed-in requests.
//
// Login copies the account's name, ID number and role into the session
// (auth_finalize_session_login), and "Inactive" used to be checked only at
// login -- so an account deactivated, deleted, renamed or demoted by another
// librarian kept its old session working unchanged. Pages and endpoints that
// need a signed-in user call smartlib_active_session_user_id() instead of
// reading $_SESSION['user_id'] directly: one primary-key lookup per request
// (~0.2 ms here), which also refreshes those session copies from the DB.
//
// Signed-out requests return 0 straight away with no query. If the lookup
// itself fails (a transient DB error), the request is let through on the
// session as-is and the error is logged -- a DB hiccup shouldn't lock every
// librarian out.
//
// Idle timeout (security review 2026-11-24, SR-11): every guarded request
// stamps $_SESSION['last_seen']; a sign-in left unused for longer than the
// limit for its role is signed out on its next request. The limits are the
// constants below; config/mail_config.php may define either one first to
// override it. Only the sign-in is ended -- the library-card sign-up,
// verification and reprint state and a pending 2FA code live in other
// session keys and are left alone. A session from before this rule existed
// (no last_seen yet) just gets a fresh stamp. The kiosk's own 2-minute
// JavaScript sign-out (assets/javascript/kiosk-idle.js) is separate.

if (is_file(__DIR__ . '/mail_config.php')) {
    require_once __DIR__ . '/mail_config.php';
}
if (!defined('SMARTLIB_IDLE_TIMEOUT_STAFF_SECONDS')) {
    define('SMARTLIB_IDLE_TIMEOUT_STAFF_SECONDS', 30 * 60);   // librarians / admins
}
if (!defined('SMARTLIB_IDLE_TIMEOUT_PATRON_SECONDS')) {
    define('SMARTLIB_IDLE_TIMEOUT_PATRON_SECONDS', 15 * 60);  // students / faculty
}

/**
 * Copy an account row's display/access fields into the session, using the
 * same role mapping as login (admin is treated as librarian). Shared by the
 * live check below and by Manage Users when librarians edit their own account,
 * so the two can't drift apart.
 */
function smartlib_session_apply_account(array $account): void {
    $role = strtolower(trim((string)($account['role'] ?? '')));

    $_SESSION['name'] = trim(((string)($account['first_name'] ?? '')) . ' ' . ((string)($account['last_name'] ?? '')));
    $_SESSION['user_number'] = (string)($account['user_number'] ?? '');
    $_SESSION['role'] = $role === 'admin' ? 'librarian' : $role;
}

/**
 * End the current sign-in, like logout.php. The session stays started (with a
 * fresh, empty ID) so the rest of the request -- e.g. the kiosk home page
 * issuing its sign-in form token -- keeps working as a signed-out visitor.
 */
function smartlib_session_sign_out(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

/** Idle limit in seconds for the signed-in role. */
function smartlib_session_idle_limit(): int {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    return in_array($role, ['librarian', 'admin'], true)
        ? (int)SMARTLIB_IDLE_TIMEOUT_STAFF_SECONDS
        : (int)SMARTLIB_IDLE_TIMEOUT_PATRON_SECONDS;
}

/**
 * Whether this request ended the sign-in because it had been idle too long
 * (callers use it to say "you were signed out" instead of "please sign in").
 * Pass true to record it.
 */
function smartlib_session_idle_expired(?bool $set = null): bool {
    static $expired = false;
    if ($set !== null) {
        $expired = $set;
    }
    return $expired;
}

/**
 * Ends only the sign-in (account keys and the idle stamp) and gives the
 * session a fresh ID. Library-card and pending-2FA keys and the form
 * tokens stay, so a half-finished card sign-up is not lost.
 */
function smartlib_session_sign_out_idle(): void {
    foreach (['user_id', 'user_number', 'role', 'name', 'last_auth_at', 'last_seen'] as $key) {
        unset($_SESSION[$key]);
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

/**
 * Idle rule for a signed-in session: false (and signed out) when it has been
 * idle past the limit for its role, else true with last_seen refreshed.
 */
function smartlib_session_touch(): bool {
    $now = time();
    $lastSeen = $_SESSION['last_seen'] ?? null;
    if (is_int($lastSeen) || (is_string($lastSeen) && ctype_digit($lastSeen))) {
        if ($now - (int)$lastSeen > smartlib_session_idle_limit()) {
            smartlib_session_sign_out_idle();
            smartlib_session_idle_expired(true);
            return false;
        }
    }
    $_SESSION['last_seen'] = $now;
    return true;
}

/**
 * The JSON payload an endpoint sends with HTTP 401 when there is no usable
 * sign-in: "session_expired" after an idle sign-out (the kiosk then reloads
 * into its signed-out state), else "signed_out" with the endpoint's own text.
 */
function smartlib_session_signed_out_payload(string $message): array {
    if (smartlib_session_idle_expired()) {
        return [
            'status' => 'error',
            'error' => 'session_expired',
            'message' => 'You were signed out because the session was idle. Please sign in again.',
        ];
    }
    return ['status' => 'error', 'error' => 'signed_out', 'message' => $message];
}

/** Where an admin page sends a request without a usable staff sign-in. */
function smartlib_staff_signed_out_location(string $home = '../index.php'): string {
    return smartlib_session_idle_expired() ? $home . '?signin=1' : $home;
}

/**
 * The signed-in user's ID if the account still exists and is active, else 0.
 * An inactive or deleted account is signed out on the spot. For an active
 * account the session's name, ID number and role are refreshed from the DB.
 * Runs the lookup at most once per request.
 */
function smartlib_active_session_user_id(mysqli $conn): int {
    static $checkedFor = null;
    static $result = 0;

    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($sessionUserId <= 0) {
        return 0;
    }
    if ($checkedFor === $sessionUserId) {
        return $result;
    }

    if (!smartlib_session_touch()) {
        $checkedFor = $sessionUserId;
        $result = 0;
        return 0;
    }

    try {
        $stmt = $conn->prepare('SELECT user_number, first_name, last_name, role, status FROM library_users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('i', $sessionUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $account = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('SmartLib session check failed for user ' . $sessionUserId . ' (allowed through): ' . $e->getMessage());
        $checkedFor = $sessionUserId;
        $result = $sessionUserId;
        return $result;
    }

    $status = strtolower(trim((string)($account['status'] ?? '')));
    // Same rule as login_handler.php: a blank status counts as active.
    if (!is_array($account) || ($status !== '' && $status !== 'active')) {
        smartlib_session_sign_out();
        $checkedFor = $sessionUserId;
        $result = 0;
        return 0;
    }

    smartlib_session_apply_account($account);
    $checkedFor = $sessionUserId;
    $result = $sessionUserId;
    return $result;
}

/**
 * For admin pages: true if the signed-in account is active and (after the
 * refresh above) still a librarian/admin.
 */
function smartlib_active_staff_session(mysqli $conn): bool {
    if (smartlib_active_session_user_id($conn) <= 0) {
        return false;
    }

    return in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['librarian', 'admin'], true);
}
