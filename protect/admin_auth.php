<?php
session_start();
include __DIR__ . '/db.php';
include __DIR__ . '/session_manager.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_login']) || (($_SESSION['login_role'] ?? null) !== 'admin')) {
    header("Location: ../login/admin");
    exit;
}

// Validate device session (ensure company user didn't log in on same device)
if (!validateDeviceSession('admin', $conn)) {
    session_destroy();
    header("Location: ../login/admin");
    exit;
}
?>
