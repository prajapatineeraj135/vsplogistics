<?php
ob_start();
include "../../protect/db.php";

$partyType = isset($_GET['party_type']) ? trim($_GET['party_type']) : '';
$biltyType = isset($_GET['bilty_type']) ? trim($_GET['bilty_type']) : '';
$station = isset($_GET['station']) ? trim($_GET['station']) : '';
$isExport = isset($_GET['export']) && $_GET['export'] !== '';
$activeTool = isset($_GET['tool']) && in_array($_GET['tool'], ['export', 'import', 'update'], true) ? $_GET['tool'] : 'export';
$sampleType = isset($_GET['sample']) ? trim($_GET['sample']) : '';

if ($sampleType === 'import' || $sampleType === 'update') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'party_' . $sampleType . '_sample.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID',
        'Party Type',
        'Bilty Type',
        'Name',
        'Contact',
        'Station',
        'Address 1',
        'Address 2',
        'Pincode',
        'City',
        'State'
    ]);

    if ($sampleType === 'import') {
        fputcsv($output, ['', 'Consignee', '', 'Sample Party', '9999999999', 'Kota', 'Address line 1', 'Address line 2', '324001', 'Kota', 'Rajasthan']);
    } else {
        fputcsv($output, ['1', 'Consignor', 'tbb', 'Updated Party Name', '8888888888', 'Kota', 'New address 1', 'New address 2', '324002', 'Kota', 'Rajasthan']);
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
            $rowNumber = 0;
            $successCount = 0;
            $failCount = 0;

            // Skip header row
            fgetcsv($handle);

            if ($action === 'import_csv') {
                $insertStmt = $conn->prepare("INSERT INTO party (party_type, bilty_type, name, contact, station, address1, address2, pincode, city, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                while (($data = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    if (count($data) < 11) {
                        $failCount++;
                        continue;
                    }

                    $partyTypeCsv = trim($data[1]);
                    $biltyTypeCsv = trim($data[2]);
                    $nameCsv = trim($data[3]);
                    $contactCsv = trim($data[4]);
                    $stationCsv = trim($data[5]);
                    $address1Csv = trim($data[6]);
                    $address2Csv = trim($data[7]);
                    $pincodeCsv = trim($data[8]);
                    $cityCsv = trim($data[9]);
                    $stateCsv = trim($data[10]);

                    if ($nameCsv === '') {
                        $failCount++;
                        continue;
                    }

                    $insertStmt->bind_param(
                        'ssssssssss',
                        $partyTypeCsv,
                        $biltyTypeCsv,
                        $nameCsv,
                        $contactCsv,
                        $stationCsv,
                        $address1Csv,
                        $address2Csv,
                        $pincodeCsv,
                        $cityCsv,
                        $stateCsv
                    );

                    if ($insertStmt->execute()) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                $insertStmt->close();
            }

            if ($action === 'update_csv') {
                $updateStmt = $conn->prepare("UPDATE party SET party_type = ?, bilty_type = ?, name = ?, contact = ?, station = ?, address1 = ?, address2 = ?, pincode = ?, city = ?, state = ? WHERE id = ?");

                while (($data = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    if (count($data) < 11) {
                        $failCount++;
                        continue;
                    }

                    $idCsv = (int) trim($data[0]);
                    $partyTypeCsv = trim($data[1]);
                    $biltyTypeCsv = trim($data[2]);
                    $nameCsv = trim($data[3]);
                    $contactCsv = trim($data[4]);
                    $stationCsv = trim($data[5]);
                    $address1Csv = trim($data[6]);
                    $address2Csv = trim($data[7]);
                    $pincodeCsv = trim($data[8]);
                    $cityCsv = trim($data[9]);
                    $stateCsv = trim($data[10]);

                    if ($idCsv <= 0 || $nameCsv === '') {
                        $failCount++;
                        continue;
                    }

                    $updateStmt->bind_param(
                        'ssssssssssi',
                        $partyTypeCsv,
                        $biltyTypeCsv,
                        $nameCsv,
                        $contactCsv,
                        $stationCsv,
                        $address1Csv,
                        $address2Csv,
                        $pincodeCsv,
                        $cityCsv,
                        $stateCsv,
                        $idCsv
                    );

                    if ($updateStmt->execute() && $updateStmt->affected_rows >= 0) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                $updateStmt->close();
            }

            fclose($handle);

            if ($failCount === 0) {
                $flashType = 'success';
            } else {
                $flashType = 'warning';
            }

            $flashMessage = "Processed: {$successCount} success, {$failCount} failed.";
        }
    }
}

$where = "WHERE 1 ";
$params = [];
$types = '';

if ($partyType !== '') {
    $where .= "AND party_type = ? ";
    $params[] = $partyType;
    $types .= 's';
}

