<?php
/*
 * Top Borrowers (Nov 2026): the students and faculty with the most
 * borrow_records rows. Shared by the Dashboard panel, Reports (quick
 * report, Full/Monthly Summary, PDF) and the Data Export CSV. Staff pages
 * only: the rows carry names and ID numbers.
 *
 * Rules (agreed with the librarian):
 *  - Only library_users.role 'student' and 'faculty' are ranked.
 *  - Every borrow_records row counts as one checkout, whatever its status
 *    (borrowed, returned, overdue, missing), unless a status is passed.
 *  - Ties: more borrows, then the most recent borrow date, then name A-Z,
 *    then user_id. Ranks are plain 1..N in the returned order.
 *  - The borrow date is COALESCE(br.date_borrowed, DATE(br.created_at)),
 *    the same expression admin/reports.php filters on.
 */

require_once __DIR__ . '/../../config/library_card_helpers.php';
require_once __DIR__ . '/../../config/db_query.php';

if (!function_exists('smartlib_top_borrowers_date')) {
    function smartlib_top_borrowers_date(?string $value): ?string {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $value : null;
    }
}

if (!function_exists('smartlib_top_borrowers')) {
    /**
     * @return array<int, array{user_id:int, borrower_number:string, borrower_name:string, role:string, program_name:string, borrow_count:int, last_borrowed:string}>
     */
    function smartlib_top_borrowers(mysqli $conn, ?string $from, ?string $to, string $status = 'all', int $limit = 10): array {
        $from = smartlib_top_borrowers_date($from);
        $to = smartlib_top_borrowers_date($to);
        $status = strtolower(trim($status));
        if (!in_array($status, ['borrowed', 'returned', 'overdue', 'missing'], true)) {
            $status = 'all';
        }
        $limit = max(1, min(500, $limit));

        $dateExpr = 'COALESCE(br.date_borrowed, DATE(br.created_at))';
        $where = ["u.role IN ('student','faculty')"];
        $params = []; // prepared (SR-16)
        if ($from !== null) {
            $where[] = "{$dateExpr} >= ?";
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = "{$dateExpr} <= ?";
            $params[] = $to;
        }
        if ($status !== 'all') {
            $where[] = "br.status = ?";
            $params[] = $status;
        }

        $sql = "SELECT u.user_id,
                       COALESCE(u.user_number, '') AS borrower_number,
                       TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS borrower_name,
                       u.role,
                       -- program_name can now hold a faculty/staff department (department first),
                       -- else a Junior/Senior High student's level, else the program
                       COALESCE(NULLIF(d.department_name, ''), " . library_card_school_level_label_sql('u.school_level') . ", p.program_name, '') AS program_name,
                       COUNT(br.record_id) AS borrow_count,
                       MAX({$dateExpr}) AS last_borrowed
                FROM borrow_records br
                INNER JOIN library_users u ON u.user_id = br.user_id
                LEFT JOIN programs p ON p.program_id = u.program_id
                LEFT JOIN departments d ON d.department_id = u.department_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY u.user_id, u.user_number, u.first_name, u.last_name, u.role, p.program_name, d.department_name, u.school_level
                HAVING COUNT(br.record_id) > 0
                ORDER BY borrow_count DESC, last_borrowed DESC, borrower_name ASC, u.user_id ASC
                LIMIT {$limit}";

        try {
            $res = smartlib_db_query($conn, $sql, $params);
            if (!$res) {
                return [];
            }
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    'user_id' => (int)$row['user_id'],
                    'borrower_number' => (string)$row['borrower_number'],
                    'borrower_name' => (string)$row['borrower_name'],
                    'role' => strtolower((string)$row['role']),
                    'program_name' => (string)$row['program_name'],
                    'borrow_count' => (int)$row['borrow_count'],
                    'last_borrowed' => (string)($row['last_borrowed'] ?? ''),
                ];
            }
            $res->free();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}
