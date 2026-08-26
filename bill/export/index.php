<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../../protect/db.php";
include "../includes/bill_sync.php";

if (!isset($_SESSION['company_id']) && !isset($_SESSION['admin_login'])) {
    header("Location: ../../login");
    exit;
}

ensureBillsSchema($conn);

$isCompanyUser = isset($_SESSION['company_id']);
$companyIdFilter = $isCompanyUser ? (int) $_SESSION['company_id'] : null;

$billNumber = isset($_GET['bill_number']) ? trim($_GET['bill_number']) : '';
$partyName = isset($_GET['party_name']) ? trim($_GET['party_name']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$billMonth = isset($_GET['bill_month']) ? trim($_GET['bill_month']) : '';
$isExport = isset($_GET['export']) && $_GET['export'] !== '';
$activeTool = isset($_GET['tool']) && in_array($_GET['tool'], ['export', 'import', 'update'], true) ? $_GET['tool'] : 'export';
$sampleType = isset($_GET['sample']) ? trim($_GET['sample']) : '';

if ($sampleType === 'import' || $sampleType === 'update') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'bill_' . $sampleType . '_sample.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Company ID', 'Bill Number', 'Bill Date', 'Party ID', 'Party Name', 'Bill Month', 'Bill Type', 'Amount', 'Status', 'Remarks']);

    if ($sampleType === 'import') {
        $cid = $companyIdFilter ?? 102;
        fputcsv($output, ['', $cid, 'MANUAL-001', date('Y-m-d'), '', 'Sample Party', date('Y-m'), 'MANUAL', '500', 'Pending', 'Sample row']);
    } else {
        fputcsv($output, ['1', $companyIdFilter ?? 102, 'MANUAL-001', date('Y-m-d'), '', 'Updated Party', date('Y-m'), 'MANUAL', '700', 'Paid', 'Updated row']);
    }

    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    $activeTool = $action === 'update_csv' ? 'update' : 'import';

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $flashType = 'danger';
        $flashMessage = 'Please upload a valid CSV file.';
    } else {
        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmpFile, 'r');

        if ($handle === false) {
            $flashType = 'danger';
            $flashMessage = 'Unable to read uploaded CSV file.';
        } else {
            $successCount = 0;
            $failCount = 0;
            fgetcsv($handle);

            if ($action === 'import_csv') {
                $insertStmt = $conn->prepare('INSERT INTO bills (company_id, bill_number, bill_date, party_id, party_name, bill_month, bill_type, amount, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 11) {
                        $failCount++;
                        continue;
                    }

                    $companyIdCsv = (int) trim($data[1]);
                    if ($companyIdFilter !== null) {
                        $companyIdCsv = $companyIdFilter;
                    }
                    $billNoCsv = trim($data[2]);
                    $billDateCsv = trim($data[3]);
                    $partyIdCsv = (int) trim($data[4]);
                    $partyCsv = trim($data[5]);
                    $monthCsv = trim($data[6]);
                    $typeCsv = trim($data[7]);
                    $amountCsv = (int) round((float) trim($data[8]));
                    $statusCsv = trim($data[9]);
                    $remarksCsv = trim($data[10]);

                    if ($companyIdCsv <= 0 || $billNoCsv === '' || $billDateCsv === '' || $partyCsv === '') {
                        $failCount++;
                        continue;
                    }
                    if ($monthCsv === '') {
                        $monthCsv = date('Y-m', strtotime($billDateCsv));
                    }
                    if ($typeCsv === '') {
                        $typeCsv = 'MANUAL';
                    }
                    if ($statusCsv === '') {
                        $statusCsv = 'Pending';
                    }

                    $insertStmt->bind_param('ississsdss', $companyIdCsv, $billNoCsv, $billDateCsv, $partyIdCsv, $partyCsv, $monthCsv, $typeCsv, $amountCsv, $statusCsv, $remarksCsv);
                    if ($insertStmt->execute()) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                $insertStmt->close();
            }

            if ($action === 'update_csv') {
                $updateStmt = $conn->prepare('UPDATE bills SET company_id = ?, bill_number = ?, bill_date = ?, party_id = ?, party_name = ?, bill_month = ?, bill_type = ?, amount = ?, status = ?, remarks = ? WHERE id = ?');

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 11) {
                        $failCount++;
                        continue;
                    }

                    $idCsv = (int) trim($data[0]);
                    $companyIdCsv = (int) trim($data[1]);
                    if ($companyIdFilter !== null) {
                        $companyIdCsv = $companyIdFilter;
                    }
                    $billNoCsv = trim($data[2]);
                    $billDateCsv = trim($data[3]);
                    $partyIdCsv = (int) trim($data[4]);
                    $partyCsv = trim($data[5]);
                    $monthCsv = trim($data[6]);
                    $typeCsv = trim($data[7]);
                    $amountCsv = (int) round((float) trim($data[8]));
                    $statusCsv = trim($data[9]);
                    $remarksCsv = trim($data[10]);

                    if ($idCsv <= 0 || $companyIdCsv <= 0 || $billNoCsv === '' || $billDateCsv === '' || $partyCsv === '') {
                        $failCount++;
                        continue;
                    }
                    if ($monthCsv === '') {
                        $monthCsv = date('Y-m', strtotime($billDateCsv));
                    }
                    if ($typeCsv === '') {
                        $typeCsv = 'MANUAL';
                    }
                    if ($statusCsv === '') {
                        $statusCsv = 'Pending';
                    }

                    $updateStmt->bind_param('ississsdssi', $companyIdCsv, $billNoCsv, $billDateCsv, $partyIdCsv, $partyCsv, $monthCsv, $typeCsv, $amountCsv, $statusCsv, $remarksCsv, $idCsv);
                    if ($updateStmt->execute() && $updateStmt->affected_rows >= 0) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                $updateStmt->close();
            }

            fclose($handle);
            $flashType = $failCount === 0 ? 'success' : 'warning';
            $flashMessage = "Processed: {$successCount} success, {$failCount} failed.";
        }
    }
}

