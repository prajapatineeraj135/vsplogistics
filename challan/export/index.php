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
$stationFilter = trim($_GET['station'] ?? '');
$vehicleFilter = trim($_GET['vehicle_no'] ?? '');
$isExport = isset($_GET['export']) && $_GET['export'] !== '';
$sampleType = trim($_GET['sample'] ?? '');

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

function challanColumnExists($conn, $column)
{
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SELECT COUNT(*) AS cnt
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'challans'
              AND COLUMN_NAME = '{$columnEsc}'";

    $res = $conn->query($sql);
    if (!$res) {
        return false;
    }

    $row = $res->fetch_assoc();
    return (int) ($row['cnt'] ?? 0) > 0;
}

$hasAgentName = challanColumnExists($conn, 'agent_name');
$hasAgentContact = challanColumnExists($conn, 'agent_contact');
$hasOwnerName = challanColumnExists($conn, 'owner_name');
$hasLegacyContact = challanColumnExists($conn, 'contact');

$personNameExpr = "''";
if ($hasAgentName) {
    $personNameExpr = 'COALESCE(agent_name, "")';
} elseif ($hasOwnerName) {
    $personNameExpr = 'COALESCE(owner_name, "")';
}

$personContactExpr = "''";
if ($hasAgentContact) {
    $personContactExpr = 'COALESCE(agent_contact, "")';
} elseif ($hasLegacyContact) {
    $personContactExpr = 'COALESCE(contact, "")';
}

function buildChallanCsvHeaders()
{
    return [
        'id',
        'challan_no',
        'challan_date',
        'challan_station',
        'vehicle_no',
        'driver_name',
        'driver_contact',
        'person_name',
        'person_contact',
        'paid_total',
        'freight_total',
        'recovery_total',
        'cutting_total',
        'commission_total',
        'final_total'
    ];
}

