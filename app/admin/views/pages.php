<?php
/**
 * Admin » Pages
 *
 * Storage layout:
 *   /public/pages/<slug>/main.json
 *
 * JSON (front-end friendly; minimal sections, no duplicate keys):
 * {
 *   "title": "About",
 *   "slug": "about",
 *   "status": "draft"|"published",
 *   "created_at": "ISO-8601",
 *   "updated_at": "ISO-8601",
 *   "author": "Name <email@example.com>",
 *   "content_type": "html|markdown|php",
 *   "excerpt": "optional",
 *   "content": "string",             // kept for admin editor
 *   "sections": [
 *     { "type":"content", "content_type":"html", "html":"..." } // for html
 *     // or { "type":"content", "content_type":"markdown|php", "content":"..." }
 *   ]
 * }
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// libs already loaded via /app/bootstrap.php:
// - e(), jread(), jwrite(), ensure_dir(), slugify() from app/lib/common.php
// - csrf_token(), csrf_ok(), is_post() from app/lib/security.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Resolve author string from session.
 *
 * @return string
 */
$author_from_session = (static function (): string {
    $name  = (string) ($_SESSION['admin_name']  ?? '');
    $email = (string) ($_SESSION['admin_email'] ?? '');
    $user  = (string) ($_SESSION['admin_user']  ?? '');
    if ($name !== '' && $email !== '') {
        return $name . ' <' . $email . '>';
    }
    if ($email !== '') {
        return $email;
    }
    if ($name !== '') {
        return $name;
    }
    if ($user !== '') {
        return $user;
    }
    return 'unknown';
})();

/**
 * Absolute /public docroot.
 *
 * @return string
 */
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3), '/\\');

/** @var string $pagesRoot root dir for pages */
$pagesRoot = $docroot . '/public/pages';
ensure_dir($pagesRoot);

/**
 * Build paths for a page slug.
 *
 * @param string $slug
 * @return array{dir:string,file:string}
 */
$paths = static function (string $slug) use ($pagesRoot): array {
    $dir  = rtrim($pagesRoot, '/\\') . '/' . $slug;
    $file = $dir . '/main.json';
    return ['dir' => $dir, 'file' => $file];
};

/**
 * Read a page by slug (returns [] if missing).
 *
 * @param string $slug
 * @return array<string,mixed>
 */
$loadPage = static function (string $slug) use ($paths): array {
    $p = $paths($slug);
    $j = jread($p['file'], []);
    return is_array($j) ? $j : [];
};

/**
 * Ensure minimal sections array for front-end without duplicate keys.
 * - HTML → use "html" key in the section
 * - Markdown/PHP → use "content" key in the section
 * Removes top-level "html"/"body"; keeps top-level "content" for the admin editor.
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
$ensureSections = static function (array $item): array {
    $ctype   = isset($item['content_type']) && in_array($item['content_type'], ['html', 'markdown', 'php'], true)
        ? (string) $item['content_type'] : 'html';
    $content = (string) ($item['content'] ?? '');

    if (empty($item['sections']) || !is_array($item['sections'])) {
        $sec = [
            'type'         => 'content',
            'content_type' => $ctype,
        ];
        if ($ctype === 'html') {
            $sec['html'] = $content;
        } else {
            $sec['content'] = $content;
        }
        $item['sections'] = [$sec];
    } else {
        // Normalize only the first content section and avoid duplicates
        foreach ($item['sections'] as $i => $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $sType  = (string) ($sec['type'] ?? 'content');
            $sCtype = (string) ($sec['content_type'] ?? $ctype);
            $sBody  = (string) ($sec['content'] ?? ($sec['html'] ?? $content));

            $norm = [
                'type'         => $sType,
                'content_type' => $sCtype,
            ];
            if ($sCtype === 'html') {
                $norm['html'] = $sBody;
            } else {
                $norm['content'] = $sBody;
            }
            $item['sections'][$i] = $norm;
            break;
        }
    }

    // Remove compatibility clutter at the top level; keep only editor "content"
    unset($item['html'], $item['body']);

    return $item;
};

/**
 * ISO-8601 now (UTC).
 *
 * @return string
 */
$nowUtc = static function (): string {
    $dt = new DateTime('now', new DateTimeZone('UTC'));
    return $dt->format('c');
};

/**
 * Recursively delete a directory.
 *
 * @param string $dir
 * @return bool
 */
