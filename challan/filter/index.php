<?php
include "../../protect/auth.php";
include "../../protect/case_converter.php";

if (!empty($_SESSION['company_id'])) {
    $company_id = (string) $_SESSION['company_id'];
} elseif (!empty($_SESSION['admin_login'])) {
    $company_id = (string) ($_SESSION['company_id'] ?? '');
    if ($company_id === '') {
        $companyResult = $conn->query("SELECT id FROM company ORDER BY id ASC LIMIT 1");
        if ($companyResult && ($companyRow = $companyResult->fetch_assoc())) {
            $company_id = (string) ($companyRow['id'] ?? '');
            $_SESSION['company_id'] = $company_id;
        }
    }
} else {
    $company_id = '';
}

if ($company_id === '') {
    echo "<h3 style='text-align:center;margin-top:40px;'>Company session not found</h3>";
    exit;
}

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_id']) || isset($_POST['delete_ids']))) {
    $rawDeleteIds = [];
    if (isset($_POST['delete_ids'])) {
        $rawDeleteIds = is_array($_POST['delete_ids'])
            ? $_POST['delete_ids']
            : explode(',', (string) $_POST['delete_ids']);
    } else {
        $rawDeleteIds = [$_POST['delete_id']];
    }

    $deleteIds = [];
    foreach ($rawDeleteIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $deleteIds[$id] = $id;
        }
    }
    $deleteIds = array_values($deleteIds);

    if (!empty($deleteIds)) {
        $conn->begin_transaction();
        try {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));

            $resetStmt = $conn->prepare("UPDATE biltys SET challan_id = NULL, status = 'Booked' WHERE company_id = ? AND challan_id IN ($placeholders) AND LOWER(status) IN ('dispatch', 'dispatched')");
            if (!$resetStmt) {
                throw new Exception('Failed to prepare linked bilty reset query');
            }
            $resetTypes = 's' . str_repeat('i', count($deleteIds));
            $resetParams = array_merge([$company_id], $deleteIds);
            $resetStmt->bind_param($resetTypes, ...$resetParams);
            if (!$resetStmt->execute()) {
                throw new Exception('Failed to reset linked biltys');
            }
            $resetStmt->close();

            $deleteStmt = $conn->prepare("DELETE FROM challans WHERE company_id = ? AND id IN ($placeholders)");
            if (!$deleteStmt) {
                throw new Exception('Failed to prepare challan delete query');
            }
            $deleteTypes = 's' . str_repeat('i', count($deleteIds));
            $deleteParams = array_merge([$company_id], $deleteIds);
            $deleteStmt->bind_param($deleteTypes, ...$deleteParams);
            if (!$deleteStmt->execute()) {
                throw new Exception('Failed to delete challan');
            }

            if ($deleteStmt->affected_rows <= 0) {
                throw new Exception('Challan not found or already deleted');
            }

            $deleteStmt->close();
            $conn->commit();
            $flashType = 'deleted';
            $flash = count($deleteIds) > 1 ? 'Selected challans deleted successfully' : 'Challan deleted successfully';
        } catch (Throwable $exception) {
            $conn->rollback();
            $flashType = 'danger';
            $flash = $exception->getMessage();
        }
    } else {
        $flashType = 'danger';
        $flash = 'Invalid challan ID';
    }

    $_SESSION['flash_message'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

if (isset($_SESSION['flash_message'])) {
    $flash = (string) ($_SESSION['flash_message'] ?? '');
    $flashType = (string) ($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$challanNo = trim($_GET['challan_no'] ?? '');
$station = trim($_GET['station'] ?? '');
$vehicle = trim($_GET['vehicle_no'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if ($dateFrom === '') {
    $dateFrom = date('Y-m-d', strtotime('-1 day'));
}

if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}

$sql = "
    SELECT
        c.id,
        c.challan_no,
        c.challan_date,
        c.challan_station,
        c.vehicle_no,
        c.driver_name,
        c.driver_contact,
        COALESCE(c.paid_total, 0) AS paid_total,
        COALESCE(c.freight_total, 0) AS freight_total,
        COALESCE(c.recovery_total, 0) AS recovery_total,
        COALESCE(c.cutting_total, 0) AS cutting_total,
        COALESCE(c.commission_total, 0) AS commission_total,
        COALESCE(c.final_total, 0) AS final_total
    FROM challans c
    WHERE c.company_id = ?
";

$types = 's';
$params = [$company_id];

if ($challanNo !== '') {
    $sql .= " AND c.challan_no LIKE ?";
    $types .= 's';
    $params[] = '%' . $challanNo . '%';
}

if ($station !== '') {
    $sql .= " AND c.challan_station LIKE ?";
    $types .= 's';
    $params[] = '%' . $station . '%';
}

if ($vehicle !== '') {
    $sql .= " AND c.vehicle_no LIKE ?";
    $types .= 's';
    $params[] = '%' . $vehicle . '%';
}

if ($dateFrom !== '') {
    $sql .= " AND DATE(c.challan_date) >= ?";
    $types .= 's';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $sql .= " AND DATE(c.challan_date) <= ?";
    $types .= 's';
    $params[] = $dateTo;
}

$sql .= " ORDER BY c.challan_date DESC, c.id DESC LIMIT 500";

$stmt = $conn->prepare($sql);
$rows = [];

if ($stmt) {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
}

$todayDate = date('Y-m-d');

function formatDisplayDate($value)
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $timestamp = strtotime($text);
    if ($timestamp === false) {
        return $text;
    }

    return date('d-M', $timestamp);
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Challan Filter</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .action-btn {
            min-width: 50px;
        }

        .action-btn:hover {
            background-color: black;
            color: white;
        }

        .table td,
        .table th {
            white-space: nowrap;
            vertical-align: middle;
        }

        .filter-card {
            border: 0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.08);
        }

        .filter-card .card-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            border-bottom: 0;
            padding: 4px 6px;
            text-align: center;
            font-size: 16px;
        }

        .filter-body {
            padding: 8px;
            background: #fff;
        }

        .filter-label {
            font-size: 13px;
            font-weight: 600;
            color: #000;
            margin-bottom: 2px;
        }

        .filter-control {
            font-size: 12px;
            min-height: 32px;
            border-radius: 8px;
            border-color: #d1d5db;
            padding: 6px 8px;
        }

        .filter-actions .btn {
            font-size: 12px;
            font-weight: 600;
            border-radius: 8px;
            padding: 4px 8px;
        }

        .today-challan {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 6px;
            background: #bbf7d0;
            border: 1px solid #22c55e;
            color: #14532d;
        }

        .challan-notify-wrap {
            position: fixed;
            top: 14px;
            right: 14px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: 400px;
        }

        .challan-notify {
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 18px;
            line-height: 1.35;
            border: 1px solid transparent;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.14);
            background: #fff;
            opacity: 0;
            transform: translateY(-4px);
            transition: opacity 180ms ease, transform 180ms ease;
        }

        .challan-notify.show {
            opacity: 1;
            transform: translateY(0);
        }

        .challan-notify.success {
            border-color: #000000;
            color: #000000;
            background: #80ffb5;
        }

        .challan-notify.warning {
            border-color: #000000;
            color: #000000;
            background: #ffea82;
        }

        .challan-notify.danger {
            border-color: #000000;
            color: #000000;
            background: #ff7b8a;
        }
    </style>
</head>

<body>
    <?php include "../../content/nav.php"; ?>

    <?php if ($flash !== ''): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            <?php if ($flashType === 'deleted'): ?>showDelete(<?= json_encode($flash) ?>);
            <?php elseif ($flashType === 'warning'): ?>showWarning(<?= json_encode($flash) ?>);
            <?php else: ?>showError(<?= json_encode($flash) ?>);
            <?php endif; ?>
        });</script>
    <?php endif; ?>

    <div class="container-fluid my-1">
        <div class="row g-2">
            <div class="col-lg-2 col-md-3">
                <div class="card filter-card sticky-top" style="top: 10px;">
                    <div class="card-header">
                        <strong>Filters</strong>
                    </div>
                    <div class="card-body filter-body">
                        <form method="get" autocomplete="off">
                            <div class="mb-1">
                                <label class="form-label filter-label">Challan No</label>
                                <input type="text" name="challan_no" class="form-control form-control-sm filter-control"
                                    value="<?= htmlspecialchars($challanNo) ?>" autocomplete="off">
                            </div>
                            <div class="mb-1">
                                <label class="form-label filter-label">Station</label>
                                <input type="text" name="station" class="form-control form-control-sm filter-control"
                                    value="<?= htmlspecialchars($station) ?>" autocomplete="off">
                            </div>
                            <div class="mb-1">
                                <label class="form-label filter-label">Vehicle</label>
                                <input type="text" name="vehicle_no" class="form-control form-control-sm filter-control"
                                    value="<?= htmlspecialchars($vehicle) ?>" autocomplete="off">
                            </div>
                            <div class="mb-1">
                                <label class="form-label filter-label">From</label>
                                <input type="date" name="date_from" class="form-control form-control-sm filter-control"
                                    value="<?= htmlspecialchars($dateFrom) ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label filter-label">To</label>
                                <input type="date" name="date_to" class="form-control form-control-sm filter-control"
                                    value="<?= htmlspecialchars($dateTo) ?>">
                            </div>
                            <div class="d-grid gap-1 filter-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                                <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-10 col-md-9">
                <div class="card shadow-sm">
                    <div class="card-header bg-light p-2 d-flex justify-content-between align-items-center">
                        <strong style="font-size: 15px;"><i class="bi bi-funnel-fill"></i> Challan Results (<?= count($rows) ?> records)</strong>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-info btn-sm action-btn" id="printSelectedChallans">
                                Print Selected <i class="bi bi-printer-fill ms-1"></i>
                            </button>
                            <button type="button" class="btn btn-danger btn-sm action-btn" id="deleteSelectedChallans">
                                Delete Selected <i class="bi bi-trash-fill ms-1"></i>
                            </button>
                            <a href="../create/index.php" class="btn btn-primary btn-sm action-btn">
                                New Challan <i class="bi bi-plus-circle ms-1"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="min-height: 360px; max-height: 700px; overflow-y: auto;">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 32px;"><input type="checkbox" id="selectAllChallans"></th>
                                <th>#</th>
                                <th>Challan No</th>
                                <th>Date</th>
                                <th>Vehicle</th>
                                <th>Paid</th>
                                <th>Freight</th>
                                <th>Recovery</th>
                                <th>Cutting</th>
                                <th>Commission</th>
                                <th>Final Total</th>
                                <th>Station</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="13" class="text-center py-3">No challan found</td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1;
                                foreach ($rows as $row):
                                    $dateKey = date('Y-m-d', strtotime((string) ($row['challan_date'] ?? '')));
                                    $todayClass = $dateKey === $todayDate ? 'today-challan' : '';
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="challan-checkbox" value="<?= (int) ($row['id'] ?? 0) ?>"></td>
                                        <td><?= $sn++ ?></td>
                                        <td><strong class="<?= $todayClass ?>"><?= htmlspecialchars($row['challan_no'] ?? '') ?></strong></td>
                                        <td><?= htmlspecialchars(formatDisplayDate($row['challan_date'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($row['vehicle_no'] ?? '') ?></td>
                                        <td><?= number_format((float) ($row['paid_total'] ?? 0), 0) ?></td>
                                        <td><?= number_format((float) ($row['freight_total'] ?? 0), 0) ?></td>
                                        <td><?= number_format((float) ($row['recovery_total'] ?? 0), 0) ?></td>
                                        <td><?= number_format((float) ($row['cutting_total'] ?? 0), 0) ?></td>
                                        <td><?= number_format((float) ($row['commission_total'] ?? 0), 0) ?></td>
                                        <td><?= number_format((float) ($row['final_total'] ?? 0), 0) ?></td>
                                        <td><strong><?= htmlspecialchars(capitalizeWords($row['challan_station'] ?? '')) ?></strong></td>
                                        <td class="text-center">
                                            <div class="dropdown">
                                                <button class="btn btn-secondary btn-sm dropdown-toggle action-btn" type="button"
                                                    data-bs-toggle="dropdown" aria-expanded="false">
                                                    Action
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <a class="dropdown-item"
                                                            href="../create/index.php?challan_id=<?= urlencode((string) $row['id']) ?>">
                                                            <i class="bi bi-pencil-fill me-2"></i>Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item"
                                                            href="../view/index.php?challan_id=<?= urlencode((string) $row['id']) ?>&view=1"
                                                            target="_blank">
                                                            <i class="bi bi-eye-fill me-2"></i>See
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item"
                                                            href="../print/index.php?challan_id=<?= urlencode((string) $row['id']) ?>"
                                                            target="_blank">
                                                            <i class="bi bi-printer-fill me-2"></i>Print
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="post" data-confirm="Delete this challan? Linked bilty will move to Booked.">
                                                            <input type="hidden" name="delete_id" value="<?= (int)($row['id'] ?? 0) ?>">
                                                            <button type="submit" class="dropdown-item text-danger">
                                                                <i class="bi bi-trash-fill me-2"></i>Delete
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
        </div>
    </div>

    <script>
        const selectAllChallans = document.getElementById('selectAllChallans');
        const challanCheckboxes = document.querySelectorAll('.challan-checkbox');
        const printSelectedChallans = document.getElementById('printSelectedChallans');
        const deleteSelectedChallans = document.getElementById('deleteSelectedChallans');

        if (selectAllChallans) {
            selectAllChallans.addEventListener('change', function () {
                challanCheckboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAllChallans.checked;
                });
            });
        }

        challanCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (!selectAllChallans) {
                    return;
                }

                const checkedCount = Array.from(challanCheckboxes).filter(function (item) {
                    return item.checked;
                }).length;

                selectAllChallans.checked = checkedCount === challanCheckboxes.length && challanCheckboxes.length > 0;
                selectAllChallans.indeterminate = checkedCount > 0 && checkedCount < challanCheckboxes.length;
            });
        });

        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                const message = form.getAttribute('data-confirm') || 'Are you sure?';
                const ok = typeof nmConfirm === 'function'
                    ? await nmConfirm(message)
                    : window.confirm(message);
                if (ok) {
                    form.submit();
                }
            });
        });

        if (printSelectedChallans) {
            printSelectedChallans.addEventListener('click', function () {
                const selectedIds = Array.from(document.querySelectorAll('.challan-checkbox:checked'))
                    .map(function (checkbox) {
                        return checkbox.value;
                    })
                    .filter(Boolean);

                if (selectedIds.length === 0) {
                    if (typeof showWarning === 'function') {
                        showWarning('Please select at least one challan to print.');
                    } else {
                        alert('Please select at least one challan to print.');
                    }
                    return;
                }

                const params = new URLSearchParams();
                params.set('ids', selectedIds.join(','));
                window.open(`../print/index.php?${params.toString()}`, '_blank');
            });
        }

        if (deleteSelectedChallans) {
            deleteSelectedChallans.addEventListener('click', async function () {
                const selectedIds = Array.from(document.querySelectorAll('.challan-checkbox:checked'))
                    .map(function (checkbox) {
                        return checkbox.value;
                    })
                    .filter(Boolean);

                if (selectedIds.length === 0) {
                    if (typeof showWarning === 'function') {
                        showWarning('Please select at least one challan to delete.');
                    } else {
                        alert('Please select at least one challan to delete.');
                    }
                    return;
                }

                const ok = typeof nmConfirm === 'function'
                    ? await nmConfirm(`Delete ${selectedIds.length} selected challan(s)? Linked bilty will move to Booked.`)
                    : window.confirm(`Delete ${selectedIds.length} selected challan(s)? Linked bilty will move to Booked.`);
                if (!ok) {
                    return;
                }

                const form = document.createElement('form');
                form.method = 'post';
                form.style.display = 'none';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_ids';
                input.value = selectedIds.join(',');
                form.appendChild(input);

                document.body.appendChild(form);
                form.submit();
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
