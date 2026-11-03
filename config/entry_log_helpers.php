<?php
/**
 * Library door entry log (library_entry_logs).
 *
 * A librarian scans a card at the door (admin/entry_scan.php); each accepted
 * scan is one entry event. Entry only -- exit tracking is still an open
 * question for the librarian. Requires library_card_helpers.php for the
 * shared ID normalizing.
 *
 * Program, year level and gender are copied onto each row at scan time, so
 * a student moving up a year or changing program doesn't rewrite past
 * reports. All times come from MySQL's clock.
 */

// A second scan of the same person within this window is treated as an
// accidental double scan: acknowledged, but not logged again.
const ENTRY_LOG_REPEAT_SECONDS = 60;

/**
 * Handle one scanned or typed ID. Always returns an array with a 'status':
 * 'ok' (logged), 'duplicate' (just logged, not logged again), 'not_found',
 * 'inactive', 'invalid' or 'error', plus the person's details when known.
 */
function entry_log_scan(mysqli $conn, string $rawId, int $scannedByUserId): array {
    $userNumber = library_card_normalize_id($rawId);
    if (!library_card_id_format_ok($userNumber)) {
        return ['status' => 'invalid', 'scanned' => $userNumber];
    }

    $stmt = $conn->prepare(
        "SELECT u.user_id, u.user_number, u.first_name, u.last_name, u.role, u.status,
                u.program_id, u.year_level, u.gender, p.program_name
         FROM library_users u
         LEFT JOIN programs p ON p.program_id = u.program_id
         WHERE u.user_number = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return ['status' => 'error'];
    }
    $stmt->bind_param('s', $userNumber);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$user) {
        return ['status' => 'not_found', 'scanned' => library_card_display_id($userNumber)];
    }

    $person = [
        'name' => trim((string)$user['first_name'] . ' ' . (string)$user['last_name']),
        'user_number' => library_card_display_id((string)$user['user_number']),
        'role' => ucfirst((string)$user['role']),
        'program' => (string)($user['program_name'] ?? ''),
    ];

    if ((string)$user['status'] !== 'active') {
        return ['status' => 'inactive'] + $person;
    }

    $userId = (int)$user['user_id'];
    $stmt = $conn->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds,
                DATE_FORMAT(created_at, '%l:%i %p') AS time_label
         FROM library_entry_logs
         WHERE user_id = ?
         ORDER BY entry_id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return ['status' => 'error'] + $person;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $last = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($last && (int)$last['age_seconds'] < ENTRY_LOG_REPEAT_SECONDS) {
        return ['status' => 'duplicate', 'time' => trim((string)$last['time_label'])] + $person;
    }

    $role = (string)$user['role'];
    $programId = $user['program_id'] !== null ? (int)$user['program_id'] : null;
    $yearLevel = $user['year_level'] !== null ? (int)$user['year_level'] : null;
    $gender = $user['gender'];
    $storedNumber = (string)$user['user_number'];

    $stmt = $conn->prepare(
        "INSERT INTO library_entry_logs (
            user_id, user_number, account_type, program_id, year_level, gender, scanned_by_user_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return ['status' => 'error'] + $person;
    }
    $stmt->bind_param('issiisi', $userId, $storedNumber, $role, $programId, $yearLevel, $gender, $scannedByUserId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return ['status' => 'error'] + $person;
    }

    $time = $conn->query("SELECT DATE_FORMAT(NOW(), '%l:%i %p') AS t");
    return ['status' => 'ok', 'time' => trim((string)($time ? $time->fetch_row()[0] : ''))] + $person;
}

function entry_log_today_count(mysqli $conn): int {
    $res = $conn->query("SELECT COUNT(*) FROM library_entry_logs WHERE created_at >= CURDATE()");
    return $res ? (int)$res->fetch_row()[0] : 0;
}

/** Today's latest entries, newest first, for the scan screen. */
function entry_log_recent(mysqli $conn, int $limit = 8): array {
    $stmt = $conn->prepare(
        "SELECT e.user_number, e.account_type, u.first_name, u.last_name,
                DATE_FORMAT(e.created_at, '%l:%i %p') AS time_label
         FROM library_entry_logs e
         LEFT JOIN library_users u ON u.user_id = e.user_id
         WHERE e.created_at >= CURDATE()
         ORDER BY e.entry_id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = [
            'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
            'user_number' => library_card_display_id((string)$row['user_number']),
            'role' => ucfirst((string)$row['account_type']),
            'time' => trim((string)$row['time_label']),
        ];
    }
    $stmt->close();
    return $rows;
}
