<?php require 'layout_top.php'; ?>
<?php
require_once '../config/db_connect.php';
require_once '../config/borrow_fine_rules.php';
require_once __DIR__ . '/includes/top_borrowers.php';

// Top Borrowers panel: three ready-made lists, switched client-side.
$topBorrowerSets = [
    'all' => smartlib_top_borrowers($conn, null, null, 'all', 10),
    'month' => smartlib_top_borrowers($conn, date('Y-m-01'), date('Y-m-t'), 'all', 10),
    'year' => smartlib_top_borrowers($conn, date('Y-01-01'), date('Y-12-31'), 'all', 10),
];

function safe_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function safe_scalar_int(mysqli $conn, string $sql, int $fallback = 0): int {
    $res = safe_query($conn, $sql);
    if (!$res) {
        return $fallback;
    }

    $row = $res->fetch_row();
    return $row ? (int)$row[0] : $fallback;
}

function safe_scalar_float(mysqli $conn, string $sql, float $fallback = 0.0): float {
    $res = safe_query($conn, $sql);
    if (!$res) {
        return $fallback;
    }

    $row = $res->fetch_row();
    return $row ? (float)$row[0] : $fallback;
}
function table_exists(mysqli $conn, string $table): bool {
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

function normalize_term(string $term): string {
    $term = trim((string)(preg_replace('/\s+/', ' ', $term) ?? ''));
    return strtolower($term);
}

function canonical_search_keyword(string $term): string {
    $term = normalize_term($term);
    if ($term === '') {
        return '';
    }

    $term = (string)(preg_replace('/[^a-z0-9\s]/', ' ', $term) ?? '');
    $term = normalize_term($term);
    if ($term === '') {
        return '';
    }

    $tokenCorrections = [
        'codin' => 'coding',
        'codingg' => 'coding',
        'programing' => 'programming',
        'progrmming' => 'programming',
        'programmin' => 'programming',
        'programi' => 'programming',
        'busines' => 'business',
        'busine' => 'business',
        'finana' => 'finance',
        'finacia' => 'finance',
        'financia' => 'finance',
        'finace' => 'finance',
        'finacial' => 'financial',
        'managemnt' => 'management',
        'managemen' => 'management',
        'markting' => 'marketing',
        'marketi' => 'marketing',
        'amrke' => 'marketing',
        'amrk' => 'marketing',
        'accouting' => 'accounting',
        'cybercrme' => 'cybercrime',
        'cybercrim' => 'cybercrime',
        'cybercir' => 'cybercrime',
        'cybercri' => 'cybercrime',
        'crimi' => 'criminology',
        'crimino' => 'criminology'
    ];

    $tokens = [];
    foreach (explode(' ', $term) as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }

        $normalizedToken = $tokenCorrections[$token] ?? $token;

        if ($normalizedToken === $token) {
            if (preg_match('/^financ[a-z]*$/i', $token)) {
                $normalizedToken = 'finance';
            } elseif (preg_match('/^program[a-z]*$/i', $token)) {
                $normalizedToken = 'programming';
            } elseif (preg_match('/^busines[a-z]*$/i', $token)) {
                $normalizedToken = 'business';
            } elseif (preg_match('/^manage[a-z]*$/i', $token)) {
                $normalizedToken = 'management';
            } elseif (preg_match('/^marke[a-z]*$/i', $token)) {
                $normalizedToken = 'marketing';
            }
        }

        $tokens[] = $normalizedToken;
    }
    $term = normalize_term(implode(' ', $tokens));
    if ($term === '') {
        return '';
    }

    if (preg_match('/\bpython\b/i', $term)) {
        return 'python';
    }

    if (preg_match('/\b(coding)\b/i', $term)) {
        return 'coding';
    }

    if (preg_match('/\b(programming|software|algorithm|data structures?|java|javascript|php|web development|computer science)\b/i', $term)) {
        return 'programming';
    }

    if (preg_match('/\b(marketing|advertising|branding|sales|digital marketing)\b/i', $term)) {
        return 'marketing';
    }

    if (preg_match('/\b(accounting|bookkeeping)\b/i', $term)) {
        return 'accounting';
    }

    if (preg_match('/\b(finance|financial|economics)\b/i', $term)) {
        return 'finance';
    }

    if (preg_match('/\b(management)\b/i', $term)) {
        return 'management';
    }

    if (preg_match('/\b(business|entrepreneurship)\b/i', $term)) {
        return 'business';
    }

    if (preg_match('/\b(nursing|medical|health|anatomy|pharmacology)\b/i', $term)) {
        return 'health sciences';
    }

    if (preg_match('/\b(research|thesis|methodology|capstone)\b/i', $term)) {
        return 'research methods';
    }

    if (preg_match('/\b(math|algebra|calculus|statistics)\b/i', $term)) {
        return 'mathematics';
    }

    $stopWords = [
        'book' => true,
        'books' => true,
        'about' => true,
        'for' => true,
        'the' => true,
        'a' => true,
        'an' => true,
        'on' => true,
        'to' => true,
        'of' => true
    ];

    $cleanTokens = [];
    foreach (explode(' ', $term) as $token) {
        $token = trim($token);
        if ($token === '' || isset($stopWords[$token])) {
            continue;
        }
        if (strlen($token) < 2) {
            continue;
        }
        $cleanTokens[] = $token;
    }

    if (empty($cleanTokens)) {
        return '';
    }

    return implode(' ', array_slice($cleanTokens, 0, 3));
}

function is_meaningful_keyword(string $term): bool {
    $term = normalize_term($term);
    if ($term === '') {
        return false;
    }

    $compact = str_replace(' ', '', $term);
    if (strlen($compact) < 4) {
        return false;
    }

    if (!preg_match('/[a-z]/i', $term)) {
        return false;
    }

    if (preg_match('/^(.)\1+$/u', $compact)) {
        return false;
    }

    return true;
}

function is_partial_prefix_keyword(string $term, int $hits, array $keywordHits): bool {
    $term = normalize_term($term);
    if ($term === '') {
        return false;
    }

    $compactTerm = str_replace(' ', '', $term);
    $termLen = strlen($compactTerm);
    if ($termLen < 4) {
        return true;
    }

    foreach ($keywordHits as $candidate => $candidateHits) {
        $candidate = normalize_term((string)$candidate);
        if ($candidate === '' || $candidate === $term) {
            continue;
        }

        $compactCandidate = str_replace(' ', '', $candidate);
        $candidateLen = strlen($compactCandidate);
        $lenGap = $candidateLen - $termLen;
        if ($lenGap <= 0 || $lenGap > 8) {
            continue;
        }

        if (!str_starts_with($compactCandidate, $compactTerm)) {
            continue;
        }

        $minCandidateHits = max(1, (int)ceil($hits * 0.35));
        if ((int)$candidateHits >= $minCandidateHits) {
            return true;
        }
    }

    return false;
}

