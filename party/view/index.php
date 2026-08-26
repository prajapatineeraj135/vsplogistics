<?php
// party/view/index.php
// This page displays all parties with their product list, rates, and details.

require_once '../../protect/db.php'; // Provides $conn
require_once '../../protect/case_converter.php'; // Case conversion functions

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function formatCurrency($amount)
{
    $value = (float) $amount;
    return number_format((int) round($value), 0, '.', '');
}

function fetchPartyAccountSummary($conn, $partyRow, $companyIdFilter = null)
{
    $partyId = (int) ($partyRow['id'] ?? 0);
    $partyName = trim((string) ($partyRow['name'] ?? ''));

    $sql = "SELECT id, bill_number, bill_date, amount, status
            FROM bills
            WHERE (party_id = ? OR party_name = ?)";
    $types = 'is';
    $params = [$partyId, $partyName];

    if ($companyIdFilter !== null) {
        $sql .= ' AND company_id = ?';
        $types .= 'i';
        $params[] = (int) $companyIdFilter;
    }

    $sql .= ' ORDER BY bill_date DESC, id DESC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'last_bill' => '--',
            'last_payment' => '--',
            'current_bill' => '--',
            'pending_sum' => '--'
        ];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $bills = [];
    while ($row = $res->fetch_assoc()) {
        $bills[] = $row;
    }
    $stmt->close();

    if (empty($bills)) {
        return [
            'last_bill' => '--',
            'last_payment' => '--',
            'current_bill' => '--',
            'pending_sum' => '--'
        ];
    }

    $currentBill = $bills[0];
    $lastBill = $bills[1] ?? null;

    $latestPaid = null;
    $pendingAmount = 0.0;

    foreach ($bills as $bill) {
        $status = strtolower(trim((string) ($bill['status'] ?? '')));

        if ($latestPaid === null && $status === 'paid') {
            $latestPaid = $bill;
        }

        if ($status === 'pending') {
            $pendingAmount += (float) ($bill['amount'] ?? 0);
        }
    }

    $currentBillText = 'Rs ' . formatCurrency($currentBill['amount'] ?? 0);

    $lastBillText = '--';
    if (!empty($lastBill)) {
        $lastBillText = 'Rs ' . formatCurrency($lastBill['amount'] ?? 0);
    }

    $lastPaymentText = '--';
    if (!empty($latestPaid)) {
        $lastPaymentText = 'Rs ' . formatCurrency($latestPaid['amount'] ?? 0);
    }

    $pendingText = 'Rs ' . formatCurrency($pendingAmount);

    return [
        'last_bill' => $lastBillText,
        'last_payment' => $lastPaymentText,
        'current_bill' => $currentBillText,
        'pending_sum' => $pendingText
    ];
}

function fetchPartyWithProducts($conn, $partyId, $companyIdFilter = null) {
    $sql = "SELECT * FROM party WHERE id = $partyId";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $row['products'] = [];
        $psql = "SELECT * FROM party_products WHERE party_id = $partyId";
        $presult = $conn->query($psql);
        if ($presult && $presult->num_rows > 0) {
            while ($prow = $presult->fetch_assoc()) {
                $row['products'][] = $prow;
            }
        }
        $row['summary'] = fetchPartyAccountSummary($conn, $row, $companyIdFilter);
        return [$row];
    }
    return [];
}

function fetchPartiesWithProducts($conn, $companyIdFilter = null) {
    $parties = [];
    $sql = "SELECT * FROM party";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $partyId = $row['id'];
            $row['products'] = [];
            $psql = "SELECT * FROM party_products WHERE party_id = $partyId";
            $presult = $conn->query($psql);
            if ($presult && $presult->num_rows > 0) {
                while ($prow = $presult->fetch_assoc()) {
                    $row['products'][] = $prow;
                }
            }
            $row['summary'] = fetchPartyAccountSummary($conn, $row, $companyIdFilter);
            $parties[] = $row;
        }
    }
    return $parties;
}

$companyIdFilter = isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : null;

