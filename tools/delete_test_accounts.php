<?php
declare(strict_types=1);

// Deletes test/seed accounts cleanly -- for the pre-launch test-data wipes.
//
// Deleting a library_users row by hand means deleting its borrow_records
// first (the foreign key is RESTRICT), and an earlier wipe that did exactly
// that left the copies on those open loans stuck as "borrowed" with no loan
// behind them, and left no audit record. This tool does the whole job:
//
//   * open loans (borrowed / overdue): the copy goes back to "available" if
//     it is still marked "borrowed" and no other open loan holds it;
//   * missing loans: the copy stays "lost" (the book really is gone);
//   * returned loans: the copy is not touched;
//   * deletes the account's loans, their reminder-log rows, favorites, and
//     pending sign-in / PIN-reset / signup-verification rows, then the account;
//   * door entry-log rows are kept unless --purge-entry-logs is passed: the
//     database unlinks them (user_id -> NULL) so entry reports keep the count,
//     but each row still holds the ID number typed at the door -- purge them
//     when wiping test data so test scans don't count toward launch reports;
//   * writes one "deleted" entry per account to the admin activity log
//     (Archived History), listing what was removed and which copies were reset.
//     It is recorded under the --by name exactly as given (linked to that
//     librarian's account when the name matches one), else "System Admin";
//     the metadata notes it came from this tool.
//
// SAFE BY DEFAULT: dry-run unless --commit is passed. A dry run only reads
// the database and prints exactly what --commit would do. Each account is
// deleted in its own transaction, so a failure leaves that account untouched.
// Librarian/admin accounts are refused unless --allow-staff is passed, and
// never if it would leave no active librarian.
//
// Take a database backup before a real run (see the smartlib_backup_*.sql
// files in the project root for the naming used so far).
//
// Usage:
//   php tools/delete_test_accounts.php --id=<user_id> [--id=...] [--user-number=<ID>] [...]
//       [--by="Your Name"] [--purge-entry-logs] [--allow-staff] [--commit]
//
// Example:
//   php tools/delete_test_accounts.php --user-number=20232245 --user-number=20232338
//   php tools/delete_test_accounts.php --user-number=20232245 --user-number=20232338 --by="Kin Ira" --commit

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/admin_audit.php';
require_once __DIR__ . '/../config/library_card_helpers.php';

$commit = in_array('--commit', $argv, true);
$allowStaff = in_array('--allow-staff', $argv, true);
$purgeEntryLogs = in_array('--purge-entry-logs', $argv, true);
$byName = '';
$ids = [];
$userNumbers = [];

foreach (array_slice($argv, 1) as $arg) {
    $arg = (string)$arg;
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $ids[] = (int)$m[1];
    } elseif (preg_match('/^--user-number=(.+)$/', $arg, $m)) {
        $userNumbers[] = library_card_normalize_id($m[1]);
    } elseif (preg_match('/^--by=(.+)$/', $arg, $m) && trim($m[1]) !== '') {
        $byName = trim($m[1]);
    } elseif (!in_array($arg, ['--commit', '--allow-staff', '--purge-entry-logs'], true)) {
        fwrite(STDERR, "Unknown argument: {$arg}" . PHP_EOL);
        exit(1);
    }
}

if (empty($ids) && empty($userNumbers)) {
    fwrite(STDERR, 'Usage: php tools/delete_test_accounts.php --id=<user_id> | --user-number=<ID> [...] [--by="Name"] [--purge-entry-logs] [--allow-staff] [--commit]' . PHP_EOL);
    exit(1);
}

