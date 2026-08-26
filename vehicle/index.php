<?php
include "../protect/auth.php";
include "../protect/case_converter.php";

$company_id = $_SESSION['company_id'] ?? '';

function normalize_vehicle_number($number)
{
    $number = preg_replace('/[^A-Za-z0-9]/', '', $number ?? '');
    return strtoupper($number);
}

function is_valid_vehicle_number($number)
{
    return (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z]{2}\d{4}$/', $number);
}

/* =====================
   SAVE / UPDATE
===================== */
if (isset($_POST['save'])) {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    $vehicle_number = normalize_vehicle_number($_POST['vehicle_number'] ?? '');
    $driver_name = strtolower(trim($_POST['driver_name'] ?? ''));
    $owner_name = strtolower(trim($_POST['owner_name'] ?? ''));
    $mobile = preg_replace('/\D+/', '', $_POST['mobile'] ?? '');

    if ($vehicle_number === '' || !is_valid_vehicle_number($vehicle_number) || $driver_name === '' || $owner_name === '') {
        header("Location: index.php?status=0");
        exit;
    }

    if ($mobile !== '' && !preg_match('/^\d{10}$/', $mobile)) {
        header("Location: index.php?status=0");
        exit;
    }

    if ($id <= 0) {
        $stmt = $conn->prepare("INSERT INTO vehicles (company_id, vehicle_number, driver_name, owner_name, mobile) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $company_id, $vehicle_number, $driver_name, $owner_name, $mobile);
    } else {
        $stmt = $conn->prepare("UPDATE vehicles SET vehicle_number = ?, driver_name = ?, owner_name = ?, mobile = ? WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ssssis", $vehicle_number, $driver_name, $owner_name, $mobile, $id, $company_id);
    }

    if ($stmt->execute()) {
        header("Location: index.php?status=" . ($id <= 0 ? "1" : "3") . "&view=list");
        exit;
    }

    if ($conn->errno === 1062) {
        header("Location: index.php?status=2");
        exit;
    }

    $stmt->close();
    header("Location: index.php?status=0");
    exit;
}

/* =====================
   DELETE
===================== */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM vehicles WHERE id = ? AND company_id = ?");
    $stmt->bind_param("is", $id, $company_id);
    $stmt->execute();
    $stmt->close();
    header("Location: index.php?status=4");
    exit;
}

