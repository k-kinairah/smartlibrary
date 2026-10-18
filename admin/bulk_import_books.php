<?php
/**
 * Bulk book import for librarians: upload a CSV of new titles, preview a
 * dry-run validation of every row (nothing is written to the database yet),
 * then commit only the rows that passed. Mirrors the "safe by default"
 * pattern already used by tools/import_books_bulk.php and
 * tools/fetch_book_covers.php -- dry run first, explicit commit second --
 * but this is the web-facing version a librarian can run themselves,
 * without needing command-line / XAMPP Shell access.
 *
 * Imported books are left with cover = NULL on purpose: the existing
 * nightly tools/fetch_book_covers.php --commit job (Windows Task
 * Scheduler) already picks up every book with no cover automatically, so a
 * large batch import doesn't need to pay the cost of fetching dozens of
 * covers synchronously inside a single web request.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

require '../config/db_connect.php';
require_once '../config/admin_audit.php';
require_once '../config/csrf.php';
require_once __DIR__ . '/includes/manage_books_functions.php';

const BULK_IMPORT_MAX_ROWS = 500;
const BULK_IMPORT_MAX_COPIES_PER_BOOK = 200;
const BULK_IMPORT_SESSION_KEY = 'smartlib_bulk_import_preview';

const BULK_IMPORT_CSV_COLUMNS = [
    'title', 'author', 'publisher', 'isbn', 'year_published', 'category', 'program',
    'call_number', 'location_section', 'location_class', 'location_shelf',
    'location_row', 'location_position', 'accession_numbers'
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

$categoryOptions = [];
$categoryByName = [];
$categoriesRes = $conn->query('SELECT category_id, category_name FROM categories ORDER BY category_name ASC');
if ($categoriesRes instanceof mysqli_result) {
    while ($row = $categoriesRes->fetch_assoc()) {
        $categoryOptions[] = $row;
        $categoryByName[strtolower(trim((string)($row['category_name'] ?? '')))] = (int)($row['category_id'] ?? 0);
    }
}

$programOptions = [];
$programByName = [];
$programsRes = $conn->query('SELECT program_id, program_name FROM programs ORDER BY program_name ASC');
if ($programsRes instanceof mysqli_result) {
    while ($row = $programsRes->fetch_assoc()) {
        $programOptions[] = $row;
        $programByName[strtolower(trim((string)($row['program_name'] ?? '')))] = (int)($row['program_id'] ?? 0);
    }
}

$hasLocationTable = table_exists($conn, 'library_locations');
$hasBookLocationId = column_exists($conn, 'books', 'location_id');
$hasBookCallNumber = column_exists($conn, 'books', 'call_number');
$hasLegacyLocation = column_exists($conn, 'books', 'location');
// (No $hasBookCopiesTable here: book_copies is never optional for this page --
// every insert path writes to it -- so checking for it first was a wasted
// information_schema round-trip on every single page load. manage_books.php's
// add_book handler doesn't check for it either, for the same reason.)

$csrfToken = smartlib_csrf_token('bulk_import_books_csrf');

/**
 * Parses an uploaded CSV file into row arrays keyed by
 * BULK_IMPORT_CSV_COLUMNS. Returns ['ok'=>bool, 'rows'=>array, 'error'=>string].
 * Tolerant of a UTF-8 BOM and of header columns in any order (matched
 * case-insensitively), so a librarian re-ordering columns in Excel doesn't
 * break the import.
 */
