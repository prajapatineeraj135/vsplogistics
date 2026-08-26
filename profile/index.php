<?php
include "../protect/auth.php";

$company_id = (int) ($_SESSION['company_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM company WHERE id = ? LIMIT 1");
if (!$stmt) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Unable to load profile data</h3>";
    exit;
}

$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Company data not found</h3>";
    exit;
}

$company = $result->fetch_assoc();
$stmt->close();

function company_value(array $company, string $key): string
{
    return htmlspecialchars((string) ($company[$key] ?? ''));
}

function profile_database_name(mysqli $conn): string
{
    $result = $conn->query('SELECT DATABASE() AS db_name');
    if (!$result) {
        return '';
    }

    $row = $result->fetch_assoc();
    return (string) ($row['db_name'] ?? '');
}

$databaseName = profile_database_name($conn);
$backupFile = realpath(__DIR__ . '/../db/backup/db_backup.sql');
$backupExists = $backupFile !== false && is_file($backupFile);
$backupSize = $backupExists ? filesize($backupFile) : 0;
$backupUpdatedAt = $backupExists ? date('d-m-Y H:i:s', filemtime($backupFile)) : '';
$base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '..';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Company Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background: #f4f6f9;
        }

        .profile-wrap {
            max-width: 1540px;
            margin: 16px auto;
            padding: 0 12px 24px;
        }

        .profile-hero {
            background: #ecfdf3;
            color: #14532d;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .profile-hero h2 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
        }

        .profile-panel {
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .profile-tabs-wrap {
            padding: 0;
            border-bottom: 1px solid #dee2e6;
            background: transparent;
            margin-bottom: 12px;
        }

        .profile-tabs {
            border-bottom: 0;
        }

        .profile-tabs .nav-link {
            border-radius: 0;
            color: #166534;
            font-weight: 600;
            padding: 8px 12px;
        }

        .profile-tabs .nav-link:hover {
            border-color: #86efac #86efac #dee2e6;
            color: #15803d;
        }

        .profile-tabs .nav-link.active {
            background: #22c55e;
            border-color: #22c55e;
            color: #ffffff;
        }

        .tab-content {
            padding: 0;
        }

        .tab-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, .08);
        }

        .tab-card .card-header {
            background: #ecfdf3;
            color: #14532d;
            font-weight: 600;
            border-bottom: 1px solid #bbf7d0;
            border-radius: 10px 10px 0 0;
            padding: 9px 12px;
        }

        .tab-card .card-header i {
            margin-right: 6px;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 4px;
        }

        .card-body {
            padding: 12px;
        }

        .form-control[readonly] {
            background-color: #ffffff;
            border-radius: 6px;
            font-size: 14px;
            border-color: #d1d5db;
            padding: 6px;
            min-height: 36px;
            box-shadow: none;
        }

        .input-group-text {
            background: #f8fafc;
            border-radius: 6px 0 0 6px;
            border-color: #d1d5db;
            padding: 6px;
            min-height: 36px;
            color: #166534;
        }

        .database-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .database-action {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            padding: 12px;
            min-height: 100%;
        }

        .database-action h6 {
            color: #14532d;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .database-meta {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 12px;
            color: #334155;
            font-size: 14px;
        }

        .row.g-3 {
            --bs-gutter-x: .75rem;
            --bs-gutter-y: .55rem;
        }

        @media (max-width: 768px) {
            .profile-hero h2 {
                font-size: 0.95rem;
            }

            .profile-tabs .nav-item {
                flex: 1 1 50%;
            }

            .profile-tabs .nav-link {
                width: 100%;
                text-align: center;
                padding: 9px 6px;
            }

            .database-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include "../content/nav.php"; ?>

    <div class="profile-wrap">
        <div class="profile-hero">
            <h2><i class="bi bi-building me-2"></i>Company Profile - <?= company_value($company, 'company_name') ?></h2>
        </div>

        <div class="profile-panel">
            <div class="profile-tabs-wrap">
                <ul class="nav nav-tabs profile-tabs" id="profileTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="company-tab" data-bs-toggle="tab" data-bs-target="#company-pane" type="button" role="tab" aria-controls="company-pane" aria-selected="true"><i class="bi bi-building"></i> Company</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="owner-tab" data-bs-toggle="tab" data-bs-target="#owner-pane" type="button" role="tab" aria-controls="owner-pane" aria-selected="false"><i class="bi bi-person-badge"></i> Owner</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact-pane" type="button" role="tab" aria-controls="contact-pane" aria-selected="false"><i class="bi bi-telephone"></i> Contact</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="branch-tab" data-bs-toggle="tab" data-bs-target="#branch-pane" type="button" role="tab" aria-controls="branch-pane" aria-selected="false"><i class="bi bi-geo-alt"></i> Branch</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="bank-tab" data-bs-toggle="tab" data-bs-target="#bank-pane" type="button" role="tab" aria-controls="bank-pane" aria-selected="false"><i class="bi bi-bank"></i> Bank</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings-pane" type="button" role="tab" aria-controls="settings-pane" aria-selected="false"><i class="bi bi-printer"></i> Print</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="user-tab" data-bs-toggle="tab" data-bs-target="#user-pane" type="button" role="tab" aria-controls="user-pane" aria-selected="false"><i class="bi bi-person-gear"></i> User</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="database-tab" data-bs-toggle="tab" data-bs-target="#database-pane" type="button" role="tab" aria-controls="database-pane" aria-selected="false"><i class="bi bi-database-gear"></i> Database</button>
                    </li>
                </ul>
            </div>

            <div class="tab-content" id="profileTabContent">
                <div class="tab-pane fade show active" id="company-pane" role="tabpanel" aria-labelledby="company-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header header-company">
                            <h6 class="mb-0"><i class="bi bi-info-circle-fill"></i> Company Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Company Name</label>
                                    <input class="form-control" value="<?= company_value($company, 'company_name') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Legal Name</label>
                                    <input class="form-control" value="<?= company_value($company, 'legal_name') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Business Type</label>
                                    <input class="form-control" value="<?= company_value($company, 'business_type') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">GST / Transport ID</label>
                                    <input class="form-control" value="<?= company_value($company, 'gst_no') ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="owner-pane" role="tabpanel" aria-labelledby="owner-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header header-owner">
                            <h6 class="mb-0"><i class="bi bi-people-fill"></i> Owner & Management</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Owner Name</label>
                                    <input class="form-control" value="<?= company_value($company, 'owner_name') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Owner Contact</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                        <input class="form-control" value="<?= company_value($company, 'owner_phone') ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Manager Name</label>
                                    <input class="form-control" value="<?= company_value($company, 'manager_name') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Manager Contact</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                        <input class="form-control" value="<?= company_value($company, 'manager_phone') ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="contact-pane" role="tabpanel" aria-labelledby="contact-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header header-contact">
                            <h6 class="mb-0"><i class="bi bi-telephone-fill"></i> Contact & Online</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Primary Phone</label>
                                    <input class="form-control" value="<?= company_value($company, 'phone1') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Secondary Phone</label>
                                    <input class="form-control" value="<?= company_value($company, 'phone2') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">WhatsApp</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                                        <input class="form-control" value="<?= company_value($company, 'whatsapp') ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input class="form-control" value="<?= company_value($company, 'email') ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="branch-pane" role="tabpanel" aria-labelledby="branch-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header header-branch">
                            <h6 class="mb-0"><i class="bi bi-geo-alt-fill"></i> Branch & Address</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Branch Name</label>
                                    <input class="form-control" value="<?= company_value($company, 'branch') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Address Line 1</label>
                                    <input class="form-control" value="<?= company_value($company, 'address1') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Address Line 2</label>
                                    <input class="form-control" value="<?= company_value($company, 'address2') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Address Line 3</label>
                                    <input class="form-control" value="<?= company_value($company, 'address3') ?>" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Pincode</label>
                                    <input class="form-control" value="<?= company_value($company, 'pincode') ?>" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">City</label>
                                    <input class="form-control" value="<?= company_value($company, 'city') ?>" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">State</label>
                                    <input class="form-control" value="<?= company_value($company, 'state') ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="bank-pane" role="tabpanel" aria-labelledby="bank-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header header-bank">
                            <h6 class="mb-0"><i class="bi bi-bank"></i> Bank And UPI Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Bank Name</label>
                                    <input class="form-control" value="<?= company_value($company, 'bank_name') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Account Holder Name</label>
                                    <input class="form-control" value="<?= company_value($company, 'bank_account_name') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Account Number</label>
                                    <input class="form-control" value="<?= company_value($company, 'bank_account_number') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">IFSC Code</label>
                                    <input class="form-control" value="<?= company_value($company, 'bank_ifsc_code') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">UPI ID</label>
                                    <input class="form-control" value="<?= company_value($company, 'upi_id') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">UPI QR Path</label>
                                    <input class="form-control" value="<?= company_value($company, 'upi_qr_path') ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="settings-pane" role="tabpanel" aria-labelledby="settings-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header header-settings">
                            <h6 class="mb-0"><i class="bi bi-printer-fill"></i> Print And Page Setting</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Printer</label>
                                    <input class="form-control" value="Leser LQ-310" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Page Size</label>
                                    <input class="form-control" value="A4" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Download PDF</label>
                                    <input class="form-control" value="PDF" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="user-pane" role="tabpanel" aria-labelledby="user-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header">
                            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                <h6 class="mb-0"><i class="bi bi-person-gear"></i> Manage User</h6>
                                <button type="button" class="btn btn-success btn-sm" disabled>
                                    <i class="bi bi-person-plus"></i> Add User
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-2">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="60">Sr</th>
                                            <th>Company ID</th>
                                            <th>Company</th>
                                            <th>Username</th>
                                            <th>Password</th>
                                            <th>Session User</th>
                                            <th>Account Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>1</td>
                                            <td><?= company_value($company, 'id') ?></td>
                                            <td><?= company_value($company, 'company_name') ?></td>
                                            <td><?= company_value($company, 'username') ?></td>
                                            <td><?= company_value($company, 'pass') ?></td>
                                            <td><?= htmlspecialchars((string) ($_SESSION['username'] ?? '')) ?></td>
                                            <td>Company User</td>
                                            <td>Active</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="database-pane" role="tabpanel" aria-labelledby="database-tab" tabindex="0">
                    <div class="card tab-card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-database-fill-gear"></i> Database</h6>
                        </div>
                        <div class="card-body">
                            <div class="database-meta">
                                <div><strong>Database:</strong> <?= htmlspecialchars($databaseName ?: 'not selected') ?></div>
                                <div><strong>Backup File:</strong> db/backup/db_backup.sql</div>
                                <div><strong>Status:</strong> <?= $backupExists ? 'Available' : 'Missing' ?></div>
                                <?php if ($backupExists): ?>
                                    <div><strong>Size:</strong> <?= number_format((float) $backupSize / 1024, 1) ?> KB</div>
                                    <div><strong>Updated:</strong> <?= htmlspecialchars($backupUpdatedAt) ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="database-actions">
                                <div class="database-action">
                                    <h6><i class="bi bi-cloud-arrow-down"></i> Backup</h6>
                                    <p class="text-muted small mb-3">Create or update db_backup.sql from current database data.</p>
                                    <form method="post" action="<?= htmlspecialchars($base) ?>/db/backup/">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="bi bi-download"></i> Backup
                                        </button>
                                    </form>
                                </div>

                                <div class="database-action">
                                    <h6><i class="bi bi-arrow-counterclockwise"></i> Restore</h6>
                                    <p class="text-muted small mb-2">Restore tables and data from db_backup.sql.</p>
                                    <form method="post" action="<?= htmlspecialchars($base) ?>/db/restore/" onsubmit="return confirm('Restore database from db_backup.sql? Current data will be replaced.');">
                                        <input type="text" name="confirm_restore" class="form-control form-control-sm mb-2" autocomplete="off" placeholder="Type RESTORE">
                                        <button type="submit" class="btn btn-danger btn-sm" <?= $backupExists ? '' : 'disabled' ?>>
                                            <i class="bi bi-upload"></i> Restore
                                        </button>
                                    </form>
                                </div>

                                <div class="database-action">
                                    <h6><i class="bi bi-database-add"></i> Create If Not Exist</h6>
                                    <p class="text-muted small mb-3">Create missing database structure, tables, and columns.</p>
                                    <a href="<?= htmlspecialchars($base) ?>/db/" class="btn btn-success btn-sm">
                                        <i class="bi bi-plus-circle"></i> Create
                                    </a>
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
        document.addEventListener('DOMContentLoaded', function () {
            const params = new URLSearchParams(window.location.search);
            const activeTab = params.get('tab');

            if (activeTab) {
                const tabButton = document.querySelector(`#profileTab [data-bs-target="#${activeTab}-pane"]`);
                if (tabButton && window.bootstrap && window.bootstrap.Tab) {
                    new window.bootstrap.Tab(tabButton).show();
                }
            }

            document.querySelectorAll('#profileTab [data-bs-toggle="tab"]').forEach(function (tabButton) {
                tabButton.addEventListener('shown.bs.tab', function (event) {
                    if (!window.history.replaceState) {
                        return;
                    }

                    const target = event.target.getAttribute('data-bs-target') || '';
                    const tabName = target.replace('#', '').replace('-pane', '');
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', tabName);
                    window.history.replaceState({}, document.title, url.pathname + '?' + url.searchParams.toString() + url.hash);
                });
            });
        });
    </script>
</body>

</html>
