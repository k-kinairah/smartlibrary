<?php

if (!function_exists('smartlib_fine_rate_per_business_day')) {
    function smartlib_fine_rate_per_business_day(): float {
        $envValue = getenv('SMARTLIB_FINE_PER_BUSINESS_DAY');
        if ($envValue !== false && is_numeric($envValue)) {
            $rate = (float)$envValue;
            if ($rate >= 0) {
                return round($rate, 2);
            }
        }

        return 10.00;
    }
}

if (!function_exists('smartlib_parse_ymd')) {
    function smartlib_parse_ymd(string $raw): ?DateTimeImmutable {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $datePart = substr($raw, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart)) {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
        if (!$dt) {
            return null;
        }

        return $dt->setTime(0, 0, 0);
    }
}

if (!function_exists('smartlib_business_days_late')) {
    function smartlib_business_days_late(string $dueDateRaw, string $asOfDateRaw): int {
        $dueDate = smartlib_parse_ymd($dueDateRaw);
        $asOfDate = smartlib_parse_ymd($asOfDateRaw);

        if (!$dueDate || !$asOfDate || $asOfDate <= $dueDate) {
            return 0;
        }

        $cursor = $dueDate->modify('+1 day');
        $daysLate = 0;

        while ($cursor <= $asOfDate) {
            $dayOfWeek = (int)$cursor->format('N');
            if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
                $daysLate++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $daysLate;
    }
}

if (!function_exists('smartlib_overdue_fine_amount')) {
    function smartlib_overdue_fine_amount(string $dueDateRaw, string $asOfDateRaw, ?float $dailyRate = null): float {
        $rate = $dailyRate ?? smartlib_fine_rate_per_business_day();
        if ($rate <= 0) {
            return 0.0;
        }

        $daysLate = smartlib_business_days_late($dueDateRaw, $asOfDateRaw);
        if ($daysLate <= 0) {
            return 0.0;
        }

        return round($daysLate * $rate, 2);
    }
}

if (!function_exists('sync_overdue_status_and_fines')) {
    function sync_overdue_status_and_fines(mysqli $conn, ?int $userId = null): void {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $whereUser = ($userId !== null && $userId > 0) ? ' AND user_id = ' . (int)$userId : '';

        $sql = "
            SELECT record_id, status, due_date, date_returned, fine
            FROM borrow_records
            WHERE due_date IS NOT NULL
              AND status IN ('borrowed', 'overdue', 'returned')
              {$whereUser}
        ";

        $res = $conn->query($sql);
        if (!$res) {
            return;
        }

        $updateStmt = $conn->prepare(
            "UPDATE borrow_records
             SET status = ?, fine = ?
             WHERE record_id = ?"
        );

        if (!$updateStmt) {
            return;
        }

        $rate = smartlib_fine_rate_per_business_day();

        while ($row = $res->fetch_assoc()) {
            $recordId = (int)($row['record_id'] ?? 0);
            if ($recordId <= 0) {
                continue;
            }

            $currentStatus = strtolower(trim((string)($row['status'] ?? 'borrowed')));
            $dueDateRaw = (string)($row['due_date'] ?? '');
            $returnDateRaw = trim((string)($row['date_returned'] ?? ''));
            $currentFine = round((float)($row['fine'] ?? 0), 2);

            if ($dueDateRaw === '') {
                continue;
            }

            $targetStatus = $currentStatus;
            $effectiveDate = $today;

            if ($currentStatus === 'returned' && $returnDateRaw !== '') {
                $effectiveDate = substr($returnDateRaw, 0, 10);
                $targetStatus = 'returned';
            } else {
                $due = smartlib_parse_ymd($dueDateRaw);
                $todayDt = smartlib_parse_ymd($today);
                $isOverdue = ($due && $todayDt && $todayDt > $due);
                $targetStatus = $isOverdue ? 'overdue' : 'borrowed';
                $effectiveDate = $today;
            }

            $targetFine = smartlib_overdue_fine_amount($dueDateRaw, $effectiveDate, $rate);

            if ($targetStatus !== $currentStatus || abs($targetFine - $currentFine) > 0.009) {
                $updateStmt->bind_param('sdi', $targetStatus, $targetFine, $recordId);
                $updateStmt->execute();
            }
        }

        $updateStmt->close();
    }
}

