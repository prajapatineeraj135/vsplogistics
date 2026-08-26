<?php
include "../protect/auth.php";
include "../protect/db.php";
include "../protect/case_converter.php";

function voucherPrintMoney($value): string
{
    return number_format((int) round((float) $value));
}

function voucherPrintDate($date): string
{
    $time = strtotime((string) $date);
    return $time ? date('d-m-Y', $time) : '';
}

function voucherPrintText($value): string
{
    return capitalizeWords(trim((string) $value));
}

$companyId = (int) ($_SESSION['company_id'] ?? 0);
$voucherId = (int) ($_GET['id'] ?? 0);

if ($voucherId <= 0) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Invalid voucher ID</h3>";
    exit;
}

$company = null;
$stmtCompany = $conn->prepare("SELECT * FROM company WHERE id = ? LIMIT 1");
if ($stmtCompany) {
    $stmtCompany->bind_param('i', $companyId);
    $stmtCompany->execute();
    $company = $stmtCompany->get_result()->fetch_assoc();
    $stmtCompany->close();
}

$voucher = null;
$stmtVoucher = $conn->prepare("SELECT id, account_name, payment_date, challan_no, voucher_no, amount, transaction_type, mode, remarks
                               FROM ledger_payments
                               WHERE id = ? AND company_id = ? AND account_type = 'TBB' AND reference_no = 'voucher'
                               LIMIT 1");
if ($stmtVoucher) {
    $stmtVoucher->bind_param('ii', $voucherId, $companyId);
    $stmtVoucher->execute();
    $voucher = $stmtVoucher->get_result()->fetch_assoc();
    $stmtVoucher->close();
}

if (!$voucher) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Voucher not found</h3>";
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Voucher Print</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @page { size: A5 portrait; margin: 6mm; }
        * { text-transform: capitalize; box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: #111827;
            background: #f4f6f9;
            font-size: 12px;
        }
        .print-sheet {
            width: 148mm;
            min-height: 210mm;
            margin: 20px auto;
            background: #ffffff;
            padding: 5mm;
            display: flex;
            flex-direction: column;
        }
        .company-head {
            text-align: center;
            padding-bottom: 6px;
        }
        .company-name {
            font-size: 40px;
            line-height: 1;
            font-weight: 700;
        }
        .company-line {
            font-size: 10.5px;
            margin-top: 3px;
        }
        .section-divider {
            width: 100%;
            border-top: 2px solid #111827;
        }
        .voucher-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 5px;
            margin: 7px 0 6px;
            padding-left: 25px;
        }
        .voucher-meta-item {
            min-height: 36px;
            padding: 6px 8px;
        }
        .meta-label {
            display: block;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 1px;
        }
        .meta-value {
            font-size: 13px;
            font-weight: 700;
        }

        .table-body {
            padding-left: 25px;
            padding-right:25px;

        
        }
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            font-size: 12px;

        }
        .detail-table th,
        .detail-table td {
            border: 1px solid #111827;
            padding: 6px 7px;
            text-align: left;
            vertical-align: top;
        }
        .detail-table th {
            width: 32%;
            font-weight: 700;
            white-space: nowrap;
        }
        .party-value {
            font-size: 16px;
            font-weight: 800;
        }
        .amount-type-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .type-value {
            font-weight: 700;
        }
        .bank-section {
            margin-top: auto;
            padding-top: 10px;
        }
        .bank-title {
            padding: 5px 0;
            font-weight: 700;
        }
        .bank-bottom-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2px;
        }
        .bank-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .bank-item {
            font-size: 10px;
            line-height: 1.45;
        }
        .bank-item strong {
            display: inline-block;
            min-width: 50px;
        }
        .signature-box {
            width: 35mm;
            min-height: 20mm;
            margin-left: auto;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            font-weight: 700;
        }
        .signature-line {
            border-top: 1px solid #111827;
            padding-top: 6px;
            text-align: center;
        }
        @media print {
            body {
                background: #ffffff;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-sheet {
                width: 100%;
                min-height: calc(210mm - 12mm);
                margin: 0;
                padding: 0 ;
            }
        }
    </style>
</head>

<body>
    <div class="print-sheet">
        <div class="company-head">
            <div class="company-name"><?= htmlspecialchars(voucherPrintText($company['company_name'] ?? 'Transport Company')) ?></div>
            <div class="company-line">
                <?= htmlspecialchars(voucherPrintText(trim((string) (($company['address1'] ?? '') . ', ' . ($company['address2'] ?? '') . ', ' . ($company['city'] ?? '') . ', ' . ($company['state'] ?? '') . '-' . ($company['pincode'] ?? ''))))) ?>
            </div>
            <div class="company-line">
                Phone: <?= htmlspecialchars((string) ($company['phone1'] ?? '-')) ?><?= !empty($company['phone2']) ? ' / ' . htmlspecialchars((string) $company['phone2']) : '' ?>
                &nbsp; | &nbsp; Transporter Id: <?= htmlspecialchars((string) ($company['gst_no'] ?? '-')) ?>
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="voucher-meta">
            <div class="voucher-meta-item">
                <span class="meta-label">Voucher No</span>
                <span class="meta-value"><?= htmlspecialchars((string) ($voucher['voucher_no'] ?? $voucher['challan_no'] ?? '')) ?></span>
            </div>
            <div class="voucher-meta-item">
                <span class="meta-label">Date</span>
                <span class="meta-value"><?= htmlspecialchars(voucherPrintDate($voucher['payment_date'] ?? '')) ?></span>
            </div>
        </div>
        <div class="table-body">
        <table class="detail-table">
            <tbody>
                <tr><th>Party Name</th><td class="party-value"><?= htmlspecialchars(voucherPrintText($voucher['account_name'] ?? '')) ?></td></tr>
                <tr>
                    <th>Amount</th>
                    <td>
                        <span class="amount-type-row">
                            <span>&#8377; <?= voucherPrintMoney($voucher['amount'] ?? 0) ?></span>
                            <span class="type-value"><?= (($voucher['transaction_type'] ?? 'CR') === 'DR') ? 'Debit' : 'Credit' ?></span>
                        </span>
                    </td>
                </tr>
                <tr><th>Payment Mode</th><td><?= htmlspecialchars(voucherPrintText($voucher['mode'] ?? '')) ?></td></tr>
                <tr><th>Remark</th><td><?= htmlspecialchars(voucherPrintText($voucher['remarks'] ?? '')) ?></td></tr>
            </tbody>
        </table>
        </div>

        <div class="bank-section">
            <div class="section-divider"></div>
            <div class="bank-bottom-row">
                <div>
                    <div class="bank-title">Bank Details</div>
                    <div class="bank-grid">
                        <div class="bank-item"><strong>Bank:</strong> <?= htmlspecialchars(voucherPrintText($company['bank_name'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>A/C Name:</strong> <?= htmlspecialchars(voucherPrintText($company['bank_account_name'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>A/C No:</strong> <?= htmlspecialchars((string) ($company['bank_account_number'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>IFSC:</strong> <?= htmlspecialchars((string) ($company['bank_ifsc_code'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>UPI ID:</strong> <?= htmlspecialchars((string) ($company['upi_id'] ?? '-')) ?></div>
                        <div class="bank-item"><strong>Contact:</strong> <?= htmlspecialchars((string) ($company['phone1'] ?? ($company['owner_phone'] ?? '-'))) ?></div>
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">Seal / Signature</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.addEventListener('load', () => {
            window.print();
        });

        window.addEventListener('afterprint', () => {
            if (window.history.length === 1) {
                window.close();
                return;
            }
            window.history.back();
        });
    </script>
</body>

</html>
