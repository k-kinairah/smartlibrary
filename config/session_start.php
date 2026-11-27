<?php
/**
 * One way to start the session (security review 2026-11-24, batch 4).
 *
 * The cookie flags HttpOnly, SameSite=Lax and strict mode come from
 * .htaccess / .user.ini. "Secure" cannot be set there because the library
 * PC also uses the site over plain http://localhost, where a Secure cookie
 * would never be sent back. So it is set here, per request, only when the
 * request itself is HTTPS. On plain HTTP nothing changes.
 *
 * Every page and endpoint calls smartlib_session_start() instead of
 * session_start().
 */

if (!function_exists('smartlib_request_is_https')) {
    /**
     * True for an HTTPS request: HTTPS set (and not "off"), port 443, or an
     * X-Forwarded-Proto of "https" -- the last only when the request comes
     * from a private or loopback address (a proxy on the host), never from
     * the open internet, where anyone can send that header.
     */
    function smartlib_request_is_https(): bool {
        $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwarded === 'https') {
            $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $isIp = filter_var($remote, FILTER_VALIDATE_IP) !== false;
            $isPublic = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if ($isIp && !$isPublic) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('smartlib_session_start')) {
    /** session_start(), with the Secure cookie flag on HTTPS requests. */
    function smartlib_session_start(): bool {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (smartlib_request_is_https()) {
            ini_set('session.cookie_secure', '1');
        }
        return session_start();
    }
}
