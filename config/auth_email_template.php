<?php
/**
 * Account emails (approved design "auth-emails-centered-premium"): dark green
 * card, gold top bar, centered "SL" badge, serif headline, gold ornament.
 * Used by the PIN/password reset link (request_pin_reset.php), the staff
 * sign-in code (login_handler.php) and the library-card sign-up / reprint
 * code (library_card_send_code_email()).
 *
 * $o keys (all values RAW: the HTML builder escapes each exactly once, the
 * text builder uses them as they are):
 *   variant       'link' (button + details + fallback link) | 'code' (big code + expiry chip)
 *   preheader     hidden inbox preview text
 *   eyebrow       small gold caps line above the title
 *   title         serif headline
 *   intro         paragraph under the ornament
 *   footer        line under the footer ornament
 *   link:  button_label, button_url, note (under the button),
 *          details [label => value] (shown side by side), fallback_label
 *   code:  code, expiry (chip text), security, sent_to
 *
 * Email-safe like config/reminder_email_template.php: tables, inline styles,
 * bgcolor + background-color on coloured cells, Arial/Georgia only, no
 * flexbox/grid, no web fonts and no images (the "SL" badge stands in for the
 * logo, so nothing is attached and each message stays small).
 */

const SMARTLIB_AUTH_EMAIL_BRAND = 'SMARTLIB';
const SMARTLIB_AUTH_EMAIL_LIBRARY = 'PHINMA-SJCDC LIBRARY';

function smartlib_auth_email_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function smartlib_auth_email_str(array $o, string $key): string {
    return trim((string)($o[$key] ?? ''));
}

/** Two 1px lines with a diamond between them (a table, so it centres everywhere). */
function smartlib_auth_email_ornament(int $lineWidth, string $diamondColor, int $marginBottom): string {
    $line = '<td width="' . $lineWidth . '" valign="middle" style="width:' . $lineWidth . 'px;">'
        . '<div style="height:1px;line-height:1px;font-size:1px;background-color:#2f6a55;">&nbsp;</div></td>';
    return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto ' . $marginBottom . 'px;">'
        . '<tr>' . $line
        . '<td valign="middle" style="padding:0 9px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:12px;color:' . $diamondColor . ';">&#9670;</td>'
        . $line . '</tr></table>';
}

