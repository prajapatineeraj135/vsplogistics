<?php
/**
 * Database restore utility.
 *
 * Restores from:
 *   company/db/backup/db_backup.sql
 */

ignore_user_abort(true);
set_time_limit(0);
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../../protect/db.php';

$backupFile = __DIR__ . '/../backup/db_backup.sql';
$messages = [];
$messageType = 'info';

function currentDatabaseName(mysqli $conn): string
{
    $result = $conn->query('SELECT DATABASE() AS db_name');
    if (!$result) {
        return '';
    }

    $row = $result->fetch_assoc();
    return (string) ($row['db_name'] ?? '');
}

function restoreDatabaseBackup(mysqli $conn, string $backupFile): array
{
    if (!is_file($backupFile)) {
        return [false, 'Backup file not found: db/backup/db_backup.sql'];
    }

    if (!is_readable($backupFile)) {
        return [false, 'Backup file is not readable: db/backup/db_backup.sql'];
    }

    $sql = file_get_contents($backupFile);
    if ($sql === false || trim($sql) === '') {
        return [false, 'Backup file is empty or cannot be read.'];
    }

    $conn->query('SET FOREIGN_KEY_CHECKS = 0');

    if (!$conn->multi_query($sql)) {
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
        return [false, 'Restore failed: ' . $conn->error];
    }

    while (true) {
        if ($result = $conn->store_result()) {
            $result->free();
        }

        if ($conn->errno) {
            $error = $conn->error;
            while ($conn->more_results() && $conn->next_result()) {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            }
            $conn->query('SET FOREIGN_KEY_CHECKS = 1');
            return [false, 'Restore failed: ' . $error];
        }

        if (!$conn->more_results()) {
            break;
        }

        if (!$conn->next_result()) {
            $error = $conn->error ?: 'Unknown SQL error while reading backup file.';
            $conn->query('SET FOREIGN_KEY_CHECKS = 1');
            return [false, 'Restore failed: ' . $error];
        }
    }

    $conn->query('SET FOREIGN_KEY_CHECKS = 1');

    return [true, 'Database recovered successfully from db/backup/db_backup.sql'];
}

$dbName = currentDatabaseName($conn);
$backupExists = is_file($backupFile);
$backupSize = $backupExists ? filesize($backupFile) : 0;
$backupUpdatedAt = $backupExists ? date('d-m-Y H:i:s', filemtime($backupFile)) : '';
$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST') {
    $confirmation = strtoupper(trim((string) ($_POST['confirm_restore'] ?? '')));
    if ($confirmation !== 'RESTORE') {
        $messageType = 'warning';
        $messages[] = 'Type RESTORE to confirm database recovery.';
    } else {
        [$ok, $message] = restoreDatabaseBackup($conn, $backupFile);
        $messageType = $ok ? 'success' : 'danger';
        $messages[] = $message;
    }

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
    <title>Database Restore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-1 text-danger">Database Restore</h3>
                <div class="text-muted">Database: <?= htmlspecialchars($dbName ?: 'not selected') ?></div>
            </div>
            <div class="d-flex gap-2">
                <a href="../backup/" class="btn btn-outline-primary btn-sm">Backup</a>
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

        <div class="card shadow-sm border-danger">
            <div class="card-body">
                <h5 class="card-title text-danger">Recover Data</h5>
                <p class="text-muted small">Restore database tables and data from db_backup.sql.</p>
                <div class="mb-3">
                    <div><strong>File:</strong> db/backup/db_backup.sql</div>
                    <div><strong>Status:</strong> <?= $backupExists ? 'Available' : 'Missing' ?></div>
                    <?php if ($backupExists): ?>
                        <div><strong>Size:</strong> <?= number_format((float) $backupSize / 1024, 1) ?> KB</div>
                        <div><strong>Updated:</strong> <?= htmlspecialchars($backupUpdatedAt) ?></div>
                    <?php endif; ?>
                </div>
                <div class="alert alert-warning small">
                    Recovery will replace current database tables with the backup file data.
                </div>
                <form method="post" onsubmit="return confirm('Recover database from db_backup.sql? Current data will be replaced.');">
                    <label class="form-label small">Type RESTORE to confirm</label>
                    <input type="text" name="confirm_restore" class="form-control mb-3" autocomplete="off" placeholder="RESTORE">
                    <button type="submit" class="btn btn-danger" <?= $backupExists ? '' : 'disabled' ?>>Recover Database</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
