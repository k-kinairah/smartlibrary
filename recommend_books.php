<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeCol = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM {$safeTable} LIKE '{$safeCol}'");
    return $res && $res->num_rows > 0;
}

function cover_url(string $cover): string {
    $cover = trim($cover);
    if ($cover === '') {
        return 'assets/covers/default.jpg';
    }

    if (preg_match('/^(https?:)?\\/\\//i', $cover)) {
        return $cover;
    }

    if (str_starts_with($cover, 'assets/')) {
        return $cover;
    }

    return 'assets/covers/' . basename($cover);
}

function normalize_book(array $row, string $reason = ''): array {
    $bookId = (int)($row['book_id'] ?? 0);
    $title = trim((string)($row['title'] ?? 'Untitled'));
    $author = trim((string)($row['author'] ?? 'Unknown Author'));
    $cover = trim((string)($row['cover'] ?? ''));

    return [
        'book_id' => $bookId,
        'title' => $title !== '' ? $title : 'Untitled',
        'author' => $author !== '' ? $author : 'Unknown Author',
        'cover' => $cover,
        'cover_url' => cover_url($cover),
        'reason' => trim($reason)
    ];
}

function query_rows(mysqli $conn, string $sql): array {
    try {
        $res = $conn->query($sql);
    } catch (Throwable $e) {
        return [];
    }

    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function load_recommendation_event_weights(mysqli $conn, int $userId): array {
    $result = [
        'enabled' => false,
        'global' => [],
        'user' => [],
        'global_rows' => 0,
        'user_rows' => 0
    ];

    if (!table_exists($conn, 'recommendation_events')) {
        return $result;
    }

    $result['enabled'] = true;

    $eventWeightExpr = "CASE event_type
        WHEN 'impression' THEN 1
        WHEN 'open' THEN 4
        WHEN 'checkout' THEN 12
        ELSE 0
    END";

    $panelWeightExpr = "CASE
        WHEN panel_key = 'course_highlights' THEN 1.45
        WHEN panel_key = 'most_borrowed' THEN 1.2
        WHEN panel_key = 'new_arrivals' THEN 1.1
        ELSE 1
    END";

    $globalRows = query_rows(
        $conn,
        "SELECT
            book_id,
            SUM({$eventWeightExpr}) AS weighted_score,
            SUM({$eventWeightExpr} * {$panelWeightExpr}) AS panel_score,
            SUM(CASE WHEN panel_key = 'course_highlights' THEN {$eventWeightExpr} ELSE 0 END) AS panel_course,
            SUM(CASE WHEN panel_key = 'most_borrowed' THEN {$eventWeightExpr} ELSE 0 END) AS panel_popular,
            SUM(CASE WHEN panel_key = 'new_arrivals' THEN {$eventWeightExpr} ELSE 0 END) AS panel_new
         FROM recommendation_events
         WHERE book_id IS NOT NULL
           AND created_at >= DATE_SUB(NOW(), INTERVAL 120 DAY)
         GROUP BY book_id"
    );

    foreach ($globalRows as $row) {
        $bookId = (int)($row['book_id'] ?? 0);
        if ($bookId <= 0) {
            continue;
        }

        $result['global'][$bookId] = [
            'weighted' => (float)($row['weighted_score'] ?? 0),
            'panel_weighted' => (float)($row['panel_score'] ?? 0),
            'course' => (float)($row['panel_course'] ?? 0),
            'popular' => (float)($row['panel_popular'] ?? 0),
            'new' => (float)($row['panel_new'] ?? 0)
        ];
        $result['global_rows'] += 1;
    }

    if ($userId > 0) {
        $safeUserId = (int)$userId;
        $userRows = query_rows(
            $conn,
            "SELECT
                book_id,
                SUM({$eventWeightExpr}) AS weighted_score,
                SUM({$eventWeightExpr} * {$panelWeightExpr}) AS panel_score,
                SUM(CASE WHEN panel_key = 'course_highlights' THEN {$eventWeightExpr} ELSE 0 END) AS panel_course,
                SUM(CASE WHEN panel_key = 'most_borrowed' THEN {$eventWeightExpr} ELSE 0 END) AS panel_popular,
                SUM(CASE WHEN panel_key = 'new_arrivals' THEN {$eventWeightExpr} ELSE 0 END) AS panel_new
             FROM recommendation_events
             WHERE book_id IS NOT NULL
               AND user_id = {$safeUserId}
               AND created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
             GROUP BY book_id"
        );

        foreach ($userRows as $row) {
            $bookId = (int)($row['book_id'] ?? 0);
            if ($bookId <= 0) {
                continue;
            }

            $result['user'][$bookId] = [
                'weighted' => (float)($row['weighted_score'] ?? 0),
                'panel_weighted' => (float)($row['panel_score'] ?? 0),
                'course' => (float)($row['panel_course'] ?? 0),
                'popular' => (float)($row['panel_popular'] ?? 0),
                'new' => (float)($row['panel_new'] ?? 0)
            ];
            $result['user_rows'] += 1;
        }
    }

    return $result;
}

function rank_rows_with_event_weights(array $rows, array $eventBundle, string $panelKey, bool $isPersonalized): array {
    if (count($rows) === 0) {
        return $rows;
    }

    $panelMap = [
        'course_highlights' => 'course',
        'most_borrowed' => 'popular',
        'new_arrivals' => 'new'
    ];
    $panelField = $panelMap[$panelKey] ?? '';

    foreach ($rows as $idx => $row) {
        $bookId = (int)($row['book_id'] ?? 0);

        $borrowCount = (int)($row['borrow_count'] ?? 0);
        $topicScore = (int)($row['topic_score'] ?? 0);
        $programMatch = (int)($row['program_match'] ?? 0);
        $historyMatch = (int)($row['history_match'] ?? 0);

        $freshness = 0.0;
        $createdDayRaw = trim((string)($row['created_day'] ?? ''));
        if ($createdDayRaw !== '' && strtotime($createdDayRaw) !== false) {
            $ageDays = max(0, (time() - strtotime($createdDayRaw)) / 86400);
            $freshness = max(0.0, 18.0 - ($ageDays / 4.5));
        }

        $global = $eventBundle['global'][$bookId] ?? [];
        $user = $eventBundle['user'][$bookId] ?? [];

        $globalWeighted = (float)($global['weighted'] ?? 0);
        $globalPanelWeighted = (float)($global['panel_weighted'] ?? 0);
        $globalPanelSpecific = $panelField !== '' ? (float)($global[$panelField] ?? 0) : 0.0;

        $userWeighted = (float)($user['weighted'] ?? 0);
        $userPanelWeighted = (float)($user['panel_weighted'] ?? 0);
        $userPanelSpecific = $panelField !== '' ? (float)($user[$panelField] ?? 0) : 0.0;

        $score = 0.0;
        $score += $borrowCount * 2.4;
        $score += $topicScore * 2.1;
        $score += $programMatch * 22;
        $score += $historyMatch * 14;
        $score += $freshness;
        $score += $globalWeighted * 1.7;
        $score += $globalPanelWeighted * 1.05;
        $score += $globalPanelSpecific * 1.65;

        if ($isPersonalized) {
            $score += $userWeighted * 4.1;
            $score += $userPanelWeighted * 2.2;
            $score += $userPanelSpecific * 3.2;
        } else {
            $score += $userWeighted * 1.8;
            $score += $userPanelSpecific * 1.6;
        }

        $rows[$idx]['ai_score'] = $score;
    }

    usort($rows, function ($a, $b) {
        $scoreA = (float)($a['ai_score'] ?? 0);
        $scoreB = (float)($b['ai_score'] ?? 0);
        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }

        $borrowA = (int)($a['borrow_count'] ?? 0);
        $borrowB = (int)($b['borrow_count'] ?? 0);
        if ($borrowA !== $borrowB) {
            return $borrowB <=> $borrowA;
        }

        return (int)($b['book_id'] ?? 0) <=> (int)($a['book_id'] ?? 0);
    });

    return $rows;
}

