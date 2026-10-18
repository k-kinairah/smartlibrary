<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function table_exists(mysqli $conn, string $table): bool {
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

function column_exists(mysqli $conn, string $table, string $column): bool {
    // 2026-10-10: see table_exists() above -- same reason, same fix.
    static $knownColumns = [
        'categories' => ['category_group'],
        'books' => ['location_id', 'call_number', 'publisher', 'category_id', 'program_id'],
        'library_locations' => ['class_code'],
        'borrow_records' => ['renew_count', 'last_renewed_at'],
    ];
    return in_array($column, $knownColumns[$table] ?? [], true);
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

function load_book_availability(mysqli $conn): array {
    $rows = query_rows(
        $conn,
        "SELECT
            b.book_id,
            COUNT(bc.copy_id) AS total_copies,
            COALESCE(SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END), 0) AS available_copies
         FROM books b
         LEFT JOIN book_copies bc ON bc.book_id = b.book_id
         GROUP BY b.book_id"
    );

    $map = [];
    foreach ($rows as $row) {
        $bookId = (int)($row['book_id'] ?? 0);
        if ($bookId <= 0) {
            continue;
        }

        $map[$bookId] = [
            'total' => (int)($row['total_copies'] ?? 0),
            'available' => (int)($row['available_copies'] ?? 0)
        ];
    }

    return $map;
}

function recommendation_availability_label(int $available, int $total): string {
    if ($available <= 0) {
        return 'Currently unavailable';
    }

    if ($total <= 1) {
        return 'Available now';
    }

    return $available . ' of ' . $total . ' copies available';
}
function normalize_book(array $row, string $reason = ''): array {
    $bookId = (int)($row['book_id'] ?? 0);
    $title = trim((string)($row['title'] ?? 'Untitled'));
    $author = trim((string)($row['author'] ?? 'Unknown Author'));
    $cover = trim((string)($row['cover'] ?? ''));
    $availableCopies = (int)($row['available_copies'] ?? 0);
    $totalCopies = (int)($row['total_copies'] ?? 0);

    return [
        'book_id' => $bookId,
        'title' => $title !== '' ? $title : 'Untitled',
        'author' => $author !== '' ? $author : 'Unknown Author',
        'cover' => $cover,
        'cover_url' => cover_url($cover),
        'reason' => trim($reason),
        'available_copies' => $availableCopies,
        'total_copies' => $totalCopies,
        'availability_label' => recommendation_availability_label($availableCopies, $totalCopies)
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

// --- Semantic (AI embedding) signal -----------------------------------
// These functions read the "fingerprints" tools/generate_book_embeddings.php
// stored in book_embeddings (one-time, offline tagging -- see that script
// and database/ai_semantic_setup.sql). Nothing here calls any external API;
// this only does local math against numbers that are already in the DB.
// If book_embeddings doesn't exist yet, every function here is a no-op and
// scoring falls back to exactly the behavior this file had before.

function ai_embeddings_available(mysqli $conn): bool {
    static $cached = null;
    if ($cached === null) {
        $cached = table_exists($conn, 'book_embeddings');
    }
    return $cached;
}

function cosine_similarity(array $a, array $b): float {
    $n = min(count($a), count($b));
    if ($n === 0) {
        return 0.0;
    }

    $dot = 0.0;
    $magA = 0.0;
    $magB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }

    if ($magA <= 0.0 || $magB <= 0.0) {
        return 0.0;
    }

    return $dot / (sqrt($magA) * sqrt($magB));
}

function load_book_embeddings_by_ids(mysqli $conn, array $bookIds): array {
    $bookIds = array_values(array_unique(array_filter(array_map('intval', $bookIds), static function ($id): bool {
        return $id > 0;
    })));

    if (count($bookIds) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
    $types = str_repeat('i', count($bookIds));

    $stmt = $conn->prepare("SELECT book_id, embedding FROM book_embeddings WHERE book_id IN ({$placeholders})");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$bookIds);
    $stmt->execute();
    $res = $stmt->get_result();

    $map = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $bookId = (int)($row['book_id'] ?? 0);
            $vector = json_decode((string)($row['embedding'] ?? '[]'), true);
            if ($bookId > 0 && is_array($vector) && count($vector) > 0) {
                $map[$bookId] = array_map('floatval', $vector);
            }
        }
    }
    $stmt->close();

    return $map;
}