if ($sampleType === 'import' || $sampleType === 'update') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'challan_' . $sampleType . '_sample.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, buildChallanCsvHeaders());

    if ($sampleType === 'import') {
        fputcsv($out, ['', 'CH-001', '12-03-2026', 'Kota', 'RJ20GB1234', 'Ramesh', '9999999999', 'Rohit Agent', '8888888888', '1000', '3500', '500', '100', '50', '3950']);
    } else {
        fputcsv($out, ['1', 'CH-001', '13-03-2026', 'Jaipur', 'RJ20GB5678', 'Suresh', '7777777777', 'Rohit Agent', '8888888888', '1200', '3800', '600', '100', '70', '4230']);
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
                    $key = strtolower(trim((string) $name));
                    $key = preg_replace('/\s+/', '_', $key);
                    $key = preg_replace('/[^a-z0-9_]/', '', $key);
                    $headerIndex[$key] = $idx;
                }

                $required = ['challan_no', 'challan_date', 'challan_station', 'vehicle_no', 'driver_name', 'driver_contact'];
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
                        $checkStmt = $conn->prepare('SELECT COUNT(*) FROM challans WHERE company_id = ? AND challan_no = ?');

                        while (($row = fgetcsv($handle)) !== false) {
                            $challan_no = trim((string) ($row[$headerIndex['challan_no']] ?? ''));
                            $challan_date = parseDateTime($row[$headerIndex['challan_date']] ?? '');
                            $challan_station = trim((string) ($row[$headerIndex['challan_station']] ?? ''));
                            $vehicle_no = trim((string) ($row[$headerIndex['vehicle_no']] ?? ''));
                            $driver_name = trim((string) ($row[$headerIndex['driver_name']] ?? ''));
                            $driver_contact = trim((string) ($row[$headerIndex['driver_contact']] ?? ''));
                            $person_name = trim((string) ($row[$headerIndex['person_name']] ?? ''));
                            $person_contact = trim((string) ($row[$headerIndex['person_contact']] ?? ''));

                            if ($challan_no === '' || $challan_station === '' || $vehicle_no === '' || $driver_name === '' || $driver_contact === '') {
                                $failCount++;
                                continue;
                            }

                            $checkStmt->bind_param('ss', $company_id, $challan_no);
                            $checkStmt->execute();
                            $checkStmt->bind_result($existingCount);
                            $checkStmt->fetch();
                            $checkStmt->free_result();

                            if ((int) $existingCount > 0) {
                                $failCount++;
                                continue;
                            }

                            $paid_total = (int) round((float) ($row[$headerIndex['paid_total']] ?? 0));
                            $freight_total = (int) round((float) ($row[$headerIndex['freight_total']] ?? 0));
                            $recovery_total = (int) round((float) ($row[$headerIndex['recovery_total']] ?? 0));
                            $cutting_total = (int) round((float) ($row[$headerIndex['cutting_total']] ?? 0));
                            $commission_total = (int) round((float) ($row[$headerIndex['commission_total']] ?? 0));
                            $final_total = (int) round((float) ($row[$headerIndex['final_total']] ?? 0));

                            $insertStmt = $conn->prepare("INSERT INTO challans (company_id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, agent_name, agent_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if ($insertStmt) {
                                $insertStmt->bind_param('sssssssssdddddd', $company_id, $challan_no, $challan_date, $challan_station, $vehicle_no, $driver_name, $driver_contact, $person_name, $person_contact, $paid_total, $freight_total, $recovery_total, $cutting_total, $commission_total, $final_total);
                            } else {
                                $insertStmt = $conn->prepare("INSERT INTO challans (company_id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, owner_name, contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                if ($insertStmt) {
                                    $insertStmt->bind_param('sssssssssdddddd', $company_id, $challan_no, $challan_date, $challan_station, $vehicle_no, $driver_name, $driver_contact, $person_name, $person_contact, $paid_total, $freight_total, $recovery_total, $cutting_total, $commission_total, $final_total);
                                } else {
                                    $insertStmt = $conn->prepare("INSERT INTO challans (company_id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact, paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    if ($insertStmt) {
                                        $insertStmt->bind_param('sssssssdddddd', $company_id, $challan_no, $challan_date, $challan_station, $vehicle_no, $driver_name, $driver_contact, $paid_total, $freight_total, $recovery_total, $cutting_total, $commission_total, $final_total);
                                    }
                                }
                            }

                            if ($insertStmt && $insertStmt->execute()) {
                                $successCount++;
                            } else {
                                $failCount++;
                            }

                            if ($insertStmt) {
                                $insertStmt->close();
                            }
                        }

                        $checkStmt->close();
                    }

                    if ($action === 'update_csv') {
                        while (($row = fgetcsv($handle)) !== false) {
                            $id = (int) ($row[$headerIndex['id']] ?? 0);
                            $challan_no = trim((string) ($row[$headerIndex['challan_no']] ?? ''));
                            $challan_date = parseDateTime($row[$headerIndex['challan_date']] ?? '');
                            $challan_station = trim((string) ($row[$headerIndex['challan_station']] ?? ''));
                            $vehicle_no = trim((string) ($row[$headerIndex['vehicle_no']] ?? ''));
                            $driver_name = trim((string) ($row[$headerIndex['driver_name']] ?? ''));
                            $driver_contact = trim((string) ($row[$headerIndex['driver_contact']] ?? ''));
                            $person_name = trim((string) ($row[$headerIndex['person_name']] ?? ''));
                            $person_contact = trim((string) ($row[$headerIndex['person_contact']] ?? ''));

                            if ($id <= 0 || $challan_no === '' || $challan_station === '' || $vehicle_no === '' || $driver_name === '' || $driver_contact === '') {
                                $failCount++;
                                continue;
                            }

                            $paid_total = (int) round((float) ($row[$headerIndex['paid_total']] ?? 0));
                            $freight_total = (int) round((float) ($row[$headerIndex['freight_total']] ?? 0));
                            $recovery_total = (int) round((float) ($row[$headerIndex['recovery_total']] ?? 0));
                            $cutting_total = (int) round((float) ($row[$headerIndex['cutting_total']] ?? 0));
                            $commission_total = (int) round((float) ($row[$headerIndex['commission_total']] ?? 0));
                            $final_total = (int) round((float) ($row[$headerIndex['final_total']] ?? 0));

                            $updateStmt = $conn->prepare("UPDATE challans SET challan_no = ?, challan_date = ?, challan_station = ?, vehicle_no = ?, driver_name = ?, driver_contact = ?, agent_name = ?, agent_contact = ?, paid_total = ?, freight_total = ?, recovery_total = ?, cutting_total = ?, commission_total = ?, final_total = ? WHERE id = ? AND company_id = ?");
                            if ($updateStmt) {
                                $updateStmt->bind_param('ssssssssddddddis', $challan_no, $challan_date, $challan_station, $vehicle_no, $driver_name, $driver_contact, $person_name, $person_contact, $paid_total, $freight_total, $recovery_total, $cutting_total, $commission_total, $final_total, $id, $company_id);
                            } else {
                                $updateStmt = $conn->prepare("UPDATE challans SET challan_no = ?, challan_date = ?, challan_station = ?, vehicle_no = ?, driver_name = ?, driver_contact = ?, owner_name = ?, contact = ?, paid_total = ?, freight_total = ?, recovery_total = ?, cutting_total = ?, commission_total = ?, final_total = ? WHERE id = ? AND company_id = ?");
                                if ($updateStmt) {
                                    $updateStmt->bind_param('ssssssssddddddis', $challan_no, $challan_date, $challan_station, $vehicle_no, $driver_name, $driver_contact, $person_name, $person_contact, $paid_total, $freight_total, $recovery_total, $cutting_total, $commission_total, $final_total, $id, $company_id);
                                } else {
                                    $updateStmt = $conn->prepare("UPDATE challans SET challan_no = ?, challan_date = ?, challan_station = ?, vehicle_no = ?, driver_name = ?, driver_contact = ?, paid_total = ?, freight_total = ?, recovery_total = ?, cutting_total = ?, commission_total = ?, final_total = ? WHERE id = ? AND company_id = ?");
                                    if ($updateStmt) {
                                        $updateStmt->bind_param('ssssssddddddis', $challan_no, $challan_date, $challan_station, $vehicle_no, $driver_name, $driver_contact, $paid_total, $freight_total, $recovery_total, $cutting_total, $commission_total, $final_total, $id, $company_id);
                                    }
                                }
                            }

                            if ($updateStmt && $updateStmt->execute() && $updateStmt->affected_rows >= 0) {
                                $successCount++;
                            } else {
                                $failCount++;
                            }

                            if ($updateStmt) {
                                $updateStmt->close();
                            }
                        }
                    }

                    $flashType = $failCount === 0 ? 'success' : 'warning';
                    $flashMessage = "Processed: {$successCount} success, {$failCount} failed.";
                }
            }

            fclose($handle);
        }
    }
}

