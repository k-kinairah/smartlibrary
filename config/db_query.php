<?php
/**
 * Prepared-statement helper (security review 2026-11-24, SR-16).
 *
 * smartlib_db_query($conn, $sql, $params) runs $sql with its ? placeholders
 * bound to $params (ints as 'i', floats as 'd', everything else as 's') and
 * returns what mysqli::query() would: a mysqli_result for a SELECT, true for
 * other statements, false when it fails. With no params it is just
 * $conn->query($sql). Errors behave exactly like $conn->query(): with mysqli
 * exceptions on they are thrown, so every caller keeps its own try/catch,
 * fail-closed handling and safe staff message.
 */

require_once __DIR__ . '/request_input.php'; // smartlib_like_escape()

if (!function_exists('smartlib_db_query')) {
    function smartlib_db_query(mysqli $conn, string $sql, array $params = []) {
        if (empty($params)) {
            return $conn->query($sql);
        }
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        $types = '';
        $values = [];
        foreach (array_values($params) as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
                $value = $value === null ? null : (string)$value;
            }
            $values[] = $value;
        }
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        if ($result === false) {
            // Not a SELECT (INSERT/UPDATE): success, like mysqli::query().
            $ok = $stmt->errno === 0;
            $stmt->close();
            return $ok;
        }
        $stmt->close();
        return $result;
    }
}
