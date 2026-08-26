<?php
ob_start();
include '../../protect/auth.php';
include '../../protect/db.php';
include '../../protect/case_converter.php';

$company_id = $_SESSION['company_id'] ?? '';
$activeTool = isset($_GET['tool']) && in_array($_GET['tool'], ['export', 'import', 'update'], true) ? $_GET['tool'] : 'export';
$flashType = '';
$flashMessage = '';

$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$consigneeFilter = trim($_GET['consignee'] ?? '');
$stationFilter = trim($_GET['station'] ?? '');
$isExport = isset($_GET['export']) && $_GET['export'] !== '';
$sampleType = trim($_GET['sample'] ?? '');

function normalizeHeader($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/\s+/', '_', $value);
    $value = preg_replace('/[^a-z0-9_]/', '', $value);
    return $value;
}

function parseDateTime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }
    $formats = ['d-m-Y', 'd-m-Y H:i', 'd-m-Y H:i:s', 'Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return date('Y-m-d H:i:s');
}

function parseDateOnly($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $formats = ['d-m-Y', 'Y-m-d'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }
    return '';
}

function formatCompactNumber($value)
{
    $number = (float) $value;
    return (string) (int) round($number);
}

function buildBiltyCsvHeaders()
{
    return [
        'id', 'gr_number', 'bilty_date', 'consignor_name', 'consignee_name', 'to_station', 'payment_type',
        'freight', 'hammali', 'p_freight', 'brokerage', 'dd_charge', 'gr_charge', 'total_charge',
        'invoice_number', 'invoice_value', 'eway_bill', 'private_mark', 'remark', 'delivery_location',
        'total_qty', 'total_weight', 'status'
    ];
}

