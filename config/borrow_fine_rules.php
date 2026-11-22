<?php
// Overdue fines can be switched off (the default) on admin/settings.php;
// see config/library_settings.php (smartlib_fines_settings()).
require_once __DIR__ . '/library_settings.php';

if (!function_exists('smartlib_fine_rate_per_business_day')) {
    /** The default rate (env SMARTLIB_FINE_PER_BUSINESS_DAY, else 10.00); the saved setting wins, see smartlib_fines_settings(). */
    function smartlib_fine_rate_per_business_day(): float {
        return smartlib_fine_default_rate();
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
    /**
     * Weekdays late up to $asOfDateRaw. With $startDateRaw (the day fines
     * were switched on) only weekdays after that day count: counting starts
     * at max(due date + 1, start date + 1), so nothing accrues for the time
     * fines were off, and switching on today charges nothing until tomorrow.
     */
    function smartlib_business_days_late(string $dueDateRaw, string $asOfDateRaw, ?string $startDateRaw = null): int {
        $dueDate = smartlib_parse_ymd($dueDateRaw);
        $asOfDate = smartlib_parse_ymd($asOfDateRaw);

        if (!$dueDate || !$asOfDate || $asOfDate <= $dueDate) {
            return 0;
        }

        $cursor = $dueDate->modify('+1 day');
        $startDate = $startDateRaw !== null ? smartlib_parse_ymd($startDateRaw) : null;
        if ($startDate) {
            $firstChargeable = $startDate->modify('+1 day');
            if ($firstChargeable > $cursor) {
                $cursor = $firstChargeable;
            }
        }
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
    function smartlib_overdue_fine_amount(string $dueDateRaw, string $asOfDateRaw, ?float $dailyRate = null, ?string $startDateRaw = null): float {
        $rate = $dailyRate ?? smartlib_fine_rate_per_business_day();
        if ($rate <= 0) {
            return 0.0;
        }

        $daysLate = smartlib_business_days_late($dueDateRaw, $asOfDateRaw, $startDateRaw);
        if ($daysLate <= 0) {
            return 0.0;
        }

        return round($daysLate * $rate, 2);
    }
}


if (!function_exists('smartlib_loan_policy_for_role')) {
    function smartlib_loan_policy_for_role(string $role): array {
        $role = strtolower(trim($role));
        $policies = [
            'student' => ['days' => 7, 'max_borrows' => 3, 'max_renewals' => 1],
            'faculty' => ['days' => 30, 'max_borrows' => 5, 'max_renewals' => 1],
            'librarian' => ['days' => 7, 'max_borrows' => 5, 'max_renewals' => 1],
            'admin' => ['days' => 7, 'max_borrows' => 5, 'max_renewals' => 1]
        ];

        return $policies[$role] ?? $policies['student'];
    }
}

if (!function_exists('smartlib_ensure_borrow_renewal_columns')) {
    function smartlib_ensure_borrow_renewal_columns(mysqli $conn): void {
        // 2026-10-10: hardcoded -- see index.php for why. Both columns are
        // confirmed present in smartlib_FULL_BACKUP_20261010.sql, so the
        // ALTER TABLE calls below correctly never run.
        $renewCountExists = true;
        $lastRenewedExists = true;

        if (!$renewCountExists) {
            $conn->query("ALTER TABLE borrow_records ADD COLUMN renew_count INT NOT NULL DEFAULT 0 AFTER status");
        }

        if (!$lastRenewedExists) {
            $conn->query("ALTER TABLE borrow_records ADD COLUMN last_renewed_at DATETIME NULL AFTER renew_count");
        }
    }
}
if (!function_exists('sync_overdue_status_and_fines')) {
    /**
     * Flips borrowed <-> overdue by due date and keeps the fine column in
     * step. With fines OFF (library_settings, the default) only the status
     * is written; the fine column is never touched. With fines ON the saved
     * rate applies, counting only days late after fines_start_date; loans
     * returned before that date keep the fine stored for them (history is
     * never rewritten by switching fines on).
     */
    function sync_overdue_status_and_fines(mysqli $conn, ?int $userId = null): void {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $fines = smartlib_fines_settings($conn);
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

        $updateStmt = $fines['enabled']
            ? $conn->prepare(
                "UPDATE borrow_records
                 SET status = ?, fine = ?
                 WHERE record_id = ?"
            )
            : $conn->prepare(
                "UPDATE borrow_records
                 SET status = ?
                 WHERE record_id = ?"
            );

        if (!$updateStmt) {
            return;
        }

        $rate = $fines['rate'];
        $startDate = $fines['start_date'];

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
                if ($fines['enabled'] && $startDate !== null && $effectiveDate < $startDate) {
                    continue;
                }
            } else {
                $due = smartlib_parse_ymd($dueDateRaw);
                $todayDt = smartlib_parse_ymd($today);
                $isOverdue = ($due && $todayDt && $todayDt > $due);
                $targetStatus = $isOverdue ? 'overdue' : 'borrowed';
                $effectiveDate = $today;
            }

            if (!$fines['enabled']) {
                // Fines off: status only, the fine column stays as it is.
                if ($targetStatus !== $currentStatus) {
                    $updateStmt->bind_param('si', $targetStatus, $recordId);
                    $updateStmt->execute();
                }
                continue;
            }

            $targetFine = smartlib_overdue_fine_amount($dueDateRaw, $effectiveDate, $rate, $startDate);

            if ($targetStatus !== $currentStatus || abs($targetFine - $currentFine) > 0.009) {
                $updateStmt->bind_param('sdi', $targetStatus, $targetFine, $recordId);
                $updateStmt->execute();
            }
        }

        $updateStmt->close();
    }
}

