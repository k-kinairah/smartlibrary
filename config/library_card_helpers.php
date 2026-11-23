<?php
/**
 * Self-registration for the digital library card.
 *
 * Students/faculty sign up with their PHINMA email and school ID number,
 * confirm a 6-digit emailed code, then choose their own kiosk PIN
 * (library_card_set_pin.php), and get a library_users row with a card and
 * that PIN, so they can sign in to the kiosk straight away. Signups started
 * on the older form carry the PIN from the form instead. Self-signups
 * created before 2026-11-04 have no PIN and still need a librarian to set one.
 *
 * Pending signups live in auth_signup_verifications (see
 * database/library_card_setup.sql). All expiry/age checks are done in SQL
 * with NOW(), so the database is the one clock they depend on.
 */

const LIBRARY_CARD_EMAIL_DOMAIN = 'phinmaed.com';
// Printed on the card. Same name the receipts and reminder emails use
// (assets/javascript/filters.js, send_receipt_email.php,
// send_overdue_reminder_email.php, send_due_date_reminders.php).
const LIBRARY_CARD_LIBRARY_NAME = 'PHINMA-SJCDC Library';
const LIBRARY_CARD_CODE_TTL_MINUTES = 10;
const LIBRARY_CARD_MAX_ATTEMPTS = 5;
const LIBRARY_CARD_RESEND_COOLDOWN_SECONDS = 60;
// Per email, not per IP: students on campus Wi-Fi share one public IP.
const LIBRARY_CARD_MAX_REQUESTS_PER_HOUR = 5;

// Report demographics. Year level is college students only; faculty and
// Junior/Senior High students store NULL. College programs here are all
// 4-year (no 5-year programs offered), so there is no "5th year or above"
// choice.
const LIBRARY_CARD_YEAR_LEVELS = [
    1 => '1st year',
    2 => '2nd year',
    3 => '3rd year',
    4 => '4th year',
];
const LIBRARY_CARD_GENDERS = [
    'female' => 'Female',
    'male' => 'Male',
    'prefer_not_to_say' => 'Prefer not to say',
];
// Students only (library_users.school_level, database/school_level_setup.sql);
// faculty, librarian and admin store NULL. The form also covers the basic
// education side: Junior High and Senior High students pick only their level
// (no grade, program or year level) and keep role 'student'. A student with
// NULL school_level (older rows) counts as College.
const LIBRARY_CARD_SCHOOL_LEVELS = [
    'college' => 'College',
    'jhs' => 'Junior High School',
    'shs' => 'Senior High School',
];

/** "College" / "Junior High School" / "Senior High School"; '' if unknown. */
function library_card_school_level_label(?string $level): string {
    return LIBRARY_CARD_SCHOOL_LEVELS[(string)$level] ?? '';
}

/**
 * SQL for a student's basic-education level label: 'Junior High School' or
 * 'Senior High School' for jhs/shs, NULL for anything else (College, NULL,
 * faculty). $levelExpr is a trusted column expression such as
 * "u.school_level". Display queries put it after the department and before
 * the program, so College students and faculty come out exactly as before.
 * The CONVERT keeps it working when the expression mixes columns of tables
 * with different collations (library_entry_logs vs library_users).
 */
function library_card_school_level_label_sql(string $levelExpr): string {
    $cases = '';
    foreach (LIBRARY_CARD_SCHOOL_LEVELS as $value => $label) {
        if ($value === 'college') {
            continue;
        }
        $cases .= " WHEN '" . $value . "' THEN '" . $label . "'";
    }
    return "(CASE CONVERT({$levelExpr} USING utf8mb4){$cases} ELSE NULL END)";
}

/** Same list and order as the admin user form (admin/manage_users.php). */
function library_card_get_programs(mysqli $conn): array {
    $programs = [];
    $res = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
    while ($res && ($row = $res->fetch_assoc())) {
        $programs[(int)$row['program_id']] = (string)$row['program_name'];
    }
    return $programs;
}

/**
 * Faculty/staff departments (database/faculty_departments_setup.sql), in the
 * school's own order. By default only ACTIVE ones (the sign-up dropdown and
 * Manage Users "Add User"); departments retired by
 * database/departments_update_nov_2026.sql stay in the table so people
 * already registered under one keep it, and $includeRetired = true lists
 * them too. Until that file has added is_active, every department counts as
 * active. Returns [] if the table is missing, so the sign-up page still
 * renders (faculty then cannot pick one and get the "choose" error).
 */
