<?php
/**
 * Bilty Trash / Delete Management Page
 * View and restore deleted bilties
 */

// Security / DB bootstrap
include '../../protect/auth.php';
include '../../protect/db.php';

// Get logged-in company ID from session
$company_id = $_SESSION['company_id'] ?? '102';

// Collect filters from query string
$gr          = trim($_GET['gr'] ?? '');
$consignor   = trim($_GET['consignor'] ?? '');
$consignee   = trim($_GET['consignee'] ?? '');
$station     = trim($_GET['station'] ?? '');
$dateFrom    = trim($_GET['date_from'] ?? '');
$dateTo      = trim($_GET['date_to'] ?? '');

// Set default date range: last 30 days to today
if ($dateFrom === '' && $dateTo === '') {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo   = date('Y-m-d');
}

// Build dynamic WHERE clause - only show TRASH status
$clauses = [];
$params  = [];
$types   = '';

$clauses[] = 'company_id = ?';
$params[]  = $company_id;
$types    .= 's';

$clauses[] = "status = 'Trash'";

if ($gr !== '') {
    $clauses[] = 'gr_number LIKE ?';
    $params[]  = "%{$gr}%";
    $types    .= 's';
}

if ($consignor !== '') {
    $clauses[] = 'consignor_name LIKE ?';
    $params[]  = "%{$consignor}%";
    $types    .= 's';
}

if ($consignee !== '') {
    $clauses[] = 'consignee_name LIKE ?';
    $params[]  = "%{$consignee}%";
    $types    .= 's';
}

if ($station !== '') {
    $clauses[] = 'to_station LIKE ?';
    $params[]  = "%{$station}%";
    $types    .= 's';
}

if ($dateFrom !== '') {
    $clauses[] = 'DATE(updated_at) >= ?';
    $params[]  = $dateFrom;
    $types    .= 's';
}

if ($dateTo !== '') {
    $clauses[] = 'DATE(updated_at) <= ?';
    $params[]  = $dateTo;
    $types    .= 's';
}

