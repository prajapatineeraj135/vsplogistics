<?php
// Require authenticated user and database connection.
include "../../protect/auth.php";
include "../../protect/db.php";
include "../../protect/case_converter.php";
include_once "../create/api/inword_dispatch_helpers.php";

// Read context values used by this page.
$company_id = (int) ($_SESSION['company_id'] ?? 102);
ensureInwordDispatchColumns($conn);
$challanIdsParam = trim((string) ($_GET['ids'] ?? ''));
$challanIds = [];

if ($challanIdsParam !== '') {
    $challanIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $challanIdsParam)), static function ($id) {
        return $id > 0;
    })));
} else {
    $challan_id = (int) ($_GET['challan_id'] ?? 0);
    if ($challan_id > 0) {
        $challanIds = [$challan_id];
    }
}

if (empty($challanIds)) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Invalid challan ID</h3>";
    exit;
}

// Format date for challan header (DD-MM-YYYY).
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

// Format numbers without trailing .00.
function formatNumberDisplay($value)
{
    $number = (float) $value;
    return (string) (int) round($number);
}

// Show first two words from first listed content item.
function formatContentDisplay($content)
{
    $contentText = trim((string) $content);
    if ($contentText === '') {
        return '-';
    }

    $firstItem = trim((string) strtok($contentText, ','));
    if ($firstItem === '') {
        $firstItem = $contentText;
    }

    $words = preg_split('/\s+/', $firstItem);
    if (!$words) {
        return '-';
    }

    return ucwords(strtolower(implode(' ', array_slice($words, 0, 2))));
}

require_once __DIR__ . '/../../protect/hindi_name_dictionary.php';

// Load company details.
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

// Try compatible challan queries because some installs have slightly different columns.
$challanSqlCandidates = [
    "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
    "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, owner_name AS agent_name, contact AS agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total FROM challans WHERE id = ? AND company_id = ? LIMIT 1",
    "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact FROM challans WHERE id = ? AND company_id = ? LIMIT 1"
];

$printChallans = [];

