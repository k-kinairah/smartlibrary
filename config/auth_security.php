<?php

const AUTH_LOGIN_FAIL_LIMIT = 5;
const AUTH_LOGIN_LOCK_MINUTES = 5;
const AUTH_2FA_CODE_TTL_MINUTES = 10;
const AUTH_2FA_MAX_ATTEMPTS = 5;
const AUTH_RESET_TOKEN_TTL_MINUTES = 15;
const AUTH_RESET_MAX_REQUESTS_PER_HOUR = 3;
const AUTH_RESET_MIN_SECONDS_BETWEEN_REQUESTS = 60;

function auth_safe_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function auth_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }

        if (strpos($candidate, ',') !== false) {
            $parts = explode(',', $candidate);
            $candidate = trim((string)$parts[0]);
        }

        if ($candidate !== '') {
            return substr($candidate, 0, 64);
        }
    }

    return '0.0.0.0';
}

function auth_mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    $name = $parts[0];
    $domain = $parts[1];

    if (strlen($name) <= 2) {
        return substr($name, 0, 1) . '*' . '@' . $domain;
    }

    return substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 3)) . substr($name, -1) . '@' . $domain;
}

function auth_human_time_left(int $seconds): string {
    $seconds = max(0, $seconds);
    $mins = intdiv($seconds, 60);
    $secs = $seconds % 60;
    if ($mins > 0) {
        return $mins . 'm ' . str_pad((string)$secs, 2, '0', STR_PAD_LEFT) . 's';
    }
    return $secs . 's';
}

