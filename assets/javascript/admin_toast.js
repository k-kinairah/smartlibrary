// Shared admin toast (Prompt 40). Picks up the toasts printed by
// admin/includes/admin_toast.php and offers window.smartlibToast(type,
// message, options) for messages made in the browser.
// - Moved to <body> into one fixed stack centred over .main-content (a
//   toast inside a backdrop-filter panel would be positioned against the
//   panel), recomputed on resize; full width under the top bar at 960px
//   and below (CSS).
// - Success 3.5 s, info 5 s, error 7 s. Hover or keyboard focus pauses,
//   the close button or Escape dismisses at once. Up to 3 at a time,
//   newest on top; an identical message already showing is not repeated.
// - Never takes focus. Styles: assets/css/admin_toast.css.
(function () {
    var DURATIONS = { success: 3500, info: 5000, error: 7000 };
    var MAX = 3;
    var LEAVE_MS = 160;
    var ICONS = {
        success: '<path d="m5 12.5 4.5 4.5L19 7.5"></path>',
        error: '<path d="M12 4 21 19H3L12 4Z"></path><path d="M12 10v4"></path><path d="M12 16.6v.4"></path>',
        info: '<circle cx="12" cy="12" r="8.5"></circle><path d="M12 11v5"></path><path d="M12 7.6v.4"></path>'
    };
    var stack = null;
    var narrow = window.matchMedia ? window.matchMedia('(max-width: 960px)') : null;

    function normaliseType(type) {
        type = String(type || '').toLowerCase();
        return DURATIONS.hasOwnProperty(type) ? type : 'info';
    }

    function position() {
        if (!stack) {
            return;
        }
        var main = document.querySelector('.main-content');
        if ((narrow && narrow.matches) || !main) {
            stack.style.left = '';
            stack.style.width = '';
            stack.classList.remove('is-centered');
            return;
        }
        var rect = main.getBoundingClientRect();
        var width = Math.min(520, window.innerWidth - 32);
        var left = rect.left + rect.width / 2 - width / 2;
        left = Math.max(16, Math.min(left, window.innerWidth - width - 16));
        stack.style.left = left + 'px';
        stack.style.width = width + 'px';
        stack.classList.add('is-centered');
    }

    function getStack() {
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'admin-toast-stack';
            document.body.appendChild(stack);
            position();
        }
        return stack;
    }

    function textOf(el) {
        var t = el.querySelector('.admin-toast-text');
        return t ? t.textContent.trim() : '';
    }

    function dismiss(el) {
        if (!el || el.classList.contains('is-leaving')) {
            return;
        }
        window.clearTimeout(el._toastTimer);
        el.classList.add('is-leaving');
        window.setTimeout(function () {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
            document.dispatchEvent(new CustomEvent('admin-toast-removed', { detail: { type: el.getAttribute('data-type'), text: textOf(el) } }));
        }, LEAVE_MS);
    }

    function startTimer(el) {
        window.clearTimeout(el._toastTimer);
        el._toastStarted = performance.now();
        el._toastTimer = window.setTimeout(function () { dismiss(el); }, Math.max(0, el._toastRemaining));
    }

    function pause(el) {
        if (el.classList.contains('is-paused') || el.classList.contains('is-leaving')) {
            return;
        }
        window.clearTimeout(el._toastTimer);
        el._toastRemaining -= performance.now() - el._toastStarted;
        el.classList.add('is-paused');
    }

    function resume(el) {
        if (!el.classList.contains('is-paused') || el.matches(':hover') || el.contains(document.activeElement)) {
            return;
        }
        el.classList.remove('is-paused');
        startTimer(el);
    }

    function manage(el, options) {
        var type = normaliseType(el.getAttribute('data-type'));
        var box = getStack();
        var text = textOf(el);
        // an identical message already showing is not repeated
        var live = box.querySelectorAll('.admin-toast:not(.is-leaving)');
        for (var i = 0; i < live.length; i++) {
            if (live[i] !== el && live[i].getAttribute('data-type') === type && textOf(live[i]) === text) {
                if (el.parentNode) {
                    el.parentNode.removeChild(el);
                }
                return live[i];
            }
        }
        var duration = (options && options.duration) || DURATIONS[type];
        el.classList.add('is-managed');
        el.style.setProperty('--admin-toast-duration', duration + 'ms');
        box.insertBefore(el, box.firstChild);
        el._toastRemaining = duration;
        el.setAttribute('data-shown-at', String(performance.now()));

        var close = el.querySelector('.admin-toast-close');
        if (close) {
            close.addEventListener('click', function () { dismiss(el); });
        }
        el.addEventListener('mouseenter', function () { pause(el); });
        el.addEventListener('mouseleave', function () { resume(el); });
        el.addEventListener('focusin', function () { pause(el); });
        el.addEventListener('focusout', function () { window.setTimeout(function () { resume(el); }, 0); });

        // at most MAX at once: the oldest goes first
        var all = box.querySelectorAll('.admin-toast:not(.is-leaving)');
        for (var j = MAX; j < all.length; j++) {
            dismiss(all[j]);
        }
        startTimer(el);
        return el;
    }

    function build(type, message) {
        type = normaliseType(type);
        var el = document.createElement('div');
        el.className = 'admin-toast admin-toast--' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.setAttribute('data-admin-toast', '');
        el.setAttribute('data-type', type);
        el.innerHTML = '<span class="admin-toast-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">' + ICONS[type] + '</svg></span>'
            + '<span class="admin-toast-text"></span>'
            + '<button type="button" class="admin-toast-close" aria-label="Dismiss">&times;</button>'
            + '<span class="admin-toast-progress" aria-hidden="true"></span>';
        el.querySelector('.admin-toast-text').textContent = String(message || '');
        return el;
    }

    window.smartlibToast = function (type, message, options) {
        if (!message) {
            return null;
        }
        return manage(build(type, message), options || {});
    };

    function adoptPrinted() {
        var printed = document.querySelectorAll('[data-admin-toast]:not(.is-managed)');
        for (var i = 0; i < printed.length; i++) {
            manage(printed[i], {});
        }
    }

    // Escape dismisses the newest toast, unless a window is open and the
    // focus is not on a toast (then Escape belongs to that window).
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !stack) {
            return;
        }
        var newest = stack.querySelector('.admin-toast:not(.is-leaving)');
        if (!newest) {
            return;
        }
        var inToast = document.activeElement && document.activeElement.closest && document.activeElement.closest('.admin-toast');
        var modalOpen = document.querySelector('.overlay-modal.show');
        if (modalOpen && !inToast) {
            return;
        }
        dismiss(inToast || newest);
    });

    window.addEventListener('resize', position);
    if (narrow) {
        if (narrow.addEventListener) {
            narrow.addEventListener('change', position);
        } else if (narrow.addListener) {
            narrow.addListener(position);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', adoptPrinted);
    } else {
        adoptPrinted();
    }
}());
