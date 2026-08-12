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

        return 'dark';
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
