<?php
/**
 * Admin » Posts
 *
 * Storage:
 *   /public/modules/posts/data/<slug>.json
 *
 * JSON (minimal; sections ready to render):
 * {
 *   "title": "Hello",
 *   "slug": "hello",
 *   "status": "draft"|"published",
 *   "created_at": "ISO-8601",
 *   "updated_at": "ISO-8601",
 *   "author": "Name <email@example.com>",
 *   "excerpt": "optional short text",
 *   "content_type": "html"|"markdown",
 *   "content": "editor text",
 *   "sections": [
 *     // html:
 *     { "type":"content", "content_type":"html", "html":"..." },
 *     // markdown:
 *     { "type":"content", "content_type":"markdown", "content":"raw md", "html":"pre-rendered html" }
 *   ]
 * }
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// libs already loaded via /app/bootstrap.php:
// e(), jread(), jwrite(), ensure_dir(), slugify()  => app/lib/common.php
// csrf_token(), csrf_ok(), is_post()              => app/lib/security.php

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
    if ($name !== '' && $email !== '') { return $name . ' <' . $email . '>'; }
    if ($email !== '') { return $email; }
    if ($name !== '')  { return $name; }
    if ($user !== '')  { return $user; }
    return 'unknown';
})();

/**
 * Absolute /public docroot.
 *
 * @return string
 */
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3), '/\\');

/**
 * Root dir for posts data.
 *
 * @var string $postsDataDir
 */
$postsDataDir = $docroot . '/public/modules/posts/data';
ensure_dir($postsDataDir);

/**
 * Markdown → HTML for pre-render (admin-side convenience).
 * Uses the site-wide renderer if present.
 *
 * @param string $md
 * @return string
 */
