<?php
/**
 * Admin » Themes (self-posting view)
 * - Lists themes under /public/themes
 * - Activate writes {projectRoot}/data/site_settings.json
 * - Preview opens "/?theme_preview=<slug>" in a new tab (front-end may honor it)
 * - Shows Delete button ONLY when theme is NOT active
 * - Content-only view (no header()/exit)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Paths */
$root          = rtrim(dirname(__DIR__, 3), '/\\'); // project root
$docroot       = rtrim($_SERVER['DOCUMENT_ROOT'] ?? $root, '/\\');
$themesDir     = $docroot . '/public/themes';
$dataDir       = $root . '/data';
$settingsFile  = $dataDir . '/site_settings.json';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

/** Helpers */
$safe = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$read_json = function (string $path, array $fallback = []): array {
    if (!is_file($path)) { return $fallback; }
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : $fallback;
};

$write_json_atomic = function (string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $tmp = $path . '.tmp';
    $ok  = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok === false) { return false; }
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
};

$rrmdir = function (string $dir): bool {
    if (!is_dir($dir)) { return true; }
    $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            if (!@rmdir($file->getPathname())) { return false; }
        } else {
            if (!@unlink($file->getPathname())) { return false; }
        }
    }
    return @rmdir($dir);
};

/** Settings */
$settings = $read_json($settingsFile, []);
$activeTheme = (string) ($settings['theme'] ?? $settings['active_theme'] ?? '');

/** Handle POST (activate/delete) */
$notice = '';
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $op   = (string) ($_POST['op'] ?? '');
    $slug = trim((string) ($_POST['slug'] ?? ''));

    if ($slug === '') {
        $error = 'Missing theme slug.';
    } elseif ($op === 'activate') {
        $cand = rtrim($themesDir, '/\\') . '/' . $slug;
        if (!is_dir($cand)) {
            $error = 'Theme folder not found: ' . $safe($slug);
        } else {
            $settings['theme'] = $slug;
            $settings['active_theme'] = $slug;
            if ($write_json_atomic($settingsFile, $settings)) {
                $activeTheme = $slug;
                $notice = 'Theme activated: ' . $slug;
            } else {
                $error = 'Failed to write site settings.';
            }
        }
    } elseif ($op === 'delete') {
        if ($slug === $activeTheme) {
            $error = 'Deactivate (activate another theme) before deleting.';
        } else {
            $path = rtrim($themesDir, '/\\') . '/' . $slug;
            if (!is_dir($path)) {
                $notice = 'Theme already removed.';
            } else {
                if ($rrmdir($path)) {
                    $notice = 'Theme deleted: ' . $slug;
                } else {
                    $error = 'Failed to delete theme folder.';
                }
            }
        }
    } else {
        $error = 'Unknown operation.';
    }
}

/** Scan themes directory */
$themes = [];
if (is_dir($themesDir)) {
    $scan = @scandir($themesDir) ?: [];
    foreach ($scan as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $path = $themesDir . '/' . $it;
        if (!is_dir($path)) { continue; }

        // defaults
        $meta = [
            'name'        => $it,
            'description' => '',
            'version'     => '',
            'author'      => '',
            'screenshot'  => '',
        ];

        // theme.json (optional)
        $tj = $path . '/theme.json';
        if (is_file($tj)) {
            $j = json_decode((string) @file_get_contents($tj), true);
            if (is_array($j)) {
                if (!empty($j['name']))        { $meta['name']        = (string) $j['name']; }
                if (!empty($j['description'])) { $meta['description'] = (string) $j['description']; }
                if (!empty($j['version']))     { $meta['version']     = (string) $j['version']; }
                if (!empty($j['author']))      { $meta['author']      = (string) $j['author']; }
                if (!empty($j['screenshot']))  { $meta['screenshot']  = (string) $j['screenshot']; }
            }
        }

        // screenshot fallback
        if ($meta['screenshot'] === '') {
            foreach (['screenshot.png','screenshot.jpg','screenshot.jpeg','screenshot.webp'] as $fn) {
                if (is_file($path . '/' . $fn)) { $meta['screenshot'] = $fn; break; }
            }
        }

        $screenshotUrl = $meta['screenshot'] !== ''
            ? '/public/themes/' . rawurlencode($it) . '/' . str_replace('\\','/',$meta['screenshot'])
            : '';

        $themes[] = [
            'slug'       => $it,
            'name'       => $meta['name'],
            'description'=> $meta['description'],
            'version'    => $meta['version'],
            'author'     => $meta['author'],
            'screenshot' => $screenshotUrl,
            'active'     => ($it === $activeTheme),
            'preview_url'=> '/?theme_preview=' . rawurlencode($it),
        ];
    }
    usort($themes, fn($a,$b) => strcasecmp((string)$a['name'], (string)$b['name']));
}

