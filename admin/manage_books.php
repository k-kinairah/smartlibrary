<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}
require '../config/db_connect.php';

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

function manage_books_csrf_token(): string {
    if (empty($_SESSION['manage_books_csrf']) || !is_string($_SESSION['manage_books_csrf'])) {
        $_SESSION['manage_books_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['manage_books_csrf'];
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
    $sql = "
        CREATE TABLE IF NOT EXISTS admin_activity_logs (
            activity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id INT NULL,
            actor_name VARCHAR(160) NOT NULL,
            action_type ENUM('added','deleted') NOT NULL,
            entity_type VARCHAR(40) NOT NULL DEFAULT 'book',
            entity_id INT NULL,
            entity_title VARCHAR(255) NOT NULL,
            metadata_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (activity_id),
            KEY idx_activity_created_at (created_at),
            KEY idx_activity_action_entity (action_type, entity_type),
            KEY idx_activity_actor (actor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        return (bool)$conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function manage_books_actor_user_id(): int {
    $sessionKeys = ['admin_id', 'user_id', 'librarian_id'];
    foreach ($sessionKeys as $key) {
        $value = (int)($_SESSION[$key] ?? 0);
        if ($value > 0) {
            return $value;
        }
    }
    return 0;
}

function manage_books_actor_name(): string {
    $name = trim((string)($_SESSION['name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($_SESSION['full_name'] ?? ''));
    }
    if ($name === '') {
        $name = trim((string)($_SESSION['username'] ?? ''));
    }
    if ($name === '') {
        $name = 'System Admin';
    }
    return $name;
}

function manage_books_log_activity(mysqli $conn, string $actionType, int $entityId, string $entityTitle, array $metadata = []): void {
    if (!in_array($actionType, ['added', 'deleted'], true)) {
        return;
    }

    if (!manage_books_ensure_activity_log_table($conn)) {
        return;
    }

    $actorUserId = manage_books_actor_user_id();
    $actorName = manage_books_actor_name();
    $safeTitle = trim($entityTitle);
    if ($safeTitle === '') {
        $safeTitle = 'Untitled Book';
    }

    $metaJson = '';
    if (!empty($metadata)) {
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $metaJson = $encoded;
        }
    }

    $stmt = $conn->prepare(
        "INSERT INTO admin_activity_logs (
            actor_user_id, actor_name, action_type, entity_type,
            entity_id, entity_title, metadata_json, created_at
        ) VALUES (
            NULLIF(?, 0), ?, ?, 'book', NULLIF(?, 0), ?, NULLIF(?, ''), NOW()
        )"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ississ', $actorUserId, $actorName, $actionType, $entityId, $safeTitle, $metaJson);
    $stmt->execute();
    $stmt->close();
}

$categories = $conn->query('SELECT category_id, category_name FROM categories ORDER BY category_name ASC');
$programs = $conn->query('SELECT program_id, program_name FROM programs ORDER BY program_name ASC');

$categoryOptions = [];
if ($categories instanceof mysqli_result) {
    while ($categoryRow = $categories->fetch_assoc()) {
        $categoryOptions[] = [
            'category_id' => (int)($categoryRow['category_id'] ?? 0),
            'category_name' => (string)($categoryRow['category_name'] ?? '')
        ];
    }
}

$programOptions = [];
if ($programs instanceof mysqli_result) {
    while ($programRow = $programs->fetch_assoc()) {
        $programOptions[] = [
            'program_id' => (int)($programRow['program_id'] ?? 0),
            'program_name' => (string)($programRow['program_name'] ?? '')
        ];
    }
}

$librarySections = [
    'General Collection',
    'Reference Collection',
    'Filipiniana Collection',
    'Reserved Collection',
    'Periodicals Collection'
];

$libraryClassNames = [
    'A' => 'General Works',
    'B' => 'Philosophy, Psychology, Religion',
    'C' => 'Auxiliary Sciences of History',
    'D' => 'World History',
    'E/F' => 'History of the Americas',
    'G' => 'Geography, Anthropology, Recreation',
    'H' => 'Social Sciences',
    'J' => 'Political Science',
    'K' => 'Law',
    'L' => 'Education',
    'M' => 'Music',
    'N' => 'Fine Arts',
    'P' => 'Language and Literature',
    'Q' => 'Science',
    'R' => 'Medicine',
    'S' => 'Agriculture',
    'T' => 'Technology',
    'U' => 'Military Science',
    'V' => 'Naval Science',
    'Z' => 'Bibliography / Library Science'
];

$libraryClassLabels = [];
foreach ($libraryClassNames as $code => $name) {
    $libraryClassLabels[$code] = $code . ' - ' . $name;
}

$shelfOptions = [];
foreach (['YA', 'GC', 'REF', 'RES', 'NUR', 'PSY'] as $prefix) {
    $max = $prefix === 'YA' ? 12 : 8;
    for ($i = 1; $i <= $max; $i++) {
        $shelfOptions[] = sprintf('%s-%02d', $prefix, $i);
    }
}

$rowOptions = ['First row', 'Second row', 'Third row', 'Fourth row', 'Fifth row'];
$positionOptions = ['Eye Level', 'Upper Shelf', 'Middle Shelf', 'Lower Shelf', 'Bottom Shelf'];

$hasLocationTable = table_exists($conn, 'library_locations');
$hasBookLocationId = column_exists($conn, 'books', 'location_id');
$hasBookCallNumber = column_exists($conn, 'books', 'call_number');
$hasLegacyLocation = column_exists($conn, 'books', 'location');

$csrfToken = manage_books_csrf_token();
$flash = ['type' => '', 'message' => ''];
$openAddBookModal = false;
$openEditBookModal = false;
$hasBookCopiesTable = table_exists($conn, 'book_copies');
$addBookForm = [
    'isbn' => '',
    'title' => '',
    'author' => '',
    'publisher' => '',
    'year_published' => '',
    'category_id' => 0,
    'program_id' => 0,
    'location_section' => '',
    'location_class' => '',
    'location_shelf' => '',
    'location_row' => '',
    'location_position' => '',
    'call_number' => '',
    'copy_count' => '',
    'accession_numbers' => ['']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        header('Location: manage_books.php');
        exit;
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $author = trim((string)($_POST['author'] ?? ''));
    $publisher = trim((string)($_POST['publisher'] ?? ''));
    $year = trim((string)($_POST['year_published'] ?? ''));
    $isbn = trim((string)($_POST['isbn'] ?? ''));

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);

    $locationSection = trim((string)($_POST['location_section'] ?? ''));
    $locationClass = trim((string)($_POST['location_class'] ?? ''));
    $locationShelf = trim((string)($_POST['location_shelf'] ?? ''));
    $locationRow = trim((string)($_POST['location_row'] ?? ''));
    $locationPosition = trim((string)($_POST['location_position'] ?? ''));
    $callNumber = trim((string)($_POST['call_number'] ?? ''));

    $copyCountRaw = trim((string)($_POST['copy_count'] ?? ''));
    $copyCount = 0;
    if ($copyCountRaw !== '' && ctype_digit($copyCountRaw)) {
        $copyCount = (int)$copyCountRaw;
    }

    $accessionInputsRaw = $_POST['accession_numbers'] ?? [];
    if (!is_array($accessionInputsRaw)) {
        $accessionInputsRaw = manage_books_parse_accession_inputs($accessionInputsRaw);
    }

    $accessionInputs = [];
    foreach ($accessionInputsRaw as $rawAccession) {
        $accessionInputs[] = trim((string)$rawAccession);
    }

    $addBookForm = [
        'isbn' => $isbn,
        'title' => $title,
        'author' => $author,
        'publisher' => $publisher,
        'year_published' => $year,
        'category_id' => $categoryId,
        'program_id' => $programId,
        'location_section' => $locationSection,
        'location_class' => $locationClass,
        'location_shelf' => $locationShelf,
        'location_row' => $locationRow,
        'location_position' => $locationPosition,
        'call_number' => $callNumber,
        'copy_count' => $copyCountRaw,
        'accession_numbers' => $accessionInputs
    ];

    $errorMessage = '';

    if ($title === '') {
        $errorMessage = 'Title is required.';
    } elseif ($callNumber === '') {
        $errorMessage = 'Call Number is required.';
    } elseif (!$hasBookCopiesTable) {
        $errorMessage = 'Book copies table is missing. Please contact the system administrator.';
    } elseif ($copyCountRaw === '' || !ctype_digit($copyCountRaw) || $copyCount < 1 || $copyCount > 200) {
        $errorMessage = 'Please enter a valid Number of Copies (1-200).';
    }

    if ($errorMessage === '' && $isbn !== '') {
        $dupIsbnStmt = $conn->prepare('SELECT 1 FROM books WHERE isbn = ? LIMIT 1');
        if ($dupIsbnStmt) {
            $dupIsbnStmt->bind_param('s', $isbn);
            $dupIsbnStmt->execute();
            $dupIsbnRes = $dupIsbnStmt->get_result();
            if ($dupIsbnRes && $dupIsbnRes->num_rows > 0) {
                $errorMessage = 'ISBN already exists. Each book must have a unique ISBN.';
            }
            $dupIsbnStmt->close();
        }
    }

    $manualAccessions = [];
    if ($errorMessage === '') {
        for ($i = 0; $i < $copyCount; $i++) {
            $token = trim((string)($accessionInputs[$i] ?? ''));
            if ($token === '') {
                $errorMessage = 'Please provide an accession number for each copy.';
                break;
            }
            $manualAccessions[] = $token;
        }

        if ($errorMessage === '' && manage_books_has_duplicate_values($manualAccessions)) {
            $errorMessage = 'Accession numbers must be unique per copy.';
        }

        if ($errorMessage === '') {
            foreach ($manualAccessions as $token) {
                if (strlen($token) > 50) {
                    $errorMessage = 'Each accession number must be 50 characters or less.';
                    break;
                }
                if (manage_books_accession_exists($conn, $token)) {
                    $errorMessage = 'Accession number "' . $token . '" is already in use.';
                    break;
                }
            }
        }
    }

    if ($errorMessage !== '') {
        $flash = ['type' => 'error', 'message' => $errorMessage];
        $openAddBookModal = true;
    } else {
        $legacyLocationText = build_legacy_location_text($locationSection, $locationClass, $locationShelf, $locationRow, $locationPosition, $callNumber);

        $locationId = 0;
        if ($hasLocationTable && $hasBookLocationId && $locationSection !== '' && $locationClass !== '' && $locationShelf !== '' && $locationRow !== '' && $locationPosition !== '') {
            $className = (string)($libraryClassNames[$locationClass] ?? 'General Works');
            $locationId = get_or_create_location_id(
                $conn,
                $locationSection,
                $locationClass,
                $className,
                $locationShelf,
                $locationRow,
                $locationPosition
            );
        }

        $stmt = null;
        $copyStmt = null;

        try {
            $conn->begin_transaction();

            if ($hasLocationTable && $hasBookLocationId) {
                if ($hasBookCallNumber) {
                    $stmt = $conn->prepare(
                        "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location_id, call_number, created_at)
                         VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), NOW())"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare book insert statement.');
                    }
                    $stmt->bind_param('sssssiiis', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $locationId, $callNumber);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location_id, created_at)
                         VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NOW())"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare book insert statement.');
                    }
                    $stmt->bind_param('sssssiii', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $locationId);
                }
            } elseif ($hasLegacyLocation) {
                $stmt = $conn->prepare(
                    "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location, created_at)
                     VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, NOW())"
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare book insert statement.');
                }
                $stmt->bind_param('sssssiis', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $legacyLocationText);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, created_at)
                     VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NOW())"
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare book insert statement.');
                }
                $stmt->bind_param('sssssii', $isbn, $title, $author, $publisher, $year, $categoryId, $programId);
            }

            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to save book details.');
            }

            $didInsert = $stmt->affected_rows > 0;
            $newBookId = (int)$conn->insert_id;
            $stmt->close();
            $stmt = null;

            if (!$didInsert || $newBookId <= 0) {
                throw new RuntimeException('Book record was not created.');
            }

            $copyStmt = $conn->prepare(
                "INSERT INTO book_copies (book_id, accession_no, isbn, status, created_at)
                 VALUES (?, NULLIF(?, ''), NULLIF(?, ''), 'available', NOW())"
            );
            if (!$copyStmt) {
                throw new RuntimeException('Unable to prepare copy insert statement.');
            }

            $insertedCopies = 0;
            $copyIsbn = substr($isbn, 0, 20);

            for ($i = 0; $i < $copyCount; $i++) {
                $accessionNo = trim((string)($accessionInputs[$i] ?? ''));
                if ($accessionNo === '') {
                    throw new RuntimeException('Missing accession number for copy #' . ($i + 1) . '.');
                }

                $copyStmt->bind_param('iss', $newBookId, $accessionNo, $copyIsbn);
                if (!$copyStmt->execute()) {
                    throw new RuntimeException('Unable to save copy #' . ($i + 1) . '.');
                }

                if ($copyStmt->affected_rows > 0) {
                    $insertedCopies++;
                }
            }

            $copyStmt->close();
            $copyStmt = null;

            manage_books_log_activity(
                $conn,
                'added',
                $newBookId,
                $title,
                [
                    'isbn' => $isbn,
                    'author' => $author,
                    'year_published' => $year,
                    'inserted_copies' => $insertedCopies,
                    'requested_copies' => $copyCount
                ]
            );

            $conn->commit();
            header('Location: manage_books.php');
            exit;
        } catch (Throwable $e) {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            if ($copyStmt instanceof mysqli_stmt) {
                $copyStmt->close();
            }

            $conn->rollback();

            $msg = (string)$e->getMessage();
            if (stripos($msg, 'duplicate') !== false && stripos($msg, 'isbn') !== false) {
                $msg = 'ISBN already exists. Each book must have a unique ISBN.';
            } elseif (stripos($msg, 'duplicate') !== false && stripos($msg, 'accession') !== false) {
                $msg = 'One of the accession numbers already exists. Please review the copy accessions.';
            } elseif ($msg === '') {
                $msg = 'Unable to add book and copies right now. Please try again.';
            }

            $flash = ['type' => 'error', 'message' => $msg];
            $openAddBookModal = true;
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_book'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        header('Location: manage_books.php');
        exit;
    }

    $bookId = (int)($_POST['book_id'] ?? 0);

    $title = trim((string)($_POST['title'] ?? ''));
    $author = trim((string)($_POST['author'] ?? ''));
    $publisher = trim((string)($_POST['publisher'] ?? ''));
    $year = trim((string)($_POST['year_published'] ?? ''));
    $isbn = trim((string)($_POST['isbn'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);

    $locationSection = trim((string)($_POST['location_section'] ?? ''));
    $locationClass = trim((string)($_POST['location_class'] ?? ''));
    $locationShelf = trim((string)($_POST['location_shelf'] ?? ''));
    $locationRow = trim((string)($_POST['location_row'] ?? ''));
    $locationPosition = trim((string)($_POST['location_position'] ?? ''));
    $callNumber = trim((string)($_POST['call_number'] ?? ''));
    $copyCountRaw = trim((string)($_POST['copy_count'] ?? ''));
    $desiredCopyCount = 0;
    if ($copyCountRaw !== '' && ctype_digit($copyCountRaw)) {
        $desiredCopyCount = (int)$copyCountRaw;
    }

    $editCopyAccessionsRaw = $_POST['edit_copy_accessions'] ?? [];
    if (!is_array($editCopyAccessionsRaw)) {
        $editCopyAccessionsRaw = [];
    }
    $editCopyAccessions = [];
    foreach ($editCopyAccessionsRaw as $copyIdRaw => $accRaw) {
        $copyId = (int)$copyIdRaw;
        if ($copyId <= 0) {
            continue;
        }
        $editCopyAccessions[$copyId] = trim((string)$accRaw);
    }

    $newAccessionsRaw = $_POST['new_accession_numbers'] ?? [];
    if (!is_array($newAccessionsRaw)) {
        $newAccessionsRaw = manage_books_parse_accession_inputs((string)$newAccessionsRaw);
    }
    $newAccessionsInput = [];
    foreach ($newAccessionsRaw as $rawValue) {
        $newAccessionsInput[] = trim((string)$rawValue);
    }

    $existingCopyRows = $hasBookCopiesTable ? manage_books_fetch_book_copies($conn, $bookId) : [];
    $currentCopyTotal = count($existingCopyRows);

    $editFormInput = [
        'book_id' => $bookId,
        'isbn' => $isbn,
        'title' => $title,
        'author' => $author,
        'publisher' => $publisher,
        'year_published' => $year,
        'category_id' => $categoryId,
        'program_id' => $programId,
        'location_section' => $locationSection,
        'location_class' => $locationClass,
        'location_shelf' => $locationShelf,
        'location_row' => $locationRow,
        'location_position' => $locationPosition,
        'call_number' => $callNumber,
        'copy_count' => $copyCountRaw,
        'edit_copy_accessions' => $editCopyAccessions,
        'new_accession_numbers' => $newAccessionsInput
    ];

    $errorMessage = '';
    if ($bookId <= 0) {
        $errorMessage = 'Invalid book selection.';
    } elseif ($title === '') {
        $errorMessage = 'Title is required.';
    } elseif ($callNumber === '') {
        $errorMessage = 'Call Number is required.';
    } elseif (!$hasBookCopiesTable) {
        $errorMessage = 'Book copies table is missing. Please contact the system administrator.';
    } elseif ($copyCountRaw === '' || !ctype_digit($copyCountRaw) || $desiredCopyCount < 1 || $desiredCopyCount > 200) {
        $errorMessage = 'Please enter a valid Number of Copies (1-200).';
    }

    if ($errorMessage === '' && $isbn !== '') {
        $dupIsbnStmt = $conn->prepare('SELECT 1 FROM books WHERE isbn = ? AND book_id <> ? LIMIT 1');
        if ($dupIsbnStmt) {
            $dupIsbnStmt->bind_param('si', $isbn, $bookId);
            $dupIsbnStmt->execute();
            $dupIsbnRes = $dupIsbnStmt->get_result();
            if ($dupIsbnRes && $dupIsbnRes->num_rows > 0) {
                $errorMessage = 'ISBN already exists. Each book must have a unique ISBN.';
            }
            $dupIsbnStmt->close();
        }
    }

    $copyRowsById = [];
    $removableCopyIds = [];
    foreach ($existingCopyRows as $copyRow) {
        $copyId = (int)($copyRow['copy_id'] ?? 0);
        if ($copyId <= 0) {
            continue;
        }
        $copyRowsById[$copyId] = $copyRow;
        if (!(bool)($copyRow['is_locked'] ?? false)) {
            $removableCopyIds[] = $copyId;
        }
    }

    foreach ($editCopyAccessions as $copyId => $accessionValue) {
        if (!isset($copyRowsById[$copyId])) {
            $errorMessage = 'Invalid copy entry in accession list.';
            break;
        }
        if ((bool)($copyRowsById[$copyId]['is_locked'] ?? false)) {
            $currentAccession = trim((string)($copyRowsById[$copyId]['accession_no'] ?? ''));
            if ($accessionValue !== '' && strcasecmp($accessionValue, $currentAccession) !== 0) {
                $errorMessage = 'Borrowed/overdue/missing copies cannot change accession numbers.';
                break;
            }
        }
    }

    $copyDeleteIds = [];
    $newAccessionsToInsert = [];
    $finalExistingAccessions = [];
    if ($errorMessage === '') {
        $toRemove = max(0, $currentCopyTotal - $desiredCopyCount);
        $toAdd = max(0, $desiredCopyCount - $currentCopyTotal);

        if ($toRemove > 0 && count($removableCopyIds) < $toRemove) {
            $errorMessage = 'Cannot reduce copies that far. Some copies are currently borrowed/overdue/missing.';
        } else {
            if ($toRemove > 0) {
                $removableDescending = array_values(array_reverse($removableCopyIds));
                $copyDeleteIds = array_slice($removableDescending, 0, $toRemove);
            }

            foreach ($copyRowsById as $copyId => $copyRow) {
                if (in_array($copyId, $copyDeleteIds, true)) {
                    continue;
                }

                $currentAccession = trim((string)($copyRow['accession_no'] ?? ''));
                $nextAccession = $currentAccession;
                if (array_key_exists($copyId, $editCopyAccessions)) {
                    $nextAccession = trim((string)$editCopyAccessions[$copyId]);
                }

                if ($nextAccession === '') {
                    $errorMessage = 'Accession number is required for every retained copy.';
                    break;
                }
                if (strlen($nextAccession) > 50) {
                    $errorMessage = 'Each accession number must be 50 characters or less.';
                    break;
                }

                $finalExistingAccessions[$copyId] = $nextAccession;
            }

            if ($errorMessage === '' && $toAdd > 0) {
                for ($i = 0; $i < $toAdd; $i++) {
                    $token = trim((string)($newAccessionsInput[$i] ?? ''));
                    if ($token === '') {
                        $errorMessage = 'Please provide accession numbers for all additional copies.';
                        break;
                    }
                    if (strlen($token) > 50) {
                        $errorMessage = 'Each accession number must be 50 characters or less.';
                        break;
                    }
                    $newAccessionsToInsert[] = $token;
                }
            }

            if ($errorMessage === '') {
                $tokenOwnerByKey = [];
                $tokenValueByKey = [];

                foreach ($finalExistingAccessions as $copyId => $token) {
                    $key = strtolower($token);
                    if (isset($tokenOwnerByKey[$key])) {
                        $errorMessage = 'Accession numbers must be unique across copies.';
                        break;
                    }
                    $tokenOwnerByKey[$key] = $copyId;
                    $tokenValueByKey[$key] = $token;
                }

                if ($errorMessage === '') {
                    foreach ($newAccessionsToInsert as $token) {
                        $key = strtolower($token);
                        if (isset($tokenOwnerByKey[$key])) {
                            $errorMessage = 'Accession numbers must be unique across copies.';
                            break;
                        }
                        $tokenOwnerByKey[$key] = 0;
                        $tokenValueByKey[$key] = $token;
                    }
                }

                if ($errorMessage === '') {
                    $dupAccStmt = $conn->prepare('SELECT copy_id FROM book_copies WHERE accession_no = ? LIMIT 1');
                    if ($dupAccStmt) {
                        foreach ($tokenValueByKey as $key => $token) {
                            $expectedCopyId = (int)($tokenOwnerByKey[$key] ?? 0);
                            $dupAccStmt->bind_param('s', $token);
                            $dupAccStmt->execute();
                            $dupAccRes = $dupAccStmt->get_result();
                            $existingCopyId = $dupAccRes ? (int)(($dupAccRes->fetch_assoc()['copy_id'] ?? 0)) : 0;

                            if ($existingCopyId > 0) {
                                if ($expectedCopyId > 0 && $existingCopyId === $expectedCopyId) {
                                    continue;
                                }
                                if (in_array($existingCopyId, $copyDeleteIds, true)) {
                                    continue;
                                }
                                $errorMessage = 'Accession number "' . $token . '" is already in use.';
                                break;
                            }
                        }
                        $dupAccStmt->close();
                    }
                }
            }
        }
    }

    if ($errorMessage !== '') {
        $flash = ['type' => 'error', 'message' => $errorMessage];
        $openEditBookModal = true;
        $editId = $bookId;
    } else {
        $legacyLocationText = build_legacy_location_text($locationSection, $locationClass, $locationShelf, $locationRow, $locationPosition, $callNumber);
        $copyIsbn = substr($isbn, 0, 20);

        $conn->begin_transaction();
        try {
            $coreUpd = $conn->prepare(
                "UPDATE books
                 SET isbn = ?,
                     title = ?,
                     author = ?,
                     publisher = ?,
                     year_published = ?,
                     category_id = NULLIF(?, 0),
                     program_id = NULLIF(?, 0)
                 WHERE book_id = ?"
            );
            if (!$coreUpd) {
                throw new RuntimeException('Unable to update book details right now.');
            }
            $coreUpd->bind_param('sssssiii', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $bookId);
            $coreUpd->execute();
            $coreUpd->close();

            if ($hasLocationTable && $hasBookLocationId) {
                $locationId = 0;
                if ($locationSection !== '' && $locationClass !== '' && $locationShelf !== '' && $locationRow !== '' && $locationPosition !== '') {
                    $className = (string)($libraryClassNames[$locationClass] ?? 'General Works');
                    $locationId = get_or_create_location_id(
                        $conn,
                        $locationSection,
                        $locationClass,
                        $className,
                        $locationShelf,
                        $locationRow,
                        $locationPosition
                    );
                }

                $locUpd = $conn->prepare('UPDATE books SET location_id = NULLIF(?, 0) WHERE book_id = ?');
                if ($locUpd) {
                    $locUpd->bind_param('ii', $locationId, $bookId);
                    $locUpd->execute();
                    $locUpd->close();
                }
            } elseif ($hasLegacyLocation) {
                $legacyUpd = $conn->prepare('UPDATE books SET location = ? WHERE book_id = ?');
                if ($legacyUpd) {
                    $legacyUpd->bind_param('si', $legacyLocationText, $bookId);
                    $legacyUpd->execute();
                    $legacyUpd->close();
                }
            }

            if ($hasBookCallNumber) {
                $callUpd = $conn->prepare("UPDATE books SET call_number = NULLIF(?, '') WHERE book_id = ?");
                if ($callUpd) {
                    $callUpd->bind_param('si', $callNumber, $bookId);
                    $callUpd->execute();
                    $callUpd->close();
                }
            }

            $copyIsbnStmt = $conn->prepare('UPDATE book_copies SET isbn = ? WHERE book_id = ?');
            if ($copyIsbnStmt) {
                $copyIsbnStmt->bind_param('si', $copyIsbn, $bookId);
                $copyIsbnStmt->execute();
                $copyIsbnStmt->close();
            }

            $accUpdStmt = $conn->prepare('UPDATE book_copies SET accession_no = ?, isbn = ? WHERE copy_id = ? AND book_id = ? LIMIT 1');
            if (!$accUpdStmt) {
                throw new RuntimeException('Unable to update accession numbers right now.');
            }
            foreach ($finalExistingAccessions as $copyId => $token) {
                $accUpdStmt->bind_param('ssii', $token, $copyIsbn, $copyId, $bookId);
                $accUpdStmt->execute();
            }
            $accUpdStmt->close();

            if (!empty($copyDeleteIds)) {
                $copyDeleteStmt = $conn->prepare('DELETE FROM book_copies WHERE copy_id = ? AND book_id = ? LIMIT 1');
                if (!$copyDeleteStmt) {
                    throw new RuntimeException('Unable to reduce copy count right now.');
                }
                foreach ($copyDeleteIds as $copyId) {
                    $copyDeleteStmt->bind_param('ii', $copyId, $bookId);
                    $copyDeleteStmt->execute();
                }
                $copyDeleteStmt->close();
            }

            if (!empty($newAccessionsToInsert)) {
                $copyInsertStmt = $conn->prepare(
                    "INSERT INTO book_copies (book_id, accession_no, isbn, status, created_at)
                     VALUES (?, ?, ?, 'available', NOW())"
                );
                if (!$copyInsertStmt) {
                    throw new RuntimeException('Unable to add new copies right now.');
                }
                foreach ($newAccessionsToInsert as $token) {
                    $copyInsertStmt->bind_param('iss', $bookId, $token, $copyIsbn);
                    $copyInsertStmt->execute();
                }
                $copyInsertStmt->close();
            }

            $conn->commit();
            header('Location: manage_books.php?updated=1');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = trim((string)$e->getMessage());
            if ($msg === '') {
                $msg = 'Unable to update book details right now. Please try again.';
            }

            $flash = ['type' => 'error', 'message' => $msg];
            $openEditBookModal = true;
            $editId = $bookId;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_book'])) {
    $currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
    if (!in_array($currentRole, ['admin', 'librarian'], true)) {
        header('Location: ../index.php');
        exit;
    }

    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        header('Location: manage_books.php');
        exit;
    }

    $bookId = (int)($_POST['book_id'] ?? 0);
    if ($bookId > 0) {
        $bookSnapshot = null;
        $snapshotStmt = $conn->prepare(
            "SELECT
                b.book_id,
                b.title,
                b.isbn,
                b.author,
                b.year_published,
                COALESCE(COUNT(bc.copy_id), 0) AS total_copies_before_delete
             FROM books b
             LEFT JOIN book_copies bc ON bc.book_id = b.book_id
             WHERE b.book_id = ?
             GROUP BY b.book_id
             LIMIT 1"
        );
        if ($snapshotStmt) {
            $snapshotStmt->bind_param('i', $bookId);
            $snapshotStmt->execute();
            $snapshotRes = $snapshotStmt->get_result();
            if ($snapshotRes) {
                $bookSnapshot = $snapshotRes->fetch_assoc() ?: null;
            }
            $snapshotStmt->close();
        }

        $deleteStmt = $conn->prepare('DELETE FROM books WHERE book_id = ?');
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $bookId);
            $deleteStmt->execute();
            $didDelete = $deleteStmt->affected_rows > 0;
            $deleteStmt->close();

            if ($didDelete && is_array($bookSnapshot)) {
                $archivedBookId = (int)($bookSnapshot['book_id'] ?? $bookId);
                $archivedTitle = trim((string)($bookSnapshot['title'] ?? ''));
                if ($archivedTitle === '') {
                    $archivedTitle = 'Book #' . $archivedBookId;
                }
                $deleteMeta = [
                    'isbn' => (string)($bookSnapshot['isbn'] ?? ''),
                    'author' => (string)($bookSnapshot['author'] ?? ''),
                    'year_published' => (string)($bookSnapshot['year_published'] ?? ''),
                    'total_copies_before_delete' => (int)($bookSnapshot['total_copies_before_delete'] ?? 0)
                ];

                manage_books_log_activity($conn, 'deleted', $archivedBookId, $archivedTitle, $deleteMeta);
            }
        }
    }

    header('Location: manage_books.php');
    exit;
}


