<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function fail_recommendation_verify(string $message): void {
    throw new RuntimeException($message);
}

function ok_recommendation_verify(string $message): void {
    echo "OK: {$message}" . PHP_EOL;
}

function fetch_recommendation_payload(string $baseUrl): array {
    $url = rtrim($baseUrl, '/') . '/recommend_books.php';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $statusLine = (string)($headers[0] ?? '');

    if ($body === false || strpos($statusLine, '200') === false) {
        fail_recommendation_verify('Recommendation endpoint did not return HTTP 200. Status: ' . $statusLine);
    }

    $payload = json_decode((string)$body, true);
    if (!is_array($payload)) {
        fail_recommendation_verify('Recommendation endpoint returned invalid JSON.');
    }

    return $payload;
}

function available_copy_count(mysqli $conn, int $bookId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM book_copies WHERE book_id = ? AND status = 'available'");
    if (!$stmt) {
        fail_recommendation_verify('Failed to prepare availability query: ' . $conn->error);
    }

    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int)$count;
}

$baseUrl = $argv[1] ?? 'http://localhost/SmartLib';

try {
    $payload = fetch_recommendation_payload($baseUrl);

    if (($payload['status'] ?? '') !== 'success') {
        fail_recommendation_verify('Recommendation payload status is not success.');
    }

    $panels = $payload['panels'] ?? null;
    if (!is_array($panels) || count($panels) === 0) {
        fail_recommendation_verify('Recommendation payload has no panels.');
    }

    $checkedBooks = 0;
    foreach ($panels as $panelIndex => $panel) {
        if (!is_array($panel)) {
            fail_recommendation_verify("Panel {$panelIndex} is not an object.");
        }

        $panelKey = trim((string)($panel['key'] ?? 'panel_' . $panelIndex));
        $books = $panel['books'] ?? null;
        if (!is_array($books) || count($books) === 0) {
            fail_recommendation_verify("Panel {$panelKey} has no books.");
        }

        $seenBookIds = [];
        foreach ($books as $bookIndex => $book) {
            if (!is_array($book)) {
                fail_recommendation_verify("Panel {$panelKey} book {$bookIndex} is not an object.");
            }

            $bookId = (int)($book['book_id'] ?? 0);
            $title = trim((string)($book['title'] ?? ''));
            $reason = trim((string)($book['reason'] ?? ''));
            $availabilityLabel = trim((string)($book['availability_label'] ?? ''));
            $availableCopies = (int)($book['available_copies'] ?? 0);

            if ($bookId <= 0) {
                fail_recommendation_verify("Panel {$panelKey} book {$bookIndex} has no valid book_id.");
            }

            if (isset($seenBookIds[$bookId])) {
                fail_recommendation_verify("Panel {$panelKey} repeats book_id {$bookId}.");
            }
            $seenBookIds[$bookId] = true;

            if ($title === '') {
                fail_recommendation_verify("Panel {$panelKey} book_id {$bookId} has no title.");
            }

            if ($reason === '') {
                fail_recommendation_verify("Panel {$panelKey} book_id {$bookId} has no recommendation reason.");
            }

            if ($availabilityLabel === '') {
                fail_recommendation_verify("Panel {$panelKey} book_id {$bookId} has no availability label.");
            }

            if ($availableCopies <= 0) {
                fail_recommendation_verify("Panel {$panelKey} book_id {$bookId} reports no available copies.");
            }

            $databaseAvailableCopies = available_copy_count($conn, $bookId);
            if ($databaseAvailableCopies <= 0) {
                fail_recommendation_verify("Panel {$panelKey} book_id {$bookId} has no available copies in the database.");
            }

            $checkedBooks++;
        }
    }

    if ($checkedBooks === 0) {
        fail_recommendation_verify('No recommendation books were checked.');
    }

    ok_recommendation_verify("checked {$checkedBooks} recommended books across " . count($panels) . ' panels');
    ok_recommendation_verify('every recommended book is available and has an explanation');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
