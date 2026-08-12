<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header("Location: ../index.php");
    exit;
}

require '../config/db_connect.php';

function reports_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function reports_table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = reports_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
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

$allowedPeriods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'];
$allowedTrendBuckets = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
$allowedStatuses = ['all', 'borrowed', 'returned', 'overdue', 'missing'];

$today = date('Y-m-d');
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$currentWeek = (int)date('W');

$period = strtolower(trim((string)($_GET['period'] ?? 'monthly')));
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'monthly';
}

$year = reports_safe_int($_GET['year'] ?? $currentYear, $currentYear, 2000, ($currentYear + 1));
$month = reports_safe_int($_GET['month'] ?? $currentMonth, $currentMonth, 1, 12);
$quarter = reports_safe_int($_GET['quarter'] ?? ((int)ceil($currentMonth / 3)), (int)ceil($currentMonth / 3), 1, 4);
$week = reports_safe_int($_GET['week'] ?? $currentWeek, $currentWeek, 1, 53);
$day = trim((string)($_GET['day'] ?? $today));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? $today));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}
$customReportType = strtolower(trim((string)($_GET['report_type'] ?? 'full_summary')));
$trendBucketDefault = in_array($period, $allowedTrendBuckets, true) ? $period : 'monthly';
$trendBucket = strtolower(trim((string)($_GET['trend_bucket'] ?? $trendBucketDefault)));
if (!in_array($trendBucket, $allowedTrendBuckets, true)) {
    $trendBucket = $trendBucketDefault;
}
$trendBucketLabel = reports_trend_bucket_label($trendBucket);

[$rangeFrom, $rangeTo, $rangeLabel] = reports_range_label(
    $period,
    $year,
    $month,
    $quarter,
    $week,
    $day,
    $dateFrom,
    $dateTo
);

$safeFrom = $conn->real_escape_string($rangeFrom);
$safeTo = $conn->real_escape_string($rangeTo);
$dateExpr = 'COALESCE(br.date_borrowed, DATE(br.created_at))';

$whereParts = [
    "$dateExpr >= '{$safeFrom}'",
    "$dateExpr <= '{$safeTo}'"
];

if ($statusFilter !== 'all') {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $whereParts[] = "br.status = '{$safeStatus}'";
}

$whereSql = implode(' AND ', $whereParts);
$borrowTrendParts = reports_trend_sql_parts($dateExpr, $trendBucket);
$borrowTrendLabelExpr = $borrowTrendParts['label_expr'];
$borrowTrendSortExpr = $borrowTrendParts['sort_expr'];
$searchTrendParts = reports_trend_sql_parts('created_at', $trendBucket);
$searchTrendLabelExpr = $searchTrendParts['label_expr'];
$searchTrendSortExpr = $searchTrendParts['sort_expr'];

$totals = [
    'total' => 0,
    'borrowed' => 0,
    'returned' => 0,
    'overdue' => 0,
    'missing' => 0,
    'total_fine' => 0.0
];

$resTotals = reports_query(
    $conn,
    "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN br.status = 'borrowed' THEN 1 ELSE 0 END) AS borrowed,
        SUM(CASE WHEN br.status = 'returned' THEN 1 ELSE 0 END) AS returned,
        SUM(CASE WHEN br.status = 'overdue' THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN br.status = 'missing' THEN 1 ELSE 0 END) AS missing,
        COALESCE(SUM(br.fine), 0) AS total_fine
    FROM borrow_records br
    WHERE {$whereSql}
    "
);

if ($resTotals && ($row = $resTotals->fetch_assoc())) {
    $totals['total'] = (int)($row['total'] ?? 0);
    $totals['borrowed'] = (int)($row['borrowed'] ?? 0);
    $totals['returned'] = (int)($row['returned'] ?? 0);
    $totals['overdue'] = (int)($row['overdue'] ?? 0);
    $totals['missing'] = (int)($row['missing'] ?? 0);
    $totals['total_fine'] = (float)($row['total_fine'] ?? 0);
}

$statusBreakdown = [];
$resStatusBreakdown = reports_query(
    $conn,
    "
    SELECT br.status, COUNT(*) AS total
    FROM borrow_records br
    WHERE {$whereSql}
    GROUP BY br.status
    ORDER BY FIELD(br.status, 'borrowed', 'returned', 'overdue', 'missing'), br.status
    "
);

if ($resStatusBreakdown) {
    while ($row = $resStatusBreakdown->fetch_assoc()) {
        $statusBreakdown[] = [
            'status' => (string)($row['status'] ?? ''),
            'total' => (int)($row['total'] ?? 0)
        ];
    }
}

$activityByMonth = [];
$resActivityByMonth = reports_query(
    $conn,
    "
    SELECT DATE_FORMAT({$dateExpr}, '%Y-%m') AS period_key, COUNT(*) AS total
    FROM borrow_records br
    WHERE {$whereSql}
    GROUP BY period_key
    ORDER BY period_key ASC
    "
);

if ($resActivityByMonth) {
    while ($row = $resActivityByMonth->fetch_assoc()) {
        $activityByMonth[] = [
            'period_key' => (string)($row['period_key'] ?? ''),
            'total' => (int)($row['total'] ?? 0)
        ];
    }
}

$topBooks = [];
$resTopBooks = reports_query(
    $conn,
    "
    SELECT
        b.title,
        COUNT(*) AS borrow_count
    FROM borrow_records br
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    WHERE {$whereSql}
    GROUP BY bc.book_id, b.title
    ORDER BY borrow_count DESC, b.title ASC
    LIMIT 10
    "
);

