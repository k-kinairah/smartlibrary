<?php
/**
 * Shared admin toast (Prompt 40): one quick pop-up for every admin
 * notification. The PHP side prints the element; assets/javascript/
 * admin_toast.js picks it up (moves it to <body>, positions it over the
 * main column, times it out) and offers window.smartlibToast(type,
 * message, options) for messages made in the browser. Without JavaScript
 * the printed element stays visible as a fixed banner
 * (assets/css/admin_toast.css), so the message is never lost.
 */

if (!function_exists('smartlib_admin_toast_type')) {
    /** success | error | info; anything else is info. */
    function smartlib_admin_toast_type(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['success', 'error', 'info'], true) ? $type : 'info';
    }

    /** Prints one toast. Errors are role="alert", the rest role="status". */
    function smartlib_admin_toast_render(string $type, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }
        $type = smartlib_admin_toast_type($type);
        $icons = [
            'success' => '<path d="m5 12.5 4.5 4.5L19 7.5"></path>',
            'error' => '<path d="M12 4 21 19H3L12 4Z"></path><path d="M12 10v4"></path><path d="M12 16.6v.4"></path>',
            'info' => '<circle cx="12" cy="12" r="8.5"></circle><path d="M12 11v5"></path><path d="M12 7.6v.4"></path>',
        ];
        echo '<div class="admin-toast admin-toast--' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '" data-admin-toast data-type="' . $type . '">'
            . '<span class="admin-toast-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">' . $icons[$type] . '</svg></span>'
            . '<span class="admin-toast-text">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<button type="button" class="admin-toast-close" aria-label="Dismiss">&times;</button>'
            . '<span class="admin-toast-progress" aria-hidden="true"></span>'
            . '</div>';
    }
}
