<?php
/**
 * Support functions for admin/reports.php: date/param helpers, the raw
 * PDF-generation engine (reports_build_pdf_from_streams, reports_build_visual_pdf,
 * reports_build_pdf), and the trend/chart-formatting helpers used when
 * rendering a report.
 *
 * Pulled out of the main script as a straight move -- behavior unchanged.
 */

function reports_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function reports_table_exists(mysqli $conn, string $table): bool {
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

function reports_valid_date(string $value): bool {
    if ($value === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $value;
}

function reports_safe_int($value, int $default, int $min, int $max): int {
    if (!is_numeric($value)) {
        return $default;
    }

    $num = (int)$value;
    if ($num < $min || $num > $max) {
        return $default;
    }

    return $num;
}

function reports_build_url(array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            $clean[(string)$key] = $text;
        }
    }

    return 'reports.php' . (!empty($clean) ? ('?' . http_build_query($clean)) : '');
}

function reports_ascii_text(string $text): string {
    $plain = str_replace(["\r", "\n", "\t"], ' ', $text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain);
        if ($converted !== false) {
            $plain = $converted;
        }
    }
    $plain = preg_replace('/[^\x20-\x7E]/', '', $plain) ?? '';
    $plain = preg_replace('/\s+/', ' ', $plain) ?? '';
    return trim($plain);
}

function reports_wrap_text(string $text, int $maxLen = 96): array {
    $text = reports_ascii_text($text);
    if ($text === '') {
        return [''];
    }

    $wrapped = wordwrap($text, $maxLen, "\n", true);
    $parts = explode("\n", $wrapped);
    $lines = [];
    foreach ($parts as $part) {
        $lines[] = trim($part);
    }
    return $lines ?: [''];
}

function reports_pdf_escape(string $text): string {
    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        reports_ascii_text($text)
    );
}

function reports_pdf_num(float $value): string {
    $text = number_format($value, 2, '.', '');
    $text = rtrim(rtrim($text, '0'), '.');
    return $text === '-0' ? '0' : $text;
}

function reports_pdf_cmd_text(float $x, float $y, string $text, int $size = 10, bool $bold = false, array $rgb = [0.12, 0.24, 0.18]): string {
    $font = $bold ? 'F2' : 'F1';
    return "BT\n"
        . "/{$font} {$size} Tf\n"
        . sprintf("%.3F %.3F %.3F rg\n", (float)$rgb[0], (float)$rgb[1], (float)$rgb[2])
        . "1 0 0 1 " . reports_pdf_num($x) . ' ' . reports_pdf_num($y) . " Tm\n"
        . '(' . reports_pdf_escape($text) . ") Tj\nET\n";
}

function reports_pdf_cmd_rect(float $x, float $y, float $w, float $h, ?array $fillRgb = null, ?array $strokeRgb = null, float $lineWidth = 1.0): string {
    $cmd = '';
    if ($fillRgb !== null) {
        $cmd .= sprintf("%.3F %.3F %.3F rg\n", (float)$fillRgb[0], (float)$fillRgb[1], (float)$fillRgb[2]);
    }
    if ($strokeRgb !== null) {
        $cmd .= sprintf("%.3F %.3F %.3F RG\n", (float)$strokeRgb[0], (float)$strokeRgb[1], (float)$strokeRgb[2]);
    }
    $op = 'S';
    if ($fillRgb !== null && $strokeRgb !== null) {
        $op = 'B';
    } elseif ($fillRgb !== null) {
        $op = 'f';
    }
    $cmd .= reports_pdf_num($lineWidth) . " w\n";
    $cmd .= reports_pdf_num($x) . ' ' . reports_pdf_num($y) . ' ' . reports_pdf_num($w) . ' ' . reports_pdf_num($h) . " re {$op}\n";
    return $cmd;
}

