<?php
include "../protect/auth.php";
include "../protect/db.php";
include "../protect/case_converter.php";

function ensureLedgerChallanSchema(mysqli $conn): void
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
}

function ledgerChallanMoney($value): string
{
    return number_format((int) round((float) $value));
}

function ledgerChallanDate($date): string
{
    $time = strtotime((string) $date);
    return $time ? date('d-m-Y', $time) : '';
}

function ledgerChallanConsignorExists(mysqli $conn, string $accountName, ?int $companyIdFilter): bool
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

    $biltySql = "SELECT id FROM biltys WHERE LOWER(consignor_name) = LOWER(?)";
    $types = 's';
    $params = [$name];
    if ($companyIdFilter !== null) {
        $biltySql .= " AND company_id = ?";
        $types .= 'i';
        $params[] = $companyIdFilter;
    }
    $biltySql .= " LIMIT 1";

    $biltyStmt = $conn->prepare($biltySql);
    if ($biltyStmt) {
        $biltyStmt->bind_param($types, ...$params);
        $biltyStmt->execute();
        $exists = (bool) $biltyStmt->get_result()->fetch_assoc();
        $biltyStmt->close();
        return $exists;
    }

    return false;
}

ensureLedgerChallanSchema($conn);

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
                    $rows[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'name' => $name
                    ];
                }
            }
            $partyStmt->close();
        }

        $companyWhere = $companyIdFilter !== null ? 'AND company_id = ?' : '';
        $biltySql = "
            SELECT consignor_name AS name
            FROM biltys
            WHERE consignor_name LIKE ?
              {$companyWhere}
              AND consignor_name IS NOT NULL
              AND consignor_name <> ''
            GROUP BY consignor_name
            ORDER BY consignor_name ASC
            LIMIT 15
        ";
        $biltyStmt = $conn->prepare($biltySql);
        if ($biltyStmt) {
            if ($companyIdFilter !== null) {
                $biltyStmt->bind_param('si', $like, $companyIdFilter);
            } else {
                $biltyStmt->bind_param('s', $like);
            }
            $biltyStmt->execute();
            $biltyResult = $biltyStmt->get_result();
            while ($row = $biltyResult->fetch_assoc()) {
                $name = trim((string) ($row['name'] ?? ''));
                $key = strtolower($name);
                if ($name !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = [
                        'id' => 0,
                        'name' => $name
                    ];
                }
            }
            $biltyStmt->close();
        }
    }

    echo json_encode(['success' => true, 'rows' => array_slice($rows, 0, 15)]);
    exit;
}

$message = '';
$messageType = 'success';
$editId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_challan_ledger'])) {
    $editId = (int) ($_POST['edit_id'] ?? 0);
}
$editEntry = null;
$searchChallanNo = trim((string) ($_GET['search_challan_no'] ?? ''));
$searchConsignor = trim((string) ($_GET['search_consignor'] ?? ''));
$searchDate = trim((string) ($_GET['search_date'] ?? ''));
if ($searchDate === '') {
    $searchDate = date('Y-m');
}
$searchAmount = trim((string) ($_GET['search_amount'] ?? ''));
$searchLimit = (int) ($_GET['search_limit'] ?? 10);
$searchShowAll = (string) ($_GET['search_show'] ?? '') === 'all';
if ($searchLimit <= 0) {
    $searchLimit = 10;
}

if (!empty($_SESSION['ledger_challan_flash'])) {
    $message = (string) ($_SESSION['ledger_challan_flash']['message'] ?? '');
    $messageType = (string) ($_SESSION['ledger_challan_flash']['type'] ?? 'success');
    unset($_SESSION['ledger_challan_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_challan_ledger'])) {
    $deleteId = (int) ($_POST['delete_id'] ?? 0);

    if ($deleteId > 0) {
        $deleteSql = "DELETE FROM ledger_payments WHERE id = ? AND account_type = 'TBB' AND mode = 'challan'";
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
                $_SESSION['ledger_challan_flash'] = [
                    'message' => 'Challan ledger entry deleted successfully.',
                    'type' => 'success'
                ];
            } else {
                $_SESSION['ledger_challan_flash'] = [
                    'message' => 'Unable to delete challan ledger entry.',
                    'type' => 'danger'
                ];
            }
            $deleteStmt->close();
        } else {
            $_SESSION['ledger_challan_flash'] = [
                'message' => 'Unable to delete challan ledger entry.',
                'type' => 'danger'
            ];
        }
    } else {
        $_SESSION['ledger_challan_flash'] = [
            'message' => 'Invalid challan ledger entry.',
            'type' => 'danger'
        ];
    }

    header('Location: challan.php?tab=search');
    exit;
}