/* =====================
   EDIT
===================== */
$edit = [];
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, vehicle_number, driver_name, owner_name, mobile FROM vehicles WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("is", $id, $company_id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

/* =====================
   AJAX LIST
===================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_vehicle') {
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = 10;

    $q = trim($_GET['q'] ?? '');

    $where = "WHERE company_id = ? ";
    $params = [$company_id];
    $types = "s";

    if ($q !== '') {
        $where .= "AND (vehicle_number LIKE ? OR driver_name LIKE ? OR owner_name LIKE ? OR mobile LIKE ?) ";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "ssss";
    }

    $sql = "SELECT id, vehicle_number, driver_name, owner_name, mobile, created_at FROM vehicles $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
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
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Vehicle Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
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

        .vehicle-tabs .nav-link {
            font-weight: 600;
            color: #166534;
        }

        .vehicle-tabs .nav-link.active {
            background: #22c55e;
            color: #ffffff;
            border-color: #22c55e #22c55e #22c55e;
        }

        .vehicle-tabs .nav-link:not(.active):hover {
            border-color: #86efac #86efac #dee2e6;
            color: #15803d;
        }

        .form-mode-create {
            background: #ecfdf3;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
        }

        .form-mode-update {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
        }

        .vehicle-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        @media (max-width: 767.98px) {
            .container-fluid {
                padding-left: 8px;
                padding-right: 8px;
            }

            .card-body {
                padding: 0.75rem;
            }

            .vehicle-tabs .nav-item {
                flex: 1 1 50%;
            }

            .vehicle-tabs .nav-link {
                width: 100%;
                text-align: center;
                font-size: 13px;
                padding: 0.6rem 0.4rem;
            }

            .table {
                font-size: 12px;
            }
        }
    </style>
</head>

<body>

    <?php include "../content/nav.php"; ?>

    <?php if (isset($_GET['status'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            <?php $s = $_GET['status']; ?>
            <?php if ($s == '1'): ?>showSave('Vehicle saved successfully');
            <?php elseif ($s == '3'): ?>showUpdate('Vehicle updated successfully');
            <?php elseif ($s == '4'): ?>showDelete('Vehicle deleted successfully');
            <?php elseif ($s == '2'): ?>showError('Vehicle number already exists');
            <?php elseif ($s == '0'): ?>showWarning('Please fill valid vehicle details');
            <?php endif; ?>
            if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('status'); window.history.replaceState({}, document.title, u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash); }
        });</script>
    <?php endif; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 vehicle-tabs" id="vehicleMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="vehicle-form-tab" data-bs-toggle="tab" data-bs-target="#vehicle-form-pane" type="button" role="tab" aria-controls="vehicle-form-pane" aria-selected="true">
                    <i class="bi bi-truck"></i> <?= isset($edit['id']) ? 'Update' : 'Create' ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="vehicle-list-tab" data-bs-toggle="tab" data-bs-target="#vehicle-list-pane" type="button" role="tab" aria-controls="vehicle-list-pane" aria-selected="false">
                    <i class="bi bi-list"></i> Vehicle List
                </button>
            </li>
        </ul>

        <div class="tab-content" id="vehicleMainTabsContent">
            <div class="tab-pane fade show active" id="vehicle-form-pane" role="tabpanel" aria-labelledby="vehicle-form-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body <?= isset($edit['id']) ? 'form-mode-update' : 'form-mode-create' ?>">

                                <h6 class="fw-bold mb-2">
                                    <i class="bi bi-truck"></i>
                                    <?= isset($edit['id']) ? 'Update Vehicle' : 'Create Vehicle' ?>
                                </h6>

                                <form method="post">
                                    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label>Vehicle Number</label>
                                            <input type="text" name="vehicle_number" class="form-control" value="<?= htmlspecialchars($edit['vehicle_number'] ?? '') ?>" required>
                                        </div>

                                        <div class="col-md-3">
                                            <label>Driver Name</label>
                                            <input type="text" name="driver_name" class="form-control" value="<?= htmlspecialchars(capitalizeWords($edit['driver_name'] ?? '')) ?>" required>
                                        </div>

                                        <div class="col-md-3">
                                            <label>Owner Name</label>
                                            <input type="text" name="owner_name" class="form-control" value="<?= htmlspecialchars(capitalizeWords($edit['owner_name'] ?? '')) ?>" required>
                                        </div>

                                        <div class="col-md-3">
                                            <label>Mobile</label>
                                            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($edit['mobile'] ?? '') ?>" placeholder="10-digit mobile">
                                        </div>
                                    </div>

                                    <div class="mt-3 d-flex gap-2 flex-wrap">
                                        <button type="submit" name="save" class="btn <?= isset($edit['id']) ? 'btn-warning' : 'btn-success' ?> btn-sm">
                                            <i class="bi bi-save"></i> <?= isset($edit['id']) ? 'Update' : 'Save' ?>
                                        </button>

                                        <?php if (isset($edit['id'])): ?>
                                            <a href="index.php?view=list" class="btn btn-danger btn-sm">Cancel</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="vehicle-list-pane" role="tabpanel" aria-labelledby="vehicle-list-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <input type="text" id="search_q" class="form-control form-control-sm mb-2" placeholder="Search vehicle by number, driver, owner, mobile..." onkeyup="autoSearchVehicle()" autocomplete="off">

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="60">Sr</th>
                                                <th>Vehicle</th>
                                                <th>Driver</th>
                                                <th>Owner</th>
                                                <th>Mobile</th>
                                                <th width="150">Action</th>
                                            </tr>
                                        </thead>

                                        <tbody id="vehicleTable"></tbody>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <script>
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

        document.addEventListener('DOMContentLoaded', function() {
            const view = new URLSearchParams(window.location.search).get('view');
            const formTabButton = document.getElementById('vehicle-form-tab');
            const listTabButton = document.getElementById('vehicle-list-tab');

            if (view === 'list' && listTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(listTabButton).show();
            } else if (view === 'create' && formTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(formTabButton).show();
            }

            const tabButtons = document.querySelectorAll('#vehicleMainTabs [data-bs-toggle="tab"]');
            tabButtons.forEach(function(tabButton) {
                tabButton.addEventListener('shown.bs.tab', function() {
                    clearViewParamFromUrl();
                });
            });
        });
    </script>

    <script>
        let offset = 0;
        const limit = 10;
        let typingTimer;

        function escHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function capitalizeWords(str) {
            return str.toLowerCase().split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        }

        function autoSearchVehicle() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                offset = 0;
                document.getElementById('vehicleTable').innerHTML = '';
                document.getElementById('loadMoreBtn').disabled = false;
                document.getElementById('loadMoreBtn').innerText = 'Load More';
                loadMoreVehicle();
            }, 400);
        }

        function loadMoreVehicle() {
            const q = document.getElementById('search_q').value.trim();

            fetch(`?ajax=load_vehicle&offset=${offset}&q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('vehicleTable');

                    if (data.length === 0 && offset === 0) {
                        tbody.innerHTML = `<tr><td colspan="6" class="text-center">No Data Found</td></tr>`;
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
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${sn++}</td>
                            <td>${escHtml(row.vehicle_number)}</td>
                            <td>${escHtml(capitalizeWords(row.driver_name))}</td>
                            <td>${escHtml(capitalizeWords(row.owner_name))}</td>
                            <td>${escHtml(row.mobile)}</td>
                            <td>
                                <div class="vehicle-actions">
                                    <a href="?edit=${row.id}" onclick="nmNavConfirm(event,'Edit this vehicle?')" class="btn btn-warning btn-sm">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="?delete=${row.id}" onclick="nmNavConfirm(event,'Delete this vehicle?')" class="btn btn-danger btn-sm">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });

                    offset += limit;
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadMoreVehicle();
        });

        document.getElementById('loadMoreBtn').addEventListener('click', loadMoreVehicle);
    </script>
</body>

</html>
