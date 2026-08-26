<?php

function ensureInwordDispatchColumns($conn) {
    static $done = false;
    if ($done) {
        return;
    }

    $col = $conn->query("SHOW COLUMNS FROM inword_biltys LIKE 'challan_id'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE inword_biltys ADD COLUMN challan_id INT(11) DEFAULT NULL AFTER inword_challan_id");
        $conn->query("ALTER TABLE inword_biltys ADD INDEX idx_inword_dispatch_challan_id (challan_id)");
    }

    $conn->query("ALTER TABLE inword_biltys MODIFY status ENUM('Booked','Dispatch','Received','Dispatched','Delivered','Trash') DEFAULT 'Booked'");
    $conn->query("UPDATE inword_biltys SET status = 'Booked' WHERE status = 'Received'");
    $conn->query("UPDATE inword_biltys SET status = 'Dispatch' WHERE status = 'Dispatched'");

    $done = true;
}

function splitDispatchBiltyIds($ids) {
    $normalIds = [];
    $inwordIds = [];

    foreach ((array)$ids as $rawId) {
        $rawId = trim((string)$rawId);
        if ($rawId === '') {
            continue;
        }

        if (strpos($rawId, ':') !== false) {
            [$type, $idValue] = explode(':', $rawId, 2);
            $id = (int)$idValue;
            if ($id <= 0) {
                continue;
            }

            if ($type === 'inword') {
                $inwordIds[] = $id;
            } else {
                $normalIds[] = $id;
            }
            continue;
        }

        $id = (int)$rawId;
        if ($id > 0) {
            $normalIds[] = $id;
        }
    }

    return [
        'normal' => array_values(array_unique($normalIds)),
        'inword' => array_values(array_unique($inwordIds))
    ];
}

function updateDispatchRows($conn, $table, $idColumn, $ids, $companyId, $setSql, $statusSql) {
    if (empty($ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE {$table} SET {$setSql} WHERE company_id = ? AND {$statusSql} AND {$idColumn} IN ({$placeholders})";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare {$table} update query");
    }

    $types = 's' . str_repeat('i', count($ids));
    $params = [$companyId];
    foreach ($ids as $id) {
        $params[] = $id;
    }

    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception("Failed to update {$table}: " . $stmt->error);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();
    return (int)$affected;
}
