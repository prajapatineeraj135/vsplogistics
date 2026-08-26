<?php
include "../protect/db.php";
include "../protect/case_converter.php";

// Ensure legacy databases have rate_basis for product-wise rate calculation.
$hasRateBasis = false;
$colCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'rate_basis'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasRateBasis = true;
} else {
    if ($conn->query("ALTER TABLE products ADD COLUMN rate_basis VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight")) {
        $hasRateBasis = true;
    }
}

$rateBasisOptions = [
    'Nag' => 'Per Nag',
    'Weight' => 'Per Quintle'
];

// Ensure product_station_rates table exists
$conn->query("
    CREATE TABLE IF NOT EXISTS product_station_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        station_name VARCHAR(100) NOT NULL,
        rate DECIMAL(10,2) NOT NULL DEFAULT 0,
        rate_basis VARCHAR(20) NOT NULL DEFAULT 'Nag',
        INDEX idx_psr_product_id (product_id)
    )
");

/* =====================
   SAVE / UPDATE
===================== */
if (isset($_POST['save'])) {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    $product_name = strtolower(trim($_POST['product_name'] ?? ''));
    $product_type = strtolower(trim($_POST['product_type'] ?? ''));
    $product_category = strtolower(trim($_POST['product_category'] ?? ''));
    $rate = (int) round((float) ($_POST['rate'] ?? 0));
    $weight = (int) round((float) ($_POST['weight'] ?? 0));
    $rate_basis = $_POST['rate_basis'] ?? 'Nag';
    if (!array_key_exists($rate_basis, $rateBasisOptions)) {
        $rate_basis = 'Nag';
    }

    if ($product_name !== '') {
        if ($id <= 0) {
            $stmt = $conn->prepare("INSERT INTO products (product_name, product_type, product_category, rate, weight, rate_basis) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssdds", $product_name, $product_type, $product_category, $rate, $weight, $rate_basis);
        } else {
            $stmt = $conn->prepare("UPDATE products SET product_name = ?, product_type = ?, product_category = ?, rate = ?, weight = ?, rate_basis = ? WHERE id = ?");
            $stmt->bind_param("sssddsi", $product_name, $product_type, $product_category, $rate, $weight, $rate_basis, $id);
        }

        $stmt->execute();
        $savedProductId = ($id <= 0) ? (int)$conn->insert_id : $id;
        $stmt->close();

        // Save station-specific rates
        $stmtDel = $conn->prepare("DELETE FROM product_station_rates WHERE product_id = ?");
        $stmtDel->bind_param("i", $savedProductId);
        $stmtDel->execute();
        $stmtDel->close();

        $stationNames = $_POST['station_name'] ?? [];
        $stationRates = $_POST['station_rate'] ?? [];
        $stationBases = $_POST['station_rate_basis'] ?? [];
        if (!empty($stationNames)) {
            $srStmt = $conn->prepare("INSERT INTO product_station_rates (product_id, station_name, rate, rate_basis) VALUES (?, ?, ?, ?)");
            foreach ($stationNames as $i => $sName) {
                $sName = strtolower(trim($sName));
                $sRate = (int) round((float)($stationRates[$i] ?? 0));
                $sBasis = array_key_exists($stationBases[$i] ?? '', $rateBasisOptions) ? $stationBases[$i] : 'Nag';
                if ($sName !== '') {
                    $srStmt->bind_param("isds", $savedProductId, $sName, $sRate, $sBasis);
                    $srStmt->execute();
                }
            }
            $srStmt->close();
        }
    }

   header("Location: index.php?status=" . ($id <= 0 ? "1" : "3") . "&view=list");
    exit;
}

/* =====================
   DELETE
===================== */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM products WHERE id=$id");
    header("Location: index.php?status=4");
    exit;
}

/* =====================
   EDIT
===================== */
$edit = [];
$editStationRates = [];
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $edit = $conn->query("SELECT * FROM products WHERE id=$id")->fetch_assoc();
    if (!empty($edit)) {
        $srRes = $conn->query("SELECT * FROM product_station_rates WHERE product_id = $id ORDER BY id ASC");
        while ($sr = $srRes->fetch_assoc()) {
            $editStationRates[] = $sr;
        }
    }
}

