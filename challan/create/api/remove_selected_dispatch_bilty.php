<?php
include "../../../protect/auth.php";
include_once __DIR__ . "/inword_dispatch_helpers.php";

header('Content-Type: application/json; charset=utf-8');

$company_id = $_SESSION['company_id'] ?? '';

if ($company_id === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Company session not found'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

$ids = $payload['ids'] ?? $_POST['ids'] ?? [];
ensureInwordDispatchColumns($conn);

if (!is_array($ids) || count($ids) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'No bilty selected'
    ]);
    exit;
}

$splitIds = splitDispatchBiltyIds($ids);
$cleanIds = $splitIds['normal'];
$cleanInwordIds = $splitIds['inword'];

if (count($cleanIds) === 0 && count($cleanInwordIds) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid bilty IDs'
    ]);
    exit;
}

try {
    $updatedCount = updateDispatchRows($conn, 'biltys', 'id', $cleanIds, $company_id, "status = 'Booked', challan_id = NULL", "status = 'Dispatch'");
    $updatedCount += updateDispatchRows($conn, 'inword_biltys', 'id', $cleanInwordIds, $company_id, "status = 'Booked', challan_id = NULL", "status = 'Dispatch'");
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'updated_count' => (int) $updatedCount,
    'message' => $updatedCount > 0
        ? 'Selected dispatch bilty moved to booked successfully'
        : 'No dispatch bilty updated'
]);