function library_card_get_departments(mysqli $conn, bool $includeRetired = false): array {
    $queries = $includeRetired
        ? []
        : ["SELECT department_id, department_name FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, department_name ASC"];
    $queries[] = "SELECT department_id, department_name FROM departments ORDER BY sort_order ASC, department_name ASC";
    foreach ($queries as $sql) {
        try {
            $res = $conn->query($sql);
        } catch (Throwable $e) {
            continue; // is_active missing: fall back to every department
        }
        if (!$res) {
            continue;
        }
        $departments = [];
        while ($row = $res->fetch_assoc()) {
            $departments[(int)$row['department_id']] = (string)$row['department_name'];
        }
        return $departments;
    }
    return [];
}

function library_card_normalize_email(string $email): string {
    return strtolower(trim($email));
}

function library_card_is_phinma_email(string $email): bool {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $suffix = '@' . LIBRARY_CARD_EMAIL_DOMAIN;
    return substr($email, -strlen($suffix)) === $suffix;
}

/**
 * Normalize a typed school ID: trim, then strip everything except letters
 * and digits.
 *
 * As of late 2026 the school has at least two ID formats live at once: the
 * legacy plain-digit IDs (8-digit students, 6-digit faculty) still carried
 * by continuing students/faculty, and a new "NN-YYYY-NNNNNN" format (e.g.
 * "10-2526-001953") the new school management started issuing to new
 * enrollees. The dashes in the new format are a human-readable grouping,
 * not meaningful data, so they're never stored: normalizing them away means
 * someone can type their ID with or without the dashes and it still
 * matches, and it keeps the printed barcode packed into Code 128's compact
 * all-digit mode (Set C) instead of roughly doubling in width -- a 14-char
 * dashed ID encoded as literal text (Set B) comes out wider than the whole
 * card. See library_card_display_id() for re-adding the dashes when
 * showing an ID to a person, and code128_svg() in config/barcode_code128.php
 * for the encoder itself.
 */
function library_card_normalize_id(string $raw): string {
    return (string)preg_replace('/[^A-Za-z0-9]/', '', trim($raw));
}

/**
 * Loose sanity check on an already-normalized ID's shape: 4-20 letters/
 * digits. Deliberately not used to infer role any more -- with two ID
 * formats live at once (and no guarantee the school won't change the
 * format again), shape alone can't be trusted to say who's a student and
 * who's faculty. The signup form asks for that explicitly instead (see
 * the account_type handling in library_card_signup.php).
 */
function library_card_id_format_ok(string $userNumber): bool {
    return (bool)preg_match('/^[A-Za-z0-9]{4,20}$/', $userNumber);
}

/**
 * Human-readable form of a stored (normalized, dash-free) ID, for display
 * on the card/UI only -- never for storage or lookups. A 12-digit ID is
 * the new "NN-YYYY-NNNNNN" format with its dashes stripped for storage;
 * re-insert them for reading. Everything else (legacy 6/8-digit IDs, any
 * alphanumeric staff ID) is shown exactly as stored.
 */
function library_card_display_id(string $userNumber): string {
    if (preg_match('/^\d{12}$/', $userNumber)) {
        return substr($userNumber, 0, 2) . '-' . substr($userNumber, 2, 4) . '-' . substr($userNumber, 6, 6);
    }
    return $userNumber;
}

/**
 * Decide whether a new signup request for ($email, $userNumber) may proceed.
 *
 * Returns ['ok' => true] or ['ok' => false, 'message' => ...]. When the
 * same person (same email AND same ID) asks again after the cooldown, their
 * earlier pending code is retired so a fresh one can be sent.
 */
