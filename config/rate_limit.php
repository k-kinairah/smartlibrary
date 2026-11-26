<?php
/**
 * Small request counters for abuse ceilings (security review 2026-11-24,
 * SR-12 / SR-13 / SR-18).
 *
 *   smartlib_rate_limit_allow($conn, 'events_session', session_id(), 60, 60)
 *
 * counts one hit for (bucket, key) and returns false when the key already
 * had $limit hits in the last $windowSeconds (the refused hit is not
 * counted). Keys are stored only as a SHA-256 hash, so no session ID, IP
 * address or typed ID number is kept in clear.
 *
 * Rules this file keeps:
 *  - FAIL OPEN. If the table cannot be created (no CREATE privilege), a query
 *    fails or the database hiccups, the hit is allowed and the problem is
 *    written to the PHP error log. A broken limiter must never block a
 *    legitimate patron or librarian.
 *  - The school and its kiosks share one public IP, so callers use per-IP
 *    buckets only as a very high flood ceiling; the real limits are per
 *    session, per patron, per loan or per submitted ID.
 *  - The table is created lazily (CREATE TABLE IF NOT EXISTS, as the auth
 *    tables are); database/rate_limit_hits.sql has the same statement for
 *    phpMyAdmin. Rows older than a day are pruned now and then.
 *  - IP addresses come from REMOTE_ADDR only (forwarded-for headers can be
 *    forged).
 */

const SMARTLIB_RATE_LIMIT_TABLE_SQL = "CREATE TABLE IF NOT EXISTS rate_limit_hits (
    hit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket VARCHAR(40) NOT NULL,
    hit_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (hit_id),
    KEY idx_rate_limit_lookup (bucket, hit_key, created_at),
    KEY idx_rate_limit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

/** The caller's IP for flood ceilings (REMOTE_ADDR only). */
function smartlib_rate_limit_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Creates the counter table once per request; false if that is impossible. */
function smartlib_rate_limit_ensure_table(mysqli $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = (bool)$conn->query(SMARTLIB_RATE_LIMIT_TABLE_SQL);
        if (!$ready) {
            error_log('SmartLib rate limit: could not create rate_limit_hits (allowing requests): ' . $conn->error);
        }
    } catch (Throwable $e) {
        error_log('SmartLib rate limit: could not create rate_limit_hits (allowing requests): ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * How many hits (bucket, key) had in the last $windowSeconds, or null when
 * the counter cannot be read (the caller then allows the request).
 */
function smartlib_rate_limit_count(mysqli $conn, string $bucket, string $key, int $windowSeconds): ?int {
    if (!smartlib_rate_limit_ensure_table($conn)) {
        return null;
    }
    try {
        $hash = hash('sha256', $key);
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM rate_limit_hits
             WHERE bucket = ? AND hit_key = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
        );
        if (!$stmt) {
            throw new RuntimeException('prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('ssi', $bucket, $hash, $windowSeconds);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return (int)$count;
    } catch (Throwable $e) {
        error_log('SmartLib rate limit: count failed for ' . $bucket . ' (allowing request): ' . $e->getMessage());
        return null;
    }
}

/** Records one hit for (bucket, key). Failures are logged and ignored. */
function smartlib_rate_limit_record(mysqli $conn, string $bucket, string $key): void {
    if (!smartlib_rate_limit_ensure_table($conn)) {
        return;
    }
    try {
        $hash = hash('sha256', $key);
        $stmt = $conn->prepare('INSERT INTO rate_limit_hits (bucket, hit_key, created_at) VALUES (?, ?, NOW())');
        if (!$stmt) {
            throw new RuntimeException('prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('ss', $bucket, $hash);
        $stmt->execute();
        $stmt->close();
        // Prune now and then; the longest window in use is one hour.
        if (mt_rand(1, 200) === 1) {
            $conn->query('DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY) LIMIT 5000');
        }
    } catch (Throwable $e) {
        error_log('SmartLib rate limit: record failed for ' . $bucket . ': ' . $e->getMessage());
    }
}

/**
 * Counts this hit and says whether it is within the limit. An empty key
 * (no session, no IP) is never limited. Fails open.
 */
function smartlib_rate_limit_allow(mysqli $conn, string $bucket, string $key, int $limit, int $windowSeconds): bool {
    if ($key === '' || $limit <= 0) {
        return true;
    }
    if (!smartlib_rate_limit_ensure_table($conn)) {
        return true;
    }
    // Insert first, then count the hits up to and including this one (by
    // auto-increment order): parallel requests each get a distinct rank, so
    // a burst can never get more than $limit through. A refused hit removes
    // its own row again, so being refused does not extend the wait.
    try {
        $hash = hash('sha256', $key);
        $ins = $conn->prepare('INSERT INTO rate_limit_hits (bucket, hit_key, created_at) VALUES (?, ?, NOW())');
        if (!$ins) {
            throw new RuntimeException('prepare failed: ' . $conn->error);
        }
        $ins->bind_param('ss', $bucket, $hash);
        $ins->execute();
        $hitId = (int)$ins->insert_id;
        $ins->close();

        $cnt = $conn->prepare(
            'SELECT COUNT(*) FROM rate_limit_hits
             WHERE bucket = ? AND hit_key = ? AND hit_id <= ? AND created_at > (NOW() - INTERVAL ? SECOND)'
        );
        if (!$cnt) {
            throw new RuntimeException('prepare failed: ' . $conn->error);
        }
        $cnt->bind_param('ssii', $bucket, $hash, $hitId, $windowSeconds);
        $cnt->execute();
        $cnt->bind_result($rank);
        $cnt->fetch();
        $cnt->close();

        if (mt_rand(1, 200) === 1) {
            $conn->query('DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY) LIMIT 5000');
        }
        if ((int)$rank > $limit) {
            $del = $conn->prepare('DELETE FROM rate_limit_hits WHERE hit_id = ?');
            if ($del) {
                $del->bind_param('i', $hitId);
                $del->execute();
                $del->close();
            }
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('SmartLib rate limit: ' . $bucket . ' check failed (allowing request): ' . $e->getMessage());
        return true;
    }
}

/** Sends HTTP 429 with a JSON reason and stops. */
function smartlib_rate_limit_refuse(string $message, array $extra = []): void {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge([
        'ok' => false,
        'status' => 'error',
        'error' => 'rate_limited',
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