function unique_take(array $rows, int $limit, array &$seenBookIds, string $reasonBuilder = ''): array {
    $picked = [];

    foreach ($rows as $row) {
        $bookId = (int)($row['book_id'] ?? 0);
        if ($bookId <= 0 || isset($seenBookIds[$bookId])) {
            continue;
        }

        $reason = $reasonBuilder;
        if ($reason === '' && isset($row['topic_score'])) {
            $topicScore = (int)$row['topic_score'];
            if ($topicScore > 0) {
                $reason = 'Trending in current kiosk searches';
            }
        }

        if ($reason === '' && isset($row['borrow_count'])) {
            $borrowCount = (int)$row['borrow_count'];
            if ($borrowCount > 0) {
                $reason = "Borrowed {$borrowCount} times";
            }
        }

        if ($reason === '' && isset($row['genre_label'])) {
            $genre = trim((string)$row['genre_label']);
            if ($genre !== '') {
                $reason = "Popular in {$genre}";
            }
        }

        $picked[] = normalize_book($row, $reason);
        $seenBookIds[$bookId] = true;

        if (count($picked) >= $limit) {
            break;
        }
    }

    return $picked;
}

function load_user_profile(mysqli $conn, int $userId): array {
    $empty = [
        'user_id' => 0,
        'role' => 'guest',
        'program_id' => null,
        'program_name' => ''
    ];

    if ($userId <= 0) {
        return $empty;
    }

    $stmt = $conn->prepare(
        "SELECT u.user_id, u.role, u.program_id, COALESCE(p.program_name, '') AS program_name
         FROM library_users u
         LEFT JOIN programs p ON p.program_id = u.program_id
         WHERE u.user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return $empty;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $empty;
    }

    return [
        'user_id' => (int)($row['user_id'] ?? 0),
        'role' => strtolower(trim((string)($row['role'] ?? 'guest'))),
        'program_id' => isset($row['program_id']) ? (int)$row['program_id'] : null,
        'program_name' => trim((string)($row['program_name'] ?? ''))
    ];
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$profile = load_user_profile($conn, $userId);
$isPersonalized = in_array($profile['role'], ['student', 'faculty'], true) && (int)($profile['program_id'] ?? 0) > 0;
$programId = (int)($profile['program_id'] ?? 0);
$programName = (string)($profile['program_name'] ?? '');
$recEventBundle = load_recommendation_event_weights($conn, $userId);
$hasRecEvents = (bool)($recEventBundle['enabled'] ?? false);

$hasCategoryGroup = column_exists($conn, 'categories', 'category_group');
$genreExpr = $hasCategoryGroup
    ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
    : "TRIM(c.category_name)";

$popularRows = query_rows(
    $conn,
    "SELECT
        b.book_id,
        b.title,
        b.author,
        b.cover,
        COUNT(br.record_id) AS borrow_count,
        MAX(COALESCE(br.date_borrowed, DATE(br.created_at), DATE(b.created_at))) AS last_activity,
        DATE(b.created_at) AS created_day
     FROM books b
     LEFT JOIN book_copies bc ON bc.book_id = b.book_id
     LEFT JOIN borrow_records br ON br.copy_id = bc.copy_id
     GROUP BY b.book_id, b.title, b.author, b.cover, b.created_at
     ORDER BY borrow_count DESC, last_activity DESC, b.created_at DESC, b.book_id DESC
     LIMIT 60"
);

if (count($popularRows) === 0) {
    $popularRows = query_rows(
        $conn,
        "SELECT b.book_id, b.title, b.author, b.cover, 0 AS borrow_count, DATE(b.created_at) AS last_activity, DATE(b.created_at) AS created_day
         FROM books b
         ORDER BY b.created_at DESC, b.book_id DESC
         LIMIT 60"
    );
}

$newArrivalRows = query_rows(
    $conn,
    "SELECT
        b.book_id,
        b.title,
        b.author,
        b.cover,
        DATE(b.created_at) AS created_day,
        b.year_published
     FROM books b
     ORDER BY b.created_at DESC, b.year_published DESC, b.book_id DESC
     LIMIT 60"
);

$searchLogsEnabled = table_exists($conn, 'search_logs');
$searchTopTermsCount = 0;
$searchDrivenRows = [];

if ($searchLogsEnabled) {
    $termRows = query_rows(
        $conn,
        "SELECT LOWER(TRIM(search_term)) AS term, COUNT(*) AS hits
         FROM search_logs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)
           AND TRIM(search_term) <> ''
         GROUP BY LOWER(TRIM(search_term))
         ORDER BY hits DESC
         LIMIT 12"
    );

    $scoreParts = [];
    foreach ($termRows as $termRow) {
        $term = trim((string)($termRow['term'] ?? ''));
        $hits = (int)($termRow['hits'] ?? 0);

        if ($term === '' || $hits <= 0) {
            continue;
        }

        $safeTerm = $conn->real_escape_string($term);
        $searchTopTermsCount += 1;

        $scoreParts[] = "CASE
            WHEN b.title LIKE '%{$safeTerm}%'
              OR b.author LIKE '%{$safeTerm}%'
              OR b.isbn LIKE '%{$safeTerm}%'
              OR {$genreExpr} LIKE '%{$safeTerm}%'
            THEN {$hits}
            ELSE 0
        END";
    }

    if (!empty($scoreParts)) {
        $scoreExpr = implode(' + ', $scoreParts);

        $searchDrivenRows = query_rows(
            $conn,
            "SELECT
                b.book_id,
                b.title,
                b.author,
                b.cover,
                ({$scoreExpr}) AS topic_score,
                {$genreExpr} AS genre_label,
                DATE(b.created_at) AS created_day
             FROM books b
             LEFT JOIN categories c ON c.category_id = b.category_id
             HAVING topic_score > 0
             ORDER BY topic_score DESC, b.created_at DESC, b.book_id DESC
             LIMIT 80"
        );
    }
}