foreach ($challanIds as $challan_id) {
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

    $stmtChallan->bind_param('ii', $challan_id, $company_id);
    if ($stmtChallan->execute()) {
        $challan = $stmtChallan->get_result()->fetch_assoc();
    }
    $stmtChallan->close();

    if (!$challan) {
        continue;
    }

    $rows = [];
    $calcItems = 0.0;
    $calcFreight = 0.0;

    $biltySql = "
        SELECT
            b.id,
            b.gr_number,
            b.consignor_name,
            b.consignee_name,
            b.payment_type,
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
        ORDER BY b.gr_number ASC
    ";

    // Load all bilties linked to this challan and compute totals.
    $stmtBilty = $conn->prepare($biltySql);
    if ($stmtBilty) {
        $stmtBilty->bind_param('ii', $company_id, $challan_id);
        if ($stmtBilty->execute()) {
            $resultBilty = $stmtBilty->get_result();
            while ($row = $resultBilty->fetch_assoc()) {
                $rows[] = $row;

                $lineFreight = (int) round((float) ($row['display_freight'] ?? 0));
                $paymentType = strtolower(trim((string) ($row['payment_type'] ?? '')));
                $isPaidMode = ($paymentType === 'cash' || $paymentType === 'tbb');

                $calcItems += (int) round((float) ($row['item_count'] ?? 0));
                if (!$isPaidMode) {
                    $calcFreight += $lineFreight;
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
        $stmtInwordBilty->bind_param('ii', $company_id, $challan_id);
        if ($stmtInwordBilty->execute()) {
            $resultInwordBilty = $stmtInwordBilty->get_result();
            while ($row = $resultInwordBilty->fetch_assoc()) {
                $rows[] = $row;

                $lineFreight = (int) round((float) ($row['display_freight'] ?? 0));
                $paymentType = strtolower(trim((string) ($row['payment_type'] ?? '')));
                $isPaidMode = ($paymentType === 'cash' || $paymentType === 'tbb');

                $calcItems += (int) round((float) ($row['item_count'] ?? 0));
                if (!$isPaidMode) {
                    $calcFreight += $lineFreight;
                }
            }
        }
        $stmtInwordBilty->close();
    }

    $printChallans[] = [
        'challan' => $challan,
        'rows' => $rows,
        'calcItems' => $calcItems,
        'calcFreight' => $calcFreight,
        'paidTotal' => (int) round((float) ($challan['paid_total'] ?? 0)),
        'freightTotal' => (int) round((float) ($challan['freight_total'] ?? 0)),
        'recoveryTotal' => (int) round((float) ($challan['recovery_total'] ?? 0)),
        'cuttingTotal' => (int) round((float) ($challan['cutting_total'] ?? 0)),
        'commissionTotal' => (int) round((float) ($challan['commission_total'] ?? 0)),
        'finalTotal' => (int) round((float) ($challan['final_total'] ?? 0)),
    ];
}

if (empty($printChallans)) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Challan not found</h3>";
    exit;
}


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Challan View</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 5mm;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 210mm;
            min-height: 297mm;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            font-size: 11px;
            font-weight: 600;
            color: #000;
            background: #fff;
        }

        .challan {
            width: 100%;
        }

        .copy-page {
            position: relative;
            padding: 5mm;
            page-break-after: always;
            break-after: page;
        }

        .copy-label {
            position: absolute;
            top: 2mm;
            left: 5mm;
            font-size: 14px;
            font-weight: 700;
        }

        .station-top-name {
            position: absolute;
            top: 1.5mm;
            left: 50%;
            transform: translateX(-50%);
            width: 70%;
            color: rgba(0, 0, 0, 0.32);
            font-size: 30px;
            font-weight: 700;
            line-height: 1.1;
            text-align: center;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .copy-page:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        .header {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 6mm;
            padding-bottom: 2mm;
            border-bottom: 2px solid #000;
        }

        .company-name {
            flex: 1;
        }

        .company-name h1 {
            margin: 0 0 6px;
            font-size: 30px;
            font-weight: 700;
            line-height: 1.2;
        }

        .company-name p {
            font-size: 14px;
            font-weight: 600;
            margin: 0;
            line-height: 1.35;
        }

        .company-branch-details {
            min-width: 230px;
            text-align: right;
            line-height: 1.5;
        }

        .company-branch-details p {
            margin: 0 0 4px;
            font-size: 14px;
            font-weight: 600;

        }


        .challan-details {
            border-bottom: 2px solid #000;
            padding: 0 0 2mm 0;
        }


        .challan-head th,
        .challan-head td {
            font-size: 14px;
            padding: 2px 4px;
            border: none;
            font-weight: 600;
        }

        .challan-body thead,
        .challan-body tfoot {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;

        }

        .challan-body td,
        .challan-body th {
            font-size: 14px;
            padding: 1px 2px;
            border: none;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            margin-top: 2mm;
        }

        th,
        td {
            border: 1px solid #000;
            /* padding: 1.5mm; */
            vertical-align: top;
            font-size: 11px;
            font-weight: 500;
        }

        th {
            font-weight: 700;
        }

        .challan-footer {
            margin-top: 4mm;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.35;
        }

        @media print {

            html,
            body {
                width: 210mm;
                min-height: 297mm;
            }

            body {
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .copy-page {
                padding: 5mm;
            }
        }
    </style>
</head>

<body>
    <?php foreach ($printChallans as $printChallan): ?>
        <?php
        $challan = $printChallan['challan'];
        $rows = $printChallan['rows'];
        $calcItems = $printChallan['calcItems'];
        $calcFreight = $printChallan['calcFreight'];
        $paidTotal = $printChallan['paidTotal'];
        $recoveryTotal = $printChallan['recoveryTotal'];
        ?>
        <?php for ($copy = 1; $copy <= 2; $copy++): ?>
        <div class="challan copy-page">
            <div class="copy-label"><?= ($copy % 2 === 1) ? 'Branch Copy' : 'Agent Copy' ?></div>
            <div class="station-top-name">
                <?= htmlspecialchars(capitalizeWords((string) ($challan['challan_station'] ?? '-'))) ?>
            </div>
            <!-- Company header -->
            <div class="header">
                <div class="company-name">
                    <h1>
                        <?= htmlspecialchars($company['company_name']) ?>
                    </h1>

                    <p>
                        <?= htmlspecialchars($company['address1']) ?>,
                        <?= htmlspecialchars($company['address2']) ?>,
                        <?= htmlspecialchars($company['address3']) ?>,
                        <?= htmlspecialchars($company['city']) ?>,
                        <?= htmlspecialchars($company['pincode']) ?>,
                        <?= htmlspecialchars($company['state']) ?>
                        <strong>Contact - </strong>
                        <?= htmlspecialchars($company['phone1']) ?>,
                        <?= htmlspecialchars($company['phone2']) ?>
                    </p>

                </div>

                <div class="company-branch-details">
                    <p><strong>Branch:</strong>
                        <?= htmlspecialchars($company['branch']) ?>
                    </p>
                    <p><strong>Contact:</strong>
                        <?= htmlspecialchars($company['owner_phone']) ?>
                    </p>
                    <p><strong>Transport ID:</strong>
                        <?= htmlspecialchars($company['gst_no']) ?>
                    </p>
                </div>
            </div>


            <div class="challan-details">
                <table>
                    <thead class="challan-head">
                        <tr>
                            <th>Challan No.</th>
                            <th>Challan Date</th>
                            <th>Station</th>
                            <th>Vehicle</th>
                            <th>Driver Name</th>
                            <th>Driver Contact</th>
                            <th>Agent Name</th>
                            <th>Agent Contact</th>
                        </tr>
                        <tr>
                            <td><strong style="font-size:14px"><?= htmlspecialchars((string) ($challan['challan_no'] ?? '-')) ?></strong></td>
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
                            <th width="3%">#</th>
                            <th width="10%">G.R.</th>
                            <th width="20%">Consignor</th>
                            <th width="20%">Consignee</th>
                            <th width="15%">Content</th>
                            <th width="7%">Item</th>
                            <th width="7%">Freight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center;">No bilty linked with this challan</td>
                            </tr>
                        <?php else: ?>
                            <?php $sn = 1;
                            foreach ($rows as $row):
                                $paymentType = strtolower(trim((string) ($row['payment_type'] ?? '')));
                                $hideFreight = ($paymentType === 'cash' || $paymentType === 'tbb');
                                ?>
                                <tr>
                                    <td><?= $sn++ ?></td>
                                    <td><?= htmlspecialchars((string) ($row['gr_number'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars(formatHindiPartyName((string) ($row['consignor_name'] ?? '-'))) ?></td>
                                    <td><?= htmlspecialchars(formatHindiPartyName((string) ($row['consignee_name'] ?? '-'))) ?></td>
                                    <td><?= htmlspecialchars(formatContentDisplay((string) ($row['content'] ?? ''))) ?></td>
                                    <td><?= formatNumberDisplay((float) ($row['item_count'] ?? 0)) ?></td>
                                    <td><?= $hideFreight ? '-' : formatNumberDisplay((float) ($row['display_freight'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><strong>Total Paid:-</strong><?= formatNumberDisplay($paidTotal) ?></td>
                            <td><strong>Total Recovery:-</strong><?=formatNumberDisplay($recoveryTotal) ?></td>
                            <td colspan="2" style="text-align:right;"><strong>Total</strong></td>
                            <td><strong style="text-align:right;"> <?= formatNumberDisplay($calcItems) ?></strong></td>
                            <td><strong style="text-align:right;"><?= formatNumberDisplay($calcFreight) ?>/-</strong></td>
                        </tr>
                        
                    </tfoot>
                </table>
            </div>


            <div class="challan-footer">
                <p>signature of receiver</p><br><br><br>
                <p>Received the above goods in good condition and correct quantity. Subject to Kota Jurisdiction.</p>
            </div>
        </div>
        <?php endfor; ?>
    <?php endforeach; ?>

    <script>
        window.addEventListener('load', () => {
            window.print();
        });

        window.addEventListener('afterprint', () => {
            if (window.history.length === 1) {
                window.close();
                return;
            }
            window.history.back();
        });
    </script>
</body>

</html>