function bulk_import_parse_csv(string $tmpPath): array {
    $handle = @fopen($tmpPath, 'r');
    if (!$handle) {
        return ['ok' => false, 'rows' => [], 'error' => 'Could not read the uploaded file.'];
    }

    $header = fgetcsv($handle);
    if ($header === false || $header === null) {
        fclose($handle);
        return ['ok' => false, 'rows' => [], 'error' => 'The file appears to be empty.'];
    }

    // Strip a UTF-8 BOM from the first header cell if Excel added one.
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $colIndex = [];
    foreach ($header as $i => $colName) {
        $colIndex[strtolower(trim((string)$colName))] = $i;
    }

    $missing = [];
    foreach (['title', 'call_number', 'accession_numbers'] as $required) {
        if (!array_key_exists($required, $colIndex)) {
            $missing[] = $required;
        }
    }
    if (!empty($missing)) {
        fclose($handle);
        return [
            'ok' => false,
            'rows' => [],
            'error' => 'The file is missing required column(s): ' . implode(', ', $missing) . '. Download the template to see the expected format.'
        ];
    }

    $rows = [];
    $rowNumber = 1; // header is row 1
    while (($line = fgetcsv($handle)) !== false) {
        $rowNumber++;
        // Skip fully blank lines (common trailing-newline artifact).
        if ($line === [null] || (count($line) === 1 && trim((string)$line[0]) === '')) {
            continue;
        }

        $parsed = ['_row_number' => $rowNumber];
        foreach (BULK_IMPORT_CSV_COLUMNS as $col) {
            $idx = $colIndex[$col] ?? null;
            $parsed[$col] = $idx !== null ? trim((string)($line[$idx] ?? '')) : '';
        }
        $rows[] = $parsed;

        if (count($rows) > BULK_IMPORT_MAX_ROWS) {
            fclose($handle);
            return [
                'ok' => false,
                'rows' => [],
                'error' => 'This file has more than ' . BULK_IMPORT_MAX_ROWS . ' data rows. Please split it into smaller batches.'
            ];
        }
    }

    fclose($handle);

    if (empty($rows)) {
        return ['ok' => false, 'rows' => [], 'error' => 'No data rows found in the file.'];
    }

    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/**
 * Validates one parsed CSV row against the form rules (mirrors Add Book's
 * own validation in manage_books.php) plus bulk-specific checks: ISBN and
 * accession-number uniqueness within the whole file, not just the DB.
 * $seenIsbns / $seenAccessions are passed by reference so each row's
 * values are folded into the running set for the next row to check
 * against -- this is what catches two rows in the same file both claiming
 * the same accession number.
 *
 * @return array{ok:bool, errors:string[], data:array}
 */
function bulk_import_validate_row(
    mysqli $conn,
    array $row,
    array $categoryByName,
    array $programByName,
    array &$seenIsbns,
    array &$seenAccessions
): array {
    $errors = [];

    $title = trim((string)($row['title'] ?? ''));
    $callNumber = trim((string)($row['call_number'] ?? ''));
    $author = trim((string)($row['author'] ?? ''));
    $publisher = trim((string)($row['publisher'] ?? ''));
    $isbn = trim((string)($row['isbn'] ?? ''));
    $yearRaw = trim((string)($row['year_published'] ?? ''));
    $categoryName = trim((string)($row['category'] ?? ''));
    $programName = trim((string)($row['program'] ?? ''));
    $locationSection = trim((string)($row['location_section'] ?? ''));
    $locationClass = trim((string)($row['location_class'] ?? ''));
    $locationShelf = trim((string)($row['location_shelf'] ?? ''));
    $locationRow = trim((string)($row['location_row'] ?? ''));
    $locationPosition = trim((string)($row['location_position'] ?? ''));
    $accessionsRaw = trim((string)($row['accession_numbers'] ?? ''));

    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($callNumber === '') {
        $errors[] = 'Call Number is required.';
    }

    $year = '';
    if ($yearRaw !== '') {
        if (!ctype_digit($yearRaw) || (int)$yearRaw < 1900 || (int)$yearRaw > 2100) {
            $errors[] = 'Year must be a number between 1900 and 2100.';
        } else {
            $year = $yearRaw;
        }
    }

    $categoryId = 0;
    if ($categoryName !== '') {
        $key = strtolower($categoryName);
        if (!array_key_exists($key, $categoryByName)) {
            $errors[] = 'Category "' . $categoryName . '" does not match any existing category.';
        } else {
            $categoryId = $categoryByName[$key];
        }
    }

    $programId = 0;
    if ($programName !== '') {
        $key = strtolower($programName);
        if (!array_key_exists($key, $programByName)) {
            $errors[] = 'Program "' . $programName . '" does not match any existing program.';
        } else {
            $programId = $programByName[$key];
        }
    }

    if ($isbn !== '') {
        $isbnKey = strtolower($isbn);
        if (isset($seenIsbns[$isbnKey])) {
            $errors[] = 'ISBN "' . $isbn . '" is used by more than one row in this file (row ' . $seenIsbns[$isbnKey] . ').';
        } else {
            $dupStmt = $conn->prepare('SELECT 1 FROM books WHERE isbn = ? LIMIT 1');
            if ($dupStmt) {
                $dupStmt->bind_param('s', $isbn);
                $dupStmt->execute();
                $dupRes = $dupStmt->get_result();
                if ($dupRes && $dupRes->num_rows > 0) {
                    $errors[] = 'ISBN "' . $isbn . '" already exists in the catalog.';
                }
                $dupStmt->close();
            }
        }
    }

    $accessions = [];
    if ($accessionsRaw === '') {
        $errors[] = 'At least one accession number is required (separate multiple copies with a semicolon).';
    } else {
        $tokens = array_map('trim', explode(';', $accessionsRaw));
        $tokens = array_values(array_filter($tokens, static fn($t) => $t !== ''));

        if (empty($tokens)) {
            $errors[] = 'At least one accession number is required.';
        } elseif (count($tokens) > BULK_IMPORT_MAX_COPIES_PER_BOOK) {
            $errors[] = 'Too many copies for one row (max ' . BULK_IMPORT_MAX_COPIES_PER_BOOK . ').';
        } else {
            foreach ($tokens as $token) {
                if (strlen($token) > 50) {
                    $errors[] = 'Accession number "' . $token . '" is longer than 50 characters.';
                    continue;
                }
                $tokenKey = strtolower($token);
                if (isset($seenAccessions[$tokenKey])) {
                    $errors[] = 'Accession number "' . $token . '" is used more than once in this file (also row ' . $seenAccessions[$tokenKey] . ').';
                    continue;
                }
                if (manage_books_accession_exists($conn, $token)) {
                    $errors[] = 'Accession number "' . $token . '" already exists in the catalog.';
                    continue;
                }
                $accessions[] = $token;
            }
        }
    }

    $ok = empty($errors);

    // Only fold this row's values into the running "seen" sets once we know
    // they didn't themselves collide with something earlier -- an invalid
    // row shouldn't poison a later, otherwise-valid row's uniqueness check.
    if ($ok) {
        if ($isbn !== '') {
            $seenIsbns[strtolower($isbn)] = (int)$row['_row_number'];
        }
        foreach ($accessions as $token) {
            $seenAccessions[strtolower($token)] = (int)$row['_row_number'];
        }
    }

    return [
        'ok' => $ok,
        'errors' => $errors,
        'data' => [
            'row_number' => (int)$row['_row_number'],
            'title' => $title,
            'author' => $author,
            'publisher' => $publisher,
            'isbn' => $isbn,
            'year_published' => $year,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'program_id' => $programId,
            'program_name' => $programName,
            'location_section' => $locationSection,
            'location_class' => $locationClass,
            'location_shelf' => $locationShelf,
            'location_row' => $locationRow,
            'location_position' => $locationPosition,
            'call_number' => $callNumber,
            'accession_numbers' => $accessions
        ]
    ];
}

/**
 * Inserts one validated row as a books row plus its book_copies rows, in
 * its own transaction. Mirrors manage_books.php's add_book handler's
 * insert logic exactly (including its three location-column fallback
 * branches), kept as a separate copy here rather than a shared function so
 * that changes to this new, less-exercised bulk path can never affect the
 * already-live, already-tested single Add Book flow.
 *
 * @return array{ok:bool, book_id:int, error:string}
 */
function bulk_import_insert_book(
    mysqli $conn,
    bool $hasLocationTable,
    bool $hasBookLocationId,
    bool $hasBookCallNumber,
    bool $hasLegacyLocation,
    array $libraryClassNames,
    array $data
): array {
    $title = (string)$data['title'];
    $author = (string)$data['author'];
    $publisher = (string)$data['publisher'];
    $year = (string)$data['year_published'];
    $isbn = (string)$data['isbn'];
    $categoryId = (int)$data['category_id'];
    $programId = (int)$data['program_id'];
    $locationSection = (string)$data['location_section'];
    $locationClass = (string)$data['location_class'];
    $locationShelf = (string)$data['location_shelf'];
    $locationRow = (string)$data['location_row'];
    $locationPosition = (string)$data['location_position'];
    $callNumber = (string)$data['call_number'];
    $accessionNumbers = (array)$data['accession_numbers'];

    $legacyLocationText = build_legacy_location_text($locationSection, $locationClass, $locationShelf, $locationRow, $locationPosition, $callNumber);

    $locationId = 0;
    if ($hasLocationTable && $hasBookLocationId && $locationSection !== '' && $locationClass !== '' && $locationShelf !== '' && $locationRow !== '' && $locationPosition !== '') {
        $className = (string)($libraryClassNames[$locationClass] ?? 'General Works');
        $locationId = get_or_create_location_id($conn, $locationSection, $locationClass, $className, $locationShelf, $locationRow, $locationPosition);
    }

    $stmt = null;
    $copyStmt = null;

    try {
        $conn->begin_transaction();

        if ($hasLocationTable && $hasBookLocationId) {
            if ($hasBookCallNumber) {
                $stmt = $conn->prepare(
                    "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location_id, call_number, created_at)
                     VALUES (NULLIF(?, ''), ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), NOW())"
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare book insert statement.');
                }
                $stmt->bind_param('sssssiiis', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $locationId, $callNumber);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location_id, created_at)
                     VALUES (NULLIF(?, ''), ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NOW())"
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare book insert statement.');
                }
                $stmt->bind_param('sssssiii', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $locationId);
            }
        } elseif ($hasLegacyLocation) {
            $stmt = $conn->prepare(
                "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location, created_at)
                 VALUES (NULLIF(?, ''), ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, NOW())"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare book insert statement.');
            }
            $stmt->bind_param('sssssiis', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $legacyLocationText);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, created_at)
                 VALUES (NULLIF(?, ''), ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NOW())"
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

        $copyIsbn = substr($isbn, 0, 20);
        foreach ($accessionNumbers as $i => $accessionNo) {
            $copyStmt->bind_param('iss', $newBookId, $accessionNo, $copyIsbn);
            if (!$copyStmt->execute()) {
                throw new RuntimeException('Unable to save copy #' . ($i + 1) . '.');
            }
        }
        $copyStmt->close();
        $copyStmt = null;

        $conn->commit();

        return ['ok' => true, 'book_id' => $newBookId, 'error' => ''];
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
            $msg = 'ISBN already exists.';
        } elseif (stripos($msg, 'duplicate') !== false && stripos($msg, 'accession') !== false) {
            $msg = 'One of the accession numbers already exists.';
        } elseif ($msg === '') {
            $msg = 'Unable to save this book.';
        }

        return ['ok' => false, 'book_id' => 0, 'error' => $msg];
    }
}

