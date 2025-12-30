<?php
/**
 * Admin Dashboard (content-only view)
 * - All PHP inline, no includes or autoload.
 * - Has top-right Settings / Maintenance / Logout toolbar.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Locate paths */
$root     = rtrim(realpath(__DIR__ . '/../../../..') ?: dirname(__DIR__, 4), '/\\');            // project root
$docroot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? $root, '/\\');                                   // web root (public lives here)
$dataDir  = $root . '/data';
$appDir   = $root . '/app';
$adminDir = $appDir . '/admin';

$pagesDir   = $docroot . '/public/pages';   // FIXED: pages are under /public/pages/<slug>/main.json
$themesDir  = $docroot . '/public/themes';
$uploadsDir = $docroot . '/public/uploads';

/** Helper: safe read JSON */
$read_json = function (string $path, array $fallback = []): array {
    if (!is_file($path)) { return $fallback; }
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : $fallback;
};

/** users.json path (primary + legacy fallback) */
$usersPrimary = $adminDir . '/data/users.json'; // /app/admin/data/users.json
$usersLegacy  = $appDir   . '/data/users.json'; // /app/data/users.json
$usersFile    = is_file($usersPrimary) ? $usersPrimary : $usersLegacy;

/** site settings */
$settingsFile = $dataDir . '/site_settings.json';
$settings     = $read_json($settingsFile, [
    'registration_enabled' => true,
    'admin_email'          => '',
    'maintenance_enabled'  => false,
    'maintenance_message'  => '',
]);

/** users (normalized a bit for reliability) */
$users = $read_json($usersFile, []);
if (!is_array($users)) { $users = []; }
foreach ($users as &$u) {
    $u['role']   = strtolower((string)($u['role'] ?? 'user')) === 'admin' ? 'admin' : 'user';
    $act         = $u['active'] ?? true;
    $u['active'] = !in_array(strtolower((string)$act), ['0','false','no','off',''], true);
}
unset($u);

/** Registration label:
 *  - If no users yet -> "open (first admin)"
 *  - Else use site_settings flag
 */
$registration = (count($users) === 0)
    ? 'open (first admin)'
    : (!empty($settings['registration_enabled']) ? 'open' : 'closed');

/** Maintenance */
$maintenance = !empty($settings['maintenance_enabled']) ? 'on' : 'off';

/** Counts */
$pagesCount = 0;
if (is_dir($pagesDir)) {
    foreach (@scandir($pagesDir) ?: [] as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $pagesDir . '/' . $it;
        if (is_dir($p) && is_file($p . '/main.json')) { $pagesCount++; }
    }
}

$themesCount = 0;
if (is_dir($themesDir)) {
    foreach (@scandir($themesDir) ?: [] as $it) {
        if ($it === '.' || $it === '..') { continue; }
        if (is_dir($themesDir . '/' . $it)) { $themesCount++; }
    }
}

$usersCount = is_array($users) ? count($users) : 0;

/** Recent Pages (last 5 by mtime on main.json) */
$recentPages = [];
if (is_dir($pagesDir)) {
    foreach (@scandir($pagesDir) ?: [] as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $dir  = $pagesDir . '/' . $it;
        $json = $dir . '/main.json';
        if (is_file($json)) {
            $mtime  = @filemtime($json) ?: 0;
            $meta   = $read_json($json, []);
            $title  = (string) ($meta['title'] ?? $it);
            $recentPages[] = ['slug' => $it, 'title' => $title, 'mtime' => $mtime];
        }
    }
    usort($recentPages, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    $recentPages = array_slice($recentPages, 0, 5);
}

/** Recent Uploads (last 5 files anywhere under /public/uploads) */
$recentUploads = [];
if (is_dir($uploadsDir)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) { continue; }
        $path  = $file->getPathname();
        $mtime = @filemtime($path) ?: 0;
        $rel   = ltrim(str_replace('\\', '/', substr($path, strlen($uploadsDir))), '/');
        $recentUploads[] = [
            'name'  => $file->getFilename(),
            'rel'   => $rel,
            'mtime' => $mtime,
            'url'   => '/public/uploads/' . $rel
        ];
    }
    usort($recentUploads, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    $recentUploads = array_slice($recentUploads, 0, 5);
}

