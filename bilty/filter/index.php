<?php
/**
 * Bilty Filter & Search Page
 * Search bilty data by GR, Consignee, Consignor, Station, and Date range.
 */

// Security / DB bootstrap

include '../../protect/auth.php';
include '../../protect/case_converter.php';

// Get logged-in company ID from session
$company_id = $_SESSION['company_id'] ?? '102';

// Load all branches/companies for filter dropdown
$branchesResult = $conn->query("SELECT id, branch FROM company ORDER BY branch");
$branches = [];
if ($branchesResult) {
    while ($row = $branchesResult->fetch_assoc()) {
        $branches[$row['id']] = $row['branch'];
    }
}

// Collect filters from query string
$gr = trim($_GET['gr'] ?? '');
$consignor = trim($_GET['consignor'] ?? '');
$consignee = trim($_GET['consignee'] ?? '');
$station = trim($_GET['station'] ?? '');
$status = array_key_exists('status', $_GET) ? trim((string) $_GET['status']) : 'Booked';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$hasSearchFilter = $gr !== '' || $consignor !== '' || $consignee !== '' || $station !== '';

// Set default date range only for the normal list view.
// Quick GR search from dashboard should search all dates.
if (!$hasSearchFilter && $dateFrom === '' && $dateTo === '') {
    $dateFrom = date('Y-m-d');
    $dateTo = date('Y-m-d');
}

// Build dynamic WHERE clause
$clauses = [];
$params = [];
$types = '';

// Always filter by logged-in user's company_id
$clauses[] = 'b.company_id = ?';
$params[] = $company_id;
$types .= 's';

// Status filter: exclude Trash, allow filtering by specific status
if ($status !== '') {
    $clauses[] = 'b.status = ?';
    $params[] = $status;
    $types .= 's';
} else {
    // Default: show all except Trash
    $clauses[] = "b.status IN ('Booked', 'Dispatch', 'Deliver', 'Cancel')";
}

if ($gr !== '') {
    $clauses[] = 'b.gr_number LIKE ?';
    $params[] = "%{$gr}%";
    $types .= 's';
}

if ($consignor !== '') {
    $clauses[] = 'b.consignor_name LIKE ?';
    $params[] = "%{$consignor}%";
    $types .= 's';
}

if ($consignee !== '') {
    $clauses[] = 'b.consignee_name LIKE ?';
    $params[] = "%{$consignee}%";
    $types .= 's';
}

if ($station !== '') {
    $clauses[] = 'b.to_station LIKE ?';
    $params[] = "%{$station}%";
    $types .= 's';
}

