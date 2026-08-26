<?php
include "../protect/db.php";

function companyColumnExists($conn, $column)
{
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SELECT COUNT(*) AS cnt
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'company'
              AND COLUMN_NAME = '{$columnEsc}'";
    $res = $conn->query($sql);
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    return (int) ($row['cnt'] ?? 0) > 0;
}

function ensureCompanyExtraSchema($conn)
{
    $columns = [
        'whatsapp' => "ALTER TABLE company ADD COLUMN whatsapp VARCHAR(20) DEFAULT NULL AFTER phone2",
        'bank_name' => "ALTER TABLE company ADD COLUMN bank_name VARCHAR(255) DEFAULT NULL AFTER email",
        'bank_account_name' => "ALTER TABLE company ADD COLUMN bank_account_name VARCHAR(255) DEFAULT NULL AFTER bank_name",
        'bank_account_number' => "ALTER TABLE company ADD COLUMN bank_account_number VARCHAR(100) DEFAULT NULL AFTER bank_account_name",
        'bank_ifsc_code' => "ALTER TABLE company ADD COLUMN bank_ifsc_code VARCHAR(50) DEFAULT NULL AFTER bank_account_number",
        'upi_id' => "ALTER TABLE company ADD COLUMN upi_id VARCHAR(150) DEFAULT NULL AFTER bank_ifsc_code",
        'upi_qr_path' => "ALTER TABLE company ADD COLUMN upi_qr_path VARCHAR(500) DEFAULT NULL AFTER upi_id",
    ];
    foreach ($columns as $column => $sql) {
        if (!companyColumnExists($conn, $column)) {
            $conn->query($sql);
        }
    }
}

ensureCompanyExtraSchema($conn);

