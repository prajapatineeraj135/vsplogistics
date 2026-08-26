<?php
session_start();
include __DIR__ . '/db.php';
include __DIR__ . '/session_manager.php';

// Record device logout
recordDeviceLogout($conn);
destroyCurrentSession();

// Redirect to admin login page
header("Location: ../login");
exit;
