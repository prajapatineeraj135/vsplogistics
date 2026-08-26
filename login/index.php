<?php
session_start();
include "../protect/db.php";
include "../protect/session_manager.php";

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!empty($_SESSION['admin_login'])) {
    header("Location: ../");
    exit;
}

if (!empty($_SESSION['company_id'])) {
    header("Location: ../");
    exit;
}

// helper to detect development environment (local machine)
function isLocalhost(): bool {
    $host = $_SERVER['SERVER_NAME'] ?? '';
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

// if running locally, preload these values for easier testing
$default_username = isLocalhost() ? 'YFOF192' : '';
$default_password = isLocalhost() ? '851009' : '';

/* -------------------------
   RUN LOGIN ONLY ON SUBMIT
--------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        header('Location: index.php?err=1');
        exit;
    } else {

        $stmt = $conn->prepare("
            SELECT id, username, password 
            FROM company 
            WHERE username = ?
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {

                startFreshLoginSession();

                // LOGIN SUCCESS - Record device session
                $_SESSION['company_id'] = $row['id'];
                $_SESSION['username']   = $row['username'];
                $_SESSION['login_role']  = 'company';
                
                // Device-aware login: logs out admin if logged in on same device
                recordDeviceLogin('company', $row['id'], $conn);

                header("Location: ../");
                exit;
            }
        }

        header('Location: index.php?err=2');
        exit;
    }}
?>
<style>
  html,
body {
  height: 100%;
}

.form-signin {
  max-width: 330px;
  padding: 1rem;
}

.form-signin .form-floating:focus-within {
  z-index: 2;
}

.form-signin input[type="email"] {
  margin-bottom: -1px;
  border-bottom-right-radius: 0;
  border-bottom-left-radius: 0;
}

.form-signin input[type="password"] {
  margin-bottom: 10px;
  border-top-left-radius: 0;
  border-top-right-radius: 0;
}

</style>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Company Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= rtrim(BASE_URL, '/') ?>/content/notify.css">
  <script src="<?= rtrim(BASE_URL, '/') ?>/content/notify.js"></script>

  <style>
    body {
      background: linear-gradient(135deg, #0d6efd, #6610f2);
      min-height: 100vh;
      display: flex;
      justify-content: center;
      padding-top: 50px;
    }
    .login-card {
      border-radius: 15px;
      box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    }
    .form-control { border-radius: 10px; }
    .btn-login { border-radius: 10px; font-weight: 600; }
  </style>
</head>

<body>
<?php if (isset($_GET['err'])): ?>
<script>document.addEventListener('DOMContentLoaded', function () {
    <?php if ($_GET['err'] == '1'): ?>showWarning('Username and Password required');
    <?php elseif ($_GET['err'] == '2'): ?>showError('Invalid username or password');
    <?php endif; ?>
    if (window.history.replaceState) { var u = new URL(window.location.href); u.searchParams.delete('err'); window.history.replaceState({}, document.title, u.pathname + u.hash); }
});</script>
<?php endif; ?>

<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">

      <div class="card login-card">
        <div class="card-body p-4">

          <div class="text-center mb-4">
            <i class="bi bi-building fs-1 text-primary"></i>
            <h4 class="mt-2 fw-bold">Company Login</h4>
            <p class="text-muted">Login using your company credentials</p>
          </div>

          <form method="post">

            <div class="mb-3">
              <label class="form-label">Username</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input type="text" name="username" class="form-control"
                       placeholder="Enter username" value="<?=htmlspecialchars($default_username)?>" required> <!-- filled on localhost only -->
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label">Password</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>

                <input type="password" name="password" id="password"
                       class="form-control" placeholder="Enter password" value="<?=htmlspecialchars($default_password)?>" required>

                <span class="input-group-text" style="cursor:pointer"
                      onclick="togglePassword()">
                  <i class="bi bi-eye" id="eyeIcon"></i>
                </span>
              </div>
            </div>

            <button type="submit" id="loginButton" class="btn btn-primary w-100 btn-login">
              <i class="bi bi-box-arrow-in-right"></i> Login
            </button>
           
          </form>

          <div class="text-center mt-3">
            <small class="text-muted">© <?= date('Y') ?> Your Company</small><br>
             <a href="admin/" class="text-muted" style="text-decoration: none;" >By Neeraj</a>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
  const pwd = document.getElementById("password");
  const icon = document.getElementById("eyeIcon");
  if (pwd.type === "password") {
    pwd.type = "text";
    icon.classList.replace("bi-eye", "bi-eye-slash");
  } else {
    pwd.type = "password";
    icon.classList.replace("bi-eye-slash", "bi-eye");
  }
}

document.addEventListener('DOMContentLoaded', function () {
  const loginButton = document.getElementById('loginButton');
  if (loginButton) {
    loginButton.focus();
  }
});
</script>

</body>
</html>