function smartlib_auth_email_html(array $o): string {
    $e = 'smartlib_auth_email_e';
    $variant = ($o['variant'] ?? 'link') === 'code' ? 'code' : 'link';
    $sans = 'Arial,Helvetica,sans-serif';
    $serif = "Georgia,'Times New Roman',serif";

    $title = smartlib_auth_email_str($o, 'title');
    $preheader = smartlib_auth_email_str($o, 'preheader');
    $eyebrow = smartlib_auth_email_str($o, 'eyebrow');
    $intro = smartlib_auth_email_str($o, 'intro');
    $footer = smartlib_auth_email_str($o, 'footer');

    // ---- body block for the variant ----
    $block = '';
    if ($variant === 'link') {
        $url = smartlib_auth_email_str($o, 'button_url');
        $label = smartlib_auth_email_str($o, 'button_label');
        $note = smartlib_auth_email_str($o, 'note');
        $fallback = smartlib_auth_email_str($o, 'fallback_label');

        // Bulletproof button: the coloured cell is the button, the link fills it.
        $block .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 10px;">'
            . '<tr><td align="center" bgcolor="#ffd84d" style="background-color:#ffd84d;border-radius:14px;border-bottom:4px solid #c9a227;">'
            . '<a href="' . $e($url) . '" target="_blank" style="display:block;padding:16px 10px 14px;font-family:' . $sans . ';font-size:16px;font-weight:bold;letter-spacing:0.4px;line-height:20px;color:#2f2815;text-decoration:none;border-radius:14px;">'
            . $e($label) . '</a></td></tr></table>';
        if ($note !== '') {
            $block .= '<div style="font-family:' . $sans . ';font-size:12.5px;line-height:18px;color:#9fd3bd;text-align:center;margin:0 0 22px;">' . $e($note) . '</div>';
        }

        $details = is_array($o['details'] ?? null) ? $o['details'] : [];
        if ($details) {
            $cells = [];
            $count = count($details);
            $i = 0;
            foreach ($details as $dLabel => $dValue) {
                $i++;
                $divider = $i < $count ? 'border-right:1px solid #356f59;' : '';
                $cells[] = '<td width="' . floor(100 / $count) . '%" align="center" valign="top" style="padding:14px 6px;' . $divider . '">'
                    . '<div style="font-family:' . $sans . ';font-size:10px;font-weight:bold;letter-spacing:1.8px;line-height:14px;color:#9fd3bd;text-transform:uppercase;">' . $e((string)$dLabel) . '</div>'
                    . '<div style="font-family:' . $sans . ';font-size:17px;font-weight:bold;line-height:22px;color:#ffffff;margin-top:5px;word-break:break-word;">' . $e(trim((string)$dValue)) . '</div></td>';
            }
            $block .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#17483a" style="background-color:#17483a;border:1px solid #356f59;border-radius:14px;border-collapse:separate;">'
                . '<tr>' . implode('', $cells) . '</tr></table>';
        }

        if ($fallback !== '' && $url !== '') {
            $block .= '<div style="font-family:' . $sans . ';font-size:12px;line-height:19px;color:#9fd3bd;text-align:center;margin-top:22px;">' . $e($fallback) . '</div>'
                . '<div style="font-family:' . $sans . ';font-size:11.5px;line-height:18px;color:#7fe0b5;text-align:center;margin-top:4px;word-break:break-all;">'
                . '<a href="' . $e($url) . '" target="_blank" style="color:#7fe0b5;text-decoration:none;word-break:break-all;">' . $e($url) . '</a></div>';
        }
    } else {
        $code = smartlib_auth_email_str($o, 'code');
        $expiry = smartlib_auth_email_str($o, 'expiry');
        $security = smartlib_auth_email_str($o, 'security');
        $sentTo = smartlib_auth_email_str($o, 'sent_to');

        // padding-left equals the letter-spacing so the digits look centred.
        $block .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 16px;">'
            . '<tr><td align="center" bgcolor="#17483a" style="background-color:#17483a;border:1px solid #c9a227;border-radius:16px;padding:22px 8px 20px;">'
            . '<div style="font-family:' . $serif . ';font-size:40px;font-weight:bold;line-height:46px;letter-spacing:12px;padding-left:12px;color:#ffd84d;word-break:break-all;font-variant-numeric:lining-nums;font-feature-settings:&quot;lnum&quot; 1;">' . $e($code) . '</div>'
            . '</td></tr></table>';
        if ($expiry !== '') {
            $block .= '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">'
                . '<tr><td bgcolor="#1d6a50" style="background-color:#1d6a50;border:1px solid #3fcf8e;border-radius:999px;padding:6px 16px;font-family:' . $sans . ';font-size:12.5px;font-weight:bold;line-height:16px;color:#cdf5e2;">'
                . $e($expiry) . '</td></tr></table>';
        }
        if ($security !== '') {
            $block .= '<div style="font-family:' . $sans . ';font-size:14px;line-height:22px;color:#cfe6dc;text-align:center;margin-top:18px;">' . $e($security) . '</div>';
        }
        if ($sentTo !== '') {
            $block .= '<div style="font-family:' . $sans . ';font-size:12px;line-height:18px;color:#9fd3bd;text-align:center;margin-top:12px;">' . $e($sentTo) . '</div>';
        }
    }

    $preheaderHtml = $preheader !== ''
        ? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:#0b231a;">'
            . $e($preheader) . str_repeat('&nbsp;&zwnj;', 30) . '</div>'
        : '';

    $badge = '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 14px;">'
        // Outer cell = the soft gold halo (12% gold over the header green).
        . '<tr><td align="center" valign="middle" bgcolor="#294328" style="background-color:#294328;border-radius:50%;padding:5px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td width="60" height="60" align="center" valign="middle" bgcolor="#0f3a2b" style="width:60px;height:60px;background-color:#0f3a2b;border:2px solid #ffd84d;border-radius:50%;font-family:' . $serif . ';font-size:20px;font-weight:bold;letter-spacing:1px;line-height:60px;color:#ffd84d;text-align:center;">SL</td>'
        . '</tr></table></td></tr></table>';

    $eyebrowHtml = $eyebrow !== ''
        ? '<div style="font-family:' . $sans . ';font-size:11px;font-weight:bold;letter-spacing:2.4px;line-height:16px;color:#ffd84d;text-align:center;text-transform:uppercase;">' . $e($eyebrow) . '</div>'
        : '';
    $footerHtml = $footer !== ''
        ? smartlib_auth_email_ornament(40, '#5f9a82', 14)
            . '<div style="font-family:' . $sans . ';font-size:12.5px;line-height:20px;color:#8fbfaa;text-align:center;">' . $e($footer) . '</div>'
        : '';

    $brand = SMARTLIB_AUTH_EMAIL_BRAND;
    $library = SMARTLIB_AUTH_EMAIL_LIBRARY;
    $libraryNb = str_replace('-', '&#8209;', $e($library));

    return '<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>' . $e($title) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#0b231a;" bgcolor="#0b231a">
' . $preheaderHtml . '
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0b231a" style="background-color:#0b231a;">
<tr><td align="center" style="padding:22px 14px 18px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;">
<tr><td>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0f3a2b" style="background-color:#0f3a2b;border:1px solid #2f6a55;border-radius:18px;border-collapse:separate;">
<tr><td height="4" bgcolor="#ffd84d" style="height:4px;line-height:4px;font-size:4px;background-color:#ffd84d;background-image:linear-gradient(90deg,#a97f12,#ffd84d 50%,#a97f12);border-radius:17px 17px 0 0;">&nbsp;</td></tr>
<tr><td align="center" bgcolor="#0c2f23" style="background-color:#0c2f23;padding:28px 22px 22px;border-bottom:1px solid #2f6a55;text-align:center;">
' . $badge . '
<div style="font-family:' . $sans . ';font-size:17px;font-weight:bold;letter-spacing:4px;line-height:22px;color:#ffffff;">' . $e($brand) . '</div>
<div style="font-family:' . $sans . ';font-size:10.5px;font-weight:bold;letter-spacing:2.6px;line-height:15px;color:#9fd3bd;margin-top:6px;">' . $libraryNb . '</div>
</td></tr>
<tr><td style="padding:28px 22px 6px;word-break:break-word;overflow-wrap:anywhere;">
' . $eyebrowHtml . '
<div style="font-family:' . $serif . ';font-size:30px;font-weight:bold;line-height:36px;color:#ffffff;text-align:center;margin:10px 0 14px;">' . $e($title) . '</div>
' . smartlib_auth_email_ornament(70, '#ffd84d', 18) . '
<div style="font-family:' . $sans . ';font-size:15px;line-height:25px;color:#cfe6dc;text-align:center;">' . $e($intro) . '</div>
' . $block . '
</td></tr>
<tr><td style="padding:22px 22px 24px;word-break:break-word;overflow-wrap:anywhere;">
' . $footerHtml . '
</td></tr>
</table>
</td></tr>
<tr><td align="center" style="padding-top:16px;font-family:' . $sans . ';font-size:10px;font-weight:bold;letter-spacing:1.8px;line-height:14px;color:#6fa38c;text-align:center;">' . $e($brand) . ' &middot; ' . $libraryNb . '</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
';
}

