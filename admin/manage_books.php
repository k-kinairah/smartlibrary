<?php require 'layout_top.php'; ?>
<?php
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

$categories = $conn->query('SELECT category_id, category_name FROM categories ORDER BY category_name ASC');
$programs = $conn->query('SELECT program_id, program_name FROM programs ORDER BY program_name ASC');

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

$flash = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book'])) {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $year = trim($_POST['year_published'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);

    $locationSection = trim($_POST['location_section'] ?? '');
    $locationClass = trim($_POST['location_class'] ?? '');
    $locationShelf = trim($_POST['location_shelf'] ?? '');
    $locationRow = trim($_POST['location_row'] ?? '');
    $locationPosition = trim($_POST['location_position'] ?? '');
    $callNumber = trim($_POST['call_number'] ?? '');

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

    if ($title !== '') {
        if ($hasLocationTable && $hasBookLocationId) {
            if ($hasBookCallNumber) {
                $stmt = $conn->prepare(
                    "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location_id, call_number, created_at)
                     VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), NOW())"
                );
                if ($stmt) {
                    $stmt->bind_param('sssssiiis', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $locationId, $callNumber);
                }
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location_id, created_at)
                     VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NOW())"
                );
                if ($stmt) {
                    $stmt->bind_param('sssssiii', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $locationId);
                }
            }
        } elseif ($hasLegacyLocation) {
            $stmt = $conn->prepare(
                "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location, created_at)
                 VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('sssssiis', $isbn, $title, $author, $publisher, $year, $categoryId, $programId, $legacyLocationText);
            }
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, created_at)
                 VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('sssssii', $isbn, $title, $author, $publisher, $year, $categoryId, $programId);
            }
        }

        if (isset($stmt) && $stmt) {
            $stmt->execute();
            $stmt->close();
        }

        header('Location: manage_books.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_location'])) {
    $bookId = (int)($_POST['book_id'] ?? 0);

    $locationSection = trim($_POST['location_section'] ?? '');
    $locationClass = trim($_POST['location_class'] ?? '');
    $locationShelf = trim($_POST['location_shelf'] ?? '');
    $locationRow = trim($_POST['location_row'] ?? '');
    $locationPosition = trim($_POST['location_position'] ?? '');
    $callNumber = trim($_POST['call_number'] ?? '');

    if ($bookId > 0) {
        $legacyLocationText = build_legacy_location_text($locationSection, $locationClass, $locationShelf, $locationRow, $locationPosition, $callNumber);

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

            if ($hasBookCallNumber) {
                $upd = $conn->prepare(
                    "UPDATE books
                     SET location_id = NULLIF(?, 0),
                         call_number = NULLIF(?, '')
                     WHERE book_id = ?"
                );
                if ($upd) {
                    $upd->bind_param('isi', $locationId, $callNumber, $bookId);
                    $upd->execute();
                    $upd->close();
                }
            } else {
                $upd = $conn->prepare(
                    "UPDATE books
                     SET location_id = NULLIF(?, 0)
                     WHERE book_id = ?"
                );
                if ($upd) {
                    $upd->bind_param('ii', $locationId, $bookId);
                    $upd->execute();
                    $upd->close();
                }
            }
        } elseif ($hasLegacyLocation) {
            $upd = $conn->prepare("UPDATE books SET location = ? WHERE book_id = ?");
            if ($upd) {
                $upd->bind_param('si', $legacyLocationText, $bookId);
                $upd->execute();
                $upd->close();
            }
        }

        header('Location: manage_books.php?updated=1');
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM books WHERE book_id = $id");
    header('Location: manage_books.php');
    exit;
}

