<?php
/**
 * Shared CSRF token helper for admin forms.
 *
 * Each admin page keeps its own session key (so a token from one page
 * can't be replayed on another), but the generation logic used to be
 * copy-pasted into manage_books.php, manage_users.php, and
 * archived_history.php separately. It now lives here once.
 */

if (!function_exists('smartlib_csrf_token')) {
    function smartlib_csrf_token(string $sessionKey): string {
        if (empty($_SESSION[$sessionKey]) || !is_string($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION[$sessionKey];
    }
}

if (!function_exists('smartlib_csrf_verify')) {
    function smartlib_csrf_verify(string $sessionKey, $submittedToken): bool {
        $expected = (string)($_SESSION[$sessionKey] ?? '');
        $submitted = trim((string)($submittedToken ?? ''));

        if ($expected === '' || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}