function library_card_check_signup_allowed(mysqli $conn, string $email, string $userNumber): array {
    $stmt = $conn->prepare(
        "SELECT email, user_number
         FROM library_users
         WHERE email = ? OR user_number = ?"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to check existing accounts right now.'];
    }
    $stmt->bind_param('ss', $email, $userNumber);
    $stmt->execute();
    $res = $stmt->get_result();
    $emailTaken = false;
    $idTaken = false;
    while ($res && ($row = $res->fetch_assoc())) {
        if (strcasecmp((string)$row['email'], $email) === 0) {
            $emailTaken = true;
        }
        if (strcasecmp((string)$row['user_number'], $userNumber) === 0) {
            $idTaken = true;
        }
    }
    $stmt->close();

    if ($emailTaken && $idTaken) {
        return ['ok' => false, 'message' => 'This email and ID number are already registered. To print your card again, use "Reprint my card" below.'];
    }
    if ($emailTaken) {
        return ['ok' => false, 'message' => 'This email is already registered to a library account.'];
    }
    if ($idTaken) {
        return ['ok' => false, 'message' => 'This ID number is already registered to a library account. If it is yours, please see the librarian.'];
    }

    $stmt = $conn->prepare(
        "SELECT verification_id, email_to, user_number,
                TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds
         FROM auth_signup_verifications
         WHERE purpose = 'signup'
           AND used_at IS NULL
           AND expires_at > NOW()
           AND attempt_count < max_attempts
           AND (email_to = ? OR user_number = ?)"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to check pending signups right now.'];
    }
    $stmt->bind_param('ss', $email, $userNumber);
    $stmt->execute();
    $res = $stmt->get_result();
    $pending = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $pending[] = $row;
    }
    $stmt->close();

    $retire = [];
    foreach ($pending as $row) {
        $sameEmail = strcasecmp((string)$row['email_to'], $email) === 0;
        $sameId = strcasecmp((string)$row['user_number'], $userNumber) === 0;

        if ($sameEmail && $sameId) {
            if ((int)$row['age_seconds'] < LIBRARY_CARD_RESEND_COOLDOWN_SECONDS) {
                return ['ok' => false, 'message' => 'A code was just sent to this email. Please wait a minute before requesting another.'];
            }
            $retire[] = (int)$row['verification_id'];
        } elseif ($sameEmail) {
            return ['ok' => false, 'message' => 'A signup for this email is already waiting for verification with a different ID number. Please wait ' . LIBRARY_CARD_CODE_TTL_MINUTES . ' minutes and try again.'];
        } else {
            return ['ok' => false, 'message' => 'A signup for this ID number is already waiting for verification. Please wait ' . LIBRARY_CARD_CODE_TTL_MINUTES . ' minutes and try again.'];
        }
    }

    if (library_card_too_many_requests($conn, $email)) {
        return ['ok' => false, 'message' => 'Too many codes have been requested for this email. Please try again in an hour.'];
    }

    foreach ($retire as $verificationId) {
        library_card_mark_verification_used($conn, $verificationId);
    }

    return ['ok' => true];
}

/** Hourly cap on codes per email, shared by signup and reprint. */
function library_card_too_many_requests(mysqli $conn, string $email): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS n
         FROM auth_signup_verifications
         WHERE email_to = ?
           AND created_at > NOW() - INTERVAL 1 HOUR"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = (int)(($res ? $res->fetch_assoc() : null)['n'] ?? 0);
    $stmt->close();

    return $count >= LIBRARY_CARD_MAX_REQUESTS_PER_HOUR;
}

/**
 * Find the card holder for a reprint request. Both email and ID must match
 * the same account, so knowing one of them isn't enough to trigger a code.
 *
 * Returns ['ok' => true, 'holder' => [...]] or ['ok' => false, 'message' => ...].
 */
function library_card_check_reprint_allowed(mysqli $conn, string $email, string $userNumber): array {
    $stmt = $conn->prepare(
        "SELECT user_id, user_number, first_name, last_name, email, role
         FROM library_users
         WHERE email = ?
           AND user_number = ?
           AND card_issued_at IS NOT NULL
           AND status = 'active'
         LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to look up your card right now.'];
    }
    $stmt->bind_param('ss', $email, $userNumber);
    $stmt->execute();
    $res = $stmt->get_result();
    $holder = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$holder) {
        return ['ok' => false, 'message' => 'No library card matches that email and ID number. If you haven\'t signed up yet, get your card first.'];
    }

    $userId = (int)$holder['user_id'];
    $stmt = $conn->prepare(
        "SELECT verification_id, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds
         FROM auth_signup_verifications
         WHERE purpose = 'reprint'
           AND user_id = ?
           AND used_at IS NULL
           AND expires_at > NOW()
           AND attempt_count < max_attempts"
    );
    $retire = [];
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            if ((int)$row['age_seconds'] < LIBRARY_CARD_RESEND_COOLDOWN_SECONDS) {
                $stmt->close();
                return ['ok' => false, 'message' => 'A code was just sent to this email. Please wait a minute before requesting another.'];
            }
            $retire[] = (int)$row['verification_id'];
        }
        $stmt->close();
    }

    if (library_card_too_many_requests($conn, $email)) {
        return ['ok' => false, 'message' => 'Too many codes have been requested for this email. Please try again in an hour.'];
    }

    foreach ($retire as $verificationId) {
        library_card_mark_verification_used($conn, $verificationId);
    }

    return ['ok' => true, 'holder' => $holder];
}

