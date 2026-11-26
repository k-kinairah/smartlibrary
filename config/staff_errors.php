<?php
/**
 * What a staff page shows when a save fails (security review 2026-11-24,
 * SR-20).
 *
 * The admin pages throw RuntimeException on purpose with a message written
 * for the librarian ("Missing accession number for copy #2.", "Unable to
 * save book details.") -- those are shown as before. Anything unexpected --
 * a database error (mysqli_sql_exception, which carries SQL and table
 * details), a PHP error, any other exception class -- is written to the PHP
 * error log with its detail, and the page shows a plain message instead.
 */
function smartlib_staff_error_message(Throwable $e, string $generic, string $context = ''): string {
    $intended = get_class($e) === RuntimeException::class;
    if ($intended) {
        $message = trim((string)$e->getMessage());
        return $message !== '' ? $message : $generic;
    }
    error_log('SmartLib staff error' . ($context !== '' ? ' (' . $context . ')' : '') . ': '
        . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    return $generic;
}

/**
 * A friendly text for a duplicate-key database error on books / copies, or
 * '' when the error is something else.
 */
function smartlib_staff_duplicate_message(Throwable $e, string $isbnText, string $accessionText): string {
    $raw = (string)$e->getMessage();
    if (stripos($raw, 'duplicate') === false) {
        return '';
    }
    if (stripos($raw, 'isbn') !== false) {
        return $isbnText;
    }
    if (stripos($raw, 'accession') !== false) {
        return $accessionText;
    }
    return '';
}
