-- SmartLib library-wide settings (admin/settings.php, config/library_settings.php)
-- Run this in phpMyAdmin SQL tab while database "smartlib" is selected.
-- Optional: the Settings page also creates this table on its first save.
-- Without the table every setting uses its default (overdue fines OFF).
--
-- Keys in use:
--   fines_enabled     '1' / '0'
--   fines_rate        amount per weekday late, e.g. '10.00'
--   fines_start_date  'YYYY-MM-DD': fines only count days late from this day

CREATE TABLE IF NOT EXISTS library_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
