<?php
/**
 * Admin » Media (self-posting view)
 *
 * Root directory: {docroot}/public/media
 * - Upload files to /public/media/YYYY/MM (auto-created), filename de-duplicated.
 * - List newest files first (recursive scan, limited to 200 to keep it snappy).
 * - Image thumbnails where possible, otherwise a generic tile.
 * - Copy URL, View, and Delete (with path jail to /public/media only).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Paths */
$root     = rtrim(dirname(__DIR__, 3), '/\\');                      // project root (…/app/admin/views -> …/)
$docroot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? $root, '/\\');
$mediaDir = $docroot . '/public/media';
if (!is_dir($mediaDir)) { @mkdir($mediaDir, 0775, true); }

/** Helpers */
$safe = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

/**
 * @param int $bytes
 * @return string
 */
$human_size = static function (int $bytes): string {
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    $n = max($bytes, 0);
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return sprintf(($i === 0) ? '%d %s' : '%.1f %s', ($i === 0 ? $n : $n), $u[$i]);
};

/**
 * Safe join under a base dir; returns NULL if the path escapes the base.
 *
 * @param string $base
 * @param string $rel
 * @return string|null
 */
$join_under = static function (string $base, string $rel): ?string {
    $rel = str_replace(['..', '\\'], ['', '/'], $rel);
    $full = rtrim($base, '/\\') . '/' . ltrim($rel, '/\\');
    $rpBase = realpath($base);
    $rpFull = realpath($full);
    // realpath() returns false if the file doesn't exist yet; handle parent dir check
    if ($rpFull === false) {
        $parent = realpath(dirname($full));
        if ($parent === false) { return null; }
        if (strpos($parent, $rpBase) !== 0) { return null; }
        return $full;
    }
    if ($rpBase === false || strpos($rpFull, $rpBase) !== 0) { return null; }
    return $rpFull;
};

/**
 * De-duplicate filename inside a directory by appending -1, -2, ...
 *
 * @param string $dir
 * @param string $filename
 * @return string
 */
$dedupe_name = static function (string $dir, string $filename): string {
    $dir = rtrim($dir, '/\\');
    $name = $filename;
    $path = $dir . '/' . $name;
    if (!file_exists($path)) { return $name; }

    $dot = strrpos($filename, '.');
    $base = $dot !== false ? substr($filename, 0, $dot) : $filename;
    $ext  = $dot !== false ? substr($filename, $dot) : '';
    $i = 1;
    do {
        $name = $base . '-' . $i . $ext;
        $path = $dir . '/' . $name;
        $i++;
    } while (file_exists($path));
    return $name;
};

/**
 * Build public URL from an absolute file path under /public.
 *
 * @param string $absPath
 * @return string
 */
$file_url = static function (string $absPath) use ($docroot): string {
    $absPath = str_replace('\\', '/', $absPath);
    $docroot = str_replace('\\', '/', rtrim($docroot, '/\\'));
    if (strpos($absPath, $docroot) === 0) {
        return substr($absPath, strlen($docroot)) ?: '/';
    }
    return '/';
};

/**
 * Find newest files under $mediaDir (recursive), capped to $limit.
 *
 * @param string $rootDir
 * @param int $limit
 * @return array<int, array{path:string, url:string, mtime:int, size:int, ext:string, is_image:bool}>
 */
$gather_files = static function (string $rootDir, int $limit = 200) use ($file_url): array {
    if (!is_dir($rootDir)) { return []; }
    $rows = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) { continue; }
        $path = $file->getPathname();
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mt   = @filemtime($path) ?: 0;
        $sz   = (int) @filesize($path);

        $isImg = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'], true);
        $rows[] = [
            'path'     => $path,
            'url'      => $file_url($path),
            'mtime'    => $mt,
            'size'     => $sz,
            'ext'      => $ext,
            'is_image' => $isImg,
        ];
    }

    usort($rows, static function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }
    return $rows;
};