if ($editId > 0) {
    $editSql = "SELECT id, company_id, COALESCE(account_id, 0) AS account_id, account_name, payment_date, challan_no, amount, transaction_type
                FROM ledger_payments
                WHERE id = ? AND account_type = 'TBB' AND mode = 'challan'";
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
        $editEntry = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_challan_ledger'])) {
    $ledgerEntryId = (int) ($_POST['ledger_entry_id'] ?? 0);
    $postedCompanyId = (int) ($_POST['company_id'] ?? 0);
    $entryCompanyId = $companyIdFilter !== null ? $companyIdFilter : ($postedCompanyId > 0 ? $postedCompanyId : null);
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $accountName = toLowercase((string) ($_POST['account_name'] ?? ''));
    $challanNo = trim((string) ($_POST['challan_no'] ?? ''));
    $challanDate = trim((string) ($_POST['challan_date'] ?? date('Y-m-d')));
    $amount = (int) round((float) ($_POST['amount'] ?? 0));
    $transactionType = strtoupper(trim((string) ($_POST['transaction_type'] ?? 'CR')));

    if (!in_array($transactionType, ['CR', 'DR'], true)) {
        $transactionType = 'CR';
    }

    if ($accountName === '' || $challanNo === '' || $amount <= 0 || strtotime($challanDate) === false) {
        $messageType = 'danger';
        $message = 'Please enter consignor, challan number, date, and valid amount.';
    } elseif (!ledgerChallanConsignorExists($conn, $accountName, $companyIdFilter)) {
        $messageType = 'danger';
        $message = 'Please select consignor from search list.';
    } else {
        $remarks = 'Challan Entry';

        if ($ledgerEntryId > 0) {
            $sql = "UPDATE ledger_payments
                    SET company_id = ?, account_id = ?, account_name = ?, payment_date = ?, amount = ?, transaction_type = ?, challan_no = ?, reference_no = ?, remarks = ?
                    WHERE id = ? AND account_type = 'TBB' AND mode = 'challan'";
            $types = 'iissdssssi';
            $params = [$entryCompanyId, $accountId, $accountName, $challanDate, $amount, $transactionType, $challanNo, $challanNo, $remarks, $ledgerEntryId];
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
            $sql = "INSERT INTO ledger_payments (company_id, account_type, account_id, account_name, payment_date, amount, transaction_type, challan_no, mode, reference_no, remarks)
                    VALUES (?, 'TBB', ?, ?, ?, ?, ?, ?, 'challan', ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('iissdssss', $entryCompanyId, $accountId, $accountName, $challanDate, $amount, $transactionType, $challanNo, $challanNo, $remarks);
            }
        }

        if ($stmt && $stmt->execute()) {
            $stmt->close();
            $_SESSION['ledger_challan_flash'] = [
                'message' => $ledgerEntryId > 0 ? 'Challan ledger entry updated successfully.' : 'Challan ledger entry saved successfully.',
                'type' => 'success'
            ];
            header('Location: challan.php');
            exit;
        }

        $messageType = 'danger';
        $message = 'Unable to save challan ledger entry.';
        if ($stmt) {
            $stmt->close();
        }
    }
}

$recentChallanRows = [];
$recentChallanWhere = "WHERE account_type = 'TBB' AND mode = 'challan' ";
$recentChallanParams = [];
$recentChallanTypes = '';
if ($companyIdFilter !== null) {
    $recentChallanWhere .= "AND company_id = ? ";
    $recentChallanParams[] = $companyIdFilter;
    $recentChallanTypes .= 'i';
}

$recentChallanSql = "SELECT id, account_name, payment_date, challan_no, amount, transaction_type
                     FROM ledger_payments
                     {$recentChallanWhere}
                     ORDER BY created_at DESC, id DESC
                     LIMIT 10";
$recentChallanStmt = $conn->prepare($recentChallanSql);
if ($recentChallanStmt) {
    if ($recentChallanTypes !== '') {
        $recentChallanStmt->bind_param($recentChallanTypes, ...$recentChallanParams);
    }
    $recentChallanStmt->execute();
    $recentChallanResult = $recentChallanStmt->get_result();
    while ($row = $recentChallanResult->fetch_assoc()) {
        $recentChallanRows[] = $row;
    }
    $recentChallanStmt->close();
}

$partyLedgerRows = [];

$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$partyLedgerWhere = "WHERE account_type = 'TBB' AND (mode = 'challan' OR reference_no = 'voucher') ";
$partyLedgerParams = [
    $currentMonthStart,
    $currentMonthEnd,
    $currentMonthStart,
    $currentMonthEnd,
    $currentMonthStart,
    $currentMonthEnd,
    $currentMonthStart
];
$partyLedgerTypes = 'sssssss';
if ($companyIdFilter !== null) {
    $partyLedgerWhere .= "AND company_id = ? ";
    $partyLedgerParams[] = $companyIdFilter;
    $partyLedgerTypes .= 'i';
}

$partyLedgerSql = "SELECT account_name,
                          SUM(CASE WHEN payment_date >= ? AND payment_date <= ? THEN 1 ELSE 0 END) AS total_entries,
                          COALESCE(SUM(CASE WHEN transaction_type = 'CR' AND payment_date >= ? AND payment_date <= ? THEN amount ELSE 0 END), 0) AS cr_amount,
                          COALESCE(SUM(CASE WHEN transaction_type = 'DR' AND payment_date >= ? AND payment_date <= ? THEN amount ELSE 0 END), 0) AS dr_amount,
                          COALESCE(SUM(CASE WHEN payment_date < ? THEN CASE WHEN transaction_type = 'DR' THEN amount ELSE -amount END ELSE 0 END), 0) AS opening_balance
                   FROM ledger_payments
                   {$partyLedgerWhere}
                   GROUP BY account_name
                   HAVING total_entries > 0
                   ORDER BY account_name ASC";
