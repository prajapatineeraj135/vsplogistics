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
if (!is_array($payload)) {
    $payload = $_POST;
}

$challan_id = (int)($payload['challan_id'] ?? 0);
$challan_no = trim((string)($payload['challan_no'] ?? ''));
$challan_date = trim((string)($payload['challan_date'] ?? ''));
$challan_station = trim((string)($payload['challan_station'] ?? ''));
$vehicle_no = trim((string)($payload['vehicle_no'] ?? ''));
$driver_name = trim((string)($payload['driver_name'] ?? ''));
$driver_contact = trim((string)($payload['driver_contact'] ?? ''));
$agent_name = trim((string)($payload['agent_name'] ?? ''));
$agent_contact = trim((string)($payload['agent_contact'] ?? ''));
$paid_total = (int)round((float)($payload['paid_total'] ?? 0));
$freight_total = (int)round((float)($payload['freight_total'] ?? 0));
$recovery_total = (int)round((float)($payload['recovery_total'] ?? 0));
$cutting_total = (int)round((float)($payload['cutting_total'] ?? 0));
$commission_total = (int)round((float)($payload['commission_total'] ?? 0));
$final_total = (int)round((float)($payload['final_total'] ?? 0));
$bilty_ids = $payload['bilty_ids'] ?? [];
ensureInwordDispatchColumns($conn);

if ($challan_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid challan ID'
    ]);
    exit;
}

$required = [
    'Challan Date' => $challan_date,
    'Station' => $challan_station,
    'Vehicle' => $vehicle_no,
    'Driver Name' => $driver_name,
    'Driver Contact' => $driver_contact
];

$missingFields = [];
foreach ($required as $label => $value) {
    if ($value === '') {
        $missingFields[] = $label;
    }
}

if (!empty($missingFields)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ' . implode(', ', $missingFields)
    ]);
    exit;
}

if (!is_array($bilty_ids) || count($bilty_ids) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'No dispatch bilty selected'
    ]);
    exit;
}

$splitIds = splitDispatchBiltyIds($bilty_ids);
$cleanIds = $splitIds['normal'];
$cleanInwordIds = $splitIds['inword'];

if (count($cleanIds) === 0 && count($cleanInwordIds) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid bilty IDs'
    ]);
    exit;
}

$existsStmt = $conn->prepare("SELECT id, challan_no, challan_station FROM challans WHERE id = ? AND company_id = ? LIMIT 1");
if (!$existsStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to validate challan'
    ]);
    exit;
}
$existsStmt->bind_param('is', $challan_id, $company_id);
$existsStmt->execute();
$existing = $existsStmt->get_result()->fetch_assoc();
$existsStmt->close();

if (!$existing) {
    echo json_encode([
        'success' => false,
        'message' => 'Challan not found'
    ]);
    exit;
}

$challan_no = trim((string)($existing['challan_no'] ?? $challan_no));
$challan_station = trim((string)($existing['challan_station'] ?? $challan_station));

$dupStmt = $conn->prepare("SELECT id FROM challans WHERE company_id = ? AND challan_no = ? AND id <> ? LIMIT 1");
if (!$dupStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to validate challan number'
    ]);
    exit;
}
$dupStmt->bind_param('ssi', $company_id, $challan_no, $challan_id);
$dupStmt->execute();
$duplicate = $dupStmt->get_result()->fetch_assoc();
$dupStmt->close();

if ($duplicate) {
    echo json_encode([
        'success' => false,
        'message' => 'Challan number already exists'
    ]);
    exit;
}

$conn->begin_transaction();

