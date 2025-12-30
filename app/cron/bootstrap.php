<?php
// /app/cron/bootstrap.php
declare(strict_types=1);

// Load the Chaos Cron engine
require_once __DIR__ . '/core.php';

// --- Register DevBot task (idempotent) ---
// This will ensure tasks.json always has a DevBot entry.
// Schedule can be changed later (hourly, daily@HH:MM, etc.).
chaos_cron_register([
    'id'          => 'devbot',
    'handler'     => 'devbot/cron/devbot_cron.php', // relative to docroot
    'schedule'    => 'every 15 minutes',
    'enabled'     => true,
    'timeout_sec' => 120
]);

// Only tick cron on non-admin GET requests (tweak to your needs)
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET' && !str_starts_with($uri, '/admin')) {
    chaos_cron_tick([
        'min_interval_sec' => 30, // throttle ticks (seconds)
        'max_concurrent'   => 2,  // max tasks per tick
    ]);
}