if (isset($_GET['party_id'])) {
    $party_id = (int)$_GET['party_id'];
    $parties = fetchPartyWithProducts($conn, $party_id, $companyIdFilter);
} else {
    $parties = fetchPartiesWithProducts($conn, $companyIdFilter);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Party Details & Product List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #e0e7ff 0%, #f4f6f9 100%); }
        .card {
            border-radius: 16px;
            box-shadow: none;
            margin-bottom: 36px;
            border: none;
        }
        .table th, .table td { vertical-align: middle; }
        .party-title {
            font-size: 2rem;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 22px;
            letter-spacing: 1px;
        }
        .product-table th {
            background: linear-gradient(90deg, #2563eb 0%, #60a5fa 100%);
            color: #fff;
            border: none;
        }
        .product-table td {
            background: #f8fafc;
        }
        .no-products { 
            color: #888; font-style: italic; 
        }
        .label {
            font-weight: 600;
            color: #2563eb;
            letter-spacing: 0.5px;
            
        }
        .value {
            color: #222;
            font-weight: 500;
        }
        .details-bg {
            background: linear-gradient(135deg, #dbeafe 0%, #f0fdfa 100%);
            border-radius: 12px;
            border: 1px solid #c7d2fe;
            box-shadow: none;
        }
        .summary-bg {
            background: linear-gradient(135deg, #fef9c3 0%, #f0fdfa 100%);
            border-radius: 12px;
            border: 1px solid #fde68a;
            box-shadow: none;
        }
        .account-summary-title {
            color: #ca8a04;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .nav-tabs .nav-link.active {
            background: linear-gradient(90deg, #22c55e 0%, #4ade80 100%);
            color: #fff !important;
            border: none;
        }
        .nav-tabs .nav-link {
            color: #2563eb;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            .party-details-table td, .party-details-table th { font-size: 13px; }
        }
    </style>
</head>
<body>
    <?php include '../../content/nav.php'; ?>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="mb-4 text-center">
                    <span class="party-title">
                        <?php if (isset($_GET['party_id'])): ?>
                            <i class="bi bi-person-badge"></i> Party Details
                        <?php else: ?>
                            <i class="bi bi-people"></i> All Parties with Product List & Rates
                        <?php endif; ?>
                    </span>
                </div>
                <?php foreach ($parties as $party): ?>
                <div class="card">
                    <div class="card-body">
                        <ul class="nav nav-tabs mb-3" id="partyTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details<?= $party['id'] ?>" type="button" role="tab" aria-controls="details<?= $party['id'] ?>" aria-selected="true">
                                    <i class="bi bi-person"></i> Customer Details
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="products-tab" data-bs-toggle="tab" data-bs-target="#products<?= $party['id'] ?>" type="button" role="tab" aria-controls="products<?= $party['id'] ?>" aria-selected="false">
                                    <i class="bi bi-box-seam"></i> Product List
                                </button>
                            </li>
                        </ul>
                        <div class="tab-content" id="partyTabContent">
                            <div class="tab-pane fade show active" id="details<?= $party['id'] ?>" role="tabpanel" aria-labelledby="details-tab">
                                <div class="row justify-content-center">
                                    <div class="col-12 col-xl-11">
                                        <div class="row g-3 justify-content-center">
                                            <div class="col-lg-8 col-md-10">
                                                <div class="p-4 details-bg w-100" style="width:100%;">
                                                    <div class="mb-3">
                                                        <h2 class="fw-bold text-primary" style="font-size:2rem; margin-bottom: 0.7em;">
                                                            <?= htmlspecialchars(capitalizeWords($party['name'])) ?>
                                                            <?php if (!empty($party['bilty_type'])): ?>
                                                                <span class="text-dark" style="font-size:1.2rem; font-weight:600; text-transform:uppercase;"> <?= strtoupper($party['bilty_type']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($party['party_type'])): ?>
                                                                <span class="text-secondary ms-2" style="font-size:1.1rem; font-weight:600; text-transform:uppercase;"> <?= strtoupper($party['party_type']) ?></span>
                                                            <?php endif; ?>
                                                        </h2>
                                                    </div>
                                                    <table class="table party-details-table mb-0">
                                                        <tbody>
                                                            <tr><th class="label" width="30%">Contact</th><td class="value"><?= htmlspecialchars(capitalizeWords($party['contact'] ?? '')) ?></td></tr>
                                                            <tr><th class="label" width="30%">Station</th><td class="value"><?= htmlspecialchars(capitalizeWords($party['station'] ?? '')) ?></td></tr>
                                                            <tr><th class="label" width="30%">Address 1</th><td class="value"><?= htmlspecialchars(capitalizeWords($party['address1'] ?? '')) ?></td></tr>
                                                            <tr><th class="label" width="30%">Address 2</th><td class="value"><?= htmlspecialchars(capitalizeWords($party['address2'] ?? '')) ?></td></tr>
                                                            <tr><th class="label" width="30%">Pincode</th><td class="value"><?= htmlspecialchars(capitalizeWords($party['pincode'] ?? '')) ?></td></tr>
                                                            <tr><th class="label" width="30%">City</th><td class="value"><?= htmlspecialchars(capitalizeWords($party['city'] ?? '')) ?></td></tr>
                                                            <tr><th class="label" width="30%">State</th><td class="value"><?= htmlspecialchars(capitalizeWords($party['state'] ?? '')) ?></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="col-lg-4 col-md-10">
                                                <div class="p-4 summary-bg h-100">
                                                    <h5 class="account-summary-title mb-3"><i class="bi bi-cash-coin"></i> Account Summary</h5>
                                                    <table class="table table-sm table-borderless">
                                                        <tbody>
                                                            <tr>
                                                                <th class="label">Last Bill</th>
                                                                <td class="value text-muted"><?= $party['summary']['last_bill'] ?? '--' ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th class="label">Last Payment</th>
                                                                <td class="value text-muted"><?= $party['summary']['last_payment'] ?? '--' ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th class="label">Current Bill</th>
                                                                <td class="value text-muted"><?= $party['summary']['current_bill'] ?? '--' ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th class="label">Pending Bills Sum</th>
                                                                <td class="value text-muted"><?= $party['summary']['pending_sum'] ?? '--' ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                    <div class="text-muted small">(Data will update automatically in future)</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="products<?= $party['id'] ?>" role="tabpanel" aria-labelledby="products-tab">
                                <div class="table-responsive mt-3">
                                    <table class="table table-striped table-bordered product-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Type</th>
                                                <th>Category</th>
                                                <th>Rate</th>
                                                <th>Weight</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($party['products'])): ?>
                                                <?php foreach ($party['products'] as $product): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars(capitalizeWords($product['product_name'] ?? '')) ?></td>
                                                        <td><?= htmlspecialchars(capitalizeWords($product['product_type'] ?? '')) ?></td>
                                                        <td><?= htmlspecialchars(capitalizeWords($product['product_category'] ?? '')) ?></td>
                                                        <td><?= htmlspecialchars($product['rate'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($product['weight'] ?? '') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="5" class="text-center no-products">No products found for this party.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
