<?php
// /app/cron/core.php — Chaos-Cron core (flat-file, duplicate-safe)
// No deps. Uses DATA_PATH if defined; else /data under docroot.

declare(strict_types=1);

/* ---------- paths ---------- */
function chaos_cron_docroot(): string {
  return rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 1), '/\\');
}
function chaos_cron_data_dir(): string {
  $base = defined('DATA_PATH') ? DATA_PATH : (chaos_cron_docroot() . '/data');
  $dir  = rtrim($base, '/\\') . '/cron';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (!is_dir($dir . '/leases')) @mkdir($dir . '/leases', 0775, true);
  return $dir;
}
function chaos_cron_state_file(): string { return chaos_cron_data_dir() . '/state.json'; }
function chaos_cron_tasks_file(): string { return chaos_cron_data_dir() . '/tasks.json'; }
function chaos_cron_lease_path(string $id): string {
  $safe = preg_replace('~[^a-z0-9\-_]~i', '_', $id);
  return chaos_cron_data_dir() . '/leases/' . $safe . '.lock';
}

/* ---------- tiny json helpers ---------- */
function chaos_cron_jread(string $file, $default = null) {
  if (!is_file($file) || !is_readable($file)) return $default;
  $raw = @file_get_contents($file);
  if (!is_string($raw) || $raw === '') return $default;
  if (substr($raw,0,3) === "\xEF\xBB\xBF") $raw = substr($raw,3);
  $j = json_decode($raw, true);
  return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : $default;
}
function chaos_cron_jwrite(string $file, $data): bool {
  $tmp = $file . '.tmp';
  $raw = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($raw === false) return false;
  if (@file_put_contents($tmp, $raw) === false) return false;
  return @rename($tmp, $file);
}

