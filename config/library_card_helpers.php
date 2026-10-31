<?php
/**
 * Self-registration for the digital library card.
 *
 * Students/faculty sign up with their PHINMA email and school ID number,
 * confirm a 6-digit emailed code, and get a library_users row with a card
 * but no PIN -- kiosk access stays librarian-created, as before.
 *
 * Pending signups live in auth_signup_verifications (see
 * database/library_card_setup.sql). All expiry/age checks are done in SQL
 * with NOW() rather than PHP's date(), because PHP's timezone isn't
 * configured on this install and would disagree with MySQL's.
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

// Report demographics. Year level is students only; faculty store NULL.
// College programs here are all 4-year (no 5-year programs offered), so
// there is no "5th year or above" choice -- this form is for the college
// side of the school only; it doesn't cover the Grade 1-12 basic
// education side at all.
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

/** Same list and order as the admin user form (admin/manage_users.php). */
function library_card_get_programs(mysqli $conn): array {
    $programs = [];
    $res = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
    while ($res && ($row = $res->fetch_assoc())) {
        $programs[(int)$row['program_id']] = (string)$row['program_name'];
    }
    return $programs;
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
 * account $userId is shown on success).
 */
function library_card_create_verification(mysqli $conn, array $signup, string $ip, string $purpose = 'signup', ?int $userId = null): ?array {
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $maxAttempts = LIBRARY_CARD_MAX_ATTEMPTS;
    $ttl = LIBRARY_CARD_CODE_TTL_MINUTES;

    $programId = $signup['program_id'] ?? null;
    $yearLevel = $signup['year_level'] ?? null;
    $gender = $signup['gender'] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO auth_signup_verifications (
            purpose, user_id, user_number, account_type, first_name, last_name,
            program_id, year_level, gender, email_to,
            code_hash, requested_ip, attempt_count, max_attempts, expires_at, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW() + INTERVAL ? MINUTE, NOW()
        )"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'sissssiissssii',
        $purpose, $userId, $signup['user_number'], $signup['account_type'], $signup['first_name'], $signup['last_name'],
        $programId, $yearLevel, $gender, $signup['email'], $hash, $ip, $maxAttempts, $ttl
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
                program_id, year_level, gender, email_to, code_hash, attempt_count, max_attempts, expires_at, used_at,
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
 * Create the verified account from a pending signup. No PIN is set, so the
 * account can't sign in to the kiosk until a librarian sets one up.
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
                card_issued_at, registration_source, password, program_id, year_level, gender,
                role, status, created_at
            ) VALUES (
                ?, ?, ?, ?, NOW(), NOW(), 'self_signup', NULL, ?, ?, ?, ?, 'active', NOW()
            )"
        );
        if (!$stmt) {
            return ['error' => 'failed'];
        }
        $programId = $verification['program_id'] !== null ? (int)$verification['program_id'] : null;
        $yearLevel = $verification['year_level'] !== null ? (int)$verification['year_level'] : null;
        $gender = $verification['gender'];
        $stmt->bind_param(
            'ssssiiss',
            $verification['user_number'], $verification['first_name'], $verification['last_name'],
            $verification['email_to'], $programId, $yearLevel, $gender, $role
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
    $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES, 'UTF-8');
    $ttl = LIBRARY_CARD_CODE_TTL_MINUTES;
    $logoCid = 'smartlib_logo';
    $logoPath = __DIR__ . '/../assets/images/stjude_logo.jpg';
    $inlineImages = [];
    if (is_file($logoPath) && is_readable($logoPath)) {
        $inlineImages[] = ['cid' => $logoCid, 'path' => $logoPath, 'mime' => 'image/jpeg'];
        $logoBadgeHtml = '<img src="cid:' . $logoCid . '" alt="SMARTLIB" width="42" height="42" style="display:block;margin:0 auto 8px;width:42px;height:42px;border-radius:50%;background:#ffffff;padding:2px;">';
    } else {
        $logoBadgeHtml = '<div style="display:inline-block;margin:0 auto 8px;width:42px;height:42px;line-height:42px;border-radius:50%;background:#ffffff;color:#0f5132;font-weight:700;">SL</div>';
    }

    $subject = 'SMARTLIB LIBRARY CARD VERIFICATION CODE';
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMARTLIB Library Card</title>
</head>
<body style="margin:0;padding:0;background:#eef3f8;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f8;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;">
          <tr>
            <td style="background:#0f5132;padding:18px 24px;color:#ffffff;text-align:center;">
              {$logoBadgeHtml}
              <div style="font-size:18px;font-weight:700;letter-spacing:.6px;color:#ffd84d;">SMARTLIB</div>
              <div style="font-size:12px;opacity:.9;margin-top:4px;text-align:center;">Digital Library Card</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;text-align:center;">
              <h1 style="margin:0 0 10px;font-size:24px;line-height:1.25;color:#0f172a;">Your verification code</h1>
              <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#475569;">Hi {$name}, enter this code on the SmartLib page to {$action}.</p>
              <div style="display:inline-block;margin:0 auto 18px;padding:12px 22px;border-radius:10px;background:#f1f5f9;border:1px solid #dbe4ee;font-size:30px;font-weight:700;letter-spacing:8px;color:#0f5132;">{$code}</div>
              <p style="margin:0;font-size:14px;line-height:1.6;color:#475569;text-align:center;">This code expires in <strong>{$ttl} minutes</strong>.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 24px 22px;font-size:12px;line-height:1.6;color:#64748b;text-align:center;">{$ignoreLine}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    $text = "SMARTLIB Library Card\n\n"
        . "Hi " . ($firstName !== '' ? $firstName : 'there') . ",\n"
        . "Your verification code to {$action} is: {$code}\n\n"
        . "It expires in {$ttl} minutes. {$ignoreLine}";

    return auth_send_multipart_email($email, $subject, $html, $text, $inlineImages);
}
