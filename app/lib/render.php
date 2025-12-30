<?php
declare(strict_types=1);

/**
 * Chaos CMS render helpers
 * ----------------------------------
 * Core rendering logic for pages, sections, and markdown content.
 * This version includes:
 *  - Markdown emphasis (bold/italic) fixes
 *  - render_markdown_file() helper for .md files
 */

if (!function_exists('chaos_md_to_html')) {
    /**
     * Convert Markdown text to HTML (block-level renderer)
     *
     * @param string $md
     * @return string
     */
    function chaos_md_to_html(string $md): string
    {
        $lines = preg_split('/\R/', $md);
        $out   = '';

        foreach ($lines as $line) {
            $trim = trim($line);

            // Headings
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
                $level = strlen($m[1]);
                $text  = chaos_md_to_html_inline(trim($m[2]));
                $out  .= "<h{$level}>{$text}</h{$level}>\n";
                continue;
            }

            // Lists
            if (preg_match('/^[-\*+]\s+(.+)$/', $trim, $m)) {
                $out .= "<ul><li>" . chaos_md_to_html_inline($m[1]) . "</li></ul>\n";
                continue;
            }

            // Paragraph
            if ($trim !== '') {
                $out .= '<p>' . chaos_md_to_html_inline($trim) . "</p>\n";
            }
        }

        return $out;
    }
}

if (!function_exists('chaos_md_to_html_inline')) {
    /**
     * Inline Markdown converter used by chaos_md_to_html()
     *
     * @param string $s
     * @return string
     */
    function chaos_md_to_html_inline(string $s): string
    {
        // Code spans
        $s = preg_replace_callback('/`([^`]+)`/', static function ($m) {
            return '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        }, $s);

        // Links
        $s = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', static function ($m) {
            $text = $m[1];
            $href = $m[2];
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $text . '</a>';
        }, $s);

        // --- Emphasis/Strong (lenient) ---
        // Strong: **bold** or __bold__
        $s = preg_replace('/\*\*\s*(.+?)\s*\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/__\s*(.+?)\s*__/s',     '<strong>$1</strong>', $s);

        // Emphasis: *em* or _em_ (avoid clashing with strong)
        $s = preg_replace('/(?<!\*)\*\s*(.+?)\s*\*(?!\*)/s', '<em>$1</em>', $s);
        $s = preg_replace('/(?<!_)_\s*(.+?)\s*_(?!_)/s',     '<em>$1</em>', $s);
        
        // Bug ID: 8804, The H's
        // Used to be h1 .....
        // Has to be h6 and below, largest to smallest
        
        // --- H6
        $s = preg_replace('/^######\s*(.+)$/m','<h6>$1</h6>', $s);
        
        // --- H5
        $s = preg_replace('/^#####\s*(.+)$/m','<h5>$1</h5>', $s);
        
        // --- H4
        $s = preg_replace('/^####\s*(.+)$/m','<h4>$1</h4>', $s);
        
        // --- H3
        $s = preg_replace('/^###\s*(.+)$/m','<h3>$1</h3>', $s);
        
        // --- H2
        $s = preg_replace('/^##\s*(.+)$/m','<h2>$1</h2>', $s);
        
        // --- H1
        $s = preg_replace('/^#\s*(.+)$/m','<h1>$1</h1>', $s);

        return $s;
    }
}

if (!function_exists('render_markdown_file')) {
    /**
     * Render a raw Markdown file to HTML using chaos_md_to_html().
     *
     * @param string $filePath Absolute or relative path to a .md/.markdown file.
     * @return void
     */
    function render_markdown_file(string $filePath): void
    {
        if (!is_file($filePath)) {
            echo '<div class="alert alert-warning">Missing content file.</div>';
            return;
        }

        $raw  = (string) @file_get_contents($filePath);
        $html = chaos_md_to_html($raw);
        echo $html;
    }
}

/**
 * Render sections from a JSON file.
 * (Unchanged baseline — included for completeness)
 */
if (!function_exists('render_json_sections')) {
    function render_json_sections(string $filePath): void
    {
        if (!is_file($filePath)) {
            echo '<div class="alert alert-warning">Missing content file.</div>';
            return;
        }

        $raw  = (string) @file_get_contents($filePath);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            echo '<div class="alert alert-danger">Invalid JSON.</div>';
            return;
        }

        $sections = $data['sections'] ?? [];
        if (!is_array($sections) || !$sections) {
            echo '<div class="alert alert-info">No sections.</div>';
            return;
        }

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title   = (string) ($section['title'] ?? '');
            $secHtml = (string) ($section['html'] ?? '');
            $secMd   = (string) ($section['md'] ?? ($section['markdown'] ?? ''));

            // Admin schema bridge (only if md/html keys not present)
            if ($secHtml === '' && $secMd === '') {
                $ctype = (string) ($section['content_type'] ?? '');
                if ($ctype === 'html') {
                    $secHtml = (string) ($section['html'] ?? $section['content'] ?? '');
                } elseif ($ctype === 'markdown') {
                    $secMd = (string) ($section['content'] ?? '');
                } elseif ($ctype === 'php') {
                    $code = (string) ($section['content'] ?? '');
                    if ($title !== '') {
                        echo '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
                    }
                    echo '<section class="section php"><pre class="code php">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</pre></section>';
                    continue;
                }
            }

            if ($secMd !== '') {
                $secHtml = chaos_md_to_html($secMd);
            }

            if ($title !== '') {
                echo '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
            }
            echo '<section class="section">' . $secHtml . '</section>';
        }
    }
}

/**
 * Render JSON file (baseline unchanged)
 */
if (!function_exists('render_json_file')) {
    function render_json_file(string $filePath): void
    {
        if (!is_file($filePath)) {
            echo '<div class="alert alert-warning">Missing content file.</div>';
            return;
        }

        $raw  = (string) @file_get_contents($filePath);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            echo '<div class="alert alert-danger">Invalid JSON.</div>';
            return;
        }

        $sections = $data['sections'] ?? [];
        if (is_array($sections) && $sections) {
            render_json_sections($filePath);
            return;
        }

        $html = (string) ($data['html'] ?? '');
        $md   = (string) ($data['md'] ?? ($data['markdown'] ?? ''));
        if ($html !== '') {
            echo $html;
            return;
        }
        if ($md !== '') {
            echo chaos_md_to_html($md);
            return;
        }

        echo '<div class="alert alert-info">No content.</div>';
    }
}