$courseRows = [];
if ($isPersonalized) {
    $userIdForSql = (int)$profile['user_id'];

    $courseSql = "SELECT
        b.book_id,
        b.title,
        b.author,
        b.cover,
        COALESCE(pop.borrow_count, 0) AS borrow_count,
        CASE WHEN b.program_id = {$programId} THEN 1 ELSE 0 END AS program_match,
        CASE WHEN b.category_id IN (
            SELECT DISTINCT b2.category_id
            FROM borrow_records br2
            INNER JOIN book_copies bc2 ON bc2.copy_id = br2.copy_id
            INNER JOIN books b2 ON b2.book_id = bc2.book_id
            WHERE br2.user_id = {$userIdForSql}
            ORDER BY br2.record_id DESC
            LIMIT 30
        ) THEN 1 ELSE 0 END AS history_match,
        {$genreExpr} AS genre_label,
        DATE(b.created_at) AS created_day
     FROM books b
     LEFT JOIN categories c ON c.category_id = b.category_id
     LEFT JOIN (
        SELECT bc.book_id, COUNT(br.record_id) AS borrow_count
        FROM book_copies bc
        LEFT JOIN borrow_records br ON br.copy_id = bc.copy_id
        GROUP BY bc.book_id
     ) pop ON pop.book_id = b.book_id
     WHERE b.program_id = {$programId}
        OR b.category_id IN (
            SELECT DISTINCT b2.category_id
            FROM borrow_records br2
            INNER JOIN book_copies bc2 ON bc2.copy_id = br2.copy_id
            INNER JOIN books b2 ON b2.book_id = bc2.book_id
            WHERE br2.user_id = {$userIdForSql}
            ORDER BY br2.record_id DESC
            LIMIT 30
        )
     ORDER BY program_match DESC, history_match DESC, borrow_count DESC, b.created_at DESC, b.book_id DESC
     LIMIT 80";

    $courseRows = query_rows($conn, $courseSql);

    if (count($courseRows) === 0) {
        $courseRows = query_rows(
            $conn,
            "SELECT b.book_id, b.title, b.author, b.cover, 0 AS borrow_count, 1 AS program_match, 0 AS history_match, '' AS genre_label, DATE(b.created_at) AS created_day
             FROM books b
             WHERE b.program_id = {$programId}
             ORDER BY b.created_at DESC, b.book_id DESC
             LIMIT 80"
        );
    }
} else {
    $courseRows = query_rows(
        $conn,
        "SELECT
            b.book_id,
            b.title,
            b.author,
            b.cover,
            COALESCE(pop.borrow_count, 0) AS borrow_count,
            {$genreExpr} AS genre_label,
            DATE(b.created_at) AS created_day
         FROM books b
         LEFT JOIN categories c ON c.category_id = b.category_id
         LEFT JOIN (
            SELECT bc.book_id, COUNT(br.record_id) AS borrow_count
            FROM book_copies bc
            LEFT JOIN borrow_records br ON br.copy_id = bc.copy_id
            GROUP BY bc.book_id
         ) pop ON pop.book_id = b.book_id
         ORDER BY borrow_count DESC, b.created_at DESC, b.book_id DESC
         LIMIT 80"
    );
}

