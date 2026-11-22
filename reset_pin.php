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
// Design: "reset-page-centered-premium" (light mint page, centered card,
// gold keyline and ornament, Merriweather headline, left-aligned fields).

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

// Which field a server-side PIN error belongs to, so it can be shown there.
$fieldError = ['new' => false, 'confirm' => false];
$fillBothMessage = "Please fill in both {$secretWord} fields.";
$mismatchMessage = ucfirst($secretWord) . ' confirmation does not match.';
if (!$linkDead && $messageType === 'error') {
    if ($message === $fillBothMessage) {
        $fieldError = ['new' => true, 'confirm' => true];
    } elseif ($message === $mismatchMessage) {
        $fieldError['confirm'] = true;
    } elseif ($newSecretError) {
        $fieldError['new'] = true;
    }
}
// Field errors are shown next to their field; the banner is only for
// general errors that no field can show (e.g. the update failing).
$showBanner = !$done && !$linkDead && $messageType === 'error' && $message !== ''
    && !$fieldError['new'] && !$fieldError['confirm'];

// After a successful reset, open the kiosk sign-in. ?signin=<anything> just
// opens the sign-in modal; the link never names a role.
$signinHref = 'index.php?signin=1';

$logoVer = @filemtime(__DIR__ . '/assets/images/stjude_logo.jpg') ?: 1;

$secretTitle = ucfirst($secretWord);
$secretRule = $isStaffReset
    ? 'At least ' . AUTH_STAFF_PASSWORD_MIN . ' characters, with a letter and a number'
    : 'At least 4 digits, numbers only';
$secretAttrs = $isStaffReset
    ? 'autocomplete="new-password" maxlength="' . AUTH_STAFF_PASSWORD_MAX_BYTES . '"'
    : 'inputmode="numeric" pattern="[0-9]{4,}" autocomplete="new-password" maxlength="20"';
// Escaped once here; the PIN wording keeps each sentence on its own line
// (as in the mockup), the longer staff wording just wraps.
$subLineHtml = $isStaffReset
    ? htmlspecialchars('Choose a new password of at least ' . (int)AUTH_STAFF_PASSWORD_MIN . ' characters with a letter and a number. This link works once.')
    : 'Choose a new PIN of at least 4 digits.<br>This link works once.';

// Server-side field errors, worded like the live checks in the script below.
$newRuleText = $secretRule;
if ($newSecretError) {
    $newRuleText = $message;
} elseif ($fieldError['new']) {
    $newRuleText = $isStaffReset ? 'Enter a new password' : 'Enter a new PIN (at least 4 digits)';
}
$confirmRuleText = "Type the same {$secretWord} again";
if ($fieldError['confirm'] && $message === $mismatchMessage) {
    $confirmRuleText = "{$secretTitle}s don't match";
}

$pageTitle = $done ? "{$secretTitle} updated" : ($linkDead ? 'Reset link expired' : "Reset your {$secretWord}");

