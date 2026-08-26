<?php
/**
 * Database backup utility.
 *
 * Creates/updates:
 *   company/db/backup/db_backup.sql
 */

ignore_user_abort(true);
set_time_limit(0);
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../../protect/db.php';

$backupDir = __DIR__;
$backupFile = $backupDir . '/db_backup.sql';
$messages = [];
$messageType = 'info';

function q(mysqli $conn, string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function sqlValue(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . $conn->real_escape_string((string) $value) . "'";
}

function currentDatabaseName(mysqli $conn): string
{
    $result = $conn->query('SELECT DATABASE() AS db_name');
    if (!$result) {
        return '';
    }

    $row = $result->fetch_assoc();
    return (string) ($row['db_name'] ?? '');
}

function createDatabaseBackup(mysqli $conn, string $dbName, string $backupDir, string $backupFile): array
{
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $handle = fopen($backupFile, 'w');
    if (!$handle) {
        return [false, 'Cannot create/update backup file: ' . $backupFile];
    }

    fwrite($handle, "-- Database Backup\n");
    fwrite($handle, "-- Database: {$dbName}\n");
    fwrite($handle, "-- Updated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- File: db_backup.sql\n\n");
    fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
    fwrite($handle, "SET time_zone = \"+00:00\";\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tablesResult = $conn->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
    if (!$tablesResult) {
        fclose($handle);
        return [false, 'Cannot read tables: ' . $conn->error];
    }

    while ($row = $tablesResult->fetch_array()) {
        $table = $row[0];

        fwrite($handle, "\n-- --------------------------------------------------------\n");
        fwrite($handle, "-- Table structure for table {$table}\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");
        fwrite($handle, 'DROP TABLE IF EXISTS ' . q($conn, $table) . ";\n");

        $createResult = $conn->query('SHOW CREATE TABLE ' . q($conn, $table));
        if (!$createResult) {
            fwrite($handle, "-- Could not get structure for {$table}: " . $conn->error . "\n\n");
            continue;
        }

        $createRow = $createResult->fetch_assoc();
        fwrite($handle, $createRow['Create Table'] . ";\n\n");
        fwrite($handle, "-- Data for table {$table}\n\n");

        $dataResult = $conn->query('SELECT * FROM ' . q($conn, $table));
        if (!$dataResult || $dataResult->num_rows <= 0) {
            continue;
        }

        while ($dataRow = $dataResult->fetch_assoc()) {
            $columns = array_map(fn($col) => q($conn, $col), array_keys($dataRow));
            $values = array_map(fn($value) => sqlValue($conn, $value), array_values($dataRow));

            fwrite(
                $handle,
                'INSERT INTO ' . q($conn, $table) .
                ' (' . implode(', ', $columns) . ')' .
                ' VALUES (' . implode(', ', $values) . ");\n"
            );
        }

        fwrite($handle, "\n");
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($handle);

    return [true, 'Backup updated successfully: db/backup/db_backup.sql'];
}

$dbName = currentDatabaseName($conn);
$backupExists = is_file($backupFile);
$backupSize = $backupExists ? filesize($backupFile) : 0;
$backupUpdatedAt = $backupExists ? date('d-m-Y H:i:s', filemtime($backupFile)) : '';
$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST') {
    [$ok, $message] = createDatabaseBackup($conn, $dbName, $backupDir, $backupFile);
    $messageType = $ok ? 'success' : 'danger';
    $messages[] = $message;

    $backupExists = is_file($backupFile);
    $backupSize = $backupExists ? filesize($backupFile) : 0;
    $backupUpdatedAt = $backupExists ? date('d-m-Y H:i:s', filemtime($backupFile)) : '';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Backup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-1">Database Backup</h3>
                <div class="text-muted">Database: <?= htmlspecialchars($dbName ?: 'not selected') ?></div>
            </div>
            <div class="d-flex gap-2">
                <a href="../restore/" class="btn btn-outline-danger btn-sm">Restore</a>
                <a href="../../" class="btn btn-outline-secondary btn-sm">Dashboard</a>
            </div>
        </div>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
                <?php foreach ($messages as $message): ?>
                    <div><?= htmlspecialchars($message) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title">Backup File</h5>
                <p class="text-muted small">Create/update the recovery file from current database data.</p>
                <div class="mb-3">
                    <div><strong>File:</strong> db/backup/db_backup.sql</div>
                    <div><strong>Status:</strong> <?= $backupExists ? 'Available' : 'Missing' ?></div>
                    <?php if ($backupExists): ?>
                        <div><strong>Size:</strong> <?= number_format((float) $backupSize / 1024, 1) ?> KB</div>
                        <div><strong>Updated:</strong> <?= htmlspecialchars($backupUpdatedAt) ?></div>
                    <?php endif; ?>
                </div>
                <form method="post">
                    <button type="submit" class="btn btn-primary">Create Backup</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
