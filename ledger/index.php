<?php
include "../protect/auth.php";
include "../bill/includes/bill_sync.php";
include "../protect/case_converter.php";

ensureBillsSchema($conn);

function ensureLedgerPaymentsSchema(mysqli $conn): void
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

function ledgerMoney($value): string
{
    return number_format((int) round((float) $value));
}

function ledgerDate($date): string
{
    $time = strtotime((string) $date);
    return $time ? date('d-m-Y', $time) : '';
}

ensureLedgerPaymentsSchema($conn);

$isCompanyUser = !empty($_SESSION['company_id']);
$companyIdFilter = $isCompanyUser ? (int) $_SESSION['company_id'] : null;

generateLastMonthBills($conn, $companyIdFilter);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tbb_payment'])) {
    $postedCompanyId = (int) ($_POST['company_id'] ?? 0);
    $paymentCompanyId = $companyIdFilter !== null ? $companyIdFilter : ($postedCompanyId > 0 ? $postedCompanyId : null);
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $accountName = toLowercase((string) ($_POST['account_name'] ?? ''));
    $paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $mode = toLowercase((string) ($_POST['mode'] ?? ''));
    $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($accountName === '' || $amount <= 0 || strtotime($paymentDate) === false) {
        $messageType = 'danger';
        $message = 'Please enter party, date, and valid payment amount.';
    } else {
        $sql = "INSERT INTO ledger_payments (company_id, account_type, account_id, account_name, payment_date, amount, mode, reference_no, remarks)
                VALUES (?, 'TBB', ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iissdsss', $paymentCompanyId, $accountId, $accountName, $paymentDate, $amount, $mode, $referenceNo, $remarks);

        if ($stmt->execute()) {
            $message = 'TBB payment saved successfully.';
        } else {
            $messageType = 'danger';
            $message = 'Unable to save payment.';
        }
        $stmt->close();
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$where = "WHERE bill_type = 'AUTO_TBB' ";
$params = [];
$types = '';

if ($companyIdFilter !== null) {
    $where .= "AND company_id = ? ";
    $params[] = $companyIdFilter;
    $types .= 'i';
}

if ($search !== '') {
    $where .= "AND (party_name LIKE ? OR bill_number LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

$ledgerRows = [];
$sql = "SELECT company_id, COALESCE(party_id, 0) AS party_id, party_name,
               COUNT(*) AS total_bills,
               COALESCE(SUM(amount), 0) AS bill_amount,
               COALESCE(SUM(total_bilty), 0) AS total_bilty,
               COALESCE(SUM(total_nag), 0) AS total_nag,
               MAX(bill_date) AS last_bill_date
        FROM bills
        {$where}
        GROUP BY company_id, COALESCE(party_id, 0), party_name
        ORDER BY party_name ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ledgerRows[] = $row;
    }
    $stmt->close();
}

$paymentTotals = [];
$paymentRows = [];
$paymentWhere = "WHERE account_type = 'TBB' ";
$paymentParams = [];
$paymentTypes = '';
if ($companyIdFilter !== null) {
    $paymentWhere .= "AND company_id = ? ";
    $paymentParams[] = $companyIdFilter;
    $paymentTypes .= 'i';
}

$paymentSql = "SELECT company_id, COALESCE(account_id, 0) AS account_id, account_name,
                      COALESCE(SUM(CASE WHEN transaction_type = 'DR' THEN -amount ELSE amount END), 0) AS paid_amount,
                      MAX(payment_date) AS last_payment_date
               FROM ledger_payments
               {$paymentWhere}
               GROUP BY company_id, COALESCE(account_id, 0), account_name";
$paymentStmt = $conn->prepare($paymentSql);
if ($paymentStmt) {
    if ($paymentTypes !== '') {
        $paymentStmt->bind_param($paymentTypes, ...$paymentParams);
    }
    $paymentStmt->execute();
    $paymentResult = $paymentStmt->get_result();
    while ($row = $paymentResult->fetch_assoc()) {
        $key = (int) ($row['company_id'] ?? 0) . '|' . (int) ($row['account_id'] ?? 0) . '|' . strtolower(trim((string) ($row['account_name'] ?? '')));
        $paymentTotals[$key] = (float) ($row['paid_amount'] ?? 0);
        $paymentRows[$key] = $row;
    }
    $paymentStmt->close();
}

$ledgerRowKeys = [];
foreach ($ledgerRows as $row) {
    $ledgerRowKeys[(int) ($row['company_id'] ?? 0) . '|' . (int) ($row['party_id'] ?? 0) . '|' . strtolower(trim((string) ($row['party_name'] ?? '')))] = true;
}

foreach ($paymentRows as $key => $row) {
    if (isset($ledgerRowKeys[$key])) {
        continue;
    }

    $ledgerRows[] = [
        'company_id' => (int) ($row['company_id'] ?? 0),
        'party_id' => (int) ($row['account_id'] ?? 0),
        'party_name' => (string) ($row['account_name'] ?? ''),
        'total_bills' => 0,
        'bill_amount' => 0,
        'total_bilty' => 0,
        'total_nag' => 0,
        'last_bill_date' => (string) ($row['last_payment_date'] ?? '')
    ];
}

usort($ledgerRows, static function ($a, $b) {
    return strcasecmp((string) ($a['party_name'] ?? ''), (string) ($b['party_name'] ?? ''));
});

$summaryBills = 0;
$summaryAmount = 0.0;
$summaryPaid = 0.0;
$summaryBalance = 0.0;

foreach ($ledgerRows as &$row) {
    $key = (int) ($row['company_id'] ?? 0) . '|' . (int) ($row['party_id'] ?? 0) . '|' . strtolower(trim((string) ($row['party_name'] ?? '')));
    $paid = $paymentTotals[$key] ?? 0.0;
    $amount = (float) ($row['bill_amount'] ?? 0);
    $row['paid_amount'] = $paid;
    $row['balance_amount'] = $amount - $paid;

    $summaryBills += (int) ($row['total_bills'] ?? 0);
    $summaryAmount += $amount;
    $summaryPaid += $paid;
    $summaryBalance += (float) $row['balance_amount'];
}
unset($row);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Ledger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .ledger-wrap { max-width: 1540px; margin: 16px auto; padding: 0 12px 24px; }
        .ledger-title { background: #ecfdf3; color: #14532d; border: 1px solid #bbf7d0; border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; }
        .ledger-title h2 { margin: 0; font-size: 1rem; font-weight: 700; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
        .summary-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
        .summary-box span { display: block; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .summary-box strong { color: #0f172a; font-size: 18px; }
        .ledger-card { border: 0; border-radius: 10px; box-shadow: 0 6px 15px rgba(0,0,0,.08); }
        .ledger-card .card-header { background: #ecfdf3; color: #14532d; border-bottom: 1px solid #bbf7d0; border-radius: 10px 10px 0 0; padding: 9px 12px; }
        .ledger-table th { white-space: nowrap; font-size: 13px; }
        .ledger-table td { font-size: 13px; vertical-align: middle; }
        @media (max-width: 992px) {
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 576px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <?php include "../content/nav.php"; ?>

    <div class="ledger-wrap">
        <div class="ledger-title">
            <h2><i class="bi bi-receipt me-2"></i>TBB Ledger</h2>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?> py-2">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="summary-grid">
            <div class="summary-box"><span>Bills</span><strong><?= (int) $summaryBills ?></strong></div>
            <div class="summary-box"><span>Bill Amount</span><strong>&#8377; <?= ledgerMoney($summaryAmount) ?></strong></div>
            <div class="summary-box"><span>Received</span><strong>&#8377; <?= ledgerMoney($summaryPaid) ?></strong></div>
            <div class="summary-box"><span>Balance</span><strong>&#8377; <?= ledgerMoney($summaryBalance) ?></strong></div>
        </div>

        <div class="card ledger-card">
            <div class="card-header">
                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <h6 class="mb-0"><i class="bi bi-journal-text"></i> Party Wise TBB</h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="challan.php" class="btn btn-success btn-sm">
                            <i class="bi bi-plus-circle"></i> Add Challan
                        </a>
                        <form method="get" class="d-flex gap-2">
                            <input type="text" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($search) ?>" placeholder="Search party or bill">
                            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-search"></i></button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm ledger-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="55">Sr</th>
                                <th>Party</th>
                                <th class="text-end">Bills</th>
                                <th class="text-end">Bilty</th>
                                <th class="text-end">Nag</th>
                                <th>Last Bill</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Received</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ledgerRows)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-3">No TBB ledger found</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($ledgerRows as $index => $row): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars(capitalizeWords((string) ($row['party_name'] ?? ''))) ?></td>
                                    <td class="text-end"><?= (int) ($row['total_bills'] ?? 0) ?></td>
                                    <td class="text-end"><?= (int) ($row['total_bilty'] ?? 0) ?></td>
                                    <td class="text-end"><?= ledgerMoney($row['total_nag'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars(ledgerDate($row['last_bill_date'] ?? '')) ?></td>
                                    <td class="text-end">&#8377; <?= ledgerMoney($row['bill_amount'] ?? 0) ?></td>
                                    <td class="text-end text-success">&#8377; <?= ledgerMoney($row['paid_amount'] ?? 0) ?></td>
                                    <td class="text-end fw-bold <?= ((float) ($row['balance_amount'] ?? 0) > 0) ? 'text-danger' : 'text-success' ?>">
                                        &#8377; <?= ledgerMoney($row['balance_amount'] ?? 0) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
