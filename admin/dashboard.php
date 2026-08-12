<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';

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

function table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = safe_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
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

$totalBooks = safe_scalar_int($conn, 'SELECT COUNT(*) FROM books');
$totalStudents = safe_scalar_int($conn, "SELECT COUNT(*) FROM library_users WHERE role = 'student'");
$totalFaculty = safe_scalar_int($conn, "SELECT COUNT(*) FROM library_users WHERE role = 'faculty'");
$totalBorrows = safe_scalar_int($conn, 'SELECT COUNT(*) FROM borrow_records');
$totalPrograms = safe_scalar_int($conn, 'SELECT COUNT(*) FROM programs');

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

$recommendationCards = [];
if (!empty($courseLabels)) {
    $courseBestBookRes = safe_query(
        $conn,
        "
        SELECT
            p.program_name,
            COALESCE(b.title, '') AS title,
            COUNT(br.record_id) AS borrow_count,
            MAX(COALESCE(br.date_borrowed, DATE(br.created_at))) AS last_borrow,
            MAX(b.created_at) AS latest_added
        FROM programs p
        INNER JOIN books b ON b.program_id = p.program_id
        LEFT JOIN book_copies bc ON bc.book_id = b.book_id
        LEFT JOIN borrow_records br ON br.copy_id = bc.copy_id
        GROUP BY p.program_id, p.program_name, b.book_id, b.title
        ORDER BY p.program_name ASC, borrow_count DESC, last_borrow DESC, latest_added DESC, b.title ASC
        "
    );

    $bestByProgram = [];
    if ($courseBestBookRes && $courseBestBookRes->num_rows > 0) {
        while ($row = $courseBestBookRes->fetch_assoc()) {
            $program = trim((string)($row['program_name'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($program === '' || $title === '' || isset($bestByProgram[$program])) {
                continue;
            }

            $bestByProgram[$program] = [
                'title' => $title,
                'borrow_count' => (int)($row['borrow_count'] ?? 0)
            ];
        }
    }

    for ($i = 0; $i < count($courseLabels); $i++) {
        $program = $courseLabels[$i];
        if (!isset($bestByProgram[$program])) {
            continue;
        }

        $bookInfo = $bestByProgram[$program];
        $recommendationCards[] = [
            'program_name' => $program,
            'title' => (string)$bookInfo['title'],
            'borrow_count' => (int)$bookInfo['borrow_count']
        ];

        if (count($recommendationCards) >= 6) {
            break;
        }
    }
}

if (empty($recommendationCards) && !empty($mostBorrowedRows)) {
    foreach (array_slice($mostBorrowedRows, 0, 4) as $row) {
        $recommendationCards[] = [
            'program_name' => 'General',
            'title' => (string)$row['title'],
            'borrow_count' => (int)$row['borrow_count']
        ];
    }
}

$recommendationMaxBorrows = 1;
if (!empty($recommendationCards)) {
    $recommendationMaxBorrows = max(
        1,
        ...array_map(
            static fn(array $card): int => (int)($card['borrow_count'] ?? 0),
            $recommendationCards
        )
    );
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
        <h1>Dashboard</h1>
        <p class="page-subtitle">Admin view with new arrivals, search behavior, borrowing trends, and course-based recommendations.</p>
    </div>
    <div class="welcome">Welcome, <?= htmlspecialchars($_SESSION['name'] ?? 'Librarian') ?>!</div>
</div>

<section class="stats-grid dashboard-metrics compact-metrics">
    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Total Books</div>
        <div class="metric-value"><?= number_format($totalBooks) ?></div>
        <div class="metric-meta">Catalog titles in collection</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Total Students</div>
        <div class="metric-value"><?= number_format($totalStudents) ?></div>
        <div class="metric-meta">Registered student accounts</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Total Faculty</div>
        <div class="metric-value"><?= number_format($totalFaculty) ?></div>
        <div class="metric-meta">Registered faculty accounts</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Total Borrows</div>
        <div class="metric-value"><?= number_format($totalBorrows) ?></div>
        <div class="metric-meta">All-time borrow transactions</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Programs</div>
        <div class="metric-value"><?= number_format($totalPrograms) ?></div>
        <div class="metric-meta">All listed courses/programs</div>
    </article>
</section>

<section class="dashboard-chart-grid arrival-activity-row"> 
    <article class="panel glass-card chart-panel compact-panel overdue-books-panel">
        <div class="panel-head">
            <div>
                <h2>Overdue Books</h2>
                <p class="panel-sub">Borrowers with overdue returns and quick email reminders</p>
            </div>
        </div>

        <div class="table-wrap compact-table-wrap overdue-table-wrap">
            <table class="data-table insight-table overdue-preview-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Book Title</th>
                        <th>Due Date</th>
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
                                <td><?= htmlspecialchars((string)($row['borrower'] ?? 'Unknown User')) ?></td>
                                <td><?= htmlspecialchars((string)($row['title'] ?? 'Unknown Book')) ?></td>
                                <td><?= htmlspecialchars($dueDisplay) ?></td>
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
                            <td colspan="4" class="dashboard-empty-cell">No overdue books right now.</td>
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
                    <div class="live-activity-item">
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

<section class="dashboard-section-wrap analytics-feature-wrap">
    <section class="dashboard-chart-grid full-width-grid analytics-feature-row">
        <article class="panel glass-card chart-panel feature-chart-panel">
            <div class="panel-head">
                <div>
                    <h2>Search Trends Over Time</h2>
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
                    <h2>Top Search Keywords</h2>
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
                    <h2>Top 10 Borrowed Books</h2>
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
        <article class="panel glass-card chart-panel feature-chart-panel">
            <div class="panel-head">
                <div>
                    <h2>Borrow Trend Over Time</h2>
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
        <article class="panel glass-card chart-panel">
            <div class="panel-head">
                <div>
                    <h2>Books per Course Popularity</h2>
                    <p class="panel-sub">Stacked bar chart: borrowed titles vs not-yet-borrowed titles per course</p>
                </div>
            </div>
            <div id="course-popularity-stacked-chart" class="chart-shell"></div>
        </article>

        <article class="panel glass-card chart-panel">
            <div class="panel-head">
                <div>
                    <h2>Course Borrowing Activity</h2>
                    <p class="panel-sub">Total borrow transactions per course/program</p>
                </div>
            </div>
            <div id="course-activity-chart" class="chart-shell"></div>
        </article>
    </section>

    <section class="panel glass-card recommendation-panel">
    <div class="panel-head recommendation-head">
        <div>
            <div class="recommendation-kicker"><span class="ai-pulse-dot" aria-hidden="true"></span>Local Recommendation Engine - Live Signals</div>
            <h2>AI Recommendation Output</h2>
            <p class="panel-sub">Card output format for course-specific suggested books</p>
        </div>
        <div class="recommendation-chip">Engine: Local Weighted Rules (v3)</div>
    </div>

    <div class="recommendation-grid">
        <?php if (!empty($recommendationCards)): ?>
            <?php foreach ($recommendationCards as $card): ?>
                <?php
                    $borrowCount = (int)($card['borrow_count'] ?? 0);
                    $signalPct = (int)round(($borrowCount / max(1, $recommendationMaxBorrows)) * 100);
                    $signalPct = max(6, min(100, $signalPct));
                    $confidenceClass = 'emerging';
                    $confidenceLabel = 'Early Trend';
                    $reasonText = 'Early-match candidate based on current course patterns.';
                    if ($signalPct >= 80) {
                        $confidenceClass = 'high';
                        $confidenceLabel = 'High Demand';
                        $reasonText = 'Strong circulation momentum and repeat borrower interest.';
                    } elseif ($signalPct >= 45) {
                        $confidenceClass = 'medium';
                        $confidenceLabel = 'Moderate Demand';
                        $reasonText = 'Steady demand with consistent course-aligned borrowing.';
                    }
                ?>
                <article class="recommendation-card recommendation-card--<?= htmlspecialchars($confidenceClass) ?>">
                    <div class="recommendation-card-top">
                        <div class="recommendation-course">Recommended for <?= htmlspecialchars($card['program_name']) ?></div>
                        <span class="recommendation-confidence recommendation-confidence--<?= htmlspecialchars($confidenceClass) ?>"><?= htmlspecialchars($confidenceLabel) ?></span>
                    </div>
                    <div class="recommendation-title"><?= htmlspecialchars($card['title']) ?></div>
                    <div class="recommendation-reason"><?= htmlspecialchars($reasonText) ?></div>
                    <div class="recommendation-signal-row">
                        <span>Recommendation Score</span>
                        <span><?= (int)$signalPct ?>%</span>
                    </div>
                    <div class="recommendation-signal-track"><span style="width: <?= (int)$signalPct ?>%;"></span></div>
                    <div class="recommendation-meta"><?= number_format($borrowCount) ?> total borrows</div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="chart-empty">No recommendation-ready data yet.</div>
        <?php endif; ?>
    </div>
</section>
</section>

<script id="dashboard-data" type="application/json"><?= $chartJson ?: '{}' ?></script>
<?php $dashJsVer = @filemtime(__DIR__ . '/../assets/javascript/admin_dashboard.js') ?: time(); ?>
<script src="../assets/javascript/admin_dashboard.js?v=<?= $dashJsVer ?>"></script>

<?php require 'layout_bottom.php'; ?>


