/** Current session user (you’re storing it under $_SESSION['admin']) */
$me      = $_SESSION['admin'] ?? null;
$meEmail = is_array($me) ? (string)($me['email'] ?? '') : '';
$meRole  = is_array($me) ? (string)($me['role']  ?? 'editor') : '';
?>
<div class="container my-4">

  <!-- Header bar with right-aligned buttons -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Dashboard</h1>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/admin?action=settings">Settings</a>
      <a class="btn btn-sm btn-outline-warning" href="/admin?action=maintenance">Maintenance</a>
      <?php if ($meEmail !== ''): ?>
        <a class="btn btn-sm btn-outline-danger" href="/admin?action=logout">Logout</a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-primary" href="/admin?action=login">Login</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($maintenance === 'on'): ?>
    <div class="alert alert-warning">
      <strong>Maintenance mode is ON.</strong>
      <?= $settings['maintenance_message'] !== '' ? htmlspecialchars((string)$settings['maintenance_message'], ENT_QUOTES, 'UTF-8') : 'Visitors will see a maintenance page.' ?>
      <a class="ms-2" href="/admin?action=maintenance">Change</a>
    </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="row g-3">
    <div class="col-12 col-md-3">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <div class="text-muted small mb-1">Pages</div>
          <div class="display-6"><?= (int)$pagesCount ?></div>
          <div class="mt-3 d-flex gap-2">
            <a class="btn btn-sm btn-primary" href="/admin?action=pages">Manage</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin?action=pages&do=new">New</a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <div class="text-muted small mb-1">Themes</div>
          <div class="display-6"><?= (int)$themesCount ?></div>
          <div class="mt-3 d-flex gap-2">
            <a class="btn btn-sm btn-primary" href="/admin?action=themes">Manage</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin?action=themes&view=preview">Preview</a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <div class="text-muted small mb-1">Users</div>
          <div class="display-6"><?= (int)$usersCount ?></div>
          <div class="mt-3 d-flex gap-2">
            <a class="btn btn-sm btn-primary" href="/admin?action=users">Manage</a>
            <?php if ($registration !== 'closed'): ?>
              <a class="btn btn-sm btn-outline-secondary" href="/admin?action=login">Register</a>
            <?php endif; ?>
          </div>
          <div class="text-muted small mt-2">Registration: <?= htmlspecialchars($registration, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <div class="text-muted small mb-1">You</div>
          <div class="fw-semibold"><?= htmlspecialchars($meEmail !== '' ? $meEmail : 'Not signed in', ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-muted small"><?= $meEmail !== '' ? 'Role: ' . htmlspecialchars($meRole, ENT_QUOTES, 'UTF-8') : '' ?></div>
          <div class="mt-3 d-flex gap-2">
            <?php if ($meEmail !== ''): ?>
              <a class="btn btn-sm btn-outline-danger" href="/admin?action=logout">Logout</a>
            <?php else: ?>
              <a class="btn btn-sm btn-primary" href="/admin?action=login">Login</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Recent activity -->
  <div class="row g-3 mt-2">
    <div class="col-12 col-lg-6">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold">Recent Pages</div>
            <a class="small" href="/admin?action=pages">All pages</a>
          </div>
          <?php if (!$recentPages): ?>
            <div class="text-muted small">No pages yet.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recentPages as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span>
                    <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-muted small ms-2">/<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?></span>
                  </span>
                  <span class="text-muted small"><?= date('Y-m-d H:i', (int)$p['mtime']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold">Recent Uploads</div>
            <a class="small" href="/admin?action=media">Open media</a>
          </div>
          <?php if (!$recentUploads): ?>
            <div class="text-muted small">No files uploaded.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recentUploads as $f): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <a href="<?= htmlspecialchars($f['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                    <?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-muted small ms-2"><?= htmlspecialchars($f['rel'], ENT_QUOTES, 'UTF-8') ?></span>
                  </a>
                  <span class="text-muted small"><?= date('Y-m-d H:i', (int)$f['mtime']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick links -->
  <div class="mt-3 d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="/admin?action=media">Media</a>
    <a class="btn btn-outline-secondary" href="/admin?action=modules">Modules</a>
    <a class="btn btn-outline-secondary" href="/admin?action=users">Membership</a>
    <a class="btn btn-outline-secondary" href="/admin?action=pages">Pages</a>
    <a class="btn btn-outline-secondary" href="/admin?action=plugins">Plugins</a>
    <a class="btn btn-outline-secondary" href="/admin?action=posts">Posts</a>
    <a class="btn btn-outline-secondary" href="/admin?action=themes">Themes</a>
  </div>
</div>