/* ---------- schedule helpers ---------- */
// Supported: "every N minutes|hours|days", "hourly", "daily@HH:MM", "weekly@DOW HH:MM" (DOW: mon..sun)
function chaos_cron_next_at(string $spec, ?int $fromTs = null): int {
  $from = $fromTs ?? time();
  $spec = trim(strtolower($spec));

  if (preg_match('~^every\s+(\d+)\s*(minute|minutes|hour|hours|day|days)$~', $spec, $m)) {
    $n = max(1, (int)$m[1]); $unit = $m[2];
    $delta = ($unit[0]==='m') ? $n*60 : (($unit[0]==='h') ? $n*3600 : $n*86400);
    return $from + $delta;
  }
  if ($spec === 'hourly') return $from + 3600;

  if (preg_match('~^daily@(\d{1,2}):(\d{2})$~', $spec, $m)) {
    $h = min(23, (int)$m[1]); $i = min(59, (int)$m[2]);
    $next = mktime($h, $i, 0, (int)date('n',$from), (int)date('j',$from), (int)date('Y',$from));
    if ($next <= $from) $next = strtotime('+1 day', $next);
    return $next;
  }
  if (preg_match('~^weekly@([a-z]{3})\s+(\d{1,2}):(\d{2})$~', $spec, $m)) {
    $map = ['sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6];
    $dow = $map[$m[1]] ?? 1; // default mon
    $h   = min(23, (int)$m[2]); $i = min(59, (int)$m[3]);
    $curDow = (int)date('w', $from);
    $days = ($dow - $curDow + 7) % 7;
    $next = mktime($h, $i, 0, (int)date('n',$from), (int)date('j',$from) + $days, (int)date('Y',$from));
    if ($next <= $from) $next = strtotime('+7 day', $next);
    return $next;
  }

  // Fallback: 1 hour
  return $from + 3600;
}

/* ---------- registry ---------- */
// id, handler (absolute or docroot-relative), schedule, enabled, timeout_sec, next_at, last_run_at, last_status
function chaos_cron_register(array $task): bool {
  $file = chaos_cron_tasks_file();
  $data = chaos_cron_jread($file, ['tasks'=>[]]);
  if (!is_array($data)) $data = ['tasks'=>[]];
  $id   = (string)($task['id'] ?? '');
  if ($id === '') return false;

  $found = -1;
  foreach ($data['tasks'] as $i=>$t) {
    if (isset($t['id']) && (string)$t['id'] === $id) { $found = $i; break; }
  }

  $defaults = [
    'id'          => $id,
    'handler'     => (string)($task['handler'] ?? ''),
    'schedule'    => (string)($task['schedule'] ?? 'hourly'),
    'enabled'     => array_key_exists('enabled', $task) ? (bool)$task['enabled'] : true,
    'timeout_sec' => (int)($task['timeout_sec'] ?? 120),
    'next_at'     => chaos_cron_next_at((string)($task['schedule'] ?? 'hourly')),
    'last_run_at' => null,
    'last_status' => null,
    'last_run_token' => null
  ];

  if ($found >= 0) {
    // merge, but keep next_at unless schedule changed
    $old = $data['tasks'][$found];
    $merged = $old;
    foreach ($defaults as $k=>$v) {
      if ($k === 'next_at' && isset($old['schedule']) && $old['schedule'] === $defaults['schedule']) continue;
      if ($k === 'id') continue;
      if (array_key_exists($k, $task)) $merged[$k] = $task[$k];
      else $merged[$k] = $merged[$k] ?? $v;
    }
    if ($old['schedule'] !== $defaults['schedule']) $merged['next_at'] = chaos_cron_next_at($defaults['schedule']);
    $data['tasks'][$found] = $merged;
  } else {
    $data['tasks'][] = $defaults;
  }

  return chaos_cron_jwrite($file, $data);
}

/* ---------- lease (duplicate prevention) ---------- */
function chaos_cron_try_lease(string $id, int $ttl = 120) {
  $path = chaos_cron_lease_path($id);
  $fp = @fopen($path, 'c+');
  if (!$fp) return [false, null];

  if (!@flock($fp, LOCK_EX | LOCK_NB)) { @fclose($fp); return [false, null]; }

  $until = time() + max(5, $ttl);
  ftruncate($fp, 0);
  fwrite($fp, json_encode(['lease_owner'=>getmypid(),'lease_until'=>$until]));
  fflush($fp);
  // caller must flock(UN) + fclose
  return [true, $fp];
}

/* ---------- run a single task ---------- */
function chaos_cron_run_task(array &$task): bool {
  $id   = (string)$task['id'];
  $ttl  = (int)($task['timeout_sec'] ?? 120);

  [$ok, $fp] = chaos_cron_try_lease($id, $ttl);
  if (!$ok) return false; // someone else is running it

  $token = bin2hex(random_bytes(8));
  $task['last_run_token'] = $token;

  $handler = (string)($task['handler'] ?? '');
  if ($handler === '') {
    $status = false;
  } else {
    // Resolve handler to absolute path
    $abs = $handler;
    if ($handler[0] !== '/' && (strpos($handler, ':\\') === false)) {
      $abs = chaos_cron_docroot() . '/' . ltrim($handler, '/');
    }
    $okInc = is_file($abs);
    $status = false;
    if ($okInc) {
      // Provide context
      $CHAOS_CRON = [
        'task_id'  => $id,
        'run_token'=> $token,
        'deadline' => time() + $ttl
      ];
      try {
        /** @noinspection PhpUnusedLocalVariableInspection */
        $status = (bool)(include $abs);
      } catch (\Throwable $e) {
        $status = false;
      }
    }
  }

  $task['last_run_at'] = gmdate('c');
  $task['last_status'] = $status ? 'ok' : 'fail';
  $task['next_at']     = chaos_cron_next_at((string)$task['schedule']);

  @flock($fp, LOCK_UN); @fclose($fp);
  return $status;
}

/* ---------- tick (called on page views) ---------- */
function chaos_cron_tick(array $opts = []): void {
  // throttle
  $tickMin = (int)($opts['min_interval_sec'] ?? 30);
  $stateF  = chaos_cron_state_file();
  $state   = chaos_cron_jread($stateF, ['last_tick'=>null]);
  $now     = time();
  $lt      = isset($state['last_tick']) ? (int)strtotime((string)$state['last_tick']) : 0;
  if (($now - $lt) < $tickMin) return;

  $state['last_tick'] = gmdate('c', $now);
  chaos_cron_jwrite($stateF, $state);

  // small jitter to spread load
  usleep(random_int(50_000, 300_000));

  // load tasks
  $file  = chaos_cron_tasks_file();
  $data  = chaos_cron_jread($file, ['tasks'=>[]]);
  $tasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];

  // due list
  $due = [];
  foreach ($tasks as $i => $t) {
    if (!($t['enabled'] ?? true)) continue;
    $nextAt = (int)($t['next_at'] ?? 0);
    if ($nextAt === 0) $nextAt = time();
    if ($nextAt <= $now) $due[] = $i;
  }

  if (!$due) return;

  // limit concurrency
  $maxRun = (int)($opts['max_concurrent'] ?? 2);
  $runIdx = array_slice($due, 0, max(1, $maxRun));
  $changed = false;

  foreach ($runIdx as $i) {
    $changed = true;
    chaos_cron_run_task($tasks[$i]); // updates task fields in-memory
  }

  if ($changed) chaos_cron_jwrite($file, ['tasks'=>$tasks]);
}
