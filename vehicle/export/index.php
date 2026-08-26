<?php
ob_start();
include "../../protect/auth.php";

$company_id = $_SESSION['company_id'] ?? '';

function normalize_vehicle_number($number)
{
    $number = preg_replace('/[^A-Za-z0-9]/', '', $number ?? '');
    return strtoupper($number);
}

function is_valid_vehicle_number($number)
{
    return (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z]{2}\d{4}$/', $number);
}

$vehicleNumber = isset($_GET['vehicle_number']) ? trim($_GET['vehicle_number']) : '';
$driverName = isset($_GET['driver_name']) ? trim($_GET['driver_name']) : '';
$ownerName = isset($_GET['owner_name']) ? trim($_GET['owner_name']) : '';
$mobile = isset($_GET['mobile']) ? trim($_GET['mobile']) : '';
$isExport = isset($_GET['export']) && $_GET['export'] !== '';
$activeTool = isset($_GET['tool']) && in_array($_GET['tool'], ['export', 'import', 'update'], true) ? $_GET['tool'] : 'export';
$sampleType = isset($_GET['sample']) ? trim($_GET['sample']) : '';

if ($sampleType === 'import' || $sampleType === 'update') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'vehicle_' . $sampleType . '_sample.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Vehicle Number', 'Driver Name', 'Owner Name', 'Mobile']);

    if ($sampleType === 'import') {
        fputcsv($output, ['', 'RJ20GB6086', 'Ramesh', 'Suresh', '9876543210']);
    } else {
        fputcsv($output, ['1', 'RJ20GB9999', 'Updated Driver', 'Updated Owner', '9999999999']);
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
                $insertStmt = $conn->prepare('INSERT INTO vehicles (company_id, vehicle_number, driver_name, owner_name, mobile) VALUES (?, ?, ?, ?, ?)');

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 5) {
                        $failCount++;
                        continue;
                    }

                    $vehicleCsv = normalize_vehicle_number($data[1]);
                    $driverCsv = trim($data[2]);
                    $ownerCsv = trim($data[3]);
                    $mobileCsv = preg_replace('/\D+/', '', $data[4]);

                    if ($vehicleCsv === '' || !is_valid_vehicle_number($vehicleCsv) || $driverCsv === '' || $ownerCsv === '') {
                        $failCount++;
                        continue;
                    }

                    if ($mobileCsv !== '' && !preg_match('/^\d{10}$/', $mobileCsv)) {
                        $failCount++;
                        continue;
                    }

                    $insertStmt->bind_param('sssss', $company_id, $vehicleCsv, $driverCsv, $ownerCsv, $mobileCsv);

                    if ($insertStmt->execute()) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                $insertStmt->close();
            }

            if ($action === 'update_csv') {
                $updateStmt = $conn->prepare('UPDATE vehicles SET vehicle_number = ?, driver_name = ?, owner_name = ?, mobile = ? WHERE id = ? AND company_id = ?');

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 5) {
                        $failCount++;
                        continue;
                    }

                    $idCsv = (int) trim($data[0]);
                    $vehicleCsv = normalize_vehicle_number($data[1]);
                    $driverCsv = trim($data[2]);
                    $ownerCsv = trim($data[3]);
                    $mobileCsv = preg_replace('/\D+/', '', $data[4]);

                    if ($idCsv <= 0 || $vehicleCsv === '' || !is_valid_vehicle_number($vehicleCsv) || $driverCsv === '' || $ownerCsv === '') {
                        $failCount++;
                        continue;
                    }

                    if ($mobileCsv !== '' && !preg_match('/^\d{10}$/', $mobileCsv)) {
                        $failCount++;
                        continue;
                    }

                    $updateStmt->bind_param('ssssis', $vehicleCsv, $driverCsv, $ownerCsv, $mobileCsv, $idCsv, $company_id);

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

$where = 'WHERE company_id = ? ';
$params = [$company_id];
$types = 's';

if ($vehicleNumber !== '') {
    $where .= 'AND vehicle_number LIKE ? ';
    $params[] = '%' . normalize_vehicle_number($vehicleNumber) . '%';
    $types .= 's';
}

if ($driverName !== '') {
    $where .= 'AND driver_name LIKE ? ';
    $params[] = "%$driverName%";
    $types .= 's';
}

if ($ownerName !== '') {
    $where .= 'AND owner_name LIKE ? ';
    $params[] = "%$ownerName%";
    $types .= 's';
}

if ($mobile !== '') {
    $where .= 'AND mobile LIKE ? ';
    $params[] = '%' . preg_replace('/\D+/', '', $mobile) . '%';
    $types .= 's';
}

$baseSql = "SELECT id, vehicle_number, driver_name, owner_name, mobile, created_at FROM vehicles $where ORDER BY created_at DESC";