$editBook = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $locationJoin = ($hasLocationTable && $hasBookLocationId)
        ? ' LEFT JOIN library_locations loc ON b.location_id = loc.location_id '
        : '';

    $locationSelect = ($hasLocationTable && $hasBookLocationId)
        ? ", loc.section AS loc_section, loc.class_code AS loc_class_code, loc.shelf_code AS loc_shelf_code, loc.row_label AS loc_row_label, loc.position_label AS loc_position_label"
        : '';

    $callSelect = $hasBookCallNumber ? ', b.call_number AS db_call_number' : '';

    $editRes = $conn->query(
        "SELECT b.book_id, b.title, b.location{$locationSelect}{$callSelect}
         FROM books b
         {$locationJoin}
         WHERE b.book_id = {$editId}
         LIMIT 1"
    );

    if ($editRes) {
        $editBook = $editRes->fetch_assoc() ?: null;
    }
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
        COALESCE(COUNT(bc.copy_id), 0) AS total_copies
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN programs p ON b.program_id = p.program_id
    LEFT JOIN book_copies bc ON bc.book_id = b.book_id
    LEFT JOIN library_locations loc ON loc.location_id = b.location_id
    GROUP BY b.book_id
    ORDER BY b.created_at DESC
    "
);

$updatedFlag = isset($_GET['updated']) && (string)$_GET['updated'] === '1';

$editDefaults = [
    'location_section' => 'General Collection',
    'location_class' => 'T',
    'location_shelf' => 'YA-05',
    'location_row' => 'First row',
    'location_position' => 'Eye Level',
    'call_number' => ''
];

if ($editBook) {
    $editDefaults['location_section'] = (string)($editBook['loc_section'] ?? $editDefaults['location_section']);
    $editDefaults['location_class'] = (string)($editBook['loc_class_code'] ?? $editDefaults['location_class']);
    $editDefaults['location_shelf'] = (string)($editBook['loc_shelf_code'] ?? $editDefaults['location_shelf']);
    $editDefaults['location_row'] = (string)($editBook['loc_row_label'] ?? $editDefaults['location_row']);
    $editDefaults['location_position'] = (string)($editBook['loc_position_label'] ?? $editDefaults['location_position']);
    $editDefaults['call_number'] = (string)($editBook['db_call_number'] ?? '');
}
?>

<div class="page-top">
    <h1>Manage Books</h1>
    <button class="btn-primary" onclick="openBookModal()">+ Add Book</button>
</div>

<?php if ($updatedFlag): ?>
    <section class="panel glass-card borrow-flash borrow-flash-success">
        Book location updated successfully.
    </section>
<?php endif; ?>

