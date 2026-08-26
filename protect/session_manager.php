<?php
/**
 * Session Manager
 * Enforces a single active login role per browser session.
 * If a user logs in as admin, company login state is cleared, and vice versa.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('startFreshLoginSession')) {
    function startFreshLoginSession() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);
        session_unset();
    }
}

if (!function_exists('clearLoginState')) {
    function clearLoginState() {
        unset(
            $_SESSION['admin_login'],
            $_SESSION['company_id'],
            $_SESSION['username'],
            $_SESSION['device_id'],
            $_SESSION['user_type'],
            $_SESSION['login_role']
        );
    }
}

if (!function_exists('destroyCurrentSession')) {
    function destroyCurrentSession() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        clearLoginState();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}

/**
 * Get unique device identifier (IP + User Agent hash)
 * This identifies the current device/browser
 */
if (!function_exists('getDeviceId')) {
    function getDeviceId() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        return md5($ip . $ua);
    }
}

/**
 * Get current session info
 */
if (!function_exists('getSessionInfo')) {
    function getSessionInfo() {
        return [
            'device_id' => getDeviceId(),
            'user_type' => null,  // 'admin' or 'company'
            'user_id'  => null,   // company_id or admin username
            'timestamp' => time()
        ];
    }
}

/**
 * Record user login with device tracking
 * Logs out the other role if it was logged in on this device
 * 
 * @param string $userType - 'admin' or 'company'
 * @param mixed $userId - username (admin) or company_id (company)
 * @param mysqli $conn - database connection
 */
if (!function_exists('recordDeviceLogin')) {
    function recordDeviceLogin($userType, $userId, $conn) {
        $deviceId = getDeviceId();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

    // Check if different role is logged in on this device
    $stmt = $conn->prepare("
        SELECT id, user_type, user_id 
        FROM device_sessions 
        WHERE device_id = ? 
        ORDER BY login_time DESC 
        LIMIT 1
    ");
    $stmt->bind_param("s", $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Different user type logged in on same device - force logout
        if ($row['user_type'] !== $userType) {
            // Mark the session as logged out
            $stmt2 = $conn->prepare("
                UPDATE device_sessions 
                SET logout_time = NOW() 
                WHERE id = ?
            ");
            $stmt2->bind_param("i", $row['id']);
            $stmt2->execute();
            $stmt2->close();
            
            // Keep current session clean if the other role was active before.
            // Fresh login handlers already start a new session, so we only
            // invalidate the previous device-session record here.
        }
    }
    $stmt->close();
    
    // Insert new login record
    $stmt = $conn->prepare("
        INSERT INTO device_sessions 
        (device_id, user_type, user_id, login_time, ip_address, user_agent) 
        VALUES (?, ?, ?, NOW(), ?, ?)
    ");
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt->bind_param(
        "ssiss",
        $deviceId,
        $userType,
        $userId,
        $ip,
        $ua
    );
    $stmt->execute();
    $stmt->close();
    
    // Store device info in session without wiping the newly created login state
        $_SESSION['device_id'] = $deviceId;
        $_SESSION['user_type'] = $userType;
        $_SESSION['login_role'] = $userType;
    }
}

/**
 * Check if user is logged in and validate device consistency
 * If different user type is active on this device, force logout
 * 
 * @param string $expectedUserType - 'admin' or 'company'
 * @param mysqli $conn - database connection
 * @return bool - true if valid, false if logged out
 */
if (!function_exists('validateDeviceSession')) {
    function validateDeviceSession($expectedUserType, $conn) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (($_SESSION['login_role'] ?? null) !== $expectedUserType) {
            return false;
        }

        $deviceId = getDeviceId();
    
    // Get most recent session for this device
    $stmt = $conn->prepare("
        SELECT user_type, user_id, logout_time 
        FROM device_sessions 
        WHERE device_id = ? 
        ORDER BY login_time DESC 
        LIMIT 1
    ");
    $stmt->bind_param("s", $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    
    if ($row = $result->fetch_assoc()) {
        // Check if logged out
        if (!is_null($row['logout_time'])) {
            return false; // Session was terminated
        }
        
        // Check if different user type is now active
        if ($row['user_type'] !== $expectedUserType) {
            return false; // Different role is active on this device
        }
        
        return true;
    }
    
        return false; // No session found for this device
    }
}

/**
 * Record user logout
 * 
 * @param mysqli $conn - database connection
 */
if (!function_exists('recordDeviceLogout')) {
    function recordDeviceLogout($conn) {
        $deviceId = getDeviceId();
    
    $stmt = $conn->prepare("
        UPDATE device_sessions 
        SET logout_time = NOW() 
        WHERE device_id = ? AND logout_time IS NULL
    ");
    $stmt->bind_param("s", $deviceId);
    $stmt->execute();
        $stmt->close();

        clearLoginState();
    }
}

?>