if ($sampleType === 'import' || $sampleType === 'update') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'bilty_' . $sampleType . '_sample.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, buildBiltyCsvHeaders());

    if ($sampleType === 'import') {
        fputcsv($out, ['', 'GR12345', '12-02-2026', 'ABC Traders', 'XYZ Store', 'Kota', 'Topay', '1000', '50', '0', '0', '0', '0', '1050', 'INV-1001', '50000', 'EWAY123', 'PM-1', 'Handle with care', 'Godown', '10', '250', 'Booked']);
    } else {
        fputcsv($out, ['1', 'GR12345', '14-02-2026', 'ABC Traders Updated', 'XYZ Store Updated', 'Jaipur', 'TBB', '1200', '60', '0', '0', '0', '0', '1260', 'INV-1002', '55000', 'EWAY999', 'PM-2', 'Updated remark', 'Branch', '12', '300', 'Dispatch']);
    }

    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string) $_POST['action']);
    $activeTool = $action === 'update_csv' ? 'update' : 'import';

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $flashType = 'danger';
        $flashMessage = 'Please upload a valid CSV file.';
    } else {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($handle === false) {
            $flashType = 'danger';
            $flashMessage = 'Unable to read uploaded CSV file.';
        } else {
            $headerRow = fgetcsv($handle);
            if (!$headerRow) {
                $flashType = 'danger';
                $flashMessage = 'CSV header row not found.';
            } else {
                $headerIndex = [];
                foreach ($headerRow as $idx => $name) {
                    $headerIndex[normalizeHeader($name)] = $idx;
                }

                $required = ['gr_number', 'consignor_name', 'consignee_name', 'to_station'];
                if ($action === 'update_csv') {
                    $required[] = 'id';
                }

                $missing = array_filter($required, function ($header) use ($headerIndex) {
                    return !array_key_exists($header, $headerIndex);
                });

                if (!empty($missing)) {
                    $flashType = 'danger';
                    $flashMessage = 'Missing required columns: ' . implode(', ', $missing);
                } else {
                    $successCount = 0;
                    $failCount = 0;

                    if ($action === 'import_csv') {
                        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM biltys WHERE company_id = ? AND gr_number = ?");
                        $insertStmt = $conn->prepare("INSERT INTO biltys (consignor_id, consignor_name, consignee_id, consignee_name, to_station, gr_number, company_id, invoice_number, invoice_value, eway_bill, private_mark, remark, delivery_location, freight, hammali, p_freight, brokerage, dd_charge, gr_charge, total_charge, payment_type, bilty_date, total_qty, total_weight, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");

                        while (($row = fgetcsv($handle)) !== false) {
                            $gr_number = trim((string) ($row[$headerIndex['gr_number']] ?? ''));
                            $consignor_name = trim((string) ($row[$headerIndex['consignor_name']] ?? ''));
                            $consignee_name = trim((string) ($row[$headerIndex['consignee_name']] ?? ''));
                            $to_station = trim((string) ($row[$headerIndex['to_station']] ?? ''));

                            if ($gr_number === '' || $consignor_name === '' || $consignee_name === '' || $to_station === '') {
                                $failCount++;
                                continue;
                            }

                            $checkStmt->bind_param('ss', $company_id, $gr_number);
                            $checkStmt->execute();
                            $checkStmt->bind_result($existingCount);
                            $checkStmt->fetch();
                            $checkStmt->free_result();

                            if ((int) $existingCount > 0) {
                                $failCount++;
                                continue;
                            }

                            $consignor_id = (int) ($row[$headerIndex['consignor_id']] ?? 0);
                            $consignee_id = (int) ($row[$headerIndex['consignee_id']] ?? 0);
                            $invoice_number = trim((string) ($row[$headerIndex['invoice_number']] ?? ''));
                            $invoice_value = (int) round((float) ($row[$headerIndex['invoice_value']] ?? 0));
                            $eway_bill = trim((string) ($row[$headerIndex['eway_bill']] ?? ''));
                            $private_mark = trim((string) ($row[$headerIndex['private_mark']] ?? ''));
                            $remark = trim((string) ($row[$headerIndex['remark']] ?? ''));
                            $delivery_location = trim((string) ($row[$headerIndex['delivery_location']] ?? 'G'));
                            $freight = (int) round((float) ($row[$headerIndex['freight']] ?? 0));
                            $hammali = (int) round((float) ($row[$headerIndex['hammali']] ?? 0));
                            $p_freight = (int) round((float) ($row[$headerIndex['p_freight']] ?? 0));
                            $brokerage = (int) round((float) ($row[$headerIndex['brokerage']] ?? 0));
                            $dd_charge = (int) round((float) ($row[$headerIndex['dd_charge']] ?? 0));
                            $gr_charge = (int) round((float) ($row[$headerIndex['gr_charge']] ?? 0));
                            $total_charge = (int) round((float) ($row[$headerIndex['total_charge']] ?? 0));
                            $payment_type = trim((string) ($row[$headerIndex['payment_type']] ?? 'Topay'));
                            $bilty_date = parseDateTime($row[$headerIndex['bilty_date']] ?? '');
                            $total_qty = (int) round((float) ($row[$headerIndex['total_qty']] ?? 0));
                            $total_weight = (int) round((float) ($row[$headerIndex['total_weight']] ?? 0));
                            $status = trim((string) ($row[$headerIndex['status']] ?? 'Booked'));

                            $bindTypes = 'isisssssdssssdddddddssdds';
                            $insertStmt->bind_param($bindTypes, $consignor_id, $consignor_name, $consignee_id, $consignee_name, $to_station, $gr_number, $company_id, $invoice_number, $invoice_value, $eway_bill, $private_mark, $remark, $delivery_location, $freight, $hammali, $p_freight, $brokerage, $dd_charge, $gr_charge, $total_charge, $payment_type, $bilty_date, $total_qty, $total_weight, $status);

                            if ($insertStmt->execute()) {
                                $successCount++;
                            } else {
                                $failCount++;
                            }
                        }

                        $checkStmt->close();
                        $insertStmt->close();
                    }

                    if ($action === 'update_csv') {
                        $updateStmt = $conn->prepare("UPDATE biltys SET consignor_id = ?, consignor_name = ?, consignee_id = ?, consignee_name = ?, to_station = ?, gr_number = ?, invoice_number = ?, invoice_value = ?, eway_bill = ?, private_mark = ?, remark = ?, delivery_location = ?, freight = ?, hammali = ?, p_freight = ?, brokerage = ?, dd_charge = ?, gr_charge = ?, total_charge = ?, payment_type = ?, bilty_date = ?, total_qty = ?, total_weight = ?, status = ? WHERE id = ? AND company_id = ?");

                        while (($row = fgetcsv($handle)) !== false) {
                            $id = (int) ($row[$headerIndex['id']] ?? 0);
                            $gr_number = trim((string) ($row[$headerIndex['gr_number']] ?? ''));
                            $consignor_name = trim((string) ($row[$headerIndex['consignor_name']] ?? ''));
                            $consignee_name = trim((string) ($row[$headerIndex['consignee_name']] ?? ''));
                            $to_station = trim((string) ($row[$headerIndex['to_station']] ?? ''));

                            if ($id <= 0 || $gr_number === '' || $consignor_name === '' || $consignee_name === '' || $to_station === '') {
                                $failCount++;
                                continue;
                            }

                            $consignor_id = (int) ($row[$headerIndex['consignor_id']] ?? 0);
                            $consignee_id = (int) ($row[$headerIndex['consignee_id']] ?? 0);
                            $invoice_number = trim((string) ($row[$headerIndex['invoice_number']] ?? ''));
                            $invoice_value = (int) round((float) ($row[$headerIndex['invoice_value']] ?? 0));
                            $eway_bill = trim((string) ($row[$headerIndex['eway_bill']] ?? ''));
                            $private_mark = trim((string) ($row[$headerIndex['private_mark']] ?? ''));
                            $remark = trim((string) ($row[$headerIndex['remark']] ?? ''));
                            $delivery_location = trim((string) ($row[$headerIndex['delivery_location']] ?? 'G'));
                            $freight = (int) round((float) ($row[$headerIndex['freight']] ?? 0));
                            $hammali = (int) round((float) ($row[$headerIndex['hammali']] ?? 0));
                            $p_freight = (int) round((float) ($row[$headerIndex['p_freight']] ?? 0));
                            $brokerage = (int) round((float) ($row[$headerIndex['brokerage']] ?? 0));
                            $dd_charge = (int) round((float) ($row[$headerIndex['dd_charge']] ?? 0));
                            $gr_charge = (int) round((float) ($row[$headerIndex['gr_charge']] ?? 0));
                            $total_charge = (int) round((float) ($row[$headerIndex['total_charge']] ?? 0));
                            $payment_type = trim((string) ($row[$headerIndex['payment_type']] ?? 'Topay'));
                            $bilty_date = parseDateTime($row[$headerIndex['bilty_date']] ?? '');
                            $total_qty = (int) round((float) ($row[$headerIndex['total_qty']] ?? 0));
                            $total_weight = (int) round((float) ($row[$headerIndex['total_weight']] ?? 0));
                            $status = trim((string) ($row[$headerIndex['status']] ?? 'Booked'));

                            $bindTypes = 'isissssdssssdddddddssddssii';
                            $updateStmt->bind_param($bindTypes, $consignor_id, $consignor_name, $consignee_id, $consignee_name, $to_station, $gr_number, $invoice_number, $invoice_value, $eway_bill, $private_mark, $remark, $delivery_location, $freight, $hammali, $p_freight, $brokerage, $dd_charge, $gr_charge, $total_charge, $payment_type, $bilty_date, $total_qty, $total_weight, $status, $id, $company_id);

                            if ($updateStmt->execute() && $updateStmt->affected_rows >= 0) {
                                $successCount++;
                            } else {
                                $failCount++;
                            }
                        }

                        $updateStmt->close();
                    }

                    $flashType = $failCount === 0 ? 'success' : 'warning';
                    $flashMessage = "Processed: {$successCount} success, {$failCount} failed.";
                }
            }

            fclose($handle);
        }
    }
}

