<?php
/**
 * Safe reads of request parameters (security review 2026-11-24, SR-07).
 *
 * PHP turns ?search[]=x into an array. Code that expects a string and calls
 * trim() on it then dies with a TypeError (a blank page or a 500 with a file
 * path), and a (string) cast prints an "Array to string conversion" warning.
 * These helpers treat a value of the wrong shape as empty instead.
 */

/**
 * A scalar parameter as a string: '' when it is missing or not a string
 * (an array, for example). With $maxLen > 0 the value is cut to at most that
 * many bytes without splitting a UTF-8 character.
 */
function smartlib_request_string(array $source, string $key, int $maxLen = 0): string {
    $value = $source[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    if ($maxLen > 0 && strlen($value) > $maxLen) {
        $value = mb_strcut($value, 0, $maxLen, 'UTF-8');
    }
    return $value;
}

/**
 * A list parameter (genre[]=a&genre[]=b) as a list of strings. Elements
 * that are not strings (nested arrays) are dropped; a plain string value is
 * not a list and gives []. At most $maxItems elements are kept.
 */
function smartlib_request_string_list(array $source, string $key, int $maxItems = 200): array {
    $value = $source[$key] ?? [];
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $out[] = $item;
            if (count($out) >= $maxItems) {
                break;
            }
        }
    }
    return $out;
}

/**
 * An integer parameter: $default when it is missing or not a number (an
 * array such as book_id[]=1 is not read as 1). Digits with an optional
 * leading minus, like an <input type="number">; JSON numbers are accepted.
 */
function smartlib_request_int(array $source, string $key, int $default = 0): int {
    $value = $source[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value) && is_finite($value)) {
        return (int)$value;
    }
    if (is_string($value) && preg_match('/^\s*-?\d{1,18}\s*$/', $value)) {
        return (int)$value;
    }
    return $default;
}
