<?php
include "../protect/db.php";
include "../protect/case_converter.php";

/* =====================
   SAVE / UPDATE
===================== */
if (isset($_POST['save'])) {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    $station_name = strtolower(trim($_POST['station_name'] ?? ''));
    $city = strtolower(trim($_POST['city'] ?? ''));
    $state = strtolower(trim($_POST['state'] ?? ''));

    if ($station_name === '' || $city === '' || $state === '') {
        header("Location: index.php?status=0");
        exit;
    }

    if ($id <= 0) {
        $stmt = $conn->prepare("INSERT INTO station (station_name, city, state) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $station_name, $city, $state);
    } else {
        $stmt = $conn->prepare("UPDATE station SET station_name = ?, city = ?, state = ? WHERE id = ?");
        $stmt->bind_param("sssi", $station_name, $city, $state, $id);
    }

    $stmt->execute();
    $stmt->close();

    header("Location: index.php?status=" . ($id <= 0 ? "1" : "3") . "&view=list");
    exit;
}

/* =====================
   DELETE
===================== */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM station WHERE id = $id");
    header("Location: index.php?status=4");
    exit;
}

/* =====================
   EDIT
===================== */
$edit = [];
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $edit = $conn->query("SELECT * FROM station WHERE id = $id")->fetch_assoc() ?: [];
}

/* =====================
   AJAX LIST
===================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_station') {
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = 10;

    $q = trim($_GET['q'] ?? '');

    $where = "WHERE 1 ";
    $params = [];
    $types = "";

    if ($q !== '') {
        $where .= "AND (station_name LIKE ? OR city LIKE ? OR state LIKE ?) ";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";
    }

    $sql = "SELECT * FROM station $where ORDER BY station_name ASC LIMIT $limit OFFSET $offset";
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
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Station Management</title>
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

        .station-tabs .nav-link {
            font-weight: 600;
            color: #166534;
        }

        .station-tabs .nav-link.active {
            background: #22c55e;
            color: #ffffff;
            border-color: #22c55e #22c55e #22c55e;
        }

        .station-tabs .nav-link:not(.active):hover {
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

        .station-actions {
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

            .station-tabs .nav-item {
                flex: 1 1 50%;
            }

            .station-tabs .nav-link {
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
            <?php if ($s == '1'): ?>showSave('Station saved successfully');
            <?php elseif ($s == '3'): ?>showUpdate('Station updated successfully');
            <?php elseif ($s == '4'): ?>showDelete('Station deleted successfully');
            <?php elseif ($s == '0'): ?>showWarning('All fields are required');
            <?php endif; ?>
            if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('status'); window.history.replaceState({}, document.title, u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash); }
        });</script>
    <?php endif; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 station-tabs" id="stationMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="station-form-tab" data-bs-toggle="tab" data-bs-target="#station-form-pane" type="button" role="tab" aria-controls="station-form-pane" aria-selected="true">
                    <i class="bi bi-geo-alt"></i> <?= isset($edit['id']) ? 'Update' : 'Create' ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="station-list-tab" data-bs-toggle="tab" data-bs-target="#station-list-pane" type="button" role="tab" aria-controls="station-list-pane" aria-selected="false">
                    <i class="bi bi-list"></i> Station List
                </button>
            </li>
        </ul>

        <div class="tab-content" id="stationMainTabsContent">
            <div class="tab-pane fade show active" id="station-form-pane" role="tabpanel" aria-labelledby="station-form-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body <?= isset($edit['id']) ? 'form-mode-update' : 'form-mode-create' ?>">

                                <h6 class="fw-bold mb-2">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <?= isset($edit['id']) ? 'Update Station' : 'Create Station' ?>
                                </h6>

                                <form method="post">
                                    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label>Station Name</label>
                                            <input type="text" name="station_name" class="form-control" value="<?= htmlspecialchars(capitalizeWords($edit['station_name'] ?? '')) ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label>City</label>
                                            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars(capitalizeWords($edit['city'] ?? '')) ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label>State</label>
                                            <input type="text" name="state" class="form-control" value="<?= htmlspecialchars(capitalizeWords($edit['state'] ?? '')) ?>" required>
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

            <div class="tab-pane fade" id="station-list-pane" role="tabpanel" aria-labelledby="station-list-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <input type="text" id="search_q" class="form-control form-control-sm mb-2" placeholder="Search station by name, city, state..." onkeyup="autoSearchStation()" autocomplete="off">

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="60">Sr</th>
                                                <th>Station</th>
                                                <th>City</th>
                                                <th>State</th>
                                                <th width="150">Action</th>
                                            </tr>
                                        </thead>

                                        <tbody id="stationTable"></tbody>
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
            const formTabButton = document.getElementById('station-form-tab');
            const listTabButton = document.getElementById('station-list-tab');

            if (view === 'list' && listTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(listTabButton).show();
            } else if (view === 'create' && formTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(formTabButton).show();
            }

            const tabButtons = document.querySelectorAll('#stationMainTabs [data-bs-toggle="tab"]');
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

        function autoSearchStation() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                offset = 0;
                document.getElementById('stationTable').innerHTML = '';
                document.getElementById('loadMoreBtn').disabled = false;
                document.getElementById('loadMoreBtn').innerText = 'Load More';
                loadMoreStation();
            }, 400);
        }

        function capitalizeWords(str) {
            return str.toLowerCase().split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        }

        function loadMoreStation() {
            const q = document.getElementById('search_q').value.trim();

            fetch(`?ajax=load_station&offset=${offset}&q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('stationTable');

                    if (data.length === 0 && offset === 0) {
                        tbody.innerHTML = `<tr><td colspan="5" class="text-center">No Data Found</td></tr>`;
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
                            <td>${escHtml(capitalizeWords(row.station_name))}</td>
                            <td>${escHtml(capitalizeWords(row.city))}</td>
                            <td>${escHtml(capitalizeWords(row.state))}</td>
                            <td>
                                <div class="station-actions">
                                    <a href="?edit=${row.id}" onclick="nmNavConfirm(event,'Edit this station?')" class="btn btn-warning btn-sm">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="?delete=${row.id}" onclick="nmNavConfirm(event,'Delete this station?')" class="btn btn-danger btn-sm">
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
            loadMoreStation();
        });

        document.getElementById('loadMoreBtn').addEventListener('click', loadMoreStation);
    </script>
</body>

</html>