$editBook = null;
$editId = isset($editId) ? (int)$editId : 0;
if ($editId <= 0) {
    $editId = (int)($_GET['edit'] ?? 0);
}
if ($editId <= 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_book'])) {
    $editId = (int)($_POST['book_id'] ?? 0);
}
if ($editId > 0) {
    $locationJoin = ($hasLocationTable && $hasBookLocationId)
        ? ' LEFT JOIN library_locations loc ON b.location_id = loc.location_id '
        : '';

    $locationSelect = ($hasLocationTable && $hasBookLocationId)
        ? ", loc.section AS loc_section, loc.class_code AS loc_class_code, loc.shelf_code AS loc_shelf_code, loc.row_label AS loc_row_label, loc.position_label AS loc_position_label"
        : '';

    $callSelect = $hasBookCallNumber ? ', b.call_number AS db_call_number' : '';

    $editRes = $conn->query(
        "SELECT
            b.book_id,
            b.title,
            b.isbn,
            b.author,
            b.publisher,
            b.year_published,
            b.category_id,
            b.program_id,
            b.location
            {$locationSelect}
            {$callSelect}
         FROM books b
         {$locationJoin}
         WHERE b.book_id = {$editId}
         LIMIT 1"
    );

    if ($editRes) {
        $editBook = $editRes->fetch_assoc() ?: null;
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$activeStatus = strtolower(trim((string)($_GET['status'] ?? '')));
$allowedStatus = ['', 'available', 'borrowed', 'no_copies', 'newly_added'];
if (!in_array($activeStatus, $allowedStatus, true)) {
    $activeStatus = '';
}

$buildManageBooksUrl = static function (array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            $clean[(string)$key] = $text;
        }
    }

    return 'manage_books.php' . (!empty($clean) ? ('?' . http_build_query($clean)) : '');
};

