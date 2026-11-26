<?php
// Library settings for librarians and admins. For now one setting: overdue
// fines on/off (off by default) and the amount per weekday late. Stored in
// library_settings (config/library_settings.php); every change is written to
// the admin activity log (Archives).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

require '../config/db_connect.php';
require_once '../config/session_guard.php';
if (!smartlib_active_staff_session($conn)) {
    header('Location: ' . smartlib_staff_signed_out_location());
    exit;
}
require_once '../config/csrf.php';
require_once '../config/library_settings.php';
require_once '../config/admin_audit.php';

const SETTINGS_FINE_RATE_MIN = 0.01;
const SETTINGS_FINE_RATE_MAX = 1000.00;

function settings_flash(string $type, string $message): void {
    $_SESSION['settings_flash'] = ['type' => $type, 'message' => $message];
}

function settings_money(float $value): string {
    return number_format($value, 2, '.', '');
}

$csrfKey = 'library_settings_csrf';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!smartlib_csrf_verify($csrfKey, $_POST['csrf_token'] ?? '')) {
        settings_flash('error', 'Your session expired. Please try again.');
        header('Location: settings.php');
        exit;
    }

    $current = smartlib_fines_settings($conn);
    $wantEnabled = ($_POST['fines_enabled'] ?? '') === '1';
    $rawRate = trim((string)($_POST['fines_rate'] ?? ''));
    $rate = is_numeric($rawRate) ? round((float)$rawRate, 2) : null;

    if ($rate === null || $rate < SETTINGS_FINE_RATE_MIN || $rate > SETTINGS_FINE_RATE_MAX) {
        settings_flash('error', 'Enter an amount from 0.01 to 1,000.00.');
        header('Location: settings.php');
        exit;
    }

    $turningOn = $wantEnabled && !$current['enabled'];
    $turningOff = !$wantEnabled && $current['enabled'];
    $rateChanged = abs($rate - (float)$current['rate']) > 0.0049;

    if (!$turningOn && !$turningOff && !$rateChanged) {
        settings_flash('info', 'No changes to save.');
        header('Location: settings.php');
        exit;
    }

    $actorId = (int)($_SESSION['user_id'] ?? 0);
    $ok = smartlib_setting_set($conn, 'fines_enabled', $wantEnabled ? '1' : '0', $actorId)
        && smartlib_setting_set($conn, 'fines_rate', settings_money($rate), $actorId);
    // Every off -> on starts counting from today: no fines for the time off.
    $startDate = $current['start_date'];
    if ($ok && $turningOn) {
        $startDate = smartlib_today_manila();
        $ok = smartlib_setting_set($conn, 'fines_start_date', $startDate, $actorId);
    }

    if (!$ok) {
        settings_flash('error', 'The settings could not be saved. Please try again.');
        header('Location: settings.php');
        exit;
    }

    if ($turningOn) {
        $detail = 'Overdue fines turned ON, rate ' . settings_money($rate) . ', from ' . $startDate;
    } elseif ($turningOff) {
        $detail = 'Overdue fines turned OFF';
    } elseif ($wantEnabled) {
        $detail = 'Overdue fine rate changed from ' . settings_money((float)$current['rate']) . ' to ' . settings_money($rate);
    } else {
        $detail = 'Overdue fine rate set to ' . settings_money($rate) . ' (fines are off)';
    }
    smartlib_admin_audit_log($conn, 'settings_changed', 'settings', 0, 'Overdue fines', [
        'detail' => $detail,
        'fines_enabled' => $wantEnabled,
        'rate' => settings_money($rate),
        'start_date' => $wantEnabled ? $startDate : null,
        'previous_enabled' => (bool)$current['enabled'],
        'previous_rate' => settings_money((float)$current['rate']),
    ]);

    if ($turningOn) {
        $message = 'Saved. Overdue fines are on from today.';
    } elseif ($turningOff) {
        $message = 'Saved. Overdue fines are off.';
    } else {
        $message = 'Saved. The amount per weekday late is now ' . settings_money($rate) . '.';
    }
    settings_flash('success', $message);
    header('Location: settings.php');
    exit;
}

$fines = smartlib_fines_settings($conn);
$csrfToken = smartlib_csrf_token($csrfKey);
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

$startLabel = '';
if ($fines['enabled'] && $fines['start_date'] !== null) {
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $fines['start_date']);
    $startLabel = $startDt ? $startDt->format('F j, Y') : $fines['start_date'];
}

require 'layout_top.php';
?>

<div class="page-top settings-top">
    <div>
        <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('settings') ?></span>Settings</h1>
        <p class="page-subtitle">Library rules that apply to the kiosk and these librarian pages.</p>
    </div>
</div>

<?php if (is_array($flash) && !empty($flash['message'])): ?>
    <section class="panel glass-card borrow-flash borrow-flash-<?= htmlspecialchars((string)($flash['type'] ?? 'info')) ?>" role="status">
        <?= htmlspecialchars((string)$flash['message']) ?>
    </section>
<?php endif; ?>

<section class="panel glass-card settings-panel data-table-panel">
    <div class="panel-head">
        <h2><span class="panel-icon" aria-hidden="true"><?= admin_icon('currency') ?></span>Overdue fines</h2>
    </div>

    <form method="POST" class="settings-form" action="settings.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="settings-row">
            <div class="settings-row-text">
                <label class="settings-label" for="fines_enabled">Charge overdue fines</label>
                <p class="settings-help">When fines are off, no fine shows anywhere and nothing is charged. When you turn them on, only days late from that day on are charged.</p>
            </div>
            <label class="settings-switch">
                <input type="checkbox" id="fines_enabled" name="fines_enabled" value="1" role="switch"<?= $fines['enabled'] ? ' checked' : '' ?>>
                <span class="settings-switch-track" aria-hidden="true"><span class="settings-switch-knob"></span></span>
                <span class="settings-switch-text"><span class="settings-switch-on">On</span><span class="settings-switch-off">Off</span></span>
            </label>
        </div>

        <div class="settings-row">
            <div class="settings-row-text">
                <label class="settings-label" for="fines_rate">Amount per weekday late (&#8369;)</label>
                <p class="settings-help">Weekends are not counted. From 0.01 to 1,000.00.</p>
            </div>
            <input class="settings-input" type="number" id="fines_rate" name="fines_rate" inputmode="decimal" min="0.01" max="1000" step="0.01" required value="<?= htmlspecialchars(settings_money((float)$fines['rate'])) ?>">
        </div>

        <?php if ($startLabel !== ''): ?>
            <p class="settings-status">Fines started on <strong><?= htmlspecialchars($startLabel) ?></strong>.</p>
        <?php endif; ?>

        <div class="settings-actions">
            <button type="submit" class="btn-primary">Save</button>
        </div>
    </form>
</section>

<?php require 'layout_bottom.php'; ?>