$rrmdir = static function (string $dir): bool {
    if (!is_dir($dir)) {
        return true;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $ok = true;
    foreach ($it as $f) {
        $ok = $ok && ($f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()));
    }
    return $ok && @rmdir($dir);
};

// ----------------------------------------------------
// Actions
// ----------------------------------------------------
$op     = (string) ($_REQUEST['op'] ?? '');
$notice = '';
$error  = '';

if (is_post()) {
    // CSRF
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!csrf_ok($token)) {
        $error = 'Invalid CSRF token.';
        $op = '';
    } else {
        if ($op === 'create') {
            $title   = trim((string) ($_POST['title'] ?? ''));
            $slugInp = trim((string) ($_POST['slug'] ?? ''));
            $status  = (string) ($_POST['status'] ?? 'draft');
            $ctype   = (string) ($_POST['content_type'] ?? 'html');
            $excerpt = (string) ($_POST['excerpt'] ?? '');
            $content = (string) ($_POST['content'] ?? '');
            $author  = $author_from_session;

            $slug = $slugInp !== '' ? slugify($slugInp) : slugify($title);
            if ($title === '') {
                $error = 'Title is required.';
                $op = 'new';
            } else {
                $p = $paths($slug);
                if (is_dir($p['dir'])) {
                    $error = 'Slug is already in use.';
                    $op = 'new';
                } else {
                    ensure_dir($p['dir']);
                    $now  = $nowUtc();
                    $item = [
                        'title'        => $title,
                        'slug'         => $slug,
                        'status'       => ($status === 'published' ? 'published' : 'draft'),
                        'created_at'   => $now,
                        'updated_at'   => $now,
                        'author'       => $author,
                        'content_type' => in_array($ctype, ['html', 'markdown', 'php'], true) ? $ctype : 'html',
                        'excerpt'      => $excerpt,
                        'content'      => $content,
                    ];
                    $item = $ensureSections($item);
                    if (jwrite($p['file'], $item)) {
                        $notice = 'Page created: ' . $slug;
                        $op = '';
                    } else {
                        $error = 'Failed to write page file.';
                        $op = 'new';
                    }
                }
            }

        } elseif ($op === 'update') {
            $original = trim((string) ($_POST['original_slug'] ?? ''));
            $title    = trim((string) ($_POST['title'] ?? ''));
            $slugInp  = trim((string) ($_POST['slug'] ?? ''));
            $status   = (string) ($_POST['status'] ?? 'draft');
            $ctype    = (string) ($_POST['content_type'] ?? 'html');
            $excerpt  = (string) ($_POST['excerpt'] ?? '');
            $content  = (string) ($_POST['content'] ?? '');

            if ($original === '') {
                $error = 'Missing original slug.';
                $op = '';
            } elseif ($title === '') {
                $error = 'Title is required.';
                $op = 'edit';
                $_GET['slug'] = $original;
            } else {
                $slug = $slugInp !== '' ? slugify($slugInp) : slugify($title !== '' ? $title : $original);
                $old  = $paths($original);
                $new  = $paths($slug);

                if ($slug !== $original && is_dir($new['dir'])) {
                    $error = 'Slug is already in use.';
                    $op = 'edit';
                    $_GET['slug'] = $original;
                } else {
                    $existing = $loadPage($original);
                    if (!$existing) {
                        $error = 'Page not found.';
                        $op = '';
                    } else {
                        $existing['title']        = $title;
                        $existing['slug']         = $slug;
                        $existing['status']       = ($status === 'published' ? 'published' : 'draft');
                        $existing['content_type'] = in_array($ctype, ['html', 'markdown', 'php'], true) ? $ctype : 'html';
                        $existing['excerpt']      = $excerpt;
                        $existing['content']      = $content;
                        $existing['updated_at']   = $nowUtc();
                        $existing                 = $ensureSections($existing);

                        // Move dir if slug changed
                        if ($slug !== $original) {
                            if (!@rename($old['dir'], $new['dir'])) {
                                $error = 'Failed to rename page directory.';
                                $op = 'edit';
                                $_GET['slug'] = $original;
                            } else {
                                if (jwrite($new['file'], $existing)) {
                                    $notice = 'Page updated: ' . $slug;
                                    $op = '';
                                } else {
                                    $error = 'Failed to write updated page file.';
                                    $op = 'edit';
                                    $_GET['slug'] = $slug;
                                }
                            }
                        } else {
                            if (jwrite($old['file'], $existing)) {
                                $notice = 'Page updated: ' . $slug;
                                $op = '';
                            } else {
                                $error = 'Failed to update page.';
                                $op = 'edit';
                                $_GET['slug'] = $original;
                            }
                        }
                    }
                }
            }

        } elseif ($op === 'publish' || $op === 'unpublish') {
            $slug = (string) ($_POST['slug'] ?? '');
            if ($slug === '') {
                $error = 'Missing slug.';
            } else {
                $p = $paths($slug);
                $j = jread($p['file'], []);
                if (!$j) {
                    $error = 'Page not found.';
                } else {
                    $j['status']     = ($op === 'publish') ? 'published' : 'draft';
                    $j['updated_at'] = $nowUtc();
                    $j               = $ensureSections($j);
                    if (jwrite($p['file'], $j)) {
                        $notice = ($op === 'publish' ? 'Published: ' : 'Unpublished: ') . $slug;
                    } else {
                        $error = 'Failed to update status.';
                    }
                }
            }
            $op = '';

        } elseif ($op === 'delete') {
            $slug = (string) ($_POST['slug'] ?? '');
            if ($slug === '') {
                $error = 'Missing slug.';
            } else {
                $p = $paths($slug);
                if (!is_dir($p['dir'])) {
                    $notice = 'Page already removed.';
                } else {
                    if ($rrmdir($p['dir'])) {
                        $notice = 'Page deleted: ' . $slug;
                    } else {
                        $error = 'Failed to delete page directory.';
                    }
                }
            }
            $op = '';
        }
    }
}

