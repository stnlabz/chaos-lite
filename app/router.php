<?php
// /app/router.php — lean file-first router (modules > pages)

// Resolve request path
$uriPath = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$trimmed = trim($uriPath, '/');                 // e.g. "about" or "posts/slug"
[$first] = $trimmed === '' ? ['home'] : explode('/', $trimmed, 2);
$docroot    = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

// --- API v1 gateway ---
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($uriPath, '/api/v1')) {
    // Simple split: /api/v1/(core|modules)/...
    $parts = array_values(array_filter(explode('/', trim($uriPath, '/')))); // [api, v1, ...]
    $scope = $parts[2] ?? ''; // 'core' or 'modules'

    // bootstrap (auth + helpers)
    require_once __DIR__ . '/api/v1/bootstrap.php';

    if ($scope === '' ) {
        require __DIR__ . '/api/v1/core/index.php';
        exit;
    }

    if ($scope === 'core') {
        // /api/v1/core/health, etc.
        $next = $parts[3] ?? 'index';
        $target = __DIR__ . '/api/v1/core/' . $next . '.php';
        if (is_file($target)) { require $target; exit; }
        api_json(404, ['status'=>'err','error'=>'Not found']);
        exit;
    }

    if ($scope === 'modules') {
        // /api/v1/modules/<module>/...
        $module = $parts[3] ?? '';
        $moduleApi = $_SERVER['DOCUMENT_ROOT'] . '/public/modules/' . $module . '/api.php';
        if (is_file($moduleApi)) { require $moduleApi; exit; }
        api_json(404, ['status'=>'err','error'=>'Module API not found']);
        exit;
    }
    
    // /api/v1/plugins/<slug>/*
   if ($scope === 'plugins') {
      $plugin = $parts[3] ?? '';
      $pluginApi = $_SERVER['DOCUMENT_ROOT'] . '/public/plugins/' . $plugin . '/api.php';
      if (is_file($pluginApi)) { require $pluginApi; exit; }
        api_json(404, ['status'=>'err','error'=>'Plugin API not found']);
        exit;
      }

    api_json(404, ['status'=>'err','error'=>'Bad scope']);
    exit;
}

// --- ADMIN ---
if ($first === 'admin') {
    $docroot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 1);
    require $docroot . '/app/admin/main.php';
    return;
}

// Candidate paths
$moduleBase = $docroot . '/public/modules/' . $first;
$pageBase   = $docroot . '/public/pages/'   . $first;

$moduleJson = $moduleBase . '/main.json';
$modulePhp  = $moduleBase . '/main.php';
$moduleMD   = $moduleBase . '/main.md';
$pageJson   = $pageBase   . '/main.json';
$pageMD     = $pageBase   . '/main.md';

// Helper: decide sectioned vs raw JSON
$render_json_decide = function (string $file): void {
    $raw = @file_get_contents($file);
    $isSectioned = false;
    if ($raw !== false && $raw !== '') {
        if (substr($raw,0,3)==="\xEF\xBB\xBF") $raw = substr($raw,3); // strip BOM if present
        $tmp = json_decode($raw, true);
        $isSectioned = is_array($tmp) && (isset($tmp['sections']) || isset($tmp['title']));
    }
    $isSectioned ? render_json_sections($file) : render_json_file($file);
};

// Assign a variable to 

// 1) Module main.json (highest priority)
if (is_file($moduleJson)) {
    $render_json_decide($moduleJson);
    return;
}

// 2) Module PHP
if (is_file($modulePhp)) {
    include $modulePhp;
    return;
}

// 3) Pages main.json (e.g., /about -> /public/pages/about/main.json)
if (is_file($pageJson)) {
    $render_json_decide($pageJson);
    return;
}

// MD Pages
if(is_file($pageMD)) {
    render_markdown_file($pageMD);
    return;
}

// MD Modules
if(is_file($moduleMD)) {
    render_markdown_file($moduleMD);
    return;
}

// 4) In-page 404
http_response_code(404);
echo '<div class="container my-4"><div class="alert alert-secondary">Not found: '
     . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . '</div></div>';
