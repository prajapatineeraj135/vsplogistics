<?php
include "../../protect/auth.php";
include "../../protect/db.php";
include "../../protect/case_converter.php";
include_once "../create/api/inword_dispatch_helpers.php";

$company_id = $_SESSION['company_id'] ?? '102';
$challan_id = (int) ($_GET['challan_id'] ?? 0);
ensureInwordDispatchColumns($conn);

if ($challan_id <= 0) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Invalid challan ID</h3>";
    exit;
}

function formatDisplayDate($dateValue)
{
    if (empty($dateValue)) {
        return '-';
    }

    $timestamp = strtotime((string) $dateValue);
    if ($timestamp === false) {
        return (string) $dateValue;
    }

    return date('d-m-Y', $timestamp);
}

function formatNumberDisplay($value)
{
    $number = (float) $value;
    return (string) (int) round($number);
}

$stmtCompany = $conn->prepare("SELECT * FROM company WHERE id = ?");
$stmtCompany->bind_param("i", $company_id);
$stmtCompany->execute();
$resultCompany = $stmtCompany->get_result();
if ($resultCompany->num_rows === 0) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Company data not found</h3>";
    exit;
}
$company = $resultCompany->fetch_assoc();
$stmtCompany->close();

$challanSqlCandidates = [
    "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
    "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, owner_name AS agent_name, contact AS agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
    "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact FROM challans WHERE id = ? AND company_id = ? LIMIT 1"
];

$challan = null;
$stmtChallan = null;
foreach ($challanSqlCandidates as $query) {
    $stmtChallan = $conn->prepare($query);
    if ($stmtChallan) {
        break;
    }
}

if (!$stmtChallan) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Unable to load challan data</h3>";
    exit;
}

$stmtChallan->bind_param('is', $challan_id, $company_id);
if ($stmtChallan->execute()) {
    $challan = $stmtChallan->get_result()->fetch_assoc();
}
$stmtChallan->close();

if (!$challan) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Challan not found</h3>";
    exit;
}

$rows = [];
$calcItems = 0.0;
$calcPaid = 0.0;
$calcCashPaid = 0.0;
$calcTbbPaid = 0.0;
$calcFreight = 0.0;
$calcRecovery = 0.0;

$biltySql = "
    SELECT
        b.id,
        b.gr_number,
        b.consignor_name,
        b.consignee_name,
        b.payment_type,
        COALESCE(b.hammali, 0) AS hammali,
        COALESCE(b.brokerage, 0) AS brokerage,
        COALESCE(b.p_freight, 0) AS p_freight,
        COALESCE(NULLIF(b.total_charge, 0), b.freight, 0) AS display_freight,
        (COALESCE(b.hammali, 0) + COALESCE(b.brokerage, 0) + COALESCE(b.p_freight, 0) + COALESCE(b.brokerage, 0)) AS recovery_amount,
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
    ORDER BY b.gr_number ASC
";

$stmtBilty = $conn->prepare($biltySql);
if ($stmtBilty) {
    $stmtBilty->bind_param('si', $company_id, $challan_id);
    if ($stmtBilty->execute()) {
        $resultBilty = $stmtBilty->get_result();
        while ($row = $resultBilty->fetch_assoc()) {
            $rows[] = $row;

            $lineFreight = (int) round((float) ($row['display_freight'] ?? 0));
            $lineRecovery = (int) round((float) ($row['recovery_amount'] ?? 0));
            $paymentType = strtolower(trim((string) ($row['payment_type'] ?? '')));

            $calcItems += (int) round((float) ($row['item_count'] ?? 0));
            $calcFreight += $lineFreight;
            $calcRecovery += $lineRecovery;

            if ($paymentType === 'cash' || $paymentType === 'tbb') {
                $calcPaid += $lineFreight;
                if ($paymentType === 'cash') {
                    $calcCashPaid += $lineFreight;
                } elseif ($paymentType === 'tbb') {
                    $calcTbbPaid += $lineFreight;
                }
            }
        }
    }
    $stmtBilty->close();
}

$inwordBiltySql = "
    SELECT
        b.id,
        COALESCE(NULLIF(b.inword_gr, ''), NULLIF(b.other_gr_no, ''), CONCAT('IN-', b.id)) AS gr_number,
        b.consignor_name,
        b.consignee_name,
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
    ORDER BY gr_number ASC
";

$stmtInwordBilty = $conn->prepare($inwordBiltySql);
if ($stmtInwordBilty) {
    $stmtInwordBilty->bind_param('si', $company_id, $challan_id);
    if ($stmtInwordBilty->execute()) {
        $resultInwordBilty = $stmtInwordBilty->get_result();
        while ($row = $resultInwordBilty->fetch_assoc()) {
            $rows[] = $row;

            $lineFreight = (int) round((float) ($row['display_freight'] ?? 0));
            $lineRecovery = (int) round((float) ($row['recovery_amount'] ?? 0));
            $paymentType = strtolower(trim((string) ($row['payment_type'] ?? '')));

            $calcItems += (int) round((float) ($row['item_count'] ?? 0));
            $calcFreight += $lineFreight;
            $calcRecovery += $lineRecovery;

            if ($paymentType === 'cash' || $paymentType === 'tbb') {
                $calcPaid += $lineFreight;
                if ($paymentType === 'cash') {
                    $calcCashPaid += $lineFreight;
                } elseif ($paymentType === 'tbb') {
                    $calcTbbPaid += $lineFreight;
                }
            }
        }
    }
    $stmtInwordBilty->close();
}

$paidTotal = (int) round((float) ($challan['paid_total'] ?? 0));
$freightTotal = (int) round((float) ($challan['freight_total'] ?? 0));
$recoveryTotal = (int) round((float) ($challan['recovery_total'] ?? 0));
$cuttingTotal = (int) round((float) ($challan['cutting_total'] ?? 0));
$commissionTotal = (int) round((float) ($challan['commission_total'] ?? 0));
$finalTotal = (int) round((float) ($challan['final_total'] ?? 0));