function auth_ensure_login_attempts_table(mysqli $conn): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS auth_login_attempts (
            attempt_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_number VARCHAR(80) NOT NULL,
            account_type VARCHAR(20) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            failure_reason VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (attempt_id),
            KEY idx_auth_login_user_time (user_number, created_at),
            KEY idx_auth_login_ip_time (ip_address, created_at),
            KEY idx_auth_login_success_time (success, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    return (bool)auth_safe_query($conn, $sql);
}

function auth_record_login_attempt(mysqli $conn, string $identifier, string $accountType, string $ip, bool $success, string $reason = ''): void {
    $stmt = $conn->prepare(
        "INSERT INTO auth_login_attempts (user_number, account_type, ip_address, success, failure_reason, created_at)
         VALUES (?, ?, ?, ?, NULLIF(?, ''), NOW())"
    );

    if (!$stmt) {
        return;
    }

    $identifier = trim($identifier);
    $accountType = trim($accountType);
    $ip = trim($ip);
    $flag = $success ? 1 : 0;
    $stmt->bind_param('sssds', $identifier, $accountType, $ip, $flag, $reason);
    $stmt->execute();
    $stmt->close();
}

function auth_login_lock_status(mysqli $conn, string $identifier, string $ip, int $limit = AUTH_LOGIN_FAIL_LIMIT, int $windowMinutes = AUTH_LOGIN_LOCK_MINUTES): array {
    $identifier = trim($identifier);
    $ip = trim($ip);
    $windowSeconds = max(60, $windowMinutes * 60);

    $userFails = 0;
    $ipFails = 0;
    $userUnlockAt = 0;
    $ipUnlockAt = 0;
    $dbNowTs = time();

    $stmtUser = $conn->prepare(
        "SELECT COUNT(*) AS fail_count,
                MIN(UNIX_TIMESTAMP(created_at)) AS first_fail_ts,
                UNIX_TIMESTAMP(NOW()) AS now_ts
         FROM auth_login_attempts
         WHERE success = 0
           AND user_number = ?
           AND UNIX_TIMESTAMP(created_at) >= (UNIX_TIMESTAMP(NOW()) - ?)"
    );
    if ($stmtUser) {
        $stmtUser->bind_param('si', $identifier, $windowSeconds);
        $stmtUser->execute();
        $res = $stmtUser->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $userFails = (int)($row['fail_count'] ?? 0);
            $firstTs = (int)($row['first_fail_ts'] ?? 0);
            $nowTs = (int)($row['now_ts'] ?? 0);
            if ($nowTs > 0) {
                $dbNowTs = $nowTs;
            }
            if ($firstTs > 0) {
                $userUnlockAt = $firstTs + $windowSeconds;
            }
        }
        $stmtUser->close();
    }

    $stmtIp = $conn->prepare(
        "SELECT COUNT(*) AS fail_count,
                MIN(UNIX_TIMESTAMP(created_at)) AS first_fail_ts,
                UNIX_TIMESTAMP(NOW()) AS now_ts
         FROM auth_login_attempts
         WHERE success = 0
           AND ip_address = ?
           AND UNIX_TIMESTAMP(created_at) >= (UNIX_TIMESTAMP(NOW()) - ?)"
    );
    if ($stmtIp) {
        $stmtIp->bind_param('si', $ip, $windowSeconds);
        $stmtIp->execute();
        $res = $stmtIp->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $ipFails = (int)($row['fail_count'] ?? 0);
            $firstTs = (int)($row['first_fail_ts'] ?? 0);
            $nowTs = (int)($row['now_ts'] ?? 0);
            if ($nowTs > 0) {
                $dbNowTs = max($dbNowTs, $nowTs);
            }
            if ($firstTs > 0) {
                $ipUnlockAt = $firstTs + $windowSeconds;
            }
        }
        $stmtIp->close();
    }

    $now = $dbNowTs;
    $locked = false;
    $unlockAt = 0;

    if ($userFails >= $limit && $userUnlockAt > $now) {
        $locked = true;
        $unlockAt = $userUnlockAt;
    }

    if ($ipFails >= $limit && $ipUnlockAt > $now) {
        $locked = true;
        if ($unlockAt === 0 || $ipUnlockAt > $unlockAt) {
            $unlockAt = $ipUnlockAt;
        }
    }

    $retryAfter = $unlockAt > $now ? ($unlockAt - $now) : 0;
    if ($retryAfter > $windowSeconds) {
        $retryAfter = $windowSeconds;
    }

    return [
        'locked' => $locked,
        'unlock_at' => $unlockAt,
        'retry_after_seconds' => $retryAfter,
        'user_fail_count' => $userFails,
        'ip_fail_count' => $ipFails,
        'limit' => $limit,
        'window_minutes' => $windowMinutes
    ];
}
function auth_clear_failed_login_attempts(mysqli $conn, string $identifier, string $ip): void {
    $stmt = $conn->prepare(
        "DELETE FROM auth_login_attempts
         WHERE success = 0
           AND (user_number = ? OR ip_address = ?)"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $identifier, $ip);
    $stmt->execute();
    $stmt->close();
}

function auth_ensure_2fa_table(mysqli $conn): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS auth_2fa_challenges (
            challenge_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            user_number VARCHAR(80) NOT NULL,
            account_type VARCHAR(20) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            email_to VARCHAR(190) NOT NULL,
            requested_ip VARCHAR(64) NOT NULL,
            attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (challenge_id),
            KEY idx_auth_2fa_user_state (user_id, used_at, expires_at),
            KEY idx_auth_2fa_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    return (bool)auth_safe_query($conn, $sql);
}

function auth_create_2fa_challenge(mysqli $conn, int $userId, string $userNumber, string $accountType, string $email, string $ip): ?array {
    $disable = $conn->prepare(
        "UPDATE auth_2fa_challenges
         SET used_at = NOW()
         WHERE user_id = ?
           AND used_at IS NULL"
    );
    if ($disable) {
        $disable->bind_param('i', $userId);
        $disable->execute();
        $disable->close();
    }

    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + (AUTH_2FA_CODE_TTL_MINUTES * 60));
    $maxAttempts = AUTH_2FA_MAX_ATTEMPTS;

    $stmt = $conn->prepare(
        "INSERT INTO auth_2fa_challenges (
            user_id, user_number, account_type, code_hash, email_to,
            requested_ip, attempt_count, max_attempts, expires_at, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW()
        )"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('isssssis', $userId, $userNumber, $accountType, $hash, $email, $ip, $maxAttempts, $expiresAt);
    $ok = $stmt->execute();
    $challengeId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok || $challengeId <= 0) {
        return null;
    }

    return [
        'challenge_id' => $challengeId,
        'code' => $code,
        'expires_at' => $expiresAt
    ];
}