if ($resTopBooks) {
    while ($row = $resTopBooks->fetch_assoc()) {
        $topBooks[] = [
            'title' => trim((string)($row['title'] ?? 'Unknown')),
            'borrow_count' => (int)($row['borrow_count'] ?? 0)
        ];
    }
}

$borrowTrendRows = [];
$resBorrowTrend = reports_query(
    $conn,
    "
    SELECT
        {$borrowTrendLabelExpr} AS bucket_label,
        COUNT(*) AS total
    FROM borrow_records br
    WHERE {$whereSql}
    GROUP BY bucket_label
    ORDER BY {$borrowTrendSortExpr} ASC
    "
);

if ($resBorrowTrend) {
    while ($row = $resBorrowTrend->fetch_assoc()) {
        $borrowTrendRows[] = [
            'bucket_label' => (string)($row['bucket_label'] ?? ''),
            'total' => (int)($row['total'] ?? 0)
        ];
    }
}

$searchTrendRows = [];
if (reports_table_exists($conn, 'search_logs')) {
    $resSearchTrend = reports_query(
        $conn,
        "
        SELECT
            {$searchTrendLabelExpr} AS bucket_label,
            COUNT(*) AS total
        FROM search_logs
        WHERE DATE(created_at) >= '{$safeFrom}'
          AND DATE(created_at) <= '{$safeTo}'
        GROUP BY bucket_label
        ORDER BY {$searchTrendSortExpr} ASC
        "
    );

    if ($resSearchTrend) {
        while ($row = $resSearchTrend->fetch_assoc()) {
            $searchTrendRows[] = [
                'bucket_label' => (string)($row['bucket_label'] ?? ''),
                'total' => (int)($row['total'] ?? 0)
            ];
        }
    }
}

$borrowTrendDisplayRows = [];
$resBorrowTrendDisplay = reports_query(
    $conn,
    "
    SELECT
        DATE({$dateExpr}) AS bucket_label,
        COUNT(*) AS total
    FROM borrow_records br
    WHERE {$whereSql}
    GROUP BY bucket_label
    ORDER BY bucket_label ASC
    "
);

if ($resBorrowTrendDisplay) {
    while ($row = $resBorrowTrendDisplay->fetch_assoc()) {
        $borrowTrendDisplayRows[] = [
            'bucket_label' => (string)($row['bucket_label'] ?? ''),
            'total' => (int)($row['total'] ?? 0)
        ];
    }
}

$searchTrendDisplayRows = [];
if (reports_table_exists($conn, 'search_logs')) {
    $resSearchTrendDisplay = reports_query(
        $conn,
        "
        SELECT
            DATE(created_at) AS bucket_label,
            COUNT(*) AS total
        FROM search_logs
        WHERE DATE(created_at) >= '{$safeFrom}'
          AND DATE(created_at) <= '{$safeTo}'
        GROUP BY bucket_label
        ORDER BY bucket_label ASC
        "
    );

    if ($resSearchTrendDisplay) {
        while ($row = $resSearchTrendDisplay->fetch_assoc()) {
            $searchTrendDisplayRows[] = [
                'bucket_label' => (string)($row['bucket_label'] ?? ''),
                'total' => (int)($row['total'] ?? 0)
            ];
        }
    }
}

$programActivityRows = [];
$resProgramActivity = reports_query(
    $conn,
    "
    SELECT
        COALESCE(p.program_name, 'General / Unassigned') AS program_name,
        COUNT(br.record_id) AS borrow_count
    FROM borrow_records br
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    LEFT JOIN programs p ON b.program_id = p.program_id
    WHERE {$whereSql}
    GROUP BY p.program_id, p.program_name
    ORDER BY borrow_count DESC, program_name ASC
    LIMIT 10
    "
);

if ($resProgramActivity) {
    while ($row = $resProgramActivity->fetch_assoc()) {
        $programActivityRows[] = [
            'program_name' => trim((string)($row['program_name'] ?? 'General / Unassigned')),
            'borrow_count' => (int)($row['borrow_count'] ?? 0)
        ];
    }
}

$maxBorrowTrend = 1;
foreach ($borrowTrendRows as $row) {
    $maxBorrowTrend = max($maxBorrowTrend, (int)($row['total'] ?? 0));
}

$maxSearchTrend = 1;
foreach ($searchTrendRows as $row) {
    $maxSearchTrend = max($maxSearchTrend, (int)($row['total'] ?? 0));
}

$maxBorrowTrendDisplay = 1;
foreach ($borrowTrendDisplayRows as $row) {
    $maxBorrowTrendDisplay = max($maxBorrowTrendDisplay, (int)($row['total'] ?? 0));
}

$maxSearchTrendDisplay = 1;
foreach ($searchTrendDisplayRows as $row) {
    $maxSearchTrendDisplay = max($maxSearchTrendDisplay, (int)($row['total'] ?? 0));
}

$maxProgramActivity = 1;
foreach ($programActivityRows as $row) {
    $maxProgramActivity = max($maxProgramActivity, (int)($row['borrow_count'] ?? 0));
}

