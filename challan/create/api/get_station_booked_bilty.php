<?php
include "../../../protect/auth.php";
include_once __DIR__ . "/inword_dispatch_helpers.php";

header('Content-Type: application/json; charset=utf-8');

$company_id = $_SESSION['company_id'] ?? '';
$station = trim($_GET['station'] ?? '');

if ($company_id === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Company session not found'
    ]);
    exit;
}

if ($station === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Station is required'
    ]);
    exit;
}

ensureInwordDispatchColumns($conn);

$sql = "
    SELECT
        'bilty' AS source_type,
        b.id,
        b.gr_number,
        b.consignor_name,
        b.consignee_name,
        b.status,
        b.freight,
        b.p_freight,
        b.hammali,
        b.brokerage,
        b.dd_charge,
        b.gr_charge,
        b.total_charge,
        (COALESCE(b.hammali, 0) + COALESCE(b.brokerage, 0) + COALESCE(b.p_freight, 0) + COALESCE(b.brokerage, 0)) AS recovery_amount,
        b.payment_type,
        COALESCE(NULLIF(b.total_charge, 0), b.freight, 0) AS display_freight,
        COALESCE(items.content, '') AS content,
        COALESCE(NULLIF(b.total_qty, 0), items.item_count, 0) AS item_count,
        b.bilty_date AS sort_date,
        b.id AS sort_id
    FROM biltys b
    LEFT JOIN (
        SELECT
            bilty_id,
            GROUP_CONCAT(item_name ORDER BY id SEPARATOR ', ') AS content,
            SUM(COALESCE(item_number, quantity, 0)) AS item_count
        FROM bilty_items
        GROUP BY bilty_id
    ) AS items ON items.bilty_id = b.id
                WHERE b.company_id = ?
                    AND LOWER(TRIM(b.to_station)) = LOWER(?)
                AND b.status = 'Booked'
    UNION ALL
    SELECT
        'inword' AS source_type,
        b.id,
        COALESCE(NULLIF(b.inword_gr, ''), NULLIF(b.other_gr_no, ''), CONCAT('IN-', b.id)) AS gr_number,
        b.consignor_name,
        b.consignee_name,
        b.status,
        b.freight,
        0 AS p_freight,
        b.hammali,
        0 AS brokerage,
        b.dd_charge,
        b.gr_charge,
        b.total_charge,
        COALESCE(b.freight, 0) + COALESCE(b.hammali, 0) + COALESCE(b.dd_charge, 0) AS recovery_amount,
        b.payment_type,
        CASE
            WHEN COALESCE(b.dr_amount, 0) > 0 THEN COALESCE(b.dr_amount, 0)
            ELSE COALESCE(b.gr_charge, 0)
        END AS display_freight,
        COALESCE(items.content, '') AS content,
        COALESCE(NULLIF(b.total_qty, 0), items.item_count, 0) AS item_count,
        b.bilty_date AS sort_date,
        b.id AS sort_id
    FROM inword_biltys b
    LEFT JOIN (
        SELECT
            inword_bilty_id,
            GROUP_CONCAT(product_name ORDER BY id SEPARATOR ', ') AS content,
            SUM(COALESCE(quantity, 0)) AS item_count
        FROM inword_bilty_items
        GROUP BY inword_bilty_id
    ) AS items ON items.inword_bilty_id = b.id
    WHERE b.company_id = ?
        AND LOWER(TRIM(b.to_station)) = LOWER(?)
        AND b.status = 'Booked'
    ORDER BY gr_number ASC, consignor_name ASC, consignee_name ASC
    LIMIT 500
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare query'
    ]);
    exit;
}

$stmt->bind_param('ssss', $company_id, $station, $company_id, $station);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to execute query'
    ]);
    $stmt->close();
    exit;
}

$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'id' => (string)($row['source_type'] ?? 'bilty') . ':' . (int)($row['id'] ?? 0),
        'source_type' => (string)($row['source_type'] ?? 'bilty'),
        'source_id' => (int)($row['id'] ?? 0),
        'gr_number' => (string)($row['gr_number'] ?? ''),
        'consignor_name' => (string)($row['consignor_name'] ?? ''),
        'consignee_name' => (string)($row['consignee_name'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'content' => (string)($row['content'] ?? ''),
        'item_count' => (int) round((float)($row['item_count'] ?? 0)),
        'freight' => (int) round((float)($row['display_freight'] ?? 0)),
        'recovery' => (int) round((float)($row['recovery_amount'] ?? 0)),
        'payment_type' => (string)($row['payment_type'] ?? '')
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'rows' => $rows
]);