function auth_get_2fa_challenge(mysqli $conn, int $challengeId, int $userId): ?array {
    $stmt = $conn->prepare(
        "SELECT challenge_id, user_id, user_number, account_type, code_hash,
                email_to, requested_ip, attempt_count, max_attempts, expires_at,
                used_at, created_at
         FROM auth_2fa_challenges
         WHERE challenge_id = ?
           AND user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $challengeId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

function auth_mark_2fa_used(mysqli $conn, int $challengeId): void {
    $stmt = $conn->prepare("UPDATE auth_2fa_challenges SET used_at = NOW() WHERE challenge_id = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $challengeId);
    $stmt->execute();
    $stmt->close();
}

function auth_increment_2fa_attempt(mysqli $conn, int $challengeId): void {
    $stmt = $conn->prepare(
        "UPDATE auth_2fa_challenges
         SET attempt_count = attempt_count + 1
         WHERE challenge_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $challengeId);
    $stmt->execute();
    $stmt->close();
}

function auth_ensure_reset_table(mysqli $conn): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS auth_pin_reset_tokens (
            token_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            user_number VARCHAR(80) NOT NULL,
            account_type VARCHAR(20) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            email_to VARCHAR(190) NOT NULL,
            requested_ip VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token_id),
            UNIQUE KEY uniq_auth_pin_reset_hash (token_hash),
            KEY idx_auth_pin_reset_user_time (user_number, created_at),
            KEY idx_auth_pin_reset_ip_time (requested_ip, created_at),
            KEY idx_auth_pin_reset_state (used_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    return (bool)auth_safe_query($conn, $sql);
}

function auth_reset_rate_limit_status(mysqli $conn, string $identifier, string $ip): array {
    $identifier = trim($identifier);
    $ip = trim($ip);

    $hourCutoff = date('Y-m-d H:i:s', time() - 3600);
    $minuteCutoff = date('Y-m-d H:i:s', time() - AUTH_RESET_MIN_SECONDS_BETWEEN_REQUESTS);

    $countHour = 0;
    $lastCreatedAt = '';

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS req_count, MAX(created_at) AS last_created_at
         FROM auth_pin_reset_tokens
         WHERE (user_number = ? OR requested_ip = ?)
           AND created_at >= ?"
    );

    if ($stmt) {
        $stmt->bind_param('sss', $identifier, $ip, $hourCutoff);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $countHour = (int)($row['req_count'] ?? 0);
            $lastCreatedAt = trim((string)($row['last_created_at'] ?? ''));
        }
        $stmt->close();
    }

    $tooMany = $countHour >= AUTH_RESET_MAX_REQUESTS_PER_HOUR;
    $cooldown = false;

    if ($lastCreatedAt !== '') {
        $lastTs = strtotime($lastCreatedAt);
        if ($lastTs !== false && $lastTs >= strtotime($minuteCutoff)) {
            $cooldown = true;
        }
    }

    return [
        'too_many' => $tooMany,
        'cooldown' => $cooldown,
        'count_hour' => $countHour,
        'limit_hour' => AUTH_RESET_MAX_REQUESTS_PER_HOUR,
        'min_seconds_between_requests' => AUTH_RESET_MIN_SECONDS_BETWEEN_REQUESTS
    ];
}

function auth_create_reset_token(mysqli $conn, int $userId, string $userNumber, string $accountType, string $email, string $ip): ?array {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (AUTH_RESET_TOKEN_TTL_MINUTES * 60));

    $stmt = $conn->prepare(
        "INSERT INTO auth_pin_reset_tokens (
            user_id, user_number, account_type, token_hash,
            email_to, requested_ip, expires_at, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW()
        )"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('issssss', $userId, $userNumber, $accountType, $tokenHash, $email, $ip, $expiresAt);
    $ok = $stmt->execute();
    $tokenId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok || $tokenId <= 0) {
        return null;
    }

    return [
        'token' => $rawToken,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
        'token_id' => $tokenId
    ];
}

