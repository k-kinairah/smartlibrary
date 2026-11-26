<?php
/**
 * Anti-forgery checks for endpoints that change data (security review
 * 2026-11-24, SR-01). Builds on config/csrf.php:
 *
 *  - smartlib_kiosk_csrf_token(): the kiosk token. index.php and the other
 *    kiosk pages print it (window.SMARTLIB_KIOSK_CSRF, data-csrf on the
 *    kiosk-idle.js tag); filters.js sends it with every state-changing call
 *    as the X-CSRF-Token header. Kept in the session, so it survives
 *    session_regenerate_id() at sign-in and 2FA.
 *  - smartlib_staff_csrf_token(): the same for the admin pages
 *    (<meta name="smartlib-csrf"> in admin/layout_top.php).
 *  - smartlib_request_guard(): refuses a request whose Origin header names
 *    another site, a JSON endpoint called without a JSON body, or a missing
 *    or wrong token, with HTTP 403 and
 *      {"ok":false,"status":"error","error":"csrf"|"origin"|"content_type","message":"..."}
 *    The kiosk script reloads the page once on error "csrf" so the visitor
 *    gets a fresh token instead of a dead button.
 */

require_once __DIR__ . '/csrf.php';

const SMARTLIB_KIOSK_CSRF_KEY = 'kiosk_csrf';
const SMARTLIB_STAFF_CSRF_KEY = 'staff_csrf';

function smartlib_kiosk_csrf_token(): string {
    return smartlib_csrf_token(SMARTLIB_KIOSK_CSRF_KEY);
}

function smartlib_staff_csrf_token(): string {
    return smartlib_csrf_token(SMARTLIB_STAFF_CSRF_KEY);
}

/**
 * True when the request carries an Origin header that is not this site.
 * Host names only (scheme and port ignored); the literal "null" (sandboxed
 * frames, some redirects, file://) counts as foreign. No Origin header at
 * all is allowed: older browsers and same-origin GET-to-POST form posts
 * may leave it out, and the token check still applies.
 */
function smartlib_request_origin_is_foreign(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin === null) {
        return false;
    }
    $origin = trim((string)$origin);
    if ($origin === '' ) {
        return false;
    }
    if (strtolower($origin) === 'null') {
        return true;
    }
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $ownHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    // Strip a port, keeping bracketed IPv6 literals intact.
    if (preg_match('/^(\[[^\]]+\]|[^:]+)(:\d+)?$/', $ownHost, $m)) {
        $ownHost = $m[1];
    }
    $originHost = trim($originHost, '[]');
    $ownHost = trim($ownHost, '[]');
    return $originHost === '' || $ownHost === '' || $originHost !== $ownHost;
}

/** The token the caller sent: X-CSRF-Token header, csrf_token field, or csrf_token in a JSON body. */
function smartlib_request_submitted_token(?array $jsonBody = null): string {
    $header = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header !== '') {
        return $header;
    }
    if (is_array($jsonBody) && isset($jsonBody['csrf_token']) && is_string($jsonBody['csrf_token'])) {
        return $jsonBody['csrf_token'];
    }
    $posted = $_POST['csrf_token'] ?? '';
    return is_string($posted) ? $posted : '';
}

/** Whether the request body is declared as JSON. */
function smartlib_request_is_json(): bool {
    $type = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
    return $type === 'application/json' || strpos($type, 'application/json;') === 0;
}

/** Sends the 403 refusal and stops. */
function smartlib_request_refuse(string $reason): void {
    $messages = [
        'csrf' => 'This page has expired. Please try again.',
        'origin' => 'This request did not come from SmartLib.',
        'content_type' => 'Unsupported request format.',
    ];
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'error' => $reason,
        'message' => $messages[$reason] ?? 'Request refused.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Runs every check for one endpoint, in this order: Origin, Content-Type
 * (JSON endpoints, before anything reads the body), token. $sessionKeys
 * lists the token(s) accepted (kiosk, staff, or both). Returns the decoded
 * JSON body for JSON endpoints (null otherwise) so the caller does not read
 * php://input twice.
 */
function smartlib_request_guard(array $sessionKeys, bool $json = false): ?array {
    if (smartlib_request_origin_is_foreign()) {
        smartlib_request_refuse('origin');
    }
    $body = null;
    if ($json) {
        if (!smartlib_request_is_json()) {
            smartlib_request_refuse('content_type');
        }
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    }
    $submitted = smartlib_request_submitted_token($body);
    foreach ($sessionKeys as $key) {
        if (smartlib_csrf_verify((string)$key, $submitted)) {
            return $body;
        }
    }
    smartlib_request_refuse('csrf');
    return null;
}

/**
 * Non-exiting variant for classic form pages (admin/borrow_records.php):
 * true when the Origin is ours and the token matches.
 */
function smartlib_request_form_ok(array $sessionKeys): bool {
    if (smartlib_request_origin_is_foreign()) {
        return false;
    }
    $submitted = smartlib_request_submitted_token(null);
    foreach ($sessionKeys as $key) {
        if (smartlib_csrf_verify((string)$key, $submitted)) {
            return true;
        }
    }
    return false;
}