<section class="panel glass-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Year</th>
                    <th>Genre</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($books && $books->num_rows > 0): ?>
                    <?php while ($row = $books->fetch_assoc()): ?>
                        <?php
                            $available = (int)($row['available_copies'] ?? 0);
                            $total = (int)($row['total_copies'] ?? 0);
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
                            <td><?= htmlspecialchars($row['title'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['author'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['year_published'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['genre_name'] ?? 'Uncategorized') ?></td>
                            <td>
                                <span class="badge status-<?= htmlspecialchars($statusClass) ?>">
                                    <?= htmlspecialchars($statusText) ?>
                                </span>
                            </td>
                            <td class="book-actions-cell">
                                <a class="btn-status activate" href="?edit=<?= intval($row['book_id']) ?>">Update</a>
                                <a class="btn-danger" href="?delete=<?= intval($row['book_id']) ?>" onclick="return confirm('Delete this book?')">Delete</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6">No books found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="overlay-modal" id="bookModal" aria-hidden="true">
    <div class="overlay-card glass-card">
        <div class="panel-head">
            <h2>Add Book</h2>
            <button class="icon-close" onclick="closeBookModal()">&times;</button>
        </div>

        <form method="POST" class="form-grid book-form-grid">
            <input type="text" name="isbn" placeholder="ISBN">
            <input type="text" name="title" placeholder="Title" required>
            <input type="text" name="author" placeholder="Author">
            <input type="text" name="publisher" placeholder="Publisher">
            <input type="number" name="year_published" placeholder="Year" min="1900" max="2100">

            <select name="category_id">
                <option value="">Select Category</option>
                <?php if ($categories): ?>
                    <?php while ($c = $categories->fetch_assoc()): ?>
                        <option value="<?= intval($c['category_id']) ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>

            <select name="program_id">
                <option value="">Select Program</option>
                <?php if ($programs): ?>
                    <?php while ($p = $programs->fetch_assoc()): ?>
                        <option value="<?= intval($p['program_id']) ?>"><?= htmlspecialchars($p['program_name']) ?></option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>

            <select name="location_section" required>
                <option value="">Select Section</option>
                <?php foreach ($librarySections as $sec): ?>
                    <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_class" required>
                <option value="">Select Class Letter</option>
                <?php foreach ($libraryClassLabels as $classCode => $classLabel): ?>
                    <option value="<?= htmlspecialchars($classCode) ?>"><?= htmlspecialchars($classLabel) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_shelf" required>
                <option value="">Select Shelf Code</option>
                <?php foreach ($shelfOptions as $shelf): ?>
                    <option value="<?= htmlspecialchars($shelf) ?>"><?= htmlspecialchars($shelf) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_row" required>
                <option value="">Select Row</option>
                <?php foreach ($rowOptions as $rowOpt): ?>
                    <option value="<?= htmlspecialchars($rowOpt) ?>"><?= htmlspecialchars($rowOpt) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_position" required>
                <option value="">Select Position</option>
                <?php foreach ($positionOptions as $pos): ?>
                    <option value="<?= htmlspecialchars($pos) ?>"><?= htmlspecialchars($pos) ?></option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="call_number" placeholder="Call Number (optional)">

            <button type="submit" name="add_book" class="btn-primary form-submit">Add Book</button>
        </form>
    </div>
</div>

<?php if ($editBook): ?>
<div class="overlay-modal show" id="locationUpdateModal" aria-hidden="false">
    <div class="overlay-card glass-card">
        <div class="panel-head">
            <h2>Update Book Location</h2>
            <a class="icon-close" href="manage_books.php" aria-label="Close">&times;</a>
        </div>

        <p style="margin-top:0;color:#bdd2c4;"><?= htmlspecialchars((string)($editBook['title'] ?? 'Book')) ?></p>

        <form method="POST" class="form-grid book-form-grid">
            <input type="hidden" name="book_id" value="<?= intval($editBook['book_id'] ?? 0) ?>">

            <select name="location_section" required>
                <option value="">Select Section</option>
                <?php foreach ($librarySections as $sec): ?>
                    <option value="<?= htmlspecialchars($sec) ?>" <?= $editDefaults['location_section'] === $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_class" required>
                <option value="">Select Class Letter</option>
                <?php foreach ($libraryClassLabels as $classCode => $classLabel): ?>
                    <option value="<?= htmlspecialchars($classCode) ?>" <?= $editDefaults['location_class'] === $classCode ? 'selected' : '' ?>><?= htmlspecialchars($classLabel) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_shelf" required>
                <option value="">Select Shelf Code</option>
                <?php foreach ($shelfOptions as $shelf): ?>
                    <option value="<?= htmlspecialchars($shelf) ?>" <?= $editDefaults['location_shelf'] === $shelf ? 'selected' : '' ?>><?= htmlspecialchars($shelf) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_row" required>
                <option value="">Select Row</option>
                <?php foreach ($rowOptions as $rowOpt): ?>
                    <option value="<?= htmlspecialchars($rowOpt) ?>" <?= $editDefaults['location_row'] === $rowOpt ? 'selected' : '' ?>><?= htmlspecialchars($rowOpt) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="location_position" required>
                <option value="">Select Position</option>
                <?php foreach ($positionOptions as $pos): ?>
                    <option value="<?= htmlspecialchars($pos) ?>" <?= $editDefaults['location_position'] === $pos ? 'selected' : '' ?>><?= htmlspecialchars($pos) ?></option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="call_number" placeholder="Call Number (optional)" value="<?= htmlspecialchars($editDefaults['call_number']) ?>">

            <button type="submit" name="update_location" class="btn-primary form-submit">Update Location</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openBookModal() {
    document.getElementById('bookModal').classList.add('show');
}

function closeBookModal() {
    document.getElementById('bookModal').classList.remove('show');
}

window.addEventListener('click', function (e) {
    if (e.target.id === 'bookModal') {
        closeBookModal();
    }
});
</script>

<?php require 'layout_bottom.php'; ?>