/* =====================
   AJAX LIST
===================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_product') {
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = 10;

    $name = trim($_GET['name'] ?? '');
    $type = trim($_GET['product_type'] ?? '');
    $category = trim($_GET['product_category'] ?? '');

    $where = "WHERE 1 ";
    $params = [];
    $types = "";

    if ($name !== '') {
        $where .= "AND product_name LIKE ? ";
        $params[] = "%$name%";
        $types .= "s";
    }

    if ($type !== '') {
        $where .= "AND product_type = ? ";
        $params[] = $type;
        $types .= "s";
    }

    if ($category !== '') {
        $where .= "AND product_category = ? ";
        $params[] = $category;
        $types .= "s";
    }

    $sql = "SELECT * FROM products $where ORDER BY product_type ASC, product_category ASC, product_name ASC LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

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

// Station search AJAX handler
if (isset($_GET['ajax']) && $_GET['ajax'] === 'station_search_product') {
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt = $conn->prepare("SELECT station_name FROM station WHERE station_name LIKE ? ORDER BY station_name ASC LIMIT 15");
    $stmt->bind_param('s', $q);
    $stmt->execute();
    $res = $stmt->get_result();
    $stations = [];
    while ($row = $res->fetch_assoc()) {
        $stations[] = $row['station_name'];
    }
    header('Content-Type: application/json');
    echo json_encode($stations);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Product Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f4f6f9;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, .08);
        }
        .form-control,
        .form-select {
            font-size: 14px;
            padding: 6px;
        }
        .btn {
            font-size: 12px;
            padding: 5px 10px;
        }
        .product-tabs .nav-link {
            font-weight: 600;
            color: #166534;
        }
        .product-tabs .nav-link.active {
            background: #22c55e;
            color: #ffffff;
            border-color: #22c55e #22c55e #22c55e;
        }
        .form-mode-create {
            background: #ecfdf3;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
        }
        .form-mode-update {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
        }
        .product-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .product-form-row .field-group {
            display: flex;
            flex-direction: column;
        }
        .product-form-row .field-group label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .weight-field-hidden {
            display: none;
        }
        .station-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 4px;
            z-index: 1000;
            max-height: 180px;
            overflow-y: auto;
            box-shadow: 0 4px 10px rgba(0,0,0,.1);
        }
        .station-drop-item {
            padding: 6px 10px;
            cursor: pointer;
            font-size: 13px;
        }
        .station-drop-item:hover,
        .station-drop-item.active {
            background: #22c55e;
            color: #fff;
        }
        @media (max-width: 767.98px) {
            .container-fluid {
                padding-left: 8px;
                padding-right: 8px;
            }
            .card-body {
                padding: 0.75rem;
            }
            .product-tabs .nav-item {
                flex: 1 1 50%;
            }
            .product-tabs .nav-link {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php include "../content/nav.php"; ?>

    <?php if (isset($_GET['status'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                <?php $s = $_GET['status']; ?>
                <?php if ($s == '1'): ?>showSave('Product saved successfully');
                <?php elseif ($s == '3'): ?>showUpdate('Product updated successfully');
                <?php elseif ($s == '4'): ?>showDelete('Product deleted successfully');
                <?php endif; ?>
                if (window.history.replaceState) {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('status');
                    var clean = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash;
                    window.history.replaceState({}, document.title, clean);
                }
            });
        </script>
    <?php endif; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 product-tabs" id="productMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="product-form-tab" data-bs-toggle="tab" data-bs-target="#product-form-pane" type="button" role="tab" aria-controls="product-form-pane" aria-selected="true">
                    <i class="bi bi-box-seam"></i> <?= isset($edit['id']) ? 'Update' : 'Create' ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="product-list-tab" data-bs-toggle="tab" data-bs-target="#product-list-pane" type="button" role="tab" aria-controls="product-list-pane" aria-selected="false">
                    <i class="bi bi-list"></i> Product List
                </button>
            </li>
        </ul>

        <div class="tab-content" id="productMainTabsContent">
            <div class="tab-pane fade show active" id="product-form-pane" role="tabpanel" aria-labelledby="product-form-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body <?= isset($edit['id']) ? 'form-mode-update' : 'form-mode-create' ?>">
                                <h6 class="fw-bold mb-2">
                                    <i class="bi bi-boxes"></i> <?= isset($edit['id']) ? 'Update Product' : 'Create Product' ?>
                                </h6>

                                <form method="post">
                                    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

                                    <div class="row g-2 align-items-end product-form-row">
                                        <div class="col-12 col-lg-3 field-group">
                                            <label>Product Name</label>
                                            <input type="text" name="product_name" class="form-control" value="<?= htmlspecialchars(capitalizeWords($edit['product_name'] ?? '')) ?>" required>
                                        </div>

                                        <div class="col-6 col-lg-2 field-group">
                                            <label>Product Type</label>
                                            <select name="product_type" class="form-select">
                                                <option value="">Select</option>
                                                <?php foreach ($typeOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= ($edit['product_type'] ?? '') === $option ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars(capitalizeWords($option)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-6 col-lg-2 field-group">
                                            <label>Category</label>
                                            <select name="product_category" class="form-select">
                                                <option value="">Select</option>
                                                <?php foreach ($categoryOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= ($edit['product_category'] ?? '') === $option ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars(capitalizeWords($option)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-lg-2 field-group">
                                            <label>Rate Basis</label>
                                            <select name="rate_basis" id="rateBasisSelect" class="form-select">
                                                <?php $selectedBasis = $edit['rate_basis'] ?? 'Nag'; ?>
                                                <?php foreach ($rateBasisOptions as $basisValue => $basisLabel): ?>
                                                    <option value="<?= htmlspecialchars($basisValue) ?>" <?= $selectedBasis === $basisValue ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($basisLabel) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <div class="col-6 col-lg-1 field-group">
                                            <label>Rate</label>
                                            <input type="text" pattern="[0-9]+" step="1" name="rate" class="form-control" value="<?= htmlspecialchars($edit['rate'] ?? '0') ?>">
                                        </div>

                                        <div class="col-6 col-lg-2 field-group" id="weightFieldGroup">
                                            <label>Weight</label>
                                            <input type="text" pattern="[0-9]+" step="1" name="weight" id="weightInput" class="form-control" value="<?= htmlspecialchars($edit['weight'] ?? '0') ?>">
                                        </div>

                                        
                                    </div>

                                    <!-- Station-Specific Rates Section -->
                                    <div class="mt-3">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <strong style="font-size:13px;"><i class="bi bi-geo-alt"></i> Station-Specific Rates</strong>
                                            <small class="text-muted">(leave empty to use default rate for all stations)</small>
                                        </div>
                                        <table class="table table-sm table-bordered mb-2" id="stationRatesTable" style="max-width:600px;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Station Name</th>
                                                    <th>Rate</th>
                                                    <th>Rate Basis</th>
                                                    <th width="40"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="stationRatesBody">
                                                <?php foreach ($editStationRates as $sr): ?>
                                                <tr>
                                                    <td style="position:relative;">
                                                        <input type="text" name="station_name[]" class="form-control form-control-sm station-search-input" autocomplete="off" value="<?= htmlspecialchars(capitalizeWords($sr['station_name'])) ?>" placeholder="Search station..." oninput="searchStationInput(this)" onkeydown="handleStationInputKey(event,this)">
                                                        <div class="station-dropdown"></div>
                                                    </td>
                                                    <td><input type="number" name="station_rate[]" class="form-control form-control-sm" step="1" min="0" value="<?= htmlspecialchars($sr['rate']) ?>" placeholder="0" required></td>
                                                    <td>
                                                        <select name="station_rate_basis[]" class="form-select form-select-sm">
                                                            <option value="Nag" <?= $sr['rate_basis'] === 'Nag' ? 'selected' : '' ?>>Per Nag</option>
                                                            <option value="Weight" <?= $sr['rate_basis'] === 'Weight' ? 'selected' : '' ?>>Per Quintle</option>
                                                        </select>
                                                    </td>
                                                    <td><button type="button" class="btn btn-danger btn-sm" onclick="removeStationRow(this)"><i class="bi bi-trash"></i></button></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addStationRow()">
                                            <i class="bi bi-plus-circle"></i> Add Station Rate
                                        </button>
                                    </div>

                                    <div class="mt-3 d-flex gap-2 flex-wrap">
                                        <button type="submit" name="save" class="btn <?= isset($edit['id']) ? 'btn-warning' : 'btn-success' ?> btn-sm">
                                            <i class="bi bi-save"></i> <?= isset($edit['id']) ? 'Update' : 'Save' ?>
                                        </button>
                                        <?php if (isset($edit['id'])): ?>
                                            <a href="index.php?view=list" class="btn btn-danger btn-sm">Cancel</a>
                                        <?php endif; ?>
                                         </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="product-list-pane" role="tabpanel" aria-labelledby="product-list-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-4">
                                        <input type="text" id="search_name" class="form-control form-control-sm" placeholder="Search product by name..." onkeyup="autoSearchName()" autocomplete="off">
                                    </div>
                                    <div class="col-md-3">
                                        <select id="search_type" class="form-select form-select-sm" onchange="applyFilters()">
                                            <option value="">All Types</option>
                                            <?php foreach ($typeOptions as $option): ?>
                                                <option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select id="search_category" class="form-select form-select-sm" onchange="applyFilters()">
                                            <option value="">All Categories</option>
                                            <?php foreach ($categoryOptions as $option): ?>
                                                <option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="60">Sr</th>
                                                <th>Product</th>
                                                <th>Type</th>
                                                <th>Category</th>
                                                <th>Rate</th>
                                                <th>Weight</th>
                                                <th>Rate Basis</th>
                                                <th width="180">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="productTable"></tbody>
                                    </table>
                                </div>

                                <div class="text-center mt-2">
                                    <button id="loadMoreBtn" class="btn btn-primary btn-sm">Load More</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let offset = 0;
        const limit = 10;
        let typingTimer;

        function escHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function capitalizeWords(str) {
            return str.toLowerCase().split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        }

        function getFilters() {
            return {
                name: document.getElementById('search_name')?.value.trim() || '',
                product_type: document.getElementById('search_type')?.value || '',
                product_category: document.getElementById('search_category')?.value || ''
            };
        }

        function resetList() {
            offset = 0;
            const tbody = document.getElementById('productTable');
            if (tbody) tbody.innerHTML = '';

            const loadMoreBtn = document.getElementById('loadMoreBtn');
            if (loadMoreBtn) {
                loadMoreBtn.disabled = false;
                loadMoreBtn.innerText = 'Load More';
            }
        }

        function autoSearchName() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(function () {
                resetList();
                loadMoreProduct();
            }, 400);
        }

        function applyFilters() {
            resetList();
            loadMoreProduct();
        }

        function clearViewParamFromUrl() {
            if (!window.history.replaceState) {
                return;
            }
            const url = new URL(window.location.href);
            if (!url.searchParams.has('view')) {
                return;
            }
            url.searchParams.delete('view');
            const clean = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash;
            window.history.replaceState({}, document.title, clean);
        }

        function syncRateBasisFields() {
            const rateBasisSelect = document.getElementById('rateBasisSelect');
            const weightFieldGroup = document.getElementById('weightFieldGroup');
            const weightInput = document.getElementById('weightInput');

            if (!rateBasisSelect || !weightFieldGroup || !weightInput) {
                return;
            }

            const showWeight = rateBasisSelect.value === 'Weight';
            weightFieldGroup.classList.toggle('weight-field-hidden', !showWeight);
            weightInput.disabled = !showWeight;

            if (!showWeight) {
                weightInput.value = '0';
            }
        }

        function loadMoreProduct() {
            const filters = getFilters();
            const query = `?ajax=load_product&offset=${offset}&name=${encodeURIComponent(filters.name)}&product_type=${encodeURIComponent(filters.product_type)}&product_category=${encodeURIComponent(filters.product_category)}`;

            fetch(query)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    const tbody = document.getElementById('productTable');
                    const loadMoreBtn = document.getElementById('loadMoreBtn');

                    if (data.length === 0 && offset === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center">No Data Found</td></tr>';
                        loadMoreBtn.disabled = true;
                        return;
                    }

                    if (data.length === 0) {
                        loadMoreBtn.innerText = 'No More Data';
                        loadMoreBtn.disabled = true;
                        return;
                    }

                    let sn = tbody.rows.length + 1;
                    data.forEach(function (row) {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${sn++}</td>
                            <td>${escHtml(capitalizeWords(row.product_name))}</td>
                            <td>${escHtml(capitalizeWords(row.product_type))}</td>
                            <td>${escHtml(capitalizeWords(row.product_category))}</td>
                            <td>${escHtml(row.rate)}</td>
                            <td>${escHtml(row.weight)}</td>
                            <td>${escHtml((row.rate_basis === 'Weight') ? 'Per Quintle' : 'Per Nag')}</td>
                            <td>
                                <div class="product-actions">
                                    <a href="view/?product_id=${row.id}" class="btn btn-success btn-sm"><i class="bi bi-eye"></i></a>
                                    <a href="?edit=${row.id}" class="btn btn-warning btn-sm" onclick="nmNavConfirm(event,'Edit this product?')"><i class="bi bi-pencil"></i></a>
                                    <a href="?delete=${row.id}" class="btn btn-danger btn-sm" onclick="nmNavConfirm(event,'Delete this product?')"><i class="bi bi-trash"></i></a>
                                </div>
                            </td>`;
                        tbody.appendChild(tr);
                    });

                    offset += limit;
                });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const view = new URLSearchParams(window.location.search).get('view');
            const formTabButton = document.getElementById('product-form-tab');
            const listTabButton = document.getElementById('product-list-tab');

            if (view === 'list' && listTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(listTabButton).show();
            } else if (view === 'create' && formTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(formTabButton).show();
            }

            syncRateBasisFields();

            const rateBasisSelect = document.getElementById('rateBasisSelect');
            if (rateBasisSelect) {
                rateBasisSelect.addEventListener('change', syncRateBasisFields);
            }

            loadMoreProduct();
            const loadMoreBtn = document.getElementById('loadMoreBtn');
            if (loadMoreBtn) {
                loadMoreBtn.addEventListener('click', loadMoreProduct);
            }

            const tabButtons = document.querySelectorAll('#productMainTabs [data-bs-toggle="tab"]');
            tabButtons.forEach(function (tabButton) {
                tabButton.addEventListener('shown.bs.tab', function () {
                    clearViewParamFromUrl();
                });
            });
        });

        function addStationRow() {
            const tbody = document.getElementById('stationRatesBody');
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="position:relative;">
                    <input type="text" name="station_name[]" class="form-control form-control-sm station-search-input" autocomplete="off" placeholder="Search station..." oninput="searchStationInput(this)" onkeydown="handleStationInputKey(event,this)">
                    <div class="station-dropdown"></div>
                </td>
                <td><input type="number" name="station_rate[]" class="form-control form-control-sm" step="1" min="0" placeholder="0"></td>
                <td>
                    <select name="station_rate_basis[]" class="form-select form-select-sm">
                        <option value="Nag">Per Nag</option>
                        <option value="Weight">Per Quintle</option>
                    </select>
                </td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeStationRow(this)"><i class="bi bi-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
            tr.querySelector('.station-search-input').focus();
        }

        function removeStationRow(btn) {
            btn.closest('tr').remove();
        }

        let stationSearchTimers = new WeakMap();
        let stationActiveIndex = -1;

        function searchStationInput(input) {
            const q = input.value.trim();
            const dropdown = input.nextElementSibling;
            stationActiveIndex = -1;

            if (stationSearchTimers.has(input)) {
                clearTimeout(stationSearchTimers.get(input));
            }

            if (q.length < 1) {
                dropdown.innerHTML = '';
                dropdown.style.display = 'none';
                return;
            }

            const timer = setTimeout(function () {
                fetch(`?ajax=station_search_product&q=${encodeURIComponent(q)}`)
                    .then(function (r) { return r.json(); })
                    .then(function (stations) {
                        dropdown.innerHTML = '';
                        stationActiveIndex = -1;
                        if (!stations.length) {
                            dropdown.style.display = 'none';
                            return;
                        }
                        stations.forEach(function (name) {
                            const div = document.createElement('div');
                            div.className = 'station-drop-item';
                            div.textContent = capitalizeWords(name);
                            div.onmousedown = function (e) {
                                e.preventDefault();
                                input.value = capitalizeWords(name);
                                dropdown.innerHTML = '';
                                dropdown.style.display = 'none';
                                const rateInput = input.closest('tr').querySelector('input[name="station_rate[]"]');
                                if (rateInput) rateInput.focus();
                            };
                            dropdown.appendChild(div);
                        });
                        dropdown.style.display = 'block';
                    })
                    .catch(function () {
                        dropdown.style.display = 'none';
                    });
            }, 250);

            stationSearchTimers.set(input, timer);
        }

        function handleStationInputKey(event, input) {
            const dropdown = input.nextElementSibling;
            const items = dropdown.querySelectorAll('.station-drop-item');

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                stationActiveIndex = Math.min(stationActiveIndex + 1, items.length - 1);
                highlightStationDropItem(items);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                stationActiveIndex = Math.max(stationActiveIndex - 1, 0);
                highlightStationDropItem(items);
            } else if (event.key === 'Enter' && stationActiveIndex >= 0) {
                event.preventDefault();
                items[stationActiveIndex].onmousedown(event);
            } else if (event.key === 'Escape') {
                dropdown.innerHTML = '';
                dropdown.style.display = 'none';
                stationActiveIndex = -1;
            }
        }

        function highlightStationDropItem(items) {
            items.forEach(function (item) { item.classList.remove('active'); });
            if (stationActiveIndex >= 0 && items[stationActiveIndex]) {
                items[stationActiveIndex].classList.add('active');
                items[stationActiveIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('station-search-input')) {
                document.querySelectorAll('.station-dropdown').forEach(function (d) {
                    d.innerHTML = '';
                    d.style.display = 'none';
                });
            }
        });
    </script>
</body>
</html>
