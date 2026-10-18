<?php
declare(strict_types=1);

// Diagnostic: shows exactly why a book is (or isn't) linked to each
// category/program, AND why it might not be showing up in any
// recommendation panel at all -- so something that "should" appear can be
// traced back to its real cause (bad primary data entry, a bad AI
// auto-link, a missing AI fingerprint, or simply zero available copies --
// recommend_books.php silently skips any book with 0 available copies in
// every single panel, no exceptions) instead of guessed at.
//
// Usage:
//   php tools/explain_book_match.php "financial management"   (matches title OR author)
//   php tools/explain_book_match.php 123        (by book_id)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function explain_log(string $message): void {
    echo $message . PHP_EOL;
}

function explain_table_exists(mysqli $conn, string $table): bool {
    // 2026-10-10: hardcoded against a known-good table list instead of
    // querying information_schema -- see index.php for why. Every name
    // below is confirmed present in smartlib_FULL_BACKUP_20261010.sql.
    static $knownTables = [
        'auth_2fa_challenges', 'auth_login_attempts', 'auth_pin_reset_tokens',
        'books', 'book_categories', 'book_copies', 'book_embeddings',
        'book_programs', 'borrow_records', 'categories',
        'due_soon_reminder_logs', 'favorites', 'library_locations',
        'library_users', 'notifications', 'overdue_reminder_logs',
        'programs', 'recommendation_events', 'search_logs',
        'admin_activity_logs',
    ];
    return in_array($table, $knownTables, true);
}

$needle = trim((string)($argv[1] ?? ''));
if ($needle === '') {
    fwrite(STDERR, 'Usage: php tools/explain_book_match.php "<title text or book_id>"' . PHP_EOL);
    exit(1);
}

if (ctype_digit($needle)) {
    $stmt = $conn->prepare('SELECT book_id, title, author, category_id, program_id FROM books WHERE book_id = ? LIMIT 5');
    $stmt->bind_param('i', $needle);
} else {
    // Matches on title OR author -- if you only remember the author's name,
    // or the cover art doesn't match the title text stored in the system,
    // this still finds the book.
    $like = '%' . $needle . '%';
    $stmt = $conn->prepare('SELECT book_id, title, author, category_id, program_id FROM books WHERE title LIKE ? OR author LIKE ? LIMIT 5');
    $stmt->bind_param('ss', $like, $like);
}
$stmt->execute();
$res = $stmt->get_result();
$books = [];
while ($row = $res->fetch_assoc()) {
    $books[] = $row;
}
$stmt->close();

if (count($books) === 0) {
    explain_log('No books found matching "' . $needle . '".');
    exit(0);
}

$hasMultiCourseTables = explain_table_exists($conn, 'book_categories') && explain_table_exists($conn, 'book_programs');
$hasEmbeddings = explain_table_exists($conn, 'book_embeddings');

foreach ($books as $book) {
    $bookId = (int)$book['book_id'];
    explain_log('====================================================');
    explain_log("Book #{$bookId}: " . $book['title'] . '  (' . $book['author'] . ')');

    $catName = '';
    if ((int)$book['category_id'] > 0) {
        $r = $conn->query('SELECT category_name FROM categories WHERE category_id = ' . (int)$book['category_id']);
        $catName = $r ? (string)($r->fetch_assoc()['category_name'] ?? '') : '';
    }
    $progName = '';
    if ((int)$book['program_id'] > 0) {
        $r = $conn->query('SELECT program_name FROM programs WHERE program_id = ' . (int)$book['program_id']);
        $progName = $r ? (string)($r->fetch_assoc()['program_name'] ?? '') : '';
    }
    explain_log('Primary category (from the books table): ' . ($catName !== '' ? $catName : '(none)'));
    explain_log('Primary program (from the books table):  ' . ($progName !== '' ? $progName : '(none)'));

    if ($hasMultiCourseTables) {
        explain_log('--- All category links (book_categories) ---');
        $r = $conn->query(
            "SELECT bc.source, bc.confidence, c.category_name
             FROM book_categories bc
             LEFT JOIN categories c ON c.category_id = bc.category_id
             WHERE bc.book_id = {$bookId}
             ORDER BY bc.source, bc.confidence DESC"
        );
        if ($r && $r->num_rows > 0) {
            while ($row = $r->fetch_assoc()) {
                $conf = $row['confidence'] !== null ? number_format((float)$row['confidence'], 3) : 'n/a';
                explain_log("  [{$row['source']}] {$row['category_name']}  (similarity: {$conf})");
            }
        } else {
            explain_log('  (none)');
        }

        explain_log('--- All program links (book_programs) ---');
        $r = $conn->query(
            "SELECT bp.source, bp.confidence, p.program_name
             FROM book_programs bp
             LEFT JOIN programs p ON p.program_id = bp.program_id
             WHERE bp.book_id = {$bookId}
             ORDER BY bp.source, bp.confidence DESC"
        );
        if ($r && $r->num_rows > 0) {
            while ($row = $r->fetch_assoc()) {
                $conf = $row['confidence'] !== null ? number_format((float)$row['confidence'], 3) : 'n/a';
                explain_log("  [{$row['source']}] {$row['program_name']}  (similarity: {$conf})");
            }
        } else {
            explain_log('  (none)');
        }
    } else {
        explain_log('(book_categories / book_programs tables not set up yet)');
    }

    if ($hasEmbeddings) {
        $r = $conn->query('SELECT model, dimensions FROM book_embeddings WHERE book_id = ' . $bookId);
        $row = $r ? $r->fetch_assoc() : null;
        explain_log('AI fingerprint: ' . ($row ? "yes ({$row['model']}, {$row['dimensions']} dims)" : 'no'));
    }

    // This is the check that trips people up most: recommend_books.php
    // silently drops a book from EVERY panel (course highlights, most
    // borrowed, most searched, new arrivals -- all of it) the moment its
    // available copies hits 0. A book can have perfect category/program
    // data and a fingerprint and still never appear anywhere for this
    // reason alone.
    $r = $conn->query(
        "SELECT COUNT(bc.copy_id) AS total_copies,
                COALESCE(SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END), 0) AS available_copies
         FROM book_copies bc
         WHERE bc.book_id = {$bookId}"
    );
    $row = $r ? $r->fetch_assoc() : null;
    $totalCopies = (int)($row['total_copies'] ?? 0);
    $availableCopies = (int)($row['available_copies'] ?? 0);
    if ($totalCopies === 0) {
        explain_log('Copies: NONE registered in book_copies -- this book will never show in any recommendation panel until copies are added.');
    } elseif ($availableCopies === 0) {
        explain_log("Copies: {$totalCopies} total, 0 available (all checked out) -- this book is hidden from every recommendation panel until a copy is returned.");
    } else {
        explain_log("Copies: {$availableCopies} of {$totalCopies} available -- OK, not the reason this book might be missing.");
    }
}
explain_log('====================================================');
?>