$where = 'WHERE 1 ';
$params = [];
$types = '';

if ($companyIdFilter !== null) {
    $where .= 'AND company_id = ? ';
    $params[] = $companyIdFilter;
    $types .= 'i';
}
if ($billNumber !== '') {
    $where .= 'AND bill_number LIKE ? ';
    $params[] = "%$billNumber%";
    $types .= 's';
}
if ($partyName !== '') {
    $where .= 'AND party_name LIKE ? ';
    $params[] = "%$partyName%";
    $types .= 's';
}
if ($status !== '') {
    $where .= 'AND status = ? ';
    $params[] = $status;
    $types .= 's';
}
if ($billMonth !== '') {
    $where .= 'AND bill_month = ? ';
    $params[] = $billMonth;
    $types .= 's';
}

$baseSql = "SELECT id, company_id, bill_number, bill_date, party_id, party_name, bill_month, bill_type, amount, status, remarks FROM bills $where ORDER BY bill_month DESC, bill_date DESC, id DESC";

if ($isExport) {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $stmt = $conn->prepare($baseSql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $filename = 'bill_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Company ID', 'Bill Number', 'Bill Date', 'Party ID', 'Party Name', 'Bill Month', 'Bill Type', 'Amount', 'Status', 'Remarks']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['id'], $row['company_id'], $row['bill_number'], $row['bill_date'], $row['party_id'], $row['party_name'], $row['bill_month'], $row['bill_type'], $row['amount'], $row['status'], $row['remarks']]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

$previewSql = $baseSql . ' LIMIT 200';
$previewStmt = $conn->prepare($previewSql);
if (!empty($params)) {
    $previewStmt->bind_param($types, ...$params);
}
$previewStmt->execute();
$previewResult = $previewStmt->get_result();