function search_keyword_category(string $keyword): string {
    $keyword = normalize_term($keyword);

    if (preg_match('/\b(python|programming|coding|software|algorithm|data structures?|java|javascript|php|computer)\b/i', $keyword)) {
        return 'IT / Programming';
    }

    if (preg_match('/\b(marketing|accounting|finance|economics|business|sales|branding|advertising)\b/i', $keyword)) {
        return 'Business / Management';
    }

    if (preg_match('/\b(nursing|medical|health|anatomy|pharmacology)\b/i', $keyword)) {
        return 'Health Sciences';
    }

    if (preg_match('/\b(math|algebra|calculus|statistics)\b/i', $keyword)) {
        return 'Math / Quantitative';
    }

    if (preg_match('/\b(research|thesis|methodology|capstone)\b/i', $keyword)) {
        return 'Research';
    }

    return 'General / Mixed';
}

function day_series_labels_values(array $dayMap, int $days): array {
    $labels = [];
    $values = [];

    $today = new DateTime('today');
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = (clone $today)->modify("-{$i} day");
        $key = $d->format('Y-m-d');
        $labels[] = $d->format('M j');
        $values[] = (int)($dayMap[$key] ?? 0);
    }

    return ['labels' => $labels, 'values' => $values];
}

function activity_action_label(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'returned') return 'returned';
    if ($s === 'missing') return 'marked missing';
    return 'checked out';
}

sync_overdue_status_and_fines($conn);
// Overdue fines can be off (Settings, the default): then no amount shows.
$finesOn = smartlib_fines_enabled($conn);

$totalBooks = safe_scalar_int($conn, 'SELECT COUNT(*) FROM books');
$totalStudents = safe_scalar_int($conn, "SELECT COUNT(*) FROM library_users WHERE role = 'student'");
$totalFaculty = safe_scalar_int($conn, "SELECT COUNT(*) FROM library_users WHERE role = 'faculty'");
$totalBorrows = safe_scalar_int($conn, 'SELECT COUNT(*) FROM borrow_records');
$totalPrograms = safe_scalar_int($conn, 'SELECT COUNT(*) FROM programs');
$overdueDashboardCount = safe_scalar_int($conn, "SELECT COUNT(*) FROM borrow_records WHERE status = 'overdue'");
$overdueFineTotal = $finesOn
    ? safe_scalar_float($conn, "SELECT COALESCE(SUM(fine), 0) FROM borrow_records WHERE status = 'overdue'")
    : 0.0;
$longestDaysOverdue = safe_scalar_int($conn, "SELECT COALESCE(MAX(DATEDIFF(CURDATE(), due_date)), 0) FROM borrow_records WHERE status = 'overdue' AND due_date IS NOT NULL");
$highPriorityOverdueCount = safe_scalar_int($conn, "SELECT COUNT(*) FROM borrow_records WHERE status = 'overdue' AND due_date IS NOT NULL AND DATEDIFF(CURDATE(), due_date) >= 7");

$newArrivalRows = [];
$newArrivalsRes = safe_query(
    $conn,
    "
    SELECT
        COALESCE(b.title, 'Untitled') AS title,
        COALESCE(b.author, 'Unknown Author') AS author,
        COALESCE(c.category_name, 'Uncategorized') AS category_name,
        COALESCE(p.program_name, 'General Collection') AS program_name,
        b.created_at
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN programs p ON b.program_id = p.program_id
    ORDER BY b.created_at DESC, b.book_id DESC
    LIMIT 5
    "
);

if ($newArrivalsRes && $newArrivalsRes->num_rows > 0) {
    while ($row = $newArrivalsRes->fetch_assoc()) {
        $newArrivalRows[] = [
            'title' => (string)($row['title'] ?? 'Untitled'),
            'author' => (string)($row['author'] ?? 'Unknown Author'),
            'category_name' => (string)($row['category_name'] ?? 'Uncategorized'),
            'program_name' => (string)($row['program_name'] ?? 'General Collection'),
            'created_at' => (string)($row['created_at'] ?? '')
        ];
    }
}

$liveActivityRows = [];
$liveActivityRes = safe_query(
    $conn,
    "
    SELECT
        CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS borrower,
        COALESCE(b.title, 'Unknown Book') AS title,
        COALESCE(br.status, 'borrowed') AS status,
        br.created_at
    FROM borrow_records br
    LEFT JOIN library_users u ON br.user_id = u.user_id
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    ORDER BY br.created_at DESC, br.record_id DESC
    LIMIT 10
    "
);

if ($liveActivityRes && $liveActivityRes->num_rows > 0) {
    while ($row = $liveActivityRes->fetch_assoc()) {
        $borrower = trim((string)($row['borrower'] ?? ''));
        if ($borrower === '') {
            $borrower = 'Unknown User';
        }

        $liveActivityRows[] = [
            'borrower' => $borrower,
            'title' => (string)($row['title'] ?? 'Unknown Book'),
            'status' => (string)($row['status'] ?? 'borrowed'),
            'action' => activity_action_label((string)($row['status'] ?? 'borrowed')),
            'created_at' => (string)($row['created_at'] ?? '-')
        ];
    }
}

$overdueRows = [];
$overdueRes = safe_query(
    $conn,
    "
    SELECT
        br.record_id,
        br.user_id,
        COALESCE(
            NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
            NULLIF(TRIM(COALESCE(u.user_number, '')), ''),
            'Unknown User'
        ) AS borrower,
        COALESCE(b.title, 'Unknown Book') AS title,
        COALESCE(br.fine, 0) AS fine,
        GREATEST(DATEDIFF(CURDATE(), br.due_date), 0) AS days_overdue,
        br.due_date
    FROM borrow_records br
    LEFT JOIN library_users u ON br.user_id = u.user_id
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    WHERE (
        br.status = 'overdue'
        OR (
            br.status = 'borrowed'
            AND br.date_returned IS NULL
            AND br.due_date IS NOT NULL
            AND DATE(br.due_date) < CURDATE()
        )
    )
    ORDER BY DATE(br.due_date) ASC, br.record_id DESC
    LIMIT 12
    "
);