$md_to_html = static function (string $md): string {
    if (function_exists('chaos_md_to_html')) {
        return (string) chaos_md_to_html($md);
    }
    // ultra-minimal fallback (headings/bold/em/paras) if lib missing:
    $s = str_replace(["\r\n", "\r"], "\n", $md);
    $s = preg_replace('/^\s*####\s+(.+)$/m', '<h4>$1</h4>', $s) ?? $s;
    $s = preg_replace('/^\s*###\s+(.+)$/m',  '<h3>$1</h3>', $s) ?? $s;
    $s = preg_replace('/^\s*##\s+(.+)$/m',   '<h2>$1</h2>', $s) ?? $s;
    $s = preg_replace('/^\s*#\s+(.+)$/m',    '<h1>$1</h1>', $s) ?? $s;
    $s = preg_replace('/\*\*\s*(.+?)\s*\*\*/s', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<!\*)\*\s*(.+?)\s*\*(?!\*)/s', '<em>$1</em>', $s) ?? $s;
    $out = [];
    foreach (explode("\n", $s) as $line) {
        $t = trim($line);
        if ($t === '' || preg_match('#^<(h1|h2|h3|h4)#i', $t)) { $out[] = $t; continue; }
        $out[] = '<p>' . $t . '</p>';
    }
    return implode("\n", $out);
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
 * Paths for a post slug.
 *
 * @param string $slug
 * @return array{file:string}
 */
$paths = static function (string $slug) use ($postsDataDir): array {
    $file = rtrim($postsDataDir, '/\\') . '/' . $slug . '.json';
    return ['file' => $file];
};

/**
 * Load post by slug (empty array if missing).
 *
 * @param string $slug
 * @return array<string,mixed>
 */
$loadPost = static function (string $slug) use ($paths): array {
    $p = $paths($slug);
    $j = jread($p['file'], []);
    return is_array($j) ? $j : [];
};

/**
 * Ensure minimal sections without duplicate keys.
 * - html:     section.html
 * - markdown: section.content (raw MD) + section.html (pre-rendered)
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
$ensureSections = static function (array $item) use ($md_to_html): array {
    $ctype   = isset($item['content_type']) && in_array($item['content_type'], ['html', 'markdown'], true)
        ? (string) $item['content_type'] : 'html';
    $content = (string) ($item['content'] ?? '');

    $sec = ['type' => 'content', 'content_type' => $ctype];

    if ($ctype === 'html') {
        $sec['html'] = (string) ($item['sections'][0]['html'] ?? $content);
    } elseif ($ctype === 'markdown') {
        $raw            = (string) ($item['sections'][0]['content'] ?? $content);
        $sec['content'] = $raw;
        $sec['html']    = (string) ($item['sections'][0]['html'] ?? $md_to_html($raw));
    }

    $item['sections'] = [$sec];
    // Avoid top-level 'html'/'body' duplication; keep 'content' for the editor
    unset($item['html'], $item['body']);

    return $item;
};

/**
 * Delete a post file.
 *
 * @param string $file
 * @return bool
 */
$delete_file = static function (string $file): bool {
    return is_file($file) ? @unlink($file) : true;
};

// ----------------------------------------------------
// Actions
// ----------------------------------------------------
$op     = (string) ($_REQUEST['op'] ?? '');
$notice = '';
$error  = '';

if (is_post()) {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!csrf_ok($token)) {
        $error = 'Invalid CSRF token.';
        $op = '';
    } else {
        if ($op === 'create') {
            $title   = trim((string) ($_POST['title'] ?? ''));
            $slugInp = trim((string) ($_POST['slug'] ?? ''));
            $status  = (string) ($_POST['status'] ?? 'draft');
            $ctype   = (string) ($_POST['content_type'] ?? 'markdown');
            $excerpt = (string) ($_POST['excerpt'] ?? '');
            $content = (string) ($_POST['content'] ?? '');
            $author  = $author_from_session;

            if ($title === '') {
                $error = 'Title is required.';
                $op = 'new';
            } else {
                $slug = $slugInp !== '' ? slugify($slugInp) : slugify($title);
                $p    = $paths($slug);
                if (is_file($p['file'])) {
                    $error = 'Slug is already in use.';
                    $op = 'new';
                } else {
                    $now  = $nowUtc();
                    $item = [
                        'title'        => $title,
                        'slug'         => $slug,
                        'status'       => ($status === 'published' ? 'published' : 'draft'),
                        'created_at'   => $now,
                        'updated_at'   => $now,
                        'author'       => $author,
                        'excerpt'      => $excerpt,
                        'content_type' => in_array($ctype, ['html', 'markdown'], true) ? $ctype : 'markdown',
                        'content'      => $content,
                    ];
                    $item = $ensureSections($item);
                    if (jwrite($p['file'], $item)) {
                        @chmod($p['file'], 0664);
                        $notice = 'Post created: ' . $slug;
                        $op = '';
                    } else {
                        $error = 'Failed to write post file.';
                        $op = 'new';
                    }
                }
            }

        } elseif ($op === 'update') {
            $original = trim((string) ($_POST['original_slug'] ?? ''));
            $title    = trim((string) ($_POST['title'] ?? ''));
            $slugInp  = trim((string) ($_POST['slug'] ?? ''));
            $status   = (string) ($_POST['status'] ?? 'draft');
            $ctype    = (string) ($_POST['content_type'] ?? 'markdown');
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
                $oldP = $paths($original);
                $newP = $paths($slug);

                if ($slug !== $original && is_file($newP['file'])) {
                    $error = 'Slug is already in use.';
                    $op = 'edit';
                    $_GET['slug'] = $original;
                } else {
                    $existing = $loadPost($original);
                    if (!$existing) {
                        $error = 'Post not found.';
                        $op = '';
                    } else {
                        $existing['title']        = $title;
                        $existing['slug']         = $slug;
                        $existing['status']       = ($status === 'published' ? 'published' : 'draft');
                        $existing['content_type'] = in_array($ctype, ['html', 'markdown'], true) ? $ctype : 'markdown';
                        $existing['excerpt']      = $excerpt;
                        $existing['content']      = $content;
                        $existing['updated_at']   = $nowUtc();
                        $existing                 = $ensureSections($existing);

                        if ($slug !== $original) {
                            if (!@rename($oldP['file'], $newP['file'])) {
                                $error = 'Failed to rename post file.';
                                $op = 'edit';
                                $_GET['slug'] = $original;
                            } else {
                                if (jwrite($newP['file'], $existing)) {
                                    $notice = 'Post updated: ' . $slug;
                                    $op = '';
                                } else {
                                    $error = 'Failed to write updated post file.';
                                    $op = 'edit';
                                    $_GET['slug'] = $slug;
                                }
                            }
                        } else {
                            if (jwrite($oldP['file'], $existing)) {
                                $notice = 'Post updated: ' . $slug;
                                $op = '';
                            } else {
                                $error = 'Failed to update post.';
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
                    $error = 'Post not found.';
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
                if (!is_file($p['file'])) {
                    $notice = 'Post already removed.';
                } else {
                    if ($delete_file($p['file'])) {
                        $notice = 'Post deleted: ' . $slug;
                    } else {
                        $error  = 'Failed to delete post file.';
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
    $post = $editSlug !== '' ? $loadPost($editSlug) : [];
    if (!$post) {
        $error = 'Post not found.';
        $op = '';
    }
}

// ----------------------------------------------------
// UI
// ----------------------------------------------------
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Posts</h1>
    <div class="d-flex gap-2">
      <?php if ($op !== 'new' && $op !== 'edit'): ?>
        <a class="btn btn-sm btn-primary" href="/admin?action=posts&amp;op=new">New Post</a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-secondary" href="/admin?action=posts">Back to List</a>
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
      $p = $isEdit ? $post : [
        'title'        => '',
        'slug'         => '',
        'status'       => 'draft',
        'content_type' => 'markdown',
        'excerpt'      => '',
        'content'      => '',
      ];
    ?>
    <div class="card">
      <div class="card-body">
        <form method="post" action="/admin?action=posts" class="mb-3">
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
              <label class="form-label">Slug (filename)</label>
              <input type="text" name="slug" class="form-control" maxlength="200"
                     value="<?= e((string) $p['slug']) ?>" placeholder="auto from title">
              <div class="form-text">Stored at <code>/public/modules/posts/data/&lt;slug&gt;.json</code></div>
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
                  $ct = (string) ($p['content_type'] ?? 'markdown');
                  $opts = ['markdown' => 'Markdown', 'html' => 'HTML'];
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
                Markdown supports #, ##, ###, ####, **bold**, *emphasis*, lists (- item).<br>
                PHP is not supported in Posts.
              </div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Post' ?></button>
            <a class="btn btn-outline-secondary" href="/admin?action=posts">Cancel</a>
          </div>
        </form>

        <?php if ($isEdit): ?>
          <div class="d-flex gap-2">
            <form method="post" action="/admin?action=posts">
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

            <form method="post" action="/admin?action=posts"
                  onsubmit="return confirm('Delete this post file permanently?');">
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
      // List posts (read *.json)
      $rows = [];
      $dir = rtrim($postsDataDir, '/\\');
      if (is_dir($dir)) {
          foreach (scandir($dir) ?: [] as $fn) {
              if ($fn === '.' || $fn === '..') { continue; }
              if (strtolower(pathinfo($fn, PATHINFO_EXTENSION)) !== 'json') { continue; }
              $file = $dir . '/' . $fn;
              $item = jread($file, []);
              if (!is_array($item)) { continue; }
              $rows[] = [
                  'title'        => (string) ($item['title'] ?? pathinfo($fn, PATHINFO_FILENAME)),
                  'slug'         => (string) ($item['slug'] ?? pathinfo($fn, PATHINFO_FILENAME)),
                  'status'       => (string) ($item['status'] ?? 'draft'),
                  'updated_at'   => (string) ($item['updated_at'] ?? ($item['created_at'] ?? '')),
                  'created_at'   => (string) ($item['created_at'] ?? ''),
                  'content_type' => (string) ($item['content_type'] ?? 'markdown'),
                  'excerpt'      => (string) ($item['excerpt'] ?? ''),
              ];
          }
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
          <div class="text-muted">No posts yet. Click <em>New Post</em> to create one.</div>
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
                         href="/admin?action=posts&amp;op=edit&amp;slug=<?= e($row['slug']) ?>">Edit</a>

                      <form method="post" action="/admin?action=posts" class="d-inline me-1">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="slug" value="<?= e($row['slug']) ?>">
                        <?php if (($row['status'] ?? 'draft') === 'published'): ?>
                          <input type="hidden" name="op" value="unpublish">
                          <button class="btn btn-sm btn-outline-warning" type="submit">Unpublish</button>
                        <?php else: ?>
                          <input <input type="hidden" name="op" value="publish">
                          <button class="btn btn-sm btn-outline-success" type="submit">Publish</button>
                        <?php endif; ?>
                      </form>

                      <form method="post" action="/admin?action=posts" class="d-inline"
                            onsubmit="return confirm('Delete post “<?= e($row['title']) ?>”?');">
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

