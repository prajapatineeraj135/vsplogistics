<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once __DIR__ . '/../protect/config.php';

// Read the most recent centralized error payload set before redirect.
$errorData = $_SESSION['central_error'] ?? null;
unset($_SESSION['central_error']);

if (!is_array($errorData)) {
    $errorData = [
        'title' => 'No recent error found',
        'detail' => 'This page shows the latest captured application error.',
        'category' => 'server',
        'status_code' => 200,
        'time' => date('Y-m-d H:i:s'),
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'file' => '',
        'line' => '',
    ];
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Technical file/line details are shown only in debug mode.
$showTech = defined('APP_DEBUG') && APP_DEBUG;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application Error</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #f4f6ff 0%, #eef7f2 100%);
            color: #1f2937;
        }

        .wrap {
            max-width: 860px;
            margin: 40px auto;
            padding: 0 16px;
        }

        .card {
            background: #fff;
            border: 1px solid #dbe4f0;
            border-radius: 14px;
            box-shadow: 0 10px 25px rgba(28, 39, 60, 0.08);
            overflow: hidden;
        }

        .head {
            background: #0f3d8a;
            color: #fff;
            padding: 16px 20px;
        }

        .head h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }

        .meta {
            padding: 16px 20px;
            border-bottom: 1px solid #eef2f7;
            font-size: 14px;
            background: #fbfdff;
        }

        .grid {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 8px 14px;
            padding: 18px 20px;
            font-size: 14px;
        }

        .k {
            color: #4b5563;
            font-weight: 600;
        }

        .v {
            color: #111827;
            word-break: break-word;
        }

        .actions {
            display: flex;
            gap: 10px;
            padding: 0 20px 20px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid #0f3d8a;
            border-radius: 8px;
            padding: 8px 12px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            color: #0f3d8a;
            background: #fff;
        }

        .btn.primary {
            background: #0f3d8a;
            color: #fff;
        }

        @media (max-width: 640px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="head">
                <h1><?php echo e($errorData['title'] ?? 'Application Error'); ?></h1>
            </div>

            <div class="meta">
                Error details have been captured centrally for this request.
            </div>

            <div class="grid">
                <div class="k">Category</div>
                <div class="v"><?php echo e($errorData['category'] ?? 'server'); ?></div>

                <div class="k">Status Code</div>
                <div class="v"><?php echo e($errorData['status_code'] ?? 500); ?></div>

                <div class="k">Description</div>
                <div class="v"><?php echo e($errorData['detail'] ?? 'Unexpected error.'); ?></div>

                <div class="k">Time</div>
                <div class="v"><?php echo e($errorData['time'] ?? ''); ?></div>

                <div class="k">Request URI</div>
                <div class="v"><?php echo e($errorData['request_uri'] ?? ''); ?></div>

                <div class="k">Request Method</div>
                <div class="v"><?php echo e($errorData['request_method'] ?? ''); ?></div>

                <?php if ($showTech): ?>
                    <div class="k">File</div>
                    <div class="v"><?php echo e($errorData['file'] ?? ''); ?></div>

                    <div class="k">Line</div>
                    <div class="v"><?php echo e($errorData['line'] ?? ''); ?></div>
                <?php endif; ?>
            </div>

            <div class="actions">
                <a class="btn" href="javascript:history.back()">Go Back</a>
                <a class="btn primary" href="<?php echo e(base_url()); ?>">Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>
