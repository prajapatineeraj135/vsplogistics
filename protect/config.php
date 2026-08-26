<?php
/**
 * Global configuration for the Company application.
 *
 * Define environment‑dependent constants here.  During development you can
 * simply change BASE_URL to whatever the local URL is (eg. http://localhost/vsplogistics)
 * and on the live server set it to the Hostinger domain.  The helper
 * `base_url()` makes it easy to append paths.
 *
 * This file is automatically included by protect/db.php, so any script that
 * includes the database connection will also have access to these settings.
 */

if (!defined('BASE_URL')) {
    // determine base URL automatically if possible
    if (isset($_SERVER['HTTP_HOST'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base_url = $proto . '://' . $_SERVER['HTTP_HOST'];
        // append subdirectory for localhost
        if (strpos($base_url, 'localhost') !== false) {
            $base_url .= '/vsplogistics';
        }
        define('BASE_URL', $base_url);
    } else {
        // fallback for CLI or when HTTP_HOST unavailable
        define('BASE_URL', 'http://localhost/vsplogistics');
    }
    // you can override BASE_URL manually above if you need a fixed value
}

if (!defined('APP_DEBUG')) {
    // Keep technical details visible on localhost, hide them on production.
    define('APP_DEBUG', strpos(BASE_URL, 'localhost') !== false);
}

/**
 * Build a complete URL based on BASE_URL and an optional path fragment.
 *
 * @param string $path  URI segment to append (leading/trailing slashes
 *                      are handled automatically).
 * @return string
 */
function base_url($path = '') {
    $url = BASE_URL;
    if ($path !== '') {
        $url = rtrim($url, '/') . '/' . ltrim($path, '/');
    }
    return $url;
}

if (file_exists(__DIR__ . '/error_handler.php')) {
    // Register centralized error handling for all modules.
    require_once __DIR__ . '/error_handler.php';
}
