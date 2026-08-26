<?php
/**
 * Centralized error handling for web + API requests.
 */

if (defined('APP_ERROR_HANDLER_REGISTERED')) {
    return;
}
define('APP_ERROR_HANDLER_REGISTERED', true);

// Prevent redirect loops when the current request is already on the error page.
function app_is_error_page_request()
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return strpos($uri, '/error') !== false;
}

// Detect API/AJAX calls to return JSON instead of HTML redirects.
function app_is_api_request()
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return (
        strpos($uri, '/api/') !== false ||
        isset($_GET['ajax']) ||
        stripos($accept, 'application/json') !== false ||
        strtolower($xhr) === 'xmlhttprequest'
    );
}

// Map errors to broad categories for clearer reporting in the UI.
function app_detect_error_category($message = '', $uri = '')
{
    $text = strtolower((string) $message . ' ' . (string) $uri);

    if (strpos($text, '/import') !== false || strpos($text, 'import') !== false) {
        return 'import';
    }

    if (strpos($text, '/export') !== false || strpos($text, 'export') !== false) {
        return 'export';
    }

    if (
        strpos($text, 'mysqli') !== false ||
        strpos($text, 'sql') !== false ||
        strpos($text, 'database') !== false ||
        strpos($text, 'db') !== false
    ) {
        return 'database';
    }

    if (
        strpos($text, 'warning') !== false ||
        strpos($text, 'notice') !== false ||
        strpos($text, 'deprecated') !== false
    ) {
        return 'web';
    }

    return 'server';
}

// Resolve the central error page URL with BASE_URL support.
function app_error_redirect_url()
{
    if (function_exists('base_url')) {
        return base_url('error');
    }

    return '/company/error';
}

// Build a normalized payload so all errors share one structure.
function app_build_error_payload($title, $detail, $category = 'server', $statusCode = 500, $extra = [])
{
    return [
        'title' => (string) $title,
        'detail' => (string) $detail,
        'category' => (string) $category,
        'status_code' => (int) $statusCode,
        'time' => date('Y-m-d H:i:s'),
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'file' => $extra['file'] ?? '',
        'line' => $extra['line'] ?? '',
    ];
}

// API responses use JSON payloads for frontend consumption.
function app_render_api_error($payload)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code((int) ($payload['status_code'] ?? 500));
    }

    $response = [
        'success' => false,
        'category' => $payload['category'] ?? 'server',
        'message' => $payload['title'] ?? 'Unexpected error',
    ];

    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['detail'] = $payload['detail'] ?? '';
        $response['file'] = $payload['file'] ?? '';
        $response['line'] = $payload['line'] ?? '';
    }

    echo json_encode($response);
    exit;
}

// Web responses store details in session and redirect to /error.
function app_redirect_to_error_page($payload)
{
    if (app_is_api_request()) {
        app_render_api_error($payload);
    }

    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['central_error'] = $payload;
    }

    if (!headers_sent()) {
        http_response_code((int) ($payload['status_code'] ?? 500));
        header('Location: ' . app_error_redirect_url());
    } else {
        echo 'Application error occurred. Please open: ' . htmlspecialchars(app_error_redirect_url(), ENT_QUOTES, 'UTF-8');
    }

    exit;
}

// Main helper used by feature modules to report expected failures.
function app_report_error($title, $detail = '', $category = 'server', $statusCode = 500, $extra = [])
{
    if (app_is_error_page_request()) {
        if (!headers_sent()) {
            http_response_code((int) $statusCode);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo $title . "\n";
        if ($detail !== '') {
            echo $detail;
        }
        exit;
    }

    $resolvedCategory = $category ?: app_detect_error_category($detail, $_SERVER['REQUEST_URI'] ?? '');
    $payload = app_build_error_payload($title, $detail, $resolvedCategory, $statusCode, $extra);
    app_redirect_to_error_page($payload);
}

// Catch all uncaught exceptions and route them centrally.
set_exception_handler(function ($exception) {
    $message = $exception->getMessage();
    $category = app_detect_error_category($message, $_SERVER['REQUEST_URI'] ?? '');

    app_report_error(
        'Unhandled exception',
        $message,
        $category,
        500,
        [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]
    );
});

// Convert PHP warnings/notices into exceptions for unified handling.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Capture fatal shutdown errors that bypass normal try/catch blocks.
register_shutdown_function(function () {
    $fatal = error_get_last();
    if ($fatal === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($fatal['type'], $fatalTypes, true)) {
        return;
    }

    $detail = $fatal['message'] ?? 'Fatal error';
    $category = app_detect_error_category($detail, $_SERVER['REQUEST_URI'] ?? '');

    app_report_error(
        'Fatal application error',
        $detail,
        $category,
        500,
        [
            'file' => $fatal['file'] ?? '',
            'line' => $fatal['line'] ?? '',
        ]
    );
});
