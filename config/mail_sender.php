<?php
// Outgoing-email sender identity for every SmartLib email (2FA, sign-up and
// PIN-reset codes, due-date and overdue reminders, borrow receipts).
//
// The real sender lives in config/mail_config.php (gitignored; copy
// config/mail_config.example.php). On a live host it MUST be an address on a
// domain the host is allowed to send for (SPF/DKIM) -- the old hard-coded
// no-reply@smartlib.local only worked locally because XAMPP's sendmail.exe
// relays through a Gmail account, which rewrites the From. A host's own mail
// server passes it through as-is, and receivers reject or spam a .local sender.

if (is_file(__DIR__ . '/mail_config.php')) {
    require_once __DIR__ . '/mail_config.php';
}

/** Address used when nothing is configured: fine for local XAMPP only. */
const SMARTLIB_MAIL_LOCAL_FALLBACK = 'no-reply@smartlib.local';

function smartlib_mail_from_address(): string {
    $configured = defined('SMARTLIB_MAIL_FROM_ADDRESS') ? trim((string)SMARTLIB_MAIL_FROM_ADDRESS) : '';
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
        return $configured;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        // Not the local XAMPP box: say so in the server error log once per
        // request rather than silently sending mail that won't arrive.
        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log('SmartLib mail: SMARTLIB_MAIL_FROM_ADDRESS is not set in config/mail_config.php; '
                . 'falling back to ' . SMARTLIB_MAIL_LOCAL_FALLBACK . ', which receiving servers will reject or spam.');
        }
    }
    return SMARTLIB_MAIL_LOCAL_FALLBACK;
}

function smartlib_mail_is_configured(): bool {
    return smartlib_mail_from_address() !== SMARTLIB_MAIL_LOCAL_FALLBACK;
}

/**
 * From + Reply-To header lines. $displayName is the per-email label
 * ("SMARTLIB", "SmartLib Kiosk"); SMARTLIB_MAIL_FROM_NAME, if set, wins.
 *
 * @return string[]
 */
function smartlib_mail_sender_headers(string $displayName): array {
    $name = defined('SMARTLIB_MAIL_FROM_NAME') && trim((string)SMARTLIB_MAIL_FROM_NAME) !== ''
        ? (string)SMARTLIB_MAIL_FROM_NAME
        : $displayName;
    // Header-safe display name: no CR/LF, quotes or angle brackets.
    $name = trim(preg_replace('/[\r\n"<>]+/', ' ', $name));
    $address = smartlib_mail_from_address();
    $replyTo = defined('SMARTLIB_MAIL_REPLY_TO') ? trim((string)SMARTLIB_MAIL_REPLY_TO) : '';
    if ($replyTo === '' || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $address;
    }
    return [
        'From: ' . ($name !== '' ? $name . ' ' : '') . '<' . $address . '>',
        'Reply-To: ' . $replyTo,
    ];
}

/**
 * mail() with the configured envelope sender (-f), so the Return-Path matches
 * the From domain and SPF can align. Left off on Windows/XAMPP, whose
 * sendmail.exe relay sets its own envelope sender.
 */
function smartlib_mail_envelope_params(string $osFamily = PHP_OS_FAMILY): string {
    if ($osFamily === 'Windows' || !smartlib_mail_is_configured()) {
        return '';
    }
    // The address already passed FILTER_VALIDATE_EMAIL; escape it anyway
    // since this string reaches the sendmail command line.
    return '-f' . escapeshellarg(smartlib_mail_from_address());
}

function smartlib_mail_send(string $to, string $subject, string $message, array $headers): bool {
    $params = smartlib_mail_envelope_params();
    return $params !== ''
        ? (bool)@mail($to, $subject, $message, implode("\r\n", $headers), $params)
        : (bool)@mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * The app's public root URL from SMARTLIB_BASE_URL (e.g.
 * "https://library.school.edu.ph/smartlib"), without a trailing slash, or ''
 * when unset/invalid. Used for every link that goes out in an email, so the
 * link never depends on the Host header a visitor sends.
 */
function smartlib_configured_base_url(): string {
    $raw = defined('SMARTLIB_BASE_URL') ? trim((string)SMARTLIB_BASE_URL) : '';
    if ($raw === '') {
        return '';
    }
    $parts = parse_url($raw);
    $ok = is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host'])
        // A root URL only: no credentials, query string or fragment.
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['query']) && !isset($parts['fragment'])
        // No whitespace, quotes, angle brackets or backslashes.
        && preg_match('/[\s<>"\\\\]/', $raw) === 0;
    if (!$ok) {
        error_log('SmartLib: SMARTLIB_BASE_URL in config/mail_config.php is not a valid http(s) URL; ignoring it.');
        return '';
    }
    return rtrim($raw, '/');
}

/**
 * Whether a 2FA / verification code may be shown on screen as a local-dev
 * fallback. Needs BOTH an explicit opt-in (SMARTLIB_DEV_SHOW_CODES_ON_SCREEN
 * = true in config/mail_config.php) AND a request from this same machine.
 * The localhost check alone wasn't enough: a host that puts a proxy on the
 * same machine in front of PHP makes every visitor's REMOTE_ADDR 127.0.0.1.
 */
function smartlib_dev_show_codes_on_screen(string $clientIp): bool {
    if (!defined('SMARTLIB_DEV_SHOW_CODES_ON_SCREEN') || SMARTLIB_DEV_SHOW_CODES_ON_SCREEN !== true) {
        return false;
    }
    return in_array($clientIp, ['127.0.0.1', '::1'], true);
}
