<?php
// /app/lib/plugins.php

if (!function_exists('chaos_load_plugins')) {
  function chaos_load_plugins(): void {
    $projectRoot = dirname(__DIR__, 2); // /app -> project root
    $pluginsDir  = $projectRoot . '/public/plugins';

    $dataDir = defined('DATA_PATH')
      ? rtrim(DATA_PATH,'/\\')
      : ($projectRoot . '/data');

    $registry = $dataDir . '/plugins.json';

    $enabled = [];
    if (is_file($registry)) {
      $j = json_decode(@file_get_contents($registry), true);
      if (is_array($j) && is_array($j['enabled'] ?? null)) $enabled = $j['enabled'];
    }

    foreach ($enabled as $slug) {
      $base = $pluginsDir . '/' . $slug;
      if (!is_dir($base)) { error_log("[plugins] missing dir: {$base}"); continue; }

      $entry = 'hook.php';
      $mfile = $base . '/plugin.json';
      if (is_file($mfile)) {
        $m = json_decode(@file_get_contents($mfile), true);
        if (is_array($m) && !empty($m['entry'])) $entry = basename($m['entry']);
      }
      $entryPath = $base . '/' . $entry;
      if (is_file($entryPath)) {
        include_once $entryPath;
      } else {
        error_log("[plugins] entry not found: {$entryPath}");
      }
    }
  }
}

if (!function_exists('chaos_block')) {
  function chaos_block(string $name): void {
    $fn = $GLOBALS['chaos_hooks']['blocks'][$name] ?? null;
    if (is_callable($fn)) echo $fn();
  }
}
