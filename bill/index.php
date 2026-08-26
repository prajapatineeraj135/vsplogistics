<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../protect/db.php";
include "includes/bill_sync.php";

if (!isset($_SESSION['company_id']) && !isset($_SESSION['admin_login'])) {
    header("Location: ../login");
    exit;
}

ensureBillsSchema($conn);

function formatMonthLabel($monthKey)
{
    $value = trim((string) $monthKey);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value . '-01');
    if ($ts === false) {
        return $value;
    }

    return strtolower(date('F-Y', $ts));
}

function formatCompactNumber($value)
{
    $number = (float) $value;
    return (string) (int) round($number);
}

$isCompanyUser = isset($_SESSION['company_id']);
$isAdminLogin = isset($_SESSION['admin_login']);
$companyIdFilter = $isCompanyUser ? (int) $_SESSION['company_id'] : null;

/* =====================
    AUTO GENERATE MONTH-WISE
===================== */
$autoGenerateResult = generateLastMonthBills($conn, $companyIdFilter);
$defaultMonthKey = (string) ($autoGenerateResult['month_key'] ?? '');
$currentMonthKey = date('Y-m');

/* =====================
   UPDATE BILL
===================== */
if (isset($_POST['update_bill'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $billDate = trim($_POST['bill_date'] ?? '');
    $amount = (int) round((float) ($_POST['amount'] ?? 0));
    $status = trim($_POST['status'] ?? 'Pending');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($id > 0 && $billDate !== '') {
        if ($companyIdFilter !== null) {
            $stmt = $conn->prepare("UPDATE bills SET bill_date = ?, amount = ?, status = ?, remarks = ? WHERE id = ? AND company_id = ?");
            $stmt->bind_param("sdssii", $billDate, $amount, $status, $remarks, $id, $companyIdFilter);
        } else {
            $stmt = $conn->prepare("UPDATE bills SET bill_date = ?, amount = ?, status = ?, remarks = ? WHERE id = ?");
            $stmt->bind_param("sdssi", $billDate, $amount, $status, $remarks, $id);
        }
        $stmt->execute();
        $stmt->close();
    }

    header("Location: index.php?status=1");
    exit;
}

/* =====================
   RESYNC BILL
===================== */
if (isset($_POST['resync_bill'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($companyIdFilter !== null) {
            $stmt = $conn->prepare("SELECT id, company_id, party_id, party_name, bill_month, bill_type FROM bills WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->bind_param("ii", $id, $companyIdFilter);
        } else {
            $stmt = $conn->prepare("SELECT id, company_id, party_id, party_name, bill_month, bill_type FROM bills WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $id);
        }
        $stmt->execute();
        $billRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($billRow) && strtoupper((string) ($billRow['bill_type'] ?? '')) === 'AUTO_TBB') {
            $syncCompanyId = (int) ($billRow['company_id'] ?? 0);
            if ($syncCompanyId <= 0 && $companyIdFilter !== null) {
                $syncCompanyId = $companyIdFilter;
            }

            $syncMonth = trim((string) ($billRow['bill_month'] ?? ''));
            $syncPartyId = (int) ($billRow['party_id'] ?? 0);
            $syncPartyName = trim((string) ($billRow['party_name'] ?? ''));

            if ($syncCompanyId > 0 && $syncMonth !== '' && $syncPartyName !== '') {
                syncAutoBillForPartyMonth($conn, $syncCompanyId, $syncMonth, $syncPartyId, $syncPartyName);
            }
        }
    }

    header("Location: index.php?status=2");
    exit;
}

/* =====================
   COMPLETE GENERATED BILL
===================== */
if (isset($_POST['complete_generated_bill'])) {
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        if ($companyIdFilter !== null) {
            $billStmt = $conn->prepare("SELECT id, company_id, party_id, party_name, bill_month, bill_type FROM bills WHERE id = ? AND company_id = ? AND completed_at IS NULL LIMIT 1");
            $billStmt->bind_param("ii", $id, $companyIdFilter);
        } else {
            $billStmt = $conn->prepare("SELECT id, company_id, party_id, party_name, bill_month, bill_type FROM bills WHERE id = ? AND completed_at IS NULL LIMIT 1");
            $billStmt->bind_param("i", $id);
        }
        $billStmt->execute();
        $completeBillRow = $billStmt->get_result()->fetch_assoc();
        $billStmt->close();

        $completeTime = date('Y-m-d H:i:s');
        if (!empty($completeBillRow) && strtoupper((string) ($completeBillRow['bill_type'] ?? '')) === 'AUTO_TBB') {
            syncAutoBillForPartyMonth(
                $conn,
                (int) ($completeBillRow['company_id'] ?? 0),
                (string) ($completeBillRow['bill_month'] ?? ''),
                (int) ($completeBillRow['party_id'] ?? 0),
                (string) ($completeBillRow['party_name'] ?? ''),
                $completeTime
            );
        }

        $completeRemark = 'Mid month bill completed on ' . date('d-m-Y H:i', strtotime($completeTime));
        if ($companyIdFilter !== null) {
            $stmt = $conn->prepare("UPDATE bills SET status = 'Pending', remarks = ?, completed_at = ?, period_end = ?, bill_date = DATE(?), updated_at = NOW() WHERE id = ? AND company_id = ? AND bill_type = 'AUTO_TBB' AND completed_at IS NULL");
            $stmt->bind_param("ssssii", $completeRemark, $completeTime, $completeTime, $completeTime, $id, $companyIdFilter);
        } else {
            $stmt = $conn->prepare("UPDATE bills SET status = 'Pending', remarks = ?, completed_at = ?, period_end = ?, bill_date = DATE(?), updated_at = NOW() WHERE id = ? AND bill_type = 'AUTO_TBB' AND completed_at IS NULL");
            $stmt->bind_param("ssssi", $completeRemark, $completeTime, $completeTime, $completeTime, $id);
        }
        $stmt->execute();
        $stmt->close();
    }

    header("Location: index.php?status=5&view=list");
    exit;
}

/* =====================
   DELETE BILL
===================== */
if (isset($_POST['delete_bill'])) {
    if (!$isAdminLogin) {
        header("Location: index.php");
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($companyIdFilter !== null) {
            $stmt = $conn->prepare("DELETE FROM bills WHERE id = ? AND company_id = ?");
            $stmt->bind_param("ii", $id, $companyIdFilter);
        } else {
            $stmt = $conn->prepare("DELETE FROM bills WHERE id = ?");
            $stmt->bind_param("i", $id);
        }
        $stmt->execute();
        $stmt->close();
    }
    header("Location: index.php?status=4");
    exit;
}

/* =====================
   EDIT
===================== */
$edit = [];
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    if ($companyIdFilter !== null) {
        $stmt = $conn->prepare("SELECT * FROM bills WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->bind_param("ii", $id, $companyIdFilter);
    } else {
        $stmt = $conn->prepare("SELECT * FROM bills WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
    }
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

/* =====================
   AJAX LIST
===================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_bill') {
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = 10;

    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status_filter'] ?? '');
    $month = trim($_GET['month_filter'] ?? '');

    $where = "WHERE 1 ";
    $params = [];
    $types = "";

    if ($companyIdFilter !== null) {
        $where .= "AND company_id = ? ";
        $params[] = $companyIdFilter;
        $types .= "i";
    }

    // Hide only current-month generated bills that are still open; completed ones belong in Bill List.
    $where .= "AND NOT (bill_type = 'AUTO_TBB' AND bill_month = ? AND completed_at IS NULL) ";
    $params[] = $currentMonthKey;
    $types .= "s";

    if ($q !== '') {
        $where .= "AND (bill_number LIKE ? OR party_name LIKE ? OR remarks LIKE ?) ";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";
    }

    if ($status !== '') {
        $where .= "AND status = ? ";
        $params[] = $status;
        $types .= "s";
    }

    if ($month !== '') {
        $where .= "AND bill_month = ? ";
        $params[] = $month;
        $types .= "s";
    }

    $sql = "SELECT * FROM bills $where ORDER BY bill_number DESC, party_name ASC, id ASC LIMIT $limit OFFSET $offset";
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

$monthOptions = [];
if ($companyIdFilter !== null) {
    $stmtM = $conn->prepare("SELECT DISTINCT bill_month FROM bills WHERE company_id = ? AND bill_month IS NOT NULL AND bill_month <> '' AND NOT (bill_type = 'AUTO_TBB' AND bill_month = ? AND completed_at IS NULL) ORDER BY bill_month DESC");
    $stmtM->bind_param("is", $companyIdFilter, $currentMonthKey);
    $stmtM->execute();
    $resM = $stmtM->get_result();
} else {
    $stmtM = $conn->prepare("SELECT DISTINCT bill_month FROM bills WHERE bill_month IS NOT NULL AND bill_month <> '' AND NOT (bill_type = 'AUTO_TBB' AND bill_month = ? AND completed_at IS NULL) ORDER BY bill_month DESC");
    $stmtM->bind_param("s", $currentMonthKey);
    $stmtM->execute();
    $resM = $stmtM->get_result();
}
while ($row = $resM->fetch_assoc()) {
    $monthOptions[] = $row['bill_month'];
}
if (isset($stmtM) && $stmtM) {
    $stmtM->close();
}

$lastMonthGenerated = [];
if ($defaultMonthKey !== '') {
    if ($companyIdFilter !== null) {
        $stmtG = $conn->prepare("SELECT id, bill_number, party_name, amount, total_bilty, total_nag, status FROM bills WHERE company_id = ? AND bill_month = ? AND bill_type = 'AUTO_TBB' AND completed_at IS NULL ORDER BY party_name ASC LIMIT 50");
        $stmtG->bind_param("is", $companyIdFilter, $defaultMonthKey);
    } else {
        $stmtG = $conn->prepare("SELECT id, bill_number, party_name, amount, total_bilty, total_nag, status FROM bills WHERE bill_month = ? AND bill_type = 'AUTO_TBB' AND completed_at IS NULL ORDER BY party_name ASC LIMIT 50");
        $stmtG->bind_param("s", $defaultMonthKey);
    }
    $stmtG->execute();
    $resG = $stmtG->get_result();
    while ($row = $resG->fetch_assoc()) {
        $lastMonthGenerated[] = $row;
    }
    $stmtG->close();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bill Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *{
            text-transform: capitalize;
        }
        body { background: #f4f6f9; }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0,0,0,.08);
        }
        .bill-tabs .nav-link {
            font-weight: 600;
            color: #166534;
        }
        .bill-tabs .nav-link.active {
            background: #22c55e;
            color: #fff;
            border-color: #22c55e #22c55e #22c55e;
        }
        .bill-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .bill-refresh-btn {
            font-size: 12px;
            padding: 5px 10px;
            border-color: #22c55e;
            background: #22c55e;
            color: #ffffff;
            font-weight: 600;
            white-space: nowrap;
        }
        .bill-refresh-btn:hover {
            background: #15803d;
            border-color: #15803d;
            color: #ffffff;
        }
        .edit-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 12px;
        }
        @media (max-width: 767.98px) {
            .container-fluid { padding-left: 8px; padding-right: 8px; }
            .bill-tabs .nav-item { flex: 1 1 50%; }
            .bill-tabs .nav-link { width: 100%; text-align: center; }
            .bill-refresh-btn { width: 100%; }
        }
    </style>
</head>
<body>
    <?php include "../content/nav.php"; ?>

    <?php if (isset($_GET['status'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            <?php $s = $_GET['status']; ?>
            <?php if ($s === '1'): ?>showUpdate('Bill updated successfully');
            <?php elseif ($s === '2'): ?>showUpdate('Bill re-sync completed');
            <?php elseif ($s === '4'): ?>showDelete('Bill deleted successfully');
            <?php elseif ($s === '5'): ?>showSave('Generated bill completed');
            <?php endif; ?>
            if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('status'); window.history.replaceState({}, document.title, u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash); }
        });</script>
    <?php endif; ?>
    <?php if (isset($_GET['refresh'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            showUpdate('Bill refreshed and up to date');
            if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('refresh'); window.history.replaceState({}, document.title, u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash); }
        });</script>
    <?php endif; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 bill-tabs" id="billMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="bill-generate-tab" data-bs-toggle="tab" data-bs-target="#bill-generate-pane" type="button" role="tab" aria-controls="bill-generate-pane" aria-selected="true">
                    <i class="bi bi-gear"></i> <?= !empty($edit['id']) ? 'Update Bill' : 'Generate' ?>
                </button>
            </li>
            <?php if (empty($edit['id'])): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="bill-list-tab" data-bs-toggle="tab" data-bs-target="#bill-list-pane" type="button" role="tab" aria-controls="bill-list-pane" aria-selected="false">
                        <i class="bi bi-list"></i> Bill List
                    </button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content" id="billMainTabsContent">
            <div class="tab-pane fade show active" id="bill-generate-pane" role="tabpanel" aria-labelledby="bill-generate-tab">
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($edit['id'])): ?>
                            <div class="card">
                                <div class="card-body p-2">
                                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                                        <h6 class="fw-bold mb-0"><i class="bi bi-list-check"></i> Current Month Generated Bill List</h6>
                                        <a href="index.php?refresh=1" class="btn btn-success btn-sm bill-refresh-btn">
                                            <i class="bi bi-arrow-clockwise"></i> Refresh
                                        </a>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Bill No</th>
                                                    <th>Party</th>
                                                    <th>Total Bilty</th>
                                                    <th>Total NAG</th>
                                                    <th>Total Freight</th>
                                                    <th>Status</th>
                                                    <th width="170">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($lastMonthGenerated)):
                                                    foreach ($lastMonthGenerated as $gRow):
                                                ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($gRow['bill_number']) ?></td>
                                                            <td><?= htmlspecialchars($gRow['party_name']) ?></td>
                                                            <td><?= (int) ($gRow['total_bilty'] ?? 0) ?></td>
                                                            <td><?= htmlspecialchars(formatCompactNumber($gRow['total_nag'] ?? 0)) ?></td>
                                                            <td>&#8377; <?= htmlspecialchars(formatCompactNumber($gRow['amount'] ?? 0)) ?></td>
                                                            <td><?= htmlspecialchars($gRow['status']) ?></td>
                                                            <td>
                                                                <div class="bill-actions">
                                                                    <a href="view.php?bill_id=<?= (int) $gRow['id'] ?>" class="btn btn-info btn-sm text-white" title="See">
                                                                        <i class="bi bi-eye"></i>
                                                                    </a>
                                                                    <form method="post" data-confirm="Complete this generated bill?">
                                                                        <input type="hidden" name="id" value="<?= (int) $gRow['id'] ?>">
                                                                        <input type="hidden" name="complete_generated_bill" value="1">
                                                                        <button type="submit" name="complete_generated_bill" class="btn btn-primary btn-sm">
                                                                            <i class="bi bi-check2-circle"></i> Complete
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr><td colspan="7">No generated bills found for current month.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($edit['id'])): ?>
                            <div class="edit-box">
                                <h6 class="fw-bold mb-2"><i class="bi bi-pencil-square"></i> Update Bill</h6>
                                <form method="post" class="row g-2 align-items-end">
                                    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                                    <div class="col-md-2">
                                        <label class="form-label mb-1">Bill No</label>
                                        <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['bill_number']) ?>" readonly>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label mb-1">Bill Date</label>
                                        <input type="date" name="bill_date" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['bill_date']) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1">Party</label>
                                        <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['party_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label mb-1">Amount</label>
                                        <input type="number" step="1" name="amount" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['amount']) ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label mb-1">Status</label>
                                        <?php $st = $edit['status'] ?? 'Pending'; ?>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="Pending" <?= $st === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="Paid" <?= $st === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                            <option value="Cancelled" <?= $st === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label mb-1">Remarks</label>
                                        <input type="text" name="remarks" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['remarks'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4 d-flex gap-2">
                                        <button type="submit" name="update_bill" class="btn btn-warning btn-sm w-100"><i class="bi bi-save"></i> Update Bill</button>
                                        <a href="index.php" class="btn btn-secondary btn-sm">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="bill-list-pane" role="tabpanel" aria-labelledby="bill-list-tab">
                <div class="card">
                    <div class="card-body p-2">
                        <div class="row g-2 mb-2">
                            <div class="col-md-5">
                                <input type="text" id="search_q" class="form-control form-control-sm" placeholder="Search bill number, party, remarks..." onkeyup="autoSearchBill()" autocomplete="off">
                            </div>
                            <div class="col-md-3">
                                <select id="search_status" class="form-select form-select-sm" onchange="resetAndLoad()">
                                    <option value="">All Status</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Paid">Paid</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select id="search_month" class="form-select form-select-sm" onchange="resetAndLoad()">
                                    <option value="">All Months</option>
                                    <?php foreach ($monthOptions as $m): ?>
                                        <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars(formatMonthLabel($m)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="50">Sr</th>
                                        <th>Bill No</th>
                                        <th>Month</th>
                                        <th>Date</th>
                                        <th>Party</th>
                                        <th>Bilty</th>
                                        <th>Nag</th>
                                        <th>Freight</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                        <th width="180">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="billTable"></tbody>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let offset = 0;
        let typingTimer;
        const isAdminLogin = <?= $isAdminLogin ? 'true' : 'false' ?>;

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

        function escHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function formatDateDisplay(value) {
            const text = String(value ?? '').trim();
            if (!text) return '';

            const datePart = text.split(' ')[0];
            const parts = datePart.split('-');
            if (parts.length === 3) {
                return `${parts[2]}-${parts[1]}-${parts[0]}`;
            }

            return text;
        }

        function formatMonthDisplay(value) {
            const text = String(value ?? '').trim();
            if (!text) return '';

            const match = text.match(/^(\d{4})-(\d{2})$/);
            if (!match) return text;

            const year = match[1];
            const month = match[2];
            const dateObj = new Date(`${year}-${month}-01T00:00:00`);

            if (Number.isNaN(dateObj.getTime())) return text;

            const shortMonth = dateObj.toLocaleString('en-US', { month: 'short' }).toLowerCase();
            return `${shortMonth}-${year}`;
        }

        function formatCompactNumber(value) {
            const number = Number(value ?? 0);
            if (!Number.isFinite(number)) return '0';
            return Number.isInteger(number) ? String(number) : String(number).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
        }

        function resetAndLoad() {
            offset = 0;
            document.getElementById('billTable').innerHTML = '';
            document.getElementById('loadMoreBtn').disabled = false;
            document.getElementById('loadMoreBtn').innerText = 'Load More';
            loadMoreBill();
        }

        function autoSearchBill() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(resetAndLoad, 350);
        }

        function loadMoreBill() {
            const q = document.getElementById('search_q').value.trim();
            const statusFilter = document.getElementById('search_status').value;
            const monthFilter = document.getElementById('search_month').value;

            fetch(`?ajax=load_bill&offset=${offset}&q=${encodeURIComponent(q)}&status_filter=${encodeURIComponent(statusFilter)}&month_filter=${encodeURIComponent(monthFilter)}`)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('billTable');

                    if (data.length === 0 && offset === 0) {
                        tbody.innerHTML = `<tr><td colspan="11" class="text-center">No Data Found</td></tr>`;
                        document.getElementById('loadMoreBtn').disabled = true;
                        return;
                    }

                    if (data.length === 0) {
                        document.getElementById('loadMoreBtn').innerText = 'No More Data';
                        document.getElementById('loadMoreBtn').disabled = true;
                        return;
                    }

                    let sn = tbody.rows.length + 1;
                    data.forEach(row => {
                        const totalBilty = parseInt(row.total_bilty ?? 0, 10) || 0;
                        const nagDisplay = formatCompactNumber(row.total_nag ?? 0);
                        const amountDisplay = formatCompactNumber(row.amount ?? 0);

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${sn++}</td>
                            <td>${escHtml(row.bill_number)}</td>
                            <td>${escHtml(formatMonthDisplay(row.bill_month))}</td>
                            <td>${escHtml(formatDateDisplay(row.bill_date))}</td>
                            <td>${escHtml(row.party_name)}</td>
                            <td>${totalBilty}</td>
                            <td>${nagDisplay}</td>
                            <td>&#8377; ${escHtml(amountDisplay)}</td>
                            <td>${escHtml(row.status)}</td>
                            <td>${escHtml(row.remarks)}</td>
                            <td>
                                <div class="bill-actions">
                                    <a href="view.php?bill_id=${row.id}" class="btn btn-info btn-sm text-white" title="See">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="print.php?bill_id=${row.id}" class="btn btn-success btn-sm" title="Print">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <a href="?edit=${row.id}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </div>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });

                    offset += 10;
                });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const view = new URLSearchParams(window.location.search).get('view');
            const generateTabButton = document.getElementById('bill-generate-tab');
            const listTabButton = document.getElementById('bill-list-tab');

            if (view === 'list' && listTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(listTabButton).show();
            } else if (view === 'generate' && generateTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(generateTabButton).show();
            }

            const tabButtons = document.querySelectorAll('#billMainTabs [data-bs-toggle="tab"]');
            tabButtons.forEach(function (tabButton) {
                tabButton.addEventListener('shown.bs.tab', function () {
                    clearViewParamFromUrl();
                });
            });

            loadMoreBill();
            document.getElementById('loadMoreBtn').addEventListener('click', loadMoreBill);
        });
    </script>
</body>
</html>
