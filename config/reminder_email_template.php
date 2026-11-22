<?php
/**
 * Due-soon and overdue reminder emails (kiosk "Look B": dark green card,
 * gold accents, colour-coded countdown block). Shared by
 * send_due_date_reminders.php (CLI worker) and send_overdue_reminder_email.php
 * (librarian button) so the two can't drift apart.
 *
 * $d keys (all values RAW; the HTML builder escapes each exactly once, the
 * text builder uses them as they are):
 *   kind          'due_soon' | 'overdue'
 *   days          int: days left (due_soon: 3, 2 or 1) or days late (overdue)
 *   name, id      borrower name and ID number
 *   aff_label     'Course' or 'Department'
 *   aff           program or department name
 *   title, author, accession, isbn
 *   borrowed_date raw date/datetime
 *   due_date      raw Y-m-d
 *   inline_logo   bool (default false): embed the school logo (cid:smartlib_logo)
 *                 when AUTH_EMAIL_EMBED_IMAGES is on; otherwise an "SL" badge.
 *                 Only callers that can send inline images may set it.
 *   fines_on      bool (default false when missing): overdue fines are switched
 *                 on in Settings (smartlib_fines_enabled()). Only then does the
 *                 closing sentence mention penalties.
 *
 * Email-safe like send_receipt_email.php: tables, bgcolor + background-color
 * on every coloured cell, sized gap divs instead of margins on tables. No
 * flexbox, grid, backdrop-filter or rgba() borders: Outlook drops them.
 * border-radius is decoration only (Outlook shows square corners).
 */

const SMARTLIB_REMINDER_LIBRARY = 'PHINMA-SJCDC Library';

function smartlib_reminder_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function smartlib_reminder_value(array $d, string $key): string {
    $value = trim((string)($d[$key] ?? ''));
    return $value !== '' ? $value : 'N/A';
}

function smartlib_reminder_date(string $raw, string $format): string {
    $raw = trim($raw);
    $ts = $raw !== '' ? strtotime($raw) : false;
    return $ts !== false ? date($format, $ts) : $raw;
}

/** True when the school logo will be embedded (and must be attached as cid:smartlib_logo). */
function smartlib_reminder_logo_embedded(array $d): bool {
    $logoPath = dirname(__DIR__) . '/assets/images/stjude_logo.jpg';
    return !empty($d['inline_logo'])
        && defined('AUTH_EMAIL_EMBED_IMAGES') && AUTH_EMAIL_EMBED_IMAGES
        && is_file($logoPath) && is_readable($logoPath);
}

/**
 * Inline images the caller must attach (5th argument of
 * auth_send_multipart_email()). Empty unless the logo is embedded.
 */
function smartlib_reminder_email_inline_images(array $d): array {
    if (!smartlib_reminder_logo_embedded($d)) {
        return [];
    }
    return [['cid' => 'smartlib_logo', 'path' => dirname(__DIR__) . '/assets/images/stjude_logo.jpg', 'mime' => 'image/jpeg']];
}

/**
 * Countdown state: label, big text, unit, date line and colours
 * [block bg, border, label, big, unit, date].
 */
function smartlib_reminder_state(array $d): array {
    $days = (int)($d['days'] ?? 0);
    $due = smartlib_reminder_date((string)($d['due_date'] ?? ''), 'l, F j, Y');

    if (($d['kind'] ?? '') === 'overdue') {
        $late = ['#8c1d2e', '#8c1d2e', '#ffd6dc', '#ffffff', '#ffe3e7', '#ffe9ec'];
        if ($days >= 1) {
            $unit = $days === 1 ? 'day late' : 'days late';
            return ['label' => 'OVERDUE', 'big' => (string)$days, 'unit' => $unit, 'date' => 'Was due ' . $due,
                'text' => "{$days} {$unit} - was due {$due}", 'tones' => $late];
        }
        return ['label' => 'PAST DUE', 'big' => 'Overdue', 'unit' => '', 'date' => 'Was due ' . $due,
            'text' => "Overdue - was due {$due}", 'tones' => $late];
    }

    if ($days === 1) {
        return ['label' => 'HEADS UP', 'big' => 'Due tomorrow', 'unit' => '', 'date' => $due,
            'text' => "Due tomorrow - {$due}", 'tones' => ['#ffab2e', '#c97a00', '#5a3300', '#2b1700', '#2b1700', '#3d2200']];
    }
    if ($days === 2) {
        return ['label' => 'DUE IN', 'big' => '2', 'unit' => 'days', 'date' => $due,
            'text' => "Due in 2 days - {$due}", 'tones' => ['#ffd84d', '#e8bd00', '#5a4a12', '#2f2815', '#2f2815', '#3d3210']];
    }
    // 3 days, and anything unexpected
    return ['label' => 'DUE IN', 'big' => '3', 'unit' => 'days', 'date' => $due,
        'text' => "Due in 3 days - {$due}", 'tones' => ['#1d6a50', '#3fcf8e', '#cfe9df', '#9ff0c8', '#ffffff', '#e8f6ef']];
}