$consigneeOptions = [];
$stationOptions = [];
if ($company_id !== '') {
    $stmtConsignee = $conn->prepare("SELECT DISTINCT consignee_name FROM biltys WHERE company_id = ? AND status <> 'Trash' AND consignee_name IS NOT NULL AND consignee_name <> '' ORDER BY consignee_name ASC");
    $stmtConsignee->bind_param('s', $company_id);
    $stmtConsignee->execute();
    $resConsignee = $stmtConsignee->get_result();
    while ($row = $resConsignee->fetch_assoc()) {
        $consigneeOptions[] = $row['consignee_name'];
    }
    $stmtConsignee->close();

    $stmtStation = $conn->prepare("SELECT DISTINCT to_station FROM biltys WHERE company_id = ? AND status <> 'Trash' AND to_station IS NOT NULL AND to_station <> '' ORDER BY to_station ASC");
    $stmtStation->bind_param('s', $company_id);
    $stmtStation->execute();
    $resStation = $stmtStation->get_result();
    while ($row = $resStation->fetch_assoc()) {
        $stationOptions[] = $row['to_station'];
    }
    $stmtStation->close();
}

$where = "WHERE company_id = ? AND status <> 'Trash'";
$params = [$company_id];
$types = 's';

$parsedFromDate = parseDateOnly($fromDate);
$parsedToDate = parseDateOnly($toDate);
if ($parsedFromDate !== '') {
    $where .= ' AND DATE(bilty_date) >= ?';
    $params[] = $parsedFromDate;
    $types .= 's';
}
if ($parsedToDate !== '') {
    $where .= ' AND DATE(bilty_date) <= ?';
    $params[] = $parsedToDate;
    $types .= 's';
}
if ($consigneeFilter !== '') {
    $where .= ' AND consignee_name LIKE ?';
    $params[] = '%' . $consigneeFilter . '%';
    $types .= 's';
}
if ($stationFilter !== '') {
    $where .= ' AND to_station LIKE ?';
    $params[] = '%' . $stationFilter . '%';
    $types .= 's';
}

