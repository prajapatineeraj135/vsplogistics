<?php
include_once "../../protect/auth.php";
include_once __DIR__ . "/api/inword_dispatch_helpers.php";


$company_id = $_SESSION['company_id'] ?? '';
$editChallanId = (int)($_GET['challan_id'] ?? $_GET['edit_challan_id'] ?? 0);
$challanEditData = null;
$isEditMode = false;

ensureInwordDispatchColumns($conn);

if ($company_id !== '' && $editChallanId > 0) {
    $challanSqlCandidates = [
        "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
            "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, owner_name AS agent_name, contact AS agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
        "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
        "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
        "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, owner_name AS agent_name, contact AS agent_contact FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
        "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact FROM challans WHERE id = ? AND company_id = ? LIMIT 1"
    ];

    $stmtChallan = null;
    foreach ($challanSqlCandidates as $query) {
        $stmtChallan = $conn->prepare($query);
        if ($stmtChallan) {
            break;
        }
    }

    if ($stmtChallan) {
        $stmtChallan->bind_param('is', $editChallanId, $company_id);
        if ($stmtChallan->execute()) {
            $challanRow = $stmtChallan->get_result()->fetch_assoc();
            if ($challanRow) {
                $isEditMode = true;
                $challanEditData = [
                    'challan_id' => (int)($challanRow['id'] ?? 0),
                    'challan_no' => (string)($challanRow['challan_no'] ?? ''),
                    'challan_date' => (string)($challanRow['challan_date'] ?? ''),
                    'challan_station' => (string)($challanRow['challan_station'] ?? ''),
                    'vehicle_no' => (string)($challanRow['vehicle_no'] ?? ''),
                    'driver_name' => (string)($challanRow['driver_name'] ?? ''),
                    'driver_contact' => (string)($challanRow['driver_contact'] ?? ''),
                    'agent_name' => (string)($challanRow['agent_name'] ?? ''),
                    'agent_contact' => (string)($challanRow['agent_contact'] ?? ''),
                    'paid_total' => (int) round((float)($challanRow['paid_total'] ?? 0)),
                    'freight_total' => (int) round((float)($challanRow['freight_total'] ?? 0)),
                    'recovery_total' => (int) round((float)($challanRow['recovery_total'] ?? 0)),
                    'cutting_total' => (int) round((float)($challanRow['cutting_total'] ?? 0)),
                    'commission_total' => (int) round((float)($challanRow['commission_total'] ?? 0)),
                    'final_total' => (int) round((float)($challanRow['final_total'] ?? 0)),
                    'bilty_rows' => []
                ];

                $sqlBilty = "
                    SELECT
                        b.id,
                        b.gr_number,
                        b.consignor_name,
                        b.consignee_name,
                        b.status,
                        b.payment_type,
                        (COALESCE(b.hammali, 0) + COALESCE(b.brokerage, 0) + COALESCE(b.p_freight, 0) + COALESCE(b.brokerage, 0)) AS recovery_amount,
                        COALESCE(NULLIF(b.total_charge, 0), b.freight, 0) AS display_freight,
                        COALESCE(items.content, '') AS content,
                        COALESCE(NULLIF(b.total_qty, 0), items.item_count, 0) AS item_count
                    FROM biltys b
                    LEFT JOIN (
                        SELECT
                            bilty_id,
                            GROUP_CONCAT(item_name ORDER BY id SEPARATOR ', ') AS content,
                            SUM(COALESCE(item_number, quantity, 0)) AS item_count
                        FROM bilty_items
                        GROUP BY bilty_id
                    ) AS items ON items.bilty_id = b.id
                    WHERE b.company_id = ? AND b.challan_id = ? AND b.status <> 'Cancel'
                    ORDER BY b.gr_number ASC, b.consignor_name ASC, b.consignee_name ASC
                    LIMIT 500
                ";

                $stmtBilty = $conn->prepare($sqlBilty);
                if ($stmtBilty) {
                    $stmtBilty->bind_param('si', $company_id, $editChallanId);
                    if ($stmtBilty->execute()) {
                        $resultBilty = $stmtBilty->get_result();
                        while ($row = $resultBilty->fetch_assoc()) {
                            $challanEditData['bilty_rows'][] = [
                                'id' => 'bilty:' . (int)($row['id'] ?? 0),
                                'source_type' => 'bilty',
                                'source_id' => (int)($row['id'] ?? 0),
                                'gr_number' => (string)($row['gr_number'] ?? ''),
                                'consignor_name' => (string)($row['consignor_name'] ?? ''),
                                'consignee_name' => (string)($row['consignee_name'] ?? ''),
                                'status' => 'Dispatch',
                                'content' => (string)($row['content'] ?? ''),
                                'item_count' => (int) round((float)($row['item_count'] ?? 0)),
                                'freight' => (int) round((float)($row['display_freight'] ?? 0)),
                                'recovery' => (int) round((float)($row['recovery_amount'] ?? 0)),
                                'payment_type' => (string)($row['payment_type'] ?? '')
                            ];
                        }
                    }
                    $stmtBilty->close();
                }

                $sqlInwordBilty = "
                    SELECT
                        b.id,
                        COALESCE(NULLIF(b.inword_gr, ''), NULLIF(b.other_gr_no, ''), CONCAT('IN-', b.id)) AS gr_number,
                        b.consignor_name,
                        b.consignee_name,
                        b.status,
                        b.payment_type,
                        COALESCE(b.freight, 0) + COALESCE(b.hammali, 0) + COALESCE(b.dd_charge, 0) AS recovery_amount,
                        CASE
                            WHEN COALESCE(b.dr_amount, 0) > 0 THEN COALESCE(b.dr_amount, 0)
                            ELSE COALESCE(b.gr_charge, 0)
                        END AS display_freight,
                        COALESCE(items.content, '') AS content,
                        COALESCE(NULLIF(b.total_qty, 0), items.item_count, 0) AS item_count
                    FROM inword_biltys b
                    LEFT JOIN (
                        SELECT
                            inword_bilty_id,
                            GROUP_CONCAT(product_name ORDER BY id SEPARATOR ', ') AS content,
                            SUM(COALESCE(quantity, 0)) AS item_count
                        FROM inword_bilty_items
                        GROUP BY inword_bilty_id
                    ) AS items ON items.inword_bilty_id = b.id
                    WHERE b.company_id = ? AND b.challan_id = ? AND b.status <> 'Trash'
                    ORDER BY gr_number ASC, b.consignor_name ASC, b.consignee_name ASC
                    LIMIT 500
                ";

                $stmtInwordBilty = $conn->prepare($sqlInwordBilty);
                if ($stmtInwordBilty) {
                    $stmtInwordBilty->bind_param('si', $company_id, $editChallanId);
                    if ($stmtInwordBilty->execute()) {
                        $resultInwordBilty = $stmtInwordBilty->get_result();
                        while ($row = $resultInwordBilty->fetch_assoc()) {
                            $challanEditData['bilty_rows'][] = [
                                'id' => 'inword:' . (int)($row['id'] ?? 0),
                                'source_type' => 'inword',
                                'source_id' => (int)($row['id'] ?? 0),
                                'gr_number' => (string)($row['gr_number'] ?? ''),
                                'consignor_name' => (string)($row['consignor_name'] ?? ''),
                                'consignee_name' => (string)($row['consignee_name'] ?? ''),
                                'status' => 'Dispatch',
                                'content' => (string)($row['content'] ?? ''),
                                'item_count' => (int) round((float)($row['item_count'] ?? 0)),
                                'freight' => (int) round((float)($row['display_freight'] ?? 0)),
                                'recovery' => (int) round((float)($row['recovery_amount'] ?? 0)),
                                'payment_type' => (string)($row['payment_type'] ?? '')
                            ];
                        }
                    }
                    $stmtInwordBilty->close();
                }
            }
        }
        $stmtChallan->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Challan Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/challan_create.css">

</head>
<body>

    <?php include "../../content/nav.php"; ?>

    <div class="challan">
    <?php include "challan_header.php"; ?>
    <?php include "challan_bilty_body.php"; ?>
    <?php include "challan_calculation.php"; ?>

    <div class="button-area">
        <?php if ($isEditMode): ?>
            <button type="button" class="update btn" name="challan-update" id="challan-update">Update</button>
            <button type="button" class="delete btn" id="challan-cancel" onclick="window.location.href='../filter/index.php'">Cancel</button>
        <?php else: ?>
            <button type="button" class="save btn" name="challan-save" id="challan-save">Dispatch Challan</button>
        <?php endif; ?>
    </div>
</div>
</body>
<script>
    window.challanEditData = <?= json_encode($challanEditData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="assets/js/challan_create.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

</html>