$quickTypeProfiles = [
    'full_summary' => [
        'report_type' => 'full_summary',
        'mode' => 'summary',
        'title' => 'SUMMARY REPORT',
        'label' => 'Full Summary'
    ],
    'monthly_summary' => [
        'report_type' => 'monthly_summary',
        'mode' => 'summary',
        'title' => 'MONTHLY SUMMARY REPORT',
        'label' => 'Monthly Summary'
    ],
    'weekly_trend' => [
        'report_type' => 'weekly_trend',
        'mode' => 'summary',
        'title' => 'WEEKLY TREND REPORT',
        'label' => 'Weekly Trend'
    ],
    'yearly_trend' => [
        'report_type' => 'yearly_trend',
        'mode' => 'summary',
        'title' => 'YEARLY TREND REPORT',
        'label' => 'Yearly Trend'
    ],
    'detailed_transactions' => [
        'report_type' => 'detailed_transactions',
        'mode' => 'detailed',
        'title' => 'DETAILED TRANSACTIONS REPORT',
        'label' => 'Detailed Transactions'
    ],
    'program_activity' => [
        'report_type' => 'program_activity',
        'mode' => 'summary',
        'title' => 'PROGRAM ACTIVITY REPORT',
        'label' => 'Program Activity'
    ],
    'search_trend' => [
        'report_type' => 'search_trend',
        'mode' => 'summary',
        'title' => 'SEARCH TREND REPORT',
        'label' => 'Search Trend'
    ]
];

$customReportTypeOptions = [
    'full_summary' => 'Full Summary',
    'monthly_summary' => 'Monthly Summary',
    'weekly_trend' => 'Weekly Trend',
    'yearly_trend' => 'Yearly Trend',
    'program_activity' => 'Program Activity',
    'search_trend' => 'Search Trend',
    'detailed_transactions' => 'Detailed Transactions'
];

$requestedQuickType = strtolower(trim((string)($_GET['quick_type'] ?? $customReportType)));
if (!isset($quickTypeProfiles[$requestedQuickType])) {
    $requestedQuickType = 'full_summary';
}
$customReportType = $requestedQuickType;
$selectedQuickProfile = $quickTypeProfiles[$requestedQuickType] ?? null;

$baseParams = [
    'period' => $period,
    'report_type' => $customReportType,
    'year' => (string)$year,
    'month' => (string)$month,
    'quarter' => (string)$quarter,
    'week' => (string)$week,
    'day' => $day,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'status' => $statusFilter
];

$exportMode = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exportMode, ['summary', 'detailed', 'summary_pdf', 'detailed_pdf'], true)) {
    $normalizedMode = (str_starts_with($exportMode, 'detailed')) ? 'detailed' : 'summary';
    $reportType = $normalizedMode === 'detailed' ? 'full_detailed' : 'full_summary';
    $reportTitle = 'SMARTLIB ' . strtoupper($normalizedMode) . ' REPORT';
    $reportLabel = ucfirst($normalizedMode) . ' (All Sections)';

    if ($selectedQuickProfile !== null) {
        $normalizedMode = (string)$selectedQuickProfile['mode'];
        $reportType = (string)$selectedQuickProfile['report_type'];
        $reportTitle = 'SMARTLIB ' . (string)$selectedQuickProfile['title'];
        $reportLabel = (string)$selectedQuickProfile['label'];
    }

    $fileStamp = date('Ymd_His');
    $periodSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($rangeLabel)) ?: 'report';
    $typeSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($reportType)) ?: 'report';
    $filename = 'smartlib-' . $typeSlug . '-' . $periodSlug . '-' . $fileStamp . '.pdf';
    $detailedRows = [];
    if ($normalizedMode === 'detailed') {
        $resDetailed = reports_query(
            $conn,
            "
            SELECT
                br.record_id,
                {$dateExpr} AS activity_date,
                br.date_borrowed,
                br.due_date,
                br.date_returned,
                br.status,
                br.fine,
                u.user_number,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS borrower_name,
                b.title,
                b.isbn,
                bc.accession_no
            FROM borrow_records br
            LEFT JOIN library_users u ON br.user_id = u.user_id
            LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
            LEFT JOIN books b ON bc.book_id = b.book_id
            WHERE {$whereSql}
            ORDER BY {$dateExpr} DESC, br.record_id DESC
            LIMIT 1000
            "
        );

        if ($resDetailed) {
            while ($row = $resDetailed->fetch_assoc()) {
                $detailedRows[] = $row;
            }
        }
    }

    $pdfBinary = reports_build_visual_pdf([
        'title' => $reportTitle,
        'report_type' => $reportType,
        'report_label' => $reportLabel,
        'generated_at' => date('Y-m-d H:i:s'),
        'range_label' => $rangeLabel,
        'range_from' => $rangeFrom,
        'range_to' => $rangeTo,
        'status_filter' => $statusFilter,
        'trend_grouping' => $trendBucketLabel,
        'mode' => $normalizedMode,
        'totals' => $totals,
        'status_breakdown' => $statusBreakdown,
        'borrow_trend' => $borrowTrendRows,
        'search_trend' => $searchTrendRows,
        'activity_by_month' => $activityByMonth,
        'top_books' => $topBooks,
        'program_activity' => $programActivityRows,
        'detailed_rows' => $detailedRows
    ]);

    $isPrintMode = trim((string)($_GET['print'] ?? '')) === '1';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($isPrintMode ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    echo $pdfBinary;
    exit;
}

$previewRows = [];
$resPreviewRows = reports_query(
    $conn,
    "
    SELECT
        br.record_id,
        {$dateExpr} AS activity_date,
        br.date_borrowed,
        br.due_date,
        br.date_returned,
        br.status,
        br.fine,
        u.user_number,
        CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS borrower_name,
        b.title,
        b.isbn,
        bc.accession_no
    FROM borrow_records br
    LEFT JOIN library_users u ON br.user_id = u.user_id
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    WHERE {$whereSql}
    ORDER BY {$dateExpr} DESC, br.record_id DESC
    LIMIT 300
    "
);

