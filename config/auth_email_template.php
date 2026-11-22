<?php
/**
 * Account emails (approved design "auth-emails-light-dark" and
 * "auth-emails-library-card-light-dark"): LIGHT by default, DARK when the
 * phone / mail app is in dark mode. Centered card, slim header (SMARTLIB
 * wordmark, no badge, no images), gold top bar, Georgia headline, gold
 * ornament.
 * Used by the PIN/password reset link (request_pin_reset.php), the staff
 * sign-in code (login_handler.php), the library-card sign-up / reprint code
 * (library_card_send_code_email()) and the "Your library card" email
 * (library_card.php, 'card' variant).
 *
 * Light vs dark: every inline style and bgcolor attribute carries the LIGHT
 * value; the dark values live only in the <style> block's
 * @media (prefers-color-scheme: dark), each with !important so it beats the
 * inline style and bgcolor. Apple Mail / iPhone Mail and Outlook follow the
 * phone's mode; the Gmail apps ignore prefers-color-scheme and always show
 * the light version (Android Gmail may darken it itself). This is expected.
 *
 * $o keys (all values RAW: the HTML builder escapes each exactly once, the
 * text builder uses them as they are):
 *   variant       'link' | 'code' | 'card' (anything else falls back to 'link')
 *   preheader     hidden inbox preview text
 *   eyebrow       small gold caps line above the title
 *   title         serif headline
 *   intro         paragraph under the ornament
 *   footer        line under the footer ornament
 *   link:  button_label, button_url, note (under the button),
 *          details [label => value] (shown side by side), fallback_label
 *   code:  code, expiry (chip text), security, sent_to
 *   card:  image_cid (inline image Content-ID, attached by the CALLER via
 *          auth_send_multipart_email()'s inline images), image_alt,
 *          tip_label, tip
 *
 * Email-safe like config/reminder_email_template.php: tables, inline styles,
 * bgcolor + background-color on coloured cells, Arial/Georgia only, no
 * flexbox/grid, no web fonts. The only image is the card picture in the
 * 'card' variant (cid:); the header has none.
 */

const SMARTLIB_AUTH_EMAIL_BRAND = 'SMARTLIB';
const SMARTLIB_AUTH_EMAIL_LIBRARY = 'PHINMA-SJCDC LIBRARY';

function smartlib_auth_email_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function smartlib_auth_email_str(array $o, string $key): string {
    return trim((string)($o[$key] ?? ''));
}

/**
 * Escaped text with a zero-width space after every "@", so Gmail does not
 * auto-link a masked address ("ki*****x@gmail.com") and paint it blue.
 * HTML only; the plain-text part keeps the address as it is.
 */
function smartlib_auth_email_e_nolink(string $value): string {
    return str_replace('@', '@&#8203;', smartlib_auth_email_e($value));
}