$stationOptions = [];
$vehicleOptions = [];
if ($company_id !== '') {
    $stmtStation = $conn->prepare("SELECT DISTINCT challan_station FROM challans WHERE company_id = ? AND challan_station IS NOT NULL AND challan_station <> '' ORDER BY challan_station ASC");
    $stmtStation->bind_param('s', $company_id);
    $stmtStation->execute();
    $resStation = $stmtStation->get_result();
    while ($row = $resStation->fetch_assoc()) {
        $stationOptions[] = $row['challan_station'];
    }
    $stmtStation->close();

    $stmtVehicle = $conn->prepare("SELECT DISTINCT vehicle_no FROM challans WHERE company_id = ? AND vehicle_no IS NOT NULL AND vehicle_no <> '' ORDER BY vehicle_no ASC");
    $stmtVehicle->bind_param('s', $company_id);
    $stmtVehicle->execute();
    $resVehicle = $stmtVehicle->get_result();
    while ($row = $resVehicle->fetch_assoc()) {
        $vehicleOptions[] = $row['vehicle_no'];
    }
    $stmtVehicle->close();
}

$where = 'WHERE company_id = ?';
$params = [$company_id];
$types = 's';

$parsedFromDate = parseDateOnly($fromDate);
$parsedToDate = parseDateOnly($toDate);
if ($parsedFromDate !== '') {
    $where .= ' AND DATE(challan_date) >= ?';
    $params[] = $parsedFromDate;
    $types .= 's';
}
if ($parsedToDate !== '') {
    $where .= ' AND DATE(challan_date) <= ?';
    $params[] = $parsedToDate;
    $types .= 's';
}
if ($stationFilter !== '') {
    $where .= ' AND challan_station LIKE ?';
    $params[] = '%' . $stationFilter . '%';
    $types .= 's';
}
if ($vehicleFilter !== '') {
    $where .= ' AND vehicle_no LIKE ?';
    $params[] = '%' . $vehicleFilter . '%';
    $types .= 's';
}

