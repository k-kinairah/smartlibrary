<?php
declare(strict_types=1);

// One-time (and re-runnable) AI semantic tagging for the book catalog.
//
// What this does: for every book that doesn't have an up-to-date AI
// "fingerprint" yet, it sends the book's title/author/category/program to
// an embedding API, gets back a list of numbers describing its meaning,
// and stores that in the book_embeddings table. recommend_books.php then
// compares those fingerprints with plain local math -- this script is the
// ONLY place that ever calls the external API.
//
// Safe to run again and again: books that are already tagged and unchanged
// are skipped automatically, so running this after adding a new batch of
// books (e.g. the rest of the ~10k-book catalog) only costs pennies for
// the new books, not the whole library again.
//
// Usage (from a terminal, in the SmartLib folder):
//   php tools/generate_book_embeddings.php
//   php tools/generate_book_embeddings.php --force   (re-tag everything)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';
require __DIR__ . '/../config/ai_config.php';

function embgen_log(string $message): void {
    echo $message . PHP_EOL;
}

function embgen_table_exists(mysqli $conn, string $table): bool {
    // 2026-10-10: hardcoded against a known-good table list instead of
    // querying information_schema. Live SHOW/information_schema lookups
    // crash this MariaDB build while it's running in InnoDB emergency
    // recovery mode (innodb_force_recovery=6) -- confirmed via repeated
    // mysqld crash dumps, including on this exact kind of check. Every
    // name below is confirmed present in smartlib_FULL_BACKUP_20261010.sql
    // (taken moments before the corruption). Revert to a live query once
    // the database has been rebuilt into a fresh data directory.
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

function embgen_column_exists(mysqli $conn, string $table, string $column): bool {
    // 2026-10-10: see embgen_table_exists() above -- same reason, same fix.
    static $knownColumns = [
        'categories' => ['category_group'],
        'books' => ['location_id', 'call_number', 'publisher', 'category_id', 'program_id'],
        'library_locations' => ['class_code'],
        'borrow_records' => ['renew_count', 'last_renewed_at'],
    ];
    return in_array($column, $knownColumns[$table] ?? [], true);
}

function embgen_build_text(array $book): string {
    $parts = [];

    $title = trim((string)($book['title'] ?? ''));
    $author = trim((string)($book['author'] ?? ''));
    $category = trim((string)($book['category_name'] ?? ''));
    $program = trim((string)($book['program_name'] ?? ''));
    $publisher = trim((string)($book['publisher'] ?? ''));
    $year = trim((string)($book['year_published'] ?? ''));

    if ($title !== '') {
        $parts[] = $title;
    }
    if ($author !== '') {
        $parts[] = 'by ' . $author;
    }
    if ($category !== '') {
        $parts[] = 'Category: ' . $category;
    }
    if ($program !== '') {
        $parts[] = 'Program: ' . $program;
    }
    if ($publisher !== '') {
        $parts[] = 'Publisher: ' . $publisher;
    }
    if ($year !== '') {
        $parts[] = 'Year: ' . $year;
    }

    return implode('. ', $parts);
}

/**
 * @param string[] $inputs
 * @return array<int, array<int, float>> vectors in the same order as $inputs
 */
function embgen_call_api(array $inputs): array {
    $payload = [
        'model' => AI_EMBEDDING_MODEL,
        'input' => $inputs,
        'dimensions' => AI_EMBEDDING_DIMENSIONS
    ];

    $ch = curl_init(AI_EMBEDDING_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . AI_EMBEDDING_API_KEY
        ],
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Connection error calling embeddings API: ' . $curlError);
    }

    $decoded = json_decode((string)$response, true);

    if ($httpCode !== 200 || !is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        $apiMessage = is_array($decoded) ? (string)($decoded['error']['message'] ?? '') : '';
        throw new RuntimeException('Embeddings API error (HTTP ' . $httpCode . '): ' . ($apiMessage !== '' ? $apiMessage : 'unexpected response'));
    }

    $vectors = [];
    foreach ($decoded['data'] as $item) {
        $index = (int)($item['index'] ?? 0);
        $vectors[$index] = array_map('floatval', $item['embedding'] ?? []);
    }
    ksort($vectors);

    return array_values($vectors);
}

// --- main ---

if (!defined('AI_EMBEDDING_API_KEY') || AI_EMBEDDING_API_KEY === '') {
    fwrite(STDERR, 'No API key set yet.' . PHP_EOL);
    fwrite(STDERR, 'Open config/ai_config.php and paste your OpenAI API key into AI_EMBEDDING_API_KEY, then run this again.' . PHP_EOL);
    exit(1);
}

