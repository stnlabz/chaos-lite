<?php
/**
 * Admin » Modules (self-posting view)
 * - Scans /public/modules for module folders
 * - Reads meta.json:
 *   { "name":"Module","version":"1.0.0","author":"stn-labz","description":"..." }
 * - Enable/Disable state stored in {projectRoot}/app/admin/data/modules.json
 * - Delete available ONLY when disabled
 * - If admin/main.php exists AND module is enabled, show "Admin" button that routes to /admin?action=module_admin&slug=<slug>
 * - Content-only view (no header()/exit)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Paths */
$root         = rtrim(dirname(__DIR__, 3), '/\\'); // project root
$docroot      = rtrim($_SERVER['DOCUMENT_ROOT'] ?? $root, '/\\');
$modulesDir   = $docroot . '/public/modules';

$adminDataDir = $root . '/app/admin/data';
if (!is_dir($adminDataDir)) { @mkdir($adminDataDir, 0775, true); }
$stateFile    = $adminDataDir . '/modules.json';

/** Helpers */
$safe = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

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
        $error = 'Missing module slug.';
    } else {
        $modulePath = rtrim($modulesDir, '/\\') . '/' . $slug;
        if (!in_array($op, ['enable', 'disable', 'delete'], true)) {
            $error = 'Unknown operation.';
        } elseif (!is_dir($modulePath) && $op !== 'delete') {
            $error = 'Module folder not found: ' . $safe($slug);
        } else {
            if ($op === 'enable') {
                $enabledSet[$slug] = 1;
                $state['enabled'] = array_values(array_keys($enabledSet));
                $notice = $write_json_atomic($stateFile, $state) ? ('Module enabled: ' . $slug) : 'Failed to update modules state.';
                if ($notice !== ('Module enabled: ' . $slug)) { $error = $notice; $notice = ''; }
            } elseif ($op === 'disable') {
                unset($enabledSet[$slug]);
                $state['enabled'] = array_values(array_keys($enabledSet));
                $notice = $write_json_atomic($stateFile, $state) ? ('Module disabled: ' . $slug) : 'Failed to update modules state.';
                if ($notice !== ('Module disabled: ' . $slug)) { $error = $notice; $notice = ''; }
            } elseif ($op === 'delete') {
                // Only allow delete if currently disabled
                if (array_key_exists($slug, $enabledSet)) {
                    $error = 'Disable the module before deleting.';
                } else {
                    if (is_dir($modulePath)) {
                        if (!$rrmdir($modulePath)) {
                            $error = 'Failed to delete module folder.';
                        } else {
                            $notice = 'Module deleted: ' . $slug;
                        }
                    } else {
                        $notice = 'Module already removed.';
                    }
                    unset($enabledSet[$slug]);
                    $state['enabled'] = array_values(array_keys($enabledSet));
                    $write_json_atomic($stateFile, $state);
                }
            }
        }
    }
}

/** Scan modules directory (read meta.json) */
$modules = [];
if (is_dir($modulesDir)) {
    foreach (@scandir($modulesDir) ?: [] as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $path = $modulesDir . '/' . $it;
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

        $modules[] = [
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

    // Sort alphabetically by Name from meta.json
    usort($modules, function ($a, $b) {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });
}
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Modules</h1>
    <div class="text-muted small">Directory: <code>/public/modules</code></div>
  </div>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?php echo $safe($notice); ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo $safe($error); ?></div>
  <?php endif; ?>

  <?php if (!$modules): ?>
    <div class="card">
      <div class="card-body">
        <div class="text-muted">No modules found. Create folders under <code>/public/modules</code>.</div>
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
              <?php foreach ($modules as $m): ?>
                <tr>
                  <td class="fw-semibold">
                    <?php echo $safe($m['name']); ?>
                    <?php if (!$m['has_meta']): ?>
                      <span class="badge text-bg-warning ms-2">no meta.json</span>
                    <?php endif; ?>
                  </td>
                  <td><code><?php echo $safe($m['slug']); ?></code></td>
                  <td><?php echo $m['version'] !== '' ? $safe($m['version']) : '—'; ?></td>
                  <td><?php echo $m['author']  !== '' ? $safe($m['author'])  : '—'; ?></td>
                  <td class="text-break" style="max-width: 380px;">
                    <?php echo $m['description'] !== '' ? $safe($m['description']) : '<span class="text-muted">—</span>'; ?>
                  </td>
                  <td>
                    <?php if ($m['has_admin']): ?>
                      <span class="badge text-bg-info">Available</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($m['enabled']): ?>
                      <span class="badge text-bg-success">Enabled</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">Disabled</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <?php if ($m['enabled']): ?>
                      <?php if ($m['has_admin']): ?>
                        <a class="btn btn-sm btn-outline-primary me-1" href="/admin?action=module_admin&amp;slug=<?php echo $safe($m['slug']); ?>">Admin</a>
                      <?php endif; ?>
                      <form method="post" action="/admin?action=modules" class="d-inline">
                        <input type="hidden" name="op" value="disable">
                        <input type="hidden" name="slug" value="<?php echo $safe($m['slug']); ?>">
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Disable</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="/admin?action=modules" class="d-inline me-1">
                        <input type="hidden" name="op" value="enable">
                        <input type="hidden" name="slug" value="<?php echo $safe($m['slug']); ?>">
                        <button class="btn btn-sm btn-primary" type="submit">Enable</button>
                      </form>
                      <!-- Delete appears ONLY when disabled -->
                      <form method="post" action="/admin?action=modules" class="d-inline"
                            onsubmit="return confirm('Delete module &quot;<?php echo $safe($m['name']); ?>&quot; (<?php echo $safe($m['slug']); ?>)? This cannot be undone.');">
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="slug" value="<?php echo $safe($m['slug']); ?>">
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

