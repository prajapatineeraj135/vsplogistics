<?php
include "protect/db.php";
include_once "protect/case_converter.php";
//include "protect/admin_auth.php";
//include "protect/auth.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$quickGr = trim((string) ($_GET['quick_gr'] ?? ''));
$reportDate = trim((string) ($_GET['report_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d');
}
$quickBiltyRows = [];
$quickBiltyError = '';
$companyId = $_SESSION['company_id'] ?? '';
$dailyReport = [
    'cash_freight' => 0,
    'tbb_freight' => 0,
    'recovery' => 0,
    'total_freight' => 0,
];

$dailyClauses = ["DATE(b.bilty_date) = ?", "b.status NOT IN ('Trash', 'Cancel')"];
$dailyParams = [$reportDate];
$dailyTypes = 's';

if ($companyId !== '') {
    $dailyClauses[] = 'b.company_id = ?';
    $dailyParams[] = $companyId;
    $dailyTypes .= 's';
}

$chargeExpression = "CASE
    WHEN COALESCE(b.total_charge, 0) > 0 THEN COALESCE(b.total_charge, 0)
    ELSE COALESCE(b.freight, 0) + COALESCE(b.p_freight, 0) + COALESCE(b.hammali, 0) + COALESCE(b.brokerage, 0)
END";
$recoveryExpression = "COALESCE(b.hammali, 0) + COALESCE(b.p_freight, 0) + COALESCE(b.brokerage, 0)";
$dailyWhere = implode(' AND ', $dailyClauses);
$dailySql = "SELECT
        SUM(CASE WHEN LOWER(TRIM(b.payment_type)) = 'cash' THEN {$chargeExpression} ELSE 0 END) AS cash_freight,
        SUM(CASE WHEN LOWER(TRIM(b.payment_type)) = 'tbb' THEN {$chargeExpression} ELSE 0 END) AS tbb_freight,
        SUM(CASE WHEN LOWER(TRIM(b.payment_type)) <> 'cash' THEN {$recoveryExpression} ELSE 0 END) AS recovery,
        SUM({$chargeExpression}) AS total_freight
    FROM biltys b
    WHERE {$dailyWhere}";

$dailyStmt = $conn->prepare($dailySql);
if ($dailyStmt) {
    if (!empty($dailyParams)) {
        $dailyStmt->bind_param($dailyTypes, ...$dailyParams);
    }
    $dailyStmt->execute();
    $dailyResult = $dailyStmt->get_result();
    $dailyRow = $dailyResult ? $dailyResult->fetch_assoc() : [];
    $dailyReport['cash_freight'] = (float) ($dailyRow['cash_freight'] ?? 0);
    $dailyReport['tbb_freight'] = (float) ($dailyRow['tbb_freight'] ?? 0);
    $dailyReport['recovery'] = (float) ($dailyRow['recovery'] ?? 0);
    $dailyReport['total_freight'] = (float) ($dailyRow['total_freight'] ?? 0);
    $dailyStmt->close();
}

if ($quickGr !== '') {
    $clauses = ["b.gr_number LIKE ?"];
    $params = ["%{$quickGr}%"];
    $types = 's';

    if ($companyId !== '') {
        $clauses[] = 'b.company_id = ?';
        $params[] = $companyId;
        $types .= 's';
    }

    $where = implode(' AND ', $clauses);
    $sql = "SELECT b.id, b.gr_number, b.bilty_date, b.status, b.consignor_name, b.consignee_name,
                   b.to_station, b.payment_type, b.total_qty, b.total_weight, b.total_charge,
                   b.freight, b.p_freight, b.hammali, b.brokerage,
                   b.eway_bill, b.private_mark, b.remark, c.challan_no, c.challan_date,
                   (
                       SELECT GROUP_CONCAT(DISTINCT bi.item_name ORDER BY bi.item_number ASC, bi.id ASC SEPARATOR ', ')
                       FROM bilty_items bi
                       WHERE bi.bilty_id = b.id
                   ) AS content
            FROM biltys b
            LEFT JOIN challans c ON c.id = b.challan_id
            WHERE {$where}
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $quickBiltyRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    } else {
        $quickBiltyError = 'Bilty search failed.';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background: #f4f6f9;
            font-family: system-ui;
        }

        .dashboard-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, .08);
            transition: .2s;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
        }

        .card-icon {
            font-size: 40px;
            opacity: .2;
            position: absolute;
            right: 20px;
            bottom: 15px;
        }

        .card-actions a {
            font-size: 14px;
        }

        .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .quick-result-table {
            font-size: 13px;
        }

        .quick-result-table th {
            white-space: nowrap;
        }

        .quick-action-primary:hover {
            color: #0d6efd;
            background: transparent;
        }

        .quick-action-success:hover {
            color: #198754;
            background: transparent;
        }

        .quick-action-warning:hover {
            color: #ffc107;
            background: transparent;
        }

        .daily-report-value {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.1;
        }
    </style>
</head>