if ($overdueRes && $overdueRes->num_rows > 0) {
    while ($row = $overdueRes->fetch_assoc()) {
        $overdueRows[] = [
            'record_id' => (int)($row['record_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'borrower' => trim((string)($row['borrower'] ?? 'Unknown User')),
            'title' => (string)($row['title'] ?? 'Unknown Book'),
            'fine' => (float)($row['fine'] ?? 0),
            'days_overdue' => (int)($row['days_overdue'] ?? 0),
            'due_date' => (string)($row['due_date'] ?? '')
        ];
    }
}
$searchLogsEnabled = table_exists($conn, 'search_logs');
$topSearchRows = [];
$searchKeywordLabels = [];
$searchKeywordValues = [];
$searchCategoryRows = [];
$searchTrendLabels = [];
$searchTrendValues = [];

if ($searchLogsEnabled) {
    $keywordAgg = [];
    $categoryAgg = [];

    $topSearchRes = safe_query(
        $conn,
        "
        SELECT
            LOWER(TRIM(search_term)) AS raw_term,
            COUNT(*) AS hits,
            MAX(created_at) AS last_seen
        FROM search_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 120 DAY)
          AND TRIM(search_term) <> ''
        GROUP BY LOWER(TRIM(search_term))
        ORDER BY hits DESC, last_seen DESC
        LIMIT 600
        "
    );

    if ($topSearchRes && $topSearchRes->num_rows > 0) {
        while ($row = $topSearchRes->fetch_assoc()) {
            $raw = (string)($row['raw_term'] ?? '');
            $hits = (int)($row['hits'] ?? 0);
            if ($hits <= 0) {
                continue;
            }

            $keyword = canonical_search_keyword($raw);
            if ($keyword === '') {
                continue;
            }

            $category = search_keyword_category($keyword);
            if (!isset($keywordAgg[$keyword])) {
                $keywordAgg[$keyword] = [
                    'keyword' => $keyword,
                    'category' => $category,
                    'hits' => 0,
                    'last_seen' => (string)($row['last_seen'] ?? '')
                ];
            }

            $keywordAgg[$keyword]['hits'] += $hits;
            $candidateLastSeen = (string)($row['last_seen'] ?? '');
            if ($candidateLastSeen > $keywordAgg[$keyword]['last_seen']) {
                $keywordAgg[$keyword]['last_seen'] = $candidateLastSeen;
            }

            if (!isset($categoryAgg[$category])) {
                $categoryAgg[$category] = 0;
            }
            $categoryAgg[$category] += $hits;
        }
    }

    if (!empty($keywordAgg)) {
        $keywordHits = [];
        foreach ($keywordAgg as $entry) {
            $keywordHits[(string)($entry['keyword'] ?? '')] = (int)($entry['hits'] ?? 0);
        }

        $topSearchRows = [];
        foreach ($keywordAgg as $entry) {
            $keyword = (string)($entry['keyword'] ?? '');
            $hits = (int)($entry['hits'] ?? 0);

            if (!is_meaningful_keyword($keyword)) {
                continue;
            }

            if (is_partial_prefix_keyword($keyword, $hits, $keywordHits)) {
                continue;
            }

            $topSearchRows[] = $entry;
        }

        usort($topSearchRows, function ($a, $b) {
            if ((int)$a['hits'] !== (int)$b['hits']) {
                return (int)$b['hits'] <=> (int)$a['hits'];
            }
            return strcmp((string)$a['keyword'], (string)$b['keyword']);
        });

        $categoryAgg = [];
        foreach ($topSearchRows as $entry) {
            $category = (string)($entry['category'] ?? 'General / Mixed');
            $categoryAgg[$category] = (int)($categoryAgg[$category] ?? 0) + (int)($entry['hits'] ?? 0);
        }

        $topSearchRows = array_slice($topSearchRows, 0, 20);

        foreach (array_slice($topSearchRows, 0, 10) as $row) {
            $searchKeywordLabels[] = (string)$row['keyword'];
            $searchKeywordValues[] = (int)$row['hits'];
        }
    }

    if (!empty($categoryAgg)) {
        foreach ($categoryAgg as $category => $hits) {
            $searchCategoryRows[] = [
                'category' => (string)$category,
                'hits' => (int)$hits
            ];
        }

        usort($searchCategoryRows, function ($a, $b) {
            if ((int)$a['hits'] !== (int)$b['hits']) {
                return (int)$b['hits'] <=> (int)$a['hits'];
            }
            return strcmp((string)$a['category'], (string)$b['category']);
        });
    }

    $searchTrendMap = [];
    $searchTrendRes = safe_query(
        $conn,
        "
        SELECT
            DATE(created_at) AS day_key,
            COUNT(*) AS total
        FROM search_logs
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day_key ASC
        "
    );

    if ($searchTrendRes && $searchTrendRes->num_rows > 0) {
        while ($row = $searchTrendRes->fetch_assoc()) {
            $searchTrendMap[(string)($row['day_key'] ?? '')] = (int)($row['total'] ?? 0);
        }
    }

    $searchSeries = day_series_labels_values($searchTrendMap, 14);
    $searchTrendLabels = $searchSeries['labels'];
    $searchTrendValues = $searchSeries['values'];
}

$mostBorrowedRows = [];
$mostBorrowedLabels = [];
$mostBorrowedValues = [];

$mostBorrowedRes = safe_query(
    $conn,
    "
    SELECT
        COALESCE(b.title, 'Unknown Book') AS title,
        COUNT(br.record_id) AS borrow_count
    FROM borrow_records br
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    GROUP BY b.book_id, b.title
    HAVING COUNT(br.record_id) > 0
    ORDER BY borrow_count DESC, title ASC
    LIMIT 10
    "
);

if ($mostBorrowedRes && $mostBorrowedRes->num_rows > 0) {
    while ($row = $mostBorrowedRes->fetch_assoc()) {
        $title = (string)($row['title'] ?? 'Unknown Book');
        $count = (int)($row['borrow_count'] ?? 0);

        $mostBorrowedRows[] = [
            'title' => $title,
            'borrow_count' => $count
        ];

        $mostBorrowedLabels[] = $title;
        $mostBorrowedValues[] = $count;
    }
}

$borrowTrendMap = [];
$borrowTrendRes = safe_query(
    $conn,
    "
    SELECT
        DATE(COALESCE(date_borrowed, DATE(created_at))) AS day_key,
        COUNT(*) AS total
    FROM borrow_records
    WHERE COALESCE(date_borrowed, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(COALESCE(date_borrowed, DATE(created_at)))
    ORDER BY day_key ASC
    "
);

if ($borrowTrendRes && $borrowTrendRes->num_rows > 0) {
    while ($row = $borrowTrendRes->fetch_assoc()) {
        $borrowTrendMap[(string)($row['day_key'] ?? '')] = (int)($row['total'] ?? 0);
    }
}

$borrowSeries = day_series_labels_values($borrowTrendMap, 14);
$borrowTrendLabels = $borrowSeries['labels'];
$borrowTrendValues = $borrowSeries['values'];

$courseLabels = [];
$courseBorrowedTitles = [];
$courseRemainingTitles = [];
$courseActivityValues = [];

$coursePopularityRes = safe_query(
    $conn,
    "
    SELECT
        p.program_name,
        COUNT(DISTINCT b.book_id) AS total_titles,
        COUNT(DISTINCT CASE WHEN br.record_id IS NOT NULL THEN b.book_id END) AS borrowed_titles,
        COUNT(br.record_id) AS borrow_activity
    FROM programs p
    LEFT JOIN books b ON b.program_id = p.program_id
    LEFT JOIN book_copies bc ON bc.book_id = b.book_id
    LEFT JOIN borrow_records br ON br.copy_id = bc.copy_id
    GROUP BY p.program_id, p.program_name
    ORDER BY borrow_activity DESC, total_titles DESC, p.program_name ASC
    LIMIT 12
    "
);

if ($coursePopularityRes && $coursePopularityRes->num_rows > 0) {
    while ($row = $coursePopularityRes->fetch_assoc()) {
        $program = trim((string)($row['program_name'] ?? ''));
        if ($program === '') {
            continue;
        }

        $totalTitles = (int)($row['total_titles'] ?? 0);
        $borrowedTitles = (int)($row['borrowed_titles'] ?? 0);
        $borrowActivity = (int)($row['borrow_activity'] ?? 0);
        $remainingTitles = max($totalTitles - $borrowedTitles, 0);

        $courseLabels[] = $program;
        $courseBorrowedTitles[] = $borrowedTitles;
        $courseRemainingTitles[] = $remainingTitles;
        $courseActivityValues[] = $borrowActivity;
    }
}

// "Top Borrowed Book per Program" panel below. This is a plain all-time
// borrow-count aggregate per program -- NOT the same engine as
// recommend_books.php (the kiosk's actual personalized recommendation
// engine, which factors in search trends, per-user event weights, AI
// semantic similarity, and only runs for a specific signed-in patron).
// This panel used to be mislabeled as "Local Recommendation Engine" /
// "Local Weighted Rules (v3)" with fabricated "confidence" copy that
// implied it reflected the kiosk's real logic -- it didn't, and an admin
// had no way to tell. Keep this panel's copy honest about being a simple
// circulation-leaders stat, not a recommendation engine.
$recommendationCards = [];
// Per-book borrow counts for every program with at least one checkout.
// Accuracy notes: titles that share the top count are reported as a tie
// rather than one being silently picked as "the" leader; programs with
// no borrows get no card (a 0-borrow "top pick" means nothing); every
// active program is shown instead of stopping at six; and each card
// carries the program's total so "4 borrows" has context. Program
// membership is the book's primary program (books.program_id), the same
// basis as the Course Popularity / Activity charts above.
$courseBestBookRes = safe_query(
    $conn,
    "
    SELECT
        p.program_name,
        b.book_id,
        COALESCE(b.title, '') AS title,
        COUNT(br.record_id) AS borrow_count,
        MAX(COALESCE(br.date_borrowed, DATE(br.created_at))) AS last_borrow
    FROM programs p
    INNER JOIN books b ON b.program_id = p.program_id
    INNER JOIN book_copies bc ON bc.book_id = b.book_id
    INNER JOIN borrow_records br ON br.copy_id = bc.copy_id
    GROUP BY p.program_id, p.program_name, b.book_id, b.title
    ORDER BY p.program_name ASC, borrow_count DESC, last_borrow DESC, b.title ASC
    "
);

$programBooks = [];
if ($courseBestBookRes && $courseBestBookRes->num_rows > 0) {
    while ($row = $courseBestBookRes->fetch_assoc()) {
        $program = trim((string)($row['program_name'] ?? ''));
        $title = trim((string)($row['title'] ?? ''));
        $count = (int)($row['borrow_count'] ?? 0);
        if ($program === '' || $title === '' || $count <= 0) {
            continue;
        }
        $programBooks[$program][] = ['title' => $title, 'borrow_count' => $count];
    }
}

foreach ($programBooks as $program => $books) {
    $topCount = $books[0]['borrow_count'];
    $leaders = array_values(array_filter($books, fn($bk) => $bk['borrow_count'] === $topCount));
    $recommendationCards[] = [
        'program_name' => $program,
        'title' => (string)$leaders[0]['title'],
        'borrow_count' => $topCount,
        'tied_titles' => array_map(fn($bk) => (string)$bk['title'], array_slice($leaders, 1)),
        'program_borrows' => array_sum(array_column($books, 'borrow_count')),
        'titles_borrowed' => count($books)
    ];
}

usort($recommendationCards, fn($x, $y) => $y['program_borrows'] <=> $x['program_borrows']
    ?: $y['borrow_count'] <=> $x['borrow_count']
    ?: strcmp($x['program_name'], $y['program_name']));

if (empty($recommendationCards) && !empty($mostBorrowedRows)) {
    foreach (array_slice($mostBorrowedRows, 0, 4) as $row) {
        $recommendationCards[] = [
            'program_name' => 'General',
            'title' => (string)$row['title'],
            'borrow_count' => (int)$row['borrow_count']
        ];
    }
}

$chartPayload = [
    'searchKeywords' => [
        'labels' => $searchKeywordLabels,
        'values' => $searchKeywordValues
    ],
    'searchTrend' => [
        'labels' => $searchTrendLabels,
        'values' => $searchTrendValues
    ],
    'mostBorrowedTop' => [
        'labels' => $mostBorrowedLabels,
        'values' => $mostBorrowedValues
    ],
    'mostBorrowedTrend' => [
        'labels' => $borrowTrendLabels,
        'values' => $borrowTrendValues
    ],
    'coursePopularity' => [
        'labels' => $courseLabels,
        'borrowedTitles' => $courseBorrowedTitles,
        'remainingTitles' => $courseRemainingTitles
    ],
    'courseActivity' => [
        'labels' => $courseLabels,
        'values' => $courseActivityValues
    ]
];

$chartJson = json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
?>

<div class="page-top dashboard-top">
    <div>
        <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('dashboard') ?></span>Dashboard</h1>
        <p class="page-subtitle">Admin view with new arrivals, search behavior, borrowing trends, and course-based recommendations.</p>
    </div>
    <div class="welcome">
        <span class="welcome-icon" aria-hidden="true"><?= admin_icon('user-check') ?></span>
        <span class="welcome-text">Welcome, <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Librarian') ?></strong>!</span>
    </div>
</div>

<section class="stats-grid dashboard-metrics compact-metrics">
    <article class="stat-card glass-card metric-card">
        <div class="metric-head-row">
            <span class="metric-icon-badge metric-icon-badge--blue" aria-hidden="true"><?= admin_icon('books') ?></span>
            <div class="metric-label">Total Books</div>
        </div>
        <div class="metric-value"><?= number_format($totalBooks) ?></div>
        <div class="metric-meta">Catalog titles in collection</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-head-row">
            <span class="metric-icon-badge metric-icon-badge--blue" aria-hidden="true"><?= admin_icon('graduation-cap') ?></span>
            <div class="metric-label">Total Students</div>
        </div>
        <div class="metric-value"><?= number_format($totalStudents) ?></div>
        <div class="metric-meta">Registered student accounts</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-head-row">
            <span class="metric-icon-badge metric-icon-badge--blue" aria-hidden="true"><?= admin_icon('id-badge') ?></span>
            <div class="metric-label">Total Faculty</div>
        </div>
        <div class="metric-value"><?= number_format($totalFaculty) ?></div>
        <div class="metric-meta">Registered faculty accounts</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-head-row">
            <span class="metric-icon-badge metric-icon-badge--blue" aria-hidden="true"><?= admin_icon('swap') ?></span>
            <div class="metric-label">Total Borrows</div>
        </div>
        <div class="metric-value"><?= number_format($totalBorrows) ?></div>
        <div class="metric-meta">All-time borrow transactions</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-head-row">
            <span class="metric-icon-badge metric-icon-badge--blue" aria-hidden="true"><?= admin_icon('layers') ?></span>
            <div class="metric-label">Programs</div>
        </div>
        <div class="metric-value"><?= number_format($totalPrograms) ?></div>
        <div class="metric-meta">All listed courses/programs</div>
    </article>
</section>

<section class="dashboard-section-wrap">
    <div class="dashboard-public-grid">
        <article class="panel glass-card dashboard-scan-panel">
            <div class="panel-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('scan') ?></span>Start your day</h2>
                    <p class="panel-sub">Open the entry scanner each morning and leave it running at the door.</p>
                </div>
                <a class="btn-primary dashboard-scan-btn" href="entry_scan.php">Open Entry Scanner</a>
            </div>
        </article>

        <article class="panel glass-card dashboard-scan-panel">
            <div class="panel-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('kiosk') ?></span>Kiosk</h2>
                    <p class="panel-sub">Return this screen to student self-service mode.</p>
                </div>
                <a class="btn-primary dashboard-scan-btn" href="../index.php">Go to Kiosk</a>
            </div>
        </article>
    </div>
</section>

<section class="dashboard-chart-grid arrival-activity-row"> 
    <article class="panel glass-card chart-panel compact-panel overdue-books-panel">
        <div class="panel-head overdue-panel-head">
            <div>
                <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('clock') ?></span><?= $finesOn ? 'Overdue &amp; Fines' : 'Overdue' ?></h2>
                <p class="panel-sub"><?= $finesOn ? 'Priority returns, estimated fines, and quick email reminders' : 'Priority returns and quick email reminders' ?></p>
            </div>
            <a class="panel-link-btn" href="borrow_records.php?status=overdue">View all</a>
        </div>

        <div class="overdue-summary-grid<?= $finesOn ? '' : ' is-three' ?>" aria-label="Overdue summary">
            <a class="overdue-summary-card" href="borrow_records.php?status=overdue">
                <span>Overdue</span>
                <strong><?= number_format($overdueDashboardCount) ?></strong>
            </a>
            <?php if ($finesOn): ?>
            <a class="overdue-summary-card" href="borrow_records.php?status=overdue">
                <span>Est. Fines</span>
                <strong>PHP <?= number_format($overdueFineTotal, 2) ?></strong>
            </a>
            <?php endif; ?>
            <a class="overdue-summary-card overdue-summary-card-alert" href="borrow_records.php?status=overdue">
                <span>7+ Days Late</span>
                <strong><?= number_format($highPriorityOverdueCount) ?></strong>
            </a>
            <a class="overdue-summary-card" href="borrow_records.php?status=overdue">
                <span>Longest</span>
                <strong><?= number_format($longestDaysOverdue) ?>d</strong>
            </a>
        </div>

        <div class="table-wrap compact-table-wrap overdue-table-wrap">
            <table class="data-table insight-table overdue-preview-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Book Title</th>
                        <th>Late</th>
                        <?php if ($finesOn): ?><th>Fine</th><?php endif; ?>
                        <th class="overdue-action-col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($overdueRows)): ?>
                        <?php foreach ($overdueRows as $row): ?>
                            <?php
                                $dueRaw = (string)($row['due_date'] ?? '');
                                $dueTs = $dueRaw !== '' ? strtotime($dueRaw) : false;
                                $dueDisplay = $dueTs ? date('M d, Y', $dueTs) : '-';
                            ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars((string)($row['borrower'] ?? 'Unknown User')) ?>
                                    <small class="overdue-due-date">Due <?= htmlspecialchars($dueDisplay) ?></small>
                                </td>
                                <td><?= htmlspecialchars((string)($row['title'] ?? 'Unknown Book')) ?></td>
                                <td><?= number_format((int)($row['days_overdue'] ?? 0)) ?>d</td>
                                <?php if ($finesOn): ?><td>PHP <?= number_format((float)($row['fine'] ?? 0), 2) ?></td><?php endif; ?>
                                <td class="overdue-action-col">
                                    <button
                                        type="button"
                                        class="btn-status activate overdue-reminder-btn"
                                        data-record-id="<?= (int)($row['record_id'] ?? 0) ?>"
                                        data-user-id="<?= (int)($row['user_id'] ?? 0) ?>"
                                        title="Send email reminder">
                                        Send Reminder
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $finesOn ? 5 : 4 ?>" class="dashboard-empty-cell">No overdue books right now.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </article>
    <article class="panel glass-card live-activity-panel compact-panel">
        <div class="panel-head">
            <div>
                <h2>Live Kiosk Activity</h2>
                <p class="panel-sub">Latest checkout and status updates</p>
            </div>
        </div>

        <div class="live-activity-feed compact-feed">
            <?php if (!empty($liveActivityRows)): ?>
                <?php foreach ($liveActivityRows as $item): ?>
                    <div class="live-activity-item status-<?= htmlspecialchars($item['status']) ?>">
                        <div class="live-activity-main">
                            <strong><?= htmlspecialchars($item['borrower']) ?></strong>
                            <?= htmlspecialchars($item['action']) ?>
                            <span class="live-activity-book"><?= htmlspecialchars($item['title']) ?></span>
                        </div>
                        <div class="live-activity-meta"><?= htmlspecialchars($item['created_at']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="chart-empty">No kiosk activity yet.</div>
            <?php endif; ?>
        </div>
        </article>
</section>

<section class="dashboard-section-wrap top-borrowers-wrap">
    <section class="dashboard-chart-grid full-width-grid">
        <article id="top-borrowers-panel" class="panel glass-card chart-panel top-borrowers-panel">
            <div class="panel-head search-keywords-head top-borrowers-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('people') ?></span>Top Borrowers</h2>
                    <p class="panel-sub">Students and faculty who borrowed the most</p>
                </div>
                <div class="tb-tabs" role="tablist" aria-label="Top Borrowers period">
                    <?php
                    $tbTabs = ['all' => 'All time', 'month' => 'This month', 'year' => 'This year'];
                    foreach ($tbTabs as $tbKey => $tbLabel):
                        $tbActive = $tbKey === 'all';
                    ?>
                    <button type="button" class="tb-tab<?= $tbActive ? ' is-active' : '' ?>" role="tab" id="tb-tab-<?= $tbKey ?>" aria-controls="tb-list-<?= $tbKey ?>" aria-selected="<?= $tbActive ? 'true' : 'false' ?>" tabindex="<?= $tbActive ? '0' : '-1' ?>" data-tb="<?= $tbKey ?>"><?= $tbLabel ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            $tbEmpty = [
                'all' => 'No borrowing recorded yet. This fills in as students and faculty borrow books.',
                'month' => 'No borrowing recorded yet this month. This fills in as students and faculty borrow books.',
                'year' => 'No borrowing recorded yet this year. This fills in as students and faculty borrow books.',
            ];
            // Inline icons (decorative; aria-hidden on each).
            $tbSvg = [
                'person' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="7.8" r="4.3"/><path d="M3.6 21.2c0-4.7 3.7-7.4 8.4-7.4s8.4 2.7 8.4 7.4c0 .6-.4 1-1 1H4.6c-.6 0-1-.4-1-1z"/></svg>',
                'crown' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 8.2l4.6 3.9L12 4.8l4.4 7.3L21 8.2l-1.9 10.6H4.9L3 8.2z"/><rect x="5" y="19.8" width="14" height="2" rx="1"/></svg>',
                'trophy' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 3h10v5.2a5 5 0 0 1-10 0V3z"/><path d="M7 4.5H4v2.2c0 1.9 1.4 3.3 3.3 3.5l-.4-1.7C6 8.3 5.6 7.7 5.6 6.9V6H7V4.5zM17 4.5h3v2.2c0 1.9-1.4 3.3-3.3 3.5l.4-1.7c.9-.2 1.3-.8 1.3-1.6V6H17V4.5z"/><rect x="11" y="12.5" width="2" height="4"/><rect x="8" y="17" width="8" height="3" rx="1"/></svg>',
                'star' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1.5l2.2 7.3 7.3 2.2-7.3 2.2L12 20.5l-2.2-7.3L2.5 11l7.3-2.2z"/></svg>',
                'cal' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M8 3v4M16 3v4M3.5 10h17"/></svg>',
                'up' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 5l7 11H5z"/></svg>',
            ];
            // Last-borrowed date: "Nov 13", with the year when it is not this year.
            $tbDate = function (string $raw): string {
                $ts = $raw !== '' ? strtotime($raw) : false;
                if (!$ts) {
                    return '';
                }
                return date('Y', $ts) === date('Y') ? date('M j', $ts) : date('M j, Y', $ts);
            };
            foreach ($topBorrowerSets as $tbKey => $tbRows):
                $n = count($tbRows);
                $tbMax = $n > 0 ? (int)$tbRows[0]['borrow_count'] : 0;
                $tbAhead = $n >= 2 ? (int)$tbRows[0]['borrow_count'] - (int)$tbRows[1]['borrow_count'] : 0;
                $tbLayout = $n >= 7 ? 'tb-layout--side' : 'tb-layout--banner';
            ?>
            <div class="tb-tabpanel" role="tabpanel" id="tb-list-<?= $tbKey ?>" aria-labelledby="tb-tab-<?= $tbKey ?>"<?= $tbKey === 'all' ? '' : ' hidden' ?>>
                <?php if (empty($tbRows)): ?>
                    <div class="chart-empty tb-empty"><?= htmlspecialchars($tbEmpty[$tbKey]) ?></div>
                <?php else:
                    $tbTop = $tbRows[0];
                    $tbTopName = trim((string)$tbTop['borrower_name']) !== '' ? (string)$tbTop['borrower_name'] : 'Unnamed borrower';
                    $tbTopMeta = array_values(array_filter([trim((string)$tbTop['borrower_number']), trim((string)$tbTop['program_name'])], 'strlen'));
                    $tbTopCount = (int)$tbTop['borrow_count'];
                    $tbTopLast = $tbDate((string)$tbTop['last_borrowed']);
                ?>
                    <div class="tb-body <?= $tbLayout ?>">
                        <div class="tb-hero">
                            <span class="tb-deco" aria-hidden="true">
                                <span class="tb-glow tb-glow--1"></span><span class="tb-glow tb-glow--2"></span><span class="tb-glow tb-glow--3"></span>
                                <span class="tb-ring-deco tb-ring-deco--1"></span><span class="tb-ring-deco tb-ring-deco--2"></span>
                                <span class="tb-spark tb-spark--1"><?= $tbSvg['star'] ?></span><span class="tb-spark tb-spark--2"><?= $tbSvg['star'] ?></span><span class="tb-spark tb-spark--3"><?= $tbSvg['star'] ?></span><span class="tb-spark tb-spark--4"><?= $tbSvg['star'] ?></span><span class="tb-spark tb-spark--5"><?= $tbSvg['star'] ?></span>
                                <span class="tb-dot tb-dot--1"></span><span class="tb-dot tb-dot--2"></span><span class="tb-dot tb-dot--3"></span>
                            </span>
                            <span class="tb-eyebrow"><?= $tbSvg['trophy'] ?>Top borrower</span>
                            <span class="tb-hero-avatar"><span class="tb-ring"><span class="tb-avin"><?= $tbSvg['person'] ?></span></span><span class="tb-crown"><?= $tbSvg['crown'] ?></span></span>
                            <div class="tb-hero-text">
                                <a class="tb-name" href="borrower_history.php?user_id=<?= (int)$tbTop['user_id'] ?>"><?= htmlspecialchars($tbTopName) ?></a>
                                <?php if ($tbTop['role'] === 'faculty'): ?><span class="tb-chip">Faculty</span><?php endif; ?>
                                <?php if (!empty($tbTopMeta)): ?><div class="tb-meta"><?= htmlspecialchars(implode(' · ', $tbTopMeta)) ?></div><?php endif; ?>
                            </div>
                            <div class="tb-hero-score">
                                <div class="tb-hero-count"><b><?= number_format($tbTopCount) ?></b><span><?= $tbTopCount === 1 ? 'borrow' : 'borrows' ?></span></div>
                                <div class="tb-hero-strip">
                                    <?php if ($tbTopLast !== ''): ?><span class="tb-lb"><?= $tbSvg['cal'] ?>Last borrowed <b><?= htmlspecialchars($tbTopLast) ?></b></span><?php endif; ?>
                                    <?php if ($tbAhead > 0): ?><span class="tb-ahead"><?= $tbSvg['up'] ?><?= number_format($tbAhead) ?> ahead of #2</span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($n > 1): ?>
                        <ol class="tb-list">
                            <?php foreach ($tbRows as $tbIndex => $tbRow):
                                if ($tbIndex === 0) {
                                    continue;
                                }
                                $tbRank = $tbIndex + 1;
                                $tbCount = (int)$tbRow['borrow_count'];
                                $tbPct = $tbMax > 0 ? max(4, (int)round($tbCount / $tbMax * 100)) : 0;
                                $tbMeta = array_values(array_filter([trim((string)$tbRow['borrower_number']), trim((string)$tbRow['program_name'])], 'strlen'));
                                $tbLast = $tbDate((string)$tbRow['last_borrowed']);
                                $tbName = trim((string)$tbRow['borrower_name']) !== '' ? (string)$tbRow['borrower_name'] : 'Unnamed borrower';
                                $tbTint = ($tbIndex % 6) + 1;
                            ?>
                            <li class="tb-row">
                                <span class="tb-rank tb-rank--<?= $tbRank ?>" aria-label="Rank <?= $tbRank ?>"><?= $tbRank ?></span>
                                <div class="tb-who">
                                    <span class="tb-av tb-av--<?= $tbTint ?>"><?= $tbSvg['person'] ?></span>
                                    <div class="tb-who-text">
                                        <div class="tb-name-line">
                                            <a class="tb-name" href="borrower_history.php?user_id=<?= (int)$tbRow['user_id'] ?>"><?= htmlspecialchars($tbName) ?></a>
                                            <?php if ($tbRow['role'] === 'faculty'): ?><span class="tb-chip">Faculty</span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($tbMeta)): ?><div class="tb-meta"><?= htmlspecialchars(implode(' · ', $tbMeta)) ?></div><?php endif; ?>
                                    </div>
                                </div>
                                <div class="tb-score">
                                    <span class="tb-count" aria-label="<?= number_format($tbCount) ?> <?= $tbCount === 1 ? 'borrow' : 'borrows' ?>"><?= number_format($tbCount) ?></span>
                                    <div class="tb-bar" aria-hidden="true"><span style="width: <?= $tbPct ?>%"></span></div>
                                </div>
                                <div class="tb-last"><?php if ($tbLast !== ''): ?><?= $tbSvg['cal'] ?><?= htmlspecialchars($tbLast) ?><?php endif; ?></div>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="tb-foot">
                <span>Students and faculty only. Ties go to the most recent borrower.</span>
                <a class="tb-report-link" href="reports.php?quick_type=top_borrowers">Open full report<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
            </div>
        </article>
    </section>
</section>
<script>
(function () {
    var panel = document.getElementById("top-borrowers-panel");
    if (!panel) return;
    var tabs = Array.prototype.slice.call(panel.querySelectorAll('.tb-tab[role="tab"]'));
    function select(tab, focus) {
        tabs.forEach(function (t) {
            var on = t === tab;
            t.classList.toggle("is-active", on);
            t.setAttribute("aria-selected", on ? "true" : "false");
            t.setAttribute("tabindex", on ? "0" : "-1");
            var list = document.getElementById(t.getAttribute("aria-controls"));
            if (list) list.hidden = !on;
        });
        if (focus) tab.focus();
    }
    tabs.forEach(function (tab, i) {
        tab.addEventListener("click", function () { select(tab, false); });
        tab.addEventListener("keydown", function (e) {
            var next = null;
            if (e.key === "ArrowRight") next = tabs[(i + 1) % tabs.length];
            else if (e.key === "ArrowLeft") next = tabs[(i - 1 + tabs.length) % tabs.length];
            else if (e.key === "Home") next = tabs[0];
            else if (e.key === "End") next = tabs[tabs.length - 1];
            if (next) { e.preventDefault(); select(next, true); }
        });
    });
})();
</script>

<section class="dashboard-section-wrap analytics-feature-wrap">
    <section class="dashboard-chart-grid full-width-grid analytics-feature-row">
        <article class="panel glass-card chart-panel feature-chart-panel search-trend-panel">
            <div class="panel-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('search') ?></span>Search Trends Over Time</h2>
                    <p class="panel-sub">Searches per day over the last 14 days</p>
                </div>
            </div>
            <div id="search-trend-chart" class="chart-shell feature-chart-shell"></div>

            <div id="search-trend-insight" class="trend-insight">
                Search insight will appear here once trend data is loaded.
            </div>
        </article>
    </section>
</section>

<section class="dashboard-section-wrap analytics-middle-wrap">
    <section class="dashboard-chart-grid equal-grid keyword-borrow-row">
                <article class="panel glass-card chart-panel search-keywords-panel">
            <div class="panel-head search-keywords-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('list') ?></span>Top Search Keywords</h2>
                    <p class="panel-sub">Normalized intents from live user searches</p>
                </div>
                <div class="search-smart-chip" id="search-keywords-chip">Calculating...</div>
            </div>
            <div id="search-keywords-chart" class="chart-shell compact-chart"></div>
            <div id="search-keywords-insight" class="search-keywords-insight">
                Smart keyword insight will appear here once data is loaded.
            </div>
        </article>

        <article class="panel glass-card chart-panel borrowed-books-panel">
            <div class="panel-head search-keywords-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('book-open') ?></span>Top 10 Borrowed Books</h2>
                    <p class="panel-sub">Most borrowed books across all records</p>
                </div>
                <div class="search-smart-chip borrow-smart-chip" id="borrowed-books-chip">Calculating...</div>
            </div>
            <div id="most-borrowed-top-chart" class="chart-shell"></div>
            <div id="most-borrowed-insight" class="search-keywords-insight borrowed-books-insight">
                Borrowing insight will appear here once data is loaded.
            </div>
        </article>
    </section>
</section>

<section class="dashboard-section-wrap borrow-trend-wrap">
    <section class="dashboard-chart-grid full-width-grid borrow-trend-row">
        <article class="panel glass-card chart-panel feature-chart-panel borrow-trend-panel">
            <div class="panel-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('swap') ?></span>Borrow Trend Over Time</h2>
                    <p class="panel-sub">Daily borrowing activity over the last 14 days</p>
                </div>
            </div>
            <div id="borrow-trend-chart" class="chart-shell feature-chart-shell"></div>
            <div id="borrow-trend-insight" class="trend-insight">Borrowing insight will appear here once trend data is loaded.</div>
        </article>
    </section>
</section>

<section class="dashboard-section-wrap">
    <div class="section-heading">Course-Based Recommendations</div>

    <section class="dashboard-chart-grid equal-grid">
        <article class="panel glass-card chart-panel course-popularity-panel">
            <div class="panel-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('layers') ?></span>Books per Course Popularity</h2>
                    <p class="panel-sub">Titles per course (bar length) and how many have been borrowed at least once</p>
                </div>
            </div>
            <div id="course-popularity-stacked-chart" class="chart-shell"></div>
        </article>

        <article class="panel glass-card chart-panel course-activity-panel">
            <div class="panel-head">
                <div>
                    <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('activity') ?></span>Course Borrowing Activity</h2>
                    <p class="panel-sub">Total borrow transactions per course/program</p>
                </div>
            </div>
            <div id="course-activity-chart" class="chart-shell"></div>
        </article>
    </section>

    <section class="panel glass-card recommendation-panel">
    <div class="panel-head recommendation-head">
        <div>
            <div class="recommendation-kicker">Circulation Leaders</div>
            <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('books') ?></span>Top Borrowed Book per Program</h2>
            <p class="panel-sub">The most-borrowed title in each program with checkouts, by all-time borrow count (ties shown)</p>
        </div>
        <div class="recommendation-chip">Basis: All-Time Borrow Count</div>
    </div>

    <div class="recommendation-grid" style="--rec-cols: <?= count($recommendationCards) <= 4 ? max(1, count($recommendationCards)) : (count($recommendationCards) <= 6 ? 3 : 4) ?>;">
        <?php if (!empty($recommendationCards)): ?>
            <?php foreach ($recommendationCards as $card): ?>
                <?php
                    $borrowCount = (int)($card['borrow_count'] ?? 0);
                    $programBorrows = (int)($card['program_borrows'] ?? 0);
                    $tiedTitles = (array)($card['tied_titles'] ?? []);
                    $tieCount = count($tiedTitles);
                    $sharePct = $programBorrows > 0 ? (int)round($borrowCount / $programBorrows * 100) : 0;
                ?>
                <article class="recommendation-card<?= $tieCount > 0 ? ' is-tied' : '' ?>">
                    <div class="recommendation-course"><?= htmlspecialchars($card['program_name']) ?></div>
                    <div class="recommendation-title" title="<?= htmlspecialchars($card['title']) ?>"><?= htmlspecialchars($card['title']) ?></div>
                    <?php if ($tieCount > 0): ?>
                        <div class="recommendation-tie" title="<?= htmlspecialchars('Also ' . number_format($borrowCount) . ' borrow' . ($borrowCount === 1 ? '' : 's') . ': ' . implode('; ', $tiedTitles)) ?>">
                            Tied with <?= $tieCount ?> other title<?= $tieCount === 1 ? '' : 's' ?>:
                            <span><?= htmlspecialchars(implode(' · ', $tiedTitles)) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="recommendation-stats">
                        <div class="recommendation-count"><strong><?= number_format($borrowCount) ?></strong> borrow<?= $borrowCount === 1 ? '' : 's' ?></div>
                        <?php if ($programBorrows > 0): ?>
                            <div class="recommendation-share">
                                <?php if ($programBorrows === 1): ?>
                                    Only borrow in this program so far
                                <?php else: ?>
                                    <?= $sharePct ?>% of <?= number_format($programBorrows) ?> program borrows
                                <?php endif; ?>
                            </div>
                            <div class="recommendation-share-bar" aria-hidden="true"><span style="width: <?= $sharePct ?>%;"></span></div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="chart-empty">No borrowing activity recorded yet.</div>
        <?php endif; ?>
    </div>
</section>
</section>

<script id="dashboard-data" type="application/json"><?= $chartJson ?: '{}' ?></script>
<?php $dashJsVer = @filemtime(__DIR__ . '/../assets/javascript/admin_dashboard.js') ?: time(); ?>
<script src="../assets/javascript/admin_dashboard.js?v=<?= $dashJsVer ?>"></script>

<?php require 'layout_bottom.php'; ?>
