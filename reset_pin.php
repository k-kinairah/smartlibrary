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
            $message = 'Please fill in both PIN fields.';
            $messageType = 'error';
        } elseif ($newPin !== $confirmPin) {
            $message = 'PIN confirmation does not match.';
            $messageType = 'error';
        } elseif (!preg_match('/^\d{4,}$/', $newPin)) {
            $message = 'PIN must be at least 4 digits.';
            $messageType = 'error';
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
                            ? 'Your PIN has been reset successfully. You can now sign in.'
                            : 'PIN updated. You can now sign in.';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib | Reset PIN</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
            background: radial-gradient(circle at 20% 20%, #1f6c50 0%, #0d2f24 45%, #071a14 100%);
            color: #eaf6ef;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
        }
        .card {
            width: min(440px, 95vw);
            border-radius: 16px;
            border: 1px solid rgba(194, 224, 207, 0.28);
            background: rgba(34, 77, 60, 0.6);
            box-shadow: 0 18px 40px rgba(2, 12, 8, 0.4);
            padding: 20px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }
        p.sub {
            margin: 0 0 16px;
            color: #c8ddd1;
            font-size: 14px;
        }
        .msg {
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 14px;
        }
        .msg.success { background: rgba(98, 209, 145, 0.2); color: #b7ffd1; border: 1px solid rgba(98, 209, 145, 0.35); }
        .msg.error { background: rgba(233, 94, 109, 0.2); color: #ffd8de; border: 1px solid rgba(233, 94, 109, 0.35); }
        .msg.info { background: rgba(120, 177, 255, 0.18); color: #d9ecff; border: 1px solid rgba(120, 177, 255, 0.32); }
        .field {
            margin-bottom: 12px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #d3e8dc;
            font-size: 13px;
            font-weight: 700;
        }
        input {
            width: 100%;
            box-sizing: border-box;
            border-radius: 10px;
            border: 1px solid rgba(196, 225, 208, 0.35);
            background: rgba(230, 242, 236, 0.14);
            color: #f2fbf6;
            padding: 10px 12px;
            outline: none;
            font-size: 14px;
        }
        input::placeholder {
            color: #bbd3c6;
        }
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
        .btn {
            width: 100%;
            border: none;
            border-radius: 10px;
            background: #1b8b4e;
            color: #ffffff;
            font-weight: 700;
            padding: 10px 14px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn:hover {
            filter: brightness(1.04);
        }
        .link {
            display: inline-block;
            margin-top: 12px;
            color: #c8e4d6;
            text-decoration: none;
            font-size: 13px;
        }
        .link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Reset PIN</h1>
    <p class="sub">Use a new PIN (at least 4 digits). This reset link can only be used once.</p>

    <?php if ($message !== ''): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!$done && $token !== ''): ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="field">
                <label for="new_pin">New PIN</label>
                <div class="password-field-wrap">
                    <input id="new_pin" name="new_pin" type="password" maxlength="20" placeholder="Enter new PIN" required>
                    <button
                        type="button"
                        id="new_pin_toggle"
                        class="password-toggle-btn"
                        aria-controls="new_pin"
                        aria-label="Show PIN"
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
            </div>
            <div class="field">
                <label for="confirm_pin">Confirm New PIN</label>
                <div class="password-field-wrap">
                    <input id="confirm_pin" name="confirm_pin" type="password" maxlength="20" placeholder="Confirm new PIN" required>
                    <button
                        type="button"
                        id="confirm_pin_toggle"
                        class="password-toggle-btn"
                        aria-controls="confirm_pin"
                        aria-label="Show PIN"
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
            </div>
            <button type="submit" class="btn">Update PIN</button>
        </form>
    <?php endif; ?>

    <a class="link" href="index.php">Back to SmartLib</a>
</div>
<script>
(function () {
    function wirePasswordToggle(inputId, buttonId) {
        const input = document.getElementById(inputId);
        const button = document.getElementById(buttonId);
        if (!input || !button) return;

        function setVisible(visible) {
            input.type = visible ? 'text' : 'password';
            button.classList.toggle('is-visible', visible);
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            button.setAttribute('aria-label', visible ? 'Hide PIN' : 'Show PIN');
        }

        button.addEventListener('click', function () {
            setVisible(input.type === 'password');
        });
    }

    wirePasswordToggle('new_pin', 'new_pin_toggle');
    wirePasswordToggle('confirm_pin', 'confirm_pin_toggle');
})();
</script>
</body>
</html>
