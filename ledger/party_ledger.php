<?php
include "../protect/auth.php";
include "../protect/db.php";
include "../protect/case_converter.php";

function ensurePartyLedgerSchema(mysqli $conn): void
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
}

function partyLedgerMoney($value): string
{
    return number_format((int) round((float) $value));
}

function partyLedgerDate($date): string
{
    $time = strtotime((string) $date);
    return $time ? date('d-m-Y', $time) : '';
}

function partyLedgerShortDate($date): string
{
    $time = strtotime((string) $date);
    return $time ? date('d-F', $time) : '';
}

function partyLedgerSqlDate($date): string
{
    $time = strtotime((string) $date);
    return $time ? date('Y-m-d', $time) : '';
}

function partyLedgerBalanceText($value): string
{
    $amount = (float) $value;
    $type = $amount >= 0 ? 'Debit - लेना' : 'Credit - देना';
    return partyLedgerMoney(abs($amount)) . ' ' . $type;
}

function partyLedgerShortBalanceText($value): string
{
    $amount = (float) $value;
    if (abs($amount) < 0.005) {
        return partyLedgerMoney(0);
    }

    return partyLedgerMoney(abs($amount)) . ' ' . ($amount >= 0 ? 'Dr' : 'Cr');
}

function loadPartyLedgerStatement(mysqli $conn, string $party, string $fromDate, string $toDate, ?int $companyIdFilter): array
{
    $party = toLowercase($party);
    $partyInfo = [
        'account_name' => $party,
        'total_entries' => 0,
        'cr_amount' => 0,
        'dr_amount' => 0,
        'last_date' => ''
    ];

    $summarySql = "SELECT account_name,
                          COUNT(*) AS total_entries,
                          COALESCE(SUM(CASE WHEN transaction_type = 'CR' THEN amount ELSE 0 END), 0) AS cr_amount,
                          COALESCE(SUM(CASE WHEN transaction_type = 'DR' THEN amount ELSE 0 END), 0) AS dr_amount,
                          MAX(payment_date) AS last_date
                   FROM ledger_payments
                   WHERE account_type = 'TBB'
                     AND (mode = 'challan' OR reference_no = 'voucher')
                     AND LOWER(account_name) = ?";
    $summaryTypes = 's';
    $summaryParams = [$party];
    if ($companyIdFilter !== null) {
        $summarySql .= " AND company_id = ?";
        $summaryTypes .= 'i';
        $summaryParams[] = $companyIdFilter;
    }
    $summarySql .= " GROUP BY account_name LIMIT 1";
    $summaryStmt = $conn->prepare($summarySql);
    if ($summaryStmt) {
        $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
        $summaryStmt->execute();
        $summaryRow = $summaryStmt->get_result()->fetch_assoc();
        if ($summaryRow) {
            $partyInfo = $summaryRow;
        }
        $summaryStmt->close();
    }

    $openingBalance = 0.0;
    $openingSql = "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'DR' THEN amount ELSE -amount END), 0) AS opening_balance
                   FROM ledger_payments
                   WHERE account_type = 'TBB'
                     AND (mode = 'challan' OR reference_no = 'voucher')
                     AND LOWER(account_name) = ?
                     AND payment_date < ?";
    $openingTypes = 'ss';
    $openingParams = [$party, $fromDate];
    if ($companyIdFilter !== null) {
        $openingSql .= " AND company_id = ?";
        $openingTypes .= 'i';
        $openingParams[] = $companyIdFilter;
    }
    $openingStmt = $conn->prepare($openingSql);
    if ($openingStmt) {
        $openingStmt->bind_param($openingTypes, ...$openingParams);
        $openingStmt->execute();
        $openingRow = $openingStmt->get_result()->fetch_assoc();
        $openingBalance = (float) ($openingRow['opening_balance'] ?? 0);
        $openingStmt->close();
    }

    $rows = [];
    $rowSql = "SELECT id, account_name, payment_date, challan_no, voucher_no, amount, transaction_type, mode, reference_no, remarks
               FROM ledger_payments
               WHERE account_type = 'TBB'
                 AND (mode = 'challan' OR reference_no = 'voucher')
                 AND LOWER(account_name) = ?
                 AND payment_date >= ?
                 AND payment_date <= ?";
    $rowTypes = 'sss';
    $rowParams = [$party, $fromDate, $toDate];
    if ($companyIdFilter !== null) {
        $rowSql .= " AND company_id = ?";
        $rowTypes .= 'i';
        $rowParams[] = $companyIdFilter;
    }
    $rowSql .= " ORDER BY payment_date ASC, id ASC";
    $rowStmt = $conn->prepare($rowSql);
    if ($rowStmt) {
        $rowStmt->bind_param($rowTypes, ...$rowParams);
        $rowStmt->execute();
        $rowResult = $rowStmt->get_result();
        while ($row = $rowResult->fetch_assoc()) {
            $rows[] = $row;
        }
        $rowStmt->close();
    }

    $rangeCr = 0.0;
    $rangeDr = 0.0;
    foreach ($rows as $row) {
        if (($row['transaction_type'] ?? 'CR') === 'DR') {
            $rangeDr += (float) ($row['amount'] ?? 0);
        } else {
            $rangeCr += (float) ($row['amount'] ?? 0);
        }
    }

    $closingBalance = $openingBalance + ($rangeDr - $rangeCr);

    return [
        'party_info' => $partyInfo,
        'rows' => $rows,
        'opening_balance' => $openingBalance,
        'opening_cr' => $openingBalance < 0 ? abs($openingBalance) : 0,
        'opening_dr' => $openingBalance > 0 ? $openingBalance : 0,
        'range_cr' => $rangeCr,
        'range_dr' => $rangeDr,
        'closing_balance' => $closingBalance
    ];
}

