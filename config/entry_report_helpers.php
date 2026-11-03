<?php
/**
 * Counts behind the library entry report (admin/reports.php).
 *
 * Provisional: the librarian's sample report hasn't arrived, so the breakdowns
 * follow her email (hour of day, course, gender, year level). To keep the
 * display easy to change, everything is built from one fine-grained query
 * (entry_report_fetch_rows); each breakdown is a rollup of those same rows,
 * so they can't disagree with each other and all add up to the total. A
 * cross-tab such as course by gender would be one more rollup, not new SQL.
 *
 * Each log row keeps the person's program, year and gender as they were at
 * scan time (see entry_log_helpers.php), so students moving up a year don't
 * rewrite past reports. library_users only fills in what the row left blank;
 * anything still blank counts under "Not specified" rather than being dropped.
 */
require_once __DIR__ . '/library_card_helpers.php';

const ENTRY_REPORT_NOT_SPECIFIED = 'Not specified';

/**
 * Entry counts for a date range (both ends inclusive), one row per distinct
 * combination of hour, program, year level and gender. Null on a query error,
 * so the page can say so instead of showing zeros.
 */
function entry_report_fetch_rows(mysqli $conn, string $dateFrom, string $dateTo): ?array {
    try {
        $stmt = $conn->prepare(
            "SELECT HOUR(e.created_at) AS hour_of_day,
                    p.program_name,
                    COALESCE(e.year_level, u.year_level) AS entry_year,
                    COALESCE(e.gender, u.gender) AS entry_gender,
                    COUNT(*) AS total
             FROM library_entry_logs e
             LEFT JOIN library_users u ON u.user_id = e.user_id
             LEFT JOIN programs p ON p.program_id = COALESCE(e.program_id, u.program_id)
             WHERE e.created_at >= ? AND e.created_at < DATE_ADD(?, INTERVAL 1 DAY)
             GROUP BY hour_of_day, p.program_name, entry_year, entry_gender"
        );
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        return null;
    }
}

/** Different people in the range; one person entering twice is two entries. */
function entry_report_count_people(mysqli $conn, string $dateFrom, string $dateTo): ?int {
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(DISTINCT user_number) FROM library_entry_logs
             WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)"
        );
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count;
    } catch (Throwable $e) {
        return null;
    }
}

/** Totals per value of one column of the fetched rows, as [value => total]. */
function entry_report_rollup(array $rows, string $column): array {
    $totals = [];
    foreach ($rows as $row) {
        $key = (string)($row[$column] ?? '');
        $totals[$key] = ($totals[$key] ?? 0) + (int)$row['total'];
    }
    return $totals;
}

/** 8 -> "8:00 - 8:59 AM" */
function entry_report_hour_label(int $hour): string {
    $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;
    return sprintf('%d:00 - %d:59 %s', $hour12, $hour12, $hour < 12 ? 'AM' : 'PM');
}

/** First to last busy hour, including quiet hours in between. */
function entry_report_by_hour(array $rows): array {
    $totals = entry_report_rollup($rows, 'hour_of_day');
    if ($totals === []) {
        return [];
    }
    $result = [];
    for ($hour = min(array_keys($totals)); $hour <= max(array_keys($totals)); $hour++) {
        $result[] = ['label' => entry_report_hour_label($hour), 'total' => $totals[$hour] ?? 0];
    }
    return $result;
}

/** Busiest first, with "Not specified" always last. */
function entry_report_by_program(array $rows): array {
    $totals = entry_report_rollup($rows, 'program_name');
    $notSpecified = $totals[''] ?? 0;
    unset($totals['']);
    arsort($totals);
    $result = [];
    foreach ($totals as $name => $total) {
        $result[] = ['label' => (string)$name, 'total' => $total];
    }
    $result[] = ['label' => ENTRY_REPORT_NOT_SPECIFIED, 'total' => $notSpecified];
    return $result;
}

/** Every option from the signup form, then "Not specified". */
function entry_report_by_gender(array $rows): array {
    $totals = entry_report_rollup($rows, 'entry_gender');
    $result = [];
    foreach (LIBRARY_CARD_GENDERS as $value => $label) {
        $result[] = ['label' => $label, 'total' => $totals[$value] ?? 0];
    }
    $notSpecified = $totals[''] ?? 0;
    $result[] = ['label' => ENTRY_REPORT_NOT_SPECIFIED, 'total' => $notSpecified];
    return $result;
}

/**
 * Every signup-form year, then any other stored year (the admin user form
 * allows a 5th year, which the signup form doesn't list), then "Not specified".
 * Faculty have no year level, so they count as "Not specified" here.
 */
function entry_report_by_year(array $rows): array {
    $totals = entry_report_rollup($rows, 'entry_year');
    $notSpecified = $totals[''] ?? 0;
    unset($totals['']);
    $years = array_unique(array_merge(array_keys(LIBRARY_CARD_YEAR_LEVELS), array_map('intval', array_keys($totals))));
    sort($years);
    $result = [];
    foreach ($years as $year) {
        $result[] = ['label' => LIBRARY_CARD_YEAR_LEVELS[$year] ?? ('Year ' . $year), 'total' => $totals[$year] ?? 0];
    }
    $result[] = ['label' => ENTRY_REPORT_NOT_SPECIFIED, 'total' => $notSpecified];
    return $result;
}

/**
 * The whole report, ready to display. 'sections' is the one list the page,
 * the preview and the PDF all loop over, so reshaping the report for her
 * sample means changing this list rather than three displays.
 */
function entry_report_build(mysqli $conn, string $dateFrom, string $dateTo): array {
    $rows = entry_report_fetch_rows($conn, $dateFrom, $dateTo);
    $people = entry_report_count_people($conn, $dateFrom, $dateTo);
    if ($rows === null || $people === null) {
        return ['ok' => false, 'total' => 0, 'people' => 0, 'sections' => []];
    }
    return [
        'ok' => true,
        'total' => array_sum(array_column($rows, 'total')),
        'people' => $people,
        'sections' => [
            ['title' => 'Entries by Hour of Day', 'column' => 'Hour', 'rows' => entry_report_by_hour($rows)],
            ['title' => 'Entries by Course', 'column' => 'Course', 'rows' => entry_report_by_program($rows)],
            ['title' => 'Entries by Gender', 'column' => 'Gender', 'rows' => entry_report_by_gender($rows)],
            ['title' => 'Entries by Year Level', 'column' => 'Year Level', 'rows' => entry_report_by_year($rows)],
        ],
    ];
}