$partyLedgerStmt = $conn->prepare($partyLedgerSql);
if ($partyLedgerStmt) {
    if ($partyLedgerTypes !== '') {
        $partyLedgerStmt->bind_param($partyLedgerTypes, ...$partyLedgerParams);
    }
    $partyLedgerStmt->execute();
    $partyLedgerResult = $partyLedgerStmt->get_result();
    while ($row = $partyLedgerResult->fetch_assoc()) {
        $partyLedgerRows[] = $row;
    }
    $partyLedgerStmt->close();
}

$searchChallanRows = [];
$searchChallanWhere = "WHERE account_type = 'TBB' AND mode = 'challan' ";
$searchChallanParams = [];
$searchChallanTypes = '';
if ($companyIdFilter !== null) {
    $searchChallanWhere .= "AND company_id = ? ";
    $searchChallanParams[] = $companyIdFilter;
    $searchChallanTypes .= 'i';
}
if ($searchChallanNo !== '') {
    $searchChallanWhere .= "AND challan_no LIKE ? ";
    $searchChallanParams[] = '%' . $searchChallanNo . '%';
    $searchChallanTypes .= 's';
}
if ($searchConsignor !== '') {
    $searchChallanWhere .= "AND account_name LIKE ? ";
    $searchChallanParams[] = '%' . toLowercase($searchConsignor) . '%';
    $searchChallanTypes .= 's';
}
if ($searchDate !== '') {
    $searchChallanWhere .= "AND DATE_FORMAT(payment_date, '%Y-%m') = ? ";
    $searchChallanParams[] = $searchDate;
    $searchChallanTypes .= 's';
}
if ($searchAmount !== '' && is_numeric($searchAmount)) {
    $searchChallanWhere .= "AND ROUND(amount, 0) = ? ";
    $searchChallanParams[] = (float) $searchAmount;
    $searchChallanTypes .= 'd';
}

$searchChallanSql = "SELECT id, account_name, challan_no, payment_date, amount, transaction_type
                     FROM ledger_payments
                     {$searchChallanWhere}
                     ORDER BY payment_date DESC, id DESC";
$searchChallanStmt = $conn->prepare($searchChallanSql);
if ($searchChallanStmt) {
    if ($searchChallanTypes !== '') {
        $searchChallanStmt->bind_param($searchChallanTypes, ...$searchChallanParams);
    }
    $searchChallanStmt->execute();
    $searchChallanResult = $searchChallanStmt->get_result();
    while ($row = $searchChallanResult->fetch_assoc()) {
        $searchChallanRows[] = $row;
    }
    $searchChallanStmt->close();
}

$searchTotalRows = count($searchChallanRows);
$searchDisplayRows = $searchShowAll ? $searchChallanRows : array_slice($searchChallanRows, 0, $searchLimit);
$nextSearchLimit = min($searchLimit + 10, $searchTotalRows);
$searchBaseParams = [
    'tab' => 'search',
    'search_challan_no' => $searchChallanNo,
    'search_consignor' => $searchConsignor,
    'search_date' => $searchDate,
    'search_amount' => $searchAmount
];
$searchBaseParams = array_filter($searchBaseParams, static function ($value) {
    return $value !== '';
});

