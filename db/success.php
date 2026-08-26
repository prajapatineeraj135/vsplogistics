<?php
session_start();
$logs = $_SESSION['install_logs'] ?? [];
$hasError = false;
foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'error') {
        $hasError = true;
        break;
    }
}
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Install Logs</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f6f7fb;margin:0;padding:30px;color:#222}
        .box{max-width:1100px;margin:auto;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:24px}
        h1{margin-top:0}.ok{color:#0a7b34}.bad{color:#b00020}
        table{width:100%;border-collapse:collapse;margin-top:18px;font-size:14px}
        th,td{border-bottom:1px solid #eee;padding:10px;text-align:left;vertical-align:top}
        th{background:#fafafa}.success{color:#0a7b34}.skipped{color:#8a5a00}.error{color:#b00020}.info{color:#2454a6}
        .empty{padding:16px;background:#fff4d6;border-radius:8px;margin-top:18px}
    </style>
</head>
<body>
<div class="box">
    <h1 class="<?= $hasError ? 'bad' : 'ok' ?>"><?= $hasError ? 'Installer Completed With Errors' : 'Database Installer Success' ?></h1>
    <?php if (!$logs): ?>
        <div class="empty">No logs found. Please run <strong>install.php</strong> first.</div>
    <?php else: ?>
        <table>
            <thead><tr><th width="160">Time</th><th width="100">Type</th><th>Log</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= e($log['time'] ?? '') ?></td>
                    <td class="<?= e($log['type'] ?? '') ?>"><?= e(strtoupper($log['type'] ?? '')) ?></td>
                    <td><?= e($log['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
