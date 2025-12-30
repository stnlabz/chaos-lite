<?php
// /app/lib/security.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate (and store) a CSRF token for this session.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted token matches.
 */
function csrf_ok(string $token): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Quick check for POST request.
 */
function is_post(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

// --- Compatibility shim ---
if (!function_exists('e') && function_exists('escape_html')) {
    // new helpers expect e(), older code used escape_html()
    function e(string $s): string { return escape_html($s); }
}

if (!function_exists('escape_html') && function_exists('e')) {
    // fallback if the inverse happens
    function escape_html(string $s): string { return e($s); }
}

// if neither exists (edge case), define both cleanly
if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('escape_html')) {
    function escape_html(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
