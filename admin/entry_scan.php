<?php
// Library door entry scanner: a full-screen page for the door computer,
// separate from kiosk sign-in. The USB barcode scanner types like a keyboard,
// so the scan field keeps focus at all times; each scan is sent here by fetch
// and logged, and the field clears for the next one.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isStaff = isset($_SESSION['user_id']) && in_array($currentRole, ['librarian', 'admin'], true);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$isStaff) {
    if ($isPost) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'signed_out']);
        exit;
    }
    header('Location: ../index.php');
    exit;
}

require '../config/db_connect.php';
require_once '../config/csrf.php';
require_once '../config/library_card_helpers.php';
require_once '../config/entry_log_helpers.php';

$csrfKey = 'entry_scan_csrf';

if ($isPost) {
    header('Content-Type: application/json; charset=utf-8');
    if (!smartlib_csrf_verify($csrfKey, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['status' => 'expired']);
        exit;
    }
    $result = entry_log_scan($conn, (string)($_POST['id'] ?? ''), (int)$_SESSION['user_id']);
    $result['today'] = entry_log_today_count($conn);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = smartlib_csrf_token($csrfKey);
$todayCount = entry_log_today_count($conn);
$recent = entry_log_recent($conn);
$cssVer = @filemtime(__DIR__ . '/../assets/css/entry_scan.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib | Library Entry</title>
    <link rel="icon" href="../assets/images/phinma-icon.png" type="image/png">
    <link rel="stylesheet" href="../assets/css/entry_scan.css?v=<?= $cssVer ?>">
</head>
<body>
<div class="scan-page">
    <header class="scan-top">
        <div class="scan-brand">
            <img src="../assets/images/stjude_seal_64.png" alt="">
            <div>
                <strong>Library Entry</strong>
                <span>SmartLib door scanner</span>
            </div>
        </div>
        <div class="scan-top-right">
            <div class="scan-count"><b id="today-count"><?= $todayCount ?></b><span>entries today</span></div>
            <a class="scan-exit" href="dashboard.php">Back to admin</a>
        </div>
    </header>

    <main class="scan-main">
        <section id="scan-status" class="scan-status is-ready" role="status" aria-live="polite">
            <p class="scan-status-label" id="status-label">Ready</p>
            <p class="scan-status-name" id="status-name">Scan a library card</p>
            <p class="scan-status-meta" id="status-meta">&nbsp;</p>
        </section>

        <form id="scan-form" class="scan-form" autocomplete="off">
            <label for="scan-input">Card barcode</label>
            <input id="scan-input" class="scan-input" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" autofocus>
        </form>

        <button type="button" id="manual-toggle" class="scan-manual-toggle">Lost or unreadable card? Type the ID</button>
        <form id="manual-form" class="scan-manual" autocomplete="off" hidden>
            <label for="manual-input">School ID number</label>
            <div class="scan-manual-row">
                <input id="manual-input" type="text" maxlength="24" autocomplete="off" spellcheck="false" placeholder="e.g. 20232243 or 10-2526-001953">
                <button type="submit">Log entry</button>
                <button type="button" id="manual-cancel" class="is-ghost">Cancel</button>
            </div>
        </form>
    </main>

    <aside class="scan-recent">
        <h2>Latest entries</h2>
        <ol id="recent-list">
            <?php foreach ($recent as $row): ?>
                <li>
                    <span class="scan-recent-name"><?= htmlspecialchars($row['name'] !== '' ? $row['name'] : $row['user_number']) ?></span>
                    <span class="scan-recent-meta"><?= htmlspecialchars($row['role']) ?> &middot; <?= htmlspecialchars($row['time']) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
        <p id="recent-empty" class="scan-recent-empty" <?= $recent ? 'hidden' : '' ?>>No entries yet today.</p>
    </aside>
</div>

<script>
(function () {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const scanInput = document.getElementById('scan-input');
    const scanForm = document.getElementById('scan-form');
    const manualToggle = document.getElementById('manual-toggle');
    const manualForm = document.getElementById('manual-form');
    const manualInput = document.getElementById('manual-input');
    const manualCancel = document.getElementById('manual-cancel');
    const statusBox = document.getElementById('scan-status');
    const statusLabel = document.getElementById('status-label');
    const statusName = document.getElementById('status-name');
    const statusMeta = document.getElementById('status-meta');
    const todayCount = document.getElementById('today-count');
    const recentList = document.getElementById('recent-list');
    const recentEmpty = document.getElementById('recent-empty');

    // Scanners send the whole code in a few milliseconds, normally ending
    // in Enter. If this one isn't set to send Enter, a short pause after the
    // last character counts as the end of the scan.
    const SCAN_IDLE_MS = 200;
    const MIN_SCAN_LENGTH = 4;
    const STATUS_RESET_MS = 8000;
    const MAX_RECENT = 8;
    let idleTimer = null;
    let resetTimer = null;

    const manualOpen = () => !manualForm.hidden;

    // The scan field holds focus at all times, unless the librarian is
    // typing in the manual field.
    function keepFocus() {
        if (manualOpen()) return;
        if (document.activeElement !== scanInput) scanInput.focus();
    }
    scanInput.addEventListener('blur', () => setTimeout(keepFocus, 0));
    document.addEventListener('click', e => {
        if (!e.target.closest('a, button')) keepFocus();
    });
    window.addEventListener('focus', keepFocus);
    setInterval(keepFocus, 1000);

    function setStatus(kind, label, name, meta) {
        statusBox.className = 'scan-status is-' + kind;
        statusLabel.textContent = label;
        statusName.textContent = name;
        statusMeta.textContent = meta || ' ';
        clearTimeout(resetTimer);
        if (kind !== 'signed-out') {
            resetTimer = setTimeout(() => setStatus('ready', 'Ready', 'Scan a library card', ''), STATUS_RESET_MS);
        }
    }

    function addRecent(data) {
        const li = document.createElement('li');
        const name = document.createElement('span');
        name.className = 'scan-recent-name';
        name.textContent = data.name || data.user_number;
        const meta = document.createElement('span');
        meta.className = 'scan-recent-meta';
        meta.textContent = data.role + ' · ' + data.time;
        li.append(name, meta);
        recentList.prepend(li);
        while (recentList.children.length > MAX_RECENT) recentList.lastElementChild.remove();
        recentEmpty.hidden = true;
    }

    function showResult(data) {
        if (typeof data.today === 'number') todayCount.textContent = data.today;
        const who = [data.role, data.program].filter(Boolean).join(' · ');
        switch (data.status) {
            case 'ok':
                setStatus('ok', 'Entry logged', data.name, [who, data.user_number, data.time].filter(Boolean).join(' · '));
                addRecent(data);
                break;
            case 'duplicate':
                setStatus('warn', 'Already logged at ' + data.time, data.name, 'Not counted again');
                break;
            case 'inactive':
                setStatus('error', 'Inactive account', data.name, 'Not logged. Please check with the librarian.');
                break;
            case 'not_found':
                setStatus('error', 'Not registered', data.scanned, 'No account has this ID. Not logged.');
                break;
            case 'invalid':
                setStatus('error', 'Could not read that', 'Please scan again', 'Or type the ID below');
                break;
            case 'expired':
                setStatus('signed-out', 'Page expired', 'Please reload this page', '');
                break;
            case 'signed_out':
                setStatus('signed-out', 'Signed out', 'Sign in again to keep logging', '');
                break;
            default:
                setStatus('error', 'Something went wrong', 'Not logged. Please scan again.', '');
        }
    }

    async function submitId(value) {
        const id = value.trim();
        if (id === '') return;
        const body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('id', id);
        try {
            const res = await fetch('entry_scan.php', { method: 'POST', body, credentials: 'same-origin' });
            showResult(await res.json());
        } catch (_err) {
            setStatus('error', 'Connection problem', 'Not logged. Please scan again.', '');
        }
    }

    function submitScan() {
        clearTimeout(idleTimer);
        const value = scanInput.value;
        scanInput.value = '';
        submitId(value);
    }

    scanForm.addEventListener('submit', e => {
        e.preventDefault();
        submitScan();
    });
    scanInput.addEventListener('input', () => {
        clearTimeout(idleTimer);
        if (scanInput.value.trim().length >= MIN_SCAN_LENGTH) {
            idleTimer = setTimeout(submitScan, SCAN_IDLE_MS);
        }
    });

    function closeManual() {
        manualForm.hidden = true;
        manualInput.value = '';
        manualToggle.hidden = false;
        keepFocus();
    }
    manualToggle.addEventListener('click', () => {
        manualToggle.hidden = true;
        manualForm.hidden = false;
        manualInput.focus();
    });
    manualCancel.addEventListener('click', closeManual);
    manualInput.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeManual();
    });
    manualForm.addEventListener('submit', e => {
        e.preventDefault();
        submitId(manualInput.value);
        closeManual();
    });

    keepFocus();
})();
</script>
</body>
</html>