if ($resPreviewRows) {
    while ($row = $resPreviewRows->fetch_assoc()) {
        $previewRows[] = $row;
    }
}

$downloadReportHref = reports_build_url(array_merge($baseParams, ['quick_type' => $customReportType, 'export' => 'summary']));
$printReportHref = reports_build_url(array_merge($baseParams, ['quick_type' => $customReportType, 'export' => 'summary', 'print' => '1']));
$quickReports = [
    [
        'title' => 'Monthly Summary Report',
        'description' => 'Overview of monthly borrow activity, status breakdown, and top books.',
        'view_url' => reports_build_url(['period' => 'monthly', 'year' => (string)$year, 'month' => (string)$month, 'status' => 'all', 'trend_bucket' => 'monthly', 'quick_type' => 'monthly_summary']),
        'preview_url' => reports_build_url(['period' => 'monthly', 'year' => (string)$year, 'month' => (string)$month, 'status' => 'all', 'trend_bucket' => 'monthly', 'quick_type' => 'monthly_summary', 'preview_modal' => '1']),
        'download_url' => reports_build_url(['period' => 'monthly', 'year' => (string)$year, 'month' => (string)$month, 'status' => 'all', 'trend_bucket' => 'monthly', 'quick_type' => 'monthly_summary', 'export' => 'summary'])
    ],
    [
        'title' => 'Weekly Trend Report',
        'description' => 'Weekly borrow and search trend timeline with trend-focused grouping.',
        'view_url' => reports_build_url(['period' => 'weekly', 'year' => (string)$year, 'week' => (string)$week, 'status' => 'all', 'trend_bucket' => 'weekly', 'quick_type' => 'weekly_trend']),
        'preview_url' => reports_build_url(['period' => 'weekly', 'year' => (string)$year, 'week' => (string)$week, 'status' => 'all', 'trend_bucket' => 'weekly', 'quick_type' => 'weekly_trend', 'preview_modal' => '1']),
        'download_url' => reports_build_url(['period' => 'weekly', 'year' => (string)$year, 'week' => (string)$week, 'status' => 'all', 'trend_bucket' => 'weekly', 'quick_type' => 'weekly_trend', 'export' => 'summary'])
    ],
    [
        'title' => 'Yearly Trend Report',
        'description' => 'Year-level activity snapshot for planning and annual panel review.',
        'view_url' => reports_build_url(['period' => 'yearly', 'year' => (string)$year, 'status' => 'all', 'trend_bucket' => 'yearly', 'quick_type' => 'yearly_trend']),
        'preview_url' => reports_build_url(['period' => 'yearly', 'year' => (string)$year, 'status' => 'all', 'trend_bucket' => 'yearly', 'quick_type' => 'yearly_trend', 'preview_modal' => '1']),
        'download_url' => reports_build_url(['period' => 'yearly', 'year' => (string)$year, 'status' => 'all', 'trend_bucket' => 'yearly', 'quick_type' => 'yearly_trend', 'export' => 'summary'])
    ],
    [
        'title' => 'Detailed Transactions',
        'description' => 'Record-level report including users, titles, status, and fines.',
        'view_url' => reports_build_url(['period' => $period, 'year' => (string)$year, 'month' => (string)$month, 'quarter' => (string)$quarter, 'week' => (string)$week, 'day' => $day, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => $statusFilter, 'trend_bucket' => $trendBucket, 'quick_type' => 'detailed_transactions']),
        'preview_url' => reports_build_url(['period' => $period, 'year' => (string)$year, 'month' => (string)$month, 'quarter' => (string)$quarter, 'week' => (string)$week, 'day' => $day, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => $statusFilter, 'trend_bucket' => $trendBucket, 'quick_type' => 'detailed_transactions', 'preview_modal' => '1']),
        'download_url' => reports_build_url(['period' => $period, 'year' => (string)$year, 'month' => (string)$month, 'quarter' => (string)$quarter, 'week' => (string)$week, 'day' => $day, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => $statusFilter, 'trend_bucket' => $trendBucket, 'quick_type' => 'detailed_transactions', 'export' => 'detailed'])
    ],
    [
        'title' => 'Program Activity Report',
        'description' => 'Top programs by borrow activity for curriculum-level demand signals.',
        'view_url' => reports_build_url(['period' => 'quarterly', 'year' => (string)$year, 'quarter' => (string)$quarter, 'status' => 'all', 'trend_bucket' => 'monthly', 'quick_type' => 'program_activity']),
        'preview_url' => reports_build_url(['period' => 'quarterly', 'year' => (string)$year, 'quarter' => (string)$quarter, 'status' => 'all', 'trend_bucket' => 'monthly', 'quick_type' => 'program_activity', 'preview_modal' => '1']),
        'download_url' => reports_build_url(['period' => 'quarterly', 'year' => (string)$year, 'quarter' => (string)$quarter, 'status' => 'all', 'trend_bucket' => 'monthly', 'quick_type' => 'program_activity', 'export' => 'summary'])
    ],
    [
        'title' => 'Search Trend Report',
        'description' => 'Search behavior trend report for demand and topic monitoring.',
        'view_url' => reports_build_url(['period' => $period, 'year' => (string)$year, 'month' => (string)$month, 'quarter' => (string)$quarter, 'week' => (string)$week, 'day' => $day, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => 'all', 'trend_bucket' => $trendBucket, 'quick_type' => 'search_trend']),
        'preview_url' => reports_build_url(['period' => $period, 'year' => (string)$year, 'month' => (string)$month, 'quarter' => (string)$quarter, 'week' => (string)$week, 'day' => $day, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => 'all', 'trend_bucket' => $trendBucket, 'quick_type' => 'search_trend', 'preview_modal' => '1']),
        'download_url' => reports_build_url(['period' => $period, 'year' => (string)$year, 'month' => (string)$month, 'quarter' => (string)$quarter, 'week' => (string)$week, 'day' => $day, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => 'all', 'trend_bucket' => $trendBucket, 'quick_type' => 'search_trend', 'export' => 'summary'])
    ]
];

