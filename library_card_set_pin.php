<?php
require_once __DIR__ . '/config/request_input.php'; // smartlib_request_string(): arrays in scalar params count as empty
/**
 * Library card signup, last step: choose the kiosk PIN.
 *
 * Reached only from library_card_verify.php after the emailed code was
 * correct, which leaves $_SESSION['library_card_pin_stage'] =
 * ['verification_id' => ..., 'at' => time()]. The account is created here,
 * with the PIN's hash, and nowhere else for new signups. The plain PIN is
 * never stored, put in the session, a cookie, a URL or a log line, and never
 * echoed back into the page.
 *
 * The stage is good for 5 minutes (and the code row must still be unused
 * and unexpired). On the shared kiosk, kiosk-idle.js signs out after 2
 * minutes without activity, which destroys the session and the stage.
 */
session_start();
require 'config/db_connect.php';
require 'config/auth_security.php';
require 'config/csrf.php';
require 'config/library_card_helpers.php';

header('Cache-Control: no-store, max-age=0');

const LIBRARY_CARD_PIN_STAGE_TTL = 300;

$csrfKey = 'library_card_csrf';
$csrfToken = smartlib_csrf_token($csrfKey);
$message = '';
$messageType = 'info';
// Once the step can't continue, only the "Start again" button is shown.
$canSetPin = true;

$stage = $_SESSION['library_card_pin_stage'] ?? null;
$verificationId = is_array($stage) ? (int)($stage['verification_id'] ?? 0) : 0;
$stageAt = is_array($stage) ? (int)($stage['at'] ?? 0) : 0;
$verification = $verificationId > 0 ? library_card_get_verification($conn, $verificationId) : null;

// Back button after finishing: this browser already holds the new card
// (success clears the stage, or the row is now used), so just show it again.
if (!empty($_SESSION['library_card_user_id']) && (!$verification || !empty($verification['used_at']))) {
    unset($_SESSION['library_card_pin_stage']);
    header('Location: library_card.php');
    exit;
}

$stageOk = $verificationId > 0
    && $stageAt > 0
    && (time() - $stageAt) <= LIBRARY_CARD_PIN_STAGE_TTL
    && $verification
    && ($verification['purpose'] ?? '') === 'signup'
    && empty($verification['used_at'])
    && (int)($verification['is_expired'] ?? 1) === 0;