$eyeIcons = '<svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M1.5 12s3.6-6.5 10.5-6.5S22.5 12 22.5 12s-3.6 6.5-10.5 6.5S1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3.2"></circle></svg>'
    . '<svg class="icon-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M1.5 12s3.6-6.5 10.5-6.5c2.1 0 3.9.6 5.4 1.5"></path><path d="M22.5 12s-3.6 6.5-10.5 6.5c-2.1 0-3.9-.6-5.4-1.5"></path><circle cx="12" cy="12" r="3.2"></circle><path d="M3 21L21 3"></path></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>SmartLib | <?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" href="assets/images/stjude_seal_64.png" type="image/png">
    <!-- Same fonts as the kiosk (kiosk.css); Georgia / system sans if offline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Merriweather:wght@700&display=swap">
    <style>
        :root {
            --ink: #0b3025;
            --ink-2: #1d4939;
            --muted: #3d5a4e;
            --soft: #4f7a6a;
            --green: #0e8660;
            --gold: #e2c25a;
            --bad: #a23a28;
            --bad-line: #d9806e;
            --sans: "Manrope", -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            --serif: "Merriweather", Georgia, "Times New Roman", serif;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(24px, env(safe-area-inset-top)) max(18px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(18px, env(safe-area-inset-left));
            font-family: var(--sans);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(120% 60% at 10% 0%, #d3eee1 0%, rgba(211, 238, 225, 0) 60%),
                radial-gradient(90% 50% at 100% 100%, #eadfb8 0%, rgba(232, 220, 180, 0) 55%),
                linear-gradient(180deg, #e6f5ee 0%, #f5faf7 100%);
        }
        .card {
            width: 100%;
            max-width: 440px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(255, 255, 255, 0.88));
            border: 1px solid rgba(14, 134, 96, 0.16);
            border-top: 3px solid var(--gold);
            border-radius: 28px;
            padding: 28px 22px 24px;
            box-shadow: 0 2px 0 rgba(255, 255, 255, 0.8) inset, 0 24px 48px rgba(11, 48, 37, 0.14);
        }

        /* Brand block: the school seal and name, so a patron can tell this
           is the real SmartLib page. */
        .brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .brand img {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            object-fit: cover;
            background: #ffffff;
            border: 3px solid #ffffff;
            box-shadow: 0 0 0 2px var(--gold), 0 10px 22px rgba(11, 48, 37, 0.22);
        }
        .brand-name {
            margin: 14px 0 0;
            font-weight: 800;
            font-size: 21px;
            letter-spacing: 0.02em;
            color: var(--ink);
        }
        .brand-sub {
            margin: 4px 0 0;
            font-weight: 700;
            font-size: 10.5px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--soft);
        }
        .orn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 16px 0 18px;
        }
        .orn i {
            height: 1px;
            width: 64px;
            background: linear-gradient(90deg, rgba(226, 194, 90, 0), var(--gold));
        }
        .orn i:last-child { background: linear-gradient(270deg, rgba(226, 194, 90, 0), var(--gold)); }
        .orn b {
            width: 7px;
            height: 7px;
            background: var(--gold);
            transform: rotate(45deg);
        }
        h1 {
            margin: 0;
            font-family: var(--serif);
            font-weight: 700;
            font-size: 25px;
            line-height: 1.25;
            color: var(--ink);
            text-align: center;
            overflow-wrap: anywhere;
        }
        .sub {
            margin: 10px 0 20px;
            font-weight: 500;
            font-size: 14.5px;
            line-height: 1.55;
            color: var(--muted);
            text-align: center;
        }
        .sub b { font-weight: 800; color: var(--ink); }

        /* General (not field-specific) error banner. */
        .msg {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 0 0 16px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #f1c2b8;
            background: #fff3f0;
            color: #7d2a1c;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.5;
        }
        .msg svg {
            flex: none;
            width: 20px;
            height: 20px;
            margin-top: 1px;
            fill: none;
            stroke: #c2412b;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .field { margin-bottom: 14px; }
        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 800;
            font-size: 11.5px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--ink-2);
        }
        .password-field-wrap { position: relative; }
        input[type="password"],
        input[type="text"] {
            width: 100%;
            height: 56px;
            margin: 0;
            padding: 0 58px 0 18px;
            border-radius: 15px;
            border: 1.5px solid #cfe5da;
            background: #ffffff;
            box-shadow: 0 1px 0 rgba(11, 48, 37, 0.04) inset;
            color: var(--ink);
            font-family: var(--sans);
            font-weight: 700;
            font-size: 18px; /* 16px+ stops iOS zooming in on focus */
            letter-spacing: 0.12em;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }
        input::placeholder {
            color: #8aa89b;
            font-weight: 500;
            font-size: 15px;
            letter-spacing: normal;
        }
        input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 4px rgba(14, 134, 96, 0.16);
        }
        .field.is-bad input {
            border-color: var(--bad-line);
            background: #fffaf8;
        }
        .field.is-bad input:focus { box-shadow: 0 0 0 4px rgba(217, 128, 110, 0.22); }
        .password-toggle-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            padding: 0;
            border-radius: 50%;
            border: 1px solid #d9ece2;
            background: #f4faf7;
            color: #2f5a4c;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .password-toggle-btn:hover { background: #e9f5ef; }
        .password-toggle-btn svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .password-toggle-btn .icon-eye-off { display: none; }
        .password-toggle-btn.is-visible .icon-eye { display: none; }
        .password-toggle-btn.is-visible .icon-eye-off { display: block; }

        /* Rule line under each field: grey ring, green check when met,
           red ring when wrong. */
        .rule {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 20px;
            margin-top: 8px;
            font-weight: 600;
            font-size: 13px;
            line-height: 1.35;
            color: var(--soft);
        }
        .rule::before {
            content: "";
            flex: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 1.6px solid #b9d3c6;
            background-position: center;
            background-repeat: no-repeat;
            background-size: 12px 12px;
        }
        .rule.is-ok::before {
            border-color: var(--green);
            background-color: var(--green);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='m6 12.5 4 4 8-9' fill='none' stroke='%23fff' stroke-width='3.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }
        .rule.is-bad { color: var(--bad); }
        .rule.is-bad::before { border-color: var(--bad-line); }
        /* A hidden rule still holds its line, so the card doesn't jump. */
        .rule[hidden] { display: flex; visibility: hidden; }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 56px;
            margin-top: 22px;
            padding: 0 16px;
            border: 0;
            border-radius: 17px;
            background: linear-gradient(180deg, #14a173, #0b7a56);
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.35) inset, 0 10px 22px rgba(14, 134, 96, 0.32);
            color: #ffffff;
            font-family: var(--sans);
            font-weight: 800;
            font-size: 16px;
            letter-spacing: 0.02em;
            text-decoration: none;
            cursor: pointer;
        }
        .btn:hover { filter: brightness(1.05); }
        .btn:active { transform: translateY(1px); }
        .back {
            display: block;
            width: fit-content;
            margin: 16px auto 0;
            font-weight: 700;
            font-size: 14px;
            color: var(--green);
            text-decoration: none;
        }
        .back:hover { text-decoration: underline; }
        .btn:focus-visible,
        .back:focus-visible,
        .password-toggle-btn:focus-visible {
            outline: 3px solid #0b7a56;
            outline-offset: 3px;
        }

        /* Result screens: a big status circle above the headline. */
        .big {
            width: 80px;
            height: 80px;
            margin: 12px auto 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .big svg { fill: none; stroke-linecap: round; stroke-linejoin: round; }
        .big.ok {
            background: linear-gradient(180deg, #14a173, #0b7a56);
            box-shadow: 0 0 0 6px rgba(14, 134, 96, 0.12), 0 12px 24px rgba(14, 134, 96, 0.3);
        }
        .big.ok svg { width: 38px; height: 38px; stroke: #ffffff; stroke-width: 3; }
        .big.warn {
            background: #fff4dc;
            border: 2px solid #f0d9a0;
            box-shadow: 0 0 0 6px rgba(226, 194, 90, 0.14);
        }
        .big.warn svg { width: 36px; height: 36px; stroke: #9a6f00; stroke-width: 2.2; }

        @media (max-width: 360px) {
            .card { padding: 24px 16px 20px; border-radius: 24px; }
            h1 { font-size: 23px; }
            .orn i { width: 48px; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">
        <img src="assets/images/stjude_logo.jpg?v=<?= (int)$logoVer ?>" alt="St. Jude College logo" width="76" height="76">
        <p class="brand-name">SmartLib</p>
        <p class="brand-sub">Self-Service Library System</p>
    </div>
    <div class="orn" aria-hidden="true"><i></i><b></b><i></i></div>

    <?php if ($done): ?>
        <div class="big ok" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 12.5 4 4 8-9"></path></svg></div>
        <h1><?= htmlspecialchars($secretTitle) ?> updated</h1>
        <p class="sub" role="status">You can now sign in to SmartLib<br>with your new <?= htmlspecialchars($secretWord) ?>.</p>
        <a class="btn" href="<?= htmlspecialchars($signinHref) ?>">Sign in</a>
    <?php elseif ($linkDead): ?>
        <div class="big warn" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></div>
        <h1>This link has expired</h1>
        <p class="sub" role="alert">Reset links work once and last <?= (int)AUTH_RESET_TOKEN_TTL_MINUTES ?> minutes. Open sign-in and tap <b>Forgot your PIN?</b> to get a new one.</p>
        <a class="btn" href="<?= htmlspecialchars($signinHref) ?>">Go to sign-in</a>
    <?php else: ?>
        <h1>Reset your <?= htmlspecialchars($secretWord) ?></h1>
        <p class="sub"><?= $subLineHtml ?></p>
        <?php if ($showBanner): ?>
            <div class="msg" role="alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5 21 19H3L12 4.5Z"></path><path d="M12 10v4"></path><path d="M12 16.8h.01"></path></svg>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>
        <form method="POST" id="reset-pin-form" data-mode="<?= $isStaffReset ? 'staff' : 'pin' ?>" novalidate>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="field<?= $fieldError['new'] ? ' is-bad' : '' ?>" id="new_pin_field">
                <label for="new_pin">New <?= htmlspecialchars($secretWord) ?></label>
                <div class="password-field-wrap">
                    <input id="new_pin" name="new_pin" type="password" <?= $secretAttrs ?> placeholder="Enter new <?= htmlspecialchars($secretWord) ?>" aria-describedby="new_pin_rule" <?= $fieldError['new'] ? 'aria-invalid="true"' : '' ?> required>
                    <button type="button" id="new_pin_toggle" class="password-toggle-btn" aria-controls="new_pin" aria-label="Show <?= htmlspecialchars($secretWord) ?>" aria-pressed="false"><?= $eyeIcons ?></button>
                </div>
                <div class="rule<?= $fieldError['new'] ? ' is-bad' : '' ?>" id="new_pin_rule" aria-live="polite"><?= htmlspecialchars($newRuleText) ?></div>
            </div>
            <div class="field<?= $fieldError['confirm'] ? ' is-bad' : '' ?>" id="confirm_pin_field">
                <label for="confirm_pin">Confirm new <?= htmlspecialchars($secretWord) ?></label>
                <div class="password-field-wrap">
                    <input id="confirm_pin" name="confirm_pin" type="password" <?= $secretAttrs ?> placeholder="Type it again" aria-describedby="confirm_pin_rule" <?= $fieldError['confirm'] ? 'aria-invalid="true"' : '' ?> required>
                    <button type="button" id="confirm_pin_toggle" class="password-toggle-btn" aria-controls="confirm_pin" aria-label="Show <?= htmlspecialchars($secretWord) ?>" aria-pressed="false"><?= $eyeIcons ?></button>
                </div>
                <div class="rule<?= $fieldError['confirm'] ? ' is-bad' : '' ?>" id="confirm_pin_rule" aria-live="polite"<?= $fieldError['confirm'] ? '' : ' hidden' ?>><?= htmlspecialchars($confirmRuleText) ?></div>
            </div>
            <button type="submit" class="btn">Update <?= htmlspecialchars($secretWord) ?></button>
        </form>
    <?php endif; ?>
    <a class="back" href="index.php">Back to SmartLib</a>
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
        setState(pinField, pinRule, pin, 'ok', 'At least 4 digits, numbers only');
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