/** Dark-mode overrides: one rule per sl- class, all !important. */
function smartlib_auth_email_dark_css(): string {
    return ':root{color-scheme:light dark;supported-color-schemes:light dark;}
@media (prefers-color-scheme: dark){
.sl-page{background-color:#0b231a !important;}
.sl-card{background-color:#0f3a2b !important;border-color:#2f6a55 !important;}
.sl-band{background-color:#0c2f23 !important;border-bottom-color:#2f6a55 !important;}
.sl-brand{color:#ffffff !important;}
.sl-sub{color:#9fd3bd !important;}
.sl-eyebrow{color:#ffd84d !important;}
.sl-h1{color:#ffffff !important;}
.sl-body{color:#cfe6dc !important;}
.sl-note{color:#9fd3bd !important;}
.sl-orn-line{background-color:#2f6a55 !important;}
.sl-orn-dia{color:#ffd84d !important;}
.sl-orn-dia2{color:#5f9a82 !important;}
.sl-btn{background-color:#ffd84d !important;border-bottom-color:#c9a227 !important;}
.sl-btn-a{color:#2f2815 !important;}
.sl-panel{background-color:#17483a !important;border-color:#356f59 !important;}
.sl-divider{border-right-color:#356f59 !important;}
.sl-lab{color:#9fd3bd !important;}
.sl-val{color:#ffffff !important;}
.sl-link{color:#7fe0b5 !important;}
.sl-code-box{background-color:#17483a !important;border-color:#c9a227 !important;}
.sl-code{color:#ffd84d !important;}
.sl-chip{background-color:#1d6a50 !important;border-color:#3fcf8e !important;color:#cdf5e2 !important;}
.sl-foot{color:#8fbfaa !important;}
.sl-outer{color:#6fa38c !important;}
.sl-cardimg{border-color:#c9a227 !important;}
}';
}

/**
 * Two 1px lines with a diamond between them (a table, so it centres
 * everywhere). $diamondClass is sl-orn-dia (top) or sl-orn-dia2 (footer).
 */
function smartlib_auth_email_ornament(int $lineWidth, string $diamondClass, string $diamondColor, int $marginBottom): string {
    $line = '<td width="' . $lineWidth . '" valign="middle" style="width:' . $lineWidth . 'px;">'
        . '<div class="sl-orn-line" style="height:1px;line-height:1px;font-size:1px;background-color:#d9ece2;">&nbsp;</div></td>';
    return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto ' . $marginBottom . 'px;">'
        . '<tr>' . $line
        . '<td class="' . $diamondClass . '" valign="middle" style="padding:0 9px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:12px;color:' . $diamondColor . ';">&#9670;</td>'
        . $line . '</tr></table>';
}

function smartlib_auth_email_variant(array $o): string {
    $variant = (string)($o['variant'] ?? 'link');
    return in_array($variant, ['link', 'code', 'card'], true) ? $variant : 'link';
}

function smartlib_auth_email_html(array $o): string {
    $e = 'smartlib_auth_email_e';
    $variant = smartlib_auth_email_variant($o);
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
            . '<tr><td class="sl-btn" align="center" bgcolor="#0e8660" style="background-color:#0e8660;border-radius:14px;border-bottom:4px solid #0a6e4e;">'
            . '<a class="sl-btn-a" href="' . $e($url) . '" target="_blank" style="display:block;padding:16px 10px 14px;font-family:' . $sans . ';font-size:16px;font-weight:bold;letter-spacing:0.4px;line-height:20px;color:#ffffff;text-decoration:none;border-radius:14px;">'
            . $e($label) . '</a></td></tr></table>';
        if ($note !== '') {
            $block .= '<div class="sl-note" style="font-family:' . $sans . ';font-size:12.5px;line-height:18px;color:#4f7a6a;text-align:center;margin:0 0 22px;">' . $e($note) . '</div>';
        }

        $details = is_array($o['details'] ?? null) ? $o['details'] : [];
        if ($details) {
            $cells = [];
            $count = count($details);
            $i = 0;
            foreach ($details as $dLabel => $dValue) {
                $i++;
                $last = $i >= $count;
                $cells[] = '<td' . ($last ? '' : ' class="sl-divider"') . ' width="' . floor(100 / $count) . '%" align="center" valign="top" style="padding:14px 6px;' . ($last ? '' : 'border-right:1px solid #d9ece2;') . '">'
                    . '<div class="sl-lab" style="font-family:' . $sans . ';font-size:10px;font-weight:bold;letter-spacing:1.8px;line-height:14px;color:#46705f;text-transform:uppercase;">' . $e((string)$dLabel) . '</div>'
                    . '<div class="sl-val" style="font-family:' . $sans . ';font-size:17px;font-weight:bold;line-height:22px;color:#0b3025;margin-top:5px;word-break:break-word;">' . $e(trim((string)$dValue)) . '</div></td>';
            }
            $block .= '<table class="sl-panel" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f0f8f4" style="background-color:#f0f8f4;border:1px solid #d9ece2;border-radius:14px;border-collapse:separate;">'
                . '<tr>' . implode('', $cells) . '</tr></table>';
        }

        if ($fallback !== '' && $url !== '') {
            $block .= '<div class="sl-note" style="font-family:' . $sans . ';font-size:12px;line-height:19px;color:#4f7a6a;text-align:center;margin-top:22px;">' . $e($fallback) . '</div>'
                . '<div class="sl-link" style="font-family:' . $sans . ';font-size:11.5px;line-height:18px;color:#0a6e4e;text-align:center;margin-top:4px;word-break:break-all;">'
                . '<a class="sl-link" href="' . $e($url) . '" target="_blank" style="color:#0a6e4e;text-decoration:none;word-break:break-all;">' . $e($url) . '</a></div>';
        }
    } elseif ($variant === 'code') {
        $code = smartlib_auth_email_str($o, 'code');
        $expiry = smartlib_auth_email_str($o, 'expiry');
        $security = smartlib_auth_email_str($o, 'security');
        $sentTo = smartlib_auth_email_str($o, 'sent_to');

        // Arial digits (lining numerals); padding-left equals the
        // letter-spacing so the digits look centred.
        $block .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 16px;">'
            . '<tr><td class="sl-code-box" align="center" bgcolor="#eef8f3" style="background-color:#eef8f3;border:1px solid #c9a227;border-radius:16px;padding:20px 8px 18px;">'
            . '<div class="sl-code" style="font-family:' . $sans . ';font-size:42px;font-weight:bold;line-height:48px;letter-spacing:11px;padding-left:11px;color:#0a6e4e;word-break:break-all;">' . $e($code) . '</div>'
            . '</td></tr></table>';
        if ($expiry !== '') {
            $block .= '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">'
                . '<tr><td class="sl-chip" bgcolor="#e6f5ee" style="background-color:#e6f5ee;border:1px solid #bfe0d0;border-radius:999px;padding:6px 16px;font-family:' . $sans . ';font-size:12.5px;font-weight:bold;line-height:16px;color:#0a6e4e;">'
                . $e($expiry) . '</td></tr></table>';
        }
        if ($security !== '') {
            $block .= '<div class="sl-body" style="font-family:' . $sans . ';font-size:14px;line-height:22px;color:#3d5a4e;text-align:center;margin-top:18px;">' . $e($security) . '</div>';
        }
        if ($sentTo !== '') {
            $block .= '<div class="sl-note" style="font-family:' . $sans . ';font-size:12px;line-height:18px;color:#4f7a6a;text-align:center;margin-top:12px;">' . smartlib_auth_email_e_nolink($sentTo) . '</div>';
        }
    } else {
        // 'card': the card picture (inline cid image attached by the caller)
        // and a "good to know" panel.
        $cid = smartlib_auth_email_str($o, 'image_cid');
        $alt = smartlib_auth_email_str($o, 'image_alt');
        $tipLabel = smartlib_auth_email_str($o, 'tip_label');
        $tip = smartlib_auth_email_str($o, 'tip');

        if ($cid !== '') {
            $block .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                . '<tr><td align="center" style="padding:22px 0 20px;">'
                . '<img class="sl-cardimg" src="cid:' . $e($cid) . '" alt="' . $e($alt) . '" width="340" style="display:block;width:100%;max-width:340px;height:auto;margin:0 auto;border:1px solid #0c4129;border-radius:12px;">'
                . '</td></tr></table>';
        }
        if ($tipLabel !== '' || $tip !== '') {
            $panel = '';
            if ($tipLabel !== '') {
                $panel .= '<div class="sl-lab" style="font-family:' . $sans . ';font-size:10px;font-weight:bold;letter-spacing:1.8px;line-height:14px;color:#46705f;text-transform:uppercase;">' . $e($tipLabel) . '</div>';
            }
            if ($tip !== '') {
                $panel .= '<div class="sl-body" style="font-family:' . $sans . ';font-size:13.5px;line-height:22px;color:#3d5a4e;' . ($tipLabel !== '' ? 'margin-top:6px;' : '') . '">' . $e($tip) . '</div>';
            }
            $block .= '<table class="sl-panel" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f0f8f4" style="background-color:#f0f8f4;border:1px solid #d9ece2;border-radius:14px;border-collapse:separate;' . ($cid === '' ? 'margin-top:22px;' : '') . '">'
                . '<tr><td align="center" style="padding:14px 16px;text-align:center;">' . $panel . '</td></tr></table>';
        }
    }

    $preheaderHtml = $preheader !== ''
        ? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:#e9f4ee;">'
            . $e($preheader) . str_repeat('&nbsp;&zwnj;', 30) . '</div>'
        : '';

    $eyebrowHtml = $eyebrow !== ''
        ? '<div class="sl-eyebrow" style="font-family:' . $sans . ';font-size:11px;font-weight:bold;letter-spacing:2.4px;line-height:16px;color:#8a6200;text-align:center;text-transform:uppercase;">' . $e($eyebrow) . '</div>'
        : '';
    $footerHtml = $footer !== ''
        ? smartlib_auth_email_ornament(40, 'sl-orn-dia2', '#9fc7b5', 14)
            . '<div class="sl-foot" style="font-family:' . $sans . ';font-size:12.5px;line-height:20px;color:#587869;text-align:center;">' . $e($footer) . '</div>'
        : '';

    $brand = SMARTLIB_AUTH_EMAIL_BRAND;
    $libraryNb = str_replace('-', '&#8209;', $e(SMARTLIB_AUTH_EMAIL_LIBRARY));

    return '<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>' . $e($title) . '</title>
<style>
' . smartlib_auth_email_dark_css() . '
</style>
</head>
<body class="sl-page" style="margin:0;padding:0;background-color:#e9f4ee;" bgcolor="#e9f4ee">
' . $preheaderHtml . '
<table class="sl-page" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#e9f4ee" style="background-color:#e9f4ee;">
<tr><td align="center" style="padding:22px 14px 18px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;">
<tr><td>
<table class="sl-card" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="background-color:#ffffff;border:1px solid #d3e8de;border-radius:18px;border-collapse:separate;">
<tr><td height="4" bgcolor="#ffd84d" style="height:4px;line-height:4px;font-size:4px;background-color:#ffd84d;background-image:linear-gradient(90deg,#a97f12,#ffd84d 50%,#a97f12);border-radius:17px 17px 0 0;">&nbsp;</td></tr>
<tr><td class="sl-band" align="center" bgcolor="#ffffff" style="background-color:#ffffff;padding:22px 22px 18px;border-bottom:1px solid #e1efe8;text-align:center;">
<div class="sl-brand" style="font-family:' . $sans . ';font-size:17px;font-weight:bold;letter-spacing:4.5px;line-height:22px;color:#0b3025;">' . $e($brand) . '</div>
<div class="sl-sub" style="font-family:' . $sans . ';font-size:10px;font-weight:bold;letter-spacing:2.6px;line-height:15px;color:#4f7a6a;margin-top:6px;">' . $libraryNb . '</div>
</td></tr>
<tr><td style="padding:28px 22px 6px;word-break:break-word;overflow-wrap:anywhere;">
' . $eyebrowHtml . '
<div class="sl-h1" style="font-family:' . $serif . ';font-size:30px;font-weight:bold;line-height:36px;color:#0b3025;text-align:center;margin:10px 0 14px;">' . $e($title) . '</div>
' . smartlib_auth_email_ornament(70, 'sl-orn-dia', '#d4a62a', 18) . '
<div class="sl-body" style="font-family:' . $sans . ';font-size:15px;line-height:25px;color:#3d5a4e;text-align:center;">' . $e($intro) . '</div>
' . $block . '
</td></tr>
<tr><td style="padding:22px 22px 24px;word-break:break-word;overflow-wrap:anywhere;">
' . $footerHtml . '
</td></tr>
</table>
</td></tr>
<tr><td class="sl-outer" align="center" style="padding-top:16px;font-family:' . $sans . ';font-size:10px;font-weight:bold;letter-spacing:1.8px;line-height:14px;color:#557567;text-align:center;">' . $e($brand) . ' &middot; ' . $libraryNb . '</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
';
}

function smartlib_auth_email_text(array $o): string {
    $variant = smartlib_auth_email_variant($o);
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
    } elseif ($variant === 'code') {
        $lines[] = 'Your code: ' . smartlib_auth_email_str($o, 'code');
        foreach (['expiry', 'security', 'sent_to'] as $key) {
            $value = smartlib_auth_email_str($o, $key);
            if ($value !== '') {
                $lines[] = $value;
            }
        }
    } else {
        $lines[] = 'Your library card is attached to this email as an image.';
        $tip = smartlib_auth_email_str($o, 'tip');
        if ($tip !== '') {
            $lines[] = $tip;
        }
    }

    $footer = smartlib_auth_email_str($o, 'footer');
    if ($footer !== '') {
        $lines[] = '';
        $lines[] = $footer;
    }

    return implode("\n", $lines) . "\n";
}