$isModalPreview = trim((string)($_GET['preview_modal'] ?? '')) === '1';
if ($isModalPreview) {
    header('Content-Type: text/html; charset=UTF-8');
    $modalReportType = $selectedQuickProfile['report_type'] ?? 'full_summary';
    $modalMode = $selectedQuickProfile['mode'] ?? 'summary';
    $modalLabel = $selectedQuickProfile['label'] ?? 'Full Summary';
    $modalScope = reports_resolve_scope((string)$modalReportType, (string)$modalMode);
    $modalBorrowRows = array_slice($borrowTrendDisplayRows, -12);
    $modalSearchRows = array_slice($searchTrendDisplayRows, -12);
    $modalActivityRows = array_slice($activityByMonth, -12);
    $modalTopBooks = array_slice($topBooks, 0, 10);
    $modalProgramRows = array_slice($programActivityRows, 0, 10);
    $modalPreviewRows = array_slice($previewRows, 0, 20);

    $maxModalActivity = 1;
    foreach ($modalActivityRows as $row) {
        $maxModalActivity = max($maxModalActivity, (int)($row['total'] ?? 0));
    }
    $maxModalTopBooks = 1;
    foreach ($modalTopBooks as $row) {
        $maxModalTopBooks = max($maxModalTopBooks, (int)($row['borrow_count'] ?? 0));
    }
    ?>
    <div class="reports-modal-meta">
        <div><strong>Report Type:</strong> <?= htmlspecialchars($modalLabel) ?></div>
        <div><strong>Period:</strong> <?= htmlspecialchars($rangeLabel) ?></div>
        <div><strong>Date Range:</strong> <?= htmlspecialchars($rangeFrom) ?> to <?= htmlspecialchars($rangeTo) ?></div>
        <div><strong>Status:</strong> <?= htmlspecialchars(ucfirst($statusFilter)) ?></div>
        <div><strong>Trend Display:</strong> Daily</div>
    </div>

    <?php if ($modalScope['show_totals']): ?>
        <div class="reports-modal-metrics">
            <article class="reports-modal-metric-card"><span>Total Records</span><strong><?= number_format((int)$totals['total']) ?></strong></article>
            <article class="reports-modal-metric-card"><span>Borrowed</span><strong><?= number_format((int)$totals['borrowed']) ?></strong></article>
            <article class="reports-modal-metric-card"><span>Returned</span><strong><?= number_format((int)$totals['returned']) ?></strong></article>
            <article class="reports-modal-metric-card"><span>Overdue</span><strong><?= number_format((int)$totals['overdue']) ?></strong></article>
            <article class="reports-modal-metric-card"><span>Missing</span><strong><?= number_format((int)$totals['missing']) ?></strong></article>
            <article class="reports-modal-metric-card"><span>Total Fine</span><strong><?= number_format((float)$totals['total_fine'], 2) ?></strong></article>
        </div>
    <?php endif; ?>

    <div class="reports-modal-grid">
        <?php if ($modalScope['show_borrow']): ?>
            <section class="reports-modal-panel">
                <h3>Borrow Trend (Daily)</h3>
                <div class="table-wrap">
                    <table class="data-table reports-mini-table reports-modal-table">
                        <thead>
                            <tr><th>Period</th><th>Count</th><th>Trend</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($modalBorrowRows)): ?>
                                <?php foreach ($modalBorrowRows as $row): ?>
                                    <?php
                                        $val = (int)($row['total'] ?? 0);
                                        $pct = (int)round(($val / max(1, $maxBorrowTrendDisplay)) * 100);
                                        $pct = max(4, min(100, $pct));
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($row['bucket_label'] ?? '-')) ?></td>
                                        <td><?= number_format($val) ?></td>
                                        <td><span class="reports-bar-track"><span class="reports-bar-fill reports-bar-fill-borrow" style="width: <?= $pct ?>%"></span></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3">No borrow trend data.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($modalScope['show_search']): ?>
            <section class="reports-modal-panel">
                <h3>Search Trend (Daily)</h3>
                <div class="table-wrap">
                    <table class="data-table reports-mini-table reports-modal-table">
                        <thead>
                            <tr><th>Period</th><th>Count</th><th>Trend</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($modalSearchRows)): ?>
                                <?php foreach ($modalSearchRows as $row): ?>
                                    <?php
                                        $val = (int)($row['total'] ?? 0);
                                        $pct = (int)round(($val / max(1, $maxSearchTrendDisplay)) * 100);
                                        $pct = max(4, min(100, $pct));
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($row['bucket_label'] ?? '-')) ?></td>
                                        <td><?= number_format($val) ?></td>
                                        <td><span class="reports-bar-track"><span class="reports-bar-fill reports-bar-fill-search" style="width: <?= $pct ?>%"></span></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3">No search trend data.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <?php if ($modalScope['show_activity_month']): ?>
        <section class="reports-modal-panel">
            <h3>Activity by Month</h3>
            <div class="table-wrap">
                <table class="data-table reports-mini-table reports-modal-table">
                    <thead>
                        <tr><th>Month</th><th>Count</th><th>Trend</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($modalActivityRows)): ?>
                            <?php foreach ($modalActivityRows as $row): ?>
                                <?php
                                    $val = (int)($row['total'] ?? 0);
                                    $pct = (int)round(($val / max(1, $maxModalActivity)) * 100);
                                    $pct = max(4, min(100, $pct));
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($row['period_key'] ?? '-')) ?></td>
                                    <td><?= number_format($val) ?></td>
                                    <td><span class="reports-bar-track"><span class="reports-bar-fill reports-bar-fill-program" style="width: <?= $pct ?>%"></span></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3">No monthly activity data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($modalScope['show_top_books']): ?>
        <section class="reports-modal-panel">
            <h3>Top Borrowed Books</h3>
            <div class="table-wrap">
                <table class="data-table reports-mini-table reports-modal-table">
                    <thead>
                        <tr><th>Book</th><th>Borrows</th><th>Trend</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($modalTopBooks)): ?>
                            <?php foreach ($modalTopBooks as $row): ?>
                                <?php
                                    $val = (int)($row['borrow_count'] ?? 0);
                                    $pct = (int)round(($val / max(1, $maxModalTopBooks)) * 100);
                                    $pct = max(4, min(100, $pct));
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(reports_trim_label((string)($row['title'] ?? '-'), 44)) ?></td>
                                    <td><?= number_format($val) ?></td>
                                    <td><span class="reports-bar-track"><span class="reports-bar-fill reports-bar-fill-borrow" style="width: <?= $pct ?>%"></span></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3">No top books data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($modalScope['show_program']): ?>
        <section class="reports-modal-panel">
            <h3>Program Borrow Activity</h3>
            <div class="table-wrap">
                <table class="data-table reports-mini-table reports-modal-table">
                    <thead>
                        <tr><th>Program</th><th>Borrows</th><th>Trend</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($modalProgramRows)): ?>
                            <?php foreach ($modalProgramRows as $row): ?>
                                <?php
                                    $val = (int)($row['borrow_count'] ?? 0);
                                    $pct = (int)round(($val / max(1, $maxProgramActivity)) * 100);
                                    $pct = max(4, min(100, $pct));
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($row['program_name'] ?? '-')) ?></td>
                                    <td><?= number_format($val) ?></td>
                                    <td><span class="reports-bar-track"><span class="reports-bar-fill reports-bar-fill-program" style="width: <?= $pct ?>%"></span></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3">No program activity data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($modalScope['show_detailed']): ?>
        <section class="reports-modal-panel">
            <h3>Recent Records</h3>
            <div class="table-wrap">
                <table class="data-table reports-mini-table reports-modal-table">
                    <thead>
                        <tr>
                            <th>Record #</th>
                            <th>Date</th>
                            <th>User</th>
                            <th>Book</th>
                            <th>Status</th>
                            <th>Fine</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($modalPreviewRows)): ?>
                            <?php foreach ($modalPreviewRows as $row): ?>
                                <?php
                                    $statusVal = strtolower(trim((string)($row['status'] ?? '')));
                                    $statusClass = in_array($statusVal, ['borrowed', 'returned', 'overdue', 'missing'], true) ? $statusVal : 'inactive';
                                    $userLabel = trim((string)($row['borrower_name'] ?? ''));
                                    $userNumber = trim((string)($row['user_number'] ?? ''));
                                    $userDisplay = trim($userLabel . ($userNumber !== '' ? ' (' . $userNumber . ')' : ''));
                                ?>
                                <tr>
                                    <td>#<?= (int)($row['record_id'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars((string)($row['activity_date'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($userDisplay !== '' ? $userDisplay : '-') ?></td>
                                    <td><?= htmlspecialchars((string)($row['title'] ?? '-')) ?></td>
                                    <td><span class="badge status-<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars(ucfirst($statusVal !== '' ? $statusVal : 'unknown')) ?></span></td>
                                    <td><?= number_format((float)($row['fine'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6">No records for this report.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    <?php
    exit;
}

require 'layout_top.php';
?>

<div class="page-top reports-top">
    <div>
        <h1>Reports</h1>
        <p class="page-subtitle">Generate custom PDF reports by date range and report type, then download or print.</p>
    </div>
    <div class="welcome">Period: <?= htmlspecialchars($rangeLabel) ?></div>
</div>

<section class="panel glass-card reports-custom-panel">
    <div class="panel-head">
        <h2>Generate Custom Report</h2>
    </div>
    <form method="GET" class="filters-inline reports-filters reports-custom-form" id="reportsFilters">
        <div class="reports-field-group">
            <label for="reportPeriod">Report Period</label>
            <select name="period" id="reportPeriod" aria-label="Report period">
                <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="quarterly" <?= $period === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                <option value="yearly" <?= $period === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
            </select>
        </div>

        <div class="reports-field-group">
            <label for="reportType">Report Type</label>
            <select name="report_type" id="reportType" aria-label="Report type">
                <?php foreach ($customReportTypeOptions as $typeValue => $typeLabel): ?>
                    <option value="<?= htmlspecialchars((string)$typeValue) ?>" <?= $customReportType === $typeValue ? 'selected' : '' ?>><?= htmlspecialchars((string)$typeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="reports-field-group">
            <label for="reportStatus">Status Filter</label>
            <select name="status" id="reportStatus" aria-label="Status filter">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                <option value="borrowed" <?= $statusFilter === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                <option value="returned" <?= $statusFilter === 'returned' ? 'selected' : '' ?>>Returned</option>
                <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                <option value="missing" <?= $statusFilter === 'missing' ? 'selected' : '' ?>>Missing</option>
            </select>
        </div>

        <div class="reports-field-group report-field report-field-year">
            <label for="reportYear">Year</label>
            <select name="year" id="reportYear" aria-label="Year">
                <?php for ($y = $currentYear + 1; $y >= $currentYear - 8; $y--): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="reports-field-group report-field report-field-month">
            <label for="reportMonth">Month</label>
            <select name="month" id="reportMonth" aria-label="Month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <?php $monthLabel = date('F', strtotime(sprintf('2000-%02d-01', $m))); ?>
                    <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= htmlspecialchars($monthLabel) ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="reports-field-group report-field report-field-quarter">
            <label for="reportQuarter">Quarter</label>
            <select name="quarter" id="reportQuarter" aria-label="Quarter">
                <?php for ($q = 1; $q <= 4; $q++): ?>
                    <option value="<?= $q ?>" <?= $quarter === $q ? 'selected' : '' ?>>Q<?= $q ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="reports-field-group report-field report-field-week">
            <label for="reportWeek">Week</label>
            <select name="week" id="reportWeek" aria-label="Week">
                <?php for ($w = 1; $w <= 53; $w++): ?>
                    <option value="<?= $w ?>" <?= $week === $w ? 'selected' : '' ?>>Week <?= $w ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="reports-field-group report-field report-field-day">
            <label for="reportDay">Day</label>
            <input type="date" name="day" id="reportDay" value="<?= htmlspecialchars($day) ?>" aria-label="Day">
        </div>

        <div class="reports-field-group report-field report-field-from">
            <label for="reportDateFrom">From Date</label>
            <input type="date" name="date_from" id="reportDateFrom" value="<?= htmlspecialchars($dateFrom) ?>" aria-label="From date">
        </div>

        <div class="reports-field-group report-field report-field-to">
            <label for="reportDateTo">To Date</label>
            <input type="date" name="date_to" id="reportDateTo" value="<?= htmlspecialchars($dateTo) ?>" aria-label="To date">
        </div>

        <div class="reports-actions">
            <button type="submit" class="btn-primary reports-icon-btn">
                <span class="reports-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                </span>
                Generate Report
            </button>
            <a class="btn-primary reports-export-link reports-icon-btn" href="<?= htmlspecialchars($downloadReportHref) ?>">
                <span class="reports-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 4v10m0 0l-4-4m4 4l4-4M5 18h14"/></svg>
                </span>
                Download Report
            </a>
            <a class="btn-status activate reports-export-secondary reports-icon-btn" href="<?= htmlspecialchars($printReportHref) ?>" target="_blank" rel="noopener">
                <span class="reports-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 9V4h12v5M6 14H5a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2h-1M7 14h10v6H7z"/></svg>
                </span>
                Print Report
            </a>
            <a href="reports.php" class="filter-reset-btn reports-icon-btn">
                <span class="reports-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 10-2.34 5.66M20 4v7h-7"/></svg>
                </span>
                Reset
            </a>
        </div>
    </form>
</section>

<section class="reports-quick">
    <div class="panel-head reports-quick-head">
        <h2>Quick Reports</h2>
    </div>
    <div class="reports-quick-grid">
        <?php foreach ($quickReports as $card): ?>
            <article class="panel glass-card reports-quick-card">
                <div class="reports-card-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H7zM14 3v6h6M10 13h6M10 17h6"/></svg>
                </div>
                <div class="reports-card-body">
                    <h3><?= htmlspecialchars((string)$card['title']) ?></h3>
                    <p><?= htmlspecialchars((string)$card['description']) ?></p>
                    <div class="reports-card-actions">
                        <button
                            type="button"
                            class="reports-action-link reports-icon-btn js-quick-view"
                            data-preview-url="<?= htmlspecialchars((string)$card['preview_url']) ?>"
                            data-report-title="<?= htmlspecialchars((string)$card['title']) ?>"
                        >
                            <span class="reports-btn-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M2 12s4-6 10-6 10 6 10 6-4 6-10 6-10-6-10-6zM12 9a3 3 0 100 6 3 3 0 000-6z"/></svg>
                            </span>
                            View
                        </button>
                        <a class="btn-status activate reports-icon-btn" href="<?= htmlspecialchars((string)$card['download_url']) ?>">
                            <span class="reports-btn-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M12 4v10m0 0l-4-4m4 4l4-4M5 18h14"/></svg>
                            </span>
                            Download PDF
                        </a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="reports-trend-grid">
    <article class="panel glass-card" id="borrowTrend">
        <div class="panel-head">
            <h2>Borrow Trend (Daily)</h2>
        </div>
        <div class="table-wrap">
            <table class="data-table reports-mini-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Borrow Count</th>
                        <th>Trend</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($borrowTrendDisplayRows)): ?>
                        <?php foreach ($borrowTrendDisplayRows as $row): ?>
                            <?php
                                $borrowValue = (int)($row['total'] ?? 0);
                                $borrowPct = (int)round(($borrowValue / max(1, $maxBorrowTrendDisplay)) * 100);
                                $borrowPct = max(4, min(100, $borrowPct));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$row['bucket_label']) ?></td>
                                <td><?= number_format($borrowValue) ?></td>
                                <td>
                                    <span class="reports-bar-track">
                                        <span class="reports-bar-fill reports-bar-fill-borrow" style="width: <?= $borrowPct ?>%"></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3">No borrow trend data for this range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel glass-card" id="searchTrend">
        <div class="panel-head">
            <h2>Search Trend (Daily)</h2>
        </div>
        <div class="table-wrap">
            <table class="data-table reports-mini-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Search Count</th>
                        <th>Trend</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($searchTrendDisplayRows)): ?>
                        <?php foreach ($searchTrendDisplayRows as $row): ?>
                            <?php
                                $searchValue = (int)($row['total'] ?? 0);
                                $searchPct = (int)round(($searchValue / max(1, $maxSearchTrendDisplay)) * 100);
                                $searchPct = max(4, min(100, $searchPct));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$row['bucket_label']) ?></td>
                                <td><?= number_format($searchValue) ?></td>
                                <td>
                                    <span class="reports-bar-track">
                                        <span class="reports-bar-fill reports-bar-fill-search" style="width: <?= $searchPct ?>%"></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3">No search trend data available for this range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel glass-card" id="programActivity">
        <div class="panel-head">
            <h2>Program Borrow Activity</h2>
        </div>
        <div class="table-wrap">
            <table class="data-table reports-mini-table">
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Borrows</th>
                        <th>Trend</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($programActivityRows)): ?>
                        <?php foreach ($programActivityRows as $row): ?>
                            <?php
                                $programValue = (int)($row['borrow_count'] ?? 0);
                                $programPct = (int)round(($programValue / max(1, $maxProgramActivity)) * 100);
                                $programPct = max(4, min(100, $programPct));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$row['program_name']) ?></td>
                                <td><?= number_format($programValue) ?></td>
                                <td>
                                    <span class="reports-bar-track">
                                        <span class="reports-bar-fill reports-bar-fill-program" style="width: <?= $programPct ?>%"></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3">No program trend data for this range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<div class="overlay-modal reports-preview-modal" id="quickReportModal" aria-hidden="true">
    <div class="overlay-card glass-card reports-preview-card" role="dialog" aria-modal="true" aria-labelledby="quickReportModalTitle">
        <div class="reports-preview-head">
            <h2 id="quickReportModalTitle">Quick Report Preview</h2>
            <button type="button" class="reports-preview-close" id="quickReportModalClose" aria-label="Close preview">&times;</button>
        </div>
        <div class="reports-preview-body" id="quickReportModalBody">
            <p class="reports-preview-loading">Loading report preview...</p>
        </div>
    </div>