function auth_get_valid_reset_token(mysqli $conn, string $rawToken): ?array {
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $conn->prepare(
        "SELECT token_id, user_id, user_number, account_type, email_to,
                expires_at, used_at, created_at
         FROM auth_pin_reset_tokens
         WHERE token_hash = ?
           AND used_at IS NULL
           AND expires_at >= NOW()
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

function auth_mark_reset_token_used(mysqli $conn, int $tokenId): void {
    $stmt = $conn->prepare("UPDATE auth_pin_reset_tokens SET used_at = NOW() WHERE token_id = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $tokenId);
    $stmt->execute();
    $stmt->close();
}

function auth_disable_other_reset_tokens(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare(
        "UPDATE auth_pin_reset_tokens
         SET used_at = NOW()
         WHERE user_id = ?
           AND used_at IS NULL"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function auth_send_multipart_email(string $toEmail, string $subject, string $htmlBody, string $textBody, array $inlineImages = []): bool {
    $safeEmail = trim($toEmail);
    if ($safeEmail === '' || !filter_var($safeEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeSubject = $subject;
    if (function_exists('mb_encode_mimeheader')) {
        $safeSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    $altBoundary = 'smartlib_auth_alt_' . bin2hex(random_bytes(8));
    $relatedBoundary = 'smartlib_auth_rel_' . bin2hex(random_bytes(8));
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: SMARTLIB <no-reply@smartlib.local>';
    $headers[] = 'Reply-To: no-reply@smartlib.local';
    $headers[] = 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"';

    $message = "--{$relatedBoundary}\r\n"
        . "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n"
        . "--{$altBoundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $textBody . "\r\n\r\n"
        . "--{$altBoundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n\r\n"
        . "--{$altBoundary}--\r\n";

    foreach ($inlineImages as $img) {
        $cid = trim((string)($img['cid'] ?? ''));
        $path = (string)($img['path'] ?? '');

        if ($cid === '' || $path === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }

        $mime = trim((string)($img['mime'] ?? ''));
        if ($mime === '') {
            $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $mime = 'image/jpeg';
            } elseif ($ext === 'gif') {
                $mime = 'image/gif';
            } elseif ($ext === 'webp') {
                $mime = 'image/webp';
            } else {
                $mime = 'image/png';
            }
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }

        $filename = basename($path);
        $message .= "--{$relatedBoundary}\r\n"
            . "Content-Type: {$mime}; name=\"{$filename}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-ID: <{$cid}>\r\n"
            . "Content-Disposition: inline; filename=\"{$filename}\"\r\n\r\n"
            . chunk_split(base64_encode($raw), 76, "\r\n")
            . "\r\n";
    }

    $message .= "--{$relatedBoundary}--";

    return (bool)@mail($safeEmail, $safeSubject, $message, implode("\r\n", $headers));
}
function auth_build_absolute_url(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $baseDir = trim((string)dirname((string)($_SERVER['PHP_SELF'] ?? '/')));
    $baseDir = str_replace('\\', '/', $baseDir);
    $baseDir = rtrim($baseDir, '/');
    if ($baseDir === '') {
        $baseDir = '/';
    }

    $cleanPath = '/' . ltrim($path, '/');

    if ($baseDir !== '/' && strpos($cleanPath, $baseDir . '/') !== 0) {
        $cleanPath = $baseDir . $cleanPath;
    }

    return $scheme . '://' . $host . $cleanPath;
}

function auth_finalize_session_login(array $user, string $normalizedRole): void {
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)($user['user_id'] ?? 0);
    $_SESSION['user_number'] = (string)($user['user_number'] ?? '');
    $_SESSION['role'] = $normalizedRole;
    $_SESSION['name'] = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
    $_SESSION['last_auth_at'] = time();

    unset($_SESSION['pending_2fa']);
}

?>



