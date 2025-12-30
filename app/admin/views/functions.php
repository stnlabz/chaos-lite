<?php
// ...your existing helpers...

if (!function_exists('admin_logout')) {
    /**
     * Kill the admin session + cookies and redirect to login.
     * Safe to call multiple times.
     */
    function admin_logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Unset all app-level auth flags
        unset($_SESSION['admin_user'], $_SESSION['admin_email'], $_SESSION['admin_role'], $_SESSION['is_admin']);

        // CSRF/session hardening: regenerate then destroy
        @session_regenerate_id(true);

        // Clear PHP's session cookie
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => !empty($params['secure']),
                'httponly' => !empty($params['httponly']),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );

        // If you set any "remember me" or custom cookies, clear them here too:
        // setcookie('remember_admin', '', time()-42000, '/', '', true, true);

        // Destroy session storage
        @session_unset();
        @session_destroy();

        // Final redirect to login
        header('Location: /admin?action=login', true, 302);
        exit;
    }
}

// --- auth helpers in admin router ---
if (!function_exists('admin_logged_in')) {
    function admin_logged_in(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); 
}
        return !empty($_SESSION['admin']) && 
!empty($_SESSION['admin']['email']);
    }
}

/**
 * Gate all admin routes. If not logged in:
 * - If headers aren't sent yet, redirect to login.
 * - If headers are already sent (theme printed), fall back to rendering 
login view.
 */
if (!function_exists('admin_gate')) {
    function admin_gate(string $action): string {
        static $whitelist = 
['login','login-post','register','register-post','password','logout'];
        if (admin_logged_in() || in_array($action, $whitelist, true)) {
            return $action;
        }
        if (!headers_sent()) {
            header('Location: /admin?action=login');
            exit;
        }
        // headers already sent (theme printed) → render login inline
        return 'login';
    }
}