if ($dateFrom !== '') {
    $clauses[] = 'DATE(b.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $clauses[] = 'DATE(b.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$where = '';
if (!empty($clauses)) {
    $where = 'WHERE ' . implode(' AND ', $clauses);
}

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM biltys b {$where}";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $perPage);
$countStmt->close();

// Main query with pagination
$sql = "SELECT b.*, c.id AS challan_id, c.challan_no AS challan_no
    FROM biltys b
    LEFT JOIN challans c ON c.id = b.challan_id
    {$where}
    ORDER BY b.created_at DESC, b.id DESC
    LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    // Query-level DB failures are redirected to the centralized error module.
    app_report_error('Database query failed', 'Query preparation failed: ' . $conn->error, 'database', 500);
}

if (!empty($params)) {
    $types .= 'ii';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Bilty Filter</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .btn-action {
            font-size: 12px;
            padding: 4px 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            line-height: 1.3;
        }

        .btn-action i {
            font-size: 14px;
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
            padding: 2px 3px;
            text-align: center;
            font-size: 20px;
        }

        .filter-title {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .filter-body {
            padding: 6px;
            background: #ffffff;
        }

        .filter-label {
            font-size: 14px;
            font-weight: 600;
            color: #000000;
            margin: 0;
        }

        .filter-control {
            font-size: 12px;
            min-height: 32px;
            border-radius: 8px;
            border-color: #d1d5db;
            padding: 6px 8px;
        }

        .filter-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 0.15rem rgba(37, 99, 235, 0.18);
        }

        .filter-actions {
            padding-top: 2px;
        }

        .filter-actions .btn {
            font-size: 12px;
            font-weight: 600;
            border-radius: 8px;
            padding: 4px 8px;
        }
    </style>
</head>

<body class="bg-light">
    <?php include "../../content/nav.php"; ?>
    <div class="container-fluid my-1">

        <div class="row g-2">
            <!-- LEFT SIDE: FILTERS -->
            <div class="col-lg-2 col-md-3">
                <div class="card filter-card sticky-top" style="top: 10px;">
                    <div class="card-header">
                        <strong class="filter-title">Filters</strong>
                    </div>
                    <div class="card-body filter-body">
                        <form method="get" autocomplete="off">
                            <div class="mb-1">
                                <label for="gr" class="form-label filter-label">GR Number</label>
                                <input type="text" class="form-control form-control-sm filter-control" id="gr" name="gr"
                                    value="<?= htmlspecialchars($gr) ?>" placeholder="GR"
                                    autocomplete="off" />
                            </div>
                            <div class="mb-1">
                                <label for="status" class="form-label filter-label">Bilty
                                    Status</label>
                                <select class="form-select form-select-sm filter-control" id="status" name="status">
                                    <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                                    <option value="Booked" <?= $status === 'Booked' ? 'selected' : '' ?>>Booked</option>
                                    <option value="Dispatch" <?= $status === 'Dispatch' ? 'selected' : '' ?>>Dispatch
                                    </option>
                                    <option value="Deliver" <?= $status === 'Deliver' ? 'selected' : '' ?>>Deliver</option>
                                    <option value="Cancel" <?= $status === 'Cancel' ? 'selected' : '' ?>>Cancel</option>
                                </select>
                            </div>

                            <div class="mb-1">
                                <label for="consignor" class="form-label filter-label">Consignor</label>
                                <input type="text" class="form-control form-control-sm filter-control" id="consignor" name="consignor"
                                    value="<?= htmlspecialchars($consignor) ?>"
                                    autocomplete="off" />
                            </div>
                            <div class="mb-1">
                                <label for="consignee" class="form-label filter-label">Consignee</label>
                                <input type="text" class="form-control form-control-sm filter-control" id="consignee" name="consignee"
                                    value="<?= htmlspecialchars($consignee) ?>"
                                    autocomplete="off" />
                            </div>
                            <div class="mb-1">
                                <label for="station" class="form-label filter-label">Station</label>
                                <input type="text" class="form-control form-control-sm filter-control" id="station" name="station"
                                    value="<?= htmlspecialchars($station) ?>"
                                    autocomplete="off" />
                            </div>
                            <div class="mb-1">
                                <label for="date_from" class="form-label filter-label">From</label>
                                <input type="date" class="form-control form-control-sm filter-control" id="date_from" name="date_from"
                                    value="<?= htmlspecialchars($dateFrom) ?>"
                                    autocomplete="off" />
                            </div>
                            <div class="mb-1">
                                <label for="date_to" class="form-label filter-label">To</label>
                                <input type="date" class="form-control form-control-sm filter-control" id="date_to" name="date_to"
                                    value="<?= htmlspecialchars($dateTo) ?>"
                                    autocomplete="off" />
                            </div>
                            <div class="d-grid gap-1 filter-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                                <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- RIGHT SIDE: RESULTS -->
            <div class="col-lg-10 col-md-9">
                <div class="card shadow-sm">
                    <div class="card-header bg-light p-2 d-flex justify-content-between align-items-center">
                        <strong style="font-size: 15px;">
                            <?php
                            $currentBranchName = $branches[$company_id] ?? "Company $company_id";
                            ?>
                            📍 <?= htmlspecialchars($currentBranchName) ?> - Results (<span
                                id="resultCount"><?= count($rows) ?></span> records)
                        </strong>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-info btn-sm btn-action" id="printSelected">
                                Print Selected <i class="bi bi-printer-fill ms-1"></i>
                            </button>
                            <button type="button" class="btn btn-danger btn-sm btn-action" id="deleteSelected">
                                Delete Selected <i class="bi bi-trash-fill ms-1"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="min-height: 700px; max-height: 700px; overflow-y: auto;">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light sticky">
                                    <tr>
                                        <th style="width:2%"><input type="checkbox" id="selectAll"></th>
                                        <th style="width:2%">#</th>
                                        <th style="width:5%">Status</th>
                                        <th style="width:5%">GR</th>
                                        <th style="width:8%">Company</th>
                                        <th style="width:16%">Consignor</th>
                                        <th style="width:16%">Consignee</th>
                                        <th style="width:6%">Station</th>
                                        <th style="width:5%">Total</th>
                                        <th style="width:5%">Mode</th>
                                        <th style="width:12%">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="11" class="text-muted text-center py-3">No records found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($rows as $index => $row):
                                            $rowCompanyId = $row['company_id'] ?? '';
                                            $rowCompanyName = $branches[$rowCompanyId] ?? "Company $rowCompanyId";
                                            $rowStatus = (string) ($row['status'] ?? '');
                                            $isCancelled = $rowStatus === 'Cancel';
                                            ?>
                                            <tr id="bilty-row-<?= intval($row['id']) ?>">
                                                <td>
                                                    <?php if (!$isCancelled): ?>
                                                        <input type="checkbox" class="bilty-checkbox" value="<?= intval($row['id']) ?>">
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= ($offset + $index + 1) ?></td>
                                                <td>
                                                    <?php if ($rowStatus === 'Dispatch' && !empty($row['challan_no'])): ?>
                                                        <span class="badge bg-success"><a class="text-white text-decoration-none"
                                                                href="../../challan/view/index.php?challan_id=<?= urlencode((string) ($row['challan_id'] ?? '')) ?>"
                                                                target="_blank"><?= "D-" . htmlspecialchars($row['challan_no']) ?></a></span>
                                                    <?php elseif ($isCancelled): ?>
                                                        <span class="badge bg-danger">Cancel</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-info "><?= htmlspecialchars($rowStatus) ?></span>
                                                    <?php endif; ?>

                                                </td>
                                                <td><strong><?= htmlspecialchars($row['gr_number']) ?></strong></td>
                                                <td><?= date('d-m-Y', strtotime($row['bilty_date'])) ?></td>
                                                <td><?= htmlspecialchars(capitalizeWords($row['consignor_name'])) ?></td>
                                                <td><?= htmlspecialchars(capitalizeWords($row['consignee_name'])) ?></td>
                                                <td><?= htmlspecialchars(capitalizeWords(substr($row['to_station'], 0, 12))) ?></td>
                                                <td><?= number_format((float) ($row['total_charge'] ?? 0) > 0 ? (float) ($row['total_charge'] ?? 0) : ((float) ($row['freight'] ?? 0) + (float) ($row['p_freight'] ?? 0) + (float) ($row['hammali'] ?? 0) + (float) ($row['brokerage'] ?? 0)), 0) ?>
                                                </td>
                                                <td><?= htmlspecialchars(strtoupper(substr((string) $row['payment_type'], 0, 6))) ?></td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-secondary btn-sm dropdown-toggle btn-action" type="button"
                                                            data-bs-toggle="dropdown" aria-expanded="false">
                                                            Action
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <a class="dropdown-item" href="../view/index.php?id=<?= urlencode($row['id']) ?>" target="_blank">
                                                                    <i class="bi bi-eye-fill me-2"></i>View
                                                                </a>
                                                            </li>
                                                            <?php if (!$isCancelled): ?>
                                                                <li>
                                                                    <a class="dropdown-item" href="../print/index.php?id=<?= urlencode($row['id']) ?>" target="_blank">
                                                                        <i class="bi bi-printer-fill me-2"></i>Print / PDF
                                                                    </a>
                                                                </li>
                                                                <li>
                                                                    <a class="dropdown-item" href="../create/index.php?edit_id=<?= urlencode($row['id']) ?>">
                                                                        <i class="bi bi-pencil-fill me-2"></i>Edit
                                                                    </a>
                                                                </li>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li>
                                                                    <button type="button" class="dropdown-item text-danger"
                                                                        onclick="cancelBilty(<?= intval($row['id']) ?>);">
                                                                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                                                                    </button>
                                                                </li>
                                                            <?php endif; ?>
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

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="card-footer" style="padding: 6px 12px;">
                            <div style="display: flex; justify-content: center; align-items: center; gap: 15px;">
                                <nav aria-label="Page navigation" style="flex: 0;">
                                    <ul class="pagination pagination-sm mb-0">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a></li>
                                        <?php if ($page > 3) { echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>'; if ($page > 4) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } ?>
                                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?><li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li><?php endfor; ?>
                                        <?php if ($page < $totalPages - 2) { if ($page < $totalPages - 3) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '">' . $totalPages . '</a></li>'; } ?>
                                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" aria-label="Next"><span aria-hidden="true">&raquo;</span></a></li>
                                    </ul>
                                </nav>
                                <small class="text-muted" style="white-space: nowrap; font-size: 11px;">Page <?= $page ?> of <?= $totalPages ?> (Total: <?= $totalRecords ?> records)</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        if (window.location.search) {
            window.history.replaceState(null, document.title, window.location.pathname);
        }

        function deleteBilty(id) {
            nmConfirm('Are you sure you want to delete this bilty?').then(function (ok) {
                if (!ok) return;

                console.log('Deleting bilty ID:', id);

            fetch('../trash/delete_bilty.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'id=' + encodeURIComponent(id)
            })
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Delete response:', data);

                    if (data.success) {
                        // Show success message
                        showDelete(data.message || 'Bilty deleted successfully');

                        // Find and remove the row with animation
                        const row = document.getElementById('bilty-row-' + id);
                        console.log('Found row:', row);

                        if (row) {
                            // Apply fade out animation
                            row.style.transition = 'opacity 0.5s ease-out';
                            row.style.opacity = '0';

                            // After animation, reload page
                            setTimeout(() => {
                                window.location.reload();
                            }, 600);
                        } else {
                            // Row not found, just reload
                            console.warn('Row not found, reloading page');
                            window.location.reload();
                        }
                    } else {
                        showError(data.message || 'Failed to delete bilty');
                    }
                })
                .catch(err => {
                    console.error('Delete error:', err);
                    showError('Error deleting bilty: ' + err.message);
                });
            });
        }

        async function cancelBilty(id) {
            const ok = await nmConfirm('Cancel this bilty? It will not be available for challan.');
            if (!ok) return;

            const password = window.prompt('Enter cancel password');
            if (password === null) return;

            fetch('cancel_bilty.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'id=' + encodeURIComponent(id) + '&password=' + encodeURIComponent(password)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showDelete(data.message || 'Bilty cancelled successfully');
                        setTimeout(() => {
                            window.location.reload();
                        }, 600);
                    } else {
                        showError(data.message || 'Failed to cancel bilty');
                    }
                })
                .catch(error => {
                    showError('Error cancelling bilty: ' + error.message);
                });
        }

        // Toggle all row checkboxes when header checkbox changes
        const selectAll = document.getElementById('selectAll');
        const rowCheckboxes = document.querySelectorAll('.bilty-checkbox');

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                rowCheckboxes.forEach(cb => {
                    cb.checked = selectAll.checked;
                });
            });
        }

        // Keep header checkbox in sync when any row checkbox toggles
        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', () => {
                const allChecked = Array.from(rowCheckboxes).every(x => x.checked);
                const noneChecked = Array.from(rowCheckboxes).every(x => !x.checked);
                if (selectAll) {
                    selectAll.indeterminate = !allChecked && !noneChecked;
                    selectAll.checked = allChecked;
                }
            });
        });

        // Print all selected bilties in one go
        const printSelectedBtn = document.getElementById('printSelected');
        if (printSelectedBtn) {
            printSelectedBtn.addEventListener('click', () => {
                const selectedIds = Array.from(document.querySelectorAll('.bilty-checkbox:checked'))
                    .map(cb => cb.value)
                    .filter(Boolean);

                if (selectedIds.length === 0) {
                    showError('Please select at least one bilty to print.');
                    return;
                }

                const params = new URLSearchParams();
                params.set('ids', selectedIds.join(','));
                window.open(`../print/index.php?${params.toString()}`, '');
            });
        }

        const deleteSelectedBtn = document.getElementById('deleteSelected');
        if (deleteSelectedBtn) {
            deleteSelectedBtn.addEventListener('click', async () => {
                const selectedIds = Array.from(document.querySelectorAll('.bilty-checkbox:checked'))
                    .map(cb => cb.value)
                    .filter(Boolean);

                if (selectedIds.length === 0) {
                    showError('Please select at least one bilty to delete.');
                    return;
                }

                const ok = await nmConfirm(`Delete ${selectedIds.length} selected bilty(s)?`);
                if (!ok) {
                    return;
                }

                deleteSelectedBtn.disabled = true;

                try {
                    const responses = await Promise.all(
                        selectedIds.map(id =>
                            fetch('../trash/delete_bilty.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: 'id=' + encodeURIComponent(id)
                            }).then(response => response.json())
                        )
                    );

                    const failed = responses.filter(r => !r || !r.success);

                    if (failed.length === 0) {
                        showDelete(`Deleted ${selectedIds.length} bilty(s) successfully.`);
                    } else {
                        showError(`Deleted ${selectedIds.length - failed.length}, failed ${failed.length}.`);
                    }

                    setTimeout(() => {
                        window.location.reload();
                    }, 600);
                } catch (error) {
                    showError('Error deleting selected biltys: ' + error.message);
                } finally {
                    deleteSelectedBtn.disabled = false;
                }
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