$baseFilterParams = [
    'search' => $search
];

$whereParts = [];
if ($search !== '') {
    $safeSearch = $conn->real_escape_string($search);
    $whereParts[] = "(
        b.isbn LIKE '%{$safeSearch}%'
        OR b.title LIKE '%{$safeSearch}%'
        OR b.author LIKE '%{$safeSearch}%'
        OR b.year_published LIKE '%{$safeSearch}%'
        OR c.category_name LIKE '%{$safeSearch}%'
        OR p.program_name LIKE '%{$safeSearch}%'
        OR bc.accession_no LIKE '%{$safeSearch}%'
    )";
}

$whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$havingSql = '';
if ($activeStatus === 'available') {
    $havingSql = "HAVING COALESCE(SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END), 0) > 0";
} elseif ($activeStatus === 'borrowed') {
    $havingSql = "HAVING COALESCE(SUM(CASE WHEN LOWER(COALESCE(NULLIF(TRIM(bc.status), ''), 'unknown')) IN ('borrowed', 'overdue', 'missing') THEN 1 ELSE 0 END), 0) > 0";
} elseif ($activeStatus === 'no_copies') {
    $havingSql = "HAVING COALESCE(COUNT(bc.copy_id), 0) = 0";
} elseif ($activeStatus === 'newly_added') {
    $havingSql = "HAVING MAX(CASE WHEN DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) = 1";
}

