<?php
/**
 * Library-wide settings, stored as key/value rows in library_settings
 * (database/library_settings_setup.sql). Edited on admin/settings.php.
 *
 * The READ path never creates the table: a database without it (the live
 * one, until the first save in Settings) simply gets the defaults, which
 * means overdue fines are OFF. smartlib_setting_set() creates the table
 * (CREATE TABLE IF NOT EXISTS) the first time something is saved.
 *
 * Keys in use:
 *   fines_enabled     '1' / '0'  (missing = off)
 *   fines_rate        amount per weekday late, e.g. '10.00'
 *   fines_start_date  'Y-m-d' (Asia/Manila): fines only count days late
 *                     from this day; reset to today on every off -> on.
 */

if (!function_exists('smartlib_settings_ensure_table')) {
    function smartlib_settings_ensure_table(mysqli $conn): bool {
        try {
            return (bool)$conn->query(
                "CREATE TABLE IF NOT EXISTS library_settings (
                    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    setting_value TEXT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by INT UNSIGNED NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('smartlib_settings_all')) {
    /**
     * Every stored setting (per-request cache). A missing table, or any
     * read error, gives an empty list -- i.e. the defaults.
     *
     * @return array<string, ?string>
     */
    function smartlib_settings_all(mysqli $conn, bool $reload = false): array {
        static $cache = null;
        if ($reload) {
            $cache = null;
        }
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        try {
            $res = $conn->query('SELECT setting_key, setting_value FROM library_settings');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cache[(string)$row['setting_key']] = $row['setting_value'] === null ? null : (string)$row['setting_value'];
                }
                $res->free();
            }
        } catch (Throwable $e) {
            // Table missing (not created yet) or unreadable: use defaults.
            $cache = [];
        }
        return $cache;
    }
}

if (!function_exists('smartlib_setting_get')) {
    function smartlib_setting_get(mysqli $conn, string $key, ?string $default = null): ?string {
        $all = smartlib_settings_all($conn);
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}

if (!function_exists('smartlib_setting_set')) {
    /** Saves one setting (creating the table if needed). */
    function smartlib_setting_set(mysqli $conn, string $key, ?string $value, ?int $updatedBy = null): bool {
        if (!smartlib_settings_ensure_table($conn)) {
            return false;
        }
        try {
            $stmt = $conn->prepare(
                'INSERT INTO library_settings (setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
            );
            if (!$stmt) {
                return false;
            }
            $by = ($updatedBy !== null && $updatedBy > 0) ? $updatedBy : null;
            $stmt->bind_param('ssi', $key, $value, $by);
            $ok = $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            return false;
        }
        smartlib_settings_all($conn, true);
        return $ok;
    }
}

if (!function_exists('smartlib_fine_default_rate')) {
    /** SMARTLIB_FINE_PER_BUSINESS_DAY from the environment, else 10.00. */
    function smartlib_fine_default_rate(): float {
        $envValue = getenv('SMARTLIB_FINE_PER_BUSINESS_DAY');
        if ($envValue !== false && is_numeric($envValue) && (float)$envValue >= 0) {
            return round((float)$envValue, 2);
        }
        return 10.00;
    }
}

if (!function_exists('smartlib_today_manila')) {
    function smartlib_today_manila(): string {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
}

if (!function_exists('smartlib_fines_settings')) {
    /**
     * @return array{enabled: bool, rate: float, start_date: ?string}
     *   enabled     default false (fines off)
     *   rate        amount per weekday late
     *   start_date  'Y-m-d' or null: only days late from this day count
     */
    function smartlib_fines_settings(mysqli $conn): array {
        $all = smartlib_settings_all($conn);

        $rate = smartlib_fine_default_rate();
        $rawRate = $all['fines_rate'] ?? null;
        if ($rawRate !== null && is_numeric($rawRate) && (float)$rawRate >= 0) {
            $rate = round((float)$rawRate, 2);
        }

        $start = trim((string)($all['fines_start_date'] ?? ''));
        $startOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1
            && DateTimeImmutable::createFromFormat('Y-m-d', $start) !== false;

        return [
            'enabled' => ($all['fines_enabled'] ?? '0') === '1',
            'rate' => $rate,
            'start_date' => $startOk ? $start : null,
        ];
    }
}

if (!function_exists('smartlib_fines_enabled')) {
    function smartlib_fines_enabled(mysqli $conn): bool {
        return smartlib_fines_settings($conn)['enabled'];
    }
}
