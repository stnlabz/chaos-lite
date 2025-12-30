<?php

declare(strict_types=1);

/**
 * Chaos CMS — Posts Frontend (Single-file module)
 *
 * File: /public/modules/posts/main.php
 *
 * Routes supported:
 *  - /posts                           → list (grid)
 *  - /posts/<slug>                    → single post (detail)
 *  - /posts?slug=<slug>               → single post (query fallback)
 *  - /posts?view=widget_last          → compact widget: latest post
 *
 * Storage:
 *  - JSON at /public/modules/posts/data/*.json
 *    Fields: { title, slug, status, created_at, updated_at, content_type, content, excerpt?, name?, username?/author?, email?, sections? }
 */

// ---------------------------------------------------------------------
// Helpers (no collision with global helpers)
// ---------------------------------------------------------------------

/**
 * HTML escape wrapper — uses global e() if it exists.
 */
function posts_esc(string $s): string
{
    return function_exists('e')
        ? e($s)
        : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Absolute filesystem path to posts data directory.
 */
function posts_data_dir(): string
{
    // This file lives in /public/modules/posts
    return __DIR__ . '/data';
}

/**
 * Load a single post by slug.
 *
 * @param string $slug
 * @return array<string,mixed>|null
 */
function posts_load_one(string $slug): ?array
{
    $file = rtrim(posts_data_dir(), '/\\') . '/' . $slug . '.json';
    if (!is_file($file)) {
        return null;
    }
    $raw = (string) @file_get_contents($file);
    $j   = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/**
 * Load all posts (published only by default), newest first.
 *
 * @param bool $publishedOnly
 * @return array<int, array<string,mixed>>
 */
function posts_load_all(bool $publishedOnly = true): array
{
    $dir = posts_data_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $rows = [];
    foreach (@scandir($dir) ?: [] as $it) {
        if ($it === '.' || $it === '..' || substr($it, -5) !== '.json') {
            continue;
        }
        $path = $dir . '/' . $it;
        $raw  = (string) @file_get_contents($path);
        $j    = json_decode($raw, true);
        if (!is_array($j)) {
            continue;
        }

        $status = strtolower(trim((string) ($j['status'] ?? 'draft')));
        if ($publishedOnly && $status !== 'published') {
            continue;
        }

        $j['_created_ts'] = strtotime((string) ($j['created_at'] ?? '')) ?: (@filemtime($path) ?: 0);
        $j['_updated_ts'] = strtotime((string) ($j['updated_at'] ?? '')) ?: $j['_created_ts'];

        if (empty($j['slug'])) {
            $j['slug'] = basename($it, '.json');
        }

        $rows[] = $j;
    }

    usort($rows, static function (array $a, array $b): int {
        $d = ($b['_updated_ts'] ?? 0) <=> ($a['_updated_ts'] ?? 0);
        return $d !== 0 ? $d : (($b['_created_ts'] ?? 0) <=> ($a['_created_ts'] ?? 0));
    });

    return $rows;
}

/**
 * Convert post body to HTML based on content_type.
 *
 * @param array<string,mixed> $post
 */
function posts_render_body_html(array $post): string
{
    $ctype = strtolower((string) ($post['content_type'] ?? 'html'));
    $body  = (string) ($post['content'] ?? '');

    // Prefer first "content" section if body empty
    if ($body === '' && !empty($post['sections']) && is_array($post['sections'])) {
        foreach ($post['sections'] as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            if (($sec['type'] ?? '') === 'content') {
                $secCt = strtolower((string) ($sec['content_type'] ?? ''));
                $secTx = (string) ($sec['content'] ?? ($sec['html'] ?? ''));
                if ($secTx !== '') {
                    $ctype = $secCt !== '' ? $secCt : $ctype;
                    $body  = $secTx;
                    break;
                }
            }
        }
    }

    if ($ctype === 'markdown' || $ctype === 'md') {
        if (function_exists('chaos_md_to_html')) {
            return (string) chaos_md_to_html($body);
        }
        if (function_exists('chaos_md_to_html_inline')) {
            return (string) chaos_md_to_html_inline($body);
        }
        // Minimal fallback: H1–H4, strong/em
        $s = str_replace(["\r\n", "\r"], "\n", $body);
        $s = preg_replace('/^####[ \t]+(.+?)\s*$/m', '<h4>$1</h4>', $s);
        $s = preg_replace('/^### [ \t]*(.+?)\s*$/m', '<h3>$1</h3>', $s);
        $s = preg_replace('/^##[ \t]+(.+?)\s*$/m', '<h2>$1</h2>', $s);
        $s = preg_replace('/^#[ \t]+(.+?)\s*$/m', '<h1>$1</h1>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $s);
        return (string) $s;
    }

    if ($ctype === 'text' || $ctype === 'txt') {
        return nl2br(posts_esc($body));
    }

    return $body; // html
}

/**
 * Short excerpt for cards.
 *
 * @param array<string,mixed> $post
 */
function posts_excerpt(array $post, int $limit = 180): string
{
    $ex = (string) ($post['excerpt'] ?? '');
    if ($ex !== '') {
        return posts_esc($ex);
    }
    $html = posts_render_body_html($post);
    $text = trim(strip_tags($html));
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) > $limit) {
        $cut = mb_substr($text, 0, $limit);
        $cut = preg_replace('/\s+\S*$/u', '', $cut) ?? $cut;
        return posts_esc($cut . '…');
    }
    return posts_esc($text);
}

/**
 * Display name for author: name || username || author || email || "unknown".
 *
 * @param array<string,mixed> $post
 */
function posts_author_display(array $post): string
{
    $name = (string) ($post['name'] ?? '');
    if ($name !== '') {
        return posts_esc($name);
    }
    $user = (string) ($post['username'] ?? ($post['author'] ?? ''));
    if ($user !== '') {
        return posts_esc($user);
    }
    $mail = (string) ($post['email'] ?? '');
    if ($mail !== '') {
        return posts_esc($mail);
    }
    return 'unknown';
}

/**
 * Badge for content type.
 *
 * @param array<string,mixed> $post
 */
function posts_type_badge(array $post): string
{
    $ct = strtolower((string) ($post['content_type'] ?? 'html'));
    $label = match ($ct) {
        'markdown', 'md' => 'Markdown',
        'text', 'txt'    => 'Text',
        'html'           => 'HTML',
        default          => strtoupper($ct),
    };
    return '<span class="badge text-bg-secondary">' . posts_esc($label) . '</span>';
}

/**
 * One card for list/grid.
 *
 * @param array<string,mixed> $post
 */
function posts_render_card(array $post): string
{
    $title = posts_esc((string) ($post['title'] ?? $post['slug'] ?? 'Untitled'));
    $slug  = posts_esc((string) ($post['slug'] ?? ''));
    $href  = '/posts/' . $slug;

    $meta  = 'By ' . posts_author_display($post) . ' · ' . date('Y-m-d', (int) ($post['_updated_ts'] ?? time()));
    $ex    = posts_excerpt($post, 180);
    $badge = posts_type_badge($post);

    return <<<HTML
<div class="card h-100 shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
      <a class="stretched-link text-decoration-none" href="{$href}"><h2 class="h5 mb-1">{$title}</h2></a>
      <div class="ms-2">{$badge}</div>
    </div>
    <div class="text-muted small mb-2">{$meta}</div>
    <p class="mb-0">{$ex}</p>
  </div>
</div>
HTML;
}

/**
 * Echo grid of newest posts.
 */
function posts_render_grid(int $limit = 12): void
{
    $rows = posts_load_all(true);
    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }

    echo '<div class="row g-3">';
    foreach ($rows as $p) {
        echo '<div class="col-12 col-md-6 col-lg-4">';
        echo posts_render_card($p);
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Echo compact “latest post” card (for home).
 */
function posts_render_last_post_card(): void
{
    $rows = posts_load_all(true);
    $p    = $rows[0] ?? null;
    if (!$p) {
        echo '<div class="card"><div class="card-body text-muted small">No posts yet.</div></div>';
        return;
    }

    $title = posts_esc((string) ($p['title'] ?? $p['slug'] ?? 'Untitled'));
    $slug  = posts_esc((string) ($p['slug'] ?? ''));
    $href  = '/posts/' . $slug;
    $meta  = 'By ' . posts_author_display($p) . ' · ' . date('Y-m-d', (int) ($p['_updated_ts'] ?? time()));
    $ex    = posts_excerpt($p, 140);
    $badge = posts_type_badge($p);

    echo <<<HTML
<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
      <a class="stretched-link text-decoration-none" href="{$href}"><h2 class="h6 mb-1">{$title}</h2></a>
      <div class="ms-2">{$badge}</div>
    </div>
    <div class="text-muted small mb-2">{$meta}</div>
    <p class="mb-0">{$ex}</p>
  </div>
</div>
HTML;
}

/**
 * Echo full single post view (title + meta + body).
 */
function posts_render_detail(string $slug): void
{
    $p = posts_load_one($slug);
    if (!$p || strtolower((string) ($p['status'] ?? 'draft')) !== 'published') {
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-warning">Post not found.</div></div>';
        return;
    }

    $title = posts_esc((string) ($p['title'] ?? $slug));
    $meta  = 'By ' . posts_author_display($p)
           . ' · ' . date('Y-m-d', (int) (strtotime((string) ($p['created_at'] ?? '')) ?: time()));
    $body  = posts_render_body_html($p);
    $badge = posts_type_badge($p);

    echo <<<HTML
<article class="container my-4">
  <header class="mb-3">
    <div class="d-flex justify-content-between align-items-start">
      <h1 class="h3 mb-1">{$title}</h1>
      <div>{$badge}</div>
    </div>
    <div class="text-muted small">{$meta}</div>
  </header>
  <div class="post-body">
    {$body}
  </div>
</article>
HTML;
}

// ---------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------

$view = (string) ($_GET['view'] ?? '');

// Try query first
$slug = trim((string) ($_GET['slug'] ?? ''));

// If no query slug, parse from path: /posts/<slug>[/...]
if ($slug === '') {
    $uriPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $parts   = array_values(array_filter(explode('/', trim($uriPath, '/'))));

    // Find "posts" segment and take the next segment as slug
    $idx = array_search('posts', $parts, true);
    if ($idx !== false && isset($parts[$idx + 1]) && $parts[$idx + 1] !== '') {
        $slug = $parts[$idx + 1];
    }
}

// Widget mode
if ($view === 'widget_last') {
    posts_render_last_post_card();
    return;
}

// Detail mode
if ($slug !== '') {
    posts_render_detail($slug);
    return;
}

// List (default)
echo '<div class="container my-4">';
echo '<h1 class="h4 mb-3">Latest Posts</h1>';
posts_render_grid(12);
echo '</div>';