$books = $conn->query(
    "
    SELECT
        b.*,
        c.category_name AS genre_name,
        p.program_name AS program_name,
        COALESCE(loc.section, '') AS location_section,
        COALESCE(loc.class_code, '') AS location_class,
        COALESCE(loc.shelf_code, '') AS location_shelf,
        COALESCE(loc.row_label, '') AS location_row,
        COALESCE(loc.position_label, '') AS location_position,
        COALESCE(SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END), 0) AS available_copies,
        COALESCE(SUM(CASE WHEN LOWER(COALESCE(NULLIF(TRIM(bc.status), ''), 'unknown')) IN ('borrowed', 'overdue', 'missing') THEN 1 ELSE 0 END), 0) AS borrowed_copies,
        MAX(CASE WHEN DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS is_newly_added,
        COALESCE(COUNT(bc.copy_id), 0) AS total_copies
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN programs p ON b.program_id = p.program_id
    LEFT JOIN book_copies bc ON bc.book_id = b.book_id
    LEFT JOIN library_locations loc ON loc.location_id = b.location_id
    {$whereSql}
    GROUP BY b.book_id
    {$havingSql}
    ORDER BY b.created_at DESC
    "
);

$bookRows = [];
if ($books) {
    while ($bookRow = $books->fetch_assoc()) {
        $bookRows[] = $bookRow;
    }
}

$bookCopyMap = [];
if (!empty($bookRows)) {
    $bookIds = [];
    foreach ($bookRows as $row) {
        $bookId = (int)($row['book_id'] ?? 0);
        if ($bookId > 0) {
            $bookIds[$bookId] = $bookId;
        }
    }

    if (!empty($bookIds)) {
        $idList = implode(',', $bookIds);
        $copyRes = $conn->query(
            "
            SELECT
                bc.book_id,
                bc.copy_id,
                COALESCE(NULLIF(TRIM(bc.accession_no), ''), CONCAT('Copy #', bc.copy_id)) AS accession_no,
                LOWER(COALESCE(NULLIF(TRIM(bc.status), ''), 'unknown')) AS copy_status,
                ar.status AS loan_status,
                u.user_number,
                u.first_name,
                u.last_name
            FROM book_copies bc
            LEFT JOIN (
                SELECT br1.copy_id, br1.record_id, br1.status, br1.user_id
                FROM borrow_records br1
                INNER JOIN (
                    SELECT copy_id, MAX(record_id) AS max_record_id
                    FROM borrow_records
                    WHERE status IN ('borrowed', 'overdue', 'missing')
                    GROUP BY copy_id
                ) latest ON latest.max_record_id = br1.record_id
            ) ar ON ar.copy_id = bc.copy_id
            LEFT JOIN library_users u ON u.user_id = ar.user_id
            WHERE bc.book_id IN ({$idList})
            ORDER BY bc.book_id ASC, bc.copy_id ASC
            "
        );

        if ($copyRes) {
            while ($copyRow = $copyRes->fetch_assoc()) {
                $bookId = (int)($copyRow['book_id'] ?? 0);
                if ($bookId > 0) {
                    if (!isset($bookCopyMap[$bookId])) {
                        $bookCopyMap[$bookId] = [];
                    }
                    $bookCopyMap[$bookId][] = $copyRow;
                }
            }
        }
    }
}

