<?php
/**
 * Bilty loader for edit mode
 * Returns bilty header + items as JSON for a given bilty id or GR number
 */

header('Content-Type: application/json');

include '../../../protect/auth.php';
include '../../../protect/db.php';
include '../includes/util.php';

ensureBiltyItemRateBasisColumn($conn);

// Ensure session for company context
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$companyId = isset($_SESSION['company_id']) ? intval($_SESSION['company_id']) : 102;
$biltyId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$grNumber = isset($_GET['gr']) ? trim($_GET['gr']) : '';

if ($biltyId <= 0 && $grNumber === '') {
    sendJsonResponse(false, [], 'Bilty id or GR number is required');
}

try {
    if ($biltyId > 0) {
        $stmt = $conn->prepare('SELECT * FROM biltys WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $biltyId, $companyId);
    } else {
        $stmt = $conn->prepare('SELECT * FROM biltys WHERE gr_number = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('si', $grNumber, $companyId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        sendJsonResponse(false, [], 'Bilty not found');
    }

    $bilty = $result->fetch_assoc();
    $stmt->close();

    // Fetch items
    $itemsStmt = $conn->prepare('SELECT item_number, item_name, quantity, rate, weight, rate_basis FROM bilty_items WHERE bilty_id = ? ORDER BY COALESCE(item_number, 0) ASC, id ASC');
    $itemsStmt->bind_param('i', $bilty['id']);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
    $itemsStmt->close();

    // Add a formatted date for UI
    if (!empty($bilty['bilty_date'])) {
        $bilty['bilty_date_formatted'] = date('d-m-Y', strtotime($bilty['bilty_date']));
    } elseif (!empty($bilty['created_at'])) {
        $bilty['bilty_date_formatted'] = date('d-m-Y', strtotime($bilty['created_at']));
    } else {
        $bilty['bilty_date_formatted'] = '';
    }

    // Return structured response with success flag
    echo json_encode([
        'success' => true,
        'bilty' => $bilty,
        'items' => $items,
        'message' => 'Bilty loaded successfully'
    ]);
    exit;

} catch (Exception $e) {
    error_log('Load bilty error: ' . $e->getMessage());
    sendJsonResponse(false, [], 'Error loading bilty: ' . $e->getMessage());
}

function ensureBiltyItemRateBasisColumn($conn) {
    $check = $conn->query("SHOW COLUMNS FROM bilty_items LIKE 'rate_basis'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    if (!$conn->query("ALTER TABLE bilty_items ADD COLUMN rate_basis VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight")) {
        throw new Exception('Could not add bilty_items.rate_basis column: ' . $conn->error);
    }
}

$conn->close();
?>