// Builds one "taste fingerprint" for a student by averaging the embeddings
// of the books they've actually borrowed (ground truth from borrow_records,
// not self-reported click events). Returns [] when the student has no
// borrow history yet or no embeddings are available for it.
function load_student_embedding_centroid(mysqli $conn, int $userId): array {
    if ($userId <= 0) {
        return [];
    }

    $rows = query_rows(
        $conn,
        "SELECT DISTINCT bc.book_id, br.record_id
         FROM borrow_records br
         INNER JOIN book_copies bc ON bc.copy_id = br.copy_id
         WHERE br.user_id = " . (int)$userId . "
         ORDER BY br.record_id DESC
         LIMIT 40"
    );

    $bookIds = [];
    foreach ($rows as $row) {
        $bid = (int)($row['book_id'] ?? 0);
        if ($bid > 0) {
            $bookIds[] = $bid;
        }
    }

    if (count($bookIds) === 0) {
        return [];
    }

    $vectors = load_book_embeddings_by_ids($conn, $bookIds);
    if (count($vectors) === 0) {
        return [];
    }

    $dimensions = count(reset($vectors));
    $centroid = array_fill(0, $dimensions, 0.0);

    foreach ($vectors as $vector) {
        foreach ($vector as $i => $value) {
            if ($i < $dimensions) {
                $centroid[$i] += $value;
            }
        }
    }

    $vectorCount = count($vectors);
    for ($i = 0; $i < $dimensions; $i++) {
        $centroid[$i] /= $vectorCount;
    }

    return $centroid;
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
        WHEN panel_key = 'most_searched' THEN 1.35
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
            SUM(CASE WHEN panel_key IN ('most_searched', 'course_highlights') THEN {$eventWeightExpr} ELSE 0 END) AS panel_search,
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
            'search' => (float)($row['panel_search'] ?? 0),
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
                SUM(CASE WHEN panel_key IN ('most_searched', 'course_highlights') THEN {$eventWeightExpr} ELSE 0 END) AS panel_search,
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
                'search' => (float)($row['panel_search'] ?? 0),
                'popular' => (float)($row['panel_popular'] ?? 0),
                'new' => (float)($row['panel_new'] ?? 0)
            ];
            $result['user_rows'] += 1;
        }
    }

    return $result;
}

