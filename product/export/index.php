<?php
ob_start();
include "../../protect/db.php";

$productName = isset($_GET['product_name']) ? trim($_GET['product_name']) : '';
$productType = isset($_GET['product_type']) ? trim($_GET['product_type']) : '';
$productCategory = isset($_GET['product_category']) ? trim($_GET['product_category']) : '';
$isExport = isset($_GET['export']) && $_GET['export'] !== '';
$activeTool = isset($_GET['tool']) && in_array($_GET['tool'], ['export', 'import', 'update'], true) ? $_GET['tool'] : 'export';
$sampleType = isset($_GET['sample']) ? trim($_GET['sample']) : '';

if ($sampleType === 'import' || $sampleType === 'update') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'product_' . $sampleType . '_sample.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Product Name', 'Product Type', 'Product Category', 'Rate', 'Weight']);

    if ($sampleType === 'import') {
        fputcsv($output, ['', 'Sample Product', 'Construction', 'Building Material', '50', '40']);
    } else {
        fputcsv($output, ['1', 'Updated Product', 'Food', 'Grains', '60', '42']);
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
                $insertStmt = $conn->prepare('INSERT INTO products (product_name, product_type, product_category, rate, weight) VALUES (?, ?, ?, ?, ?)');

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 6) {
                        $failCount++;
                        continue;
                    }

                    $nameCsv = trim($data[1]);
                    $typeCsv = trim($data[2]);
                    $categoryCsv = trim($data[3]);
                    $rateCsv = (int) round((float) trim($data[4]));
                    $weightCsv = (int) round((float) trim($data[5]));

                    if ($nameCsv === '') {
                        $failCount++;
                        continue;
                    }

                    $insertStmt->bind_param('sssdd', $nameCsv, $typeCsv, $categoryCsv, $rateCsv, $weightCsv);

                    if ($insertStmt->execute()) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                $insertStmt->close();
            }

            if ($action === 'update_csv') {
                $updateStmt = $conn->prepare('UPDATE products SET product_name = ?, product_type = ?, product_category = ?, rate = ?, weight = ? WHERE id = ?');

                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) < 6) {
                        $failCount++;
                        continue;
                    }

                    $idCsv = (int) trim($data[0]);
                    $nameCsv = trim($data[1]);
                    $typeCsv = trim($data[2]);
                    $categoryCsv = trim($data[3]);
                    $rateCsv = (int) round((float) trim($data[4]));
                    $weightCsv = (int) round((float) trim($data[5]));

                    if ($idCsv <= 0 || $nameCsv === '') {
                        $failCount++;
                        continue;
                    }

                    $updateStmt->bind_param('sssddi', $nameCsv, $typeCsv, $categoryCsv, $rateCsv, $weightCsv, $idCsv);

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

if ($productName !== '') {
    $where .= 'AND product_name LIKE ? ';
    $params[] = "%$productName%";
    $types .= 's';
}

if ($productType !== '') {
    $where .= 'AND product_type = ? ';
    $params[] = $productType;
    $types .= 's';
}

if ($productCategory !== '') {
    $where .= 'AND product_category = ? ';
    $params[] = $productCategory;
    $types .= 's';
}

$baseSql = "SELECT id, product_name, product_type, product_category, rate, weight FROM products $where ORDER BY product_type ASC, product_category ASC, product_name ASC";

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

    $filename = 'product_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Product Name', 'Product Type', 'Product Category', 'Rate', 'Weight']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['product_name'],
            $row['product_type'],
            $row['product_category'],
            $row['rate'],
            $row['weight']
        ]);
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

$typeOptions = [];
$typeRes = $conn->query("SELECT DISTINCT product_type FROM products WHERE product_type IS NOT NULL AND product_type <> '' ORDER BY product_type ASC");
while ($row = $typeRes->fetch_assoc()) {
    $typeOptions[] = $row['product_type'];
}

$categoryOptions = [];
$categoryRes = $conn->query("SELECT DISTINCT product_category FROM products WHERE product_category IS NOT NULL AND product_category <> '' ORDER BY product_category ASC");
while ($row = $categoryRes->fetch_assoc()) {
    $categoryOptions[] = $row['product_category'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Product Export</title>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-filter-square"></i> Product Export Filters</h5>
                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="export" id="exportFlag" value="">

                        <div class="col-md-4">
                            <label class="form-label">Product Name</label>
                            <input type="text" class="form-control" name="product_name" value="<?= htmlspecialchars($productName) ?>" placeholder="Search by name">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Product Type</label>
                            <select class="form-select" name="product_type">
                                <option value="">All</option>
                                <?php foreach ($typeOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $productType === $option ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($option) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="product_category">
                                <option value="">All</option>
                                <?php foreach ($categoryOptions as $option): ?>
                                    <option value="<?= htmlspecialchars($option) ?>" <?= $productCategory === $option ? 'selected' : '' ?>>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-upload"></i> Import Product CSV</h5>
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
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Update Product CSV</h5>
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
                                <th>Product Name</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Rate</th>
                                <th>Weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($previewResult && $previewResult->num_rows > 0): ?>
                                <?php $totalPreviewRows = (int) $previewResult->num_rows; ?>
                                <?php $sr = 1; ?>
                                <?php while ($row = $previewResult->fetch_assoc()): ?>
                                    <tr class="preview-data-row <?= $sr > 10 ? 'd-none' : '' ?>" data-preview-index="<?= $sr ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><?= htmlspecialchars($row['product_name']) ?></td>
                                        <td><?= htmlspecialchars($row['product_type']) ?></td>
                                        <td><?= htmlspecialchars($row['product_category']) ?></td>
                                        <td><?= htmlspecialchars($row['rate']) ?></td>
                                        <td><?= htmlspecialchars($row['weight']) ?></td>
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
