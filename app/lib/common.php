<?php
// /app/lib/common.php

// HTML escape
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// JSON read/write
if (!function_exists('jread')) {
  function jread(string $file, $default = null) {
    if (!is_file($file) || !is_readable($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return $default;
    if (substr($raw,0,3) === "\xEF\xBB\xBF") $raw = substr($raw,3);
    $j = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $j : $default;
  }
}
if (!function_exists('ensure_dir')) {
  function ensure_dir(string $dir): bool { return is_dir($dir) || @mkdir($dir, 0775, true); }
}
if (!function_exists('jwrite')) {
  function jwrite(string $file, $data): bool {
    $dir = dirname($file);
    if (!ensure_dir($dir)) return false;
    $tmp  = $file . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $file);
  }
}
if (!function_exists('write_json_atomic')) {
  function write_json_atomic(string $file, array $data): bool { return jwrite($file, $data); }
}
if (!function_exists('read_dir_files')) {
  function read_dir_files(string $dir, string $pattern='*.json'): array {
    if (!is_dir($dir)) return [];
    $list = glob(rtrim($dir,'/\\') . '/' . $pattern) ?: [];
    sort($list);
    return $list;
  }
}

// Slugs
if (!function_exists('slugify')) {
  function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    $s = trim($s, '-');
    return $s ?: 'item';
  }
}
if (!function_exists('slugify_page')) {
  function slugify_page(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('~[^\p{L}\p{N}]+~u', '-', $s);
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('~[^a-z0-9-]+~', '', $s);
    $s = preg_replace('~-+~', '-', $s);
    $s = trim($s, '-');
    if (strlen($s) > 64) $s = rtrim(substr($s, 0, 64), '-');
    return $s ?: 'page';
  }
}

// Dates & snippets
if (!function_exists('fmt_date')) {
  function fmt_date(?string $iso): string {
    if (!$iso) return '';
    $ts = strtotime($iso);
    return $ts ? date('Y-m-d', $ts) : $iso;
  }
}
if (!function_exists('snip')) {
  function snip(string $s, int $limit=160, string $ellipsis='…'): string {
    if ($limit <= 0) return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      return (mb_strlen($s,'UTF-8') > $limit) ? mb_substr($s,0,$limit,'UTF-8').$ellipsis : $s;
    }
    return (strlen($s) > $limit) ? substr($s,0,$limit).$ellipsis : $s;
  }
}