</div>

<script>
    (function () {
        var periodSelect = document.getElementById('reportPeriod');
        if (periodSelect) {
            var fields = {
                year: document.querySelector('.report-field-year'),
                month: document.querySelector('.report-field-month'),
                quarter: document.querySelector('.report-field-quarter'),
                week: document.querySelector('.report-field-week'),
                day: document.querySelector('.report-field-day'),
                from: document.querySelector('.report-field-from'),
                to: document.querySelector('.report-field-to')
            };

            function setVisible(fieldNode, visible) {
                if (!fieldNode) return;
                fieldNode.style.display = visible ? '' : 'none';
                var inputs = fieldNode.querySelectorAll('input, select');
                inputs.forEach(function (el) {
                    el.disabled = !visible;
                });
            }

            function syncPeriodFields() {
                var period = periodSelect.value;
                setVisible(fields.year, period === 'weekly' || period === 'monthly' || period === 'quarterly' || period === 'yearly');
                setVisible(fields.month, period === 'monthly');
                setVisible(fields.quarter, period === 'quarterly');
                setVisible(fields.week, period === 'weekly');
                setVisible(fields.day, period === 'daily');
                setVisible(fields.from, period === 'custom');
                setVisible(fields.to, period === 'custom');
            }

            periodSelect.addEventListener('change', syncPeriodFields);
            syncPeriodFields();
        }

        var modal = document.getElementById('quickReportModal');
        var modalBody = document.getElementById('quickReportModalBody');
        var modalTitle = document.getElementById('quickReportModalTitle');
        var modalClose = document.getElementById('quickReportModalClose');
        var quickViewButtons = document.querySelectorAll('.js-quick-view');

        if (!modal || !modalBody || !modalTitle || !quickViewButtons.length) {
            return;
        }

        function openModal() {
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function closeModal() {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        function setLoading() {
            modalBody.innerHTML = '<p class="reports-preview-loading">Loading report preview...</p>';
        }

        quickViewButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var previewUrl = btn.getAttribute('data-preview-url');
                var reportTitle = btn.getAttribute('data-report-title') || 'Quick Report Preview';
                if (!previewUrl) {
                    return;
                }

                modalTitle.textContent = reportTitle;
                setLoading();
                openModal();

                fetch(previewUrl + (previewUrl.indexOf('?') === -1 ? '?' : '&') + '_ts=' + Date.now(), {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                    .then(function (res) {
                        if (!res.ok) {
                            throw new Error('Failed to load report preview.');
                        }
                        return res.text();
                    })
                    .then(function (html) {
                        modalBody.innerHTML = html;
                    })
                    .catch(function () {
                        modalBody.innerHTML = '<p class="reports-preview-error">Unable to load preview. Please try again.</p>';
                    });
            });
        });

        if (modalClose) {
            modalClose.addEventListener('click', closeModal);
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.classList.contains('show')) {
                closeModal();
            }
        });
    }());
</script>

<?php require 'layout_bottom.php'; ?>
