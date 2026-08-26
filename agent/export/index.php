<?php
ob_start();
include "../../protect/db.php";
include "../../protect/case_converter.php";

$agentName = isset($_GET['agent_name']) ? trim($_GET['agent_name']) : '';
$contact = isset($_GET['contact']) ? preg_replace('/\D+/', '', trim($_GET['contact'])) : '';
$station = isset($_GET['station']) ? trim($_GET['station']) : '';
$isExport = isset($_GET['export']) && $_GET['export'] !== '';
$activeTool = isset($_GET['tool']) && in_array($_GET['tool'], ['export', 'import', 'update'], true) ? $_GET['tool'] : 'export';
$sampleType = isset($_GET['sample']) ? trim($_GET['sample']) : '';

function agent_station_map($conn)
{
    $map = [];
    $res = $conn->query("SELECT station_name FROM station ORDER BY station_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $name = trim((string) ($row['station_name'] ?? ''));
            if ($name !== '') {
                $map[strtolower($name)] = $name;
            }
        }
    }
    return $map;
}

function agent_normalize_stations($stationText, $stationMap)
{
    $parts = preg_split('/\s*,\s*/', trim((string) $stationText), -1, PREG_SPLIT_NO_EMPTY);
    $selected = [];
    $seen = [];

    foreach ($parts as $station) {
        $key = strtolower(trim($station));
        if ($key === '' || !isset($stationMap[$key])) {
            return '';
        }
        if (!isset($seen[$key])) {
            $selected[] = $stationMap[$key];
            $seen[$key] = true;
        }
    }

    return implode(', ', $selected);
}