if (isset($_POST['company_action']) && $_POST['company_action'] === 'save_credentials') {
    $id = (int) ($_POST['id'] ?? 0);
    $username = trim((string) ($_POST['username'] ?? ''));
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

    if ($id <= 0 || $username === '' || $newPassword === '' || $confirmPassword === '') {
        header('Location: index.php?err=credentials_required&edit=' . urlencode((string) $id));
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        header('Location: index.php?err=password_mismatch&edit=' . urlencode((string) $id));
        exit;
    }

    $dupStmt = $conn->prepare("SELECT id FROM company WHERE username = ? AND id <> ? LIMIT 1");
    $dupStmt->bind_param("si", $username, $id);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();
    if ($dupResult && $dupResult->num_rows > 0) {
        $dupStmt->close();
        header('Location: index.php?err=username_taken&edit=' . urlencode((string) $id));
        exit;
    }
    $dupStmt->close();

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE company SET username = ?, pass = ?, password = ? WHERE id = ?");
    $stmt->bind_param("sssi", $username, $newPassword, $hashed, $id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['company_flash_success'] = 'Username and password updated successfully.';
    header('Location: index.php?edit=' . urlencode((string) $id));
    exit;
}

if (isset($_POST['save'])) {
    $countRes = $conn->query("SELECT COUNT(*) AS total FROM company");
    $countRow = $countRes ? $countRes->fetch_assoc() : ['total' => 0];
    if (($countRow['total'] ?? 0) >= 3 && empty($_POST['id'])) {
        header('Location: index.php?err=max_company');
        exit;
    }

    $id = $_POST['id'] ?? '';
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
    $bankAccountName = trim((string) ($_POST['bank_account_name'] ?? ''));
    $bankAccountNumber = trim((string) ($_POST['bank_account_number'] ?? ''));
    $bankIfscCode = strtoupper(trim((string) ($_POST['bank_ifsc_code'] ?? '')));
    $upiId = trim((string) ($_POST['upi_id'] ?? ''));
    $upiQrPath = trim((string) ($_POST['upi_qr_path'] ?? ''));

    $data = [
        $_POST['company_name'] ?? '',
        $_POST['legal_name'] ?? '',
        $_POST['business_type'] ?? '',
        $_POST['gst_no'] ?? '',
        $_POST['owner_name'] ?? '',
        $_POST['owner_phone'] ?? '',
        $_POST['manager_name'] ?? '',
        $_POST['manager_phone'] ?? '',
        $_POST['phone1'] ?? '',
        $_POST['phone2'] ?? '',
        $_POST['whatsapp'] ?? '',
        $_POST['email'] ?? '',
        $bankName,
        $bankAccountName,
        $bankAccountNumber,
        $bankIfscCode,
        $upiId,
        $upiQrPath,
        $_POST['branch'] ?? '',
        $_POST['address1'] ?? '',
        $_POST['address2'] ?? '',
        $_POST['address3'] ?? '',
        $_POST['pincode'] ?? '',
        $_POST['city'] ?? '',
        $_POST['state'] ?? ''
    ];

    if ($id == "") {
        $res = $conn->query("SELECT MAX(id) AS max_id FROM company");
        $row = $res ? $res->fetch_assoc() : ['max_id' => null];
        $new_id = ($row['max_id'] === NULL) ? 101 : $row['max_id'] + 1;
        array_unshift($data, $new_id);

        $sql = "INSERT INTO company
        (id, company_name, legal_name, business_type, gst_no,
         owner_name, owner_phone, manager_name, manager_phone,
         phone1, phone2, whatsapp, email,
         bank_name, bank_account_name, bank_account_number, bank_ifsc_code, upi_id, upi_qr_path,
         branch, address1, address2, address3,
         pincode, city, state)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i" . str_repeat("s", count($data) - 1), ...$data);
        $stmt->execute();
        $stmt->close();
        createUserCredentials($conn, $new_id);
    } else {
        $data[] = $id;
        $sql = "UPDATE company SET
        company_name=?, legal_name=?, business_type=?, gst_no=?,
        owner_name=?, owner_phone=?, manager_name=?, manager_phone=?,
        phone1=?, phone2=?, whatsapp=?, email=?,
        bank_name=?, bank_account_name=?, bank_account_number=?, bank_ifsc_code=?, upi_id=?, upi_qr_path=?,
        branch=?, address1=?, address2=?, address3=?,
        pincode=?, city=?, state=?
        WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat("s", count($data) - 1) . "i", ...$data);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: index.php");
    exit;
}

if (isset($_GET['delete'])) {
    $delete_id = (int) $_GET['delete'];
    $countRes = $conn->query("SELECT COUNT(*) AS total FROM company");
    $countRow = $countRes ? $countRes->fetch_assoc() : ['total' => 0];
    if (($countRow['total'] ?? 0) <= 1) {
        header("Location: index.php?err=min_company");
        exit;
    }
    $conn->query("DELETE FROM company WHERE id=$delete_id");
    header("Location: index.php");
    exit;
}

$edit = [];
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $res = $conn->query("SELECT * FROM company WHERE id=$edit_id");
    $edit = $res ? ($res->fetch_assoc() ?: []) : [];
}

$list = $conn->query("SELECT * FROM company ORDER BY id asc");

function createUserCredentials($conn, $company_id)
{
    $check = $conn->prepare("SELECT username FROM company WHERE id = ? AND username IS NOT NULL");
    $check->bind_param("i", $company_id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        return;
    }
    $check->close();

    do {
        $username = generateUsername();
        $chk = $conn->prepare("SELECT id FROM company WHERE username = ?");
        $chk->bind_param("s", $username);
        $chk->execute();
        $chk->store_result();
    } while ($chk->num_rows > 0);
    $chk->close();

    $password = generatePassword();
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE company SET username=?, pass=?, password=? WHERE id=?");
    $stmt->bind_param("sssi", $username, $password, $hashed, $company_id);
    $stmt->execute();
    $stmt->close();
}

function generateUsername()
{
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $u = '';
    for ($i = 0; $i < 4; $i++) {
        $u .= $letters[random_int(0, 25)];
    }
    return $u . random_int(100, 999);
}

