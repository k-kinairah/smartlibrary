-- SmartLib: request counters for abuse ceilings (added 2026-11-26, security batch 3)
-- Safe to run more than once (IF NOT EXISTS).
--
-- config/rate_limit.php creates this table by itself the first time a
-- limiter runs. Run this file in phpMyAdmin only if the hosting database
-- user is not allowed to CREATE tables; until the table exists the limits
-- are simply not applied (the site keeps working, and the PHP error log
-- notes it). Rows hold a bucket name, a SHA-256 hash of the key (session,
-- patron, loan, submitted ID or IP address) and a time -- nothing in clear.
-- Rows older than a day are pruned automatically.

CREATE TABLE IF NOT EXISTS rate_limit_hits (
    hit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket VARCHAR(40) NOT NULL,
    hit_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (hit_id),
    KEY idx_rate_limit_lookup (bucket, hit_key, created_at),
    KEY idx_rate_limit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
