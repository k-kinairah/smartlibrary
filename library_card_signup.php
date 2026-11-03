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
$programs = library_card_get_programs($conn);
$form = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'user_number' => '',
    'account_type' => '',
    'program_id' => '',
    'year_level' => '',
    'gender' => '',
    'data_consent' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $form['last_name'] = trim((string)($_POST['last_name'] ?? ''));
    $form['email'] = library_card_normalize_email((string)($_POST['email'] ?? ''));
    $form['user_number'] = library_card_normalize_id((string)($_POST['user_number'] ?? ''));
    $form['account_type'] = trim((string)($_POST['account_type'] ?? ''));
    $form['program_id'] = trim((string)($_POST['program_id'] ?? ''));
    $form['year_level'] = trim((string)($_POST['year_level'] ?? ''));
    $form['gender'] = trim((string)($_POST['gender'] ?? ''));
    $form['data_consent'] = ($_POST['data_consent'] ?? '') === '1' ? '1' : '';
    // Kiosk PIN: same rule and trimming as every other PIN entry point
    // (admin/manage_users.php, reset_pin.php). Never kept in $form, so it
    // isn't echoed back into the page after a validation error.
    $pin = trim((string)($_POST['pin'] ?? ''));
    $confirmPin = trim((string)($_POST['confirm_pin'] ?? ''));
    // Role is an explicit choice, not inferred from the ID's shape -- see
    // library_card_normalize_id()'s docblock for why.
    $role = in_array($form['account_type'], ['student', 'faculty'], true) ? $form['account_type'] : null;

    $programId = ctype_digit($form['program_id']) && isset($programs[(int)$form['program_id']]) ? (int)$form['program_id'] : null;
    $yearLevel = ctype_digit($form['year_level']) && isset(LIBRARY_CARD_YEAR_LEVELS[(int)$form['year_level']]) ? (int)$form['year_level'] : null;
    $gender = isset(LIBRARY_CARD_GENDERS[$form['gender']]) ? $form['gender'] : null;
    if ($role === 'faculty') {
        // Faculty have no year level; ignore anything submitted for it.
        $yearLevel = null;
    }

    if (!smartlib_csrf_verify($csrfKey, $_POST['csrf_token'] ?? '')) {
        $message = 'Your session expired. Please submit the form again.';
        $messageType = 'error';
    } elseif ($form['first_name'] === '' || $form['last_name'] === '' || $form['email'] === '' || $form['user_number'] === '') {
        $message = 'Please fill in your name, PHINMA email, and ID number.';
        $messageType = 'error';
    } elseif (mb_strlen($form['first_name']) > 100 || mb_strlen($form['last_name']) > 100) {
        $message = 'Name is too long.';
        $messageType = 'error';
    } elseif (!library_card_is_phinma_email($form['email'])) {
        $message = 'Please use your official PHINMA email address (ending in @' . LIBRARY_CARD_EMAIL_DOMAIN . ').';
        $messageType = 'error';
    } elseif ($role === null) {
        $message = 'Please select whether you\'re a student or faculty.';
        $messageType = 'error';
    } elseif (!library_card_id_format_ok($form['user_number'])) {
        $message = 'Please enter a valid school ID number.';
        $messageType = 'error';
    } elseif ($form['program_id'] !== '' && $programId === null) {
        $message = 'Please choose your program from the list.';
        $messageType = 'error';
    } elseif ($role === 'student' && $programId === null) {
        $message = 'Please choose your program.';
        $messageType = 'error';
    } elseif ($role === 'student' && $yearLevel === null) {
        $message = 'Please choose your year level.';
        $messageType = 'error';
    } elseif ($gender === null) {
        $message = 'Please choose a gender option (you can choose "Prefer not to say").';
        $messageType = 'error';
    } elseif ($form['data_consent'] !== '1') {
        $message = 'Please check the box confirming you understand how your information will be used.';
        $messageType = 'error';
    } elseif (!preg_match('/^\d{4,}$/', $pin)) {
        $message = 'Please enter a kiosk PIN of at least 4 digits.';
        $messageType = 'error';
    } elseif ($confirmPin !== $pin) {
        $message = 'PINs don\'t match.';
        $messageType = 'error';
    } else {
        $allowed = library_card_check_signup_allowed($conn, $form['email'], $form['user_number']);

        if (empty($allowed['ok'])) {
            $message = (string)($allowed['message'] ?? 'Unable to sign up right now.');
            $messageType = 'error';
        } else {
            $created = library_card_create_verification($conn, [
                'user_number' => $form['user_number'],
                'account_type' => $role,
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'email' => $form['email'],
                'program_id' => $programId,
                'year_level' => $yearLevel,
                'gender' => $gender
            ], auth_client_ip(), 'signup', null, password_hash($pin, PASSWORD_DEFAULT));

            if (!$created) {
                $message = 'Unable to start your signup right now. Please try again.';
                $messageType = 'error';
            } elseif (!library_card_send_code_email($form['email'], $form['first_name'], (string)$created['code'])) {
                // Retire the code so this failed attempt doesn't block a retry.
                library_card_mark_verification_used($conn, (int)$created['verification_id']);
                $message = 'We could not send the verification email. Please try again in a few minutes, or ask the librarian for help.';
                $messageType = 'error';
            } else {
                $_SESSION['library_card_pending'] = [
                    'verification_id' => (int)$created['verification_id'],
                    'masked_email' => auth_mask_email($form['email']),
                    'purpose' => 'signup'
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
    <title>SmartLib | Get Your Library Card</title>
    <link rel="icon" href="assets/images/phinma-icon.png" type="image/png">
    <link rel="stylesheet" href="assets/css/library_card.css?v=<?= $cssVer ?>">
</head>
<body>
<div class="panel panel-wide">
    <div class="panel-brand">
        <img src="assets/images/stjude_logo.jpg" alt="School logo">
        <div>
            <strong>SMARTLIB</strong>
            <span>Digital Library Card</span>
        </div>
    </div>
    <h1>Get your library card</h1>
    <p class="sub">Sign up with your PHINMA email. We'll email you a 6-digit code to confirm it's you, then show your card to print.</p>

    <?php if ($message !== ''): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>" role="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <div class="field-row">
            <div class="field">
                <label for="first_name">First name</label>
                <input id="first_name" name="first_name" type="text" maxlength="100" autocomplete="given-name" value="<?= htmlspecialchars($form['first_name']) ?>" required>
            </div>
            <div class="field">
                <label for="last_name">Last name</label>
                <input id="last_name" name="last_name" type="text" maxlength="100" autocomplete="family-name" value="<?= htmlspecialchars($form['last_name']) ?>" required>
            </div>
        </div>
        <div class="field">
            <label for="email">PHINMA email</label>
            <input id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?= htmlspecialchars($form['email']) ?>" required>
        </div>
        <div class="field">
            <label for="user_number">School ID number</label>
            <input id="user_number" name="user_number" type="text" inputmode="text" maxlength="20" pattern="[A-Za-z0-9-]{4,20}" autocomplete="off" value="<?= htmlspecialchars($form['user_number']) ?>" required>
        </div>

        <div class="field">
            <label id="account_type_label">I am a</label>
            <div class="role-toggle" role="radiogroup" aria-labelledby="account_type_label">
                <label class="role-option">
                    <input type="radio" name="account_type" value="student" <?= $form['account_type'] === 'student' ? 'checked' : '' ?> required>
                    <span>Student</span>
                </label>
                <label class="role-option">
                    <input type="radio" name="account_type" value="faculty" <?= $form['account_type'] === 'faculty' ? 'checked' : '' ?>>
                    <span>Faculty</span>
                </label>
            </div>
        </div>

        <div class="field">
            <label for="program_id">Program / course</label>
            <select id="program_id" name="program_id">
                <option value="">Select your program</option>
                <?php foreach ($programs as $pid => $pname): ?>
                    <option value="<?= $pid ?>" <?= $form['program_id'] === (string)$pid ? 'selected' : '' ?>><?= htmlspecialchars($pname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field-row">
            <div class="field" id="year_level_field" <?= $form['account_type'] === 'faculty' ? 'hidden' : '' ?>>
                <label for="year_level">Year level</label>
                <select id="year_level" name="year_level">
                    <option value="">Select year</option>
                    <?php foreach (LIBRARY_CARD_YEAR_LEVELS as $level => $label): ?>
                        <option value="<?= $level ?>" <?= $form['year_level'] === (string)$level ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="gender">Gender</label>
                <select id="gender" name="gender">
                    <option value="">Select</option>
                    <?php foreach (LIBRARY_CARD_GENDERS as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $form['gender'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field consent-field">
            <label class="consent-option">
                <input type="checkbox" id="data_consent" name="data_consent" value="1" <?= $form['data_consent'] === '1' ? 'checked' : '' ?> required>
                <span class="consent-check" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7"></path></svg>
                </span>
                <span class="consent-text">I understand my info is used to manage my library account, and my program, year level, and gender only for anonymous usage stats.</span>
            </label>
        </div>

        <p class="hint pin-intro">This PIN logs you into the self-service kiosk to search and borrow books &mdash; you'll use it every time, so pick something you'll remember.</p>
        <div class="field-row pin-row stack-sm">
            <div class="field">
                <label for="pin">Create your kiosk PIN</label>
                <div class="password-field-wrap">
                    <input id="pin" name="pin" type="password" inputmode="numeric" maxlength="20" pattern="\d{4,}" autocomplete="new-password" placeholder="At least 4 digits" required>
                    <button type="button" id="pin_toggle" class="password-toggle-btn" aria-controls="pin" aria-label="Show PIN" aria-pressed="false">
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
                <label for="confirm_pin">Confirm PIN</label>
                <div class="password-field-wrap">
                    <input id="confirm_pin" name="confirm_pin" type="password" inputmode="numeric" maxlength="20" pattern="\d{4,}" autocomplete="new-password" placeholder="Re-enter PIN" required>
                    <button type="button" id="confirm_pin_toggle" class="password-toggle-btn" aria-controls="confirm_pin" aria-label="Show PIN" aria-pressed="false">
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
        </div>

        <button type="submit" class="btn">Send verification code</button>
    </form>

    <div class="panel-footer">
        <a class="link" href="library_card_reprint.php">Already have a card? <strong>Reprint my card</strong></a>
        <a class="link" href="index.php">Back to SmartLib</a>
    </div>
</div>
<script>
(function () {
    // Year level only applies to students; role is an explicit choice now
    // (it used to be guessed from the ID's length, but the school has more
    // than one ID format in use at once -- see library_card_normalize_id()
    // in config/library_card_helpers.php). The server ignores year level
    // for faculty regardless.
    const roleInputs = document.querySelectorAll('input[name="account_type"]');
    const yearField = document.getElementById('year_level_field');
    if (!roleInputs.length || !yearField) return;

    function sync() {
        const checked = document.querySelector('input[name="account_type"]:checked');
        yearField.hidden = !!checked && checked.value === 'faculty';
    }

    roleInputs.forEach(function (input) {
        input.addEventListener('change', sync);
    });
    sync();
})();

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
})();

(function () {
    // Wraps each native <select> with a themed trigger button + listbox
    // panel. The real <select> is kept in the DOM (visually hidden via
    // CSS, not removed) so the form still submits name/value pairs
    // normally, and the page still works if this script never runs. See
    // the .lc-select rules in library_card.css for why: a native
    // dropdown's open list can only take a couple of CSS properties and
    // always renders in the OS's own flat white style, no matter how the
    // closed box is themed.
    var selects = document.querySelectorAll('form select');

    selects.forEach(function (select) {
        var wrap = document.createElement('div');
        wrap.className = 'lc-select';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.tabIndex = -1; // reachable by click/label, skipped by Tab -- the trigger button is the real tab stop

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'lc-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        var label = document.createElement('span');
        label.className = 'lc-select-label';
        trigger.appendChild(label);

        var svgNS = 'http://www.w3.org/2000/svg';
        var chevron = document.createElementNS(svgNS, 'svg');
        chevron.setAttribute('class', 'lc-select-chevron');
        chevron.setAttribute('viewBox', '0 0 24 24');
        chevron.setAttribute('fill', 'none');
        chevron.setAttribute('stroke', 'currentColor');
        chevron.setAttribute('stroke-width', '2.6');
        chevron.setAttribute('stroke-linecap', 'round');
        chevron.setAttribute('stroke-linejoin', 'round');
        chevron.setAttribute('aria-hidden', 'true');
        var chevronPath = document.createElementNS(svgNS, 'path');
        chevronPath.setAttribute('d', 'm7 10 5 5 5-5');
        chevron.appendChild(chevronPath);
        trigger.appendChild(chevron);

        var panel = document.createElement('div');
        panel.className = 'lc-select-panel';
        panel.setAttribute('role', 'listbox');
        if (select.id) {
            panel.id = select.id + '_listbox';
            trigger.setAttribute('aria-controls', panel.id);
            trigger.setAttribute('aria-labelledby', select.id + '_trigger_lbl');
            var srcLabel = document.querySelector('label[for="' + select.id + '"]');
            if (srcLabel) {
                if (!srcLabel.id) { srcLabel.id = select.id + '_trigger_lbl'; }
                trigger.setAttribute('aria-labelledby', srcLabel.id);
            }
        }

        var optionEls = [];
        var activeIdx = -1;

        function buildOptions() {
            // The placeholder ("Select your program", etc.) lives in the
            // closed trigger only -- it's not a real choice, so it's left
            // out of the open list entirely rather than just styled as a
            // muted first row. selectIndex(i) below still uses the option's
            // real index into the native <select>, not its position in this
            // (now shorter) rendered list.
            panel.innerHTML = '';
            optionEls = [];
            Array.prototype.forEach.call(select.options, function (opt, i) {
                if (opt.value === '') { return; }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lc-select-option';
                btn.dataset.optIndex = String(i);
                btn.setAttribute('role', 'option');
                btn.setAttribute('aria-selected', 'false');
                btn.textContent = opt.text;
                btn.addEventListener('click', function () {
                    selectIndex(i);
                    close();
                    trigger.focus();
                });
                panel.appendChild(btn);
                optionEls.push(btn);
            });
        }

        function syncFromSelect() {
            var opt = select.options[select.selectedIndex];
            label.textContent = opt ? opt.text : '';
            trigger.classList.toggle('is-placeholder', !!opt && opt.value === '');
            optionEls.forEach(function (btn) {
                var isSel = Number(btn.dataset.optIndex) === select.selectedIndex;
                btn.classList.toggle('is-selected', isSel);
                btn.setAttribute('aria-selected', isSel ? 'true' : 'false');
            });
        }

        function selectIndex(i) {
            if (select.selectedIndex === i) { return; }
            select.selectedIndex = i;
            syncFromSelect();
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function setActive(i) {
            if (i < 0 || i >= optionEls.length) { return; }
            if (activeIdx >= 0 && optionEls[activeIdx]) { optionEls[activeIdx].classList.remove('is-active'); }
            activeIdx = i;
            optionEls[activeIdx].classList.add('is-active');
            optionEls[activeIdx].scrollIntoView({ block: 'nearest' });
        }

        function open() {
            document.querySelectorAll('.lc-select.is-open').forEach(function (openEl) {
                if (openEl !== wrap) {
                    openEl.classList.remove('is-open');
                    var openTrigger = openEl.querySelector('.lc-select-trigger');
                    if (openTrigger) { openTrigger.setAttribute('aria-expanded', 'false'); }
                }
            });
            wrap.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            // Highlight whichever rendered row matches the current value;
            // if nothing's selected yet (the placeholder), fall back to the
            // first real option -- there's no rendered row for the
            // placeholder itself to land on any more.
            var activeStart = 0;
            for (var k = 0; k < optionEls.length; k++) {
                if (Number(optionEls[k].dataset.optIndex) === select.selectedIndex) {
                    activeStart = k;
                    break;
                }
            }
            setActive(activeStart);
        }
        function close() {
            wrap.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }
        function toggle() {
            if (wrap.classList.contains('is-open')) { close(); } else { open(); }
        }

        trigger.addEventListener('click', toggle);
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!wrap.classList.contains('is-open')) { open(); return; }
                var next = activeIdx + (e.key === 'ArrowDown' ? 1 : -1);
                setActive(Math.max(0, Math.min(optionEls.length - 1, next)));
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (wrap.classList.contains('is-open')) {
                    if (activeIdx >= 0) { selectIndex(activeIdx); }
                    close();
                } else {
                    open();
                }
            } else if (e.key === 'Escape') {
                close();
            }
        });

        // A click on the <label for="..."> focuses the (visually hidden)
        // native select directly, bypassing the trigger button entirely --
        // redirect that focus to the trigger and open the panel, so the
        // label still works as a click target the way it visually implies.
        select.addEventListener('focus', function () {
            trigger.focus();
            open();
        });
        select.addEventListener('change', syncFromSelect);

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) { close(); }
        });

        buildOptions();
        syncFromSelect();
        wrap.insertBefore(trigger, select);
        wrap.appendChild(panel);
    });
})();
</script>
<script src="assets/javascript/kiosk-idle.js?v=<?= @filemtime(__DIR__ . '/assets/javascript/kiosk-idle.js') ?: time() ?>"></script>
</body>
</html>