/**
 * Store a new pending code and return ['verification_id' => ..., 'code' => ...].
 * $purpose is 'signup' (account created on success) or 'reprint' (existing
 * account $userId is shown on success). $pinHash is the password_hash() of a
 * kiosk PIN chosen on the older signup form; new signups pass null and the PIN
 * is chosen after the code is verified (library_card_set_pin.php). The plain
 * PIN never gets here.
 */
function library_card_create_verification(mysqli $conn, array $signup, string $ip, string $purpose = 'signup', ?int $userId = null, ?string $pinHash = null): ?array {
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $maxAttempts = LIBRARY_CARD_MAX_ATTEMPTS;
    $ttl = LIBRARY_CARD_CODE_TTL_MINUTES;

    $programId = $signup['program_id'] ?? null;
    $departmentId = $signup['department_id'] ?? null;
    $schoolLevel = $signup['school_level'] ?? null;
    $yearLevel = $signup['year_level'] ?? null;
    $gender = $signup['gender'] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO auth_signup_verifications (
            purpose, user_id, user_number, account_type, first_name, last_name,
            program_id, department_id, school_level, year_level, gender, email_to,
            code_hash, pin_hash, requested_ip, attempt_count, max_attempts, expires_at, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW() + INTERVAL ? MINUTE, NOW()
        )"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'sissssiisisssssii',
        $purpose, $userId, $signup['user_number'], $signup['account_type'], $signup['first_name'], $signup['last_name'],
        $programId, $departmentId, $schoolLevel, $yearLevel, $gender, $signup['email'], $hash, $pinHash, $ip, $maxAttempts, $ttl
    );
    $ok = $stmt->execute();
    $verificationId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok || $verificationId <= 0) {
        return null;
    }

    return ['verification_id' => $verificationId, 'code' => $code];
}

