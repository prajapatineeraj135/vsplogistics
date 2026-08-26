<?php
include "../../protect/auth.php";

$company_id = $_SESSION['company_id'] ?? '';

$stations = [];
$vehicles = [];
$agents = [];
$default_challan_no = '';

$stationResult = $conn->query("SELECT station_name FROM station ORDER BY station_name ASC");
if ($stationResult) {
    while ($row = $stationResult->fetch_assoc()) {
        $stations[] = $row;
    }
}

$stmtVehicles = $conn->prepare("SELECT vehicle_number, driver_name, mobile FROM vehicles WHERE company_id = ? ORDER BY vehicle_number ASC");
if ($stmtVehicles) {
    $stmtVehicles->bind_param("s", $company_id);
    $stmtVehicles->execute();
    $vehicleResult = $stmtVehicles->get_result();

    while ($row = $vehicleResult->fetch_assoc()) {
        $vehicles[] = $row;
    }

    $stmtVehicles->close();
}

$agentResult = $conn->query("SELECT station, agent_name, contact, commission_percent FROM agent ORDER BY station ASC, agent_name ASC");
if ($agentResult) {
    while ($row = $agentResult->fetch_assoc()) {
        $agents[] = $row;
    }
}

$companyPrefix = trim((string) $company_id);
if ($companyPrefix === '') {
    $companyPrefix = '0';
}

$nextSerial = 1001;
$stmtChallanNo = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(challan_no, '/', -1) AS UNSIGNED)) AS max_serial FROM challans WHERE company_id = ? AND challan_no LIKE CONCAT(?, '/%')");
if ($stmtChallanNo) {
    $stmtChallanNo->bind_param("ss", $company_id, $companyPrefix);
    if ($stmtChallanNo->execute()) {
        $challanNoResult = $stmtChallanNo->get_result();
        $challanNoRow = $challanNoResult ? $challanNoResult->fetch_assoc() : null;
        $currentMax = (int) ($challanNoRow['max_serial'] ?? 0);
        if ($currentMax > 0) {
            $nextSerial = $currentMax + 1;
        }
    }
    $stmtChallanNo->close();
}

$default_challan_no = $companyPrefix . '/' . $nextSerial;
?>




<table>
    <thead>
        <tr class="challan-head">
            <th><label for="challan-no">Challan No.</label></th>
            <th><label for="challan-date">Challan Date</label></th>
            <th><label for="challan-station">Station</label></th>
            <th><label for="challan-vehicle">Vehicle</label></th>
            <th><label for="challan-driver-name">Driver Name</label></th>
            <th><label for="challan-driver-contact">Driver Contact</label></th>
            <th><label for="challan-agent-name">Agent Name</label></th>
            <th><label for="challan-agent-contact">Agent Contact</label></th>
        </tr>
        <tr class="challan-head">
            <input type="text" id="challan-id" value="<?= (int) ($editChallanId ?? 0) ?>" hidden>
            <td class="challan-no-wrap">

                <input type="text" id="challan-no" value="<?= htmlspecialchars($default_challan_no) ?>"
                    data-auto-value="<?= htmlspecialchars($default_challan_no) ?>" readonly>
                <select id="challan-no-mode" class="challan-mode">
                    <option value="auto" selected>Auto</option>
                    <option value="manual">Manual</option>
                </select>

            </td>
            <td><input type="date" id="challan-date" tabindex="-1"></td>
            <td>
                <div class="suggest-box">
                    <input type="text" id="challan-station" autocomplete="off" required>
                    <ul id="station-suggestions" class="suggest-list"></ul>
                </div>
            </td>
            <td>
                <div class="suggest-box">
                    <input type="text" id="challan-vehicle" autocomplete="off" required>
                    <ul id="vehicle-suggestions" class="suggest-list"></ul>
                </div>
            </td>
            <td><input type="text" id="challan-driver" tabindex="-1" readonly></td>
            <td><input type="text" id="challan-contact" tabindex="-1" readonly></td>
            <td><input type="text" id="challan-agent-name" tabindex="-1" readonly></td>
            <td><input type="text" id="challan-agent-contact" tabindex="-1" readonly></td>
    </thead>
    <tbody>

    </tbody>
</table>

<script>
    window.challanHeaderData = {
        stationDetails: <?= json_encode($stations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        vehicleDetails: <?= json_encode($vehicles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        agentDetails: <?= json_encode($agents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
    };
</script>