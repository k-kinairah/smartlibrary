<?php
// Library door entry scanner: a full-screen page for the door computer,
// separate from kiosk sign-in. The USB barcode scanner types like a keyboard,
// so the scan field keeps focus at all times; each scan is sent here by fetch
// and logged, and the field clears for the next one.
//
// The page stays open and signed in all day. PHP's session cleanup removes
// any session file untouched for 24 minutes, and session files are shared
// with the kiosk (whose requests run that cleanup with the default limit), so
// raising the limit here wouldn't protect this session. Instead the open page
// sends a heartbeat (action=ping below), which keeps the session file fresh
// and doubles as the connection check shown on screen.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isStaff = isset($_SESSION['user_id']) && in_array($currentRole, ['librarian', 'admin'], true);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isStaff) {
    // Live check: still active and still staff (see config/session_guard.php).
    require '../config/db_connect.php';
    require_once '../config/session_guard.php';
    $isStaff = smartlib_active_staff_session($conn);
}

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
    if (($_POST['action'] ?? '') === 'ping') {
        echo json_encode(['status' => 'alive', 'today' => entry_log_today_count($conn)]);
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
$staffName = trim((string)($_SESSION['name'] ?? ''));
$avatarIcon = '<span class="scan-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"></path></svg></span>';
// Initials for the avatar circle (first and last word of the name); the
// generic icon when there is no name to take them from.
$avatarFor = static function (string $name) use ($avatarIcon): string {
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) {
        return $avatarIcon;
    }
    $first = static fn(string $w): string => function_exists('mb_substr') ? mb_substr($w, 0, 1) : substr($w, 0, 1);
    $initials = $first($words[0]) . (count($words) > 1 ? $first($words[count($words) - 1]) : '');
    return '<span class="scan-avatar" aria-hidden="true">' . htmlspecialchars(function_exists('mb_strtoupper') ? mb_strtoupper($initials) : strtoupper($initials)) . '</span>';
};
$cssVer = @filemtime(__DIR__ . '/../assets/css/entry_scan.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib | Library Entry</title>
    <link rel="icon" href="../assets/images/stjude_seal_64.png" type="image/png">
    <link rel="stylesheet" href="../assets/css/entry_scan.css?v=<?= $cssVer ?>">
</head>
<body>
<div class="scan-page">
    <header class="scan-top">
        <div class="scan-brand">
            <div class="scan-seal">
                <i class="scan-seal-halo" aria-hidden="true"></i>
                <img src="../assets/images/stjude_seal_64.png" alt="">
            </div>
            <div>
                <strong>PHINMA-SJC Dasma Library</strong>
                <div class="scan-brand-rule"></div>
                <span>SmartLib Door Entry</span>
            </div>
        </div>
        <div class="scan-toolbar">
            <div class="scan-toolbar-item scan-listen is-ok" id="listen-status">
                <i aria-hidden="true"></i><span id="listen-label">Ready to scan</span>
            </div>
            <div class="scan-toolbar-divider" aria-hidden="true"></div>
            <div class="scan-toolbar-item scan-clock" id="clock"></div>
            <div class="scan-toolbar-divider" aria-hidden="true"></div>
            <div class="scan-toolbar-item scan-count"><b id="today-count"><?= $todayCount ?></b><span>entries today</span></div>
        </div>
    </header>

    <main class="scan-main">
        <section id="scan-status" class="scan-status is-ready" role="status" aria-live="polite"></section>

        <div class="scan-bar">
            <svg class="scan-bar-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5v14M6.5 5v14M10 5v14M13 5v14M17 5v14M21 5v14"></path></svg>
            <form id="scan-form" class="scan-form" autocomplete="off">
                <label for="scan-input">Card barcode</label>
                <input id="scan-input" class="scan-input" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Waiting for a scan" autofocus>
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
        </div>
    </main>

    <aside class="scan-recent" id="recent-panel">
        <div class="scan-recent-head"><i class="scan-recent-live" aria-hidden="true"></i><h2>Latest entries</h2><span class="scan-recent-pill">Live</span></div>
        <ol id="recent-list">
            <?php foreach ($recent as $row): ?>
                <li>
                    <?= $avatarFor($row['name']) ?>
                    <span class="scan-recent-text">
                        <span class="scan-recent-name"><?= htmlspecialchars($row['name'] !== '' ? $row['name'] : $row['user_number']) ?></span>
                        <span class="scan-recent-meta"><?= htmlspecialchars($row['role']) ?> &middot; <?= htmlspecialchars($row['time']) ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
        <p id="recent-empty" class="scan-recent-empty" <?= $recent ? 'hidden' : '' ?>>Entries will appear here as scans come in</p>
    </aside>

    <footer class="scan-footer">
        <span id="connection-status">Connected to <b>SmartLib</b></span>
        <span>Signed in as <b><?= htmlspecialchars($staffName !== '' ? $staffName : 'staff') ?></b> &middot; <a href="../logout.php">Sign out</a></span>
    </footer>
</div>

<template id="avatar-icon"><?= $avatarIcon ?></template>

<template id="ready-graphic">
    <div class="scan-idle-glow"></div>
    <div class="scan-idle-warmth"></div>
    <div class="scan-corner-ring is-lower"></div>
    <div class="scan-corner-ring is-upper"></div>
    <div class="scan-panel-grid"></div>
    <div class="scan-panel-sweep"></div>
    <span class="scan-mote" style="width:3px; height:3px; left:10%; bottom:14%; animation-duration:7s;"></span>
    <span class="scan-mote" style="width:2px; height:2px; left:16%; bottom:55%; animation-duration:9s; animation-delay:2s;"></span>
    <span class="scan-mote" style="width:4px; height:4px; left:8%; bottom:78%; animation-duration:8s; animation-delay:1s;"></span>
    <span class="scan-mote" style="width:2px; height:2px; left:82%; bottom:66%; animation-duration:10s; animation-delay:3.5s;"></span>
    <span class="scan-mote" style="width:3px; height:3px; left:90%; bottom:18%; animation-duration:7.5s; animation-delay:4.5s;"></span>
    <span class="scan-mote" style="width:2px; height:2px; left:88%; bottom:44%; animation-duration:8.5s; animation-delay:0.6s;"></span>
    <span class="scan-mote" style="width:3px; height:3px; left:30%; bottom:82%; animation-duration:9.5s; animation-delay:2.8s;"></span>
    <span class="scan-mote" style="width:2px; height:2px; left:68%; bottom:85%; animation-duration:8.2s; animation-delay:5.2s;"></span>
    <span class="scan-mote" style="width:3px; height:3px; left:50%; bottom:10%; animation-duration:7.8s; animation-delay:1.6s;"></span>
    <span class="scan-mote" style="width:2px; height:2px; left:40%; bottom:68%; animation-duration:9.2s; animation-delay:3.2s;"></span>
    <div class="scan-ring">
        <div class="scan-sonar"></div>
        <div class="scan-sonar"></div>
        <div class="scan-sonar"></div>
        <div class="scan-ring-spin"></div>
        <div class="scan-ring-core">
            <div class="scan-ring-line"></div>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V4h3M20 7V4h-3M4 17v3h3M20 17v3h-3"></path></svg>
        </div>
    </div>
</template>

<template id="success-graphic">
    <div class="scan-flash"></div>
    <div class="scan-badge-stage">
        <div class="scan-burst"></div>
        <div class="scan-burst"></div>
        <div class="scan-burst"></div>
        <span class="scan-sparkle" style="--sx:-62px; --sy:-42px; animation-delay:.32s;"></span>
        <span class="scan-sparkle" style="--sx:64px; --sy:-48px; animation-delay:.4s;"></span>
        <span class="scan-sparkle" style="--sx:-78px; --sy:8px; animation-delay:.36s;"></span>
        <span class="scan-sparkle" style="--sx:78px; --sy:14px; animation-delay:.44s;"></span>
        <span class="scan-sparkle" style="--sx:-36px; --sy:58px; animation-delay:.48s;"></span>
        <span class="scan-sparkle" style="--sx:38px; --sy:58px; animation-delay:.52s;"></span>
        <div class="scan-check">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7"></path></svg>
        </div>
    </div>
</template>

<template id="warn-graphic">
    <div class="scan-state-badge is-warn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7.5V12l3 2"></path></svg>
    </div>
</template>

<template id="error-graphic">
    <div class="scan-state-badge is-error">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17"></path></svg>
    </div>
</template>

<template id="signed-out-graphic">
    <div class="scan-state-badge is-error">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17"></path></svg>
    </div>
</template>

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
    const todayCount = document.getElementById('today-count');
    const recentList = document.getElementById('recent-list');
    const recentEmpty = document.getElementById('recent-empty');
    const recentPanel = document.getElementById('recent-panel');
    const listenStatus = document.getElementById('listen-status');
    const listenLabel = document.getElementById('listen-label');
    const clock = document.getElementById('clock');
    const connectionStatus = document.getElementById('connection-status');

    // Scanners send the whole code in a few milliseconds, normally ending
    // in Enter. If this one isn't set to send Enter, a short pause after the
    // last character counts as the end of the scan, but only when every
    // character arrived at scanner speed: someone typing in this field has
    // pauses too, and must press Enter. Anything left sitting unsubmitted is
    // cleared so it can't get glued onto the front of the next scan.
    const SCAN_IDLE_MS = 200;
    const SCAN_MAX_KEY_GAP_MS = 60;
    const STALE_INPUT_MS = 10000;
    const MIN_SCAN_LENGTH = 4;
    const STATUS_RESET_MS = 8000;
    const MAX_RECENT = 8;
    // Well under PHP's 24-minute session cleanup, and frequent enough that
    // the connection line on screen is never long out of date.
    const HEARTBEAT_MS = 60000;
    let idleTimer = null;
    let lastInputAt = 0;
    let scannerPace = true;
    let resetTimer = null;
    let online = true;
    let signedOut = false;

    const manualOpen = () => !manualForm.hidden;

    // ---- Toolbar and footer ---------------------------------------------

    // A USB scanner is just a keyboard to the browser, so the page can't see
    // whether one is plugged in. What it can report is whether a scan would
    // land right now: the page has focus and the server is answering.
    function updateListening() {
        let kind = 'is-ok';
        let label = 'Ready to scan';
        if (signedOut) {
            kind = 'is-error';
            label = 'Signed out';
        } else if (!online) {
            kind = 'is-error';
            label = 'Offline';
        } else if (!document.hasFocus()) {
            kind = 'is-warn';
            label = 'Click here to scan';
        } else if (manualOpen()) {
            kind = 'is-warn';
            label = 'Typing an ID';
        }
        listenStatus.className = 'scan-toolbar-item scan-listen ' + kind;
        listenLabel.textContent = label;
    }

    function setOnline(value) {
        online = value;
        connectionStatus.className = value ? '' : 'is-lost';
        recentPanel.classList.toggle('is-stale', !value);
        connectionStatus.replaceChildren();
        if (value) {
            const name = document.createElement('b');
            name.textContent = 'SmartLib';
            connectionStatus.append('Connected to ', name);
        } else {
            connectionStatus.textContent = 'Connection lost. Retrying…';
        }
        updateListening();
    }

    function tickClock() {
        clock.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    }
    tickClock();
    setInterval(tickClock, 1000);

    // ---- Focus ----------------------------------------------------------

    // The scan field holds focus at all times, unless the librarian is
    // typing in the manual field.
    function keepFocus() {
        if (!manualOpen() && document.activeElement !== scanInput) scanInput.focus();
        updateListening();
    }
    scanInput.addEventListener('blur', () => setTimeout(keepFocus, 0));
    document.addEventListener('click', e => {
        if (!e.target.closest('a, button')) keepFocus();
    });
    window.addEventListener('focus', keepFocus);
    window.addEventListener('blur', updateListening);
    setInterval(keepFocus, 1000);

    // ---- Result panel ---------------------------------------------------

    function textLine(className, text) {
        const el = document.createElement('p');
        el.className = className;
        el.textContent = text;
        return el;
    }

    // Rebuilt from scratch every time, so a second scan restarts the success
    // animation instead of leaving it on its finished frame.
    function setStatus(kind, label, name, meta) {
        const graphic = document.getElementById(kind + '-graphic');
        statusBox.className = 'scan-status is-' + kind;
        statusBox.replaceChildren(
            ...(graphic ? [graphic.content.cloneNode(true)] : []),
            textLine('scan-status-label', label),
            textLine('scan-status-name', name),
            textLine('scan-status-meta', meta || ' ')
        );
        clearTimeout(resetTimer);
        if (kind !== 'signed-out' && kind !== 'ready') {
            resetTimer = setTimeout(showReady, STATUS_RESET_MS);
        }
    }

    function showReady() {
        setStatus('ready', 'Ready', 'Scan a library card', 'Hold the barcode toward the scanner');
    }

    function addRecent(data) {
        const li = document.createElement('li');
        li.className = 'is-new';
        // Initials when there is a name; the generic icon otherwise.
        const words = (data.name || '').trim().split(/\s+/).filter(Boolean);
        let avatar;
        if (words.length) {
            avatar = document.createElement('span');
            avatar.className = 'scan-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = (Array.from(words[0])[0] + (words.length > 1 ? Array.from(words[words.length - 1])[0] : '')).toUpperCase();
        } else {
            avatar = document.getElementById('avatar-icon').content.cloneNode(true);
        }
        const text = document.createElement('span');
        text.className = 'scan-recent-text';
        const name = document.createElement('span');
        name.className = 'scan-recent-name';
        name.textContent = data.name || data.user_number;
        const meta = document.createElement('span');
        meta.className = 'scan-recent-meta';
        meta.textContent = data.role + ' · ' + data.time;
        text.append(name, meta);
        li.append(avatar, text);
        recentList.prepend(li);
        while (recentList.children.length > MAX_RECENT) recentList.lastElementChild.remove();
        recentEmpty.hidden = true;
    }

    function setToday(count) {
        if (typeof count !== 'number') return;
        todayCount.textContent = count;
        // Left open overnight: yesterday's names shouldn't stay on screen.
        if (count === 0) {
            recentList.replaceChildren();
            recentEmpty.hidden = false;
        }
    }

    function markSignedOut(label, name) {
        signedOut = true;
        recentPanel.classList.add('is-stale');
        setStatus('signed-out', label, name, '');
        updateListening();
    }

    function showResult(data) {
        setToday(data.today);
        const who = [data.role, data.program].filter(Boolean).join(' · ');
        switch (data.status) {
            case 'ok':
                setStatus('success', 'Entry logged · ' + data.time, data.name, [who, data.user_number].filter(Boolean).join(' · '));
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
                markSignedOut('Page expired', 'Please reload this page');
                break;
            case 'signed_out':
                markSignedOut('Signed out', 'Sign in again to keep logging');
                break;
            default:
                setStatus('error', 'Something went wrong', 'Not logged. Please scan again.', '');
        }
    }

    // ---- Server ---------------------------------------------------------

    function post(fields) {
        const body = new FormData();
        body.append('csrf_token', csrfToken);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        return fetch('entry_scan.php', { method: 'POST', body, credentials: 'same-origin' })
            .then(res => res.json());
    }

    async function submitId(value) {
        const id = value.trim();
        if (id === '') return;
        try {
            const data = await post({ id });
            setOnline(true);
            showResult(data);
        } catch (_err) {
            setOnline(false);
            setStatus('error', 'Connection problem', 'Not logged. Please scan again.', '');
        }
    }

    async function heartbeat() {
        try {
            const data = await post({ action: 'ping' });
            setOnline(true);
            if (data.status === 'alive') setToday(data.today);
            else showResult(data);
        } catch (_err) {
            setOnline(false);
        }
    }
    setInterval(heartbeat, HEARTBEAT_MS);

    // ---- Scan and manual entry ------------------------------------------

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
        const now = performance.now();
        if (scanInput.value.length <= 1) scannerPace = true;
        else if (now - lastInputAt > SCAN_MAX_KEY_GAP_MS) scannerPace = false;
        lastInputAt = now;
        if (scannerPace && scanInput.value.trim().length >= MIN_SCAN_LENGTH) {
            idleTimer = setTimeout(submitScan, SCAN_IDLE_MS);
        } else {
            idleTimer = setTimeout(() => { scanInput.value = ''; }, STALE_INPUT_MS);
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
        updateListening();
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

    showReady();
    keepFocus();
})();
</script>
</body>
</html>