function bulk_import_csv_template_content(): string {
    $header = implode(',', BULK_IMPORT_CSV_COLUMNS);
    $example1 = '"The Hobbit","Tolkien, J.R.R.","HarperCollins","9780547928227","2012","Literature","BS Business Administration","P-TOL-2012","General Collection","P","YA-01","First row","Eye Level","ACC-10001"';
    $example2 = '"Intro to Psychology","Myers, David G.","Worth Publishers","","2018","Psychology","","BF-MYE-2018","General Collection","B","PSY-01","Second row","Middle Shelf","ACC-10002;ACC-10003"';
    return $header . "\r\n" . $example1 . "\r\n" . $example2 . "\r\n";
}

/**
 * Two small SVGs in the same stroke-based house style as admin_icon()
 * (layout_top.php) that aren't in that shared map. Kept local to this
 * page rather than added to the global icon list since nothing else
 * uses them yet.
 */
function bulk_import_icon_upload(): string {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5V5.5"></path><path d="m7.5 10 4.5-4.5 4.5 4.5"></path><path d="M5 19h14"></path></svg>';
}

function bulk_import_icon_info(): string {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.4"></circle><path d="M12 11v5.2"></path><circle cx="12" cy="8" r="0.9" fill="currentColor" stroke="none"></circle></svg>';
}

// --- Download the CSV template ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'template') {
    $content = bulk_import_csv_template_content();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="smartlib_bulk_import_template.csv"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

// --- Start over: drop any stored preview and go back to the upload form ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'reset') {
    unset($_SESSION[BULK_IMPORT_SESSION_KEY]);
    header('Location: bulk_import_books.php');
    exit;
}

$flash = ['type' => '', 'message' => ''];
$commitSummary = null;

