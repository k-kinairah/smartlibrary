<?php
/**
 * Helper functions for admin/manage_books.php.
 *
 * Pulled out of the main script (which mixed these with page-rendering
 * logic) so the file is easier to navigate. Behavior is unchanged --
 * this is a straight move, not a rewrite.
 */

function table_exists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $dbRes = $conn->query('SELECT DATABASE() AS db');
    $db = $dbRes ? (string)($dbRes->fetch_assoc()['db'] ?? '') : '';
    if ($db === '') return false;
    $db = $conn->real_escape_string($db);

    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = '$db' AND table_name = '$table' LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $dbRes = $conn->query('SELECT DATABASE() AS db');
    $db = $dbRes ? (string)($dbRes->fetch_assoc()['db'] ?? '') : '';
    if ($db === '') return false;
    $db = $conn->real_escape_string($db);

    $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = '$db' AND table_name = '$table' AND column_name = '$column' LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function get_or_create_location_id(
    mysqli $conn,
    string $section,
    string $classCode,
    string $className,
    string $shelf,
    string $row,
    string $position
): int {
    $find = $conn->prepare(
        'SELECT location_id FROM library_locations
         WHERE section = ? AND class_code = ? AND shelf_code = ? AND row_label = ? AND position_label = ?
         LIMIT 1'
    );

    if ($find) {
        $find->bind_param('sssss', $section, $classCode, $shelf, $row, $position);
        $find->execute();
        $res = $find->get_result();
        if ($res && ($rowData = $res->fetch_assoc())) {
            $find->close();
            return (int)($rowData['location_id'] ?? 0);
        }
        $find->close();
    }

    $insert = $conn->prepare(
        'INSERT INTO library_locations (section, class_code, class_name, shelf_code, row_label, position_label, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );

    if (!$insert) {
        return 0;
    }

    $insert->bind_param('ssssss', $section, $classCode, $className, $shelf, $row, $position);
    $insert->execute();
    $newId = (int)$insert->insert_id;
    $insert->close();

    return $newId;
}

function build_legacy_location_text(string $section, string $classCode, string $shelf, string $row, string $position, string $callNumber): string {
    $locationParts = [];
    if ($section !== '') $locationParts[] = 'Section: ' . $section;
    if ($classCode !== '') $locationParts[] = 'Class: ' . $classCode;
    if ($shelf !== '') $locationParts[] = 'Shelf: ' . $shelf;
    if ($row !== '') $locationParts[] = 'Row: ' . $row;
    if ($position !== '') $locationParts[] = 'Position: ' . $position;
    if ($callNumber !== '') $locationParts[] = 'Call: ' . $callNumber;
    return implode(' | ', $locationParts);
}

function manage_books_parse_accession_inputs($rawInput): array {
    $values = [];
    if (is_array($rawInput)) {
        $values = $rawInput;
    } elseif (is_string($rawInput) && trim($rawInput) !== '') {
        $split = preg_split('/[\r\n,;]+/', $rawInput);
        if (is_array($split)) {
            $values = $split;
        }
    }

    $normalized = [];
    foreach ($values as $value) {
        $normalized[] = trim((string)$value);
    }

    return $normalized;
}

function manage_books_has_duplicate_values(array $values): bool {
    $seen = [];
    foreach ($values as $value) {
        $key = strtolower(trim((string)$value));
        if ($key === '') {
            continue;
        }
        if (isset($seen[$key])) {
            return true;
        }
        $seen[$key] = true;
    }
    return false;
}

function manage_books_accession_exists(mysqli $conn, string $accessionNo): bool {
    $token = trim($accessionNo);
    if ($token === '') {
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM book_copies WHERE accession_no = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = (bool)($res && $res->num_rows > 0);
    $stmt->close();

    return $exists;
}

function manage_books_effective_copy_status(string $copyStatusRaw, string $loanStatusRaw): string {
    $copyStatus = strtolower(trim($copyStatusRaw));
    $loanStatus = strtolower(trim($loanStatusRaw));

    foreach ([$loanStatus, $copyStatus] as $status) {
        if (in_array($status, ['missing', 'overdue', 'borrowed'], true)) {
            return $status;
        }
    }

    if ($copyStatus === 'available') {
        return 'available';
    }

    return 'unknown';
}

function manage_books_copy_status_is_locked(string $effectiveStatus): bool {
    return in_array(strtolower(trim($effectiveStatus)), ['borrowed', 'overdue', 'missing'], true);
}

function manage_books_fetch_book_copies(mysqli $conn, int $bookId): array {
    if ($bookId <= 0) {
        return [];
    }

    $rows = [];
    $sql = "
        SELECT
            bc.copy_id,
            bc.book_id,
            COALESCE(NULLIF(TRIM(bc.accession_no), ''), CONCAT('Copy #', bc.copy_id)) AS accession_no,
            LOWER(COALESCE(NULLIF(TRIM(bc.status), ''), 'unknown')) AS copy_status,
            LOWER(COALESCE(ar.status, '')) AS loan_status
        FROM book_copies bc
        LEFT JOIN (
            SELECT br1.copy_id, br1.record_id, br1.status
            FROM borrow_records br1
            INNER JOIN (
                SELECT copy_id, MAX(record_id) AS max_record_id
                FROM borrow_records
                WHERE status IN ('borrowed', 'overdue', 'missing')
                GROUP BY copy_id
            ) latest ON latest.max_record_id = br1.record_id
        ) ar ON ar.copy_id = bc.copy_id
        WHERE bc.book_id = ?
        ORDER BY bc.copy_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $copyStatus = (string)($row['copy_status'] ?? '');
            $loanStatus = (string)($row['loan_status'] ?? '');
            $effectiveStatus = manage_books_effective_copy_status($copyStatus, $loanStatus);
            $rows[] = [
                'copy_id' => (int)($row['copy_id'] ?? 0),
                'book_id' => (int)($row['book_id'] ?? 0),
                'accession_no' => trim((string)($row['accession_no'] ?? '')),
                'copy_status' => $copyStatus,
                'loan_status' => $loanStatus,
                'effective_status' => $effectiveStatus,
                'is_locked' => manage_books_copy_status_is_locked($effectiveStatus)
            ];
        }
    }
    $stmt->close();

    return $rows;
}

function manage_books_ensure_activity_log_table(mysqli $conn): bool {
    return smartlib_admin_audit_ensure_table($conn);
}

function manage_books_actor_user_id(): int {
    return smartlib_admin_audit_actor_user_id();
}

function manage_books_actor_name(): string {
    return smartlib_admin_audit_actor_name();
}

function manage_books_log_activity(mysqli $conn, string $actionType, int $entityId, string $entityTitle, array $metadata = []): void {
    smartlib_admin_audit_log($conn, $actionType, 'book', $entityId, $entityTitle, $metadata);
}