$popularRows = rank_rows_with_event_weights($popularRows, $recEventBundle, 'most_borrowed', $isPersonalized);
$newArrivalRows = rank_rows_with_event_weights($newArrivalRows, $recEventBundle, 'new_arrivals', $isPersonalized);
$searchDrivenRows = rank_rows_with_event_weights($searchDrivenRows, $recEventBundle, 'course_highlights', $isPersonalized);
$courseRows = rank_rows_with_event_weights($courseRows, $recEventBundle, 'course_highlights', $isPersonalized);

$used = [];
$mostBorrowedBooks = unique_take($popularRows, 5, $used);
$newArrivalBooks = unique_take($newArrivalRows, 5, $used);

if ($isPersonalized) {
    $courseReason = $programName !== '' ? "Matches {$programName}" : 'Matches your course';
    $courseBooks = unique_take($courseRows, 5, $used, $courseReason);

    if (count($courseBooks) < 5 && count($searchDrivenRows) > 0) {
        $courseBooks = array_merge(
            $courseBooks,
            unique_take($searchDrivenRows, 5 - count($courseBooks), $used, 'Trending in current kiosk searches')
        );
    }

    if (count($courseBooks) < 5) {
        $courseBooks = array_merge(
            $courseBooks,
            unique_take($popularRows, 5 - count($courseBooks), $used, 'Campus favorite')
        );
    }

    $courseSubtitle = $programName !== ''
        ? "AI highlights for {$programName} students"
        : 'AI highlights based on your course and activity';
} else {
    $courseBooks = unique_take($searchDrivenRows, 5, $used, 'Trending in current kiosk searches');

    if (count($courseBooks) < 5) {
        $courseBooks = array_merge(
            $courseBooks,
            unique_take($courseRows, 5 - count($courseBooks), $used, 'AI trending pick')
        );
    }

    if (count($courseBooks) < 5) {
        $courseBooks = array_merge(
            $courseBooks,
            unique_take($popularRows, 5 - count($courseBooks), $used, 'Campus favorite')
        );
    }

    $courseSubtitle = count($searchDrivenRows) > 0
        ? 'AI highlights based on current kiosk searches'
        : 'AI highlights based on campus trends';
}

