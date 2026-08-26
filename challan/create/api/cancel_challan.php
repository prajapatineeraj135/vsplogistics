<?php
include "../../../protect/auth.php";
include_once __DIR__ . "/inword_dispatch_helpers.php";

header('Content-Type: application/json; charset=utf-8');

$company_id = $_SESSION['company_id'] ?? '';
if ($company_id === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Company session not found']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$challan_id = (int)($payload['challan_id'] ?? 0);
ensureInwordDispatchColumns($conn);

if ($challan_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid challan ID']);
    exit;
}

// Verify this challan belongs to the company
$checkStmt = $conn->prepare("SELECT id FROM challans WHERE id = ? AND company_id = ? LIMIT 1");
if (!$checkStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to validate challan']);
    exit;
}
$checkStmt->bind_param('is', $challan_id, $company_id);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$existing) {
    echo json_encode(['success' => false, 'message' => 'Challan not found']);
    exit;
}

$conn->begin_transaction();

try {
    // Reset all linked bilty back to Booked without deleting the challan
    $resetStmt = $conn->prepare(
        "UPDATE biltys SET challan_id = NULL, status = 'Booked'
         WHERE company_id = ? AND challan_id = ? AND LOWER(status) IN ('dispatch', 'dispatched')"
    );
    if (!$resetStmt) {
        throw new Exception('Failed to prepare bilty reset query');
    }
    $resetStmt->bind_param('si', $company_id, $challan_id);
    if (!$resetStmt->execute()) {
        throw new Exception('Failed to reset linked bilty: ' . $resetStmt->error);
    }
    $resetStmt->close();

    $resetInwordStmt = $conn->prepare(
        "UPDATE inword_biltys SET challan_id = NULL, status = 'Booked'
         WHERE company_id = ? AND challan_id = ? AND LOWER(status) IN ('dispatch', 'dispatched')"
    );
    if (!$resetInwordStmt) {
        throw new Exception('Failed to prepare inword bilty reset query');
    }
    $resetInwordStmt->bind_param('si', $company_id, $challan_id);
    if (!$resetInwordStmt->execute()) {
        throw new Exception('Failed to reset linked inword bilty: ' . $resetInwordStmt->error);
    }
    $resetInwordStmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message'  => 'Challan booking cancelled. Bilty returned to Booked.'
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
