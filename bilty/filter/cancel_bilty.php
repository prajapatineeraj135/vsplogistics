<?php
/**
 * Cancel Bilty Handler
 * Marks a bilty as cancelled after password confirmation.
 */

header('Content-Type: application/json; charset=utf-8');
include "../../protect/auth.php";
include "../../protect/db.php";

$company_id = $_SESSION['company_id'] ?? '';
$biltyId = (int) ($_POST['id'] ?? 0);
$password = (string) ($_POST['password'] ?? '');

if ($company_id === '') {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit;
}

if ($biltyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid bilty ID']);
    exit;
}

if ($password !== '1234') {
    echo json_encode(['success' => false, 'message' => 'Invalid cancel password']);
    exit;
}

function ensureCancelStatusAllowed(mysqli $conn): bool
{
    $result = $conn->query("SHOW COLUMNS FROM biltys LIKE 'status'");
    if (!$result) {
        return false;
    }

    $column = $result->fetch_assoc();
    if (!$column) {
        return false;
    }

    $type = (string) ($column['Type'] ?? '');
    if (stripos($type, "'Cancel'") !== false || stripos($type, '"Cancel"') !== false) {
        return true;
    }

    if (stripos($type, 'enum(') !== 0) {
        return true;
    }

    $rawValues = substr($type, 5, -1);
    $values = str_getcsv($rawValues, ',', "'");
    $values[] = 'Cancel';
    $values = array_values(array_unique(array_filter($values, static function ($value) {
        return trim((string) $value) !== '';
    })));

    $enumValues = array_map(static function ($value) use ($conn) {
        return "'" . $conn->real_escape_string((string) $value) . "'";
    }, $values);

    $nullSql = (($column['Null'] ?? '') === 'NO') ? ' NOT NULL' : '';
    $default = (string) ($column['Default'] ?? '');
    $defaultSql = $default !== '' ? " DEFAULT '" . $conn->real_escape_string($default) . "'" : '';

    return (bool) $conn->query("ALTER TABLE biltys MODIFY status ENUM(" . implode(',', $enumValues) . "){$nullSql}{$defaultSql}");
}

if (!ensureCancelStatusAllowed($conn)) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare cancel status']);
    exit;
}

$sql = "UPDATE biltys
        SET status = 'Cancel',
            remark = 'Cancel by party',
            challan_id = NULL,
            updated_at = NOW()
        WHERE id = ?
          AND company_id = ?
          AND status IN ('Booked', 'Dispatch', 'Deliver')";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare cancel query']);
    exit;
}

$stmt->bind_param('is', $biltyId, $company_id);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to cancel bilty']);
    exit;
}

if ($affected <= 0) {
    echo json_encode(['success' => false, 'message' => 'No eligible bilty found for cancel']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Bilty cancelled successfully']);