/** All rows of a prepared single-int-parameter query. */
function dta_rows(mysqli $conn, string $sql, int $id): array {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function dta_count(mysqli $conn, string $sql, int $id, string $userNumber = ''): int {
    $stmt = $conn->prepare($sql);
    if ($userNumber !== '') {
        $stmt->bind_param('is', $id, $userNumber);
    } else {
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $count;
}

/**
 * Everything --commit would do for one account, read-only. Dry run prints
 * this; commit executes exactly this plan.
 */
function dta_plan(mysqli $conn, array $user, bool $purgeEntryLogs): array {
    $userId = (int)$user['user_id'];
    $userNumber = (string)$user['user_number'];

    $loans = dta_rows($conn,
        "SELECT br.record_id, br.copy_id, br.status, br.fine, bc.accession_no, bc.status AS copy_status, b.title,
                (SELECT COUNT(*) FROM borrow_records o
                  WHERE o.copy_id = br.copy_id AND o.user_id <> br.user_id
                    AND o.status IN ('borrowed', 'overdue', 'missing')) AS other_open_loans
           FROM borrow_records br
           LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
           LEFT JOIN books b ON b.book_id = bc.book_id
          WHERE br.user_id = ?
          ORDER BY br.record_id", $userId);

    $copyActions = [];
    $loanCounts = ['returned' => 0, 'open' => 0, 'missing' => 0];
    foreach ($loans as $loan) {
        $status = strtolower((string)$loan['status']);
        $label = sprintf('%s "%s" (loan #%d, %s)', $loan['accession_no'] ?: ('copy #' . $loan['copy_id']), $loan['title'] ?? '?', $loan['record_id'], $status);

        if ($status === 'returned') {
            $loanCounts['returned']++;
            continue;
        }
        if ($status === 'missing') {
            $loanCounts['missing']++;
            $copyActions[] = ['copy_id' => (int)$loan['copy_id'], 'reset' => false, 'text' => "keep as {$loan['copy_status']}: {$label} -- missing book"];
            continue;
        }

        $loanCounts['open']++;
        if ($loan['copy_status'] === 'borrowed' && (int)$loan['other_open_loans'] === 0) {
            $copyActions[] = ['copy_id' => (int)$loan['copy_id'], 'reset' => true, 'accession_no' => (string)$loan['accession_no'], 'text' => "reset borrowed -> available: {$label}"];
        } else {
            $why = (int)$loan['other_open_loans'] > 0 ? 'another open loan holds it' : "copy is already {$loan['copy_status']}";
            $copyActions[] = ['copy_id' => (int)$loan['copy_id'], 'reset' => false, 'text' => "leave as {$loan['copy_status']} ({$why}): {$label}"];
        }
    }

    return [
        'loans' => $loans,
        'loan_counts' => $loanCounts,
        'copy_actions' => $copyActions,
        'related' => [
            'due_soon_reminder_logs' => dta_count($conn, 'SELECT COUNT(*) FROM due_soon_reminder_logs WHERE user_id = ?', $userId),
            'overdue_reminder_logs' => dta_count($conn, 'SELECT COUNT(*) FROM overdue_reminder_logs WHERE user_id = ?', $userId),
            'favorites' => dta_count($conn, 'SELECT COUNT(*) FROM favorites WHERE user_id = ?', $userId),
            'auth_2fa_challenges' => dta_count($conn, 'SELECT COUNT(*) FROM auth_2fa_challenges WHERE user_id = ? OR user_number = ?', $userId, $userNumber),
            'auth_pin_reset_tokens' => dta_count($conn, 'SELECT COUNT(*) FROM auth_pin_reset_tokens WHERE user_id = ? OR user_number = ?', $userId, $userNumber),
            'auth_signup_verifications' => dta_count($conn, 'SELECT COUNT(*) FROM auth_signup_verifications WHERE user_id = ? OR user_number = ?', $userId, $userNumber),
        ],
        'entry_logs' => dta_count($conn, 'SELECT COUNT(*) FROM library_entry_logs WHERE user_id = ?', $userId),
        'purge_entry_logs' => $purgeEntryLogs,
    ];
}

function dta_exec(mysqli $conn, string $sql, string $types, ...$params): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('prepare failed: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

// ---- Resolve the accounts ------------------------------------------------

$users = [];
$lookup = $conn->prepare('SELECT user_id, user_number, first_name, last_name, email, role, status FROM library_users WHERE user_id = ? OR user_number = ? LIMIT 1');
foreach (array_merge(array_map(fn($i) => ['id', $i], $ids), array_map(fn($n) => ['num', $n], $userNumbers)) as [$kind, $value]) {
    $id = $kind === 'id' ? (int)$value : 0;
    $num = $kind === 'num' ? (string)$value : "\0";
    $lookup->bind_param('is', $id, $num);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    if (!$row) {
        fwrite(STDERR, "No account matches " . ($kind === 'id' ? "--id={$value}" : "--user-number={$value}") . ' -- nothing done.' . PHP_EOL);
        exit(1);
    }
    $users[(int)$row['user_id']] = $row;
}

$staff = array_filter($users, fn($u) => in_array(strtolower((string)$u['role']), ['librarian', 'admin'], true));
if (!empty($staff)) {
    if (!$allowStaff) {
        fwrite(STDERR, 'Refusing: these are librarian/admin accounts (pass --allow-staff if that is really intended):' . PHP_EOL);
        foreach ($staff as $u) {
            fwrite(STDERR, "  #{$u['user_id']} {$u['user_number']} {$u['first_name']} {$u['last_name']} ({$u['role']})" . PHP_EOL);
        }
        exit(1);
    }
    $placeholders = implode(',', array_map('intval', array_keys($users)));
    $remaining = (int)$conn->query("SELECT COUNT(*) FROM library_users WHERE role IN ('librarian','admin') AND status = 'active' AND user_id NOT IN ({$placeholders})")->fetch_row()[0];
    if ($remaining === 0) {
        fwrite(STDERR, 'Refusing: this would leave no active librarian/admin account.' . PHP_EOL);
        exit(1);
    }
}

// ---- Plan / execute -------------------------------------------------------

echo 'Mode: ' . ($commit ? 'COMMIT (writing to the database)' : 'DRY RUN (nothing will be saved -- pass --commit to actually delete)') . PHP_EOL;
$actor = smartlib_admin_audit_set_cli_actor($conn, $byName);
echo 'Recorded as: ' . $actor['name'] . ($actor['user_id'] > 0 ? " (librarian account #{$actor['user_id']})" : '') . PHP_EOL;
if ($byName === '') {
    echo '  (no --by given -- pass --by="Your Name" so the log shows who ran this)' . PHP_EOL;
}
echo 'Accounts: ' . count($users) . PHP_EOL . PHP_EOL;

if ($commit) {
    // Run the audit table's CREATE/ALTER now: DDL commits any open
    // transaction, so it must not happen inside the per-account ones.
    smartlib_admin_audit_ensure_table($conn);
}

$totals = ['accounts' => 0, 'loans' => 0, 'copies_reset' => 0, 'failed' => 0];

foreach ($users as $userId => $user) {
    $name = trim($user['first_name'] . ' ' . $user['last_name']);
    echo "--- #{$userId} {$user['user_number']} {$name} ({$user['role']}, {$user['status']}, " . ($user['email'] ?: 'no email') . ') ---' . PHP_EOL;

    $plan = dta_plan($conn, $user, $purgeEntryLogs);
    $lc = $plan['loan_counts'];
    echo sprintf('  loans to delete: %d (returned %d, open %d, missing %d)', count($plan['loans']), $lc['returned'], $lc['open'], $lc['missing']) . PHP_EOL;
    foreach ($plan['copy_actions'] as $action) {
        echo '    copy: ' . $action['text'] . PHP_EOL;
    }
    foreach ($plan['related'] as $table => $count) {
        if ($count > 0) {
            echo "  {$table}: delete {$count}" . PHP_EOL;
        }
    }
    if ($plan['entry_logs'] > 0) {
        echo "  library_entry_logs: {$plan['entry_logs']} -- " . ($purgeEntryLogs ? 'delete' : 'keep, unlinked from the account but still holding the ID number (pass --purge-entry-logs to delete)') . PHP_EOL;
    }

    if (!$commit) {
        echo '  account: would delete' . PHP_EOL . PHP_EOL;
        $totals['accounts']++;
        $totals['loans'] += count($plan['loans']);
        $totals['copies_reset'] += count(array_filter($plan['copy_actions'], fn($a) => $a['reset']));
        continue;
    }

    $resetAccessions = [];
    $conn->begin_transaction();
    try {
        foreach ($plan['copy_actions'] as $action) {
            if (!$action['reset']) {
                continue;
            }
            // Re-check the conditions at write time, not just at plan time.
            $done = dta_exec($conn,
                "UPDATE book_copies bc SET bc.status = 'available'
                  WHERE bc.copy_id = ? AND bc.status = 'borrowed'
                    AND NOT EXISTS (SELECT 1 FROM borrow_records o
                                     WHERE o.copy_id = bc.copy_id AND o.user_id <> ?
                                       AND o.status IN ('borrowed', 'overdue', 'missing'))",
                'ii', $action['copy_id'], $userId);
            if ($done === 1) {
                $resetAccessions[] = $action['accession_no'];
            }
        }

        dta_exec($conn, 'DELETE FROM due_soon_reminder_logs WHERE user_id = ?', 'i', $userId);
        dta_exec($conn, 'DELETE FROM overdue_reminder_logs WHERE user_id = ?', 'i', $userId);
        $loansDeleted = dta_exec($conn, 'DELETE FROM borrow_records WHERE user_id = ?', 'i', $userId);
        dta_exec($conn, 'DELETE FROM favorites WHERE user_id = ?', 'i', $userId);
        $num = (string)$user['user_number'];
        dta_exec($conn, 'DELETE FROM auth_2fa_challenges WHERE user_id = ? OR user_number = ?', 'is', $userId, $num);
        dta_exec($conn, 'DELETE FROM auth_pin_reset_tokens WHERE user_id = ? OR user_number = ?', 'is', $userId, $num);
        dta_exec($conn, 'DELETE FROM auth_signup_verifications WHERE user_id = ? OR user_number = ?', 'is', $userId, $num);
        $entryDeleted = $purgeEntryLogs ? dta_exec($conn, 'DELETE FROM library_entry_logs WHERE user_id = ?', 'i', $userId) : 0;
        $accountDeleted = dta_exec($conn, 'DELETE FROM library_users WHERE user_id = ?', 'i', $userId);
        if ($accountDeleted !== 1) {
            throw new RuntimeException('account row was not deleted');
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $totals['failed']++;
        echo '  FAILED, rolled back (nothing changed for this account): ' . $e->getMessage() . PHP_EOL . PHP_EOL;
        continue;
    }

    smartlib_admin_audit_log($conn, 'deleted', 'user', $userId, $name, [
        'user_number' => (string)$user['user_number'],
        'email' => (string)$user['email'],
        'role' => (string)$user['role'],
        'status' => (string)$user['status'],
        'via' => 'tools/delete_test_accounts.php',
        'loans_deleted' => $loansDeleted,
        'loan_breakdown' => $lc,
        'copies_reset_to_available' => $resetAccessions,
        'related_rows_deleted' => $plan['related'],
        'entry_logs' => $purgeEntryLogs ? ['deleted' => $entryDeleted] : ['kept_unlinked' => $plan['entry_logs']],
    ]);

    echo '  DELETED. Copies reset: ' . (empty($resetAccessions) ? 'none' : implode(', ', $resetAccessions)) . PHP_EOL . PHP_EOL;
    $totals['accounts']++;
    $totals['loans'] += $loansDeleted;
    $totals['copies_reset'] += count($resetAccessions);
}

echo sprintf('%s: %d account(s), %d loan(s), %d cop%s reset to available%s.',
    $commit ? 'Done' : 'Dry run total',
    $totals['accounts'], $totals['loans'], $totals['copies_reset'], $totals['copies_reset'] === 1 ? 'y' : 'ies',
    $totals['failed'] > 0 ? ", {$totals['failed']} FAILED" : '') . PHP_EOL;
if (!$commit) {
    echo 'Nothing was changed. Re-run with --commit to apply.' . PHP_EOL;
}
exit($totals['failed'] > 0 ? 1 : 0);
