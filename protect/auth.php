<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/session_manager.php';

// Allow either company or admin sessions on shared application pages.
if (empty($_SESSION['company_id']) && empty($_SESSION['admin_login'])) {
    header("Location: ../login");
    exit;
}

if (!empty($_SESSION['company_id']) && (($_SESSION['login_role'] ?? null) === 'company')) {
    if (!validateDeviceSession('company', $conn)) {
        session_destroy();
        header("Location: ../login");
        exit;
    }
}
?>
