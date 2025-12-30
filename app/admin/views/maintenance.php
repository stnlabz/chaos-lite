<?php
/**
 * Admin » Maintenance (self-posting view)
 *
 * Edits {projectRoot}/data/site_settings.json → "maintenance" block:
 * {
 *   "maintenance": {
 *     "enabled": true|false,
 *     "message": "string",
 *     "until": "YYYY-MM-DDTHH:MM:SSZ" // optional
 *   },
 *   ... (other site settings preserved)
 * }
 *
 * Front-end behavior (your bootstrap):
 * if ($settings['maintenance']['enabled'] ?? false) { require /public/maintenance.php; exit; }
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/** Paths */
$root         = rtrim(dirname(__DIR__, 3), '/\\');   // …/app/admin/views -> project root
$dataDir      = $root . '/data';
$settingsFile = $dataDir . '/site_settings.json';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

/** Helpers */

/**
 * Escape for HTML.
 *
 * @param string $s
 * @return string
 */
function adm_safe(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Read JSON file to array, with fallback.
 *
 * @param string               $path
 * @param array<string,mixed>  $fallback
 * @return array<string,mixed>
 */
function adm_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = (string) @file_get_contents($path);
    $j   = json_decode($raw, true);
    return is_array($j) ? $j : $fallback;
}

/**
 * Atomic pretty JSON write.
 *
 * @param string               $path
 * @param array<string,mixed>  $data
 * @return bool
 */
function adm_write_json_atomic(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp';
    $ok  = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok === false) {
        return false;
    }
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
}

/** Load current settings */
$settings    = adm_read_json($settingsFile, []);
$maintenance = (array) ($settings['maintenance'] ?? []);

$enabled = !empty($maintenance['enabled']);
$message = (string) ($maintenance['message'] ?? 'We’ll be back soon.');
$until   = (string) ($maintenance['until'] ?? '');

/** Handle POST */
$notice = '';
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $newEnabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
    $newMessage = trim((string) ($_POST['message'] ?? ''));
    $newUntil   = trim((string) ($_POST['until'] ?? ''));

    if ($newMessage === '') {
        $error = 'A public message is required.';
    } else {
        // Preserve unrelated settings, only update maintenance block
        $settingsFresh = adm_read_json($settingsFile, []);
        $settingsFresh['maintenance'] = [
            'enabled' => $newEnabled,
            'message' => $newMessage,
            'until'   => $newUntil,
        ];

        if (adm_write_json_atomic($settingsFile, $settingsFresh)) {
            $settings    = $settingsFresh;
            $maintenance = $settingsFresh['maintenance'];
            $enabled     = (bool) $maintenance['enabled'];
            $message     = (string) $maintenance['message'];
            $until       = (string) $maintenance['until'];
            $notice      = 'Maintenance settings saved.';
        } else {
            $error = 'Failed to write site settings.';
        }
    }
}

/** UI */
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Maintenance</h1>
    <div class="text-muted small">File: <code>/data/site_settings.json</code></div>
  </div>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?php echo adm_safe($notice); ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo adm_safe($error); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="/admin?action=maintenance" class="row g-3">
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
            <label class="form-check-label" for="enabled">Enable maintenance mode</label>
          </div>
          <div class="form-text">
            When enabled, your bootstrap will <code>require /public/maintenance.php</code> and stop normal rendering.
          </div>
        </div>

        <div class="col-12">
          <label class="form-label">Public message</label>
          <textarea name="message" class="form-control" rows="3" required><?php echo adm_safe($message); ?></textarea>
          <div class="form-text">Shown on the maintenance page.</div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Estimated back online (ISO-8601, optional)</label>
          <input type="text" name="until" class="form-control" placeholder="2025-10-26T02:00:00Z" value="<?php echo adm_safe($until); ?>">
          <div class="form-text">Leave blank if unknown. This is informational only for your maintenance page.</div>
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button class="btn btn-primary" type="submit">Save</button>
          <a class="btn btn-outline-secondary" href="/admin?action=maintenance">Reset</a>
          <a class="btn btn-outline-dark ms-auto" href="/public/maintenance.php" target="_blank" rel="noopener">Preview Page</a>
        </div>
      </form>
    </div>
  </div>

  <?php
    $snapshot = adm_read_json($settingsFile, []);
    $jsonOut  = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  ?>
  <div class="card mt-4">
    <div class="card-header">Current site_settings.json</div>
    <div class="card-body">
      <pre class="mb-0" style="white-space:pre-wrap;"><?php echo adm_safe($jsonOut ?? "{}"); ?></pre>
    </div>
  </div>
</div>

