<?php
declare(strict_types=1);

// Clears the admin activity log (Archived History / "Audit Log" page,
// table admin_activity_logs) -- meant for one clean start at the Nov 13
// handover, so the librarian doesn't inherit months of test entries.
//
//   1. Backs up every row and column to a timestamped .sql file OUTSIDE the
//      web root (default: <xampp>/smartlib_backups/, next to htdocs, never
//      inside it), then re-reads the file to check it holds every row.
//   2. Deletes exactly the rows that were backed up, in one transaction --
//      anything logged after the backup started is kept -- and rolls back
//      if the count doesn't match.
//   3. Writes one fresh entry recording the clear (how many entries, their
//      date range, the backup file name and checksum), unless --no-marker.
//
// SAFE BY DEFAULT: dry-run unless --commit is passed. A dry run only reads
// the database and prints what would happen; it writes no file either.
// --backup-only writes the backup file and changes nothing in the database.
//
// Nothing references this table by foreign key (the tool re-checks and
// refuses if that ever changes). Note: clearing removes the Archives page's
// "Retrieve" button for deleted books, which works from these entries.
//
// Restore (if ever needed), into an empty table or a scratch database:
//   C:\xampp\mysql\bin\mysql.exe -u root -p smartlib < <backup file>.sql
//
// Usage:
//   php tools/clear_audit_log.php [--by="Your Name"] [--backup-only | --commit]
//       [--no-marker] [--backup-dir=<folder outside htdocs>]
//
// Example (handover day):
//   php tools/clear_audit_log.php                        (dry run: read the summary)
//   php tools/clear_audit_log.php --by="Kin Ira" --commit

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/admin_audit.php';

const CAL_TABLE = 'admin_activity_logs';

$commit = in_array('--commit', $argv, true);
$backupOnly = in_array('--backup-only', $argv, true);
$noMarker = in_array('--no-marker', $argv, true);
$byName = '';
$webRoot = dirname(__DIR__, 2);                       // ...\htdocs
$backupDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'smartlib_backups';

foreach (array_slice($argv, 1) as $arg) {
    $arg = (string)$arg;
    if (preg_match('/^--by=(.+)$/', $arg, $m)) {
        $byName = trim($m[1]);
    } elseif (preg_match('/^--backup-dir=(.+)$/', $arg, $m)) {
        $backupDir = rtrim(trim($m[1]), '\\/');
    } elseif (!in_array($arg, ['--commit', '--backup-only', '--no-marker'], true)) {
        fwrite(STDERR, "Unknown argument: {$arg}" . PHP_EOL);
        exit(1);
    }
}

if ($commit && $backupOnly) {
    fwrite(STDERR, 'Pass either --backup-only or --commit, not both.' . PHP_EOL);
    exit(1);
}

/** Lower-cased absolute path with forward slashes, for prefix checks. */
function cal_norm_path(string $path): string {
    $real = realpath($path);
    return strtolower(str_replace('\\', '/', $real !== false ? $real : $path));
}

function cal_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// ---- Safety checks --------------------------------------------------------

$dbName = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
$exists = (int)$conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . CAL_TABLE . "'")->fetch_row()[0];
if ($exists !== 1) {
    cal_fail('Table ' . CAL_TABLE . " does not exist in {$dbName} -- nothing to clear.");
}

$fkRows = $conn->query(
    "SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
      WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '" . CAL_TABLE . "'"
)->fetch_all(MYSQLI_ASSOC);
if (!empty($fkRows)) {
    $list = implode(', ', array_map(fn($r) => $r['TABLE_NAME'] . ' (' . $r['CONSTRAINT_NAME'] . ')', $fkRows));
    cal_fail("Refusing: other tables reference " . CAL_TABLE . " by foreign key: {$list}. Handle those first.");
}

$backupDirNorm = cal_norm_path(is_dir($backupDir) ? $backupDir : dirname($backupDir));
if (str_starts_with($backupDirNorm . '/', cal_norm_path($webRoot) . '/')) {
    cal_fail("Refusing: backup folder {$backupDir} is inside the web root ({$webRoot}). Choose a folder outside htdocs.");
}

$stats = $conn->query('SELECT COUNT(*) AS n, COALESCE(MAX(activity_id), 0) AS max_id, MIN(created_at) AS oldest, MAX(created_at) AS newest FROM ' . CAL_TABLE)->fetch_assoc();
$rowCount = (int)$stats['n'];
$maxId = (int)$stats['max_id'];

$mode = $commit ? 'COMMIT (backing up, then clearing the log)' : ($backupOnly ? 'BACKUP ONLY (writes the backup file, database unchanged)' : 'DRY RUN (nothing will be saved -- pass --commit to clear, or --backup-only to just back up)');
echo 'Mode: ' . $mode . PHP_EOL;
echo "Database: {$dbName}, table " . CAL_TABLE . PHP_EOL;
echo "Entries: {$rowCount}" . ($rowCount > 0 ? " (#1-#{$maxId} range, {$stats['oldest']} to {$stats['newest']})" : '') . PHP_EOL;
$byType = $conn->query('SELECT action_type, COUNT(*) AS n FROM ' . CAL_TABLE . ' GROUP BY action_type ORDER BY n DESC')->fetch_all(MYSQLI_ASSOC);
if (!empty($byType)) {
    echo '  ' . implode(', ', array_map(fn($r) => $r['action_type'] . ' ' . $r['n'], $byType)) . PHP_EOL;
}
$retrievable = (int)$conn->query("SELECT COUNT(*) FROM " . CAL_TABLE . " WHERE action_type = 'deleted' AND entity_type = 'book' AND JSON_EXTRACT(metadata_json, '$.restored_book_id') IS NULL")->fetch_row()[0];
if ($retrievable > 0) {
    echo "  Note: {$retrievable} deleted book(s) can currently be retrieved from Archives; clearing removes that option (the backup keeps their details)." . PHP_EOL;
}
echo 'Foreign keys referencing it: none' . PHP_EOL;