try {
    $driver_contact_value = $driver_contact !== '' ? $driver_contact : '-';

    $updateStmt = $conn->prepare("UPDATE challans SET challan_no = ?, challan_date = ?, challan_station = ?, vehicle_no = ?, driver_name = ?, driver_contact = ?, agent_name = ?, agent_contact = ?, paid_total = ?, freight_total = ?, recovery_total = ?, cutting_total = ?, commission_total = ?, final_total = ? WHERE id = ? AND company_id = ?");
    if ($updateStmt) {
        $updateStmt->bind_param(
            'ssssssssddddddis',
            $challan_no,
            $challan_date,
            $challan_station,
            $vehicle_no,
            $driver_name,
            $driver_contact_value,
            $agent_name,
            $agent_contact,
            $paid_total,
            $freight_total,
            $recovery_total,
            $cutting_total,
            $commission_total,
            $final_total,
            $challan_id,
            $company_id
        );
    } else {
        $updateStmt = $conn->prepare("UPDATE challans SET challan_no = ?, challan_date = ?, challan_station = ?, vehicle_no = ?, driver_name = ?, driver_contact = ?, owner_name = ?, contact = ?, paid_total = ?, freight_total = ?, recovery_total = ?, cutting_total = ?, commission_total = ?, final_total = ? WHERE id = ? AND company_id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param(
                'ssssssssddddddis',
                $challan_no,
                $challan_date,
                $challan_station,
                $vehicle_no,
                $driver_name,
                $driver_contact_value,
                $agent_name,
                $agent_contact,
                $paid_total,
                $freight_total,
                $recovery_total,
                $cutting_total,
                $commission_total,
                $final_total,
                $challan_id,
                $company_id
            );
        } else {
            $updateStmt = $conn->prepare("UPDATE challans SET challan_no = ?, challan_date = ?, challan_station = ?, vehicle_no = ?, driver_name = ?, driver_contact = ?, paid_total = ?, freight_total = ?, recovery_total = ?, cutting_total = ?, commission_total = ?, final_total = ? WHERE id = ? AND company_id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param(
                    'ssssssddddddis',
                    $challan_no,
                    $challan_date,
                    $challan_station,
                    $vehicle_no,
                    $driver_name,
                    $driver_contact_value,
                    $paid_total,
                    $freight_total,
                    $recovery_total,
                    $cutting_total,
                    $commission_total,
                    $final_total,
                    $challan_id,
                    $company_id
                );
            } else {
                $updateStmt = $conn->prepare("UPDATE challans SET challan_no = ?, challan_date = ?, challan_station = ?, vehicle_no = ?, driver_name = ?, driver_contact = ? WHERE id = ? AND company_id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param(
                        'ssssssis',
                        $challan_no,
                        $challan_date,
                        $challan_station,
                        $vehicle_no,
                        $driver_name,
                        $driver_contact_value,
                        $challan_id,
                        $company_id
                    );
                }
            }
        }
    }

    if (!$updateStmt) {
        throw new Exception('Failed to prepare challan update query');
    }

    if (!$updateStmt->execute()) {
        throw new Exception('Failed to update challan: ' . $updateStmt->error);
    }
    $updateStmt->close();

    $resetStmt = $conn->prepare("UPDATE biltys SET challan_id = NULL, status = 'Booked' WHERE company_id = ? AND challan_id = ? AND status <> 'Cancel'");
    if (!$resetStmt) {
        throw new Exception('Failed to prepare bilty reset query');
    }
    $resetStmt->bind_param('si', $company_id, $challan_id);
    if (!$resetStmt->execute()) {
        throw new Exception('Failed to reset previous challan bilty: ' . $resetStmt->error);
    }
    $resetStmt->close();

    $resetInwordStmt = $conn->prepare("UPDATE inword_biltys SET challan_id = NULL, status = 'Booked' WHERE company_id = ? AND challan_id = ? AND status <> 'Trash'");
    if (!$resetInwordStmt) {
        throw new Exception('Failed to prepare inword bilty reset query');
    }
    $resetInwordStmt->bind_param('si', $company_id, $challan_id);
    if (!$resetInwordStmt->execute()) {
        throw new Exception('Failed to reset previous inword challan bilty: ' . $resetInwordStmt->error);
    }
    $resetInwordStmt->close();

    $updatedNormal = 0;
    if (!empty($cleanIds)) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $linkSql = "UPDATE biltys SET challan_id = ?, status = 'Dispatch' WHERE company_id = ? AND status = 'Booked' AND id IN ($placeholders)";
        $linkStmt = $conn->prepare($linkSql);
        if (!$linkStmt) {
            throw new Exception('Failed to prepare bilty relink query');
        }

        $types = 'is' . str_repeat('i', count($cleanIds));
        $params = [$challan_id, $company_id];
        foreach ($cleanIds as $id) {
            $params[] = $id;
        }

        $linkStmt->bind_param($types, ...$params);
        if (!$linkStmt->execute()) {
            throw new Exception('Failed to update bilty records: ' . $linkStmt->error);
        }
        $updatedNormal = (int)$linkStmt->affected_rows;
        $linkStmt->close();
    }

    $updatedInword = 0;
    if (!empty($cleanInwordIds)) {
        $placeholders = implode(',', array_fill(0, count($cleanInwordIds), '?'));
        $linkSql = "UPDATE inword_biltys SET challan_id = ?, status = 'Dispatch' WHERE company_id = ? AND status = 'Booked' AND id IN ($placeholders)";
        $linkStmt = $conn->prepare($linkSql);
        if (!$linkStmt) {
            throw new Exception('Failed to prepare inword bilty relink query');
        }

        $types = 'is' . str_repeat('i', count($cleanInwordIds));
        $params = [$challan_id, $company_id];
        foreach ($cleanInwordIds as $id) {
            $params[] = $id;
        }

        $linkStmt->bind_param($types, ...$params);
        if (!$linkStmt->execute()) {
            throw new Exception('Failed to update inword bilty records: ' . $linkStmt->error);
        }
        $updatedInword = (int)$linkStmt->affected_rows;
        $linkStmt->close();
    }

    if (($updatedNormal + $updatedInword) <= 0) {
        throw new Exception('No eligible booked bilty found for challan');
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'challan_id' => $challan_id,
        'message' => 'Challan updated successfully'
    ]);
} catch (Throwable $exception) {
    $conn->rollback();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
}
