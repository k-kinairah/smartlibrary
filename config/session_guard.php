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