if ($isExport) {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $stmt = $conn->prepare($baseSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $filename = 'vehicle_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Vehicle Number', 'Driver Name', 'Owner Name', 'Mobile']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['vehicle_number'],
            $row['driver_name'],
            $row['owner_name'],
            $row['mobile']
        ]);
    }

    fclose($output);
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
    <title>Vehicle Export</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f4f6f9;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .form-label {
            font-weight: 600;
            color: #374151;
        }

        .nav-tabs .nav-link.active {
            background: #22c55e;
            border-color: #22c55e;
            color: #ffffff;
        }

        .nav-tabs .nav-link {
            color: #166534;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <?php include "../../content/nav.php"; ?>

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

                <?php if (!empty($flashMessage)): ?>
                    <div class="alert alert-<?= htmlspecialchars($flashType ?? 'info') ?> py-2">
                        <?= htmlspecialchars($flashMessage) ?>
                    </div>
                <?php endif; ?>

                <div id="tool-pane-export" class="tool-pane <?= $activeTool === 'export' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-filter-square"></i> Vehicle Export Filters</h5>
                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="export" id="exportFlag" value="">

                        <div class="col-md-3">
                            <label class="form-label">Vehicle Number</label>
                            <input type="text" class="form-control" name="vehicle_number" value="<?= htmlspecialchars($vehicleNumber) ?>" placeholder="RJ20GB6086">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Driver Name</label>
                            <input type="text" class="form-control" name="driver_name" value="<?= htmlspecialchars($driverName) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Owner Name</label>
                            <input type="text" class="form-control" name="owner_name" value="<?= htmlspecialchars($ownerName) ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Mobile</label>
                            <input type="text" class="form-control" name="mobile" value="<?= htmlspecialchars($mobile) ?>">
                        </div>

                        <div class="col-md-1 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100" onclick="document.getElementById('exportFlag').value='';">
                                <i class="bi bi-search"></i>
                            </button>
                            <button type="submit" class="btn btn-success btn-sm w-100" onclick="document.getElementById('exportFlag').value='1';">
                                <i class="bi bi-download"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <div id="tool-pane-import" class="tool-pane <?= $activeTool === 'import' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-upload"></i> Import Vehicle CSV</h5>
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
                            <a href="?sample=import" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-download"></i> Download Sample File
                            </a>
                        </div>
                    </form>
                </div>

                <div id="tool-pane-update" class="tool-pane <?= $activeTool === 'update' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Update Vehicle CSV</h5>
                    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                        <input type="hidden" name="action" value="update_csv">
                        <div class="col-md-10">
                            <label class="form-label">CSV File</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-warning w-100"><i class="bi bi-arrow-repeat"></i> Update</button>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">Updates by ID from first column. Keep header and ID values unchanged.</small>
                        </div>
                        <div class="col-12">
                            <a href="?sample=update" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-download"></i> Download Sample File
                            </a>
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
                                <th>Vehicle Number</th>
                                <th>Driver</th>
                                <th>Owner</th>
                                <th>Mobile</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($previewResult && $previewResult->num_rows > 0): ?>
                                <?php $totalPreviewRows = (int) $previewResult->num_rows; ?>
                                <?php $sr = 1; ?>
                                <?php while ($row = $previewResult->fetch_assoc()): ?>
                                    <tr class="preview-data-row <?= $sr > 10 ? 'd-none' : '' ?>" data-preview-index="<?= $sr ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><?= htmlspecialchars($row['vehicle_number']) ?></td>
                                        <td><?= htmlspecialchars($row['driver_name']) ?></td>
                                        <td><?= htmlspecialchars($row['owner_name']) ?></td>
                                        <td><?= htmlspecialchars($row['mobile']) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No data found for selected filters.</td>
                                </tr>
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
        (function() {
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
                previewRows.forEach(function(row) {
                    const idx = parseInt(row.getAttribute('data-preview-index') || '0', 10);
                    row.classList.toggle('d-none', idx > 10);
                });

                if (seeMoreWrap) {
                    const hiddenRows = document.querySelectorAll('.preview-data-row.d-none').length;
                    seeMoreWrap.classList.toggle('d-none', hiddenRows === 0);
                }
            }

            function activateTool(tool) {
                tabs.forEach(function(btn) {
                    btn.classList.toggle('active', btn.getAttribute('data-tool') === tool);
                });

                Object.keys(panes).forEach(function(key) {
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
                seeMoreBtn.addEventListener('click', function() {
                    const hiddenRows = Array.from(document.querySelectorAll('.preview-data-row.d-none'));
                    hiddenRows.slice(0, 10).forEach(function(row) {
                        row.classList.remove('d-none');
                    });

                    if (seeMoreWrap) {
                        seeMoreWrap.classList.toggle('d-none', document.querySelectorAll('.preview-data-row.d-none').length === 0);
                    }
                });
            }

            tabs.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const tool = btn.getAttribute('data-tool') || 'export';
                    activateTool(tool);
                });
            });

            activateTool('<?= htmlspecialchars($activeTool) ?>');
        })();
    </script>
</body>

</html>