$baseSql = "SELECT id, gr_number, bilty_date, consignor_name, consignee_name, to_station, payment_type, total_charge, total_qty, total_weight, status FROM biltys {$where} ORDER BY bilty_date DESC, id DESC";

if ($isExport) {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $stmt = $conn->prepare("SELECT id, gr_number, bilty_date, consignor_name, consignee_name, to_station, payment_type, freight, hammali, p_freight, brokerage, dd_charge, gr_charge, total_charge, invoice_number, invoice_value, eway_bill, private_mark, remark, delivery_location, total_qty, total_weight, status FROM biltys {$where} ORDER BY bilty_date DESC, id DESC");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bilty_export_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, buildBiltyCsvHeaders());
    while ($row = $result->fetch_assoc()) {
        $row['bilty_date'] = !empty($row['bilty_date']) ? date('d-m-Y', strtotime((string) $row['bilty_date'])) : '';
        fputcsv($out, [$row['id'], $row['gr_number'], $row['bilty_date'], $row['consignor_name'], $row['consignee_name'], $row['to_station'], $row['payment_type'], formatCompactNumber($row['freight'] ?? 0), formatCompactNumber($row['hammali'] ?? 0), formatCompactNumber($row['p_freight'] ?? 0), formatCompactNumber($row['brokerage'] ?? 0), formatCompactNumber($row['dd_charge'] ?? 0), formatCompactNumber($row['gr_charge'] ?? 0), formatCompactNumber($row['total_charge'] ?? 0), $row['invoice_number'], formatCompactNumber($row['invoice_value'] ?? 0), $row['eway_bill'], $row['private_mark'], $row['remark'], $row['delivery_location'], formatCompactNumber($row['total_qty'] ?? 0), formatCompactNumber($row['total_weight'] ?? 0), $row['status']]);
    }
    fclose($out);
    $stmt->close();
    exit;
}

