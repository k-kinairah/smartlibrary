<?php
/**
 * Display helpers for the Quick Report "View" modal (admin/reports.php).
 *
 * Presentation only: everything here works on rows reports.php has already
 * fetched for its tables (daily trend rows, totals, top books, entry
 * breakdowns). It picks out peaks and builds sentences; it runs no queries.
 */

/** "month", "week" ... for sentences like "No searches recorded yet this month." */
function reports_modal_period_word(string $period): string {
    $words = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'quarterly' => 'quarter', 'yearly' => 'year'];
    return $words[$period] ?? 'period';
}

/** 2026-11-01..2026-11-30 -> "Nov 1 – 30"; across months/years it spells out more. */
function reports_modal_short_range(string $from, string $to): string {
    $a = DateTime::createFromFormat('!Y-m-d', $from);
    $b = DateTime::createFromFormat('!Y-m-d', $to);
    if (!$a || !$b) {
        return $from . ' – ' . $to;
    }
    if ($from === $to) {
        return $a->format('M j, Y');
    }
    if ($a->format('Y-m') === $b->format('Y-m')) {
        return $a->format('M j') . ' – ' . $b->format('j');
    }
    if ($a->format('Y') === $b->format('Y')) {
        return $a->format('M j') . ' – ' . $b->format('M j');
    }
    return $a->format('M j, Y') . ' – ' . $b->format('M j, Y');
}

/**
 * Daily rows (bucket_label = Y-m-d, total) summed into chart bars across the
 * whole range, empty buckets included. The bar size follows the range: days
 * for up to two weeks, weeks for up to about a quarter, then months.
 */
function reports_modal_buckets(array $dailyRows, string $from, string $to): array {
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$start || !$end || $end < $start) {
        return ['unit' => 'day', 'bars' => [], 'peak' => null];
    }
    $days = (int)$start->diff($end)->days + 1;
    $unit = $days <= 14 ? 'day' : ($days <= 92 ? 'week' : 'month');

    $keyOf = static function (DateTimeImmutable $d) use ($unit): string {
        if ($unit === 'day') {
            return $d->format('Y-m-d');
        }
        if ($unit === 'week') {
            return $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d');
        }
        return $d->format('Y-m');
    };

    $multiYear = $start->format('Y') !== $end->format('Y');
    $bars = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $key = $keyOf($d);
        if (!isset($bars[$key])) {
            if ($unit === 'day') {
                $label = $d->format('D j');
                $long = $d->format('l, M j');
            } elseif ($unit === 'week') {
                $weekStart = DateTimeImmutable::createFromFormat('!Y-m-d', $key);
                $label = $weekStart->format('M j');
                $long = 'the week of ' . $weekStart->format('M j');
            } else {
                $label = $d->format($multiYear ? 'M y' : 'M');
                $long = $d->format($multiYear ? 'F Y' : 'F');
            }
            $bars[$key] = ['label' => $label, 'long' => $long, 'total' => 0];
        }
    }
    foreach ($dailyRows as $row) {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($row['bucket_label'] ?? ''));
        if ($day) {
            $key = $keyOf($day);
            if (isset($bars[$key])) {
                $bars[$key]['total'] += (int)($row['total'] ?? 0);
            }
        }
    }
    $bars = array_values($bars);
    $peak = null;
    foreach ($bars as $i => $bar) {
        if ($bar['total'] > 0 && ($peak === null || $bar['total'] > $bars[$peak]['total'])) {
            $peak = $i;
        }
    }
    return ['unit' => $unit, 'bars' => $bars, 'peak' => $peak];
}

/**
 * The busiest single day, and how it compares with a typical active day
 * (the median). The ratio is only given when it says something: at least
 * three active days and a peak at least twice the median.
 */
function reports_modal_peak_day(array $dailyRows): ?array {
    $counts = [];
    $best = null;
    foreach ($dailyRows as $row) {
        $total = (int)($row['total'] ?? 0);
        if ($total <= 0) {
            continue;
        }
        $counts[] = $total;
        if ($best === null || $total > $best['total']) {
            $best = ['date' => (string)$row['bucket_label'], 'total' => $total];
        }
    }
    if ($best === null) {
        return null;
    }
    sort($counts);
    $n = count($counts);
    $median = $n % 2 ? $counts[intdiv($n, 2)] : ($counts[$n / 2 - 1] + $counts[$n / 2]) / 2;
    $ratio = $median > 0 ? $best['total'] / $median : 0;
    $best['ratio'] = ($n >= 3 && $ratio >= 2) ? (int)round($ratio) : null;
    $date = DateTime::createFromFormat('!Y-m-d', $best['date']);
    $best['label'] = $date ? $date->format('M j') : $best['date'];
    return $best;
}