// --- Preview (dry run): parse + validate, store results in the session,
// nothing is written to the database here. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_import'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        header('Location: bulk_import_books.php');
        exit;
    }

    $fileInfo = $_FILES['import_csv'] ?? null;
    $uploadError = (int)($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE);

    if (!$fileInfo || $uploadError === UPLOAD_ERR_NO_FILE) {
        $flash = ['type' => 'error', 'message' => 'Please choose a CSV file to upload.'];
    } elseif ($uploadError !== UPLOAD_ERR_OK) {
        $flash = ['type' => 'error', 'message' => 'Upload failed (the file may be too large). Please try again.'];
    } elseif (!is_uploaded_file((string)($fileInfo['tmp_name'] ?? ''))) {
        $flash = ['type' => 'error', 'message' => 'Upload failed. Please try again.'];
    } else {
        $parsed = bulk_import_parse_csv((string)$fileInfo['tmp_name']);
        if (!$parsed['ok']) {
            $flash = ['type' => 'error', 'message' => $parsed['error']];
        } else {
            $seenIsbns = [];
            $seenAccessions = [];
            $validated = [];
            $validCount = 0;
            foreach ($parsed['rows'] as $row) {
                $result = bulk_import_validate_row($conn, $row, $categoryByName, $programByName, $seenIsbns, $seenAccessions);
                $validated[] = $result;
                if ($result['ok']) {
                    $validCount++;
                }
            }

            $_SESSION[BULK_IMPORT_SESSION_KEY] = [
                'generated_at' => time(),
                'rows' => $validated
            ];

            if ($validCount === 0) {
                $flash = ['type' => 'error', 'message' => 'None of the ' . count($validated) . ' row(s) in this file passed validation. See the details below.'];
            }
        }
    }
}

// --- Commit: re-validate each previously-valid row against the CURRENT
// database state (another action may have changed things since preview),
// then insert. Each row is its own transaction so one bad row doesn't
// block the rest of the batch. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['commit_import'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    $preview = $_SESSION[BULK_IMPORT_SESSION_KEY] ?? null;

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken) || !is_array($preview)) {
        header('Location: bulk_import_books.php');
        exit;
    }

    $imported = 0;
    $skipped = 0;
    $skippedDetails = [];

    $seenIsbns = [];
    $seenAccessions = [];

    foreach ((array)($preview['rows'] ?? []) as $entry) {
        if (empty($entry['ok'])) {
            $skipped++;
            continue;
        }

        // Re-run the same per-row checks against the database as it stands
        // right now, since time has passed since the preview was generated.
        $data = $entry['data'];
        $recheckErrors = [];

        if ($data['isbn'] !== '') {
            $isbnKey = strtolower((string)$data['isbn']);
            if (isset($seenIsbns[$isbnKey])) {
                $recheckErrors[] = 'Duplicate ISBN within this batch.';
            } else {
                $dupStmt = $conn->prepare('SELECT 1 FROM books WHERE isbn = ? LIMIT 1');
                if ($dupStmt) {
                    $dupStmt->bind_param('s', $data['isbn']);
                    $dupStmt->execute();
                    $dupRes = $dupStmt->get_result();
                    if ($dupRes && $dupRes->num_rows > 0) {
                        $recheckErrors[] = 'ISBN now already exists (added since preview).';
                    }
                    $dupStmt->close();
                }
            }
        }

        foreach ((array)$data['accession_numbers'] as $accessionNo) {
            $accKey = strtolower((string)$accessionNo);
            if (isset($seenAccessions[$accKey])) {
                $recheckErrors[] = 'Duplicate accession number within this batch.';
                break;
            }
            if (manage_books_accession_exists($conn, (string)$accessionNo)) {
                $recheckErrors[] = 'Accession number "' . $accessionNo . '" now already exists (added since preview).';
                break;
            }
        }

        if (!empty($recheckErrors)) {
            $skipped++;
            $skippedDetails[] = 'Row ' . (int)$data['row_number'] . ' ("' . $data['title'] . '"): ' . implode(' ', $recheckErrors);
            continue;
        }

        $insertResult = bulk_import_insert_book($conn, $hasLocationTable, $hasBookLocationId, $hasBookCallNumber, $hasLegacyLocation, $libraryClassNames, $data);

        if ($insertResult['ok']) {
            $imported++;
            if ($data['isbn'] !== '') {
                $seenIsbns[strtolower((string)$data['isbn'])] = true;
            }
            foreach ((array)$data['accession_numbers'] as $accessionNo) {
                $seenAccessions[strtolower((string)$accessionNo)] = true;
            }
        } else {
            $skipped++;
            $skippedDetails[] = 'Row ' . (int)$data['row_number'] . ' ("' . $data['title'] . '"): ' . $insertResult['error'];
        }
    }

    if ($imported > 0) {
        manage_books_log_activity($conn, 'bulk_imported', 0, $imported . ' book(s) via CSV', [
            'imported' => $imported,
            'skipped' => $skipped
        ]);
    }

    unset($_SESSION[BULK_IMPORT_SESSION_KEY]);

    $commitSummary = [
        'imported' => $imported,
        'skipped' => $skipped,
        'details' => $skippedDetails
    ];
}

$previewData = $_SESSION[BULK_IMPORT_SESSION_KEY] ?? null;

require 'layout_top.php';
?>

