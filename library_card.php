<?php
require_once __DIR__ . '/config/request_input.php'; // smartlib_request_string(): arrays in scalar params count as empty
session_start();
require 'config/db_connect.php';
require 'config/barcode_code128.php';
require 'config/library_card_helpers.php';
require 'config/auth_security.php';
require 'config/csrf.php';
require_once 'config/auth_email_template.php';

// "Done" exits the card view and resets the kiosk for the next person --
// without this, one person's name and barcode could sit on screen
// indefinitely on a shared walk-up kiosk. Handled before anything else
// touches output.
if (isset($_GET['done'])) {
    unset($_SESSION['library_card_user_id']);
    header('Location: library_card_signup.php');
    exit;
}

$userId = (int)($_SESSION['library_card_user_id'] ?? 0);
$holder = $userId > 0 ? library_card_get_card_holder($conn, $userId) : null;
$barcodeSvg = $holder ? code128_svg((string)$holder['user_number']) : null;

// "Send to email" -- the holder already proved their inbox during
// signup/reprint, so this re-sends the same card as an image for backup/
// reference (it still can't be scanned from a phone screen -- the SCANA
// scanner is a laser scanner, see library_card_reprint.php's history in
// claude/november-launch-readiness.md). The browser rasterizes the real
// .library-card DOM node to a PNG client-side (no server-side GD/Imagick
// available on this install) and posts it here. AJAX, JSON response.
$csrfKey = 'library_card_email_csrf';
$csrfToken = smartlib_csrf_token($csrfKey);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && smartlib_request_string($_POST, 'action') === 'email_card') {
    header('Content-Type: application/json');

    if (!$holder || $barcodeSvg === null) {
        echo json_encode(['ok' => false, 'message' => 'No card to send right now.']);
        exit;
    }
    if (!smartlib_csrf_verify($csrfKey, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Your session expired. Please reload the page and try again.']);
        exit;
    }

    $cooldownUntil = (int)($_SESSION['library_card_email_cooldown_until'] ?? 0);
    if (time() < $cooldownUntil) {
        echo json_encode(['ok' => false, 'message' => 'Please wait a few seconds before sending again.']);
        exit;
    }

    $dataUrl = smartlib_request_string($_POST, 'image');
    if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $m)) {
        echo json_encode(['ok' => false, 'message' => 'Could not read the card image. Please try again.']);
        exit;
    }
    $bytes = base64_decode($m[1], true);
    // A real rendered card is tens of KB at minimum; reject anything
    // implausibly small rather than trust the client blindly.
    if ($bytes === false || strlen($bytes) < 2000) {
        echo json_encode(['ok' => false, 'message' => 'Could not read the card image. Please try again.']);
        exit;
    }

    if (!is_dir(__DIR__ . '/tmp')) {
        mkdir(__DIR__ . '/tmp', 0777, true);
    }
    $tmpPath = __DIR__ . '/tmp/card_email_' . bin2hex(random_bytes(8)) . '.png';
    if (file_put_contents($tmpPath, $bytes) === false) {
        echo json_encode(['ok' => false, 'message' => 'Could not prepare the email right now. Please try again.']);
        exit;
    }

    $firstName = (string)$holder['first_name'];
    $cardCid = 'smartlib_card_image';
    $subject = 'YOUR SMARTLIB LIBRARY CARD';
    $emailOptions = [
        'variant' => 'card',
        'preheader' => 'Your SmartLib library card is inside.',
        'eyebrow' => 'LIBRARY CARD',
        'title' => 'Your library card',
        // RAW first name: the template escapes it.
        'intro' => 'Hi ' . ($firstName !== '' ? $firstName : 'there') . ', here is your SmartLib library card.',
        'image_cid' => $cardCid,
        'image_alt' => 'Library card',
        'tip_label' => 'GOOD TO KNOW',
        'tip' => 'The door scanner reads printed cards, not phone screens. Keep this for your records, and print your card at the library if you have not already.',
        'footer' => 'You received this because you chose “Send to email” on your SmartLib card page.',
    ];
    $html = smartlib_auth_email_html($emailOptions);
    $text = smartlib_auth_email_text($emailOptions);

    $sent = auth_send_multipart_email(
        (string)($holder['email'] ?? ''),
        $subject,
        $html,
        $text,
        [['cid' => $cardCid, 'path' => $tmpPath, 'mime' => 'image/png']]
    );

    @unlink($tmpPath);

    if ($sent) {
        $_SESSION['library_card_email_cooldown_until'] = time() + 20;
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'message' => 'Could not send the email right now. Please try again in a moment.']);
    }
    exit;
}