/** "Search activity peaked in September — Sep 30 alone had 86 searches, about 9× a typical day." */
function reports_modal_peak_sentence(string $what, string $noun, array $chart, ?array $peakDay): string {
    if ($peakDay === null || $chart['peak'] === null) {
        return '';
    }
    $times = $peakDay['ratio'] ? ', about ' . $peakDay['ratio'] . '× a typical day' : '';
    if ($chart['unit'] === 'day') {
        return $what . ' peaked on ' . $chart['bars'][$chart['peak']]['long'] . ', with ' . number_format($peakDay['total']) . ' ' . $noun . $times . '.';
    }
    return $what . ' peaked in ' . $chart['bars'][$chart['peak']]['long'] . ' — ' . $peakDay['label'] . ' alone had '
        . number_format($peakDay['total']) . ' ' . $noun . $times . '.';
}

/** "12 books borrowed, 9 returned, 2 overdue." with zero clauses left out. */
function reports_modal_borrow_sentence(array $totals, string $periodWord): string {
    $total = (int)($totals['total'] ?? 0);
    if ($total === 0) {
        return '';
    }
    $parts = [number_format($total) . ' ' . ($total === 1 ? 'book' : 'books') . ' borrowed'];
    foreach (['returned' => 'returned', 'overdue' => 'overdue', 'missing' => 'missing'] as $key => $word) {
        if ((int)($totals[$key] ?? 0) > 0) {
            $parts[] = number_format((int)$totals[$key]) . ' ' . $word;
        }
    }
    return implode(', ', $parts) . ' this ' . $periodWord . '.';
}

function reports_modal_empty_text(string $what, string $periodWord): string {
    return 'No ' . $what . ' recorded yet this ' . $periodWord . '. This section fills in automatically as entries come in.';
}

function reports_modal_empty(string $text): void {
    ?>
    <p class="reports-modal-empty"><?= htmlspecialchars($text) ?></p>
    <?php
}