/** Handle POST: upload/delete */
$notice = '';
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $op = (string) ($_POST['op'] ?? '');

    if ($op === 'upload') {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            $error = 'No file uploaded.';
        } else {
            $f = $_FILES['file'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Upload error code: ' . (int) $f['error'];
            } else {
                $orig = (string) ($f['name'] ?? 'upload.bin');
                // simple filename cleanup
                $clean = preg_replace('~[^a-zA-Z0-9\.\-_]+~', '-', $orig) ?? $orig;
                $clean = trim($clean, '.- ');

                // target subdir /YYYY/MM
                $yyyy = date('Y');
                $mm   = date('m');
                $targetDir = rtrim($mediaDir, '/\\') . '/' . $yyyy . '/' . $mm;
                if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }

                $name = $dedupe_name($targetDir, $clean === '' ? ('file-' . substr(sha1((string)mt_rand()), 0, 6)) : $clean);
                $dest = $targetDir . '/' . $name;

                if (@move_uploaded_file((string) $f['tmp_name'], $dest)) {
                    @chmod($dest, 0664);
                    $notice = 'Uploaded: ' . $name;
                } else {
                    $error = 'Failed to move uploaded file.';
                }
            }
        }

    } elseif ($op === 'delete') {
        $rel = (string) ($_POST['rel'] ?? '');
        $abs = $join_under($mediaDir, $rel);
        if ($abs === null) {
            $error = 'Invalid path.';
        } elseif (!is_file($abs)) {
            $notice = 'File already removed.';
        } else {
            if (@unlink($abs)) {
                $notice = 'Deleted: ' . basename($abs);
            } else {
                $error = 'Failed to delete file.';
            }
        }
    }
}

/** Gather newest files for display */
$files = $gather_files($mediaDir, 200);

/** UI */
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Media</h1>
    <div class="text-muted small">Directory: <code>/public/media</code></div>
  </div>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?php echo $safe($notice); ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo $safe($error); ?></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <form method="post" action="/admin?action=media" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="op" value="upload">
        <div class="col-12 col-md-6">
          <label class="form-label">Upload file</label>
          <input class="form-control" type="file" name="file" required>
          <div class="form-text">Files are stored in <code>/public/media/YYYY/MM</code>. Max size depends on <code>upload_max_filesize</code>.</div>
        </div>
        <div class="col-12 col-md-3">
          <button class="btn btn-primary" type="submit">Upload</button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!$files): ?>
    <div class="card"><div class="card-body">
      <div class="text-muted">No media files yet. Use the upload form above.</div>
    </div></div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($files as $fi): ?>
        <?php
          $url   = $fi['url'];
          $mtime = $fi['mtime'];
          $sizeH = $human_size($fi['size']);
          // Relative path under /public/media for delete
          $rel   = ltrim(substr(str_replace('\\', '/', $fi['path']), strlen(str_replace('\\', '/', $mediaDir))), '/');
        ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
          <div class="card h-100 shadow-sm">
            <div class="ratio ratio-4x3" style="background:#f8f9fb; display:block;">
              <?php if ($fi['is_image']): ?>
                <img src="<?php echo $safe($url); ?>" alt="" style="object-fit:cover; width:100%; height:100%; border-top-left-radius:.375rem; border-top-right-radius:.375rem;">
              <?php else: ?>
                <div class="d-flex align-items-center justify-content-center" style="height:100%;">
                  <div class="text-center">
                    <div class="fw-semibold"><?php echo strtoupper($safe($fi['ext'] ?: 'FILE')); ?></div>
                    <div class="small text-muted">non-image</div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <div class="card-body d-flex flex-column">
              <div class="small text-truncate mb-1" title="<?php echo $safe(basename($url)); ?>">
                <?php echo $safe(basename($url)); ?>
              </div>
              <div class="text-muted small mb-2">
                <?php echo $safe($sizeH); ?> · <?php echo $safe(date('Y-m-d H:i', $mtime)); ?>
              </div>
              <div class="mt-auto d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary" href="<?php echo $safe($url); ?>" target="_blank" rel="noopener">View</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyMediaUrl('<?php echo $safe($url); ?>')">Copy URL</button>
                <form method="post" action="/admin?action=media" class="ms-auto"
                      onsubmit="return confirm('Delete this file permanently?');">
                  <input type="hidden" name="op" value="delete">
                  <input type="hidden" name="rel" value="<?php echo $safe($rel); ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function copyMediaUrl(url) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(function () {
      alert('Copied URL:\n' + url);
    }, function () {
      prompt('Copy URL:', url);
    });
  } else {
    prompt('Copy URL:', url);
  }
}
</script>