$activeLedgerTab = (string) ($_GET['tab'] ?? '');
if ($editEntry) {
    $activeLedgerTab = 'create';
} elseif ($activeLedgerTab === '') {
    $activeLedgerTab = ($searchChallanNo !== '' || $searchConsignor !== '' || $searchDate !== '' || $searchAmount !== '') ? 'search' : 'create';
}
if (!in_array($activeLedgerTab, ['create', 'search', 'party'], true)) {
    $activeLedgerTab = 'create';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Add Challan Ledger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../bilty/create/assets/css/notification.css">
    <style>
        body {
            height: 100vh;
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

        .party-tabs .nav-link {
            font-weight: 600;
            color: #166534;
        }

        .party-tabs .nav-link.active {
            background: #22c55e;
            color: #ffffff;
            border-color: #22c55e #22c55e #22c55e;
        }

        .party-tabs .nav-link:not(.active):hover {
            border-color: #86efac #86efac #dee2e6;
            color: #15803d;
        }

        .form-mode-create {
            background: #ecfdf3;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
        }

        .challan-entry-form { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr .75fr auto; gap: 8px; align-items: end; }
        .challan-filter-form { display: grid; grid-template-columns: 1.3fr 1.6fr 1fr 1fr auto auto; gap: 8px; align-items: end; margin-bottom: 8px; }
        .challan-entry-form label,
        .challan-filter-form label { font-size: 14px; font-weight: 600; margin-bottom: 3px; }
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
        .party-item:hover,
        .party-item.active { background: #ecfdf3; font-weight: 600; }
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
        .entries-table th {
            white-space: nowrap;
            font-size: 14px;
            padding: 3px 5px;
        }
        .entries-table td {
            font-size: 14px;
            vertical-align: middle;
            padding: 3px 5px;
        }
        .entries-table tbody tr:nth-child(odd) td { background: #ffffff; }
        .entries-table tbody tr:nth-child(even) td { background: #f1f5f9; }
        .ledger-party-name { font-size: 14px; font-weight: 700; }
        .challan-actions { display: flex; gap: 4px; flex-wrap: wrap; }
        .challan-actions .btn { padding: 3px 7px; font-size: 11px; }
        @media (max-width: 1200px) {
            .challan-entry-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .challan-filter-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 767.98px) {
            body { height: auto; }
            .container-fluid { padding-left: 8px; padding-right: 8px; }
            .card-body { padding: 0.75rem; }
            .party-tabs .nav-item { flex: 1 1 50%; }
            .party-tabs .nav-link { width: 100%; text-align: center; font-size: 13px; padding: 0.6rem 0.4rem; }
            .challan-entry-form { grid-template-columns: 1fr; }
            .challan-filter-form { grid-template-columns: 1fr; }
            .table { font-size: 12px; }
        }
    </style>
</head>

<body>
    <?php include "../content/nav.php"; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 party-tabs" id="challanLedgerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeLedgerTab === 'create' ? 'active' : '' ?>" id="challan-create-tab" data-bs-toggle="tab"
                    data-bs-target="#challan-create-pane" type="button" role="tab" aria-controls="challan-create-pane"
                    aria-selected="<?= $activeLedgerTab === 'create' ? 'true' : 'false' ?>">
                    <i class="bi bi-plus-circle"></i> Create Challan
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeLedgerTab === 'search' ? 'active' : '' ?>" id="challan-search-tab" data-bs-toggle="tab"
                    data-bs-target="#challan-search-pane" type="button" role="tab" aria-controls="challan-search-pane"
                    aria-selected="<?= $activeLedgerTab === 'search' ? 'true' : 'false' ?>">
                    <i class="bi bi-search"></i> Search Challan
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeLedgerTab === 'party' ? 'active' : '' ?>" id="party-ledger-tab" data-bs-toggle="tab"
                    data-bs-target="#party-ledger-pane" type="button" role="tab" aria-controls="party-ledger-pane"
                    aria-selected="<?= $activeLedgerTab === 'party' ? 'true' : 'false' ?>">
                    <i class="bi bi-journal-text"></i> Party Ledger
                </button>
            </li>
            <li class="ms-auto">
                <a href="index.php" class="btn btn-success btn-sm"><i class="bi bi-arrow-left"></i> Ledger</a>
            </li>
        </ul>

        <div class="tab-content" id="challanLedgerTabsContent">
            <div class="tab-pane fade <?= $activeLedgerTab === 'create' ? 'show active' : '' ?>" id="challan-create-pane" role="tabpanel"
                aria-labelledby="challan-create-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body form-mode-create">
                                <h6 class="fw-bold mb-2"><i class="bi bi-pencil-square"></i> <?= $editEntry ? 'Update Challan' : 'Create Challan' ?></h6>
                                <form method="post" class="challan-entry-form" id="challan-entry-form" autocomplete="off" data-edit-mode="<?= $editEntry ? '1' : '0' ?>">
                                    <input type="hidden" name="add_challan_ledger" value="1">
                                    <input type="hidden" name="ledger_entry_id" value="<?= (int) ($editEntry['id'] ?? 0) ?>">
                                    <input type="hidden" name="company_id" value="<?= htmlspecialchars((string) ($companyIdFilter ?? '')) ?>">
                                    <input type="hidden" name="account_id" id="ledger-account-id" value="<?= (int) ($editEntry['account_id'] ?? 0) ?>">

                                    <div class="party-wrap">
                                        <label for="ledger-account-name">Consignor Name <span class="required-star">*</span></label>
                                        <input type="text" class="form-control form-control-sm" name="account_name" id="ledger-account-name" value="<?= htmlspecialchars(capitalizeWords((string) ($editEntry['account_name'] ?? ''))) ?>" required>
                                        <div class="party-results" id="ledger-party-results"></div>
                                    </div>

                                    <div>
                                        <label for="ledger-challan-no">Challan Number <span class="required-star">*</span></label>
                                        <input type="text" class="form-control form-control-sm" name="challan_no" id="ledger-challan-no" value="<?= htmlspecialchars((string) ($editEntry['challan_no'] ?? '')) ?>" required>
                                    </div>

                                    <div>
                                        <label for="ledger-challan-date">Date <span class="required-star">*</span></label>
                                        <input type="date" class="form-control form-control-sm" name="challan_date" id="ledger-challan-date" value="<?= htmlspecialchars((string) ($editEntry['payment_date'] ?? date('Y-m-d'))) ?>" required>
                                    </div>

                                    <div>
                                        <label for="ledger-amount">Amount <span class="required-star">*</span></label>
                                        <input type="text" class="form-control form-control-sm" name="amount" id="ledger-amount" inputmode="numeric" pattern="^\d+$" value="<?= htmlspecialchars((string) (isset($editEntry['amount']) ? (int) round((float) $editEntry['amount']) : '')) ?>" required>
                                    </div>

                                    <div>
                                        <label for="ledger-transaction-type">Credit/Debit <span class="required-star">*</span></label>
                                        <select class="form-select form-select-sm <?= (($editEntry['transaction_type'] ?? 'CR') === 'DR') ? 'ledger-type-dr' : 'ledger-type-cr' ?>" name="transaction_type" id="ledger-transaction-type" required>
                                            <option value="CR" <?= (($editEntry['transaction_type'] ?? 'CR') === 'CR') ? 'selected' : '' ?>>Credit - देना</option>
                                            <option value="DR" <?= (($editEntry['transaction_type'] ?? 'CR') === 'DR') ? 'selected' : '' ?>>Debit  - लेना</option>
                                        </select>
                                    </div>

                                    <div class="d-flex gap-1">
                                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-save"></i> <?= $editEntry ? 'Update' : 'Save' ?></button>
                                        <?php if ($editEntry): ?>
                                            <a href="challan.php" class="btn btn-danger btn-sm">Cancel</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <h6 class="fw-bold mb-2"><i class="bi bi-clock-history"></i> Recent Challans</h6>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm entries-table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="55">Sr</th>
                                                <th>Consignor</th>
                                                <th>Challan No.</th>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th>Credit/Debit</th>
                                                <th width="210">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentChallanRows)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-3">No recent challan found</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($recentChallanRows as $index => $row): ?>
                                                <?php $type = (string) ($row['transaction_type'] ?? 'CR'); ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td class="ledger-party-name"><?= htmlspecialchars(capitalizeWords((string) ($row['account_name'] ?? ''))) ?></td>
                                                    <td><?= htmlspecialchars((string) ($row['challan_no'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars(ledgerChallanDate($row['payment_date'] ?? '')) ?></td>
                                                    <td class="text-end"><?= ledgerChallanMoney($row['amount'] ?? 0) ?></td>
                                                    <td>
                                                        <span class="badge <?= $type === 'DR' ? 'ledger-type-dr' : 'ledger-type-cr' ?>">
                                                            <?= $type === 'DR' ? 'Debit' : 'Credit' ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="challan-actions">
                                                            <form method="post" class="d-inline ledger-edit-form">
                                                                <input type="hidden" name="edit_challan_ledger" value="1">
                                                                <input type="hidden" name="edit_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                                                <button type="submit" class="btn btn-warning btn-sm">
                                                                    <i class="bi bi-pencil-square"></i> Edit
                                                                </button>
                                                            </form>
                                                            <form method="post" class="d-inline ledger-delete-form">
                                                                <input type="hidden" name="delete_challan_ledger" value="1">
                                                                <input type="hidden" name="delete_id" value="<?= (int) ($row['id'] ?? 0) ?>">
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

            <div class="tab-pane fade <?= $activeLedgerTab === 'search' ? 'show active' : '' ?>" id="challan-search-pane" role="tabpanel" aria-labelledby="challan-search-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <h6 class="fw-bold mb-2"><i class="bi bi-search"></i> Search Challan</h6>
                                <form method="get" class="challan-filter-form" autocomplete="off">
                                    <input type="hidden" name="tab" value="search">
                                    <div>
                                        <label for="search-challan-no">Challan Number</label>
                                        <input type="text" class="form-control form-control-sm" name="search_challan_no" id="search-challan-no" value="<?= htmlspecialchars($searchChallanNo) ?>">
                                    </div>
                                    <div class="party-wrap">
                                        <label for="search-consignor">Consignor Name</label>
                                        <input type="text" class="form-control form-control-sm" name="search_consignor" id="search-consignor" value="<?= htmlspecialchars(capitalizeWords($searchConsignor)) ?>">
                                        <div class="party-results" id="search-party-results"></div>
                                    </div>
                                    <div>
                                        <label for="search-date">Month</label>
                                        <input type="month" class="form-control form-control-sm" name="search_date" id="search-date" value="<?= htmlspecialchars($searchDate) ?>">
                                    </div>
                                    <div>
                                        <label for="search-amount">Amount</label>
                                        <input type="text" class="form-control form-control-sm" name="search_amount" id="search-amount" inputmode="numeric" pattern="^\d*$" value="<?= htmlspecialchars($searchAmount) ?>">
                                    </div>
                                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-search"></i> Search</button>
                                    <a href="challan.php?tab=search" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                                </form>

                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small fw-semibold">
                                        Showing <?= count($searchDisplayRows) ?> of <?= $searchTotalRows ?> challan<?= $searchTotalRows === 1 ? '' : 's' ?>
                                    </span>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm entries-table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="55">Sr</th>
                                                <th>Consignor</th>
                                                <th>Challan No.</th>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th>Credit/Debit</th>
                                                <th width="210">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($searchDisplayRows)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-3">No challan found</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($searchDisplayRows as $index => $row): ?>
                                                <?php $type = (string) ($row['transaction_type'] ?? 'CR'); ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td class="ledger-party-name"><?= htmlspecialchars(capitalizeWords((string) ($row['account_name'] ?? ''))) ?></td>
                                                    <td><?= htmlspecialchars((string) ($row['challan_no'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars(ledgerChallanDate($row['payment_date'] ?? '')) ?></td>
                                                    <td class="text-end"><?= ledgerChallanMoney($row['amount'] ?? 0) ?></td>
                                                    <td>
                                                        <span class="badge <?= $type === 'DR' ? 'ledger-type-dr' : 'ledger-type-cr' ?>">
                                                            <?= $type === 'DR' ? 'Debit' : 'Credit' ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="challan-actions">
                                                            <form method="post" class="d-inline ledger-edit-form">
                                                                <input type="hidden" name="edit_challan_ledger" value="1">
                                                                <input type="hidden" name="edit_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                                                <button type="submit" class="btn btn-warning btn-sm">
                                                                    <i class="bi bi-pencil-square"></i> Edit
                                                                </button>
                                                            </form>
                                                            <form method="post" class="d-inline ledger-delete-form">
                                                                <input type="hidden" name="delete_challan_ledger" value="1">
                                                                <input type="hidden" name="delete_id" value="<?= (int) ($row['id'] ?? 0) ?>">
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
                                <?php if ($searchTotalRows > count($searchDisplayRows)): ?>
                                    <div class="d-flex justify-content-center gap-2 mt-2">
                                        <a href="challan.php?<?= htmlspecialchars(http_build_query(array_merge($searchBaseParams, ['search_limit' => $nextSearchLimit]))) ?>" class="btn btn-primary btn-sm">
                                            <i class="bi bi-chevron-down"></i> See More
                                        </a>
                                        <a href="challan.php?<?= htmlspecialchars(http_build_query(array_merge($searchBaseParams, ['search_show' => 'all']))) ?>" class="btn btn-success btn-sm">
                                            <i class="bi bi-list-ul"></i> See All
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $activeLedgerTab === 'party' ? 'show active' : '' ?>" id="party-ledger-pane" role="tabpanel" aria-labelledby="party-ledger-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold mb-0"><i class="bi bi-journal-text"></i> Party Ledger</h6>
                                    <button type="button" class="btn btn-primary btn-sm" id="printSelectedPartyLedger">
                                        <i class="bi bi-printer"></i> Print Selected
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm entries-table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="34"><input type="checkbox" id="selectAllPartyLedger"></th>
                                                <th width="55">Sr</th>
                                                <th>Consignor</th>
                                                <th class="text-end">Entries</th>
                                                <th class="text-end">Opening</th>
                                                <th class="text-end">Credit Amount</th>
                                                <th class="text-end">Debit Amount</th>
                                                <th class="text-end">Closing</th>
                                                <th width="170">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($partyLedgerRows)): ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted py-3">No party ledger found</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($partyLedgerRows as $index => $row): ?>
                                                <?php
                                                $crAmount = (float) ($row['cr_amount'] ?? 0);
                                                $drAmount = (float) ($row['dr_amount'] ?? 0);
                                                $openingBalance = (float) ($row['opening_balance'] ?? 0);
                                                $closingBalance = $openingBalance + ($drAmount - $crAmount);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" class="party-ledger-checkbox" value="<?= htmlspecialchars((string) ($row['account_name'] ?? '')) ?>">
                                                    </td>
                                                    <td><?= $index + 1 ?></td>
                                                    <td class="ledger-party-name"><?= htmlspecialchars(capitalizeWords((string) ($row['account_name'] ?? ''))) ?></td>
                                                    <td class="text-end"><?= (int) ($row['total_entries'] ?? 0) ?></td>
                                                    <td class="text-end fw-bold">&#8377; <?= ledgerChallanMoney($openingBalance) ?></td>
                                                    <td class="text-end"><span class="badge ledger-type-cr">&#8377; <?= ledgerChallanMoney($crAmount) ?></span></td>
                                                    <td class="text-end"><span class="badge ledger-type-dr">&#8377; <?= ledgerChallanMoney($drAmount) ?></span></td>
                                                    <td class="text-end fw-bold">&#8377; <?= ledgerChallanMoney($closingBalance) ?></td>
                                                    <td>
                                                        <div class="challan-actions">
                                                            <a href="party_ledger.php?party=<?= urlencode((string) ($row['account_name'] ?? '')) ?>" class="btn btn-success btn-sm">
                                                                <i class="bi bi-eye"></i> See
                                                            </a>
                                                            <a href="party_ledger.php?party=<?= urlencode((string) ($row['account_name'] ?? '')) ?>&range=last&print=1" target="_blank" class="btn btn-primary btn-sm">
                                                                <i class="bi bi-printer"></i> Print
                                                            </a>
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
            const accountInput = document.getElementById('ledger-account-name');
            const accountIdInput = document.getElementById('ledger-account-id');
            const resultsBox = document.getElementById('ledger-party-results');
            const searchConsignorInput = document.getElementById('search-consignor');
            const searchConsignorResultsBox = document.getElementById('search-party-results');
            const searchAmountInput = document.getElementById('search-amount');
            const typeSelect = document.getElementById('ledger-transaction-type');
            const dateInput = document.getElementById('ledger-challan-date');
            const amountInput = document.getElementById('ledger-amount');
            const tabStorageKey = 'ledgerChallanActiveTab';
            const isEditMode = <?= $editEntry ? 'true' : 'false' ?>;
            const requestedTab = <?= json_encode((string) ($_GET['tab'] ?? '')) ?>;
            const searchFilterKeys = ['search_challan_no', 'search_consignor', 'search_date', 'search_amount', 'search_limit', 'search_show'];
            let searchTimer = null;
            let currentRows = [];
            let activeIndex = -1;

            const navigationEntry = performance.getEntriesByType('navigation')[0];
            if (navigationEntry && navigationEntry.type === 'reload') {
                const url = new URL(window.location.href);
                const isSearchTab = url.searchParams.get('tab') === 'search';
                const hasSearchFilter = searchFilterKeys.some((key) => url.searchParams.has(key));
                if (isSearchTab && hasSearchFilter) {
                    window.location.replace('challan.php?tab=search');
                    return;
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
                document.getElementById('ledger-challan-no')?.focus();
            }

            function focusAccountInput() {
                if (!accountInput) {
                    return;
                }

                setTimeout(() => {
                    accountInput.focus();
                    accountInput.select();
                }, 80);
            }

            function highlightActiveItem() {
                const items = Array.from(resultsBox.querySelectorAll('.party-item'));
                items.forEach((item, index) => {
                    item.classList.toggle('active', index === activeIndex);
                });

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
                resultsBox.innerHTML = rows.map((row, index) => (
                    `<div class="party-item" data-index="${index}">${escapeHtml(capitalizeWords(row.name || ''))}</div>`
                )).join('');
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
                            const response = await fetch(`challan.php?ajax=consignor_search&q=${encodeURIComponent(query)}`);
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

                window.addEventListener('load', focusAccountInput);
            }

            if (searchConsignorInput && searchConsignorResultsBox) {
                let searchConsignorTimer = null;
                let searchConsignorRows = [];
                let searchConsignorActiveIndex = -1;

                function hideSearchConsignorResults() {
                    searchConsignorResultsBox.style.display = 'none';
                    searchConsignorResultsBox.innerHTML = '';
                    searchConsignorRows = [];
                    searchConsignorActiveIndex = -1;
                }

                function selectSearchConsignor(row) {
                    searchConsignorInput.value = capitalizeWords(row.name || '');
                    hideSearchConsignorResults();
                    document.getElementById('search-date')?.focus();
                }

                function highlightSearchConsignorItem() {
                    const items = Array.from(searchConsignorResultsBox.querySelectorAll('.party-item'));
                    items.forEach((item, index) => {
                        item.classList.toggle('active', index === searchConsignorActiveIndex);
                    });

                    if (searchConsignorActiveIndex >= 0 && items[searchConsignorActiveIndex]) {
                        items[searchConsignorActiveIndex].scrollIntoView({ block: 'nearest' });
                    }
                }

                function renderSearchConsignorResults(rows) {
                    if (!Array.isArray(rows) || rows.length === 0) {
                        hideSearchConsignorResults();
                        return;
                    }

                    searchConsignorRows = rows;
                    searchConsignorActiveIndex = -1;
                    searchConsignorResultsBox.innerHTML = rows.map((row, index) => (
                        `<div class="party-item" data-index="${index}">${escapeHtml(capitalizeWords(row.name || ''))}</div>`
                    )).join('');
                    searchConsignorResultsBox.style.display = 'block';

                    Array.from(searchConsignorResultsBox.querySelectorAll('.party-item')).forEach((item) => {
                        item.addEventListener('mousedown', (event) => {
                            event.preventDefault();
                            selectSearchConsignor(rows[Number(item.dataset.index || 0)]);
                        });
                    });
                }

                searchConsignorInput.addEventListener('input', () => {
                    clearTimeout(searchConsignorTimer);
                    const query = searchConsignorInput.value.trim();
                    if (query.length < 1) {
                        hideSearchConsignorResults();
                        return;
                    }

                    searchConsignorTimer = setTimeout(async () => {
                        try {
                            const response = await fetch(`challan.php?ajax=consignor_search&q=${encodeURIComponent(query)}`);
                            const data = await response.json();
                            renderSearchConsignorResults(data.rows || []);
                        } catch (error) {
                            hideSearchConsignorResults();
                        }
                    }, 180);
                });

                searchConsignorInput.addEventListener('keydown', (event) => {
                    if (searchConsignorResultsBox.style.display !== 'block' || searchConsignorRows.length === 0) {
                        return;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        searchConsignorActiveIndex = searchConsignorActiveIndex < searchConsignorRows.length - 1 ? searchConsignorActiveIndex + 1 : 0;
                        highlightSearchConsignorItem();
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        searchConsignorActiveIndex = searchConsignorActiveIndex > 0 ? searchConsignorActiveIndex - 1 : searchConsignorRows.length - 1;
                        highlightSearchConsignorItem();
                    } else if (event.key === 'Enter' && searchConsignorActiveIndex >= 0) {
                        event.preventDefault();
                        selectSearchConsignor(searchConsignorRows[searchConsignorActiveIndex]);
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        hideSearchConsignorResults();
                    }
                });

                searchConsignorInput.addEventListener('blur', () => setTimeout(hideSearchConsignorResults, 150));
            }

            if (searchAmountInput) {
                searchAmountInput.addEventListener('input', () => {
                    searchAmountInput.value = searchAmountInput.value.replace(/\D/g, '');
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

            const tabButtons = Array.from(document.querySelectorAll('#challanLedgerTabs [data-bs-toggle="tab"]'));
            const tabTargetMap = {
                '#challan-create-pane': 'create',
                '#challan-search-pane': 'search',
                '#party-ledger-pane': 'party'
            };

            function syncTabUrl(target) {
                const tabName = tabTargetMap[target] || 'create';
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabName);

                if (tabName !== 'search') {
                    searchFilterKeys.forEach((key) => url.searchParams.delete(key));
                }

                window.history.replaceState(null, '', url.toString());
            }

            tabButtons.forEach((tabButton) => {
                tabButton.addEventListener('shown.bs.tab', () => {
                    const target = tabButton.getAttribute('data-bs-target') || '';
                    localStorage.setItem(tabStorageKey, target);
                    syncTabUrl(target);
                    if (target === '#challan-create-pane') {
                        focusAccountInput();
                    }
                });
            });

            document.querySelectorAll('.ledger-delete-form').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const ok = typeof nmConfirm === 'function'
                        ? await nmConfirm('Delete this challan ledger entry?')
                        : window.confirm('Delete this challan ledger entry?');
                    if (ok) {
                        form.submit();
                    }
                });
            });

            const selectAllPartyLedger = document.getElementById('selectAllPartyLedger');
            const partyLedgerCheckboxes = Array.from(document.querySelectorAll('.party-ledger-checkbox'));
            const printSelectedPartyLedger = document.getElementById('printSelectedPartyLedger');

            if (selectAllPartyLedger) {
                selectAllPartyLedger.addEventListener('change', () => {
                    partyLedgerCheckboxes.forEach((checkbox) => {
                        checkbox.checked = selectAllPartyLedger.checked;
                    });
                });
            }

            partyLedgerCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    if (!selectAllPartyLedger) {
                        return;
                    }

                    const checkedCount = partyLedgerCheckboxes.filter((item) => item.checked).length;
                    selectAllPartyLedger.checked = checkedCount === partyLedgerCheckboxes.length && partyLedgerCheckboxes.length > 0;
                    selectAllPartyLedger.indeterminate = checkedCount > 0 && checkedCount < partyLedgerCheckboxes.length;
                });
            });

            if (printSelectedPartyLedger) {
                printSelectedPartyLedger.addEventListener('click', () => {
                    const parties = partyLedgerCheckboxes
                        .filter((checkbox) => checkbox.checked)
                        .map((checkbox) => checkbox.value)
                        .filter(Boolean);

                    if (parties.length === 0) {
                        if (typeof showWarning === 'function') {
                            showWarning('Please select at least one consignor ledger to print.');
                        } else {
                            alert('Please select at least one consignor ledger to print.');
                        }
                        return;
                    }

                    const params = new URLSearchParams();
                    params.set('parties', parties.join(','));
                    params.set('range', 'last');
                    params.set('print', '1');
                    window.open(`party_ledger.php?${params.toString()}`, '_blank');
                });
            }

            document.querySelectorAll('.ledger-edit-form').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const ok = typeof nmConfirm === 'function'
                        ? await nmConfirm('Edit this challan ledger entry?')
                        : window.confirm('Edit this challan ledger entry?');
                    if (ok) {
                        form.submit();
                    }
                });
            });

            const challanEntryForm = document.getElementById('challan-entry-form');
            if (challanEntryForm && accountInput) {
                accountInput.dataset.selectedName = accountInput.value.trim() ? accountInput.value.trim().toLowerCase() : '';
                challanEntryForm.addEventListener('submit', (event) => {
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

            if (challanEntryForm && challanEntryForm.dataset.editMode === '1') {
                challanEntryForm.addEventListener('submit', async (event) => {
                    if (event.defaultPrevented) {
                        return;
                    }

                    if (challanEntryForm.dataset.confirmed === '1') {
                        return;
                    }

                    event.preventDefault();
                    const ok = typeof nmConfirm === 'function'
                        ? await nmConfirm('Update this challan ledger entry?')
                        : window.confirm('Update this challan ledger entry?');
                    if (ok) {
                        challanEntryForm.dataset.confirmed = '1';
                        challanEntryForm.submit();
                    }
                });
            }

            if (!isEditMode && !requestedTab) {
                const savedTarget = localStorage.getItem(tabStorageKey);
                if (savedTarget) {
                    const savedButton = document.querySelector(`#challanLedgerTabs [data-bs-target="${savedTarget}"]`);
                    if (savedButton && window.bootstrap) {
                        bootstrap.Tab.getOrCreateInstance(savedButton).show();
                    }
                }
            } else if (isEditMode) {
                localStorage.setItem(tabStorageKey, '#challan-create-pane');
            }
        })();
    </script>
</body>

</html>