function smartlib_reminder_copy(array $d): array {
    $overdue = ($d['kind'] ?? '') === 'overdue';
    return [
        'title' => $overdue ? 'SmartLib Overdue Reminder' : 'SmartLib Due Date Reminder',
        'lead' => $overdue ? 'One of your borrowed books is past its due date.' : 'A book you borrowed is due soon.',
        // Penalties are only mentioned while overdue fines are switched on.
        'closing' => !empty($d['fines_on'])
            ? ($overdue
                ? 'Please return this book as soon as possible to avoid additional penalties.'
                : 'Please return this book on or before the due date to avoid overdue penalties.')
            : ($overdue
                ? 'Please return this book as soon as possible.'
                : 'Please return this book on or before the due date.'),
        'renew' => !$overdue,
    ];
}

function smartlib_reminder_email_html(array $d): string {
    $gold = '#ffd84d';
    $page = '#0b231a';
    $card = '#0f3a2b';
    $panelBg = '#17483a';
    $text = '#eaf6ef';
    $muted = '#a9c7b7';
    $line = '#2f6a55';
    $brand = '#164436';

    $e = 'smartlib_reminder_e';
    $copy = smartlib_reminder_copy($d);
    $s = smartlib_reminder_state($d);
    [$bg, $bd, $labC, $bigC, $unitC, $dateC] = $s['tones'];

    $gap = static fn(int $px): string => '<div style="height:' . $px . 'px;line-height:' . $px . 'px;font-size:1px;">&nbsp;</div>';
    $rule = static fn(): string => '<td width="30" height="1" bgcolor="' . $gold . '" style="width:30px;height:1px;line-height:1px;font-size:1px;background-color:' . $gold . ';">&nbsp;</td>';
    $flank = static function (string $label) use ($rule, $gold): string {
        $side = '<td style="vertical-align:middle;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $rule() . '</tr></table></td>';
        return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>' . $side
            . '<td style="vertical-align:middle;padding:0 10px;font-size:11px;line-height:16px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;white-space:nowrap;color:' . $gold . ';">' . $label . '</td>' . $side . '</tr></table>';
    };
    // $fields: [label (escaped), value (escaped), nowrap]
    $strip = static function (array $fields) use ($line, $muted, $text): string {
        $cells = '';
        foreach ($fields as $i => [$label, $value, $nowrap]) {
            $cells .= '<td align="center" style="padding:0 14px;text-align:center;vertical-align:top;' . ($i > 0 ? 'border-left:1px solid ' . $line . ';' : '') . '">'
                . '<div style="font-size:11px;line-height:15px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap;color:' . $muted . ';">' . $label . '</div>'
                . '<div style="font-size:14px;line-height:20px;font-weight:700;color:' . $text . ';' . ($nowrap ? 'white-space:nowrap;' : '') . '">' . $value . '</div></td>';
        }
        return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>' . $cells . '</tr></table>';
    };
    $panel = static fn(string $inner): string => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $panelBg . '" style="background-color:' . $panelBg . ';border:1px solid ' . $line . ';border-radius:12px;">'
        . '<tr><td style="padding:14px 12px;">' . $inner . '</td></tr></table>';

    // Countdown block
    $bigSize = ctype_digit($s['big']) ? 52 : 34;
    $unitHtml = $s['unit'] !== '' ? '<span style="font-size:20px;font-weight:700;color:' . $unitC . ';"> ' . $e($s['unit']) . '</span>' : '';
    $block = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $bg . '" style="background-color:' . $bg . ';border:1px solid ' . $bd . ';border-radius:12px;">'
        . '<tr><td align="center" style="padding:16px 16px 15px;">'
        . '<div style="font-size:12px;line-height:16px;font-weight:700;letter-spacing:.14em;color:' . $labC . ';">' . $e($s['label']) . '</div>'
        . '<div style="margin-top:2px;font-size:' . $bigSize . 'px;line-height:' . ($bigSize + 6) . 'px;font-weight:800;color:' . $bigC . ';">' . $e($s['big']) . $unitHtml . '</div>'
        . '<div style="margin-top:4px;font-size:15px;line-height:20px;font-weight:700;color:' . $dateC . ';">' . $e($s['date']) . '</div>'
        . '</td></tr></table>';

    // Logo: embedded school logo when allowed, otherwise the "SL" badge (gold ring).
    $logo = smartlib_reminder_logo_embedded($d)
        ? '<img src="cid:smartlib_logo" alt="SmartLib" style="display:block;margin:0 auto 8px;width:52px;height:52px;border-radius:50%;border:2px solid ' . $gold . ';background:#ffffff;object-fit:cover;">'
        : '<div style="display:inline-block;margin:0 auto 8px;width:42px;height:42px;line-height:42px;border-radius:50%;border:2px solid ' . $gold . ';background:#ffffff;color:' . $brand . ';font-weight:700;">SL</div>';

    $book = '<div style="text-align:center;font-size:18px;line-height:24px;font-weight:700;color:#ffffff;">' . $e(smartlib_reminder_value($d, 'title')) . '</div>' . $gap(10)
        . $strip([['Author', $e(smartlib_reminder_value($d, 'author')), false]]) . $gap(8)
        . $strip([['Accession No.', $e(smartlib_reminder_value($d, 'accession')), true], ['ISBN', $e(smartlib_reminder_value($d, 'isbn')), true]]);

    $renew = $copy['renew']
        ? $panel('<div style="font-size:13px;line-height:1.6;text-align:center;color:' . $text . ';"><strong style="color:' . $gold . ';">Need more time?</strong> Renew once at the kiosk: sign in, open <strong>My Books</strong>, then tap <strong>Renew</strong>. Overdue or fined books can&#39;t be renewed.</div>') . $gap(12)
        : '';

    $affLabel = trim((string)($d['aff_label'] ?? '')) !== '' ? trim((string)$d['aff_label']) : 'Course';
    $borrowed = smartlib_reminder_date((string)($d['borrowed_date'] ?? ''), 'F j, Y');

    $body = '<div style="text-align:center;font-size:15px;line-height:22px;color:' . $text . ';">Hello, <strong>' . $e(smartlib_reminder_value($d, 'name')) . '</strong></div>'
        . '<div style="text-align:center;font-size:14px;line-height:21px;color:' . $muted . ';">' . $copy['lead'] . '</div>' . $gap(14)
        . $block . $gap(20)
        . $flank('Book Information') . $gap(8) . $panel($book) . $gap(18)
        . $flank('Borrower Information') . $gap(8) . $panel($strip([['ID Number', $e(smartlib_reminder_value($d, 'id')), true], [$e($affLabel), $e(smartlib_reminder_value($d, 'aff')), false]])) . $gap(18)
        . $flank('Loan Details') . $gap(8) . $panel($strip([['Borrowed', $e($borrowed !== '' ? $borrowed : 'N/A'), true]])) . $gap(20)
        . $renew
        . '<div style="text-align:center;font-size:13px;line-height:1.6;font-style:italic;color:' . $muted . ';">' . $copy['closing'] . '</div>';

    $sent = date('F j, Y');
    $title = $copy['title'];
    $library = SMARTLIB_REMINDER_LIBRARY;

    return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:{$page};font-family:Arial,Helvetica,sans-serif;color:{$text};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$page}" style="background:{$page};background-color:{$page};padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:630px;border-collapse:separate;"><tr>
