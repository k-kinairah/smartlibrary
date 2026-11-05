<?php
// SmartLib outgoing-email settings -- template.
//
// Copy this file to config/mail_config.php (gitignored, like db_config.php)
// and fill in the real values. Read by config/mail_sender.php.

// The address every SmartLib email is sent from. On a live host this MUST be
// a real mailbox on a domain the host can send for (set up SPF/DKIM in the
// hosting panel), e.g. library@your-school-domain.edu.ph. Leave it empty on a
// local XAMPP install that relays through Gmail.
define('SMARTLIB_MAIL_FROM_ADDRESS', '');

// Optional: one display name for every email. Empty keeps the per-email
// names ("SMARTLIB" for account emails, "SmartLib Kiosk" for kiosk emails).
define('SMARTLIB_MAIL_FROM_NAME', '');

// Optional: where replies go (e.g. the librarian's inbox). Empty = the From
// address above.
define('SMARTLIB_MAIL_REPLY_TO', '');

// The app's public root URL, used for links in emails (e.g. the PIN-reset
// link), e.g. 'https://library.your-school-domain.edu.ph/smartlib' -- no
// trailing slash needed. On a live host this MUST be set: without it the link
// is built from the request's Host header, which a visitor can forge to point
// reset links at their own site. Leave empty on local XAMPP.
define('SMARTLIB_BASE_URL', '');

// LOCAL DEVELOPMENT ONLY. When true, a sign-in 2FA code is also shown on the
// login screen -- and only for requests from this same machine -- so you are
// not locked out when email isn't set up. Never true on a live host.
define('SMARTLIB_DEV_SHOW_CODES_ON_SCREEN', false);