ensurePartyLedgerSchema($conn);

$isCompanyUser = !empty($_SESSION['company_id']);
$companyIdFilter = $isCompanyUser ? (int) $_SESSION['company_id'] : null;
$accountName = toLowercase((string) ($_GET['party'] ?? ''));
$isPrintView = (($_GET['print'] ?? '') === '1');
$printParties = array_values(array_unique(array_filter(array_map(static function ($party) {
    return toLowercase((string) $party);
}, explode(',', (string) ($_GET['parties'] ?? ''))))));

if ($accountName === '' && empty($printParties)) {
    echo "<h4 style='text-align:center;margin-top:50px;'>Party name required</h4>";
    exit;
}

$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01', strtotime($today));
$currentMonthEnd = date('Y-m-t', strtotime($today));
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));
$range = trim((string) ($_GET['range'] ?? 'current'));
$fromDate = partyLedgerSqlDate($_GET['from_date'] ?? '');
$toDate = partyLedgerSqlDate($_GET['to_date'] ?? '');
$challanNoFilter = trim((string) ($_GET['challan_no'] ?? ''));

if ($range === 'last') {
    $fromDate = $lastMonthStart;
    $toDate = $lastMonthEnd;
} elseif ($range === 'current' && ($fromDate === '' || $toDate === '')) {
    $fromDate = $currentMonthStart;
    $toDate = $currentMonthEnd;
} elseif ($fromDate === '' || $toDate === '') {
    $fromDate = $currentMonthStart;
    $toDate = $currentMonthEnd;
}

if (strtotime($fromDate) > strtotime($toDate)) {
    $swapDate = $fromDate;
    $fromDate = $toDate;
    $toDate = $swapDate;
}

$partyInfo = [
    'name' => $accountName,
    'total_entries' => 0,
    'cr_amount' => 0,
    'dr_amount' => 0,
    'last_date' => ''
];
$summarySql = "SELECT account_name,
                      COUNT(*) AS total_entries,
                      COALESCE(SUM(CASE WHEN transaction_type = 'CR' THEN amount ELSE 0 END), 0) AS cr_amount,
                      COALESCE(SUM(CASE WHEN transaction_type = 'DR' THEN amount ELSE 0 END), 0) AS dr_amount,
                      MAX(payment_date) AS last_date
               FROM ledger_payments
               WHERE account_type = 'TBB'
                 AND (mode = 'challan' OR reference_no = 'voucher')
                 AND LOWER(account_name) = ?";
