<?php
/**
 * Chaos CMS Example Plugin (v1.0.0)
 *
 * Purpose:
 *  - Demonstrate CCR-compliant plugin structure without touching core.
 *  - Show lifecycle hooks: plugin_init, plugin_register_routes, plugin_shutdown.
 *  - Register minimal routes (admin + API) using the published router.
 *  - Use /app/lib utilities via safe helpers.
 *
 * Compatibility:
 *  - Chaos CMS >= 1.2.5
 *
 * Notes:
 *  - Keep all logic self-contained; do not modify /app/core or /public/index.php.
 *  - Namespacing avoided for simplicity—function names are prefixed to prevent collisions.
 *  - If a helper function is unavailable, this plugin degrades gracefully.
 */

// -----------------------------------------------------------------------------
// Guard: prevent direct web access to this file
// -----------------------------------------------------------------------------
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// -----------------------------------------------------------------------------
// Lightweight helpers (wrap CMS facilities if present)
// -----------------------------------------------------------------------------

/**
 * Log a message to Chaos CMS log if available; otherwise noop.
 */
function example_log(string $msg, array $ctx = []): void {
    if (function_exists('chaos_log')) {
        chaos_log('[example-plugin] ' . $msg, $ctx);
        return;
    }
    // Fallback: error_log for dev environments
    @error_log('[example-plugin] ' . $msg . (!empty($ctx) ? ' ' . json_encode($ctx) : ''));
}

/**
 * Access a library from /app/lib via the CMS helper if available.
 * e.g., $mailer = example_lib('mailer');
 */
function example_lib(string $name) {
    if (function_exists('chaos_lib')) {
        return chaos_lib($name);
    }
    return null; // graceful fallback
}

/**
 * Register a route using the CMS router helper if available; otherwise try globals.
 */
function example_route(string $method, string $path, callable $handler): void {
    if (function_exists('chaos_register_route')) {
        chaos_register_route($method, $path, $handler);
        return;
    }

    // Fallback: try a globally exposed router (non-core environments)
    if (isset($GLOBALS['CHAOS_ROUTER']) && method_exists($GLOBALS['CHAOS_ROUTER'], 'add')) {
        $GLOBALS['CHAOS_ROUTER']->add($method, $path, $handler);
        return;
    }

    example_log('Router not available; route not registered', compact('method','path'));
}

// -----------------------------------------------------------------------------
// Lifecycle Hooks (called by Chaos CMS if declared)
// -----------------------------------------------------------------------------

/**
 * Called during system bootstrap.
 * Set up any state your plugin needs, but avoid long-running work here.
 */
function plugin_init(): void {
    // Example: ensure a config default exists (read-only here; prefer a /data JSON file)
    // if (function_exists('chaos_config_get') && function_exists('chaos_config_set')) {
    //     $enabled = chaos_config_get('plugins.example.enabled', true);
    //     chaos_config_set('plugins.example.enabled', (bool)$enabled);
    // }

    example_log('plugin_init');
}

/**
 * Called when routes are mapped.
 * Register admin and API routes here—no direct output, just handlers.
 */
function plugin_register_routes(): void {
    example_log('plugin_register_routes');

    // Admin page (GET): simple status view in a sandboxed admin route
    example_route('GET', '/admin/plugins/example', function () {
        // Minimal, framework-friendly response
        $status = [
            'plugin'   => 'Chaos CMS Example Plugin',
            'version'  => '1.0.0',
            'lib.mail' => example_lib('mailer') ? 'available' : 'unavailable',
            'time'     => gmdate('c'),
        ];

        // If the CMS provides a response helper, use it; otherwise emit JSON.
        if (function_exists('chaos_json')) {
            return chaos_json($status, 200);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($status);
        return null;
    });

    // API endpoint (POST): a tiny echo/ping with validation
    example_route('POST', '/api/example/ping', function () {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $err = ['error' => 'Invalid JSON', 'detail' => json_last_error_msg()];
            if (function_exists('chaos_json')) {
                return chaos_json($err, 400);
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode($err);
            return null;
        }

        // Example use of /app/lib: send a mail or enqueue a job (if available)
        if (!empty($data['notify']) && ($mailer = example_lib('mailer'))) {
            try {
                // $mailer->send([...]); // pseudo-code; depends on your mailer API
                example_log('mailer notify requested', ['to' => $data['notify']]);
            } catch (\Throwable $e) {
                example_log('mailer error', ['error' => $e->getMessage()]);
            }
        }

        $resp = [
            'ok'      => true,
            'echo'    => $data,
            'ts'      => gmdate('c'),
            'plugin'  => 'example',
            'version' => '1.0.0'
        ];

        if (function_exists('chaos_json')) {
            return chaos_json($resp, 200);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resp);
        return null;
    });
}

/**
 * Called before system shutdown or cache flush.
 * Close resources, flush buffers, etc.
 */
function plugin_shutdown(): void {
    example_log('plugin_shutdown');
}

// -----------------------------------------------------------------------------
// End of plugin.php
// -----------------------------------------------------------------------------