/** A column chart of the buckets, with the raw daily rows folded away below it. */
function reports_modal_chart(string $title, string $tone, array $chart, array $dailyRows, string $emptyText): void {
    $max = 1;
    foreach ($chart['bars'] as $bar) {
        $max = max($max, $bar['total']);
    }
    ?>
    <section class="reports-modal-card">
        <div class="reports-modal-card-head">
            <h3><?= htmlspecialchars($title) ?></h3>
            <?php if ($chart['peak'] !== null): ?>
                <span class="reports-modal-peak">&#9733; peak: <?= htmlspecialchars($chart['bars'][$chart['peak']]['label']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($chart['peak'] === null): ?>
            <?php reports_modal_empty($emptyText); ?>
        <?php else: ?>
            <div class="reports-modal-columns <?= $tone ?>">
                <?php foreach ($chart['bars'] as $i => $bar): ?>
                    <?php $pct = $bar['total'] > 0 ? max(3, (int)round($bar['total'] / $max * 100)) : 0; ?>
                    <div class="reports-modal-column" title="<?= htmlspecialchars($bar['long'] . ': ' . number_format($bar['total'])) ?>">
                        <span class="reports-modal-column-bar<?= $i === $chart['peak'] ? ' is-peak' : '' ?>" style="height: <?= $pct ?>%"></span>
                        <span class="reports-modal-column-label"><?= htmlspecialchars($bar['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <details class="reports-modal-daily">
                <summary><span class="reports-modal-daily-chevron" aria-hidden="true">&#9656;</span>Show daily breakdown</summary>
                <div class="table-wrap">
                    <table class="data-table reports-mini-table">
                        <thead><tr><th>Day</th><th>Count</th></tr></thead>
                        <tbody>
                            <?php foreach ($dailyRows as $row): ?>
                                <tr><td><?= htmlspecialchars((string)$row['bucket_label']) ?></td><td><?= number_format((int)$row['total']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * Horizontal bars: label, bar relative to the largest value, count. Rows
 * marked 'muted' (e.g. "Not specified") are greyed out. $ranked numbers them.
 * With $shareOf, each count also shows its share of that total ("5 · 56%"),
 * and the largest real category is picked out.
 */
function reports_modal_bar_rows(array $rows, string $tone, bool $ranked, ?int $shareOf = null): void {
    $max = 1;
    $topIndex = null;
    foreach ($rows as $i => $row) {
        $max = max($max, (int)$row['total']);
        if ($shareOf !== null && empty($row['muted']) && (int)$row['total'] > 0
            && ($topIndex === null || (int)$row['total'] > (int)$rows[$topIndex]['total'])) {
            $topIndex = $i;
        }
    }
    ?>
    <ol class="reports-modal-bars <?= $tone ?><?= $ranked ? ' is-ranked' : '' ?><?= $shareOf !== null ? ' has-share' : '' ?>">
        <?php foreach ($rows as $i => $row): ?>
            <?php
                $value = (int)$row['total'];
                $pct = $value > 0 ? max(3, (int)round($value / $max * 100)) : 0;
                $classes = trim((!empty($row['muted']) ? 'is-muted ' : '') . ($i === $topIndex ? 'is-top' : ''));
            ?>
            <li class="<?= $classes ?>">
                <?php if ($ranked): ?><span class="reports-modal-rank"><?= $i + 1 ?></span><?php endif; ?>
                <span class="reports-modal-bar-label" title="<?= htmlspecialchars((string)$row['label']) ?>"><?= htmlspecialchars((string)$row['label']) ?></span>
                <span class="reports-modal-bar-track"><span class="reports-modal-bar-fill" style="width: <?= $pct ?>%"></span></span>
                <span class="reports-modal-bar-value"><?= number_format($value) ?><?php if ($shareOf): ?><small><?= (int)round($value / $shareOf * 100) ?>%</small><?php endif; ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php
}

/**
 * "8:00 - 8:59 PM" -> "8 PM", for a headline tile. Falls back to the label.
 */
function reports_entry_short_hour(string $label): string {
    return preg_match('/^(\d{1,2}):00 - .* (AM|PM)$/', $label, $m) ? $m[1] . ' ' . $m[2] : $label;
}

/**
 * The entry summary as HTML with its key facts bolded: the counts, the busiest
 * hour and the most common course. Escapes first, so it stays safe.
 */
function reports_entry_summary_html(string $text): string {
    $html = htmlspecialchars($text);
    $html = preg_replace('/^([\d,]+ (?:entry|entries)) from ([\d,]+ (?:person|people))/u', '<strong>$1</strong> from <strong>$2</strong>', $html);
    $html = preg_replace('/(between |Busiest hour: )([^,—.]+?(?:AM|PM))/u', '$1<strong>$2</strong>', $html);
    $html = preg_replace('/(Most common course: )(.+?)\.$/u', '$1<strong>$2</strong>.', $html);
    return (string)$html;
}

/** The busiest hour row of an entry report, or null when there are none. */
function reports_entry_busiest_hour(array $entryReport): ?array {
    $best = null;
    foreach ($entryReport['sections'][0]['rows'] ?? [] as $row) {
        if ((int)$row['total'] > 0 && ($best === null || (int)$row['total'] > (int)$best['total'])) {
            $best = $row;
        }
    }
    return $best;
}

/**
 * The entry summary minus its opening "9 entries from 3 people · <range>."
 * clause, for places where tiles already show those numbers.
 */
function reports_entry_highlights(array $entryReport, string $from, string $to, string $periodWord): array {
    $summary = reports_entry_summary($entryReport, $from, $to, $periodWord);
    $rest = $summary['empty'] ? '' : (string)preg_replace('/^.*?\d{4}\.\s*/u', '', $summary['text']);
    return ['text' => $rest !== '' ? $rest : $summary['text'], 'empty' => $summary['empty']];
}

/**
 * One entry breakdown as bar rows. Categories with entries are shown ("Not
 * specified" greyed out); the zero ones fold away behind a toggle.
 */
function reports_entry_breakdown(array $rows, ?int $total = null): void {
    $shown = [];
    $zeros = [];
    foreach ($rows as $row) {
        $isNotSpecified = $row['label'] === ENTRY_REPORT_NOT_SPECIFIED;
        if ((int)$row['total'] > 0) {
            $shown[] = $row + ['muted' => $isNotSpecified];
        } else {
            $zeros[] = $row + ['muted' => true];
        }
    }
    reports_modal_bar_rows($shown, 'is-green', false, $total);
    if ($zeros) {
        ?>
        <details class="reports-entries-zeros">
            <summary>+ <?= count($zeros) ?> more with 0 entries</summary>
            <?php reports_modal_bar_rows($zeros, 'is-green', false, $total); ?>
        </details>
        <?php
    }
}

/**
 * The line above the breakdowns: "9 entries from 3 people · Nov 1 – 30, 2026.
 * All 9 happened between 8:00 - 8:59 PM — the only hour with any activity
 * this period." Built from the totals and the hour breakdown.
 */
function reports_entry_summary(array $entryReport, string $from, string $to, string $periodWord): array {
    if (empty($entryReport['ok'])) {
        return ['text' => 'Entry data could not be loaded. Please try again.', 'empty' => true];
    }
    $total = (int)$entryReport['total'];
    if ($total === 0) {
        return ['text' => reports_modal_empty_text('library entries', $periodWord), 'empty' => true];
    }
    $range = reports_modal_short_range($from, $to);
    if (!preg_match('/\d{4}$/', $range)) {
        $range .= ', ' . substr($to, 0, 4);
    }
    $people = (int)$entryReport['people'];
    $text = number_format($total) . ' ' . ($total === 1 ? 'entry' : 'entries') . ' from ' . number_format($people)
        . ' ' . ($people === 1 ? 'person' : 'people') . ' · ' . $range . '.';

    $activeHours = array_values(array_filter($entryReport['sections'][0]['rows'], fn($row) => (int)$row['total'] > 0));
    if (count($activeHours) === 1) {
        $text .= ' ' . ($total === 1 ? 'It' : 'All ' . number_format($total)) . ' happened between ' . $activeHours[0]['label']
            . ' — the only hour with any activity this ' . $periodWord . '.';
    } elseif ($activeHours) {
        usort($activeHours, fn($x, $y) => (int)$y['total'] <=> (int)$x['total']);
        $text .= ' Busiest hour: ' . $activeHours[0]['label'] . ', with ' . number_format((int)$activeHours[0]['total'])
            . ' of the ' . number_format($total) . '.';
    }
    $course = reports_modal_top_label($entryReport['sections'][1]['rows']);
    if ($course !== null) {
        $text .= ' Most common course: ' . $course . '.';
    }
    return ['text' => $text, 'empty' => false];
}

/** At a Glance empty state: icon, what's missing, and that it fills itself. */
function reports_glance_empty(string $icon, string $text): void {
    ?>
    <div class="reports-glance-empty">
        <span class="reports-glance-empty-icon" aria-hidden="true"><?= $icon ?></span>
        <p><?= htmlspecialchars($text) ?></p>
        <p class="reports-glance-empty-sub">Fills in automatically as entries come in.</p>
    </div>
    <?php
}

/**
 * At a Glance strip: one bar per day for the last 7 days of the period (up to
 * today, never before the period starts), labelled by weekday, with the
 * busiest day named underneath. Kept deliberately small next to the report
 * charts. Shows the empty state when the whole period has nothing.
 */
function reports_glance_sparkline(array $dailyRows, string $from, string $to, string $tone, string $emptyIcon, string $emptyText): void {
    if (array_sum(array_column($dailyRows, 'total')) === 0) {
        reports_glance_empty($emptyIcon, $emptyText);
        return;
    }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    $today = new DateTimeImmutable('today');
    if ($end > $today) {
        $end = $today;
    }
    $first = $end->modify('-6 days');
    if ($first < $start) {
        $first = $start;
    }
    $byDay = [];
    foreach ($dailyRows as $row) {
        $byDay[(string)$row['bucket_label']] = (int)$row['total'];
    }
    $days = [];
    for ($d = $first; $d <= $end; $d = $d->modify('+1 day')) {
        $days[] = ['date' => $d, 'total' => $byDay[$d->format('Y-m-d')] ?? 0];
    }
    $max = max(1, ...array_column($days, 'total'));
    $busiest = null;
    foreach ($days as $day) {
        if ($day['total'] > 0 && ($busiest === null || $day['total'] > $busiest['total'])) {
            $busiest = $day;
        }
    }
    $windowLabel = $end == $today && count($days) === 7
        ? 'Last 7 days'
        : reports_modal_short_range($first->format('Y-m-d'), $end->format('Y-m-d'));
    ?>
    <div class="reports-glance-spark <?= $tone ?>">
        <?php foreach ($days as $day): ?>
            <?php $pct = $day['total'] > 0 ? max(6, (int)round($day['total'] / $max * 100)) : 0; ?>
            <div class="reports-glance-spark-day" title="<?= htmlspecialchars($day['date']->format('D, M j') . ': ' . number_format($day['total'])) ?>">
                <span class="reports-glance-spark-bar" style="height: <?= $pct ?>%"></span>
                <span class="reports-glance-spark-label"><?= htmlspecialchars(substr($day['date']->format('D'), 0, 1)) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="reports-glance-spark-foot">
        <span><?= htmlspecialchars($windowLabel) ?></span>
        <span><?= $busiest ? '<b>' . htmlspecialchars($busiest['date']->format('D')) . '</b> busiest' : 'No activity on these days' ?></span>
    </div>
    <?php
}

/** Largest row of a breakdown, ignoring "Not specified" and zero rows. */
function reports_modal_top_label(array $rows): ?string {
    $best = null;
    foreach ($rows as $row) {
        if ((int)$row['total'] > 0 && $row['label'] !== ENTRY_REPORT_NOT_SPECIFIED
            && ($best === null || (int)$row['total'] > (int)$best['total'])) {
            $best = $row;
        }
    }
    return $best ? (string)$best['label'] : null;
}