function agent_station_assigned_elsewhere($conn, $stationText, $ignoreId = 0)
{
    $keys = [];
    foreach (preg_split('/\s*,\s*/', trim((string) $stationText), -1, PREG_SPLIT_NO_EMPTY) as $station) {
        $keys[strtolower(trim($station))] = true;
    }
    if (empty($keys)) {
        return false;
    }

    if ((int) $ignoreId > 0) {
        $stmt = $conn->prepare("SELECT station FROM agent WHERE id <> ?");
        $stmt->bind_param('i', $ignoreId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query("SELECT station FROM agent");
    }

    while ($row = $res->fetch_assoc()) {
        foreach (preg_split('/\s*,\s*/', trim((string) ($row['station'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $usedStation) {
            if (isset($keys[strtolower(trim($usedStation))])) {
                if (isset($stmt)) {
                    $stmt->close();
                }
                return true;
            }
        }
    }

    if (isset($stmt)) {
        $stmt->close();
    }
    return false;
}

$stationMap = agent_station_map($conn);

if ($sampleType === 'import' || $sampleType === 'update') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="agent_' . $sampleType . '_sample.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Agent Name', 'Contact', 'Station', 'Address', 'Commission']);

    if ($sampleType === 'import') {
        fputcsv($output, ['', 'Sample Agent', '9999999999', 'Kota, Jaipur', 'Sample address', '5']);
    } else {
        fputcsv($output, ['1', 'Updated Agent', '8888888888', 'Kota', 'Updated address', '6']);
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
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');

        if ($handle === false) {
            $flashType = 'danger';
            $flashMessage = 'Unable to read uploaded CSV file.';
        } else {
            $successCount = 0;
            $failCount = 0;
            fgetcsv($handle);

            if ($action === 'import_csv') {
                $insertStmt = $conn->prepare("INSERT INTO agent (agent_name, contact, station, address, commission_percent) VALUES (?, ?, ?, ?, ?)");

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 6) {
                        $failCount++;
                        continue;
                    }

                    $nameCsv = strtolower(trim($data[1]));
                    $contactCsv = preg_replace('/\D+/', '', trim($data[2]));
                    $stationCsv = strtolower(agent_normalize_stations($data[3], $stationMap));
                    $addressCsv = strtolower(trim($data[4]));
                    $commissionCsv = (int) round((float) trim($data[5]));

                    if ($nameCsv === '' || !preg_match('/^\d{10}$/', $contactCsv) || $stationCsv === '' || $commissionCsv < 0 || $commissionCsv > 100 || agent_station_assigned_elsewhere($conn, $stationCsv)) {
                        $failCount++;
                        continue;
                    }

                    $insertStmt->bind_param('ssssi', $nameCsv, $contactCsv, $stationCsv, $addressCsv, $commissionCsv);
                    $insertStmt->execute() ? $successCount++ : $failCount++;
                }

                $insertStmt->close();
            }

            if ($action === 'update_csv') {
                $updateStmt = $conn->prepare("UPDATE agent SET agent_name = ?, contact = ?, station = ?, address = ?, commission_percent = ? WHERE id = ?");

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 6) {
                        $failCount++;
                        continue;
                    }

                    $idCsv = (int) trim($data[0]);
                    $nameCsv = strtolower(trim($data[1]));
                    $contactCsv = preg_replace('/\D+/', '', trim($data[2]));
                    $stationCsv = strtolower(agent_normalize_stations($data[3], $stationMap));
                    $addressCsv = strtolower(trim($data[4]));
                    $commissionCsv = (int) round((float) trim($data[5]));

                    if ($idCsv <= 0 || $nameCsv === '' || !preg_match('/^\d{10}$/', $contactCsv) || $stationCsv === '' || $commissionCsv < 0 || $commissionCsv > 100 || agent_station_assigned_elsewhere($conn, $stationCsv, $idCsv)) {
                        $failCount++;
                        continue;
                    }

                    $updateStmt->bind_param('ssssii', $nameCsv, $contactCsv, $stationCsv, $addressCsv, $commissionCsv, $idCsv);
                    ($updateStmt->execute() && $updateStmt->affected_rows >= 0) ? $successCount++ : $failCount++;
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

if ($agentName !== '') {
    $where .= 'AND agent_name LIKE ? ';
    $params[] = "%$agentName%";
    $types .= 's';
}

if ($contact !== '') {
    $where .= 'AND contact LIKE ? ';
    $params[] = "%$contact%";
    $types .= 's';
}

if ($station !== '') {
    $where .= 'AND station LIKE ? ';
    $params[] = "%$station%";
    $types .= 's';
}

$baseSql = "SELECT id, agent_name, contact, station, address, commission_percent FROM agent $where ORDER BY agent_name ASC";

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

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="agent_export_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Agent Name', 'Contact', 'Station', 'Address', 'Commission']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['agent_name'],
            $row['contact'],
            $row['station'],
            $row['address'],
            (int) round((float) $row['commission_percent'])
        ]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

$previewStmt = $conn->prepare($baseSql . ' LIMIT 200');
if (!empty($params)) {
    $previewStmt->bind_param($types, ...$params);
}
$previewStmt->execute();
$previewResult = $previewStmt->get_result();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Agent Export</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-filter-square"></i> Agent Export Filters</h5>
                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="export" id="exportFlag" value="">

                        <div class="col-md-4">
                            <label class="form-label">Agent Name</label>
                            <input type="text" class="form-control" name="agent_name" value="<?= htmlspecialchars($agentName) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Contact</label>
                            <input type="text" class="form-control" name="contact" value="<?= htmlspecialchars($contact) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Station</label>
                            <input type="text" class="form-control" name="station" value="<?= htmlspecialchars($station) ?>">
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-upload"></i> Import Agent CSV</h5>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Update Agent CSV</h5>
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
                            <small class="text-muted">Updates by ID from first column. Station names must already exist and cannot be assigned to another agent.</small>
                        </div>
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
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Station</th>
                                <th>Address</th>
                                <th>Commission</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($previewResult && $previewResult->num_rows > 0): ?>
                                <?php $totalPreviewRows = (int) $previewResult->num_rows; ?>
                                <?php $sr = 1; ?>
                                <?php while ($row = $previewResult->fetch_assoc()): ?>
                                    <tr class="preview-data-row <?= $sr > 10 ? 'd-none' : '' ?>" data-preview-index="<?= $sr ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords($row['agent_name'])) ?></td>
                                        <td><?= htmlspecialchars($row['contact']) ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords($row['station'])) ?></td>
                                        <td><?= htmlspecialchars(capitalizeWords($row['address'])) ?></td>
                                        <td><?= (int) round((float) $row['commission_percent']) ?>%</td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No data found for selected filters.</td>
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
                document.querySelectorAll('.preview-data-row').forEach(function (row) {
                    const idx = parseInt(row.getAttribute('data-preview-index') || '0', 10);
                    row.classList.toggle('d-none', idx > 10);
                });
                if (seeMoreWrap) {
                    seeMoreWrap.classList.toggle('d-none', document.querySelectorAll('.preview-data-row.d-none').length === 0);
                }
            }

            function activateTool(tool) {
                tabs.forEach(function (btn) {
                    btn.classList.toggle('active', btn.getAttribute('data-tool') === tool);
                });
                Object.keys(panes).forEach(function (key) {
                    if (panes[key]) panes[key].classList.toggle('d-none', key !== tool);
                });
                if (previewCard) {
                    previewCard.classList.toggle('d-none', tool !== 'export');
                    if (tool === 'export') resetPreviewRows();
                }
            }

            if (seeMoreBtn) {
                seeMoreBtn.addEventListener('click', function () {
                    Array.from(document.querySelectorAll('.preview-data-row.d-none')).slice(0, 10).forEach(function (row) {
                        row.classList.remove('d-none');
                    });
                    if (seeMoreWrap) {
                        seeMoreWrap.classList.toggle('d-none', document.querySelectorAll('.preview-data-row.d-none').length === 0);
                    }
                });
            }

            tabs.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activateTool(btn.getAttribute('data-tool'));
                });
            });

            resetPreviewRows();
        })();
    </script>
</body>

</html>