function generatePassword()
{
    return random_int(100000, 999999);
}

if (isset($_POST['resetpass'])) {
    $id = $_POST['id'] ?? '';
    if ($id == '') {
        echo "<p style='color:red;'>Invalid company selected.</p>";
        exit;
    }

    $check = $conn->prepare("SELECT username FROM company WHERE id = ? AND username IS NOT NULL");
    $check->bind_param("i", $id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows == 0) {
        echo "<p style='color:red;'>User not created yet. Cannot reset password.</p>";
        exit;
    }
    $check->close();

    $newPassword = random_int(100000, 999999);
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE company SET password = ?, pass = ? WHERE id = ?");
    $stmt->bind_param("ssi", $hashed, $newPassword, $id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['company_flash_success'] = 'Password reset successfully. New password: ' . $newPassword;
    header('Location: index.php?edit=' . urlencode((string) $id));
    exit;
}
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
        body { background: #f4f6f9; font-family: system-ui, -apple-system; }
        .company-wrap { max-width: 1540px; margin: 16px auto; padding: 0 12px 24px; }
        .company-hero { background: #ecfdf3; color: #14532d; border: 1px solid #bbf7d0; border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; }
        .company-hero h2 { margin: 0; font-size: 1rem; font-weight: 700; }
        .profile-panel { background: transparent; border: 0; box-shadow: none; }
        .tab-card { border: none; border-radius: 10px; box-shadow: 0 6px 15px rgba(0, 0, 0, .08); }
        .tab-card .card-header { background: #ecfdf3; color: #14532d; font-weight: 600; border-bottom: 1px solid #bbf7d0; border-radius: 10px 10px 0 0; padding: 9px 12px; }
        .tab-card .card-header i { margin-right: 6px; }
        .form-label { font-size: 14px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .card-body { padding: 12px; }
        .form-control, .input-group-text { min-height: 36px; border-color: #d1d5db; box-shadow: none; }
        .form-control { border-radius: 6px; font-size: 14px; }
        .form-control:focus { box-shadow: none; border-color: #22c55e; }
        .input-group-text { background: #f8fafc; border-radius: 6px 0 0 6px; color: #166534; padding: 6px 10px; }
        .required::after { content: " *"; color: red; }
        .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; color: #6c757d; margin: 10px 0 8px; }
        .company-actions .btn { min-width: 170px; }
        .company-list-card { border: none; border-radius: 10px; box-shadow: 0 6px 15px rgba(0,0,0,.08); overflow: hidden; }
        .company-list-card .card-header { background: #0f172a; color: #fff; font-weight: 600; border: 0; padding: 9px 12px; }
        .password-toggle {
            cursor: pointer;
            user-select: none;
        }
    </style>
</head>
<body>
    <?php include "../content/nav.php"; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'max_company'): ?>
        <script>document.addEventListener('DOMContentLoaded', function () { showWarning('Maximum 3 companies can be created.'); });</script>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'min_company'): ?>
        <script>document.addEventListener('DOMContentLoaded', function () { showWarning('At least 1 company must remain.'); });</script>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'password_mismatch'): ?>
        <script>document.addEventListener('DOMContentLoaded', function () { showWarning('New password and confirm password do not match.'); });</script>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'username_taken'): ?>
        <script>document.addEventListener('DOMContentLoaded', function () { showWarning('Username already exists. Please choose another username.'); });</script>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'credentials_required'): ?>
        <script>document.addEventListener('DOMContentLoaded', function () { showWarning('Please fill username and password fields.'); });</script>
    <?php endif; ?>
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'pwreset'): ?>
        <script>document.addEventListener('DOMContentLoaded', function () { showUpdate('Password reset successfully. New password: <?= htmlspecialchars($_GET["pw"] ?? "") ?>'); });</script>
    <?php endif; ?>
    <?php if (!empty($_SESSION['company_flash_success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showUpdate('<?= htmlspecialchars($_SESSION["company_flash_success"], ENT_QUOTES) ?>');
            });
        </script>
        <?php unset($_SESSION['company_flash_success']); ?>
    <?php endif; ?>

    <div class="company-wrap">
        <div class="company-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2><i class="bi bi-building me-2"></i>Company Profile - <?= htmlspecialchars($edit['company_name'] ?? 'Company List') ?></h2>
            <a class="btn btn-success btn-sm" href="?new=1">
                <i class="bi bi-plus-circle"></i> New Company
            </a>
        </div>

        <?php if (isset($edit['id']) || isset($_GET['new'])): ?>
        <form method="post" class="profile-panel mb-4">
            <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($edit['id'] ?? '')) ?>">
            <input type="hidden" name="company_action" id="company_action" value="">

            <div class="card tab-card mb-3">
                <div class="card-header"><i class="bi bi-info-circle-fill"></i> Company Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Company Name</label>
                            <input class="form-control" name="company_name" required value="<?= htmlspecialchars($edit['company_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Legal Name</label>
                            <input class="form-control" name="legal_name" required value="<?= htmlspecialchars($edit['legal_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">GST/ Transport ID</label>
                            <input class="form-control" name="gst_no" required pattern="[0-9A-Z]+" title="Capital A-Z character GST number" value="<?= htmlspecialchars($edit['gst_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Business Type</label>
                            <input class="form-control" name="business_type" value="<?= htmlspecialchars($edit['business_type'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card tab-card mb-3">
                <div class="card-header"><i class="bi bi-people-fill"></i> Owner & Management</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Owner Name</label>
                            <input class="form-control" name="owner_name" required value="<?= htmlspecialchars($edit['owner_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Owner Phone</label>
                            <input class="form-control" name="owner_phone" required pattern="[0-9]{10}" value="<?= htmlspecialchars($edit['owner_phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Manager Name</label>
                            <input class="form-control" name="manager_name" value="<?= htmlspecialchars($edit['manager_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Manager Phone</label>
                            <input class="form-control" name="manager_phone" pattern="[0-9]{10}" value="<?= htmlspecialchars($edit['manager_phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card tab-card mb-3">
                <div class="card-header"><i class="bi bi-telephone-fill"></i> Contact Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label required">Phone 1</label>
                            <input class="form-control" name="phone1" required pattern="[0-9]{10}" value="<?= htmlspecialchars($edit['phone1'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Phone 2</label>
                            <input class="form-control" name="phone2" value="<?= htmlspecialchars($edit['phone2'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">WhatsApp</label>
                            <input class="form-control" name="whatsapp" value="<?= htmlspecialchars($edit['whatsapp'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input class="form-control" name="email" type="email" value="<?= htmlspecialchars($edit['email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card tab-card mb-3">
                <div class="card-header"><i class="bi bi-geo-alt-fill"></i> Branch & Address</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label required">Branch</label>
                            <input class="form-control" name="branch" value="<?= htmlspecialchars($edit['branch'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">Pincode</label>
                            <input class="form-control" name="pincode" pattern="[0-9]{6}" value="<?= htmlspecialchars($edit['pincode'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">City</label>
                            <input class="form-control" name="city" value="<?= htmlspecialchars($edit['city'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">State</label>
                            <input class="form-control" name="state" value="<?= htmlspecialchars($edit['state'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Address Line 1</label>
                            <input class="form-control" name="address1" value="<?= htmlspecialchars($edit['address1'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Address Line 2</label>
                            <input class="form-control" name="address2" value="<?= htmlspecialchars($edit['address2'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Address Line 3</label>
                            <input class="form-control" name="address3" value="<?= htmlspecialchars($edit['address3'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card tab-card mb-3">
                <div class="card-header"><i class="bi bi-bank"></i> Bank / UPI Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Bank Name</label>
                            <input class="form-control" name="bank_name" value="<?= htmlspecialchars($edit['bank_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Account Holder Name</label>
                            <input class="form-control" name="bank_account_name" value="<?= htmlspecialchars($edit['bank_account_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Account Number</label>
                            <input class="form-control" name="bank_account_number" value="<?= htmlspecialchars($edit['bank_account_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IFSC Code</label>
                            <input class="form-control text-uppercase" name="bank_ifsc_code" value="<?= htmlspecialchars($edit['bank_ifsc_code'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">UPI ID</label>
                            <input class="form-control" name="upi_id" value="<?= htmlspecialchars($edit['upi_id'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">UPI QR Image Path</label>
                            <input class="form-control" name="upi_qr_path" value="<?= htmlspecialchars($edit['upi_qr_path'] ?? '') ?>" placeholder="uploads/qr/company-101.png">
                        </div>
                    </div>
                </div>
            </div>

            <?php if (isset($edit['id'])): ?>
            <div class="card tab-card mb-3">
                <div class="card-header"><i class="bi bi-shield-lock"></i> Login Credentials</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">Username</label>
                            <input class="form-control" name="username" value="<?= htmlspecialchars($edit['username'] ?? '') ?>" required autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">New Password</label>
                            <div class="input-group">
                                <input class="form-control" type="password" name="new_password" id="new_password" required autocomplete="new-password">
                                <span class="input-group-text password-toggle" onclick="togglePasswordField('new_password', 'new_password_eye')">
                                    <i class="bi bi-eye" id="new_password_eye"></i>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Confirm Password</label>
                            <div class="input-group">
                                <input class="form-control" type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password">
                                <span class="input-group-text password-toggle" onclick="togglePasswordField('confirm_password', 'confirm_password_eye')">
                                    <i class="bi bi-eye" id="confirm_password_eye"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary px-4" onclick="submitCompanyCredentials(this)">
                            <i class="bi bi-shield-lock"></i> Save Credentials
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button class="btn btn-success px-4" name="save" <?= isset($edit['id']) ? 'style="display:none"' : '' ?>><i class="bi bi-save"></i> Create Company</button>
                <button class="btn btn-success px-4" name="save" onclick="nmBtnConfirm(event,'Update this company?')" <?= !isset($edit['id']) ? 'style="display:none"' : '' ?>><i class="bi bi-save"></i> Update Company</button>
                <button class="btn btn-outline-success px-4" name="resetpass" onclick="nmBtnConfirm(event,'Reset Password for this company?')" <?= !isset($edit['id']) ? 'style="display:none"' : '' ?>><i class="bi bi-key"></i> Reset Password</button>
                <a class="btn btn-outline-secondary px-4" href="index.php">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
            </div>
        </form>
        <?php endif; ?>

        <div class="company-list-card">
            <div class="card-header"><i class="bi bi-list-ul"></i> Company List</div>
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>ID</th><th>Username</th><th>Password</th><th>Company</th><th>Branch</th><th>Phone</th><th width="150">Action</th></tr>
                </thead>
                <tbody>
                    <?php while ($row = $list->fetch_assoc()) { ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['username'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['pass'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['branch'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['phone1'] ?? '') ?></td>
                            <td>
                                <a href="?edit=<?= (int) $row['id'] ?>" class="btn btn-sm btn-warning" onclick="nmNavConfirm(event,'Edit this company?')"><i class="bi bi-pencil"></i></a>
                                <a href="?delete=<?= (int) $row['id'] ?>" onclick="nmNavConfirm(event,'Delete this company?')" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function submitCompanyCredentials(button) {
            const form = button ? button.form : null;
            if (!form) return;

            const actionField = document.getElementById('company_action');
            if (actionField) {
                actionField.value = 'save_credentials';
            }

            if (typeof window.nmConfirm !== 'function') {
                form.submit();
                return;
            }

            window.nmConfirm('Update username and password?').then(function (ok) {
                if (!ok) return;
                form.submit();
            });
        }

        function togglePasswordField(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    </script>
</body>
</html>
