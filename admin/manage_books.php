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
require_once '../config/session_guard.php';
if (!smartlib_active_staff_session($conn)) {
    header('Location: ' . smartlib_staff_signed_out_location());
    exit;
}
require_once '../config/admin_audit.php';
require_once '../config/csrf.php';

require_once __DIR__ . '/includes/manage_books_functions.php';
require_once __DIR__ . '/../config/cover_fetch_helpers.php';
require_once __DIR__ . '/../config/staff_errors.php';
require_once __DIR__ . '/../config/book_cover.php';
require_once __DIR__ . '/../config/request_input.php';

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

// Loan sections (config/loan_policy.php decides days / room use only).
$librarySections = [
    'Circulation',
    'Filipiniana',
    'Fiction',
    'Reference',
    'Thesis',
    'Basic Education'
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

$hasLocationTable = table_exists($conn, 'library_locations');
$hasBookLocationId = column_exists($conn, 'books', 'location_id');
$hasBookCallNumber = column_exists($conn, 'books', 'call_number');
$hasLegacyLocation = column_exists($conn, 'books', 'location');

$csrfToken = smartlib_csrf_token('manage_books_csrf');
$flash = ['type' => '', 'message' => ''];
$openAddBookModal = false;
$openEditBookModal = false;
$hasBookCopiesTable = table_exists($conn, 'book_copies');
// Several programs per book: book_programs holds every linked program; the
// main one also stays in books.program_id (see manage_books_functions.php).
$hasBookProgramsTable = table_exists($conn, 'book_programs');
$programNameById = [];
foreach ($programOptions as $programOption) {
    $programNameById[(int)$programOption['program_id']] = (string)$programOption['program_name'];
}
$programIdSet = array_fill_keys(array_keys($programNameById), true);

// --- List position: search, status chip and page. Edit, Close, a saved
// edit, the cover actions and Delete all carry these three so the librarian
// lands back where they were. The modal forms have no action attribute, so
// they post to the current URL and the handlers read the same $_GET values.
// Only these three are ever copied from the request into a URL. ---
const MANAGE_BOOKS_PER_PAGE = 50;
$allowedStatus = ['', 'available', 'borrowed', 'no_copies', 'newly_added'];
$search = trim(smartlib_request_string($_GET, 'search'));
$activeStatus = strtolower(trim(smartlib_request_string($_GET, 'status')));
if (!in_array($activeStatus, $allowedStatus, true)) {
    $activeStatus = '';
}
// int only: missing, non-numeric, arrays, 0 or negative = page 1 (the upper
// end is clamped to the last page once the filtered total is known).
$booksPage = max(1, smartlib_request_int($_GET, 'page', 1));
$listContext = ['search' => $search, 'status' => $activeStatus, 'page' => $booksPage];

/**
 * Every Manage Books list/edit URL. Known keys only, in a fixed order;
 * empty values and page 1 are left out; values are URL-encoded and the
 * result is always a relative manage_books.php URL (no open redirect).
 * $fragment is only ever 'book-row-<id>' built here from an int.
 */
$buildManageBooksUrl = static function (array $params, string $fragment = ''): string {
    $clean = [];
    foreach (['edit', 'search', 'status', 'page', 'updated', 'cover_status', 'cover_msg'] as $key) {
        if (!array_key_exists($key, $params) || is_array($params[$key])) {
            continue;
        }
        $text = trim((string)$params[$key]);
        if ($key === 'edit' || $key === 'page') {
            $number = (int)$text;
            if ($number <= ($key === 'page' ? 1 : 0)) {
                continue;
            }
            $text = (string)$number;
        }
        if ($text !== '') {
            $clean[$key] = $text;
        }
    }

    return 'manage_books.php'
        . (!empty($clean) ? ('?' . http_build_query($clean, '', '&', PHP_QUERY_RFC3986)) : '')
        . (preg_match('/^book-row-[1-9][0-9]*$/', $fragment) ? ('#' . $fragment) : '');
};
// The list URL for the current position, plus extra keys (extra wins).
$manageBooksUrl = static function (array $extra = [], string $fragment = '') use (&$listContext, $buildManageBooksUrl): string {
    return $buildManageBooksUrl($extra + $listContext, $fragment);
};
$addBookForm = [
    'isbn' => '',
    'title' => '',
    'author' => '',
    'publisher' => '',
    'year_published' => '',
    'category_id' => 0,
    'program_id' => 0,
    'program_ids' => [],
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
    // Ticked programs (program_ids[]) and the main one (main_program_id);
    // an old single program_id post still works. books.program_id = main.
    $programSelection = manage_books_program_selection($_POST, $programIdSet);
    $programIds = $programSelection['ids'];
    $programId = $programSelection['main'];

    $locationSection = trim((string)($_POST['location_section'] ?? ''));
    $callNumber = trim((string)($_POST['call_number'] ?? ''));
    // The Add Book window asks for Section and Call Number only. The class
    // letter comes from the call number (manage_books_class_from_call_number,
    // shared with Edit Book); shelf, row and position are left empty.
    $locationClass = manage_books_class_from_call_number($callNumber, $libraryClassLabels);
    $locationShelf = '';
    $locationRow = '';
    $locationPosition = '';

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
        'program_ids' => $programIds,
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
    } elseif ($locationSection === '') {
        $errorMessage = 'Section is required.';
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
        if ($hasLocationTable && $hasBookLocationId) {
            $locationId = manage_books_resolve_location_id(
                $conn,
                $libraryClassNames,
                $locationSection,
                $locationClass,
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

            if ($hasBookProgramsTable) {
                manage_books_sync_book_programs($conn, $newBookId, $programIds, $programId);
            }

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

            // Immediate cover fetch: Open Library only (useGoogle=false), so
            // saving a new book stays fast and never spends any of the
            // shared Google Books daily quota the nightly backfill job
            // relies on. Best-effort -- the book is already saved and
            // committed above, so a failure here never blocks adding it;
            // books.cover just stays NULL and the nightly job will try
            // again on its own.
            if (fbc_curl_available()) {
                try {
                    $coverResult = fbc_find_cover($title, $author, $isbn, false, false, $newBookId);
                    if ($coverResult['status'] === 'found') {
                        fbc_save_cover_bytes($conn, $newBookId, $coverResult['bytes'], $coverResult['ext']);
                    }
                } catch (Throwable $coverError) {
                    // Swallow -- cover lookup failures must never block adding the book.
                }
            }

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

            // Duplicate keys get their usual friendly text; the app's own
            // messages show as written; any other error is logged and shown
            // as a plain message (config/staff_errors.php, SR-20).
            $msg = smartlib_staff_duplicate_message($e,
                'ISBN already exists. Each book must have a unique ISBN.',
                'One of the accession numbers already exists. Please review the copy accessions.');
            if ($msg === '') {
                $msg = smartlib_staff_error_message($e, 'Unable to add book and copies right now. Nothing was saved. Please try again.', 'add book');
            }

            $flash = ['type' => 'error', 'message' => $msg];
            $openAddBookModal = true;
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_book'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        header('Location: ' . $manageBooksUrl());
        exit;
    }

    $bookId = (int)($_POST['book_id'] ?? 0);

    $title = trim((string)($_POST['title'] ?? ''));
    $author = trim((string)($_POST['author'] ?? ''));
    $publisher = trim((string)($_POST['publisher'] ?? ''));
    $year = trim((string)($_POST['year_published'] ?? ''));
    $isbn = trim((string)($_POST['isbn'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $programSelection = manage_books_program_selection($_POST, $programIdSet);
    $programIds = $programSelection['ids'];
    $programId = $programSelection['main'];

    // Location: the Section only. location_class / _shelf / _row / _position
    // are never read (an old cached page may still post them); see the
    // location update below for what a Section change does.
    $locationSection = trim((string)($_POST['location_section'] ?? ''));
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
        'program_ids' => $programIds,
        'location_section' => $locationSection,
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
        // The book's stored Section, to tell a real Section change from a
        // save that leaves it as it was.
        $storedLocationId = 0;
        $storedSection = '';
        if ($hasLocationTable && $hasBookLocationId) {
            $storedLocStmt = $conn->prepare(
                'SELECT COALESCE(b.location_id, 0) AS location_id, COALESCE(loc.section, \'\') AS section
                 FROM books b
                 LEFT JOIN library_locations loc ON loc.location_id = b.location_id
                 WHERE b.book_id = ?
                 LIMIT 1'
            );
            if ($storedLocStmt) {
                $storedLocStmt->bind_param('i', $bookId);
                $storedLocStmt->execute();
                $storedLocRes = $storedLocStmt->get_result();
                $storedLocRow = $storedLocRes ? $storedLocRes->fetch_assoc() : null;
                $storedLocStmt->close();
                $storedLocationId = (int)($storedLocRow['location_id'] ?? 0);
                $storedSection = (string)($storedLocRow['section'] ?? '');
            }
        }
        $copyIsbn = substr($isbn, 0, 20);

        $conn->begin_transaction();
        try {
            $coreUpd = $conn->prepare(
                "UPDATE books
                 SET isbn = NULLIF(?, ''),
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

            if ($hasBookProgramsTable) {
                manage_books_sync_book_programs($conn, $bookId, $programIds, $programId);
            }

            // Location (Section only; never invented, never wiped):
            // - blank Section: left as it is;
            // - the same Section as stored: left as it is (no new row);
            // - a different Section (or none stored yet): a location for that
            //   Section with the class letter from the call number, exactly as
            //   Add Book does, and empty shelf, row and position. The old
            //   library_locations row is left alone.
            if ($hasLocationTable && $hasBookLocationId) {
                if ($locationSection !== '' && !($storedLocationId > 0 && $locationSection === $storedSection)) {
                    $locationId = manage_books_resolve_location_id(
                        $conn,
                        $libraryClassNames,
                        $locationSection,
                        manage_books_class_from_call_number($callNumber, $libraryClassLabels),
                        '',
                        '',
                        ''
                    );
                    if ($locationId > 0) {
                        $locUpd = $conn->prepare('UPDATE books SET location_id = ? WHERE book_id = ?');
                        if ($locUpd) {
                            $locUpd->bind_param('ii', $locationId, $bookId);
                            $locUpd->execute();
                            $locUpd->close();
                        }
                    }
                }
            } elseif ($hasLegacyLocation && $locationSection !== '') {
                // Installs without the location tables: text from the Section and call number only.
                $legacyLocationText = build_legacy_location_text($locationSection, '', '', '', '', $callNumber);
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

            $copyIsbnStmt = $conn->prepare("UPDATE book_copies SET isbn = NULLIF(?, '') WHERE book_id = ?");
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

            manage_books_log_activity(
                $conn,
                'updated',
                $bookId,
                $title,
                [
                    'isbn' => $isbn,
                    'author' => $author,
                    'year_published' => $year,
                    'copy_count_after_update' => count($finalExistingAccessions) + count($newAccessionsToInsert),
                    'copies_added' => count($newAccessionsToInsert),
                    'copies_removed' => count($copyDeleteIds)
                ]
            );
            $conn->commit();
            header('Location: ' . $manageBooksUrl(['updated' => '1'], 'book-row-' . (int)$bookId));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = smartlib_staff_duplicate_message($e,
                'ISBN already exists. Each book must have a unique ISBN.',
                'One of the accession numbers already exists. Please review the copy accessions.');
            if ($msg === '') {
                $msg = smartlib_staff_error_message($e, 'Unable to update book details right now. Nothing was changed. Please try again.', 'edit book');
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
        header('Location: ' . $manageBooksUrl());
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
                b.program_id,
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

        // The book's programs (main first), read before the delete removes
        // its book_programs rows, so Archived History can restore them.
        $deletedProgramIds = [];
        if (is_array($bookSnapshot)) {
            $deletedMainProgramId = (int)($bookSnapshot['program_id'] ?? 0);
            $deletedLinks = $hasBookProgramsTable ? (manage_books_fetch_book_programs($conn, [$bookId])[$bookId] ?? []) : [];
            $deletedProgramIds = manage_books_ordered_program_ids($deletedMainProgramId, $deletedLinks, $programNameById);
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
                if (!empty($deletedProgramIds)) {
                    $deleteMeta['program_id'] = (int)($bookSnapshot['program_id'] ?? 0);
                    $deleteMeta['program_ids'] = $deletedProgramIds;
                }

                manage_books_log_activity($conn, 'deleted', $archivedBookId, $archivedTitle, $deleteMeta);
            }
        }
    }

    // Same list page (a page that became empty is clamped to the new last page).
    header('Location: ' . $manageBooksUrl());
    exit;
}

// --- Cover fix-up actions for the Edit Book modal: re-fetch, manual
// upload, and clear. All three redirect back into edit mode for the same
// book (?edit=<id>) so the librarian immediately sees the result without
// losing their place, carrying a short status message via query string. ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refetch_cover'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    $coverBookId = (int)($_POST['book_id'] ?? 0);
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken) || $coverBookId <= 0) {
        header('Location: ' . $manageBooksUrl());
        exit;
    }

    $coverBookRow = null;
    $coverBookStmt = $conn->prepare('SELECT title, author, isbn FROM books WHERE book_id = ? LIMIT 1');
    if ($coverBookStmt) {
        $coverBookStmt->bind_param('i', $coverBookId);
        $coverBookStmt->execute();
        $coverBookRes = $coverBookStmt->get_result();
        $coverBookRow = $coverBookRes ? $coverBookRes->fetch_assoc() : null;
        $coverBookStmt->close();
    }

    $refetchOk = false;
    $refetchMessage = 'Book not found.';

    if ($coverBookRow) {
        if (!fbc_curl_available()) {
            $refetchMessage = "The server's PHP curl extension is disabled, so cover lookups aren't available right now.";
        } else {
            try {
                // The librarian is right here to look at the result before
                // it's saved, so allow the full cascade (including Google)
                // rather than the Add Book fast path's Open-Library-only lookup.
                $coverResult = fbc_find_cover(
                    (string)($coverBookRow['title'] ?? ''),
                    (string)($coverBookRow['author'] ?? ''),
                    (string)($coverBookRow['isbn'] ?? ''),
                    false,
                    true,
                    $coverBookId
                );
                if ($coverResult['status'] === 'found') {
                    $coverSaveResult = fbc_save_cover_bytes($conn, $coverBookId, $coverResult['bytes'], $coverResult['ext']);
                    if ($coverSaveResult['ok']) {
                        $refetchOk = true;
                        $refetchMessage = 'Found and saved a new cover (via ' . $coverResult['via'] . '). Check that it looks right.';
                        manage_books_log_activity($conn, 'cover_refetched', $coverBookId, (string)($coverBookRow['title'] ?? ''), ['via' => $coverResult['via']]);
                    } else {
                        $refetchMessage = (string)($coverSaveResult['error'] ?? 'Found a cover but could not save it.');
                    }
                } elseif (($coverResult['no_cover_notice'] ?? null) !== null) {
                    $notice = $coverResult['no_cover_notice'];
                    $refetchMessage = 'Found a confident match ("' . (string)($notice['matched_title'] ?? '') . '") but that source has no cover image for it.';
                } else {
                    $refetchMessage = 'No confident match found for this title/author/ISBN.';
                }
            } catch (Throwable $coverError) {
                $refetchMessage = 'Cover lookup failed unexpectedly. Please try again.';
            }
        }
    }

    header('Location: ' . $manageBooksUrl(['edit' => $coverBookId, 'cover_status' => $refetchOk ? 'ok' : 'error', 'cover_msg' => $refetchMessage]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_cover'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    $coverBookId = (int)($_POST['book_id'] ?? 0);
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken) || $coverBookId <= 0) {
        header('Location: ' . $manageBooksUrl());
        exit;
    }

    // The book must exist before anything is written (security review
    // 2026-11-24, SR-15): no orphan cover_<id> file for a made-up ID.
    $coverBookExists = false;
    $coverBookCheck = $conn->prepare('SELECT 1 FROM books WHERE book_id = ? LIMIT 1');
    if ($coverBookCheck) {
        $coverBookCheck->bind_param('i', $coverBookId);
        $coverBookCheck->execute();
        $coverBookCheck->store_result();
        $coverBookExists = $coverBookCheck->num_rows > 0;
        $coverBookCheck->close();
    }
    if (!$coverBookExists) {
        header('Location: ' . $manageBooksUrl());
        exit;
    }

    $uploadOk = false;
    $uploadMessage = 'Please choose a JPG or PNG image to upload.';

    $fileInfo = $_FILES['cover_image'] ?? null;
    $uploadError = (int)($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($fileInfo && $uploadError === UPLOAD_ERR_OK && (int)($fileInfo['size'] ?? 0) > FBC_UPLOAD_MAX_BYTES) {
        $uploadMessage = FBC_UPLOAD_TOO_LARGE_MESSAGE;
    } elseif ($fileInfo && in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $uploadMessage = FBC_UPLOAD_TOO_LARGE_MESSAGE;
    } elseif ($fileInfo && $uploadError === UPLOAD_ERR_OK && is_uploaded_file((string)($fileInfo['tmp_name'] ?? ''))) {
        $validated = fbc_validate_uploaded_image((string)$fileInfo['tmp_name']);
        if ($validated['ok']) {
            $uploadSaveResult = fbc_save_cover_bytes($conn, $coverBookId, $validated['bytes'], $validated['ext']);
            if ($uploadSaveResult['ok']) {
                $uploadOk = true;
                $uploadMessage = 'Cover image uploaded.';
                $uploadTitleRow = $conn->query('SELECT title FROM books WHERE book_id = ' . $coverBookId);
                $uploadTitle = $uploadTitleRow ? (string)($uploadTitleRow->fetch_assoc()['title'] ?? '') : '';
                manage_books_log_activity($conn, 'cover_uploaded', $coverBookId, $uploadTitle, []);
            } else {
                $uploadMessage = (string)($uploadSaveResult['error'] ?? 'Could not save the uploaded image.');
            }
        } else {
            $uploadMessage = (string)($validated['error'] ?? 'That file could not be used as a cover image.');
        }
    } elseif ($fileInfo && $uploadError !== UPLOAD_ERR_NO_FILE) {
        $uploadMessage = 'Upload failed (the file may be too large). Please try a smaller image.';
    }

    header('Location: ' . $manageBooksUrl(['edit' => $coverBookId, 'cover_status' => $uploadOk ? 'ok' : 'error', 'cover_msg' => $uploadMessage]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cover'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    $coverBookId = (int)($_POST['book_id'] ?? 0);
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken) || $coverBookId <= 0) {
        header('Location: ' . $manageBooksUrl());
        exit;
    }

    $clearMessage = 'Book not found.';
    $clearBookRow = null;
    $clearStmt = $conn->prepare('SELECT title, cover FROM books WHERE book_id = ? LIMIT 1');
    if ($clearStmt) {
        $clearStmt->bind_param('i', $coverBookId);
        $clearStmt->execute();
        $clearRes = $clearStmt->get_result();
        $clearBookRow = $clearRes ? $clearRes->fetch_assoc() : null;
        $clearStmt->close();
    }

    if ($clearBookRow) {
        $clearResult = fbc_clear_cover(
            $conn,
            $coverBookId,
            (string)($clearBookRow['cover'] ?? ''),
            'cleared by a librarian in Manage Books',
            smartlib_admin_audit_actor_name()
        );
        if ($clearResult['ok']) {
            $clearMessage = 'Cover cleared. The book now shows its generated cover, and the nightly job won\'t fetch this one again. Re-fetch or upload a cover here if you find the right one.';
            manage_books_log_activity($conn, 'cover_cleared', $coverBookId, (string)($clearBookRow['title'] ?? ''), []);
        } else {
            $clearMessage = (string)($clearResult['error'] ?? 'Could not clear the cover.');
        }
    }

    header('Location: ' . $manageBooksUrl(['edit' => $coverBookId, 'cover_status' => 'ok', 'cover_msg' => $clearMessage]));
    exit;
}

$editBook = null;
$editId = isset($editId) ? (int)$editId : 0;
if ($editId <= 0) {
    $editId = smartlib_request_int($_GET, 'edit', 0);
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
            b.location,
            b.cover
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
        OR bc.accession_no LIKE '%{$safeSearch}%'" . ($hasBookProgramsTable ? "
        OR EXISTS (
            SELECT 1 FROM book_programs bpx
            INNER JOIN programs px ON px.program_id = bpx.program_id
            WHERE bpx.book_id = b.book_id AND px.program_name LIKE '%{$safeSearch}%'
        )" : '') . "
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

// Filtered total: the same grouped query (joins, WHERE, HAVING), counted.
$filteredBooksTotal = 0;
$filteredCountRes = $conn->query(
    "
    SELECT COUNT(*) AS filtered_total FROM (
        SELECT b.book_id
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        LEFT JOIN programs p ON b.program_id = p.program_id
        LEFT JOIN book_copies bc ON bc.book_id = b.book_id
        LEFT JOIN library_locations loc ON loc.location_id = b.location_id
        {$whereSql}
        GROUP BY b.book_id
        {$havingSql}
    ) filtered_books
    "
);
if ($filteredCountRes && ($filteredCountRow = $filteredCountRes->fetch_assoc())) {
    $filteredBooksTotal = (int)($filteredCountRow['filtered_total'] ?? 0);
}
$totalPages = max(1, (int)ceil($filteredBooksTotal / MANAGE_BOOKS_PER_PAGE));
$booksPage = min($booksPage, $totalPages);
$listContext['page'] = $booksPage;
$pageOffset = ($booksPage - 1) * MANAGE_BOOKS_PER_PAGE;
$pageLimitSql = 'LIMIT ' . (int)$pageOffset . ', ' . (int)MANAGE_BOOKS_PER_PAGE;

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
    ORDER BY b.created_at DESC, b.book_id DESC
    {$pageLimitSql}
    "
);

$bookRows = [];
if ($books) {
    while ($bookRow = $books->fetch_assoc()) {
        $bookRows[] = $bookRow;
    }
}

// Programs per listed book (one query): the Program column shows the main
// program plus "+N" for the others.
$bookProgramMap = ($hasBookProgramsTable && !empty($bookRows))
    ? manage_books_fetch_book_programs($conn, array_column($bookRows, 'book_id'))
    : [];

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

$coverFlash = null;
if (isset($_GET['cover_status'])) {
    $coverFlash = [
        'status' => smartlib_request_string($_GET, 'cover_status'),
        'message' => smartlib_request_string($_GET, 'cover_msg')
    ];
}

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
    'program_ids' => [],
    'cover' => (string)($editBook['cover'] ?? ''),
    // The stored Section only (blank -> "Select Section"); nothing invented.
    'location_section' => '',
    'call_number' => '',
    'copy_count' => $editCopyTotal > 0 ? (string)$editCopyTotal : '',
    'edit_copy_accessions' => [],
    'new_accession_numbers' => []
];

if ($editBook) {
    $editBookLinks = $hasBookProgramsTable
        ? (manage_books_fetch_book_programs($conn, [(int)$editBook['book_id']])[(int)$editBook['book_id']] ?? [])
        : [];
    $editForm['program_ids'] = manage_books_ordered_program_ids((int)$editForm['program_id'], $editBookLinks, $programNameById);
    $editForm['location_section'] = (string)($editBook['loc_section'] ?? '');
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

<style>
    .page-top-actions { display: flex; gap: 10px; align-items: center; }
    .bulk-import-nav-btn {
        display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
        border-radius: 999px; padding: 10px 18px; font-weight: 700; font-size: 14px;
        background: rgba(var(--pb-8cb9eb), 0.16); border: 1.5px solid rgba(var(--pb-8cb9eb), 0.55); color: rgb(var(--pb-eaf3fc));
        transition: background .15s ease, border-color .15s ease, transform .14s ease, box-shadow .14s ease;
    }
    .bulk-import-nav-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .bulk-import-nav-btn:hover { background: rgba(var(--pb-8cb9eb), 0.27); border-color: rgba(var(--pb-8cb9eb), 0.8); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(var(--pb-0d2440), 0.22); }
    .bulk-import-nav-btn:active { transform: translateY(0); }
</style>

<div class="page-top">
    <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('books') ?></span>Manage Books</h1>
    <div class="page-top-actions">
        <a class="bulk-import-nav-btn" href="bulk_import_books.php"><?= admin_icon('box') ?> Bulk Import</a>
        <button class="btn-primary" onclick="openBookModal()">+ Add Book</button>
    </div>
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
        <?php $chipMeta = stat_chip_meta((string)($chip['title'] ?? '')); ?>
        <a href="<?= htmlspecialchars($chipHref) ?>" class="stat-card glass-card borrow-chip<?= $isActiveChip ? ' is-active' : '' ?>">
            <div class="metric-head-row">
                <span class="metric-icon-badge metric-icon-badge--<?= htmlspecialchars($chipMeta['accent']) ?>" aria-hidden="true"><?= admin_icon($chipMeta['icon']) ?></span>
                <h3><?= htmlspecialchars((string)($chip['title'] ?? '')) ?></h3>
            </div>
            <p class="value"><?= number_format((int)($chip['count'] ?? 0)) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="filters-bar-panel">
    <form method="GET" class="filters-inline manage-books-filters borrow-filters">
        <input type="hidden" name="status" value="<?= htmlspecialchars($activeStatus) ?>">
        <div class="search-field-wrap">
            <span class="filters-search-icon" aria-hidden="true"><?= admin_icon('search') ?></span>
            <input type="text" name="search" placeholder="Search ISBN, title, author, year, accession" value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn-primary">Apply</button>
        <a href="manage_books.php" class="filter-reset-btn">Reset</a>
    </form>
</section>

<section class="panel glass-card data-table-panel">
    <div class="table-wrap">
        <table class="data-table manage-books-table pp-program-table">
            <thead>
                <tr>
                    <th>Book ID</th>
                    <th><?= $activeStatus === 'borrowed' ? 'Accession #' : 'ISBN' ?></th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Year</th>
                    <th>Genre</th>
                    <th>Program</th>
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
                        <tr id="book-row-<?= $bookId ?>">
                            <td class="book-id-col"><?= $bookId ?></td>
                            <td><?= htmlspecialchars($primaryIdentifier) ?></td>
                            <td><?= htmlspecialchars($row['title'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['author'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['year_published'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['genre_name'] ?? 'Uncategorized') ?></td>
                            <?php
                                $rowMainProgramId = (int)($row['program_id'] ?? 0);
                                $rowProgramIds = manage_books_ordered_program_ids($rowMainProgramId, $bookProgramMap[$bookId] ?? [], $programNameById);
                                $rowOtherNames = [];
                                foreach ($rowProgramIds as $rowProgramId) {
                                    if ($rowProgramId !== $rowMainProgramId) {
                                        $rowOtherNames[] = $programNameById[$rowProgramId];
                                    }
                                }
                                $rowAllNames = [];
                                foreach ($rowProgramIds as $rowProgramId) {
                                    $rowAllNames[] = $programNameById[$rowProgramId];
                                }
                            ?>
                            <td class="pp-program-cell">
                                <?php if ($rowMainProgramId > 0 && isset($programNameById[$rowMainProgramId])): ?>
                                    <span class="pp-cell-name"><?= htmlspecialchars($programNameById[$rowMainProgramId]) ?></span><?php if (!empty($rowOtherNames)): ?> <span class="pp-cell-pill" title="<?= htmlspecialchars(implode(', ', $rowAllNames)) ?>">+<?= count($rowOtherNames) ?><span class="pp-sr"> more: <?= htmlspecialchars(implode(', ', $rowOtherNames)) ?></span></span><?php endif; ?>
                                <?php elseif (!empty($rowProgramIds)): ?>
                                    <span class="pp-cell-name pp-cell-multi" title="<?= htmlspecialchars(implode(', ', $rowAllNames)) ?>"><?= count($rowProgramIds) === count($programNameById) ? 'All programs' : (count($rowProgramIds) . (count($rowProgramIds) === 1 ? ' program' : ' programs')) ?></span>
                                <?php else: ?>
                                    <span class="pp-cell-none">-</span>
                                <?php endif; ?>
                            </td>
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
                                    href="<?= htmlspecialchars($manageBooksUrl(['edit' => $bookId])) ?>"
                                    aria-label="Update book">
                                    <?= admin_icon('square-pen') ?>
                                </a>
                                <form method="POST" class="row-action-form delete-book-form" data-book-title="<?= htmlspecialchars((string)($row['title'] ?? 'Book')) ?>">
                                    <input type="hidden" name="delete_book" value="1">
                                    <input type="hidden" name="book_id" value="<?= $bookId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button
                                        type="submit"
                                        class="manage-book-icon-btn delete"
                                        aria-label="Delete book">
                                        <?= admin_icon('trash-2') ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr class="book-copy-details-row" id="copy-details-<?= $bookId ?>" hidden>
                            <td colspan="10">
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
                                                        } elseif ($copyStatusRaw === 'missing' || $copyStatusRaw === 'lost') {
                                                            // Lost copies share the amber "missing" pill instead of plain grey.
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

                                                        // No active loan: one note across Borrow Status + Borrower
                                                        // instead of two "-" cells (Copy Status already says it).
                                                        $hasActiveLoan = $loanStatusRaw !== '' || $borrowerText !== '-';

                                                        // Row state for the status edge bar + pill icon:
                                                        // overdue > on loan > lost/missing > available.
                                                        if ($loanStatusRaw === 'overdue') {
                                                            $copyRowState = 'overdue';
                                                        } elseif ($hasActiveLoan) {
                                                            $copyRowState = 'loan';
                                                        } elseif ($copyStatusRaw === 'lost' || $copyStatusRaw === 'missing') {
                                                            $copyRowState = 'lost';
                                                        } elseif ($copyStatusRaw === 'available') {
                                                            $copyRowState = 'available';
                                                        } else {
                                                            $copyRowState = 'other';
                                                        }
                                                        $copyStatusIcons = [
                                                            'available' => '<path d="m5 12.5 4.5 4.5L19 7.5"></path>',
                                                            'loan' => '<circle cx="12" cy="12" r="8.5"></circle><path d="M12 7.5V12l3 2"></path>',
                                                            'lost' => '<path d="M12 4 21 19H3L12 4Z"></path><path d="M12 10v4"></path>',
                                                        ];
                                                        $copyIconKey = $copyStatusRaw === 'available' ? 'available'
                                                            : (($copyStatusRaw === 'lost' || $copyStatusRaw === 'missing') ? 'lost'
                                                            : (($copyStatusRaw === 'borrowed' || $copyStatusRaw === 'overdue') ? 'loan' : ''));
                                                    ?>
                                                    <tr data-copy-state="<?= htmlspecialchars($copyRowState) ?>">
                                                        <td class="copy-detail-accession"><?= htmlspecialchars((string)($copy['accession_no'] ?? '-')) ?></td>
                                                        <td class="copy-detail-id"><?= intval($copy['copy_id'] ?? 0) ?></td>
                                                        <td><span class="<?= htmlspecialchars($copyStatusTextClass) ?>"><?php if ($copyIconKey !== ''): ?><svg class="copy-detail-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $copyStatusIcons[$copyIconKey] ?></svg><?php endif; ?><?= htmlspecialchars($copyStatusText) ?></span></td>
                                                        <?php if ($hasActiveLoan): ?>
                                                            <td><span class="<?= htmlspecialchars($borrowStatusClass) ?>"><?= htmlspecialchars($loanStatusText) ?></span></td>
                                                            <td><?= htmlspecialchars($borrowerText) ?></td>
                                                        <?php else: ?>
                                                            <td colspan="2" class="copy-detail-no-loan"><span class="copy-detail-note">Not currently borrowed</span></td>
                                                        <?php endif; ?>
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
                    <tr><td colspan="10">No books found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <?php
            // Page numbers: first, last and current +/- 2; a gap of one page
            // shows that page instead of an ellipsis.
            $pagerNumbers = [1, $totalPages];
            for ($pagerN = $booksPage - 2; $pagerN <= $booksPage + 2; $pagerN++) {
                if ($pagerN >= 1 && $pagerN <= $totalPages) {
                    $pagerNumbers[] = $pagerN;
                }
            }
            $pagerNumbers = array_values(array_unique($pagerNumbers));
            sort($pagerNumbers);
            $pagerItems = [];
            $previousNumber = 0;
            foreach ($pagerNumbers as $pagerN) {
                if ($previousNumber > 0 && $pagerN - $previousNumber === 2) {
                    $pagerItems[] = $previousNumber + 1;
                } elseif ($previousNumber > 0 && $pagerN - $previousNumber > 2) {
                    $pagerItems[] = 0;
                }
                $pagerItems[] = $pagerN;
                $previousNumber = $pagerN;
            }
            $pagerFirstShown = $pageOffset + 1;
            $pagerLastShown = min($filteredBooksTotal, $pageOffset + MANAGE_BOOKS_PER_PAGE);
        ?>
        <nav class="mb-pager" aria-label="Pagination">
            <p class="mb-pager-summary">Showing <strong><?= number_format($pagerFirstShown) ?>&ndash;<?= number_format($pagerLastShown) ?></strong> of <strong><?= number_format($filteredBooksTotal) ?></strong> books</p>
            <ul class="mb-pager-list">
                <li>
                    <?php if ($booksPage > 1): ?>
                        <a class="filter-reset-btn mb-pager-btn mb-pager-step" href="<?= htmlspecialchars($manageBooksUrl(['page' => $booksPage - 1])) ?>" rel="prev">&lsaquo; Previous</a>
                    <?php else: ?>
                        <span class="filter-reset-btn mb-pager-btn mb-pager-step is-disabled" aria-disabled="true">&lsaquo; Previous</span>
                    <?php endif; ?>
                </li>
                <?php foreach ($pagerItems as $pagerItem): ?>
                    <?php if ($pagerItem === 0): ?>
                        <li class="mb-pager-gap" aria-hidden="true">&hellip;</li>
                    <?php elseif ($pagerItem === $booksPage): ?>
                        <li><a class="btn-primary mb-pager-btn mb-pager-current" href="<?= htmlspecialchars($manageBooksUrl(['page' => $pagerItem])) ?>" aria-current="page" aria-label="Page <?= $pagerItem ?>, current page"><?= number_format($pagerItem) ?></a></li>
                    <?php else: ?>
                        <li><a class="filter-reset-btn mb-pager-btn" href="<?= htmlspecialchars($manageBooksUrl(['page' => $pagerItem])) ?>" aria-label="Page <?= $pagerItem ?>"><?= number_format($pagerItem) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li>
                    <?php if ($booksPage < $totalPages): ?>
                        <a class="filter-reset-btn mb-pager-btn mb-pager-step" href="<?= htmlspecialchars($manageBooksUrl(['page' => $booksPage + 1])) ?>" rel="next">Next &rsaquo;</a>
                    <?php else: ?>
                        <span class="filter-reset-btn mb-pager-btn mb-pager-step is-disabled" aria-disabled="true">Next &rsaquo;</span>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</section>

<?php
    // Add Book window ("Cover preview" layout, ab- classes in admin.css and
    // admin_palette_green.css; behaviour in manage_books_admin.js).
    $initialCopyCountRaw = trim((string)($addBookForm['copy_count'] ?? ''));
    $initialCopyCount = '';
    if ($initialCopyCountRaw !== '' && ctype_digit($initialCopyCountRaw)) {
        $tmpCount = (int)$initialCopyCountRaw;
        if ($tmpCount >= 1 && $tmpCount <= 200) {
            $initialCopyCount = (string)$tmpCount;
        }
    }
    // A fresh window starts at 1 copy (the stepper's minimum); a window
    // reopened after an error keeps what was sent (blank if it was invalid).
    if (!$openAddBookModal && $initialCopyCountRaw === '') {
        $initialCopyCount = '1';
    }
    $renderAccessionCount = $initialCopyCount !== '' ? (int)$initialCopyCount : 0;
    $initialAccessions = is_array($addBookForm['accession_numbers'] ?? null) ? $addBookForm['accession_numbers'] : [];
    $addBookError = ($openAddBookModal && ($flash['type'] ?? '') === 'error') ? (string)($flash['message'] ?? '') : '';
?>
<div class="overlay-modal ab-modal<?= $openAddBookModal ? ' show' : '' ?>" id="bookModal" aria-hidden="<?= $openAddBookModal ? 'false' : 'true' ?>">
    <div class="ab-card" role="dialog" aria-modal="true" aria-labelledby="abTitle" aria-describedby="abSubtitle">
        <div class="ab-head">
            <span class="ab-chip" aria-hidden="true"><?= admin_icon('books') ?></span>
            <div class="ab-head-text">
                <h2 class="ab-title" id="abTitle">Add Book</h2>
                <p class="ab-subtitle" id="abSubtitle">Add a title to the catalog</p>
            </div>
            <button type="button" class="ab-close" onclick="closeBookModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg>
            </button>
        </div>

        <form method="POST" class="ab-form" id="addBookForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="ab-body">
                <div class="ab-preview">
                    <div class="ab-cover">
                        <img id="abCoverImg" src="book_cover_preview.php?<?= htmlspecialchars(http_build_query(['title' => (string)($addBookForm['title'] ?? ''), 'author' => (string)($addBookForm['author'] ?? ''), 'category_id' => (int)($addBookForm['category_id'] ?? 0)])) ?>" alt="Generated cover preview" width="176" height="264">
                    </div>
                    <div class="ab-preview-text">
                        <span class="ab-pill">Live preview</span>
                        <p class="ab-caption">Looks up the real cover when you save. Until one is found, this generated cover is shown.</p>
                    </div>
                </div>

                <div class="ab-fields">
                    <?php if ($addBookError !== ''): ?>
                        <p class="ab-alert" role="alert"><?= htmlspecialchars($addBookError) ?></p>
                    <?php endif; ?>

                    <fieldset class="ab-group">
                        <legend class="ab-group-label">Book details</legend>
                        <div class="ab-grid">
                            <div class="ab-field ab-span-2">
                                <label class="ab-label" for="abTitleInput">Title<span class="ab-req" aria-hidden="true"></span></label>
                                <input class="ab-input" type="text" id="abTitleInput" name="title" placeholder="e.g. Principles of Accounting" required value="<?= htmlspecialchars((string)($addBookForm['title'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="abAuthorInput">Author</label>
                                <input class="ab-input" type="text" id="abAuthorInput" name="author" placeholder="Last name, First name" value="<?= htmlspecialchars((string)($addBookForm['author'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="abPublisherInput">Publisher</label>
                                <input class="ab-input" type="text" id="abPublisherInput" name="publisher" placeholder="Publisher" value="<?= htmlspecialchars((string)($addBookForm['publisher'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="abIsbnInput">ISBN</label>
                                <input class="ab-input" type="text" id="abIsbnInput" name="isbn" placeholder="978…" value="<?= htmlspecialchars((string)($addBookForm['isbn'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="abYearInput">Year</label>
                                <input class="ab-input" type="number" id="abYearInput" name="year_published" placeholder="e.g. 2019" min="1900" max="2100" value="<?= htmlspecialchars((string)($addBookForm['year_published'] ?? '')) ?>">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="ab-group">
                        <legend class="ab-group-label">Catalog</legend>
                        <div class="ab-grid">
                            <div class="ab-field">
                                <label class="ab-label" for="abCategorySelect">Category</label>
                                <?php /* The <button><selectedcontent> inside these three selects is the
                                   customizable-select button (Chrome/Edge 135+, styled in admin.css):
                                   it shows the chosen option and lets a long name end in "...".
                                   Other browsers drop it while parsing and show the plain select. */ ?>
                                <select class="ab-input ab-select" id="abCategorySelect" name="category_id">
                                    <button type="button" class="ab-select-button"><selectedcontent></selectedcontent></button>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categoryOptions as $categoryOption): ?>
                                        <?php $cid = (int)($categoryOption['category_id'] ?? 0); ?>
                                        <option value="<?= $cid ?>" <?= (int)($addBookForm['category_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= htmlspecialchars((string)($categoryOption['category_name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ab-field">
                                <span class="ab-label" id="abProgramLabel">Program</span>
                                <?= manage_books_render_program_picker([
                                    'id' => 'abPrograms',
                                    'context' => 'add',
                                    'label_id' => 'abProgramLabel',
                                    'programs' => $programOptions,
                                    'ids' => (array)($addBookForm['program_ids'] ?? []),
                                    'main' => (int)($addBookForm['program_id'] ?? 0),
                                ]) ?>
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="abSectionSelect">Section<span class="ab-req" aria-hidden="true"></span></label>
                                <select class="ab-input ab-select" id="abSectionSelect" name="location_section" required>
                                    <button type="button" class="ab-select-button"><selectedcontent></selectedcontent></button>
                                    <option value="">Select Section</option>
                                    <?php foreach (manage_books_section_options($librarySections, (string)($addBookForm['location_section'] ?? '')) as $sec => $secLabel): ?>
                                        <option value="<?= htmlspecialchars((string)$sec) ?>" <?= ((string)($addBookForm['location_section'] ?? '') === (string)$sec) ? 'selected' : '' ?>><?= htmlspecialchars($secLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="abCallNumberInput">Call number<span class="ab-req" aria-hidden="true"></span></label>
                                <input class="ab-input" type="text" id="abCallNumberInput" name="call_number" placeholder="e.g. HF5635 .B35 2019" required value="<?= htmlspecialchars((string)($addBookForm['call_number'] ?? '')) ?>">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="ab-group">
                        <legend class="ab-group-label">Copies</legend>
                        <div class="ab-copies">
                            <div class="ab-field ab-copies-count">
                                <label class="ab-label" for="copyCountInput">Copies<span class="ab-req" aria-hidden="true"></span></label>
                                <div class="ab-stepper">
                                    <button type="button" class="ab-step" data-step="-1" aria-label="One copy fewer" aria-controls="copyCountInput">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12"></path></svg>
                                    </button>
                                    <input
                                        class="ab-step-input"
                                        type="text"
                                        name="copy_count"
                                        id="copyCountInput"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        aria-describedby="abCopiesHint"
                                        required
                                        value="<?= htmlspecialchars($initialCopyCount) ?>">
                                    <button type="button" class="ab-step" data-step="1" aria-label="One copy more" aria-controls="copyCountInput">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6v12M6 12h12"></path></svg>
                                    </button>
                                </div>
                                <span class="ab-hint" id="abCopiesHint">1 to 200</span>
                            </div>
                            <div class="ab-field ab-accessions" role="group" aria-labelledby="abAccessionLabel">
                                <span class="ab-label" id="abAccessionLabel">Accession numbers<span class="ab-req" aria-hidden="true"></span><span class="ab-sr"> (required, one per copy)</span></span>
                                <div class="ab-accession-grid" id="accessionInputsWrap">
                                    <?php for ($i = 0; $i < $renderAccessionCount; $i++): ?>
                                        <?php $accValue = (string)($initialAccessions[$i] ?? ''); ?>
                                        <input class="ab-input" type="text" name="accession_numbers[]" placeholder="Accession #<?= $i + 1 ?>" aria-label="Accession number, copy <?= $i + 1 ?>" value="<?= htmlspecialchars($accValue) ?>">
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="ab-foot">
                <span class="ab-foot-note"><span class="ab-req" aria-hidden="true"></span>Required</span>
                <div class="ab-actions">
                    <button type="button" class="ab-btn ab-btn-ghost" onclick="closeBookModal()">Cancel</button>
                    <button type="submit" name="add_book" class="ab-btn ab-btn-primary">
                        <span class="ab-btn-icon" aria-hidden="true"><?= admin_icon('plus-circle') ?></span>
                        Add Book
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($editBook): ?>
<?php
    // Edit Book window: the Add Book look (ab- classes, styled for both
    // windows in admin.css and admin_palette_green.css) with the book's
    // current cover and its cover tools on the left.
    $editCoverFilename = trim((string)($editForm['cover'] ?? ''));
    $editBookIdForCover = (int)($editForm['book_id'] ?? 0);
    $editCoverIsExcluded = $editBookIdForCover > 0 && fbc_cover_rejection_reason($conn, $editBookIdForCover) !== null;
    $editCloseUrl = $manageBooksUrl([], 'book-row-' . (int)($editForm['book_id'] ?? 0));
    $editBookError = ($openEditBookModal && ($flash['type'] ?? '') === 'error') ? (string)($flash['message'] ?? '') : '';
    $editCopiesNowText = number_format($editCopyTotal) . ($editCopyTotal === 1 ? ' copy now' : ' copies now');
?>
<div class="overlay-modal ab-modal show" id="editBookModal" aria-hidden="false">
    <div class="ab-card" role="dialog" aria-modal="true" aria-labelledby="ebTitle" aria-describedby="ebSubtitle">
        <div class="ab-head">
            <span class="ab-chip" aria-hidden="true"><?= admin_icon('square-pen') ?></span>
            <div class="ab-head-text">
                <h2 class="ab-title" id="ebTitle">Edit Book</h2>
                <p class="ab-subtitle" id="ebSubtitle">Update this title in the catalog</p>
            </div>
            <a class="ab-close" href="<?= htmlspecialchars($editCloseUrl) ?>" aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg>
            </a>
        </div>

        <!-- Cover actions are their own POST forms; HTML can't nest forms, so
             their visible controls live in the left column below and attach
             back to these through the form="" attribute. -->
        <form method="POST" id="editCoverUploadForm" enctype="multipart/form-data" hidden>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="book_id" value="<?= $editBookIdForCover ?>">
        </form>
        <form method="POST" id="editCoverRefetchForm" hidden onsubmit="return confirm('Look for a new cover for this book? This will replace the current cover if a match is found.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="book_id" value="<?= $editBookIdForCover ?>">
        </form>
        <?php if ($editCoverFilename !== ''): ?>
            <form method="POST" id="editCoverClearForm" hidden onsubmit="return confirm(&quot;Clear this book's cover as wrong? It will show its generated cover, and the nightly job won't fetch this one again.&quot;);">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="book_id" value="<?= $editBookIdForCover ?>">
            </form>
        <?php endif; ?>

        <form method="POST" class="ab-form" id="editBookForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="book_id" value="<?= intval($editForm['book_id'] ?? 0) ?>">
            <input type="hidden" id="editCurrentCopyCount" value="<?= intval($editCopyTotal) ?>">

            <div class="ab-body">
                <div class="ab-preview eb-preview">
                    <div class="ab-cover">
                        <?php if ($editCoverFilename !== ''): ?>
                            <img src="../assets/covers/<?= htmlspecialchars($editCoverFilename) ?>?v=<?= time() ?>" alt="Current cover for this book" width="176" height="264">
                        <?php else: ?>
                            <?php // Generated default cover (config/book_cover.php), the same one the kiosk shows for this book. ?>
                            <img src="../book_cover.php?id=<?= intval($editForm['book_id'] ?? 0) ?>&amp;v=<?= BOOK_COVER_VERSION ?>" alt="Generated cover (no cover image yet)" width="176" height="264">
                        <?php endif; ?>
                    </div>
                    <div class="ab-preview-text">
                        <span class="ab-pill">Current cover</span>
                        <p class="ab-caption">Shown on the kiosk. Upload your own or look for a new one.</p>
                    </div>

                    <div class="eb-cover-tools">
                        <?php if (($coverFlash['message'] ?? '') !== ''): ?>
                            <p class="book-cover-flash book-cover-flash-<?= ($coverFlash['status'] ?? '') === 'ok' ? 'success' : 'error' ?>" role="status">
                                <?= htmlspecialchars((string)$coverFlash['message']) ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($editCoverIsExcluded): ?>
                            <p class="book-cover-caution">Heads up: automated lookups have returned the wrong image for this book before. Check any re-fetched result carefully, or upload the correct cover yourself.</p>
                        <?php endif; ?>

                        <label class="book-cover-drop" id="editCoverDrop">
                            <input type="file" name="cover_image" form="editCoverUploadForm" class="book-cover-file" id="editCoverFileInput" accept="image/jpeg,image/png" required aria-label="Cover image file">
                            <span class="book-cover-drop-icon" aria-hidden="true"><?= admin_icon('image') ?></span>
                            <span class="book-cover-drop-text">
                                <strong id="editCoverFileName">Choose an image</strong>
                                <small id="editCoverFileHint">JPG/PNG &middot; or drop here</small>
                            </span>
                        </label>
                        <div class="eb-cover-buttons">
                            <button type="submit" name="upload_cover" form="editCoverUploadForm" class="filter-reset-btn book-cover-btn book-cover-btn-upload" aria-label="Upload cover" title="Upload cover"><span class="book-cover-btn-icon" aria-hidden="true"><?= admin_icon('upload') ?></span>Upload</button>
                            <button type="submit" name="refetch_cover" form="editCoverRefetchForm" class="filter-reset-btn book-cover-btn book-cover-btn-refetch" aria-label="Re-fetch cover" title="Re-fetch cover"><span class="book-cover-btn-icon" aria-hidden="true"><?= admin_icon('refresh') ?></span>Re-fetch</button>
                        </div>
                        <?php if ($editCoverFilename !== ''): ?>
                            <button type="submit" name="clear_cover" form="editCoverClearForm" class="filter-reset-btn book-cover-btn book-cover-btn-danger"><span class="book-cover-btn-icon" aria-hidden="true"><?= admin_icon('trash') ?></span>Clear cover</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ab-fields">
                    <?php if ($editBookError !== ''): ?>
                        <p class="ab-alert" role="alert"><?= htmlspecialchars($editBookError) ?></p>
                    <?php endif; ?>

                    <fieldset class="ab-group">
                        <legend class="ab-group-label">Book details</legend>
                        <div class="ab-grid">
                            <div class="ab-field ab-span-2">
                                <label class="ab-label" for="ebTitleInput">Title<span class="ab-req" aria-hidden="true"></span></label>
                                <input class="ab-input" type="text" id="ebTitleInput" name="title" placeholder="e.g. Principles of Accounting" required value="<?= htmlspecialchars((string)($editForm['title'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="ebAuthorInput">Author</label>
                                <input class="ab-input" type="text" id="ebAuthorInput" name="author" placeholder="Last name, First name" value="<?= htmlspecialchars((string)($editForm['author'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="ebPublisherInput">Publisher</label>
                                <input class="ab-input" type="text" id="ebPublisherInput" name="publisher" placeholder="Publisher" value="<?= htmlspecialchars((string)($editForm['publisher'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="ebIsbnInput">ISBN</label>
                                <input class="ab-input" type="text" id="ebIsbnInput" name="isbn" placeholder="978…" value="<?= htmlspecialchars((string)($editForm['isbn'] ?? '')) ?>">
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="ebYearInput">Year</label>
                                <input class="ab-input" type="number" id="ebYearInput" name="year_published" placeholder="e.g. 2019" min="1900" max="2100" value="<?= htmlspecialchars((string)($editForm['year_published'] ?? '')) ?>">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="ab-group">
                        <legend class="ab-group-label">Catalog</legend>
                        <div class="ab-grid">
                            <div class="ab-field">
                                <label class="ab-label" for="ebCategorySelect">Category</label>
                                <?php /* Same customizable-select button as Add Book (see there). */ ?>
                                <select class="ab-input ab-select" id="ebCategorySelect" name="category_id">
                                    <button type="button" class="ab-select-button"><selectedcontent></selectedcontent></button>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categoryOptions as $categoryOption): ?>
                                        <?php $cid = (int)($categoryOption['category_id'] ?? 0); ?>
                                        <option value="<?= $cid ?>" <?= (int)($editForm['category_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= htmlspecialchars((string)($categoryOption['category_name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ab-field">
                                <span class="ab-label" id="ebProgramLabel">Program</span>
                                <?= manage_books_render_program_picker([
                                    'id' => 'ebPrograms',
                                    'context' => 'edit',
                                    'label_id' => 'ebProgramLabel',
                                    'programs' => $programOptions,
                                    'ids' => (array)($editForm['program_ids'] ?? []),
                                    'main' => (int)($editForm['program_id'] ?? 0),
                                ]) ?>
                            </div>
                            <div class="ab-field">
                                <?php /* Section only: the class letter comes from the call number when
                                   the Section changes; shelf, row and position are not edited here. */ ?>
                                <label class="ab-label" for="ebSectionSelect">Section<span class="ab-req" aria-hidden="true"></span></label>
                                <select class="ab-input ab-select" id="ebSectionSelect" name="location_section" required>
                                    <button type="button" class="ab-select-button"><selectedcontent></selectedcontent></button>
                                    <option value="">Select Section</option>
                                    <?php foreach (manage_books_section_options($librarySections, (string)($editForm['location_section'] ?? '')) as $sec => $secLabel): ?>
                                        <option value="<?= htmlspecialchars((string)$sec) ?>" <?= ((string)($editForm['location_section'] ?? '') === (string)$sec) ? 'selected' : '' ?>><?= htmlspecialchars($secLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ab-field">
                                <label class="ab-label" for="ebCallNumberInput">Call number<span class="ab-req" aria-hidden="true"></span></label>
                                <input class="ab-input" type="text" id="ebCallNumberInput" name="call_number" placeholder="e.g. HF5635 .B35 2019" required value="<?= htmlspecialchars((string)($editForm['call_number'] ?? '')) ?>">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="ab-group">
                        <legend class="ab-group-label">Copies</legend>
                        <div class="ab-copies eb-copies">
                            <div class="ab-field ab-copies-count">
                                <label class="ab-label" for="editCopyCountInput">Copies<span class="ab-req" aria-hidden="true"></span></label>
                                <div class="ab-stepper">
                                    <button type="button" class="ab-step" data-step="-1" aria-label="One copy fewer" aria-controls="editCopyCountInput">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12"></path></svg>
                                    </button>
                                    <input
                                        class="ab-step-input"
                                        type="text"
                                        name="copy_count"
                                        id="editCopyCountInput"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        aria-describedby="ebCopiesHint editCopyAdjustmentHint"
                                        required
                                        value="<?= htmlspecialchars((string)($editForm['copy_count'] ?? '')) ?>">
                                    <button type="button" class="ab-step" data-step="1" aria-label="One copy more" aria-controls="editCopyCountInput">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6v12M6 12h12"></path></svg>
                                    </button>
                                </div>
                                <span class="ab-hint" id="ebCopiesHint"><?= htmlspecialchars($editCopiesNowText) ?></span>
                            </div>
                            <div class="ab-field eb-copies-side">
                                <span class="ab-label" id="ebCurrentCopiesLabel">Current copies</span>
                                <div class="eb-copy-list" role="group" aria-labelledby="ebCurrentCopiesLabel">
                                    <?php if (!empty($editCopyRows)): ?>
                                        <?php foreach ($editCopyRows as $copyRow): ?>
                                            <?php
                                                $copyId = (int)($copyRow['copy_id'] ?? 0);
                                                $statusRaw = strtolower((string)($copyRow['effective_status'] ?? 'unknown'));
                                                $statusLabel = (string)($copyRow['status_label'] ?? ucfirst(str_replace('_', ' ', $statusRaw)));
                                                $isLocked = (bool)($copyRow['is_locked'] ?? false);
                                                $accessionInput = (string)($copyRow['input_accession'] ?? '');
                                            ?>
                                            <div class="eb-copy-row<?= $isLocked ? ' is-locked' : '' ?>">
                                                <span class="eb-copy-id">Copy #<?= $copyId ?></span>
                                                <input
                                                    class="eb-copy-acc"
                                                    type="text"
                                                    name="edit_copy_accessions[<?= $copyId ?>]"
                                                    placeholder="Accession #"
                                                    aria-label="Accession number, copy #<?= $copyId ?><?= $isLocked ? ' (locked: ' . htmlspecialchars(strtolower($statusLabel)) . ')' : '' ?>"
                                                    value="<?= htmlspecialchars($accessionInput) ?>"
                                                    <?= $isLocked ? 'readonly' : '' ?>>
                                                <span class="eb-copy-status status-<?= htmlspecialchars($statusRaw) ?>"<?= $isLocked ? ' title="Locked while ' . htmlspecialchars(strtolower($statusLabel)) . ': it can\'t be edited or removed"' : '' ?>>
                                                    <?php if ($isLocked): ?><svg class="eb-copy-lock" viewBox="0 0 24 24" aria-hidden="true"><rect x="5.5" y="10.5" width="13" height="9" rx="2"></rect><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"></path></svg><?php endif; ?><?= htmlspecialchars($statusLabel) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="eb-copy-empty">No copy records found for this book.</p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($editLockedCount > 0): ?>
                                    <p class="ab-hint eb-copy-note"><?= intval($editLockedCount) ?> <?= $editLockedCount === 1 ? 'copy is' : 'copies are' ?> borrowed, overdue or missing: locked, and never removed when you lower the count.</p>
                                <?php endif; ?>
                                <p class="ab-hint eb-copy-note" id="editCopyAdjustmentHint" aria-live="polite"></p>
                                <div class="eb-new-copies" id="editNewCopiesBlock"<?= $editAdditionalCopyCount > 0 ? '' : ' hidden' ?>>
                                    <span class="ab-label" id="ebNewAccessionLabel">New accession numbers<span class="ab-req" aria-hidden="true"></span><span class="ab-sr"> (required, one per new copy)</span></span>
                                    <div class="ab-accession-grid" id="editNewAccessionInputsWrap" role="group" aria-labelledby="ebNewAccessionLabel">
                                        <?php for ($i = 0; $i < $editAdditionalCopyCount; $i++): ?>
                                            <?php $newAccessionValue = (string)($editNewAccessionsInput[$i] ?? ''); ?>
                                            <input class="ab-input" type="text" name="new_accession_numbers[]" placeholder="New accession #<?= $i + 1 ?>" aria-label="Accession number, new copy <?= $i + 1 ?>" value="<?= htmlspecialchars($newAccessionValue) ?>">
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="ab-foot">
                <span class="ab-foot-note"><span class="ab-req" aria-hidden="true"></span>Required</span>
                <div class="ab-actions">
                    <a class="ab-btn ab-btn-ghost" href="<?= htmlspecialchars($editCloseUrl) ?>">Cancel</a>
                    <button type="submit" name="update_book" class="ab-btn ab-btn-primary">
                        <span class="ab-btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"></path></svg></span>
                        Save changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="overlay-modal" id="deleteBookConfirmModal" aria-hidden="true">
    <div class="overlay-card glass-card confirm-delete-card confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="deleteBookConfirmTitle" aria-describedby="deleteBookConfirmText">
        <button type="button" class="icon-close confirm-delete-cancel confirm-modal-close" aria-label="Cancel">&times;</button>
        <div class="confirm-delete-header confirm-modal-header">
            <span class="confirm-delete-icon confirm-modal-icon is-danger" aria-hidden="true">!</span>
            <div>
                <h2 id="deleteBookConfirmTitle">Delete Book</h2>
                <p id="deleteBookConfirmText">Delete this book from Manage Books?</p>
            </div>
        </div>
        <p class="confirm-delete-note confirm-modal-note">This cannot be undone from this page. You can still review activity in Archives.</p>
        <div class="confirm-delete-actions confirm-modal-actions">
            <button type="button" class="confirm-delete-confirm confirm-modal-confirm is-danger">Delete</button>
        </div>
    </div>
</div>

<script src="../assets/javascript/manage_books_admin.js?v=<?= @filemtime(__DIR__ . '/../assets/javascript/manage_books_admin.js') ?: time() ?>"></script>

<?php require 'layout_bottom.php'; ?>