// Build query
$where = !empty($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
$sql   = "SELECT id, gr_number, consignor_name, consignee_name, to_station, total_charge, bilty_date, updated_at FROM biltys $where ORDER BY updated_at DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$bilties = [];
while ($row = $result->fetch_assoc()) {
    $bilties[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash Bilties</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 20px;
        }
        .container-fluid {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #dc3545;
            margin-bottom: 25px;
            font-weight: bold;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .filter-section label {
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
        }
        .table-responsive {
            margin-top: 20px;
        }
        .table thead {
            background-color: #dc3545;
            color: white;
        }
        .table tbody tr {
            border-bottom: 1px solid #e9ecef;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .action-btn {
            padding: 5px 10px;
            font-size: 12px;
            margin: 2px;
        }
        .bulk-actions {
            margin-top: 8px;
        }
        .checkbox-col {
            text-align: center;
            width: 4%;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
    </style>
</head>
<body>
<?php include '../../content/nav.php'; ?>

<div class="container-fluid">
    <h1>🗑️ Trash Bilties</h1>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label>GR Number</label>
                <input type="text" name="gr" class="form-control form-control-sm" value="<?= htmlspecialchars($gr) ?>" placeholder="Search GR...">
            </div>
            <div class="col-md-2">
                <label>Consignor</label>
                <input type="text" name="consignor" class="form-control form-control-sm" value="<?= htmlspecialchars($consignor) ?>" placeholder="Consignor...">
            </div>
            <div class="col-md-2">
                <label>Consignee</label>
                <input type="text" name="consignee" class="form-control form-control-sm" value="<?= htmlspecialchars($consignee) ?>" placeholder="Consignee...">
            </div>
            <div class="col-md-2">
                <label>Station</label>
                <input type="text" name="station" class="form-control form-control-sm" value="<?= htmlspecialchars($station) ?>" placeholder="Station...">
            </div>
            <div class="col-md-2">
                <label>Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label>Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-md-12">
                <button type="submit" class="btn btn-danger btn-sm">Search</button>
                <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
                <a href="../filter/index.php" class="btn btn-primary btn-sm">Back to Active</a>
                <?php if (!empty($bilties)) : ?>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="restoreSelected()">Restore Selected</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="permanentDeleteSelected()">Delete Selected</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results Table -->
    <div class="table-responsive">
        <?php if (!empty($bilties)) : ?>
            <table class="table table-hover table-bordered">
                <thead>
                    <tr>
                        <th class="checkbox-col">
                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                        </th>
                        <th style="width: 5%">S.No</th>
                        <th style="width: 5%">GR Number</th>
                        <th style="width: 15%">Consignor</th>
                        <th style="width: 15%">Consignee</th>
                        <th style="width: 10%">Station</th>
                        <th style="width: 10%">Charge</th>
                        <th style="width: 10%">Date</th>
                        <th style="width: 30%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bilties as $idx => $row) : ?>
                        <tr>
                            <td class="checkbox-col">
                                <input type="checkbox" class="row-select" value="<?= intval($row['id']) ?>" onchange="syncSelectAllState()">
                            </td>
                            <td><?= $idx + 1 ?></td>
                            <td><strong><?= htmlspecialchars($row['gr_number'] ?? (string)$row['id']) ?></strong></td>
                            <td><?= htmlspecialchars($row['consignor_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['consignee_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['to_station'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(number_format((float)($row['total_charge'] ?? 0), 0)) ?></td>
                            <td><?= date('d-m-Y', strtotime($row['bilty_date'] ?? $row['updated_at'])) ?></td>
                            <td>
                                <button class="btn btn-info btn-sm action-btn" onclick="viewBilty(<?= intval($row['id']) ?>)">View</button>
                                <button class="btn btn-success btn-sm action-btn" onclick="restoreBilty(this, <?= intval($row['id']) ?>)">Restore</button>
                                <button class="btn btn-danger btn-sm action-btn" onclick="permanentDelete(this, <?= intval($row['id']) ?>)">Permanent Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <div class="no-data">
                <p>No trash bilties found</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- centralized notify.js loaded via nav.php -->
<script>
    function getSelectedIds() {
        return Array.from(document.querySelectorAll('.row-select:checked')).map(function (el) {
            return el.value;
        });
    }

    function sendBulkRequest(url, ids) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ids=' + encodeURIComponent(ids.join(','))
        }).then(function (r) {
            return r.json();
        });
    }

    function viewBilty(id) {
        window.location.href = '../view/index.php?id=' + id;
    }

    function restoreBilty(buttonEl, id) {
        nmConfirm('Restore this bilty?').then(function (ok) {
            if (!ok) return;

            // Find the row in a browser-compatible way.
            const row = buttonEl && buttonEl.closest ? buttonEl.closest('tr') : null;
        
        sendBulkRequest('restore_bilty.php', [id])
        .then(data => {
            if (data.success) {
                showSave(data.message || 'Bilty restored successfully');
                
                // Smooth removal animation
                if (row) {
                    row.style.transition = 'opacity 0.5s ease-out';
                    row.style.opacity = '0';
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 600);
                } else {
                    setTimeout(() => { window.location.reload(); }, 500);
                }
            } else {
                showError(data.message || 'Failed to restore bilty');
            }
        })
        .catch(e => {
            console.error('Restore error:', e);
            showError('Error restoring bilty');
        });
        });
    }

    function permanentDelete(buttonEl, id) {
        nmConfirm('Permanently delete this bilty? This cannot be undone.').then(function (ok) {
            if (!ok) return;

            // Find the row in a browser-compatible way.
            const row = buttonEl && buttonEl.closest ? buttonEl.closest('tr') : null;
        
        sendBulkRequest('permanent_delete_bilty.php', [id])
        .then(data => {
            if (data.success) {
                showDelete(data.message || 'Bilty permanently deleted');
                
                // Smooth removal animation
                if (row) {
                    row.style.transition = 'opacity 0.5s ease-out';
                    row.style.opacity = '0';
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 600);
                } else {
                    setTimeout(() => { window.location.reload(); }, 500);
                }
            } else {
                showError(data.message || 'Failed to delete bilty');
            }
        })
        .catch(e => {
            console.error('Delete error:', e);
            showError('Error deleting bilty');
        });
        });
    }

    function toggleSelectAll(checkbox) {
        document.querySelectorAll('.row-select').forEach(function (rowCheckbox) {
            rowCheckbox.checked = checkbox.checked;
        });
    }

    function syncSelectAllState() {
        const all = document.querySelectorAll('.row-select');
        const checked = document.querySelectorAll('.row-select:checked');
        const selectAll = document.getElementById('selectAll');
        if (!selectAll) return;

        if (all.length === 0) {
            selectAll.checked = false;
            return;
        }

        selectAll.checked = all.length === checked.length;
    }

    function permanentDeleteSelected() {
        const selected = getSelectedIds();

        if (selected.length === 0) {
            showError('Please select at least one bilty');
            return;
        }

        nmConfirm('Permanently delete selected bilties? This cannot be undone.').then(function (ok) {
            if (!ok) return;

        sendBulkRequest('permanent_delete_bilty.php', selected)
            .then(function (data) {
                if (data && data.success) {
                    showDelete(data.message || 'Selected bilties permanently deleted');
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                } else {
                    showError((data && data.message) || 'Failed to delete selected bilties');
                }
            })
            .catch(function (e) {
                console.error('Bulk delete error:', e);
                showError('Error deleting selected bilties');
            });
        });
    }

    function restoreSelected() {
        const selected = getSelectedIds();

        if (selected.length === 0) {
            showError('Please select at least one bilty');
            return;
        }

        nmConfirm('Restore selected bilties?').then(function (ok) {
            if (!ok) return;

            sendBulkRequest('restore_bilty.php', selected)
                .then(function (data) {
                    if (data && data.success) {
                        showSave(data.message || 'Selected bilties restored');
                        setTimeout(function () {
                            window.location.reload();
                        }, 500);
                    } else {
                        showError((data && data.message) || 'Failed to restore selected bilties');
                    }
                })
                .catch(function (e) {
                    console.error('Bulk restore error:', e);
                    showError('Error restoring selected bilties');
                });
        });
    }
</script>

</body>
</html>
