<?php
// Same rules as ../logout.php: only a POST from this site with a valid
// token ends the session; a GET changes nothing. Lands on the kiosk home
// (../index.php -- there is no admin/index.php).
session_start();
require_once __DIR__ . '/../config/request_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && smartlib_request_form_ok([SMARTLIB_KIOSK_CSRF_KEY, SMARTLIB_STAFF_CSRF_KEY])) {
    session_unset();
    session_destroy();
}
header('Location: ../index.php');
exit;