$cssVer = @filemtime(__DIR__ . '/assets/css/library_card.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib | Library Card</title>
    <link rel="icon" href="assets/images/stjude_seal_64.png" type="image/png">
    <link rel="stylesheet" href="assets/css/library_card.css?v=<?= $cssVer ?>">
</head>
<body>
<?php if (!$holder || $barcodeSvg === null): ?>
    <div class="panel">
        <div class="panel-brand">
            <img src="assets/images/stjude_logo.jpg" alt="School logo">
            <div>
                <strong>SMARTLIB</strong>
                <span>Digital Library Card</span>
            </div>
        </div>
        <h1>Library card</h1>
        <div class="msg error" role="alert">Your card is shown right after you verify your email. Sign up to get your card, or reprint one you already have.</div>
        <a class="btn" href="library_card_signup.php">Get your library card</a>
        <div class="panel-footer">
            <a class="link" href="library_card_reprint.php">Already have a card? <strong>Reprint my card</strong></a>
            <a class="link" href="index.php">Back to SmartLib</a>
        </div>
    </div>
<?php else: ?>
    <?php $fullName = trim((string)$holder['first_name'] . ' ' . (string)$holder['last_name']); ?>
    <div class="card-page">
        <div class="panel-brand no-print">
            <img src="assets/images/stjude_logo.jpg" alt="School logo">
            <div>
                <strong>SMARTLIB</strong>
                <span>Digital Library Card</span>
            </div>
        </div>
        <div class="card-title-row no-print">
            <h1>Your library card</h1>
            <span class="status-chip" role="status">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7"></path></svg>
                Email verified
            </span>
        </div>

        <div class="card-stage">
            <div class="library-card">
                <svg class="lc-guilloche" viewBox="0 0 85.6 54" preserveAspectRatio="none" aria-hidden="true">
                    <?php for ($r = 6; $r <= 110; $r += 1.4): ?>
                        <circle cx="96" cy="62" r="<?= $r ?>"></circle>
                    <?php endfor; ?>
                    <?php for ($r = 6; $r <= 60; $r += 1.4): ?>
                        <circle cx="-8" cy="-10" r="<?= $r ?>"></circle>
                    <?php endfor; ?>
                </svg>
                <div class="lc-head">
                    <img src="assets/images/stjude_logo.jpg" alt="">
                    <div class="lc-letterhead">
                        <div class="lc-school"><?= htmlspecialchars(LIBRARY_CARD_LIBRARY_NAME) ?></div>
                        <div class="lc-title">SmartLib<span class="lc-title-sep">&middot;</span>Library Card</div>
                    </div>
                </div>
                <div class="lc-rule"></div>
                <div class="lc-body">
                    <div class="lc-holder">
                        <div class="lc-label">Cardholder</div>
                        <?php
                        // No-JS fallback size: a long word (10+ letters) or a long name
                        // (over 22 characters) starts at the smaller 3.2mm. With JS,
                        // fitCardholderNames() below sizes the name to fit instead.
                        $nameWords = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                        $longestNameWord = $nameWords ? max(array_map('mb_strlen', $nameWords)) : 0;
                        $nameIsLong = $longestNameWord >= 10 || mb_strlen($fullName) > 22;
                        ?>
                        <div class="lc-name<?= $nameIsLong ? ' lc-name-long' : '' ?>"><?= htmlspecialchars($fullName) ?></div>
                        <span class="lc-role">
                            <?php if ((string)$holder['role'] === 'faculty'): ?>
                                <svg class="lc-role-icon-faculty" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path><path d="M3 13h18"></path></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 9.5 12 5l10 4.5-10 4.5L2 9.5Z"></path><path d="M6 11.5v4.5c0 1.4 2.7 3 6 3s6-1.6 6-3v-4.5"></path><path d="M22 9.5v5"></path></svg>
                            <?php endif; ?>
                            <span class="lc-role-text"><?= htmlspecialchars(ucfirst((string)$holder['role'])) ?></span>
                        </span>
                    </div>
                    <div class="lc-barcode-box">
                        <div class="lc-barcode">
                            <?= $barcodeSvg ?>
                            <div class="lc-id"><?= htmlspecialchars(library_card_display_id((string)$holder['user_number'])) ?></div>
                        </div>
                    </div>
                </div>
                <div class="lc-edge"></div>
            </div>
        </div>

        <div class="card-actions no-print">
            <div class="notice">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path></svg>
                <span>The door scanner can't read phone screens. <strong>Print this card at 100%&nbsp;scale</strong> so the barcode stays scannable, then cut along the border.</span>
            </div>
            <button type="button" class="btn" onclick="window.print()">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6"></path><rect x="3" y="9" width="18" height="8" rx="2"></rect><path d="M7 14h10v7H7z"></path></svg>
                Print my card
            </button>
            <div class="card-actions-row">
                <button type="button" class="btn btn-ghost" id="lc-download-btn">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg>
                    Download
                </button>
                <button type="button" class="btn btn-ghost" id="lc-email-btn">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path></svg>
                    Send to email
                </button>
            </div>
            <div id="lc-action-status" class="msg" role="status" hidden></div>
            <a class="btn btn-ghost" href="library_card.php?done=1">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7"></path></svg>
                Done
            </a>
        </div>
    </div>
    <script>
    // Cardholder name: never break inside a word and never hide part of it.
    // Picks the largest size from 4.3mm down to 2.4mm (0.1mm steps) at which
    // no word is cut and the name fits in 3 lines; at 2.4mm a 4th and then a
    // 5th line are allowed, and only if that fails too may a word break
    // (overflow-wrap: anywhere, clamped at the lines it takes). The card is sized in mm and scaled by CSS zoom, so the
    // result is the same at every screen width and in print. Names that fit
    // at 4.3mm keep the stylesheet's look (no inline size or line-height).
    // Safe to run repeatedly: it starts again from 4.3mm each time.
    function fitCardholderNames() {
        document.querySelectorAll('.library-card').forEach(function (card) {
            var el = card.querySelector('.lc-name');
            if (!el) return;
            var BASE = 4.3, FLOOR = 2.4, STEP = 0.1;
            function lineCount() {
                var r = document.createRange();
                r.selectNodeContents(el);
                var tops = [];
                Array.prototype.forEach.call(r.getClientRects(), function (q) {
                    var t = Math.round(q.top);
                    if (!tops.some(function (x) { return Math.abs(x - t) < 3; })) tops.push(t);
                });
                return tops.length || 1;
            }
            function fits(maxLines) {
                return el.scrollWidth <= el.clientWidth + 1 && lineCount() <= maxLines;
            }
            // The PHP no-JS fallback class (3.2mm) would come back as soon as
            // the inline size is cleared for a name that fits at 4.3mm.
            el.classList.remove('lc-name-long');
            el.style.lineHeight = '';
            el.style.webkitLineClamp = 'unset';
            el.style.overflowWrap = 'normal';
            el.style.maxHeight = 'none';
            var size = BASE;
            el.style.fontSize = size + 'mm';
            while (!fits(3) && size - STEP >= FLOOR - 0.001) {
                size = Math.round((size - STEP) * 10) / 10;
                el.style.fontSize = size + 'mm';
            }
            var lines = 3;
            if (!fits(3)) {
                lines = 4;
                if (!fits(4)) {
                    lines = 5;
                    if (!fits(5)) {
                        // A word wider than the card even at 2.4mm: break it,
                        // and clamp at however many lines that takes, so no
                        // part of the name is ever hidden.
                        el.style.overflowWrap = 'anywhere';
                        lines = Math.max(5, lineCount());
                    }
                }
            }
            el.style.webkitLineClamp = String(lines);
            el.style.maxHeight = '';
            el.style.fontSize = size < BASE ? size + 'mm' : '';
            el.style.lineHeight = size < BASE ? '1.12' : '';
            el.setAttribute('data-fit-size', size);
        });
    }
    fitCardholderNames();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitCardholderNames);
    (function () {
        var fitTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(fitTimer);
            fitTimer = setTimeout(fitCardholderNames, 150);
        });
        window.addEventListener('beforeprint', fitCardholderNames);
        window.addEventListener('afterprint', fitCardholderNames);
    })();

    (function () {
        var card = document.querySelector('.library-card');
        var downloadBtn = document.getElementById('lc-download-btn');
        var emailBtn = document.getElementById('lc-email-btn');
        var statusEl = document.getElementById('lc-action-status');
        var csrfToken = <?= json_encode($csrfToken) ?>;

        function showStatus(type, text) {
            if (!statusEl) { return; }
            statusEl.hidden = false;
            statusEl.className = 'msg ' + type;
            statusEl.textContent = text;
        }

        // Rasterizes the real .library-card DOM node by reading each piece's
        // *actual rendered* position (getBoundingClientRect) and computed
        // style, then redrawing it with the 2D canvas API -- background,
        // logo, text, role pill, and the barcode bars (copied straight from
        // the live barcode SVG's own rects). No third-party library, same as
        // everywhere else in this project.
        //
        // This replaces an earlier version that cloned the card into an SVG
        // <foreignObject> and rasterized that: Chrome taints any canvas
        // drawn from an SVG containing a foreignObject -- even same-origin,
        // even with no images in it at all -- so toDataURL() always threw
        // "Tainted canvases may not be exported." there. Plain canvas
        // drawing calls (fillRect/fillText/drawImage) never trigger that.
        function roundedRectPath(ctx, x, y, w, h, r) {
            var rr = Math.min(r, w / 2, h / 2);
            ctx.beginPath();
            ctx.moveTo(x + rr, y);
            ctx.arcTo(x + w, y, x + w, y + h, rr);
            ctx.arcTo(x + w, y + h, x, y + h, rr);
            ctx.arcTo(x, y + h, x, y, rr);
            ctx.arcTo(x, y, x + w, y, rr);
            ctx.closePath();
        }

        // Draws a small line-icon <svg> (viewBox-based, stroke-only -- the
        // role badge's graduation-cap/briefcase icon, same style as every
        // other icon in this project) into a destination rect in canvas
        // space. Path2D accepts SVG path syntax directly, so each child
        // <path>'s own "d" attribute is reused as-is rather than
        // hand-walking the path data.
        function drawSvgIcon(ctx, svgEl, destRect) {
            var vb = svgEl.viewBox && svgEl.viewBox.baseVal ? svgEl.viewBox.baseVal : { x: 0, y: 0, width: 24, height: 24 };
            var sx = destRect.w / vb.width;
            var sy = destRect.h / vb.height;
            var svgCs = getComputedStyle(svgEl);
            ctx.save();
            ctx.translate(destRect.x - vb.x * sx, destRect.y - vb.y * sy);
            ctx.scale(sx, sy);
            ctx.strokeStyle = svgCs.stroke && svgCs.stroke !== 'none' ? svgCs.stroke : '#ffd84d';
            ctx.lineWidth = parseFloat(svgCs.strokeWidth) || 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            var children = svgEl.children;
            for (var i = 0; i < children.length; i++) {
                var el = children[i];
                if (el.tagName === 'path') {
                    ctx.stroke(new Path2D(el.getAttribute('d')));
                } else if (el.tagName === 'rect') {
                    var rx = parseFloat(el.getAttribute('x')) || 0;
                    var ry = parseFloat(el.getAttribute('y')) || 0;
                    var rw = parseFloat(el.getAttribute('width')) || 0;
                    var rh = parseFloat(el.getAttribute('height')) || 0;
                    var rr = parseFloat(el.getAttribute('rx')) || 0;
                    ctx.beginPath();
                    if (ctx.roundRect) { ctx.roundRect(rx, ry, rw, rh, rr); } else { ctx.rect(rx, ry, rw, rh); }
                    ctx.stroke();
                }
            }
            ctx.restore();
        }

        function buildCardImage() {
            if (!card) {
                return Promise.reject(new Error('Card not found.'));
            }

            var computedZoom = parseFloat(getComputedStyle(card).zoom) || 1;
            var cardRect = card.getBoundingClientRect();
            var trueW = cardRect.width / computedZoom;
            var trueH = cardRect.height / computedZoom;
            var scale = 4; // output px per true (zoom:1) CSS px
            var outW = Math.max(1, Math.round(trueW * scale));
            var outH = Math.max(1, Math.round(trueH * scale));

            function toCanvasRect(rect) {
                return {
                    x: (rect.left - cardRect.left) / computedZoom * scale,
                    y: (rect.top - cardRect.top) / computedZoom * scale,
                    w: rect.width / computedZoom * scale,
                    h: rect.height / computedZoom * scale
                };
            }

            function drawTextLine(ctx, text, el, lineRect) {
                var cs = getComputedStyle(el);
                var c = toCanvasRect(lineRect);
                // cs.* values come from getComputedStyle, which already
                // reports the true (zoom:1) CSS px/number -- unlike a
                // getBoundingClientRect()-sourced rect, which IS zoomed and
                // needs the /computedZoom undo inside toCanvasRect(). Dividing
                // a computedStyle value by computedZoom again (as this used
                // to) shrank the font by an extra ~1.32x on top of the
                // correct size, so text was drawn visibly smaller than the
                // box it was measured into -- which is what left the role
                // pill's text short of its own (correctly-sized) border,
                // reading as "too much space after the text."
                var fontSizeTrue = parseFloat(cs.fontSize) * scale;
                ctx.font = (cs.fontWeight || '400') + ' ' + fontSizeTrue + 'px ' + (cs.fontFamily || 'Arial, Helvetica, sans-serif');
                ctx.fillStyle = cs.color;
                ctx.textBaseline = 'alphabetic';
                ctx.textAlign = 'left';
                try {
    var ls = parseFloat(cs.letterSpacing);
                    ctx.letterSpacing = (isNaN(ls) ? 0 : ls * scale) + 'px';
                } catch (e) { /* letterSpacing unsupported; ignore */ }
                // Vertically centers within the element's *actual measured*
                // box (lineRect's own height, already mapped into c.h)
                // rather than a line-height read from CSS. Some text
                // elements (.lc-role-text specifically, which carries its
                // own small padding-top as an optical-centering nudge for
                // caps-only text) render taller than their CSS line-height
                // alone would predict, which threw this calc off for
                // exactly that element -- the role badge text sat visibly
                // low in the pill. Using the real rect height fixes that
                // case and is a no-op for every plain single-line text
                // element already centering correctly (their rendered
                // height already equals their line-height with nothing
                // extra added).
                var baseline = c.y + (c.h - fontSizeTrue) / 2 + fontSizeTrue * 0.80;
                var out = cs.textTransform === 'uppercase' ? text.toUpperCase() : text;
                ctx.fillText(out, c.x, baseline);
            }

            // Draws an element's text exactly as the browser laid it out: each
            // character's own rect (a Range per character) says which line it
            // is on, a new line starts whenever the top moves by more than 3px,
            // and each line is drawn at its own rect. So the image matches the
            // card line for line, including a break after a hyphen.
            function drawWrappedText(ctx, el) {
                var lines = [];
                var cur = null;
                var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
                var node;
                var range = document.createRange();
                while ((node = walker.nextNode())) {
                    for (var i = 0; i < node.length; i++) {
                        range.setStart(node, i);
                        range.setEnd(node, i + 1);
                        var rects = range.getClientRects();
                        var q = null;
                        for (var r = 0; r < rects.length; r++) {
                            if (rects[r].width > 0) { q = rects[r]; break; }
                        }
                        if (!q) { q = rects.length ? rects[0] : null; }
                        if (!q) { continue; }
                        if (!cur || Math.abs(q.top - cur.top) > 3) {
                            cur = { text: '', top: q.top, left: q.left, right: q.right, bottom: q.bottom };
                            lines.push(cur);
                        }
                        cur.text += node.data.charAt(i);
                        if (q.width > 0) {
                            cur.left = Math.min(cur.left, q.left);
                            cur.right = Math.max(cur.right, q.right);
                        }
                        cur.top = Math.min(cur.top, q.top);
                        cur.bottom = Math.max(cur.bottom, q.bottom);
                    }
                }
                // Only lines the card actually shows: a line pushed below the
                // name box (clamped, which only the anywhere fallback can
                // leave) must not be drawn over the role pill.
                var boxBottom = el.getBoundingClientRect().bottom + 1;
                for (var k = 0; k < lines.length; k++) {
                    var text = lines[k].text.replace(/\s+$/, '');
                    if (!text || lines[k].bottom > boxBottom) { continue; }
                    drawTextLine(ctx, text, el, {
                        left: lines[k].left,
                        top: lines[k].top,
                        width: lines[k].right - lines[k].left,
                        height: lines[k].bottom - lines[k].top
                    });
                }
            }

            var canvas = document.createElement('canvas');
            canvas.width = outW;
            canvas.height = outH;
            var ctx = canvas.getContext('2d');

            var cardCs = getComputedStyle(card);
            var cardRadius = parseFloat(cardCs.borderRadius) * scale || 0;
            roundedRectPath(ctx, 0, 0, outW, outH, cardRadius);
            ctx.fillStyle = cardCs.backgroundColor;
            ctx.fill();
            ctx.save();
            roundedRectPath(ctx, 0, 0, outW, outH, cardRadius);
            ctx.clip();

            // Engraved-circle background (.lc-guilloche): decorative, but
            // its absence was exactly why the downloaded/emailed card read
            // as noticeably flatter than the real on-screen card. The SVG
            // fills the card edge-to-edge (viewBox "0 0 85.6 54", matching
            // the card's own mm dimensions) with preserveAspectRatio="none",
            // so its rendered box is the same as the card's own -- each
            // circle's viewBox-space cx/cy/r maps onto canvas space with
            // one scale factor, the same technique the barcode bars below
            // already use for their own viewBox mapping.
            var guillocheEl = card.querySelector('.lc-guilloche');
            if (guillocheEl) {
                var gCircles = guillocheEl.querySelectorAll('circle');
                if (gCircles.length) {
                    var gVb = guillocheEl.viewBox.baseVal;
                    var gRect = toCanvasRect(guillocheEl.getBoundingClientRect());
                    var gScale = (gRect.w / gVb.width + gRect.h / gVb.height) / 2;
                    var gCs = getComputedStyle(gCircles[0]);
                    ctx.strokeStyle = gCs.stroke && gCs.stroke !== 'none' ? gCs.stroke : '#145a39';
                    ctx.lineWidth = Math.max(0.4, (parseFloat(gCs.strokeWidth) || 0.3) * gScale);
                    for (var gi = 0; gi < gCircles.length; gi++) {
                        var gcx = gRect.x + parseFloat(gCircles[gi].getAttribute('cx')) * gScale;
                        var gcy = gRect.y + parseFloat(gCircles[gi].getAttribute('cy')) * gScale;
                        var gr = parseFloat(gCircles[gi].getAttribute('r')) * gScale;
                        if (gr <= 0) { continue; }
                        ctx.beginPath();
                        ctx.arc(gcx, gcy, gr, 0, Math.PI * 2);
                        ctx.stroke();
                    }
                }
            }

            var edgeEl = card.querySelector('.lc-edge');
            if (edgeEl) {
                var edgeCs = getComputedStyle(edgeEl);
                var edgeC = toCanvasRect(edgeEl.getBoundingClientRect());
                ctx.fillStyle = edgeCs.backgroundColor;
                ctx.fillRect(edgeC.x, edgeC.y, edgeC.w, edgeC.h);
            }

            var logoEl = card.querySelector('.lc-head img');
            var logoReady = Promise.resolve();
            if (logoEl) {
                var lc = toCanvasRect(logoEl.getBoundingClientRect());
                var drawLogo = function () {
                    ctx.save();
                    ctx.beginPath();
                    ctx.ellipse(lc.x + lc.w / 2, lc.y + lc.h / 2, lc.w / 2, lc.h / 2, 0, 0, Math.PI * 2);
                    ctx.clip();
                    ctx.drawImage(logoEl, lc.x, lc.y, lc.w, lc.h);
                    ctx.restore();
                };
                if (logoEl.complete && logoEl.naturalWidth > 0) {
                    drawLogo();
                } else {
                    logoReady = new Promise(function (resolve) {
                        logoEl.addEventListener('load', function () { drawLogo(); resolve(); }, { once: true });
                        logoEl.addEventListener('error', function () { resolve(); }, { once: true });
                    });
                }
            }

            return logoReady.then(function () {
                var schoolEl = card.querySelector('.lc-school');
                if (schoolEl) { drawTextLine(ctx, schoolEl.textContent, schoolEl, schoolEl.getBoundingClientRect()); }
                var titleEl = card.querySelector('.lc-title');
                if (titleEl) { drawTextLine(ctx, titleEl.textContent.replace(/·/g, ' · '), titleEl, titleEl.getBoundingClientRect()); }

                var ruleEl = card.querySelector('.lc-rule');
                if (ruleEl) {
                    var ruleCs = getComputedStyle(ruleEl);
                    var rc = toCanvasRect(ruleEl.getBoundingClientRect());
                    ctx.fillStyle = ruleCs.backgroundColor;
                    ctx.fillRect(rc.x, rc.y, rc.w, rc.h);
                }

                var labelEl = card.querySelector('.lc-label');
                if (labelEl) { drawTextLine(ctx, labelEl.textContent, labelEl, labelEl.getBoundingClientRect()); }
                var nameEl = card.querySelector('.lc-name');
                if (nameEl) { drawWrappedText(ctx, nameEl); }

                var roleEl = card.querySelector('.lc-role');
                if (roleEl) {
                    var roleCs = getComputedStyle(roleEl);
                    var rrc = toCanvasRect(roleEl.getBoundingClientRect());
                    var pillR = rrc.h / 2;
                    roundedRectPath(ctx, rrc.x, rrc.y, rrc.w, rrc.h, pillR);
                    var borderW = parseFloat(roleCs.borderTopWidth) * scale || 1;
                    ctx.lineWidth = borderW;
                    ctx.strokeStyle = roleCs.borderTopColor;
                    ctx.stroke();
                    var roleIconEl = roleEl.querySelector('svg');
                    if (roleIconEl) { drawSvgIcon(ctx, roleIconEl, toCanvasRect(roleIconEl.getBoundingClientRect())); }
                    var roleTextEl = card.querySelector('.lc-role-text');
                    if (roleTextEl) { drawTextLine(ctx, roleTextEl.textContent, roleTextEl, roleTextEl.getBoundingClientRect()); }
                }

                var boxEl = card.querySelector('.lc-barcode-box');
                if (boxEl) {
                    var boxCs = getComputedStyle(boxEl);
                    var bc = toCanvasRect(boxEl.getBoundingClientRect());
                    var boxR = parseFloat(boxCs.borderRadius) * scale || 0;
                    roundedRectPath(ctx, bc.x, bc.y, bc.w, bc.h, boxR);
                    ctx.fillStyle = boxCs.backgroundColor;
                    ctx.fill();
                }

                // Barcode bars: map the live SVG's own viewBox-space rects
                // onto its real rendered screen box -- preserveAspectRatio
                // "none" already did that stretch for the on-screen SVG, so
                // this just mirrors the same mapping into canvas space.
                var barcodeSvg = card.querySelector('.lc-barcode svg');
                if (barcodeSvg) {
                    var vb = barcodeSvg.viewBox.baseVal;
                    var svgC = toCanvasRect(barcodeSvg.getBoundingClientRect());
                    var bars = barcodeSvg.querySelectorAll('g rect');
                    ctx.fillStyle = '#000000';
                    for (var bi = 0; bi < bars.length; bi++) {
                        var bx = parseFloat(bars[bi].getAttribute('x'));
                        var bw = parseFloat(bars[bi].getAttribute('width'));
                        var px = svgC.x + (bx / vb.width) * svgC.w;
                        var pw = (bw / vb.width) * svgC.w;
                        ctx.fillRect(px, svgC.y, pw, svgC.h);
                    }
                }

                var idEl = card.querySelector('.lc-id');
                if (idEl) { drawTextLine(ctx, idEl.textContent, idEl, idEl.getBoundingClientRect()); }

                ctx.restore();
                try {
                    return canvas.toDataURL('image/png');
                } catch (e) {
                    throw new Error('Could not render the card.');
                }
            });
        }

        if (downloadBtn) {
            downloadBtn.addEventListener('click', function () {
                downloadBtn.disabled = true;
                buildCardImage().then(function (dataUrl) {
                    var a = document.createElement('a');
                    a.href = dataUrl;
                    a.download = 'smartlib-library-card.png';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                }).catch(function () {
                    showStatus('error', "Could not download the card. Please try again, or use Print instead.");
                }).finally(function () {
                    downloadBtn.disabled = false;
                });
            });
        }

        if (emailBtn) {
            emailBtn.addEventListener('click', function () {
                emailBtn.disabled = true;
                buildCardImage().then(function (dataUrl) {
                    var body = new URLSearchParams();
                    body.set('action', 'email_card');
                    body.set('csrf_token', csrfToken);
                    body.set('image', dataUrl);
                    return fetch('library_card.php', { method: 'POST', body: body }).then(function (res) {
                        return res.json();
                    });
                }).then(function (json) {
                    if (json.ok) {
                        showStatus('success', 'Sent! Check your email for a copy of your card.');
                    } else {
                        showStatus('error', json.message || 'Could not send the email. Please try again.');
                    }
                }).catch(function () {
                    showStatus('error', 'Could not send the email. Please try again.');
                }).finally(function () {
                    emailBtn.disabled = false;
                });
            });
        }
    })();
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/config/request_guard.php'; ?><script src="assets/javascript/kiosk-idle.js?v=<?= @filemtime(__DIR__ . '/assets/javascript/kiosk-idle.js') ?: time() ?>" data-csrf="<?= htmlspecialchars(smartlib_kiosk_csrf_token()) ?>"></script>
</body>
</html>
