<?php
session_start();
require 'config/db_connect.php';
require 'config/auth_security.php';

if (!auth_ensure_reset_table($conn)) {
    http_response_code(500);
    echo 'PIN reset is unavailable right now.';
    exit;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$messageType = 'info';
$done = false;

// Librarian/admin accounts set a real password here (they sign in with a
// password plus 2FA); students and faculty set a numeric PIN as before.
$linkRow = ($token !== '' && strlen($token) >= 20) ? auth_get_valid_reset_token($conn, $token) : null;
$isStaffReset = $linkRow ? auth_user_is_staff($conn, (int)($linkRow['user_id'] ?? 0)) : false;
$secretWord = $isStaffReset ? 'password' : 'PIN';
$newSecretError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPin = trim((string)($_POST['new_pin'] ?? ''));
    $confirmPin = trim((string)($_POST['confirm_pin'] ?? ''));

    if ($token === '' || strlen($token) < 20) {
        $message = 'Invalid reset link. Please request a new PIN reset.';
        $messageType = 'error';
    } else {
        $tokenRow = auth_get_valid_reset_token($conn, $token);
        if (!$tokenRow) {
            $message = 'This reset link is invalid, expired, or already used. Request a new one.';
            $messageType = 'error';
        } elseif ($newPin === '' || $confirmPin === '') {
            $message = "Please fill in both {$secretWord} fields.";
            $messageType = 'error';
        } elseif ($newPin !== $confirmPin) {
            $message = ucfirst($secretWord) . ' confirmation does not match.';
            $messageType = 'error';
        } elseif (auth_user_is_staff($conn, (int)($tokenRow['user_id'] ?? 0))
            && ($staffProblem = auth_staff_password_problem($newPin, (string)($tokenRow['user_number'] ?? ''))) !== '') {
            $message = $staffProblem;
            $messageType = 'error';
            $newSecretError = true;
        } elseif (!auth_user_is_staff($conn, (int)($tokenRow['user_id'] ?? 0)) && !preg_match('/^\d{4,}$/', $newPin)) {
            $message = 'PIN must be at least 4 digits.';
            $messageType = 'error';
            $newSecretError = true;
        } else {
            $userId = (int)($tokenRow['user_id'] ?? 0);
            $tokenId = (int)($tokenRow['token_id'] ?? 0);

            if ($userId <= 0 || $tokenId <= 0) {
                $message = 'Reset token is not valid anymore. Please request a new one.';
                $messageType = 'error';
            } else {
                $passwordHash = password_hash($newPin, PASSWORD_DEFAULT);

                $update = $conn->prepare('UPDATE library_users SET password = ? WHERE user_id = ? LIMIT 1');
                if (!$update) {
                    $message = 'Unable to reset PIN right now. Please try again later.';
                    $messageType = 'error';
                } else {
                    $update->bind_param('si', $passwordHash, $userId);
                    $ok = $update->execute();
                    $affected = $update->affected_rows;
                    $update->close();

                    if (!$ok) {
                        $message = 'Unable to reset PIN right now. Please try again later.';
                        $messageType = 'error';
                    } else {
                        auth_mark_reset_token_used($conn, $tokenId);
                        auth_disable_other_reset_tokens($conn, $userId);

                        $close2fa = $conn->prepare('UPDATE auth_2fa_challenges SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
                        if ($close2fa) {
                            $close2fa->bind_param('i', $userId);
                            $close2fa->execute();
                            $close2fa->close();
                        }

                        $message = $affected >= 0
                            ? "Your {$secretWord} has been reset successfully. You can now sign in."
                            : ucfirst($secretWord) . ' updated. You can now sign in.';
                        $messageType = 'success';
                        $done = true;
                    }
                }
            }
        }
    }
}

if ($token === '') {
    $message = $message !== '' ? $message : 'Missing reset token. Open the link from your email.';
    $messageType = 'error';
}

// ---- Presentation only below: nothing here changes what the handler above
// accepts, rejects or writes. ------------------------------------------------

// A link that can no longer be used (missing, malformed, expired or already
// used) gets its own screen instead of a form that can never succeed. This
// reuses the same read-only check the handler runs on submit.
$linkDead = !$done && (
    $token === ''
    || strlen($token) < 20
    || auth_get_valid_reset_token($conn, $token) === null
);
if ($linkDead && $message === '') {
    $message = 'This reset link is invalid, expired, or already used. Request a new one.';
    $messageType = 'error';
}

// Which field a server-side PIN error belongs to, so it can be outlined.
$fieldError = ['new' => false, 'confirm' => false];
if (!$linkDead && $messageType === 'error') {
    if ($message === "Please fill in both {$secretWord} fields.") {
        $fieldError = ['new' => true, 'confirm' => true];
    } elseif ($message === ucfirst($secretWord) . ' confirmation does not match.') {
        $fieldError['confirm'] = true;
    } elseif ($newSecretError) {
        $fieldError['new'] = true;
    }
}

// After a successful reset, open the kiosk sign-in. ?signin=<anything> just
// opens the sign-in modal; the link never names a role.
$signinHref = 'index.php?signin=1';

$messageIcons = [
    'success' => '<path d="m6.5 12.5 3.6 3.6 7.4-8"></path>',
    'error' => '<path d="M12 4.5 21 19H3L12 4.5Z"></path><path d="M12 10v4"></path><path d="M12 16.8h.01"></path>',
    'info' => '<path d="M12 11v6"></path><path d="M12 7.3h.01"></path>',
];
$logoVer = @filemtime(__DIR__ . '/assets/images/stjude_logo.jpg') ?: 1;

$secretTitle = ucfirst($secretWord);
$secretRule = $isStaffReset
    ? 'At least ' . AUTH_STAFF_PASSWORD_MIN . ' characters, with a letter and a number'
    : 'At least 4 digits, numbers only';
$secretAttrs = $isStaffReset
    ? 'autocomplete="new-password" maxlength="' . AUTH_STAFF_PASSWORD_MAX_BYTES . '"'
    : 'inputmode="numeric" pattern="[0-9]{4,}" autocomplete="new-password" maxlength="20"';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>SmartLib | Reset PIN</title>
    <link rel="icon" href="assets/images/stjude_seal_64.png" type="image/png">
    <style>
        :root {
            --text: #eaf6ef;
            --muted: #c8ddd1;
            --soft: #9fc2b0;
            --line: rgba(194, 224, 207, 0.28);
            --ok: #8ff0b6;
            --ok-bg: rgba(98, 209, 145, 0.16);
            --ok-line: rgba(98, 209, 145, 0.5);
            --bad: #ff9a9a;
            --bad-bg: rgba(255, 107, 107, 0.18);
            --bad-line: rgba(255, 107, 107, 0.65);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
            background: radial-gradient(circle at 20% 20%, #1f6c50 0%, #0d2f24 45%, #071a14 100%);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
        }
        .card {
            width: min(440px, 100%);
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(34, 77, 60, 0.6);
            box-shadow: 0 18px 40px rgba(2, 12, 8, 0.4);
            padding: 24px 22px 20px;
        }

        /* Brand header: the same school seal and name as the admin sidebar,
           so a patron can tell this is the real SmartLib page. */
        .brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            text-align: center;
            padding-bottom: 18px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--line);
        }
        .brand img {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.4);
            background: #ffffff;
            flex-shrink: 0;
        }
        .brand-name {
            margin: 0;
            font-size: 22px;
            line-height: 1.1;
            font-weight: 800;
            letter-spacing: 0.01em;
        }
        .brand-sub {
            margin: 3px 0 0;
            font-size: 12px;
            color: var(--soft);
        }

        h1 {
            margin: 0 0 6px;
            font-size: 26px;
        }
        p.sub {
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }

        /* Status banner: ONE component for every message. All variants share
           the base below and only swap the three accent variables. The fill
           is a dark neutral (not a translucent tint, which turned red into a
           muddy brown over this green), with the accent carried by the left
           bar, the icon chip and the border. */
        .msg {
            --accent: #7cb8ff;
            --accent-soft: rgba(124, 184, 255, 0.18);
            --accent-line: rgba(124, 184, 255, 0.5);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
            padding: 13px 15px 13px 13px;
            border-radius: 12px;
            border: 1px solid var(--accent-line);
            border-left: 4px solid var(--accent);
            background:
                linear-gradient(90deg, var(--accent-soft) 0%, rgba(0, 0, 0, 0) 75%),
                rgba(5, 16, 12, 0.62);
            color: #f4fbf7;
            font-size: 14px;
            line-height: 1.5;
        }
        .msg-icon {
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--accent-soft);
            color: var(--accent);
        }
        .msg-icon svg {
            width: 17px;
            height: 17px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .msg-text {
            padding-top: 4px;
            min-width: 0;
        }
        .msg.success {
            --accent: #5fe39c;
            --accent-soft: rgba(95, 227, 156, 0.17);
            --accent-line: rgba(95, 227, 156, 0.45);
        }
        .msg.error {
            --accent: #ff6b6b;
            --accent-soft: rgba(255, 107, 107, 0.18);
            --accent-line: rgba(255, 107, 107, 0.55);
        }
        .msg.info {
            --accent: #7cb8ff;
            --accent-soft: rgba(124, 184, 255, 0.18);
            --accent-line: rgba(124, 184, 255, 0.5);
        }

        .help {
            margin: -4px 0 16px;
            font-size: 13px;
            line-height: 1.5;
            color: var(--muted);
        }
        .help strong { color: var(--text); }

        .field {
            margin-bottom: 14px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            color: #d3e8dc;
            font-size: 13px;
            font-weight: 700;
        }
        input {
            width: 100%;
            border-radius: 10px;
            border: 1px solid rgba(196, 225, 208, 0.35);
            background: rgba(230, 242, 236, 0.14);
            color: #f2fbf6;
            padding: 11px 12px;
            outline: none;
            font-size: 16px; /* 16px stops iOS zooming in on focus */
            letter-spacing: 0.08em;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input::placeholder {
            color: #bbd3c6;
            letter-spacing: normal;
        }
        input:focus {
            border-color: rgba(150, 230, 190, 0.7);
            box-shadow: 0 0 0 3px rgba(98, 209, 145, 0.18);
        }
        .field.is-ok input { border-color: var(--ok-line); }
        .field.is-bad input { border-color: var(--bad-line); }
        .field.is-bad input:focus { box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.22); }

        .password-field-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-field-wrap input {
            padding-right: 46px;
            margin-bottom: 0;
        }
        .password-toggle-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border-radius: 999px;
            border: 1px solid rgba(184, 211, 196, 0.42);
            background: rgba(214, 233, 223, 0.14);
            color: #d9efe4;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            cursor: pointer;
        }
        .password-toggle-btn:hover {
            background: rgba(205, 229, 217, 0.24);
            border-color: rgba(196, 225, 208, 0.55);
        }
        .password-toggle-btn svg {
            width: 17px;
            height: 17px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .password-toggle-btn .icon-eye-off {
            display: none;
        }
        .password-toggle-btn.is-visible .icon-eye {
            display: none;
        }
        .password-toggle-btn.is-visible .icon-eye-off {
            display: block;
        }

        /* Live rule line under each field: neutral, then a check or a cross. */
        .rule {
            display: flex;
            align-items: center;
            gap: 6px;
            min-height: 18px;
            margin-top: 6px;
            font-size: 12.5px;
            color: var(--soft);
        }
        .rule::before {
            content: "";
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            border-radius: 50%;
            border: 1.5px solid currentColor;
            opacity: 0.7;
            background-position: center;
            background-repeat: no-repeat;
            background-size: 10px 10px;
        }
        .rule.is-ok { color: var(--ok); }
        .rule.is-ok::before {
            opacity: 1;
            border-color: var(--ok-line);
            background-color: var(--ok-bg);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'%3E%3Cpath d='M2 5.2 4.1 7.3 8 3' fill='none' stroke='%238ff0b6' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }
        .rule.is-bad { color: var(--bad); }
        .rule.is-bad::before {
            opacity: 1;
            border-color: var(--bad-line);
            background-color: var(--bad-bg);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'%3E%3Cpath d='M3 3l4 4M7 3 3 7' fill='none' stroke='%23ff9a9a' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E");
        }
        /* Hidden rule still holds its line, so the card doesn't jump when it appears. */
        .rule[hidden] { display: flex; visibility: hidden; }

        .btn {
            display: block;
            width: 100%;
            border: none;
            border-radius: 10px;
            background: #1b8b4e;
            color: #ffffff;
            font-weight: 700;
            padding: 12px 14px;
            margin-top: 6px;
            cursor: pointer;
            font-size: 15px;
            text-align: center;
            text-decoration: none;
            font-family: inherit;
        }
        .btn:hover {
            filter: brightness(1.06);
        }
        .btn:focus-visible,
        .link:focus-visible,
        .password-toggle-btn:focus-visible {
            outline: 2px solid #b7ffd1;
            outline-offset: 2px;
        }
        .link {
            display: inline-block;
            margin-top: 14px;
            color: #c8e4d6;
            text-decoration: none;
            font-size: 13px;
        }
        .link:hover {
            text-decoration: underline;
        }

        @media (max-width: 380px) {
            .card { padding: 20px 16px 18px; }
            h1 { font-size: 23px; }
        }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">
        <img src="assets/images/stjude_logo.jpg?v=<?= (int)$logoVer ?>" alt="St. Jude logo">
        <div>
            <p class="brand-name">SmartLib</p>
            <p class="brand-sub">Self-Service Library System</p>
        </div>
    </div>

    <h1>Reset <?= htmlspecialchars($secretTitle) ?></h1>
    <?php if (!$done && !$linkDead): ?>
        <?php if ($isStaffReset): ?>
            <p class="sub">Use a new password: at least <?= (int)AUTH_STAFF_PASSWORD_MIN ?> characters with a letter and a number. This reset link can only be used once.</p>
        <?php else: ?>
            <p class="sub">Use a new PIN (at least 4 digits). This reset link can only be used once.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>">
            <span class="msg-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><?= $messageIcons[$messageType] ?? $messageIcons['info'] ?></svg></span>
            <span class="msg-text"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($done): ?>
        <a class="btn" href="<?= htmlspecialchars($signinHref) ?>">Sign in with your new <?= htmlspecialchars($secretWord) ?></a>
    <?php elseif ($linkDead): ?>
        <p class="help">Reset links work once and expire after a while. To get a new one, open sign-in on SmartLib and choose <strong>Forgot your PIN?</strong></p>
        <a class="btn" href="<?= htmlspecialchars($signinHref) ?>">Go to SmartLib sign-in</a>
    <?php else: ?>
        <form method="POST" id="reset-pin-form" data-mode="<?= $isStaffReset ? 'staff' : 'pin' ?>" novalidate>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="field<?= $fieldError['new'] ? ' is-bad' : '' ?>" id="new_pin_field">
                <label for="new_pin">New <?= htmlspecialchars($secretTitle) ?></label>
                <div class="password-field-wrap">
                    <input id="new_pin" name="new_pin" type="password" <?= $secretAttrs ?> placeholder="Enter new <?= htmlspecialchars($secretWord) ?>" aria-describedby="new_pin_rule" <?= $fieldError['new'] ? 'aria-invalid="true"' : '' ?> required>
                    <button
                        type="button"
                        id="new_pin_toggle"
                        class="password-toggle-btn"
                        aria-controls="new_pin"
                        aria-label="Show <?= htmlspecialchars($secretWord) ?>"
                        aria-pressed="false">
                        <svg class="icon-eye" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5S22.5 12 22.5 12s-3.6 6.5-10.5 6.5S1.5 12 1.5 12z"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5c2.1 0 3.9.6 5.4 1.5"></path>
                            <path d="M22.5 12s-3.6 6.5-10.5 6.5c-2.1 0-3.9-.6-5.4-1.5"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                            <path d="M3 21L21 3"></path>
                        </svg>
                    </button>
                </div>
                <div class="rule<?= $fieldError['new'] ? ' is-bad' : '' ?>" id="new_pin_rule" aria-live="polite"><?= htmlspecialchars($newSecretError ? $message : $secretRule) ?></div>
            </div>
            <div class="field<?= $fieldError['confirm'] ? ' is-bad' : '' ?>" id="confirm_pin_field">
                <label for="confirm_pin">Confirm New <?= htmlspecialchars($secretTitle) ?></label>
                <div class="password-field-wrap">
                    <input id="confirm_pin" name="confirm_pin" type="password" <?= $secretAttrs ?> placeholder="Confirm new <?= htmlspecialchars($secretWord) ?>" aria-describedby="confirm_pin_rule" <?= $fieldError['confirm'] ? 'aria-invalid="true"' : '' ?> required>
                    <button
                        type="button"
                        id="confirm_pin_toggle"
                        class="password-toggle-btn"
                        aria-controls="confirm_pin"
                        aria-label="Show <?= htmlspecialchars($secretWord) ?>"
                        aria-pressed="false">
                        <svg class="icon-eye" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5S22.5 12 22.5 12s-3.6 6.5-10.5 6.5S1.5 12 1.5 12z"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5c2.1 0 3.9.6 5.4 1.5"></path>
                            <path d="M22.5 12s-3.6 6.5-10.5 6.5c-2.1 0-3.9-.6-5.4-1.5"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                            <path d="M3 21L21 3"></path>
                        </svg>
                    </button>
                </div>
                <div class="rule<?= $fieldError['confirm'] ? ' is-bad' : '' ?>" id="confirm_pin_rule" aria-live="polite"<?= $fieldError['confirm'] ? '' : ' hidden' ?>><?= $fieldError['confirm'] && $message === ucfirst($secretWord) . ' confirmation does not match.' ? htmlspecialchars("{$secretTitle}s don't match") : htmlspecialchars("Type the same {$secretWord} again") ?></div>
            </div>
            <button type="submit" class="btn">Update <?= htmlspecialchars($secretTitle) ?></button>
        </form>
    <?php endif; ?>

    <a class="link" href="index.php">Back to SmartLib</a>
</main>
<script>
(function () {
    // Staff (librarian/admin) links set a password, everyone else a PIN.
    const STAFF = document.getElementById('reset-pin-form')?.dataset.mode === 'staff';
    const WORD = STAFF ? 'password' : 'PIN';
    const TITLE = STAFF ? 'Password' : 'PIN';
    const STAFF_MIN = <?= (int)AUTH_STAFF_PASSWORD_MIN ?>;

    function wirePasswordToggle(inputId, buttonId) {
        const input = document.getElementById(inputId);
        const button = document.getElementById(buttonId);
        if (!input || !button) return;

        function setVisible(visible) {
            input.type = visible ? 'text' : 'password';
            button.classList.toggle('is-visible', visible);
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            button.setAttribute('aria-label', (visible ? 'Hide ' : 'Show ') + WORD);
        }

        button.addEventListener('click', function () {
            setVisible(input.type === 'password');
        });
    }

    wirePasswordToggle('new_pin', 'new_pin_toggle');
    wirePasswordToggle('confirm_pin', 'confirm_pin_toggle');

    // Live feedback while typing. Mirrors the server's rules (PIN: /^\d{4,}$/;
    // staff password: 10+ characters with a letter and a number; both entries
    // equal) only to guide the user -- the server still checks everything.
    const form = document.getElementById('reset-pin-form');
    const pin = document.getElementById('new_pin');
    const confirm = document.getElementById('confirm_pin');
    if (!form || !pin || !confirm) return;

    const pinField = document.getElementById('new_pin_field');
    const confirmField = document.getElementById('confirm_pin_field');
    const pinRule = document.getElementById('new_pin_rule');
    const confirmRule = document.getElementById('confirm_pin_rule');
    const MIN = STAFF ? STAFF_MIN : 4;

    function setState(field, rule, input, state, text) {
        field.classList.toggle('is-ok', state === 'ok');
        field.classList.toggle('is-bad', state === 'bad');
        rule.classList.toggle('is-ok', state === 'ok');
        rule.classList.toggle('is-bad', state === 'bad');
        if (state === 'bad') {
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
        }
        if (text !== null) rule.textContent = text;
    }

    // forceErrors: on submit, an empty or short entry counts as an error
    // instead of a gentle reminder.
    function checkStaffPassword(forceErrors) {
        const v = pin.value;
        const rule = 'At least ' + STAFF_MIN + ' characters, with a letter and a number';
        if (v === '') {
            setState(pinField, pinRule, pin, forceErrors ? 'bad' : 'neutral', forceErrors ? 'Enter a new password (' + rule.toLowerCase() + ')' : rule);
            return false;
        }
        const missing = [];
        if (v.length < STAFF_MIN) missing.push((STAFF_MIN - v.length) + ' more character' + (STAFF_MIN - v.length === 1 ? '' : 's'));
        if (!/\p{L}/u.test(v)) missing.push('a letter');
        if (!/\d/.test(v)) missing.push('a number');
        if (missing.length) {
            setState(pinField, pinRule, pin, forceErrors ? 'bad' : 'neutral', 'Still needed: ' + missing.join(', '));
            return false;
        }
        setState(pinField, pinRule, pin, 'ok', rule);
        return true;
    }

    function checkPin(forceErrors) {
        if (STAFF) return checkStaffPassword(forceErrors);
        const v = pin.value;
        if (v === '') {
            setState(pinField, pinRule, pin, forceErrors ? 'bad' : 'neutral', forceErrors ? 'Enter a new PIN (at least 4 digits)' : 'At least 4 digits, numbers only');
            return false;
        }
        if (!/^\d+$/.test(v)) {
            setState(pinField, pinRule, pin, 'bad', 'Numbers only (0-9)');
            return false;
        }
        if (v.length < MIN) {
            const left = MIN - v.length;
            setState(pinField, pinRule, pin, forceErrors ? 'bad' : 'neutral', left + ' more digit' + (left === 1 ? '' : 's') + ' needed');
            return false;
        }
        setState(pinField, pinRule, pin, 'ok', 'At least 4 digits');
        return true;
    }

    function checkConfirm(forceErrors) {
        const a = pin.value;
        const b = confirm.value;
        if (b === '') {
            confirmRule.hidden = !forceErrors;
            setState(confirmField, confirmRule, confirm, forceErrors ? 'bad' : 'neutral', forceErrors ? 'Type the same ' + WORD + ' again' : null);
            return false;
        }
        confirmRule.hidden = false;
        // Judge once there is enough to compare: as long as the confirmation
        // or the PIN, or as soon as it stops being the start of the PIN.
        const enough = b.length >= a.length || b.length >= MIN || a.indexOf(b) !== 0;
        if (!enough && !forceErrors) {
            setState(confirmField, confirmRule, confirm, 'neutral', 'Type the same ' + WORD + ' again');
            return false;
        }
        if (a !== '' && a === b) {
            setState(confirmField, confirmRule, confirm, 'ok', TITLE + 's match');
            return true;
        }
        setState(confirmField, confirmRule, confirm, 'bad', TITLE + "s don't match");
        return false;
    }

    pin.addEventListener('input', function () {
        checkPin(false);
        if (confirm.value !== '') checkConfirm(false);
    });
    confirm.addEventListener('input', function () { checkConfirm(false); });

    form.addEventListener('submit', function (event) {
        const pinOk = checkPin(true);
        const confirmOk = checkConfirm(true);
        if (!pinOk || !confirmOk) {
            event.preventDefault();
            (pinOk ? confirm : pin).focus();
        }
    });
})();
</script>
</body>
</html>
