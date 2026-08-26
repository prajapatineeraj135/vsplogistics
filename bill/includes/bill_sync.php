<?php

function billColumnExists($conn, $table, $column)
{
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tableEsc}' AND COLUMN_NAME = '{$columnEsc}'";
    $res = $conn->query($sql);
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    return (int) ($row['cnt'] ?? 0) > 0;
}

function ensureBillsSchema($conn)
{
    $conn->query("CREATE TABLE IF NOT EXISTS bills (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT DEFAULT NULL,
        bill_number VARCHAR(80) NOT NULL,
        bill_date DATE NOT NULL,
        party_id INT DEFAULT NULL,
        party_name VARCHAR(255) NOT NULL,
        bill_month CHAR(7) DEFAULT NULL,
        bill_type VARCHAR(30) DEFAULT 'AUTO_TBB',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(50) NOT NULL DEFAULT 'Pending',
        remarks VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_bill_number (bill_number),
        KEY idx_company_id (company_id),
        KEY idx_bill_month (bill_month),
        KEY idx_party_id (party_id),
        KEY idx_bill_type (bill_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!billColumnExists($conn, 'bills', 'company_id')) {
        $conn->query("ALTER TABLE bills ADD COLUMN company_id INT DEFAULT NULL AFTER id");
    }
    if (!billColumnExists($conn, 'bills', 'party_id')) {
        $conn->query("ALTER TABLE bills ADD COLUMN party_id INT DEFAULT NULL AFTER bill_date");
    }
    if (!billColumnExists($conn, 'bills', 'bill_month')) {
        $conn->query("ALTER TABLE bills ADD COLUMN bill_month CHAR(7) DEFAULT NULL AFTER party_name");
    }
    if (!billColumnExists($conn, 'bills', 'bill_type')) {
        $conn->query("ALTER TABLE bills ADD COLUMN bill_type VARCHAR(30) DEFAULT 'AUTO_TBB' AFTER bill_month");
    }
    if (!billColumnExists($conn, 'bills', 'total_bilty')) {
        $conn->query("ALTER TABLE bills ADD COLUMN total_bilty INT NOT NULL DEFAULT 0 AFTER amount");
    }
    if (!billColumnExists($conn, 'bills', 'total_nag')) {
        $conn->query("ALTER TABLE bills ADD COLUMN total_nag DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_bilty");
    }
    if (!billColumnExists($conn, 'bills', 'period_start')) {
        $conn->query("ALTER TABLE bills ADD COLUMN period_start DATETIME DEFAULT NULL AFTER bill_type");
    }
    if (!billColumnExists($conn, 'bills', 'period_end')) {
        $conn->query("ALTER TABLE bills ADD COLUMN period_end DATETIME DEFAULT NULL AFTER period_start");
    }
    if (!billColumnExists($conn, 'bills', 'completed_at')) {
        $conn->query("ALTER TABLE bills ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER period_end");
    }
}

function getLastMonthRange()
{
    $firstDayLastMonth = date('Y-m-01', strtotime('first day of last month'));
    $lastDayLastMonth = date('Y-m-t', strtotime($firstDayLastMonth));
    return [$firstDayLastMonth, $lastDayLastMonth];
}

function billMonthFromDate($date)
{
    return date('Y-m', strtotime($date));
}

function getLastCompletedMonthKey()
{
    return date('Y-m', strtotime('first day of last month'));
}

function getCurrentMonthKey()
{
    return date('Y-m');
}

function getMonthRangeFromKey($monthKey)
{
    $start = $monthKey . '-01 00:00:00';
    $end = date('Y-m-t 23:59:59', strtotime($monthKey . '-01'));
    return [$start, $end];
}

function getNextMonthKey($monthKey)
{
    return date('Y-m', strtotime($monthKey . '-01 +1 month'));
}

function getEarliestTbbMonthKey($conn, $companyId = null)
{
        $sql = "SELECT MIN(bilty_date) AS min_date
                        FROM biltys
                        WHERE payment_type = 'TBB'
                            AND status IN ('Booked', 'Dispatch')";

    if ($companyId !== null) {
        $sql .= ' AND company_id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $row = $conn->query($sql)->fetch_assoc();
    }

    $minDate = trim((string) ($row['min_date'] ?? ''));
    if ($minDate === '') {
        return null;
    }

    return date('Y-m', strtotime($minDate));
}

function getNextCompanyBillNumber($conn, $companyId)
{
    $prefix = (string) ((int) $companyId) . '/';
    $like = $prefix . '%';

    $sql = "SELECT bill_number FROM bills WHERE company_id = ? AND bill_number LIKE ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $companyId, $like);
    $stmt->execute();
    $res = $stmt->get_result();

    $maxSerial = 1200;
    while ($row = $res->fetch_assoc()) {
        $billNo = (string) ($row['bill_number'] ?? '');
        if (strpos($billNo, $prefix) !== 0) {
            continue;
        }
        $serialPart = substr($billNo, strlen($prefix));
        if (ctype_digit($serialPart)) {
            $num = (int) $serialPart;
            if ($num > $maxSerial) {
                $maxSerial = $num;
            }
        }
    }
    $stmt->close();

    return $prefix . (string) ($maxSerial + 1);
}

function syncAutoBillForPartyMonth($conn, $companyId, $monthKey, $partyId, $partyName, $periodEndDateTime = null)
{
    if ((int) $companyId <= 0 || trim($monthKey) === '' || trim($partyName) === '') {
        return;
    }

    $monthStart = $monthKey . '-01 00:00:00';
    $start = $monthStart;
    $end = trim((string) $periodEndDateTime);
    if ($end === '') {
        $end = date('Y-m-t 23:59:59', strtotime($monthKey . '-01'));
    }

    $lastCompletedSql = "SELECT period_end FROM bills
                         WHERE company_id = ?
                           AND bill_month = ?
                           AND bill_type = 'AUTO_TBB'
                           AND party_name = ?
                           AND completed_at IS NOT NULL
                           AND period_end IS NOT NULL
                         ORDER BY period_end DESC
                         LIMIT 1";
    $lastCompletedStmt = $conn->prepare($lastCompletedSql);
    $lastCompletedStmt->bind_param('iss', $companyId, $monthKey, $partyName);
    $lastCompletedStmt->execute();
    $lastCompleted = $lastCompletedStmt->get_result()->fetch_assoc();
    $lastCompletedStmt->close();

    $lastCompletedEnd = trim((string) ($lastCompleted['period_end'] ?? ''));
    if ($lastCompletedEnd !== '') {
        $nextStart = date('Y-m-d H:i:s', strtotime($lastCompletedEnd . ' +1 second'));
        if (strtotime($nextStart) > strtotime($monthStart)) {
            $start = $nextStart;
        }
    }

    if (strtotime($start) > strtotime($end)) {
        return;
    }

    if ((int) $partyId > 0) {
        $sumSql = "SELECT COALESCE(SUM(freight), 0) AS total_freight,
                          COALESCE(SUM(total_qty), 0) AS total_nag,
                          COUNT(*) AS total_bilty
                   FROM biltys
                   WHERE company_id = ?
                     AND payment_type = 'TBB'
                                         AND status IN ('Booked', 'Dispatch')
                     AND bilty_date BETWEEN ? AND ?
                     AND consignor_id = ?";
        $sumStmt = $conn->prepare($sumSql);
        $sumStmt->bind_param('issi', $companyId, $start, $end, $partyId);
    } else {
        $sumSql = "SELECT COALESCE(SUM(freight), 0) AS total_freight,
                          COALESCE(SUM(total_qty), 0) AS total_nag,
                          COUNT(*) AS total_bilty
                   FROM biltys
                   WHERE company_id = ?
                     AND payment_type = 'TBB'
                                         AND status IN ('Booked', 'Dispatch')
                     AND bilty_date BETWEEN ? AND ?
                     AND consignor_name = ?";
        $sumStmt = $conn->prepare($sumSql);
        $sumStmt->bind_param('isss', $companyId, $start, $end, $partyName);
    }

    $sumStmt->execute();
    $sumRes = $sumStmt->get_result()->fetch_assoc();
    $sumStmt->close();

    $totalFreight = (int) round((float) ($sumRes['total_freight'] ?? 0));
    $totalNag     = (int) round((float) ($sumRes['total_nag'] ?? 0));
    $totalBilty   = (int)   ($sumRes['total_bilty'] ?? 0);

    $findSql = "SELECT id FROM bills WHERE company_id = ? AND bill_month = ? AND bill_type = 'AUTO_TBB' AND party_name = ? AND completed_at IS NULL LIMIT 1";
    $findStmt = $conn->prepare($findSql);
    $findStmt->bind_param('iss', $companyId, $monthKey, $partyName);
    $findStmt->execute();
    $existing = $findStmt->get_result()->fetch_assoc();
    $findStmt->close();

    if ($totalFreight <= 0) {
        if (!empty($existing['id'])) {
            $delStmt = $conn->prepare('DELETE FROM bills WHERE id = ?');
            $delStmt->bind_param('i', $existing['id']);
            $delStmt->execute();
            $delStmt->close();
        }
        return;
    }

    $billDate = date('Y-m-d', strtotime($end));

    if (!empty($existing['id'])) {
        // Keep bill number stable once created; only recalculate amount/date.
        $existingBillNumber = null;
        $numStmt = $conn->prepare('SELECT bill_number FROM bills WHERE id = ? LIMIT 1');
        $existingId = (int) $existing['id'];
        $numStmt->bind_param('i', $existingId);
        $numStmt->execute();
        $numRow = $numStmt->get_result()->fetch_assoc();
        $numStmt->close();
        $existingBillNumber = trim((string) ($numRow['bill_number'] ?? ''));
        if ($existingBillNumber === '') {
            $existingBillNumber = getNextCompanyBillNumber($conn, $companyId);
        }

        $updSql = "UPDATE bills
                   SET bill_number = ?, bill_date = ?, party_id = ?, period_start = ?, period_end = ?, amount = ?, total_bilty = ?, total_nag = ?, updated_at = NOW()
                   WHERE id = ?";
        $updStmt = $conn->prepare($updSql);
        $updStmt->bind_param('ssissdidi', $existingBillNumber, $billDate, $partyId, $start, $end, $totalFreight, $totalBilty, $totalNag, $existingId);
        $updStmt->execute();
        $updStmt->close();
    } else {
        $newBillNumber = getNextCompanyBillNumber($conn, $companyId);
        $insSql = "INSERT INTO bills (company_id, bill_number, bill_date, party_id, party_name, bill_month, bill_type, period_start, period_end, amount, total_bilty, total_nag, status, remarks)
                   VALUES (?, ?, ?, ?, ?, ?, 'AUTO_TBB', ?, ?, ?, ?, ?, 'Pending', 'Auto-generated from TBB bilty freight')";
        $insStmt = $conn->prepare($insSql);
        $insStmt->bind_param('ississssdid', $companyId, $newBillNumber, $billDate, $partyId, $partyName, $monthKey, $start, $end, $totalFreight, $totalBilty, $totalNag);
        $insStmt->execute();
        $insStmt->close();
    }
}

function generateAutoBillsByMonth($conn, $companyId = null)
{
    ensureBillsSchema($conn);

    $targetMonthKey = getCurrentMonthKey();
    $earliestMonthKey = getEarliestTbbMonthKey($conn, $companyId);

    if ($earliestMonthKey === null) {
        return [
            'month_key' => $targetMonthKey,
            'groups_synced' => 0,
            'months_synced' => 0,
            'month_start' => $targetMonthKey . '-01',
            'month_end' => date('Y-m-d'),
        ];
    }

    if (strtotime($earliestMonthKey . '-01') > strtotime($targetMonthKey . '-01')) {
        return [
            'month_key' => $targetMonthKey,
            'groups_synced' => 0,
            'months_synced' => 0,
            'month_start' => $targetMonthKey . '-01',
            'month_end' => date('Y-m-d'),
        ];
    }

    $groupsSynced = 0;
    $monthsSynced = 0;
    $monthCursor = $earliestMonthKey;

    while (strtotime($monthCursor . '-01') <= strtotime($targetMonthKey . '-01')) {
        [$startDateTime, $endDateTime] = getMonthRangeFromKey($monthCursor);
        $isCurrentMonth = ($monthCursor === $targetMonthKey);
        $effectiveEndDateTime = $isCurrentMonth ? date('Y-m-d 23:59:59') : $endDateTime;

        $sql = "SELECT company_id, consignor_id, consignor_name, COALESCE(SUM(freight), 0) AS total_freight
                FROM biltys
                WHERE payment_type = 'TBB'
                                    AND status IN ('Booked', 'Dispatch')
                  AND bilty_date BETWEEN ? AND ?";

        $params = [$startDateTime, $effectiveEndDateTime];
        $types = 'ss';

        if ($companyId !== null) {
            $sql .= ' AND company_id = ?';
            $params[] = (int) $companyId;
            $types .= 'i';
        }

        $sql .= ' GROUP BY company_id, consignor_id, consignor_name';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $cid = (int) ($row['company_id'] ?? 0);
            $pid = (int) ($row['consignor_id'] ?? 0);
            $pname = trim((string) ($row['consignor_name'] ?? ''));

            if ($cid > 0 && $pname !== '') {
                syncAutoBillForPartyMonth($conn, $cid, $monthCursor, $pid, $pname, $effectiveEndDateTime);
                $groupsSynced++;
            }
        }

        $stmt->close();

        $monthsSynced++;
        $monthCursor = getNextMonthKey($monthCursor);
    }

    return [
        'month_key' => $targetMonthKey,
        'groups_synced' => $groupsSynced,
        'months_synced' => $monthsSynced,
        'month_start' => $earliestMonthKey . '-01',
        'month_end' => date('Y-m-d'),
    ];
}

function generateLastMonthBills($conn, $companyId = null)
{
    // Backward-compatible wrapper: sync all pending months up to current month.
    return generateAutoBillsByMonth($conn, $companyId);
}