// GET: edit
if ($op === 'edit') {
    $editSlug = (string) ($_GET['slug'] ?? '');
    $page = $editSlug !== '' ? $loadPage($editSlug) : [];
    if (!$page) {
        $error = 'Page not found.';
        $op = '';
    }
}

// ----------------------------------------------------
// UI
// ----------------------------------------------------
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Pages</h1>
    <div class="d-flex gap-2">
      <?php if ($op !== 'new' && $op !== 'edit'): ?>
        <a class="btn btn-sm btn-primary" href="/admin?action=pages&amp;op=new">New Page</a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-secondary" href="/admin?action=pages">Back to List</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($op === 'new' || $op === 'edit'): ?>
    <?php
      $isEdit = ($op === 'edit');
      $p = $isEdit ? $page : [
        'title'        => '',
        'slug'         => '',
        'status'       => 'draft',
        'content_type' => 'html',
        'excerpt'      => '',
        'content'      => '',
      ];
    ?>
    <div class="card">
      <div class="card-body">
        <form method="post" action="/admin?action=pages" class="mb-3">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="op" value="<?= $isEdit ? 'update' : 'create' ?>">
          <?php if ($isEdit): ?>
            <input type="hidden" name="original_slug" value="<?= e((string) $p['slug']) ?>">
          <?php endif; ?>

          <div class="row g-3">
            <div class="col-12 col-md-8">
              <label class="form-label">Title</label>
              <input type="text" name="title" class="form-control" required maxlength="200"
                     value="<?= e((string) $p['title']) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Slug (folder name)</label>
              <input type="text" name="slug" class="form-control" maxlength="200"
                     value="<?= e((string) $p['slug']) ?>" placeholder="auto from title">
              <div class="form-text">Stored at <code>/public/pages/&lt;slug&gt;/main.json</code></div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Status</label>
              <select class="form-select" name="status">
                <option value="draft"     <?= ($p['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= ($p['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Output Type</label>
              <select class="form-select" name="content_type">
                <?php
                  $ct = (string) ($p['content_type'] ?? 'html');
                  $opts = ['html' => 'HTML', 'markdown' => 'Markdown', 'php' => 'PHP'];
                  foreach ($opts as $val => $label) {
                      $sel = $ct === $val ? 'selected' : '';
                      echo '<option value="' . e($val) . "\" $sel>" . e($label) . '</option>';
                  }
                ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Excerpt (optional)</label>
              <input type="text" name="excerpt" class="form-control" maxlength="500"
                     value="<?= e((string) $p['excerpt']) ?>">
            </div>

            <div class="col-12">
              <label class="form-label">Content</label>
              <textarea name="content" class="form-control" rows="14"><?= e((string) $p['content']) ?></textarea>
              <div class="form-text">
                For <strong>PHP</strong>, content is stored as text. Execution (if any) is controlled by the front-end.
              </div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Page' ?></button>
            <a class="btn btn-outline-secondary" href="/admin?action=pages">Cancel</a>
          </div>
        </form>

        <?php if ($isEdit): ?>
          <div class="d-flex gap-2">
            <form method="post" action="/admin?action=pages">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="slug" value="<?= e((string) $p['slug']) ?>">
              <?php if (($p['status'] ?? 'draft') === 'published'): ?>
                <input type="hidden" name="op" value="unpublish">
                <button class="btn btn-outline-warning" type="submit">Unpublish</button>
              <?php else: ?>
                <input type="hidden" name="op" value="publish">
                <button class="btn btn-outline-success" type="submit">Publish</button>
              <?php endif; ?>
            </form>

            <form method="post" action="/admin?action=pages"
                  onsubmit="return confirm('Delete this page directory permanently?');">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="op" value="delete">
              <input type="hidden" name="slug" value="<?= e((string) $p['slug']) ?>">
              <button class="btn btn-outline-danger" type="submit">Delete</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <?php
      // list pages
      $rows = [];
      foreach (scandir($pagesRoot) ?: [] as $slug) {
          if ($slug === '.' || $slug === '..') {
              continue;
          }
          $dir = $pagesRoot . '/' . $slug;
          if (!is_dir($dir)) {
              continue;
          }
          $file = $dir . '/main.json';
          if (!is_file($file)) {
              continue;
          }

          $item = jread($file, []);
          if (!is_array($item)) {
              continue;
          }

          $rows[] = [
              'title'        => (string) ($item['title'] ?? $slug),
              'slug'         => (string) ($item['slug'] ?? $slug),
              'status'       => (string) ($item['status'] ?? 'draft'),
              'updated_at'   => (string) ($item['updated_at'] ?? ($item['created_at'] ?? '')),
              'created_at'   => (string) ($item['created_at'] ?? ''),
              'content_type' => (string) ($item['content_type'] ?? 'html'),
              'excerpt'      => (string) ($item['excerpt'] ?? ''),
          ];
      }
      usort($rows, static function ($a, $b) {
          $ua = strtotime((string) ($a['updated_at'] ?? '')) ?: 0;
          $ub = strtotime((string) ($b['updated_at'] ?? '')) ?: 0;
          return $ub <=> $ua;
      });
    ?>
    <div class="card">
      <div class="card-body">
        <?php if (!$rows): ?>
          <div class="text-muted">No pages yet. Click <em>New Page</em> to create one.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Slug</th>
                  <th>Status</th>
                  <th>Updated</th>
                  <th>Created</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td class="fw-semibold">
                      <?= e($row['title']) ?>
                      <div class="small text-muted">
                        <?= strtoupper(e($row['content_type'])) ?>
                        <?php if ($row['excerpt'] !== ''): ?>
                          · <?= e($row['excerpt']) ?>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td><code><?= e($row['slug']) ?></code></td>
                    <td>
                      <?php if (($row['status'] ?? 'draft') === 'published'): ?>
                        <span class="badge text-bg-success">Published</span>
                      <?php else: ?>
                        <span class="badge text-bg-secondary">Draft</span>
                      <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e($row['updated_at']) ?></td>
                    <td class="small text-muted"><?= e($row['created_at']) ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary me-1"
                         href="/admin?action=pages&amp;op=edit&amp;slug=<?= e($row['slug']) ?>">Edit</a>

                      <form method="post" action="/admin?action=pages" class="d-inline me-1">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="slug" value="<?= e($row['slug']) ?>">
                        <?php if (($row['status'] ?? 'draft') === 'published'): ?>
                          <input type="hidden" name="op" value="unpublish">
                          <button class="btn btn-sm btn-outline-warning" type="submit">Unpublish</button>
                        <?php else: ?>
                          <input type="hidden" name="op" value="publish">
                          <button class="btn btn-sm btn-outline-success" type="submit">Publish</button>
                        <?php endif; ?>
                      </form>

                      <form method="post" action="/admin?action=pages" class="d-inline"
                            onsubmit="return confirm('Delete page “<?= e($row['title']) ?>” and its folder?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="slug" value="<?= e($row['slug']) ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

