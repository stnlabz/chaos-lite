<?php
/**
 * Admin » Plugins (self-posting view)
 * - Scans /public/plugins for plugin folders
 * - Reads meta.json:
 *   { "name":"Plugin","version":"1.0.0","author":"stn-labz","description":"..." }
 * - Enable/Disable state stored in {projectRoot}/app/admin/data/plugins.json
 * - Delete available ONLY when disabled
 * - If admin/main.php exists AND plugin is enabled, show "Admin" button that routes to /admin?action=plugin_admin&slug=<slug>
 * - Content-only view (no header()/exit)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Paths */
$root         = rtrim(dirname(__DIR__, 3), '/\\'); // project root
$docroot      = rtrim($_SERVER['DOCUMENT_ROOT'] ?? $root, '/\\');
$pluginsDir   = $docroot . '/public/plugins';

$adminDataDir = $root . '/app/admin/data';
if (!is_dir($adminDataDir)) { @mkdir($adminDataDir, 0775, true); }
$stateFile    = $adminDataDir . '/plugins.json';

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

/** Load enabled state (admin-scoped) */
$state = $read_json($stateFile, ['enabled' => []]); // { "enabled": ["slug1","slug2"] }
$enabledSet = array_flip(array_map('strval', (array) ($state['enabled'] ?? [])));

/** Handle POST (enable/disable/delete) */
$notice = '';
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $op   = (string) ($_POST['op'] ?? '');
    $slug = trim((string) ($_POST['slug'] ?? ''));

    if ($slug === '') {
        $error = 'Missing plugin slug.';
    } else {
        $pluginPath = rtrim($pluginsDir, '/\\') . '/' . $slug;
        if (!in_array($op, ['enable', 'disable', 'delete'], true)) {
            $error = 'Unknown operation.';
        } elseif (!is_dir($pluginPath) && $op !== 'delete') {
            $error = 'Plugin folder not found: ' . $safe($slug);
        } else {
            if ($op === 'enable') {
                $enabledSet[$slug] = 1;
                $state['enabled'] = array_values(array_keys($enabledSet));
                $notice = $write_json_atomic($stateFile, $state) ? ('Plugin enabled: ' . $slug) : 'Failed to update plugins state.';
                if ($notice !== ('Plugin enabled: ' . $slug)) { $error = $notice; $notice = ''; }
            } elseif ($op === 'disable') {
                unset($enabledSet[$slug]);
                $state['enabled'] = array_values(array_keys($enabledSet));
                $notice = $write_json_atomic($stateFile, $state) ? ('Plugin disabled: ' . $slug) : 'Failed to update plugins state.';
                if ($notice !== ('Plugin disabled: ' . $slug)) { $error = $notice; $notice = ''; }
            } elseif ($op === 'delete') {
                // Only allow delete if currently disabled
                if (array_key_exists($slug, $enabledSet)) {
                    $error = 'Disable the plugin before deleting.';
                } else {
                    if (is_dir($pluginPath)) {
                        if (!$rrmdir($pluginPath)) {
                            $error = 'Failed to delete plugin folder.';
                        } else {
                            $notice = 'Plugin deleted: ' . $slug;
                        }
                    } else {
                        $notice = 'Plugin already removed.';
                    }
                    unset($enabledSet[$slug]);
                    $state['enabled'] = array_values(array_keys($enabledSet));
                    $write_json_atomic($stateFile, $state);
                }
            }
        }
    }
}

/** Scan plugins directory (read meta.json) */
$plugins = [];
if (is_dir($pluginsDir)) {
    foreach (@scandir($pluginsDir) ?: [] as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $path = $pluginsDir . '/' . $it;
        if (!is_dir($path)) { continue; }

        // Defaults; meta.json is authoritative if present
        $meta = [
            'name'        => $it,
            'version'     => '',
            'author'      => '',
            'description' => '',
        ];

        $metaFile    = $path . '/meta.json';
        $adminEntry  = $path . '/admin/main.php';
        $hasAdmin    = is_file($adminEntry);

        if (is_file($metaFile)) {
            $j = json_decode((string) @file_get_contents($metaFile), true);
            if (is_array($j)) {
                if (!empty($j['name']))        { $meta['name']        = (string) $j['name']; }
                if (!empty($j['version']))     { $meta['version']     = (string) $j['version']; }
                if (!empty($j['author']))      { $meta['author']      = (string) $j['author']; }
                if (!empty($j['description'])) { $meta['description'] = (string) $j['description']; }
            }
        }

        $plugins[] = [
            'slug'        => $it,
            'name'        => $meta['name'],
            'version'     => $meta['version'],
            'author'      => $meta['author'],
            'description' => $meta['description'],
            'enabled'     => array_key_exists($it, $enabledSet),
            'has_meta'    => is_file($metaFile),
            'has_admin'   => $hasAdmin,
        ];
    }

    usort($plugins, function ($a, $b) {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });
}
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Plugins</h1>
    <div class="text-muted small">Directory: <code>/public/plugins</code></div>
  </div>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?php echo $safe($notice); ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo $safe($error); ?></div>
  <?php endif; ?>

  <?php if (!$plugins): ?>
    <div class="card">
      <div class="card-body">
        <div class="text-muted">No plugins found. Create folders under <code>/public/plugins</code>.</div>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Version</th>
                <th>Author</th>
                <th>Description</th>
                <th>Admin</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($plugins as $p): ?>
                <tr>
                  <td class="fw-semibold">
                    <?php echo $safe($p['name']); ?>
                    <?php if (!$p['has_meta']): ?>
                      <span class="badge text-bg-warning ms-2">no meta.json</span>
                    <?php endif; ?>
                  </td>
                  <td><code><?php echo $safe($p['slug']); ?></code></td>
                  <td><?php echo $p['version'] !== '' ? $safe($p['version']) : '—'; ?></td>
                  <td><?php echo $p['author']  !== '' ? $safe($p['author'])  : '—'; ?></td>
                  <td class="text-break" style="max-width: 380px;">
                    <?php echo $p['description'] !== '' ? $safe($p['description']) : '<span class="text-muted">—</span>'; ?>
                  </td>
                  <td>
                    <?php if ($p['has_admin']): ?>
                      <span class="badge text-bg-info">Available</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($p['enabled']): ?>
                      <span class="badge text-bg-success">Enabled</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">Disabled</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <?php if ($p['enabled']): ?>
                      <?php if ($p['has_admin']): ?>
                        <a class="btn btn-sm btn-outline-primary me-1" href="/admin?action=plugin_admin&amp;slug=<?php echo $safe($p['slug']); ?>">Admin</a>
                      <?php endif; ?>
                      <form method="post" action="/admin?action=plugins" class="d-inline">
                        <input type="hidden" name="op" value="disable">
                        <input type="hidden" name="slug" value="<?php echo $safe($p['slug']); ?>">
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Disable</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="/admin?action=plugins" class="d-inline me-1">
                        <input type="hidden" name="op" value="enable">
                        <input type="hidden" name="slug" value="<?php echo $safe($p['slug']); ?>">
                        <button class="btn btn-sm btn-primary" type="submit">Enable</button>
                      </form>
                      <!-- Delete appears ONLY when disabled -->
                      <form method="post" action="/admin?action=plugins" class="d-inline"
                            onsubmit="return confirm('Delete plugin &quot;<?php echo $safe($p['name']); ?>&quot; (<?php echo $safe($p['slug']); ?>)? This cannot be undone.');">
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="slug" value="<?php echo $safe($p['slug']); ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

