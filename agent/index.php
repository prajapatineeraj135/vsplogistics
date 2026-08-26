<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../protect/db.php";
include "../protect/case_converter.php";

function getSelectedStationName($conn, $station)
{
    $station = trim((string) $station);
    if ($station === '') {
        return '';
    }

    $stmt = $conn->prepare("SELECT station_name FROM station WHERE LOWER(station_name) = LOWER(?) LIMIT 1");
    $stmt->bind_param("s", $station);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return trim((string) ($row['station_name'] ?? ''));
}

function getSelectedStationNames($conn, $stationText)
{
    $parts = preg_split('/\s*,\s*/', trim((string) $stationText), -1, PREG_SPLIT_NO_EMPTY);
    $selected = [];
    $seen = [];

    foreach ($parts as $station) {
        $stationName = getSelectedStationName($conn, $station);
        if ($stationName === '') {
            return [];
        }

        $key = strtolower($stationName);
        if (!isset($seen[$key])) {
            $selected[] = $stationName;
            $seen[$key] = true;
        }
    }

    return $selected;
}

function getStationAssignedAgentName($conn, $selectedStations, $currentAgentId = 0)
{
    $selectedKeys = [];
    foreach ($selectedStations as $station) {
        $key = strtolower(trim((string) $station));
        if ($key !== '') {
            $selectedKeys[$key] = true;
        }
    }

    if (empty($selectedKeys)) {
        return '';
    }

    if ((int) $currentAgentId > 0) {
        $stmt = $conn->prepare("SELECT agent_name, station FROM agent WHERE id <> ?");
        $stmt->bind_param("i", $currentAgentId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query("SELECT agent_name, station FROM agent");
    }

    while ($row = $res->fetch_assoc()) {
        $agentName = trim((string) ($row['agent_name'] ?? ''));
        $stations = preg_split('/\s*,\s*/', trim((string) ($row['station'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($stations as $station) {
            if (isset($selectedKeys[strtolower(trim($station))])) {
                if (isset($stmt)) {
                    $stmt->close();
                }
                return $agentName;
            }
        }
    }

    if (isset($stmt)) {
        $stmt->close();
    }

    return '';
}

/* =========================
   SAVE / UPDATE
========================= */
if (isset($_POST['save'])) {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    $agent_name = strtolower(trim($_POST['agent_name'] ?? ''));
    $contact = preg_replace('/\D+/', '', trim($_POST['contact'] ?? ''));
    $station = trim($_POST['station'] ?? '');
    $address = strtolower(trim($_POST['address'] ?? ''));
    $commission_percent = (int) round((float) trim($_POST['commission_percent'] ?? ''));

    if ($agent_name === '' || $contact === '' || $station === '') {
        header('Location: index.php?err=1');
        exit;
    }

    if (!preg_match('/^\d{10}$/', $contact)) {
        header('Location: index.php?err=6');
        exit;
    }

    if ($commission_percent < 0 || $commission_percent > 100) {
        header('Location: index.php?err=2');
        exit;
    }

    $selectedStations = getSelectedStationNames($conn, $station);
    if (empty($selectedStations)) {
        header('Location: index.php?err=5');
        exit;
    }

    $assignedAgentName = getStationAssignedAgentName($conn, $selectedStations, $id);
    if ($assignedAgentName !== '') {
        header('Location: index.php?err=7&agent=' . urlencode($assignedAgentName));
        exit;
    }

    $station = strtolower(implode(', ', $selectedStations));

    if ($id <= 0) {
        $stmt = $conn->prepare("INSERT INTO agent (agent_name, contact, station, address, commission_percent) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $agent_name, $contact, $station, $address, $commission_percent);
    } else {
        $stmt = $conn->prepare("UPDATE agent SET agent_name=?, contact=?, station=?, address=?, commission_percent=? WHERE id=?");
        $stmt->bind_param("ssssii", $agent_name, $contact, $station, $address, $commission_percent, $id);
    }

    $stmt->execute();
    $stmt->close();

    header("Location: index.php?status=" . ($id <= 0 ? "1" : "3") . "&view=list");
    exit;
}

/* =========================
   DELETE
========================= */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM agent WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: index.php?status=4&view=list");
    exit;
}

/* =========================
   EDIT
========================= */
$edit = [];
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM agent WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

$stationOptionsList = [];
$stationResult = $conn->query("SELECT station_name FROM station ORDER BY station_name ASC");
if ($stationResult) {
    while ($stationRow = $stationResult->fetch_assoc()) {
        $stationName = trim((string) ($stationRow['station_name'] ?? ''));
        if ($stationName !== '') {
            $stationOptionsList[] = $stationName;
        }
    }
}

$assignedStationList = [];
$assignedStationSeen = [];
$assignedStationAgentMap = [];
$currentEditAgentId = isset($edit['id']) ? (int) $edit['id'] : 0;
if ($currentEditAgentId > 0) {
    $assignedStmt = $conn->prepare("SELECT agent_name, station FROM agent WHERE id <> ?");
    $assignedStmt->bind_param("i", $currentEditAgentId);
    $assignedStmt->execute();
    $assignedResult = $assignedStmt->get_result();
} else {
    $assignedResult = $conn->query("SELECT agent_name, station FROM agent");
}

if ($assignedResult) {
    while ($assignedRow = $assignedResult->fetch_assoc()) {
        $agentName = trim((string) ($assignedRow['agent_name'] ?? ''));
        $stations = preg_split('/\s*,\s*/', trim((string) ($assignedRow['station'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($stations as $stationName) {
            $stationName = trim($stationName);
            $key = strtolower($stationName);
            if ($stationName !== '' && !isset($assignedStationSeen[$key])) {
                $assignedStationList[] = $stationName;
                $assignedStationAgentMap[$key] = capitalizeWords($agentName);
                $assignedStationSeen[$key] = true;
            }
        }
    }
}
if (isset($assignedStmt)) {
    $assignedStmt->close();
}

/* =========================
   AJAX LIST
========================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_agent') {
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = 10;
    $q = trim($_GET['q'] ?? '');

    $where = "WHERE 1 ";
    $params = [];
    $types = "";

    if ($q !== '') {
        $where .= "AND (agent_name LIKE ? OR contact LIKE ? OR station LIKE ? OR address LIKE ?) ";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "ssss";
    }

    $sql = "SELECT id, agent_name, contact, station, address, commission_percent FROM agent $where ORDER BY agent_name ASC LIMIT $limit OFFSET $offset";
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
    <title>Agent Management</title>
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

        .agent-tabs .nav-link {
            font-weight: 600;
            color: #166534;
        }

        .agent-tabs .nav-link.active {
            background: #22c55e;
            color: #ffffff;
            border-color: #22c55e #22c55e #22c55e;
        }

        .agent-tabs .nav-link:not(.active):hover {
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

        .agent-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .station-picker {
            position: relative;
        }

        .station-float {
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            right: 0;
            max-height: 220px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #000;
            box-shadow: 0 6px 14px rgba(0, 0, 0, .16);
            display: none;
            z-index: 10000;
        }

        .station-float .float-item {
            padding: 6px 8px;
            cursor: pointer;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }

        .station-float .float-item:hover,
        .station-float .float-item.active {
            background: #cce5ff;
            font-weight: 700;
        }

        .station-float .float-item.assigned {
            cursor: not-allowed;
            background: #f8fafc;
            color: #6b7280;
        }

        .station-float .float-item.assigned:hover,
        .station-float .float-item.assigned.active {
            background: #f1f5f9;
            font-weight: 400;
        }

        .station-result-main {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .station-result-agent {
            color: #dc2626;
            font-size: 12px;
            white-space: nowrap;
        }

        .station-empty {
            padding: 6px 8px;
            color: #6b7280;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .station-empty .btn {
            flex: 0 0 auto;
            padding: 3px 8px;
            font-size: 12px;
        }

        .station-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
            min-height: 24px;
        }

        .station-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 7px;
            border: 1px solid #86efac;
            background: #dcfce7;
            color: #166534;
            font-size: 12px;
            font-weight: 600;
        }

        .station-tag button {
            border: 0;
            background: transparent;
            color: #166534;
            font-weight: 700;
            line-height: 1;
            padding: 0;
        }

        @media (max-width: 767.98px) {
            .container-fluid {
                padding-left: 8px;
                padding-right: 8px;
            }

            .card-body {
                padding: 0.75rem;
            }

            .agent-tabs .nav-item {
                flex: 1 1 50%;
            }

            .agent-tabs .nav-link {
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

    <?php if (isset($_GET['err'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            <?php $assignedAgentMessage = isset($_GET['agent']) ? capitalizeWords($_GET['agent']) : ''; ?>
            <?php if ($_GET['err'] == '1'): ?>showWarning('All fields are required');
            <?php elseif ($_GET['err'] == '2'): ?>showWarning('Commission must be between 0 and 100');
            <?php elseif ($_GET['err'] == '5'): ?>showWarning('Please select station from list');
            <?php elseif ($_GET['err'] == '6'): ?>showWarning('Contact must be 10 digit number');
            <?php elseif ($_GET['err'] == '7'): ?>showWarning(<?= json_encode('Station already selected by ' . ($assignedAgentMessage !== '' ? $assignedAgentMessage : 'another agent'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
            <?php endif; ?>
            if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('err'); u.searchParams.delete('agent'); window.history.replaceState({}, document.title, u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash); }
        });</script>
    <?php endif; ?>

    <?php if (isset($_GET['status'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            <?php $s = $_GET['status']; ?>
            <?php if ($s == '1'): ?>showSave('Agent saved successfully');
            <?php elseif ($s == '3'): ?>showUpdate('Agent updated successfully');
            <?php elseif ($s == '4'): ?>showDelete('Agent deleted successfully');
            <?php endif; ?>
            if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('status'); window.history.replaceState({}, document.title, u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash); }
        });</script>
    <?php endif; ?>

    <div class="container-fluid my-3">
        <ul class="nav nav-tabs mb-3 agent-tabs" id="agentMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="agent-form-tab" data-bs-toggle="tab" data-bs-target="#agent-form-pane" type="button" role="tab" aria-controls="agent-form-pane" aria-selected="true">
                    <i class="bi bi-person-plus"></i> <?= isset($edit['id']) ? 'Update' : 'Create' ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="agent-list-tab" data-bs-toggle="tab" data-bs-target="#agent-list-pane" type="button" role="tab" aria-controls="agent-list-pane" aria-selected="false">
                    <i class="bi bi-search"></i> Agent Search
                </button>
            </li>
        </ul>

        <div class="tab-content" id="agentMainTabsContent">
            <div class="tab-pane fade show active" id="agent-form-pane" role="tabpanel" aria-labelledby="agent-form-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body <?= isset($edit['id']) ? 'form-mode-update' : 'form-mode-create' ?>">
                                <h6 class="fw-bold mb-2">
                                    <i class="bi bi-person-vcard"></i>
                                    <?= isset($edit['id']) ? 'Update Agent' : 'Create Agent' ?>
                                </h6>

                                <form method="post" id="agentForm" autocomplete="off">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($edit['id'] ?? '')) ?>">

                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label>Agent Name</label>
                                            <input type="text" name="agent_name" id="agentNameInput" class="form-control" autocomplete="off" value="<?= htmlspecialchars(capitalizeWords($edit['agent_name'] ?? '')) ?>" required>
                                        </div>

                                        <div class="col-md-3">
                                            <label>Contact</label>
                                            <input type="text" name="contact" id="contactInput" class="form-control" autocomplete="off" inputmode="numeric" pattern="^\d{10}$" maxlength="10" value="<?= htmlspecialchars((string) ($edit['contact'] ?? '')) ?>" required>
                                        </div>

                                        <div class="col-md-3 station-picker">
                                            <label>Station</label>
                                            <input type="hidden" name="station" id="stationSelectedInput" value="<?= htmlspecialchars(capitalizeWords($edit['station'] ?? '')) ?>">
                                            <input type="text" id="stationInput" class="form-control" autocomplete="off" placeholder="Search station">
                                            <div id="stationFloat" class="station-float"></div>
                                            <div id="stationTags" class="station-tags"></div>
                                        </div>

                                        <div class="col-md-3">
                                            <label>Commission (%)</label>
                                            <div class="input-group">
                                                <input type="text" name="commission_percent" class="form-control" autocomplete="off" inputmode="numeric" pattern="^\d{1,3}$" maxlength="3" value="<?= isset($edit['commission_percent']) ? (int) round((float) $edit['commission_percent']) : '' ?>" required>
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label>Address</label>
                                            <textarea name="address" class="form-control" rows="2" autocomplete="off" required><?= htmlspecialchars(capitalizeWords($edit['address'] ?? '')) ?></textarea>
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

            <div class="tab-pane fade" id="agent-list-pane" role="tabpanel" aria-labelledby="agent-list-tab">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-2">
                                <input type="text" id="search_q" class="form-control form-control-sm mb-2" placeholder="Search agent by name, contact, station, address..." onkeyup="autoSearchAgent()" autocomplete="off">

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="60">Sr</th>
                                                <th>Name</th>
                                                <th>Contact</th>
                                                <th>Station</th>
                                                <th>Address</th>
                                                <th>Commission</th>
                                                <th width="150">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="agentTable"></tbody>
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
        const stationInput = document.getElementById('stationInput');
        const stationSelectedInput = document.getElementById('stationSelectedInput');
        const stationTags = document.getElementById('stationTags');
        const stationFloat = document.getElementById('stationFloat');
        const agentForm = document.getElementById('agentForm');
        const contactInput = document.getElementById('contactInput');
        const stationMaster = <?= json_encode(array_values(array_map('capitalizeWords', $stationOptionsList)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const assignedStationMaster = <?= json_encode(array_values(array_map('capitalizeWords', $assignedStationList)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const assignedStationAgents = <?= json_encode($assignedStationAgentMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const stationCreateUrl = <?= json_encode(BASE_URL . '/station?view=create', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        let stationOptions = new Map();
        let assignedStationOptions = new Set();
        let stationResults = [];
        let stationIndex = -1;
        let selectedStations = [];

        if (contactInput) {
            contactInput.addEventListener('input', () => {
                contactInput.value = contactInput.value.replace(/\D/g, '').slice(0, 10);
            });
        }

        if (stationInput && stationSelectedInput && stationTags && stationFloat) {
            stationMaster.forEach(station => {
                const value = String(station || '').trim();
                if (value) stationOptions.set(value.toLowerCase(), value);
            });
            assignedStationMaster.forEach(station => {
                const value = String(station || '').trim();
                if (value) assignedStationOptions.add(value.toLowerCase());
            });

            const getAssignedAgentName = station => {
                return assignedStationAgents[String(station || '').trim().toLowerCase()] || 'another agent';
            };

            const syncSelectedStationInput = () => {
                stationSelectedInput.value = selectedStations.join(', ');
            };

            const renderStationTags = () => {
                stationTags.innerHTML = '';
                selectedStations.forEach((station, index) => {
                    const tag = document.createElement('span');
                    tag.className = 'station-tag';
                    const text = document.createElement('span');
                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.setAttribute('aria-label', 'Remove station');
                    removeBtn.textContent = 'x';
                    text.textContent = station;
                    tag.appendChild(text);
                    tag.appendChild(removeBtn);
                    removeBtn.addEventListener('click', () => {
                        selectedStations.splice(index, 1);
                        syncSelectedStationInput();
                        renderStationTags();
                        stationInput.focus();
                    });
                    stationTags.appendChild(tag);
                });
            };

            const addSelectedStation = station => {
                const selected = stationOptions.get(String(station || '').trim().toLowerCase());
                if (!selected) return false;

                if (assignedStationOptions.has(selected.toLowerCase())) {
                    showWarning(`Station already selected by ${getAssignedAgentName(selected)}`);
                    stationInput.value = '';
                    hideStationList();
                    return false;
                }

                if (!selectedStations.some(item => item.toLowerCase() === selected.toLowerCase())) {
                    selectedStations.push(selected);
                    syncSelectedStationInput();
                    renderStationTags();
                }

                stationInput.value = '';
                hideStationList();
                return true;
            };

            const hideStationList = () => {
                stationFloat.style.display = 'none';
                stationIndex = -1;
            };

            const highlightStation = () => {
                const items = stationFloat.querySelectorAll('.float-item');
                items.forEach(item => item.classList.remove('active'));
                if (stationIndex >= 0 && items[stationIndex]) {
                    items[stationIndex].classList.add('active');
                    items[stationIndex].scrollIntoView({ block: 'nearest' });
                }
            };

            const moveStationIndex = direction => {
                if (!stationResults.length) return;
                let nextIndex = stationIndex;

                for (let i = 0; i < stationResults.length; i++) {
                    nextIndex += direction;
                    if (nextIndex < 0) nextIndex = stationResults.length - 1;
                    if (nextIndex >= stationResults.length) nextIndex = 0;
                    if (!stationResults[nextIndex].assigned) {
                        stationIndex = nextIndex;
                        highlightStation();
                        return;
                    }
                }
            };

            const selectStation = index => {
                const result = stationResults[index];
                if (!result || result.assigned) return false;
                return addSelectedStation(result.station);
            };

            stationSelectedInput.value.split(',')
                .map(station => station.trim())
                .filter(Boolean)
                .forEach(station => addSelectedStation(station));

            const showStationList = () => {
                const query = stationInput.value.trim();
                if (query.length < 1) {
                    stationResults = [];
                    stationFloat.innerHTML = '';
                    hideStationList();
                    return;
                }

                const lowered = query.toLowerCase();
                stationResults = stationMaster
                    .filter(station => station.toLowerCase().includes(lowered))
                    .filter(station => !selectedStations.some(selected => selected.toLowerCase() === station.toLowerCase()))
                    .map(station => ({
                        station,
                        assigned: assignedStationOptions.has(station.toLowerCase()),
                        agent: getAssignedAgentName(station)
                    }))
                    .slice(0, 15);

                stationFloat.innerHTML = '';
                stationIndex = stationResults.findIndex(result => !result.assigned);

                if (!stationResults.length) {
                    stationFloat.innerHTML = `<div class="station-empty"><span>No station found</span><a href="${stationCreateUrl}" class="btn btn-success btn-sm">Add</a></div>`;
                    stationFloat.style.display = 'block';
                    return;
                }

                stationResults.forEach((result, index) => {
                    const item = document.createElement('div');
                    item.className = `float-item${result.assigned ? ' assigned' : ''}`;
                    item.innerHTML = result.assigned
                        ? `<div class="station-result-main"><span>${escHtml(result.station)}</span><span class="station-result-agent">${escHtml(result.agent)}</span></div>`
                        : escHtml(result.station);
                    item.addEventListener('mousedown', event => {
                        event.preventDefault();
                        if (result.assigned) {
                            showWarning(`Station already selected by ${result.agent}`);
                            return;
                        }
                        selectStation(index);
                    });
                    stationFloat.appendChild(item);
                });

                stationFloat.style.display = 'block';
                highlightStation();
            };

            stationInput.addEventListener('input', showStationList);
            stationInput.addEventListener('focus', () => {
                if (stationInput.value.trim().length > 0) {
                    showStationList();
                }
            });
            stationInput.addEventListener('blur', () => {
                setTimeout(() => {
                    addSelectedStation(stationInput.value);
                    hideStationList();
                }, 120);
            });
            stationInput.addEventListener('keydown', event => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (stationFloat.style.display !== 'block') showStationList();
                    if (!stationResults.length) return;
                    moveStationIndex(1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (stationFloat.style.display !== 'block') showStationList();
                    if (!stationResults.length) return;
                    moveStationIndex(-1);
                } else if (event.key === 'Enter') {
                    if (stationFloat.style.display === 'block' && stationIndex >= 0) {
                        event.preventDefault();
                        selectStation(stationIndex);
                    } else {
                        event.preventDefault();
                        addSelectedStation(stationInput.value);
                    }
                } else if (event.key === 'Escape') {
                    hideStationList();
                }
            });

            document.addEventListener('click', event => {
                if (!event.target.closest('.station-picker')) {
                    hideStationList();
                }
            });

            if (agentForm) {
                agentForm.addEventListener('submit', event => {
                    if (contactInput && !/^\d{10}$/.test(contactInput.value.trim())) {
                        event.preventDefault();
                        showWarning('Contact must be 10 digit number');
                        contactInput.focus();
                        return;
                    }

                    const pendingStation = stationInput.value.trim();
                    if (pendingStation !== '' && !addSelectedStation(pendingStation)) {
                        event.preventDefault();
                        showWarning('Please select available station from list');
                        stationInput.focus();
                        return;
                    }

                    if (selectedStations.length === 0) {
                        event.preventDefault();
                        showWarning('Please select at least one station from list');
                        stationInput.focus();
                    }
                });
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
            const formTabButton = document.getElementById('agent-form-tab');
            const listTabButton = document.getElementById('agent-list-tab');
            const agentNameInput = document.getElementById('agentNameInput');

            if (view === 'list' && listTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(listTabButton).show();
            } else if (view === 'create' && formTabButton && window.bootstrap && window.bootstrap.Tab) {
                new window.bootstrap.Tab(formTabButton).show();
                if (agentNameInput) agentNameInput.focus();
            } else if (!view && agentNameInput && !agentNameInput.value.trim()) {
                agentNameInput.focus();
            }

            const tabButtons = document.querySelectorAll('#agentMainTabs [data-bs-toggle="tab"]');
            tabButtons.forEach(function (tabButton) {
                tabButton.addEventListener('shown.bs.tab', function () {
                    clearViewParamFromUrl();
                });
            });
        });

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
            return String(str ?? '').toLowerCase().split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        }

        function resetAgentList() {
            offset = 0;
            document.getElementById('agentTable').innerHTML = '';
            document.getElementById('loadMoreBtn').disabled = false;
            document.getElementById('loadMoreBtn').innerText = 'Load More';
            loadMoreAgent();
        }

        function autoSearchAgent() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(resetAgentList, 400);
        }

        function loadMoreAgent() {
            const q = document.getElementById('search_q').value.trim();

            fetch(`?ajax=load_agent&offset=${offset}&q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('agentTable');

                    if (data.length === 0 && offset === 0) {
                        tbody.innerHTML = `<tr><td colspan="7" class="text-center">No Data Found</td></tr>`;
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
                            <td>${escHtml(capitalizeWords(row.agent_name))}</td>
                            <td>${escHtml(capitalizeWords(row.contact))}</td>
                            <td>${escHtml(capitalizeWords(row.station))}</td>
                            <td>${escHtml(capitalizeWords(row.address))}</td>
                            <td>${parseInt(row.commission_percent ?? 0, 10) || 0}%</td>
                            <td>
                                <div class="agent-actions">
                                    <a href="?edit=${row.id}" onclick="nmNavConfirm(event,'Edit this agent?')" class="btn btn-warning btn-sm">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="?delete=${row.id}" onclick="nmNavConfirm(event,'Delete this agent?')" class="btn btn-danger btn-sm">
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

        document.addEventListener('DOMContentLoaded', function () {
            loadMoreAgent();
            document.getElementById('loadMoreBtn').addEventListener('click', loadMoreAgent);
        });
    </script>
</body>

</html>
