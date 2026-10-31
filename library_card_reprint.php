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
$form = [
    'email' => '',
    'user_number' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['email'] = library_card_normalize_email((string)($_POST['email'] ?? ''));
    $form['user_number'] = library_card_normalize_id((string)($_POST['user_number'] ?? ''));

    if (!smartlib_csrf_verify($csrfKey, $_POST['csrf_token'] ?? '')) {
        $message = 'Your session expired. Please submit the form again.';
        $messageType = 'error';
    } elseif ($form['email'] === '' || $form['user_number'] === '') {
        $message = 'Please enter the PHINMA email and ID number you signed up with.';
        $messageType = 'error';
    } elseif (!library_card_is_phinma_email($form['email'])) {
        $message = 'Please use your official PHINMA email address (ending in @' . LIBRARY_CARD_EMAIL_DOMAIN . ').';
        $messageType = 'error';
    } elseif (!library_card_id_format_ok($form['user_number'])) {
        $message = 'Please enter a valid school ID number.';
        $messageType = 'error';
    } else {
        $allowed = library_card_check_reprint_allowed($conn, $form['email'], $form['user_number']);

        if (empty($allowed['ok'])) {
            $message = (string)($allowed['message'] ?? 'Unable to reprint your card right now.');
            $messageType = 'error';
        } else {
            $holder = $allowed['holder'];
            $created = library_card_create_verification($conn, [
                'user_number' => (string)$holder['user_number'],
                'account_type' => (string)$holder['role'],
                'first_name' => (string)$holder['first_name'],
                'last_name' => (string)$holder['last_name'],
                'email' => (string)$holder['email']
            ], auth_client_ip(), 'reprint', (int)$holder['user_id']);

            if (!$created) {
                $message = 'Unable to send a code right now. Please try again.';
                $messageType = 'error';
            } elseif (!library_card_send_code_email((string)$holder['email'], (string)$holder['first_name'], (string)$created['code'], 'reprint')) {
                library_card_mark_verification_used($conn, (int)$created['verification_id']);
                $message = 'We could not send the verification email. Please try again in a few minutes, or ask the librarian for help.';
                $messageType = 'error';
            } else {
                $_SESSION['library_card_pending'] = [
                    'verification_id' => (int)$created['verification_id'],
                    'masked_email' => auth_mask_email((string)$holder['email']),
                    'purpose' => 'reprint'
                ];
                header('Location: library_card_verify.php');
                exit;
            }
        }
    }
}

$cssVer = @filemtime(__DIR__ . '/assets/css/library_card.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib | Reprint Library Card</title>
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
    <h1>Reprint my card</h1>
    <p class="sub">Lost your printout? Enter the PHINMA email and ID number you signed up with. We'll email you a 6-digit code, then show your card again.</p>

    <?php if ($message !== ''): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>" role="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <div class="field">
            <label for="email">PHINMA email</label>
            <input id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?= htmlspecialchars($form['email']) ?>" required>
        </div>
        <div class="field field-before-submit">
            <label for="user_number">School ID number</label>
            <input id="user_number" name="user_number" type="text" inputmode="text" maxlength="20" pattern="[A-Za-z0-9-]{4,20}" autocomplete="off" value="<?= htmlspecialchars($form['user_number']) ?>" required>
        </div>
        <button type="submit" class="btn">Send verification code</button>
    </form>

    <div class="panel-footer">
        <a class="link" href="library_card_signup.php">Don't have a card yet? <strong>Sign up</strong></a>
        <a class="link" href="index.php">Back to SmartLib</a>
    </div>
</div>
</body>
</html>