if (!$stageOk) {
    unset($_SESSION['library_card_pin_stage']);
    $message = 'This step expired. Please start again.';
    $messageType = 'error';
    $canSetPin = false;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same rule as every other PIN entry point (admin/manage_users.php,
    // reset_pin.php): exactly 4 digits, auth_kiosk_pin_valid(). Not trimmed,
    // kept as a string (a leading zero stays).
    $pin = smartlib_request_string($_POST, 'pin');
    $confirmPin = smartlib_request_string($_POST, 'confirm_pin');

    if (!smartlib_csrf_verify($csrfKey, $_POST['csrf_token'] ?? '')) {
        $message = 'Your session expired. Please enter your PIN again.';
        $messageType = 'error';
    } elseif (!auth_kiosk_pin_valid($pin)) {
        $message = AUTH_KIOSK_PIN_MESSAGE;
        $messageType = 'error';
    } elseif ($confirmPin !== $pin) {
        $message = 'PINs don\'t match.';
        $messageType = 'error';
    } else {
        $verification['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
        $result = library_card_create_account($conn, $verification);

        if (!empty($result['user_id'])) {
            unset($_SESSION['library_card_pin_stage'], $_SESSION['library_card_pending']);
            session_regenerate_id(true);
            $_SESSION['library_card_user_id'] = (int)$result['user_id'];
            header('Location: library_card.php');
            exit;
        }

        library_card_mark_verification_used($conn, $verificationId);
        unset($_SESSION['library_card_pin_stage'], $_SESSION['library_card_pending']);
        $message = ($result['error'] ?? '') === 'duplicate'
            ? 'This email or ID number was registered while you were verifying. Please see the librarian if this is your account.'
            : 'Unable to create your library card right now. Please try again.';
        $messageType = 'error';
        $canSetPin = false;
    }
    unset($pin, $confirmPin);
}

$cssVer = @filemtime(__DIR__ . '/assets/css/library_card.css') ?: time();
$eyeIcons = '<svg class="icon-eye" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5S22.5 12 22.5 12s-3.6 6.5-10.5 6.5S1.5 12 1.5 12z"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5c2.1 0 3.9.6 5.4 1.5"></path>
                            <path d="M22.5 12s-3.6 6.5-10.5 6.5c-2.1 0-3.9-.6-5.4-1.5"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                            <path d="M3 21L21 3"></path>
                        </svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib | Create Your Kiosk PIN</title>
    <link rel="icon" href="assets/images/stjude_seal_64.png" type="image/png">
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
    <h1>Create your kiosk PIN</h1>
    <?php if ($canSetPin): ?>
        <p class="sub">Email confirmed. This PIN logs you into the self-service kiosk to search and borrow books &mdash; you'll use it every time, so pick something you'll remember.</p>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>" role="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($canSetPin): ?>
        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="field-row pin-row stack-sm">
                <div class="field">
                    <label for="pin">Create your kiosk PIN</label>
                    <div class="password-field-wrap">
                        <input id="pin" name="pin" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="new-password" placeholder="4 digits" required autofocus>
                        <button type="button" id="pin_toggle" class="password-toggle-btn" aria-controls="pin" aria-label="Show PIN" aria-pressed="false">
                        <?= $eyeIcons ?>
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label for="confirm_pin">Confirm PIN</label>
                    <div class="password-field-wrap">
                        <input id="confirm_pin" name="confirm_pin" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="new-password" placeholder="Re-enter PIN" required>
                        <button type="button" id="confirm_pin_toggle" class="password-toggle-btn" aria-controls="confirm_pin" aria-label="Show PIN" aria-pressed="false">
                        <?= $eyeIcons ?>
                        </button>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn">Create my PIN and get my card</button>
        </form>
    <?php else: ?>
        <a class="btn" href="library_card_signup.php">Start again</a>
    <?php endif; ?>

    <div class="panel-footer">
        <?php if ($canSetPin): ?>
            <a class="link" href="library_card_signup.php"><strong>Start again</strong></a>
        <?php endif; ?>
        <a class="link" href="index.php">Back to SmartLib</a>
    </div>
</div>
<script>
(function () {
    // Show/hide PIN toggles, same as reset_pin.php's.
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

    wirePasswordToggle('pin', 'pin_toggle');
    wirePasswordToggle('confirm_pin', 'confirm_pin_toggle');

    // Digits only, at most 4, as the user types or pastes (the server still
    // checks: exactly 4 digits).
    ['pin', 'confirm_pin'].forEach(function (id) {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('input', function () {
            const clean = input.value.replace(/[^0-9]/g, '').slice(0, 4);
            if (clean !== input.value) input.value = clean;
        });
        // A paste keeps its digits first and then the first 4, so "12-34"
        // or "1234 5" becomes "1234" (maxlength alone would cut it first).
        input.addEventListener('paste', function (e) {
            const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
            e.preventDefault();
            const v = input.value;
            const s = input.selectionStart ?? v.length;
            const en = input.selectionEnd ?? v.length;
            input.value = (v.slice(0, s) + text + v.slice(en)).replace(/[^0-9]/g, '').slice(0, 4);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
})();
</script>
<?php require_once __DIR__ . '/config/request_guard.php'; ?><script src="assets/javascript/kiosk-idle.js?v=<?= @filemtime(__DIR__ . '/assets/javascript/kiosk-idle.js') ?: time() ?>" data-csrf="<?= htmlspecialchars(smartlib_kiosk_csrf_token()) ?>" data-idle-ms="120000"></script>
</body>
</html>