if ($biltyType !== '') {
    $where .= "AND bilty_type = ? ";
    $params[] = $biltyType;
    $types .= 's';
}

if ($station !== '') {
    $where .= "AND station = ? ";
    $params[] = $station;
    $types .= 's';
}

$baseSql = "SELECT id, party_type, bilty_type, name, contact, station, address1, address2, pincode, city, state
            FROM party
            $where
            ORDER BY party_type ASC, bilty_type ASC, name ASC";

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

    $filename = 'party_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID',
        'Party Type',
        'Bilty Type',
        'Name',
        'Contact',
        'Station',
        'Address 1',
        'Address 2',
        'Pincode',
        'City',
        'State'
    ]);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['party_type'],
            $row['bilty_type'],
            $row['name'],
            $row['contact'],
            $row['station'],
            $row['address1'],
            $row['address2'],
            $row['pincode'],
            $row['city'],
            $row['state']
        ]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

$previewResult = null;
$previewSql = $baseSql . " LIMIT 200";
$previewStmt = $conn->prepare($previewSql);
if (!empty($params)) {
    $previewStmt->bind_param($types, ...$params);
}
$previewStmt->execute();
$previewResult = $previewStmt->get_result();

$biltyOptions = [];
$biltyRes = $conn->query("SELECT DISTINCT bilty_type FROM party WHERE bilty_type IS NOT NULL AND bilty_type <> '' ORDER BY bilty_type ASC");
while ($row = $biltyRes->fetch_assoc()) {
    $biltyOptions[] = $row['bilty_type'];
}

$stationOptions = [];
$stationRes = $conn->query("SELECT DISTINCT station FROM party WHERE station IS NOT NULL AND station <> '' ORDER BY station ASC");
while ($row = $stationRes->fetch_assoc()) {
    $stationOptions[] = $row['station'];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Party Export</title>
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

        .nav-tabs .nav-link.active,
        .nav-pills .nav-link.active {
            background: #22c55e;
            border-color: #22c55e;
            color: #ffffff;
        }

        .nav-tabs .nav-link,
        .nav-pills .nav-link {
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-filter-square"></i> Party Export Filters</h5>
                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="export" id="exportFlag" value="">
                        <div class="col-md-3">
                            <label class="form-label">Party Type</label>
                            <select class="form-select" name="party_type">
                                <option value="">All</option>
                                <option value="Consignee" <?= $partyType === 'Consignee' ? 'selected' : '' ?>>Consignee</option>
                                <option value="Consignor" <?= $partyType === 'Consignor' ? 'selected' : '' ?>>Consignor</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Bilty Type</label>
                            <select class="form-select" name="bilty_type">
                                <option value="">All</option>
                                <?php foreach ($biltyOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $biltyType === $option ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(strtoupper($option)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Station</label>
                            <select class="form-select" name="station">
                                <option value="">All</option>
                                <?php foreach ($stationOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $station === $option ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($option) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100" onclick="document.getElementById('exportFlag').value='';">
                                <i class="bi bi-search"></i> Apply
                            </button>
                            <button type="submit" class="btn btn-success btn-sm w-100" onclick="document.getElementById('exportFlag').value='1';">
                                <i class="bi bi-download"></i> CSV
                            </button>
                        </div>
                    </form>
                </div>

                <div id="tool-pane-import" class="tool-pane <?= $activeTool === 'import' ? '' : 'd-none' ?>">
                    <h5 class="fw-bold mb-3"><i class="bi bi-upload"></i> Import Party CSV</h5>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Update Party CSV</h5>
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
                                <th>Type</th>
                                <th>Bilty</th>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Station</th>
                                <th>Address 1</th>
                                <th>Address 2</th>
                                <th>Pincode</th>
                                <th>City</th>
                                <th>State</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($previewResult && $previewResult->num_rows > 0): ?>
                                <?php $totalPreviewRows = (int) $previewResult->num_rows; ?>
                                <?php $sr = 1; ?>
                                <?php while ($row = $previewResult->fetch_assoc()): ?>
                                    <tr class="preview-data-row <?= $sr > 10 ? 'd-none' : '' ?>" data-preview-index="<?= $sr ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><?= htmlspecialchars($row['party_type']) ?></td>
                                        <td><?= htmlspecialchars(strtoupper($row['bilty_type'])) ?></td>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['contact']) ?></td>
                                        <td><?= htmlspecialchars($row['station']) ?></td>
                                        <td><?= htmlspecialchars($row['address1']) ?></td>
                                        <td><?= htmlspecialchars($row['address2']) ?></td>
                                        <td><?= htmlspecialchars($row['pincode']) ?></td>
                                        <td><?= htmlspecialchars($row['city']) ?></td>
                                        <td><?= htmlspecialchars($row['state']) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center">No data found for selected filters.</td>
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
</body>
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

</html>
