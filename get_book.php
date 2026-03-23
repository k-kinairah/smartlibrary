<?php
require 'config/db_connect.php';
session_start();

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pick(array $source, array $keys, $default = '') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
            return $source[$key];
        }
    }
    return $default;
}

function table_exists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $dbRes = $conn->query('SELECT DATABASE() AS db');
    $db = $dbRes ? (string)($dbRes->fetch_assoc()['db'] ?? '') : '';
    if ($db === '') {
        return false;
    }

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
    if ($db === '') {
        return false;
    }

    $db = $conn->real_escape_string($db);
    $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = '$db' AND table_name = '$table' AND column_name = '$column' LIMIT 1";
    $res = $conn->query($sql);

    return $res && $res->num_rows > 0;
}
function cover_path($coverName) {
    $name = basename((string)$coverName);
    if ($name === '') {
        return 'assets/covers/default.jpg';
    }

    $full = __DIR__ . '/assets/covers/' . $name;
    if (is_file($full)) {
        return 'assets/covers/' . $name;
    }

    return 'assets/covers/default.jpg';
}

function split_tags($genre, $course = '') {
    $tags = [];

    foreach (preg_split('/[,|\/]+/', (string)$genre) as $chunk) {
        $item = trim($chunk);
        if ($item !== '') {
            $tags[] = $item;
        }
    }

    if (empty($tags) && trim((string)$course) !== '') {
        $tags[] = trim((string)$course);
    }

    if (empty($tags)) {
        $tags = ['Featured', 'Library', 'Student Pick'];
    }

    return array_slice(array_values(array_unique($tags)), 0, 3);
}

