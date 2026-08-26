<?php
session_start();
include "../../protect/db.php";
include "../../protect/session_manager.php";

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!empty($_SESSION['company_id'])) {
    header("Location: ../../");
    exit;
}

if (!empty($_SESSION['admin_login'])) {
    header("Location: ../../");
    exit;
}

/* -------------------------
   FIXED ADMIN CREDENTIALS
--------------------------*/
define("ADMIN_USER", "neeraj");
define("ADMIN_PASS", "neeraj@123"); // change if needed

// helper for environment detection. on a local/dev box we want the
// form to be pre-filled with the test credentials. on the live server
// show empty values so users must type them manually.
function isLocalhost(): bool {
    $host = $_SERVER['SERVER_NAME'] ?? '';
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

$default_username = isLocalhost() ? ADMIN_USER : '';
$default_password = isLocalhost() ? ADMIN_PASS : '';

/* -------------------------
   LOGIN CHECK
--------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $username = $_POST['username'] ?? '';
  $password = $_POST['password'] ?? '';

  if ($username === ADMIN_USER && $password === ADMIN_PASS) {

    startFreshLoginSession();

    $defaultCompanyId = 0;
    $companyRes = $conn->query("SELECT id FROM company ORDER BY id ASC LIMIT 1");
    if ($companyRes && ($companyRow = $companyRes->fetch_assoc())) {
        $defaultCompanyId = (int) ($companyRow['id'] ?? 0);
    }

    $_SESSION['admin_login'] = true;
    if ($defaultCompanyId > 0) {
        $_SESSION['company_id'] = $defaultCompanyId;
    }
    $_SESSION['login_role'] = 'admin';
    
    // Device-aware login: logs out company user if logged in on same device
    recordDeviceLogin('admin', $username, $conn);

                header("Location: ../../");
                exit;
  } else {
    $error = "Invalid Admin Username or Password";
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Admin Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body {
      background: linear-gradient(135deg, #dc3545, #6610f2);
      min-height: 100vh;
      display: flex;
      justify-content: center;
      padding-top: 50px;
    }

    .login-card {
      border-radius: 15px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, .25);
    }

    .form-control {
      border-radius: 10px;
    }
  </style>
</head>

<body>

  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-4">

        <div class="card login-card">
          <div class="card-body p-4">

            <div class="text-center mb-4">
              <i class="bi bi-shield-lock fs-1 text-danger"></i>
              <h4 class="fw-bold">Admin Login</h4>
              <p class="text-muted">Authorized access only</p>
            </div>

            <?php if (!empty($error)) { ?>
              <div class="alert alert-danger text-center">
                <?= $error ?>
              </div>
            <?php } ?>

            <form method="post">

              <div class="mb-3">
                <label class="form-label">Admin Username</label>
                <input type="text" name="username" class="form-control" value="<?=htmlspecialchars($default_username)?>" required> <!-- filled only on localhost -->
              </div>

              <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                  <input type="password" name="password" id="password" value="<?=htmlspecialchars($default_password)?>" class="form-control" required><!-- filled only on localhost -->
                  <span class="input-group-text" onclick="togglePassword()" style="cursor:pointer">
                    <i class="bi bi-eye" id="eyeIcon"></i>
                  </span>
                </div>
              </div>

              <button type="submit" class="btn btn-danger w-100 fw-semibold">
                <i class="bi bi-box-arrow-in-right"></i> Login
              </button>

            </form>
  <div class="text-center mt-3">
            <small class="text-muted">© <?= date('Y') ?> Your Company</small><br>
             <a href="../" class="text-muted" style="text-decoration: none;" >By Neeraj</a>
          </div>

          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    function togglePassword() {
      const p = document.getElementById("password");
      const i = document.getElementById("eyeIcon");
      if (p.type === "password") {
        p.type = "text"; i.classList.replace("bi-eye", "bi-eye-slash");
      } else {
        p.type = "password"; i.classList.replace("bi-eye-slash", "bi-eye");
      }
    }
  </script>

</body>

</html>
