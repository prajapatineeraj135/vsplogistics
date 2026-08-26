<?php
include "../protect/db.php";

/* =====================
   FETCH STATIONS
===================== */
$station_list = $conn->query("SELECT station_name, city, state FROM station ORDER BY station_name ASC");

/* =====================
   SAVE / UPDATE
===================== */
if (isset($_POST['save'])) {

    $id = $_POST['id'] ?? '';

    $party_type = strtolower(trim($_POST['party_type']));
    $bilty_type = strtolower(trim($_POST['bilty_type']));
    $name = strtolower(trim($_POST['name']));
    $contact = strtolower(trim($_POST['contact']));
    $station = strtolower(trim($_POST['station']));
    $address1 = strtolower(trim($_POST['address1']));
    $address2 = strtolower(trim($_POST['address2']));
    $pincode = strtolower(trim($_POST['pincode']));
    $city = strtolower(trim($_POST['city']));
    $state = strtolower(trim($_POST['state']));

    if ($id == '') {
        // INSERT
        $stmt = $conn->prepare("
            INSERT INTO party 
            (party_type,bilty_type, name, contact, station, address1, address2, pincode, city, state)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param(
            "ssssssssss",
            $party_type,
            $bilty_type,
            $name,
            $contact,
            $station,
            $address1,
            $address2,
            $pincode,
            $city,
            $state
        );
    } else {
        // UPDATE
        $stmt = $conn->prepare("
            UPDATE party SET
            party_type=?,bilty_type=?, name=?, contact=?, station=?,
            address1=?, address2=?, pincode=?, city=?, state=?
            WHERE id=?
        ");
        $stmt->bind_param(
            "ssssssssssi",
            $party_type,
            $bilty_type,
            $name,
            $contact,
            $station,
            $address1,
            $address2,
            $pincode,
            $city,
            $state,
            $id
        );
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
    $conn->query("DELETE FROM party WHERE id=$id");
    $conn->query("DELETE FROM party_products WHERE party_id=$id");
    header("Location: index.php?status=4&view=list");
    exit;
}

/* =====================
   EDIT
===================== */
$edit = [];
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $edit = $conn->query("SELECT * FROM party WHERE id=$id")->fetch_assoc();
}

/* =====================
   LIST
===================== */



/* ===== AJAX LOAD MORE PARTY ===== */
include "../protect/db.php";

/* ===============================
   AJAX : LOAD PARTY (AUTO SEARCH)
================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_party') {

    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = 10;

    $name = $_GET['name'] ?? '';
    $partyType = $_GET['party_type'] ?? '';

    $where = "WHERE 1 ";
    $params = [];
    $types = "";

    if ($name !== '') {
        $where .= "AND name LIKE ? ";
        $params[] = "%$name%";
        $types .= "s";
    }

    if ($partyType !== '') {
        $where .= "AND party_type = ? ";
        $params[] = $partyType;
        $types .= "s";
    }

    $sql = "SELECT * FROM party $where ORDER BY party_type ASC, bilty_type DESC, name ASC LIMIT $limit OFFSET $offset";
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
    <title>Party Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

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

        .form-mode-update {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
        }

        .party-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        @media (max-width: 767.98px) {
            body {
                height: auto;
            }

            .container-fluid {
                padding-left: 8px;
                padding-right: 8px;
            }

            .card-body {
                padding: 0.75rem;
            }

            .party-tabs .nav-item {
                flex: 1 1 50%;
            }

            .party-tabs .nav-link {
                width: 100%;
                text-align: center;
                font-size: 13px;
                padding: 0.6rem 0.4rem;
            }

            .table {
                font-size: 12px;
            }

            .party-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 4px;
                min-width: 110px;
            }

            .party-actions .btn {
                padding: 4px 6px;
            }
        }
    </style>
</head>

<body>

    <?php include "../content/nav.php"; ?>
    <?php $s = $_GET['status'] ?? '';
    if ($s === '1' || $s === '3' || $s === '4'): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            <?php if ($s === '1'): ?>showSave('Party saved successfully');
            <?php elseif ($s === '3'): ?>showUpdate('Party updated successfully');
            <?php elseif ($s === '4'): ?>showDelete('Party deleted successfully');
                <?php endif; ?>
                if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('status'); window.history.replaceState({}, document.title, u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash); }
            });</script>
    <?php endif; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 party-tabs" id="partyMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="party-form-tab" data-bs-toggle="tab"
                    data-bs-target="#party-form-pane" type="button" role="tab" aria-controls="party-form-pane"
                    aria-selected="true">
                    <i class="bi bi-person-plus"></i> <?= isset($edit['id']) ? 'Update' : 'Create' ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="party-list-tab" data-bs-toggle="tab" data-bs-target="#party-list-pane"
                    type="button" role="tab" aria-controls="party-list-pane" aria-selected="false">
                    <i class="bi bi-list"></i> Party List
                </button>
            </li>
        </ul>

        <div class="tab-content" id="partyMainTabsContent">
            <div class="tab-pane fade show active" id="party-form-pane" role="tabpanel"
                aria-labelledby="party-form-tab">
                <div class="row g-3">

                    <!-- ================= LEFT FORM ================= -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body <?= isset($edit['id']) ? 'form-mode-update' : 'form-mode-create' ?>">

                                <h6 class="fw-bold mb-2">
                                    <i class="bi bi-people"></i>
                                    <?= isset($edit['id']) ? 'Update Details' : 'Create Details' ?>
                                </h6>

                                <form method="post">
                                    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

                                    <div class="row g-2">

                                        <div class="col-md-4">
                                            <label>Party Type</label>
                                            <select name="party_type" class="form-select"
                                                onchange="toggleBiltyTypeVisibility(); autoSelectKotaForConsignor();"
                                                required>
                                                <option value="">Select</option>
                                                <option value="Consignor" <?= ($edit['party_type'] ?? '') == 'Consignor' ? 'selected' : '' ?>>Consignor</option>
                                                <option value="Consignee" <?= ($edit['party_type'] ?? '') == 'Consignee' ? 'selected' : '' ?>>Consignee</option>
                                            </select>

                                        </div>

                                        <div class="col-md-4">

                                            <label>Station</label>
                                            <select name="station" class="form-select"
                                                onchange="fillStationDetails(this)" required>
                                                <option value="">Select Station</option>
                                                <?php while ($s = $station_list->fetch_assoc()) { ?>
                                                    <option value="<?= htmlspecialchars($s['station_name']) ?>"
                                                        data-city="<?= htmlspecialchars($s['city']) ?>"
                                                        data-state="<?= htmlspecialchars($s['state']) ?>"
                                                        <?= (!empty($edit['station']) && $edit['station'] == $s['station_name']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($s['station_name']) ?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                        </div>


                                        <div class="col-md-6">
                                            <label>Name</label>
                                            <input type="text" name="name" class="form-control"
                                                value="<?= $edit['name'] ?? '' ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label>Contact</label>
                                            <input type="text" name="contact" class="form-control"
                                                value="<?= $edit['contact'] ?? '' ?>">
                                        </div>



                                        <div class="col-md-6">
                                            <label>Address 1</label>
                                            <input type="text" name="address1" class="form-control"
                                                value="<?= $edit['address1'] ?? '' ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label>Address 2</label>
                                            <input type="text" name="address2" class="form-control"
                                                value="<?= $edit['address2'] ?? '' ?>">
                                        </div>

                                        <div class="col-md-3">
                                            <label>Pincode</label>
                                            <input type="text" name="pincode" class="form-control"
                                                value="<?= $edit['pincode'] ?? '' ?>">
                                        </div>

                                        <div class="col-md-3">
                                            <label>City</label>
                                            <input type="text" name="city" class="form-control"
                                                value="<?= $edit['city'] ?? '' ?>">
                                        </div>

                                        <div class="col-md-3">
                                            <label>State</label>
                                            <input type="text" name="state" class="form-control"
                                                value="<?= $edit['state'] ?? '' ?>">
                                        </div>

                                    </div>
                                    <div class="col-md-4 d-none" id="biltyTypeWrap">

                                        <label>Bilty Type</label>
                                        <select name="bilty_type" class="form-select">
                                            <option value="topay" <?= (empty($edit['bilty_type']) || $edit['bilty_type'] == 'topay') ? 'selected' : '' ?>>Topay</option>
                                            <option value="cash" <?= ($edit['bilty_type'] ?? '') == 'cash' ? 'selected' : '' ?>>Cash</option>
                                            <option value="tbb" <?= ($edit['bilty_type'] ?? '') == 'tbb' ? 'selected' : '' ?>>TBB</option>

                                        </select>

                                    </div>

                                    <div class="mt-3">
                                        <button type="submit" name="save"
                                            class="btn <?= isset($edit['id']) ? 'btn-warning' : 'btn-success' ?> btn-sm">
                                            <i class="bi bi-save"></i> <?= isset($edit['id']) ? 'Update' : 'Save' ?>
                                        </button>

                                        <?php if (isset($edit['id'])) { ?>
                                            <a href="index.php?view=list" class="btn btn-danger btn-sm">Cancel</a>
                                            <a href="party_product.php?party_id=<?= $edit['id'] ?>"
                                                class="btn btn-primary btn-sm">Add Product</a>
                                        <?php } ?>
                                    </div>

                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= RIGHT LIST ================= -->
            <div class="tab-pane fade" id="party-list-pane" role="tabpanel" aria-labelledby="party-list-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">


                                <ul class="nav nav-pills mb-2 justify-content-center" id="partyTypeTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" type="button"
                                            data-party-type="Consignor">Consignor</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" type="button"
                                            data-party-type="Consignee">Consignee</button>
                                    </li>

                                </ul>
                                <div class="row g-2 mb-2">

                                    <input type="text" id="search_name" class="form-control form-control-sm mb-2"
                                        placeholder="Search party by name..." onkeyup="autoSearchName()"
                                        autocomplete="off">


                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="50">Sr</th>
                                                    <th width="150">Name</th>
                                                    <th width="100">Station</th>
                                                    <th width="100">Contact</th>
                                                    <th width="100">Type</th>
                                                    <th width="100">Bilty</th>
                                                    <th width="120">Action</th>
                                                </tr>
                                            </thead>

                                            <tbody id="partyTable">

                                            </tbody>

                                        </table>
                                    </div>
                                    <div class="text-center mt-2">
                                        <button id="loadMoreBtn" class="btn btn-primary btn-sm">
                                            Load More
                                        </button>
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
            function fillStationDetails(select) {
                const opt = select.options[select.selectedIndex];
                document.querySelector('[name="city"]').value = opt.dataset.city || '';
                document.querySelector('[name="state"]').value = opt.dataset.state || '';
            }

            function autoSelectKotaForConsignor() {
                const partyType = document.querySelector('[name="party_type"]');
                const stationSelect = document.querySelector('[name="station"]');

                if (!partyType || !stationSelect) {
                    return;
                }

                if (partyType.value === 'Consignor') {
                    const kotaOption = Array.from(stationSelect.options).find(function (opt) {
                        return (opt.value || '').toLowerCase() === 'kota';
                    });

                    if (kotaOption) {
                        stationSelect.value = kotaOption.value;
                        fillStationDetails(stationSelect);
                    }
                }
            }

            function toggleBiltyTypeVisibility() {
                const partyType = document.querySelector('[name="party_type"]');
                const biltyWrap = document.getElementById('biltyTypeWrap');
                const biltySelect = document.querySelector('[name="bilty_type"]');
                const partyIdInput = document.querySelector('[name="id"]');

                if (!partyType || !biltyWrap || !biltySelect || !partyIdInput) {
                    return;
                }

                const isCreateMode = !(partyIdInput.value || '').trim();

                if (partyType.value === 'Consignor') {
                    biltyWrap.classList.remove('d-none');
                    if (isCreateMode) {
                        biltySelect.value = 'topay';
                    } else if (!biltySelect.value) {
                        biltySelect.value = 'topay';
                    }
                } else {
                    biltyWrap.classList.add('d-none');
                }
            }

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

            document.addEventListener('DOMContentLoaded', function () {
                const view = new URLSearchParams(window.location.search).get('view');
                const formTabButton = document.getElementById('party-form-tab');
                const listTabButton = document.getElementById('party-list-tab');

                if (view === 'list' && listTabButton && window.bootstrap && window.bootstrap.Tab) {
                    new window.bootstrap.Tab(listTabButton).show();
                } else if (view === 'create' && formTabButton && window.bootstrap && window.bootstrap.Tab) {
                    new window.bootstrap.Tab(formTabButton).show();
                }

                toggleBiltyTypeVisibility();
                autoSelectKotaForConsignor();

                const tabButtons = document.querySelectorAll('#partyMainTabs [data-bs-toggle="tab"]');
                tabButtons.forEach(function (tabButton) {
                    tabButton.addEventListener('shown.bs.tab', function () {
                        clearViewParamFromUrl();
                    });
                });
            });
        </script>

        <!-- Script for load 10 Data List In Single PAge -->
        <script>
            let offset = 0;
            const limit = 10;
            let typingTimer;
            let activePartyType = 'Consignee';

            /* AUTO SEARCH WHEN USER TYPES */
            function autoSearchName() {
                clearTimeout(typingTimer);
                typingTimer = setTimeout(() => {
                    offset = 0;
                    document.getElementById('partyTable').innerHTML = '';
                    document.getElementById('loadMoreBtn').disabled = false;
                    document.getElementById('loadMoreBtn').innerText = 'Load More';
                    loadMoreParty();
                }, 400);
            }

            /* LOAD DATA (NORMAL OR SEARCHED) */
            function loadMoreParty() {

                const name = document.getElementById('search_name').value.trim();

                fetch(`?ajax=load_party&offset=${offset}&name=${encodeURIComponent(name)}&party_type=${encodeURIComponent(activePartyType)}`)
                    .then(res => res.json())
                    .then(data => {

                        const tbody = document.getElementById('partyTable');

                        if (data.length === 0 && offset === 0) {
                            tbody.innerHTML =
                                `<tr><td colspan="7" class="text-center">No Data Found</td></tr>`;
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
                    <td>${row.name}</td>
                    <td>${row.station}</td>
                    <td>${row.contact}</td>
                    <td>${row.party_type}</td>
                    <td>${row.bilty_type?.toUpperCase()}
</td>
                    <td>
                        <div class="party-actions">
                        
                       
                        <a href="view?party_id=${row.id}" class="btn btn-success btn-sm">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="party_product.php?party_id=${row.id}" class="btn btn-secondary btn-sm">
                            <i class="bi bi-box-seam"></i>
                        </a>
                         <a href="?edit=${row.id}" onclick="nmNavConfirm(event,'Edit This Party?')" class="btn btn-warning btn-sm">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="?delete=${row.id}" onclick="nmNavConfirm(event,'Delete This Party?')" class="btn btn-danger btn-sm">
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

            /* 🔥 DEFAULT LOAD (NORMAL RESULT) */
            document.addEventListener('DOMContentLoaded', function () {
                loadMoreParty(); // shows normal list by default

                const partyTypeTabs = document.querySelectorAll('#partyTypeTabs [data-party-type]');
                partyTypeTabs.forEach(function (tabBtn) {
                    tabBtn.addEventListener('click', function () {
                        partyTypeTabs.forEach(function (btn) {
                            btn.classList.remove('active');
                        });
                        tabBtn.classList.add('active');

                        activePartyType = tabBtn.getAttribute('data-party-type') || 'Consignee';
                        offset = 0;
                        document.getElementById('partyTable').innerHTML = '';
                        document.getElementById('loadMoreBtn').disabled = false;
                        document.getElementById('loadMoreBtn').innerText = 'Load More';
                        loadMoreParty();
                    });
                });
            });

            /* LOAD MORE */
            document.getElementById('loadMoreBtn').addEventListener('click', loadMoreParty);
        </script>





</body>

</html>