$previewSql = $baseSql . ' LIMIT 200';
$previewStmt = $conn->prepare($previewSql);
$previewStmt->bind_param($types, ...$params);
$previewStmt->execute();
$previewResult = $previewStmt->get_result();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bilty Export</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      
        body { background: #f4f6f9; }
        .card { border: none; border-radius: 12px; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06); }
        .form-label { font-weight: 600; color: #374151; }
        .nav-tabs .nav-link.active,
        .nav-pills .nav-link.active { background: #22c55e; border-color: #22c55e; color: #ffffff; }
        .nav-tabs .nav-link,
        .nav-pills .nav-link { color: #166534; font-weight: 600; }
    </style>
</head>
<body>
    <?php include '../../content/nav.php'; ?>

    <div class="container-fluid my-3">
        <div class="card mb-3">
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link export-tool-tab <?= $activeTool === 'export' ? 'active' : '' ?>" data-tool="export" role="tab">
                            <i class="bi bi-box-arrow-down"></i> Export
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link export-tool-tab <?= $activeTool === 'import' ? 'active' : '' ?>" data-tool="import" role="tab">
                            <i class="bi bi-upload"></i> Import
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link export-tool-tab <?= $activeTool === 'update' ? 'active' : '' ?>" data-tool="update" role="tab">
                            <i class="bi bi-arrow-repeat"></i> Update
                        </button>
                    </li>
                </ul>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert alert-<?= htmlspecialchars($flashType !== '' ? $flashType : 'info') ?> py-2">
                        <?= htmlspecialchars($flashMessage) ?>
                    </div>
                <?php endif; ?>

                <div id="tool-pane-export" class="tool-pane <?= $activeTool === 'export' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-filter-square"></i> Bilty Export Filters</h5>
                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="export" id="exportFlag" value="">
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label">Consignee</label>
                            <select class="form-select" name="consignee">
                                <option value="">All</option>
                                <?php foreach ($consigneeOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $consigneeFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label">Station</label>
                            <select class="form-select" name="station">
                                <option value="">All</option>
                                <?php foreach ($stationOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $stationFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100" onclick="document.getElementById('exportFlag').value='';"><i class="bi bi-search"></i> Apply</button>
                            <button type="submit" class="btn btn-success btn-sm w-100" onclick="document.getElementById('exportFlag').value='1';"><i class="bi bi-download"></i> CSV</button>
                        </div>
                    </form>
                </div>

                <div id="tool-pane-import" class="tool-pane <?= $activeTool === 'import' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-upload"></i> Import Bilty CSV</h5>
                    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                        <input type="hidden" name="action" value="import_csv">
                        <div class="col-md-10">
                            <label class="form-label">CSV File</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success w-100"><i class="bi bi-upload"></i> Import</button>
                        </div>
                        <div class="col-12">
                            <a href="?sample=import" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Download Sample File</a>
                        </div>
                    </form>
                </div>

                <div id="tool-pane-update" class="tool-pane <?= $activeTool === 'update' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Update Bilty CSV</h5>
                    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                        <input type="hidden" name="action" value="update_csv">
                        <div class="col-md-10">
                            <label class="form-label">CSV File</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-warning w-100"><i class="bi bi-arrow-repeat"></i> Update</button>
                        </div>
                        <div class="col-12"><small class="text-muted">Updates by ID from first column. Keep header and ID values unchanged.</small></div>
                        <div class="col-12">
                            <a href="?sample=update" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Download Sample File</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="previewCard" class="card <?= $activeTool === 'export' ? '' : 'd-none' ?>">
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sr</th>
                                <th>GR Number</th>
                                <th>Date</th>
                                <th>Consignor</th>
                                <th>Consignee</th>
                                <th>Station</th>
                                <th>Qty</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($previewResult && $previewResult->num_rows > 0): ?>
                                <?php $totalPreviewRows = (int) $previewResult->num_rows; ?>
                                <?php $sr = 1; ?>
                                <?php while ($row = $previewResult->fetch_assoc()): ?>
                                    <tr class="preview-data-row <?= $sr > 10 ? 'd-none' : '' ?>" data-preview-index="<?= $sr ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><?= htmlspecialchars((string) ($row['gr_number'] ?? '')) ?></td>
                                        <td><?= !empty($row['bilty_date']) ? htmlspecialchars(date('d-m-Y', strtotime((string) $row['bilty_date']))) : '' ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords((string) ($row['consignor_name'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords((string) ($row['consignee_name'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords((string) ($row['to_station'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars(formatCompactNumber($row['total_qty'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars(formatCompactNumber($row['total_charge'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords((string) ($row['payment_type'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="11" class="text-center">No data found for selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($totalPreviewRows) && $totalPreviewRows > 10): ?>
                    <div id="previewSeeMoreWrap" class="text-center mt-2">
                        <button type="button" id="previewSeeMoreBtn" class="btn btn-outline-primary btn-sm">See More</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const tabs = document.querySelectorAll('.export-tool-tab');
            const panes = {
                export: document.getElementById('tool-pane-export'),
                import: document.getElementById('tool-pane-import'),
                update: document.getElementById('tool-pane-update')
            };
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
                tabs.forEach(function (btn) {
                    btn.classList.toggle('active', btn.getAttribute('data-tool') === tool);
                });

                Object.keys(panes).forEach(function (key) {
                    if (!panes[key]) return;
                    panes[key].classList.toggle('d-none', key !== tool);
                });

                if (previewCard) {
                    previewCard.classList.toggle('d-none', tool !== 'export');
                    if (tool === 'export') {
                        resetPreviewRows();
                    }
                }
            }

            if (seeMoreBtn) {
                seeMoreBtn.addEventListener('click', function () {
                    const hiddenRows = Array.from(document.querySelectorAll('.preview-data-row.d-none'));
                    hiddenRows.slice(0, 10).forEach(function (row) {
                        row.classList.remove('d-none');
                    });

                    if (seeMoreWrap) {
                        seeMoreWrap.classList.toggle('d-none', document.querySelectorAll('.preview-data-row.d-none').length === 0);
                    }
                });
            }

            tabs.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const tool = btn.getAttribute('data-tool') || 'export';
                    activateTool(tool);
                });
            });

            activateTool('<?= htmlspecialchars($activeTool) ?>');
        })();
    </script>
</body>
</html>