$stamp = date('Ymd_His');
$backupFile = $backupDir . DIRECTORY_SEPARATOR . "audit_log_backup_{$dbName}_{$stamp}.sql";
echo 'Backup file: ' . $backupFile . PHP_EOL;

if (!$commit && !$backupOnly) {
    echo PHP_EOL . 'Dry run: nothing was written. ' . ($rowCount === 0 ? 'The log is already empty.' : "--commit would back up and remove these {$rowCount} entries" . ($noMarker ? '.' : ', then log one entry recording the clear.')) . PHP_EOL;
    exit(0);
}

// ---- Backup ---------------------------------------------------------------

if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    cal_fail("Could not create backup folder {$backupDir}.");
}

$createSql = (string)$conn->query('SHOW CREATE TABLE ' . CAL_TABLE)->fetch_row()[1];
$createSql = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $createSql, 1);
$columns = array_column($conn->query('SHOW COLUMNS FROM ' . CAL_TABLE)->fetch_all(MYSQLI_ASSOC), 'Field');
$colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

$tmpFile = $backupFile . '.part';
$fh = fopen($tmpFile, 'wb');
if (!$fh) {
    cal_fail("Could not write {$tmpFile}.");
}
fwrite($fh, "-- SmartLib audit log backup (" . CAL_TABLE . ")\n");
fwrite($fh, "-- Database: {$dbName}; taken " . date('Y-m-d H:i:s') . " by tools/clear_audit_log.php\n");
fwrite($fh, "-- Rows: {$rowCount} (activity_id <= {$maxId}). Restore into an empty table or a scratch database.\n");
fwrite($fh, "SET NAMES utf8mb4;\nSET time_zone = '+08:00';\n\n{$createSql};\n\n");

$written = 0;
$res = $conn->query('SELECT * FROM ' . CAL_TABLE . " WHERE activity_id <= {$maxId} ORDER BY activity_id", MYSQLI_USE_RESULT);
$batch = [];
$flush = static function () use (&$batch, $fh, $colList): void {
    if (!empty($batch)) {
        fwrite($fh, 'INSERT INTO `' . CAL_TABLE . "` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
};
while ($row = $res->fetch_assoc()) {
    $values = [];
    foreach ($columns as $c) {
        $values[] = $row[$c] === null ? 'NULL' : "'" . $conn->real_escape_string((string)$row[$c]) . "'";
    }
    $batch[] = '(' . implode(', ', $values) . ')';
    $written++;
    if (count($batch) >= 200) {
        $flush();
    }
}
$res->free();
$flush();
fwrite($fh, "\n-- End of backup: {$written} rows.\n");
fclose($fh);

// Re-read the file and count the row tuples, so a truncated write is caught.
$check = file_get_contents($tmpFile);
$tuplesInFile = preg_match_all('/^\(\'\d+\', /m', (string)$check);
if ($written !== $rowCount || $tuplesInFile !== $rowCount || strpos((string)$check, "-- End of backup: {$written} rows.") === false) {
    @unlink($tmpFile);
    cal_fail("Backup check failed (expected {$rowCount} rows, wrote {$written}, file holds {$tuplesInFile}). Nothing was cleared.");
}
if (!rename($tmpFile, $backupFile)) {
    cal_fail("Could not finalise {$backupFile}. Nothing was cleared.");
}
$sha = hash_file('sha256', $backupFile);
echo "Backup written: {$written} rows, " . number_format((int)filesize($backupFile)) . " bytes, sha256 {$sha}" . PHP_EOL;

if ($backupOnly) {
    echo 'Backup only: the database was not changed.' . PHP_EOL;
    exit(0);
}

// ---- Clear ----------------------------------------------------------------

// The marker entry goes through smartlib_admin_audit_log(), whose table
// check runs DDL; DDL commits any open transaction, so do it up front.
smartlib_admin_audit_ensure_table($conn);
$actor = smartlib_admin_audit_set_cli_actor($conn, $byName);

$conn->begin_transaction();
try {
    $stmt = $conn->prepare('DELETE FROM ' . CAL_TABLE . ' WHERE activity_id <= ?');
    $stmt->bind_param('i', $maxId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    if ($deleted !== $rowCount) {
        throw new RuntimeException("expected to remove {$rowCount} entries, the database reported {$deleted}");
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    cal_fail('FAILED, rolled back -- the log is unchanged: ' . $e->getMessage() . PHP_EOL . "(The backup file was still written: {$backupFile})");
}

echo "Cleared: {$deleted} entries removed." . PHP_EOL;

if (!$noMarker) {
    smartlib_admin_audit_log($conn, 'deleted', 'audit_log', 0, "Audit log cleared ({$deleted} entries)", [
        'entries_removed' => $deleted,
        'oldest_removed' => (string)$stats['oldest'],
        'newest_removed' => (string)$stats['newest'],
        'backup_file' => basename($backupFile),
        'backup_sha256' => $sha,
        'via' => 'tools/clear_audit_log.php',
    ]);
    echo 'Logged one entry recording the clear, as: ' . $actor['name'] . PHP_EOL;
}
if ($byName === '') {
    echo '(no --by given -- recorded as "System Admin"; pass --by="Your Name" next time)' . PHP_EOL;
}
exit(0);
