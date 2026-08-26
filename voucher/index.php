<?php
include "../protect/auth.php";
include "../protect/db.php";
include "../protect/case_converter.php";

function ensureLedgerVoucherSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS ledger_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT DEFAULT NULL,
        account_type VARCHAR(30) NOT NULL,
        account_id INT DEFAULT NULL,
        account_name VARCHAR(255) NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        mode VARCHAR(50) DEFAULT NULL,
        reference_no VARCHAR(100) DEFAULT NULL,
        remarks VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_company_id (company_id),
        KEY idx_account_type (account_type),
        KEY idx_account_name (account_name),
        KEY idx_payment_date (payment_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $transactionTypeColumn = $conn->query("SHOW COLUMNS FROM ledger_payments LIKE 'transaction_type'");
    if ($transactionTypeColumn && $transactionTypeColumn->num_rows === 0) {
        $conn->query("ALTER TABLE ledger_payments ADD COLUMN transaction_type ENUM('CR','DR') NOT NULL DEFAULT 'CR' AFTER amount");
    }

    $challanNoColumn = $conn->query("SHOW COLUMNS FROM ledger_payments LIKE 'challan_no'");
    if ($challanNoColumn && $challanNoColumn->num_rows === 0) {
        $conn->query("ALTER TABLE ledger_payments ADD COLUMN challan_no VARCHAR(100) DEFAULT NULL AFTER transaction_type");
    }

    $voucherNoColumn = $conn->query("SHOW COLUMNS FROM ledger_payments LIKE 'voucher_no'");
    if ($voucherNoColumn && $voucherNoColumn->num_rows === 0) {
        $conn->query("ALTER TABLE ledger_payments ADD COLUMN voucher_no VARCHAR(100) DEFAULT NULL AFTER challan_no");
    }

    $conn->query("UPDATE ledger_payments
                  SET challan_no = voucher_no
                  WHERE reference_no = 'voucher'
                    AND (challan_no IS NULL OR challan_no = '')
                    AND voucher_no IS NOT NULL
                    AND voucher_no <> ''");

    $conn->query("UPDATE ledger_payments
                  SET voucher_no = CONCAT('V-', voucher_no)
                  WHERE reference_no = 'voucher'
                    AND voucher_no IS NOT NULL
                    AND voucher_no <> ''
                    AND voucher_no NOT LIKE 'V-%'");

    $conn->query("UPDATE ledger_payments
                  SET challan_no = CONCAT('V-', challan_no)
                  WHERE reference_no = 'voucher'
                    AND challan_no IS NOT NULL
                    AND challan_no <> ''
                    AND challan_no NOT LIKE 'V-%'");
}

function voucherMoney($value): string
{
    return number_format((int) round((float) $value));
}

function voucherDate($date): string
{
    $time = strtotime((string) $date);
    return $time ? date('d-m-Y', $time) : '';
}

function formatVoucherNumber($value): string
{
    $number = trim((string) $value);
    if ($number === '') {
        return '';
    }

    return stripos($number, 'V-') === 0 ? $number : 'V-' . $number;
}

function voucherConsignorExists(mysqli $conn, string $accountName, ?int $companyIdFilter): bool
{
    $name = trim($accountName);
    if ($name === '') {
        return false;
    }

    $partyStmt = $conn->prepare("SELECT id FROM party WHERE party_type = 'Consignor' AND LOWER(name) = LOWER(?) LIMIT 1");
    if ($partyStmt) {
        $partyStmt->bind_param('s', $name);
        $partyStmt->execute();
        $exists = (bool) $partyStmt->get_result()->fetch_assoc();
        $partyStmt->close();
        if ($exists) {
            return true;
        }
    }

    $ledgerSql = "SELECT id FROM ledger_payments WHERE LOWER(account_name) = LOWER(?)";
    $types = 's';
    $params = [$name];
    if ($companyIdFilter !== null) {
        $ledgerSql .= " AND company_id = ?";
        $types .= 'i';
        $params[] = $companyIdFilter;
    }
    $ledgerSql .= " LIMIT 1";

    $ledgerStmt = $conn->prepare($ledgerSql);
    if ($ledgerStmt) {
        $ledgerStmt->bind_param($types, ...$params);
        $ledgerStmt->execute();
        $exists = (bool) $ledgerStmt->get_result()->fetch_assoc();
        $ledgerStmt->close();
        return $exists;
    }

    return false;
}

ensureLedgerVoucherSchema($conn);

$isCompanyUser = !empty($_SESSION['company_id']);
$companyIdFilter = $isCompanyUser ? (int) $_SESSION['company_id'] : null;

if (isset($_GET['ajax']) && $_GET['ajax'] === 'consignor_search') {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim((string) ($_GET['q'] ?? ''));
    $rows = [];
    $seen = [];

    if ($query !== '') {
        $like = '%' . $query . '%';
        $partySql = "SELECT id, name FROM party WHERE party_type = 'Consignor' AND name LIKE ? ORDER BY name ASC LIMIT 10";
        $partyStmt = $conn->prepare($partySql);
        if ($partyStmt) {
            $partyStmt->bind_param('s', $like);
            $partyStmt->execute();
            $partyResult = $partyStmt->get_result();
            while ($row = $partyResult->fetch_assoc()) {
                $name = trim((string) ($row['name'] ?? ''));
                $key = strtolower($name);
                if ($name !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = ['id' => (int) ($row['id'] ?? 0), 'name' => $name];
                }
            }
            $partyStmt->close();
        }

        $companyWhere = $companyIdFilter !== null ? 'AND company_id = ?' : '';
        $ledgerSql = "
            SELECT account_name AS name, COALESCE(account_id, 0) AS id
            FROM ledger_payments
            WHERE account_name LIKE ?
              {$companyWhere}
              AND account_name IS NOT NULL
              AND account_name <> ''
            GROUP BY COALESCE(account_id, 0), account_name
            ORDER BY account_name ASC
            LIMIT 15
        ";
        $ledgerStmt = $conn->prepare($ledgerSql);
        if ($ledgerStmt) {
            if ($companyIdFilter !== null) {
                $ledgerStmt->bind_param('si', $like, $companyIdFilter);
            } else {
                $ledgerStmt->bind_param('s', $like);
            }
            $ledgerStmt->execute();
            $ledgerResult = $ledgerStmt->get_result();
            while ($row = $ledgerResult->fetch_assoc()) {
                $name = trim((string) ($row['name'] ?? ''));
                $key = strtolower($name);
                if ($name !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = ['id' => (int) ($row['id'] ?? 0), 'name' => $name];
                }
            }
            $ledgerStmt->close();
        }
    }

    echo json_encode(['success' => true, 'rows' => array_slice($rows, 0, 15)]);
    exit;
}

$message = '';
$messageType = 'success';
$editId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_voucher'])) {
    $editId = (int) ($_POST['edit_id'] ?? 0);
}
$editVoucher = null;

if (!empty($_SESSION['ledger_voucher_flash'])) {
    $message = (string) ($_SESSION['ledger_voucher_flash']['message'] ?? '');
    $messageType = (string) ($_SESSION['ledger_voucher_flash']['type'] ?? 'success');
    unset($_SESSION['ledger_voucher_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voucher'])) {
    $deleteId = (int) ($_POST['delete_id'] ?? 0);

    if ($deleteId > 0) {
        $deleteSql = "DELETE FROM ledger_payments WHERE id = ? AND account_type = 'TBB' AND reference_no = 'voucher'";
        $deleteTypes = 'i';
        $deleteParams = [$deleteId];
        if ($companyIdFilter !== null) {
            $deleteSql .= " AND company_id = ?";
            $deleteTypes .= 'i';
            $deleteParams[] = $companyIdFilter;
        }

        $deleteStmt = $conn->prepare($deleteSql);
        if ($deleteStmt) {
            $deleteStmt->bind_param($deleteTypes, ...$deleteParams);
            if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
                $_SESSION['ledger_voucher_flash'] = [
                    'message' => 'Voucher deleted successfully.',
                    'type' => 'success'
                ];
            } else {
                $_SESSION['ledger_voucher_flash'] = [
                    'message' => 'Unable to delete voucher.',
                    'type' => 'danger'
                ];
            }
            $deleteStmt->close();
        } else {
            $_SESSION['ledger_voucher_flash'] = [
                'message' => 'Unable to delete voucher.',
                'type' => 'danger'
            ];
        }
    } else {
        $_SESSION['ledger_voucher_flash'] = [
            'message' => 'Invalid voucher.',
            'type' => 'danger'
        ];
    }

    header('Location: index.php?view=' . urlencode((string) ($_POST['return_view'] ?? 'search')));
    exit;
}

if ($editId > 0) {
    $editSql = "SELECT id, COALESCE(account_id, 0) AS account_id, account_name, payment_date, challan_no, voucher_no, amount, transaction_type, mode, remarks
                FROM ledger_payments
                WHERE id = ? AND account_type = 'TBB' AND reference_no = 'voucher'";
    $editTypes = 'i';
    $editParams = [$editId];
    if ($companyIdFilter !== null) {
        $editSql .= " AND company_id = ?";
        $editTypes .= 'i';
        $editParams[] = $companyIdFilter;
    }
    $editSql .= " LIMIT 1";
    $editStmt = $conn->prepare($editSql);
    if ($editStmt) {
        $editStmt->bind_param($editTypes, ...$editParams);
        $editStmt->execute();
        $editVoucher = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_voucher'])) {
    $voucherId = (int) ($_POST['voucher_id'] ?? 0);
    $postedCompanyId = (int) ($_POST['company_id'] ?? 0);
    $entryCompanyId = $companyIdFilter !== null ? $companyIdFilter : ($postedCompanyId > 0 ? $postedCompanyId : null);
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $accountName = toLowercase((string) ($_POST['account_name'] ?? ''));
    $voucherNumber = formatVoucherNumber($_POST['voucher_number'] ?? '');
    $paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
    $amount = (int) round((float) ($_POST['amount'] ?? 0));
    $paymentMode = strtolower(trim((string) ($_POST['payment_mode'] ?? 'cash')));
    $transactionType = strtoupper(trim((string) ($_POST['transaction_type'] ?? 'CR')));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if (!in_array($paymentMode, ['cash', 'check', 'online'], true)) {
        $paymentMode = 'cash';
    }
    if (!in_array($transactionType, ['CR', 'DR'], true)) {
        $transactionType = 'CR';
    }

    if ($accountName === '' || $voucherNumber === '' || $amount <= 0 || strtotime($paymentDate) === false) {
        $messageType = 'danger';
        $message = 'Please enter consignor, voucher number, date, amount, payment mode, and credit/debit.';
    } elseif (!voucherConsignorExists($conn, $accountName, $companyIdFilter)) {
        $messageType = 'danger';
        $message = 'Please select consignor from search list.';
    } else {
        if ($voucherId > 0) {
            $sql = "UPDATE ledger_payments
                    SET company_id = ?, account_id = ?, account_name = ?, payment_date = ?, amount = ?, transaction_type = ?, challan_no = ?, voucher_no = ?, mode = ?, remarks = ?
                    WHERE id = ? AND account_type = 'TBB' AND reference_no = 'voucher'";
            $types = 'iissdsssssi';
            $params = [$entryCompanyId, $accountId, $accountName, $paymentDate, $amount, $transactionType, $voucherNumber, $voucherNumber, $paymentMode, $remarks, $voucherId];
            if ($companyIdFilter !== null) {
                $sql .= " AND company_id = ?";
                $types .= 'i';
                $params[] = $companyIdFilter;
            }
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
            }
        } else {
            $sql = "INSERT INTO ledger_payments (company_id, account_type, account_id, account_name, payment_date, amount, transaction_type, challan_no, voucher_no, mode, reference_no, remarks)
                    VALUES (?, 'TBB', ?, ?, ?, ?, ?, ?, ?, ?, 'voucher', ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('iissdsssss', $entryCompanyId, $accountId, $accountName, $paymentDate, $amount, $transactionType, $voucherNumber, $voucherNumber, $paymentMode, $remarks);
            }
        }

        if ($stmt && $stmt->execute()) {
            $stmt->close();
            $_SESSION['ledger_voucher_flash'] = [
                'message' => $voucherId > 0 ? 'Voucher updated successfully.' : 'Voucher saved successfully.',
                'type' => 'success'
            ];
            header('Location: index.php');
            exit;
        }

        $messageType = 'danger';
        $message = 'Unable to save voucher.';
        if ($stmt) {
            $stmt->close();
        }
    }
}

$filterConsignor = trim((string) ($_GET['filter_consignor'] ?? ''));
$filterMonth = trim((string) ($_GET['filter_month'] ?? ''));
if ($filterMonth === '') {
    $filterMonth = date('Y-m');
}
$filterAmount = trim((string) ($_GET['filter_amount'] ?? ''));
$filterVoucherNumber = trim((string) ($_GET['filter_voucher_number'] ?? ''));
$voucherSearchLimit = (int) ($_GET['search_limit'] ?? 10);
$voucherSearchShowAll = (string) ($_GET['search_show'] ?? '') === 'all';
if ($voucherSearchLimit <= 0) {
    $voucherSearchLimit = 10;
}
$requestedView = (string) ($_GET['view'] ?? '');
$activeVoucherTab = $editVoucher ? 'create' : (in_array($requestedView, ['search', 'list'], true) ? 'search' : 'create');
$hasSearchFilter = $activeVoucherTab === 'search' && !$editVoucher;

$recentEntries = [];
$recentWhere = "WHERE account_type = 'TBB' AND reference_no = 'voucher' ";
$recentParams = [];
$recentTypes = '';
if ($companyIdFilter !== null) {
    $recentWhere .= "AND company_id = ? ";
    $recentParams[] = $companyIdFilter;
    $recentTypes .= 'i';
}
$recentSql = "SELECT id, account_name, payment_date, challan_no, voucher_no, amount, transaction_type, mode, remarks
              FROM ledger_payments
              {$recentWhere}
              ORDER BY payment_date DESC, id DESC
              LIMIT 10";
$recentStmt = $conn->prepare($recentSql);
if ($recentStmt) {
    if ($recentTypes !== '') {
        $recentStmt->bind_param($recentTypes, ...$recentParams);
    }
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    while ($row = $recentResult->fetch_assoc()) {
        $recentEntries[] = $row;
    }
    $recentStmt->close();
}

$searchEntries = [];
$entryWhere = "WHERE account_type = 'TBB' AND reference_no = 'voucher' ";
$entryParams = [];
$entryTypes = '';
if ($companyIdFilter !== null) {
    $entryWhere .= "AND company_id = ? ";
    $entryParams[] = $companyIdFilter;
    $entryTypes .= 'i';
}
if ($filterConsignor !== '') {
    $entryWhere .= "AND account_name LIKE ? ";
    $entryParams[] = '%' . toLowercase($filterConsignor) . '%';
    $entryTypes .= 's';
}
if ($filterMonth !== '') {
    $entryWhere .= "AND DATE_FORMAT(payment_date, '%Y-%m') = ? ";
    $entryParams[] = $filterMonth;
    $entryTypes .= 's';
}
if ($filterVoucherNumber !== '') {
    $entryWhere .= "AND voucher_no LIKE ? ";
    $entryParams[] = '%' . $filterVoucherNumber . '%';
    $entryTypes .= 's';
}
if ($filterAmount !== '' && is_numeric($filterAmount)) {
    $entryWhere .= "AND ROUND(amount, 0) = ? ";
    $entryParams[] = (float) $filterAmount;
    $entryTypes .= 'd';
}

$entrySql = "SELECT id, account_name, payment_date, challan_no, voucher_no, amount, transaction_type, mode, remarks
             FROM ledger_payments
             {$entryWhere}
             ORDER BY payment_date DESC, id DESC";
$entryStmt = $conn->prepare($entrySql);
if ($entryStmt) {
    if ($entryTypes !== '') {
        $entryStmt->bind_param($entryTypes, ...$entryParams);
    }
    $entryStmt->execute();
    $entryResult = $entryStmt->get_result();
    while ($row = $entryResult->fetch_assoc()) {
        $searchEntries[] = $row;
    }
    $entryStmt->close();
}

$voucherTotalRows = count($searchEntries);
$voucherDisplayRows = $voucherSearchShowAll ? $searchEntries : array_slice($searchEntries, 0, $voucherSearchLimit);
$nextVoucherSearchLimit = min($voucherSearchLimit + 10, $voucherTotalRows);
$voucherSearchBaseParams = [
    'view' => 'search',
    'filter_voucher_number' => $filterVoucherNumber,
    'filter_consignor' => $filterConsignor,
    'filter_month' => $filterMonth,
    'filter_amount' => $filterAmount
];
$voucherSearchBaseParams = array_filter($voucherSearchBaseParams, static function ($value) {
    return $value !== '';
});
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Voucher</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../bilty/create/assets/css/notification.css">
    <style>
        body { height: 100vh; background: #f4f6f9; }
        .card { border: none; border-radius: 10px; box-shadow: 0 6px 15px rgba(0, 0, 0, .08); }
        .form-control, .form-select { font-size: 14px; padding: 6px; }
        .btn { font-size: 12px; padding: 5px 10px; }
        .party-tabs .nav-link { font-weight: 600; color: #166534; }
        .party-tabs .nav-link.active { background: #22c55e; color: #ffffff; border-color: #22c55e #22c55e #22c55e; }
        .party-tabs .nav-link:not(.active):hover { border-color: #86efac #86efac #dee2e6; color: #15803d; }
        .form-mode-create { background: #ecfdf3; border: 1px solid #bbf7d0; border-radius: 10px; }
        .voucher-entry-form { display: grid; grid-template-columns: 1fr 1.6fr 1fr 1fr 1fr .9fr 1.6fr auto; gap: 8px; align-items: end; }
        .voucher-filter-form { display: grid; grid-template-columns: 1.1fr 1.6fr 1fr 1fr auto auto; gap: 8px; align-items: end; margin-bottom: 8px; }
        .voucher-entry-form label, .voucher-filter-form label { font-size: 14px; font-weight: 600; margin-bottom: 3px; }
        .required-star { color: #dc2626; font-weight: 800; }
        .party-wrap { position: relative; }
        .party-results {
            position: absolute;
            z-index: 20;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, .08);
            max-height: 190px;
            overflow-y: auto;
            display: none;
        }
        .party-item { padding: 6px 9px; cursor: pointer; font-size: 14px; }
        .party-item:hover, .party-item.active { background: #ecfdf3; font-weight: 600; }
        .ledger-type-cr {
            background-color: #fee2e2 !important;
            color: #991b1b !important;
            border: 1px solid #fca5a5;
        }
        .ledger-type-dr {
            background-color: #dcfce7 !important;
            color: #166534 !important;
            border: 1px solid #86efac;
        }
        .ledger-type-cr:focus {
            background-color: #fecaca !important;
            border-color: #ef4444;
            box-shadow: 0 0 0 .18rem rgba(239, 68, 68, .18);
        }
        .ledger-type-dr:focus {
            background-color: #bbf7d0 !important;
            border-color: #22c55e;
            box-shadow: 0 0 0 .18rem rgba(34, 197, 94, .18);
        }
        .voucher-table th {
            white-space: nowrap;
            font-size: 14px;
            padding: 3px 5px;
        }
        .voucher-table td {
            font-size: 14px;
            vertical-align: middle;
            padding: 3px 5px;
        }
        .voucher-table tbody tr:nth-child(odd) td { background: #ffffff; }
        .voucher-table tbody tr:nth-child(even) td { background: #f1f5f9; }
        .voucher-party-name { font-size: 14px; font-weight: 700; }
        .voucher-actions { display: flex; gap: 4px; flex-wrap: wrap; }
        .voucher-actions .btn { padding: 3px 7px; font-size: 11px; }
        @media (max-width: 1200px) {
            .voucher-entry-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .voucher-filter-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 767.98px) {
            body { height: auto; }
            .container-fluid { padding-left: 8px; padding-right: 8px; }
            .card-body { padding: 0.75rem; }
            .party-tabs .nav-item { flex: 1 1 50%; }
            .party-tabs .nav-link { width: 100%; text-align: center; font-size: 13px; padding: 0.6rem 0.4rem; }
            .voucher-entry-form, .voucher-filter-form { grid-template-columns: 1fr; }
            .table { font-size: 12px; }
        }
    </style>
</head>

<body>
    <?php include "../content/nav.php"; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 party-tabs" id="voucherTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeVoucherTab === 'create' ? 'active' : '' ?>" id="voucher-create-tab" data-bs-toggle="tab"
                    data-bs-target="#voucher-create-pane" type="button" role="tab" aria-controls="voucher-create-pane"
                    aria-selected="<?= $activeVoucherTab === 'create' ? 'true' : 'false' ?>">
                    <i class="bi bi-plus-circle"></i> Create Voucher
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeVoucherTab === 'search' ? 'active' : '' ?>" id="voucher-search-tab" data-bs-toggle="tab"
                    data-bs-target="#voucher-search-pane" type="button" role="tab" aria-controls="voucher-search-pane"
                    aria-selected="<?= $activeVoucherTab === 'search' ? 'true' : 'false' ?>">
                    <i class="bi bi-search"></i> Search Voucher
                </button>
            </li>
        </ul>

        <div class="tab-content" id="voucherTabsContent">
            <div class="tab-pane fade <?= $activeVoucherTab === 'create' ? 'show active' : '' ?>" id="voucher-create-pane" role="tabpanel" aria-labelledby="voucher-create-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body form-mode-create">
                                <h6 class="fw-bold mb-2"><i class="bi bi-receipt"></i> <?= $editVoucher ? 'Update Voucher' : 'Create Voucher' ?></h6>
                                <form method="post" class="voucher-entry-form" id="voucher-entry-form" autocomplete="off" data-edit-mode="<?= $editVoucher ? '1' : '0' ?>">
                            <input type="hidden" name="save_voucher" value="1">
                            <input type="hidden" name="voucher_id" value="<?= (int) ($editVoucher['id'] ?? 0) ?>">
                            <input type="hidden" name="company_id" value="<?= htmlspecialchars((string) ($companyIdFilter ?? '')) ?>">
                            <input type="hidden" name="account_id" id="voucher-account-id" value="<?= (int) ($editVoucher['account_id'] ?? 0) ?>">

                            <div>
                                <label for="voucher-number">Voucher Number <span class="required-star">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="voucher_number" id="voucher-number" value="<?= htmlspecialchars((string) ($editVoucher['voucher_no'] ?? '')) ?>" required>
                            </div>

                            <div class="party-wrap">
                                <label for="voucher-account-name">Consignor Name <span class="required-star">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="account_name" id="voucher-account-name" value="<?= htmlspecialchars(capitalizeWords((string) ($editVoucher['account_name'] ?? ''))) ?>" required>
                                <div class="party-results" id="voucher-party-results"></div>
                            </div>

                            <div>
                                <label for="voucher-date">Date <span class="required-star">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="payment_date" id="voucher-date" value="<?= htmlspecialchars((string) ($editVoucher['payment_date'] ?? date('Y-m-d'))) ?>" required>
                            </div>

                            <div>
                                <label for="voucher-amount">Amount <span class="required-star">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="amount" id="voucher-amount" inputmode="numeric" pattern="^\d+$" value="<?= htmlspecialchars((string) (isset($editVoucher['amount']) ? (int) round((float) $editVoucher['amount']) : '')) ?>" required>
                            </div>

                            <div>
                                <label for="voucher-payment-mode">Payment Mode <span class="required-star">*</span></label>
                                <select class="form-select form-select-sm" name="payment_mode" id="voucher-payment-mode" required>
                                    <option value="cash" <?= (($editVoucher['mode'] ?? 'cash') === 'cash') ? 'selected' : '' ?>>Cash</option>
                                    <option value="check" <?= (($editVoucher['mode'] ?? 'cash') === 'check') ? 'selected' : '' ?>>Check</option>
                                    <option value="online" <?= (($editVoucher['mode'] ?? 'cash') === 'online') ? 'selected' : '' ?>>Online</option>
                                </select>
                            </div>

                            <div>
                                <label for="voucher-transaction-type">Credit/Debit <span class="required-star">*</span></label>
                                <select class="form-select form-select-sm <?= (($editVoucher['transaction_type'] ?? 'CR') === 'DR') ? 'ledger-type-dr' : 'ledger-type-cr' ?>" name="transaction_type" id="voucher-transaction-type" required>
                                    <option value="CR" <?= (($editVoucher['transaction_type'] ?? 'CR') === 'CR') ? 'selected' : '' ?>>Credit - लिए</option>
                                    <option value="DR" <?= (($editVoucher['transaction_type'] ?? 'CR') === 'DR') ? 'selected' : '' ?>>Debit - दिए</option>
                                </select>
                            </div>

                            <div>
                                <label for="voucher-remarks">Remark</label>
                                <input type="text" class="form-control form-control-sm" name="remarks" id="voucher-remarks" value="<?= htmlspecialchars((string) ($editVoucher['remarks'] ?? '')) ?>" placeholder="Payment transfer in account">
                            </div>

                            <div class="d-flex gap-1">
                                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-save"></i> <?= $editVoucher ? 'Update' : 'Save' ?></button>
                                <?php if ($editVoucher): ?>
                                    <a href="index.php" class="btn btn-danger btn-sm">Cancel</a>
                                <?php endif; ?>
                            </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <h6 class="fw-bold mb-2"><i class="bi bi-clock-history"></i> Recent Vouchers</h6>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm voucher-table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="55">Sr</th>
                                                <th>Party Name</th>
                                                <th>Voucher Number</th>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th>Debit/Credit</th>
                                                <th>Mode</th>
                                                <th>Remark</th>
                                                <th width="210">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentEntries)): ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted py-3">No recent voucher found</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($recentEntries as $index => $entry): ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td class="voucher-party-name"><?= htmlspecialchars(capitalizeWords((string) ($entry['account_name'] ?? ''))) ?></td>
                                                    <td><?= htmlspecialchars((string) ($entry['voucher_no'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars(voucherDate($entry['payment_date'] ?? '')) ?></td>
                                                    <td class="text-end"><?= voucherMoney($entry['amount'] ?? 0) ?></td>
                                                    <td>
                                                        <span class="badge <?= (($entry['transaction_type'] ?? 'CR') === 'DR') ? 'ledger-type-dr' : 'ledger-type-cr' ?>">
                                                            <?= (($entry['transaction_type'] ?? 'CR') === 'DR') ? 'Debit' : 'Credit' ?>
                                                        </span>
                                                    </td>
                                                    <td><?= htmlspecialchars(capitalizeWords((string) ($entry['mode'] ?? ''))) ?></td>
                                                    <td><?= htmlspecialchars(capitalizeWords((string) ($entry['remarks'] ?? ''))) ?></td>
                                                    <td>
                                                        <div class="voucher-actions">
                                                            <a href="print.php?id=<?= (int) ($entry['id'] ?? 0) ?>" target="_blank" class="btn btn-primary btn-sm">
                                                                <i class="bi bi-printer"></i> Print
                                                            </a>
                                                            <form method="post" class="d-inline voucher-edit-form">
                                                                <input type="hidden" name="edit_voucher" value="1">
                                                                <input type="hidden" name="edit_id" value="<?= (int) ($entry['id'] ?? 0) ?>">
                                                                <button type="submit" class="btn btn-warning btn-sm">
                                                                    <i class="bi bi-pencil-square"></i> Edit
                                                                </button>
                                                            </form>
                                                            <form method="post" class="d-inline voucher-delete-form">
                                                                <input type="hidden" name="delete_voucher" value="1">
                                                                <input type="hidden" name="delete_id" value="<?= (int) ($entry['id'] ?? 0) ?>">
                                                                <input type="hidden" name="return_view" value="create">
                                                                <button type="submit" class="btn btn-danger btn-sm">
                                                                    <i class="bi bi-trash"></i> Delete
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $activeVoucherTab === 'search' ? 'show active' : '' ?>" id="voucher-search-pane" role="tabpanel" aria-labelledby="voucher-search-tab">
                <div class="card">
                    <div class="card-body p-2">
                        <h6 class="fw-bold mb-2"><i class="bi bi-search"></i> Search Voucher</h6>
                        <form method="get" class="voucher-filter-form" autocomplete="off">
                            <input type="hidden" name="view" value="search">
                            <div>
                                <label for="filter-voucher-number">Voucher Number</label>
                                <input type="text" class="form-control form-control-sm" name="filter_voucher_number" id="filter-voucher-number" value="<?= htmlspecialchars($filterVoucherNumber) ?>">
                            </div>
                            <div class="party-wrap">
                                <label for="filter-consignor">Consignor Name</label>
                                <input type="text" class="form-control form-control-sm" name="filter_consignor" id="filter-consignor" value="<?= htmlspecialchars(capitalizeWords($filterConsignor)) ?>">
                                <div class="party-results" id="filter-party-results"></div>
                            </div>
                            <div>
                                <label for="filter-month">Month</label>
                                <input type="month" class="form-control form-control-sm" name="filter_month" id="filter-month" value="<?= htmlspecialchars($filterMonth) ?>">
                            </div>
                            <div>
                                <label for="filter-amount">Amount</label>
                                <input type="text" class="form-control form-control-sm" name="filter_amount" id="filter-amount" inputmode="numeric" pattern="^\d*$" value="<?= htmlspecialchars($filterAmount) ?>">
                            </div>
                            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-search"></i> Search</button>
                            <a href="index.php?view=search" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                        </form>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-semibold">
                                Showing <?= count($voucherDisplayRows) ?> of <?= $voucherTotalRows ?> voucher<?= $voucherTotalRows === 1 ? '' : 's' ?>
                            </span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm voucher-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="55">Sr</th>
                                        <th>Party Name</th>
                                        <th>Voucher Number</th>
                                        <th>Date</th>
                                        <th class="text-end">Amount</th>
                                        <th>Debit/Credit</th>
                                        <th>Mode</th>
                                        <th>Remark</th>
                                        <th width="280">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($voucherDisplayRows)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-3">No voucher found</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($voucherDisplayRows as $index => $entry): ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td class="voucher-party-name"><?= htmlspecialchars(capitalizeWords((string) ($entry['account_name'] ?? ''))) ?></td>
                                            <td><?= htmlspecialchars((string) ($entry['voucher_no'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars(voucherDate($entry['payment_date'] ?? '')) ?></td>
                                            <td class="text-end">&#8377; <?= voucherMoney($entry['amount'] ?? 0) ?></td>
                                            <td>
                                                <span class="badge <?= (($entry['transaction_type'] ?? 'CR') === 'DR') ? 'ledger-type-dr' : 'ledger-type-cr' ?>">
                                                    <?= (($entry['transaction_type'] ?? 'CR') === 'DR') ? 'Debit' : 'Credit' ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars(capitalizeWords((string) ($entry['mode'] ?? ''))) ?></td>
                                            <td><?= htmlspecialchars(capitalizeWords((string) ($entry['remarks'] ?? ''))) ?></td>
                                            <td>
                                                <div class="voucher-actions">
                                                    <button type="button"
                                                        class="btn btn-success btn-sm voucher-see-btn"
                                                        data-party="<?= htmlspecialchars(capitalizeWords((string) ($entry['account_name'] ?? ''))) ?>"
                                                        data-voucher="<?= htmlspecialchars((string) ($entry['voucher_no'] ?? '')) ?>"
                                                        data-date="<?= htmlspecialchars(voucherDate($entry['payment_date'] ?? '')) ?>"
                                                        data-amount="<?= htmlspecialchars(voucherMoney($entry['amount'] ?? 0)) ?>"
                                                        data-type="<?= (($entry['transaction_type'] ?? 'CR') === 'DR') ? 'Debit' : 'Credit' ?>"
                                                        data-mode="<?= htmlspecialchars(capitalizeWords((string) ($entry['mode'] ?? ''))) ?>"
                                                        data-remark="<?= htmlspecialchars(capitalizeWords((string) ($entry['remarks'] ?? ''))) ?>">
                                                        <i class="bi bi-eye"></i> See
                                                    </button>
                                                    <a href="print.php?id=<?= (int) ($entry['id'] ?? 0) ?>" target="_blank" class="btn btn-primary btn-sm">
                                                        <i class="bi bi-printer"></i> Print
                                                    </a>
                                                    <form method="post" class="d-inline voucher-edit-form">
                                                        <input type="hidden" name="edit_voucher" value="1">
                                                        <input type="hidden" name="edit_id" value="<?= (int) ($entry['id'] ?? 0) ?>">
                                                        <button type="submit" class="btn btn-warning btn-sm">
                                                            <i class="bi bi-pencil-square"></i> Edit
                                                        </button>
                                                    </form>
                                                    <form method="post" class="d-inline voucher-delete-form">
                                                        <input type="hidden" name="delete_voucher" value="1">
                                                        <input type="hidden" name="delete_id" value="<?= (int) ($entry['id'] ?? 0) ?>">
                                                        <input type="hidden" name="return_view" value="search">
                                                        <button type="submit" class="btn btn-danger btn-sm">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($voucherTotalRows > count($voucherDisplayRows)): ?>
                            <div class="d-flex justify-content-center gap-2 mt-2">
                                <a href="index.php?<?= htmlspecialchars(http_build_query(array_merge($voucherSearchBaseParams, ['search_limit' => $nextVoucherSearchLimit]))) ?>" class="btn btn-primary btn-sm">
                                    <i class="bi bi-chevron-down"></i> See More
                                </a>
                                <a href="index.php?<?= htmlspecialchars(http_build_query(array_merge($voucherSearchBaseParams, ['search_show' => 'all']))) ?>" class="btn btn-success btn-sm">
                                    <i class="bi bi-list-ul"></i> See All
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="voucherSeeModal" tabindex="-1" aria-labelledby="voucherSeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="voucherSeeModalLabel">Voucher Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-bordered table-sm mb-0">
                        <tbody>
                            <tr><th width="35%">Voucher Number</th><td id="see-voucher"></td></tr>
                            <tr><th>Date</th><td id="see-date"></td></tr>
                            <tr><th>Party Name</th><td id="see-party" class="voucher-party-name"></td></tr>
                            <tr><th>Amount</th><td id="see-amount"></td></tr>
                            <tr><th>Debit/Credit</th><td id="see-type"></td></tr>
                            <tr><th>Mode</th><td id="see-mode"></td></tr>
                            <tr><th>Remark</th><td id="see-remark"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../bilty/create/assets/js/notification.js"></script>
    <?php if ($message !== ''): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const message = <?= json_encode($message) ?>;
                <?php if ($messageType === 'danger'): ?>
                    showError(message, 5000);
                <?php else: ?>
                    showSuccess(message, 5000);
                <?php endif; ?>
            });
        </script>
    <?php endif; ?>
    <script>
        (function () {
            const accountInput = document.getElementById('voucher-account-name');
            const accountIdInput = document.getElementById('voucher-account-id');
            const resultsBox = document.getElementById('voucher-party-results');
            const filterConsignorInput = document.getElementById('filter-consignor');
            const filterConsignorResultsBox = document.getElementById('filter-party-results');
            const filterAmountInput = document.getElementById('filter-amount');
            const typeSelect = document.getElementById('voucher-transaction-type');
            const dateInput = document.getElementById('voucher-date');
            const amountInput = document.getElementById('voucher-amount');
            const tabStorageKey = 'ledgerVoucherActiveTab';
            const hasSearchFilter = <?= $hasSearchFilter ? 'true' : 'false' ?>;
            const isEditMode = <?= $editVoucher ? 'true' : 'false' ?>;
            const requestedView = <?= json_encode((string) ($_GET['view'] ?? '')) ?>;
            const voucherSearchFilterKeys = ['filter_voucher_number', 'filter_consignor', 'filter_month', 'filter_amount', 'search_limit', 'search_show'];
            let searchTimer = null;
            let currentRows = [];
            let activeIndex = -1;

            const navigationEntry = performance.getEntriesByType('navigation')[0];
            if (navigationEntry && navigationEntry.type === 'reload') {
                const url = new URL(window.location.href);
                const isSearchTab = ['search', 'list'].includes(url.searchParams.get('view') || '');
                const hasVoucherSearchFilter = voucherSearchFilterKeys.some((key) => url.searchParams.has(key));
                if (isSearchTab && hasVoucherSearchFilter) {
                    window.location.replace('index.php?view=search');
                    return;
                }
            }

            function setText(id, value) {
                const element = document.getElementById(id);
                if (element) {
                    element.textContent = value || '';
                }
            }

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char]));
            }

            function capitalizeWords(value) {
                return String(value ?? '').toLowerCase().replace(/\b\w/g, (char) => char.toUpperCase());
            }

            function hideResults() {
                resultsBox.style.display = 'none';
                resultsBox.innerHTML = '';
                currentRows = [];
                activeIndex = -1;
            }

            function selectParty(row) {
                accountInput.value = capitalizeWords(row.name || '');
                accountIdInput.value = row.id || '0';
                accountInput.dataset.selectedName = accountInput.value.trim().toLowerCase();
                hideResults();
                document.getElementById('voucher-date')?.focus();
            }

            function highlightActiveItem() {
                const items = Array.from(resultsBox.querySelectorAll('.party-item'));
                items.forEach((item, index) => item.classList.toggle('active', index === activeIndex));
                if (activeIndex >= 0 && items[activeIndex]) {
                    items[activeIndex].scrollIntoView({ block: 'nearest' });
                }
            }

            function renderResults(rows) {
                if (!Array.isArray(rows) || rows.length === 0) {
                    hideResults();
                    return;
                }

                currentRows = rows;
                activeIndex = -1;
                resultsBox.innerHTML = rows.map((row, index) =>
                    `<div class="party-item" data-index="${index}">${escapeHtml(capitalizeWords(row.name || ''))}</div>`
                ).join('');
                resultsBox.style.display = 'block';

                Array.from(resultsBox.querySelectorAll('.party-item')).forEach((item) => {
                    item.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                        selectParty(rows[Number(item.dataset.index || 0)]);
                    });
                });
            }

            if (accountInput && accountIdInput && resultsBox) {
                accountInput.addEventListener('input', () => {
                    accountIdInput.value = '0';
                    accountInput.dataset.selectedName = '';
                    clearTimeout(searchTimer);
                    const query = accountInput.value.trim();
                    if (query.length < 1) {
                        hideResults();
                        return;
                    }

                    searchTimer = setTimeout(async () => {
                        try {
                            const response = await fetch(`index.php?ajax=consignor_search&q=${encodeURIComponent(query)}`);
                            const data = await response.json();
                            renderResults(data.rows || []);
                        } catch (error) {
                            hideResults();
                        }
                    }, 180);
                });

                accountInput.addEventListener('keydown', (event) => {
                    if (resultsBox.style.display !== 'block' || currentRows.length === 0) {
                        return;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        activeIndex = activeIndex < currentRows.length - 1 ? activeIndex + 1 : 0;
                        highlightActiveItem();
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        activeIndex = activeIndex > 0 ? activeIndex - 1 : currentRows.length - 1;
                        highlightActiveItem();
                    } else if (event.key === 'Enter' && activeIndex >= 0) {
                        event.preventDefault();
                        selectParty(currentRows[activeIndex]);
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        hideResults();
                    }
                });

                accountInput.addEventListener('blur', () => setTimeout(hideResults, 150));

                window.addEventListener('load', () => {
                    document.getElementById('voucher-number')?.focus();
                });
            }

            if (filterConsignorInput && filterConsignorResultsBox) {
                let filterConsignorTimer = null;
                let filterConsignorRows = [];
                let filterConsignorActiveIndex = -1;

                function hideFilterConsignorResults() {
                    filterConsignorResultsBox.style.display = 'none';
                    filterConsignorResultsBox.innerHTML = '';
                    filterConsignorRows = [];
                    filterConsignorActiveIndex = -1;
                }

                function selectFilterConsignor(row) {
                    filterConsignorInput.value = capitalizeWords(row.name || '');
                    hideFilterConsignorResults();
                    document.getElementById('filter-month')?.focus();
                }

                function highlightFilterConsignorItem() {
                    const items = Array.from(filterConsignorResultsBox.querySelectorAll('.party-item'));
                    items.forEach((item, index) => item.classList.toggle('active', index === filterConsignorActiveIndex));
                    if (filterConsignorActiveIndex >= 0 && items[filterConsignorActiveIndex]) {
                        items[filterConsignorActiveIndex].scrollIntoView({ block: 'nearest' });
                    }
                }

                function renderFilterConsignorResults(rows) {
                    if (!Array.isArray(rows) || rows.length === 0) {
                        hideFilterConsignorResults();
                        return;
                    }

                    filterConsignorRows = rows;
                    filterConsignorActiveIndex = -1;
                    filterConsignorResultsBox.innerHTML = rows.map((row, index) =>
                        `<div class="party-item" data-index="${index}">${escapeHtml(capitalizeWords(row.name || ''))}</div>`
                    ).join('');
                    filterConsignorResultsBox.style.display = 'block';

                    Array.from(filterConsignorResultsBox.querySelectorAll('.party-item')).forEach((item) => {
                        item.addEventListener('mousedown', (event) => {
                            event.preventDefault();
                            selectFilterConsignor(rows[Number(item.dataset.index || 0)]);
                        });
                    });
                }

                filterConsignorInput.addEventListener('input', () => {
                    clearTimeout(filterConsignorTimer);
                    const query = filterConsignorInput.value.trim();
                    if (query.length < 1) {
                        hideFilterConsignorResults();
                        return;
                    }

                    filterConsignorTimer = setTimeout(async () => {
                        try {
                            const response = await fetch(`index.php?ajax=consignor_search&q=${encodeURIComponent(query)}`);
                            const data = await response.json();
                            renderFilterConsignorResults(data.rows || []);
                        } catch (error) {
                            hideFilterConsignorResults();
                        }
                    }, 180);
                });

                filterConsignorInput.addEventListener('keydown', (event) => {
                    if (filterConsignorResultsBox.style.display !== 'block' || filterConsignorRows.length === 0) {
                        return;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        filterConsignorActiveIndex = filterConsignorActiveIndex < filterConsignorRows.length - 1 ? filterConsignorActiveIndex + 1 : 0;
                        highlightFilterConsignorItem();
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        filterConsignorActiveIndex = filterConsignorActiveIndex > 0 ? filterConsignorActiveIndex - 1 : filterConsignorRows.length - 1;
                        highlightFilterConsignorItem();
                    } else if (event.key === 'Enter' && filterConsignorActiveIndex >= 0) {
                        event.preventDefault();
                        selectFilterConsignor(filterConsignorRows[filterConsignorActiveIndex]);
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        hideFilterConsignorResults();
                    }
                });

                filterConsignorInput.addEventListener('blur', () => setTimeout(hideFilterConsignorResults, 150));
            }

            if (filterAmountInput) {
                filterAmountInput.addEventListener('input', () => {
                    filterAmountInput.value = filterAmountInput.value.replace(/\D/g, '');
                });
            }

            if (typeSelect) {
                const syncTypeColor = () => {
                    typeSelect.classList.toggle('ledger-type-dr', typeSelect.value === 'DR');
                    typeSelect.classList.toggle('ledger-type-cr', typeSelect.value !== 'DR');
                };
                typeSelect.addEventListener('change', syncTypeColor);
                syncTypeColor();
            }

            if (dateInput && amountInput) {
                dateInput.addEventListener('keydown', (event) => {
                    if (event.key === 'Tab' && !event.shiftKey) {
                        event.preventDefault();
                        amountInput.focus();
                        amountInput.select();
                    }
                });
            }

            Array.from(document.querySelectorAll('.voucher-see-btn')).forEach((button) => {
                button.addEventListener('click', () => {
                    setText('see-party', button.dataset.party || '');
                    setText('see-voucher', button.dataset.voucher || '');
                    setText('see-date', button.dataset.date || '');
                    setText('see-amount', button.dataset.amount ? `₹ ${button.dataset.amount}` : '');
                    setText('see-type', button.dataset.type || '');
                    setText('see-mode', button.dataset.mode || '');
                    setText('see-remark', button.dataset.remark || '');

                    const modalElement = document.getElementById('voucherSeeModal');
                    if (modalElement && window.bootstrap) {
                        bootstrap.Modal.getOrCreateInstance(modalElement).show();
                    }
                });
            });

            document.querySelectorAll('.voucher-delete-form').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const ok = typeof nmConfirm === 'function'
                        ? await nmConfirm('Delete this voucher?')
                        : window.confirm('Delete this voucher?');
                    if (ok) {
                        form.submit();
                    }
                });
            });

            document.querySelectorAll('.voucher-edit-form').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const ok = typeof nmConfirm === 'function'
                        ? await nmConfirm('Edit this voucher?')
                        : window.confirm('Edit this voucher?');
                    if (ok) {
                        form.submit();
                    }
                });
            });

            const voucherEntryForm = document.getElementById('voucher-entry-form');
            if (voucherEntryForm && accountInput) {
                accountInput.dataset.selectedName = accountInput.value.trim() ? accountInput.value.trim().toLowerCase() : '';
                voucherEntryForm.addEventListener('submit', (event) => {
                    const selectedName = accountInput.dataset.selectedName || '';
                    const currentName = accountInput.value.trim().toLowerCase();
                    if (!selectedName || selectedName !== currentName) {
                        event.preventDefault();
                        if (typeof showWarning === 'function') {
                            showWarning('Please select consignor from search list.');
                        } else {
                            alert('Please select consignor from search list.');
                        }
                    }
                });
            }

            if (voucherEntryForm && voucherEntryForm.dataset.editMode === '1') {
                voucherEntryForm.addEventListener('submit', async (event) => {
                    if (event.defaultPrevented) {
                        return;
                    }

                    if (voucherEntryForm.dataset.confirmed === '1') {
                        return;
                    }

                    event.preventDefault();
                    const ok = typeof nmConfirm === 'function'
                        ? await nmConfirm('Update this voucher?')
                        : window.confirm('Update this voucher?');
                    if (ok) {
                        voucherEntryForm.dataset.confirmed = '1';
                        voucherEntryForm.submit();
                    }
                });
            }

            const tabButtons = Array.from(document.querySelectorAll('#voucherTabs [data-bs-toggle="tab"]'));
            const tabTargetMap = {
                '#voucher-create-pane': 'create',
                '#voucher-search-pane': 'search'
            };

            function syncTabUrl(target) {
                const tabName = tabTargetMap[target] || 'create';
                const url = new URL(window.location.href);

                if (tabName === 'search') {
                    url.searchParams.set('view', 'search');
                } else {
                    url.searchParams.set('view', 'create');
                    voucherSearchFilterKeys.forEach((key) => url.searchParams.delete(key));
                }

                window.history.replaceState(null, '', url.toString());
            }

            tabButtons.forEach((tabButton) => {
                tabButton.addEventListener('shown.bs.tab', () => {
                    const target = tabButton.getAttribute('data-bs-target') || '';
                    localStorage.setItem(tabStorageKey, target);
                    syncTabUrl(target);
                });
            });

            if (!requestedView && !hasSearchFilter && !isEditMode) {
                const savedTarget = localStorage.getItem(tabStorageKey);
                if (savedTarget) {
                    const savedButton = document.querySelector(`#voucherTabs [data-bs-target="${savedTarget}"]`);
                    if (savedButton && window.bootstrap) {
                        bootstrap.Tab.getOrCreateInstance(savedButton).show();
                    }
                }
            } else if (isEditMode) {
                localStorage.setItem(tabStorageKey, '#voucher-create-pane');
            }
        })();
    </script>
</body>

</html>
