<?php
/**
 * Chaos CMS — Admin Router (Lean Version)
 * Order: header → functions → autoload → routing → footer
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');


/** BASIC HELPERS / AUTH */
require __DIR__ . '/views/functions.php';



if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/** AUTOLOAD */
spl_autoload_register(function ($fqcn) {
    if (stripos($fqcn, 'admin\\') !== 0) {
        return;
    }
    $rel  = strtolower(str_replace('\\', '/', $fqcn));
    $path = __DIR__ . '/modules/' . $rel . '.php';
    if (is_file($path)) {
        require $path;
    }
});

/** QUICK HELPERS */

function admin_read_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = (string) @file_get_contents($path);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function admin_enabled_modules(): array
{
    $root = rtrim(dirname(__DIR__, 1), '/\\');
    $file = $root . '/admin/data/modules.json';
    $data = admin_read_json($file);
    $list = isset($data['enabled']) && is_array($data['enabled']) ? $data['enabled'] : [];
    return array_values(array_map('strval', $list));
}

function admin_enabled_plugins(): array
{
    $root = rtrim(dirname(__DIR__, 1), '/\\');
    $file = $root . '/admin/data/plugins.json';
    $data = admin_read_json($file);
    $list = isset($data['enabled']) && is_array($data['enabled']) ? $data['enabled'] : [];
    return array_values(array_map('strval', $list));
}

function admin_sanitize_slug(string $slug): string
{
    return (string) preg_replace('~[^a-z0-9_\-]~i', '', $slug);
}

/** ACTION DETECTION */
$action = (string) ($_GET['action'] ?? 'dashboard');
$action = admin_gate($action);
// Handle logout BEFORE any output (no header/footer rendering here)
if ($action === 'logout') {
    // optional: require the functions helper if not already loaded
    // require __DIR__ . '/views/functions.php';
    admin_logout(); // will exit
}

/** ROUTING */
switch ($action) {

    case 'login':
        include __DIR__ . '/views/login.php';
        break;

    case 'logout':
        admin_logout();
        header('Location: /admin?action=login');
        exit;

    case 'dashboard':
        include __DIR__ . '/views/dashboard/main.php';
        break;

    case 'pages':
        include __DIR__ . '/views/pages.php';
        break;

    case 'themes':
        include __DIR__ . '/views/themes.php';
        break;

    case 'modules':
        include __DIR__ . '/views/modules.php';
        break;

    case 'plugins':
        include __DIR__ . '/views/plugins.php';
        break;
        
    case 'posts':
        include __DIR__ . '/views/posts.php';
        break;

    case 'media':
        include __DIR__ . '/views/media.php';
        break;

    case 'users':
        include __DIR__ . '/views/users.php';
        break;

    case 'settings':
        include __DIR__ . '/views/settings.php';
        break;

    case 'maintenance':
        include __DIR__ . '/views/maintenance.php';
        break;

    case 'account':
        include __DIR__ . '/views/account.php';
        break;

    /** --- MODULE ADMIN HOOK --- */
    case 'module_admin': {
        $slug = isset($_GET['slug']) ? admin_sanitize_slug((string) $_GET['slug']) : '';
        if ($slug === '') {
            echo '<div class="alert alert-danger">Missing module slug.</div>';
            break;
        }

        $enabled = admin_enabled_modules();
        if (!in_array($slug, $enabled, true)) {
            echo '<div class="alert alert-warning">Module <code>' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</code> is not enabled.</div>';
            break;
        }

        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 1), '/\\');
        $entry   = $docroot . '/public/modules/' . $slug . '/admin/main.php';
        if (is_file($entry)) {
            include $entry;
        } else {
            echo '<div class="alert alert-warning">Admin UI not found for module <code>' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</code>.</div>';
        }
        break;
    }

    /** --- PLUGIN ADMIN HOOK --- */
    case 'plugin_admin': {
        $slug = isset($_GET['slug']) ? admin_sanitize_slug((string) $_GET['slug']) : '';
        if ($slug === '') {
            echo '<div class="alert alert-danger">Missing plugin slug.</div>';
            break;
        }

        $enabled = admin_enabled_plugins();
        if (!in_array($slug, $enabled, true)) {
            echo '<div class="alert alert-warning">Plugin <code>' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</code> is not enabled.</div>';
            break;
        }

        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 1), '/\\');
        $entry   = $docroot . '/public/plugins/' . $slug . '/admin/main.php';
        if (is_file($entry)) {
            include $entry;
        } else {
            echo '<div class="alert alert-warning">Admin UI not found for plugin <code>' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</code>.</div>';
        }
        break;
    }

    default:
        echo '<div class="alert alert-danger">Unknown admin route: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '</div>';
        break;
}
