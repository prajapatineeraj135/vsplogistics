<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../protect/db.php";
include "includes/bill_sync.php";

if (!isset($_SESSION['company_id']) && !isset($_SESSION['admin_login'])) {
    header("Location: ../login");
    exit;
}

ensureBillsSchema($conn);

function billPrintDate($date)
{
    $value = trim((string) $date);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);
    return $ts === false ? $value : date('d-m-Y', $ts);
}

function billPrintMonth($monthKey)
{
    $value = trim((string) $monthKey);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value . '-01');
    return $ts === false ? $value : strtolower(date('M-Y', $ts));
}

function billPrintText($value)
{
    $text = trim((string) $value);
    return $text === '' ? '' : ucwords(strtolower($text));
}

function billPrintNumber($value)
{
    return (string) (int) round((float) $value);
}

$companyIdFilter = isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : null;
$billId = isset($_GET['bill_id']) ? (int) $_GET['bill_id'] : 0;

if ($billId <= 0) {
    header("Location: index.php?view=list");
    exit;
}

if ($companyIdFilter !== null) {
    $stmt = $conn->prepare("SELECT * FROM bills WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("ii", $billId, $companyIdFilter);
} else {
    $stmt = $conn->prepare("SELECT * FROM bills WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $billId);
}
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bill) {
    header("Location: index.php?view=list");
    exit;
}

$company = null;
$billCompanyId = (int) ($bill['company_id'] ?? 0);
if ($billCompanyId > 0) {
    $companyStmt = $conn->prepare("SELECT * FROM company WHERE id = ? LIMIT 1");
    $companyStmt->bind_param("i", $billCompanyId);
    $companyStmt->execute();
    $company = $companyStmt->get_result()->fetch_assoc();
    $companyStmt->close();
}

$monthKey = trim((string) ($bill['bill_month'] ?? ''));
$periodStart = trim((string) ($bill['period_start'] ?? ''));
$periodEnd = trim((string) ($bill['period_end'] ?? ''));
$monthStart = $periodStart !== '' ? $periodStart : ($monthKey !== '' ? $monthKey . '-01 00:00:00' : null);
$monthEnd = $periodEnd !== '' ? $periodEnd : ($monthKey !== '' ? date('Y-m-t 23:59:59', strtotime($monthKey . '-01')) : null);

$biltyRows = [];
$totalBilty = 0;
$totalNag = 0;
$totalFreight = 0;

if ($billCompanyId > 0 && $monthStart !== null && $monthEnd !== null) {
    $partyId = (int) ($bill['party_id'] ?? 0);
    $partyName = trim((string) ($bill['party_name'] ?? ''));

    $sql = "SELECT id, gr_number, bilty_date, consignee_name, to_station, total_qty, freight
            FROM biltys
            WHERE company_id = ?
              AND payment_type = 'TBB'
              AND status IN ('Booked', 'Dispatch')
              AND bilty_date BETWEEN ? AND ?";
    $types = "iss";
    $params = [$billCompanyId, $monthStart, $monthEnd];

    if ($partyId > 0) {
        $sql .= " AND consignor_id = ?";
        $types .= "i";
        $params[] = $partyId;
    } else {
        $sql .= " AND consignor_name = ?";
        $types .= "s";
        $params[] = $partyName;
    }

    $sql .= " ORDER BY bilty_date ASC, id ASC";

    $detailStmt = $conn->prepare($sql);
    $detailStmt->bind_param($types, ...$params);
    $detailStmt->execute();
    $detailRes = $detailStmt->get_result();

    while ($row = $detailRes->fetch_assoc()) {
        $biltyRows[] = $row;
        $totalBilty++;
        $totalNag += (int) round((float) ($row['total_qty'] ?? 0));
        $totalFreight += (int) round((float) ($row['freight'] ?? 0));
    }

    $detailStmt->close();
}

$rowsPerPrintPage = 35;
$biltyPages = !empty($biltyRows) ? array_chunk($biltyRows, $rowsPerPrintPage) : [[]];
$totalPages = count($biltyPages);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Print Bill</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 6mm 6mm; }
        * { text-transform: capitalize; box-sizing: border-box; }
        body { background: #f4f6f9; color: #111827; font-size: 13px; }
        .no-print {
            max-width: 210mm;
            margin: 12px auto;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        .print-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 5px auto;
            background: #ffffff;
            padding: 5mm;
            display: flex;
            flex-direction: column;
        }
        .print-sheet.page-break {
            break-after: page;
            page-break-after: always;
        }
        .company-head {
            text-align: center;
            padding-bottom: 7px;
            margin-bottom: 0;
        }
        .company-name {
            font-size: 40px;
            line-height: 1.1;
            font-weight: 700;
        }
        .company-line {
            font-size: 12px;
            margin-top: 3px;
        }
        .section-divider {
            width: 100%;
            border-top: 2px solid #111827;
        }
        .bill-meta {
            display: grid;
            grid-template-columns: minmax(220px, 2fr) repeat(3, minmax(110px, 1fr));
            gap: 6px;
        }
        .bill-meta-item {
            min-height: 42px;
            padding: 7px 9px;
            background: #ecfdf5;
        }
        .meta-label {
            display: block;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 1px;
        }
        .meta-value {
            font-size: 14px;
            font-weight: 700;
        }
        .party-value {
            font-size: 16px;
        }
        .remark-line {
            padding: 6px 8px;
            background: #f8fafc;
            font-weight: 600;
            margin-top: 6px;
        }
        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin: 4px 0 0;
            font-size: 10px;
        }
        .bill-table th,
        .bill-table td {
            border: 1px solid #111827;
            padding: 2px 3px;
            text-align: left;
            vertical-align: middle;
        }
        .bill-table th {
            background: #f3f4f6;
            font-weight: 700;
            white-space: nowrap;
        }
        .bill-table tbody tr:nth-child(odd) td { background: #ffffff; }
        .bill-table tbody tr:nth-child(even) td { background: #e5e7eb; }
        .total-row th,
        .total-row td {
            background: #ecfdf5;
            font-weight: 700;
        }
        .bank-section {
            margin-top: auto;
            padding-top: 10px;
        }
        .bank-title {
            padding: 5px 0;
            font-weight: 700;
        }
        .bank-bottom-row {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 10px;
            align-items: end;
        }
        .bank-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .bank-item {
            font-size: 12px;
        }
        .bank-item strong {
            display: inline-block;
            min-width: 78px;
        }
        .page-footer {
            margin-top: 8px;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
        }
        .print-sheet.page-break .page-footer {
            margin-top: auto;
        }
        .signature-box {
            width: 100px;
            min-height: 75px;
            margin-left: 150px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            font-weight: 700;
        }
        .signature-line {
            border-top: 1px solid #111827;
            padding-top: 6px;
        }
        @media print {
            body { background: #ffffff; }
            .no-print { display: none !important; }
            .print-sheet {
                width: 100%;
                min-height: calc(297mm - 24mm);
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <a href="index.php?view=list" class="btn btn-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <button type="button" class="btn btn-success btn-sm" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>

    <?php foreach ($biltyPages as $pageIndex => $pageRows): ?>
    <?php $isLastPage = ($pageIndex + 1) === $totalPages; ?>
    <div class="print-sheet<?= $isLastPage ? ' last-page' : ' page-break' ?>">
        <div class="company-head">
            <div class="company-name"><?= htmlspecialchars(billPrintText($company['company_name'] ?? 'Transport Company')) ?></div>
            <div class="company-line">
                <?= htmlspecialchars(billPrintText(trim((string) (($company['address1'] ?? '') . ', ' . ($company['address2'] ?? '') . ', ' . ($company['city'] ?? '') . ', ' . ($company['state'] ?? '') . '-' . ($company['pincode'] ?? ''))))) ?>
            </div>
            <div class="company-line">
                Phone: <?= htmlspecialchars((string) ($company['phone1'] ?? '-')) ?><?= !empty($company['phone2']) ? ' / ' . htmlspecialchars((string) $company['phone2']) : '' ?>
                &nbsp; | &nbsp; Transporter Id: <?= htmlspecialchars((string) ($company['gst_no'] ?? '-')) ?>
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="bill-meta">
            <div class="bill-meta-item">
                <span class="meta-label">Party Name</span>
                <span class="meta-value party-value"><?= htmlspecialchars(billPrintText($bill['party_name'] ?? '')) ?></span>
            </div>
            <div class="bill-meta-item">
                <span class="meta-label">Bill No</span>
                <span class="meta-value"><?= htmlspecialchars((string) ($bill['bill_number'] ?? '')) ?></span>
            </div>
            <div class="bill-meta-item">
                <span class="meta-label">Bill Month</span>
                <span class="meta-value"><?= htmlspecialchars(billPrintMonth($bill['bill_month'] ?? '')) ?></span>
            </div>
            <div class="bill-meta-item">
                <span class="meta-label">Bill Date</span>
                <span class="meta-value"><?= htmlspecialchars(billPrintDate($bill['bill_date'] ?? '')) ?></span>
            </div>
        </div>



        <table class="bill-table">
            <thead>
                <tr>
                    <th width="40">Sr</th>
                    <th width="12%">GR No</th>
                    <th width="12%">Date</th>
                    <th>Consignee</th>
                    <th>Station</th>
                    <th  width="7%">Nag</th>
                    <th width="12%">Freight</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($pageRows)): ?>
                    <?php foreach ($pageRows as $index => $row): ?>
                        <tr>
                            <td><?= ($pageIndex * $rowsPerPrintPage) + $index + 1 ?></td>
                            <td><?= htmlspecialchars((string) ($row['gr_number'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(billPrintDate($row['bilty_date'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(billPrintText($row['consignee_name'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(billPrintText($row['to_station'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(billPrintNumber($row['total_qty'] ?? 0)) ?></td>
                            <td><?= htmlspecialchars(billPrintNumber($row['freight'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">No bilty details found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <?php if ($isLastPage): ?>
                <tfoot>
                    <tr class="total-row">
                        <th colspan="4"></th>
                        <th>Total</th>
                        <td><?= htmlspecialchars(billPrintNumber($totalNag)) ?></td>
                        <td>&#8377; <?= htmlspecialchars(billPrintNumber($totalFreight)) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>

       

        <?php if ($isLastPage): ?>
        <div class="bank-section">
             <div class="section-divider"></div>    
            <div class="bank-bottom-row">
                <div>
                    <div class="bank-title">Bank Details</div>
                    <div class="bank-grid">
                        <div class="bank-item"><strong>Bank:</strong> <?= htmlspecialchars(billPrintText($company['bank_name'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>A/C Name:</strong> <?= htmlspecialchars(billPrintText($company['bank_account_name'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>A/C No:</strong> <?= htmlspecialchars((string) ($company['bank_account_number'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>IFSC:</strong> <?= htmlspecialchars((string) ($company['bank_ifsc_code'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>UPI ID:</strong> <?= htmlspecialchars((string) ($company['upi_id'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>Contact:</strong> <?= htmlspecialchars((string) ($company['phone1'] ?? ($company['owner_phone'] ?? '-'))) ?></div>
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">Seal / Signature</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($totalPages > 1): ?>
            <div class="page-footer">Page <?= $pageIndex + 1 ?> Of <?= $totalPages ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <script>
        window.addEventListener('load', function () {
            let redirected = false;
            const redirectAfterPrint = function () {
                if (redirected) return;
                redirected = true;
                window.location.href = 'index.php?view=list';
            };

            window.addEventListener('afterprint', redirectAfterPrint);
            setTimeout(function () {
                window.print();
            }, 300);
        });
    </script>
</body>
</html>