/** Fetch a pending signup, with is_expired computed by MySQL. */
function library_card_get_verification(mysqli $conn, int $verificationId): ?array {
    $stmt = $conn->prepare(
        "SELECT verification_id, purpose, user_id, user_number, account_type, first_name, last_name,
                program_id, department_id, school_level, year_level, gender, email_to, code_hash, pin_hash, attempt_count, max_attempts, expires_at, used_at,
                (expires_at <= NOW()) AS is_expired
         FROM auth_signup_verifications
         WHERE verification_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $verificationId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

function library_card_mark_verification_used(mysqli $conn, int $verificationId): void {
    $stmt = $conn->prepare("UPDATE auth_signup_verifications SET used_at = NOW() WHERE verification_id = ? AND used_at IS NULL LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $verificationId);
    $stmt->execute();
    $stmt->close();
}

function library_card_increment_attempt(mysqli $conn, int $verificationId): void {
    $stmt = $conn->prepare("UPDATE auth_signup_verifications SET attempt_count = attempt_count + 1 WHERE verification_id = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $verificationId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Create the verified account from a pending signup. $verification['pin_hash']
 * (the signer's chosen kiosk PIN, already hashed: set by library_card_set_pin.php,
 * or staged by the older signup form for in-flight signups) becomes the account's
 * password, so it can sign in to the kiosk right away. A signup staged
 * before pin_hash existed carries NULL and gets a no-PIN account, as before.
 *
 * Returns ['user_id' => ...] or ['error' => 'duplicate' | 'failed'].
 */
function library_card_create_account(mysqli $conn, array $verification): array {
    $role = (string)$verification['account_type'];
    if (!in_array($role, ['student', 'faculty'], true)) {
        return ['error' => 'failed'];
    }

    try {
        $stmt = $conn->prepare(
            "INSERT INTO library_users (
                user_number, first_name, last_name, email, email_verified_at,
                card_issued_at, registration_source, password, program_id, department_id, school_level, year_level, gender,
                role, status, created_at
            ) VALUES (
                ?, ?, ?, ?, NOW(), NOW(), 'self_signup', ?, ?, ?, ?, ?, ?, ?, 'active', NOW()
            )"
        );
        if (!$stmt) {
            return ['error' => 'failed'];
        }
        $pinHash = $verification['pin_hash'] ?? null;
        $programId = $verification['program_id'] !== null ? (int)$verification['program_id'] : null;
        $departmentId = isset($verification['department_id']) && $verification['department_id'] !== null ? (int)$verification['department_id'] : null;
        // Students only. A student signup staged before school_level existed
        // (still in flight at deploy time) carries NULL: that is College.
        $schoolLevel = null;
        if ($role === 'student') {
            $schoolLevel = (string)($verification['school_level'] ?? '');
            if (!isset(LIBRARY_CARD_SCHOOL_LEVELS[$schoolLevel])) {
                $schoolLevel = 'college';
            }
        }
        $yearLevel = $verification['year_level'] !== null ? (int)$verification['year_level'] : null;
        $gender = $verification['gender'];
        $stmt->bind_param(
            'sssssiisiss',
            $verification['user_number'], $verification['first_name'], $verification['last_name'],
            $verification['email_to'], $pinHash, $programId, $departmentId, $schoolLevel, $yearLevel, $gender, $role
        );
        $stmt->execute();
        $userId = (int)$stmt->insert_id;
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        // 1062 = duplicate key: someone registered this email/ID between the
        // signup request and now. The unique keys are the final word.
        return ['error' => $e->getCode() === 1062 ? 'duplicate' : 'failed'];
    }

    if ($userId <= 0) {
        return ['error' => 'failed'];
    }

    $verificationId = (int)$verification['verification_id'];
    $stmt = $conn->prepare("UPDATE auth_signup_verifications SET user_id = ?, used_at = NOW() WHERE verification_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $verificationId);
        $stmt->execute();
        $stmt->close();
    }

    return ['user_id' => $userId];
}

function library_card_get_card_holder(mysqli $conn, int $userId): ?array {
    $stmt = $conn->prepare(
        "SELECT user_id, user_number, first_name, last_name, email, role, card_issued_at
         FROM library_users
         WHERE user_id = ?
           AND card_issued_at IS NOT NULL
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

/** Send the verification code. Returns true only if mail() accepted it. */
function library_card_send_code_email(string $email, string $firstName, string $code, string $purpose = 'signup'): bool {
    $action = $purpose === 'reprint'
        ? 'view and print your library card again'
        : 'get your library card';
    $ignoreLine = $purpose === 'reprint'
        ? 'If you did not ask to reprint your SmartLib library card, you can safely ignore this message.'
        : 'If you did not sign up for a SmartLib library card, you can safely ignore this message.';
    $name = trim($firstName) !== '' ? trim($firstName) : 'there';
    $ttl = LIBRARY_CARD_CODE_TTL_MINUTES;

    require_once __DIR__ . '/auth_email_template.php';
    $subject = 'SMARTLIB LIBRARY CARD VERIFICATION CODE';
    $emailOptions = [
        'variant' => 'code',
        'preheader' => "Your SmartLib verification code is {$code}. It expires in {$ttl} minutes.",
        'eyebrow' => 'LIBRARY CARD',
        'title' => 'Your verification code',
        'intro' => "Hi {$name}, enter this code on the SmartLib page to {$action}.",
        'code' => $code,
        'expiry' => "Expires in {$ttl} minutes",
        'security' => 'Never share this code. Library staff will never ask you for it.',
        'sent_to' => function_exists('auth_mask_email') ? 'Sent to ' . auth_mask_email($email) : '',
        'footer' => $ignoreLine,
    ];
    $html = smartlib_auth_email_html($emailOptions);
    $text = smartlib_auth_email_text($emailOptions);

    return auth_send_multipart_email($email, $subject, $html, $text);
}