function reports_pdf_cmd_line(float $x1, float $y1, float $x2, float $y2, array $strokeRgb = [0.75, 0.82, 0.78], float $lineWidth = 1.0): string {
    return sprintf("%.3F %.3F %.3F RG\n", (float)$strokeRgb[0], (float)$strokeRgb[1], (float)$strokeRgb[2])
        . reports_pdf_num($lineWidth) . " w\n"
        . reports_pdf_num($x1) . ' ' . reports_pdf_num($y1) . " m\n"
        . reports_pdf_num($x2) . ' ' . reports_pdf_num($y2) . " l\nS\n";
}

function reports_build_pdf_from_streams(array $pageStreams): string {
    if (empty($pageStreams)) {
        $pageStreams = [reports_pdf_cmd_text(50, 760, 'No report data available.', 11, true)];
    }

    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

    $pageObjectNumbers = [];
    $nextObj = 3;
    foreach ($pageStreams as $_stream) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageObjectNumbers[] = [$pageObj, $contentObj];
    }
    $fontRegularObj = $nextObj++;
    $fontBoldObj = $nextObj++;

    $kidsRefs = [];
    foreach ($pageObjectNumbers as [$pageObj, $_contentObj]) {
        $kidsRefs[] = $pageObj . " 0 R";
    }
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kidsRefs) . "] /Count " . count($pageObjectNumbers) . " >>";

    foreach ($pageStreams as $idx => $stream) {
        [$pageObj, $contentObj] = $pageObjectNumbers[$idx];
        $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 {$fontRegularObj} 0 R /F2 {$fontBoldObj} 0 R >> >> /Contents {$contentObj} 0 R >>";
    }

    $objects[$fontRegularObj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[$fontBoldObj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $objNum => $body) {
        $offsets[$objNum] = strlen($pdf);
        $pdf .= $objNum . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefPos = strlen($pdf);
    $maxObj = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObj; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= str_pad((string)$offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    return $pdf;
}

function reports_build_visual_pdf(array $report): string {
    $title = (string)($report['title'] ?? 'SMARTLIB REPORT');
    $generatedAt = (string)($report['generated_at'] ?? date('Y-m-d H:i:s'));
    $rangeLabel = (string)($report['range_label'] ?? '');
    $rangeFrom = (string)($report['range_from'] ?? '');
    $rangeTo = (string)($report['range_to'] ?? '');
    $statusFilter = (string)($report['status_filter'] ?? 'all');
    $trendGrouping = (string)($report['trend_grouping'] ?? 'monthly');
    $mode = strtolower(trim((string)($report['mode'] ?? 'summary')));
    $reportType = strtolower(trim((string)($report['report_type'] ?? ($mode === 'detailed' ? 'full_detailed' : 'full_summary'))));
    $reportLabel = trim((string)($report['report_label'] ?? ''));
    $scope = reports_resolve_scope($reportType, $mode);

    $totals = (array)($report['totals'] ?? []);
    $statusBreakdown = (array)($report['status_breakdown'] ?? []);
    $borrowTrend = (array)($report['borrow_trend'] ?? []);
    $searchTrend = (array)($report['search_trend'] ?? []);
    $activityByMonth = (array)($report['activity_by_month'] ?? []);
    $topBooks = (array)($report['top_books'] ?? []);
    $programActivity = (array)($report['program_activity'] ?? []);
    $detailedRows = (array)($report['detailed_rows'] ?? []);

    $contentX = 36.0;
    $contentW = 540.0;
    $margin = 36.0;
    $cursorY = 770.0;
    $stream = '';
    $pages = [];

    $pushPage = function () use (&$pages, &$stream, &$cursorY): void {
        if (trim($stream) !== '') {
            $pages[] = $stream;
        }
        $stream = '';
        $cursorY = 770.0;
    };

    $ensureSpace = function (float $need) use (&$cursorY, $margin, $pushPage): void {
        if (($cursorY - $need) < $margin) {
            $pushPage();
        }
    };

    $addText = function (float $x, float $y, string $text, int $size = 10, bool $bold = false, array $rgb = [0.12, 0.24, 0.18]) use (&$stream): void {
        $stream .= reports_pdf_cmd_text($x, $y, $text, $size, $bold, $rgb);
    };

    $addRect = function (float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lineWidth = 1.0) use (&$stream): void {
        $stream .= reports_pdf_cmd_rect($x, $y, $w, $h, $fill, $stroke, $lineWidth);
    };

    $addLine = function (float $x1, float $y1, float $x2, float $y2, array $stroke = [0.75, 0.82, 0.78], float $lineWidth = 1.0) use (&$stream): void {
        $stream .= reports_pdf_cmd_line($x1, $y1, $x2, $y2, $stroke, $lineWidth);
    };

    $drawSectionHeading = function (string $heading) use (&$cursorY, $contentX, $contentW, $ensureSpace, $addText, $addLine): void {
        $ensureSpace(24);
        $addText($contentX, $cursorY - 2, $heading, 13, true, [0.11, 0.25, 0.19]);
        $cursorY -= 8;
        $addLine($contentX, $cursorY, $contentX + $contentW, $cursorY, [0.77, 0.84, 0.80], 0.8);
        $cursorY -= 10;
    };

    $drawBarSection = function (string $heading, array $rows, string $labelKey, string $valueKey, string $suffix = '', int $limit = 10, string $sliceMode = 'last', array $barFill = [0.39, 0.70, 0.53]) use (
        &$cursorY,
        $margin,
        $contentX,
        $pushPage,
        $addText,
        $addRect,
        $drawSectionHeading
    ): void {
        $rows = array_values($rows);
        if ($sliceMode === 'first') {
            $rows = array_slice($rows, 0, max(1, $limit));
        } else {
            $rows = array_slice($rows, -1 * max(1, $limit));
        }

        $drawSectionHeading($heading);
        if (empty($rows)) {
            $addText($contentX + 2, $cursorY - 10, 'No data available.', 10, false, [0.40, 0.52, 0.46]);
            $cursorY -= 22;
            return;
        }

        $maxValue = 1;
        foreach ($rows as $row) {
            $maxValue = max($maxValue, (int)($row[$valueKey] ?? 0));
        }

        foreach ($rows as $index => $row) {
            if (($cursorY - 18) < $margin) {
                $pushPage();
                $drawSectionHeading($heading . ' (cont.)');
            }

            $label = reports_trim_label((string)($row[$labelKey] ?? '-'), 26);
            $value = (int)($row[$valueKey] ?? 0);
            $ratio = $maxValue > 0 ? ($value / $maxValue) : 0;
            $trackX = $contentX + 230;
            $trackW = 220.0;
            $trackH = 8.0;
            $trackY = $cursorY - 11;
            $fillW = max(2.0, $trackW * $ratio);

            $rowBg = ($index % 2 === 0) ? [0.97, 0.98, 0.97] : [0.94, 0.97, 0.95];
            $addRect($contentX, $cursorY - 16, 540, 16, $rowBg, [0.86, 0.90, 0.88], 0.25);
            $addText($contentX + 4, $cursorY - 11, $label, 9, false, [0.17, 0.30, 0.24]);
            $addRect($trackX, $trackY, $trackW, $trackH, [0.86, 0.90, 0.88], [0.78, 0.84, 0.80], 0.4);
            $addRect($trackX, $trackY, $fillW, $trackH, $barFill, null, 0);
            $addText($contentX + 462, $cursorY - 11, number_format($value) . $suffix, 9, true, [0.16, 0.28, 0.22]);
            $cursorY -= 18;
        }

        $cursorY -= 8;
    };

    $ensureSpace(74);
    $addRect($contentX, $cursorY - 58, $contentW, 58, [0.13, 0.46, 0.31], null, 0);
    $addText($contentX + 14, $cursorY - 23, $title, 18, true, [1.00, 1.00, 1.00]);
    $addText($contentX + 14, $cursorY - 42, 'Generated: ' . $generatedAt, 10, false, [0.89, 0.96, 0.92]);
    $cursorY -= 72;

    $metaLines = [
        'Range: ' . $rangeLabel,
        'From: ' . $rangeFrom . '  To: ' . $rangeTo,
        'Status Filter: ' . ucfirst($statusFilter),
        'Trend Grouping: ' . $trendGrouping
    ];
    if ($reportLabel !== '') {
        array_unshift($metaLines, 'Report Type: ' . $reportLabel);
    }

    foreach ($metaLines as $line) {
        $ensureSpace(16);
        $addText($contentX + 2, $cursorY - 11, $line, 10, false, [0.14, 0.28, 0.21]);
        $cursorY -= 15;
    }
    $cursorY -= 6;

    if ($scope['show_totals']) {
        $metricCards = [
            ['label' => 'Total Records', 'value' => number_format((int)($totals['total'] ?? 0))],
            ['label' => 'Borrowed', 'value' => number_format((int)($totals['borrowed'] ?? 0))],
            ['label' => 'Returned', 'value' => number_format((int)($totals['returned'] ?? 0))],
            ['label' => 'Overdue', 'value' => number_format((int)($totals['overdue'] ?? 0))],
            ['label' => 'Missing', 'value' => number_format((int)($totals['missing'] ?? 0))],
            ['label' => 'Total Fine', 'value' => number_format((float)($totals['total_fine'] ?? 0), 2)]
        ];

        $cardW = ($contentW - (12 * 2)) / 3;
        $cardH = 52;
        $ensureSpace(($cardH * 2) + 26);
        foreach ($metricCards as $idx => $card) {
            $row = intdiv($idx, 3);
            $col = $idx % 3;
            $x = $contentX + ($col * ($cardW + 12));
            $top = $cursorY - ($row * ($cardH + 10));
            $addRect($x, $top - $cardH, $cardW, $cardH, [0.96, 0.98, 0.97], [0.79, 0.85, 0.81], 0.8);
            $addText($x + 8, $top - 16, (string)$card['label'], 9, true, [0.22, 0.36, 0.29]);
            $addText($x + 8, $top - 36, (string)$card['value'], 18, true, [0.14, 0.29, 0.22]);
        }
        $cursorY -= (($cardH * 2) + 14 + 10);
    }

    if ($scope['show_status']) {
        $drawBarSection('Status Breakdown', $statusBreakdown, 'status', 'total', '', 8, 'first', [0.37, 0.65, 0.46]);
    }
    if ($scope['show_borrow']) {
        $drawBarSection('Borrow Trend (' . strtoupper($trendGrouping) . ')', $borrowTrend, 'bucket_label', 'total', '', 12, 'last', [0.37, 0.70, 0.53]);
    }
    if ($scope['show_search']) {
        $drawBarSection('Search Trend (' . strtoupper($trendGrouping) . ')', $searchTrend, 'bucket_label', 'total', ' searches', 12, 'last', [0.29, 0.63, 0.80]);
    }
    if ($scope['show_activity_month']) {
        $drawBarSection('Activity by Month', $activityByMonth, 'period_key', 'total', '', 12, 'last', [0.84, 0.66, 0.20]);
    }
    if ($scope['show_top_books']) {
        $drawBarSection('Top Borrowed Books', $topBooks, 'title', 'borrow_count', ' borrows', 10, 'first', [0.85, 0.68, 0.24]);
    }
    if ($scope['show_program']) {
        $drawBarSection('Program Borrow Activity', $programActivity, 'program_name', 'borrow_count', ' borrows', 10, 'first', [0.40, 0.71, 0.54]);
    }

    if ($scope['show_detailed'] && !empty($detailedRows)) {
        $pushPage();
        $addText($contentX, $cursorY - 2, 'Detailed Records', 16, true, [0.10, 0.25, 0.18]);
        $cursorY -= 20;

        $drawDetailHeader = function () use (&$cursorY, $contentX, $contentW, $addRect, $addText): void {
            $addRect($contentX, $cursorY - 16, $contentW, 16, [0.87, 0.93, 0.89], [0.77, 0.84, 0.80], 0.6);
            $x = $contentX + 4;
            $addText($x, $cursorY - 11, 'ID', 9, true, [0.18, 0.32, 0.25]); $x += 38;
            $addText($x, $cursorY - 11, 'Date', 9, true, [0.18, 0.32, 0.25]); $x += 64;
            $addText($x, $cursorY - 11, 'User', 9, true, [0.18, 0.32, 0.25]); $x += 128;
            $addText($x, $cursorY - 11, 'Book', 9, true, [0.18, 0.32, 0.25]); $x += 185;
            $addText($x, $cursorY - 11, 'Status', 9, true, [0.18, 0.32, 0.25]); $x += 58;
            $addText($x, $cursorY - 11, 'Fine', 9, true, [0.18, 0.32, 0.25]);
            $cursorY -= 16;
        };

        $drawDetailHeader();
        $rowHeight = 16;
        $index = 0;
        foreach ($detailedRows as $row) {
            if (($cursorY - $rowHeight) < $margin) {
                $pushPage();
                $addText($contentX, $cursorY - 2, 'Detailed Records (cont.)', 14, true, [0.10, 0.25, 0.18]);
                $cursorY -= 20;
                $drawDetailHeader();
            }

            $bg = ($index % 2 === 0) ? [0.98, 0.99, 0.98] : [0.95, 0.98, 0.96];
            $addRect($contentX, $cursorY - $rowHeight, $contentW, $rowHeight, $bg, [0.84, 0.90, 0.86], 0.25);

            $recordId = '#' . (int)($row['record_id'] ?? 0);
            $activityDate = (string)($row['activity_date'] ?? '-');
            $statusText = ucfirst(strtolower((string)($row['status'] ?? 'unknown')));
            $fineText = number_format((float)($row['fine'] ?? 0), 2);
            $userNumber = trim((string)($row['user_number'] ?? ''));
            $borrower = trim((string)($row['borrower_name'] ?? ''));
            $userText = reports_trim_label(trim($borrower . ($userNumber !== '' ? ' (' . $userNumber . ')' : '')), 24);
            $bookText = reports_trim_label((string)($row['title'] ?? '-'), 36);

            $x = $contentX + 4;
            $addText($x, $cursorY - 11, $recordId, 8, true, [0.18, 0.30, 0.24]); $x += 38;
            $addText($x, $cursorY - 11, $activityDate, 8, false, [0.18, 0.30, 0.24]); $x += 64;
            $addText($x, $cursorY - 11, $userText, 8, false, [0.18, 0.30, 0.24]); $x += 128;
            $addText($x, $cursorY - 11, $bookText, 8, false, [0.18, 0.30, 0.24]); $x += 185;
            $addText($x, $cursorY - 11, $statusText, 8, true, [0.18, 0.30, 0.24]); $x += 58;
            $addText($x, $cursorY - 11, $fineText, 8, true, [0.18, 0.30, 0.24]);

            $cursorY -= $rowHeight;
            $index++;
        }
    }

    if (trim($stream) !== '') {
        $pages[] = $stream;
    }

    return reports_build_pdf_from_streams($pages);
}

function reports_build_pdf(array $pagesLines): string {
    if (empty($pagesLines)) {
        $pagesLines = [['No report data available.']];
    }

    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

    $pageObjectNumbers = [];
    $nextObj = 3;
    foreach ($pagesLines as $_) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageObjectNumbers[] = [$pageObj, $contentObj];
    }
    $fontObj = $nextObj++;

    $kidsRefs = [];
    foreach ($pageObjectNumbers as [$pageObj, $_contentObj]) {
        $kidsRefs[] = $pageObj . " 0 R";
    }
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kidsRefs) . "] /Count " . count($pageObjectNumbers) . " >>";

    foreach ($pagesLines as $idx => $lines) {
        [$pageObj, $contentObj] = $pageObjectNumbers[$idx];
        $lineHeight = 14;
        $startY = 760;
        $stream = "BT\n/F1 10 Tf\n50 {$startY} Td\n{$lineHeight} TL\n";

        foreach ($lines as $line) {
            $stream .= "(" . reports_pdf_escape((string)$line) . ") Tj\nT*\n";
        }
        $stream .= "ET";

        $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 {$fontObj} 0 R >> >> /Contents {$contentObj} 0 R >>";
    }

    $objects[$fontObj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $objNum => $body) {
        $offsets[$objNum] = strlen($pdf);
        $pdf .= $objNum . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefPos = strlen($pdf);
    $maxObj = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObj; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= str_pad((string)$offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    return $pdf;
}

function reports_paginate_lines(array $lines, int $linesPerPage = 48): array {
    $pages = [];
    $current = [];

    foreach ($lines as $line) {
        $wrapped = reports_wrap_text((string)$line, 96);
        foreach ($wrapped as $segment) {
            if (count($current) >= $linesPerPage) {
                $pages[] = $current;
                $current = [];
            }
            $current[] = $segment;
        }
    }

    if (!empty($current)) {
        $pages[] = $current;
    }

    return $pages ?: [['No report data available.']];
}

function reports_trend_sql_parts(string $sourceExpr, string $bucket): array {
    $bucket = strtolower(trim($bucket));

    if ($bucket === 'daily') {
        return [
            'label_expr' => "DATE({$sourceExpr})",
            'sort_expr' => "MIN(DATE({$sourceExpr}))"
        ];
    }

    if ($bucket === 'weekly') {
        return [
            'label_expr' => "CONCAT(YEAR({$sourceExpr}), '-W', LPAD(WEEK({$sourceExpr}, 1), 2, '0'))",
            'sort_expr' => "MIN(DATE({$sourceExpr}))"
        ];
    }

    if ($bucket === 'quarterly') {
        return [
            'label_expr' => "CONCAT(YEAR({$sourceExpr}), '-Q', QUARTER({$sourceExpr}))",
            'sort_expr' => "MIN(DATE({$sourceExpr}))"
        ];
    }

    if ($bucket === 'yearly') {
        return [
            'label_expr' => "DATE_FORMAT({$sourceExpr}, '%Y')",
            'sort_expr' => "MIN(DATE({$sourceExpr}))"
        ];
    }

    return [
        'label_expr' => "DATE_FORMAT({$sourceExpr}, '%Y-%m')",
        'sort_expr' => "MIN(DATE({$sourceExpr}))"
    ];
}

function reports_trend_bucket_label(string $bucket): string {
    $bucket = strtolower(trim($bucket));
    if ($bucket === 'daily') return 'Daily';
    if ($bucket === 'weekly') return 'Weekly';
    if ($bucket === 'quarterly') return 'Quarterly';
    if ($bucket === 'yearly') return 'Yearly';
    return 'Monthly';
}

function reports_ascii_bar(int $value, int $max, int $width = 28): string {
    $max = max(1, $max);
    $width = max(6, $width);
    $len = (int)round(($value / $max) * $width);
    $len = max(1, min($width, $len));
    return str_repeat('#', $len) . str_repeat('.', max(0, $width - $len));
}

function reports_trim_label(string $text, int $maxLen = 30): string {
    $safe = reports_ascii_text($text);
    if ($safe === '') {
        return '-';
    }
    if (strlen($safe) <= $maxLen) {
        return $safe;
    }
    return rtrim(substr($safe, 0, max(1, $maxLen - 3))) . '...';
}

function reports_resolve_scope(string $reportType, string $mode = 'summary'): array {
    $base = [
        'show_totals' => true,
        'show_status' => true,
        'show_borrow' => true,
        'show_search' => true,
        'show_activity_month' => true,
        'show_top_books' => true,
        'show_program' => true,
        'show_detailed' => (strtolower(trim($mode)) === 'detailed')
    ];

    $type = strtolower(trim($reportType));
    if ($type === 'monthly_summary') {
        return [
            'show_totals' => true,
            'show_status' => true,
            'show_borrow' => true,
            'show_search' => true,
            'show_activity_month' => false,
            'show_top_books' => true,
            'show_program' => false,
            'show_detailed' => false
        ];
    }
    if ($type === 'weekly_trend') {
        return [
            'show_totals' => false,
            'show_status' => false,
            'show_borrow' => true,
            'show_search' => true,
            'show_activity_month' => false,
            'show_top_books' => false,
            'show_program' => false,
            'show_detailed' => false
        ];
    }
    if ($type === 'yearly_trend') {
        return [
            'show_totals' => false,
            'show_status' => false,
            'show_borrow' => true,
            'show_search' => true,
            'show_activity_month' => true,
            'show_top_books' => false,
            'show_program' => false,
            'show_detailed' => false
        ];
    }
    if ($type === 'detailed_transactions') {
        return [
            'show_totals' => false,
            'show_status' => false,
            'show_borrow' => false,
            'show_search' => false,
            'show_activity_month' => false,
            'show_top_books' => false,
            'show_program' => false,
            'show_detailed' => true
        ];
    }
    if ($type === 'program_activity') {
        return [
            'show_totals' => false,
            'show_status' => false,
            'show_borrow' => false,
            'show_search' => false,
            'show_activity_month' => false,
            'show_top_books' => false,
            'show_program' => true,
            'show_detailed' => false
        ];
    }
    if ($type === 'search_trend') {
        return [
            'show_totals' => false,
            'show_status' => false,
            'show_borrow' => false,
            'show_search' => true,
            'show_activity_month' => false,
            'show_top_books' => false,
            'show_program' => false,
            'show_detailed' => false
        ];
    }

    if ($type === 'full_detailed') {
        $base['show_detailed'] = true;
        return $base;
    }

    if ($type === 'full_summary') {
        $base['show_detailed'] = false;
        return $base;
    }

    return $base;
}

function reports_append_chart_lines(array &$lines, string $title, array $rows, string $labelKey, string $valueKey, string $suffix = '', int $limit = 14): void {
    $lines[] = $title;
    if (empty($rows)) {
        $lines[] = 'No data.';
        $lines[] = '';
        return;
    }

    $slice = array_slice($rows, -1 * max(1, $limit));
    $maxValue = 1;
    foreach ($slice as $row) {
        $maxValue = max($maxValue, (int)($row[$valueKey] ?? 0));
    }

    foreach ($slice as $row) {
        $label = reports_trim_label((string)($row[$labelKey] ?? ''), 28);
        $value = (int)($row[$valueKey] ?? 0);
        $bar = reports_ascii_bar($value, $maxValue, 26);
        $lines[] = str_pad($label, 30, ' ', STR_PAD_RIGHT) . '[' . $bar . '] ' . number_format($value) . $suffix;
    }
    $lines[] = '';
}

function reports_range_label(string $period, int $year, int $month, int $quarter, int $week, string $day, string $dateFrom, string $dateTo): array {
    $monthName = static function (int $m): string {
        $dt = DateTime::createFromFormat('!m', (string)$m);
        return $dt ? $dt->format('F') : 'Month';
    };

    if ($period === 'daily') {
        $targetDay = reports_valid_date($day) ? $day : date('Y-m-d');
        return [$targetDay, $targetDay, 'Daily (' . $targetDay . ')'];
    }

    if ($period === 'weekly') {
        $dt = new DateTime();
        $dt->setISODate($year, $week, 1);
        $from = $dt->format('Y-m-d');
        $to = $dt->modify('+6 days')->format('Y-m-d');
        return [$from, $to, 'Week ' . $week . ', ' . $year];
    }

    if ($period === 'monthly') {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));
        return [$from, $to, $monthName($month) . ' ' . $year];
    }

    if ($period === 'quarterly') {
        $qMonthStart = (($quarter - 1) * 3) + 1;
        $from = sprintf('%04d-%02d-01', $year, $qMonthStart);
        $toMonth = $qMonthStart + 2;
        $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $toMonth)));
        return [$from, $to, 'Q' . $quarter . ' ' . $year];
    }

    if ($period === 'yearly') {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        return [$from, $to, 'Year ' . $year];
    }

    $from = reports_valid_date($dateFrom) ? $dateFrom : date('Y-m-01');
    $to = reports_valid_date($dateTo) ? $dateTo : date('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to, 'Custom (' . $from . ' to ' . $to . ')'];
}
