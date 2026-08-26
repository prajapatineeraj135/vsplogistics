<?php
/**
 * Database Connection
 * Establishes MySQLi connection to nmtc135 database
 */

// load global configuration (defines BASE_URL, helpers, etc.)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Set database credentials based on environment
if (strpos(BASE_URL, 'localhost') !== false) {
    // Local development
    $host = "localhost";
    $user = "root";
    $pass = "";
    $db = "nmtc135";
} else {
    // Live/Hostinger
    $host = "localhost";
    $user = "u448438938_nmtc";
    $pass = "Nmtc@135@135";
    $db = "u448438938_nmtc";
}

$conn = new mysqli($host, $user, $pass);

// Check server connection first.
if ($conn->connect_error) {
    if (function_exists('app_report_error')) {
        app_report_error('Database server connection failed', $conn->connect_error, 'database', 500);
    }
    die('Database server connection failed');
}

// Ensure database exists, then select it.
$dbEscaped = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$db);
if ($dbEscaped === '') {
    if (function_exists('app_report_error')) {
        app_report_error('Invalid database name', $db, 'database', 500);
    }
    die('Invalid database name');
}

if (!$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbEscaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
    if (function_exists('app_report_error')) {
        app_report_error('Database creation failed', $conn->error, 'database', 500);
    }
    die('Database creation failed');
}

if (!$conn->select_db($dbEscaped)) {
    if (function_exists('app_report_error')) {
        app_report_error('Database selection failed', $conn->error, 'database', 500);
    }
    die('Database selection failed');
}

// Set charset to UTF-8
if (!$conn->set_charset("utf8mb4") && function_exists('app_report_error')) {
    // Charset mismatch can cause data corruption; treat as critical DB issue.
    app_report_error('Database charset setup failed', $conn->error, 'database', 500);
}
?>
