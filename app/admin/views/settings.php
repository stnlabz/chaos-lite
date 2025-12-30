<?php
/**
 * Admin » Settings (self-posting view)
 *
 * Stores settings in: {projectRoot}/data/site_settings.json
 * Fields handled here:
 *  - site_name:        string
 *  - site_tagline:     string
 *  - registration_open: bool
 *  - theme/active_theme: read-only display (managed by Themes page)
 *
 * Notes:
 *  - Unknown keys in site_settings.json are preserved unmodified.
 *  - File/dirs are created on demand.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Paths */
$root        = rtrim(dirname(__DIR__, 3), '/\\');  // …/app/admin/views -> project root
$dataDir     = $root . '/data';
$settingsFile= $dataDir . '/site_settings.json';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

/** Helpers */
$safe = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

/**
 * @param string $path
 * @param array<string,mixed> $fallback
 * @return array<string,mixed>
 */
$read_json = static function (string $path, array $fallback = []): array {
    if (!is_file($path)) { return $fallback; }
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : $fallback;
};

/**
 * Atomic-ish pretty JSON write.
 *
 * @param string $path
 * @param array<string,mixed> $data
 * @return bool
 */
$write_json_atomic = static function (string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $tmp = $path . '.tmp';
    $ok  = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok === false) { return false; }
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
};

/** Load settings (with defaults) */
$settings = $read_json($settingsFile, []);
$defaults = [
    'site_name'         => 'My Site',
    'site_tagline'      => '',
    'registration_open' => false,
];
$effective = array_merge($defaults, $settings);

$activeTheme = (string) ($settings['theme'] ?? $settings['active_theme'] ?? '');

/** Handle POST */
$notice = '';
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Pull current again in case concurrent changes happened
    $current = $read_json($settingsFile, []);
    // Merge known fields into current to preserve unknown keys
    $current['site_name']         = trim((string) ($_POST['site_name'] ?? $effective['site_name']));
    $current['site_tagline']      = (string) ($_POST['site_tagline'] ?? $effective['site_tagline']);
    $current['registration_open'] = isset($_POST['registration_open']) && $_POST['registration_open'] === '1';

    // Keep theme keys untouched here; Themes page manages them

    // Quick validation
    if ($current['site_name'] === '') {
        $error = 'Site name is required.';
    } else {
        if ($write_json_atomic($settingsFile, $current)) {
            $notice = 'Settings saved.';
            $settings    = $current;
            $effective   = array_merge($defaults, $settings);
            $activeTheme = (string) ($settings['theme'] ?? $settings['active_theme'] ?? '');
        } else {
            $error = 'Failed to write settings file.';
        }
    }
}
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Settings</h1>
    <div class="text-muted small">File: <code>/data/site_settings.json</code></div>
  </div>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?php echo $safe($notice); ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo $safe($error); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="/admin?action=settings" class="row g-3">
        <div class="col-12 col-md-6">
          <label class="form-label">Site Name</label>
          <input type="text" name="site_name" class="form-control" required maxlength="150"
                 value="<?php echo $safe((string) $effective['site_name']); ?>">
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Tagline</label>
          <input type="text" name="site_tagline" class="form-control" maxlength="200"
                 value="<?php echo $safe((string) $effective['site_tagline']); ?>">
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="registration_open" name="registration_open" value="1"
                   <?php echo !empty($effective['registration_open']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="registration_open">
              Allow new user registrations (first user becomes admin; can be toggled here after)
            </label>
          </div>
          <div class="form-text">
            When unchecked, registration is closed. This flag is read by your auth/registration flow.
          </div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Active Theme</label>
          <input type="text" class="form-control" value="<?php echo $safe($activeTheme !== '' ? $activeTheme : '—'); ?>" disabled>
          <div class="form-text">Change the active theme in <a href="/admin?action=themes">Themes</a>.</div>
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button class="btn btn-primary" type="submit">Save Settings</button>
          <a class="btn btn-outline-secondary" href="/admin?action=settings">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <?php
    // Optional: show a compact, read-only JSON snapshot to help debug
    $preview = $read_json($settingsFile, []);
    $jsonOut = json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  ?>
  <div class="card mt-4">
    <div class="card-header">Current site_settings.json</div>
    <div class="card-body">
      <pre class="mb-0" style="white-space:pre-wrap;"><?php echo $safe($jsonOut ?? "{}"); ?></pre>
    </div>
  </div>
</div>