function class_name_from_code($code) {
    $map = [
        'A' => 'General Works',
        'B' => 'Philosophy / Psychology / Religion',
        'C' => 'Auxiliary Sciences of History',
        'D' => 'World History',
        'E/F' => 'History of the Americas',
        'G' => 'Geography / Anthropology / Recreation',
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

    $code = strtoupper(trim((string)$code));
    return $map[$code] ?? 'General Collection';
}

function guess_class_code($genre, $course = '') {
    $source = strtolower(trim((string)$genre . ' ' . (string)$course));

    if (str_contains($source, 'nursing') || str_contains($source, 'medicine') || str_contains($source, 'anatomy') || str_contains($source, 'pharma')) return 'R';
    if (str_contains($source, 'psychology') || str_contains($source, 'behavior')) return 'B';
    if (str_contains($source, 'computer') || str_contains($source, 'technology') || str_contains($source, 'algorithm') || str_contains($source, 'programming')) return 'T';
    if (str_contains($source, 'business') || str_contains($source, 'economics')) return 'H';
    if (str_contains($source, 'fiction') || str_contains($source, 'literature') || str_contains($source, 'classic') || str_contains($source, 'fantasy') || str_contains($source, 'adventure')) return 'P';
    if (str_contains($source, 'science') || str_contains($source, 'mathematics')) return 'Q';
    if (str_contains($source, 'education')) return 'L';

    return 'A';
}

function parse_location_meta($rawLocation, $genre = '', $course = '') {
    $guessedClass = guess_class_code($genre, $course);

    $meta = [
        'section' => 'General Collection',
        'class_code' => $guessedClass,
        'class_name' => class_name_from_code($guessedClass),
        'shelf' => 'YA-05',
        'row' => 'First row',
        'position' => 'Eye Level',
        'call_number' => ''
    ];

    $raw = trim((string)$rawLocation);
    if ($raw === '') {
        return $meta;
    }

    foreach (explode('|', $raw) as $partRaw) {
        $part = trim((string)$partRaw);
        if ($part === '') continue;

        if (stripos($part, 'Section:') === 0) {
            $meta['section'] = trim(substr($part, strlen('Section:')));
            continue;
        }

        if (stripos($part, 'Class:') === 0) {
            $classPart = trim(substr($part, strlen('Class:')));
            if (preg_match('/\b([A-Z](?:\/[A-Z])?)\b/i', $classPart, $m)) {
                $meta['class_code'] = strtoupper($m[1]);
            }
            continue;
        }

        if (stripos($part, 'Shelf:') === 0) {
            $meta['shelf'] = trim(substr($part, strlen('Shelf:')));
            continue;
        }

        if (stripos($part, 'Row:') === 0) {
            $meta['row'] = trim(substr($part, strlen('Row:')));
            continue;
        }

        if (stripos($part, 'Position:') === 0) {
            $meta['position'] = trim(substr($part, strlen('Position:')));
            continue;
        }

        if (stripos($part, 'Call:') === 0) {
            $meta['call_number'] = trim(substr($part, strlen('Call:')));
            continue;
        }
    }

    if (preg_match('/\b([A-Z]{2,3}-\d{2})\b/', $raw, $m)) {
        $meta['shelf'] = $m[1];
    } elseif (preg_match('/Shelf\s+([A-Z0-9-]+)/i', $raw, $m)) {
        $meta['shelf'] = strtoupper(trim($m[1]));
    }

    if (preg_match('/\b(FIRST|SECOND|THIRD|FOURTH|FIFTH)\s+ROW\b/i', $raw, $m)) {
        $meta['row'] = ucfirst(strtolower($m[1])) . ' row';
    }

    if (preg_match('/\b(EYE\s+LEVEL|UPPER\s+SHELF|MIDDLE\s+SHELF|LOWER\s+SHELF|BOTTOM\s+SHELF)\b/i', $raw, $m)) {
        $meta['position'] = ucwords(strtolower($m[1]));
    }

    if ($meta['section'] === 'General Collection') {
        if (stripos($raw, 'reference') !== false) $meta['section'] = 'Reference Collection';
        if (stripos($raw, 'filipiniana') !== false) $meta['section'] = 'Filipiniana Collection';
        if (stripos($raw, 'periodical') !== false) $meta['section'] = 'Periodicals Collection';
    }

    if ($meta['call_number'] === '' && preg_match('/\b([A-Z]{1,3}\s?\d{1,4}(?:\.\d+)?\s?[A-Z]?\d{0,4}(?:\s?\d{4})?)\b/', $raw, $m)) {
        $meta['call_number'] = trim($m[1]);
    }

    $meta['class_name'] = class_name_from_code($meta['class_code']);
    return $meta;
}

function availability_text(array $book) {
    $status = strtolower((string)pick($book, ['status'], 'available'));

    $availableRaw = pick($book, ['available_copies', 'copies_available', 'available', 'stock_available'], '');
    $totalRaw = pick($book, ['total_copies', 'copies_total', 'quantity', 'stock_total'], '');

    $available = is_numeric($availableRaw) ? (int)$availableRaw : null;
    $total = is_numeric($totalRaw) ? (int)$totalRaw : null;

    if ($total !== null && $available !== null) {
        return max(0, $available) . ' of ' . max(0, $total) . ' available';
    }

    if ($total !== null) {
        if ($status === 'borrowed') {
            return '0 of ' . max(0, $total) . ' available';
        }
        return max(0, $total) . ' of ' . max(0, $total) . ' available';
    }

    if ($status === 'borrowed') {
        return '0 of 1 available';
    }

    return '1 of 1 available';
}

function fallback_book_by_cover($coverName) {
    $cover = basename((string)$coverName);

    $fallbacks = [
        'adventure1.jpg' => [
            'title' => 'Journey Across The Sea',
            'author' => 'Elena Parker',
            'genre' => 'Adventure, Fiction, Young Adult',
            'year_published' => '2021',
            'isbn' => '978-621-555-014-3',
            'description' => 'A character-driven adventure story about self-discovery, friendship, and courage while navigating open waters and uncertain choices.',
            'rating' => '4.8',
            'location' => 'Shelf A2 - Adventure',
            'available_copies' => 4,
            'total_copies' => 6,
            'status' => 'available',
            'cover' => 'adventure1.jpg'
        ],
        'adventure2.jpg' => [
            'title' => 'The Hobbit',
            'author' => 'J.R.R. Tolkien',
            'genre' => 'Fantasy, Adventure, Fiction',
            'year_published' => '1937',
            'isbn' => '978-0-547-92822-7',
            'description' => 'A fantasy novel about Bilbo Baggins leaving the comfort of home to join a high-stakes quest filled with unlikely allies and dangerous turns.',
            'rating' => '4.9',
            'location' => 'Shelf B1 - Classics',
            'available_copies' => 4,
            'total_copies' => 6,
            'status' => 'available',
            'cover' => 'adventure2.jpg'
        ],
        'databasedesign.jpg' => [
            'title' => 'Beginning Database Design',
            'author' => 'Clare Churcher',
            'genre' => 'Computer Science, Education, Reference',
            'course' => 'BS Computer Science',
            'year_published' => '2020',
            'isbn' => '978-1-4302-7828-0',
            'description' => 'A practical guide to data modeling, relational design, and common database patterns for students and beginner developers.',
            'rating' => '4.7',
            'location' => 'Shelf C4 - CS',
            'available_copies' => 2,
            'total_copies' => 5,
            'status' => 'available',
            'cover' => 'databasedesign.jpg'
        ],
        'tech2.jpg' => [
            'title' => 'Introduction to Algorithms',
            'author' => 'Thomas H. Cormen',
            'genre' => 'Computer Science, Textbook, Reference',
            'course' => 'BS Computer Science',
            'year_published' => '2022',
            'isbn' => '978-0-262-04630-5',
            'description' => 'A foundational textbook that covers algorithm analysis, data structures, and design techniques used in modern computing.',
            'rating' => '4.8',
            'location' => 'Shelf C1 - Algorithms',
            'available_copies' => 3,
            'total_copies' => 5,
            'status' => 'available',
            'cover' => 'tech2.jpg'
        ],
        'atkinson.png' => [
            'title' => 'Introduction to Psychology',
            'author' => 'Susan Nolen-Hoeksema',
            'genre' => 'Education, Psychology, Reference',
            'course' => 'BS Psychology',
            'year_published' => '2019',
            'isbn' => '978-1-337-56394-3',
            'description' => 'An introductory psychology text exploring behavior, cognition, emotion, and contemporary research with student-friendly examples.',
            'rating' => '4.6',
            'location' => 'Shelf D2 - Psychology',
            'available_copies' => 1,
            'total_copies' => 4,
            'status' => 'available',
            'cover' => 'atkinson.png'
        ],
        'anatomy.jpg' => [
            'title' => 'Human Anatomy Essentials',
            'author' => 'Sarah L. Greene',
            'genre' => 'Science, Textbook, Education',
            'course' => 'BS Nursing',
            'year_published' => '2023',
            'isbn' => '978-981-098-120-4',
            'description' => 'A visual anatomy reference designed for health-science learners, with clear diagrams and concise clinical context.',
            'rating' => '4.7',
            'location' => 'Shelf N1 - Nursing',
            'available_copies' => 2,
            'total_copies' => 3,
            'status' => 'available',
            'cover' => 'anatomy.jpg'
        ],
        'drug2023.png' => [
            'title' => 'Drug Handbook 2023',
            'author' => 'M. Reyes',
            'genre' => 'Reference, Science, Education',
            'course' => 'BS Nursing',
            'year_published' => '2023',
            'isbn' => '978-621-889-302-5',
            'description' => 'A quick-reference handbook of common medications, indications, and nursing reminders for clinical preparation.',
            'rating' => '4.5',
            'location' => 'Shelf N3 - Pharmacology',
            'available_copies' => 1,
            'total_copies' => 2,
            'status' => 'borrowed',
            'cover' => 'drug2023.png'
        ]
    ];

    return $fallbacks[$cover] ?? null;
}

function icon_svg($name) {
    $icons = [
        'author' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.4"></circle><path d="M5 19c0-3.2 2.8-5.2 7-5.2s7 2 7 5.2"></path></svg>',
        'published' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="15" rx="2"></rect><path d="M8 3.8v3.4M16 3.8v3.4M3.5 9.2h17"></path></svg>',
        'isbn' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5.6h12.5a2 2 0 0 1 2 2V18"></path><path d="M5 18.4h12.5a2 2 0 0 0 2-2V6.8"></path><path d="M8.2 8.2v7.5M11 8.2v7.5"></path></svg>',
        'availability' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.5"></circle><path d="M12 8.3v4.2l2.7 1.6"></path></svg>',
        'rating' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5l2.3 4.7 5.2.8-3.7 3.7.9 5.2-4.7-2.5-4.7 2.5.9-5.2-3.7-3.7 5.2-.8z"></path></svg>',
        'pin' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.2s6-6 6-10a6 6 0 1 0-12 0c0 4 6 10 6 10z"></path><circle cx="12" cy="10.2" r="2.2"></circle></svg>'
    ];

    return $icons[$name] ?? '';
}

$id = (int)($_GET['id'] ?? 0);
$coverParam = trim((string)($_GET['cover'] ?? ''));
$requestedCover = $coverParam !== '' ? basename(urldecode($coverParam)) : '';
$book = null;

$hasLocationTable = table_exists($conn, 'library_locations');
$hasBookLocationId = column_exists($conn, 'books', 'location_id');
$hasBookCallNumber = column_exists($conn, 'books', 'call_number');

$locationSelect = '';
$locationJoin = '';

if ($hasLocationTable && $hasBookLocationId) {
    $locationSelect = ",
        loc.section AS loc_section,
        loc.class_code AS loc_class_code,
        loc.class_name AS loc_class_name,
        loc.shelf_code AS loc_shelf_code,
        loc.row_label AS loc_row_label,
        loc.position_label AS loc_position_label";

    $locationJoin = ' LEFT JOIN library_locations loc ON b.location_id = loc.location_id';
}

if ($hasBookCallNumber) {
    $locationSelect .= ', b.call_number AS db_call_number';
}

$baseSql = "
    SELECT
        b.*{$locationSelect},
        (
            SELECT COUNT(*)
            FROM book_copies bc_total
            WHERE bc_total.book_id = b.book_id
        ) AS total_copies,
        (
            SELECT COUNT(*)
            FROM book_copies bc_avail
            WHERE bc_avail.book_id = b.book_id
              AND bc_avail.status = 'available'
        ) AS available_copies
    FROM books b{$locationJoin}
";

if ($id > 0) {
    $stmt = $conn->prepare($baseSql . ' WHERE b.book_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $book = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
} elseif ($requestedCover !== '') {
    $stmt = $conn->prepare($baseSql . ' WHERE b.cover = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $requestedCover);
        $stmt->execute();
        $res = $stmt->get_result();
        $book = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

if (!$book && $requestedCover !== '') {
    $book = fallback_book_by_cover($requestedCover);
}

if (!$book) {
    $book = [
        'title' => 'Book Preview',
        'author' => 'Library Collection',
        'genre' => 'Featured, Library, Student Pick',
        'year_published' => date('Y'),
        'isbn' => '978-000-000-000-0',
        'description' => 'Book details will appear here once records are added to your database. You can still use this layout as your final kiosk modal design.',
        'rating' => '4.8',
        'location' => 'Main Library - Front Desk',
        'available_copies' => 1,
        'total_copies' => 1,
        'status' => 'available'
    ];

    if ($requestedCover !== '') {
        $book['cover'] = $requestedCover;
    }
}

$title = (string)pick($book, ['title'], 'Untitled Book');
$author = (string)pick($book, ['author'], 'Unknown Author');
$genre = (string)pick($book, ['genre'], 'Featured, Library');
$course = (string)pick($book, ['course', 'program', 'program_name'], 'Computer Science');
$year = (string)pick($book, ['year_published', 'published_year'], 'N/A');
$isbn = (string)pick($book, ['isbn'], 'N/A');
$description = (string)pick($book, ['description', 'summary', 'synopsis'], 'No description available yet.');
$location = (string)pick($book, ['location'], '');
$coverName = (string)pick($book, ['cover'], $requestedCover);
$cover = cover_path($coverName);

$ratingRaw = (string)pick($book, ['rating'], '4.8');
$ratingDisplay = is_numeric($ratingRaw)
    ? number_format((float)$ratingRaw, 1) . ' / 5.0'
    : h($ratingRaw);

$tags = split_tags($genre, $course);
$availability = availability_text($book);
$statusValue = strtolower((string)pick($book, ['status'], 'available'));
$availableCopiesRaw = pick($book, ['available_copies'], null);
$totalCopiesRaw = pick($book, ['total_copies'], null);
if (is_numeric($totalCopiesRaw)) {
    if ((int)$totalCopiesRaw <= 0) {
        $statusValue = 'unavailable';
    } else {
        $statusValue = ((int)$availableCopiesRaw > 0) ? 'available' : 'borrowed';
    }
}
$unavailableStates = ['borrowed', 'unavailable', 'checked_out', 'checked out'];
$isUnavailable = in_array($statusValue, $unavailableStates, true);
$checkoutLabel = $isUnavailable ? 'Unavailable' : 'Check Out';
$checkoutDisabled = $isUnavailable ? ' disabled' : '';
$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$canCheckout = isset($_SESSION['user_id']) && in_array($sessionRole, ['student', 'faculty', 'librarian', 'admin'], true);
$bookId = (int)pick($book, ['book_id'], 0);
$accession = (string)pick(
    $book,
    ['accession_number', 'accession'],
    'FIC/' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $author), 0, 3)) . '/' . ($year === 'N/A' ? date('Y') : $year)
);

$legacyLocationMeta = parse_location_meta($location, $genre, $course);
$hasStructuredLocation =
    trim((string)pick($book, ['loc_section'], '')) !== '' ||
    trim((string)pick($book, ['loc_class_code'], '')) !== '' ||
    trim((string)pick($book, ['loc_shelf_code'], '')) !== '';

if ($hasStructuredLocation) {
    $locationSection = (string)pick($book, ['loc_section'], (string)$legacyLocationMeta['section']);
    $rawClassCode = (string)pick($book, ['loc_class_code'], (string)$legacyLocationMeta['class_code']);
    $locationClassCode = strtoupper(trim($rawClassCode));
    if ($locationClassCode === '') {
        $locationClassCode = (string)$legacyLocationMeta['class_code'];
    }

    $locationClassName = (string)pick($book, ['loc_class_name'], class_name_from_code($locationClassCode));
    $locationShelf = (string)pick($book, ['loc_shelf_code'], (string)$legacyLocationMeta['shelf']);
    $locationRow = (string)pick($book, ['loc_row_label'], (string)$legacyLocationMeta['row']);
    $locationPosition = (string)pick($book, ['loc_position_label'], (string)$legacyLocationMeta['position']);
    $locationCall = (string)pick($book, ['db_call_number', 'call_number'], (string)$legacyLocationMeta['call_number']);
} else {
    $locationSection = (string)$legacyLocationMeta['section'];
    $locationClassCode = (string)$legacyLocationMeta['class_code'];
    $locationClassName = (string)$legacyLocationMeta['class_name'];
    $locationShelf = (string)$legacyLocationMeta['shelf'];
    $locationRow = (string)$legacyLocationMeta['row'];
    $locationPosition = (string)$legacyLocationMeta['position'];
    $locationCall = (string)pick($book, ['db_call_number', 'call_number'], (string)$legacyLocationMeta['call_number']);
}

if ($locationClassName === '') {
    $locationClassName = class_name_from_code($locationClassCode);
}

$locationDisplay = "{$locationSection} | Class {$locationClassCode} ({$locationClassName}) | Shelf {$locationShelf} | {$locationRow} | {$locationPosition}";
if ($locationCall !== '') {
    $locationDisplay .= " | Call {$locationCall}";
}

$tagsHtml = '';
foreach ($tags as $tag) {
    $tagsHtml .= "<span class='book-tag'>" . h($tag) . "</span>";
}

function info_item($icon, $label, $value) {
    return "
    <div class='book-info-item'>
        <span class='book-info-icon'>" . icon_svg($icon) . "</span>
        <div>
            <div class='book-info-label'>" . h($label) . "</div>
            <div class='book-info-value'>" . h($value) . "</div>
        </div>
    </div>";
}

echo "
<div class='book-modal-v2'
     data-book-id='" . intval($bookId) . "'
     data-title='" . h($title) . "'
     data-author='" . h($author) . "'
     data-isbn='" . h($isbn) . "'
     data-course='" . h($course) . "'
     data-year='" . h($year) . "'
     data-location='" . h($locationDisplay) . "'
     data-location-section='" . h($locationSection) . "'
     data-location-class-code='" . h($locationClassCode) . "'
     data-location-class-name='" . h($locationClassName) . "'
     data-location-shelf='" . h($locationShelf) . "'
     data-location-row='" . h($locationRow) . "'
     data-location-position='" . h($locationPosition) . "'
     data-location-call='" . h($locationCall) . "'
     data-status='" . h($statusValue) . "'
     data-accession='" . h($accession) . "'
     data-availability='" . h($availability) . "'>
    <div class='book-modal-header'>
        <h2 class='book-modal-title'>" . h($title) . "</h2>
        <p class='book-modal-author-sub'>" . h($author) . "</p>
    </div>

    <div class='book-modal-body'>
        <div class='book-modal-left'>
            <img class='book-cover-lg' src='" . h($cover) . "' alt='Book cover'>
            " . ($canCheckout ? "<button type='button' class='checkout-btn' data-book-id='" . intval($bookId) . "'" . $checkoutDisabled . ">" . h($checkoutLabel) . "</button>" : "") . "
            <button type='button' class='find-book-btn'>" . icon_svg('pin') . "<span>Find This Book</span></button>
        </div>

        <div class='book-modal-right'>
            <div class='book-tag-row'>" . $tagsHtml . "</div>

            <div class='book-info-grid'>
                " . info_item('author', 'Author', $author) . "
                " . info_item('published', 'Published', $year) . "
                " . info_item('isbn', 'ISBN', $isbn) . "
                " . info_item('availability', 'Availability', $availability) . "
                " . info_item('rating', 'Rating', $ratingDisplay) . "
            </div>

            <div class='book-description'>
                <h3>Description</h3>
                <p>" . h($description) . "</p>
            </div>
        </div>
    </div>
</div>
";
?>