<style>
    /* ---- Page-top ---- */
    .bulk-import-top { align-items: flex-start; }
    .bulk-import-panel { max-width: 880px; margin-left: auto; margin-right: auto; }
    .bulk-import-back-btn {
        display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
        width: 38px; height: 38px; border-radius: 50%; text-decoration: none;
        background: rgba(140, 185, 235, 0.1); border: 1.5px solid rgba(140, 185, 235, 0.34); color: #cfe2fa;
        transition: background .15s ease, border-color .15s ease, transform .14s ease;
    }
    .bulk-import-back-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2.3; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-back-btn:hover { background: rgba(140, 185, 235, 0.22); border-color: rgba(140, 185, 235, 0.6); transform: translateX(-2px); }
    .bulk-import-back-btn:active { transform: translateX(-1px); }

    /* ---- Stepper ---- */
    .bulk-import-stepper { display: flex; align-items: center; margin-bottom: 22px; }
    .bulk-import-stepper-step { display: flex; align-items: center; gap: 10px; flex: 0 0 auto; }
    .bulk-import-stepper-circle {
        width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
        display: grid; place-items: center; font-size: 13px; font-weight: 800;
        background: rgba(140, 185, 235, 0.1); border: 1.5px solid rgba(140, 185, 235, 0.32); color: #9fbfe4;
        transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
    }
    .bulk-import-stepper-circle svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-stepper-step.is-active .bulk-import-stepper-circle,
    .bulk-import-stepper-step.is-complete .bulk-import-stepper-circle {
        background: linear-gradient(160deg, #4d8ad4 0%, #1c4573 100%); border-color: rgba(140, 185, 235, 0.65); color: #fff;
    }
    .bulk-import-stepper-step.is-active .bulk-import-stepper-circle { box-shadow: 0 0 0 4px rgba(77, 138, 212, 0.22); }
    .bulk-import-stepper-label { font-size: 13px; font-weight: 700; color: #85a2c6; white-space: nowrap; }
    .bulk-import-stepper-step.is-active .bulk-import-stepper-label,
    .bulk-import-stepper-step.is-complete .bulk-import-stepper-label { color: #eaf3fc; }
    .bulk-import-stepper-line { flex: 1 1 auto; height: 2px; min-width: 20px; margin: 0 14px; border-radius: 2px; background: rgba(140, 185, 235, 0.2); }
    .bulk-import-stepper-line.is-complete { background: rgba(140, 185, 235, 0.6); }

    /* ---- "How it works" strip ---- */
    .bulk-import-howto { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin: 4px 0 22px; }
    .bulk-import-howto-item { display: flex; gap: 10px; align-items: flex-start; }
    .bulk-import-howto-icon {
        width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
        display: grid; place-items: center; background: rgba(140, 185, 235, 0.14);
        border: 1px solid rgba(140, 185, 235, 0.3); color: #d7e8fa;
    }
    .bulk-import-howto-icon svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-howto-text h4 { margin: 2px 0 3px; font-size: 13px; color: #eaf3fc; }
    .bulk-import-howto-text p { margin: 0; font-size: 12px; color: #92accf; line-height: 1.4; }

    /* ---- Template download row ---- */
    .bulk-import-template-row {
        display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;
        padding: 13px 16px; border-radius: 12px; margin-bottom: 20px;
        background: rgba(140, 185, 235, 0.07); border: 1px solid rgba(140, 185, 235, 0.22);
    }
    .bulk-import-template-copy { font-size: 13px; color: #b9cee2; }
    .bulk-import-template-copy strong { color: #eaf3fc; }
    .bulk-import-template-copy code { background: rgba(140, 185, 235, 0.14); border-radius: 5px; padding: 1px 5px; font-size: 12px; }
    .bulk-import-template-btn { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; flex-shrink: 0; }
    .bulk-import-template-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    /* ---- Dropzone ---- */
    .bulk-import-dropzone {
        display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 7px;
        padding: 36px 20px; border-radius: 14px; cursor: pointer;
        border: 2px dashed rgba(140, 185, 235, 0.35); background: rgba(140, 185, 235, 0.05);
        transition: border-color .15s ease, background .15s ease;
    }
    .bulk-import-dropzone:hover,
    .bulk-import-dropzone.is-dragover { border-color: rgba(140, 185, 235, 0.68); background: rgba(140, 185, 235, 0.12); }
    .bulk-import-dropzone.has-file { border-style: solid; border-color: rgba(122, 219, 160, 0.55); background: rgba(68, 148, 96, 0.1); }
    .bulk-import-dropzone-icon {
        width: 52px; height: 52px; border-radius: 50%; display: grid; place-items: center;
        background: rgba(140, 185, 235, 0.16); border: 1px solid rgba(140, 185, 235, 0.32); color: #cfe2fa;
        margin-bottom: 2px; transition: background .15s ease, color .15s ease;
    }
    .bulk-import-dropzone.has-file .bulk-import-dropzone-icon { background: rgba(84, 188, 123, 0.22); color: #bdf2d2; }
    .bulk-import-dropzone-icon svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-dropzone-title { font-weight: 700; font-size: 14px; color: #eaf3fc; }
    .bulk-import-dropzone-hint { font-size: 12px; color: #88a4c8; }
    .bulk-import-dropzone-filename { font-size: 13px; font-weight: 700; color: #7f9cc0; margin-top: 2px; word-break: break-all; }
    .bulk-import-dropzone.has-file .bulk-import-dropzone-filename { color: #9ff0c0; }
    .bulk-import-file-input { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

    /* ---- Info note ---- */
    .bulk-import-info-note {
        display: flex; gap: 10px; align-items: flex-start; margin: 16px 0 4px;
        padding: 11px 14px; border-radius: 11px; font-size: 12.5px; line-height: 1.5; color: #a9c2de;
        background: rgba(140, 185, 235, 0.07); border: 1px solid rgba(140, 185, 235, 0.2);
    }
    .bulk-import-info-note svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; color: #8fb3de; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    .bulk-import-panel > form .borrow-flash { margin-bottom: 18px; }

    /* ---- Flash message rendered as a floating, auto-dismissing toast instead of an inline banner ---- */
    .bulk-import-flash-toast {
        /* `left` starts as a 50%-of-viewport fallback and is immediately replaced with an
           exact pixel value by the script right below it, once it can measure where
           .main-content actually sits (it shares the row with the sidebar, so centering
           on the viewport as a whole would skew the toast toward the sidebar). */
        position: fixed; top: 22px; left: 50%; transform: translateX(-50%);
        z-index: 1000; display: flex; align-items: flex-start; gap: 12px;
        max-width: min(560px, calc(100vw - 32px));
        box-shadow: 0 18px 40px rgba(4, 14, 28, 0.35), 0 1px 0 rgba(255, 255, 255, 0.05) inset;
        animation: bulkImportToastIn 0.25s ease-out;
    }
    .bulk-import-flash-toast.is-positioned { transform: none; }
    .bulk-import-flash-toast-text { flex: 1; }
    .bulk-import-flash-toast-close {
        flex-shrink: 0; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center;
        background: rgba(255, 255, 255, 0.08); border: none; border-radius: 50%; cursor: pointer;
        color: inherit; font-size: 15px; line-height: 1; opacity: 0.75; padding: 0;
        transition: opacity .12s ease, background .12s ease;
    }
    .bulk-import-flash-toast-close:hover { opacity: 1; background: rgba(255, 255, 255, 0.16); }
    .bulk-import-flash-toast.is-leaving { animation: bulkImportToastOut 0.2s ease-in forwards; }
    @keyframes bulkImportToastIn {
        from { opacity: 0; margin-top: -14px; }
        to { opacity: 1; margin-top: 0; }
    }
    @keyframes bulkImportToastOut {
        from { opacity: 1; margin-top: 0; }
        to { opacity: 0; margin-top: -14px; }
    }

    .bulk-import-submit-row { display: flex; justify-content: center; margin-top: 20px; }
    .bulk-import-submit-row .btn-primary { min-width: 190px; }

    /* ---- Review step ---- */
    .bulk-import-summary-chips { display: flex; gap: 10px; flex-wrap: wrap; margin: 2px 0 18px; }
    .bulk-import-chip {
        display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px;
        font-size: 13px; font-weight: 700; border: 1px solid rgba(140, 185, 235, 0.28); background: rgba(140, 185, 235, 0.08); color: #dcebfa;
    }
    .bulk-import-chip svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-chip.is-ok { border-color: rgba(84, 188, 123, 0.4); background: rgba(68, 148, 96, 0.14); color: #bdf2d2; }
    .bulk-import-chip.is-error { border-color: rgba(233, 113, 113, 0.4); background: rgba(150, 57, 57, 0.14); color: #ffc9c9; }

    .bulk-import-row-num { color: #7f9cc0; font-variant-numeric: tabular-nums; font-size: 12.5px; }
    .bulk-import-call-chip { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; background: rgba(140, 185, 235, 0.1); border: 1px solid rgba(140, 185, 235, 0.22); padding: 3px 7px; border-radius: 6px; color: #cfe2fa; white-space: nowrap; }
    .bulk-import-copies-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; padding: 2px 7px; border-radius: 999px; background: rgba(140, 185, 235, 0.12); border: 1px solid rgba(140, 185, 235, 0.26); font-size: 12px; font-weight: 700; color: #dcebfa; }
    .bulk-import-row-ok-text { display: inline-flex; align-items: center; gap: 4px; color: #7fd6a0; font-size: 12px; font-weight: 600; }
    .bulk-import-row-ok-text svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-row-errors { margin: 0; padding-left: 16px; color: #ffb3b3; font-size: 12px; line-height: 1.55; }
    .bulk-import-row-errors li + li { margin-top: 2px; }

    .bulk-import-review-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; align-items: center; justify-content: flex-end; }

    /* ---- Commit result step ---- */
    .bulk-import-result { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; padding: 22px 10px 6px; }
    .bulk-import-result-icon { width: 60px; height: 60px; border-radius: 50%; display: grid; place-items: center; background: rgba(68, 148, 96, 0.18); border: 1px solid rgba(84, 188, 123, 0.4); color: #bdf2d2; margin-bottom: 4px; }
    .bulk-import-result-icon svg { width: 28px; height: 28px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-result h2 { margin: 0; font-size: 21px; color: #eaf3fc; }
    .bulk-import-result-count { font-size: 14.5px; color: #b9cee2; margin: 0; }
    .bulk-import-result-count strong { color: #9ff0c0; font-size: 16px; }
    .bulk-import-skip-panel { margin: 18px 0 4px; padding: 14px 16px; border-radius: 12px; background: rgba(214, 171, 58, 0.1); border: 1px solid rgba(214, 171, 58, 0.3); text-align: left; }
    .bulk-import-skip-panel-head { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; font-size: 13px; font-weight: 700; color: #ffe9b3; }
    .bulk-import-skip-panel-head svg { width: 15px; height: 15px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-skip-panel ul { margin: 0; padding-left: 18px; font-size: 12.5px; color: #f0dcae; line-height: 1.6; }
    .bulk-import-result-actions { display: flex; gap: 10px; justify-content: center; margin-top: 22px; flex-wrap: wrap; }
</style>

<div class="page-top bulk-import-top">
    <div>
        <h1 class="page-heading">
            <a href="manage_books.php" class="bulk-import-back-btn" aria-label="Back to Manage Books" title="Back to Manage Books"><?= admin_icon('arrow-left') ?></a>
            <span class="page-icon" aria-hidden="true"><?= admin_icon('box') ?></span>
            Bulk Import Books
        </h1>
        <p class="page-subtitle">Add several books at once from a CSV file &mdash; nothing is saved until you review and commit.</p>
    </div>
</div>

<?php
    $bulkImportStage = $commitSummary !== null ? 'commit' : (is_array($previewData) ? 'review' : 'upload');
    $bulkImportStepLabels = ['upload' => 'Upload', 'review' => 'Review', 'commit' => 'Commit'];
    $bulkImportStepKeys = array_keys($bulkImportStepLabels);
    $bulkImportStageIndex = (int)array_search($bulkImportStage, $bulkImportStepKeys, true);
?>

<?php if (($flash['message'] ?? '') !== ''): ?>
    <!-- Rendered outside the glass-card panel below on purpose: that panel has a
         backdrop-filter, and backdrop-filter (like transform) creates a new containing
         block for position:fixed descendants -- a fixed-position toast nested inside it
         would be "fixed" relative to the panel instead of the real viewport. -->
    <div class="borrow-flash <?= ($flash['type'] ?? '') === 'error' ? 'borrow-flash-error' : 'borrow-flash-info' ?> bulk-import-flash-toast" id="bulkImportFlash" role="alert">
        <span class="bulk-import-flash-toast-text"><?= htmlspecialchars((string)$flash['message']) ?></span>
        <button type="button" class="bulk-import-flash-toast-close" id="bulkImportFlashClose" aria-label="Dismiss">&times;</button>
    </div>
    <script>
    (function () {
        // Center the toast over .main-content specifically, not the whole viewport --
        // the sidebar sits beside it, so a plain 50vw center skews the toast toward the
        // sidebar on any normal desktop width. Runs immediately (before the entrance
        // animation is visible) and again on resize.
        var toast = document.getElementById('bulkImportFlash');
        var main = document.querySelector('.main-content');
        if (!toast || !main) { return; }

        function positionToast() {
            var rect = main.getBoundingClientRect();
            var centerX = rect.left + rect.width / 2;
            var width = toast.offsetWidth;
            var left = centerX - width / 2;
            var maxLeft = window.innerWidth - width - 16;
            left = Math.max(16, Math.min(left, maxLeft));
            toast.style.left = left + 'px';
            toast.classList.add('is-positioned');
        }

        positionToast();
        window.addEventListener('resize', positionToast);
    })();
    </script>
<?php endif; ?>

<section class="panel glass-card bulk-import-panel data-table-panel">

    <div class="bulk-import-stepper" role="list">
        <?php foreach ($bulkImportStepKeys as $i => $stepKey): ?>
            <?php $stepState = $i < $bulkImportStageIndex ? 'is-complete' : ($i === $bulkImportStageIndex ? 'is-active' : ''); ?>
            <div class="bulk-import-stepper-step <?= $stepState ?>" role="listitem">
                <span class="bulk-import-stepper-circle">
                    <?php if ($stepState === 'is-complete'): ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7"></path></svg>
                    <?php else: ?>
                        <?= $i + 1 ?>
                    <?php endif; ?>
                </span>
                <span class="bulk-import-stepper-label"><?= htmlspecialchars($bulkImportStepLabels[$stepKey]) ?></span>
            </div>
            <?php if ($i < count($bulkImportStepKeys) - 1): ?>
                <span class="bulk-import-stepper-line <?= $i < $bulkImportStageIndex ? 'is-complete' : '' ?>"></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($commitSummary !== null): ?>

        <div class="bulk-import-result">
            <span class="bulk-import-result-icon"><?= admin_icon('check-circle') ?></span>
            <h2>Import complete</h2>
            <p class="bulk-import-result-count"><strong><?= (int)$commitSummary['imported'] ?></strong> book(s) imported successfully<?= $commitSummary['skipped'] > 0 ? ', ' . (int)$commitSummary['skipped'] . ' row(s) skipped' : '' ?>.</p>
        </div>

        <?php if ($commitSummary['skipped'] > 0): ?>
            <div class="bulk-import-skip-panel">
                <p class="bulk-import-skip-panel-head"><?= admin_icon('alert-triangle') ?> <?= (int)$commitSummary['skipped'] ?> row(s) were skipped</p>
                <ul>
                    <?php foreach ($commitSummary['details'] as $detailLine): ?>
                        <li><?= htmlspecialchars($detailLine) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="bulk-import-result-actions">
            <a class="btn-primary" href="manage_books.php">Go to Manage Books</a>
            <a class="filter-reset-btn" href="bulk_import_books.php">Import another file</a>
        </div>

    <?php elseif (is_array($previewData)): ?>

        <?php
            $rows = (array)($previewData['rows'] ?? []);
            $validCount = 0;
            foreach ($rows as $r) {
                if (!empty($r['ok'])) {
                    $validCount++;
                }
            }
            $errorCount = count($rows) - $validCount;
        ?>

        <div class="bulk-import-summary-chips">
            <span class="bulk-import-chip"><?= admin_icon('list') ?> <?= count($rows) ?> row(s) checked</span>
            <span class="bulk-import-chip is-ok"><?= admin_icon('check-circle') ?> <?= $validCount ?> ready</span>
            <?php if ($errorCount > 0): ?>
                <span class="bulk-import-chip is-error"><?= admin_icon('alert-triangle') ?> <?= $errorCount ?> with problems</span>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Status</th>
                        <th>Title</th>
                        <th>Call #</th>
                        <th>Copies</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $d = $r['data']; ?>
                        <tr>
                            <td class="bulk-import-row-num"><?= (int)$d['row_number'] ?></td>
                            <td>
                                <?php if (!empty($r['ok'])): ?>
                                    <span class="badge status-active">OK</span>
                                <?php else: ?>
                                    <span class="badge status-inactive">Error</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)$d['title']) ?></td>
                            <td><?php if ((string)$d['call_number'] !== ''): ?><span class="bulk-import-call-chip"><?= htmlspecialchars((string)$d['call_number']) ?></span><?php endif; ?></td>
                            <td><?php $copyCount = count((array)$d['accession_numbers']); ?><?php if ($copyCount > 0): ?><span class="bulk-import-copies-badge"><?= $copyCount ?></span><?php else: ?>&mdash;<?php endif; ?></td>
                            <td>
                                <?php if (!empty($r['errors'])): ?>
                                    <ul class="bulk-import-row-errors">
                                        <?php foreach ($r['errors'] as $err): ?>
                                            <li><?= htmlspecialchars($err) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="bulk-import-row-ok-text"><?= admin_icon('check-circle') ?> Ready</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="bulk-import-review-actions">
            <a class="filter-reset-btn" href="bulk_import_books.php?action=reset">Start over</a>
            <?php if ($validCount > 0): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" name="commit_import" class="btn-primary">Commit <?= $validCount ?> valid row(s)</button>
                </form>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="bulk-import-howto">
            <div class="bulk-import-howto-item">
                <span class="bulk-import-howto-icon"><?= admin_icon('export') ?></span>
                <div class="bulk-import-howto-text">
                    <h4>1. Download the template</h4>
                    <p>A ready-made CSV with the right columns and two example rows.</p>
                </div>
            </div>
            <div class="bulk-import-howto-item">
                <span class="bulk-import-howto-icon"><?= admin_icon('edit') ?></span>
                <div class="bulk-import-howto-text">
                    <h4>2. Fill in one row per book</h4>
                    <p>Multiple copies? List their accession numbers in one cell separated by <code>;</code></p>
                </div>
            </div>
            <div class="bulk-import-howto-item">
                <span class="bulk-import-howto-icon"><?= bulk_import_icon_upload() ?></span>
                <div class="bulk-import-howto-text">
                    <h4>3. Upload &amp; review</h4>
                    <p>Check every row before anything is saved to the catalog.</p>
                </div>
            </div>
        </div>

        <div class="bulk-import-template-row">
            <span class="bulk-import-template-copy">Need the column layout? e.g. <code>title,author,...,accession_numbers</code></span>
            <a class="filter-reset-btn bulk-import-template-btn" href="bulk_import_books.php?action=template"><?= admin_icon('export') ?> Download CSV template</a>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <label for="bulkImportFile" class="bulk-import-dropzone" id="bulkImportDropzone">
                <span class="bulk-import-dropzone-icon"><?= bulk_import_icon_upload() ?></span>
                <span class="bulk-import-dropzone-title">Click to choose a CSV file</span>
                <span class="bulk-import-dropzone-hint">or drag and drop it here</span>
                <span class="bulk-import-dropzone-filename" id="bulkImportFileName">No file chosen</span>
                <input type="file" name="import_csv" id="bulkImportFile" accept=".csv,text/csv" required class="bulk-import-file-input">
            </label>

            <p class="bulk-import-info-note"><?= bulk_import_icon_info() ?> New books from this import are added without a cover image. The nightly cover-fetch job picks up every book with no cover automatically, usually within a day.</p>

            <div class="bulk-import-submit-row">
                <button type="submit" name="preview_import" class="btn-primary">Preview import</button>
            </div>
        </form>

        <script>
        (function () {
            // Auto-dismiss the upload-error toast after a while, or right away if the
            // person closes it themselves -- either way it fades out rather than vanishing.
            var AUTO_DISMISS_MS = 8000;
            var toast = document.getElementById('bulkImportFlash');
            if (toast) {
                var dismissed = false;
                var dismiss = function () {
                    if (dismissed) { return; }
                    dismissed = true;
                    toast.classList.add('is-leaving');
                    setTimeout(function () {
                        if (toast.parentNode) { toast.parentNode.removeChild(toast); }
                    }, 200);
                };
                var autoTimer = setTimeout(dismiss, AUTO_DISMISS_MS);
                var closeBtn = document.getElementById('bulkImportFlashClose');
                if (closeBtn) {
                    closeBtn.addEventListener('click', function () {
                        clearTimeout(autoTimer);
                        dismiss();
                    });
                }
            }
        })();

        (function () {
            var dropzone = document.getElementById('bulkImportDropzone');
            var input = document.getElementById('bulkImportFile');
            var nameEl = document.getElementById('bulkImportFileName');
            if (!dropzone || !input || !nameEl) { return; }

            function showFile(file) {
                if (file) {
                    nameEl.textContent = file.name;
                    dropzone.classList.add('has-file');
                } else {
                    nameEl.textContent = 'No file chosen';
                    dropzone.classList.remove('has-file');
                }
            }

            input.addEventListener('change', function () {
                showFile(input.files && input.files[0] ? input.files[0] : null);
            });

            ['dragover', 'dragenter'].forEach(function (evt) {
                dropzone.addEventListener(evt, function (e) {
                    e.preventDefault();
                    dropzone.classList.add('is-dragover');
                });
            });

            ['dragleave', 'dragend'].forEach(function (evt) {
                dropzone.addEventListener(evt, function () {
                    dropzone.classList.remove('is-dragover');
                });
            });

            dropzone.addEventListener('drop', function (e) {
                e.preventDefault();
                dropzone.classList.remove('is-dragover');
                var files = e.dataTransfer && e.dataTransfer.files;
                if (files && files.length > 0) {
                    input.files = files;
                    showFile(files[0]);
                }
            });
        })();
        </script>

    <?php endif; ?>

</section>

<?php require 'layout_bottom.php'; ?>