if ($paidTotal == 0) {
    $paidTotal = $calcPaid;
}
if ($freightTotal == 0) {
    $freightTotal = $calcFreight;
}
if ($recoveryTotal == 0) {
    $recoveryTotal = $calcRecovery;
}
if ($finalTotal == 0) {
    $finalTotal = max(0, $freightTotal - $recoveryTotal - $cuttingTotal - $commissionTotal);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Challan View</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 16px;
            font-family: Arial, sans-serif;
            font-size: 13px;
            color: #000;
            background: #fff;
        }

        .challan {
            width: 100%;
        }

        
        .challan-details {
            border-bottom: 2px solid #000;
            border-top: 2px solid #000;
            margin: 10px 0;
        }

        .challan-head th,
        .challan-head td {
            font-size: 15px;
            padding: 2px 4px;
            border: none;
        }

        .challan-body thead,
        .challan-body tfoot {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;

        }

        .challan-body td,
        .challan-body th {
            font-size: 13px;
            border: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 2px;
            vertical-align: top;
            font-size: 15px;
            font-weight: 400;
        }

        th {
            font-weight: 700;
        }

        .summary-table {
            width: 100%;
            margin-top: 18px;
        }

       
    </style>
</head>

<body>
    <div class="challan">
       

        <div class="challan-details">
            <table>
                <thead>
                    <tr class="challan-head">
                        <th>Challan No.</th>
                        <th>Challan Date</th>
                        <th>Station</th>
                        <th>Vehicle</th>
                        <th>Driver Name</th>
                        <th>Driver Contact</th>
                        <th>Agent Name</th>
                        <th>Agent Contact</th>
                    </tr>
                    <tr class="challan-head">
                        <td><?= htmlspecialchars((string) ($challan['challan_no'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars(formatDisplayDate((string) ($challan['challan_date'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars(capitalizeWords((string) ($challan['challan_station'] ?? '-'))) ?></td>
                        <td><?= htmlspecialchars((string) ($challan['vehicle_no'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars(capitalizeWords((string) ($challan['driver_name'] ?? '-'))) ?></td>
                        <td><?= htmlspecialchars((string) ($challan['driver_contact'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars(capitalizeWords((string) ($challan['agent_name'] ?? '-'))) ?></td>
                        <td><?= htmlspecialchars((string) ($challan['agent_contact'] ?? '-')) ?></td>
                    </tr>
                </thead>
            </table>
        </div>
        <div class="challan-body">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>G.R.</th>
                        <th>Consignor</th>
                        <th>Consignee</th>
                        <th>Content</th>
                        <th>Item</th>
                        <th>Recovery</th>
                        <th>Freight</th>
                        <th>Mode</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;">No bilty linked with this challan</td>
                        </tr>
                    <?php else: ?>
                        <?php $sn = 1;
                        foreach ($rows as $row): ?>
                            <tr>
                                <td><?= $sn++ ?></td>
                                <td><?= htmlspecialchars((string) ($row['gr_number'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars(capitalizeWords((string) ($row['consignor_name'] ?? '-'))) ?></td>
                                <td><?= htmlspecialchars(capitalizeWords((string) ($row['consignee_name'] ?? '-'))) ?></td>
                                <td><?= htmlspecialchars(capitalizeWords((string) ($row['content'] ?? '-'))) ?></td>
                                <td><?= formatNumberDisplay((float) ($row['item_count'] ?? 0)) ?></td>
                                <td><?= formatNumberDisplay((float) ($row['recovery_amount'] ?? 0)) ?></td>
                                <td><?= formatNumberDisplay((float) ($row['display_freight'] ?? 0)) ?></td>
                                <td><?= htmlspecialchars(strtoupper((string) ($row['payment_type'] ?? '-'))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;"><strong>Total</strong></td>
                        <td><strong><?= formatNumberDisplay($calcItems) ?></strong></td>
                        <td><strong><?= formatNumberDisplay($calcRecovery) ?></strong></td>
                        <td><strong><?= formatNumberDisplay($calcFreight) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="challan-footer">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th width="20%">
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <div style="border-right: 2px solid #000; padding-right: 10px;">Total Paid</div>
                                <div>Cash: <span id="calc-paid-cash"><?= formatNumberDisplay($calcCashPaid) ?></span>
                                </div>
                                <div>TBB: <span id="calc-paid-tbb"><?= formatNumberDisplay($calcTbbPaid) ?></span></div>
                            </div>
                            <div>
                        </th>
                        <th>Total Freight</th>
                        <th>Total Recovery</th>
                        <th>Total Cutting</th>
                        <th>Total Commission</th>
                        <th>Final Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= formatNumberDisplay($paidTotal) ?></td>
                        <td><?= formatNumberDisplay($freightTotal) ?></td>
                        <td><?= formatNumberDisplay($recoveryTotal) ?></td>
                        <td><?= formatNumberDisplay($cuttingTotal) ?></td>
                        <td><?= formatNumberDisplay($commissionTotal) ?></td>
                        <td><?= formatNumberDisplay($finalTotal) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

  <!-- <script>
        (function blockPrint() {
            window.addEventListener('keydown', function (event) {
                const key = String(event.key || '').toLowerCase();
                if ((event.ctrlKey || event.metaKey) && key === 'p') {
                    event.preventDefault();
                    showWarning('Print is disabled on this page.');
                }
            });

            window.addEventListener('beforeprint', function () {
                setTimeout(function () {
                    window.stop();
                }, 0);
            });
        })();
    </script> -->
    
</body>

</html>