$totalBooks = 0;
$availableBooks = 0;
$borrowedBooks = 0;
$noCopiesBooks = 0;
$newlyAddedBooks = 0;

$countsRes = $conn->query(
    "
    SELECT
        COUNT(*) AS total_books,
        SUM(CASE WHEN x.available_copies > 0 THEN 1 ELSE 0 END) AS available_books,
        SUM(CASE WHEN x.borrowed_copies > 0 THEN 1 ELSE 0 END) AS borrowed_books,
        SUM(CASE WHEN x.total_copies = 0 THEN 1 ELSE 0 END) AS no_copies_books,
        SUM(CASE WHEN x.is_newly_added = 1 THEN 1 ELSE 0 END) AS newly_added_books
    FROM (
        SELECT
            b.book_id,
            COALESCE(SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END), 0) AS available_copies,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(NULLIF(TRIM(bc.status), ''), 'unknown')) IN ('borrowed', 'overdue', 'missing') THEN 1 ELSE 0 END), 0) AS borrowed_copies,
            MAX(CASE WHEN DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS is_newly_added,
            COALESCE(COUNT(bc.copy_id), 0) AS total_copies
        FROM books b
        LEFT JOIN book_copies bc ON bc.book_id = b.book_id
        GROUP BY b.book_id
    ) x
    "
);

if ($countsRes && ($countsRow = $countsRes->fetch_assoc())) {
    $totalBooks = (int)($countsRow['total_books'] ?? 0);
    $availableBooks = (int)($countsRow['available_books'] ?? 0);
    $borrowedBooks = (int)($countsRow['borrowed_books'] ?? 0);
    $noCopiesBooks = (int)($countsRow['no_copies_books'] ?? 0);
    $newlyAddedBooks = (int)($countsRow['newly_added_books'] ?? 0);
}

$chipMetrics = [
    ['status' => '', 'title' => 'Total Books', 'count' => $totalBooks],
    ['status' => 'available', 'title' => 'Available', 'count' => $availableBooks],
    ['status' => 'borrowed', 'title' => 'Borrowed', 'count' => $borrowedBooks],
    ['status' => 'no_copies', 'title' => 'No Copies', 'count' => $noCopiesBooks],
    ['status' => 'newly_added', 'title' => 'Newly Added', 'count' => $newlyAddedBooks]
];

$updatedFlag = isset($_GET['updated']) && (string)$_GET['updated'] === '1';

$editCopyRows = [];
$editCopyTotal = 0;
$editRemovableCount = 0;
if ($editBook && $hasBookCopiesTable) {
    $editCopyRows = manage_books_fetch_book_copies($conn, (int)($editBook['book_id'] ?? 0));
    $editCopyTotal = count($editCopyRows);
    foreach ($editCopyRows as $copyRow) {
        if (!(bool)($copyRow['is_locked'] ?? false)) {
            $editRemovableCount++;
        }
    }
}
$editLockedCount = max(0, $editCopyTotal - $editRemovableCount);

$editForm = [
    'book_id' => (int)($editBook['book_id'] ?? 0),
    'isbn' => (string)($editBook['isbn'] ?? ''),
    'title' => (string)($editBook['title'] ?? ''),
    'author' => (string)($editBook['author'] ?? ''),
    'publisher' => (string)($editBook['publisher'] ?? ''),
    'year_published' => (string)($editBook['year_published'] ?? ''),
    'category_id' => (int)($editBook['category_id'] ?? 0),
    'program_id' => (int)($editBook['program_id'] ?? 0),
    'location_section' => 'General Collection',
    'location_class' => 'T',
    'location_shelf' => 'YA-05',
    'location_row' => 'First row',
    'location_position' => 'Eye Level',
    'call_number' => '',
    'copy_count' => $editCopyTotal > 0 ? (string)$editCopyTotal : '',
    'edit_copy_accessions' => [],
    'new_accession_numbers' => []
];

if ($editBook) {
    $editForm['location_section'] = (string)($editBook['loc_section'] ?? $editForm['location_section']);
    $editForm['location_class'] = (string)($editBook['loc_class_code'] ?? $editForm['location_class']);
    $editForm['location_shelf'] = (string)($editBook['loc_shelf_code'] ?? $editForm['location_shelf']);
    $editForm['location_row'] = (string)($editBook['loc_row_label'] ?? $editForm['location_row']);
    $editForm['location_position'] = (string)($editBook['loc_position_label'] ?? $editForm['location_position']);
    $editForm['call_number'] = (string)($editBook['db_call_number'] ?? '');
}

if (isset($editFormInput) && !empty($editFormInput) && is_array($editFormInput)) {
    foreach ($editFormInput as $formKey => $formValue) {
        if (array_key_exists($formKey, $editForm)) {
            $editForm[$formKey] = $formValue;
        }
    }
}

$editCopyAccessionInputs = [];
if (is_array($editForm['edit_copy_accessions'] ?? null)) {
    foreach ($editForm['edit_copy_accessions'] as $copyIdRaw => $accessionRaw) {
        $copyId = (int)$copyIdRaw;
        if ($copyId <= 0) {
            continue;
        }
        $editCopyAccessionInputs[$copyId] = trim((string)$accessionRaw);
    }
}

$editNewAccessionsInput = [];
if (is_array($editForm['new_accession_numbers'] ?? null)) {
    foreach ($editForm['new_accession_numbers'] as $token) {
        $editNewAccessionsInput[] = trim((string)$token);
    }
}

foreach ($editCopyRows as $copyIndex => $copyRow) {
    $copyId = (int)($copyRow['copy_id'] ?? 0);
    $statusRaw = strtolower(trim((string)($copyRow['effective_status'] ?? 'unknown')));
    $statusLabel = ucfirst(str_replace('_', ' ', $statusRaw));
    $inputValue = (string)($copyRow['accession_no'] ?? '');
    if (isset($editCopyAccessionInputs[$copyId])) {
        $inputValue = $editCopyAccessionInputs[$copyId];
    }

    $editCopyRows[$copyIndex]['status_label'] = $statusLabel;
    $editCopyRows[$copyIndex]['input_accession'] = $inputValue;
}

$editDesiredCopyCount = null;
$editCopyCountRaw = trim((string)($editForm['copy_count'] ?? ''));
if ($editCopyCountRaw !== '' && ctype_digit($editCopyCountRaw)) {
    $tmpCopyCount = (int)$editCopyCountRaw;
    if ($tmpCopyCount >= 1 && $tmpCopyCount <= 200) {
        $editDesiredCopyCount = $tmpCopyCount;
    }
}
$editAdditionalCopyCount = $editDesiredCopyCount !== null
    ? max(0, $editDesiredCopyCount - $editCopyTotal)
    : count($editNewAccessionsInput);

if ($editAdditionalCopyCount > 0 && count($editNewAccessionsInput) < $editAdditionalCopyCount) {
    $editNewAccessionsInput = array_pad($editNewAccessionsInput, $editAdditionalCopyCount, '');
} elseif ($editAdditionalCopyCount > 0) {
    $editNewAccessionsInput = array_slice($editNewAccessionsInput, 0, $editAdditionalCopyCount);
} else {
    $editNewAccessionsInput = [];
}
require 'layout_top.php';
?>

<div class="page-top">
    <h1>Manage Books</h1>
    <button class="btn-primary" onclick="openBookModal()">+ Add Book</button>
</div>

<?php if ($updatedFlag): ?>
    <section class="panel glass-card borrow-flash borrow-flash-success">
        Book updated successfully.
    </section>
<?php endif; ?>

<?php if (($flash['message'] ?? '') !== ''): ?>
    <section class="panel glass-card borrow-flash <?= ($flash['type'] ?? '') === 'error' ? 'borrow-flash-error' : 'borrow-flash-info' ?>">
        <?= htmlspecialchars((string)($flash['message'] ?? '')) ?>
    </section>
<?php endif; ?>

<section class="stats-grid borrow-status-chips">
    <?php foreach ($chipMetrics as $chip): ?>
        <?php
            $chipStatus = (string)($chip['status'] ?? '');
            $chipParams = $baseFilterParams;
            if ($chipStatus !== '') {
                $chipParams['status'] = $chipStatus;
            }
            $isActiveChip = ($chipStatus === '' && $activeStatus === '') || ($chipStatus !== '' && $activeStatus === $chipStatus);
            $chipHref = $buildManageBooksUrl($chipParams);
        ?>
        <a href="<?= htmlspecialchars($chipHref) ?>" class="stat-card glass-card borrow-chip<?= $isActiveChip ? ' is-active' : '' ?>">
            <h3><?= htmlspecialchars((string)($chip['title'] ?? '')) ?></h3>
            <p class="value"><?= number_format((int)($chip['count'] ?? 0)) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="panel glass-card">
    <form method="GET" class="filters-inline manage-books-filters borrow-filters">
        <input type="hidden" name="status" value="<?= htmlspecialchars($activeStatus) ?>">
        <input type="text" name="search" placeholder="Search ISBN, title, author, year, accession" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn-primary">Apply</button>
        <a href="manage_books.php" class="btn-status activate">Reset</a>
    </form>
</section>

