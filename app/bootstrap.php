<?php
/*
 * Bootstrap
 * Because its all needed
*/

declare(strict_types=1);
// Definitions
$ROOT = dirname(__DIR__);
define('LOG_PATH', $ROOT . '/logs');

spl_autoload_register(function ($class) {
    $class = ltrim($class, '\\');
    if (strpos($class, 'app\\') === 0) {
        $class_path = str_replace('\\', '/', $class);
        $file = __DIR__ . '/' . substr($class_path, 4) . '.php';
        if (file_exists($file)) {
            require_once $file;
        } else {
            error_log("Autoload failed: $file\n", 3, LOG_PATH . '/autoload.log');
        }
    }
});

// Canonical data path for this vhost
if (!defined('DATA_PATH')) {
    define('DATA_PATH', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/data');
}

// Error Logging
ini_set('display_errors', 1); // Hide from user
ini_set('log_errors', 1);     // Enable logging
ini_set('error_log', LOG_PATH . '/php_errors.log');

// Optional: set error reporting level
error_reporting(E_ALL);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Sessions
if (session_status() === PHP_SESSION_NONE) {
  $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>$https]);
  session_start();
}

$PROJECT_ROOT = dirname(__DIR__, 1); // /app -> project root
if (!defined('DATA_PATH')) {
  define('DATA_PATH', $PROJECT_ROOT . '/data');
}

/*
 * Load up core
 * For the things needed
*/

// Utility
$utility = new app\core\utility();

/*
 * Site Specific
 * For the sitename
 * and the theme
 * from the JSON  settings
*/

$site_settings  = $utility->get_settings() ?: [];

$navItems = [];

if (isset($settings['nav'])) {
    $raw = $settings['nav'];
    // If something turned it into objects, normalize to array
    if (is_object($raw)) {
        $raw = json_decode(json_encode($raw), true);
    }
    if (is_array($raw)) {
        // keep only items that have label+href
        $navItems = array_values(array_filter($raw, function ($it) {
            return is_array($it) && isset($it['label'], $it['href']);
        }));
    }
}

// Canonical, well-named vars
$site_name = isset($site_settings['name'])  ? (string)$site_settings['name']  : 'Chaos CMS Lite';
$site_theme = isset($site_settings['theme']) ? (string)$site_settings['theme'] : 'minimal';
$nav = (isset($site_settings['nav']) && is_array($site_settings['nav'])) ? $site_settings['nav'] : [];

// Make slug global from router
$uri  = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$slug = $uri !== '' ? $uri : 'home';
define('CURRENT_SLUG', $slug);

// Libs (common helpers/render/plugins)
require_once $PROJECT_ROOT . '/app/lib/common.php';
require_once $PROJECT_ROOT . '/app/lib/render.php';
require_once $PROJECT_ROOT . '/app/lib/plugins.php';
require_once $PROJECT_ROOT . '/app/lib/security.php';

// Cron
require_once __DIR__ . '/cron/bootstrap.php';