$summaryTypes = 's';
$summaryParams = [$accountName];
if ($companyIdFilter !== null) {
    $summarySql .= " AND company_id = ?";
    $summaryTypes .= 'i';
    $summaryParams[] = $companyIdFilter;
}
$summarySql .= " GROUP BY account_name LIMIT 1";
$summaryStmt = $conn->prepare($summarySql);
if ($summaryStmt) {
    $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
    $summaryStmt->execute();
    $summaryRow = $summaryStmt->get_result()->fetch_assoc();
    if ($summaryRow) {
        $partyInfo = $summaryRow;
    }
    $summaryStmt->close();
}

$openingBalance = 0.0;
$openingSql = "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'DR' THEN amount ELSE -amount END), 0) AS opening_balance
               FROM ledger_payments
               WHERE account_type = 'TBB'
                 AND (mode = 'challan' OR reference_no = 'voucher')
                 AND LOWER(account_name) = ?
                 AND payment_date < ?";
$openingTypes = 'ss';
$openingParams = [$accountName, $fromDate];
if ($companyIdFilter !== null) {
    $openingSql .= " AND company_id = ?";
    $openingTypes .= 'i';
    $openingParams[] = $companyIdFilter;
}
$openingStmt = $conn->prepare($openingSql);
if ($openingStmt) {
    $openingStmt->bind_param($openingTypes, ...$openingParams);
    $openingStmt->execute();
    $openingRow = $openingStmt->get_result()->fetch_assoc();
    $openingBalance = (float) ($openingRow['opening_balance'] ?? 0);
    $openingStmt->close();
}

$rows = [];
$rowSql = "SELECT id, account_name, payment_date, challan_no, voucher_no, amount, transaction_type, mode, reference_no, remarks
           FROM ledger_payments
           WHERE account_type = 'TBB'
             AND (mode = 'challan' OR reference_no = 'voucher')
             AND LOWER(account_name) = ?
             AND payment_date >= ?
             AND payment_date <= ?";
$rowTypes = 'sss';
$rowParams = [$accountName, $fromDate, $toDate];
if ($companyIdFilter !== null) {
    $rowSql .= " AND company_id = ?";
    $rowTypes .= 'i';
    $rowParams[] = $companyIdFilter;
}
if ($challanNoFilter !== '') {
    $rowSql .= " AND (challan_no LIKE ? OR voucher_no LIKE ?)";
    $challanLike = '%' . $challanNoFilter . '%';
    $rowTypes .= 'ss';
    $rowParams[] = $challanLike;
    $rowParams[] = $challanLike;
}
$rowSql .= " ORDER BY payment_date ASC, id ASC";
$rowStmt = $conn->prepare($rowSql);
if ($rowStmt) {
    $rowStmt->bind_param($rowTypes, ...$rowParams);
    $rowStmt->execute();
    $rowResult = $rowStmt->get_result();
    while ($row = $rowResult->fetch_assoc()) {
        $rows[] = $row;
    }
    $rowStmt->close();
}

$rangeCr = 0.0;
$rangeDr = 0.0;
foreach ($rows as $row) {
    if (($row['transaction_type'] ?? 'CR') === 'DR') {
        $rangeDr += (float) ($row['amount'] ?? 0);
    } else {
        $rangeCr += (float) ($row['amount'] ?? 0);
    }
}
$rangeBalance = $rangeDr - $rangeCr;
$closingBalance = $openingBalance + $rangeBalance;
$openingCr = $openingBalance < 0 ? abs($openingBalance) : 0;
$openingDr = $openingBalance > 0 ? $openingBalance : 0;
$totalBalance = (float) ($partyInfo['dr_amount'] ?? 0) - (float) ($partyInfo['cr_amount'] ?? 0);
$balanceSummaryClass = $totalBalance >= 0 ? 'summary-debit' : 'summary-credit';
$partyParam = urlencode($accountName);

