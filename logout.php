<?php
// Sign-out. Ends the session only for a POST from this site that carries a
// valid token: the kiosk token (account menu, kiosk-idle.js) or the staff
// token (admin pages). Anything else -- a plain GET from a link or an image
// tag, or a post from another site -- changes nothing and just goes to the
// kiosk home. (Security review 2026-11-24, SR-17.)
session_start();
require_once __DIR__ . '/config/request_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && smartlib_request_form_ok([SMARTLIB_KIOSK_CSRF_KEY, SMARTLIB_STAFF_CSRF_KEY])) {
    session_destroy();
}
header('Location: index.php');
exit;