function smartlib_auth_email_text(array $o): string {
    $variant = ($o['variant'] ?? 'link') === 'code' ? 'code' : 'link';
    $lines = [SMARTLIB_AUTH_EMAIL_BRAND . ' - ' . SMARTLIB_AUTH_EMAIL_LIBRARY, ''];

    $eyebrow = smartlib_auth_email_str($o, 'eyebrow');
    if ($eyebrow !== '') {
        $lines[] = strtoupper($eyebrow);
    }
    $lines[] = smartlib_auth_email_str($o, 'title');
    $lines[] = '';
    $lines[] = smartlib_auth_email_str($o, 'intro');
    $lines[] = '';

    if ($variant === 'link') {
        $label = smartlib_auth_email_str($o, 'button_label');
        $url = smartlib_auth_email_str($o, 'button_url');
        $lines[] = ($label !== '' ? $label . ': ' : '') . $url;
        $note = smartlib_auth_email_str($o, 'note');
        if ($note !== '') {
            $lines[] = $note;
        }
        $details = is_array($o['details'] ?? null) ? $o['details'] : [];
        if ($details) {
            $lines[] = '';
            foreach ($details as $dLabel => $dValue) {
                $lines[] = ucfirst(strtolower((string)$dLabel)) . ': ' . trim((string)$dValue);
            }
        }
    } else {
        $lines[] = 'Your code: ' . smartlib_auth_email_str($o, 'code');
        foreach (['expiry', 'security', 'sent_to'] as $key) {
            $value = smartlib_auth_email_str($o, $key);
            if ($value !== '') {
                $lines[] = $value;
            }
        }
    }

    $footer = smartlib_auth_email_str($o, 'footer');
    if ($footer !== '') {
        $lines[] = '';
        $lines[] = $footer;
    }

    return implode("\n", $lines) . "\n";
}
