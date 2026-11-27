(function () {
    var STORAGE_KEY = 'smartlib-admin-theme';
    var root = document.documentElement;
    var toggle = document.getElementById('adminThemeToggle');

    if (!toggle) {
        return;
    }

    var stateNode = toggle.querySelector('.theme-toggle-state');

    function resolveTheme(themeValue) {
        if (themeValue === 'light' || themeValue === 'dark') {
            return themeValue;
        }

        var rootTheme = root.getAttribute('data-theme');
        if (rootTheme === 'light' || rootTheme === 'dark') {
            return rootTheme;
        }

        // Same default as the inline script in admin/layout_top.php (light
        // since Prompt 39).
        return 'light';
    }

    function setTheme(theme, shouldPersist) {
        var nextTheme = resolveTheme(theme);
        var targetModeLabel = nextTheme === 'dark' ? 'light' : 'dark';

        root.setAttribute('data-theme', nextTheme);
        toggle.setAttribute('data-theme', nextTheme);
        toggle.setAttribute('aria-pressed', nextTheme === 'light' ? 'true' : 'false');
        toggle.setAttribute('aria-label', 'Switch to ' + targetModeLabel + ' mode');
        toggle.setAttribute('title', 'Switch to ' + targetModeLabel + ' mode');

        if (stateNode) {
            stateNode.textContent = nextTheme === 'light' ? 'Light mode' : 'Dark mode';
        }

        if (shouldPersist) {
            try {
                localStorage.setItem(STORAGE_KEY, nextTheme);
            } catch (error) {
                // Intentionally ignore write failures for private browsing/storage restrictions.
            }
        }

        document.dispatchEvent(new CustomEvent('admin-theme-changed', {
            detail: { theme: nextTheme }
        }));
    }

    var savedTheme = null;
    try {
        savedTheme = localStorage.getItem(STORAGE_KEY);
    } catch (error) {
        savedTheme = null;
    }

    setTheme(savedTheme, false);

    toggle.addEventListener('click', function () {
        var current = resolveTheme(root.getAttribute('data-theme'));
        setTheme(current === 'dark' ? 'light' : 'dark', true);
    });
}());

// Phone and tablet menu (960px and below, Prompt 39): the sidebar is a
// slide-over drawer opened by the top bar's menu button (admin_mobile.css).
// It closes on the backdrop, Escape, any link inside it, and when the
// window grows past 960px. Opening moves focus to the first link; closing
// with the button, the backdrop or Escape returns focus to the button.
// While it is open, Tab stays inside it and the page behind does not
// scroll (the is-nav-open class on <html>).
(function () {
    var root = document.documentElement;
    var button = document.getElementById('adminMenuButton');
    var drawer = document.getElementById('adminSidebar');
    var backdrop = document.getElementById('adminNavBackdrop');
    if (!button || !drawer || !window.matchMedia) {
        return;
    }

    var narrow = window.matchMedia('(max-width: 960px)');
    var lockedScrollY = 0;

    // Scroll lock that also holds on iOS Safari (which ignores overflow:
    // hidden for touch scrolling): pin <body> where it is, restore on close.
    function lockScroll() {
        lockedScrollY = window.scrollY || window.pageYOffset || 0;
        var body = document.body;
        body.style.position = 'fixed';
        body.style.top = (-lockedScrollY) + 'px';
        body.style.left = '0';
        body.style.right = '0';
        body.style.width = '100%';
    }

    function unlockScroll() {
        var body = document.body;
        body.style.position = '';
        body.style.top = '';
        body.style.left = '';
        body.style.right = '';
        body.style.width = '';
        window.scrollTo(0, lockedScrollY);
    }

    function isOpen() {
        return root.classList.contains('is-nav-open');
    }

    function focusables() {
        return Array.prototype.filter.call(
            drawer.querySelectorAll('a[href], button:not([disabled])'),
            function (el) { return el.offsetParent !== null || el.getClientRects().length > 0; }
        );
    }

    function openMenu() {
        if (!narrow.matches || isOpen()) {
            return;
        }
        lockScroll();
        root.classList.add('is-nav-open');
        button.setAttribute('aria-expanded', 'true');
        button.setAttribute('aria-label', 'Close menu');
        var first = drawer.querySelector('.nav-link') || drawer;
        window.setTimeout(function () { first.focus(); }, 30);
    }

    function closeMenu(returnFocus) {
        if (!isOpen()) {
            return;
        }
        root.classList.remove('is-nav-open');
        unlockScroll();
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', 'Open menu');
        if (returnFocus) {
            button.focus();
        }
    }

    button.addEventListener('click', function () {
        if (isOpen()) {
            closeMenu(true);
        } else {
            openMenu();
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () { closeMenu(true); });
    }

    // Any link closes it (the Logout link then opens its confirmation,
    // which sits above everything).
    drawer.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            closeMenu(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (!isOpen()) {
            return;
        }
        if (event.key === 'Escape') {
            closeMenu(true);
            return;
        }
        if (event.key === 'Tab') {
            var items = focusables();
            if (!items.length) {
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    function onWidthChange() {
        if (!narrow.matches) {
            closeMenu(false);
        }
    }
    if (narrow.addEventListener) {
        narrow.addEventListener('change', onWidthChange);
    } else if (narrow.addListener) {
        narrow.addListener(onWidthChange);
    }
}());

// Tables that scroll sideways inside their box (phones and tablets, see
// admin_mobile.css): mark them so the hidden edge fades out. Only classes
// change; nothing happens above 960px because the CSS ignores them there.
(function () {
    var wraps = document.querySelectorAll('.main-content .table-wrap');
    if (!wraps.length) {
        return;
    }

    function update(wrap) {
        var scrollable = wrap.scrollWidth > wrap.clientWidth + 2;
        wrap.classList.toggle('is-scrollable', scrollable);
        wrap.classList.toggle('is-scrolled', scrollable && wrap.scrollLeft > 2);
        wrap.classList.toggle('is-at-end', !scrollable || wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 2);
    }

    function updateAll() {
        Array.prototype.forEach.call(wraps, update);
    }

    Array.prototype.forEach.call(wraps, function (wrap) {
        wrap.addEventListener('scroll', function () { update(wrap); }, { passive: true });
    });
    window.addEventListener('resize', updateAll);
    window.addEventListener('load', updateAll);
    updateAll();
}());