$baseSql = "SELECT id, challan_no, challan_date, challan_station, vehicle_no, driver_name, driver_contact,
            {$personNameExpr} AS person_name,
            {$personContactExpr} AS person_contact,
            paid_total, freight_total, recovery_total, cutting_total, commission_total, final_total
            FROM challans
            {$where}
            ORDER BY challan_date DESC, id DESC";

if ($isExport) {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $stmt = $conn->prepare($baseSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="challan_export_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, buildChallanCsvHeaders());
    while ($row = $result->fetch_assoc()) {
        $formattedDate = !empty($row['challan_date']) ? date('d-m-Y', strtotime((string) $row['challan_date'])) : '';
        fputcsv($out, [
            $row['id'],
            $row['challan_no'],
            $formattedDate,
            $row['challan_station'],
            $row['vehicle_no'],
            $row['driver_name'],
            $row['driver_contact'],
            $row['person_name'],
            $row['person_contact'],
            formatCompactNumber($row['paid_total'] ?? 0),
            formatCompactNumber($row['freight_total'] ?? 0),
            formatCompactNumber($row['recovery_total'] ?? 0),
            formatCompactNumber($row['cutting_total'] ?? 0),
            formatCompactNumber($row['commission_total'] ?? 0),
            formatCompactNumber($row['final_total'] ?? 0)
        ]);
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
    <title>Challan Export</title>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-filter-square"></i> Challan Export Filters</h5>
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
                            <label class="form-label">Station</label>
                            <select class="form-select" name="station">
                                <option value="">All</option>
                                <?php foreach ($stationOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $stationFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label">Vehicle No</label>
                            <select class="form-select" name="vehicle_no">
                                <option value="">All</option>
                                <?php foreach ($vehicleOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $vehicleFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-upload"></i> Import Challan CSV</h5>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Update Challan CSV</h5>
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
                                <th>Challan No</th>
                                <th>Date</th>
                                <th>Station</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Contact</th>
                                <th>Paid</th>
                                <th>Freight</th>
                                <th>Recovery</th>
                                <th>Final</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($previewResult && $previewResult->num_rows > 0): ?>
                                <?php $totalPreviewRows = (int) $previewResult->num_rows; ?>
                                <?php $sr = 1; ?>
                                <?php while ($row = $previewResult->fetch_assoc()): ?>
                                    <tr class="preview-data-row <?= $sr > 10 ? 'd-none' : '' ?>" data-preview-index="<?= $sr ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><?= htmlspecialchars((string) ($row['challan_no'] ?? '')) ?></td>
                                        <td><?= !empty($row['challan_date']) ? htmlspecialchars(date('d-m-Y', strtotime((string) $row['challan_date']))) : '' ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords((string) ($row['challan_station'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['vehicle_no'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords((string) ($row['driver_name'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['driver_contact'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars(formatCompactNumber($row['paid_total'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars(formatCompactNumber($row['freight_total'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars(formatCompactNumber($row['recovery_total'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars(formatCompactNumber($row['final_total'] ?? 0)) ?></td>
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
