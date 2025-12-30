<?php
// /public/modules/home/main.php
// Module-level router: decides what function to call

// -- Latest announcement helper (home-local, silent on failure) -----------
if (!function_exists('get_latest_announcement_home')) {
    /**
     * Returns newest published announcement from the plugin JSON, or null.
     */
    function get_latest_announcement_home(): ?array {
        // Build absolute path from web root (works regardless of this file's location)
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        if ($docroot === '') return null;

        $file = $docroot . '/public/plugins/announcements/data/announcements.json';
        if (!is_readable($file)) return null;

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;

        // Strip UTF-8 BOM if present
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

        $j = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($j) || empty($j['items']) || !is_array($j['items'])) {
            return null;
        }

        // Only published items
        $items = array_values(array_filter($j['items'], static fn($it) => !empty($it['published'])));
        if (!$items) return null;

        // Newest first by ISO-like id (string compare is fine for ISO 8601)
        usort($items, static fn($a, $b) => strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? '')));

        return $items[0] ?? null;
    }
}


// Parse action from URL: "/", "/home", "/home/<action>"
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$parts = array_values(array_filter(explode('/', trim($path,'/'))));
$route = $parts[1] ?? ''; // after /"
$id    = $parts[2] ?? '';

function home() {
  echo '<div class="container">';
  echo '  <h2>Welcome</h2>';
  echo '  <p class="lead">Hey there,</p>';
  echo '  <p>So this is your brand new site, powered by the Chaos CMS. Congratulations on your install, we hope that you find this platform full of the features that you want.</p>';
  echo '<p>Please <a href="/getting-started">Start Here</a></p>';
  echo '<p>Please edit this file <code>/public/modules/home/main.php</code> and remember that you could always just use a MarkDown File <code>main.md</code> or a JSON File <code>main.json</code> to make your front page more to your liking.</p>';
  
echo '</div>';
}
/* --- dispatch --- */
switch ($route) {
  case '':
  case 'home':
    home();
  break;
  default:
    http_response_code(404);
    echo '<div class="container my-4"><div class="alert alert-secondary">Not found: ' . e($action) . '</div></div>';
    break;
}