/** Current preview banner (optional) */
$__preview = '';
if (!empty($_COOKIE['theme_preview']))      { $__preview = (string) $_COOKIE['theme_preview']; }
elseif (!empty($_SESSION['theme_preview'])) { $__preview = (string) $_SESSION['theme_preview']; }
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Themes</h1>
    <div class="text-muted small">Directory: <code>/public/themes</code></div>
  </div>

  <?php if ($__preview !== ''): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
      <div>
        <strong>Previewing:</strong> <?php echo $safe($__preview); ?>
        <span class="text-muted small ms-2">Only you can see this (request override/session).</span>
      </div>
      <a class="btn btn-sm btn-outline-secondary" href="/?theme_preview=__clear" target="_blank" rel="noopener">Stop Preview</a>
    </div>
  <?php endif; ?>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?php echo $safe($notice); ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo $safe($error); ?></div>
  <?php endif; ?>

  <?php if (!$themes): ?>
    <div class="card"><div class="card-body">
      <div class="text-muted">No themes found. Create folders under <code>/public/themes</code>.</div>
    </div></div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($themes as $t): ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card h-100 shadow-sm">
            <?php if ($t['screenshot'] !== ''): ?>
              <img src="<?php echo $safe($t['screenshot']); ?>" class="card-img-top" alt="screenshot">
            <?php endif; ?>
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold"><?php echo $safe($t['name']); ?></div>
                  <div class="text-muted small"><code><?php echo $safe($t['slug']); ?></code></div>
                </div>
                <?php if ($t['active']): ?>
                  <span class="badge text-bg-success">Active</span>
                <?php endif; ?>
              </div>

              <?php if ($t['description'] !== ''): ?>
                <p class="mt-2 mb-2"><?php echo $safe($t['description']); ?></p>
              <?php endif; ?>

              <div class="text-muted small">
                <?php
                  $bits = [];
                  if ($t['version'] !== '') { $bits[] = 'v' . $safe($t['version']); }
                  if ($t['author']  !== '') { $bits[] = 'by ' . $safe($t['author']); }
                  echo $bits ? implode(' · ', $bits) : '';
                ?>
              </div>

              <div class="mt-3 d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo $safe($t['preview_url']); ?>" target="_blank" rel="noopener">Preview</a>

                <?php if (!$t['active']): ?>
                  <form method="post" action="/admin?action=themes" class="d-inline me-1">
                    <input type="hidden" name="op" value="activate">
                    <input type="hidden" name="slug" value="<?php echo $safe($t['slug']); ?>">
                    <button class="btn btn-sm btn-primary" type="submit">Activate</button>
                  </form>
                  <!-- Delete appears ONLY when not active -->
                  <form method="post" action="/admin?action=themes" class="d-inline"
                        onsubmit="return confirm('Delete theme &quot;<?php echo $safe($t['name']); ?>&quot; (<?php echo $safe($t['slug']); ?>)? This cannot be undone.');">
                    <input type="hidden" name="op" value="delete">
                    <input type="hidden" name="slug" value="<?php echo $safe($t['slug']); ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                  </form>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-success" type="button" disabled>Activated</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