<td bgcolor="{$card}" style="background-color:{$card};border:1px solid {$line};border-radius:16px;overflow:hidden;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
<tr><td bgcolor="#0c2f23" style="background-color:#0c2f23;border-bottom:2px solid {$gold};padding:20px 24px;color:#ffffff;text-align:center;border-radius:15px 15px 0 0;">
{$logo}
<div style="font-size:18px;font-weight:700;letter-spacing:.3px;color:#ffffff;">{$title}</div>
<div style="font-size:12px;margin-top:4px;color:{$muted};">{$library} &middot; {$sent}</div></td></tr>
<tr><td bgcolor="{$card}" style="background-color:{$card};padding:24px 24px 22px;">{$body}</td></tr></table></td></tr></table></td></tr></table></body></html>
HTML;
}

function smartlib_reminder_email_text(array $d): string {
    $copy = smartlib_reminder_copy($d);
    $s = smartlib_reminder_state($d);
    $affLabel = trim((string)($d['aff_label'] ?? '')) !== '' ? trim((string)$d['aff_label']) : 'Course';
    $borrowed = smartlib_reminder_date((string)($d['borrowed_date'] ?? ''), 'F j, Y');

    $lines = [
        strtoupper($copy['title']),
        SMARTLIB_REMINDER_LIBRARY . ' - ' . date('F j, Y'),
        '',
        'Hello, ' . smartlib_reminder_value($d, 'name'),
        $copy['lead'],
        '',
        $s['text'],
        '',
        'BOOK INFORMATION',
        'Title: ' . smartlib_reminder_value($d, 'title'),
        'Author: ' . smartlib_reminder_value($d, 'author'),
        'Accession No.: ' . smartlib_reminder_value($d, 'accession'),
        'ISBN: ' . smartlib_reminder_value($d, 'isbn'),
        '',
        'BORROWER INFORMATION',
        'ID Number: ' . smartlib_reminder_value($d, 'id'),
        $affLabel . ': ' . smartlib_reminder_value($d, 'aff'),
        '',
        'LOAN DETAILS',
        'Borrowed: ' . ($borrowed !== '' ? $borrowed : 'N/A'),
        '',
    ];
    if ($copy['renew']) {
        $lines[] = "Need more time? Renew once at the kiosk: sign in, open My Books, then tap Renew. Overdue or fined books can't be renewed.";
        $lines[] = '';
    }
    $lines[] = $copy['closing'];

    return implode("\n", $lines);
}