if (!embgen_table_exists($conn, 'book_embeddings')) {
    fwrite(STDERR, "The book_embeddings table doesn't exist yet." . PHP_EOL);
    fwrite(STDERR, 'Run database/ai_semantic_setup.sql in phpMyAdmin first, then run this again.' . PHP_EOL);
    exit(1);
}

$force = in_array('--force', $argv, true);

$hasCategoryTable = embgen_table_exists($conn, 'categories');
$hasProgramTable = embgen_table_exists($conn, 'programs');
$hasPublisher = embgen_column_exists($conn, 'books', 'publisher');

$categoryJoin = $hasCategoryTable ? 'LEFT JOIN categories c ON c.category_id = b.category_id' : '';
$categorySelect = $hasCategoryTable ? ", TRIM(c.category_name) AS category_name" : ", '' AS category_name";
$programJoin = $hasProgramTable ? 'LEFT JOIN programs p ON p.program_id = b.program_id' : '';
$programSelect = $hasProgramTable ? ', p.program_name AS program_name' : ", '' AS program_name";
$publisherSelect = $hasPublisher ? ', b.publisher AS publisher' : ", '' AS publisher";

$sql = "SELECT b.book_id, b.title, b.author, b.year_published{$categorySelect}{$programSelect}{$publisherSelect}
        FROM books b
        {$categoryJoin}
        {$programJoin}
        ORDER BY b.book_id ASC";

$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, 'Failed to load books: ' . $conn->error . PHP_EOL);
    exit(1);
}

$existingHashes = [];
$hashRes = $conn->query('SELECT book_id, source_hash FROM book_embeddings');
if ($hashRes) {
    while ($row = $hashRes->fetch_assoc()) {
        $existingHashes[(int)$row['book_id']] = (string)$row['source_hash'];
    }
}

$pending = [];
while ($book = $res->fetch_assoc()) {
    $bookId = (int)$book['book_id'];
    $text = embgen_build_text($book);
    if ($text === '') {
        continue;
    }

    $hash = hash('sha256', AI_EMBEDDING_MODEL . '|' . AI_EMBEDDING_DIMENSIONS . '|' . $text);

    if (!$force && isset($existingHashes[$bookId]) && $existingHashes[$bookId] === $hash) {
        continue;
    }

    $pending[] = ['book_id' => $bookId, 'text' => $text, 'hash' => $hash];
}

$total = count($pending);
embgen_log("Books needing tagging: {$total}" . ($force ? ' (--force: re-tagging everything)' : ' (new or changed only)'));

if ($total === 0) {
    embgen_log('Nothing to do -- every book already has an up-to-date AI fingerprint.');
    exit(0);
}

$stmt = $conn->prepare(
    'INSERT INTO book_embeddings (book_id, model, dimensions, source_hash, embedding, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE model = VALUES(model), dimensions = VALUES(dimensions),
        source_hash = VALUES(source_hash), embedding = VALUES(embedding), updated_at = NOW()'
);

if (!$stmt) {
    fwrite(STDERR, 'Failed to prepare insert statement: ' . $conn->error . PHP_EOL);
    exit(1);
}

$batchSize = 100;
$done = 0;
$failed = 0;

for ($offset = 0; $offset < $total; $offset += $batchSize) {
    $batch = array_slice($pending, $offset, $batchSize);
    $inputs = array_column($batch, 'text');

    $vectors = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $vectors = embgen_call_api($inputs);
            break;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Batch at offset ' . $offset . ', attempt ' . $attempt . ' failed: ' . $e->getMessage() . PHP_EOL);
            if ($attempt < 2) {
                sleep(2);
            }
        }
    }

    if ($vectors === null) {
        fwrite(STDERR, 'Giving up on batch at offset ' . $offset . ' (' . count($batch) . ' books skipped this run).' . PHP_EOL);
        $failed += count($batch);
        continue;
    }

    foreach ($batch as $i => $item) {
        $vector = $vectors[$i] ?? [];
        if (count($vector) === 0) {
            $failed++;
            continue;
        }

        $bookId = (int)$item['book_id'];
        $model = AI_EMBEDDING_MODEL;
        $dims = count($vector);
        $hash = (string)$item['hash'];
        $json = json_encode($vector);

        $stmt->bind_param('isiss', $bookId, $model, $dims, $hash, $json);
        if ($stmt->execute()) {
            $done++;
        } else {
            $failed++;
        }
    }

    embgen_log('Progress: ' . min($offset + $batchSize, $total) . ' / ' . $total . ' processed (' . $done . ' tagged, ' . $failed . ' failed so far)');
}

$stmt->close();

embgen_log('Done. ' . $done . ' books tagged, ' . $failed . ' failed.');
exit($failed > 0 && $done === 0 ? 1 : 0);
?>
