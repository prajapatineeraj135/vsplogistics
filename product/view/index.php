<?php
require_once '../../protect/db.php';
require_once '../../protect/case_converter.php';

$items = [];

if (isset($_GET['product_id'])) {
    $productId = (int) $_GET['product_id'];
    $res = $conn->query("SELECT * FROM products WHERE id = $productId");
    if ($res && $res->num_rows > 0) {
        $items[] = $res->fetch_assoc();
    }
} else {
    $res = $conn->query("SELECT * FROM products ORDER BY product_type ASC, product_category ASC, product_name ASC");
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
}

// Fetch station-specific rates for all shown products
$stationRates = []; // keyed by product_id
if (!empty($items)) {
    $ids = implode(',', array_map(fn($p) => (int)$p['id'], $items));
    $srRes = $conn->query("SELECT * FROM product_station_rates WHERE product_id IN ($ids) ORDER BY station_name ASC");
    if ($srRes) {
        while ($sr = $srRes->fetch_assoc()) {
            $stationRates[(int)$sr['product_id']][] = $sr;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Product Details</title>
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
        .label {
            font-weight: 600;
            color: #166534;
        }
        .station-rate-table th {
            background: #f0fdf4;
            color: #166534;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .station-rate-table td {
            font-size: 0.85rem;
        }
        .badge-basis {
            font-size: 0.75rem;
            background: #dcfce7;
            color: #166534;
            border-radius: 6px;
            padding: 2px 8px;
        }
    </style>
</head>
<body>
    <?php include '../../content/nav.php'; ?>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="mb-3 text-center">
                    <h4 class="fw-bold text-success">
                        <?php if (isset($_GET['product_id'])): ?>
                            <i class="bi bi-box"></i> Product Details
                        <?php else: ?>
                            <i class="bi bi-boxes"></i> All Products
                        <?php endif; ?>
                    </h4>
                </div>

                <?php if (empty($items)): ?>
                    <div class="alert alert-warning">No product found.</div>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-4"><span class="label">Product Name:</span> <?= htmlspecialchars(capitalizeWords($item['product_name'] ?? '')) ?></div>
                                    <div class="col-md-3"><span class="label">Type:</span> <?= htmlspecialchars(capitalizeWords($item['product_type'] ?? '')) ?></div>
                                    <div class="col-md-3"><span class="label">Category:</span> <?= htmlspecialchars(capitalizeWords($item['product_category'] ?? '')) ?></div>
                                    <div class="col-md-1"><span class="label">Rate:</span> <?= htmlspecialchars($item['rate'] ?? '') ?></div>
                                    <div class="col-md-1"><span class="label">Weight:</span> <?= htmlspecialchars($item['weight'] ?? '') ?></div>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-md-3"><span class="label">Rate Basis:</span> <?= htmlspecialchars($item['rate_basis'] ?? 'Nag') ?></div>
                                </div>

                                <?php
                                $pid = (int)$item['id'];
                                $rates = $stationRates[$pid] ?? [];
                                if (!empty($rates)): ?>
                                    <div class="mt-2">
                                        <span class="label"><i class="bi bi-geo-alt"></i> Station-Specific Rates</span>
                                        <table class="table table-sm table-bordered station-rate-table mt-1 mb-0">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Station</th>
                                                    <th>Rate</th>
                                                    <th>Rate Basis</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rates as $i => $sr): ?>
                                                    <tr>
                                                        <td><?= $i + 1 ?></td>
                                                        <td><?= htmlspecialchars(capitalizeWords($sr['station_name'])) ?></td>
                                                        <td><?= htmlspecialchars($sr['rate']) ?></td>
                                                        <td><span class="badge-basis"><?= htmlspecialchars($sr['rate_basis']) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