function rank_rows_with_event_weights(array $rows, array $eventBundle, string $panelKey, bool $isPersonalized, array $semanticByBookId = []): array {
    if (count($rows) === 0) {
        return $rows;
    }

    $panelMap = [
        'course_highlights' => 'course',
        'most_searched' => 'search',
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

        // Continuous AI semantic similarity (0..1) between this book and the
        // student's borrowing taste, from cosine similarity of embedding
        // vectors. Unlike history_match (an exact category yes/no), this
        // recognizes books that are conceptually related even when they sit
        // in a different category -- the actual "AI understands the book"
        // signal. It's 0 for every row when embeddings haven't been
        // generated yet or the student has no borrow history, so this is
        // purely additive and never makes recommendations worse.
        $semanticScore = (float)($semanticByBookId[$bookId] ?? 0.0);

        $score = 0.0;
        $score += $borrowCount * 2.4;
        $score += $topicScore * 2.1;
        $score += $programMatch * 22;
        $score += $historyMatch * 14;
        $score += $semanticScore * 34;
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
    global $availabilityByBookId;

    $picked = [];

    foreach ($rows as $row) {
        $bookId = (int)($row['book_id'] ?? 0);
        if ($bookId <= 0 || isset($seenBookIds[$bookId])) {
            continue;
        }

        $availability = $availabilityByBookId[$bookId] ?? ['available' => 0, 'total' => 0];
        $availableCopies = (int)($availability['available'] ?? 0);
        $totalCopies = (int)($availability['total'] ?? 0);
        if ($availableCopies <= 0) {
            continue;
        }

        $row['available_copies'] = $availableCopies;
        $row['total_copies'] = $totalCopies;

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

        if ($reason === '' && isset($row['created_day'])) {
            $createdDay = trim((string)($row['created_day'] ?? ''));
            if ($createdDay !== '') {
                $ageDays = max(0, (int)floor((time() - strtotime($createdDay)) / 86400));
                if ($ageDays <= 30) {
                    $reason = 'New arrival';
                }
            }
        }

        if ($reason === '' && isset($row['genre_label'])) {
            $genre = trim((string)$row['genre_label']);
            if ($genre !== '') {
                $reason = "Popular in {$genre}";
            }
        }

        if ($reason === '') {
            $reason = recommendation_availability_label($availableCopies, $totalCopies);
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
$availabilityByBookId = load_book_availability($conn);
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

// A book can now be cross-listed under more than one program/category (see
// database/multi_category_setup.sql and tools/auto_categorize_books.php) --
// e.g. a research-methods book filed under Criminology but also genuinely
// relevant to CS students. When those tables exist, a book counts as a
// program/history match through EITHER its one primary category_id/
// program_id OR any extra course it's been cross-listed under. When the
// migration hasn't been run yet, this falls back to exactly the old
// single-value behavior -- nothing breaks either way.
$hasMultiCourseTables = table_exists($conn, 'book_programs') && table_exists($conn, 'book_categories');

$courseRows = [];
if ($isPersonalized) {
    $userIdForSql = (int)$profile['user_id'];

    $recentCategoriesSql = "SELECT DISTINCT b2.category_id
            FROM borrow_records br2
            INNER JOIN book_copies bc2 ON bc2.copy_id = br2.copy_id
            INNER JOIN books b2 ON b2.book_id = bc2.book_id
            WHERE br2.user_id = {$userIdForSql}
            ORDER BY br2.record_id DESC
            LIMIT 30";

    $programMatchExpr = "b.program_id = {$programId}";
    $historyMatchExpr = "b.category_id IN ({$recentCategoriesSql})";
    if ($hasMultiCourseTables) {
        $programMatchExpr .= " OR EXISTS (SELECT 1 FROM book_programs bpx WHERE bpx.book_id = b.book_id AND bpx.program_id = {$programId})";
        $historyMatchExpr .= " OR EXISTS (SELECT 1 FROM book_categories bcx WHERE bcx.book_id = b.book_id AND bcx.category_id IN ({$recentCategoriesSql}))";
    }

    $courseSql = "SELECT
        b.book_id,
        b.title,
        b.author,
        b.cover,
        COALESCE(pop.borrow_count, 0) AS borrow_count,
        CASE WHEN {$programMatchExpr} THEN 1 ELSE 0 END AS program_match,
        CASE WHEN {$historyMatchExpr} THEN 1 ELSE 0 END AS history_match,
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
     WHERE ({$programMatchExpr})
        OR ({$historyMatchExpr})
     ORDER BY program_match DESC, history_match DESC, borrow_count DESC, b.created_at DESC, b.book_id DESC
     LIMIT 80";

    $courseRows = query_rows($conn, $courseSql);

    if (count($courseRows) === 0) {
        $courseRows = query_rows(
            $conn,
            "SELECT b.book_id, b.title, b.author, b.cover, 0 AS borrow_count, 1 AS program_match, 0 AS history_match, '' AS genre_label, DATE(b.created_at) AS created_day
             FROM books b
             WHERE {$programMatchExpr}
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

// Build the semantic similarity map once per request, bounded to only the
// books already gathered above (a few hundred rows at most) rather than the
// whole catalog -- this stays fast and light whether the library has 500
// books or the full ~10k that are still being entered.
$semanticByBookId = [];
if (ai_embeddings_available($conn) && $isPersonalized) {
    $candidateBookIds = [];
    foreach ([$popularRows, $newArrivalRows, $searchDrivenRows, $courseRows] as $rowSet) {
        foreach ($rowSet as $row) {
            $bid = (int)($row['book_id'] ?? 0);
            if ($bid > 0) {
                $candidateBookIds[$bid] = true;
            }
        }
    }

    if (count($candidateBookIds) > 0) {
        $studentCentroid = load_student_embedding_centroid($conn, (int)$profile['user_id']);
        if (count($studentCentroid) > 0) {
            $candidateEmbeddings = load_book_embeddings_by_ids($conn, array_keys($candidateBookIds));
            foreach ($candidateEmbeddings as $bid => $vector) {
                $semanticByBookId[$bid] = cosine_similarity($studentCentroid, $vector);
            }
        }
    }
}

$popularRows = rank_rows_with_event_weights($popularRows, $recEventBundle, 'most_borrowed', $isPersonalized, $semanticByBookId);

$searchDrivenRows = rank_rows_with_event_weights($searchDrivenRows, $recEventBundle, 'most_searched', $isPersonalized, $semanticByBookId);
$courseRows = rank_rows_with_event_weights($courseRows, $recEventBundle, 'course_highlights', $isPersonalized, $semanticByBookId);

$usedMostBorrowed = [];
$mostBorrowedBooks = unique_take($popularRows, 5, $usedMostBorrowed);

$usedNewArrivals = [];
$newArrivalBooks = unique_take($newArrivalRows, 5, $usedNewArrivals);

if ($isPersonalized) {
    $courseReason = $programName !== '' ? "Matches {$programName}" : 'Matches your course';
    $usedCourse = [];
    // Only genuine course/program matches go in this panel -- no padding
    // with generic trending-search or popular-campus books that have
    // nothing to do with the student's actual program. Backfilling used to
    // let an unrelated book (e.g. a Nursing title trending in searches)
    // show up under a "Course-Specific Highlights" / "AI highlights for
    // <program>" banner, which was misleading even though that book's own
    // reason text was honestly labeled. With a small catalog this means
    // the panel may come back with fewer than 5 books, or none at all (in
    // which case it's dropped from the response entirely below) -- more
    // honest than padding, and it fills back in naturally as more books
    // get tagged and cross-linked.
    $courseBooks = unique_take($courseRows, 5, $usedCourse, $courseReason);

    $courseSubtitle = $programName !== ''
        ? "AI highlights for {$programName} students"
        : 'AI highlights based on your course and activity';
} else {
    $usedCourse = [];
    $courseBooks = unique_take($searchDrivenRows, 5, $usedCourse, 'Trending in current kiosk searches');

    if (count($courseBooks) < 5) {
        $courseBooks = array_merge(
            $courseBooks,
            unique_take($courseRows, 5 - count($courseBooks), $usedCourse, 'AI trending pick')
        );
    }

    if (count($courseBooks) < 5) {
        $courseBooks = array_merge(
            $courseBooks,
            unique_take($popularRows, 5 - count($courseBooks), $usedCourse, 'Campus favorite')
        );
    }

    $courseSubtitle = count($searchDrivenRows) > 0
        ? 'AI highlights based on current kiosk searches'
        : 'AI highlights based on campus trends';
}

$usedMostSearched = [];
$mostSearchedBooks = unique_take($searchDrivenRows, 5, $usedMostSearched, 'Trending in current kiosk searches');

if (count($mostSearchedBooks) < 5) {
    $mostSearchedBooks = array_merge(
        $mostSearchedBooks,
        unique_take($popularRows, 5 - count($mostSearchedBooks), $usedMostSearched, 'Popular on campus')
    );
}

if ($isPersonalized) {
    $panels = [];

    // Only show this panel when there are genuine course-relevant books --
    // an empty or missing panel is more honest than one padded with
    // unrelated content under a course-specific banner (see the note above
    // where $courseBooks is built).
    if (count($courseBooks) > 0) {
        $panels[] = [
            'key' => 'course_highlights',
            'title' => 'Course-Specific Highlights',
            'subtitle' => $courseSubtitle,
            'books' => $courseBooks
        ];
    }

    $panels[] = [
        'key' => 'most_borrowed',
        'title' => 'Most Borrowed Books',
        'subtitle' => 'Popular titles frequently checked out by students',
        'books' => $mostBorrowedBooks
    ];
    $panels[] = [
        'key' => 'most_searched',
        'title' => 'Most Searched',
        'subtitle' => 'Topics and titles students are actively looking for',
        'books' => $mostSearchedBooks
    ];
    $panels[] = [
        'key' => 'new_arrivals',
        'title' => 'New Arrivals',
        'subtitle' => 'Latest books and journals added to our collection',
        'books' => $newArrivalBooks
    ];
} else {
    $panels = [
        [
            'key' => 'most_borrowed',
            'title' => 'Most Borrowed Books',
            'subtitle' => 'Popular titles frequently checked out by students',
            'books' => $mostBorrowedBooks
        ],
        [
            'key' => 'most_searched',
            'title' => 'Most Searched',
            'subtitle' => 'Topics and titles students are actively looking for',
            'books' => $mostSearchedBooks
        ],
        [
            'key' => 'new_arrivals',
            'title' => 'New Arrivals',
            'subtitle' => 'Latest books and journals added to our collection',
            'books' => $newArrivalBooks
        ]
    ];
}

echo json_encode([
    'status' => 'success',
    'engine' => ai_embeddings_available($conn) ? 'hybrid_semantic_v4' : 'local_rules_v3_weighted',
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
        'recommendation_event_books_user' => (int)($recEventBundle['user_rows'] ?? 0),
        'semantic_embeddings_enabled' => ai_embeddings_available($conn),
        'semantic_matches_used' => count($semanticByBookId),
        'available_recommendation_books' => count(array_filter($availabilityByBookId, static function ($row): bool { return (int)($row['available'] ?? 0) > 0; }))
    ],
    'panels' => $panels
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>