<body>

    <?php include "content/nav.php"; ?>

    <div class="container my-5">

        <h2 class="fw-bold mb-4">
            <i class="bi bi-speedometer2"></i> Dashboard
        </h2>

        <div class="row g-4">

            <!-- ================= BILTY ================= -->
            <div class="col-md-6 col-xl-3">
                <div class="card dashboard-card text-white bg-success position-relative">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-book"></i> Bilty
                        </h5>
                        <p class="small">Manage bilties</p>

                        <div class="card-actions">
                            <a href="bilty/create" class="btn btn-sm btn-light">Make Bilty</a>
                            <a href="bilty/filter" class="btn btn-sm btn-outline-light">Search Bilty</a>
                        </div>

                        <i class="bi bi-book card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- ================= CHALLAN ================= -->
            <div class="col-md-6 col-xl-3">
                <div class="card dashboard-card text-white bg-success position-relative">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-file-earmark-text"></i> Challan
                        </h5>
                        <p class="small">Transport challans</p>

                        <div class="card-actions">
                            <a href="challan/create" class="btn btn-sm btn-light">Make Challan</a>
                            <a href="challan/filter" class="btn btn-sm btn-outline-light">Search Challan</a>
                        </div>

                        <i class="bi bi-file-earmark-text card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- ================= Ledger ================= -->
            <div class="col-md-6 col-xl-3">
                <div class="card dashboard-card text-white bg-success position-relative">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-journal-text"></i> Ledger
                        </h5>
                        <p class="small">Ledger challan and party accounts</p>

                        <div class="card-actions">
                            <a href="ledger/challan.php?tab=create" class="btn btn-sm btn-light">Challan Entery</a>
                            <a href="ledger/challan.php?tab=search" class="btn btn-sm btn-outline-light">Search Challan</a>
                        </div>

                        <i class="bi bi-journal-text card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- ================= Voucher ================= -->
            <div class="col-md-6 col-xl-3">
                <div class="card dashboard-card text-white bg-success position-relative">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-file-earmark-text"></i> Voucher
                        </h5>
                        <p class="small">Party vouchers</p>

                        <div class="card-actions">
                            <a href="voucher?view=create" class="btn btn-sm btn-light">Add Voucher</a>
                            <a href="voucher?view=search" class="btn btn-sm btn-outline-light">Search Vouchers</a>
                        </div>

                        <i class="bi bi-file-earmark-text card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- ================= Bill ================= -->
            <div class="col-md-6 col-xl-3">
                <div class="card dashboard-card text-white bg-success position-relative">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-receipt"></i> Bill
                        </h5>
                        <p class="small">Company bills</p>

                        <div class="card-actions">
                            <a href="bill/" class="btn btn-sm btn-light">Create Bill</a>
                            <a href="bill/" class="btn btn-sm btn-outline-light">Search Bills</a>
                        </div>

                        <i class="bi bi-receipt card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- ================= PARTY ================= -->
            <div class="col-md-6 col-xl-3">
                <div class="card dashboard-card text-white bg-success position-relative">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-people"></i> Party
                        </h5>
                        <p class="small">Consignor / Consignee / Transport</p>

                        <div class="card-actions">
                            <a href="party/index.php" class="btn btn-sm btn-light">Make Party</a>
                            <a href="party/index.php" class="btn btn-sm btn-outline-light">Search Party</a>
                        </div>

                        <i class="bi bi-people card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- ================= Vehicle ================= -->
            <div class="col-md-6 col-xl-3">
                <div class="card dashboard-card text-white bg-success position-relative">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-truck"></i> Vehicle
                        </h5>
                        <p class="small">Vehicle Report</p>

                        <div class="card-actions">
                            <a href="vehicle/index.php" class="btn btn-sm btn-light">Make Vehicle</a>
                            <a href="vehicle/index.php" class="btn btn-sm btn-outline-light">Search Vehicle</a>
                        </div>

                        <i class="bi bi-truck card-icon"></i>
                    </div>
                </div>
            </div>

        </div>

        <section class="mt-4">
            <div class="row g-4 justify-content-center mb-4">
                <div class="col-lg-8 col-xl-6">
                    <div class="card dashboard-card">
                        <div class="card-body text-center">
                            <h6><i class="bi bi-search"></i> Quick Bilty Search</h6>
                            <form action="index.php" method="get" class="d-flex gap-2 mx-auto" autocomplete="off" style="max-width: 520px;">
                                <input type="text" name="quick_gr" class="form-control form-control-sm" placeholder="GR Number"
                                    value="<?= htmlspecialchars($quickGr) ?>" required>
                                <input type="hidden" name="report_date" value="<?= htmlspecialchars($reportDate) ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Search</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php if ($quickGr !== ''): ?>
            <div class="card dashboard-card mt-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-receipt-cutoff"></i> Bilty Result</h5>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>

                    <?php if ($quickBiltyError !== ''): ?>
                        <div class="alert alert-danger mb-0"><?= htmlspecialchars($quickBiltyError) ?></div>
                    <?php elseif (empty($quickBiltyRows)): ?>
                        <div class="alert alert-warning mb-0">No bilty found for GR <?= htmlspecialchars($quickGr) ?>.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle quick-result-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>GR</th>
                                        <th>Booked Date</th>
                                        <th>Dispatch Date</th>
                                        <th>Challan</th>
                                        <th>Status</th>
                                        <th>Consignor</th>
                                        <th>Consignee</th>
                                        <th>Station</th>
                                        <th>Content</th>
                                        <th>Qty</th>
                                        <th>Total</th>
                                        <th>Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quickBiltyRows as $row):
                                        $totalCharge = (float) ($row['total_charge'] ?? 0);
                                        if ($totalCharge <= 0) {
                                            $totalCharge = (float) ($row['freight'] ?? 0) + (float) ($row['p_freight'] ?? 0) + (float) ($row['hammali'] ?? 0) + (float) ($row['brokerage'] ?? 0);
                                        }
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars((string) $row['gr_number']) ?></strong></td>
                                            <td><?= !empty($row['bilty_date']) ? htmlspecialchars(date('d-m-Y', strtotime((string) $row['bilty_date']))) : '-' ?></td>
                                            <td><?= !empty($row['challan_date']) ? htmlspecialchars(date('d-m-Y', strtotime((string) $row['challan_date']))) : '-' ?></td>
                                            <td><?= htmlspecialchars((string) ($row['challan_no'] ?? '-')) ?></td>
                                            <td><span class="badge bg-info"><?= htmlspecialchars((string) ($row['status'] ?? '-')) ?></span></td>
                                            <td><?= htmlspecialchars(capitalizeWords($row['consignor_name'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars(capitalizeWords($row['consignee_name'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars(capitalizeWords($row['to_station'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars(capitalizeWords($row['content'] ?? '')) ?></td>
                                            <td><?= number_format((float) ($row['total_qty'] ?? 0), 0) ?></td>
                                            <td><?= number_format($totalCharge, 0) ?></td>
                                            <td><?= htmlspecialchars(strtoupper((string) ($row['payment_type'] ?? ''))) ?></td>
                                            <td class="text-nowrap">
                                                <a class="btn btn-sm btn-primary quick-action-primary" href="bilty/view/index.php?id=<?= urlencode((string) $row['id']) ?>" target="_blank">View</a>
                                                <button type="button" class="btn btn-sm btn-warning quick-action-warning"
                                                    onclick="confirmEditBilty('<?= htmlspecialchars((string) $row['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars((string) $row['gr_number'], ENT_QUOTES) ?>')">
                                                    Edit
                                                </button>
                                                <a class="btn btn-sm btn-success quick-action-success" href="bilty/print/index.php?id=<?= urlencode((string) $row['id']) ?>" target="_blank">Print</a>
                                            </td>
                                        </tr>
                                        <?php if (!empty($row['eway_bill']) || !empty($row['private_mark']) || !empty($row['remark'])): ?>
                                            <tr class="table-light">
                                                <td colspan="13">
                                                    <?php if (!empty($row['eway_bill'])): ?>
                                                        <strong>E-Way:</strong> <?= htmlspecialchars((string) $row['eway_bill']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['private_mark'])): ?>
                                                        <strong class="ms-3">Private Mark:</strong> <?= htmlspecialchars((string) $row['private_mark']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['remark'])): ?>
                                                        <strong class="ms-3">Remark:</strong> <?= htmlspecialchars((string) $row['remark']) ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-3 mt-4">
            <div class="col-md">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-2"><i class="bi bi-calendar3"></i> Date</h6>
                        <form action="index.php" method="get" class="d-flex gap-1" autocomplete="off">
                            <input type="date" name="report_date" class="form-control form-control-sm"
                                value="<?= htmlspecialchars($reportDate) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Go</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-2"><i class="bi bi-cash-stack"></i> Cash</h6>
                        <div class="daily-report-value">₹<?= number_format($dailyReport['cash_freight'], 0) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-2"><i class="bi bi-journal-check"></i> TBB</h6>
                        <div class="daily-report-value">₹<?= number_format($dailyReport['tbb_freight'], 0) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-2"><i class="bi bi-arrow-repeat"></i> Recovery</h6>
                        <div class="daily-report-value">₹<?= number_format($dailyReport['recovery'], 0) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-2"><i class="bi bi-calculator"></i> Total Freight</h6>
                        <div class="daily-report-value">₹<?= number_format($dailyReport['total_freight'], 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        </section>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        if (window.location.search && new URLSearchParams(window.location.search).has('quick_gr')) {
            const cleanParams = new URLSearchParams(window.location.search);
            cleanParams.delete('quick_gr');
            const cleanQuery = cleanParams.toString();
            window.history.replaceState(null, document.title, window.location.pathname + (cleanQuery ? '?' + cleanQuery : ''));
        }

        function confirmEditBilty(id, grNumber) {
            if (!window.confirm('Edit bilty GR ' + grNumber + '?')) {
                return;
            }

            window.location.href = 'bilty/create/index.php?edit_id=' + encodeURIComponent(id);
        }
    </script>
</body>

</html>