$panels = [
    [
        'key' => 'most_borrowed',
        'title' => 'Most Borrowed Books',
        'subtitle' => 'Popular titles frequently checked out by students',
        'books' => $mostBorrowedBooks
    ],
    [
        'key' => 'new_arrivals',
        'title' => 'New Arrivals',
        'subtitle' => 'Latest books and journals added to our collection',
        'books' => $newArrivalBooks
    ],
    [
        'key' => 'course_highlights',
        'title' => $isPersonalized ? 'Course-Specific Highlights' : 'Smart Highlights',
        'subtitle' => $courseSubtitle,
        'books' => $courseBooks
    ]
];

echo json_encode([
    'status' => 'success',
    'engine' => 'local_rules_v3_weighted',
    'personalized' => $isPersonalized,
    'user_context' => [
        'role' => $profile['role'],
        'program_id' => $programId > 0 ? $programId : null,
        'program_name' => $programName
    ],
    'diagnostics' => [
        'search_logs_enabled' => $searchLogsEnabled,
        'search_terms_used' => $searchTopTermsCount,
        'recommendation_events_enabled' => $hasRecEvents,
        'recommendation_event_books_global' => (int)($recEventBundle['global_rows'] ?? 0),
        'recommendation_event_books_user' => (int)($recEventBundle['user_rows'] ?? 0)
    ],
    'panels' => $panels
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