if ($isPrintView) {
    $company = [];
    $companySql = "SELECT company_name, legal_name, gst_no, phone1, phone2, email, address1, address2, address3, branch
                   FROM company";
    $companyTypes = '';
    $companyParams = [];
    if ($companyIdFilter !== null) {
        $companySql .= " WHERE id = ?";
        $companyTypes = 'i';
        $companyParams[] = $companyIdFilter;
    }
    $companySql .= " ORDER BY id ASC LIMIT 1";
    $companyStmt = $conn->prepare($companySql);
    if ($companyStmt) {
        if ($companyTypes !== '') {
            $companyStmt->bind_param($companyTypes, ...$companyParams);
        }
        $companyStmt->execute();
        $company = $companyStmt->get_result()->fetch_assoc() ?: [];
        $companyStmt->close();
    }

    $companyAddress = trim(implode(', ', array_filter([
        $company['address1'] ?? '',
        $company['address2'] ?? '',
        $company['address3'] ?? '',
        $company['branch'] ?? ''
    ])));

    $statementParties = !empty($printParties) ? $printParties : [$accountName];
    $printStatements = [];
    foreach ($statementParties as $statementParty) {
        if ($statementParty !== '') {
            $printStatements[] = loadPartyLedgerStatement($conn, $statementParty, $fromDate, $toDate, $companyIdFilter);
        }
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Party Ledger Statement</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            * { box-sizing: border-box; }
            body { margin: 0 5px; font-family: Arial, sans-serif; color: #111; background: #fff; font-size: 12px; }
            .print-page { padding: 12px; }
            .company-head { text-align: center; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
            .company-name { font-size: 30px; font-weight: 600; text-transform: uppercase; line-height: 1.2; }
            .company-line { font-size: 10px; line-height: 1.35; }
            .statement-title { display: flex; justify-content: space-between; gap: 10px; align-items: center; margin: 8px 0; font-weight: 700; }
            .statement-section { break-before: page; page-break-before: always; }
            .statement-section:first-of-type { break-before: auto; page-break-before: auto; }
            .party-name { font-size: 18px; text-transform: capitalize; }
            table { width: 100%; border-collapse: collapse;}
            th, td { border: 1px solid #111; padding: 3px; vertical-align: middle; }
            th { background: #f1f1f1; font-weight: 700; }
            th:nth-child(-n+4),
            td:nth-child(-n+4) { text-align: left; }
            th:nth-child(n+5),
            td:nth-child(n+5) { text-align: right; }
            .text-end { text-align: right; }
            .text-center { text-align: center; }
            .fw-bold { font-weight: 700; }
            .summary { margin-top: 8px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
            .summary div { border: 1px solid #111; padding: 5px; display: flex; justify-content: space-between; gap: 6px; }
            .no-print { margin: 10px 12px; }
            .no-print button { padding: 5px 12px; cursor: pointer; }
            @media print {
                @page { size: A4; margin: 10mm; }
                .no-print { display: none; }
                .print-page { padding: 0; }
                thead { display: table-header-group; }
                tfoot { display: table-row-group; }
                tr { page-break-inside: avoid; }
            }
        </style> 
    </head>
    <body>
        <div class="no-print">
            <button type="button" onclick="window.print()">Print</button>
            <button type="button" onclick="window.close()">Close</button>
        </div>
        <div class="print-page">
            <?php foreach ($printStatements as $statement): ?>
                <?php
                $statementPartyInfo = $statement['party_info'];
                $statementRows = $statement['rows'];
                $statementOpeningBalance = (float) $statement['opening_balance'];
                ?>
                <section class="statement-section">
                    <div class="company-head">
                        <div class="company-name"><?= htmlspecialchars(capitalizeWords((string) ($company['company_name'] ?? 'Transport Company'))) ?></div>
                       
                        <?php if ($companyAddress !== ''): ?>
                            <div class="company-line"><?= htmlspecialchars(capitalizeWords($companyAddress)) ?></div>
                        <?php endif; ?>
                        <div class="company-line">
                            <?php if (($company['phone1'] ?? '') !== ''): ?>Phone: <?= htmlspecialchars((string) $company['phone1']) ?><?php endif; ?>
                            <?php if (($company['phone2'] ?? '') !== ''): ?> / <?= htmlspecialchars((string) $company['phone2']) ?><?php endif; ?>
                        </div>
                    </div>

                    <div class="statement-title">
                        <div class="party-name">Party: <?= htmlspecialchars(capitalizeWords((string) ($statementPartyInfo['account_name'] ?? ''))) ?></div>
                        <div>Statement: <?= htmlspecialchars(partyLedgerDate($fromDate)) ?> To <?= htmlspecialchars(partyLedgerDate($toDate)) ?></div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th width="40">Sr</th>
                                <th width="85">Date</th>
                                <th width="140">Challan No.</th>
                                <th>Remark</th>
                                <th width="90" class="text-end">Credit</th>
                                <th width="90" class="text-end">Debit</th>
                                <th width="95" class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td></td>
                                <td><?= htmlspecialchars(partyLedgerShortDate($fromDate)) ?></td>
                                <td></td>
                                <td class="fw-bold">Opening Balance</td>
                                <td class="text-end"><?= partyLedgerMoney($statement['opening_cr']) ?></td>
                                <td class="text-end"><?= partyLedgerMoney($statement['opening_dr']) ?></td>
                                <td class="text-end fw-bold"><?= partyLedgerShortBalanceText($statementOpeningBalance) ?></td>
                            </tr>
                            <?php if (empty($statementRows)): ?>
                                <tr><td colspan="7" class="text-center">No ledger entries found</td></tr>
                            <?php endif; ?>
                            <?php $printRunningBalance = $statementOpeningBalance; ?>
                            <?php foreach ($statementRows as $index => $row): ?>
                                <?php
                                $type = (string) ($row['transaction_type'] ?? 'CR');
                                $amount = (float) ($row['amount'] ?? 0);
                                $crAmount = $type === 'CR' ? $amount : 0;
                                $drAmount = $type === 'DR' ? $amount : 0;
                                $printRunningBalance += $drAmount - $crAmount;
                                $isVoucherEntry = (($row['reference_no'] ?? '') === 'voucher');
                                $entryNo = $isVoucherEntry
                                    ? ($row['voucher_no'] ?? $row['challan_no'] ?? '')
                                    : ($row['challan_no'] ?? '');
                                ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars(partyLedgerShortDate($row['payment_date'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) $entryNo) ?></td>
                                    <td><?= htmlspecialchars(capitalizeWords((string) ($row['remarks'] ?? ''))) ?></td>
                                    <td class="text-end"><?= partyLedgerMoney($crAmount) ?></td>
                                    <td class="text-end"><?= partyLedgerMoney($drAmount) ?></td>
                                    <td class="text-end"><?= partyLedgerShortBalanceText($printRunningBalance) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td></td>
                                <td><?= htmlspecialchars(partyLedgerShortDate($toDate)) ?></td>
                                <td></td>
                                <td class="fw-bold">Closing Balance</td>
                                <td class="text-end fw-bold "><?= partyLedgerMoney($statement['range_cr']) ?></td>
                                <td class="text-end fw-bold"><?= partyLedgerMoney($statement['range_dr']) ?></td>
                                <td class="text-end fw-bold"><?= partyLedgerShortBalanceText($statement['closing_balance']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            <?php endforeach; ?>
        </div>
        <script>
            const ledgerReturnUrl = <?= json_encode('challan.php?tab=party') ?>;
            let redirectedAfterPrint = false;

            function returnToLedger() {
                if (redirectedAfterPrint) {
                    return;
                }
                redirectedAfterPrint = true;
                window.location.href = ledgerReturnUrl;
            }

            window.addEventListener('afterprint', returnToLedger);
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Party Ledger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; background: #f4f6f9; }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, .08);
        }
        .btn { font-size: 12px; padding: 5px 10px; }
        .form-control { font-size: 14px; padding: 6px; }
        .account-title {
            font-size: 21px;
            font-weight: 800;
            color: #111827;
            text-align: center;
            white-space: nowrap;
            border-radius: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 8px;
        }
        .ledger-top-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
        }
        .account-filter {
            display: flex;
            gap: 5px;
            align-items: center;
            max-width: 60%;
            flex-wrap: nowrap;
            padding: 5px 6px;
        }
        .back-action { justify-self: end; }
        .filter-field {
            
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .account-filter label { font-size: 15px; font-weight: 800; margin: 0; color: #111827; }
        .account-filter .form-control { width: 130px; font-size: 14px; font-weight: 700; }
        #challan-no { width: 115px; }
        .account-filter .btn,
        .back-action { width: max-content; white-space: nowrap; }
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 25px;
            
        }
        .ledger-detail-head {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
        }
        .ledger-detail-head .date-range {
            font-weight: 600;
            justify-self: end;
        }
        .summary-box {
            background: #ecfdf3;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 3px 8px;
            min-height: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-width: 150px;
        }
        .summary-credit { background: #fee2e2; border-color: #fecaca; }
        .summary-debit { background: #dcfce7; border-color: #bbf7d0; }
        .summary-neutral { background: #f1f5f9; border-color: #cbd5e1; }
        .summary-box span { font-size: 12px; font-weight: 600; white-space: nowrap; line-height: 1; }
        .summary-box strong { font-size: 15px; line-height: 1; }
        .ledger-type-cr { background-color: #ef4444 !important; color: #ffffff !important; border: 1px solid #b91c1c; }
        .ledger-type-dr { background-color: #22c55e !important; color: #ffffff !important; border: 1px solid #15803d; }
        .entries-table th { white-space: nowrap; font-size: 14px; }
        .entries-table th,
        .entries-table td { text-align: left !important; }
        .entries-table .balance-col { text-align: right !important; }
        .entries-table td { font-size: 14px; vertical-align: middle; }
        .entries-table tbody tr:nth-child(odd) td { background: #ffffff; }
        .entries-table tbody tr:nth-child(even) td { background: #f1f5f9; }
        .party-name-cell { font-size: 14px; font-weight: 700; }
        @media (max-width: 992px) {
            .ledger-top-row { grid-template-columns: 1fr; }
            .account-filter { flex-wrap: wrap; }
            .back-action { justify-self: start; }
            .summary-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .ledger-detail-head { grid-template-columns: 1fr; }
            .ledger-detail-head .summary-row,
            .ledger-detail-head .date-range { justify-self: stretch; }
        }
        @media (max-width: 576px) {
            .account-filter,
            .summary-row { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <?php include "../content/nav.php"; ?>

    <div class="container-fluid my-3">
        <div class="card mb-3">
            <div class="card-body">
                <div class="ledger-top-row">
                    <form method="get" class="account-filter" autocomplete="off">
                        <input type="hidden" name="party" value="<?= htmlspecialchars($accountName) ?>">
                        <div class="filter-field">
                            <label for="from-date">From</label>
                            <input type="date" class="form-control form-control-sm" name="from_date" id="from-date" value="<?= htmlspecialchars($fromDate) ?>">
                        </div>
                        <div class="filter-field">
                            <label for="to-date">To</label>
                            <input type="date" class="form-control form-control-sm" name="to_date" id="to-date" value="<?= htmlspecialchars($toDate) ?>">
                        </div>
                        <div class="filter-field">
                            <label for="challan-no">Challan No</label>
                            <input type="text" class="form-control form-control-sm" name="challan_no" id="challan-no" value="<?= htmlspecialchars($challanNoFilter) ?>">
                        </div>
                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-search"></i> Filter</button>
                        <a href="party_ledger.php?party=<?= $partyParam ?>" class="btn btn-light btn-sm"><i class="bi bi-x-circle"></i> Reset</a>
                        <a href="party_ledger.php?party=<?= $partyParam ?>&range=last<?= $challanNoFilter !== '' ? '&challan_no=' . urlencode($challanNoFilter) : '' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar2-week"></i> Last Month</a>
                        <a href="party_ledger.php?party=<?= $partyParam ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?><?= $challanNoFilter !== '' ? '&challan_no=' . urlencode($challanNoFilter) : '' ?>&print=1" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Print</a>
                    </form>
                    <a href="challan.php" class="btn btn-success btn-sm back-action"><i class="bi bi-arrow-left"></i> Challan Ledger</a>
                </div>
                <div class="account-title"><?= htmlspecialchars(capitalizeWords((string) ($partyInfo['account_name'] ?? $accountName))) ?></div>

            </div>
        </div>

        <div class="card">
            <div class="card-body p-2">
                <div class="ledger-detail-head">
                    <h6 class="fw-bold mb-0"><i class="bi bi-journal-text"></i> Ledger Detail</h6>
                    <div class="summary-row">
                        <div class="summary-box summary-neutral"><span>Total Entries</span><strong><?= (int) ($partyInfo['total_entries'] ?? 0) ?></strong></div>
                        <div class="summary-box summary-credit"><span>Total Credit</span><strong><?= partyLedgerMoney($rangeCr) ?></strong></div>
                        <div class="summary-box summary-debit"><span>Total Debit</span><strong><?= partyLedgerMoney($rangeDr) ?></strong></div>
                        <div class="summary-box <?= $balanceSummaryClass ?>"><span>Balance</span><strong><?= partyLedgerBalanceText($totalBalance) ?></strong></div>
                    </div>
                    <div class="small text-muted date-range"><?= htmlspecialchars(partyLedgerDate($fromDate)) ?> To <?= htmlspecialchars(partyLedgerDate($toDate)) ?></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm entries-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="55">Sr</th>
                                <th>Date</th>
                                <th>Challan/Voucher No.</th>
                                <th>Remark</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Debit</th>
                                <th class="balance-col">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td></td>
                                <td><?= htmlspecialchars(partyLedgerDate($fromDate)) ?></td>
                                <td></td>
                                <td class="fw-bold">Opening Balance</td>
                                <td class="text-end"><?= partyLedgerMoney($openingCr) ?></td>
                                <td class="text-end"><?= partyLedgerMoney($openingDr) ?></td>
                                <td class="balance-col fw-bold"><?= partyLedgerMoney($openingBalance) ?></td>
                            </tr>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No ledger entries found</td>
                                </tr>
                            <?php endif; ?>
                            <?php $runningBalance = $openingBalance; ?>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php
                                $type = (string) ($row['transaction_type'] ?? 'CR');
                                $amount = (float) ($row['amount'] ?? 0);
                                $crAmount = $type === 'CR' ? $amount : 0;
                                $drAmount = $type === 'DR' ? $amount : 0;
                                $runningBalance += $drAmount - $crAmount;
                                $entryNo = (($row['reference_no'] ?? '') === 'voucher')
                                    ? ($row['voucher_no'] ?? $row['challan_no'] ?? '')
                                    : ($row['challan_no'] ?? '');
                                ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars(partyLedgerDate($row['payment_date'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) $entryNo) ?></td>
                                    <td><?= htmlspecialchars(capitalizeWords((string) ($row['remarks'] ?? ''))) ?></td>
                                    <td class="text-end"><?= partyLedgerMoney($crAmount) ?></td>
                                    <td class="text-end"><?= partyLedgerMoney($drAmount) ?></td>
                                    <td class="balance-col fw-bold"><?= partyLedgerMoney($runningBalance) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td></td>
                                <td><?= htmlspecialchars(partyLedgerDate($toDate)) ?></td>
                                <td></td>
                                <td class="fw-bold">Closing Balance</td>
                                <td class="text-end fw-bold"><?= partyLedgerMoney($rangeCr) ?></td>
                                <td class="text-end fw-bold"><?= partyLedgerMoney($rangeDr) ?></td>
                                <td class="balance-col fw-bold"><?= partyLedgerMoney($closingBalance) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
