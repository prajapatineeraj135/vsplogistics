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

$required = [
    'Challan No.' => $challan_no,
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

$checkStmt = $conn->prepare("SELECT id FROM challans WHERE company_id = ? AND challan_no = ? LIMIT 1");
if (!$checkStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to validate challan number'
    ]);
    exit;
}
$checkStmt->bind_param('ss', $company_id, $challan_no);
$checkStmt->execute();
$duplicate = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

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

    $saveStmt = null;

    $saveStmt = $conn->prepare("INSERT INTO challans (company_id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($saveStmt) {
        $saveStmt->bind_param(
            'sssssssssdddddd',
            $company_id,
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
            $final_total
        );
    } else {
        $saveStmt = $conn->prepare("INSERT INTO challans (company_id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, owner_name, contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($saveStmt) {
            $saveStmt->bind_param(
                'sssssssssdddddd',
                $company_id,
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
                $final_total
            );
        } else {
            $saveStmt = $conn->prepare("INSERT INTO challans (company_id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($saveStmt) {
                $saveStmt->bind_param(
                    'sssssssdddddd',
                    $company_id,
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
                    $final_total
                );
            } else {
                $saveStmt = $conn->prepare("INSERT INTO challans (company_id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($saveStmt) {
                    $saveStmt->bind_param(
                        'sssssss',
                        $company_id,
                        $challan_no,
                        $challan_date,
                        $challan_station,
                        $vehicle_no,
                        $driver_name,
                        $driver_contact_value
                    );
                }
            }
        }
    }

    if (!$saveStmt) {
        throw new Exception('Failed to prepare challan save query');
    }

    if (!$saveStmt->execute()) {
        throw new Exception('Failed to save challan: ' . $saveStmt->error);
    }

    $challan_id = (int)$saveStmt->insert_id;
    $saveStmt->close();

    $updatedNormal = 0;
    if (!empty($cleanIds)) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $linkSql = "UPDATE biltys SET challan_id = ?, status = 'Dispatch' WHERE company_id = ? AND status = 'Booked' AND id IN ($placeholders)";
        $linkStmt = $conn->prepare($linkSql);
        if (!$linkStmt) {
            throw new Exception('Failed to prepare bilty link query');
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
            throw new Exception('Failed to prepare inword bilty link query');
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
        'message' => 'Challan saved successfully'
    ]);
} catch (Throwable $exception) {
    $conn->rollback();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
}