<section class="panel glass-card">
    <div class="table-wrap">
        <table class="data-table manage-books-table">
            <thead>
                <tr>
                    <th>Book ID</th>
                    <th><?= $activeStatus === 'borrowed' ? 'Accession #' : 'ISBN' ?></th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Year</th>
                    <th>Genre</th>
                    <th>Copies</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($bookRows)): ?>
                    <?php foreach ($bookRows as $row): ?>
                        <?php
                            $bookId = (int)($row['book_id'] ?? 0);
                            $available = (int)($row['available_copies'] ?? 0);
                            $total = (int)($row['total_copies'] ?? 0);
                            $copiesForRow = $bookCopyMap[$bookId] ?? [];
                            $borrowedAccessionNos = [];
                            foreach ($copiesForRow as $copyRowForBook) {
                                $copyState = strtolower((string)($copyRowForBook['copy_status'] ?? ''));
                                $loanState = strtolower((string)($copyRowForBook['loan_status'] ?? ''));
                                if (in_array($copyState, ['borrowed', 'overdue', 'missing'], true) || in_array($loanState, ['borrowed', 'overdue', 'missing'], true)) {
                                    $accText = trim((string)($copyRowForBook['accession_no'] ?? ''));
                                    if ($accText !== '') {
                                        $borrowedAccessionNos[$accText] = true;
                                    }
                                }
                            }
                            $primaryIdentifier = (string)($row['isbn'] ?? '-');
                            if ($activeStatus === 'borrowed') {
                                $primaryIdentifier = !empty($borrowedAccessionNos) ? implode(', ', array_keys($borrowedAccessionNos)) : '-';
                            }
                            if ($total === 0) {
                                $statusText = 'No copies';
                                $statusClass = 'inactive';
                            } elseif ($available > 0) {
                                $statusText = 'Available';
                                $statusClass = 'active';
                            } else {
                                $statusText = 'Borrowed';
                                $statusClass = 'inactive';
                            }
                        ?>
                        <tr>
                            <td class="book-id-col"><?= $bookId ?></td>
                            <td><?= htmlspecialchars($primaryIdentifier) ?></td>
                            <td><?= htmlspecialchars($row['title'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['author'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['year_published'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['genre_name'] ?? 'Uncategorized') ?></td>
                            <td>
                                <div class="book-copies-cell">
                                    <span><?= number_format($available) ?> / <?= number_format($total) ?></span>
                                    <?php if ($total > 0): ?>
                                        <button
                                            type="button"
                                            class="book-copy-toggle-btn"
                                            data-target="copy-details-<?= $bookId ?>"
                                            aria-expanded="false">
                                            View
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge status-<?= htmlspecialchars($statusClass) ?>">
                                    <?= htmlspecialchars($statusText) ?>
                                </span>
                            </td>
                            <td class="book-actions-cell">
                                <a
                                    class="btn-status user-pin-btn user-action-icon-btn"
                                    href="?edit=<?= $bookId ?>"
                                    aria-label="Update book">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M4 20h4.2l9.7-9.7-4.2-4.2L4 15.8V20Z"></path>
                                        <path d="m12.8 7.2 4.2 4.2"></path>
                                    </svg>
                                </a>
                                <form method="POST" class="row-action-form delete-book-form" data-book-title="<?= htmlspecialchars((string)($row['title'] ?? 'Book')) ?>">
                                    <input type="hidden" name="delete_book" value="1">
                                    <input type="hidden" name="book_id" value="<?= $bookId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button
                                        type="submit"
                                        class="manage-book-icon-btn delete"
                                        aria-label="Delete book">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M4 7h16"></path>
                                            <path d="M9 7V5h6v2"></path>
                                            <path d="M7.6 7l.8 12h7.2l.8-12"></path>
                                            <path d="M10 11v5"></path>
                                            <path d="M14 11v5"></path>
                                        </svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr class="book-copy-details-row" id="copy-details-<?= $bookId ?>" hidden>
                            <td colspan="9">
                                <div class="book-copy-details-wrap">
                                    <?php $copies = $copiesForRow; ?>
                                    <?php if (!empty($copies)): ?>
                                        <table class="copy-detail-table">
                                            <thead>
                                                <tr>
                                                    <th>Accession #</th>
                                                    <th>Copy ID</th>
                                                    <th>Copy Status</th>
                                                    <th>Borrow Status</th>
                                                    <th>Borrower</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($copies as $copy): ?>
                                                    <?php
                                                        $copyStatusRaw = strtolower((string)($copy['copy_status'] ?? 'unknown'));
                                                        $copyStatusText = ucfirst(str_replace('_', ' ', $copyStatusRaw));
                                                        $copyStatusTextClass = 'copy-detail-muted';
                                                        if ($copyStatusRaw === 'borrowed') {
                                                            $copyStatusTextClass = 'copy-detail-status-borrowed';
                                                        } elseif ($copyStatusRaw === 'overdue') {
                                                            $copyStatusTextClass = 'copy-detail-status-overdue';
                                                        } elseif ($copyStatusRaw === 'missing') {
                                                            $copyStatusTextClass = 'copy-detail-status-missing';
                                                        } elseif ($copyStatusRaw === 'available') {
                                                            $copyStatusTextClass = 'copy-detail-status-available';
                                                        }

                                                        $loanStatusRaw = strtolower((string)($copy['loan_status'] ?? ''));
                                                        $loanStatusText = '-';
                                                        $borrowStatusClass = 'copy-detail-muted';
                                                        if ($loanStatusRaw !== '') {
                                                            $loanStatusText = ucfirst($loanStatusRaw);
                                                            if ($loanStatusRaw === 'borrowed') {
                                                                $borrowStatusClass = 'copy-detail-status-borrowed';
                                                            } elseif ($loanStatusRaw === 'overdue') {
                                                                $borrowStatusClass = 'copy-detail-status-overdue';
                                                            } elseif ($loanStatusRaw === 'missing') {
                                                                $borrowStatusClass = 'copy-detail-status-missing';
                                                            } else {
                                                                $borrowStatusClass = 'copy-detail-status-available';
                                                            }
                                                        }

                                                        $borrowerName = trim(((string)($copy['first_name'] ?? '')) . ' ' . ((string)($copy['last_name'] ?? '')));
                                                        $borrowerId = trim((string)($copy['user_number'] ?? ''));
                                                        $borrowerText = '-';
                                                        if ($borrowerName !== '') {
                                                            $borrowerText = $borrowerName;
                                                            if ($borrowerId !== '') {
                                                                $borrowerText .= ' (' . $borrowerId . ')';
                                                            }
                                                        } elseif ($borrowerId !== '') {
                                                            $borrowerText = $borrowerId;
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string)($copy['accession_no'] ?? '-')) ?></td>
                                                        <td><?= intval($copy['copy_id'] ?? 0) ?></td>
                                                        <td><span class="<?= htmlspecialchars($copyStatusTextClass) ?>"><?= htmlspecialchars($copyStatusText) ?></span></td>
                                                        <td><span class="<?= htmlspecialchars($borrowStatusClass) ?>"><?= htmlspecialchars($loanStatusText) ?></span></td>
                                                        <td><?= htmlspecialchars($borrowerText) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="copy-detail-empty">No copy records found for this book.</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9">No books found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="overlay-modal<?= $openAddBookModal ? ' show' : '' ?>" id="bookModal" aria-hidden="<?= $openAddBookModal ? 'false' : 'true' ?>">
    <div class="overlay-card glass-card">
        <div class="panel-head">
            <h2>Add Book</h2>
            <button class="icon-close" onclick="closeBookModal()">&times;</button>
        </div>

        <form method="POST" class="form-grid book-form-grid">
            <?php
                $initialCopyCountRaw = trim((string)($addBookForm['copy_count'] ?? ''));
                $initialCopyCount = '';
                if ($initialCopyCountRaw !== '' && ctype_digit($initialCopyCountRaw)) {
                    $tmpCount = (int)$initialCopyCountRaw;
                    if ($tmpCount >= 1 && $tmpCount <= 200) {
                        $initialCopyCount = (string)$tmpCount;
                    }
                }
                $renderAccessionCount = $initialCopyCount !== '' ? (int)$initialCopyCount : 0;
                $initialAccessions = is_array($addBookForm['accession_numbers'] ?? null) ? $addBookForm['accession_numbers'] : [];
            ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="book-form-col book-form-col-inputs">
                <input type="text" name="title" placeholder="Title" required value="<?= htmlspecialchars((string)($addBookForm['title'] ?? '')) ?>">
                <input type="text" name="isbn" placeholder="ISBN" value="<?= htmlspecialchars((string)($addBookForm['isbn'] ?? '')) ?>">
                <input type="text" name="author" placeholder="Author" value="<?= htmlspecialchars((string)($addBookForm['author'] ?? '')) ?>">
                <input type="text" name="publisher" placeholder="Publisher" value="<?= htmlspecialchars((string)($addBookForm['publisher'] ?? '')) ?>">
                <input type="number" name="year_published" placeholder="Year" min="1900" max="2100" value="<?= htmlspecialchars((string)($addBookForm['year_published'] ?? '')) ?>">
                <input type="text" name="call_number" placeholder="Call Number" required value="<?= htmlspecialchars((string)($addBookForm['call_number'] ?? '')) ?>">
                <input
                    type="text"
                    name="copy_count"
                    id="copyCountInput"
                    placeholder="Number of Copies"
                    inputmode="numeric"
                    autocomplete="off"
                    required
                    value="<?= htmlspecialchars($initialCopyCount) ?>">

                <div class="book-accession-block">
                    <div class="book-accession-head">
                        <strong>Accession Numbers (per copy)</strong>
                        <span class="book-accession-hint">Enter one accession number for each copy.</span>
                    </div>
                    <div class="book-accession-inputs" id="accessionInputsWrap">
                        <?php for ($i = 0; $i < $renderAccessionCount; $i++): ?>
                            <?php $accValue = (string)($initialAccessions[$i] ?? ''); ?>
                            <input type="text" name="accession_numbers[]" placeholder="Accession #<?= $i + 1 ?>" value="<?= htmlspecialchars($accValue) ?>">
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="book-form-col book-form-col-selects">
                <select name="category_id">
                    <option value="">Select Category</option>
                    <?php foreach ($categoryOptions as $categoryOption): ?>
                        <?php $cid = (int)($categoryOption['category_id'] ?? 0); ?>
                        <option value="<?= $cid ?>" <?= (int)($addBookForm['category_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= htmlspecialchars((string)($categoryOption['category_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="program_id">
                    <option value="">Select Program</option>
                    <?php foreach ($programOptions as $programOption): ?>
                        <?php $pid = (int)($programOption['program_id'] ?? 0); ?>
                        <option value="<?= $pid ?>" <?= (int)($addBookForm['program_id'] ?? 0) === $pid ? 'selected' : '' ?>><?= htmlspecialchars((string)($programOption['program_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_section" required>
                    <option value="">Select Section</option>
                    <?php foreach ($librarySections as $sec): ?>
                        <option value="<?= htmlspecialchars($sec) ?>" <?= ((string)($addBookForm['location_section'] ?? '') === (string)$sec) ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_class" required>
                    <option value="">Select Class Letter</option>
                    <?php foreach ($libraryClassLabels as $classCode => $classLabel): ?>
                        <option value="<?= htmlspecialchars($classCode) ?>" <?= ((string)($addBookForm['location_class'] ?? '') === (string)$classCode) ? 'selected' : '' ?>><?= htmlspecialchars($classLabel) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_shelf" required>
                    <option value="">Select Shelf Code</option>
                    <?php foreach ($shelfOptions as $shelf): ?>
                        <option value="<?= htmlspecialchars($shelf) ?>" <?= ((string)($addBookForm['location_shelf'] ?? '') === (string)$shelf) ? 'selected' : '' ?>><?= htmlspecialchars($shelf) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_row" required>
                    <option value="">Select Row</option>
                    <?php foreach ($rowOptions as $rowOpt): ?>
                        <option value="<?= htmlspecialchars($rowOpt) ?>" <?= ((string)($addBookForm['location_row'] ?? '') === (string)$rowOpt) ? 'selected' : '' ?>><?= htmlspecialchars($rowOpt) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_position" required>
                    <option value="">Select Position</option>
                    <?php foreach ($positionOptions as $pos): ?>
                        <option value="<?= htmlspecialchars($pos) ?>" <?= ((string)($addBookForm['location_position'] ?? '') === (string)$pos) ? 'selected' : '' ?>><?= htmlspecialchars($pos) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" name="add_book" class="btn-primary form-submit">Add Book</button>
        </form>
    </div>
</div>

<?php if ($editBook): ?>
<div class="overlay-modal show" id="editBookModal" aria-hidden="false">
    <div class="overlay-card glass-card edit-book-modal-card">
        <div class="panel-head">
            <h2>Edit Book</h2>
            <a class="icon-close" href="manage_books.php" aria-label="Close">&times;</a>
        </div>

        <form method="POST" class="form-grid book-form-grid edit-book-form-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="book_id" value="<?= intval($editForm['book_id'] ?? 0) ?>">
            <input type="hidden" id="editCurrentCopyCount" value="<?= intval($editCopyTotal) ?>">

            <div class="book-form-col book-form-col-inputs">
                <input type="text" name="title" placeholder="Title" required value="<?= htmlspecialchars((string)($editForm['title'] ?? '')) ?>">
                <input type="text" name="isbn" placeholder="ISBN" value="<?= htmlspecialchars((string)($editForm['isbn'] ?? '')) ?>">
                <input type="text" name="author" placeholder="Author" value="<?= htmlspecialchars((string)($editForm['author'] ?? '')) ?>">
                <input type="text" name="publisher" placeholder="Publisher" value="<?= htmlspecialchars((string)($editForm['publisher'] ?? '')) ?>">
                <input type="number" name="year_published" placeholder="Year" min="1900" max="2100" value="<?= htmlspecialchars((string)($editForm['year_published'] ?? '')) ?>">
                <input type="text" name="call_number" placeholder="Call Number" required value="<?= htmlspecialchars((string)($editForm['call_number'] ?? '')) ?>">
            </div>

            <div class="book-form-col book-form-col-selects">
                <select name="category_id">
                    <option value="">Select Category</option>
                    <?php foreach ($categoryOptions as $categoryOption): ?>
                        <?php $cid = (int)($categoryOption['category_id'] ?? 0); ?>
                        <option value="<?= $cid ?>" <?= (int)($editForm['category_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= htmlspecialchars((string)($categoryOption['category_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="program_id">
                    <option value="">Select Program</option>
                    <?php foreach ($programOptions as $programOption): ?>
                        <?php $pid = (int)($programOption['program_id'] ?? 0); ?>
                        <option value="<?= $pid ?>" <?= (int)($editForm['program_id'] ?? 0) === $pid ? 'selected' : '' ?>><?= htmlspecialchars((string)($programOption['program_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_section" required>
                    <option value="">Select Section</option>
                    <?php foreach ($librarySections as $sec): ?>
                        <option value="<?= htmlspecialchars($sec) ?>" <?= ((string)($editForm['location_section'] ?? '') === (string)$sec) ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_class" required>
                    <option value="">Select Class Letter</option>
                    <?php foreach ($libraryClassLabels as $classCode => $classLabel): ?>
                        <option value="<?= htmlspecialchars($classCode) ?>" <?= ((string)($editForm['location_class'] ?? '') === (string)$classCode) ? 'selected' : '' ?>><?= htmlspecialchars($classLabel) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_shelf" required>
                    <option value="">Select Shelf Code</option>
                    <?php foreach ($shelfOptions as $shelf): ?>
                        <option value="<?= htmlspecialchars($shelf) ?>" <?= ((string)($editForm['location_shelf'] ?? '') === (string)$shelf) ? 'selected' : '' ?>><?= htmlspecialchars($shelf) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_row" required>
                    <option value="">Select Row</option>
                    <?php foreach ($rowOptions as $rowOpt): ?>
                        <option value="<?= htmlspecialchars($rowOpt) ?>" <?= ((string)($editForm['location_row'] ?? '') === (string)$rowOpt) ? 'selected' : '' ?>><?= htmlspecialchars($rowOpt) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="location_position" required>
                    <option value="">Select Position</option>
                    <?php foreach ($positionOptions as $pos): ?>
                        <option value="<?= htmlspecialchars($pos) ?>" <?= ((string)($editForm['location_position'] ?? '') === (string)$pos) ? 'selected' : '' ?>><?= htmlspecialchars($pos) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="book-form-col book-form-col-copies">
                <input
                    type="text"
                    name="copy_count"
                    id="editCopyCountInput"
                    placeholder="Number of Copies"
                    inputmode="numeric"
                    autocomplete="off"
                    required
                    value="<?= htmlspecialchars((string)($editForm['copy_count'] ?? '')) ?>">

                <div class="book-accession-block">
                    <div class="book-accession-head">
                        <strong>Current Copy Accessions</strong>
                        <span class="book-accession-hint">
                            <?= intval($editCopyTotal) ?> copies total.
                            <?= intval($editLockedCount) ?> locked (borrowed/overdue/missing),
                            <?= intval($editRemovableCount) ?> editable/removable.
                        </span>
                    </div>
                    <div class="edit-copy-accession-list">
                        <?php if (!empty($editCopyRows)): ?>
                            <?php foreach ($editCopyRows as $copyRow): ?>
                                <?php
                                    $copyId = (int)($copyRow['copy_id'] ?? 0);
                                    $statusRaw = strtolower((string)($copyRow['effective_status'] ?? 'unknown'));
                                    $statusLabel = (string)($copyRow['status_label'] ?? ucfirst(str_replace('_', ' ', $statusRaw)));
                                    $isLocked = (bool)($copyRow['is_locked'] ?? false);
                                    $accessionInput = (string)($copyRow['input_accession'] ?? '');
                                ?>
                                <div class="edit-copy-accession-row<?= $isLocked ? ' is-locked' : '' ?>">
                                    <div class="edit-copy-accession-meta">
                                        <span class="edit-copy-accession-id">Copy #<?= $copyId ?></span>
                                        <span class="edit-copy-accession-status status-<?= htmlspecialchars($statusRaw) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                    </div>
                                    <input
                                        type="text"
                                        name="edit_copy_accessions[<?= $copyId ?>]"
                                        placeholder="Accession #<?= $copyId ?>"
                                        value="<?= htmlspecialchars($accessionInput) ?>"
                                        <?= $isLocked ? 'readonly' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="copy-detail-empty">No copy records found for this book.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="book-accession-block">
                    <div class="book-accession-head">
                        <strong>New Copy Accessions</strong>
                        <span class="book-accession-hint" id="editCopyAdjustmentHint">
                            Increase Number of Copies to add new accession fields.
                        </span>
                    </div>
                    <div class="book-accession-inputs" id="editNewAccessionInputsWrap">
                        <?php for ($i = 0; $i < $editAdditionalCopyCount; $i++): ?>
                            <?php $newAccessionValue = (string)($editNewAccessionsInput[$i] ?? ''); ?>
                            <input type="text" name="new_accession_numbers[]" placeholder="New Accession #<?= $i + 1 ?>" value="<?= htmlspecialchars($newAccessionValue) ?>">
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <button type="submit" name="update_book" class="btn-primary form-submit">Save Changes</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="overlay-modal" id="deleteBookConfirmModal" aria-hidden="true">
    <div class="overlay-card glass-card confirm-delete-card" role="dialog" aria-modal="true" aria-labelledby="deleteBookConfirmTitle" aria-describedby="deleteBookConfirmText">
        <div class="confirm-delete-header">
            <span class="confirm-delete-icon" aria-hidden="true">!</span>
            <div>
                <h2 id="deleteBookConfirmTitle">Delete Book</h2>
                <p id="deleteBookConfirmText">Delete this book from Manage Books?</p>
            </div>
        </div>
        <p class="confirm-delete-note">This cannot be undone from this page. You can still review activity in Archives.</p>
        <div class="confirm-delete-actions">
            <button type="button" class="btn-status activate confirm-delete-cancel">Cancel</button>
            <button type="button" class="confirm-delete-confirm">Delete</button>
        </div>
    </div>
</div>

<script>
function openBookModal() {
    const modal = document.getElementById('bookModal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closeBookModal() {
    const modal = document.getElementById('bookModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}

window.addEventListener('click', function (e) {
    if (e.target.id === 'bookModal') {
        closeBookModal();
    }
});

const copyCountInput = document.getElementById('copyCountInput');
const accessionInputsWrap = document.getElementById('accessionInputsWrap');

function normalizeCopyCount(value) {
    const text = String(value || '').trim();
    if (text === '') return null;
    if (!/^\d+$/.test(text)) return null;

    const parsed = Number.parseInt(text, 10);
    if (Number.isNaN(parsed) || parsed < 1 || parsed > 200) return null;

    return parsed;
}

function renderAccessionInputs() {
    if (!copyCountInput || !accessionInputsWrap) return;

    const targetCount = normalizeCopyCount(copyCountInput.value);

    const existingValues = Array.from(
        accessionInputsWrap.querySelectorAll('input[name="accession_numbers[]"]')
    ).map(function (input) {
        return input.value || '';
    });

    accessionInputsWrap.innerHTML = '';

    if (targetCount === null) {
        return;
    }

    for (let i = 0; i < targetCount; i += 1) {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'accession_numbers[]';
        input.placeholder = 'Accession #' + (i + 1);
        input.value = existingValues[i] || '';
        accessionInputsWrap.appendChild(input);
    }
}

if (copyCountInput && accessionInputsWrap) {
    copyCountInput.addEventListener('input', renderAccessionInputs);
    copyCountInput.addEventListener('change', renderAccessionInputs);
    copyCountInput.addEventListener('blur', function () {
        const parsed = normalizeCopyCount(copyCountInput.value);
        if (parsed !== null) {
            copyCountInput.value = String(parsed);
        }
    });
    renderAccessionInputs();
}

const editCopyCountInput = document.getElementById('editCopyCountInput');
const editCurrentCopyCountInput = document.getElementById('editCurrentCopyCount');
const editNewAccessionInputsWrap = document.getElementById('editNewAccessionInputsWrap');
const editCopyAdjustmentHint = document.getElementById('editCopyAdjustmentHint');

function getEditCurrentCopyCount() {
    if (!editCurrentCopyCountInput) {
        return 0;
    }
    const raw = String(editCurrentCopyCountInput.value || '').trim();
    if (!/^\d+$/.test(raw)) {
        return 0;
    }
    return Number.parseInt(raw, 10);
}

function setEditCopyHint(message) {
    if (!editCopyAdjustmentHint) return;
    editCopyAdjustmentHint.textContent = message;
}

function renderEditNewAccessionInputs() {
    if (!editCopyCountInput || !editNewAccessionInputsWrap) return;

    const desiredCount = normalizeCopyCount(editCopyCountInput.value);
    const currentCount = getEditCurrentCopyCount();

    const existingValues = Array.from(
        editNewAccessionInputsWrap.querySelectorAll('input[name="new_accession_numbers[]"]')
    ).map(function (input) {
        return input.value || '';
    });

    editNewAccessionInputsWrap.innerHTML = '';

    if (desiredCount === null) {
        setEditCopyHint('Enter a valid Number of Copies (1-200).');
        return;
    }

    const additionalCount = Math.max(0, desiredCount - currentCount);

    if (additionalCount === 0) {
        if (desiredCount < currentCount) {
            setEditCopyHint('Lower copy count will remove available copies first (borrowed/overdue/missing stay protected).');
        } else {
            setEditCopyHint('Copy count matches current inventory. No new accession fields needed.');
        }
        return;
    }

    setEditCopyHint('Provide accession numbers for ' + additionalCount + ' new ' + (additionalCount === 1 ? 'copy.' : 'copies.'));

    for (let i = 0; i < additionalCount; i += 1) {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'new_accession_numbers[]';
        input.placeholder = 'New Accession #' + (i + 1);
        input.value = existingValues[i] || '';
        editNewAccessionInputsWrap.appendChild(input);
    }
}

if (editCopyCountInput && editNewAccessionInputsWrap) {
    editCopyCountInput.addEventListener('input', renderEditNewAccessionInputs);
    editCopyCountInput.addEventListener('change', renderEditNewAccessionInputs);
    editCopyCountInput.addEventListener('blur', function () {
        const parsed = normalizeCopyCount(editCopyCountInput.value);
        if (parsed !== null) {
            editCopyCountInput.value = String(parsed);
        }
        renderEditNewAccessionInputs();
    });
    renderEditNewAccessionInputs();
}
document.querySelectorAll('.book-copy-toggle-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const rowId = btn.getAttribute('data-target');
        if (!rowId) return;

        const detailRow = document.getElementById(rowId);
        if (!detailRow) return;

        const willShow = detailRow.hasAttribute('hidden');
        if (willShow) {
            detailRow.removeAttribute('hidden');
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = 'Hide';
        } else {
            detailRow.setAttribute('hidden', '');
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'View';
        }
    });
});

const deleteConfirmModal = document.getElementById('deleteBookConfirmModal');
const deleteConfirmText = document.getElementById('deleteBookConfirmText');
const deleteConfirmCancel = document.querySelector('.confirm-delete-cancel');
const deleteConfirmButton = document.querySelector('.confirm-delete-confirm');
let pendingDeleteForm = null;

function openDeleteBookConfirm(formEl) {
    if (!deleteConfirmModal || !deleteConfirmText || !deleteConfirmButton) return;

    pendingDeleteForm = formEl;
    const rawTitle = (formEl && formEl.dataset && formEl.dataset.bookTitle) ? formEl.dataset.bookTitle.trim() : '';
    const displayTitle = rawTitle !== '' ? '"' + rawTitle + '"' : 'this book';
    deleteConfirmText.textContent = 'Delete ' + displayTitle + ' from Manage Books?';

    deleteConfirmModal.classList.add('show');
    deleteConfirmModal.setAttribute('aria-hidden', 'false');
    deleteConfirmButton.focus();
}

function closeDeleteBookConfirm() {
    if (!deleteConfirmModal) return;
    deleteConfirmModal.classList.remove('show');
    deleteConfirmModal.setAttribute('aria-hidden', 'true');
    pendingDeleteForm = null;
}

document.querySelectorAll('.delete-book-form').forEach(function (formEl) {
    formEl.addEventListener('submit', function (event) {
        event.preventDefault();
        openDeleteBookConfirm(formEl);
    });
});

if (deleteConfirmCancel) {
    deleteConfirmCancel.addEventListener('click', function () {
        closeDeleteBookConfirm();
    });
}

if (deleteConfirmButton) {
    deleteConfirmButton.addEventListener('click', function () {
        if (!pendingDeleteForm) {
            closeDeleteBookConfirm();
            return;
        }

        const formToSubmit = pendingDeleteForm;
        pendingDeleteForm = null;
        deleteConfirmModal.classList.remove('show');
        deleteConfirmModal.setAttribute('aria-hidden', 'true');
        formToSubmit.submit();
    });
}

window.addEventListener('click', function (event) {
    if (event.target === deleteConfirmModal) {
        closeDeleteBookConfirm();
    }
});

window.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && deleteConfirmModal && deleteConfirmModal.classList.contains('show')) {
        closeDeleteBookConfirm();
    }
});
</script>

<?php require 'layout_bottom.php'; ?>










