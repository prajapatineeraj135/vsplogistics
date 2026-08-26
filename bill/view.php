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

function billViewDate($date)
{
    $value = trim((string) $date);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);
    return $ts === false ? $value : date('d-m-Y', $ts);
}

function billViewMonth($monthKey)
{
    $value = trim((string) $monthKey);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value . '-01');
    return $ts === false ? $value : strtolower(date('M-Y', $ts));
}

function billViewText($value)
{
    $text = trim((string) $value);
    return $text === '' ? '' : ucwords(strtolower($text));
}

function billViewNumber($value)
{
    return (string) (int) round((float) $value);
}

$isCompanyUser = isset($_SESSION['company_id']);
$companyIdFilter = $isCompanyUser ? (int) $_SESSION['company_id'] : null;
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

$billCompanyId = (int) ($bill['company_id'] ?? 0);

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>See Bill</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { text-transform: capitalize; }
        body { background: #f4f6f9; font-size: 14px; color: #111827; }
        .page-shell { max-width: 96vw; }
        .top-actions {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px;
            box-shadow: 0 4px 12px rgba(15,23,42,.07);
        }
        .bill-title { font-size: 17px; font-weight: 700; color: #14532d; }
        .bill-sheet {
            background: #ffffff;
            border: 1px solid #d1d5db;
            box-shadow: 0 8px 22px rgba(15,23,42,.10);
        }
        .bill-meta {
            display: grid;
            grid-template-columns: minmax(260px, 2fr) repeat(3, minmax(140px, 1fr));
            border-bottom: 1px solid #111827;
        }
        .bill-meta-item {
            min-height: 46px;
            padding: 9px 12px;
            border-right: 1px solid #111827;
        }
        .bill-meta-item:nth-child(4n) { border-right: 0; }
        .bill-meta-item {
            background: #ecfdf5;
        }
        .meta-label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            font-weight: 700;
            margin-bottom: 1px;
        }
        .meta-value {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
        }
        .party-value {
            font-size: 18px;
            color: #14532d;
        }
        .remark-line {
            padding: 9px 12px;
            border-bottom: 1px solid #111827;
            font-weight: 600;
        }
        .bill-table {
            margin: 0;
            font-size: 14px;
        }
        .bill-table th {
            background: #f3f4f6;
            color: #111827;
            white-space: nowrap;
            font-size: 14px;
            text-align: left;
            padding: 5px 7px;
        }
        .bill-table td {
            vertical-align: middle;
            padding: 5px 7px;
        }
        .bill-table th,
        .bill-table td {
            text-align: left;
        }
        .bill-table tbody tr:nth-child(odd) td {
            background: #ffffff;
        }
        .bill-table tbody tr:nth-child(even) td {
            background: #e5e7eb;
        }
        .total-row th, .total-row td {
            background: #ecfdf5;
            font-weight: 800;
        }
        .bilty-actions {
            display: flex;
            gap: 4px;
            flex-wrap: nowrap;
        }
        @media (max-width: 767.98px) {
            .container-fluid { padding-left: 8px; padding-right: 8px; }
            .bill-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .bill-meta-item:nth-child(4n) { border-right: 1px solid #111827; }
            .bill-meta-item:nth-child(2n) { border-right: 0; }
        }
    </style>
</head>
<body>
    <?php include "../content/nav.php"; ?>

    <div class="container-fluid page-shell py-3">
        <div class="top-actions d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
            <div class="bill-title"><i class="bi bi-eye"></i> See Bill Details</div>
            <div class="d-flex gap-2">
                <a href="index.php?view=list" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <a href="print.php?bill_id=<?= (int) $billId ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-printer"></i> Print
                </a>
            </div>
        </div>

        <div class="bill-sheet">
            <div class="bill-meta">
                <div class="bill-meta-item">
                    <span class="meta-label">Party Name</span>
                    <span class="meta-value party-value"><?= htmlspecialchars(billViewText($bill['party_name'] ?? '')) ?></span>
                </div>
                <div class="bill-meta-item">
                    <span class="meta-label">Bill No</span>
                    <span class="meta-value"><?= htmlspecialchars((string) ($bill['bill_number'] ?? '')) ?></span>
                </div>
                <div class="bill-meta-item">
                    <span class="meta-label">Bill Month</span>
                    <span class="meta-value"><?= htmlspecialchars(billViewMonth($bill['bill_month'] ?? '')) ?></span>
                </div>
                <div class="bill-meta-item">
                    <span class="meta-label">Bill Date</span>
                    <span class="meta-value"><?= htmlspecialchars(billViewDate($bill['bill_date'] ?? '')) ?></span>
                </div>
            </div>

            <?php if (trim((string) ($bill['remarks'] ?? '')) !== ''): ?>
                <div class="remark-line">
                    Remark: <?= htmlspecialchars(billViewText($bill['remarks'] ?? '')) ?>
                </div>
            <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm bill-table">
                        <thead class="table-light">
                            <tr>
                                <th width="45">Sr</th>
                                <th>GR No</th>
                                <th>Date</th>
                                <th>Consignee</th>
                                <th>Station</th>
                                <th>Nag</th>
                                <th>Freight</th>
                                <th width="120">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($biltyRows)): ?>
                                <?php foreach ($biltyRows as $index => $row): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <a href="../bilty/view/index.php?id=<?= (int) ($row['id'] ?? 0) ?>" class="fw-bold text-decoration-none">
                                                <?= htmlspecialchars((string) ($row['gr_number'] ?? '')) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars(billViewDate($row['bilty_date'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars(billViewText($row['consignee_name'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars(billViewText($row['to_station'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars(billViewNumber($row['total_qty'] ?? 0)) ?></td>
                                        <td>&#8377; <?= htmlspecialchars(billViewNumber($row['freight'] ?? 0)) ?></td>
                                        <td>
                                            <div class="bilty-actions">
                                                <a href="../bilty/view/index.php?id=<?= (int) ($row['id'] ?? 0) ?>" class="btn btn-info btn-sm text-white" title="See">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-warning btn-sm" title="Edit" onclick="confirmEditBiltyFromBill('<?= (int) ($row['id'] ?? 0) ?>', '<?= htmlspecialchars((string) ($row['gr_number'] ?? ''), ENT_QUOTES) ?>')">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <a href="../bilty/print/index.php?id=<?= (int) ($row['id'] ?? 0) ?>" class="btn btn-success btn-sm" title="Print">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">No bilty details found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <th colspan="4"></th>
                                <th>Total</th>
                                <td><?= htmlspecialchars(billViewNumber($totalNag)) ?></td>
                                <td>&#8377; <?= htmlspecialchars(billViewNumber($totalFreight)) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

        </div>
    </div>
    <script>
        function confirmEditBiltyFromBill(id, grNumber) {
            const editUrl = <?= json_encode(base_url('bilty/create/index.php?edit_id='), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> + encodeURIComponent(id);
            const message = 'Edit bilty GR ' + grNumber + '?';

            if (typeof window.nmConfirm === 'function') {
                window.nmConfirm(message).then(function (ok) {
                    if (ok) {
                        window.location.href = editUrl;
                    }
                });
                return;
            }

            if (window.confirm(message)) {
                window.location.href = editUrl;
            }
        }
    </script>
</body>
</html>
