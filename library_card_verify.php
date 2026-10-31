<?php
session_start();
require 'config/db_connect.php';
require 'config/auth_security.php';
require 'config/csrf.php';
require 'config/library_card_helpers.php';

$csrfKey = 'library_card_csrf';
$csrfToken = smartlib_csrf_token($csrfKey);
$message = '';
$messageType = 'info';
// Once a signup can't continue (expired, locked out, already used), only
// the "start over" link is shown.
$canEnterCode = true;

// Shared by signup and "reprint my card"; what happens on success is
// decided by the stored row's purpose, not by anything in the session.
$pending = $_SESSION['library_card_pending'] ?? null;
$verificationId = is_array($pending) ? (int)($pending['verification_id'] ?? 0) : 0;
$maskedEmail = is_array($pending) ? (string)($pending['masked_email'] ?? '') : '';
$isReprint = is_array($pending) && ($pending['purpose'] ?? '') === 'reprint';
$restartUrl = $isReprint ? 'library_card_reprint.php' : 'library_card_signup.php';

if ($verificationId <= 0) {
    $message = 'There is no code waiting to be entered. Please start again.';
    $messageType = 'error';
    $canEnterCode = false;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
    $verification = library_card_get_verification($conn, $verificationId);

    if (!smartlib_csrf_verify($csrfKey, $_POST['csrf_token'] ?? '')) {
        $message = 'Your session expired. Please enter the code again.';
        $messageType = 'error';
    } elseif (!$verification || !empty($verification['used_at'])) {
        unset($_SESSION['library_card_pending']);
        $message = 'This code is no longer valid. Please start again to get a new one.';
        $messageType = 'error';
        $canEnterCode = false;
    } elseif ((int)$verification['is_expired'] === 1) {
        library_card_mark_verification_used($conn, $verificationId);
        unset($_SESSION['library_card_pending']);
        $message = 'This code has expired. Please start again to get a new one.';
        $messageType = 'error';
        $canEnterCode = false;
    } elseif ((int)$verification['attempt_count'] >= (int)$verification['max_attempts']) {
        library_card_mark_verification_used($conn, $verificationId);
        unset($_SESSION['library_card_pending']);
        $message = 'Too many incorrect codes. Please start again to get a new one.';
        $messageType = 'error';
        $canEnterCode = false;
    } elseif (strlen($code) !== 6) {
        $message = 'Enter the 6-digit code from your email.';
        $messageType = 'error';
    } elseif (!password_verify($code, (string)$verification['code_hash'])) {
        library_card_increment_attempt($conn, $verificationId);
        $remaining = max(0, (int)$verification['max_attempts'] - ((int)$verification['attempt_count'] + 1));

        if ($remaining <= 0) {
            library_card_mark_verification_used($conn, $verificationId);
            unset($_SESSION['library_card_pending']);
            $message = 'Too many incorrect codes. Please start again to get a new one.';
            $canEnterCode = false;
        } else {
            $message = 'Incorrect code. ' . $remaining . ' attempt(s) left.';
        }
        $messageType = 'error';
    } elseif ($verification['purpose'] === 'reprint') {
        library_card_mark_verification_used($conn, $verificationId);
        unset($_SESSION['library_card_pending']);
        $holder = library_card_get_card_holder($conn, (int)$verification['user_id']);

        if ($holder) {
            session_regenerate_id(true);
            $_SESSION['library_card_user_id'] = (int)$holder['user_id'];
            header('Location: library_card.php');
            exit;
        }

        $message = 'This library card is no longer available. Please see the librarian.';
        $messageType = 'error';
        $canEnterCode = false;
    } else {
        $result = library_card_create_account($conn, $verification);

        if (!empty($result['user_id'])) {
            unset($_SESSION['library_card_pending']);
            session_regenerate_id(true);
            $_SESSION['library_card_user_id'] = (int)$result['user_id'];
            header('Location: library_card.php');
            exit;
        }

        library_card_mark_verification_used($conn, $verificationId);
        unset($_SESSION['library_card_pending']);
        $message = ($result['error'] ?? '') === 'duplicate'
            ? 'This email or ID number was registered while you were verifying. Please see the librarian if this is your account.'
            : 'Unable to create your library card right now. Please try again.';
        $messageType = 'error';
        $canEnterCode = false;
    }
}

$cssVer = @filemtime(__DIR__ . '/assets/css/library_card.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib | Verify Your Email</title>
    <link rel="icon" href="assets/images/phinma-icon.png" type="image/png">
    <link rel="stylesheet" href="assets/css/library_card.css?v=<?= $cssVer ?>">
</head>
<body>
<div class="panel">
    <div class="panel-brand">
        <img src="assets/images/stjude_logo.jpg" alt="School logo">
        <div>
            <strong>SMARTLIB</strong>
            <span>Digital Library Card</span>
        </div>
    </div>
    <h1>Check your email</h1>
    <?php if ($canEnterCode): ?>
        <p class="sub">We sent a 6-digit code to <strong><?= htmlspecialchars($maskedEmail) ?></strong>. It expires in <?= LIBRARY_CARD_CODE_TTL_MINUTES ?> minutes. Check your spam folder if you don't see it.</p>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>" role="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($canEnterCode): ?>
        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="field">
                <label for="code">Verification code</label>
                <input id="code" name="code" class="code-input" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" pattern="\d{6}" placeholder="••••••" aria-describedby="code_hint" required autofocus>
                <span class="hint" id="code_hint">Enter the 6 digits from the email.</span>
            </div>
            <button type="submit" class="btn"><?= $isReprint ? 'Verify and show my card' : 'Verify and get my card' ?></button>
        </form>
    <?php else: ?>
        <a class="btn" href="<?= htmlspecialchars($restartUrl) ?>">Start again</a>
    <?php endif; ?>

    <div class="panel-footer">
        <?php if ($canEnterCode): ?>
            <a class="link" href="<?= htmlspecialchars($restartUrl) ?>">Didn't get a code? <strong>Start again</strong></a>
        <?php endif; ?>
        <a class="link" href="index.php">Back to SmartLib</a>
    </div>
</div>
<script>
(function () {
    // Digits only, so a pasted "123 456" or "Code: 123456" still works.
    const code = document.getElementById('code');
    if (!code) return;
    code.addEventListener('input', function () {
        const digits = code.value.replace(/\D+/g, '').slice(0, 6);
        if (digits !== code.value) code.value = digits;
    });
})();
</script>
</body>
</html>