$monthOptions = [];
if ($companyIdFilter !== null) {
    $stmtM = $conn->prepare("SELECT DISTINCT bill_month FROM bills WHERE company_id = ? AND bill_month IS NOT NULL AND bill_month <> '' ORDER BY bill_month DESC");
    $stmtM->bind_param('i', $companyIdFilter);
    $stmtM->execute();
    $resM = $stmtM->get_result();
} else {
    $resM = $conn->query("SELECT DISTINCT bill_month FROM bills WHERE bill_month IS NOT NULL AND bill_month <> '' ORDER BY bill_month DESC");
}
while ($row = $resM->fetch_assoc()) {
    $monthOptions[] = $row['bill_month'];
}
if (isset($stmtM) && $stmtM) {
    $stmtM->close();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bill Export</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .card { border: none; border-radius: 12px; box-shadow: 0 6px 16px rgba(0,0,0,.06); }
        .form-label { font-weight: 600; color: #374151; }
        .nav-tabs .nav-link.active { background: #22c55e; border-color: #22c55e; color: #fff; }
        .nav-tabs .nav-link { color: #166534; font-weight: 600; }
    </style>
</head>
<body>
    <?php include "../../content/nav.php"; ?>

    <div class="container-fluid my-3">
        <div class="card mb-3">
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link export-tool-tab <?= $activeTool === 'export' ? 'active' : '' ?>" data-tool="export"><i class="bi bi-box-arrow-down"></i> Export</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link export-tool-tab <?= $activeTool === 'import' ? 'active' : '' ?>" data-tool="import"><i class="bi bi-upload"></i> Import</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link export-tool-tab <?= $activeTool === 'update' ? 'active' : '' ?>" data-tool="update"><i class="bi bi-arrow-repeat"></i> Update</button></li>
                </ul>

                <?php if (!empty($flashMessage)): ?>
                    <div class="alert alert-<?= htmlspecialchars($flashType ?? 'info') ?> py-2"><?= htmlspecialchars($flashMessage) ?></div>
                <?php endif; ?>

                <div id="tool-pane-export" class="tool-pane <?= $activeTool === 'export' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-filter-square"></i> Bill Export Filters</h5>
                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="export" id="exportFlag" value="">
                        <div class="col-md-3"><label class="form-label">Bill Number</label><input type="text" class="form-control" name="bill_number" value="<?= htmlspecialchars($billNumber) ?>"></div>
                        <div class="col-md-3"><label class="form-label">Party Name</label><input type="text" class="form-control" name="party_name" value="<?= htmlspecialchars($partyName) ?>"></div>
                        <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All</option><option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option><option value="Paid" <?= $status === 'Paid' ? 'selected' : '' ?>>Paid</option><option value="Cancelled" <?= $status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option></select></div>
                        <div class="col-md-2"><label class="form-label">Bill Month</label><select class="form-select" name="bill_month"><option value="">All</option><?php foreach ($monthOptions as $m): ?><option value="<?= htmlspecialchars($m) ?>" <?= $billMonth === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary btn-sm w-100" onclick="document.getElementById('exportFlag').value='';"><i class="bi bi-search"></i> Apply</button><button type="submit" class="btn btn-success btn-sm w-100" onclick="document.getElementById('exportFlag').value='1';"><i class="bi bi-download"></i> CSV</button></div>
                    </form>
                </div>

                <div id="tool-pane-import" class="tool-pane <?= $activeTool === 'import' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-upload"></i> Import Bill CSV</h5>
                    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                        <input type="hidden" name="action" value="import_csv">
                        <div class="col-md-10"><label class="form-label">CSV File</label><input type="file" class="form-control" name="csv_file" accept=".csv" required></div>
                        <div class="col-md-2"><button type="submit" class="btn btn-success w-100"><i class="bi bi-upload"></i> Import</button></div>
                        <div class="col-12"><a href="?sample=import" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Download Sample File</a></div>
                    </form>
                </div>

                <div id="tool-pane-update" class="tool-pane <?= $activeTool === 'update' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Update Bill CSV</h5>
                    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                        <input type="hidden" name="action" value="update_csv">
                        <div class="col-md-10"><label class="form-label">CSV File</label><input type="file" class="form-control" name="csv_file" accept=".csv" required></div>
                        <div class="col-md-2"><button type="submit" class="btn btn-warning w-100"><i class="bi bi-arrow-repeat"></i> Update</button></div>
                        <div class="col-12"><small class="text-muted">Updates by ID from first column. Keep header and ID values unchanged.</small></div>
                        <div class="col-12"><a href="?sample=update" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Download Sample File</a></div>
                    </form>
                </div>
            </div>
        </div>

        <div id="previewCard" class="card <?= $activeTool === 'export' ? '' : 'd-none' ?>">
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Sr</th><th>Bill No</th><th>Month</th><th>Date</th><th>Party</th><th>Amount</th><th>Status</th><th>Type</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($previewResult && $previewResult->num_rows > 0): ?>
                                <?php $totalPreviewRows = (int) $previewResult->num_rows; $sr = 1; ?>
                                <?php while ($row = $previewResult->fetch_assoc()): ?>
                                    <tr class="preview-data-row <?= $sr > 10 ? 'd-none' : '' ?>" data-preview-index="<?= $sr ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><?= htmlspecialchars($row['bill_number']) ?></td>
                                        <td><?= htmlspecialchars($row['bill_month']) ?></td>
                                        <td><?= htmlspecialchars($row['bill_date']) ?></td>
                                        <td><?= htmlspecialchars($row['party_name']) ?></td>
                                        <td><?= htmlspecialchars($row['amount']) ?></td>
                                        <td><?= htmlspecialchars($row['status']) ?></td>
                                        <td><?= htmlspecialchars($row['bill_type']) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center">No data found for selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($totalPreviewRows) && $totalPreviewRows > 10): ?>
                    <div id="previewSeeMoreWrap" class="text-center mt-2"><button type="button" id="previewSeeMoreBtn" class="btn btn-outline-primary btn-sm">See More</button></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const tabs = document.querySelectorAll('.export-tool-tab');
            const panes = { export: document.getElementById('tool-pane-export'), import: document.getElementById('tool-pane-import'), update: document.getElementById('tool-pane-update') };
            const previewCard = document.getElementById('previewCard');
            const seeMoreBtn = document.getElementById('previewSeeMoreBtn');
            const seeMoreWrap = document.getElementById('previewSeeMoreWrap');

            function resetPreviewRows() {
                const previewRows = document.querySelectorAll('.preview-data-row');
                previewRows.forEach(function (row) {
                    const idx = parseInt(row.getAttribute('data-preview-index') || '0', 10);
                    row.classList.toggle('d-none', idx > 10);
                });
                if (seeMoreWrap) {
                    const hiddenRows = document.querySelectorAll('.preview-data-row.d-none').length;
                    seeMoreWrap.classList.toggle('d-none', hiddenRows === 0);
                }
            }

            function activateTool(tool) {
                tabs.forEach(function (btn) { btn.classList.toggle('active', btn.getAttribute('data-tool') === tool); });
                Object.keys(panes).forEach(function (key) { if (panes[key]) panes[key].classList.toggle('d-none', key !== tool); });
                if (previewCard) {
                    previewCard.classList.toggle('d-none', tool !== 'export');
                    if (tool === 'export') resetPreviewRows();
                }
            }

            if (seeMoreBtn) {
                seeMoreBtn.addEventListener('click', function () {
                    const hiddenRows = Array.from(document.querySelectorAll('.preview-data-row.d-none'));
                    hiddenRows.slice(0, 10).forEach(function (row) { row.classList.remove('d-none'); });
                    if (seeMoreWrap) {
                        seeMoreWrap.classList.toggle('d-none', document.querySelectorAll('.preview-data-row.d-none').length === 0);
                    }
                });
            }

            tabs.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activateTool(btn.getAttribute('data-tool') || 'export');
                });
            });

            activateTool('<?= htmlspecialchars($activeTool) ?>');
        })();
    </script>
</body>
</html>
